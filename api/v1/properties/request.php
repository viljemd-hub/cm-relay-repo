<?php
/**
 * CM Relay
 * File: api/v1/properties/request.php
 * Author: Viljem Dvojmoč
 * Assistant: Claude
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 *
 * POST /api/v1/properties/request
 * Auth: Authorization: Bearer <installation token>
 * Body (kind='provision', default): {cm_property_id, property: {title,
 *   currency, country, city, property_type?}, units: [{cm_unit_id, title,
 *   currency?, rate, occ_adults?, occ_children?, occ_infants?,
 *   count_of_rooms?, min_nights?}]}
 * Body (kind='photo'): {cm_property_id, kind:'photo', cm_unit_id, url,
 *   position?, description?}
 *
 * Replaces the old properties/provision.php and properties/photos.php as
 * the entry point an already-connected CM-software installation uses -
 * owner correction 2026-11-20: NO Channex-affecting action should be
 * automatic, even for an already-authenticated installation. This endpoint
 * only ever creates a pending channex_requests row (relay_create_
 * channex_request() in src/signup/signup_lib.php) - it NEVER calls
 * Channex. A human operator reviews and approves in admin/review_queue.php
 * (relay_approve_channex_request()), which is the only place that actually
 * touches Channex. Poll api/v1/properties/request_status.php for the
 * outcome - no new secret is issued, the caller already holds the only
 * credential that matters (its own bearer token).
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../_lib/auth.php';
require_once __DIR__ . '/../../../src/signup/signup_lib.php';

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
$kind = (string)($body['kind'] ?? 'provision');
if (!in_array($kind, ['provision', 'photo'], true)) {
    $kind = 'provision';
}

if ($cmPropertyId === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'cm_property_id required']);
    exit;
}

if ($kind === 'provision') {
    $property = is_array($body['property'] ?? null) ? $body['property'] : [];
    $units = is_array($body['units'] ?? null) ? $body['units'] : [];
    if (empty($property) || empty($units)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'property and units required']);
        exit;
    }
    $payload = ['property' => $property, 'units' => $units];
} else {
    $cmUnitId = trim((string)($body['cm_unit_id'] ?? ''));
    $url = trim((string)($body['url'] ?? ''));
    if ($cmUnitId === '' || $url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'cm_unit_id and a valid url required']);
        exit;
    }
    $payload = [
        'cm_unit_id' => $cmUnitId,
        'url' => $url,
        'position' => (int)($body['position'] ?? 0),
        'description' => isset($body['description']) ? (string)$body['description'] : null,
    ];
}

$result = relay_create_channex_request($installation['id'], $cmPropertyId, $kind, $payload);
if (!$result['ok']) {
    http_response_code(409);
    echo json_encode($result);
    exit;
}

echo json_encode(['ok' => true, 'status' => 'pending', 'request_id' => $result['request_id'], 'tracking_token' => $result['tracking_token']]);
