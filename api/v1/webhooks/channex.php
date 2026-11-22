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
 * IS the authentication. An unmapped property_id is rejected outright. Two
 * independent layers additionally guard against arbitrary internet traffic
 * hitting this endpoint: a shared secret header (see
 * secrets/channex_webhook_secret.json) and Channex's own HMAC-SHA256
 * request signing (see secrets/channex_hmac_signing_secret.json, per
 * https://docs.channex.io/api-v.1-documentation/webhook-collection#webhook-security).
 * Kept as two checks rather than replacing one with the other - cheap
 * defense in depth, and avoids a flag-day cutover if the signing key is
 * ever only attached to some webhooks and not others.
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

$rawBody = (string)file_get_contents('php://input');

/**
 * Verifies Channex's HMAC-SHA256 request signature. Signed string is
 * "{timestamp}.{raw_body}" (raw bytes, not the re-serialized/parsed JSON -
 * re-encoding can reorder keys or change whitespace, which would break the
 * signature). x-hmac-signature-256 may carry 1 or 2 comma-separated
 * signatures during Channex-side key rotation (current key first, pending
 * key second) - accept if ANY match our stored secret. A 10-minute
 * timestamp tolerance guards against replay of a captured request.
 *
 * Returns 'valid', 'invalid', or 'absent' (no HMAC headers sent at all -
 * happens until the signing key is actually attached to the webhook in
 * Channex's dashboard, Organization > Webhooks > Signing Keys; a fresh key
 * shows as "Unused" there until attached). 'absent' is treated as a soft
 * pass below (falls back to the pre-existing shared-secret check) rather
 * than a hard reject, specifically so turning this code on can never by
 * itself break real webhook delivery for live installations (57) while the
 * signing key attachment is still pending on Channex's side. Tighten
 * 'absent' to a hard reject once relay_audit shows real traffic is
 * consistently arriving with valid signatures.
 */
function cm_relay_verify_channex_hmac(string $rawBody): string
{
    $hmacSecretPath = relay_secrets_root() . '/channex_hmac_signing_secret.json';
    $hmacSecret = (json_decode((string)@file_get_contents($hmacSecretPath), true))['secret'] ?? null;
    if (!is_string($hmacSecret) || $hmacSecret === '') {
        return 'absent';
    }

    $timestamp = $_SERVER['HTTP_X_HMAC_TIMESTAMP'] ?? '';
    $signatureHeader = $_SERVER['HTTP_X_HMAC_SIGNATURE_256'] ?? '';
    if ($timestamp === '' || $signatureHeader === '') {
        return 'absent';
    }
    if (!ctype_digit($timestamp)) {
        return 'invalid';
    }

    if (abs(time() - (int)$timestamp) > 600) {
        return 'invalid';
    }

    $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $hmacSecret);

    foreach (explode(',', $signatureHeader) as $candidate) {
        if (hash_equals($expected, trim($candidate))) {
            return 'valid';
        }
    }

    return 'invalid';
}

$hmacResult = cm_relay_verify_channex_hmac($rawBody);
if ($hmacResult === 'invalid') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'invalid_signature']);
    exit;
}

$body = json_decode($rawBody, true);
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

if ($hmacResult === 'absent') {
    // Soft pass (see cm_relay_verify_channex_hmac docblock) - logged so we
    // know when the signing key has actually been attached in Channex and
    // it's safe to make HMAC a hard requirement.
    relay_audit('webhook.hmac_absent', 'property', $propertyId);
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
