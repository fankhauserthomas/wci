<?php
/**
 * Test-Beispiel für die HRSLogin Klasse
 * Zeigt, wie die hrs_login.php für andere Projekte verwendet werden kann
 */

require_once 'hrs_login.php';

echo "=== HRS Login Test ===\n\n";

// HRS Login-Klasse instanziieren
$hrsLogin = new HRSLogin();

// Login durchführen
if ($hrsLogin->login()) {
    echo "\n🎉 Login erfolgreich! Kann jetzt authentifizierte API-Calls machen:\n";
    
    // Beispiel: Hutteninfo abrufen
    $headers = array(
        'X-XSRF-TOKEN: ' . $hrsLogin->getCsrfToken()
    );
    
    $response = $hrsLogin->makeRequest('/api/v1/hut/675', 'GET', null, $headers);
    
    if ($response && $response['status'] == 200) {
        $hutData = json_decode($response['body'], true);
        echo "✅ Hütte-Info erfolgreich abgerufen:\n";
        echo "   Name: " . ($hutData['name'] ?? 'N/A') . "\n";
        echo "   Status: " . ($hutData['status'] ?? 'N/A') . "\n";
    } else {
        echo "❌ Hütte-Info konnte nicht abgerufen werden\n";
    }
    
    // Status anzeigen
    echo "\n🔧 Login-Status:\n";
    echo "   Eingeloggt: " . ($hrsLogin->isLoggedIn() ? 'Ja' : 'Nein') . "\n";
    echo "   CSRF-Token: " . substr($hrsLogin->getCsrfToken(), 0, 20) . "...\n";
    echo "   Cookies: " . count($hrsLogin->getCookies()) . " gesetzt\n";
    
} else {
    echo "\n❌ Login fehlgeschlagen!\n";
}

echo "\n=== Test beendet ===\n";
?>
