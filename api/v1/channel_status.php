<?php
/**
 * CM Relay
 * File: api/v1/channel_status.php
 * Author: Viljem Dvojmoč
 * Assistant: Claude
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 *
 * GET /api/v1/channel_status.php?cm_unit_id=A1
 * Auth: Authorization: Bearer <installation token>
 *
 * Read-side support for the cm_connector.php "which channels are actually
 * live" display (2026-10-18) - a mapping existing in unit_mappings does not
 * mean the Channex channel itself is active (e.g. Airbnb mapped but not yet
 * activated). For each of this unit's Channex mappings, finds the real
 * channel on Channex whose rate_plans include that mapping's
 * provider_rate_plan_id, and returns its title + is_active, so the UI can
 * show real status instead of just "a mapping exists".
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/_lib/auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'GET required']);
    exit;
}

$installation = relay_require_auth();

$cmUnitId = trim((string)($_GET['cm_unit_id'] ?? ''));
if ($cmUnitId === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'cm_unit_id required']);
    exit;
}

$db = relay_registry_db();
$rows = $db->prepare('
    SELECT um.id AS unit_mapping_id, um.provider_room_type_id, um.provider_rate_plan_id,
           pm.provider, pm.provider_property_id
    FROM unit_mappings um
    JOIN property_mappings pm ON pm.id = um.property_mapping_id
    WHERE pm.installation_id = ? AND um.cm_unit_id = ?
');
$rows->execute([$installation['id'], $cmUnitId]);
$mappings = $rows->fetchAll(PDO::FETCH_ASSOC);

$channexMappings = array_values(array_filter($mappings, static fn (array $m): bool => $m['provider'] === 'channex'));
if (!$channexMappings) {
    echo json_encode(['ok' => true, 'channels' => []]);
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

// Group mappings by provider_property_id so each distinct property is only
// looked up once, no matter how many rate plans map to it.
$byProperty = [];
foreach ($channexMappings as $m) {
    $byProperty[$m['provider_property_id']][] = $m;
}

$result = [];

foreach ($byProperty as $propertyId => $propertyMappings) {
    relay_rate_limit_record_call('channex');

    $ch = curl_init('https://app.channex.io/api/v1/channels?filter[property_id]=' . urlencode($propertyId));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['user-api-key: ' . $apiKey]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $httpCode >= 400) {
        foreach ($propertyMappings as $m) {
            $result[] = ['unit_mapping_id' => $m['unit_mapping_id'], 'ok' => false, 'error' => 'channex_fetch_failed'];
        }
        continue;
    }

    $channels = json_decode($raw, true)['data'] ?? [];

    foreach ($propertyMappings as $m) {
        $found = null;
        foreach ($channels as $c) {
            $ratePlans = $c['attributes']['rate_plans'] ?? [];
            foreach ($ratePlans as $rp) {
                if (($rp['rate_plan_id'] ?? null) === $m['provider_rate_plan_id']) {
                    $found = $c;
                    break 2;
                }
            }
        }

        if ($found === null) {
            $result[] = [
                'unit_mapping_id' => $m['unit_mapping_id'],
                'ok' => true,
                'channel_code' => null,
                'channel_title' => null,
                'is_active' => false,
            ];
        } else {
            $result[] = [
                'unit_mapping_id' => $m['unit_mapping_id'],
                'ok' => true,
                'channel_code' => $found['attributes']['channel'] ?? null,
                'channel_title' => $found['attributes']['title'] ?? null,
                'is_active' => (bool)($found['attributes']['is_active'] ?? false),
            ];
        }
    }
}

echo json_encode(['ok' => true, 'channels' => $result]);
