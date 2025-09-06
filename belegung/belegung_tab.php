<?php
/**
 * Belegungsanalyse - Nur Tabelle
 * Erweiterte Belegungsanalyse ohne Chart-Funktionalität
 */

require_once '../config.php';
require_once 'includes/utility_functions.php';
require_once 'includes/quota_functions.php';
require_once 'includes/database_functions.php';

// Zeitraum: heute + 31 Tage (kann später parametrisiert werden)
$startDate = $_GET['start'] ?? date('Y-m-d');
$endDate = $_GET['end'] ?? date('Y-m-d', strtotime($startDate . ' +31 days'));

// Quota-Optimierung Konfiguration
$zielauslastung = $_GET['za'] ?? 135; // Zielauslastung (default 135)

// Daten laden
$resultSet = getErweiterteGelegungsDaten($mysqli, $startDate, $endDate);
$detailDaten = $resultSet['detail'];
$rohdaten = $resultSet['aggregated'];
$freieKapazitaeten = $resultSet['freieKapazitaeten'];

// Quota-Daten laden
$quotaData = getQuotaData($mysqli, $startDate, $endDate);

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Belegungsanalyse - Tabellenansicht</title>
    <link rel="stylesheet" href="style/belegung_tab.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
</head>
<body>
    <div class="container">
        <div class="controls">
            <div class="control-group">
                <label for="startDate">Startdatum:</label>
                <input type="date" id="startDate" value="<?= $startDate ?>">
            </div>
            <div class="control-group">
                <label for="endDate">Enddatum:</label>
                <input type="date" id="endDate" value="<?= $endDate ?>">
            </div>
            <div class="control-group">
                <label>&nbsp;</label>
                <button onclick="updateData()">Aktualisieren</button>
            </div>
            <div class="control-group">
                <label>&nbsp;</label>
                <button onclick="importHRSData()" class="btn-import" id="importBtn">HRS Import</button>
            </div>
            <div class="control-group">
                <label>&nbsp;</label>
                <button onclick="importWebImpData()" class="btn-import" id="webimpBtn">WebImp → Production</button>
            </div>
            <div class="control-group">
                <label>&nbsp;</label>
                <button onclick="importWebImpData(true)" class="btn-import" id="dryRunBtn" style="background: #FF9800;">Dry-Run Test</button>
            </div>
            <div class="control-group">
                <label>&nbsp;</label>
                <button onclick="showBackupPanel()" class="btn-import" style="background: #e74c3c;">Backup-Verwaltung</button>
            </div>
            <div class="control-group">
                <label>&nbsp;</label>
                <button onclick="showAnalysisPanel()" class="btn-import" style="background: #9b59b6;">Import-Analyse</button>
            </div>
            <div class="control-group">
                <label>&nbsp;</label>
                <button class="export-btn export-excel" onclick="exportToExcel()">Excel Export</button>
            </div>
            <div class="control-group">
                <label>&nbsp;</label>
                <button onclick="toggleQuotaOptimization()" class="btn-import" style="background: #17a2b8;" id="quotaOptBtn">Quota-Optimierung</button>
            </div>
        </div>
        
        <!-- Quota-Optimierung Konfiguration -->
        <div id="quotaOptimizationPanel" style="display: none; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 5px; padding: 15px; margin-bottom: 15px;">
            <h4 style="margin: 0 0 10px 0; color: #495057;">⚙️ Quota-Optimierung Konfiguration</h4>
            
            <!-- Zeitraumauswahl -->
            <div style="background: #e9ecef; padding: 10px; border-radius: 4px; margin-bottom: 15px;">
                <div style="display: flex; gap: 15px; flex-wrap: wrap; align-items: center; margin-bottom: 10px;">
                    <div>
                        <label for="quotaStartDate" style="font-weight: bold;">Von:</label>
                        <input type="date" id="quotaStartDate" value="<?= $startDate ?>" style="padding: 4px; margin-left: 5px;">
                    </div>
                    <div>
                        <label for="quotaEndDate" style="font-weight: bold;">Bis:</label>
                        <input type="date" id="quotaEndDate" value="<?= $endDate ?>" style="padding: 4px; margin-left: 5px;">
                    </div>
                    <div>
                        <button onclick="toggleQuotaDateSelection()" id="dateSelectionBtn" style="background: #17a2b8; color: white; border: none; padding: 6px 12px; border-radius: 3px; cursor: pointer;">📅 In Tabelle auswählen</button>
                    </div>
                    <div>
                        <button onclick="resetQuotaSelection()" style="background: #6c757d; color: white; border: none; padding: 6px 12px; border-radius: 3px; cursor: pointer;">🗑️ Auswahl löschen</button>
                    </div>
                </div>
                <div id="quotaSelectionHint" style="display: none; font-size: 12px; color: #6c757d; font-style: italic;">
                    🖱️ Klicken Sie zuerst auf das <strong>Startdatum</strong>, dann auf das <strong>Enddatum</strong> in der Tabelle
                </div>
            </div>
            
            <!-- Optimierung -->
            <div style="display: flex; gap: 15px; flex-wrap: wrap; align-items: center;">
                <div>
                    <label for="zielauslastung" style="font-weight: bold;">Zielauslastung (ZA):</label>
                    <input type="number" id="zielauslastung" value="<?= $zielauslastung ?>" style="width: 70px; padding: 4px; margin-left: 5px;">
                </div>
                <div>
                    <button onclick="applyQuotaOptimization()" style="background: #28a745; color: white; border: none; padding: 6px 12px; border-radius: 3px; cursor: pointer;">Anwenden</button>
                </div>
                <div>
                    <button onclick="showQuotaChangesPreview()" style="background: #6f42c1; color: white; border: none; padding: 6px 12px; border-radius: 3px; cursor: pointer;">🔄 Änderungs-Vorschau</button>
                </div>
                <div>
                    <button onclick="showQuotaComparison()" style="background: #17a2b8; color: white; border: none; padding: 6px 12px; border-radius: 3px; cursor: pointer;">📊 Quota Vergleich</button>
                </div>
            </div>
            <div style="margin-top: 10px; font-size: 12px; color: #6c757d;">
                <strong>Hinweis:</strong> Primär wird die Lager-Quota (ML) angepasst. Bei fehlender Lager-Quota wird auf andere Kategorien verteilt.
            </div>
            <div style="margin-top: 8px; font-size: 11px; color: #495057; background: #f8f9fa; padding: 8px; border-radius: 4px;">
                <strong>📊 Berechnung:</strong><br>
                <strong>• Formel:</strong> Neue Freie Quotas = Zielauslastung - Gesamtbelegung<br>
                <strong>• Schutz:</strong> Quotas gehen nie unter 0<br>
                <strong>• Anpassung:</strong> Direkte Berechnung ohne Rundung
            </div>
        </div>
        
        <!-- Progress Indicator -->
        <div id="progressContainer" class="progress-container">
            <div class="progress-steps">
                <div class="progress-step" id="step1">
                    <div class="step-circle">1</div>
                    <div class="step-label">Reservierungen</div>
                    <div class="step-result" id="step1Result"></div>
                </div>
                <div class="progress-step" id="step2">
                    <div class="step-circle">2</div>
                    <div class="step-label">Daily Summaries</div>
                    <div class="step-result" id="step2Result"></div>
                </div>
                <div class="progress-step" id="step3">
                    <div class="step-circle">3</div>
                    <div class="step-label">Quotas</div>
                    <div class="step-result" id="step3Result"></div>
                </div>
            </div>
            <div class="progress-status" id="progressStatus">Vorbereitung...</div>
        </div>

        <!-- Kontroll-Tabelle -->
        <div style="margin-top: 15px;">
            <div class="table-container">
                <table class="sticky-table">
                    <thead>
                        <tr>
                            <th class="header-datum" style="background: #f5f5f5;">Datum</th>
                            <th style="background: #e8f5e8;">Frei<br>Ges.</th>
                            <th style="background: #d4edda;">Frei<br>Quot.</th>
                            <th style="background: #f3e5f5;">Frei<br>Son.</th>
                            <th style="background: #e5f5e5;">Frei<br>Lag.</th>
                            <th style="background: #fffbe5;">Frei<br>Bet.</th>
                            <th style="background: #ffe5e5;">Frei<br>DZ</th>
                            <th class="header-quota-name" style="background: #fff3cd;">Quota Name</th>
                            <th style="background: #f0f8ff;">Q-S</th>
                            <th style="background: #f0f8ff;">Q-L</th>
                            <th style="background: #f0f8ff;">Q-B</th>
                            <th style="background: #f0f8ff;">Q-D</th>
                            <th style="color: #9966CC;">H-S</th>
                            <th style="color: #7722CC;">L-S</th>
                            <th style="color: #66CC66;">H-L</th>
                            <th style="color: #228822;">L-L</th>
                            <th style="color: #CCCC66;">H-B</th>
                            <th style="color: #CCCC00;">L-B</th>
                            <th style="color: #FF6666;">H-D</th>
                            <th style="color: #CC2222;">L-D</th>
                            <th style="background: #f5f5f5;">Ges.<br>Bel.</th>
                            <th style="background: #e6f3ff;">NQ-S</th>
                            <th style="background: #e6f3ff;">NQ-L</th>
                            <th style="background: #e6f3ff;">NQ-B</th>
                            <th style="background: #e6f3ff;">NQ-D</th>
                            <th style="background: #f0f8e6;" class="header-quota-name">Neu Q-Name</th>
                            <th style="background: #fff2e6;">Alt<br>GA</th>
                            <th style="background: #e6ffe6;">Neu<br>GA</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Alle Tage durchgehen und Daten strukturieren
                        $alleTage = [];
                        $currentDate = new DateTime($startDate);
                        $endDateTime = new DateTime($endDate);
                        
                        while ($currentDate <= $endDateTime) {
                            $alleTage[] = $currentDate->format('Y-m-d');
                            $currentDate->add(new DateInterval('P1D'));
                        }
                        
                        // Daten für Tabelle vorbereiten
                        $datenIndex = [];
                        foreach ($rohdaten as $row) {
                            $key = $row['tag'] . '_' . $row['quelle'];
                            $datenIndex[$key] = $row;
                        }
                        
                        // Quota-Spans vorberechnen (für rowspan)
                        $quotaSpans = [];
                        $processedQuotas = [];
                        
                        // === VEREINFACHTE QUOTA-OPTIMIERUNG: Tagesbasiert ===
                        // Keine Gruppierung mehr nötig - jeder Tag wird individuell optimiert
                        
                        // Original Quota-Spans (für bestehende Quota-Spalten)
                        foreach ($alleTage as $i => $tag) {
                            $tagesQuotas = getQuotasForDate($quotaData, $tag);
                            if (!empty($tagesQuotas)) {
                                $quota = $tagesQuotas[0];
                                $quotaKey = $quota['id'] . '_' . $quota['date_from'] . '_' . $quota['date_to'];
                                
                                if (!isset($processedQuotas[$quotaKey])) {
                                    // Berechne wie viele Nächte diese Quota abdeckt
                                    // Quota vom 1.8. bis 2.8. = nur 1 Nacht (1.8.)
                                    // Quota vom 1.8. bis 5.8. = 4 Nächte (1.8., 2.8., 3.8., 4.8.)
                                    $quotaStart = max($quota['date_from'], $startDate);
                                    $quotaEnd = min($quota['date_to'], $endDate);
                                    
                                    $start = new DateTime($quotaStart);
                                    $end = new DateTime($quotaEnd);
                                    $interval = $start->diff($end);
                                    $quotaSpanDays = $interval->days; // NICHT +1, da Endtag nicht eingeschlossen
                                    
                                    // Finde den ersten Tag dieser Quota in unserer Liste
                                    $firstDayIndex = array_search($quotaStart, $alleTage);
                                    if ($firstDayIndex !== false && $quotaSpanDays > 0) {
                                        $quotaSpans[$firstDayIndex] = $quotaSpanDays;
                                        $processedQuotas[$quotaKey] = $firstDayIndex;
                                    }
                                }
                            }
                        }
                        
                        foreach ($alleTage as $i => $tag) {
                            $datum = new DateTime($tag);
                            $hrsKey = $tag . '_hrs';
                            $lokalKey = $tag . '_lokal';
                            
                            $hrsData = isset($datenIndex[$hrsKey]) ? $datenIndex[$hrsKey] : ['sonder' => 0, 'lager' => 0, 'betten' => 0, 'dz' => 0];
                            $lokalData = isset($datenIndex[$lokalKey]) ? $datenIndex[$lokalKey] : ['sonder' => 0, 'lager' => 0, 'betten' => 0, 'dz' => 0];
                            
                            $freieKap = isset($freieKapazitaeten[$tag]) ? $freieKapazitaeten[$tag] : [
                                'gesamt_frei' => 0, 'sonder_frei' => 0, 'lager_frei' => 0, 'betten_frei' => 0, 'dz_frei' => 0
                            ];
                            
                            // Quotas für diesen Tag
                            $tagesQuotas = getQuotasForDate($quotaData, $tag);
                            
                            $gesamtBelegt = $hrsData['sonder'] + $hrsData['lager'] + $hrsData['betten'] + $hrsData['dz'] +
                                           $lokalData['sonder'] + $lokalData['lager'] + $lokalData['betten'] + $lokalData['dz'];
                            
                            // Quota-Hintergrundfarbe bestimmen
                            $quotaName = '';
                            $quotaHrsId = '';
                            $quotaSonder = $quotaLager = $quotaBetten = $quotaDz = '';
                            $freieQuotas = 0;
                            
                            // Quota-Zahlen initialisieren (numerisch) - immer setzen für Quota-Optimierung
                            $quotaSonderNum = 0;
                            $quotaLagerNum = 0;
                            $quotaBettenNum = 0;
                            $quotaDzNum = 0;
                            
                            // Wochenende erkennen (für Datum-Formatierung)
                            $dayOfWeek = $datum->format('w');
                            $isWeekend = ($dayOfWeek == 0 || $dayOfWeek == 6);
                            
                            // Normale Zebrastreifen ohne Quota-Färbung
                            $rowBgColor = ($i % 2 == 0) ? '#f9f9f9' : 'white';
                            
                            if (!empty($tagesQuotas)) {
                                $quota = $tagesQuotas[0]; // Erste Quota nehmen
                                $quotaName = $quota['title'];
                                $quotaHrsId = $quota['hrs_id'];
                                
                                // Quota-Zahlen aus Kategorien extrahieren (numerisch)
                                if (!empty($quota['categories'])) {
                                    $quotaSonderNum = isset($quota['categories']['SK']) ? $quota['categories']['SK']['total_beds'] : 0;
                                    $quotaLagerNum = isset($quota['categories']['ML']) ? $quota['categories']['ML']['total_beds'] : 0;
                                    $quotaBettenNum = isset($quota['categories']['MBZ']) ? $quota['categories']['MBZ']['total_beds'] : 0;
                                    $quotaDzNum = isset($quota['categories']['2BZ']) ? $quota['categories']['2BZ']['total_beds'] : 0;
                                    
                                    // Freie Quotas berechnen: max(0, Quota - HRS_belegt) pro Kategorie
                                    $freieQuotas = max(0, $quotaSonderNum - $hrsData['sonder']) +
                                                  max(0, $quotaLagerNum - $hrsData['lager']) +
                                                  max(0, $quotaBettenNum - $hrsData['betten']) +
                                                  max(0, $quotaDzNum - $hrsData['dz']);
                                }
                                
                                // Für Anzeige: leere Werte als '' darstellen
                                $quotaSonder = $quotaSonderNum > 0 ? $quotaSonderNum : '';
                                $quotaLager = $quotaLagerNum > 0 ? $quotaLagerNum : '';
                                $quotaBetten = $quotaBettenNum > 0 ? $quotaBettenNum : '';
                                $quotaDz = $quotaDzNum > 0 ? $quotaDzNum : '';
                            } else {
                                // Keine Quotas vorhanden - Anzeige-Werte als leer setzen
                                $quotaSonder = $quotaLager = $quotaBetten = $quotaDz = '';
                            }
                            
                            echo '<tr style="background: ' . $rowBgColor . ';" onclick="selectDateForQuota(' . $i . ')" data-date="' . $datum->format('Y-m-d') . '" class="table-row">';
                            
                            // Deutsche Datumstexte
                            $germanDays = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
                            $dayOfWeek = $germanDays[$datum->format('w')];
                            
                            // Datum mit deutscher Wochenend-Kennzeichnung
                            $datumClasses = 'cell-base cell-datum';
                            $datumText = $dayOfWeek . ' ' . $datum->format('d.m.');
                            $fullDate = $datum->format('Y-m-d'); // Echtes Datum für Hintergrund
                            
                            if ($isWeekend) {
                                $datumClasses .= ' cell-weekend';
                                // Kein Emoji mehr für Wochenende
                            }
                            
                            echo '<td class="' . $datumClasses . '" data-full-date="' . $fullDate . '" id="date-cell-' . $i . '">' . $datumText . '</td>';
                            
                            // Freie Kapazitäten - Null-Werte als leer anzeigen
                            echo '<td class="cell-base cell-frei-gesamt">' . 
                                 ($freieKap['gesamt_frei'] > 0 ? $freieKap['gesamt_frei'] : '') . '</td>';
                            
                            // Frei Quotas mit rotem Hintergrund wenn ungleich Frei Gesamt
                            $quotasBgColor = '#d4edda'; // Standard grün
                            if ($freieQuotas != $freieKap['gesamt_frei']) {
                                $quotasBgColor = '#f8d7da'; // Rot wenn Quotas ≠ Frei Gesamt
                            }
                            echo '<td class="cell-base" style="background: ' . $quotasBgColor . '; font-weight: bold;">' . 
                                 ($freieQuotas > 0 ? $freieQuotas : '') . '</td>';
                            echo '<td class="cell-base cell-frei-sonder">' . 
                                 ($freieKap['sonder_frei'] > 0 ? $freieKap['sonder_frei'] : '') . '</td>';
                            echo '<td class="cell-base cell-frei-lager">' . 
                                 ($freieKap['lager_frei'] > 0 ? $freieKap['lager_frei'] : '') . '</td>';
                            echo '<td class="cell-base cell-frei-betten">' . 
                                 ($freieKap['betten_frei'] > 0 ? $freieKap['betten_frei'] : '') . '</td>';
                            echo '<td class="cell-base cell-frei-dz">' . 
                                 ($freieKap['dz_frei'] > 0 ? $freieKap['dz_frei'] : '') . '</td>';
                                 
                            // Quota-Informationen mit rowspan
                            // Prüfe ob dies der erste Tag einer mehrtägigen Quota ist
                            $isFirstQuotaDay = isset($quotaSpans[$i]);
                            $isQuotaContinuation = false;
                            
                            // Prüfe ob dies ein Fortsetzungstag einer Quota ist
                            if (!$isFirstQuotaDay && !empty($tagesQuotas)) {
                                $currentQuota = $tagesQuotas[0];
                                foreach ($quotaSpans as $firstDayIndex => $spanDays) {
                                    if ($i > $firstDayIndex && $i <= $firstDayIndex + $spanDays - 1) {
                                        // Dies ist ein Fortsetzungstag, überspringe Quota-Zellen
                                        $isQuotaContinuation = true;
                                        break;
                                    }
                                }
                            }
                            
                            if (!$isQuotaContinuation) {
                                $rowspanAttr = $isFirstQuotaDay ? ' rowspan="' . $quotaSpans[$i] . '"' : '';
                                
                                // Tooltip für Quota-Name mit HRS-ID
                                $quotaTitle = $quotaHrsId ? "title=\"Quota ID (HRS): {$quotaHrsId}\"" : '';
                                
                                echo '<td' . $rowspanAttr . ' class="cell-base cell-quota-name" ' . $quotaTitle . '>' . 
                                     htmlspecialchars($quotaName) . '</td>';
                                echo '<td' . $rowspanAttr . ' class="cell-base cell-quota-data">' . $quotaSonder . '</td>';
                                echo '<td' . $rowspanAttr . ' class="cell-base cell-quota-data">' . $quotaLager . '</td>';
                                echo '<td' . $rowspanAttr . ' class="cell-base cell-quota-data">' . $quotaBetten . '</td>';
                                echo '<td' . $rowspanAttr . ' class="cell-base cell-quota-data">' . $quotaDz . '</td>';
                            }
                            
                            // Belegungszahlen - Null-Werte als leer anzeigen
                            echo '<td class="cell-base cell-hrs-sonder">' . ($hrsData['sonder'] > 0 ? $hrsData['sonder'] : '') . '</td>';
                            echo '<td class="cell-base cell-lokal-sonder">' . ($lokalData['sonder'] > 0 ? $lokalData['sonder'] : '') . '</td>';
                            echo '<td class="cell-base cell-hrs-lager">' . ($hrsData['lager'] > 0 ? $hrsData['lager'] : '') . '</td>';
                            echo '<td class="cell-base cell-lokal-lager">' . ($lokalData['lager'] > 0 ? $lokalData['lager'] : '') . '</td>';
                            echo '<td class="cell-base cell-hrs-betten">' . ($hrsData['betten'] > 0 ? $hrsData['betten'] : '') . '</td>';
                            echo '<td class="cell-base cell-lokal-betten">' . ($lokalData['betten'] > 0 ? $lokalData['betten'] : '') . '</td>';
                            echo '<td class="cell-base cell-hrs-dz">' . ($hrsData['dz'] > 0 ? $hrsData['dz'] : '') . '</td>';
                            echo '<td class="cell-base cell-lokal-dz">' . ($lokalData['dz'] > 0 ? $lokalData['dz'] : '') . '</td>';
                            
                            echo '<td class="cell-base cell-gesamt">' . ($gesamtBelegt > 0 ? $gesamtBelegt : '') . '</td>';
                            
                            // === QUOTA-OPTIMIERUNG FÜR EXAKTE ZIELAUSLASTUNG ===
                            // Jeder Tag wird individuell optimiert für präzise Zielauslastung
                            $altGesamtAuslastung = $gesamtBelegt + $freieQuotas;
                            
                            // Neue Quotas initialisieren mit aktuellen Werten
                            $neueQuotaSonder = $quotaSonderNum;
                            $neueQuotaLager = $quotaLagerNum;
                            $neueQuotaBetten = $quotaBettenNum;
                            $neueQuotaDz = $quotaDzNum;
                            
                            // Optimierung für jeden Tag separat durchführen
                            if (!empty($tagesQuotas)) {
                                $optimizedResult = calculateOptimizedQuotasForExactTarget(
                                    $tagesQuotas, $zielauslastung, $gesamtBelegt, $freieQuotas
                                );
                                
                                if ($optimizedResult['should_optimize']) {
                                    $neueQuotaSonder = $optimizedResult['sonder'];
                                    $neueQuotaLager = $optimizedResult['lager'];
                                    $neueQuotaBetten = $optimizedResult['betten'];
                                    $neueQuotaDz = $optimizedResult['dz'];
                                }
                            }
                            
                            // Berechne neue Gesamtauslastung
                            $neueFreieQuotas = max(0, $neueQuotaSonder - $hrsData['sonder']) +
                                              max(0, $neueQuotaLager - $hrsData['lager']) +
                                              max(0, $neueQuotaBetten - $hrsData['betten']) +
                                              max(0, $neueQuotaDz - $hrsData['dz']);
                            $neueGesamtAuslastung = $gesamtBelegt + $neueFreieQuotas;
                            
                            // Neue Quota-Spalten ausgeben mit Farbkodierung
                            $sonderBgColor = getCellBackgroundColor($quotaSonderNum, $neueQuotaSonder);
                            $lagerBgColor = getCellBackgroundColor($quotaLagerNum, $neueQuotaLager);
                            $bettenBgColor = getCellBackgroundColor($quotaBettenNum, $neueQuotaBetten);
                            $dzBgColor = getCellBackgroundColor($quotaDzNum, $neueQuotaDz);
                            
                            echo '<td class="cell-base" style="background: ' . $sonderBgColor . ';">' . 
                                 ($neueQuotaSonder > 0 ? $neueQuotaSonder : '') . '</td>';
                            echo '<td class="cell-base" style="background: ' . $lagerBgColor . ';">' . 
                                 ($neueQuotaLager > 0 ? $neueQuotaLager : '') . '</td>';
                            echo '<td class="cell-base" style="background: ' . $bettenBgColor . ';">' . 
                                 ($neueQuotaBetten > 0 ? $neueQuotaBetten : '') . '</td>';
                            echo '<td class="cell-base" style="background: ' . $dzBgColor . ';">' . 
                                 ($neueQuotaDz > 0 ? $neueQuotaDz : '') . '</td>';
                            
                            // Neuer Quota-Name für tagesbasierte Optimierung
                            $wasOptimized = false;
                            if (!empty($tagesQuotas)) {
                                // Prüfe ob für diesen Tag optimiert wurde
                                $optimizedResult = calculateOptimizedQuotasForExactTarget(
                                    $tagesQuotas, $zielauslastung, $gesamtBelegt, $freieQuotas
                                );
                                $wasOptimized = $optimizedResult['should_optimize'];
                            }
                            
                            $optimizedQuotaName = generateOptimizedQuotaName($tagesQuotas, $tag, $wasOptimized);
                            
                            echo '<td class="cell-base" style="background: #f0f8e6; font-size: 9px; font-weight: bold;">' . 
                                 htmlspecialchars($optimizedQuotaName) . '</td>';
                            
                            // Alte und neue Gesamtauslastung
                            echo '<td class="cell-base" style="background: #fff2cc;">' . 
                                 ($altGesamtAuslastung > 0 ? $altGesamtAuslastung : '') . '</td>';
                            echo '<td class="cell-base" style="background: #d5e8d4; font-weight: bold;">' . 
                                 ($neueGesamtAuslastung > 0 ? $neueGesamtAuslastung : '') . '</td>';
                            
                            echo '</tr>';
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Backup-Interface -->
    <div id="backupPanel" style="
        display: none; 
        position: fixed; 
        top: 0; left: 0; 
        width: 100%; height: 100%; 
        background: rgba(0,0,0,0.5); 
        z-index: 10000;
        padding: 20px;
        box-sizing: border-box;
        overflow-y: auto;"
    >
        <div style="
            background: white; 
            max-width: 900px; 
            margin: 20px auto; 
            border-radius: 8px; 
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
            max-height: 90vh;
            display: flex;
            flex-direction: column;"
        >
            <div class="details-header" style="
                background: #e74c3c; 
                color: white; 
                padding: 15px 20px; 
                border-radius: 8px 8px 0 0; 
                font-weight: bold; 
                font-size: 16px;
                display: flex;
                justify-content: space-between;
                align-items: center;"
            >
                🛡️ AV-Res Backup-Verwaltung
                <button class="close-btn" onclick="hideBackupPanel()" style="
                    background: rgba(255,255,255,0.2); 
                    border: none; 
                    color: white; 
                    padding: 5px 10px; 
                    border-radius: 4px; 
                    cursor: pointer;
                    font-size: 14px;"
                >✕ Schließen</button>
            </div>
            
            <div style="padding: 20px; flex: 1; overflow-y: auto;">
                <!-- Backup erstellen -->
                <div style="background: #f8f9fa; padding: 15px; border-radius: 6px; margin-bottom: 20px;">
                    <h3 style="margin-top: 0; color: #2c3e50;">🔒 Neues Backup erstellen</h3>
                    <p style="color: #666; margin-bottom: 15px;">
                        Erstellt eine vollständige Sicherheitskopie der AV-Res Tabelle mit Zeitstempel.
                    </p>
                    <button onclick="createBackup()" style="
                        background: #27ae60; 
                        color: white; 
                        border: none; 
                        padding: 10px 20px; 
                        border-radius: 4px; 
                        cursor: pointer; 
                        font-size: 14px;
                        margin-right: 10px;"
                    >📦 Backup jetzt erstellen</button>
                    <span id="backupStatus" style="color: #666; font-style: italic;"></span>
                </div>
                
                <!-- Backup-Liste -->
                <div>
                    <h3 style="color: #2c3e50;">📋 Vorhandene Backups</h3>
                    <div id="backupList" style="margin-top: 15px;">
                        <div style="text-align: center; color: #666; padding: 20px;">
                            Lade Backup-Liste...
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Import-Analyse Panel -->
    <div id="analysisPanel" style="
        display: none; 
        position: fixed; 
        top: 0; left: 0; 
        width: 100%; height: 100%; 
        background: rgba(0,0,0,0.5); 
        z-index: 10000;
        padding: 20px;
        box-sizing: border-box;
        overflow-y: auto;"
    >
        <div style="
            background: white; 
            max-width: 1200px; 
            margin: 20px auto; 
            border-radius: 8px; 
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
            max-height: 90vh;
            display: flex;
            flex-direction: column;"
        >
            <div class="details-header" style="
                background: #9b59b6; 
                color: white; 
                padding: 15px 20px; 
                border-radius: 8px 8px 0 0; 
                font-weight: bold; 
                font-size: 16px;
                display: flex;
                justify-content: space-between;
                align-items: center;"
            >
                📊 WebImp Import-Analyse & Validierung
                <button class="close-btn" onclick="hideAnalysisPanel()" style="
                    background: rgba(255,255,255,0.2); 
                    border: none; 
                    color: white; 
                    padding: 5px 10px; 
                    border-radius: 4px; 
                    cursor: pointer;
                    font-size: 14px;"
                >✕ Schließen</button>
            </div>
            
            <div style="padding: 20px; flex: 1; overflow-y: auto;">
                <!-- Backup-Auswahl für Vergleich -->
                <div style="background: #f8f9fa; padding: 15px; border-radius: 6px; margin-bottom: 20px;">
                    <h3 style="margin-top: 0; color: #2c3e50;">🔍 Backup-Vergleichsanalyse</h3>
                    <p style="color: #666; margin-bottom: 15px;">
                        Analysiert die Unterschiede zwischen Grundzustand, altem Import und neuem Import für den gewählten Datumsbereich.
                    </p>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                        <div>
                            <label style="display: block; font-weight: bold; margin-bottom: 5px;">📍 Grundzustand (Baseline):</label>
                            <select id="baselineBackup" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                                <option value="">Backup auswählen...</option>
                            </select>
                        </div>
                        <div>
                            <label style="display: block; font-weight: bold; margin-bottom: 5px;">🔄 Nach altem Import:</label>
                            <select id="afterOldBackup" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                                <option value="">Backup auswählen...</option>
                            </select>
                        </div>
                        <div>
                            <label style="display: block; font-weight: bold; margin-bottom: 5px;">✨ Nach neuem Import:</label>
                            <select id="afterNewBackup" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                                <option value="">Backup auswählen...</option>
                            </select>
                        </div>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                        <div>
                            <label style="display: block; font-weight: bold; margin-bottom: 5px;">📅 Von Datum:</label>
                            <input type="date" id="analysisStartDate" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;" value="<?= $startDate ?>">
                        </div>
                        <div>
                            <label style="display: block; font-weight: bold; margin-bottom: 5px;">📅 Bis Datum:</label>
                            <input type="date" id="analysisEndDate" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;" value="<?= $endDate ?>">
                        </div>
                    </div>
                    
                    <button onclick="runBackupComparison()" style="
                        background: #3498db; 
                        color: white; 
                        border: none; 
                        padding: 10px 20px; 
                        border-radius: 4px; 
                        cursor: pointer; 
                        font-size: 14px;
                        margin-right: 10px;"
                    >🔍 Vergleich durchführen</button>
                    
                    <button onclick="debugProblematicRecords()" style="
                        background: #e74c3c; 
                        color: white; 
                        border: none; 
                        padding: 10px 20px; 
                        border-radius: 4px; 
                        cursor: pointer; 
                        font-size: 14px;
                        margin-right: 10px;"
                    >🐛 Problematische Datensätze debuggen</button>
                    
                    <button onclick="analyzeSpecificRecords()" style="
                        background: #f39c12; 
                        color: white; 
                        border: none; 
                        padding: 10px 20px; 
                        border-radius: 4px; 
                        cursor: pointer; 
                        font-size: 14px;
                        margin-right: 10px;"
                    >🎯 Monique Georgi analysieren</button>
                    
                    <button onclick="loadAnalysisBackups()" style="
                        background: #95a5a6; 
                        color: white; 
                        border: none; 
                        padding: 10px 20px; 
                        border-radius: 4px; 
                        cursor: pointer; 
                        font-size: 14px;"
                    >🔄 Backup-Liste aktualisieren</button>
                </div>
                
                <!-- Analyse-Ergebnisse -->
                <div id="analysisResults" style="margin-top: 20px;">
                    <div style="text-align: center; color: #666; padding: 40px;">
                        👆 Wählen Sie drei Backups aus und führen Sie einen Vergleich durch
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function showBackupPanel() {
            document.getElementById('backupPanel').style.display = 'block';
            loadBackupList();
        }
        
        function hideBackupPanel() {
            document.getElementById('backupPanel').style.display = 'none';
        }
        
        function createBackup() {
            const status = document.getElementById('backupStatus');
            status.innerHTML = '🔄 Erstelle Backup...';
            status.style.color = '#f39c12';
            
            fetch('../hrs/backup_analysis.php?action=create_backup', {
                method: 'GET'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    status.innerHTML = `✅ ${data.message}`;
                    status.style.color = '#27ae60';
                    // Liste neu laden
                    setTimeout(() => {
                        loadBackupList();
                        status.innerHTML = '';
                    }, 2000);
                } else {
                    status.innerHTML = `❌ Fehler: ${data.error}`;
                    status.style.color = '#e74c3c';
                }
            })
            .catch(error => {
                status.innerHTML = `❌ Netzwerkfehler: ${error.message}`;
                status.style.color = '#e74c3c';
            });
        }
        
        function loadBackupList() {
            const list = document.getElementById('backupList');
            list.innerHTML = '<div style="text-align: center; color: #666; padding: 20px;">🔄 Lade Backup-Liste...</div>';
            
            fetch('../hrs/backup_analysis.php?action=list_backups')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (data.backups.length === 0) {
                        list.innerHTML = '<div style="text-align: center; color: #666; padding: 20px;">📁 Keine Backups vorhanden</div>';
                        return;
                    }
                    
                    let html = '<table style="width: 100%; border-collapse: collapse;">';
                    html += '<thead><tr style="background: #f1f2f6;">';
                    html += '<th style="padding: 10px; text-align: left; border-bottom: 1px solid #ddd;">Datum/Zeit</th>';
                    html += '<th style="padding: 10px; text-align: left; border-bottom: 1px solid #ddd;">Typ</th>';
                    html += '<th style="padding: 10px; text-align: center; border-bottom: 1px solid #ddd;">Datensätze</th>';
                    html += '<th style="padding: 10px; text-align: center; border-bottom: 1px solid #ddd;">Aktionen</th>';
                    html += '</tr></thead><tbody>';
                    
                    data.backups.forEach(backup => {
                        const isPreRestore = backup.table_name.includes('PreRestore');
                        const isPreImport = backup.table_name.includes('PreImport');
                        const type = isPreRestore ? '🔄 Vor Restore' : (isPreImport ? '📥 Vor Import' : '💾 Manuell');
                        
                        html += '<tr style="border-bottom: 1px solid #eee;">';
                        html += `<td style="padding: 10px;">${backup.readable_date}</td>`;
                        html += `<td style="padding: 10px;">${type}</td>`;
                        html += `<td style="padding: 10px; text-align: center;">${backup.record_count}</td>`;
                        html += '<td style="padding: 10px; text-align: center;">';
                        html += `<button onclick="restoreBackup('${backup.table_name}')" style="
                            background: #3498db; color: white; border: none; padding: 5px 10px; 
                            border-radius: 3px; cursor: pointer; margin-right: 5px; font-size: 12px;"
                            title="Backup wiederherstellen">🔄 Restore</button>`;
                        html += `<button onclick="deleteBackup('${backup.table_name}')" style="
                            background: #e74c3c; color: white; border: none; padding: 5px 10px; 
                            border-radius: 3px; cursor: pointer; font-size: 12px;"
                            title="Backup löschen">🗑️ Löschen</button>`;
                        html += '</td></tr>';
                    });
                    
                    html += '</tbody></table>';
                    list.innerHTML = html;
                } else {
                    list.innerHTML = `<div style="color: #e74c3c; text-align: center; padding: 20px;">❌ Fehler: ${data.error}</div>`;
                }
            })
            .catch(error => {
                list.innerHTML = `<div style="color: #e74c3c; text-align: center; padding: 20px;">❌ Netzwerkfehler: ${error.message}</div>`;
            });
        }
        
        function restoreBackup(backupName) {
            if (!confirm(`⚠️ WARNUNG: Möchten Sie wirklich das Backup '${backupName}' wiederherstellen?\n\nDies überschreibt ALLE aktuellen Daten in der AV-Res Tabelle!\n\n(Ein Backup der aktuellen Daten wird automatisch erstellt)`)) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'restore_backup');
            formData.append('backup_name', backupName);
            
            const list = document.getElementById('backupList');
            list.innerHTML = '<div style="text-align: center; color: #f39c12; padding: 20px;">🔄 Stelle Backup wieder her...</div>';
            
            fetch('../hrs/backup_analysis.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(`✅ ${data.message}\n\nPre-Restore Backup: ${data.pre_restore_backup}`);
                    loadBackupList();
                } else {
                    alert(`❌ Restore fehlgeschlagen: ${data.error}`);
                    loadBackupList();
                }
            })
            .catch(error => {
                alert(`❌ Netzwerkfehler: ${error.message}`);
                loadBackupList();
            });
        }
        
        function deleteBackup(backupName) {
            if (!confirm(`Backup '${backupName}' wirklich löschen?\n\nDieser Vorgang kann nicht rückgängig gemacht werden.`)) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'delete_backup');
            formData.append('backup_name', backupName);
            
            fetch('../hrs/backup_analysis.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    loadBackupList();
                } else {
                    alert(`❌ Löschen fehlgeschlagen: ${data.error}`);
                }
            })
            .catch(error => {
                alert(`❌ Netzwerkfehler: ${error.message}`);
            });
        }
        
        // Panel schließen beim Klick außerhalb
        document.getElementById('backupPanel').addEventListener('click', function(e) {
            if (e.target === this) {
                hideBackupPanel();
            }
        });
        
        // === ANALYSE-PANEL FUNKTIONEN ===
        
        function showAnalysisPanel() {
            document.getElementById('analysisPanel').style.display = 'block';
            loadAnalysisBackups();
        }
        
        function hideAnalysisPanel() {
            document.getElementById('analysisPanel').style.display = 'none';
        }
        
        function loadAnalysisBackups() {
            const selects = ['baselineBackup', 'afterOldBackup', 'afterNewBackup'];
            
            fetch('../hrs/backup_analysis.php?action=list_backups_for_analysis')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        selects.forEach(selectId => {
                            const select = document.getElementById(selectId);
                            select.innerHTML = '<option value="">Backup auswählen...</option>';
                            
                            data.backups.forEach(backup => {
                                const option = document.createElement('option');
                                option.value = backup.name;  // Korrigiert: name statt table_name
                                option.textContent = `${backup.timestamp} (${backup.count} Datensätze)`;  // Korrigiert: timestamp statt readable_date
                                select.appendChild(option);
                            });
                        });
                    } else {
                        alert('Fehler beim Laden der Backups: ' + data.error);
                    }
                })
                .catch(error => {
                    alert('Netzwerkfehler: ' + error.message);
                });
        }
        
        function runBackupComparison() {
            const baseline = document.getElementById('baselineBackup').value;
            const afterOld = document.getElementById('afterOldBackup').value;
            const afterNew = document.getElementById('afterNewBackup').value;
            const startDate = document.getElementById('analysisStartDate').value;
            const endDate = document.getElementById('analysisEndDate').value;
            
            if (!baseline || !afterOld || !afterNew) {
                alert('Bitte alle drei Backups auswählen!');
                return;
            }
            
            if (!startDate || !endDate) {
                alert('Bitte Datumsbereich angeben!');
                return;
            }
            
            const resultsDiv = document.getElementById('analysisResults');
            resultsDiv.innerHTML = '<div style="text-align: center; color: #f39c12; padding: 40px;">🔄 Analysiere Backup-Unterschiede...</div>';
            
            const formData = new FormData();
            formData.append('action', 'compare_backups');
            formData.append('baseline', baseline);
            formData.append('after_old', afterOld);
            formData.append('after_new', afterNew);
            formData.append('start_date', startDate);
            formData.append('end_date', endDate);
            
            fetch('../hrs/backup_analysis.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayAnalysisResults(data.analysis);
                } else {
                    resultsDiv.innerHTML = `<div style="color: #e74c3c; text-align: center; padding: 20px;">❌ Fehler: ${data.error}</div>`;
                }
            })
            .catch(error => {
                resultsDiv.innerHTML = `<div style="color: #e74c3c; text-align: center; padding: 20px;">❌ Netzwerkfehler: ${error.message}</div>`;
            });
        }
        
        function debugProblematicRecords() {
            const resultsDiv = document.getElementById('analysisResults');
            resultsDiv.innerHTML = '<div style="text-align: center; color: #e74c3c; padding: 40px;">🐛 Suche problematische Datensätze...</div>';
            
            const formData = new FormData();
            formData.append('action', 'debug_problematic_records');
            
            fetch('../hrs/backup_analysis.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                displayDebugResults(data);
            })
            .catch(error => {
                resultsDiv.innerHTML = `<div style="color: #e74c3c; text-align: center; padding: 20px;">❌ Netzwerkfehler: ${error.message}</div>`;
            });
        }
        
        function analyzeSpecificRecords() {
            const resultsDiv = document.getElementById('analysisResults');
            resultsDiv.innerHTML = '<div style="text-align: center; color: #f39c12; padding: 40px;">🎯 Analysiere spezifische problematische Records...</div>';
            
            const formData = new FormData();
            formData.append('action', 'analyze_specific_records');
            formData.append('av_ids', '5235447'); // Nur Monique Georgi (AV-ID 0 sind lokale Records und werden ignoriert)
            
            fetch('../hrs/backup_analysis.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displaySpecificRecordsAnalysis(data.analysis);
                } else {
                    resultsDiv.innerHTML = `<div style="color: #e74c3c; text-align: center; padding: 20px;">❌ Fehler: ${data.error}</div>`;
                }
            })
            .catch(error => {
                resultsDiv.innerHTML = `<div style="color: #e74c3c; text-align: center; padding: 20px;">❌ Netzwerkfehler: ${error.message}</div>`;
            });
        }
        
        function displaySpecificRecordsAnalysis(analysis) {
            const resultsDiv = document.getElementById('analysisResults');
            
            let html = `
                <div style="background: #fff; border: 1px solid #ddd; border-radius: 6px; overflow: hidden;">
                    <div style="background: #f39c12; color: white; padding: 15px; font-weight: bold;">
                        🎯 Spezifische Records-Analyse: ${analysis.requested_av_ids.join(', ')} (${analysis.timestamp})
                    </div>
                    
                    <!-- Gefundene Records -->
                    <div style="padding: 15px; border-bottom: 1px solid #eee;">
                        <h4 style="margin-top: 0;">📋 Gefundene Records (${analysis.records_found.length})</h4>
                        ${analysis.records_found.length > 0 ? `
                            <div style="max-height: 400px; overflow-y: auto; border: 1px solid #ddd; border-radius: 4px;">
                                <table style="width: 100%; border-collapse: collapse;">
                                    <thead style="background: #f8f9fa; position: sticky; top: 0;">
                                        <tr>
                                            <th style="padding: 8px; border: 1px solid #ddd; text-align: left;">Tabelle</th>
                                            <th style="padding: 8px; border: 1px solid #ddd; text-align: left;">AV-ID</th>
                                            <th style="padding: 8px; border: 1px solid #ddd; text-align: left;">Name</th>
                                            <th style="padding: 8px; border: 1px solid #ddd; text-align: left;">Anreise/Abreise</th>
                                            <th style="padding: 8px; border: 1px solid #ddd; text-align: left;">Belegung</th>
                                            <th style="padding: 8px; border: 1px solid #ddd; text-align: left;">Status</th>
                                            <th style="padding: 8px; border: 1px solid #ddd; text-align: left;">Bemerkung</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${analysis.records_found.map(record => `
                                            <tr style="border-bottom: 1px solid #eee; ${record.av_id == 0 ? 'background: #fadbd8;' : ''}">
                                                <td style="padding: 8px; border: 1px solid #ddd; font-weight: bold; color: ${record.table === 'AV-Res' ? '#27ae60' : '#e74c3c'};">${record.table}</td>
                                                <td style="padding: 8px; border: 1px solid #ddd;">${record.av_id}</td>
                                                <td style="padding: 8px; border: 1px solid #ddd; font-weight: bold;">${record.name}</td>
                                                <td style="padding: 8px; border: 1px solid #ddd; font-size: 12px;">
                                                    📅 ${record.anreise ? record.anreise.substring(0,10) : 'N/A'}<br>
                                                    📅 ${record.abreise ? record.abreise.substring(0,10) : 'N/A'}
                                                </td>
                                                <td style="padding: 8px; border: 1px solid #ddd; font-size: 12px;">
                                                    ${record.lager || 0}L + ${record.betten || 0}B + ${record.dz || 0}DZ + ${record.sonder || 0}S
                                                </td>
                                                <td style="padding: 8px; border: 1px solid #ddd;">
                                                    ${record.storno !== undefined ? (record.storno == 1 ? '<span style="color: #e74c3c;">STORNO</span>' : '<span style="color: #27ae60;">AKTIV</span>') : 'N/A'}<br>
                                                    <small>${record.vorgang || 'N/A'}</small>
                                                </td>
                                                <td style="padding: 8px; border: 1px solid #ddd; max-width: 200px; font-size: 11px;">
                                                    ${record.bem || 'Leer'}
                                                </td>
                                            </tr>
                                        `).join('')}
                                    </tbody>
                                </table>
                            </div>
                        ` : '<div style="color: #e74c3c; text-align: center; padding: 20px;">❌ Keine Records mit diesen AV-IDs gefunden</div>'}
                    </div>
                    
                    <!-- Backup-Vergleich -->
                    ${analysis.backup_comparison.length > 0 ? `
                        <div style="padding: 15px;">
                            <h4 style="margin-top: 0;">🔄 Backup-Vergleich (${analysis.backup_comparison.length} Backups)</h4>
                            ${analysis.backup_comparison.map(backup => `
                                <div style="margin-bottom: 20px; border: 1px solid #ddd; border-radius: 4px;">
                                    <h5 style="background: #f8f9fa; margin: 0; padding: 10px; border-radius: 4px 4px 0 0;">${backup.backup_table}</h5>
                                    <div style="padding: 10px;">
                                        ${backup.records.map(record => `
                                            <div style="padding: 5px; border-bottom: 1px solid #eee; font-size: 12px;">
                                                <strong>AV-ID ${record.av_id}:</strong> ${record.name} | 
                                                ${record.anreise ? record.anreise.substring(0,10) : 'N/A'} - ${record.abreise ? record.abreise.substring(0,10) : 'N/A'} | 
                                                ${record.lager || 0}L+${record.betten || 0}B+${record.dz || 0}DZ+${record.sonder || 0}S | 
                                                ${record.storno == 1 ? 'STORNO' : 'AKTIV'}
                                            </div>
                                        `).join('')}
                                    </div>
                                </div>
                            `).join('')}
                        </div>
                    ` : ''}
                </div>
            `;
            
            resultsDiv.innerHTML = html;
        }
        
        function displayDebugResults(data) {
            const resultsDiv = document.getElementById('analysisResults');
            
            // Sicherheitsprüfungen für die Datenstruktur
            const debug = data || {};
            const statistics = debug.statistics || {};
            const problematicRecords = debug.problematic_records || [];
            const encodingIssues = debug.encoding_issues || [];
            const fieldLimits = debug.field_limits || {};
            
            let html = `
                <div style="background: #fff; border: 1px solid #ddd; border-radius: 6px; overflow: hidden;">
                    <div style="background: #e74c3c; color: white; padding: 15px; font-weight: bold;">
                        🐛 Debug-Analyse problematischer Datensätze (${debug.timestamp || 'Unbekannt'})
                    </div>
                    
                    <!-- Statistiken -->
                    <div style="padding: 15px; border-bottom: 1px solid #eee;">
                        <h4 style="margin-top: 0;">📊 WebImp-Statistiken</h4>
                        <div style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px;">
                            <div style="text-align: center; padding: 10px; background: #ecf0f1; border-radius: 4px;">
                                <div style="font-size: 18px; font-weight: bold; color: #2c3e50;">${statistics.total_webimp_records || '0'}</div>
                                <div style="color: #7f8c8d; font-size: 12px;">Gesamt WebImp</div>
                            </div>
                            <div style="text-align: center; padding: 10px; background: #fadbd8; border-radius: 4px;">
                                <div style="font-size: 18px; font-weight: bold; color: #e74c3c;">${statistics.invalid_av_ids || '0'}</div>
                                <div style="color: #c0392b; font-size: 12px;">Ungültige AV-IDs</div>
                            </div>
                            <div style="text-align: center; padding: 10px; background: #fdeaa7; border-radius: 4px;">
                                <div style="font-size: 18px; font-weight: bold; color: #f39c12;">${statistics.long_bemerkungen || '0'}</div>
                                <div style="color: #d68910; font-size: 12px;">Lange Bemerkungen</div>
                            </div>
                            <div style="text-align: center; padding: 10px; background: #d5f4e6; border-radius: 4px;">
                                <div style="font-size: 18px; font-weight: bold; color: #27ae60;">${statistics.confirmed_records || '0'}</div>
                                <div style="color: #229954; font-size: 12px;">CONFIRMED</div>
                            </div>
                            <div style="text-align: center; padding: 10px; background: #f2d7d5; border-radius: 4px;">
                                <div style="font-size: 18px; font-weight: bold; color: #8b4513;">${statistics.discarded_records || '0'}</div>
                                <div style="color: #a0522d; font-size: 12px;">DISCARDED</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Problematische Datensätze -->
                    <div style="padding: 15px; border-bottom: 1px solid #eee;">
                        <h4 style="margin-top: 0;">⚠️ Problematische Datensätze (${problematicRecords.length})</h4>
                        ${problematicRecords.length > 0 ? `
                            <div style="max-height: 400px; overflow-y: auto; border: 1px solid #ddd; border-radius: 4px;">
                                <table style="width: 100%; border-collapse: collapse;">
                                    <thead style="background: #f8f9fa; position: sticky; top: 0;">
                                        <tr>
                                            <th style="padding: 8px; border: 1px solid #ddd; text-align: left;">AV-ID</th>
                                            <th style="padding: 8px; border: 1px solid #ddd; text-align: left;">Name</th>
                                            <th style="padding: 8px; border: 1px solid #ddd; text-align: left;">Probleme</th>
                                            <th style="padding: 8px; border: 1px solid #ddd; text-align: left;">Vorgang</th>
                                            <th style="padding: 8px; border: 1px solid #ddd; text-align: left;">Bemerkung</th>
                                            <th style="padding: 8px; border: 1px solid #ddd; text-align: left;">In AV-Res</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${problematicRecords.map(record => `
                                            <tr style="border-bottom: 1px solid #eee;">
                                                <td style="padding: 8px; border: 1px solid #ddd; ${(record.av_id || 0) <= 0 ? 'background: #fadbd8;' : ''}">${record.av_id || 'N/A'}</td>
                                                <td style="padding: 8px; border: 1px solid #ddd; font-weight: bold;">${record.name || 'Unbekannt'}</td>
                                                <td style="padding: 8px; border: 1px solid #ddd;">
                                                    ${(record.issues || []).map(issue => `<span style="background: #e74c3c; color: white; padding: 2px 6px; border-radius: 3px; font-size: 11px; margin-right: 4px; display: inline-block; margin-bottom: 2px;">${issue}</span>`).join('')}
                                                </td>
                                                <td style="padding: 8px; border: 1px solid #ddd;">${record.vorgang || 'N/A'}</td>
                                                <td style="padding: 8px; border: 1px solid #ddd; max-width: 300px;">
                                                    <div style="font-size: 11px; color: #666;">Länge: ${record.bem_length || '0'}</div>
                                                    <div style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="${record.bem_preview || ''}">${record.bem_preview || 'Leer'}</div>
                                                    ${record.bem_hex_sample ? `<div style="font-family: monospace; font-size: 10px; color: #999; margin-top: 2px;">HEX: ${record.bem_hex_sample}</div>` : ''}
                                                </td>
                                                <td style="padding: 8px; border: 1px solid #ddd; text-align: center;">
                                                    ${record.exists_in_av_res ? '<span style="color: #27ae60;">✓</span>' : '<span style="color: #e74c3c;">✗</span>'}
                                                </td>
                                            </tr>
                                        `).join('')}
                                    </tbody>
                                </table>
                            </div>
                        ` : '<div style="color: #27ae60; text-align: center; padding: 20px;">✓ Keine problematischen Datensätze gefunden</div>'}
                    </div>
                    
                    <!-- Encoding-Probleme -->
                    ${encodingIssues.length > 0 ? `
                        <div style="padding: 15px; border-bottom: 1px solid #eee;">
                            <h4 style="margin-top: 0;">🔤 Encoding-Probleme (${encodingIssues.length})</h4>
                            <div style="max-height: 200px; overflow-y: auto;">
                                ${encodingIssues.map(issue => `
                                    <div style="padding: 5px; border-bottom: 1px solid #eee;">
                                        <strong>${issue.name || 'Unbekannt'}</strong> (AV-ID: ${issue.av_id || 'N/A'})<br>
                                        <small>Zeichen: ${issue.char_length || '0'}, Bytes: ${issue.byte_length || '0'}, Differenz: ${issue.difference || '0'}</small>
                                    </div>
                                `).join('')}
                            </div>
                        </div>
                    ` : ''}
                    
                    <!-- Feldlimits -->
                    <div style="padding: 15px;">
                        <h4 style="margin-top: 0;">📏 Tabellen-Struktur & Limits</h4>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                            <div>
                                <h5>AV-Res Struktur:</h5>
                                <div style="font-family: monospace; font-size: 11px; background: #f8f9fa; padding: 10px; border-radius: 4px; max-height: 200px; overflow-y: auto;">
                                    ${Object.entries(fieldLimits['AV-Res'] || {}).map(([field, info]) => 
                                        `<div>${field}: ${info.type || 'unknown'} ${info.null === 'YES' ? '(NULL)' : '(NOT NULL)'}</div>`
                                    ).join('')}
                                </div>
                            </div>
                            <div>
                                <h5>AV-Res-webImp Struktur:</h5>
                                <div style="font-family: monospace; font-size: 11px; background: #f8f9fa; padding: 10px; border-radius: 4px; max-height: 200px; overflow-y: auto;">
                                    ${Object.entries(fieldLimits['AV-Res-webImp'] || {}).map(([field, info]) => 
                                        `<div>${field}: ${info.type || 'unknown'} ${info.null === 'YES' ? '(NULL)' : '(NOT NULL)'}</div>`
                                    ).join('')}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            resultsDiv.innerHTML = html;
        }
        
        function displayAnalysisResults(data) {
            const resultsDiv = document.getElementById('analysisResults');
            
            // Vereinfachte, robuste Anzeige der Analyseergebnisse
            try {
                const analysis = data || {};
                const dateRange = analysis.date_range || {};
                const recordCounts = analysis.record_counts || {};
                const fieldChanges = analysis.field_changes || {};
                const oldChanges = analysis.changes_old_vs_baseline || { added: [], removed: [], modified: [], unchanged: 0 };
                const newChanges = analysis.changes_new_vs_baseline || { added: [], removed: [], modified: [], unchanged: 0 };
                const newVsOld = analysis.changes_new_vs_old || { added: [], removed: [], modified: [], unchanged: 0 };
                const dailyOccupancy = analysis.daily_occupancy || [];
                
                let html = `
                    <div style="background: #fff; border: 1px solid #ddd; border-radius: 6px; overflow: hidden;">
                        <div style="background: #3498db; color: white; padding: 15px; font-weight: bold;">
                            📊 Backup-Analyse: ${dateRange.start || 'Unbekannt'} bis ${dateRange.end || 'Unbekannt'}
                        </div>
                        
                        <!-- Datensatz-Übersicht -->
                        <div style="padding: 15px; border-bottom: 1px solid #eee;">
                            <h4 style="margin-top: 0;">📈 Datensatz-Anzahl</h4>
                            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px;">
                                <div style="text-align: center; padding: 10px; background: #ecf0f1; border-radius: 4px;">
                                    <div style="font-size: 24px; font-weight: bold; color: #2c3e50;">${recordCounts.baseline || '0'}</div>
                                    <div style="color: #7f8c8d;">Grundzustand</div>
                                </div>
                                <div style="text-align: center; padding: 10px; background: #fdeaa7; border-radius: 4px;">
                                    <div style="font-size: 24px; font-weight: bold; color: #f39c12;">${recordCounts.after_old || '0'}</div>
                                    <div style="color: #d68910;">Alter Import</div>
                                </div>
                                <div style="text-align: center; padding: 10px; background: #d5f4e6; border-radius: 4px;">
                                    <div style="font-size: 24px; font-weight: bold; color: #27ae60;">${recordCounts.after_new || '0'}</div>
                                    <div style="color: #229954;">Neuer Import</div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Änderungen-Übersicht -->
                        <div style="padding: 15px; border-bottom: 1px solid #eee;">
                            <h4 style="margin-top: 0;">🔄 Änderungen-Übersicht</h4>
                            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px;">
                                <div>
                                    <h5 style="color: #f39c12;">🔄 Alter Import vs Grundzustand</h5>
                                    <div>✅ Hinzugefügt: ${oldChanges.added ? oldChanges.added.length : 0}</div>
                                    <div>📝 Geändert: ${oldChanges.modified ? oldChanges.modified.length : 0}</div>
                                    <div>⚪ Unverändert: ${oldChanges.unchanged || 0}</div>
                                </div>
                                <div>
                                    <h5 style="color: #27ae60;">✨ Neuer Import vs Grundzustand</h5>
                                    <div>✅ Hinzugefügt: ${newChanges.added ? newChanges.added.length : 0}</div>
                                    <div>📝 Geändert: ${newChanges.modified ? newChanges.modified.length : 0}</div>
                                    <div>⚪ Unverändert: ${newChanges.unchanged || 0}</div>
                                </div>
                                <div style="border: 2px solid #9b59b6; border-radius: 6px; padding: 10px;">
                                    <h5 style="color: #9b59b6;">🎯 Neuer vs Alter Import</h5>
                                    <div>✅ Hinzugefügt: ${newVsOld.added ? newVsOld.added.length : 0}</div>
                                    <div>📝 Geändert: ${newVsOld.modified ? newVsOld.modified.length : 0}</div>
                                    <div>⚪ Unverändert: ${newVsOld.unchanged || 0}</div>
                                </div>
                            </div>
                        </div>
                `;
                
                // Feld-Änderungen falls verfügbar
                if (fieldChanges && Object.keys(fieldChanges).length > 0) {
                    html += `
                        <div style="padding: 15px; border-bottom: 1px solid #eee;">
                            <h4>📋 Feld-Änderungen</h4>
                            <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
                                <thead>
                                    <tr style="background: #f1f2f6;">
                                        <th style="padding: 8px; border: 1px solid #ddd;">Feld</th>
                                        <th style="padding: 8px; border: 1px solid #ddd;">Alter vs Basis</th>
                                        <th style="padding: 8px; border: 1px solid #ddd;">Neuer vs Basis</th>
                                        <th style="padding: 8px; border: 1px solid #ddd;">Neu vs Alt</th>
                                    </tr>
                                </thead>
                                <tbody>
                    `;
                    
                    const oldVsBaseline = fieldChanges.old_vs_baseline || {};
                    const newVsBaseline = fieldChanges.new_vs_baseline || {};
                    const newVsOldFields = fieldChanges.new_vs_old || {};
                    
                    const allFields = new Set([
                        ...Object.keys(oldVsBaseline),
                        ...Object.keys(newVsBaseline), 
                        ...Object.keys(newVsOldFields)
                    ]);
                    
                    allFields.forEach(field => {
                        const oldVal = oldVsBaseline[field] || '0';
                        const newVal = newVsBaseline[field] || '0';
                        const diffVal = newVsOldFields[field] || '0';
                        const diffColor = diffVal !== '0' ? '#e74c3c' : '#27ae60';
                        
                        html += `
                            <tr>
                                <td style="padding: 8px; border: 1px solid #ddd; font-weight: bold;">${field}</td>
                                <td style="padding: 8px; border: 1px solid #ddd; text-align: center;">${oldVal}</td>
                                <td style="padding: 8px; border: 1px solid #ddd; text-align: center;">${newVal}</td>
                                <td style="padding: 8px; border: 1px solid #ddd; text-align: center; color: ${diffColor}; font-weight: bold;">${diffVal}</td>
                            </tr>
                        `;
                    });
                    
                    html += `</tbody></table></div>`;
                }
                
                // Detaillierte Änderungen bei Unterschieden
                if (newVsOld.modified && newVsOld.modified.length > 0) {
                    html += `
                        <div style="padding: 15px; border-bottom: 1px solid #eee;">
                            <h4 style="color: #e74c3c;">⚠️ Inkonsistente Datensätze (${newVsOld.modified.length})</h4>
                            <div style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; border-radius: 4px;">
                    `;
                    
                    newVsOld.modified.slice(0, 10).forEach((item, index) => {
                        const bgColor = index % 2 === 0 ? '#f8f9fa' : 'white';
                        html += `
                            <div style="padding: 10px; border-bottom: 1px solid #eee; background: ${bgColor};">
                                <div style="font-weight: bold; color: #2c3e50; margin-bottom: 5px;">
                                    👤 ${item.name || 'Unbekannt'} (AV-ID: ${item.av_id || 'Unbekannt'})
                                </div>
                        `;
                        
                        if (item.changes && Array.isArray(item.changes)) {
                            item.changes.forEach(change => {
                                html += `
                                    <div style="margin-left: 20px; font-size: 12px; margin-bottom: 3px;">
                                        <strong>${change.field || 'Unbekannt'}</strong>: 
                                        "${change.from || ''}" → "${change.to || ''}"
                                    </div>
                                `;
                            });
                        }
                        
                        html += `</div>`;
                    });
                    
                    if (newVsOld.modified.length > 10) {
                        html += `<div style="padding: 10px; text-align: center; font-style: italic;">... und ${newVsOld.modified.length - 10} weitere</div>`;
                    }
                    
                    html += `</div></div>`;
                }
                
                html += `</div>`;
                
                resultsDiv.innerHTML = html;
                
            } catch (error) {
                console.error("Fehler beim Anzeigen der Analyseergebnisse:", error);
                resultsDiv.innerHTML = `
                    <div style="background: #fff; border: 1px solid #e74c3c; border-radius: 6px; padding: 20px;">
                        <div style="color: #e74c3c; font-weight: bold; margin-bottom: 10px;">❌ Anzeigefehler</div>
                        <div>Fehler beim Verarbeiten der Analyseergebnisse: ${error.message}</div>
                        <details style="margin-top: 10px;">
                            <summary>Debug-Informationen</summary>
                            <pre style="background: #f8f9fa; padding: 10px; border-radius: 4px; overflow: auto; font-size: 11px;">${JSON.stringify(data, null, 2)}</pre>
                        </details>
                    </div>
                `;
            }
        }
        
        // Hilfsfunktionen für die Anzeige
        function getFieldColor(field) {
            const colors = {
                'anreise': '#3498db',
                'abreise': '#e74c3c',
                'lager': '#f39c12',
                'betten': '#9b59b6',
                'dz': '#1abc9c',
                'sonder': '#e67e22',
                'storno': '#c0392b',
                'arr': '#27ae60',
                'hp': '#8e44ad',
                'vorgang': '#34495e',
                'gruppe': '#16a085',
                'bem_av': '#7f8c8d',
                'handy': '#2980b9',
                'email': '#d35400'
            };
            return colors[field] || '#95a5a6';
        }
        
        function formatDisplayValue(value) {
            if (value === null || value === undefined) return '∅';
            if (value === '') return '(leer)';
            if (typeof value === 'string' && value.length > 30) {
                return value.substring(0, 30) + '...';
            }
            return value;
        }
        
        // Panel schließen beim Klick außerhalb
        document.getElementById('analysisPanel').addEventListener('click', function(e) {
            if (e.target === this) {
                hideAnalysisPanel();
            }
        });
    </script>

    <!-- Quota-Änderungs-Vorschau Modal -->
    <div id="quotaChangesModal" style="
        display: none; 
        position: fixed; 
        top: 0; left: 0; 
        width: 100%; height: 100%; 
        background: rgba(0,0,0,0.6); 
        z-index: 15000;
        padding: 20px;
        box-sizing: border-box;
        overflow-y: auto;"
    >
        <div style="
            background: white; 
            max-width: 1200px; 
            margin: 20px auto; 
            border-radius: 12px; 
            box-shadow: 0 8px 32px rgba(0,0,0,0.3);
            max-height: 90vh;
            display: flex;
            flex-direction: column;"
        >
            <!-- Header -->
            <div style="
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
                color: white; 
                padding: 20px; 
                border-radius: 12px 12px 0 0;
                display: flex;
                justify-content: space-between;
                align-items: center;"
            >
                <div>
                    <h2 style="margin: 0; font-size: 24px;">🔄 Quota-Änderungs-Vorschau</h2>
                    <p style="margin: 5px 0 0 0; opacity: 0.9; font-size: 14px;" id="quotaChangesDateRange">
                        Zeitraum wird geladen...
                    </p>
                </div>
                <button onclick="closeQuotaChangesModal()" style="
                    background: rgba(255,255,255,0.2); 
                    border: none; 
                    color: white; 
                    padding: 8px 15px; 
                    border-radius: 6px; 
                    cursor: pointer;
                    font-size: 16px;
                    font-weight: bold;"
                >✕ Schließen</button>
            </div>
            
            <!-- Content -->
            <div style="padding: 25px; flex: 1; overflow-y: auto;">
                <!-- Zusammenfassung -->
                <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 25px; border-left: 4px solid #667eea;">
                    <h3 style="margin: 0 0 10px 0; color: #2c3e50; display: flex; align-items: center;">
                        📊 Änderungs-Zusammenfassung
                    </h3>
                    <div id="quotaChangesSummary" style="font-size: 14px; color: #555;">
                        Analyse läuft...
                    </div>
                </div>
                
                <!-- Zwei-Spalten Layout -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 25px;">
                    <!-- Linke Spalte: Zu löschende/ersetzende Quotas -->
                    <div>
                        <h3 style="color: #e74c3c; display: flex; align-items: center; margin: 0 0 15px 0;">
                            🗑️ Zu löschende Original-Quotas
                        </h3>
                        <div style="background: #fff5f5; border: 1px solid #fed7d7; border-radius: 8px; padding: 15px;">
                            <div id="quotasToDelete" style="font-size: 13px;">
                                Wird analysiert...
                            </div>
                        </div>
                    </div>
                    
                    <!-- Rechte Spalte: Neue Quotas -->
                    <div>
                        <h3 style="color: #27ae60; display: flex; align-items: center; margin: 0 0 15px 0;">
                            ➕ Neue optimierte Quotas
                        </h3>
                        <div style="background: #f0fff4; border: 1px solid #c6f6d5; border-radius: 8px; padding: 15px;">
                            <div id="newQuotas" style="font-size: 13px;">
                                Wird berechnet...
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Footer Actions -->
                <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #e2e8f0; text-align: center;">
                    <div style="background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 6px; padding: 15px; margin-bottom: 20px;">
                        <p style="margin: 0; color: #8b5a00; font-weight: 500;">
                            ⚠️ <strong>Vorschau-Modus:</strong> Keine Aktionen werden ausgeführt. 
                            Die tatsächlichen Quota-Änderungen werden später im HRS-System vorgenommen.
                        </p>
                    </div>
                    
                    <div style="display: flex; gap: 15px; justify-content: center; flex-wrap: wrap;">
                        <button onclick="closeQuotaChangesModal()" style="
                            background: #6c757d; 
                            color: white; 
                            border: none; 
                            padding: 12px 30px; 
                            border-radius: 6px; 
                            cursor: pointer;
                            font-size: 16px;"
                        >📋 Vorschau schließen</button>
                        
                        <button onclick="deleteSelectedQuotas()" style="
                            background: #dc3545; 
                            color: white; 
                            border: none; 
                            padding: 12px 30px; 
                            border-radius: 6px; 
                            cursor: pointer;
                            font-size: 16px;"
                        >�️ Original-Quotas löschen</button>
                        
                        <button onclick="exportQuotaChanges()" style="
                            background: #17a2b8; 
                            color: white; 
                            border: none; 
                            padding: 12px 30px; 
                            border-radius: 6px; 
                            cursor: pointer;
                            font-size: 16px;"
                        >📄 Als Excel exportieren</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Daten für JavaScript verfügbar machen
        const detailData = <?= json_encode($detailDaten) ?>;
        
        function updateData() {
            const startDate = document.getElementById('startDate').value;
            const endDate = document.getElementById('endDate').value;
            
            if (startDate && endDate) {
                window.location.href = `?start=${startDate}&end=${endDate}`;
            }
        }
        
        async function importWebImpData(dryRun = false) {
            const webimpBtn = document.getElementById('webimpBtn');
            const dryRunBtn = document.getElementById('dryRunBtn');
            
            if (!dryRun && !confirm('Sollen die Daten aus der WebImp-Zwischentabelle in die Production-Tabelle AV-Res importiert werden?\n\nDies überschreibt ggf. vorhandene Reservierungen mit gleicher AV-ID!')) {
                return;
            }
            
            // UI Setup
            const targetBtn = dryRun ? dryRunBtn : webimpBtn;
            targetBtn.disabled = true;
            targetBtn.textContent = dryRun ? '🔍 Analysiere...' : '⏳ Importiere...';
            
            try {
                console.log(dryRun ? 'Starte WebImp Dry-Run...' : 'Starte WebImp Import...');
                
                const protocol = window.location.protocol;
                const host = window.location.host;
                const baseUrl = `${protocol}//${host}`;
                const url = dryRun ? `${baseUrl}/wci/hrs/import_webimp.php?json=1&dry-run=1` : `${baseUrl}/wci/hrs/import_webimp.php?json=1`;
                const response = await fetch(url);
                if (!response.ok) {
                    throw new Error(`Import API Fehler: ${response.status}`);
                }
                
                const text = await response.text();
                console.log('WebImp Import Antwort:', text);
                
                let data;
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    throw new Error('Ungültige JSON-Antwort: ' + text);
                }
                
                console.log('WebImp Import Daten:', data);
                
                if (data.success) {
                    if (dryRun) {
                        // Für Dry-Run: Zeige detaillierte Informationen in Modal
                        showDryRunResults(data);
                    } else {
                        // Für echten Import: Zeige einfache Bestätigung
                        let message = `✅ WebImp Import erfolgreich!\n\n`;
                        message += `Verarbeitet: ${data.total}\n`;
                        message += `Neu eingefügt: ${data.inserted}\n`;
                        message += `Aktualisiert: ${data.updated}\n`;
                        message += `Unverändert: ${data.unchanged}\n`;
                        
                        // Backup-Info anzeigen
                        if (data.backup_info) {
                            message += `\n💾 ${data.backup_info}\n`;
                        }
                        
                        if (!dryRun && data.sourceCleared) {
                            message += `\n📝 Zwischentabelle wurde geleert`;
                        }
                        
                        if (data.errors && data.errors.length > 0) {
                            message += `\n\n⚠️ Warnungen (${data.errors.length}):\n`;
                            data.errors.slice(0, 5).forEach(error => {
                                message += `- ${error}\n`;
                            });
                            if (data.errors.length > 5) {
                                message += `... und ${data.errors.length - 5} weitere`;
                            }
                        }
                        
                        alert(message);
                        updateData();
                    }
                } else {
                    throw new Error(data.message || data.error || 'Unbekannter Fehler');
                }
                
            } catch (error) {
                console.error('WebImp Import Fehler:', error);
                alert('❌ Fehler beim WebImp Import: ' + error.message);
            } finally {
                webimpBtn.disabled = false;
                webimpBtn.textContent = '📂 WebImp → Production';
                dryRunBtn.disabled = false;
                dryRunBtn.textContent = '🔍 Dry-Run Test';
            }
        }
        
        async function importHRSData() {
            const startDate = document.getElementById('startDate').value;
            const endDate = document.getElementById('endDate').value;
            const importBtn = document.getElementById('importBtn');
            const progressContainer = document.getElementById('progressContainer');
            const progressStatus = document.getElementById('progressStatus');
            
            if (!startDate || !endDate) {
                alert('Bitte Start- und Enddatum auswählen!');
                return;
            }
            
            // UI Setup
            importBtn.disabled = true;
            importBtn.textContent = '⏳ Importiere...';
            progressContainer.style.display = 'block';
            
            // Reset progress steps
            ['step1', 'step2', 'step3'].forEach(id => {
                const step = document.getElementById(id);
                step.classList.remove('active', 'completed');
                document.getElementById(id + 'Result').textContent = '';
            });
            
            try {
                // Step 1: Reservierungen importieren
                document.getElementById('step1').classList.add('active');
                progressStatus.textContent = 'Importiere Reservierungen...';
                console.log('Starte Reservierungen Import...');
                
                const protocol = window.location.protocol;
                const host = window.location.host;
                const baseUrl = `${protocol}//${host}`;
                const resResponse = await fetch(`${baseUrl}/wci/hrs/hrs_imp_res.php?from=${startDate}&to=${endDate}&json=1`);
                if (!resResponse.ok) {
                    throw new Error(`Reservierungen API Fehler: ${resResponse.status}`);
                }
                const resText = await resResponse.text();
                console.log('Reservierungen Antwort:', resText);
                const resData = JSON.parse(resText);
                console.log('Reservierungen:', resData);
                
                document.getElementById('step1').classList.remove('active');
                document.getElementById('step1').classList.add('completed');
                document.getElementById('step1Result').textContent = resData.success ? 'Erfolg!' : 'Fehler';
                
                // Step 2: Daily Summaries importieren
                document.getElementById('step2').classList.add('active');
                progressStatus.textContent = 'Importiere Daily Summaries...';
                console.log('Starte Daily Summaries Import...');
                
                const dailyResponse = await fetch(`${baseUrl}/wci/hrs/hrs_imp_daily.php?from=${startDate}&to=${endDate}&json=1`);
                if (!dailyResponse.ok) {
                    throw new Error(`Daily Summaries API Fehler: ${dailyResponse.status}`);
                }
                const dailyText = await dailyResponse.text();
                console.log('Daily Summaries Antwort:', dailyText);
                const dailyData = JSON.parse(dailyText);
                console.log('Daily Summaries:', dailyData);
                
                document.getElementById('step2').classList.remove('active');
                document.getElementById('step2').classList.add('completed');
                document.getElementById('step2Result').textContent = dailyData.success ? 'Erfolg!' : 'Fehler';
                
                // Step 3: Quotas importieren
                document.getElementById('step3').classList.add('active');
                progressStatus.textContent = 'Importiere Quotas...';
                console.log('Starte Quotas Import...');
                
                const quotaResponse = await fetch(`${baseUrl}/wci/hrs/hrs_imp_quota.php?from=${startDate}&to=${endDate}&json=1`);
                if (!quotaResponse.ok) {
                    throw new Error(`Quotas API Fehler: ${quotaResponse.status}`);
                }
                const quotaText = await quotaResponse.text();
                console.log('Quotas Antwort:', quotaText);
                const quotaData = JSON.parse(quotaText);
                console.log('Quotas:', quotaData);
                
                document.getElementById('step3').classList.remove('active');
                document.getElementById('step3').classList.add('completed');
                document.getElementById('step3Result').textContent = quotaData.success ? 'Erfolg!' : 'Fehler';
                progressStatus.textContent = 'Import erfolgreich abgeschlossen! ✅';
                
                setTimeout(() => {
                    alert('✅ HRS Import erfolgreich abgeschlossen!\\n\\n' +
                          `Reservierungen: ${resData.message || 'OK'}\\n` +
                          `Daily Summaries: ${dailyData.message || 'OK'}\\n` +
                          `Quotas: ${quotaData.message || 'OK'}`);
                    
                    // Seite neu laden um neue Daten anzuzeigen
                    updateData();
                }, 500);
                
            } catch (error) {
                console.error('Import Fehler:', error);
                progressStatus.textContent = 'Fehler beim Import ❌';
                alert('❌ Fehler beim HRS Import: ' + error.message);
            } finally {
                importBtn.disabled = false;
                importBtn.textContent = '📥 HRS Import';
                setTimeout(() => {
                    progressContainer.style.display = 'none';
                }, 3000);
            }
        }
        
        function exportToExcel() {
            // Hilfsfunktion zum Parsen deutscher Datumsangaben
            function parseGermanDate(dateStr) {
                // Entferne Emojis und Wochentag-Abkürzungen
                const cleanStr = dateStr.replace(/^\w+\s+/, '');
                
                // Parse DD.MM.YYYY Format
                const parts = cleanStr.split('.');
                if (parts.length === 3) {
                    const day = parseInt(parts[0]);
                    const month = parseInt(parts[1]) - 1; // JavaScript Monate sind 0-basiert
                    const year = parseInt(parts[2]);
                    return new Date(year, month, day);
                }
                return null;
            }
            
            // Hilfsfunktion für Zahlenwerte
            function parseNumericValue(value) {
                if (!value || value.trim() === '' || value.toLowerCase() === 'nan') {
                    return ''; // Leere Zelle für NaN oder leere Werte
                }
                const numValue = parseFloat(value);
                return isNaN(numValue) ? value : numValue;
            }
            
            // Tabellendaten sammeln
            const tableData = [];
            
            // Header-Zeile
            const headers = [
                'Datum', 'Frei Gesamt', 'Frei Quotas', 'Frei Sonder', 'Frei Lager', 'Frei Betten', 'Frei DZ',
                'Quota Name', 'Quota Sonder', 'Quota Lager', 'Quota Betten', 'Quota DZ',
                'HRS Sonder', 'Lokal Sonder', 'HRS Lager', 'Lokal Lager', 
                'HRS Betten', 'Lokal Betten', 'HRS DZ', 'Lokal DZ', 'Gesamt Belegt'
            ];
            tableData.push(headers);
            
            // Tabellendaten durchgehen
            const table = document.querySelector('table tbody');
            const rows = table.querySelectorAll('tr');
            
            rows.forEach(row => {
                const cells = row.querySelectorAll('td');
                if (cells.length > 0) {
                    const rowData = [];
                    
                    // Datum - konvertiere zu Excel-kompatiblem Datum
                    const dateText = cells[0]?.textContent.trim() || '';
                    const excelDate = parseGermanDate(dateText);
                    rowData.push(excelDate || dateText);
                    
                    // Freie Kapazitäten (6 Spalten - jetzt mit Frei Quotas)
                    for (let i = 1; i <= 6; i++) {
                        const value = cells[i]?.textContent.trim() || '';
                        rowData.push(parseNumericValue(value));
                    }
                    
                    // Quota-Informationen (5 Spalten)
                    let quotaStartIndex = 7;
                    for (let i = quotaStartIndex; i < quotaStartIndex + 5; i++) {
                        const cellContent = cells[i]?.textContent.trim() || '';
                        if (i === quotaStartIndex) {
                            // Quota Name - Text beibehalten
                            rowData.push(cellContent || '');
                        } else {
                            // Quota Zahlen
                            rowData.push(parseNumericValue(cellContent));
                        }
                    }
                    
                    // Belegungszahlen (8 Spalten)
                    let belegungStartIndex = quotaStartIndex + 5;
                    for (let i = belegungStartIndex; i < belegungStartIndex + 8; i++) {
                        const value = cells[i]?.textContent.trim() || '';
                        rowData.push(parseNumericValue(value));
                    }
                    
                    // Gesamt belegt
                    const gesamtIndex = belegungStartIndex + 8;
                    const gesamtValue = cells[gesamtIndex]?.textContent.trim() || '';
                    rowData.push(parseNumericValue(gesamtValue));
                    
                    tableData.push(rowData);
                }
            });
            
            // Excel-Arbeitsblatt erstellen
            const ws = XLSX.utils.aoa_to_sheet(tableData);
            
            // Spaltenbreiten setzen
            const colWidths = [
                {wch: 12}, // Datum
                {wch: 10}, // Frei Gesamt
                {wch: 10}, // Frei Quotas
                {wch: 10}, // Frei Sonder
                {wch: 10}, // Frei Lager
                {wch: 10}, // Frei Betten
                {wch: 10}, // Frei DZ
                {wch: 15}, // Quota Name
                {wch: 10}, // Quota Sonder
                {wch: 10}, // Quota Lager
                {wch: 10}, // Quota Betten
                {wch: 10}, // Quota DZ
                {wch: 10}, // HRS Sonder
                {wch: 10}, // Lokal Sonder
                {wch: 10}, // HRS Lager
                {wch: 10}, // Lokal Lager
                {wch: 10}, // HRS Betten
                {wch: 10}, // Lokal Betten
                {wch: 10}, // HRS DZ
                {wch: 10}, // Lokal DZ
                {wch: 12}  // Gesamt Belegt
            ];
            ws['!cols'] = colWidths;
            
            // Header-Styling
            const headerStyle = {
                font: { bold: true },
                fill: { fgColor: { rgb: "CCCCCC" } },
                alignment: { horizontal: "center" }
            };
            
            // Header-Zellen stylen
            for (let i = 0; i < headers.length; i++) {
                const cellRef = XLSX.utils.encode_cell({ r: 0, c: i });
                if (!ws[cellRef]) ws[cellRef] = {};
                ws[cellRef].s = headerStyle;
            }
            
            // Datumsspalte formatieren
            const range = XLSX.utils.decode_range(ws['!ref']);
            for (let row = 1; row <= range.e.r; row++) {
                const cellRef = XLSX.utils.encode_cell({ r: row, c: 0 });
                if (ws[cellRef] && ws[cellRef].v instanceof Date) {
                    ws[cellRef].z = 'DD.MM.YYYY'; // Deutsches Datumsformat
                    ws[cellRef].t = 'd'; // Typ: Datum
                }
            }
            
            // Arbeitsmappe erstellen
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Belegungsanalyse");
            
            // Dateiname mit aktuellem Datum
            const today = new Date();
            const dateStr = today.getFullYear() + '-' + 
                          String(today.getMonth() + 1).padStart(2, '0') + '-' + 
                          String(today.getDate()).padStart(2, '0');
            const filename = `Belegungsanalyse_${dateStr}.xlsx`;
            
            // Excel-Datei herunterladen
            XLSX.writeFile(wb, filename);
        }
        
        function showDryRunResults(data) {
            const content = document.getElementById('detailsContent');
            
            let html = `<h3>🔍 WebImp Dry-Run Analyse</h3>`;
            
            // Zusammenfassung
            html += `<div style="background: #f8f9fa; padding: 15px; border-radius: 6px; margin-bottom: 20px;">`;
            html += `<h4 style="margin-top: 0;">📊 Zusammenfassung</h4>`;
            html += `<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px;">`;
            html += `<div><strong>Verarbeitet:</strong> ${data.total}</div>`;
            html += `<div><strong>Neu eingefügt:</strong> <span style="color: #28a745;">${data.inserted}</span></div>`;
            html += `<div><strong>Aktualisiert:</strong> <span style="color: #ffc107;">${data.updated}</span></div>`;
            html += `<div><strong>Unverändert:</strong> <span style="color: #6c757d;">${data.unchanged}</span></div>`;
            html += `</div>`;
            
            if (data.debug) {
                html += `<div style="margin-top: 10px; font-size: 12px; color: #666;">`;
                html += `<strong>Debug:</strong> ${data.debug.indexStatus || 'unbekannt'} | `;
                html += `Bereits in DB: ${data.debug.existingInTarget || 0}`;
                html += `</div>`;
            }
            html += `</div>`;
            
            // Detaillierte Änderungsliste
            if (data.dryRunDetails && data.dryRunDetails.length > 0) {
                html += `<h4>📝 Detaillierte Änderungen</h4>`;
                html += `<div style="max-height: 400px; overflow-y: auto; border: 1px solid #ddd; border-radius: 4px;">`;
                
                data.dryRunDetails.forEach((item, index) => {
                    const bgColor = index % 2 === 0 ? '#f9f9f9' : '#ffffff';
                    
                    if (item.action === 'UPDATE') {
                        html += `<div style="background: ${bgColor}; padding: 10px; border-bottom: 1px solid #eee;">`;
                        html += `<strong style="color: #ffc107;">🔄 UPDATE</strong> `;
                        html += `<strong>AV-ID ${item.av_id}</strong> (${item.name})<br>`;
                        html += `<div style="margin-left: 20px; margin-top: 5px;">`;
                        item.changes.forEach(change => {
                            html += `<div style="font-family: monospace; font-size: 11px; color: #666;">• ${change}</div>`;
                        });
                        html += `</div></div>`;
                        
                    } else if (item.action === 'INSERT') {
                        html += `<div style="background: ${bgColor}; padding: 10px; border-bottom: 1px solid #eee;">`;
                        html += `<strong style="color: #28a745;">➕ NEU EINFÜGEN</strong> `;
                        html += `<strong>AV-ID ${item.av_id}</strong> (${item.name})`;
                        html += `</div>`;
                        
                    } else if (item.action === 'UNCHANGED') {
                        // Zeige nur eine Auswahl der unveränderten Records um das Modal nicht zu überladen
                        if (index < 20) {
                            html += `<div style="background: ${bgColor}; padding: 5px 10px; border-bottom: 1px solid #eee;">`;
                            html += `<span style="color: #6c757d;">✓ UNVERÄNDERT</span> `;
                            html += `AV-ID ${item.av_id} (${item.name})`;
                            html += `</div>`;
                        }
                    }
                });
                
                // Hinweis wenn zu viele unveränderte Records
                const unchangedCount = data.dryRunDetails.filter(item => item.action === 'UNCHANGED').length;
                if (unchangedCount > 20) {
                    html += `<div style="background: #e9ecef; padding: 10px; text-align: center; font-style: italic;">`;
                    html += `... und ${unchangedCount - 20} weitere unveränderte Records`;
                    html += `</div>`;
                }
                
                html += `</div>`;
            }
            
            // Fehler und Warnungen
            if (data.errors && data.errors.length > 0) {
                html += `<div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 6px; margin-top: 20px;">`;
                html += `<h4 style="margin-top: 0; color: #856404;">⚠️ Warnungen (${data.errors.length})</h4>`;
                html += `<ul style="margin: 0;">`;
                data.errors.slice(0, 10).forEach(error => {
                    html += `<li style="font-size: 12px;">${error}</li>`;
                });
                if (data.errors.length > 10) {
                    html += `<li style="font-style: italic;">... und ${data.errors.length - 10} weitere</li>`;
                }
                html += `</ul></div>`;
            }
            
            content.innerHTML = html;
            
            // Zeige Panel mit angepasstem Titel
            document.querySelector('#detailsPanel .details-header').innerHTML = `
                🔍 WebImp Dry-Run Analyse
                <button class="close-btn" onclick="hideDetailsPanel()">✕ Schließen</button>
            `;
            document.getElementById('detailsPanel').style.display = 'block';
        }

        function hideDetailsPanel() {
            document.getElementById('detailsPanel').style.display = 'none';
        }

        // === QUOTA-OPTIMIERUNG FUNKTIONEN ===        // Quota-Zeitraumauswahl Variablen
        let quotaSelectionMode = false;
        let quotaStartSelected = null;
        let quotaEndSelected = null;
        let allTableRows = [];
        let servicedDays = []; // Array der SERVICED Tage
        
        // Quota-Daten aus PHP für SERVICED-Check
        const quotaDataForJS = <?= json_encode($quotaData) ?>;
        
        // Initialisiere SERVICED-Tage beim Laden
        function initializeServicedDays() {
            const allDates = <?= json_encode($alleTage) ?>;
            servicedDays = [];
            
            allDates.forEach((date, index) => {
                let isServiced = false;
                
                // Prüfe ob für diesen Tag SERVICED Quotas existieren
                for (let quota of quotaDataForJS) {
                    if (quota.mode === 'SERVICED' && 
                        date >= quota.date_from && 
                        date < quota.date_to) {
                        isServiced = true;
                        break;
                    }
                }
                
                servicedDays[index] = isServiced;
                
                // Markiere nicht-SERVICED Tage visuell
                if (!isServiced) {
                    const dateCell = document.getElementById('date-cell-' + index);
                    const tableRow = document.querySelectorAll('.table-row')[index];
                    if (dateCell) dateCell.classList.add('quota-not-serviced');
                    if (tableRow) tableRow.classList.add('quota-not-serviced');
                }
            });
        }
        
        function toggleQuotaDateSelection() {
            quotaSelectionMode = !quotaSelectionMode;
            const btn = document.getElementById('dateSelectionBtn');
            const hint = document.getElementById('quotaSelectionHint');
            const container = document.querySelector('.container');
            
            if (quotaSelectionMode) {
                btn.textContent = '❌ Auswahl beenden';
                btn.style.background = '#dc3545';
                hint.style.display = 'block';
                container.classList.add('quota-selection-active');
                
                // Tabelle und Datumsspalte hervorheben (nur SERVICED Tage)
                const tableRows = document.querySelectorAll('.table-row');
                const dateCells = document.querySelectorAll('.cell-datum');
                
                tableRows.forEach((row, index) => {
                    if (servicedDays[index]) {
                        row.classList.add('quota-selection-hint');
                    }
                });
                
                dateCells.forEach((cell, index) => {
                    if (servicedDays[index]) {
                        cell.classList.add('quota-selection-hint');
                    }
                });
                
                // Vorherige Auswahl NICHT löschen, nur Selection-Hints entfernen
                clearSelectionHints();
            } else {
                btn.textContent = '📅 In Tabelle auswählen';
                btn.style.background = '#17a2b8';
                hint.style.display = 'none';
                container.classList.remove('quota-selection-active');
                
                // Nur Selection-Hints entfernen, aber Auswahl beibehalten
                clearSelectionHints();
            }
        }
        
        function clearSelectionHints() {
            // Entfernt nur die gelben Auswahl-Hints, behält aber die eigentliche Auswahl
            const tableRows = document.querySelectorAll('.table-row');
            const dateCells = document.querySelectorAll('.cell-datum');
            
            tableRows.forEach(row => {
                row.classList.remove('quota-selection-hint');
            });
            
            dateCells.forEach(cell => {
                cell.classList.remove('quota-selection-hint');
            });
        }
        
        function clearQuotaSelection() {
            // Vollständiges Löschen der Auswahl (nur bei explizitem Reset)
            quotaStartSelected = null;
            quotaEndSelected = null;
            
            const tableRows = document.querySelectorAll('.table-row');
            const dateCells = document.querySelectorAll('.cell-datum');
            
            tableRows.forEach(row => {
                row.classList.remove('quota-start-selected', 'quota-end-selected', 'quota-range-selected', 'quota-selection-hint');
            });
            
            dateCells.forEach(cell => {
                cell.classList.remove('date-start-selected', 'date-end-selected', 'date-range-selected', 'quota-selection-hint');
            });
        }
        
        function selectDateForQuota(dayIndex) {
            if (!quotaSelectionMode) return;
            
            // Prüfe ob dieser Tag SERVICED ist
            if (!servicedDays[dayIndex]) {
                alert('Dieser Tag ist nicht als SERVICED markiert und kann nicht für die Quota-Optimierung ausgewählt werden.');
                return;
            }
            
            const allDates = <?= json_encode($alleTage) ?>;
            const rows = document.querySelectorAll('.table-row');
            const dateCells = document.querySelectorAll('.cell-datum');
            const clickedRow = rows[dayIndex];
            const clickedDateCell = dateCells[dayIndex];
            const clickedDate = clickedRow.getAttribute('data-date');
            
            if (!quotaStartSelected) {
                // Ersten Tag auswählen - automatisch erweitern für mehrtägige Quotas
                const expandedRange = expandRangeForMultiDayQuotas(dayIndex, dayIndex, allDates);
                
                quotaStartSelected = { 
                    index: expandedRange.startIndex, 
                    date: allDates[expandedRange.startIndex], 
                    row: rows[expandedRange.startIndex], 
                    dateCell: dateCells[expandedRange.startIndex] 
                };
                
                // Visuell markieren
                quotaStartSelected.row.classList.add('quota-start-selected');
                quotaStartSelected.row.classList.remove('quota-selection-hint');
                quotaStartSelected.dateCell.classList.add('date-start-selected');
                quotaStartSelected.dateCell.classList.remove('quota-selection-hint');
                
                // Datum in Input setzen
                document.getElementById('quotaStartDate').value = quotaStartSelected.date;
                
                // Wenn mehrtägige Quota automatisch erweitert wurde, zeige Info
                if (expandedRange.startIndex != dayIndex || expandedRange.endIndex != dayIndex) {
                    document.getElementById('quotaSelectionHint').innerHTML = 
                        `🖱️ Startdatum automatisch erweitert auf <strong>${formatDate(quotaStartSelected.date)}</strong> (mehrtägige Quota). Klicken Sie nun auf das <strong>Enddatum</strong>.`;
                } else {
                    document.getElementById('quotaSelectionHint').innerHTML = 
                        `🖱️ Startdatum gewählt: <strong>${formatDate(quotaStartSelected.date)}</strong>. Klicken Sie nun auf das <strong>Enddatum</strong>.`;
                }
                    
            } else if (!quotaEndSelected) {
                // Zweiten Tag auswählen - automatisch erweitern für mehrtägige Quotas
                const startIndex = quotaStartSelected.index;
                const endIndex = dayIndex;
                
                // WICHTIG: Erst den End-Tag einzeln erweitern, um mehrtägige Quotas zu berücksichtigen
                const endExpanded = expandRangeForMultiDayQuotas(endIndex, endIndex, allDates);
                const actualEndIndex = endExpanded.endIndex;
                
                // Dann den gesamten Bereich vom Start bis zum erweiterten Ende erweitern
                const fullRange = expandRangeForMultiDayQuotas(
                    Math.min(startIndex, actualEndIndex), 
                    Math.max(startIndex, actualEndIndex), 
                    allDates
                );
                
                // Prüfe ob der vollständig erweiterte Bereich durchgehend SERVICED ist
                for (let i = fullRange.startIndex; i <= fullRange.endIndex; i++) {
                    if (!servicedDays[i]) {
                        alert(`Der automatisch erweiterte Zeitraum (wegen mehrtägiger Quotas) enthält nicht-SERVICED Tage. Bitte wählen Sie einen anderen Zeitraum.`);
                        return;
                    }
                }
                
                // Update Start falls erweitert
                if (fullRange.startIndex < quotaStartSelected.index) {
                    quotaStartSelected.row.classList.remove('quota-start-selected');
                    quotaStartSelected.dateCell.classList.remove('date-start-selected');
                    
                    quotaStartSelected = { 
                        index: fullRange.startIndex, 
                        date: allDates[fullRange.startIndex], 
                        row: rows[fullRange.startIndex], 
                        dateCell: dateCells[fullRange.startIndex] 
                    };
                    
                    quotaStartSelected.row.classList.add('quota-start-selected');
                    quotaStartSelected.dateCell.classList.add('date-start-selected');
                    document.getElementById('quotaStartDate').value = quotaStartSelected.date;
                }
                
                // Set End mit vollständiger Erweiterung
                quotaEndSelected = { 
                    index: fullRange.endIndex, 
                    date: allDates[fullRange.endIndex], 
                    row: rows[fullRange.endIndex], 
                    dateCell: dateCells[fullRange.endIndex] 
                };
                
                quotaEndSelected.row.classList.add('quota-end-selected');
                quotaEndSelected.row.classList.remove('quota-selection-hint');
                quotaEndSelected.dateCell.classList.add('date-end-selected');
                quotaEndSelected.dateCell.classList.remove('quota-selection-hint');
                
                // Datum in Input setzen
                document.getElementById('quotaEndDate').value = quotaEndSelected.date;
                
                // Bereich zwischen Start und Ende markieren
                highlightQuotaRange();
                
                // Info über automatische Erweiterung
                const originalStartIndex = Math.min(startIndex, endIndex);
                const originalEndIndex = Math.max(startIndex, endIndex);
                
                if (fullRange.startIndex != originalStartIndex || 
                    fullRange.endIndex != originalEndIndex) {
                    document.getElementById('quotaSelectionHint').innerHTML = 
                        `✅ Zeitraum automatisch erweitert von <strong>${formatDate(quotaStartSelected.date)}</strong> bis <strong>${formatDate(quotaEndSelected.date)}</strong> (mehrtägige Quotas vollständig eingeschlossen).`;
                } else {
                    document.getElementById('quotaSelectionHint').innerHTML = 
                        `✅ Zeitraum gewählt: <strong>${formatDate(quotaStartSelected.date)}</strong> bis <strong>${formatDate(quotaEndSelected.date)}</strong>.`;
                }
                
                // Auswahl automatisch beenden, aber Markierung beibehalten
                setTimeout(() => {
                    toggleQuotaDateSelection(); // Beendet nur den Auswahlmodus, behält Markierung
                }, 1000);
                
            } else {
                // Reset wenn bereits beide ausgewählt
                clearQuotaSelection();
                selectDateForQuota(dayIndex); // Neue Auswahl starten
            }
        }
        
        // Erweitert einen Datumsbereich um vollständige mehrtägige Quotas einzuschließen
        function expandRangeForMultiDayQuotas(startIndex, endIndex, allDates) {
            let expandedStart = startIndex;
            let expandedEnd = endIndex;
            let hasExpanded = true;
            
            console.log(`DEBUG: Eingabe - Start: ${startIndex} (${allDates[startIndex]}), Ende: ${endIndex} (${allDates[endIndex]})`);
            
            // Solange erweitern bis keine mehrtägigen Quotas mehr teilweise überschnitten werden
            while (hasExpanded) {
                hasExpanded = false;
                
                // Prüfe alle Quotas im erweiterten Bereich
                for (let i = expandedStart; i <= expandedEnd; i++) {
                    const currentDate = allDates[i];
                    
                    // Finde Quotas für diesen Tag
                    quotaDataForJS.forEach(quota => {
                        const quotaStart = quota.date_from;
                        const quotaEnd = quota.date_to;
                        
                        // Prüfe ob diese Quota den aktuellen Tag betrifft
                        if (currentDate >= quotaStart && currentDate < quotaEnd) {
                            console.log(`DEBUG: Quota gefunden - ${quota.title}: ${quotaStart} bis ${quotaEnd} (betrifft ${currentDate})`);
                            
                            // Finde Indices für Quota-Start
                            const quotaStartIndex = allDates.indexOf(quotaStart);
                            
                            // Wenn Quota-Start vor unserem Bereich liegt, erweitern
                            if (quotaStartIndex >= 0 && quotaStartIndex < expandedStart) {
                                console.log(`DEBUG: Erweitere Start von ${expandedStart} auf ${quotaStartIndex}`);
                                expandedStart = quotaStartIndex;
                                hasExpanded = true;
                            }
                            
                            // Für das Ende: Finde den letzten Tag der Quota
                            // Quota-Ende ist exklusiv, also suchen wir den Tag VOR quotaEnd
                            let lastQuotaDay = -1;
                            for (let j = allDates.length - 1; j >= 0; j--) {
                                if (allDates[j] < quotaEnd) {
                                    lastQuotaDay = j;
                                    break;
                                }
                            }
                            
                            console.log(`DEBUG: Quota Ende ${quotaEnd} → letzter Tag Index: ${lastQuotaDay} (${allDates[lastQuotaDay]})`);
                            
                            // Wenn der letzte Tag der Quota nach unserem Bereich liegt, erweitern
                            if (lastQuotaDay >= 0 && lastQuotaDay > expandedEnd) {
                                console.log(`DEBUG: Erweitere Ende von ${expandedEnd} auf ${lastQuotaDay}`);
                                expandedEnd = lastQuotaDay;
                                hasExpanded = true;
                            }
                        }
                    });
                }
            }
            
            console.log(`DEBUG: Ausgabe - Start: ${expandedStart} (${allDates[expandedStart]}), Ende: ${expandedEnd} (${allDates[expandedEnd]})`);
            
            return {
                startIndex: expandedStart,
                endIndex: expandedEnd
            };
        }
        
        function highlightQuotaRange() {
            if (!quotaStartSelected || !quotaEndSelected) return;
            
            const startIndex = Math.min(quotaStartSelected.index, quotaEndSelected.index);
            const endIndex = Math.max(quotaStartSelected.index, quotaEndSelected.index);
            const rows = document.querySelectorAll('.table-row');
            const dateCells = document.querySelectorAll('.cell-datum');
            
            for (let i = startIndex + 1; i < endIndex; i++) {
                rows[i].classList.add('quota-range-selected');
                rows[i].classList.remove('quota-selection-hint');
                dateCells[i].classList.add('date-range-selected');
                dateCells[i].classList.remove('quota-selection-hint');
            }
        }
        
        function formatDate(dateStr) {
            const date = new Date(dateStr);
            const days = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
            const months = ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];
            
            return `${days[date.getDay()]} ${date.getDate()}.${months[date.getMonth()]}`;
        }
        
        function resetQuotaSelection() {
            // Expliziter Reset der Auswahl mit Bestätigung
            if (quotaStartSelected || quotaEndSelected) {
                if (confirm('Möchten Sie die aktuelle Zeitraumauswahl wirklich löschen?')) {
                    clearQuotaSelection();
                    // Datumsfelder zurücksetzen auf Standard
                    const allDates = <?= json_encode($alleTage) ?>;
                    if (allDates.length > 0) {
                        document.getElementById('quotaStartDate').value = allDates[0];
                        document.getElementById('quotaEndDate').value = allDates[allDates.length - 1];
                    }
                }
            } else {
                alert('Keine Auswahl vorhanden.');
            }
        }
        
        // Initialisiere SERVICED-Tage beim Laden der Seite
        document.addEventListener('DOMContentLoaded', function() {
            initializeServicedDays();
        });
        
        function toggleQuotaOptimization() {
            const panel = document.getElementById('quotaOptimizationPanel');
            const btn = document.getElementById('quotaOptBtn');
            
            if (panel.style.display === 'none' || panel.style.display === '') {
                panel.style.display = 'block';
                btn.textContent = 'Panel schließen';
                btn.style.background = '#dc3545';
            } else {
                panel.style.display = 'none';
                btn.textContent = 'Quota-Optimierung';
                btn.style.background = '#17a2b8';
            }
        }
        
        function applyQuotaOptimization() {
            const quotaStartDate = document.getElementById('quotaStartDate').value;
            const quotaEndDate = document.getElementById('quotaEndDate').value;
            const za = document.getElementById('zielauslastung').value;
            
            // Validierung der Datumsfelder
            if (!quotaStartDate || !quotaEndDate) {
                alert('Bitte wählen Sie einen gültigen Zeitraum für die Quota-Optimierung aus.');
                return;
            }
            
            if (quotaStartDate > quotaEndDate) {
                alert('Das Startdatum muss vor dem Enddatum liegen.');
                return;
            }
            
            // URL mit Parametern erstellen
            const params = new URLSearchParams();
            params.set('start', quotaStartDate);
            params.set('end', quotaEndDate);
            params.set('za', za);
            
            // Seite neu laden mit neuen Parametern
            window.location.href = '?' + params.toString();
        }

        async function showQuotaComparison() {
            const quotaStartDate = document.getElementById('quotaStartDate').value;
            const quotaEndDate = document.getElementById('quotaEndDate').value;
            const za = document.getElementById('zielauslastung').value;
            
            // Validierung der Datumsfelder
            if (!quotaStartDate || !quotaEndDate) {
                alert('Bitte wählen Sie einen gültigen Zeitraum für die Quota-Optimierung aus.');
                return;
            }
            
            if (quotaStartDate > quotaEndDate) {
                alert('Das Startdatum muss vor dem Enddatum liegen.');
                return;
            }
            
            try {
                // API-Aufruf für Quota-Vergleich
                const protocol = window.location.protocol;
                const host = window.location.host;
                const baseUrl = `${protocol}//${host}`;
                const response = await fetch(`${baseUrl}/wci/belegung/quota_comparison.php?start=${quotaStartDate}&end=${quotaEndDate}&za=${za}&json=1`);
                
                if (!response.ok) {
                    throw new Error(`API Fehler: ${response.status}`);
                }
                
                const data = await response.json();
                
                if (data.success) {
                    displayQuotaComparison(data.comparison, quotaStartDate, quotaEndDate, za);
                } else {
                    throw new Error(data.message || 'Fehler beim Laden der Quota-Daten');
                }
                
            } catch (error) {
                console.error('Quota Vergleich Fehler:', error);
                alert('❌ Fehler beim Laden des Quota-Vergleichs: ' + error.message);
            }
        }

        function displayQuotaComparison(comparisonData, startDate, endDate, za) {
            const content = document.getElementById('detailsContent');
            
            let html = `<h3>📊 Quota Vergleich - Optimierung</h3>`;
            html += `<div style="background: #f8f9fa; padding: 15px; border-radius: 6px; margin-bottom: 20px;">`;
            html += `<h4 style="margin-top: 0;">⚙️ Optimierungsparameter</h4>`;
            html += `<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px;">`;
            html += `<div><strong>Zeitraum:</strong> ${startDate} bis ${endDate}</div>`;
            html += `<div><strong>Zielauslastung:</strong> ${za}%</div>`;
            html += `<div><strong>Anzahl Tage:</strong> ${comparisonData.length}</div>`;
            html += `</div></div>`;
            
            // Vergleichstabelle
            html += `<div style="overflow-x: auto;">`;
            html += `<table style="width: 100%; border-collapse: collapse; font-size: 12px;">`;
            html += `<thead>`;
            html += `<tr style="background: #e9ecef;">`;
            html += `<th style="padding: 8px; border: 1px solid #ddd; text-align: left;">Datum</th>`;
            html += `<th style="padding: 8px; border: 1px solid #ddd; text-align: center;">HRS ID</th>`;
            html += `<th style="padding: 8px; border: 1px solid #ddd; text-align: center;">Alte Quotas</th>`;
            html += `<th style="padding: 8px; border: 1px solid #ddd; text-align: center;">Neue Quotas</th>`;
            html += `<th style="padding: 8px; border: 1px solid #ddd; text-align: center;">Änderung</th>`;
            html += `<th style="padding: 8px; border: 1px solid #ddd; text-align: center;">Belegung</th>`;
            html += `</tr>`;
            html += `</thead>`;
            html += `<tbody>`;
            
            comparisonData.forEach((day, index) => {
                const bgColor = index % 2 === 0 ? '#f9f9f9' : '#ffffff';
                const isWeekend = new Date(day.datum).getDay() === 0 || new Date(day.datum).getDay() === 6;
                const dateStyle = isWeekend ? 'color: #cc6600; font-weight: bold;' : '';
                
                html += `<tr style="background: ${bgColor};">`;
                html += `<td style="padding: 6px; border: 1px solid #ddd; ${dateStyle}">${formatDate(day.datum)}</td>`;
                html += `<td style="padding: 6px; border: 1px solid #ddd; text-align: center;">${day.hrs_id || '-'}</td>`;
                
                // Alte Quotas
                html += `<td style="padding: 6px; border: 1px solid #ddd; font-family: monospace;">`;
                if (day.old_quotas) {
                    Object.entries(day.old_quotas).forEach(([type, value]) => {
                        if (value > 0) {
                            html += `<span style="display: inline-block; margin-right: 8px; padding: 2px 4px; background: #e9ecef; border-radius: 3px;">${type}: ${value}</span>`;
                        }
                    });
                }
                html += `</td>`;
                
                // Neue Quotas
                html += `<td style="padding: 6px; border: 1px solid #ddd; font-family: monospace;">`;
                if (day.new_quotas) {
                    Object.entries(day.new_quotas).forEach(([type, value]) => {
                        if (value > 0) {
                            const oldValue = day.old_quotas?.[type] || 0;
                            const isChanged = value !== oldValue;
                            const bgClass = isChanged ? (value > oldValue ? '#d4edda' : '#f8d7da') : '#e9ecef';
                            html += `<span style="display: inline-block; margin-right: 8px; padding: 2px 4px; background: ${bgClass}; border-radius: 3px;">${type}: ${value}</span>`;
                        }
                    });
                }
                html += `</td>`;
                
                // Änderungen
                html += `<td style="padding: 6px; border: 1px solid #ddd; text-align: center;">`;
                if (day.changes && day.changes.length > 0) {
                    day.changes.forEach(change => {
                        const changeColor = change.startsWith('+') ? '#28a745' : change.startsWith('-') ? '#dc3545' : '#6c757d';
                        html += `<div style="color: ${changeColor}; font-size: 10px; font-family: monospace;">${change}</div>`;
                    });
                } else {
                    html += `<span style="color: #6c757d;">-</span>`;
                }
                html += `</td>`;
                
                // Belegung
                html += `<td style="padding: 6px; border: 1px solid #ddd; text-align: center;">`;
                if (day.occupancy) {
                    html += `<div style="font-size: 10px;">Gesamt: ${day.occupancy.total || 0}</div>`;
                    html += `<div style="font-size: 9px; color: #666;">Ausl: ${day.occupancy.utilization || 0}%</div>`;
                }
                html += `</td>`;
                
                html += `</tr>`;
            });
            
            html += `</tbody></table></div>`;
            
            content.innerHTML = html;
            
            // Zeige Panel mit angepasstem Titel
            document.querySelector('#detailsPanel .details-header').innerHTML = `
                📊 Quota Vergleich - Optimierung
                <button class="close-btn" onclick="hideDetailsPanel()" style="float: right; background: none; border: none; font-size: 18px; cursor: pointer;">✕ Schließen</button>
            `;
            document.getElementById('detailsPanel').style.display = 'block';
        }

        function formatDate(dateStr) {
            const date = new Date(dateStr);
            const days = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
            return `${date.getDate().toString().padStart(2, '0')}.${(date.getMonth() + 1).toString().padStart(2, '0')} (${days[date.getDay()]})`;
        }
        
        // ===== QUOTA-ÄNDERUNGS-VORSCHAU FUNKTIONEN =====
        
        function showQuotaChangesPreview() {
            if (!quotaStartSelected || !quotaEndSelected) {
                alert('Bitte wählen Sie zuerst einen Zeitraum in der Tabelle aus (Start- und Enddatum).');
                return;
            }
            
            const startDate = quotaStartSelected.date;
            const endDate = quotaEndSelected.date;
            const zielauslastung = parseInt(document.getElementById('zielauslastung').value) || 135;
            
            // Modal anzeigen
            document.getElementById('quotaChangesModal').style.display = 'block';
            
            // Datum-Range anzeigen
            document.getElementById('quotaChangesDateRange').textContent = 
                `${formatDate(startDate)} bis ${formatDate(endDate)} | Zielauslastung: ${zielauslastung}`;
            
            // Daten analysieren
            analyzeQuotaChanges(startDate, endDate, zielauslastung);
        }
        
        function analyzeQuotaChanges(startDate, endDate, zielauslastung) {
            const allDates = <?= json_encode($alleTage) ?>;
            const startIndex = allDates.indexOf(startDate);
            const endIndex = allDates.indexOf(endDate);
            
            if (startIndex === -1 || endIndex === -1) {
                document.getElementById('quotaChangesSummary').innerHTML = 
                    '<span style="color: #e74c3c;">❌ Fehler: Ungültiger Datumsbereich</span>';
                return;
            }
            
            const selectedDates = allDates.slice(startIndex, endIndex + 1);
            const quotasToDelete = [];
            const newQuotas = [];
            const quotasDeletedIds = new Set();
            
            // Analyse aller betroffenen Tage
            selectedDates.forEach(date => {
                // Originale Quotas für diesen Tag finden
                const dayQuotas = quotaDataForJS.filter(quota => 
                    date >= quota.date_from && date < quota.date_to
                );
                
                // Originale Quotas zur Löschliste hinzufügen (einmalig)
                dayQuotas.forEach(quota => {
                    if (!quotasDeletedIds.has(quota.id)) {
                        quotasDeletedIds.add(quota.id);
                        quotasToDelete.push({
                            ...quota,
                            affects_date: date
                        });
                    }
                });
                
                // Neue optimierte Quota für diesen Tag berechnen
                if (dayQuotas.length > 0) {
                    const quota = dayQuotas[0];
                    
                    // Belegungsdaten für diesen Tag simulieren
                    const dayKey = date + '_hrs';
                    const lokalKey = date + '_lokal';
                    const hrsData = <?= json_encode($datenIndex) ?>[dayKey] || {sonder: 0, lager: 0, betten: 0, dz: 0};
                    const lokalData = <?= json_encode($datenIndex) ?>[lokalKey] || {sonder: 0, lager: 0, betten: 0, dz: 0};
                    
                    const gesamtBelegt = hrsData.sonder + hrsData.lager + hrsData.betten + hrsData.dz +
                                        lokalData.sonder + lokalData.lager + lokalData.betten + lokalData.dz;
                    
                    // Originale Quota-Werte
                    const originalSonder = quota.categories?.SK?.total_beds || 0;
                    const originalLager = quota.categories?.ML?.total_beds || 0;
                    const originalBetten = quota.categories?.MBZ?.total_beds || 0;
                    const originalDz = quota.categories?.['2BZ']?.total_beds || 0;
                    
                    // Freie Quotas berechnen
                    const freieQuotas = Math.max(0, originalSonder - hrsData.sonder) +
                                       Math.max(0, originalLager - hrsData.lager) +
                                       Math.max(0, originalBetten - hrsData.betten) +
                                       Math.max(0, originalDz - hrsData.dz);
                    
                    // Optimierte Quotas berechnen
                    const benoetigteFreieQuotas = zielauslastung - gesamtBelegt;
                    const quotaAnpassung = benoetigteFreieQuotas - freieQuotas;
                    
                    let neueSonder = originalSonder;
                    let neueLager = originalLager;
                    let neueBetten = originalBetten;
                    let neueDz = originalDz;
                    
                    if (quotaAnpassung !== 0) {
                        if (originalLager > 0) {
                            neueLager = Math.max(0, originalLager + quotaAnpassung);
                        } else {
                            // Verteilung auf verfügbare Kategorien
                            const verfuegbare = [];
                            if (originalSonder > 0) verfuegbare.push('sonder');
                            if (originalBetten > 0) verfuegbare.push('betten');
                            if (originalDz > 0) verfuegbare.push('dz');
                            
                            if (verfuegbare.length > 0) {
                                const anteil = quotaAnpassung / verfuegbare.length;
                                if (verfuegbare.includes('sonder')) neueSonder = Math.max(0, originalSonder + anteil);
                                if (verfuegbare.includes('betten')) neueBetten = Math.max(0, originalBetten + anteil);
                                if (verfuegbare.includes('dz')) neueDz = Math.max(0, originalDz + anteil);
                            }
                        }
                    }
                    
                    // Nur hinzufügen wenn sich etwas geändert hat
                    const hasChanges = neueSonder !== originalSonder || 
                                      neueLager !== originalLager || 
                                      neueBetten !== originalBetten || 
                                      neueDz !== originalDz;
                    
                    if (hasChanges) {
                        const neueGesamtAuslastung = gesamtBelegt + 
                            Math.max(0, neueSonder - hrsData.sonder) +
                            Math.max(0, neueLager - hrsData.lager) +
                            Math.max(0, neueBetten - hrsData.betten) +
                            Math.max(0, neueDz - hrsData.dz);
                        
                        newQuotas.push({
                            date: date,
                            name: `Auto-${date.substring(8)}${date.substring(5,7)}`,
                            original: {
                                sonder: originalSonder,
                                lager: originalLager,
                                betten: originalBetten,
                                dz: originalDz
                            },
                            optimized: {
                                sonder: Math.round(neueSonder),
                                lager: Math.round(neueLager),
                                betten: Math.round(neueBetten),
                                dz: Math.round(neueDz)
                            },
                            occupancy: {
                                belegt: gesamtBelegt,
                                alt: gesamtBelegt + freieQuotas,
                                neu: Math.round(neueGesamtAuslastung)
                            }
                        });
                    }
                }
            });
            
            // Ergebnisse anzeigen
            displayQuotaChanges(quotasToDelete, newQuotas, selectedDates.length);
        }
        
        function displayQuotaChanges(quotasToDelete, newQuotas, totalDays) {
            // Zusammenfassung
            const summaryHtml = `
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px;">
                    <div style="text-align: center;">
                        <div style="font-size: 24px; font-weight: bold; color: #3498db;">${totalDays}</div>
                        <div style="font-size: 12px; color: #666;">Betroffene Tage</div>
                    </div>
                    <div style="text-align: center;">
                        <div style="font-size: 24px; font-weight: bold; color: #e74c3c;">${quotasToDelete.length}</div>
                        <div style="font-size: 12px; color: #666;">Zu löschende Quotas</div>
                    </div>
                    <div style="text-align: center;">
                        <div style="font-size: 24px; font-weight: bold; color: #27ae60;">${newQuotas.length}</div>
                        <div style="font-size: 12px; color: #666;">Neue Quotas</div>
                    </div>
                    <div style="text-align: center;">
                        <div style="font-size: 24px; font-weight: bold; color: #f39c12;">${newQuotas.length - quotasToDelete.length}</div>
                        <div style="font-size: 12px; color: #666;">Netto-Änderung</div>
                    </div>
                </div>
            `;
            document.getElementById('quotaChangesSummary').innerHTML = summaryHtml;
            
            // Zu löschende Quotas
            let deleteHtml = '';
            if (quotasToDelete.length === 0) {
                deleteHtml = '<div style="color: #6c757d; font-style: italic;">Keine originalen Quotas betroffen</div>';
            } else {
                deleteHtml = '<div style="max-height: 300px; overflow-y: auto;">';
                quotasToDelete.forEach(quota => {
                    deleteHtml += `
                        <div style="background: white; border: 1px solid #f1c0c0; border-radius: 4px; padding: 10px; margin-bottom: 8px;">
                            <div style="font-weight: bold; color: #dc3545; margin-bottom: 5px;">
                                📋 ${quota.title || 'Unbenannte Quota'} (ID: ${quota.id})
                            </div>
                            <div style="font-size: 11px; color: #666; margin-bottom: 5px;">
                                🗓️ Zeitraum: ${formatDate(quota.date_from)} bis ${formatDate(quota.date_to)}
                            </div>
                            <div style="font-size: 11px; display: grid; grid-template-columns: repeat(4, 1fr); gap: 5px;">
                                <span>SK: ${quota.categories?.SK?.total_beds || 0}</span>
                                <span>ML: ${quota.categories?.ML?.total_beds || 0}</span>
                                <span>MBZ: ${quota.categories?.MBZ?.total_beds || 0}</span>
                                <span>2BZ: ${quota.categories?.['2BZ']?.total_beds || 0}</span>
                            </div>
                        </div>
                    `;
                });
                deleteHtml += '</div>';
            }
            document.getElementById('quotasToDelete').innerHTML = deleteHtml;
            
            // Neue Quotas
            let newHtml = '';
            if (newQuotas.length === 0) {
                newHtml = '<div style="color: #6c757d; font-style: italic;">Keine neuen Quotas erforderlich</div>';
            } else {
                newHtml = '<div style="max-height: 300px; overflow-y: auto;">';
                newQuotas.forEach(quota => {
                    newHtml += `
                        <div style="background: white; border: 1px solid #c0f1c0; border-radius: 4px; padding: 10px; margin-bottom: 8px;">
                            <div style="font-weight: bold; color: #28a745; margin-bottom: 5px;">
                                ➕ ${quota.name} (${formatDate(quota.date)})
                            </div>
                            <div style="font-size: 11px; display: grid; grid-template-columns: repeat(4, 1fr); gap: 5px; margin-bottom: 5px;">
                                <span style="color: ${quota.optimized.sonder !== quota.original.sonder ? '#dc3545' : '#666'};">
                                    SK: ${quota.original.sonder} → ${quota.optimized.sonder}
                                </span>
                                <span style="color: ${quota.optimized.lager !== quota.original.lager ? '#dc3545' : '#666'};">
                                    ML: ${quota.original.lager} → ${quota.optimized.lager}
                                </span>
                                <span style="color: ${quota.optimized.betten !== quota.original.betten ? '#dc3545' : '#666'};">
                                    MBZ: ${quota.original.betten} → ${quota.optimized.betten}
                                </span>
                                <span style="color: ${quota.optimized.dz !== quota.original.dz ? '#dc3545' : '#666'};">
                                    2BZ: ${quota.original.dz} → ${quota.optimized.dz}
                                </span>
                            </div>
                            <div style="font-size: 10px; color: #666; border-top: 1px solid #eee; padding-top: 5px;">
                                📊 Auslastung: ${quota.occupancy.alt} → ${quota.occupancy.neu} (Belegung: ${quota.occupancy.belegt})
                            </div>
                        </div>
                    `;
                });
                newHtml += '</div>';
            }
            document.getElementById('newQuotas').innerHTML = newHtml;
        }
        
        function closeQuotaChangesModal() {
            document.getElementById('quotaChangesModal').style.display = 'none';
        }
        
        function exportQuotaChanges() {
            alert('Excel-Export wird in einer späteren Version implementiert.');
        }
        
        function deleteSelectedQuotas() {
            // Get current quota changes from the modal analysis
            const quotasToDeleteElements = document.querySelectorAll('#quotasToDelete > div > div');
            
            if (quotasToDeleteElements.length === 0) {
                alert('ℹ️ Keine Quotas zum Löschen gefunden.');
                return;
            }
            
            // Extract quota DATABASE IDs (not HRS IDs!) from the displayed elements
            const quotaDbIds = [];
            const quotaDetails = [];
            
            quotasToDeleteElements.forEach(element => {
                const text = element.textContent;
                const idMatch = text.match(/ID: (\d+)/);
                if (idMatch) {
                    const quotaDbId = parseInt(idMatch[1]);
                    const quotaData = quotaDataForJS.find(q => q.id === quotaDbId);
                    if (quotaData && quotaData.hrs_id) {
                        quotaDbIds.push(quotaDbId);
                        quotaDetails.push({
                            db_id: quotaDbId,
                            hrs_id: quotaData.hrs_id,
                            name: quotaData.title || 'Unbenannte Quota',
                            datum_von: quotaData.date_from,
                            datum_bis: quotaData.date_to
                        });
                    }
                }
            });
            
            if (quotaDbIds.length === 0) {
                alert('❌ Fehler: Keine gültigen HRS-Quotas zum Löschen gefunden.');
                return;
            }
            
            // Confirmation dialog with DB-IDs and HRS-IDs
            const confirmMsg = `🗑️ Wirklich ${quotaDbIds.length} Original-Quota(s) im HRS-System löschen?\n\n` +
                              `⚠️ Dies kann nicht rückgängig gemacht werden!\n\n` +
                              `Quotas (werden als Batch gelöscht):\n${quotaDetails.map(q => `- ${q.name} (DB-ID: ${q.db_id}, HRS-ID: ${q.hrs_id})`).join('\n')}`;
            
            if (!confirm(confirmMsg)) {
                return;
            }
            
            // Show progress modal
            document.getElementById('quotaChangesModal').style.display = 'none';
            showBatchDeleteProgressModal(quotaDbIds, quotaDetails);
        }
        
        function showBatchDeleteProgressModal(quotaDbIds, quotaDetails) {
            // Create progress modal
            const modal = document.createElement('div');
            modal.id = 'batchDeleteProgressModal';
            modal.style.cssText = `
                position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
                background: rgba(0,0,0,0.8); z-index: 2000; display: flex;
                align-items: center; justify-content: center;
            `;
            
            modal.innerHTML = `
                <div style="background: white; padding: 30px; border-radius: 8px; max-width: 700px; width: 90%;">
                    <h3 style="margin-top: 0; color: #dc3545;">🗑️ Batch-Löschung läuft...</h3>
                    <div style="margin: 15px 0; padding: 10px; background: #e3f2fd; border-radius: 4px;">
                        <strong>Modus:</strong> Ein Login für ${quotaDbIds.length} Quotas (effizienter)
                    </div>
                    <div id="batchDeleteProgress" style="margin: 20px 0;">
                        <div style="background: #f8f9fa; border-radius: 4px; padding: 10px; height: 350px; overflow-y: auto; font-family: monospace; font-size: 12px;"></div>
                    </div>
                    <div style="text-align: center;">
                        <button id="closeBatchDeleteModal" onclick="closeBatchDeleteProgressModal()" disabled style="
                            background: #6c757d; color: white; border: none; padding: 10px 20px; 
                            border-radius: 4px; cursor: not-allowed;">
                            Schließen (läuft...)
                        </button>
                    </div>
                </div>
            `;
            
            document.body.appendChild(modal);
            
            // Start batch deletion process
            startBatchDeletion(quotaDbIds);
        }
        
        function startBatchDeletion(quotaDbIds) {
            const progressDiv = document.querySelector('#batchDeleteProgress > div');
            const closeBtn = document.getElementById('closeBatchDeleteModal');
            
            progressDiv.innerHTML += `🚀 Starte Batch-Löschung für ${quotaDbIds.length} Quotas...\n`;
            progressDiv.scrollTop = progressDiv.scrollHeight;
            
            // Create FormData for POST request (like import pattern)
            const formData = new FormData();
            formData.append('quota_db_ids', JSON.stringify(quotaDbIds));
            
            // Call batch delete script
            fetch('../hrs/hrs_del_quota_batch.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(responseText => {
                // Parse multiple JSON responses (like in import)
                const lines = responseText.trim().split('\n');
                let summary = null;
                
                lines.forEach(line => {
                    if (line.trim()) {
                        try {
                            const result = JSON.parse(line);
                            
                            if (result.status === 'summary') {
                                summary = result;
                                progressDiv.innerHTML += `\n📊 ZUSAMMENFASSUNG:\n`;
                                progressDiv.innerHTML += `✅ Erfolgreich: ${result.success_count}\n`;
                                progressDiv.innerHTML += `❌ Fehler: ${result.error_count}\n`;
                                progressDiv.innerHTML += `📋 Gesamt: ${result.total_count}\n\n`;
                            } else if (result.status === 'success') {
                                progressDiv.innerHTML += `✅ ${result.message}\n`;
                            } else if (result.status === 'error') {
                                progressDiv.innerHTML += `❌ ${result.message}\n`;
                            } else if (result.status === 'info') {
                                progressDiv.innerHTML += `ℹ️ ${result.message}\n`;
                            } else if (result.status === 'complete') {
                                progressDiv.innerHTML += `\n🏁 ${result.message}\n`;
                                
                                // Enable close button
                                closeBtn.textContent = 'Fertig - Seite neu laden';
                                closeBtn.disabled = false;
                                closeBtn.onclick = () => window.location.reload();
                            }
                        } catch (e) {
                            // Ignore parse errors for status lines
                        }
                    }
                });
                
                progressDiv.scrollTop = progressDiv.scrollHeight;
                
                // If no summary was found, show basic completion
                if (!summary) {
                    progressDiv.innerHTML += `\n🏁 Batch-Löschung abgeschlossen\n`;
                    closeBtn.textContent = 'Schließen';
                    closeBtn.disabled = false;
                }
            })
            .catch(error => {
                progressDiv.innerHTML += `\n❌ Netzwerkfehler: ${error.message}\n`;
                progressDiv.scrollTop = progressDiv.scrollHeight;
                
                closeBtn.textContent = 'Fehler - Schließen';
                closeBtn.disabled = false;
            });
        }
        
        function closeBatchDeleteProgressModal() {
            const modal = document.getElementById('batchDeleteProgressModal');
            if (modal) {
                modal.remove();
            }
        }
        
        // Modal schließen beim Klick außerhalb
        document.getElementById('quotaChangesModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeQuotaChangesModal();
            }
        });
    </script>

    <!-- Detail Panel für Dry-Run Ergebnisse -->
    <div id="detailsPanel" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000;">
        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; width: 90%; max-width: 800px; max-height: 90%; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.3); overflow: hidden;">
            <div class="details-header" style="background: #f8f9fa; padding: 15px; border-bottom: 1px solid #ddd; font-weight: bold; font-size: 16px;">
                Details
                <button class="close-btn" onclick="hideDetailsPanel()" style="float: right; background: none; border: none; font-size: 18px; cursor: pointer;">✕ Schließen</button>
            </div>
            <div id="detailsContent" style="padding: 20px; max-height: 70vh; overflow-y: auto;">
                <!-- Content wird hier eingefügt -->
            </div>
        </div>
    </div>

</body>
</html>
