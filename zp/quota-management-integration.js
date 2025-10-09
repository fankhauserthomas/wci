/**
 * Quota Management Integration for Timeline
 * ==========================================
 * 
 * Erweitert timeline-unified.js mit Quota-Management-Features:
 * - Context-Menu für selektierte Histogram-Tage
 * - "Quota bearbeiten" Button
 * - Reload Histogram nach Quota-Update
 * 
 * VERWENDUNG:
 * Füge dieses Script NACH timeline-unified.js ein:
 * <script src="timeline-unified.js"></script>
 * <script src="quota-management-integration.js"></script>
 */

(function () {
    'use strict';

    /**
     * Erweitert TimelineUnifiedRenderer mit Quota-Management
     */
    function extendRendererWithQuotaManagement() {
        if (typeof TimelineUnifiedRenderer === 'undefined') {
            console.error('❌ TimelineUnifiedRenderer nicht gefunden!');
            return;
        }

        const proto = TimelineUnifiedRenderer.prototype;

        // Speichere Original handleMouseDown
        const originalHandleMouseDown = proto.handleMouseDown;

        /**
         * Erweitere handleMouseDown um Context-Menu Check
         */
        proto.handleMouseDown = function (e) {
            // Rechtsklick auf Histogram → Context-Menu
            if (e.button === 2 && this.selectedHistogramDays && this.selectedHistogramDays.size > 0) {
                const rect = this.canvas.getBoundingClientRect();
                const mouseY = e.clientY - rect.top;

                // Prüfe ob Klick im Histogram-Bereich
                if (mouseY >= this.areas.histogram.y &&
                    mouseY <= this.areas.histogram.y + this.areas.histogram.height) {

                    e.preventDefault();
                    this.showQuotaContextMenu(e.clientX, e.clientY);
                    return;
                }
            }

            // Original-Funktion aufrufen
            return originalHandleMouseDown.call(this, e);
        };

        /**
         * Zeigt Context-Menu für Quota-Bearbeitung
         */
        proto.showQuotaContextMenu = function (x, y) {
            // Entferne existierendes Menu
            const existingMenu = document.getElementById('quotaContextMenu');
            if (existingMenu) {
                existingMenu.remove();
            }

            // Erstelle Menu
            const menu = document.createElement('div');
            menu.id = 'quotaContextMenu';
            menu.style.cssText = `
                position: fixed;
                left: ${x}px;
                top: ${y}px;
                background: linear-gradient(135deg, #1e1e1e 0%, #2d2d2d 100%);
                border: 1px solid #444;
                border-radius: 6px;
                padding: 8px 0;
                box-shadow: 0 4px 12px rgba(0,0,0,0.5);
                z-index: 10000;
                min-width: 200px;
            `;

            const selectedCount = this.selectedHistogramDays.size;

            // Button: Quota bearbeiten
            const quotaButton = document.createElement('button');
            quotaButton.innerHTML = `📊 Quota bearbeiten (${selectedCount} Tage)`;
            quotaButton.style.cssText = `
                width: 100%;
                padding: 8px 16px;
                background: none;
                border: none;
                color: #fff;
                text-align: left;
                cursor: pointer;
                font-size: 14px;
            `;
            quotaButton.onmouseover = () => quotaButton.style.background = 'rgba(255,255,255,0.1)';
            quotaButton.onmouseout = () => quotaButton.style.background = 'none';
            quotaButton.onclick = () => {
                this.openQuotaModal();
                menu.remove();
            };

            menu.appendChild(quotaButton);

            // Button: Selektion aufheben
            const clearButton = document.createElement('button');
            clearButton.innerHTML = `❌ Selektion aufheben`;
            clearButton.style.cssText = quotaButton.style.cssText;
            clearButton.onmouseover = () => clearButton.style.background = 'rgba(255,255,255,0.1)';
            clearButton.onmouseout = () => clearButton.style.background = 'none';
            clearButton.onclick = () => {
                this.selectedHistogramDays.clear();
                this.scheduleRender('clear_selection');
                menu.remove();
            };

            menu.appendChild(clearButton);

            document.body.appendChild(menu);

            // Schließe Menu bei Klick außerhalb
            const closeMenu = (e) => {
                if (!menu.contains(e.target)) {
                    menu.remove();
                    document.removeEventListener('click', closeMenu);
                }
            };

            setTimeout(() => document.addEventListener('click', closeMenu), 10);
        };

        /**
         * Öffnet Quota-Modal
         */
        proto.openQuotaModal = function () {
            if (!quotaInputModal) {
                console.error('❌ Quota Input Modal nicht initialisiert!');
                alert('Quota-Modal nicht verfügbar. Bitte Seite neu laden.');
                return;
            }

            // selectedHistogramDays enthält TAG-INDIZES, keine Timestamps!
            // Wir müssen diese zu Datums-Strings konvertieren
            const { startDate } = this.getTimelineDateRange();

            const selectedDays = Array.from(this.selectedHistogramDays)
                .sort((a, b) => a - b)
                .map(dayIndex => {
                    // dayIndex ist die Anzahl Tage seit startDate
                    const date = new Date(startDate);
                    date.setDate(date.getDate() + dayIndex);

                    const year = date.getFullYear();
                    const month = String(date.getMonth() + 1).padStart(2, '0');
                    const day = String(date.getDate()).padStart(2, '0');
                    const formatted = `${year}-${month}-${day}`;

                    console.log('📅 DayIndex:', dayIndex, '→ Date:', date.toLocaleDateString('de-DE'), '→ Formatted:', formatted);
                    return formatted;
                });

            console.log('🗓️ Selektierte Tage (formatiert):', selectedDays);

            if (selectedDays.length === 0) {
                alert('❌ Keine Tage selektiert!');
                return;
            }

            // Prüfe ob Modal bereit ist
            if (!window.quotaInputModal) {
                alert('❌ Quota Modal noch nicht bereit. Bitte warten...');
                console.error('quotaInputModal not initialized yet');
                return;
            }

            // Öffne Modal
            window.quotaInputModal.show(selectedDays);
        };

        /**
         * Lädt Histogram-Daten neu
         */
        proto.reloadHistogramData = async function () {
            console.log('🔄 Lade Histogram-Daten neu...');

            try {
                const { startDate, endDate } = this.getTimelineDateRange();
                const startDateStr = this.formatDate(startDate);
                const endDateStr = this.formatDate(endDate);

                const cacheBuster = Date.now();
                const response = await fetch(`/wci/zp/getHistogramSource.php?start=${startDateStr}&end=${endDateStr}&cb=${cacheBuster}`);

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }

                const data = await response.json();

                if (!data.success) {
                    throw new Error(data.error || 'API-Fehler');
                }

                // Update internal data
                this.setHistogramSource(
                    data.data.histogram,
                    data.data.storno,
                    data.data.availability
                );

                // Re-render
                this.scheduleRender('quota_updated');

                console.log('✅ Histogram-Daten aktualisiert');

            } catch (error) {
                console.error('❌ Fehler beim Reload:', error);
                throw error;
            }
        };

        /**
         * Helper: Formatiert Date zu YYYY-MM-DD
         */
        proto.formatDate = function (date) {
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
        };

        /**
         * Helper: Ermittelt Zeitraum
         */
        /**
         * Helper: Get Timeline Date Range (cached)
         */
        proto.getTimelineDateRange = function () {
            // Verwende gecachte Werte falls vorhanden
            if (this.cachedDateRange) {
                return this.cachedDateRange;
            }

            const today = new Date();
            today.setHours(0, 0, 0, 0);

            const weekRange = this.weekRange || { past: 4, future: 52 };

            const startDate = new Date(today);
            startDate.setDate(startDate.getDate() - (weekRange.past * 7));

            const endDate = new Date(today);
            endDate.setDate(endDate.getDate() + (weekRange.future * 7));

            // Cache für konsistente Abfragen
            this.cachedDateRange = { startDate, endDate };

            return { startDate, endDate };
        };

        console.log('✅ Timeline erweitert mit Quota-Management');
    }

    /**
     * Unterdrücke Browser-Kontextmenü auf Canvas wenn Histogram-Tage selektiert
     */
    function suppressContextMenuOnHistogram() {
        document.addEventListener('contextmenu', function (e) {
            // Prüfe ob Event vom Timeline-Canvas kommt
            if (e.target.tagName === 'CANVAS' && e.target.closest('.main-areas-container')) {
                // Prüfe ob renderer existiert und Tage selektiert sind
                if (window.renderer && window.renderer.selectedHistogramDays &&
                    window.renderer.selectedHistogramDays.size > 0) {

                    const rect = e.target.getBoundingClientRect();
                    const mouseY = e.clientY - rect.top;

                    // Prüfe ob Klick im Histogram-Bereich (grobe Prüfung)
                    if (window.renderer.areas && window.renderer.areas.histogram) {
                        const histogramArea = window.renderer.areas.histogram;
                        if (mouseY >= histogramArea.y &&
                            mouseY <= histogramArea.y + histogramArea.height) {
                            e.preventDefault(); // Unterdrücke Browser-Kontextmenü
                            return false;
                        }
                    }
                }
            }
        });
        console.log('✅ Browser-Kontextmenü wird bei Histogram-Selektion unterdrückt');
    }

    // Warte auf DOMContentLoaded
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            extendRendererWithQuotaManagement();
            suppressContextMenuOnHistogram();
        });
    } else {
        extendRendererWithQuotaManagement();
        suppressContextMenuOnHistogram();
    }

})();
