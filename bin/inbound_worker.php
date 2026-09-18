<?php
/**
 * CM Relay
 * File: bin/inbound_worker.php
 *
 * Processes pending ('received') inbound events across ALL installations -
 * the second half of the webhook pipeline (api/v1/webhooks/channex.php only
 * queues; this actually reads and finalizes). Meant to run on a schedule
 * (cron), same convention as outbox_worker.php.
 *
 * Per event type:
 *   - booking_new / booking_modification / booking_cancellation: the
 *     webhook payload is NOT trusted (Channex's own guidance - "webhooks
 *     are triggers to pull current state"). Calls GET /booking_revisions
 *     /feed for the real data, translates Channex's own room_type_id to
 *     cm_unit_id via the registry (local CM should never see a Channex ID),
 *     then POSTs the acknowledge - Channex nags via email if a revision
 *     sits unacknowledged ~30 minutes.
 *   - ari: the webhook payload already carries the changed values in full
 *     (availability/rate/stop_sell per date/room_type/rate_plan) - no
 *     separate fetch needed, just normalized and queued for local CM.
 *   - everything else (sync_error, sync_warning, booking_unmapped_room/
 *     rate, etc): informational, queued as-is for local CM to log/alert on.
 *
 * Run: php bin/inbound_worker.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/db/registry.php';
require_once __DIR__ . '/../src/db/installation_queue.php';
require_once __DIR__ . '/../src/providers/ChannexAdapter.php';

$apiKey = (json_decode(file_get_contents(relay_secrets_root() . '/channex_sandbox.json'), true))['api_key'] ?? null;
if (!$apiKey) {
    fwrite(STDERR, "No Channex API key in " . relay_secrets_root() . "/channex_sandbox.json\n");
    exit(1);
}
$adapter = new ChannexAdapter($apiKey);

$installations = relay_registry_db()->query('SELECT id, installation_uuid FROM installations WHERE status = "active"')
    ->fetchAll(PDO::FETCH_ASSOC);

$bookingEventTypes = ['booking_new', 'booking_modification', 'booking_cancellation'];
$processed = 0;
$failed = 0;

foreach ($installations as $installation) {
    $queue = relay_installation_queue_db($installation['id']);
    $pending = $queue->query('SELECT * FROM events WHERE status = "received" ORDER BY created_at ASC')
        ->fetchAll(PDO::FETCH_ASSOC);

    foreach ($pending as $event) {
        $webhookBody = json_decode((string)$event['payload'], true) ?? [];
        $propertyId = (string)($webhookBody['property_id'] ?? '');

        if (in_array($event['type'], $bookingEventTypes, true)) {
            if (!relay_rate_limit_ok('channex', 20)) {
                continue; // leave pending, worker's next run picks it up
            }
            relay_rate_limit_record_call('channex');

            $result = $adapter->fetchBookingRevisions($propertyId);
            if (!$result->ok) {
                $queue->prepare('UPDATE events SET delivery_attempts = delivery_attempts + 1, next_retry_at = ? WHERE id = ?')
                    ->execute([gmdate('c', time() + 300), $event['id']]);
                $failed++;
                echo "FAIL installation={$installation['installation_uuid']} event={$event['id']} fetch_error={$result->error}\n";
                continue;
            }

            $revisions = $result->data['data'] ?? [];
            $wantedBookingId = (string)($webhookBody['payload']['booking_id'] ?? '');
            $revision = null;
            foreach ($revisions as $candidate) {
                $attrs = $candidate['attributes'] ?? [];
                if ($wantedBookingId === '' || ($attrs['booking_id'] ?? '') === $wantedBookingId) {
                    $revision = $candidate;
                    break;
                }
            }

            if ($revision === null) {
                // Nothing matched yet - Channex's own eventual-consistency
                // note applies here too, retry later rather than guessing.
                $queue->prepare('UPDATE events SET delivery_attempts = delivery_attempts + 1, next_retry_at = ? WHERE id = ?')
                    ->execute([gmdate('c', time() + 300), $event['id']]);
                continue;
            }

            $attrs = $revision['attributes'] ?? [];
            $rooms = is_array($attrs['rooms'] ?? null) ? $attrs['rooms'] : [];
            $cmUnitId = null;
            foreach ($rooms as $room) {
                $roomTypeId = (string)($room['room_type_id'] ?? '');
                if ($roomTypeId === '') {
                    continue;
                }
                $cmUnitId = relay_lookup_cm_unit_by_provider_room_type($installation['id'], 'channex', $roomTypeId);
                if ($cmUnitId !== null) {
                    break;
                }
            }

            $normalized = [
                'cm_unit_id' => $cmUnitId, // null if no mapping found - local CM must not guess
                'booking_id' => $attrs['booking_id'] ?? null,
                'ota_reservation_code' => $attrs['ota_reservation_code'] ?? null,
                'ota_name' => $attrs['ota_name'] ?? null,
                'status' => $attrs['status'] ?? null,
                'arrival_date' => $attrs['arrival_date'] ?? null,
                'departure_date' => $attrs['departure_date'] ?? null,
                'amount' => $attrs['amount'] ?? null,
                'currency' => $attrs['currency'] ?? null,
                'customer' => $attrs['customer'] ?? [],
                'occupancy' => $attrs['occupancy'] ?? [],
                'notes' => $attrs['notes'] ?? null,
            ];

            $ackResult = $adapter->acknowledgeBookingRevision((string)$revision['id']);
            if (!$ackResult->ok) {
                // We DID get the real data - deliver it to local CM anyway,
                // but flag the ack failure so it can be retried separately
                // rather than silently losing the acknowledgement forever.
                echo "WARN installation={$installation['installation_uuid']} event={$event['id']} ack_failed={$ackResult->error}\n";
            }

            $queue->prepare('UPDATE events SET status = "queued", payload = ? WHERE id = ?')
                ->execute([json_encode($normalized), $event['id']]);
            $processed++;
            echo "OK   installation={$installation['installation_uuid']} event={$event['id']} type={$event['type']} cm_unit=" . ($cmUnitId ?? 'UNMAPPED') . "\n";
            continue;
        }

        if ($event['type'] === 'ari') {
            $ariEntries = is_array($webhookBody['payload'] ?? null) ? $webhookBody['payload'] : [];
            $normalized = [];
            foreach ($ariEntries as $entry) {
                $roomTypeId = (string)($entry['room_type_id'] ?? '');
                $cmUnitId = $roomTypeId !== ''
                    ? relay_lookup_cm_unit_by_provider_room_type($installation['id'], 'channex', $roomTypeId)
                    : null;
                $normalized[] = $entry + ['cm_unit_id' => $cmUnitId];
            }

            $queue->prepare('UPDATE events SET status = "queued", payload = ? WHERE id = ?')
                ->execute([json_encode($normalized), $event['id']]);
            $processed++;
            echo "OK   installation={$installation['installation_uuid']} event={$event['id']} type=ari entries=" . count($normalized) . "\n";
            continue;
        }

        // Informational event types - pass through as-is, local CM decides
        // whether/how to surface them (e.g. log, admin notification).
        $queue->prepare('UPDATE events SET status = "queued" WHERE id = ?')->execute([$event['id']]);
        $processed++;
        echo "OK   installation={$installation['installation_uuid']} event={$event['id']} type={$event['type']} (passthrough)\n";
    }
}

echo "\nDone. processed=$processed failed=$failed\n";
