<?php
/**
 * DEBUG: AV API Response Checker
 * Überprüft die tatsächlichen Daten von der HRS Availability API
 */

$hutID = 675;
$testDate = '2026-06-20'; // Sommersaison - should have data

$apiUrl = "https://www.hut-reservation.org/api/v1/reservation/getHutAvailability?hutId={$hutID}&step=WIZARD&from={$testDate}";

echo "🔍 Testing HRS Availability API\n";
echo "=" . str_repeat("=", 60) . "\n";
echo "URL: $apiUrl\n\n";

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $apiUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_HTTPHEADER => [
        'Accept: application/json',
        'User-Agent: WCI-Debug-Checker/1.0'
    ],
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_VERBOSE => false
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

echo "HTTP Status: $httpCode\n";
if ($curlError) {
    echo "❌ cURL Error: $curlError\n";
    exit(1);
}

$data = json_decode($response, true);

if (!is_array($data)) {
    echo "❌ Response ist kein Array!\n";
    echo "Raw Response:\n$response\n";
    exit(1);
}

echo "✅ Response enthält " . count($data) . " Tage\n\n";

// Zeige erste 3 Tage
echo "📊 Erste 3 Tage der Antwort:\n";
echo str_repeat("=", 80) . "\n";

for ($i = 0; $i < min(3, count($data)); $i++) {
    $day = $data[$i];
    echo "\nTag " . ($i + 1) . ":\n";
    echo "  Datum: " . ($day['date'] ?? 'N/A') . "\n";
    echo "  Datum (formatiert): " . ($day['dateFormatted'] ?? 'N/A') . "\n";
    echo "  Freie Plätze: " . json_encode($day['freeBeds'] ?? 'null') . "\n";
    echo "  Hut Status: " . ($day['hutStatus'] ?? 'N/A') . "\n";
    echo "  Total Schlafplätze: " . ($day['totalSleepingPlaces'] ?? 'N/A') . "\n";
    echo "  Prozent: " . ($day['percentage'] ?? 'N/A') . "\n";
    
    $freeBedsPerCategory = $day['freeBedsPerCategory'] ?? [];
    echo "  freeBedsPerCategory:\n";
    if (empty($freeBedsPerCategory)) {
        echo "    ❌ LEER!\n";
    } else {
        foreach ($freeBedsPerCategory as $catId => $count) {
            echo "    $catId: $count\n";
        }
    }
}

// Suche nach Tag mit Daten
echo "\n\n🔎 Suche nach Tagen mit Kategorie-Daten...\n";
echo str_repeat("=", 80) . "\n";

$foundWithData = false;
foreach ($data as $index => $day) {
    $freeBedsPerCategory = $day['freeBedsPerCategory'] ?? [];
    if (!empty($freeBedsPerCategory)) {
        $foundWithData = true;
        echo "\n✅ GEFUNDEN bei Index $index:\n";
        echo "  Datum: " . ($day['date'] ?? 'N/A') . "\n";
        echo "  Freie Plätze: " . ($day['freeBeds'] ?? 'null') . "\n";
        echo "  Kategorien:\n";
        foreach ($freeBedsPerCategory as $catId => $count) {
            echo "    Kategorie $catId: $count freie Plätze\n";
        }
        
        // Nur ersten 3 Tage mit Daten zeigen
        if ($index > 10) break;
    }
}

if (!$foundWithData) {
    echo "❌ KEINE Tage mit Kategorie-Daten gefunden!\n";
    echo "\nDas Problem:\n";
    echo "- Die öffentliche API 'getHutAvailability' liefert keine Kategorie-Daten\n";
    echo "- Oder der angeforderte Zeitraum liegt außerhalb der verfügbaren Daten\n";
    echo "- Möglicherweise benötigen wir eine andere API (Management-API?)\n";
}

// Prüfe ob vom-Parameter ignoriert wird
echo "\n\n🔬 Prüfe ob 'from' Parameter funktioniert...\n";
echo str_repeat("=", 80) . "\n";

$firstDate = $data[0]['date'] ?? null;
if ($firstDate) {
    $parsedDate = DateTime::createFromFormat('Y-m-d\TH:i:s\Z', $firstDate);
    if (!$parsedDate) {
        $parsedDate = DateTime::createFromFormat('Y-m-d\TH:i:s', $firstDate);
    }
    
    if ($parsedDate) {
        $actualDate = $parsedDate->format('Y-m-d');
        echo "Angefordert: $testDate\n";
        echo "Erhalten:    $actualDate\n";
        
        if ($actualDate !== $testDate) {
            echo "❌ FEHLER: API ignoriert 'from' Parameter!\n";
            echo "   Die API gibt immer nur aktuelle Daten zurück.\n";
        } else {
            echo "✅ OK: API respektiert 'from' Parameter\n";
        }
    }
}

echo "\n\n";
?>
