<?php
/**
 * CM Relay
 * File: api/v1/channel_presence_check.php
 * Author: Viljem Dvojmoč
 * Assistant: Claude
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 *
 * GET /api/v1/channel_presence_check.php?cm_unit_id=T1&date_from=2026-11-15&date_to=2026-11-16
 * Auth: Authorization: Bearer <installation token>
 *
 * Part of the channel-presence SMS confirmation gate - see
 * docs/cm_relay/direct_booking_channel_confirmation_gate.md.
 *
 * Checks every Channex unit_mapping this installation has for the given
 * cm_unit_id. For each mapping's room_type, reads back current availability
 * (ChannexAdapter::getAvailability() - the GET counterpart of the
 * pushAvailability() path that already carries a direct booking's soft
 * hold out via cm_connector_notify_unit_changed()). "Confirmed" means every
 * date in [date_from, date_to) shows availability == 0 on every mapped
 * channel - i.e. the block has actually landed, not just been queued.
 *
 * If this cm_unit_id has NO Channex mapping at all, that's not an error -
 * it just means this unit has no API-connected channel, and the caller
 * (pro-dev-repo's SMS-gate cron) should fall back to the ICS-only path
 * instead. Returned as {ok:true, confirmed:false, reason:"no_api_mapping"}.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/_lib/auth.php';
require_once __DIR__ . '/../../src/providers/ChannexAdapter.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'GET required']);
    exit;
}

$installation = relay_require_auth();

$cmUnitId = trim((string)($_GET['cm_unit_id'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo   = trim((string)($_GET['date_to'] ?? ''));

if ($cmUnitId === '' || $dateFrom === '' || $dateTo === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'cm_unit_id, date_from, date_to required']);
    exit;
}

$db = relay_registry_db();
$rows = $db->prepare('
    SELECT um.provider_room_type_id, pm.provider, pm.provider_property_id
    FROM unit_mappings um
    JOIN property_mappings pm ON pm.id = um.property_mapping_id
    WHERE pm.installation_id = ? AND um.cm_unit_id = ?
');
$rows->execute([$installation['id'], $cmUnitId]);
$mappings = $rows->fetchAll(PDO::FETCH_ASSOC);

if (!$mappings) {
    echo json_encode(['ok' => true, 'confirmed' => false, 'reason' => 'no_api_mapping']);
    exit;
}

$channexMappings = array_values(array_filter($mappings, static fn (array $m): bool => $m['provider'] === 'channex'));
if (!$channexMappings) {
    echo json_encode(['ok' => true, 'confirmed' => false, 'reason' => 'no_channex_mapping']);
    exit;
}

if (!relay_rate_limit_ok('channex', 20)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'channex_rate_limited']);
    exit;
}

$secretsFile = relay_secrets_root() . '/channex_production.json';
$apiKey = (json_decode((string)@file_get_contents($secretsFile), true))['api_key'] ?? null;
if (!$apiKey) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'channex_api_key_not_configured']);
    exit;
}

$adapter = new ChannexAdapter($apiKey);

$dates = [];
$cursor = new DateTimeImmutable($dateFrom);
$end = new DateTimeImmutable($dateTo);
while ($cursor < $end) {
    $dates[] = $cursor->format('Y-m-d');
    $cursor = $cursor->modify('+1 day');
}

$perMapping = [];
$allConfirmed = true;

foreach ($channexMappings as $m) {
    relay_rate_limit_record_call('channex');
    $result = $adapter->getAvailability($m['provider_property_id'], $m['provider_room_type_id'], $dateFrom, $dateTo);

    if (!$result->ok) {
        $allConfirmed = false;
        $perMapping[] = ['room_type_id' => $m['provider_room_type_id'], 'ok' => false, 'error' => $result->error];
        continue;
    }

    $byDate = $result->data['data'][$m['provider_room_type_id']] ?? [];
    $blockedDates = [];
    $openDates = [];
    foreach ($dates as $d) {
        $avail = $byDate[$d] ?? null;
        if ($avail === null) {
            // date missing from Channex response - treat as NOT confirmed,
            // never assume absence means blocked.
            $openDates[] = $d;
            continue;
        }
        if ((int)$avail === 0) {
            $blockedDates[] = $d;
        } else {
            $openDates[] = $d;
        }
    }

    if ($openDates) {
        $allConfirmed = false;
    }
    $perMapping[] = [
        'room_type_id' => $m['provider_room_type_id'],
        'ok' => true,
        'blocked_dates' => $blockedDates,
        'still_open_dates' => $openDates,
    ];
}

echo json_encode([
    'ok' => true,
    'confirmed' => $allConfirmed,
    'reason' => $allConfirmed ? 'blocked_on_all_channels' : 'not_yet_confirmed',
    'mappings' => $perMapping,
]);
