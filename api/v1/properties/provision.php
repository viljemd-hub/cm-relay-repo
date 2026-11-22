<?php
/**
 * CM Relay
 * File: api/v1/properties/provision.php
 * Author: Viljem Dvojmoč
 * Assistant: Claude
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 *
 * RETIRED 2026-11-20. This used to call Channex directly the instant any
 * authenticated installation asked - owner correction: no Channex-affecting
 * action should ever be automatic, not even for an already-connected
 * CM-software installation. Neutered (not deleted) rather than removed, so
 * a stale client gets a clear, permanent 410 instead of a generic 404, and
 * so nobody later re-adds a same-named file that accidentally restores the
 * direct-call bypass this change closes.
 *
 * Replacement: POST api/v1/properties/request.php (creates a pending
 * review row, never touches Channex) + GET
 * api/v1/properties/request_status.php (poll for the outcome). Approval
 * (the only place that still actually calls Channex) now lives in
 * admin/review_queue.php via relay_approve_channex_request() in
 * src/signup/signup_lib.php - same underlying provisioning logic this file
 * used to run, just moved behind a human review gate.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../_lib/auth.php';

relay_require_auth();

http_response_code(410);
echo json_encode([
    'ok' => false,
    'error' => 'retired - use POST properties/request.php and poll GET properties/request_status.php; all Channex-affecting actions now require operator review',
]);
