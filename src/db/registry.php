<?php
/**
 * CM Relay
 * File: src/db/registry.php
 *
 * Shared SQLite registry (one file, per README "Jezik / framework / hosting"
 * 2026-09-17 decision): installations, mappings, entitlements, audit. Grows
 * slowly - one row per installation, not per event - so a single shared
 * SQLite file is the right scale here, unlike events/commands (see
 * installation_queue.php), which are partitioned per installation.
 */

declare(strict_types=1);

/**
 * Defaults to <repo>/data for local/dev use. A real deployment sets
 * RELAY_PRIVATE_ROOT (e.g. via Apache SetEnv) to a directory OUTSIDE the
 * DocumentRoot entirely - not just Apache-deny-protected inside it - so
 * SQLite files/secrets can never be served regardless of any future vhost
 * misconfiguration. See secrets/README or the deployment notes for
 * cmrelay.duckdns.org (data lives in /var/www/html/relay-private/data).
 */
/**
 * mod_php doesn't reliably surface Apache's SetEnv through getenv() -
 * $_SERVER is the documented, reliable way to read it under mod_php,
 * while getenv() is what CLI usage (cron, bin/*.php) actually needs.
 * Check both rather than guessing which SAPI is running.
 */
function relay_private_root_override(): ?string
{
    $value = $_SERVER['RELAY_PRIVATE_ROOT'] ?? getenv('RELAY_PRIVATE_ROOT');
    return ($value !== false && $value !== null && $value !== '') ? $value : null;
}

function relay_data_root(): string
{
    $override = relay_private_root_override();
    if ($override !== null) {
        return rtrim($override, '/') . '/data';
    }

    return dirname(__DIR__, 2) . '/data';
}

function relay_secrets_root(): string
{
    $override = relay_private_root_override();
    if ($override !== null) {
        return rtrim($override, '/') . '/secrets';
    }

    return dirname(__DIR__, 2) . '/secrets';
}

function relay_registry_path(): string
{
    return relay_data_root() . '/registry.sqlite';
}

function relay_registry_db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    if (!is_dir(relay_data_root())) {
        mkdir(relay_data_root(), 0770, true);
    }

    $pdo = new PDO('sqlite:' . relay_registry_path());
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');

    relay_registry_migrate($pdo);

    return $pdo;
}

function relay_registry_migrate(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS installations (
            id TEXT PRIMARY KEY,
            installation_uuid TEXT UNIQUE NOT NULL,
            token_hash TEXT NOT NULL,
            edition TEXT,
            cm_version TEXT,
            connector_version TEXT,
            unit_count INTEGER,
            locale TEXT,
            status TEXT NOT NULL DEFAULT "active",
            created_at TEXT NOT NULL,
            last_seen_at TEXT
        )
    ');

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS service_entitlements (
            id TEXT PRIMARY KEY,
            installation_id TEXT NOT NULL REFERENCES installations(id),
            service TEXT NOT NULL,
            plan TEXT,
            status TEXT NOT NULL DEFAULT "requested",
            active_from TEXT,
            active_to TEXT
        )
    ');

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS provider_accounts (
            id TEXT PRIMARY KEY,
            provider TEXT NOT NULL,
            account_ref TEXT,
            created_at TEXT NOT NULL
        )
    ');

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS property_mappings (
            id TEXT PRIMARY KEY,
            installation_id TEXT NOT NULL REFERENCES installations(id),
            cm_property_id TEXT NOT NULL,
            provider TEXT NOT NULL,
            provider_property_id TEXT NOT NULL,
            UNIQUE(installation_id, cm_property_id)
        )
    ');

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS unit_mappings (
            id TEXT PRIMARY KEY,
            property_mapping_id TEXT NOT NULL REFERENCES property_mappings(id),
            cm_unit_id TEXT NOT NULL,
            provider_room_type_id TEXT NOT NULL,
            provider_rate_plan_id TEXT NOT NULL,
            price_corrector_pct REAL NOT NULL DEFAULT 0,
            UNIQUE(property_mapping_id, cm_unit_id)
        )
    ');

    // price_corrector_pct: added 2026-09-19, after unit_mappings already
    // shipped without it - SQLite's CREATE TABLE IF NOT EXISTS above is a
    // no-op on an existing file, so older registries need an explicit
    // ALTER. Per-mapping (not per-unit, not global) since the whole point
    // is different platforms/rate plans needing different corrections for
    // their own commission structure - a unit sold on two channels can
    // need two different correctors.
    $existingCols = array_column($pdo->query('PRAGMA table_info(unit_mappings)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('price_corrector_pct', $existingCols, true)) {
        $pdo->exec('ALTER TABLE unit_mappings ADD COLUMN price_corrector_pct REAL NOT NULL DEFAULT 0');
    }

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS audit_log (
            id TEXT PRIMARY KEY,
            actor TEXT,
            action TEXT NOT NULL,
            target_type TEXT,
            target_id TEXT,
            created_at TEXT NOT NULL
        )
    ');

    // Beta feedback (added 2026-09-21) - minimal reuse of the existing
    // installation_id/token auth instead of building a new feedback system
    // from scratch. Shared registry DB (not per-installation), since the
    // owner reads all feedback from every beta installation in one place -
    // there is no reason to partition this by installation the way
    // events/commands are.
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS installation_feedback (
            id TEXT PRIMARY KEY,
            installation_id TEXT NOT NULL REFERENCES installations(id),
            message TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT "new",
            created_at TEXT NOT NULL
        )
    ');

    // Global rate limiter state - Channex's 20 ARI-calls/minute limit is per
    // ACCOUNT, i.e. shared across every connected installation once the
    // relay/reseller model is live, not per installation. Lives in the
    // shared registry DB (not per-installation) precisely because it must
    // be checked across all of them together.
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS provider_call_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            provider TEXT NOT NULL,
            called_at TEXT NOT NULL
        )
    ');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_provider_call_log ON provider_call_log(provider, called_at)');
}

/**
 * True if a call to $provider right now would stay within $limit calls in
 * the trailing 60 seconds. Also prunes log rows older than 2 minutes so the
 * table doesn't grow unbounded (only the last ~minute is ever queried).
 */
function relay_rate_limit_ok(string $provider, int $limit = 20): bool
{
    $db = relay_registry_db();
    $db->prepare('DELETE FROM provider_call_log WHERE called_at < ?')
        ->execute([gmdate('c', time() - 120)]);

    $stmt = $db->prepare('SELECT COUNT(*) FROM provider_call_log WHERE provider = ? AND called_at >= ?');
    $stmt->execute([$provider, gmdate('c', time() - 60)]);

    return (int)$stmt->fetchColumn() < $limit;
}

function relay_rate_limit_record_call(string $provider): void
{
    relay_registry_db()
        ->prepare('INSERT INTO provider_call_log (provider, called_at) VALUES (?, ?)')
        ->execute([$provider, gmdate('c')]);
}

function relay_uuid_v4(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * @return array{provider: string, provider_property_id: string, provider_room_type_id: string, provider_rate_plan_id: string, price_corrector_pct: float}|null
 */
function relay_lookup_unit_mapping(string $installationId, string $cmUnitId): ?array
{
    $stmt = relay_registry_db()->prepare('
        SELECT pm.provider, pm.provider_property_id, um.provider_room_type_id, um.provider_rate_plan_id, um.price_corrector_pct
        FROM unit_mappings um
        JOIN property_mappings pm ON pm.id = um.property_mapping_id
        WHERE pm.installation_id = ? AND um.cm_unit_id = ?
    ');
    $stmt->execute([$installationId, $cmUnitId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row !== false ? $row : null;
}

/**
 * Reverse of relay_lookup_unit_mapping() - given a provider's own property_id
 * (what a webhook payload carries), find which CM installation owns it. A
 * webhook has no installation token to authenticate with directly, so this
 * lookup IS the authentication: an unmapped property_id is rejected outright.
 *
 * @return array{installation_id: string, installation_uuid: string, cm_property_id: string}|null
 */
function relay_lookup_installation_by_provider_property(string $provider, string $providerPropertyId): ?array
{
    $stmt = relay_registry_db()->prepare('
        SELECT i.id AS installation_id, i.installation_uuid, pm.cm_property_id
        FROM property_mappings pm
        JOIN installations i ON i.id = pm.installation_id
        WHERE pm.provider = ? AND pm.provider_property_id = ?
    ');
    $stmt->execute([$provider, $providerPropertyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row !== false ? $row : null;
}

/**
 * Reverse of relay_lookup_unit_mapping() in the OTHER direction - given a
 * provider's own room_type_id, find which cm_unit_id it maps to. Needed
 * when normalizing an inbound booking (Channex only ever speaks in its own
 * room_type_id, never our cm_unit_id) - local CM should never need to know
 * Channex's IDs at all, so this translation happens here, on the Relay
 * side, before an event is ever handed to local CM.
 */
function relay_lookup_cm_unit_by_provider_room_type(string $installationId, string $provider, string $providerRoomTypeId): ?string
{
    $stmt = relay_registry_db()->prepare('
        SELECT um.cm_unit_id
        FROM unit_mappings um
        JOIN property_mappings pm ON pm.id = um.property_mapping_id
        WHERE pm.installation_id = ? AND pm.provider = ? AND um.provider_room_type_id = ?
    ');
    $stmt->execute([$installationId, $provider, $providerRoomTypeId]);
    $row = $stmt->fetchColumn();

    return $row !== false ? (string)$row : null;
}

function relay_audit(string $action, ?string $targetType = null, ?string $targetId = null, ?string $actor = null): void
{
    $stmt = relay_registry_db()->prepare(
        'INSERT INTO audit_log (id, actor, action, target_type, target_id, created_at) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([relay_uuid_v4(), $actor, $action, $targetType, $targetId, gmdate('c')]);
}

function relay_save_feedback(string $installationId, string $message): string
{
    $id = relay_uuid_v4();
    relay_registry_db()->prepare(
        'INSERT INTO installation_feedback (id, installation_id, message, status, created_at) VALUES (?, ?, ?, "new", ?)'
    )->execute([$id, $installationId, $message, gmdate('c')]);

    return $id;
}
