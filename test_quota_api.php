<?php
/**
 * Test Quota API
 */

$testData = [
    'selectedDays' => ['2026-02-15', '2026-02-16'],
    'targetCapacity' => 67,
    'priorities' => [
        ['category' => 'lager', 'max' => null],
        ['category' => 'betten', 'max' => 10],
        ['category' => 'dz', 'max' => 2],
        ['category' => 'sonder', 'max' => 4]
    ],
    'dailyQuotas' => [
        [
            'date' => '2026-02-15',
            'quotas' => ['lager' => 81, 'betten' => 0, 'dz' => 0, 'sonder' => 0],
            'targetCapacity' => 67,
            'avReservations' => 14,
            'internalReservations' => 0,
            'calculatedQuota' => 81
        ],
        [
            'date' => '2026-02-16',
            'quotas' => ['lager' => 81, 'betten' => 0, 'dz' => 0, 'sonder' => 0],
            'targetCapacity' => 67,
            'avReservations' => 14,
            'internalReservations' => 0,
            'calculatedQuota' => 81
        ]
    ],
    'operation' => 'update'
];

echo "Testing Quota API with request:\n";
echo json_encode($testData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

// Make request
$ch = curl_init('http://192.168.15.14:8080/wci/hrs/hrs_update_quota_timeline.php');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($testData));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Status: $httpCode\n";
echo "Response:\n";
echo $response . "\n";

?>
