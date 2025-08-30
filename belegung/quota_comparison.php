<?php
/**
 * Quota Comparison API
 * Vergleicht aktuelle Quotas mit optimierten Quotas
 */

require_once '../config.php';
require_once 'includes/utility_functions.php';
require_once 'includes/quota_functions.php';
require_once 'includes/database_functions.php';

// Parameter auslesen
$startDate = $_GET['start'] ?? date('Y-m-d');
$endDate = $_GET['end'] ?? date('Y-m-d', strtotime($startDate . ' +31 days'));
$zielauslastung = (int)($_GET['za'] ?? 135);
$json = isset($_GET['json']);

try {
    // Belegungsdaten laden
    $resultSet = getErweiterteGelegungsDaten($mysqli, $startDate, $endDate);
    $detailDaten = $resultSet['detail'];
    $quotaData = getQuotaData($mysqli, $startDate, $endDate);
    
    $comparison = [];
    
    // Iteriere durch jeden Tag im Zeitraum
    $currentDate = new DateTime($startDate);
    $endDateTime = new DateTime($endDate);
    
    while ($currentDate <= $endDateTime) {
        $dateStr = $currentDate->format('Y-m-d');
        $dayData = [
            'datum' => $dateStr,
            'hrs_id' => null,
            'old_quotas' => [],
            'new_quotas' => [],
            'changes' => [],
            'occupancy' => []
        ];
        
        // Hole HRS ID für diesen Tag aus den Quota-Daten
        if (isset($quotaData[$dateStr]) && !empty($quotaData[$dateStr])) {
            // Nimm die HRS ID aus dem ersten Quota-Eintrag für diesen Tag
            $dayData['hrs_id'] = $quotaData[$dateStr][0]['hrs_id'] ?? null;
        }
        
        // Aktuelle Quotas für diesen Tag
        $oldQuotas = [];
        if (isset($quotaData[$dateStr])) {
            foreach ($quotaData[$dateStr] as $quota) {
                $type = $quota['quota_type'];
                $oldQuotas[$type] = (int)$quota['quota_value'];
            }
        }
        $dayData['old_quotas'] = $oldQuotas;
        
        // Belegungsdaten für diesen Tag
        $occupancyData = [];
        if (isset($detailDaten[$dateStr])) {
            $dayDetail = $detailDaten[$dateStr];
            $occupancyData = [
                'total' => $dayDetail['gesamt_belegt'] ?? 0,
                'sonder' => $dayDetail['belegt_sonder'] ?? 0,
                'lager' => $dayDetail['belegt_lager'] ?? 0,
                'betten' => $dayDetail['belegt_betten'] ?? 0,
                'dz' => $dayDetail['belegt_dz'] ?? 0,
                'utilization' => $dayDetail['auslastung_prozent'] ?? 0
            ];
        }
        $dayData['occupancy'] = $occupancyData;
        
        // Berechne neue optimierte Quotas
        $newQuotas = calculateOptimizedQuotas($dayDetail ?? [], $zielauslastung, $oldQuotas);
        $dayData['new_quotas'] = $newQuotas;
        
        // Berechne Änderungen
        $changes = [];
        foreach ($newQuotas as $type => $newValue) {
            $oldValue = $oldQuotas[$type] ?? 0;
            if ($newValue != $oldValue) {
                $diff = $newValue - $oldValue;
                $changes[] = $type . ': ' . ($diff > 0 ? '+' : '') . $diff;
            }
        }
        $dayData['changes'] = $changes;
        
        $comparison[] = $dayData;
        $currentDate->add(new DateInterval('P1D'));
    }
    
    if ($json) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'comparison' => $comparison,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'target_utilization' => $zielauslastung
        ]);
    } else {
        // HTML Output für Debug
        echo "<h1>Quota Vergleich</h1>";
        echo "<pre>" . json_encode($comparison, JSON_PRETTY_PRINT) . "</pre>";
    }
    
} catch (Exception $e) {
    if ($json) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    } else {
        echo "Fehler: " . $e->getMessage();
    }
}

/**
 * Berechnet optimierte Quotas basierend auf Belegungsdaten und Zielauslastung
 */
function calculateOptimizedQuotas($dayDetail, $zielauslastung, $oldQuotas) {
    $newQuotas = [];
    
    // Wenn keine Daten vorhanden, behalte alte Quotas
    if (empty($dayDetail)) {
        return $oldQuotas;
    }
    
    // Aktuelle Belegung
    $aktueleBelegung = $dayDetail['gesamt_belegt'] ?? 0;
    
    // Berechne erforderliche freie Kapazität für Zielauslastung
    $erforderlicheFreieKapazitaet = max(0, $zielauslastung - $aktueleBelegung);
    
    // Aktuelle freie Kapazitäten
    $freiSonder = $dayDetail['frei_sonder'] ?? 0;
    $freiLager = $dayDetail['frei_lager'] ?? 0;
    $freiBetten = $dayDetail['frei_betten'] ?? 0;
    $freiDz = $dayDetail['frei_dz'] ?? 0;
    
    $aktuelleFreieGesamt = $freiSonder + $freiLager + $freiBetten + $freiDz;
    
    // Berechne Anpassung
    $anpassung = $erforderlicheFreieKapazitaet - $aktuelleFreieGesamt;
    
    // Neue Quotas berechnen (Priorität: Lager -> Sonder -> Betten -> DZ)
    $newQuotas['ML'] = max(0, ($oldQuotas['ML'] ?? 0) + $anpassung); // Lager bekommt Hauptanpassung
    $newQuotas['MS'] = $oldQuotas['MS'] ?? 0; // Sonder bleibt gleich
    $newQuotas['BT'] = $oldQuotas['BT'] ?? 0; // Betten bleibt gleich
    $newQuotas['DZ'] = $oldQuotas['DZ'] ?? 0; // DZ bleibt gleich
    
    // Wenn Lager-Quota negativ werden würde, verteile auf andere
    if ($newQuotas['ML'] < 0 && $anpassung < 0) {
        $restAnpassung = abs($newQuotas['ML']);
        $newQuotas['ML'] = 0;
        
        // Verteile negative Anpassung auf andere Kategorien
        $kategorien = ['MS', 'BT', 'DZ'];
        foreach ($kategorien as $kat) {
            if ($restAnpassung > 0 && $newQuotas[$kat] > 0) {
                $reduzierung = min($restAnpassung, $newQuotas[$kat]);
                $newQuotas[$kat] -= $reduzierung;
                $restAnpassung -= $reduzierung;
            }
        }
    }
    
    // Entferne Quotas mit Wert 0 für saubere Anzeige
    $newQuotas = array_filter($newQuotas, function($value) {
        return $value > 0;
    });
    
    return $newQuotas;
}
?>
