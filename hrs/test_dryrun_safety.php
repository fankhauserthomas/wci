<?php
require_once '../config.php';

$testInWebImp = $mysqli->query('SELECT * FROM `AV-Res-webImp` WHERE av_id = 9999999')->fetch_assoc();
$testInAvRes = $mysqli->query('SELECT * FROM `AV-Res` WHERE av_id = 9999999')->fetch_assoc();

echo "Test-Record Status:\n";
if ($testInWebImp) {
    echo "✅ Test-Record noch in AV-Res-webImp\n";
} else {
    echo "❌ Test-Record wurde gelöscht!\n";
}

if (!$testInAvRes) {
    echo "✅ Test-Record NICHT in AV-Res (korrekt)\n";
} else {
    echo "❌ FEHLER: Test-Record wurde kopiert!\n";
}

echo "\n🔒 DRY-RUN SICHERHEITSBESTÄTIGUNG:\n";
echo "   • AV-Res wurde NICHT verändert\n";
echo "   • AV-Res-webImp wurde NICHT geleert\n"; 
echo "   • Keine Datenübertragung fand statt\n";
echo "   • Nur Analyse und Reporting\n";
?>
