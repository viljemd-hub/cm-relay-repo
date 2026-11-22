<?php
/**
 * CM Relay
 * File: src/signup/signup_lib.php
 * Author: Viljem Dvojmoč
 * Assistant: Claude
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 *
 * Shared logic for the external (non-CM) signup flow, used by
 * admin/review_queue.php, api/v1/signup/request.php, api/v1/signup/approve.php
 * and public/confirmation.php - no duplicated queries/business logic
 * between the admin UI and the API surface.
 *
 * Three-stage flow, invitation-first (owner's explicit correction
 * 2026-11-18 - access is never self-serve/open, same principle Channex
 * itself applied before granting the reseller agreement):
 *   1. Operator issues a signup_invites row (relay_create_signup_invite)
 *      and sends the resulting invite_code to a candidate by hand. Only a
 *      valid, unconsumed code lets public/signup.php's form render/submit.
 *   2. Candidate submits the form -> a 'pending_review' installation +
 *      external_consents row + pending_signup payload file (unchanged from
 *      before) + the invite is marked 'submitted', linked to that
 *      installation (relay_consume_signup_invite).
 *   3. Operator reviews (admin/review_queue.php) and approves
 *      (relay_approve_external_signup) - this provisions Channex and
 *      records the business terms, but does NOT issue the final bearer
 *      token yet. It creates a signup_confirmations row instead and the
 *      operator sends ITS link to the candidate. Only when the candidate
 *      opens that link (public/confirmation.php) is the real bearer token
 *      finally generated, hashed+stored, and the installation flipped to
 *      'active' - deferred to the moment of actual need, same "shown once"
 *      principle api/v1/installations/register.php already uses for
 *      CM-software installations.
 */

declare(strict_types=1);

require_once __DIR__ . '/../db/registry.php';
require_once __DIR__ . '/../db/mapping_writer.php';
require_once __DIR__ . '/../providers/ChannexAdapter.php';
require_once __DIR__ . '/../../api/v1/_lib/auth.php'; // relay_generate_token()

function relay_generate_opaque_code(): string
{
    return bin2hex(random_bytes(16));
}

/**
 * Stage 1: operator issues an invite. $candidateName/$candidateEmail are
 * free-text notes for the operator's own tracking - never validated against
 * what the candidate later actually submits (the submitted liability
 * signer_name/signer_email is its own independent record).
 */
function relay_create_signup_invite(string $candidateName, string $candidateEmail): array
{
    $db = relay_registry_db();
    $id = relay_uuid_v4();
    $code = relay_generate_opaque_code();

    $db->prepare('
        INSERT INTO signup_invites (id, invite_code, candidate_name, candidate_email, status, created_at)
        VALUES (?, ?, ?, ?, "issued", ?)
    ')->execute([$id, $code, $candidateName, $candidateEmail, gmdate('c')]);

    relay_audit('signup_invite.create', 'signup_invite', $id);

    return ['id' => $id, 'invite_code' => $code];
}

/**
 * @return array<string,mixed>|null the invite row if $inviteCode exists and
 *   is still 'issued' (not yet submitted/expired), else null.
 */
function relay_validate_signup_invite(string $inviteCode): ?array
{
    if ($inviteCode === '') {
        return null;
    }
    $stmt = relay_registry_db()->prepare('SELECT * FROM signup_invites WHERE invite_code = ? AND status = "issued"');
    $stmt->execute([$inviteCode]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

function relay_consume_signup_invite(string $inviteCode, string $installationId): void
{
    $db = relay_registry_db();
    $db->prepare('UPDATE signup_invites SET status = "submitted", installation_id = ?, consumed_at = ? WHERE invite_code = ?')
        ->execute([$installationId, gmdate('c'), $inviteCode]);
    relay_audit('signup_invite.consume', 'installation', $installationId);
}

/**
 * Every invite not yet submitted - what admin/review_queue.php shows as
 * "outstanding invites" (sent but no response yet).
 */
function relay_list_outstanding_invites(): array
{
    return relay_registry_db()
        ->query('SELECT * FROM signup_invites WHERE status = "issued" ORDER BY created_at DESC')
        ->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Stage 2 read side: every pending (submitted, not yet approved) external
 * signup, with its consent record and raw submitted payload - what an
 * operator needs to decide whether to approve. Shared by admin/review_queue.php
 * and api/v1/signup/approve.php's GET - same query, one place.
 */
function relay_list_pending_signups(): array
{
    $rows = relay_registry_db()->query("
        SELECT i.id, i.unit_count, i.locale, i.created_at,
               ec.signer_name, ec.signer_email, ec.liability_accepted_at, ec.ip_address
        FROM installations i
        LEFT JOIN external_consents ec ON ec.installation_id = i.id
        WHERE i.kind = 'external_direct' AND i.status = 'pending_review'
        ORDER BY i.created_at
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $payload = json_decode((string)@file_get_contents(relay_pending_signup_path($row['id'])), true);
        $row['payload'] = is_array($payload) ? $payload : null;
    }
    unset($row);

    return $rows;
}

/**
 * Stage 3: provisions Channex for a reviewed, pending external signup and
 * records the agreed business terms - deliberately does NOT issue the
 * final bearer token (see this file's top doc comment). Leaves
 * installations.status at 'approved_pending_confirmation' and creates the
 * signup_confirmations row/token the operator sends next.
 *
 * @return array{ok:bool, error?:string, confirmation_token?:string, property_id?:string}
 */
function relay_approve_external_signup(string $installationId): array
{
    $db = relay_registry_db();

    $stmt = $db->prepare("SELECT * FROM installations WHERE id = ? AND kind = 'external_direct' AND status = 'pending_review'");
    $stmt->execute([$installationId]);
    if ($stmt->fetch(PDO::FETCH_ASSOC) === false) {
        return ['ok' => false, 'error' => 'no pending external installation with that id'];
    }

    $payload = json_decode((string)@file_get_contents(relay_pending_signup_path($installationId)), true);
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'pending signup payload missing or unreadable'];
    }

    $apiKey = (json_decode((string)@file_get_contents(relay_secrets_root() . '/channex_production.json'), true))['api_key'] ?? null;
    if (!$apiKey) {
        return ['ok' => false, 'error' => 'relay misconfigured - no channex api key'];
    }

    $property = $payload['property'];
    // cm_unit_id must be stable and unique per unit within this property -
    // the signup form has no local CM unit concept to borrow, so
    // index-based ids (unit_0, unit_1, ...) are assigned here, in
    // submission order.
    $units = array_values($payload['units']);
    foreach ($units as $i => &$unit) {
        $unit['cm_unit_id'] = 'unit_' . $i;
        $unit['currency'] = $unit['currency'] ?? ($property['currency'] ?? null);
    }
    unset($unit);

    $adapter = new ChannexAdapter($apiKey);
    $result = $adapter->provisionProperty($property, $units);
    if (!$result->ok) {
        return ['ok' => false, 'error' => $result->error];
    }

    $propertyId = $result->data['property_id'];
    foreach ($result->data['units'] as $createdUnit) {
        relay_write_mapping($db, $installationId, [
            'cm_property_id' => $installationId, // one external signup = one property, same convention as cm_connector_provision_property()
            'provider' => 'channex',
            'provider_property_id' => $propertyId,
            'cm_unit_id' => $createdUnit['cm_unit_id'],
            'provider_room_type_id' => $createdUnit['room_type_id'],
            'provider_rate_plan_id' => $createdUnit['rate_plan_id'],
        ]);
    }

    // Photos (§"Photos - ship now" in the 2026-11-20 plan) - attached to the
    // first created unit's room_type. External signups are typically small
    // (1-3 units) and the form collects one shared photo set, not a
    // per-unit gallery, so this simplification covers the common case
    // without complicating the signup form. A failed photo add is logged
    // but never fails the whole approval - the property/units are already
    // real by this point, losing one photo isn't worth rolling that back.
    if (!empty($payload['photos']) && !empty($result->data['units'][0]['room_type_id'])) {
        $firstRoomTypeId = $result->data['units'][0]['room_type_id'];
        foreach ($payload['photos'] as $photo) {
            $photoUrl = (string)($photo['url'] ?? '');
            if ($photoUrl === '') {
                continue;
            }
            $photoResult = $adapter->addPhoto($propertyId, $firstRoomTypeId, $photoUrl, (int)($photo['position'] ?? 0));
            if (!$photoResult->ok) {
                relay_audit('property.photo.add_failed', 'installation', $installationId);
            }
        }
    }

    $agreement = is_array($payload['agreement'] ?? null) ? $payload['agreement'] : [];

    // Pricing is policy-fixed (§5.2), never taken from the payload as-is -
    // recomputed here from the actual provisioned unit count, same
    // function request.php already used for the live preview. The one-time
    // fee has no dedicated column (it's always re-derivable from unit
    // count), so it's recorded as a clearly-marked prefix on notes - a
    // permanent snapshot of what applied at approval time, in case the
    // policy itself changes later.
    $pricing = relay_calculate_connection_pricing(count($units));
    $notes = '[One-time connection fee at approval: EUR ' . number_format($pricing['one_time_eur'], 2) . '] '
        . (string)($agreement['notes'] ?? '');

    $db->prepare('
        INSERT INTO service_entitlements
            (id, installation_id, service, plan, status, active_from, price_eur_month, contract_ref, relay_account_email, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ')->execute([
        relay_uuid_v4(),
        $installationId,
        'ota_connectivity',
        (string)($agreement['plan'] ?? ''),
        'active',
        gmdate('c'),
        (string)$pricing['monthly_eur'],
        (string)($agreement['contract_ref'] ?? ''),
        (string)($agreement['relay_account_email'] ?? ''),
        $notes,
    ]);

    $db->prepare("UPDATE installations SET status = 'approved_pending_confirmation' WHERE id = ?")->execute([$installationId]);

    $confirmation = relay_create_signup_confirmation($installationId);

    relay_audit('installation.signup.approve', 'installation', $installationId);

    return ['ok' => true, 'property_id' => $propertyId, 'confirmation_token' => $confirmation['confirmation_token']];
}

function relay_create_signup_confirmation(string $installationId): array
{
    $db = relay_registry_db();
    $id = relay_uuid_v4();
    $token = relay_generate_opaque_code();

    $db->prepare('
        INSERT INTO signup_confirmations (id, installation_id, confirmation_token, created_at)
        VALUES (?, ?, ?, ?)
    ')->execute([$id, $installationId, $token, gmdate('c')]);

    return ['id' => $id, 'confirmation_token' => $token];
}

/**
 * Read-only check for public/confirmation.php's GET (page load) - whether
 * $confirmationToken is real and not yet revealed. Deliberately separate
 * from relay_confirm_signup() below (the actual token-issuing mutation),
 * so a page load/link preview/crawler bot can never burn a one-time reveal
 * by itself - only a deliberate POST (the page's "Reveal my token" button)
 * does that.
 *
 * @return array<string,mixed>|null
 */
function relay_lookup_signup_confirmation(string $confirmationToken): ?array
{
    $stmt = relay_registry_db()->prepare('SELECT * FROM signup_confirmations WHERE confirmation_token = ?');
    $stmt->execute([$confirmationToken]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

/**
 * Stage 3 finish - the actual mutation, called only from public/
 * confirmation.php's POST handler (never on a bare page load, see
 * relay_lookup_signup_confirmation() above). Generates the REAL bearer
 * token for the first and only time, hashes+stores it, flips the
 * installation to 'active'. Refuses a second call for an
 * already-'viewed_at' confirmation - exactly-once, not "latest reload
 * wins" - if the candidate lost the token after viewing it, that is a
 * genuinely new case for the operator to handle (e.g. rotate/reissue),
 * not something this function silently does again.
 *
 * @return array{ok:bool, error?:string, token?:string, installation_id?:string}
 */
function relay_confirm_signup(string $confirmationToken): array
{
    $db = relay_registry_db();
    $confirmation = relay_lookup_signup_confirmation($confirmationToken);
    if ($confirmation === null) {
        return ['ok' => false, 'error' => 'invalid confirmation link'];
    }
    if ($confirmation['viewed_at'] !== null) {
        return ['ok' => false, 'error' => 'this confirmation link was already used - contact the operator if the token was lost'];
    }

    $installationId = $confirmation['installation_id'];
    $instStmt = $db->prepare("SELECT status FROM installations WHERE id = ? AND kind = 'external_direct'");
    $instStmt->execute([$installationId]);
    $installation = $instStmt->fetch(PDO::FETCH_ASSOC);
    if ($installation === false) {
        return ['ok' => false, 'error' => 'installation not found'];
    }
    if ($installation['status'] !== 'approved_pending_confirmation') {
        return ['ok' => false, 'error' => 'installation not in an approved state'];
    }

    $token = relay_generate_token();
    $db->prepare("UPDATE installations SET token_hash = ?, status = 'active', last_seen_at = ? WHERE id = ?")
        ->execute([hash('sha256', $token), gmdate('c'), $installationId]);
    $db->prepare('UPDATE signup_confirmations SET viewed_at = ? WHERE id = ?')
        ->execute([gmdate('c'), $confirmation['id']]);

    relay_audit('installation.signup.confirm', 'installation', $installationId);

    return ['ok' => true, 'token' => $token, 'installation_id' => $installationId];
}

/**
 * Pricing policy decided 2026-11-19 (CM_Connectivity_Community_Contract_v0.2.md
 * §5.2) - the SAME degressive monthly per-unit table applies to every
 * participant, CM-software or external (fairness to every participant was
 * the owner's own explicit reason). External signups additionally pay a
 * ONE-TIME connection fee, Channex-style (flat + linear per unit, but
 * one-time, not monthly) - this reflects the real admin time spent
 * reviewing/approving each external signup, which CM-software customers
 * don't incur the same way (their consent already happened locally via
 * cm_connector.php).
 *
 * Deliberately a pure function of $unitCount, no DB/side effects - called
 * both for a live client-side preview (public/signup.php, mirrored in JS -
 * never trust that copy, always recompute here server-side) and for the
 * authoritative figure stored at submission/approval time.
 *
 * @return array{unit_count:int, one_time_eur:float, monthly_eur:float}
 */
function relay_calculate_connection_pricing(int $unitCount): array
{
    $unitCount = max(0, $unitCount);

    // €30 covers the property + first unit, +€3 per unit after that.
    $oneTime = $unitCount > 0 ? 30.0 + max(0, $unitCount - 1) * 3.0 : 0.0;

    // Cumulative degressive tiers from §5.1: 1-3 @ €3.00, 4-6 @ €2.55,
    // 7-10 @ €2.10, 11+ @ €1.65 - each tier only applies to the units that
    // actually fall within it, not retroactively to all units.
    $tiers = [[3, 3.00], [3, 2.55], [4, 2.10], [PHP_INT_MAX, 1.65]];
    $remaining = $unitCount;
    $monthly = 0.0;
    foreach ($tiers as [$tierSize, $tierRate]) {
        if ($remaining <= 0) {
            break;
        }
        $take = min($remaining, $tierSize);
        $monthly += $take * $tierRate;
        $remaining -= $take;
    }

    return [
        'unit_count' => $unitCount,
        'one_time_eur' => round($oneTime, 2),
        'monthly_eur' => round($monthly, 2),
    ];
}

/**
 * Owner correction 2026-11-20: NO Channex-affecting action should be
 * automatic, not even for an already-connected CM-software installation
 * (previously api/v1/properties/provision.php / photos.php called Channex
 * directly, the instant an authenticated installation asked). These
 * functions move that into the same request -> human review -> approve
 * shape external signups already use, minus the invite-gate (already
 * authenticated, no anonymous-access problem) and minus a mailed
 * confirmation link (no new secret to disclose - the installation already
 * holds a valid bearer token, so it picks up the result by polling its own
 * already-authenticated endpoint, see relay_get_channex_request_status()).
 */

/**
 * Creates a pending channex_requests row - never touches Channex. Refuses
 * a second concurrent pending request for the same installation+property
 * (one in flight at a time; approve or reject the existing one first).
 *
 * @param array $payload for kind='provision': {property, units}. For
 *   kind='photo': {cm_unit_id, url, position?, description?}.
 * @return array{ok:bool, error?:string, request_id?:string, tracking_token?:string}
 */
function relay_create_channex_request(string $installationId, string $cmPropertyId, string $kind, array $payload): array
{
    $db = relay_registry_db();

    $existing = $db->prepare("SELECT 1 FROM channex_requests WHERE installation_id = ? AND cm_property_id = ? AND status = 'pending'");
    $existing->execute([$installationId, $cmPropertyId]);
    if ($existing->fetch() !== false) {
        return ['ok' => false, 'error' => 'a request for this property is already pending review'];
    }

    $id = relay_uuid_v4();
    $token = relay_generate_opaque_code();

    $db->prepare('
        INSERT INTO channex_requests (id, installation_id, cm_property_id, kind, payload_json, status, tracking_token, created_at)
        VALUES (?, ?, ?, ?, ?, "pending", ?, ?)
    ')->execute([$id, $installationId, $cmPropertyId, $kind, json_encode($payload), $token, gmdate('c')]);

    relay_audit('channex_request.create', 'channex_request', $id, $installationId);

    return ['ok' => true, 'request_id' => $id, 'tracking_token' => $token];
}

/**
 * Every pending channex_requests row, for admin/review_queue.php - shaped
 * to merge cleanly alongside relay_list_pending_signups()'s rows (caller
 * tags each with its own 'type').
 */
function relay_list_pending_channex_requests(): array
{
    return relay_registry_db()->query("
        SELECT cr.id, cr.installation_id, cr.cm_property_id, cr.kind, cr.payload_json, cr.created_at,
               i.edition, i.cm_version, i.locale
        FROM channex_requests cr
        JOIN installations i ON i.id = cr.installation_id
        WHERE cr.status = 'pending'
        ORDER BY cr.created_at
    ")->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * The actual Channex-calling logic, run only once a human approves in
 * admin/review_queue.php. 'provision' kind is the per-unit-idempotent
 * create-property-and-units logic (lifted from the old provision.php,
 * same reuse of relay_write_mapping()); 'photo' kind is a single
 * addPhoto() call (lifted from the old photos.php). On a Channex-side
 * failure the row is left 'pending' (not auto-rejected) so the operator
 * can see the error and retry approval rather than losing the request.
 *
 * @return array{ok:bool, error?:string, result?:array}
 */
function relay_approve_channex_request(string $requestId): array
{
    $db = relay_registry_db();
    $stmt = $db->prepare("SELECT * FROM channex_requests WHERE id = ? AND status = 'pending'");
    $stmt->execute([$requestId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        return ['ok' => false, 'error' => 'no pending request with that id'];
    }

    $payload = json_decode($row['payload_json'], true);
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'request payload missing or unreadable'];
    }

    $apiKey = (json_decode((string)@file_get_contents(relay_secrets_root() . '/channex_production.json'), true))['api_key'] ?? null;
    if (!$apiKey) {
        return ['ok' => false, 'error' => 'relay misconfigured - no channex api key'];
    }
    $adapter = new ChannexAdapter($apiKey);

    if ($row['kind'] === 'photo') {
        $result = relay_approve_photo_request($db, $adapter, $row['installation_id'], $row['cm_property_id'], $payload);
    } else {
        $result = relay_approve_provision_request($db, $adapter, $row['installation_id'], $row['cm_property_id'], $payload);
    }

    if (!$result['ok']) {
        return $result;
    }

    $db->prepare("UPDATE channex_requests SET status = 'approved', reviewed_at = ?, result_json = ? WHERE id = ?")
        ->execute([gmdate('c'), json_encode($result['result']), $requestId]);
    relay_audit('channex_request.approve', 'channex_request', $requestId, $row['installation_id']);

    return $result;
}

/**
 * Same idempotent-per-unit logic api/v1/properties/provision.php used to
 * run directly on an unauthenticated-review basis - unchanged behavior,
 * just moved behind the approval gate.
 */
function relay_approve_provision_request(PDO $db, ChannexAdapter $adapter, string $installationId, string $cmPropertyId, array $payload): array
{
    $property = is_array($payload['property'] ?? null) ? $payload['property'] : [];
    $units = is_array($payload['units'] ?? null) ? $payload['units'] : [];
    if (empty($property) || empty($units)) {
        return ['ok' => false, 'error' => 'property and units required in request payload'];
    }

    $existingPropStmt = $db->prepare('SELECT id, provider_property_id FROM property_mappings WHERE installation_id = ? AND cm_property_id = ?');
    $existingPropStmt->execute([$installationId, $cmPropertyId]);
    $existingProp = $existingPropStmt->fetch(PDO::FETCH_ASSOC);

    $existingUnitIds = [];
    if ($existingProp !== false) {
        $existingUnitsStmt = $db->prepare('SELECT cm_unit_id FROM unit_mappings WHERE property_mapping_id = ?');
        $existingUnitsStmt->execute([$existingProp['id']]);
        $existingUnitIds = array_column($existingUnitsStmt->fetchAll(PDO::FETCH_ASSOC), 'cm_unit_id');
    }

    $newUnits = array_values(array_filter($units, static fn($u) => !in_array($u['cm_unit_id'] ?? null, $existingUnitIds, true)));

    if ($existingProp !== false && empty($newUnits)) {
        $existingUnits = $db->prepare('SELECT cm_unit_id, provider_room_type_id AS room_type_id, provider_rate_plan_id AS rate_plan_id FROM unit_mappings WHERE property_mapping_id = ?');
        $existingUnits->execute([$existingProp['id']]);
        return ['ok' => true, 'result' => [
            'already_provisioned' => true,
            'property_mapping_id' => $existingProp['id'],
            'property_id' => $existingProp['provider_property_id'],
            'units' => $existingUnits->fetchAll(PDO::FETCH_ASSOC),
        ]];
    }

    if ($existingProp !== false) {
        $propertyResult = $adapter->createOrAttachProperty(['provider_property_id' => $existingProp['provider_property_id']]);
    } else {
        $propertyResult = $adapter->createProperty($property);
    }
    if (!$propertyResult->ok) {
        return ['ok' => false, 'error' => $propertyResult->error];
    }
    $propertyId = $propertyResult->data['property_id'];

    $mappedUnits = [];
    foreach ($newUnits as $unit) {
        $unit['currency'] = $unit['currency'] ?? ($property['currency'] ?? null);
        $unitResult = $adapter->createRoomTypeWithRatePlan($propertyId, $unit);
        if (!$unitResult->ok) {
            return ['ok' => false, 'error' => $unitResult->error, 'result' => [
                'property_id' => $propertyId,
                'created_before_failure' => $mappedUnits,
            ]];
        }
        relay_audit('channex.unit.create', 'provider_room_type', $unitResult->data['room_type_id']);

        $written = relay_write_mapping($db, $installationId, [
            'cm_property_id' => $cmPropertyId,
            'provider' => 'channex',
            'provider_property_id' => $propertyId,
            'cm_unit_id' => $unitResult->data['cm_unit_id'],
            'provider_room_type_id' => $unitResult->data['room_type_id'],
            'provider_rate_plan_id' => $unitResult->data['rate_plan_id'],
        ]);
        $mappedUnits[] = $written + [
            'cm_unit_id' => $unitResult->data['cm_unit_id'],
            'room_type_id' => $unitResult->data['room_type_id'],
            'rate_plan_id' => $unitResult->data['rate_plan_id'],
        ];
    }

    return ['ok' => true, 'result' => [
        'already_provisioned' => false,
        'property_id' => $propertyId,
        'property_mapping_id' => $mappedUnits[0]['property_mapping_id'] ?? ($existingProp['id'] ?? null),
        'units' => $mappedUnits,
    ]];
}

/**
 * Same lookup+addPhoto() logic api/v1/properties/photos.php used to run
 * directly - unchanged, just moved behind the approval gate.
 */
function relay_approve_photo_request(PDO $db, ChannexAdapter $adapter, string $installationId, string $cmPropertyId, array $payload): array
{
    $cmUnitId = trim((string)($payload['cm_unit_id'] ?? ''));
    $url = trim((string)($payload['url'] ?? ''));
    if ($cmUnitId === '' || $url === '') {
        return ['ok' => false, 'error' => 'cm_unit_id and url required in request payload'];
    }

    $stmt = $db->prepare('
        SELECT pm.provider_property_id, um.provider_room_type_id
        FROM property_mappings pm
        JOIN unit_mappings um ON um.property_mapping_id = pm.id
        WHERE pm.installation_id = ? AND pm.cm_property_id = ? AND um.cm_unit_id = ? AND pm.provider = "channex"
        LIMIT 1
    ');
    $stmt->execute([$installationId, $cmPropertyId, $cmUnitId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        return ['ok' => false, 'error' => 'no mounted unit for this cm_property_id/cm_unit_id'];
    }

    $result = $adapter->addPhoto(
        $row['provider_property_id'],
        $row['provider_room_type_id'],
        $url,
        (int)($payload['position'] ?? 0),
        isset($payload['description']) ? (string)$payload['description'] : null
    );
    if (!$result->ok) {
        return ['ok' => false, 'error' => $result->error];
    }

    relay_audit('property.photo.add', 'unit_mapping', $cmUnitId, $installationId);

    return ['ok' => true, 'result' => ['photo_added' => true, 'cm_unit_id' => $cmUnitId]];
}

function relay_reject_channex_request(string $requestId, string $reason = ''): array
{
    $db = relay_registry_db();
    $stmt = $db->prepare("UPDATE channex_requests SET status = 'rejected', reviewed_at = ?, reviewed_by = ? WHERE id = ? AND status = 'pending'");
    $stmt->execute([gmdate('c'), $reason, $requestId]);
    if ($stmt->rowCount() === 0) {
        return ['ok' => false, 'error' => 'no pending request with that id'];
    }
    relay_audit('channex_request.reject', 'channex_request', $requestId);
    return ['ok' => true];
}

/**
 * Polling endpoint for the installation's own admin page - no secret is
 * ever returned here (the caller already authenticated with its own
 * bearer token), just status + (once approved) the resulting mapping.
 */
function relay_get_channex_request_status(string $installationId, string $cmPropertyId): ?array
{
    $stmt = relay_registry_db()->prepare('
        SELECT id, kind, status, created_at, reviewed_at, result_json
        FROM channex_requests
        WHERE installation_id = ? AND cm_property_id = ?
        ORDER BY created_at DESC LIMIT 1
    ');
    $stmt->execute([$installationId, $cmPropertyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        return null;
    }

    $result = $row['result_json'] !== null ? json_decode($row['result_json'], true) : null;
    return [
        'request_id' => $row['id'],
        'kind' => $row['kind'],
        'status' => $row['status'],
        'created_at' => $row['created_at'],
        'reviewed_at' => $row['reviewed_at'],
        'result' => $result,
    ];
}
