<?php
/**
 * HRS Login Test - Prüft ob die Authentifizierung funktioniert
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once 'hrs_login.php';

try {
    echo "=== HRS LOGIN TEST ===\n\n";
    
    $hrsLogin = new HRSLogin();
    
    echo "1. Initialisiere HRS Login...\n";
    
    echo "2. Versuche Login...\n";
    $loginResult = $hrsLogin->login();
    
    if ($loginResult) {
        echo "✅ Login erfolgreich!\n\n";
        
        // Test eines einfachen API-Aufrufs
        echo "3. Teste API-Aufruf...\n";
        
        try {
            $testResponse = $hrsLogin->makeRequest('/api/v1/manage/quota', 'GET');
            echo "✅ API-Aufruf erfolgreich: " . print_r($testResponse, true) . "\n";
        } catch (Exception $e) {
            echo "❌ API-Aufruf fehlgeschlagen: " . $e->getMessage() . "\n";
        }
        
        // Teste DELETE-Request (ohne echte ID)
        echo "4. Teste DELETE-Request Format...\n";
        try {
            $deleteUrl = '/api/v1/manage/deleteQuota?hutId=675&quotaId=99999&canChangeMode=false&canOverbook=true&allSeries=false';
            $deleteResponse = $hrsLogin->makeRequest($deleteUrl, 'DELETE');
            echo "📨 DELETE Response: " . print_r($deleteResponse, true) . "\n";
        } catch (Exception $e) {
            echo "🔍 DELETE Error (erwartet für ungültige ID): " . $e->getMessage() . "\n";
        }
        
    } else {
        echo "❌ Login fehlgeschlagen!\n";
        echo "Details: " . print_r($hrsLogin->getLastError(), true) . "\n";
    }
    
} catch (Exception $e) {
    echo "❌ FEHLER: " . $e->getMessage() . "\n";
    echo "Stack Trace: " . $e->getTraceAsString() . "\n";
}

echo "\n=== TEST ENDE ===\n";
?>
