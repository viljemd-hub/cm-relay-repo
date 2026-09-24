<?php
/**
 * CM Relay
 * File: api/v1/events_ack.php
 * Author: Viljem Dvojmoč
 * Assistant: Claude
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 *
 * POST /api/v1/events_ack.php
 * Auth: Authorization: Bearer <installation token>
 * Body: {event_id}
 *
 * What cm_connector_ack_event() calls once local CM has actually applied
 * an event (e.g. written the reservation, or logged the alert). Marks
 * acknowledged, never deletes - matches the same "never delete on retry"
 * rule as the inbound cm_connector_events.php on the local CM side.
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
$eventId = (string)($body['event_id'] ?? '');

if ($eventId === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'event_id required']);
    exit;
}

$queue = relay_installation_queue_db($installation['id']);
$stmt = $queue->prepare('UPDATE events SET status = "acknowledged", acknowledged_at = ? WHERE id = ? AND status = "queued"');
$stmt->execute([gmdate('c'), $eventId]);

echo json_encode(['ok' => true, 'acknowledged' => $stmt->rowCount() > 0]);
