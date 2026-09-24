<?php
/**
 * CM Relay
 * File: api/v1/feedback.php
 * Author: Viljem Dvojmoč
 * Assistant: Claude
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 *
 * POST /api/v1/feedback.php
 * Auth: Authorization: Bearer <installation token>
 * Body: {message}
 *
 * Minimal beta-feedback channel (2026-09-21) - deliberately reuses the
 * existing installation_id/token auth already built for events/commands
 * instead of a separate feedback system. A beta tester's CM install
 * already has this token once Connectivity is activated, so this is the
 * cheapest real feedback loop available, not a new piece of
 * infrastructure. No reply mechanism here - the owner reads feedback
 * directly from the registry for now (see relay_save_feedback()).
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/_lib/auth.php';
require_once __DIR__ . '/../../src/db/registry.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$installation = relay_require_auth();
$body = json_decode(file_get_contents('php://input'), true);
$message = trim((string)($body['message'] ?? ''));

if ($message === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'message required']);
    exit;
}

if (mb_strlen($message) > 4000) {
    $message = mb_substr($message, 0, 4000);
}

$id = relay_save_feedback($installation['id'], $message);
relay_audit('feedback.submit', 'installation', $installation['id']);

echo json_encode(['ok' => true, 'id' => $id]);
