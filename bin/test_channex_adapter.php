<?php
/**
 * Manual smoke test for ChannexAdapter against the sandbox demo property.
 * Not a unit test suite - Faza D is a single provider proving the interface end
 * to end, this script is that proof. Run: php bin/test_channex_adapter.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/providers/ChannexAdapter.php';

$secretsFile = __DIR__ . '/../secrets/channex_sandbox.json';
if (!file_exists($secretsFile)) {
    fwrite(STDERR, "Missing $secretsFile\n");
    exit(1);
}
$apiKey = (json_decode(file_get_contents($secretsFile), true))['api_key'] ?? null;
if (!$apiKey) {
    fwrite(STDERR, "No api_key in $secretsFile\n");
    exit(1);
}

$adapter = new ChannexAdapter($apiKey);

$propertyId = '4e42c4c9-32ee-4456-b7bb-13b92fdd0258'; // Apartma Matevž Demo
$roomTypeId = 'acc8efba-d49f-439b-855b-3f5fca1af8a0'; // Demo Signle
$ratePlanId = '06cd8365-8586-4347-8548-1527440d3fd6';
$date = date('Y-m-d', strtotime('+4 day'));

echo "1) attach property\n";
$r = $adapter->createOrAttachProperty(['provider_property_id' => $propertyId]);
printResult($r);

echo "2) map unit\n";
$r = $adapter->mapUnit([
    'cm_unit_id' => 'test-unit-1',
    'provider_room_type_id' => $roomTypeId,
    'provider_rate_plan_id' => $ratePlanId,
]);
printResult($r);

echo "3) push rate (77.50 EUR)\n";
$r = $adapter->pushRates([
    'property_id' => $propertyId,
    'rate_plan_id' => $ratePlanId,
    'date' => $date,
    'rate' => 7750,
]);
printResult($r);

echo "4) push availability (1)\n";
$r = $adapter->pushAvailability([
    'property_id' => $propertyId,
    'rate_plan_id' => $ratePlanId,
    'date' => $date,
    'availability' => 1,
]);
printResult($r);

echo "5) connection status\n";
$status = $adapter->connectionStatus($propertyId);
$connectedStr = $status->connected ? 'true' : 'false';
echo "connected=$connectedStr lastSyncAt={$status->lastSyncAt} error={$status->error}\n\n";

function printResult(ProviderResult $r): void
{
    echo 'ok=' . ($r->ok ? 'true' : 'false') . ' error=' . ($r->error ?? '-') . "\n";
    echo json_encode($r->data) . "\n\n";
}
