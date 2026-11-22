<?php
/**
 * CM Relay
 * File: api/v1/signup/request.php
 * Author: Viljem Dvojmoč
 * Assistant: Claude
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 *
 * POST /api/v1/signup/request.php - public endpoint, but NOT open access:
 * requires a valid, unconsumed invite_code (see src/signup/signup_lib.php's
 * top doc comment - CM Relay access is invitation-first, never self-serve,
 * owner's explicit correction 2026-11-18). No bearer token can exist yet
 * for a prospect who has never talked to Relay before, so that layer alone
 * isn't the gate - the invite_code is. This endpoint deliberately does NOT
 * touch Channex at all - it only records a 'pending_review' installation +
 * the liability consent + the raw submitted payload, for a human operator
 * to review via admin/review_queue.php. See public/signup.php for the form that
 * posts here.
 *
 * Body: {
 *   invite_code: <from the operator-sent invite link>,
 *   property: {title, currency, country, city, property_type?},
 *   units: [{title, rate, occ_adults?}],
 *   agreement: {plan?, price_eur_month?, contract_ref?, relay_account_email?, notes?},
 *   liability: {accepted: true, signer_name, signer_email},
 *   form_loaded_at: <ms epoch, set by the form page's own JS on load>,
 *   website: "" (honeypot - a real visitor never sees or fills this field)
 * }
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../src/signup/signup_lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

if (!relay_signup_attempt_ok($ip, 3)) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'too_many_attempts']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    $body = [];
}

$inviteCode = trim((string)($body['invite_code'] ?? ''));
$invite = relay_validate_signup_invite($inviteCode);
if ($invite === null) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'invalid or already-used invite code']);
    exit;
}

// Honeypot: a real browser never populates this field (hidden via CSS in
// public/signup.php). Bots filling every field get a generic-looking
// success so they don't learn they were caught, but nothing is persisted.
if (!empty($body['website'])) {
    echo json_encode(['ok' => true, 'status' => 'pending_review']);
    exit;
}

// A form submitted faster than a human could plausibly read+fill it is
// almost certainly scripted. form_loaded_at is set client-side on page load.
$formLoadedAt = (int)($body['form_loaded_at'] ?? 0);
if ($formLoadedAt <= 0 || (time() * 1000 - $formLoadedAt) < 4000) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'submitted_too_fast']);
    exit;
}

$property = is_array($body['property'] ?? null) ? $body['property'] : [];
$units = is_array($body['units'] ?? null) ? $body['units'] : [];
$photos = is_array($body['photos'] ?? null) ? $body['photos'] : [];
// Collect-only, 2026-11-20 - never auto-wired into a Channex call, see
// src/providers/ChannexAdapter.php's doc comment on the unverified
// hotel_policy/cancellation_policy/facility create-API shape.
$google = is_array($body['google'] ?? null) ? $body['google'] : [];
$agreement = is_array($body['agreement'] ?? null) ? $body['agreement'] : [];
$liability = is_array($body['liability'] ?? null) ? $body['liability'] : [];

foreach (['title', 'currency', 'country', 'city'] as $required) {
    if (empty($property[$required])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => "property.$required required"]);
        exit;
    }
}
if (empty($units)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'at least one unit required']);
    exit;
}
foreach ($units as $unit) {
    if (empty($unit['title']) || !isset($unit['rate'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'each unit needs title and rate']);
        exit;
    }
}

$signerEmail = trim((string)($liability['signer_email'] ?? ''));
if (empty($liability['accepted']) || trim((string)($liability['signer_name'] ?? '')) === '' || !filter_var($signerEmail, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'liability consent (accepted, signer_name, valid signer_email) required']);
    exit;
}

relay_signup_attempt_record($ip);

$db = relay_registry_db();
$installationId = relay_uuid_v4();

$db->prepare('
    INSERT INTO installations
        (id, installation_uuid, token_hash, edition, cm_version, connector_version, unit_count, locale, status, kind, created_at, last_seen_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
')->execute([
    $installationId,
    relay_uuid_v4(), // installation_uuid: no real CM install exists for an external signup, generate one to satisfy the UNIQUE constraint
    '', // no token yet - issued only on approval
    'external',
    '',
    '',
    count($units),
    (string)($body['locale'] ?? 'en'),
    'pending_review',
    'external_direct',
    gmdate('c'),
    gmdate('c'),
]);

$db->prepare('
    INSERT INTO external_consents
        (id, installation_id, liability_accepted_at, liability_text_version, signer_name, signer_email, ip_address, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
')->execute([
    relay_uuid_v4(),
    $installationId,
    gmdate('c'),
    'v1',
    (string)$liability['signer_name'],
    $signerEmail,
    $ip,
    gmdate('c'),
]);

// Pricing (CM_Connectivity_Community_Contract_v0.2.md §5.2) is policy-fixed,
// never client-supplied - computed here from the real submitted unit count
// regardless of whatever (if anything) the caller sent in agreement.
$agreement['pricing'] = relay_calculate_connection_pricing(count($units));

file_put_contents(
    relay_pending_signup_path($installationId),
    json_encode(['property' => $property, 'units' => $units, 'photos' => $photos, 'google' => $google, 'agreement' => $agreement], JSON_PRETTY_PRINT)
);

relay_consume_signup_invite($inviteCode, $installationId);
relay_audit('installation.signup.request', 'installation', $installationId);

echo json_encode(['ok' => true, 'status' => 'pending_review', 'installation_id' => $installationId]);
