<?php
/**
 * One-off provisioning script: creates 5 separate Channex properties (one unit
 * each), simulating 5 independent CM installations sharing a single Channex
 * contract via the relay/white-label model. Prints the resulting mapping table
 * (used as input for the adapter round-trip test).
 *
 * Run: php bin/create_5_test_installations.php
 */

declare(strict_types=1);

$secretsFile = __DIR__ . '/../secrets/channex_sandbox.json';
$apiKey = (json_decode(file_get_contents($secretsFile), true))['api_key'] ?? null;
if (!$apiKey) {
    fwrite(STDERR, "No api_key in $secretsFile\n");
    exit(1);
}

const BASE_URL = 'https://staging.channex.io/api/v1';

function api(string $apiKey, string $method, string $path, ?array $body = null): array
{
    $ch = curl_init(BASE_URL . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['user-api-key: ' . $apiKey, 'Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = json_decode($raw, true) ?? [];
    if ($code >= 400) {
        fwrite(STDERR, "ERROR $method $path: " . json_encode($decoded) . "\n");
        exit(1);
    }
    return $decoded;
}

$installations = [
    ['cm_installation_id' => 'cm-install-1', 'title' => 'CM Test Namestitev 1', 'city' => 'Radovljica'],
    ['cm_installation_id' => 'cm-install-2', 'title' => 'CM Test Namestitev 2', 'city' => 'Bled'],
    ['cm_installation_id' => 'cm-install-3', 'title' => 'CM Test Namestitev 3', 'city' => 'Bohinj'],
    ['cm_installation_id' => 'cm-install-4', 'title' => 'CM Test Namestitev 4', 'city' => 'Kranj'],
    ['cm_installation_id' => 'cm-install-5', 'title' => 'CM Test Namestitev 5', 'city' => 'Ljubljana'],
];

$mapping = [];

foreach ($installations as $inst) {
    echo "== {$inst['title']} ==\n";

    $prop = api($apiKey, 'POST', '/properties', ['property' => [
        'title' => $inst['title'],
        'currency' => 'EUR',
        'country' => 'SI',
        'city' => $inst['city'],
        'property_type' => 'apartment',
    ]]);
    $propertyId = $prop['data']['id'];
    echo "  property_id=$propertyId\n";

    $roomType = api($apiKey, 'POST', '/room_types', ['room_type' => [
        'property_id' => $propertyId,
        'title' => 'Enota 1',
        'count_of_rooms' => 1,
        'occ_adults' => 2,
        'occ_children' => 0,
        'occ_infants' => 0,
    ]]);
    $roomTypeId = $roomType['data']['id'];
    echo "  room_type_id=$roomTypeId\n";

    $ratePlan = api($apiKey, 'POST', '/rate_plans', ['rate_plan' => [
        'property_id' => $propertyId,
        'room_type_id' => $roomTypeId,
        'title' => 'Standardna cena',
        'currency' => 'EUR',
        'options' => [['occupancy' => 2, 'is_primary' => true, 'rate' => 8000]],
    ]]);
    $ratePlanId = $ratePlan['data']['id'];
    echo "  rate_plan_id=$ratePlanId\n\n";

    $mapping[] = [
        'cm_installation_id' => $inst['cm_installation_id'],
        'provider_property_id' => $propertyId,
        'provider_room_type_id' => $roomTypeId,
        'provider_rate_plan_id' => $ratePlanId,
    ];
}

$outFile = __DIR__ . '/../secrets/test_5_installations_mapping.json';
file_put_contents($outFile, json_encode($mapping, JSON_PRETTY_PRINT));
echo "Mapping written to $outFile\n";
