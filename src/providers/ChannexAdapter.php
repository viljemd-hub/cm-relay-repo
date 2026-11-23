<?php
/**
 * CM Relay — Channex provider adapter (Faza D)
 * File: src/providers/ChannexAdapter.php
 * Author: Viljem Dvojmoč
 * Assistant: Claude
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 *
 * First real CmConnectivityProviderAdapter implementation, built and verified
 * against the free Channex staging/sandbox environment (https://staging.channex.io),
 * demo property "Apartma Matevž Demo" (2026-09-16).
 *
 * 2026-10-13: PMS certification passed, subscribed to the paid Whitelabel
 * plan, switched to the real production API (https://app.channex.io) -
 * staging is no longer the live target, see secrets/channex_production.json.
 *
 * Verified live against the sandbox:
 *   - GET  /api/v1/properties, /api/v1/room_types, /api/v1/rate_plans
 *   - GET/POST /api/v1/restrictions (rate + min_stay_arrival/through, keyed by rate_plan_id)
 *   - GET/POST /api/v1/availability (availability, keyed by room_type_id - see below)
 *
 * NOT yet verified against a live channel/OTA connection (sandbox has none configured):
 *   requestFullSync(), normalizeIncomingEvent(), connectionStatus(). Implemented from
 *   documented shape only - treat as unverified until exercised against a real channel.
 *
 * Two real Channex quirks worth remembering:
 *  - availability sent in a push is silently clamped down to the room type's
 *    count_of_rooms - never assume the value you sent is the value that stuck;
 *    a caller that needs certainty must re-GET after pushing.
 *  - availability is NOT part of /restrictions and is NOT keyed by rate_plan_id,
 *    despite /restrictions accepting an `availability` field without complaint -
 *    it silently no-ops there instead of erroring. Confirmed by Channex support
 *    (Andrew, 2026-09-17) after a push-then-revert bug looked like a platform
 *    flake for a while: the correct endpoint is /api/v1/availability, keyed by
 *    room_type_id. Use pushAvailability()/pushAvailabilityBatch() below, never
 *    the `availability` key inside pushRestrictions()/pushBatch().
 *
 * Google Vacation Rentals eligibility (confirmed 2026-11-20 via Channex docs
 * + Google's own Hotel Center help page, NOT yet implemented here): activating
 * the Google channel needs >=8 property photos (addPhoto() below already
 * handles this part, proven live) PLUS phone, website URL, latitude/
 * longitude, timezone, a hotel policy, a cancellation policy, and >=1
 * facility on the property. public/signup.php collects these fields
 * (property.google.*) but they are deliberately NOT wired into any create
 * call here - the real Channex API shape for creating a hotel_policy/
 * cancellation_policy/facility object was never verified against the live
 * API (only the requirement's existence was confirmed via docs/search).
 * Before writing createHotelPolicy()/createCancellationPolicy()/
 * createFacility() methods, verify the actual request/response shape
 * empirically against the real production key first, same as every other
 * quirk documented in this block - don't assume a shape from docs alone.
 */

declare(strict_types=1);

require_once __DIR__ . '/CmConnectivityProviderAdapter.php';
require_once __DIR__ . '/ProviderResult.php';
require_once __DIR__ . '/ProviderStatus.php';
require_once __DIR__ . '/../db/registry.php';

final class ChannexAdapter implements CmConnectivityProviderAdapter
{
    private const BASE_URL = 'https://app.channex.io/api/v1';

    public function __construct(private readonly string $apiKey)
    {
    }

    /**
     * Back-compat: attaches an already-existing Channex property if
     * provider_property_id is given, otherwise creates a new one. New
     * callers provisioning a fresh property should call createProperty()
     * directly - this wrapper exists only because the original stub shape
     * (attach-only) may still have callers expecting it.
     */
    public function createOrAttachProperty(array $property): ProviderResult
    {
        if (!empty($property['provider_property_id'])) {
            $result = $this->request('GET', '/properties/' . urlencode($property['provider_property_id']));
            if (!$result->ok) {
                return $result;
            }

            return new ProviderResult(true, null, ['property_id' => $result->data['data']['id'] ?? null]);
        }

        return $this->createProperty($property);
    }

    /**
     * Creates a brand-new Channex property. $property: title, currency,
     * country, city, property_type (defaults to 'apartment', matching the
     * shape already proven live in bin/create_production_property.php).
     * Replaces that one-off script's hardcoded logic as a reusable method -
     * see provisionProperty() for the full property+units orchestration.
     */
    public function createProperty(array $property): ProviderResult
    {
        foreach (['title', 'currency', 'country', 'city'] as $required) {
            if (empty($property[$required])) {
                return new ProviderResult(false, "$required required");
            }
        }

        if (!relay_rate_limit_ok('channex', 20)) {
            return new ProviderResult(false, 'channex rate limit reached, try again shortly');
        }
        $result = $this->request('POST', '/properties', ['property' => [
            'title' => $property['title'],
            'currency' => $property['currency'],
            'country' => $property['country'],
            'city' => $property['city'],
            'property_type' => $property['property_type'] ?? 'apartment',
        ]]);
        relay_rate_limit_record_call('channex');

        if (!$result->ok) {
            return $result;
        }

        return new ProviderResult(true, null, ['property_id' => $result->data['data']['id'] ?? null]);
    }

    /**
     * Creates one room_type + its primary rate_plan on an existing property.
     * $unit: title, count_of_rooms (default 1), occ_adults (default 2),
     * occ_children (default 0), occ_infants (default 0), rate_plan_title
     * (default 'Standard rate'), currency (required - should match the
     * property's own currency), rate (required unless occupancy_prices is
     * given, MAJOR units e.g. 80.00 for 80 EUR - converted to Channex's
     * minor-unit/cents convention here, same *100 convention already used in
     * outbox_worker.php's price push).
     *
     * occupancy_prices (optional, 2026-11-23, Occupancy-Based Pricing/OBP):
     * {occupancy: rate} map, e.g. [1 => 70.00, 2 => 80.00] - when given,
     * creates the rate plan with Channex's sell_mode:"per_person" and one
     * "options" entry per occupancy tier, instead of the single-option
     * sell_mode:"per_room" default below. Confirmed against Channex's own
     * API docs and a live rate plan read on production (2026-11-22/23):
     * sell_mode is set once at creation and is IMMUTABLE afterward (a later
     * update attempt is refused with 422) - there is no "upgrade this rate
     * plan to per_person later" path, a fresh rate plan must be created.
     * The highest occupancy tier is marked is_primary (Channex requires
     * exactly one primary option); matches occ_adults when present among
     * the given tiers, otherwise falls back to the numerically highest one.
     */
    public function createRoomTypeWithRatePlan(string $propertyId, array $unit): ProviderResult
    {
        $occupancyPrices = is_array($unit['occupancy_prices'] ?? null) ? $unit['occupancy_prices'] : [];
        $required = $occupancyPrices ? ['title', 'currency'] : ['title', 'currency', 'rate'];
        foreach ($required as $field) {
            if (empty($unit[$field]) && $unit[$field] !== 0) {
                return new ProviderResult(false, "$field required");
            }
        }

        if (!relay_rate_limit_ok('channex', 20)) {
            return new ProviderResult(false, 'channex rate limit reached, try again shortly');
        }
        $roomType = $this->request('POST', '/room_types', ['room_type' => [
            'property_id' => $propertyId,
            'title' => $unit['title'],
            'count_of_rooms' => (int)($unit['count_of_rooms'] ?? 1),
            'occ_adults' => (int)($unit['occ_adults'] ?? 2),
            'occ_children' => (int)($unit['occ_children'] ?? 0),
            'occ_infants' => (int)($unit['occ_infants'] ?? 0),
        ]]);
        relay_rate_limit_record_call('channex');

        if (!$roomType->ok) {
            return $roomType;
        }
        $roomTypeId = $roomType->data['data']['id'] ?? null;

        if (!relay_rate_limit_ok('channex', 20)) {
            return new ProviderResult(false, 'channex rate limit reached after room_type create', [
                'room_type_id' => $roomTypeId,
            ]);
        }

        $ratePlanBody = [
            'property_id' => $propertyId,
            'room_type_id' => $roomTypeId,
            'title' => $unit['rate_plan_title'] ?? 'Standard rate',
            'currency' => $unit['currency'],
        ];
        if ($occupancyPrices) {
            $primaryOccupancy = (int)($unit['occ_adults'] ?? 0);
            if (!array_key_exists($primaryOccupancy, $occupancyPrices)) {
                $primaryOccupancy = max(array_map('intval', array_keys($occupancyPrices)));
            }
            $options = [];
            foreach ($occupancyPrices as $occupancy => $rate) {
                $options[] = [
                    'occupancy' => (int)$occupancy,
                    'is_primary' => ((int)$occupancy === $primaryOccupancy),
                    'rate' => (int)round((float)$rate * 100),
                ];
            }
            $ratePlanBody['sell_mode'] = 'per_person';
            $ratePlanBody['options'] = $options;
        } else {
            $ratePlanBody['options'] = [[
                'occupancy' => (int)($unit['occ_adults'] ?? 2),
                'is_primary' => true,
                'rate' => (int)round((float)$unit['rate'] * 100),
            ]];
        }
        $ratePlan = $this->request('POST', '/rate_plans', ['rate_plan' => $ratePlanBody]);
        relay_rate_limit_record_call('channex');

        if (!$ratePlan->ok) {
            return new ProviderResult(false, $ratePlan->error, ['room_type_id' => $roomTypeId]);
        }

        return new ProviderResult(true, null, [
            'cm_unit_id' => $unit['cm_unit_id'] ?? null,
            'room_type_id' => $roomTypeId,
            'rate_plan_id' => $ratePlan->data['data']['id'] ?? null,
        ]);
    }

    /**
     * Deletes one rate_plan on Channex. Returns ok even if Channex reports
     * it already gone (404) - a deprovision that partially succeeded earlier
     * (e.g. rate_plan deleted, room_type delete then failed/rate-limited)
     * must be safely re-runnable without erroring on the already-done half.
     */
    public function deleteRatePlan(string $ratePlanId): ProviderResult
    {
        if (!relay_rate_limit_ok('channex', 20)) {
            return new ProviderResult(false, 'channex rate limit reached, try again shortly');
        }
        $result = $this->request('DELETE', '/rate_plans/' . urlencode($ratePlanId));
        relay_rate_limit_record_call('channex');

        if (!$result->ok && (int)($result->data['http_code'] ?? 0) !== 404) {
            return $result;
        }

        return new ProviderResult(true);
    }

    /**
     * Deletes one room_type on Channex. Same 404-is-fine tolerance as
     * deleteRatePlan() above. Must be called AFTER its rate_plan is deleted -
     * Channex rejects deleting a room_type that still has rate plans attached.
     */
    public function deleteRoomType(string $roomTypeId): ProviderResult
    {
        if (!relay_rate_limit_ok('channex', 20)) {
            return new ProviderResult(false, 'channex rate limit reached, try again shortly');
        }
        $result = $this->request('DELETE', '/room_types/' . urlencode($roomTypeId));
        relay_rate_limit_record_call('channex');

        if (!$result->ok && (int)($result->data['http_code'] ?? 0) !== 404) {
            return $result;
        }

        return new ProviderResult(true);
    }

    /**
     * Deletes a Channex property outright. Same 404-is-fine tolerance as
     * deleteRatePlan()/deleteRoomType() - a retry after a partial earlier
     * failure must not error on a property that's already gone. Property
     * must have no remaining room_types (the caller, api/v1/properties/
     * deprovision.php, only calls this once a unit removal brings a
     * property down to zero units) - owner correction 2026-11-18: an empty
     * property left behind is NOT harmless, it can still carry real
     * financial liability on the Channex account even with zero units, so
     * removal must cascade all the way, not stop at "empty but present."
     */
    public function deleteProperty(string $propertyId): ProviderResult
    {
        if (!relay_rate_limit_ok('channex', 20)) {
            return new ProviderResult(false, 'channex rate limit reached, try again shortly');
        }
        $result = $this->request('DELETE', '/properties/' . urlencode($propertyId));
        relay_rate_limit_record_call('channex');

        if (!$result->ok && (int)($result->data['http_code'] ?? 0) !== 404) {
            return $result;
        }

        return new ProviderResult(true);
    }

    /**
     * Removes one unit's Channex footprint entirely - the "undo" counterpart
     * to createRoomTypeWithRatePlan(). Deletes the rate_plan first, then the
     * room_type (Channex rejects a room_type delete while a rate_plan still
     * references it). Does NOT touch the property itself - whether the
     * property should also be deleted (i.e. this was its last unit) is
     * registry-level knowledge (how many unit_mappings remain) that this
     * provider-only class has no access to; see deprovision.php for the
     * cascade-to-deleteProperty() decision.
     */
    public function deprovisionUnit(string $roomTypeId, string $ratePlanId): ProviderResult
    {
        $rateResult = $this->deleteRatePlan($ratePlanId);
        if (!$rateResult->ok) {
            return $rateResult;
        }

        $roomResult = $this->deleteRoomType($roomTypeId);
        if (!$roomResult->ok) {
            return $roomResult;
        }

        return new ProviderResult(true);
    }

    /**
     * Full onboarding orchestration: one new property + one room_type/rate_plan
     * per unit. Channex has no multi-object transaction, so a failure partway
     * through (e.g. unit 3 of 5) must not lose track of the units that DID
     * get created - each success is audit-logged as it happens so a retry/
     * cleanup pass has something to find, and the partial result (property_id
     * plus whichever units succeeded) is still returned rather than discarded.
     */
    public function provisionProperty(array $property, array $units): ProviderResult
    {
        $propertyResult = $this->createProperty($property);
        if (!$propertyResult->ok) {
            return $propertyResult;
        }
        $propertyId = $propertyResult->data['property_id'];
        relay_audit('channex.property.create', 'provider_property', $propertyId);

        $createdUnits = [];
        foreach ($units as $unit) {
            $unitResult = $this->createRoomTypeWithRatePlan($propertyId, $unit);
            if (!$unitResult->ok) {
                return new ProviderResult(false, $unitResult->error, [
                    'property_id' => $propertyId,
                    'units' => $createdUnits,
                    'failed_unit' => $unit['cm_unit_id'] ?? $unit['title'] ?? null,
                ]);
            }
            relay_audit('channex.unit.create', 'provider_room_type', $unitResult->data['room_type_id']);
            $createdUnits[] = $unitResult->data;
        }

        return new ProviderResult(true, null, ['property_id' => $propertyId, 'units' => $createdUnits]);
    }

    /**
     * REAL FINDING 2026-11-18, live production test: a freshly created
     * property already comes back with is_active:true by default - the
     * opposite of what was first assumed. A follow-up PUT /properties/{id}
     * with {property:{is_active:false}} returns HTTP 200 and echoes the
     * full property object back, but is_active stays true on the next GET -
     * silently ignored, same class of quirk as availability clamping/
     * min_stay field-name rejection elsewhere in this file. is_active is not
     * a usable lever via this API. REMOVED (activateProperty/
     * deactivateProperty, api/v1/properties/activate.php,
     * api/v1/signup/activate.php, the admin UI "Activate on Channex" button)
     * rather than shipped as dead code.
     *
     * The real "mount vs activate" two-stage flow the property owner
     * remembers from using Channex's own dashboard is almost certainly
     * about connecting an actual OTA CHANNEL (Booking.com/Airbnb) to a
     * property, not this property-level flag - a freshly provisioned
     * property has acc_channels_count:0 and is not bookable by any real
     * guest regardless of is_active, until a channel is deliberately wired
     * up. That channel-connection step is NOT automated anywhere in this
     * codebase and should stay a manual, Channex-dashboard action - see the
     * 2026-09-18 incident in cm_relay_ota_architecture memory, where
     * activating Booking.com as a Connectivity Provider wiped the live
     * property's existing calendar/pricing with no easy way back.
     */

    /**
     * Attaches one photo by URL (Channex's Photos API takes a URL, not a
     * file upload - same shape already proven live in
     * bin/upload_a1_photos.php for the real A1 property, and re-confirmed
     * live 2026-11-18: GET /photos?filter[property_id]=X is the real place
     * to see what's attached - the property's own content.photos field
     * stays empty even after a successful photo add, don't rely on it.
     * Several OTA channels (Google Vacation Rental in particular, per that
     * script's own comment) require a minimum photo count before a listing
     * is eligible - this is surfaced as a UI hint, not enforced here.
     */
    public function addPhoto(string $propertyId, string $roomTypeId, string $url, int $position = 0, ?string $description = null): ProviderResult
    {
        if (!relay_rate_limit_ok('channex', 20)) {
            return new ProviderResult(false, 'channex rate limit reached, try again shortly');
        }
        $result = $this->request('POST', '/photos', ['photo' => array_filter([
            'property_id' => $propertyId,
            'room_type_id' => $roomTypeId,
            'url' => $url,
            'position' => $position,
            'description' => $description,
        ], static fn($v) => $v !== null)]);
        relay_rate_limit_record_call('channex');

        return $result;
    }

    public function mapUnit(array $mapping): ProviderResult
    {
        if (empty($mapping['provider_room_type_id']) || empty($mapping['provider_rate_plan_id'])) {
            return new ProviderResult(false, 'provider_room_type_id and provider_rate_plan_id required');
        }

        $roomType = $this->request('GET', '/room_types/' . urlencode($mapping['provider_room_type_id']));
        if (!$roomType->ok) {
            return $roomType;
        }

        return new ProviderResult(true, null, [
            'cm_unit_id' => $mapping['cm_unit_id'] ?? null,
            'provider_room_type_id' => $mapping['provider_room_type_id'],
            'provider_rate_plan_id' => $mapping['provider_rate_plan_id'],
            'count_of_rooms' => $roomType->data['data']['attributes']['count_of_rooms'] ?? null,
        ]);
    }

    /**
     * Availability lives on a SEPARATE endpoint from rate/restrictions, keyed
     * by room_type_id, not rate_plan_id - confirmed by Channex support
     * (Andrew, 2026-09-17) after availability pushes via /restrictions
     * silently reverted. $payload: property_id, provider_room_type_id, date
     * (or date_from/date_to), availability.
     */
    /**
     * Read-back availability for a room_type over a date range - the GET
     * counterpart of pushAvailability(). Used by the channel-presence
     * confirmation gate (docs/cm_relay/direct_booking_channel_confirmation_gate.md)
     * to verify a just-pushed block is actually visible on Channex's side
     * before letting a direct-booking SMS code go out. Returns raw decoded
     * data: {"data": {"<room_type_id>": {"<date>": <int availability>}}}.
     */
    public function getAvailability(string $propertyId, string $roomTypeId, string $dateFrom, string $dateTo): ProviderResult
    {
        $qs = http_build_query([
            'filter' => [
                'property_id' => $propertyId,
                'room_type_id' => $roomTypeId,
                'date' => ['gte' => $dateFrom, 'lte' => $dateTo],
            ],
        ]);
        return $this->request('GET', '/availability?' . $qs);
    }

    public function pushAvailability(array $payload): ProviderResult
    {
        $required = ['property_id', 'provider_room_type_id'];
        foreach ($required as $key) {
            if (empty($payload[$key])) {
                return new ProviderResult(false, "$key required");
            }
        }
        if (empty($payload['date']) && (empty($payload['date_from']) || empty($payload['date_to']))) {
            return new ProviderResult(false, 'date, or date_from+date_to, required');
        }

        $entry = [
            'property_id' => $payload['property_id'],
            'room_type_id' => $payload['provider_room_type_id'],
        ];
        $entry += array_intersect_key($payload, array_flip(['date', 'date_from', 'date_to']));
        $entry['availability'] = $payload['availability'];

        return $this->request('POST', '/availability', ['values' => [$entry]]);
    }

    /**
     * Batched availability push - same room_type_id-keyed endpoint as
     * pushAvailability(), multiple dates in one call.
     */
    public function pushAvailabilityBatch(array $entries): ProviderResult
    {
        if (empty($entries)) {
            return new ProviderResult(false, 'entries required');
        }

        foreach ($entries as $entry) {
            if (empty($entry['property_id']) || empty($entry['room_type_id'])) {
                return new ProviderResult(false, 'each entry needs property_id and room_type_id');
            }
            if (empty($entry['date']) && (empty($entry['date_from']) || empty($entry['date_to']))) {
                return new ProviderResult(false, 'each entry needs date, or date_from+date_to');
            }
        }

        return $this->request('POST', '/availability', ['values' => array_values($entries)]);
    }

    public function pushRates(array $payload): ProviderResult
    {
        return $this->pushValues($payload, ['rate']);
    }

    public function pushRestrictions(array $payload): ProviderResult
    {
        return $this->pushValues($payload, [
            'min_stay_arrival', 'min_stay_through', 'min_stay', 'max_stay',
            'closed_to_arrival', 'closed_to_departure', 'stop_sell',
        ]);
    }

    /**
     * $payload: property_id, rate_plan_id, date (or date_from+date_to), plus the
     * relevant value keys for $allowedKeys. Extra keys are dropped, not forwarded.
     */
    private function pushValues(array $payload, array $allowedKeys): ProviderResult
    {
        $required = ['property_id', 'rate_plan_id'];
        foreach ($required as $key) {
            if (empty($payload[$key])) {
                return new ProviderResult(false, "$key required");
            }
        }
        if (empty($payload['date']) && (empty($payload['date_from']) || empty($payload['date_to']))) {
            return new ProviderResult(false, 'date, or date_from+date_to, required');
        }

        $entry = [
            'property_id' => $payload['property_id'],
            'rate_plan_id' => $payload['rate_plan_id'],
        ];
        $entry += array_intersect_key($payload, array_flip(['date', 'date_from', 'date_to']));
        $entry += array_intersect_key($payload, array_flip($allowedKeys));

        return $this->request('POST', '/restrictions', ['values' => [$entry]]);
    }

    /**
     * Sends multiple date/value entries in a SINGLE API call. Required by
     * Channex certification test #3/#4: "Your integration must batch these
     * into 1 API call. If your current code loops per-date, this is a sign
     * you need to refactor before certifying." $entries is an array of
     * associative arrays, each needing at minimum property_id, rate_plan_id,
     * and date (or date_from/date_to) plus whatever value keys apply.
     */
    public function pushBatch(array $entries): ProviderResult
    {
        if (empty($entries)) {
            return new ProviderResult(false, 'entries required');
        }

        foreach ($entries as $entry) {
            if (empty($entry['property_id']) || empty($entry['rate_plan_id'])) {
                return new ProviderResult(false, 'each entry needs property_id and rate_plan_id');
            }
            if (empty($entry['date']) && (empty($entry['date_from']) || empty($entry['date_to']))) {
                return new ProviderResult(false, 'each entry needs date, or date_from+date_to');
            }
        }

        return $this->request('POST', '/restrictions', ['values' => array_values($entries)]);
    }

    public function requestFullSync(string $propertyId): ProviderResult
    {
        return new ProviderResult(false, 'not yet verified against a live channel - no OTA channel configured in sandbox as of 2026-09-16');
    }

    /**
     * A webhook's own payload is deliberately NOT trusted as authoritative
     * (Channex's own docs: "consider webhooks as triggers to pull current
     * state" - delivery order isn't guaranteed). For booking_* events, this
     * is the real read: GET /booking_revisions/feed?filter[property_id]=X,
     * one page (Channex's own default page size).
     */
    public function fetchBookingRevisions(string $propertyId): ProviderResult
    {
        return $this->request('GET', '/booking_revisions/feed?' . http_build_query([
            'filter' => ['property_id' => $propertyId],
        ]));
    }

    /**
     * Must be called after successfully processing a revision - Channex
     * emails a warning if a revision sits unacknowledged for ~30 minutes,
     * and won't stop re-delivering it via the feed until acked.
     */
    public function acknowledgeBookingRevision(string $revisionId): ProviderResult
    {
        return $this->request('POST', '/booking_revisions/' . urlencode($revisionId) . '/ack');
    }

    public function normalizeIncomingEvent(array $providerPayload): array
    {
        // Documented shape only, not exercised against a real webhook yet.
        return [
            'type' => $providerPayload['event'] ?? 'unknown',
            'occurred_at' => $providerPayload['timestamp'] ?? null,
            'raw' => $providerPayload,
        ];
    }

    public function connectionStatus(string $propertyId): ProviderStatus
    {
        $result = $this->request('GET', '/properties/' . urlencode($propertyId));
        if (!$result->ok) {
            return new ProviderStatus(false, null, $result->error);
        }

        $isActive = (bool) ($result->data['data']['attributes']['is_active'] ?? false);
        return new ProviderStatus($isActive, gmdate('c'), $isActive ? null : 'property inactive');
    }

    private function request(string $method, string $path, ?array $body = null): ProviderResult
    {
        $ch = curl_init(self::BASE_URL . $path);
        $headers = ['user-api-key: ' . $this->apiKey, 'Content-Type: application/json'];

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return new ProviderResult(false, "curl error: $curlError");
        }

        $decoded = json_decode($raw, true);
        if ($httpCode >= 400) {
            $errorMsg = $decoded['errors']['title'] ?? $decoded['errors']['code'] ?? "HTTP $httpCode";
            // http_code kept as its own data key (not string-matched out of
            // $errorMsg) so callers like deleteRatePlan()/deleteRoomType() can
            // reliably tell "already gone" (404) apart from a real failure,
            // regardless of whatever wording Channex's own error title uses.
            return new ProviderResult(false, is_array($errorMsg) ? json_encode($errorMsg) : $errorMsg, (is_array($decoded) ? $decoded : []) + ['http_code' => $httpCode]);
        }

        return new ProviderResult(true, null, $decoded ?? []);
    }
}
