<?php
// Test für das Name Format Feature
require_once 'namen-import.php';

// Test Daten
$testNames = "Hans Müller 25.12.1980
Schmidt, Anna 45
Peter Weber
Maria Rodriguez Gonzalez 1985";

echo "=== Namen-Format Test ===\n\n";

// Test 1: Firstname-Lastname Format (Standard)
echo "1. Test: Firstname-Lastname Format\n";
echo "Eingabe:\n$testNames\n\n";

$parser = new NamesBirthdateParser();
$result1 = $parser->parseNames($testNames, '', 'firstname-lastname');

echo "Ergebnisse:\n";
foreach ($result1['names'] as $name) {
    echo "- Vollname: '{$name['name']}' → Vorname: '{$name['firstName']}', Nachname: '{$name['lastName']}'\n";
}
echo "\n";

// Test 2: Lastname-Firstname Format
echo "2. Test: Lastname-Firstname Format\n";
$result2 = $parser->parseNames($testNames, '', 'lastname-firstname');

echo "Ergebnisse:\n";
foreach ($result2['names'] as $name) {
    echo "- Vollname: '{$name['name']}' → Vorname: '{$name['firstName']}', Nachname: '{$name['lastName']}'\n";
}
echo "\n";

// Test 3: Spezielle Fälle
$specialNames = "Müller Hans 1990
Dr. Weber Peter
von Schmidt Anna Maria
O'Connor Michael 2000";

echo "3. Test: Spezielle Fälle mit lastname-firstname Format\n";
echo "Eingabe:\n$specialNames\n\n";

$result3 = $parser->parseNames($specialNames, '', 'lastname-firstname');

echo "Ergebnisse:\n";
foreach ($result3['names'] as $name) {
    echo "- Vollname: '{$name['name']}' → Vorname: '{$name['firstName']}', Nachname: '{$name['lastName']}'\n";
    if ($name['birthdate']) {
        echo "  Geburtsdatum: {$name['birthdate']} (Typ: {$name['detection_type']})\n";
    }
}
?>
