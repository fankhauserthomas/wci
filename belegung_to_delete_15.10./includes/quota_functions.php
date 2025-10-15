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
 * Generiert einen optimierten Quota-Namen für tagesbasierte Berechnung
 * @param array $quotas Array mit Quota-Daten
 * @param string $date Datum für das der Name generiert wird (Y-m-d)
 * @param bool $isOptimized Ob dieser Quota optimiert wurde
 * @return string Generierter Quota-Name
 */
function generateOptimizedQuotaName($quotas, $date, $isOptimized = false) {
    if ($isOptimized) {
        // Einfaches Format: Auto-ddmm (z.B. Auto-0109 für 1. September)
        $dateFormatted = date('dm', strtotime($date));
        return "Auto-{$dateFormatted}";
    }
    
    // Nicht optimiert - zeige originalen Namen oder Fallback
    if (!empty($quotas)) {
        $quota = $quotas[0];
        $originalName = $quota['title'] ?? '';
        if (!empty($originalName)) {
            return $originalName;
        }
    }
    
    // Fallback wenn keine Quotas oder kein Name vorhanden
    $dateFormatted = date('dm', strtotime($date));
    return "Auto-{$dateFormatted}";
}

/**
 * Legacy-Funktion für Gruppierung (wird nicht mehr verwendet)
 * Gruppiert identische Quotas für rowspan-Darstellung
 * @param array $alleDaten Array mit Tagesdaten und Quotas
 * @return array Array mit gruppierten Quota-Informationen
 */
function groupIdenticalQuotas($alleDaten) {
    // Diese Funktion wird bei tagesbasierter Optimierung nicht mehr verwendet
    // Behalten für Rückwärtskompatibilität, falls irgendwo noch aufgerufen
    return [];
}

/**
 * Berechnet optimierte Quotas für exakte Zielauslastung (tagesbasiert)
 * @param array $currentQuotas Aktuelle Quotas für diesen Tag
 * @param int $zielauslastung Gewünschte Zielauslastung
 * @param int $gesamtBelegt Aktuelle Gesamtbelegung
 * @param int $freieQuotas Aktuelle freie Quotas
 * @return array Array mit optimierten Quota-Werten
 */
function calculateOptimizedQuotasForExactTarget($currentQuotas, $zielauslastung, $gesamtBelegt, $freieQuotas) {
    if (empty($currentQuotas)) {
        return [
            'sonder' => 0,
            'lager' => 0, 
            'betten' => 0,
            'dz' => 0,
            'should_optimize' => false
        ];
    }
    
    $quota = $currentQuotas[0];
    
    // PRÄZISE BERECHNUNG: Exakt auf Zielauslastung kommen
    // Neue Gesamtauslastung soll genau der Zielauslastung entsprechen
    // Zielauslastung = Gesamtbelegung + Freie Quotas (neu)
    // => Freie Quotas (neu) = Zielauslastung - Gesamtbelegung
    $benoetigteFreieQuotas = $zielauslastung - $gesamtBelegt;
    $quotaAnpassung = $benoetigteFreieQuotas - $freieQuotas;
    
    // Aktuelle Quota-Werte als Ausgangsbasis
    $neueQuotaSonder = $quota['categories']['SK']['total_beds'] ?? 0;
    $neueQuotaLager = $quota['categories']['ML']['total_beds'] ?? 0;
    $neueQuotaBetten = $quota['categories']['MBZ']['total_beds'] ?? 0;
    $neueQuotaDz = $quota['categories']['2BZ']['total_beds'] ?? 0;
    
    // Optimierung nur wenn Anpassung nötig
    if ($quotaAnpassung != 0) {
        // Primäre Strategie: Lager-Quota anpassen (meist die flexibelste Kategorie)
        if ($neueQuotaLager > 0) {
            $zielLagerQuota = $neueQuotaLager + $quotaAnpassung;
            $neueQuotaLager = max(0, $zielLagerQuota);
        }
        // Fallback: Verteilung auf alle verfügbaren Kategorien
        else {
            $verfuegbareKategorien = [];
            if ($neueQuotaSonder > 0) $verfuegbareKategorien[] = 'sonder';
            if ($neueQuotaBetten > 0) $verfuegbareKategorien[] = 'betten'; 
            if ($neueQuotaDz > 0) $verfuegbareKategorien[] = 'dz';
            
            if (!empty($verfuegbareKategorien)) {
                $anteilProKategorie = $quotaAnpassung / count($verfuegbareKategorien);
                
                foreach ($verfuegbareKategorien as $kat) {
                    switch ($kat) {
                        case 'sonder':
                            $neueQuotaSonder = max(0, $neueQuotaSonder + $anteilProKategorie);
                            break;
                        case 'betten':
                            $neueQuotaBetten = max(0, $neueQuotaBetten + $anteilProKategorie);
                            break;
                        case 'dz':
                            $neueQuotaDz = max(0, $neueQuotaDz + $anteilProKategorie);
                            break;
                    }
                }
            }
        }
    }
    
    return [
        'sonder' => $neueQuotaSonder,
        'lager' => $neueQuotaLager,
        'betten' => $neueQuotaBetten,
        'dz' => $neueQuotaDz,
        'should_optimize' => ($quotaAnpassung != 0),
        'quota_adjustment' => $quotaAnpassung,
        'target_free_quotas' => $benoetigteFreieQuotas
    ];
}
?>
