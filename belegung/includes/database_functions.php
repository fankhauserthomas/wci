<?php
/**
 * Database Functions für Belegungsanalyse
 * Datenbankabfragen und Datenverarbeitung
 * 
 * @author System
 * @version 1.0
 * @created 2025-08-29
 */

/**
 * Lädt Quota-Daten aus der Datenbank für einen bestimmten Zeitraum
 * 
 * Sucht nach allen Quotas die in den angegebenen Zeitraum fallen oder ihn überschneiden.
 * Quotas werden mit ihren Kategorien (ML, MBZ, 2BZ, SK) verknüpft.
 * 
 * @param mysqli $mysqli Datenbankverbindung
 * @param string $startDate Start-Datum im Format Y-m-d
 * @param string $endDate End-Datum im Format Y-m-d
 * @return array Array mit Quota-Daten inkl. Kategorien
 * 
 * @throws mysqli_sql_exception Bei Datenbankfehlern
 * 
 * Struktur der Rückgabe:
 * [
 *   [
 *     'id' => 123,
 *     'hrs_id' => 456,
 *     'title' => 'Quota Name',
 *     'date_from' => '2025-08-29',
 *     'date_to' => '2025-08-31',
 *     'capacity' => 150,
 *     'mode' => 'SERVICED',
 *     'categories' => [
 *       'ML' => ['category_id' => 1958, 'total_beds' => 50, 'category_type' => 'ML'],
 *       'SK' => ['category_id' => 6106, 'total_beds' => 20, 'category_type' => 'SK']
 *     ]
 *   ]
 * ]
 */
function getQuotaData($mysqli, $startDate, $endDate) {
    $quotas = [];
    
    // Debug-Ausgabe
    if (isset($_GET['debug'])) {
        echo "<!-- DEBUG getQuotaData: Suche Quotas von $startDate bis $endDate -->\n";
    }
    
    // Lade alle Quotas die in den Zeitraum fallen oder ihn überschneiden
    // Mapping der category_id zu internen Kürzeln:
    // 1958 = ML (Mehrbettzimmer Lager)
    // 2293 = MBZ (Mehrbettzimmer)  
    // 2381 = 2BZ (2-Bettzimmer)
    // 6106 = SK (Sonderkontingent)
    $sql = "SELECT hq.*, hqc.category_id, hqc.total_beds,
                   CASE 
                       WHEN hqc.category_id = 1958 THEN 'ML'
                       WHEN hqc.category_id = 2293 THEN 'MBZ' 
                       WHEN hqc.category_id = 2381 THEN '2BZ'
                       WHEN hqc.category_id = 6106 THEN 'SK'
                       ELSE 'UNKNOWN'
                   END as category_type
            FROM hut_quota hq
            LEFT JOIN hut_quota_categories hqc ON hq.id = hqc.hut_quota_id
            WHERE hq.date_from <= ? AND hq.date_to > ?
            ORDER BY hq.date_from, hq.title, hqc.category_id";
    
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        throw new mysqli_sql_exception("Prepared statement failed: " . $mysqli->error);
    }
    
    $stmt->bind_param('ss', $endDate, $startDate);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $quotaId = $row['id'];
        
        // Initialisiere Quota falls noch nicht vorhanden
        if (!isset($quotas[$quotaId])) {
            $quotas[$quotaId] = [
                'id' => $row['id'],
                'hrs_id' => $row['hrs_id'],
                'title' => $row['title'],
                'date_from' => $row['date_from'],
                'date_to' => $row['date_to'],
                'capacity' => $row['capacity'],
                'mode' => $row['mode'],
                'categories' => []
            ];
        }
        
        // Kategorie hinzufügen falls vorhanden
        if ($row['category_id']) {
            $quotas[$quotaId]['categories'][$row['category_type']] = [
                'category_id' => $row['category_id'],
                'total_beds' => $row['total_beds'],
                'category_type' => $row['category_type']
            ];
        }
    }
    $stmt->close();
    
    // Debug-Ausgabe der gefundenen Quotas
    if (isset($_GET['debug'])) {
        echo "<!-- DEBUG: Gefundene Quotas in DB-Abfrage: " . count($quotas) . "\n";
        foreach ($quotas as $i => $quota) {
            echo "  Quota $i: {$quota['title']} von {$quota['date_from']} bis {$quota['date_to']}\n";
        }
        echo "-->\n";
    }
    
    // Gebe Array ohne Indizes zurück für einfachere Verarbeitung
    return array_values($quotas);
}

/**
 * Berechnet freie Kapazitäten für einen bestimmten Tag
 * 
 * Ermittelt freie Plätze in den verschiedenen Kategorien (Sonder, Lager, Betten, DZ)
 * basierend auf daily_summary_categories. Falls keine Kategorien-Daten vorhanden sind,
 * wird ein Fallback zur daily_summary Tabelle verwendet.
 * 
 * @param mysqli $mysqli Datenbankverbindung
 * @param string $datum Datum im Format Y-m-d
 * @return array Assoziatives Array mit freien Kapazitäten
 * 
 * @throws mysqli_sql_exception Bei Datenbankfehlern
 * 
 * Struktur der Rückgabe:
 * [
 *   'gesamt_frei' => 75,    // Gesamtsumme aller freien Plätze
 *   'sonder_frei' => 15,    // Freie Sonderplätze (SK)
 *   'lager_frei' => 30,     // Freie Lagerplätze (ML)
 *   'betten_frei' => 20,    // Freie Bettenplätze (MBZ)
 *   'dz_frei' => 10         // Freie Doppelzimmer (2BZ)
 * ]
 */
function getFreieKapazitaet($mysqli, $datum) {
    // Initialisiere Ergebnis-Array mit Nullwerten
    $result = [
        'gesamt_frei' => 0,
        'sonder_frei' => 0,
        'lager_frei' => 0,
        'betten_frei' => 0,
        'dz_frei' => 0
    ];
    
    // Prüfe zuerst daily_summary_categories über JOIN mit daily_summary
    // Diese Tabelle enthält die detaillierten Kapazitätsdaten pro Kategorie
    $sql = "SELECT dsc.category_type, dsc.free_places 
            FROM daily_summary_categories dsc
            JOIN daily_summary ds ON dsc.daily_summary_id = ds.id
            WHERE ds.day = ?";
    
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        throw new mysqli_sql_exception("Prepared statement failed: " . $mysqli->error);
    }
    
    $stmt->bind_param('s', $datum);
    $stmt->execute();
    $categoryResult = $stmt->get_result();
    
    $kategorienGefunden = false;
    
    // Durchlaufe alle gefundenen Kategorien
    while ($row = $categoryResult->fetch_assoc()) {
        $kategorienGefunden = true;
        $freePlaces = max(0, (int)$row['free_places']); // Negative Werte auf 0 begrenzen
        
        // Mapping der Kategorien zu internen Bezeichnungen:
        // SK = Sonderkategorie (spezielle Reservierungen)
        // ML = Matratzenlager (Mehrbettlager)
        // MBZ = Mehrbettzimmer (normale Betten)
        // 2BZ = Zweierzimmer (Doppelzimmer)
        switch ($row['category_type']) {
            case 'SK': // Sonderkategorie
                $result['sonder_frei'] += $freePlaces;
                break;
            case 'ML': // Matratzenlager
                $result['lager_frei'] += $freePlaces;
                break;
            case 'MBZ': // Mehrbettzimmer
                $result['betten_frei'] += $freePlaces;
                break;
            case '2BZ': // Zweierzimmer
                $result['dz_frei'] += $freePlaces;
                break;
        }
    }
    $stmt->close();
    
    // Falls Kategorien gefunden wurden, berechne Gesamtsumme
    if ($kategorienGefunden) {
        $result['gesamt_frei'] = $result['sonder_frei'] + $result['lager_frei'] + 
                                $result['betten_frei'] + $result['dz_frei'];
    } else {
        // Fallback: Versuche Daten aus daily_summary zu holen
        // Dies wird verwendet wenn keine detaillierten Kategorien-Daten verfügbar sind
        $sql = "SELECT total_guests FROM daily_summary WHERE day = ?";
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            throw new mysqli_sql_exception("Prepared statement failed: " . $mysqli->error);
        }
        
        $stmt->bind_param('s', $datum);
        $stmt->execute();
        $summaryResult = $stmt->get_result();
        
        if ($row = $summaryResult->fetch_assoc()) {
            // Hier könnten wir eine Schätzung basierend auf Kapazität machen,
            // aber ohne Kapazitätsdaten ist eine genaue Berechnung schwierig.
            // Daher bleibt Ergebnis bei 0 wenn keine Kategorien-Daten vorhanden sind.
            $result['gesamt_frei'] = 0;
        }
        $stmt->close();
    }
    
    return $result;
}

/**
 * Erweiterte Belegungsdaten für einen Zeitraum berechnen
 * 
 * Diese Funktion ist das Herzstück der Belegungsanalyse. Sie:
 * - Generiert alle Tage im angegebenen Zeitraum
 * - Lädt für jeden Tag alle aktiven Reservierungen
 * - Berechnet aggregierte Daten nach Quelle (HRS/Lokal)
 * - Holt freie Kapazitäten aus der Daily Summary
 * - Strukturiert die Daten für die Tabellenansicht
 * 
 * @param mysqli $mysqli    Datenbankverbindung
 * @param string $startDate Startdatum (YYYY-MM-DD)
 * @param string $endDate   Enddatum (YYYY-MM-DD)
 * 
 * @return array Strukturierte Belegungsdaten mit folgenden Schlüsseln:
 *               - 'detail': Detaildaten pro Tag mit Reservierungen
 *               - 'aggregated': Aggregierte Daten für Charts/Analysen
 *               - 'freieKapazitaeten': Freie Kapazitäten pro Tag
 * 
 * @throws Exception Bei ungültigen Datumswerten oder Datenbankfehlern
 * 
 * @example
 * $resultSet = getErweiterteGelegungsDaten($mysqli, '2025-08-29', '2025-08-31');
 * $detailDaten = $resultSet['detail'];
 * $rohdaten = $resultSet['aggregated'];
 * $freieKapazitaeten = $resultSet['freieKapazitaeten'];
 * 
 * @author WCI Belegungsanalyse System
 * @version 1.0 - Extracted from main file for better maintainability
 */
function getErweiterteGelegungsDaten($mysqli, $startDate, $endDate) {
    // Alle Tage im Zeitraum generieren (PHP-basiert)
    $alleTage = [];
    $currentDate = new DateTime($startDate);
    $endDateTime = new DateTime($endDate);
    
    while ($currentDate <= $endDateTime) {
        $alleTage[] = $currentDate->format('Y-m-d');
        $currentDate->add(new DateInterval('P1D'));
    }
    
    // Für jeden Tag die Belegung berechnen
    $detailDaten = [];
    $aggregatedData = [];
    $freieKapazitaeten = [];
    
    foreach ($alleTage as $tag) {
        // Freie Kapazitäten aus Daily Summary holen
        $freieKapazitaeten[$tag] = getFreieKapazitaet($mysqli, $tag);
        
        // Alle Reservierungen die an diesem Tag im Haus sind
        $sql = "SELECT 
            CASE WHEN av_id > 0 THEN 'hrs' ELSE 'lokal' END as quelle,
            av_id, vorname, nachname, gruppe,
            sonder, lager, betten, dz,
            hp, vegi, bem_av, anreise, abreise,
            timestamp as import_zeit
        FROM `AV-Res` 
        WHERE DATE(?) >= DATE(anreise) 
        AND DATE(?) < DATE(abreise)
        AND (storno IS NULL OR storno != 1)
        ORDER BY quelle, nachname";
        
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param('ss', $tag, $tag);
        $stmt->execute();
        $result = $stmt->get_result();
        
        // Aggregierte Daten für Chart
        $tagesDaten = ['hrs' => [], 'lokal' => []];
        $aggregierung = ['hrs' => ['sonder' => 0, 'lager' => 0, 'betten' => 0, 'dz' => 0], 'lokal' => ['sonder' => 0, 'lager' => 0, 'betten' => 0, 'dz' => 0]];
        
        while ($row = $result->fetch_assoc()) {
            $quelle = $row['quelle'];
            $tagesDaten[$quelle][] = $row;
            
            // Aggregierung
            $key = $tag . '_' . $quelle;
            if (!isset($aggregatedData[$key])) {
                $aggregatedData[$key] = [
                    'tag' => $tag,
                    'quelle' => $quelle,
                    'sonder' => 0,
                    'lager' => 0,
                    'betten' => 0,
                    'dz' => 0,
                    'reservierungen' => []
                ];
            }
            
            $aggregatedData[$key]['sonder'] += (int)$row['sonder'];
            $aggregatedData[$key]['lager'] += (int)$row['lager'];
            $aggregatedData[$key]['betten'] += (int)$row['betten'];
            $aggregatedData[$key]['dz'] += (int)$row['dz'];
            $aggregatedData[$key]['reservierungen'][] = $row;
        }
        
        // Detaildaten für spätere Verwendung
        $detailDaten[] = [
            'tag' => $tag,
            'datum_formatted' => date('D d.m.Y', strtotime($tag)),
            'hrs' => $tagesDaten['hrs'],
            'lokal' => $tagesDaten['lokal'],
            'freie_plaetze' => $freieKapazitaeten[$tag]['gesamt_frei'] ?? 0
        ];
    }
    
    return [
        'detail' => $detailDaten,
        'aggregated' => array_values($aggregatedData),
        'freieKapazitaeten' => $freieKapazitaeten
    ];
}
?>
