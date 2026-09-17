<?php
/**
 * CM Relay
 * File: bin/outbox_worker.php
 *
 * Drains pending 'price' commands across ALL installations and pushes them
 * to Channex, respecting the shared 20-calls/minute account-wide rate limit
 * (see Channex PMS Certification Tests §12 - this is per ACCOUNT, not per
 * installation, since one contract serves many installations). Meant to run
 * periodically (cron), not as a long-lived daemon - not built yet.
 *
 * Scope today: 'price' commands only. 'availability'/'min_stay' commands
 * (payload.occupancy_merged + min_nights) still need segment-to-per-date
 * conversion logic before they can be pushed - left pending, not attempted,
 * rather than pushed wrong.
 *
 * Run: php bin/outbox_worker.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/db/registry.php';
require_once __DIR__ . '/../src/db/installation_queue.php';
require_once __DIR__ . '/../src/providers/ChannexAdapter.php';

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

foreach ($installations as $installation) {
    $queue = relay_installation_queue_db($installation['id']);
    $pending = $queue->query('SELECT * FROM commands WHERE status = "pending" AND reason = "price" ORDER BY created_at ASC')
        ->fetchAll(PDO::FETCH_ASSOC);

    foreach ($pending as $command) {
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
            // nothing to push, but don't leave it pending forever
            $queue->prepare('UPDATE commands SET status = "sent", completed_at = ? WHERE id = ?')
                ->execute([gmdate('c'), $command['id']]);
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
            $queue->prepare('UPDATE commands SET status = "sent", completed_at = ? WHERE id = ?')
                ->execute([gmdate('c'), $command['id']]);
            $pushed++;
            echo "OK  installation={$installation['installation_uuid']} unit={$command['unit']} dates=" . count($entries) . "\n";
        } else {
            $queue->prepare('
                UPDATE commands SET delivery_attempts = delivery_attempts + 1, last_error = ? WHERE id = ?
            ')->execute([$result->error, $command['id']]);
            echo "FAIL installation={$installation['installation_uuid']} unit={$command['unit']} error={$result->error}\n";
        }
    }
}

echo "\nDone. pushed=$pushed skipped_rate_limit=$skippedRateLimit skipped_no_mapping=$skippedNoMapping\n";
