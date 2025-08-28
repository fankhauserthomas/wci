<?php
// Debug: Vergleiche Datensätze zwischen WebImp und AV-Res
require_once '../config.php';

// Hole ersten AV-IDs aus WebImp
$avIds = [];
$webimpResult = $mysqli->query('SELECT av_id FROM `AV-Res-webImp` WHERE av_id > 0 LIMIT 5');
while ($row = $webimpResult->fetch_assoc()) {
    $avIds[] = $row['av_id'];
}

echo "=== Vergleiche AV-IDs: " . implode(', ', $avIds) . " ===\n\n";

// Hole entsprechende Datensätze aus AV-Res
foreach ($avIds as $avId) {
    echo "--- AV-ID: $avId ---\n";
    
    // WebImp Daten
    $webimpResult = $mysqli->query("SELECT * FROM `AV-Res-webImp` WHERE av_id = $avId");
    $webimpData = $webimpResult->fetch_assoc();
    
    // AV-Res Daten  
    $avresResult = $mysqli->query("SELECT * FROM `AV-Res` WHERE av_id = $avId");
    $avresData = $avresResult->fetch_assoc();
    
    if (!$webimpData || !$avresData) {
        echo "  FEHLER: Datensatz nicht in beiden Tabellen gefunden\n\n";
        continue;
    }
    
    // Vergleiche kritische Felder
    $fields = ['anreise', 'abreise', 'lager', 'betten', 'dz', 'sonder', 'gruppe', 'nachname', 'vorname', 'email', 'vorgang'];
    $hasChanges = false;
    
    foreach ($fields as $field) {
        $webimpVal = $webimpData[$field];
        $avresVal = $avresData[$field];
        
        // Normalisierung wie im Import-Script
        if (in_array($field, ['gruppe', 'nachname', 'vorname', 'email', 'vorgang'])) {
            $webimpVal = ($webimpVal === '' || $webimpVal === null) ? null : $webimpVal;
            $avresVal = ($avresVal === '' || $avresVal === null) ? null : $avresVal;
        } elseif (in_array($field, ['lager', 'betten', 'dz', 'sonder'])) {
            $webimpVal = (int)$webimpVal;
            $avresVal = (int)$avresVal;
        }
        
        if ($webimpVal !== $avresVal) {
            echo "  DIFF $field: WebImp='" . var_export($webimpVal, true) . "' vs AV-Res='" . var_export($avresVal, true) . "'\n";
            $hasChanges = true;
        }
    }
    
    if (!$hasChanges) {
        echo "  IDENTISCH: Keine Änderungen erkannt\n";
    }
    echo "\n";
}

// Zusätzlich: Prüfe bem_av Feld separat (das wird oft übersehen)
echo "=== bem_av Feld Analyse ===\n";
$avId = $avIds[0];
$webimpResult = $mysqli->query("SELECT av_id, bem_av FROM `AV-Res-webImp` WHERE av_id = $avId");
$webimpData = $webimpResult->fetch_assoc();
$avresResult = $mysqli->query("SELECT av_id, bem_av FROM `AV-Res` WHERE av_id = $avId");
$avresData = $avresResult->fetch_assoc();

echo "AV-ID $avId bem_av:\n";
echo "  WebImp: '" . var_export($webimpData['bem_av'], true) . "' (type: " . gettype($webimpData['bem_av']) . ")\n";
echo "  AV-Res: '" . var_export($avresData['bem_av'], true) . "' (type: " . gettype($avresData['bem_av']) . ")\n";

// Normalisiert
$webimpNorm = ($webimpData['bem_av'] === '' || $webimpData['bem_av'] === null) ? null : $webimpData['bem_av'];
$avresNorm = ($avresData['bem_av'] === '' || $avresData['bem_av'] === null) ? null : $avresData['bem_av'];
echo "  Normalisiert gleich? " . ($webimpNorm === $avresNorm ? 'JA' : 'NEIN') . "\n";
?>
