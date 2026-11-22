<?php
/**
 * CM Relay
 * File: api/v1/properties/photos.php
 * Author: Viljem Dvojmoč
 * Assistant: Claude
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 *
 * RETIRED 2026-11-20. Same reasoning as properties/provision.php's
 * tombstone comment - attaching a photo is still a real Channex API call,
 * and owner correction 2026-11-20 says NO Channex-affecting action should
 * be automatic, even for an already-connected CM-software installation.
 * Neutered (not deleted) so a stale client gets a clear, permanent 410.
 *
 * Replacement: POST api/v1/properties/request.php with
 * {kind:'photo', cm_property_id, cm_unit_id, url, position?, description?}
 * (creates a pending review row, never touches Channex), approved via
 * admin/review_queue.php -> relay_approve_channex_request() in
 * src/signup/signup_lib.php (same addPhoto() logic this file used to run
 * directly, now behind a human review gate).
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../_lib/auth.php';

relay_require_auth();

http_response_code(410);
echo json_encode([
    'ok' => false,
    'error' => 'retired - use POST properties/request.php with kind:"photo"; all Channex-affecting actions now require operator review',
]);
