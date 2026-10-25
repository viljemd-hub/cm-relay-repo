<?php
/**
 * CM Relay
 * File: bin/outbox_worker.php
 * Author: Viljem Dvojmoč
 * Assistant: Claude
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 *
 * Drains pending commands (price, availability, min_stay, full_sync) across
 * ALL installations and pushes them to Channex, respecting the shared
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

$apiKey = (json_decode(file_get_contents(relay_secrets_root() . '/channex_production.json'), true))['api_key'] ?? null;
if (!$apiKey) {
    fwrite(STDERR, "No Channex API key in " . relay_secrets_root() . "/channex_production.json\n");
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

        $mappings = relay_lookup_unit_mappings($installation['id'], (string)$command['unit']);
        if (empty($mappings)) {
            $skippedNoMapping++;
            continue;
        }

        $payload = json_decode((string)$command['payload'], true) ?? [];
        $prices = is_array($payload['prices'] ?? null) ? $payload['prices'] : [];
        if (empty($prices)) {
            markSent($queue, [$command['id']]); // nothing to push, don't leave it pending forever
            continue;
        }

        // A unit can have more than one mapping now (one per channel's own
        // rate plan - e.g. Airbnb and Booking.com each need their own
        // corrector for their own commission structure, see registry.php's
        // 2026-10-25 migration). Push the same underlying CM prices to EVERY
        // mapped rate plan, each with its own corrector applied on the way
        // out - CM's own stored price is never modified.
        $allOk = true;
        $lastError = null;
        $totalDates = 0;
        foreach ($mappings as $mapping) {
            if (!relay_rate_limit_ok('channex', 20)) {
                $allOk = false;
                $lastError = 'rate_limited_between_mappings';
                break;
            }

            $correctorMultiplier = 1.0 + ((float)($mapping['price_corrector_pct'] ?? 0) / 100.0);

            $entries = [];
            foreach ($prices as $date => $price) {
                $entries[] = [
                    'property_id' => $mapping['provider_property_id'],
                    'rate_plan_id' => $mapping['provider_rate_plan_id'],
                    'date' => $date,
                    'rate' => (int)round((float)$price * $correctorMultiplier * 100),
                ];
            }

            relay_rate_limit_record_call('channex');
            $result = $adapter->pushBatch($entries);
            $totalDates += count($entries);

            if (!$result->ok) {
                $allOk = false;
                $lastError = $result->error;
                break;
            }
        }

        if ($allOk) {
            markSent($queue, [$command['id']]);
            $pushed++;
            echo "OK  installation={$installation['installation_uuid']} unit={$command['unit']} reason=price mappings=" . count($mappings) . " dates=$totalDates\n";
        } else {
            markFailed($queue, [$command['id']], (string)$lastError);
            echo "FAIL installation={$installation['installation_uuid']} unit={$command['unit']} reason=price error=$lastError\n";
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

        $mappings = relay_lookup_unit_mappings($installation['id'], (string)$unit);
        if (empty($mappings)) {
            $skippedNoMapping += count($commands);
            continue;
        }

        $payload = json_decode((string)$newest['payload'], true) ?? [];
        $segments = is_array($payload['occupancy_merged'] ?? null) ? $payload['occupancy_merged'] : [];
        $minNights = (int)($payload['min_nights'] ?? 1);
        $maxNights = isset($payload['max_nights']) ? (int)$payload['max_nights'] : null;
        $includeSoftBlocks = !array_key_exists('include_blocked_days', $payload) || (bool)$payload['include_blocked_days'];

        // Availability is keyed by room_type_id, which every mapping for
        // this unit shares (same physical room) - push it once, from the
        // first mapping, not once per rate plan. Restrictions (min/max
        // nights) are keyed by rate_plan_id though, and each channel's rate
        // plan needs its own push, even though the values themselves are
        // the same property-wide policy (price is the only thing that
        // actually differs per channel, via price_corrector_pct).
        $first = $mappings[0];
        $built = relay_build_availability_entries(
            $first['provider_property_id'],
            $first['provider_room_type_id'],
            $first['provider_rate_plan_id'],
            $segments,
            $minNights,
            $maxNights,
            500,
            $includeSoftBlocks
        );

        relay_rate_limit_record_call('channex');
        $availResult = $adapter->pushAvailabilityBatch($built['availability']);

        $allOk = $availResult->ok;
        $lastError = $availResult->ok ? null : $availResult->error;

        if ($availResult->ok) {
            foreach ($mappings as $i => $mapping) {
                if (!relay_rate_limit_ok('channex', 20)) {
                    $allOk = false;
                    $lastError = 'rate_limited_between_calls';
                    break;
                }

                // First mapping's restrictions were already built above
                // (same call that built availability); the rest need their
                // own restriction entries for their own rate_plan_id.
                $restrictions = $i === 0
                    ? $built['restrictions']
                    : relay_build_availability_entries(
                        $mapping['provider_property_id'],
                        $mapping['provider_room_type_id'],
                        $mapping['provider_rate_plan_id'],
                        $segments,
                        $minNights,
                        $maxNights,
                        500,
                        $includeSoftBlocks
                    )['restrictions'];

                relay_rate_limit_record_call('channex');
                $restrResult = $adapter->pushBatch($restrictions);

                if (!$restrResult->ok) {
                    $allOk = false;
                    $lastError = $restrResult->error;
                    break;
                }
            }
        }

        if ($allOk) {
            markSent($queue, $allIds);
            $pushed++;
            echo "OK  installation={$installation['installation_uuid']} unit=$unit reason=availability window=" . count($built['availability']) . " mappings=" . count($mappings) . " collapsed=" . count($allIds) . "\n";
        } else {
            markFailed($queue, $allIds, (string)$lastError);
            echo "FAIL installation={$installation['installation_uuid']} unit=$unit reason=availability error=$lastError\n";
        }
    }

    // --- full_sync commands: Channex PMS Certification Tests §1/§8 - the
    // full 500-day picture (rates + restrictions + availability) pushed in
    // exactly 2 API calls, when a mapping first goes live. NOT a timer
    // (that would violate §13). CM side already bundled prices + occupancy
    // + min_nights into one snapshot (cm_connector_prepare_unit_snapshot,
    // reason='full_sync') specifically so this stays 2 calls, not 2*N. ---
    $pendingFullSync = $queue->query('SELECT * FROM commands WHERE status = "pending" AND reason = "full_sync" ORDER BY created_at ASC')
        ->fetchAll(PDO::FETCH_ASSOC);

    foreach ($pendingFullSync as $command) {
        if (!relay_rate_limit_ok('channex', 20)) {
            $skippedRateLimit++;
            continue;
        }

        $mappings = relay_lookup_unit_mappings($installation['id'], (string)$command['unit']);
        if (empty($mappings)) {
            $skippedNoMapping++;
            continue;
        }

        $payload = json_decode((string)$command['payload'], true) ?? [];
        $prices = is_array($payload['prices'] ?? null) ? $payload['prices'] : [];
        $segments = is_array($payload['occupancy_merged'] ?? null) ? $payload['occupancy_merged'] : [];
        $minNights = (int)($payload['min_nights'] ?? 1);
        $maxNights = isset($payload['max_nights']) ? (int)$payload['max_nights'] : null;
        $includeSoftBlocks = !array_key_exists('include_blocked_days', $payload) || (bool)$payload['include_blocked_days'];

        // Availability (room_type_id-keyed) is shared across every mapping
        // for this unit - built/pushed once, from the first mapping. Each
        // mapping still gets its own combined rate+restrictions push below,
        // since §1 wants rate_plan_id-scoped rate+restrictions together and
        // each channel's rate plan needs its own corrector-adjusted price.
        $first = $mappings[0];
        $built = relay_build_availability_entries(
            $first['provider_property_id'],
            $first['provider_room_type_id'],
            $first['provider_rate_plan_id'],
            $segments,
            $minNights,
            $maxNights,
            // windowDays defaults to 500 - matches the certification spec exactly
            500,
            $includeSoftBlocks
        );

        relay_rate_limit_record_call('channex');
        $availResult = $adapter->pushAvailabilityBatch($built['availability']);

        $allOk = $availResult->ok;
        $lastError = $availResult->ok ? null : $availResult->error;

        if ($availResult->ok) {
            foreach ($mappings as $i => $mapping) {
                $restrictions = $i === 0
                    ? $built['restrictions']
                    : relay_build_availability_entries(
                        $mapping['provider_property_id'],
                        $mapping['provider_room_type_id'],
                        $mapping['provider_rate_plan_id'],
                        $segments,
                        $minNights,
                        $maxNights,
                        500,
                        $includeSoftBlocks
                    )['restrictions'];

                $correctorMultiplier = 1.0 + ((float)($mapping['price_corrector_pct'] ?? 0) / 100.0);

                // Merge the real CM price into each restriction entry - §1
                // wants rate + restrictions together in one call, not split
                // in two. Each mapping applies its OWN corrector here.
                foreach ($restrictions as &$entry) {
                    if (isset($prices[$entry['date']])) {
                        $entry['rate'] = (int)round((float)$prices[$entry['date']] * $correctorMultiplier * 100);
                    }
                }
                unset($entry);
                // Only push dates CM actually has a price for - a restriction
                // entry with no real rate isn't what §1 is asking for.
                $restrictionEntries = array_values(array_filter($restrictions, static fn($e) => isset($e['rate'])));

                if (empty($restrictionEntries)) {
                    $allOk = false;
                    $lastError = 'no_price_data_in_window';
                    break;
                }

                if (!relay_rate_limit_ok('channex', 20)) {
                    $allOk = false;
                    $lastError = 'rate_limited_between_calls';
                    break;
                }

                relay_rate_limit_record_call('channex');
                $restrResult = $adapter->pushBatch($restrictionEntries);

                if (!$restrResult->ok) {
                    $allOk = false;
                    $lastError = $restrResult->error;
                    break;
                }
            }
        }

        if ($allOk) {
            markSent($queue, [$command['id']]);
            $pushed++;
            echo "OK  installation={$installation['installation_uuid']} unit={$command['unit']} reason=full_sync availability_days=" . count($built['availability']) . " mappings=" . count($mappings) . "\n";
        } else {
            markFailed($queue, [$command['id']], (string)$lastError);
            echo "FAIL installation={$installation['installation_uuid']} unit={$command['unit']} reason=full_sync error=$lastError\n";
        }
    }
}

echo "\nDone. pushed=$pushed skipped_rate_limit=$skippedRateLimit skipped_no_mapping=$skippedNoMapping\n";
