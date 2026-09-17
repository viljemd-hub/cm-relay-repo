<?php
/**
 * CM Relay
 * File: bin/outbox_worker.php
 *
 * Drains pending commands (price, availability, min_stay) across ALL
 * installations and pushes them to Channex, respecting the shared
 * 20-calls/minute account-wide rate limit (see Channex PMS Certification
 * Tests §12 - this is per ACCOUNT, not per installation, since one contract
 * serves many installations). Meant to run periodically (cron), not as a
 * long-lived daemon - not built yet.
 *
 * RESOLVED 2026-09-17 (was reported as an open issue earlier the same day):
 * availability=1 (re-open) never stuck when pushed via /restrictions keyed
 * by rate_plan_id, while availability=0 (close) always worked - looked like
 * a flaky platform bug. Channex support (Andrew) confirmed the real cause:
 * availability is a SEPARATE endpoint (/api/v1/availability), keyed by
 * room_type_id, not part of /restrictions at all. Fixed via
 * ChannexAdapter::pushAvailability()/pushAvailabilityBatch() and
 * relay_build_availability_entries() now returning two entry sets. Verified
 * live: both 0 and 1 stick correctly, single and batched.
 *
 * Run: php bin/outbox_worker.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/db/registry.php';
require_once __DIR__ . '/../src/db/installation_queue.php';
require_once __DIR__ . '/../src/providers/ChannexAdapter.php';
require_once __DIR__ . '/../src/lib/occupancy_to_ari.php';

$apiKey = (json_decode(file_get_contents(__DIR__ . '/../secrets/channex_sandbox.json'), true))['api_key'] ?? null;
if (!$apiKey) {
    fwrite(STDERR, "No Channex API key in secrets/channex_sandbox.json\n");
    exit(1);
}
$adapter = new ChannexAdapter($apiKey);

$installations = relay_registry_db()->query('SELECT id, installation_uuid FROM installations WHERE status = "active"')
    ->fetchAll(PDO::FETCH_ASSOC);

$pushed = 0;
$skippedRateLimit = 0;
$skippedNoMapping = 0;

function markSent(PDO $queue, array $ids): void
{
    $stmt = $queue->prepare('UPDATE commands SET status = "sent", completed_at = ? WHERE id = ?');
    foreach ($ids as $id) {
        $stmt->execute([gmdate('c'), $id]);
    }
}

function markFailed(PDO $queue, array $ids, string $error): void
{
    $stmt = $queue->prepare('UPDATE commands SET delivery_attempts = delivery_attempts + 1, last_error = ? WHERE id = ?');
    foreach ($ids as $id) {
        $stmt->execute([$error, $id]);
    }
}

foreach ($installations as $installation) {
    $queue = relay_installation_queue_db($installation['id']);

    // --- price commands: each already carries just its own changed dates,
    // pushed as-is, one Channex call per command. ---
    $pendingPrices = $queue->query('SELECT * FROM commands WHERE status = "pending" AND reason = "price" ORDER BY created_at ASC')
        ->fetchAll(PDO::FETCH_ASSOC);

    foreach ($pendingPrices as $command) {
        if (!relay_rate_limit_ok('channex', 20)) {
            $skippedRateLimit++;
            continue;
        }

        $mapping = relay_lookup_unit_mapping($installation['id'], (string)$command['unit']);
        if ($mapping === null) {
            $skippedNoMapping++;
            continue;
        }

        $payload = json_decode((string)$command['payload'], true) ?? [];
        $prices = is_array($payload['prices'] ?? null) ? $payload['prices'] : [];
        if (empty($prices)) {
            markSent($queue, [$command['id']]); // nothing to push, don't leave it pending forever
            continue;
        }

        $entries = [];
        foreach ($prices as $date => $price) {
            $entries[] = [
                'property_id' => $mapping['provider_property_id'],
                'rate_plan_id' => $mapping['provider_rate_plan_id'],
                'date' => $date,
                'rate' => (int)round((float)$price * 100),
            ];
        }

        relay_rate_limit_record_call('channex');
        $result = $adapter->pushBatch($entries);

        if ($result->ok) {
            markSent($queue, [$command['id']]);
            $pushed++;
            echo "OK  installation={$installation['installation_uuid']} unit={$command['unit']} reason=price dates=" . count($entries) . "\n";
        } else {
            markFailed($queue, [$command['id']], (string)$result->error);
            echo "FAIL installation={$installation['installation_uuid']} unit={$command['unit']} reason=price error={$result->error}\n";
        }
    }

    // --- availability/min_stay commands: multiple pending nudges for the
    // SAME unit are collapsed into one push - each payload is a full fresh
    // snapshot already (see cm_connector_prepare_unit_snapshot on the CM
    // side), so only the newest one per unit needs to actually be sent; the
    // older ones are superseded, not separate changes to replay. ---
    $pendingAvailability = $queue->query(
        'SELECT * FROM commands WHERE status = "pending" AND reason IN ("availability", "min_stay") ORDER BY created_at ASC'
    )->fetchAll(PDO::FETCH_ASSOC);

    $latestByUnit = [];
    foreach ($pendingAvailability as $command) {
        $latestByUnit[$command['unit']][] = $command; // keep insertion order (ASC by created_at)
    }

    foreach ($latestByUnit as $unit => $commands) {
        $newest = end($commands);
        $allIds = array_column($commands, 'id');

        // Two separate Channex calls now (availability endpoint + restrictions
        // endpoint, see occupancy_to_ari.php), so the rate limit is checked/
        // recorded twice - counts as 2 against the 20/min account-wide budget.
        if (!relay_rate_limit_ok('channex', 20)) {
            $skippedRateLimit += count($commands);
            continue;
        }

        $mapping = relay_lookup_unit_mapping($installation['id'], (string)$unit);
        if ($mapping === null) {
            $skippedNoMapping += count($commands);
            continue;
        }

        $payload = json_decode((string)$newest['payload'], true) ?? [];
        $segments = is_array($payload['occupancy_merged'] ?? null) ? $payload['occupancy_merged'] : [];
        $minNights = (int)($payload['min_nights'] ?? 1);

        $built = relay_build_availability_entries(
            $mapping['provider_property_id'],
            $mapping['provider_room_type_id'],
            $mapping['provider_rate_plan_id'],
            $segments,
            $minNights
        );

        relay_rate_limit_record_call('channex');
        $availResult = $adapter->pushAvailabilityBatch($built['availability']);

        $restrResult = null;
        if ($availResult->ok && relay_rate_limit_ok('channex', 20)) {
            relay_rate_limit_record_call('channex');
            $restrResult = $adapter->pushBatch($built['restrictions']);
        }

        if ($availResult->ok && $restrResult !== null && $restrResult->ok) {
            markSent($queue, $allIds);
            $pushed++;
            echo "OK  installation={$installation['installation_uuid']} unit=$unit reason=availability window=" . count($built['availability']) . " collapsed=" . count($allIds) . "\n";
        } else {
            $error = !$availResult->ok ? $availResult->error : ($restrResult->error ?? 'rate_limited_between_calls');
            markFailed($queue, $allIds, (string)$error);
            echo "FAIL installation={$installation['installation_uuid']} unit=$unit reason=availability error=$error\n";
        }
    }
}

echo "\nDone. pushed=$pushed skipped_rate_limit=$skippedRateLimit skipped_no_mapping=$skippedNoMapping\n";
