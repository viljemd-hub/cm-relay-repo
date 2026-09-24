<?php
/**
 * CM Relay
 * File: api/v1/webhooks/channex.php
 * Author: Viljem Dvojmoč
 * Assistant: Claude
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 *
 * POST target registered with Channex (Organization → Webhooks). Per
 * Channex's own docs: "consider webhooks as triggers to pull current state
 * rather than authoritative data" - this endpoint deliberately does NOT
 * trust the payload as the source of truth. It only records "something
 * happened for this installation" (the dregljaj/nudge, same philosophy as
 * cm_connector_notify_unit_changed on the local CM side) - a separate,
 * not-yet-built worker step is responsible for actually pulling and
 * acknowledging via GET /api/v1/booking_revisions/feed before anything is
 * delivered to the local CM installation.
 *
 * Auth: no per-installation token exists at this layer (Channex has no way
 * to send one) - the property_id itself, mapped back through the registry,
 * IS the authentication. An unmapped property_id is rejected outright. A
 * shared secret header additionally guards against arbitrary internet
 * traffic hitting this endpoint (see secrets/channex_webhook_secret.json).
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../src/db/registry.php';
require_once __DIR__ . '/../../../src/db/installation_queue.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$secretPath = relay_secrets_root() . '/channex_webhook_secret.json';
$expectedSecret = (json_decode((string)@file_get_contents($secretPath), true))['secret'] ?? null;
$providedSecret = $_SERVER['HTTP_X_RELAY_WEBHOOK_SECRET'] ?? '';

if ($expectedSecret === null || !hash_equals((string)$expectedSecret, (string)$providedSecret)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body) || empty($body['event'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_payload']);
    exit;
}

$event = (string)$body['event'];
$propertyId = (string)($body['property_id'] ?? '');

if ($propertyId === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_property_id']);
    exit;
}

$installation = relay_lookup_installation_by_provider_property('channex', $propertyId);
if ($installation === null) {
    // Deliberately still 200 here - Channex will retry/flag persistent
    // errors, and an unmapped property from OUR side isn't Channex's fault.
    // Logged via audit_log for our own visibility instead.
    relay_audit('webhook.unmapped_property', 'property', $propertyId);
    echo json_encode(['ok' => true, 'skipped' => 'property_not_mapped']);
    exit;
}

$queue = relay_installation_queue_db($installation['installation_id']);

$providerEventId = (string)($body['payload']['revision_id'] ?? $body['payload']['booking_id'] ?? '');
$idempotencyKey = $event . ':' . $propertyId . ':' . $providerEventId . ':' . (string)($body['timestamp'] ?? '');

$existing = null;
if ($idempotencyKey !== '') {
    $stmt = $queue->prepare('SELECT id FROM events WHERE idempotency_key = ?');
    $stmt->execute([$idempotencyKey]);
    $existing = $stmt->fetchColumn();
}

if ($existing === false || $existing === null) {
    $queue->prepare('
        INSERT INTO events (id, provider_event_id, idempotency_key, type, channel, occurred_at, payload, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ')->execute([
        relay_uuid_v4(),
        $providerEventId ?: null,
        $idempotencyKey ?: null,
        $event,
        'channex',
        (string)($body['timestamp'] ?? gmdate('c')),
        json_encode($body),
        gmdate('c'),
    ]);
}

echo json_encode(['ok' => true]);
