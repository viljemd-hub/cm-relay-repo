<?php
/**
 * CM Relay
 * File: api/v1/events.php
 *
 * GET /api/v1/events
 * Auth: Authorization: Bearer <installation token>
 *
 * Per relay_api_contract.md - what cm_connector_pull_events() on the local
 * CM side calls. Only returns 'queued' events (status set by
 * bin/inbound_worker.php once a booking_new/etc has been fully resolved via
 * booking_revisions/feed, or an ari/other event normalized) - 'received'
 * events aren't ready yet, local CM should never see the raw, unverified
 * webhook payload.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/_lib/auth.php';
require_once __DIR__ . '/../../src/db/installation_queue.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'GET required']);
    exit;
}

$installation = relay_require_auth();
$queue = relay_installation_queue_db($installation['id']);

$rows = $queue->query('SELECT id, type, channel, occurred_at, payload, created_at FROM events WHERE status = "queued" ORDER BY created_at ASC LIMIT 50')
    ->fetchAll(PDO::FETCH_ASSOC);

$events = array_map(static function (array $row): array {
    return [
        'id' => $row['id'],
        'type' => $row['type'],
        'channel' => $row['channel'],
        'occurred_at' => $row['occurred_at'],
        'payload' => json_decode((string)$row['payload'], true),
        'created_at' => $row['created_at'],
    ];
}, $rows);

echo json_encode(['ok' => true, 'events' => $events]);
