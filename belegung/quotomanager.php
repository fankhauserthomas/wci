<?php
/**
 * Quota Manager
 * Zentrale Verwaltung für Quotas, Import und Optimierung
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

// Quota-Daten laden für Dashboard
$quotaData = getQuotaData($mysqli, $startDate, $endDate);

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quota Manager</title>
    <link rel="stylesheet" href="style/belegung_tab.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <style>
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .dashboard-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-left: 4px solid #007cba;
        }
        .dashboard-card h3 {
            margin: 0 0 15px 0;
            color: #333;
            font-size: 18px;
        }
        .action-buttons {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .action-buttons button {
            padding: 12px 16px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        .btn-primary { background: #2196F3; color: white; }
        .btn-success { background: #4CAF50; color: white; }
        .btn-warning { background: #FF9800; color: white; }
        .btn-info { background: #17a2b8; color: white; }
        .btn-primary:hover { background: #1976D2; }
        .btn-success:hover { background: #45a049; }
        .btn-warning:hover { background: #e68900; }
        .btn-info:hover { background: #138496; }
        .status-info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 15px;
        }
        .status-info h4 {
            margin: 0 0 10px 0;
            color: #495057;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🎯 Quota Manager</h1>
        
        <!-- Zeitraum Kontrollen -->
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
                <button onclick="updateData()" class="btn-primary">Aktualisieren</button>
            </div>
        </div>

        <!-- Dashboard Grid -->
        <div class="dashboard-grid">
            
            <!-- HRS Import Karte -->
            <div class="dashboard-card">
                <h3>📥 HRS Import</h3>
                <div class="status-info">
                    <h4>Import Status</h4>
                    <p>Importiert HRS-Daten (Reservierungen, Daily Summaries, Quotas) für den gewählten Zeitraum.</p>
                </div>
                <div class="action-buttons">
                    <button onclick="importHRSData()" class="btn-primary" id="importBtn">
                        🔄 HRS Import starten
                    </button>
                </div>
                
                <!-- Progress Container für HRS Import -->
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
                    <div class="progress-status" id="progressStatus">Bereit für Import</div>
                </div>
            </div>

            <!-- WebImp Management Karte -->
            <div class="dashboard-card">
                <h3>🔄 WebImp Management</h3>
                <div class="status-info">
                    <h4>WebImp → Production</h4>
                    <p>Überträgt Daten aus der WebImp-Zwischentabelle in die Production-Datenbank.</p>
                </div>
                <div class="action-buttons">
                    <button onclick="importWebImpData(true)" class="btn-warning" id="dryRunBtn">
                        🔍 Dry-Run Test
                    </button>
                    <button onclick="importWebImpData()" class="btn-success" id="webimpBtn">
                        📂 WebImp → Production
                    </button>
                </div>
            </div>

            <!-- Quota Optimierung Karte -->
            <div class="dashboard-card">
                <h3>⚙️ Quota-Optimierung</h3>
                <div class="status-info">
                    <h4>Zielauslastung: <?= $zielauslastung ?>%</h4>
                    <p>Optimiert Quotas basierend auf Belegungsdaten und Zielauslastung.</p>
                </div>
                
                <!-- Quota Konfiguration -->
                <div style="margin-bottom: 15px;">
                    <div style="display: flex; gap: 10px; margin-bottom: 10px;">
                        <div>
                            <label for="quotaStartDate" style="font-size: 12px; font-weight: bold;">Von:</label>
                            <input type="date" id="quotaStartDate" value="<?= $startDate ?>" style="padding: 4px; font-size: 12px;">
                        </div>
                        <div>
                            <label for="quotaEndDate" style="font-size: 12px; font-weight: bold;">Bis:</label>
                            <input type="date" id="quotaEndDate" value="<?= $endDate ?>" style="padding: 4px; font-size: 12px;">
                        </div>
                    </div>
                    <div style="margin-bottom: 10px;">
                        <label for="zielauslastung" style="font-size: 12px; font-weight: bold;">Zielauslastung (%):</label>
                        <input type="number" id="zielauslastung" value="<?= $zielauslastung ?>" min="50" max="200" style="padding: 4px; margin-left: 5px; width: 80px; font-size: 12px;">
                    </div>
                </div>
                
                <div class="action-buttons">
                    <button onclick="applyQuotaOptimization()" class="btn-info">
                        🎯 Optimierung anwenden
                    </button>
                </div>
            </div>

            <!-- Status & Info Karte -->
            <div class="dashboard-card">
                <h3>📊 System Status</h3>
                <div class="status-info">
                    <h4>Aktueller Zeitraum</h4>
                    <p><strong>Von:</strong> <?= date('d.m.Y', strtotime($startDate)) ?></p>
                    <p><strong>Bis:</strong> <?= date('d.m.Y', strtotime($endDate)) ?></p>
                    <p><strong>Tage:</strong> <?= (strtotime($endDate) - strtotime($startDate)) / (60*60*24) + 1 ?></p>
                </div>
                
                <?php if (!empty($quotaData)): ?>
                <div class="status-info">
                    <h4>Quota Status</h4>
                    <p><strong>Quotas geladen:</strong> <?= count($quotaData) ?></p>
                    <p><strong>Letzte Aktualisierung:</strong> <?= date('d.m.Y H:i') ?></p>
                </div>
                <?php endif; ?>
                
                <div class="action-buttons">
                    <button onclick="window.location.href='belegung_tab.php?start=<?= $startDate ?>&end=<?= $endDate ?>&za=<?= $zielauslastung ?>'" class="btn-info">
                        📋 Zur Belegungstabelle
                    </button>
                </div>
            </div>
        </div>
    </div>

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

    <script>
        // === GRUNDFUNKTIONEN ===
        
        function updateData() {
            const startDate = document.getElementById('startDate').value;
            const endDate = document.getElementById('endDate').value;
            const za = document.getElementById('zielauslastung').value;
            
            if (!startDate || !endDate) {
                alert('Bitte wählen Sie gültige Start- und Enddaten aus.');
                return;
            }
            
            const params = new URLSearchParams();
            params.set('start', startDate);
            params.set('end', endDate);
            params.set('za', za);
            
            window.location.href = '?' + params.toString();
        }

        // === WEBIMP IMPORT FUNKTIONEN ===
        
        async function importWebImpData(dryRun = false) {
            const webimpBtn = document.getElementById('webimpBtn');
            const dryRunBtn = document.getElementById('dryRunBtn');
            
            if (!dryRun && !confirm('Sollen die Daten aus der WebImp-Zwischentabelle in die Production-Tabelle AV-Res importiert werden?\\n\\nDies überschreibt ggf. vorhandene Reservierungen mit gleicher AV-ID!')) {
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
                        let message = `✅ WebImp Import erfolgreich!\\n\\n`;
                        message += `Verarbeitet: ${data.total}\\n`;
                        message += `Neu eingefügt: ${data.inserted}\\n`;
                        message += `Aktualisiert: ${data.updated}\\n`;
                        message += `Unverändert: ${data.unchanged}\\n`;
                        
                        // Backup-Info anzeigen
                        if (data.backup_info) {
                            message += `\\n💾 ${data.backup_info}\\n`;
                        }
                        
                        if (!dryRun && data.sourceCleared) {
                            message += `\\n📝 Zwischentabelle wurde geleert`;
                        }
                        
                        if (data.errors && data.errors.length > 0) {
                            message += `\\n\\n⚠️ Warnungen (${data.errors.length}):\\n`;
                            data.errors.slice(0, 5).forEach(error => {
                                message += `- ${error}\\n`;
                            });
                            if (data.errors.length > 5) {
                                message += `... und ${data.errors.length - 5} weitere`;
                            }
                        }
                        
                        alert(message);
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

        // === HRS IMPORT FUNKTIONEN ===
        
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
            
            if (!confirm(`HRS Import für Zeitraum ${startDate} bis ${endDate} starten?\\n\\nDies kann einige Minuten dauern.`)) {
                return;
            }
            
            // UI Setup
            importBtn.disabled = true;
            importBtn.textContent = '⏳ Importiere...';
            progressContainer.style.display = 'block';
            
            // Reset aller Steps
            ['step1', 'step2', 'step3'].forEach(id => {
                document.getElementById(id).classList.remove('active', 'completed');
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
                
                progressStatus.textContent = '✅ Import abgeschlossen!';
                
                // Zusammenfassung anzeigen
                let summary = `✅ HRS Import abgeschlossen!\\n\\n`;
                summary += `📅 Zeitraum: ${startDate} bis ${endDate}\\n\\n`;
                summary += `📊 Ergebnisse:\\n`;
                summary += `- Reservierungen: ${resData.success ? 'Erfolg' : 'Fehler'}\\n`;
                summary += `- Daily Summaries: ${dailyData.success ? 'Erfolg' : 'Fehler'}\\n`;
                summary += `- Quotas: ${quotaData.success ? 'Erfolg' : 'Fehler'}\\n`;
                
                alert(summary);
                
            } catch (error) {
                console.error('HRS Import Fehler:', error);
                progressStatus.textContent = '❌ Fehler beim Import';
                alert('❌ Fehler beim HRS Import: ' + error.message);
            } finally {
                importBtn.disabled = false;
                importBtn.textContent = '🔄 HRS Import starten';
            }
        }

        // === DRY-RUN RESULTS ANZEIGE ===
        
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

        // === QUOTA-OPTIMIERUNG FUNKTIONEN ===
        
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
            
            // URL mit Parametern erstellen und zur Belegungstabelle wechseln
            const params = new URLSearchParams();
            params.set('start', quotaStartDate);
            params.set('end', quotaEndDate);
            params.set('za', za);
            
            // Zur Belegungstabelle wechseln mit den Optimierungsparametern
            window.location.href = 'belegung_tab.php?' + params.toString();
        }
    </script>
</body>
</html>
