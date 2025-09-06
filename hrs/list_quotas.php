<?php
/**
 * HRS Quota Listing - Zeigt verfügbare Quotas zum Testen
 */

header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once 'hrs_login.php';

try {
    echo "=== HRS QUOTA LISTING ===\n\n";
    
    $hrsLogin = new HRSLogin();
    
    echo "1. Login...\n";
    $loginResult = $hrsLogin->login();
    
    if (!$loginResult) {
        throw new Exception("Login fehlgeschlagen");
    }
    
    echo "✅ Login erfolgreich!\n\n";
    
    // Liste verfügbare Quota-Endpoints
    $testUrls = array(
        '/api/v1/manage/quota',
        '/api/v1/manage/quota?hutId=675',
        '/api/v1/manage/hut/675/quota',
        '/api/v1/hut/675/quota'
    );
    
    foreach ($testUrls as $url) {
        echo "2. Teste URL: $url\n";
        try {
            $response = $hrsLogin->makeRequest($url, 'GET');
            echo "Status: " . $response['status'] . "\n";
            if ($response['status'] == 200 && !empty($response['body'])) {
                $data = json_decode($response['body'], true);
                if ($data && is_array($data)) {
                    echo "✅ Daten gefunden (" . count($data) . " Einträge)\n";
                    if (count($data) > 0) {
                        echo "Erste 3 Quotas:\n";
                        foreach (array_slice($data, 0, 3) as $quota) {
                            if (isset($quota['id'])) {
                                echo "  - ID: {$quota['id']}, Name: " . ($quota['title'] ?? 'Unbekannt') . "\n";
                            }
                        }
                    }
                } else {
                    echo "Body: " . substr($response['body'], 0, 200) . "\n";
                }
            } else {
                echo "Body: " . substr($response['body'], 0, 100) . "\n";
            }
        } catch (Exception $e) {
            echo "❌ Fehler: " . $e->getMessage() . "\n";
        }
        echo "\n";
    }
    
} catch (Exception $e) {
    echo "❌ FEHLER: " . $e->getMessage() . "\n";
}

echo "=== ENDE ===\n";
?>
