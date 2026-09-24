<?php
/**
 * CM Relay
 * File: api/v1/commands.php
 * Author: Viljem Dvojmoč
 * Assistant: Claude
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 *
 * POST /api/v1/commands
 * Auth: Authorization: Bearer <installation token>
 * Body: {type, unit, reason, idempotency_key, payload}
 *
 * This is where cm_connector_send_command() on the local CM side will
 * eventually POST to (currently local-only, see cm_connector_outbox.php -
 * this endpoint is the future receiving end, not yet wired to it).
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/_lib/auth.php';
require_once __DIR__ . '/../../src/db/installation_queue.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$installation = relay_require_auth();

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body) || empty($body['type'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'type required']);
    exit;
}

$idempotencyKey = (string)($body['idempotency_key'] ?? '');
$queue = relay_installation_queue_db($installation['id']);

if ($idempotencyKey !== '') {
    $dupe = $queue->prepare('SELECT id FROM commands WHERE idempotency_key = ? AND status = "pending"');
    $dupe->execute([$idempotencyKey]);
    if ($dupe->fetch()) {
        echo json_encode(['ok' => true, 'already_queued' => true]);
        exit;
    }
}

$id = relay_uuid_v4();
$queue->prepare('
    INSERT INTO commands (id, type, unit, reason, idempotency_key, payload, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?)
')->execute([
    $id,
    (string)$body['type'],
    (string)($body['unit'] ?? ''),
    (string)($body['reason'] ?? ''),
    $idempotencyKey,
    json_encode($body['payload'] ?? []),
    gmdate('c'),
]);

echo json_encode(['ok' => true, 'command_id' => $id]);
