<?php
/**
 * Quota Helper Functions für Belegungsanalyse
 * Funktionen für Quota-Optimierung und -Verarbeitung
 */

/**
 * Filtert Quotas für ein bestimmtes Datum
 * @param array $quotas Array mit allen Quota-Daten
 * @param string $date Datum für das gefiltert werden soll (Y-m-d)
 * @return array Array mit passenden Quotas für das Datum
 */
function getQuotasForDate($quotas, $date) {
    $matching = [];
    foreach ($quotas as $quota) {
        // Quota gilt für Nächte: date_from bis date_to (exklusiv)
        // Quota vom 1.8. bis 2.8. gilt nur für 1.8. (Nacht vom 1.8. auf 2.8.)
        if ($date >= $quota['date_from'] && $date < $quota['date_to']) {
            $matching[] = $quota;
        }
    }
    
    // Debug: Wenn es der erste Tag ist, zeige alle verfügbaren Quotas
    if (isset($_GET['debug']) && $date == $_GET['start']) {
        echo "<!-- DEBUG für Datum $date:\n";
        echo "Alle Quotas:\n";
        foreach ($quotas as $i => $quota) {
            echo "Quota $i: {$quota['title']} von {$quota['date_from']} bis {$quota['date_to']}\n";
            echo "  Bedingung: $date >= {$quota['date_from']} && $date < {$quota['date_to']}\n";
            echo "  Ergebnis: " . (($date >= $quota['date_from'] && $date < $quota['date_to']) ? 'MATCH' : 'NO MATCH') . "\n";
        }
        echo "Gefundene Quotas: " . count($matching) . "\n";
        if (!empty($matching)) {
            foreach ($matching as $m) {
                echo "  - {$m['title']} von {$m['date_from']} bis {$m['date_to']}\n";
            }
        }
        echo "-->\n";
    }
    
    // Wenn mehrere Quotas gefunden, wähle die beste aus
    if (count($matching) > 1) {
        // Priorisierung:
        // 1. Quota die genau an diesem Tag startet
        // 2. Quota mit dem neuesten Startdatum
        // 3. Quota mit der höchsten HRS_ID (meist neuer)
        usort($matching, function($a, $b) use ($date) {
            // Priorisiere Quota die genau an diesem Tag startet
            $aStartsToday = ($a['date_from'] == $date) ? 1 : 0;
            $bStartsToday = ($b['date_from'] == $date) ? 1 : 0;
            if ($aStartsToday != $bStartsToday) {
                return $bStartsToday - $aStartsToday;
            }
            
            // Dann nach Startdatum (neuestes zuerst)
            $dateCompare = strcmp($b['date_from'], $a['date_from']);
            if ($dateCompare != 0) {
                return $dateCompare;
            }
            
            // Zuletzt nach HRS_ID (höchste zuerst)
            return $b['hrs_id'] - $a['hrs_id'];
        });
        
        // Nur die beste Quota zurückgeben
        return [$matching[0]];
    }
    
    return $matching;
}

/**
 * Generiert einen intelligenten Quota-Namen basierend auf Zeitraum
 * @param array $quotas Array mit Quota-Daten
 * @param string $startDate Start-Datum (Y-m-d)
 * @param string $endDate End-Datum (Y-m-d)
 * @return string Generierter Quota-Name
 */
function generateIntelligentQuotaName($quotas, $startDate, $endDate) {
    // Zeitraum im Format DDMM ohne Punkte
    $start = date('dm', strtotime($startDate));
    $end = date('dm', strtotime($endDate));
    
    return "Auto {$start}-{$end}";
}

/**
 * Gruppiert identische Quotas für rowspan-Darstellung
 * @param array $alleDaten Array mit Tagesdaten und Quotas
 * @return array Array mit gruppierten Quota-Informationen
 */
function groupIdenticalQuotas($alleDaten) {
    $groups = [];
    $currentGroup = null;
    
    foreach ($alleDaten as $i => $tagData) {
        $quotas = $tagData['quotas'];
        $quotaSignature = '';
        
        if (!empty($quotas)) {
            $quota = $quotas[0];
            $quotaSignature = implode('-', [
                $quota['categories']['SK']['total_beds'] ?? 0,
                $quota['categories']['ML']['total_beds'] ?? 0,
                $quota['categories']['MBZ']['total_beds'] ?? 0,
                $quota['categories']['2BZ']['total_beds'] ?? 0
            ]);
        }
        
        // Neue Gruppe starten wenn Quota sich ändert
        if ($currentGroup === null || $currentGroup['signature'] !== $quotaSignature) {
            if ($currentGroup !== null) {
                $groups[] = $currentGroup;
            }
            
            $currentGroup = [
                'signature' => $quotaSignature,
                'start_index' => $i,
                'end_index' => $i,
                'days' => 1,
                'quotas' => $quotas,
                'generated_name' => ''
            ];
        } else {
            // Erweitere aktuelle Gruppe
            $currentGroup['end_index'] = $i;
            $currentGroup['days']++;
        }
    }
    
    // Letzte Gruppe hinzufügen
    if ($currentGroup !== null) {
        $groups[] = $currentGroup;
    }
    
    return $groups;
}
?>
