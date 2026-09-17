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

function relay_data_root(): string
{
    return dirname(__DIR__, 2) . '/data';
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
            UNIQUE(property_mapping_id, cm_unit_id)
        )
    ');

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
 * @return array{provider: string, provider_property_id: string, provider_room_type_id: string, provider_rate_plan_id: string}|null
 */
function relay_lookup_unit_mapping(string $installationId, string $cmUnitId): ?array
{
    $stmt = relay_registry_db()->prepare('
        SELECT pm.provider, pm.provider_property_id, um.provider_room_type_id, um.provider_rate_plan_id
        FROM unit_mappings um
        JOIN property_mappings pm ON pm.id = um.property_mapping_id
        WHERE pm.installation_id = ? AND um.cm_unit_id = ?
    ');
    $stmt->execute([$installationId, $cmUnitId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row !== false ? $row : null;
}

function relay_audit(string $action, ?string $targetType = null, ?string $targetId = null, ?string $actor = null): void
{
    $stmt = relay_registry_db()->prepare(
        'INSERT INTO audit_log (id, actor, action, target_type, target_id, created_at) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([relay_uuid_v4(), $actor, $action, $targetType, $targetId, gmdate('c')]);
}
