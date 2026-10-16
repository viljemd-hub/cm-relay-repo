<?php
/**
 * CM Relay
 * File: api/v1/sms/send.php
 * Author: Viljem Dvojmoč
 * Assistant: Claude
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 *
 * POST /api/v1/sms/send.php
 * Auth: Authorization: Bearer <installation token>
 * Body: { "to": "<guest phone>", "message": "<text>" }
 *
 * Forwards to the Motorola gateway (capcom6/android-sms-gateway, Local
 * Server mode, same LAN as GN7) over HTTP Basic Auth - see
 * docs/cm_relay/direct_booking_channel_confirmation_gate.md for the wider
 * design (channel-presence SMS confirmation gate on direct bookings).
 *
 * Scope as of 2026-10-16: single shared gateway (our own phone/SIM), used
 * by any authenticated installation. Fine for the beta-tester proof of
 * concept this was built for - a per-installation gateway registry is a
 * later problem, not solved here.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../_lib/auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$installation = relay_require_auth();

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body) || empty($body['to']) || empty($body['message'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'to and message required']);
    exit;
}

$to = trim((string)$body['to']);
$message = (string)$body['message'];

$configPath = relay_secrets_root() . '/sms_gateway.json';
$config = json_decode((string)@file_get_contents($configPath), true);
if (!is_array($config) || empty($config['host']) || empty($config['port'])
    || empty($config['username']) || !isset($config['password'])) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'sms_gateway_not_configured']);
    exit;
}

$url = sprintf('http://%s:%d/message', $config['host'], (int)$config['port']);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST => 'POST',
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_USERPWD => $config['username'] . ':' . $config['password'],
    CURLOPT_POSTFIELDS => json_encode([
        'textMessage' => ['text' => $message],
        'phoneNumbers' => [$to],
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
]);

$raw = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($raw === false) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'gateway_unreachable', 'detail' => $curlError]);
    exit;
}

if ($httpCode >= 400) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'gateway_rejected', 'http_status' => $httpCode]);
    exit;
}

echo json_encode(['ok' => true, 'gateway_response' => json_decode($raw, true)]);
