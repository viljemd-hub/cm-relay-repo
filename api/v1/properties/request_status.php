<?php
/**
 * CM Relay
 * File: api/v1/properties/request_status.php
 * Author: Viljem Dvojmoč
 * Assistant: Claude
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 *
 * GET /api/v1/properties/request_status.php?cm_property_id=...
 * Auth: Authorization: Bearer <installation token>
 *
 * How an already-connected CM-software installation finds out whether its
 * properties/request.php submission was approved - plain authenticated
 * polling, not a mailed link, since there is no new secret to disclose
 * (the caller already holds the only credential that matters). Returns the
 * latest request for this installation+cm_property_id, whatever its
 * status; once 'approved' the response includes the real mapping info
 * (same shape the old properties/provision.php returned synchronously).
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../_lib/auth.php';
require_once __DIR__ . '/../../../src/signup/signup_lib.php';

$installation = relay_require_auth();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'GET required']);
    exit;
}

$cmPropertyId = trim((string)($_GET['cm_property_id'] ?? ''));
if ($cmPropertyId === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'cm_property_id required']);
    exit;
}

$status = relay_get_channex_request_status($installation['id'], $cmPropertyId);
if ($status === null) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'no request found for this cm_property_id']);
    exit;
}

echo json_encode(['ok' => true] + $status);
