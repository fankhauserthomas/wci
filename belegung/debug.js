    <script>
        // Globale Variablen
        let chart;
        const detailData = <?= json_encode($detailDaten) ?>;
        const chartData = <?= json_encode($chartData) ?>;
        
        console.log('Debug: Chart Data Labels:', chartData.labels.length);
        console.log('Debug: Detail Data:', detailData.length, 'Einträge');
        
        // Chart initialisieren
        function initChart() {
            const ctx = document.getElementById('belegungChart').getContext('2d');
            
            // Cookie-Wert beim Laden wiederherstellen
            const savedTargetOccupancy = loadTargetOccupancyFromCookie();
            if (savedTargetOccupancy) {
                const targetInput = document.getElementById('targetOccupancy');
                if (targetInput) {
                    targetInput.value = savedTargetOccupancy;
                    console.log('Zielauslastung aus Cookie wiederhergestellt:', savedTargetOccupancy);
                }
            }
            
            chart = new Chart(ctx, {
                type: 'bar',
                data: chartData,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    backgroundColor: '#2F2F2F',
                    interaction: {
                        intersect: false,
                        mode: 'index'
                    },
                    scales: {
                        x: {
                            stacked: true,
                            title: { display: true, text: 'Datum', color: '#FFFFFF' },
                            ticks: { color: '#FFFFFF' },
                            grid: { color: '#555555' }
                        },
                        y: {
                            stacked: true,
                            beginAtZero: true,
                            title: { display: true, text: 'Anzahl Personen', color: '#FFFFFF' },
                            ticks: { color: '#FFFFFF' },
                            grid: { color: '#555555' }
                        }
                    },
                    plugins: {
                        title: {
                            display: true,
                            text: 'Tägliche Hausbelegung nach Kategorien und Herkunft (ohne Stornos)',
                            font: { size: 16 },
                            color: '#FFFFFF'
                        },
                        legend: {
                            display: true,
                            position: 'bottom',
                            labels: { usePointStyle: true, padding: 15, color: '#FFFFFF' }
                        },
                        tooltip: {
                            enabled: false,
                            external: function(context) {
                                showCustomTooltip(context);
                            }
                        },
                        zoom: {
                            zoom: {
                                wheel: {
                                    enabled: true,
                                    speed: 0.1
                                },
                                pinch: {
                                    enabled: true
                                },
                                mode: 'x',
                                onZoom: function({chart}) {
                                    updateChartWidth();
                                }
                            }
                        }
                    }
                },
                plugins: [
                    {
                        id: 'freeCapacityLabels',
                        afterDatasetsDraw: function(chart) {
                            const ctx = chart.ctx;
                            const meta = chart.getDatasetMeta(0);
                            
                            ctx.save();
                            ctx.fillStyle = '#FFFFFF';
                            ctx.font = 'bold 12px Arial';
                            ctx.textAlign = 'center';
                            ctx.textBaseline = 'bottom';
                            
                            meta.data.forEach((bar, index) => {
                                const freieKap = chartData.freieKapazitaeten[index];
                                if (freieKap && freieKap.gesamt_frei > 0) {
                                    const x = bar.x;
                                    // Finde den höchsten Punkt aller Balken (inklusive freie Plätze)
                                    let maxY = bar.y;
                                    chart.data.datasets.forEach((dataset, datasetIndex) => {
                                        const meta = chart.getDatasetMeta(datasetIndex);
                                        if (meta.data[index] && meta.data[index].y < maxY) {
                                            maxY = meta.data[index].y;
                                        }
                                    });
                                    
                                    const y = maxY - 10; // 10px über dem höchsten Segment
                                    
                                    // Hauptzahl (Gesamt frei)
                                    ctx.font = 'bold 12px Arial';
                                    ctx.fillText(freieKap.gesamt_frei, x, y);
                                    
                                    // Detailangaben (kleinere Schrift)
                                    ctx.font = '10px Arial';
                                    let detailText = '';
                                    if (freieKap.sonder_frei > 0) detailText += `S:${freieKap.sonder_frei} `;
                                    if (freieKap.lager_frei > 0) detailText += `L:${freieKap.lager_frei} `;
                                    if (freieKap.betten_frei > 0) detailText += `B:${freieKap.betten_frei} `;
                                    if (freieKap.dz_frei > 0) detailText += `D:${freieKap.dz_frei}`;
                                    
                                    if (detailText.trim()) {
                                        ctx.fillText(detailText.trim(), x, y);
                                    }
                                }
                            });
                            
                            ctx.restore();
                        }
                    },
                    {
                        id: 'targetOccupancyArrows',
                        afterDatasetsDraw: function(chart) {
                            console.log('=== PLUGIN AUFGERUFEN ===');
                            
                            // Prüfe ob Zielauslastung aktiv ist
                            const targetInput = document.getElementById('targetOccupancy');
                            console.log('TargetInput:', targetInput);
                            console.log('TargetInput Value:', targetInput ? targetInput.value : 'null');
                            
                            if (!targetInput || !targetInput.value) {
                                console.log('❌ Kein Zielauslastung-Wert gefunden');
                                return; // Keine Zielauslastung definiert
                            }
                            
                            const targetOccupancy = parseInt(targetInput.value);
                            if (isNaN(targetOccupancy) || targetOccupancy <= 0) {
                                console.log('❌ Ungültiger Zielauslastung-Wert:', targetInput.value);
                                return;
                            }
                            
                            console.log('✅ Plugin: Zeichne Pfeile für Zielauslastung', targetOccupancy);
                            
                            const ctx = chart.ctx;
                            const chartArea = chart.chartArea;
                            const yScale = chart.scales.y;
                            
                            let arrowsDrawn = 0;
                            
                            // Einfacher Test: Zeichne einen großen roten Pfeil
                            ctx.save();
                            ctx.strokeStyle = '#FF0000';
                            ctx.fillStyle = '#FF0000';
                            ctx.lineWidth = 10;
                            
                            // Großer Test-Pfeil in der Mitte
                            const midX = chartArea.left + chartArea.width / 2;
                            const topY = chartArea.top + 50;
                            const bottomY = chartArea.top + 150;
                            
                            // Vertikale Linie
                            ctx.beginPath();
                            ctx.moveTo(midX, topY);
                            ctx.lineTo(midX, bottomY);
                            ctx.stroke();
                            
                            // Pfeilspitze
                            ctx.beginPath();
                            ctx.moveTo(midX, bottomY);
                            ctx.lineTo(midX - 20, bottomY - 20);
                            ctx.lineTo(midX + 20, bottomY - 20);
                            ctx.closePath();
                            ctx.fill();
                            
                            ctx.restore();
                            console.log('🎯 TEST-PFEIL GEZEICHNET!');
                        }
                    }
            ],
            options: {
                responsive: true,
                maintainAspectRatio: false,
                backgroundColor: '#2F2F2F',
                interaction: {
                    intersect: false,
                    mode: 'index'
                },
                scales: {
                    x: {
                        stacked: true,
                        title: { display: true, text: 'Datum', color: '#FFFFFF' },
                        ticks: { color: '#FFFFFF' },
                        grid: { color: '#555555' }
                    },
                    y: {
                        stacked: true,
                        beginAtZero: true,
                        title: { display: true, text: 'Anzahl Personen', color: '#FFFFFF' },
                        ticks: { color: '#FFFFFF' },
                        grid: { color: '#555555' }
                    }
                },
                plugins: {
                    title: {
                        display: true,
                        text: 'Tägliche Hausbelegung nach Kategorien und Herkunft (ohne Stornos)',
                        font: { size: 16 },
                        color: '#FFFFFF'
                    },
                    legend: {
                        display: true,
                        position: 'bottom',
                        labels: { usePointStyle: true, padding: 15, color: '#FFFFFF' }
                    },
                    tooltip: {
                        enabled: false,
                        external: function(context) {
                            showCustomTooltip(context);
                        }
                    },
                    zoom: {
                        zoom: {
                            wheel: {
                                enabled: true,
                                speed: 0.1
                            },
                            pinch: {
                                enabled: true
                            },
                            mode: 'x',
                            onZoom: function({chart}) {
                                updateChartWidth();
                            }
                        }
                    }
                },
                onClick: function(event, elements) {
                    if (elements.length > 0) {
                        const dataIndex = elements[0].index;
                        showDayDetails(dataIndex);
                    }
                },
                onHover: function(event, elements) {
                    event.native.target.style.cursor = elements.length > 0 ? 'pointer' : 'default';
                }
            });
            
            // Chart-Breite basierend auf Anzahl der Datenpunkte anpassen
            updateChartWidth();
            
            // Event-Listener für Pfeil-Redraw nach Interaktionen
            console.log('Chart mit Mausrad-Zoom initialisiert');
        }
        
        // Chart-Breite dynamisch anpassen
        function updateChartWidth() {
            const chartWrapper = document.querySelector('.chart-wrapper');
            const dataPointCount = chartData.labels.length;
            const minBarWidth = 20; // Minimale Balkenbreite in Pixel
            const minWidth = Math.max(800, dataPointCount * minBarWidth);
            
            chartWrapper.style.minWidth = minWidth + 'px';
            
            if (chart) {
                chart.resize();
            }
        }
        
        // Custom Tooltip anzeigen
        function showCustomTooltip(context) {
            const tooltip = document.getElementById('customTooltip');
            const tooltipContent = document.getElementById('tooltipContent');
            
            if (context.tooltip.opacity === 0) {
                tooltip.style.display = 'none';
                return;
            }
            
            const tooltipModel = context.tooltip;
            let html = '';
            
            if (tooltipModel.dataPoints && tooltipModel.dataPoints.length > 0) {
                const dataIndex = tooltipModel.dataPoints[0].dataIndex;
                const datum = chartData.labels[dataIndex];
                
                let total = 0;
                tooltipModel.dataPoints.forEach(function(point) {
                    total += point.parsed.y;
                });
                
                html = `<div class="tooltip-header">${datum} - Total: ${total} Personen</div>`;
                
                tooltipModel.dataPoints.forEach(function(point) {
                    if (point.parsed.y > 0) {
                        const color = point.dataset.backgroundColor;
                        html += `
                            <div class="tooltip-item">
                                <div class="tooltip-color" style="background-color: ${color}"></div>
                                <div class="tooltip-label">${point.dataset.label}</div>
                                <div class="tooltip-value">${point.parsed.y}</div>
                            </div>
                        `;
                    }
                });
            }
            
            tooltipContent.innerHTML = html;
            tooltip.style.display = 'block';
        }
        
        // Tagesdetails anzeigen
        function showDayDetails(dayIndex) {
            const startDate = new Date('<?= $startDate ?>');
            const targetDate = new Date(startDate);
            targetDate.setDate(startDate.getDate() + dayIndex);
            const targetDateStr = targetDate.toISOString().split('T')[0];
            
            const dayDetails = detailData.filter(item => item.tag === targetDateStr);
            
            const detailsPanel = document.getElementById('detailsPanel');
            const detailsContent = document.getElementById('detailsContent');
            
            let html = '';
            if (dayDetails.length === 0) {
                html = '<p>Keine Reservierungen für diesen Tag.</p>';
            } else {
                dayDetails.forEach(reservation => {
                    const kategorie = [];
                    if (reservation.sonder > 0) kategorie.push(`Sonder: ${reservation.sonder}`);
                    if (reservation.lager > 0) kategorie.push(`Lager: ${reservation.lager}`);
                    if (reservation.betten > 0) kategorie.push(`Betten: ${reservation.betten}`);
                    if (reservation.dz > 0) kategorie.push(`DZ: ${reservation.dz}`);
                    
                    const badge = reservation.quelle === 'hrs' ? 
                        '<span class="hrs-badge">HRS</span>' : 
                        '<span class="local-badge">LOKAL</span>';
                    
                    html += `
                        <div class="reservation-item">
                            <div>
                                <div class="guest-info">${reservation.nachname}, ${reservation.vorname} ${badge}</div>
                                <div class="category-info">${kategorie.join(', ')}</div>
                                ${reservation.gruppe ? `<div class="category-info">Gruppe: ${reservation.gruppe}</div>` : ''}
                                ${reservation.bem_av ? `<div class="category-info">Bemerkung: ${reservation.bem_av}</div>` : ''}
                            </div>
                            <div>
                                ${reservation.hp ? '🍽️' : ''} ${reservation.vegi ? '🥬' : ''}
                            </div>
                        </div>
                    `;
                });
            }
            
            detailsContent.innerHTML = html;
            detailsPanel.style.display = 'block';
            detailsPanel.querySelector('.details-header').textContent = 
                `Reservierungen am ${targetDate.toLocaleDateString('de-DE')} (${dayDetails.length} Reservierungen)`;
        }
        
        // Chart aktualisieren
        function updateChart() {
            const startDate = document.getElementById('startDate').value;
            const endDate = document.getElementById('endDate').value;
            window.location.href = `?start=${startDate}&end=${endDate}`;
        }
        
        // Chart aktualisieren mit HRS Import
        function updateChartWithImport() {
            const startDate = document.getElementById('startDate').value;
            const endDate = document.getElementById('endDate').value;
            
            if (!startDate || !endDate) {
                alert('Bitte Start- und Enddatum auswählen.');
                return;
            }
            
            // Prüfe Zeitraum (max 31 Tage für Stabilität)
            const start = new Date(startDate);
            const end = new Date(endDate);
            const diffDays = Math.ceil((end - start) / (1000 * 60 * 60 * 24));
            
            if (diffDays > 31) {
                if (!confirm(`Zeitraum ist ${diffDays} Tage lang. HRS-Import kann bei langen Zeiträumen instabil sein.\n\nTrotzdem fortfahren?`)) {
                    return;
                }
            }
            
            // Button deaktivieren und Status anzeigen
            const btn = event.target;
            const originalText = btn.textContent;
            btn.disabled = true;
            btn.textContent = '⏳ Importiere HRS Daten...';
            btn.style.background = '#666';
            
            // Alle drei Importer parallel ausführen
            const importPromises = [
                fetch(`../hrs/hrs_imp_daily.php?from=${startDate}&to=${endDate}`)
                    .then(r => r.text())
                    .then(text => {
                        try {
                            const result = JSON.parse(text);
                            return { type: 'daily', ...result };
                        } catch (e) {
                            console.warn('Daily Import Response:', text);
                            return { type: 'daily', success: false, error: 'JSON Parse Error', rawResponse: text };
                        }
                    }),
                fetch(`../hrs/hrs_imp_quota.php?from=${startDate}&to=${endDate}`)
                    .then(r => r.text())
                    .then(text => {
                        try {
                            const result = JSON.parse(text);
                            return { type: 'quota', ...result };
                        } catch (e) {
                            console.warn('Quota Import Response:', text);
                            return { type: 'quota', success: false, error: 'JSON Parse Error', rawResponse: text };
                        }
                    }),
                fetch(`../hrs/hrs_imp_res.php?from=${startDate}&to=${endDate}`)
                    .then(r => r.text())
                    .then(text => {
                        try {
                            const result = JSON.parse(text);
                            return { type: 'res', ...result };
                        } catch (e) {
                            console.warn('Reservation Import Response:', text);
                            return { type: 'res', success: false, error: 'JSON Parse Error', rawResponse: text };
                        }
                    })
            ];
            
            Promise.all(importPromises)
                .then(results => {
                    let successMessage = '📊 HRS Import Ergebnisse:\n\n';
                    let hasErrors = false;
                    
                    results.forEach(result => {
                        const emoji = result.type === 'daily' ? '📊' : result.type === 'quota' ? '🏗️' : '🏠';
                        const name = result.type === 'daily' ? 'Daily Summary' : result.type === 'quota' ? 'Quotas' : 'Reservierungen';
                        
                        if (result.success) {
                            successMessage += `${emoji} ${name}: ✅ ${result.imported || 0} importiert, ${result.updated || 0} aktualisiert\n`;
                        } else {
                            hasErrors = true;
                            const errorMsg = result.error || 'Unbekannter Fehler';
                            successMessage += `${emoji} ${name}: ❌ ${errorMsg}\n`;
                            
                            // Spezielle Behandlung für HRS 500 Fehler
                            if (errorMsg.includes('HTTP 500') || errorMsg.includes('No quota data received')) {
                                successMessage += `    → HRS-Server Probleme, versuchen Sie kleineren Zeitraum\n`;
                            }
                        }
                    });
                    
                    if (hasErrors) {
                        successMessage += '\n⚠️ Einige Importer hatten Probleme. Details in der Browser-Konsole.';
                        successMessage += '\n💡 Tipp: Versuchen Sie einen kleineren Zeitraum (max 31 Tage).';
                    }
                    
                    alert(successMessage);
                    
                    // Seite neu laden mit aktualisierten Daten (auch bei teilweisen Fehlern)
                    window.location.href = `?start=${startDate}&end=${endDate}`;
                })
                .catch(error => {
                    console.error('Import Error:', error);
                    alert(`❌ HRS Import fehlgeschlagen:\n${error.message}\n\nDie Seite wird trotzdem aktualisiert, falls teilweise Daten importiert wurden.`);
                    
                    // Trotzdem versuchen zu aktualisieren
                    window.location.href = `?start=${startDate}&end=${endDate}`;
                })
                .finally(() => {
                    // Button wieder aktivieren (falls kein reload)
                    btn.disabled = false;
                    btn.textContent = originalText;
                    btn.style.background = '#2196F3';
                });
        }
        
        // Standardbereich zurücksetzen
        function resetToDefaultRange() {
            const today = new Date().toISOString().split('T')[0];
            const endDate = new Date();
            endDate.setDate(endDate.getDate() + 31);
            const endDateStr = endDate.toISOString().split('T')[0];
            
            document.getElementById('startDate').value = today;
            document.getElementById('endDate').value = endDateStr;
        }
        
        // CSV Export
        function exportToCSV() {
            const rows = [['Datum', 'Quelle', 'Name', 'Sonder', 'Lager', 'Betten', 'DZ', 'HP', 'Vegetarisch', 'Bemerkung']];
            
            detailData.forEach(item => {
                rows.push([
                    item.tag,
                    item.quelle.toUpperCase(),
                    `${item.nachname}, ${item.vorname}`,
                    item.sonder,
                    item.lager,
                    item.betten,
                    item.dz,
                    item.hp ? 'Ja' : 'Nein',
                    item.vegi ? 'Ja' : 'Nein',
                    item.bem_av || ''
                ]);
            });
            
            const csv = rows.map(row => row.map(field => `"${field}"`).join(',')).join('\n');
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `belegung_${new Date().toISOString().split('T')[0]}.csv`;
            a.click();
            URL.revokeObjectURL(url);
        }
        
        // PNG Export
        function exportChartAsPNG() {
            const link = document.createElement('a');
            link.download = `belegungsdiagramm_${new Date().toISOString().split('T')[0]}.png`;
            link.href = chart.toBase64Image();
            link.click();
        }
        
        // Cookie-Funktionen für Zielauslastung
        function saveTargetOccupancyToCookie(value) {
            const expiryDate = new Date();
            expiryDate.setTime(expiryDate.getTime() + (30 * 24 * 60 * 60 * 1000)); // 30 Tage
            document.cookie = `targetOccupancy=${value}; expires=${expiryDate.toUTCString()}; path=/; SameSite=Lax`;
            console.log('Zielauslastung in Cookie gespeichert:', value);
        }
        
        function loadTargetOccupancyFromCookie() {
            const cookies = document.cookie.split(';');
            for (let cookie of cookies) {
                const [name, value] = cookie.trim().split('=');
                if (name === 'targetOccupancy') {
                    console.log('Zielauslastung aus Cookie geladen:', value);
                    return value;
                }
            }
            return null;
        }
        
        function deleteTargetOccupancyCookie() {
            document.cookie = 'targetOccupancy=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/; SameSite=Lax';
            console.log('Zielauslastung Cookie gelöscht');
        }

        // Zielauslastung anwenden
        function updateTargetOccupancy() {
            const targetValue = parseInt(document.getElementById('targetOccupancy').value);
            
            if (!targetValue || targetValue < 0) {
                alert('Bitte geben Sie eine gültige Zielauslastung ein.');
                return;
            }
            
            console.log('Anwenden der Zielauslastung:', targetValue);
            
            // In Cookie speichern
            saveTargetOccupancyToCookie(targetValue);
            
            calculateAndShowTargetAdjustments(targetValue);
        }
        
        // Zielauslastung zurücksetzen
        function resetTargetOccupancy() {
            console.log('Zielauslastung zurücksetzen');
            
            // Y-Achse auf Auto zurücksetzen
            delete chart.options.scales.y.max;
            
            // Entferne alle Zielauslastungs-Elemente
            removeTargetLines();
            
            // Entferne Info-Panel
            const infoDiv = document.getElementById('targetInfo');
            if (infoDiv) {
                infoDiv.remove();
            }
            
            // Zielauslastung-Input zurücksetzen
            const targetInput = document.getElementById('targetOccupancy');
            if (targetInput) {
                targetInput.value = '';
            }
            
            // Cookie löschen
            deleteTargetOccupancyCookie();
            
            // Chart aktualisieren
            chart.update('none');
            
            console.log('Zielauslastung zurückgesetzt');
        }
        
        // Test-Funktion für Pfeile
        function testArrowDrawing() {
            console.log('Test: Zeichne Pfeile direkt');
            const ctx = chart.ctx;
            const chartArea = chart.chartArea;
            
            ctx.save();
            ctx.strokeStyle = '#FF0000';
            ctx.fillStyle = '#FF0000';
            ctx.lineWidth = 5;
            
            // Zeichne einen Test-Pfeil in der Mitte
            const x = chartArea.left + chartArea.width / 2;
            const y1 = chartArea.top + 50;
            const y2 = chartArea.top + 100;
            
            // Vertikale Linie
            ctx.beginPath();
            ctx.moveTo(x, y1);
            ctx.lineTo(x, y2);
            ctx.stroke();
            
            // Pfeilspitze
            ctx.beginPath();
            ctx.moveTo(x, y2);
            ctx.lineTo(x - 10, y2 - 15);
            ctx.lineTo(x + 10, y2 - 15);
            ctx.closePath();
            ctx.fill();
            
            ctx.restore();
            console.log('Test-Pfeil gezeichnet');
        }
        
        // Berechne Anpassungen für Zielauslastung
        function calculateAndShowTargetAdjustments(targetOccupancy) {
            const adjustments = [];
            let maxValue = targetOccupancy;
            
            console.log('=== NEUE ZIELAUSLASTUNG BERECHNUNG ===');
            console.log('Zielauslastung:', targetOccupancy);
            console.log('Anzahl Labels:', chartData.labels.length);
            console.log('Anzahl Datasets:', chartData.datasets.length);
            
            // Y-Achse komplett zurücksetzen
            delete chart.options.scales.y.max;
            chart.options.scales.y.beginAtZero = true;
            
            // Für jeden Tag die aktuelle Gesamtstapelhöhe berechnen
            chartData.labels.forEach((label, index) => {
                let currentTotal = 0;
                
                // NUR die ursprünglichen Belegungsdaten zählen (OHNE Zielauslastungs-Datasets)
                chartData.datasets.forEach((dataset, datasetIndex) => {
                    // Überspringe Zielauslastungs-Dataset
                    if (dataset.label === 'Zielauslastung') return;
                    
                    // Letztes Dataset ist "Freie Plätze" - das zählt zur Gesamtkapazität
                    const value = dataset.data[index] || 0;
                    currentTotal += value;
                    
                    console.log(`Tag ${index} - Dataset "${dataset.label}": ${value} (Gesamt: ${currentTotal})`);
                });
                
                // Maxwert für Y-Achse tracking (nur aus echten Daten, nicht aus Zielauslastung)
                const realMax = Math.max(currentTotal, targetOccupancy);
                maxValue = Math.max(maxValue, realMax);
                
                // Änderungswert berechnen
                const adjustmentValue = targetOccupancy - currentTotal;
                
                console.log(`Tag ${index} (${label}): Current=${currentTotal}, Target=${targetOccupancy}, Adjustment=${adjustmentValue}`);
                
                adjustments.push({
                    date: label,
                    currentTotal: currentTotal,
                    targetOccupancy: targetOccupancy,
                    adjustment: adjustmentValue,
                    canAdjust: adjustmentValue >= 0 // Negativ = nicht möglich
                });
            });
            
            // Y-Achse automatisch anpassen: (maxValue * 1.2) aufgerundet auf nächste 5
            const suggestedMax = Math.ceil((maxValue * 1.2) / 5) * 5;
            
            console.log('MaxValue aus Daten:', maxValue);
            console.log('Berechnete Y-Achse Max:', suggestedMax);
            
            // Chart Y-Achse aktualisieren
            chart.options.scales.y.max = suggestedMax;
            
            // Visualisierung der Anpassungen
            showTargetAdjustments(adjustments);
            
            console.log('=== BERECHNUNG ABGESCHLOSSEN ===');
            console.log('Finale Anpassungen:', adjustments);
        }
        
        // Zeige Zielauslastungs-Anpassungen im Chart
        function showTargetAdjustments(adjustments) {
            // Entferne vorherige Ziellinien
            removeTargetLines();
            
            // Erstelle neue Zielauslastungs-Linie
            if (!chart.data.datasets.find(d => d.label === 'Zielauslastung')) {
                const targetData = adjustments.map(adj => adj.targetOccupancy);
                
                chart.data.datasets.push({
                    label: 'Zielauslastung',
                    data: targetData,
                    type: 'line',
                    borderColor: '#FF6B35',
                    backgroundColor: 'transparent',
                    borderWidth: 3,
                    borderDash: [10, 5],
                    pointBackgroundColor: '#FF6B35',
                    pointBorderColor: '#FF6B35',
                    pointRadius: 5,
                    fill: false,
                    tension: 0,
                    order: -1 // Über allem anderen anzeigen
                });
            }
            
            chart.update('none'); // Update ohne Animation
            
            // Zeige Anpassungsinfo
            showAdjustmentInfo(adjustments);
        }
        
        // Entferne Ziellinien
        function removeTargetLines() {
            console.log('Entferne alte Zielauslastungs-Datasets');
            
            // Filtere alle Zielauslastungs-Datasets raus
            const originalDatasetCount = chart.data.datasets.length;
            chart.data.datasets = chart.data.datasets.filter(dataset => 
                dataset.label !== 'Zielauslastung'
            );
            
            console.log(`Datasets: ${originalDatasetCount} → ${chart.data.datasets.length}`);
            
            // Entferne gespeicherte Anpassungen
            window.targetAdjustments = null;
            
            // Chart neu zeichnen um alte Pfeile zu entfernen
            chart.update('none');
        }
        
        // Zeige Anpassungsinfo
        function showAdjustmentInfo(adjustments) {
            let infoHTML = '<div style="background: #f8f9fa; padding: 15px; margin: 10px 0; border-radius: 8px; border-left: 4px solid #007bff;">';
            infoHTML += '<h4>🎯 Zielauslastung Analyse</h4>';
            
            const totalAdjustments = adjustments.filter(adj => adj.adjustment !== 0).length;
            const positiveAdjustments = adjustments.filter(adj => adj.adjustment > 0).length;
            const negativeAdjustments = adjustments.filter(adj => adj.adjustment < 0).length;
            
            infoHTML += `<p><strong>Tage mit Anpassungsbedarf:</strong> ${totalAdjustments}</p>`;
            if (positiveAdjustments > 0) {
                infoHTML += `<p style="color: #28a745;"><strong>↗️ Kapazität erhöhen:</strong> ${positiveAdjustments} Tage</p>`;
            }
            if (negativeAdjustments > 0) {
                infoHTML += `<p style="color: #dc3545;"><strong>⚠️ Überbelegung:</strong> ${negativeAdjustments} Tage (Reduzierung nicht möglich)</p>`;
            }
            
            // Detailierte Aufschlüsselung der Anpassungen
            if (totalAdjustments > 0) {
                infoHTML += '<div style="margin-top: 15px; padding: 10px; background: #ffffff; border-radius: 4px;">';
                infoHTML += '<h5>📋 Anpassungen pro Tag:</h5>';
                infoHTML += '<div style="max-height: 200px; overflow-y: auto; font-size: 12px;">';
                
                adjustments.forEach(adj => {
                    if (adj.adjustment !== 0) {
                        const arrow = adj.adjustment > 0 ? '↗️' : '↘️';
                        const color = adj.canAdjust ? (adj.adjustment > 0 ? '#28a745' : '#dc3545') : '#dc3545';
                        infoHTML += `<div style="color: ${color}; margin: 2px 0;">`;
                        infoHTML += `${arrow} ${adj.date}: ${Math.abs(adj.adjustment)} Personen `;
                        infoHTML += `(${adj.currentTotal} → ${adj.targetOccupancy})`;
                        infoHTML += '</div>';
                    }
                });
                
                infoHTML += '</div></div>';
            }
            
            infoHTML += '<div style="margin-top: 10px;"><small>';
            infoHTML += '💡 <strong>Legende:</strong> Orange gestrichelte Linie = Zielauslastung | ';
            infoHTML += 'Grüne vertikale Pfeile = Kapazität erhöhen | ';
            infoHTML += 'Rote vertikale Pfeile = Überbelegung | ';
            infoHTML += 'Y-Achse automatisch angepasst: (Max * 1.2) mod 5';
            infoHTML += '</small></div>';
            infoHTML += '</div>';
            
            // Füge Info nach Chart ein
            const chartContainer = document.querySelector('.chart-container');
            let infoDiv = document.getElementById('targetInfo');
            if (!infoDiv) {
                infoDiv = document.createElement('div');
                infoDiv.id = 'targetInfo';
                chartContainer.insertAdjacentElement('afterend', infoDiv);
            }
            infoDiv.innerHTML = infoHTML;
        }
        
        // Einfacher Pfeil-Test
        function simpleArrowTest() {
            console.log('=== SIMPLE ARROW TEST START ===');
            
            // Setze Test-Wert in Input
            const targetInput = document.getElementById('targetOccupancy');
            targetInput.value = '100';
            
            console.log('Input gesetzt auf 100, triggere Chart Update...');
            
            // Chart Update forcieren
            chart.update();
            
