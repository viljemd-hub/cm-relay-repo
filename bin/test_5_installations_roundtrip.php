<?php
/**
 * CM Relay
 * File: bin/test_5_installations_roundtrip.php
 * Author: Viljem Dvojmoč
 * Assistant: Claude
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 *
 * Proves the relay/white-label premise: one Channex account/contract driving
 * 5 independent "CM installations" via ChannexAdapter, each pushed a different
 * rate, then re-read to confirm no cross-contamination between installations.
 *
 * Run: php bin/test_5_installations_roundtrip.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/providers/ChannexAdapter.php';

$apiKey = (json_decode(file_get_contents(__DIR__ . '/../secrets/channex_sandbox.json'), true))['api_key'];
$mapping = json_decode(file_get_contents(__DIR__ . '/../secrets/test_5_installations_mapping.json'), true);

$adapter = new ChannexAdapter($apiKey);
$date = date('Y-m-d', strtotime('+6 day'));

echo "--- push distinct rate per installation ---\n";
foreach ($mapping as $i => $inst) {
    $rateEur = 5000 + ($i * 1500); // 50.00, 65.00, 80.00, 95.00, 110.00
    $r = $adapter->pushRates([
        'property_id' => $inst['provider_property_id'],
        'rate_plan_id' => $inst['provider_rate_plan_id'],
        'date' => $date,
        'rate' => $rateEur,
    ]);
    printf("%s -> pushed %.2f EUR, ok=%s\n", $inst['cm_installation_id'], $rateEur / 100, $r->ok ? 'true' : 'false');
}

sleep(3);

echo "\n--- re-read to confirm no cross-contamination ---\n";
foreach ($mapping as $inst) {
    $status = readRate($apiKey, $inst['provider_property_id'], $inst['provider_rate_plan_id'], $date);
    printf("%s (property %s) -> rate=%s\n", $inst['cm_installation_id'], substr($inst['provider_property_id'], 0, 8), $status);
}

function readRate(string $apiKey, string $propertyId, string $ratePlanId, string $date): string
{
    $ch = curl_init('https://staging.channex.io/api/v1/restrictions?' . http_build_query([
        'filter' => ['property_id' => $propertyId, 'date' => ['gte' => $date, 'lte' => $date], 'restrictions' => 'rate'],
    ]));
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['user-api-key: ' . $apiKey],
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    $decoded = json_decode($raw, true);
    return $decoded['data'][$ratePlanId][$date]['rate'] ?? 'MISSING';
}
