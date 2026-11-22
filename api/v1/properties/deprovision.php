<?php
/**
 * CM Relay
 * File: api/v1/properties/deprovision.php
 * Author: Viljem Dvojmoč
 * Assistant: Claude
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 *
 * POST /api/v1/properties/deprovision
 * Auth: Authorization: Bearer <installation token>
 * Body: {cm_property_id, cm_unit_id}
 *
 * The "undo" counterpart to properties/provision.php - deletes the unit's
 * rate_plan + room_type on Channex (ChannexAdapter::deprovisionUnit()) and
 * removes its mapping(s), scoped to this installation's own cm_property_id,
 * so it can never touch another installation's (or another unit's) Channex
 * objects.
 *
 * Mapping rows are only deleted AFTER the Channex-side delete confirms ok -
 * if Channex fails partway (e.g. rate limited), the mapping stays in place
 * so a retry has something to find rather than silently losing track of a
 * Channex object that's still real.
 *
 * Cascades to deleting the Channex PROPERTY too (ChannexAdapter::
 * deleteProperty()) when this removal brings its unit count to zero - an
 * empty property left behind is not harmless, it can still carry real
 * financial liability on the Channex account even with no units mapped to
 * it (owner correction 2026-11-18). A genuinely different, still-in-use
 * property is never touched: the check only fires when zero unit_mappings
 * remain for this exact property_mapping.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../_lib/auth.php';
require_once __DIR__ . '/../../../src/db/mapping_writer.php';
require_once __DIR__ . '/../../../src/providers/ChannexAdapter.php';

$installation = relay_require_auth();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    $body = [];
}

$cmPropertyId = trim((string)($body['cm_property_id'] ?? ''));
$cmUnitId = trim((string)($body['cm_unit_id'] ?? ''));
if ($cmPropertyId === '' || $cmUnitId === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'cm_property_id and cm_unit_id required']);
    exit;
}

$db = relay_registry_db();

try {
    $unitMappings = relay_find_unit_mappings($db, $installation['id'], $cmPropertyId, $cmUnitId);
} catch (InvalidArgumentException $e) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}

$apiKey = (json_decode((string)@file_get_contents(relay_secrets_root() . '/channex_production.json'), true))['api_key'] ?? null;
if (!$apiKey) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'relay misconfigured - no channex api key']);
    exit;
}

$adapter = new ChannexAdapter($apiKey);
$removed = [];
$propertyMappingId = $unitMappings[0]['property_mapping_id'];
$providerPropertyId = $unitMappings[0]['provider_property_id'];

foreach ($unitMappings as $mapping) {
    $result = $adapter->deprovisionUnit($mapping['provider_room_type_id'], $mapping['provider_rate_plan_id']);
    if (!$result->ok) {
        http_response_code(502);
        echo json_encode(['ok' => false, 'error' => $result->error, 'removed_before_failure' => $removed]);
        exit;
    }
    relay_delete_unit_mapping_row($db, $mapping['unit_mapping_id']);
    $removed[] = $mapping['unit_mapping_id'];
}

relay_audit('property.deprovision', 'cm_unit', $cmUnitId, $installation['id']);

$propertyRemoved = false;
if (relay_property_mapping_is_empty($db, $propertyMappingId)) {
    $propertyDeleteResult = $adapter->deleteProperty($providerPropertyId);
    if (!$propertyDeleteResult->ok) {
        // Units are already gone and their mappings already removed - that
        // part succeeded and must not be reported as a failure. The
        // property itself staying behind (now empty) is a real, known gap
        // to retry/clean up manually, not silently swallowed.
        echo json_encode([
            'ok' => true,
            'removed' => $removed,
            'property_removed' => false,
            'property_removal_error' => $propertyDeleteResult->error,
        ]);
        exit;
    }
    relay_delete_property_mapping_row($db, $propertyMappingId);
    $propertyRemoved = true;
}

echo json_encode(['ok' => true, 'removed' => $removed, 'property_removed' => $propertyRemoved]);
