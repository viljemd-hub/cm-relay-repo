<?php
/**
 * CM Relay
 * File: api/v1/mappings.php
 * Author: Viljem Dvojmoč
 * Assistant: Claude
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 *
 * POST /api/v1/mappings
 * Auth: Authorization: Bearer <installation token>
 * Body: {cm_property_id, provider, provider_property_id, cm_unit_id,
 *        provider_room_type_id, provider_rate_plan_id,
 *        price_corrector_pct?}
 *
 * Creates (or returns existing) property_mapping + unit_mapping for this
 * installation. Manual for now - the real "onboarding creates a Channex
 * property automatically" flow (see bin/create_5_test_installations.php in
 * this repo for the pattern) is a later step once a real paid contract
 * exists; today's worker needs mappings to exist to be testable at all.
 *
 * price_corrector_pct (added 2026-09-19): optional signed percentage
 * applied to the price ONLY on its way out to this specific channel/rate
 * plan - e.g. +12 to cover a platform's higher commission vs. a cheaper
 * one, or a negative value to undercut. Lives here (per mapping, i.e. per
 * unit+channel), not globally or per-unit, since the whole point is
 * different platforms needing different corrections. CM's own stored
 * price is never touched - see outbox_worker.php. Re-POSTing an existing
 * mapping updates the corrector in place (the one field on a mapping
 * expected to change without recreating the mapping).
 *
 * GET /api/v1/mappings (added 2026-10-16)
 * Auth: Authorization: Bearer <installation token>
 * Lists every unit_mapping for this installation, so a local CM admin UI
 * can show current channel/rate-plan mappings and let the user edit
 * price_corrector_pct per channel without needing to already know the
 * provider_room_type_id/provider_rate_plan_id - this is the read side of
 * the "price resolver" settings screen.
 *
 * DELETE /api/v1/mappings?unit_mapping_id=<id> (added 2026-10-16)
 * Auth: Authorization: Bearer <installation token>
 * Removes a stale/leftover unit_mapping - e.g. the early-dev-era "HOME"/
 * "HOME 123456" test mappings on 57 that were left pointing at a dead
 * sandbox Channex property and were polluting channel_presence_check.php's
 * "is this really blocked everywhere" check with permanently-open noise.
 * Scoped to the authenticated installation's own mappings only - a mapping
 * id belonging to another installation 404s, never a cross-tenant delete.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/_lib/auth.php';

$installation = relay_require_auth();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $db = relay_registry_db();
    $rows = $db->prepare('
        SELECT um.id AS unit_mapping_id, um.cm_unit_id, um.provider_room_type_id,
               um.provider_rate_plan_id, um.price_corrector_pct,
               pm.id AS property_mapping_id, pm.cm_property_id, pm.provider, pm.provider_property_id
        FROM unit_mappings um
        JOIN property_mappings pm ON pm.id = um.property_mapping_id
        WHERE pm.installation_id = ?
        ORDER BY um.cm_unit_id, pm.provider
    ');
    $rows->execute([$installation['id']]);
    $mappings = array_map(static function (array $r): array {
        $r['price_corrector_pct'] = (float)$r['price_corrector_pct'];
        return $r;
    }, $rows->fetchAll(PDO::FETCH_ASSOC));

    echo json_encode(['ok' => true, 'mappings' => $mappings]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'DELETE') {
    $unitMappingId = trim((string)($_GET['unit_mapping_id'] ?? ''));
    if ($unitMappingId === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'unit_mapping_id required']);
        exit;
    }

    $db = relay_registry_db();
    $check = $db->prepare('
        SELECT um.id FROM unit_mappings um
        JOIN property_mappings pm ON pm.id = um.property_mapping_id
        WHERE um.id = ? AND pm.installation_id = ?
    ');
    $check->execute([$unitMappingId, $installation['id']]);
    if ($check->fetch(PDO::FETCH_ASSOC) === false) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'unit_mapping not found for this installation']);
        exit;
    }

    $db->prepare('DELETE FROM unit_mappings WHERE id = ?')->execute([$unitMappingId]);
    relay_audit('mapping.delete', 'unit_mapping', $unitMappingId, $installation['id']);

    echo json_encode(['ok' => true, 'deleted' => $unitMappingId]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST or GET required']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    $body = [];
}

foreach (['cm_property_id', 'provider', 'provider_property_id', 'cm_unit_id', 'provider_room_type_id', 'provider_rate_plan_id'] as $required) {
    if (empty($body[$required])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => "$required required"]);
        exit;
    }
}

$db = relay_registry_db();

$existingProp = $db->prepare('SELECT id FROM property_mappings WHERE installation_id = ? AND cm_property_id = ?');
$existingProp->execute([$installation['id'], $body['cm_property_id']]);
$propRow = $existingProp->fetch(PDO::FETCH_ASSOC);

if ($propRow === false) {
    $propId = relay_uuid_v4();
    $db->prepare('
        INSERT INTO property_mappings (id, installation_id, cm_property_id, provider, provider_property_id)
        VALUES (?, ?, ?, ?, ?)
    ')->execute([$propId, $installation['id'], $body['cm_property_id'], $body['provider'], $body['provider_property_id']]);
} else {
    // Re-mapping to a different provider property (e.g. the old sandbox
    // property was deleted and rebuilt) must actually update the stored
    // provider_property_id - previously this silently kept the stale one,
    // since only unit_mappings' room_type/rate_plan were ever updated here.
    $propId = $propRow['id'];
    $db->prepare('UPDATE property_mappings SET provider = ?, provider_property_id = ? WHERE id = ?')
        ->execute([$body['provider'], $body['provider_property_id'], $propId]);
}

$existingUnit = $db->prepare('SELECT id, price_corrector_pct FROM unit_mappings WHERE property_mapping_id = ? AND cm_unit_id = ?');
$existingUnit->execute([$propId, $body['cm_unit_id']]);
$unitRow = $existingUnit->fetch(PDO::FETCH_ASSOC);

// Only overwrite the corrector when the caller actually sent one - re-mapping
// room_type/rate_plan IDs shouldn't silently reset an already-configured
// correction back to 0.
if ($unitRow === false) {
    $priceCorrectorPct = isset($body['price_corrector_pct']) ? (float)$body['price_corrector_pct'] : 0.0;
    $unitId = relay_uuid_v4();
    $db->prepare('
        INSERT INTO unit_mappings (id, property_mapping_id, cm_unit_id, provider_room_type_id, provider_rate_plan_id, price_corrector_pct)
        VALUES (?, ?, ?, ?, ?, ?)
    ')->execute([$unitId, $propId, $body['cm_unit_id'], $body['provider_room_type_id'], $body['provider_rate_plan_id'], $priceCorrectorPct]);
} else {
    $unitId = $unitRow['id'];
    $priceCorrectorPct = isset($body['price_corrector_pct']) ? (float)$body['price_corrector_pct'] : (float)$unitRow['price_corrector_pct'];
    $db->prepare('
        UPDATE unit_mappings SET provider_room_type_id = ?, provider_rate_plan_id = ?, price_corrector_pct = ? WHERE id = ?
    ')->execute([$body['provider_room_type_id'], $body['provider_rate_plan_id'], $priceCorrectorPct, $unitId]);
}

relay_audit('mapping.create', 'unit_mapping', $unitId, $installation['id']);

echo json_encode(['ok' => true, 'property_mapping_id' => $propId, 'unit_mapping_id' => $unitId]);
