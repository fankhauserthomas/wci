<?php
/**
 * Direkter HRS Quota Deletion Test
 */

header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once 'hrs_login.php';

try {
    echo "=== DIREKTER HRS QUOTA DELETE TEST ===\n\n";
    
    $hrsLogin = new HRSLogin();
    
    echo "1. Login...\n";
    $loginResult = $hrsLogin->login();
    
    if (!$loginResult) {
        throw new Exception("Login fehlgeschlagen");
    }
    
    echo "✅ Login erfolgreich!\n\n";
    
    // Test DELETE mit korrekten Headers wie VB.NET
    $quotaId = 42797;
    $hutId = 675;
    
    echo "2. Teste DELETE für Quota-ID: $quotaId\n";
    
    $queryParams = array(
        'hutId' => $hutId,
        'quotaId' => $quotaId,
        'canChangeMode' => 'false',
        'canOverbook' => 'true',
        'allSeries' => 'false'
    );
    
    $queryString = http_build_query($queryParams);
    $url = "/api/v1/manage/deleteQuota?$queryString";
    
    echo "URL: $url\n";
    
    // Headers exakt wie VB.NET
    $headers = array(
        'accept' => 'application/json, text/plain, */*',
        'origin' => 'https://www.hut-reservation.org',
        'referer' => 'https://www.hut-reservation.org/hut/manage-hut/675'
    );
    
    echo "Headers: " . print_r($headers, true) . "\n";
    
    $response = $hrsLogin->makeRequest($url, 'DELETE', null, $headers);
    
    echo "Response Status: " . $response['status'] . "\n";
    echo "Response Headers: " . substr($response['headers'], 0, 500) . "...\n";
    echo "Response Body: " . $response['body'] . "\n";
    
    if ($response['status'] == 200) {
        echo "\n✅ DELETE erfolgreich!\n";
    } else {
        echo "\n❌ DELETE fehlgeschlagen (Status: {$response['status']})\n";
    }
    
} catch (Exception $e) {
    echo "❌ FEHLER: " . $e->getMessage() . "\n";
    echo "Stack Trace: " . $e->getTraceAsString() . "\n";
}

echo "\n=== ENDE ===\n";
?>
