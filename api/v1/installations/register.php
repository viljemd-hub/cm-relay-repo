<?php
/**
 * CM Relay
 * File: api/v1/installations/register.php
 * Author: Viljem Dvojmoč
 * Assistant: Claude
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 *
 * POST /api/v1/installations/register
 * Body: {installation_uuid, edition, cm_version, connector_version, unit_count, locale}
 *
 * If installation_uuid is new: creates a registry row + issues a fresh
 * bearer token (shown once, only token_hash is stored - same convention as
 * every other secret in this project). If installation_uuid already exists,
 * returns its existing installation_id but does NOT reissue a token - use a
 * separate rotate endpoint for that (not built yet, no caller needs it).
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../src/db/registry.php';
require_once __DIR__ . '/../_lib/auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    $body = [];
}

$installationUuid = trim((string)($body['installation_uuid'] ?? ''));
if ($installationUuid === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'installation_uuid required']);
    exit;
}

$db = relay_registry_db();

$existing = $db->prepare('SELECT id FROM installations WHERE installation_uuid = ?');
$existing->execute([$installationUuid]);
$row = $existing->fetch(PDO::FETCH_ASSOC);

if ($row !== false) {
    relay_audit('installation.register.repeat', 'installation', $row['id']);
    echo json_encode(['ok' => true, 'installation_id' => $row['id'], 'already_registered' => true]);
    exit;
}

$id = relay_uuid_v4();
$token = relay_generate_token();

$db->prepare('
    INSERT INTO installations
        (id, installation_uuid, token_hash, edition, cm_version, connector_version, unit_count, locale, created_at, last_seen_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
')->execute([
    $id,
    $installationUuid,
    hash('sha256', $token),
    (string)($body['edition'] ?? ''),
    (string)($body['cm_version'] ?? ''),
    (string)($body['connector_version'] ?? ''),
    (int)($body['unit_count'] ?? 0),
    (string)($body['locale'] ?? ''),
    gmdate('c'),
    gmdate('c'),
]);

relay_audit('installation.register.new', 'installation', $id);

echo json_encode([
    'ok' => true,
    'installation_id' => $id,
    'token' => $token, // shown once - caller must store it
]);
