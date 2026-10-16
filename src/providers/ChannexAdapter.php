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
 */

declare(strict_types=1);

require_once __DIR__ . '/CmConnectivityProviderAdapter.php';
require_once __DIR__ . '/ProviderResult.php';
require_once __DIR__ . '/ProviderStatus.php';

final class ChannexAdapter implements CmConnectivityProviderAdapter
{
    private const BASE_URL = 'https://app.channex.io/api/v1';

    public function __construct(private readonly string $apiKey)
    {
    }

    public function createOrAttachProperty(array $property): ProviderResult
    {
        if (empty($property['provider_property_id'])) {
            return new ProviderResult(false, 'provider_property_id required - Channex property creation via API not yet built, attach an existing property id for now');
        }

        $result = $this->request('GET', '/properties/' . urlencode($property['provider_property_id']));
        if (!$result->ok) {
            return $result;
        }

        return new ProviderResult(true, null, ['property_id' => $result->data['data']['id'] ?? null]);
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
            return new ProviderResult(false, is_array($errorMsg) ? json_encode($errorMsg) : $errorMsg, $decoded ?? []);
        }

        return new ProviderResult(true, null, $decoded ?? []);
    }
}
