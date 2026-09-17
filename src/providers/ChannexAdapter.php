<?php
/**
 * CM Relay — Channex provider adapter (Faza D)
 * File: src/providers/ChannexAdapter.php
 *
 * First real CmConnectivityProviderAdapter implementation, built and verified
 * against the free Channex staging/sandbox environment (https://staging.channex.io),
 * demo property "Apartma Matevž Demo" (2026-09-16).
 *
 * Verified live against the sandbox on this date:
 *   - GET  /api/v1/properties, /api/v1/room_types, /api/v1/rate_plans
 *   - GET  /api/v1/restrictions  (filter[property_id], filter[date][gte]/[lte], filter[restrictions])
 *   - POST /api/v1/restrictions  (rate + availability push, confirmed round-trip)
 *
 * NOT yet verified against a live channel/OTA connection (sandbox has none configured):
 *   requestFullSync(), normalizeIncomingEvent(), connectionStatus(). Implemented from
 *   documented shape only - treat as unverified until exercised against a real channel.
 *
 * Channex quirk worth remembering: availability sent in a push is silently clamped
 * down to the room type's count_of_rooms - never assume the value you sent is the
 * value that stuck; a caller that needs certainty must re-GET after pushing.
 */

declare(strict_types=1);

require_once __DIR__ . '/CmConnectivityProviderAdapter.php';
require_once __DIR__ . '/ProviderResult.php';
require_once __DIR__ . '/ProviderStatus.php';

final class ChannexAdapter implements CmConnectivityProviderAdapter
{
    private const BASE_URL = 'https://staging.channex.io/api/v1';

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

    public function pushAvailability(array $payload): ProviderResult
    {
        return $this->pushValues($payload, ['availability']);
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
