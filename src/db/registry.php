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

    // UNIQUE(property_mapping_id, cm_unit_id) originally allowed only ONE
    // rate plan per unit - blocked the exact "different platforms need
    // different correctors" case the price_corrector_pct comment above
    // already described (2026-10-25: Booking.com needs its own rate plan/
    // corrector, separate from Airbnb's, for the same A1 unit). SQLite
    // can't ALTER a table-level UNIQUE constraint in place, so this rebuilds
    // the table with UNIQUE(property_mapping_id, cm_unit_id,
    // provider_rate_plan_id) instead - one row per unit PER RATE PLAN, so a
    // unit can have N mappings (N channels/rate plans) rather than just one.
    // Dry-run tested against a copy of the live registry 2026-10-25 before
    // landing here - preserves all existing rows, allows a second A1 row.
    // Idempotent: only runs if the old 2-column constraint is still present.
    $uniqueCols = [];
    foreach ($pdo->query('PRAGMA index_list(unit_mappings)')->fetchAll(PDO::FETCH_ASSOC) as $idx) {
        if ((int)$idx['unique'] !== 1) {
            continue;
        }
        $cols = array_column($pdo->query('PRAGMA index_info(' . $idx['name'] . ')')->fetchAll(PDO::FETCH_ASSOC), 'name');
        $uniqueCols[] = $cols;
    }
    $hasOldTwoColConstraint = in_array(['property_mapping_id', 'cm_unit_id'], $uniqueCols, true);
    $hasNewThreeColConstraint = in_array(['property_mapping_id', 'cm_unit_id', 'provider_rate_plan_id'], $uniqueCols, true);

    if ($hasOldTwoColConstraint && !$hasNewThreeColConstraint) {
        $pdo->exec('BEGIN TRANSACTION');
        try {
            $pdo->exec('
                CREATE TABLE unit_mappings_new (
                    id TEXT PRIMARY KEY,
                    property_mapping_id TEXT NOT NULL REFERENCES property_mappings(id),
                    cm_unit_id TEXT NOT NULL,
                    provider_room_type_id TEXT NOT NULL,
                    provider_rate_plan_id TEXT NOT NULL,
                    price_corrector_pct REAL NOT NULL DEFAULT 0,
                    UNIQUE(property_mapping_id, cm_unit_id, provider_rate_plan_id)
                )
            ');
            $pdo->exec('
                INSERT INTO unit_mappings_new (id, property_mapping_id, cm_unit_id, provider_room_type_id, provider_rate_plan_id, price_corrector_pct)
                SELECT id, property_mapping_id, cm_unit_id, provider_room_type_id, provider_rate_plan_id, price_corrector_pct FROM unit_mappings
            ');
            $pdo->exec('DROP TABLE unit_mappings');
            $pdo->exec('ALTER TABLE unit_mappings_new RENAME TO unit_mappings');
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
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

    // 'kind' distinguishes installations that came from a real CM-software
    // install (register.php, called by cm_connector_activate_with_relay())
    // from ones created directly by a non-CM external signup (no
    // installation_uuid from any CM install exists for those - see
    // api/v1/signup/*.php). Same ALTER-if-missing idiom as price_corrector_pct
    // above, since CREATE TABLE IF NOT EXISTS is a no-op on an existing file.
    $installationCols = array_column($pdo->query('PRAGMA table_info(installations)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('kind', $installationCols, true)) {
        $pdo->exec("ALTER TABLE installations ADD COLUMN kind TEXT NOT NULL DEFAULT 'cm_software'");
    }

    // Legal/compliance record for the external (non-CM) signup's liability
    // consent step - its own table rather than audit_log, since this needs
    // to be queried back out directly (e.g. "who accepted liability text
    // v1") and audit_log's generic actor/action/target shape isn't a good
    // fit for that.
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS external_consents (
            id TEXT PRIMARY KEY,
            installation_id TEXT NOT NULL REFERENCES installations(id),
            liability_accepted_at TEXT NOT NULL,
            liability_text_version TEXT NOT NULL,
            signer_name TEXT,
            signer_email TEXT,
            ip_address TEXT,
            created_at TEXT NOT NULL
        )
    ');

    // IP-based throttle for the public, unauthenticated signup/request.php
    // endpoint - same called_at-trailing-window shape as provider_call_log
    // above, keyed by IP instead of provider, since this gate is about
    // abuse of a public form, not Channex's own API limit.
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS signup_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip_address TEXT NOT NULL,
            created_at TEXT NOT NULL
        )
    ');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_signup_attempts ON signup_attempts(ip_address, created_at)');

    // Free-text manual business-agreement fields on service_entitlements -
    // same fields/spirit as the CM-customer "business agreement" template in
    // admin/cm_connector.php (plan/price/contract_ref/email/notes), so the
    // external signup's agreement step writes to one conceptual place.
    $entitlementCols = array_column($pdo->query('PRAGMA table_info(service_entitlements)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    foreach (['price_eur_month' => 'TEXT', 'contract_ref' => 'TEXT', 'relay_account_email' => 'TEXT', 'notes' => 'TEXT'] as $col => $type) {
        if (!in_array($col, $entitlementCols, true)) {
            $pdo->exec("ALTER TABLE service_entitlements ADD COLUMN $col $type");
        }
    }

    // Owner correction 2026-11-18: the external signup flow is invitation-
    // FIRST, not open-first. CM Relay's own operator issues an invite_code
    // (via admin/review_queue.php) and sends it to a candidate by hand; only a
    // valid, unconsumed invite_code lets public/signup.php's form render or
    // submit at all - signup_attempts' IP throttle above stays as a cheap
    // extra layer, but the real admission gate is this table, not that one.
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS signup_invites (
            id TEXT PRIMARY KEY,
            invite_code TEXT UNIQUE NOT NULL,
            candidate_name TEXT,
            candidate_email TEXT,
            status TEXT NOT NULL DEFAULT "issued",
            installation_id TEXT REFERENCES installations(id),
            created_at TEXT NOT NULL,
            consumed_at TEXT
        )
    ');

    // The SECOND tokenized link - issued only after the operator approves
    // the submitted (pending) data in admin/review_queue.php. Deliberately a
    // separate table/token from signup_invites above: the invite grants
    // access to the FORM, this confirmation grants access to the actual
    // issued bearer token/connection instructions, and the two must never
    // be collapsed into one token or a leaked invite link would also leak
    // live API access once approved.
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS signup_confirmations (
            id TEXT PRIMARY KEY,
            installation_id TEXT NOT NULL REFERENCES installations(id),
            confirmation_token TEXT UNIQUE NOT NULL,
            created_at TEXT NOT NULL,
            viewed_at TEXT
        )
    ');

    // Owner correction 2026-11-20: NO Channex-affecting action should ever
    // be automatic, not even for an already-connected, already-authenticated
    // CM-software installation - "Create on Channex" used to call Channex
    // immediately (api/v1/properties/provision.php). Now it only creates a
    // row here; a human operator reviews it in admin/review_queue.php
    // before anything real happens. Deliberately NOT a reuse of
    // signup_confirmations (that table's whole point is a one-time SECRET
    // reveal to an unauthenticated party - an already-authenticated
    // installation has no new secret that needs revealing) or
    // external_consents (a liability signature for a legally distinct
    // unknown party - a CM installation already consented locally via
    // cm_connector_save_consent()). tracking_token is a pure audit handle
    // (owner's own ask, "zaradi sledenja bolje, da je tudi ta prijava
    // žetonirana") - returned in API responses, never gated behind a
    // reveal page.
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS channex_requests (
            id TEXT PRIMARY KEY,
            installation_id TEXT NOT NULL REFERENCES installations(id),
            cm_property_id TEXT NOT NULL,
            kind TEXT NOT NULL DEFAULT "provision",
            payload_json TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT "pending",
            tracking_token TEXT UNIQUE NOT NULL,
            created_at TEXT NOT NULL,
            reviewed_at TEXT,
            reviewed_by TEXT,
            result_json TEXT
        )
    ');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_channex_requests_pending ON channex_requests(installation_id, cm_property_id, status)');
}

/**
 * Where an external signup's raw submitted property+units+agreement payload
 * is held between api/v1/signup/request.php (write) and
 * api/v1/signup/approve.php (read) - a write-once-read-once blob, not worth
 * a SQL table for. Lives under relay_data_root() alongside registry.sqlite,
 * same private-root protection.
 */
function relay_pending_signup_path(string $installationId): string
{
    $dir = relay_data_root() . '/pending_signups';
    if (!is_dir($dir)) {
        mkdir($dir, 0770, true);
    }
    return $dir . '/' . $installationId . '.json';
}

/**
 * True if $ipAddress has made fewer than $limit signup attempts in the
 * trailing 24 hours. Also prunes rows older than 2 days so the table
 * doesn't grow unbounded. Mirrors relay_rate_limit_ok()'s shape but keyed
 * by IP over a day-long window instead of by provider over a minute.
 */
function relay_signup_attempt_ok(string $ipAddress, int $limit = 3): bool
{
    $db = relay_registry_db();
    $db->prepare("DELETE FROM signup_attempts WHERE created_at < ?")
        ->execute([gmdate('c', time() - 172800)]);

    $stmt = $db->prepare("SELECT COUNT(*) FROM signup_attempts WHERE ip_address = ? AND created_at > ?");
    $stmt->execute([$ipAddress, gmdate('c', time() - 86400)]);

    return (int)$stmt->fetchColumn() < $limit;
}

function relay_signup_attempt_record(string $ipAddress): void
{
    relay_registry_db()
        ->prepare('INSERT INTO signup_attempts (ip_address, created_at) VALUES (?, ?)')
        ->execute([$ipAddress, gmdate('c')]);
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
 * Plural counterpart to relay_lookup_unit_mapping() - a cm_unit_id can now
 * have more than one row (one per provider_rate_plan_id, e.g. Airbnb and
 * Booking.com each with their own rate plan/corrector for the same A1 unit,
 * added 2026-10-25). outbox_worker.php uses this to push price to every
 * mapped rate plan, each with its own corrector, instead of just one.
 *
 * @return array<int, array{unit_mapping_id: string, provider: string, provider_property_id: string, provider_room_type_id: string, provider_rate_plan_id: string, price_corrector_pct: float}>
 */
function relay_lookup_unit_mappings(string $installationId, string $cmUnitId): array
{
    $stmt = relay_registry_db()->prepare('
        SELECT um.id AS unit_mapping_id, pm.provider, pm.provider_property_id, um.provider_room_type_id, um.provider_rate_plan_id, um.price_corrector_pct
        FROM unit_mappings um
        JOIN property_mappings pm ON pm.id = um.property_mapping_id
        WHERE pm.installation_id = ? AND um.cm_unit_id = ?
    ');
    $stmt->execute([$installationId, $cmUnitId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
