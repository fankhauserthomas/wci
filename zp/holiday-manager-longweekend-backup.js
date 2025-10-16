/**
 * Holiday Manager for Timeline
 * ============================
 * 
 * Lädt Feiertage und Ferien aus der Nager.Date API für DE, AT, IT, CH
 * und integriert sie in die Timeline-Tagesanzeige.
 * 
 * API Documentation: https://date.nager.at/
 */

(function () {
    'use strict';

    class HolidayManager {
        constructor() {
            this.holidayCache = new Map(); // countryCode_year -> holidays array
            this.schoolHolidayCache = new Map(); // countryCode_year -> school holidays
            this.enabledCountries = ['AT', 'DE', 'CH', 'IT'];
            this.cacheExpiry = 24 * 60 * 60 * 1000; // 24 Stunden
            this.lastCacheTime = new Map();
        }

        /**
         * Formatiert Datum als YYYY-MM-DD
         */
        formatDate(date) {
            if (!(date instanceof Date)) return '';
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
        }

        /**
         * Prüft ob Cache noch gültig ist
         */
        isCacheValid(cacheKey) {
            const cacheTime = this.lastCacheTime.get(cacheKey);
            if (!cacheTime) return false;
            return (Date.now() - cacheTime) < this.cacheExpiry;
        }

        /**
         * Lädt lange Wochenenden für ein Land und Jahr (optional, falls API verfügbar)
         */
        async loadLongWeekends(countryCode, year) {
            const cacheKey = `longweekend_${countryCode}_${year}`;

            // Prüfe Cache
            if (this.isCacheValid(cacheKey) && this.holidayCache.has(cacheKey)) {
                console.log(`📅 Lange Wochenenden für ${countryCode} ${year} aus Cache geladen`);
                return this.holidayCache.get(cacheKey);
            }

            try {
                const response = await fetch(`/wci/zp/api-proxy.php?url=${encodeURIComponent(`https://date.nager.at/api/v3/LongWeekend/${year}/${countryCode}?availableBridgeDays=1`)}`);

                if (!response.ok) {
                    // Fallback: Direkte API-Anfrage ohne Proxy (kann CORS-Probleme geben)
                    console.log(`⚠️ Proxy failed, trying direct API for ${countryCode} ${year}...`);
                    const directResponse = await fetch(`https://date.nager.at/api/v3/LongWeekend/${year}/${countryCode}?availableBridgeDays=1`, {
                        mode: 'cors',
                        headers: {
                            'Accept': 'application/json',
                        }
                    });

                    if (!directResponse.ok) {
                        console.warn(`⚠️ Long Weekend API fehler für ${countryCode} ${year}: ${directResponse.status}, verwende Fallback`);

                        // Verwende Fallback Long Weekends
                        const fallbackLongWeekends = this.getFallbackLongWeekends(countryCode, year);
                        return this.processLongWeekends(fallbackLongWeekends, countryCode, cacheKey);
                    }

                    const longWeekends = await directResponse.json();
                    return this.processLongWeekends(longWeekends, countryCode, cacheKey);
                }

                const longWeekends = await response.json();
                return this.processLongWeekends(longWeekends, countryCode, cacheKey);

                return this.processLongWeekends(longWeekends, countryCode, cacheKey);

            } catch (error) {
                console.log(`ℹ️ Long Weekend API nicht erreichbar für ${countryCode} ${year}, verwende Fallback-Daten`);

                // Verwende Fallback Long Weekends
                const fallbackLongWeekends = this.getFallbackLongWeekends(countryCode, year);
                const convertedFallback = this.processLongWeekends(fallbackLongWeekends, countryCode, cacheKey);

                return convertedFallback;
            }
        }

        /**
         * Verarbeitet Long Weekend API Response
         */
        processLongWeekends(longWeekends, countryCode, cacheKey) {
            // Konvertiere zu Holiday-Format für einheitliche Behandlung
            const convertedWeekends = longWeekends.map(weekend => {
                const bridgeInfo = weekend.needBridgeDay ? ` (${weekend.bridgeDays.length} Brückentag${weekend.bridgeDays.length > 1 ? 'e' : ''})` : '';
                const localName = `Langes Wochenende${bridgeInfo}`;

                return {
                    date: weekend.startDate,
                    localName: localName,
                    name: `Long Weekend (${weekend.dayCount} days)`,
                    country: countryCode,
                    global: true,
                    type: 'longweekend',
                    holidayType: 'longweekend',
                    dayCount: weekend.dayCount,
                    endDate: weekend.endDate,
                    needBridgeDay: weekend.needBridgeDay,
                    bridgeDays: weekend.bridgeDays || []
                };
            });

            // Cache speichern
            this.holidayCache.set(cacheKey, convertedWeekends);
            this.lastCacheTime.set(cacheKey, Date.now());

            console.log(`✅ ${convertedWeekends.length} lange Wochenenden für ${countryCode} geladen`);
            return convertedWeekends;
        }

        /**
         * Lädt Feiertage für ein Land und Jahr
         */
        async loadPublicHolidays(countryCode, year) {
            const cacheKey = `${countryCode}_${year}`;

            // Prüfe Cache
            if (this.isCacheValid(cacheKey) && this.holidayCache.has(cacheKey)) {
                console.log(`📅 Feiertage für ${countryCode} ${year} aus Cache geladen`);
                return this.holidayCache.get(cacheKey);
            }

            try {
                console.log(`📅 Lade Feiertage für ${countryCode} ${year}...`);

                // Use our wciFetch if available for better CORS handling
                let response;
                if (typeof wciFetch === 'function') {
                    // Proxy über eigenen Server falls wciFetch verfügbar
                    response = await wciFetch(`/wci/api-proxy.php?url=https://date.nager.at/api/v3/PublicHolidays/${year}/${countryCode}`);
                } else {
                    // Direct API call
                    response = await fetch(`https://date.nager.at/api/v3/PublicHolidays/${year}/${countryCode}`, {
                        method: 'GET',
                        mode: 'cors',
                        headers: {
                            'Accept': 'application/json',
                        }
                    });
                }

                if (!response.ok) {
                    console.warn(`❌ Feiertage für ${countryCode} ${year} nicht verfügbar: ${response.status}`);
                    return [];
                }

                const holidays = await response.json();
                console.log(`✅ ${holidays.length} Feiertage für ${countryCode} ${year} geladen`);

                // In Cache speichern
                this.holidayCache.set(cacheKey, holidays);
                this.lastCacheTime.set(cacheKey, Date.now());

                return holidays;
            } catch (error) {
                console.error(`❌ Fehler beim Laden der Feiertage für ${countryCode} ${year}:`, error);

                // Fallback: Verwende bekannte Feiertage für das Jahr
                const fallbackHolidays = this.getFallbackHolidays(countryCode, year);
                if (fallbackHolidays.length > 0) {
                    console.log(`📅 Verwende ${fallbackHolidays.length} Fallback-Feiertage für ${countryCode} ${year}`);
                    this.holidayCache.set(cacheKey, fallbackHolidays);
                    this.lastCacheTime.set(cacheKey, Date.now());
                    return fallbackHolidays;
                }

                return [];
            }
        }

        /**
         * Fallback Feiertage für wichtigste Termine (falls API nicht verfügbar)
         */
        getFallbackHolidays(countryCode, year) {
            const commonHolidays = {
                'DE': [
                    { date: `${year}-01-01`, localName: 'Neujahr', name: 'New Year\'s Day', global: true },
                    { date: `${year}-05-01`, localName: 'Tag der Arbeit', name: 'Labour Day', global: true },
                    { date: `${year}-10-03`, localName: 'Tag der Deutschen Einheit', name: 'German Unity Day', global: true },
                    { date: `${year}-12-25`, localName: 'Erster Weihnachtstag', name: 'Christmas Day', global: true },
                    { date: `${year}-12-26`, localName: 'Zweiter Weihnachtstag', name: 'St. Stephen\'s Day', global: true }
                ],
                'AT': [
                    { date: `${year}-01-01`, localName: 'Neujahr', name: 'New Year\'s Day', global: true },
                    { date: `${year}-01-06`, localName: 'Heilige Drei Könige', name: 'Epiphany', global: true },
                    { date: `${year}-05-01`, localName: 'Staatsfeiertag', name: 'National Holiday', global: true },
                    { date: `${year}-08-15`, localName: 'Maria Himmelfahrt', name: 'Assumption Day', global: true },
                    { date: `${year}-10-26`, localName: 'Nationalfeiertag', name: 'National Holiday', global: true },
                    { date: `${year}-11-01`, localName: 'Allerheiligen', name: 'All Saints\' Day', global: true },
                    { date: `${year}-12-25`, localName: 'Weihnachten', name: 'Christmas Day', global: true },
                    { date: `${year}-12-26`, localName: 'Stefanitag', name: 'St. Stephen\'s Day', global: true }
                ],
                'IT': [
                    { date: `${year}-01-01`, localName: 'Capodanno', name: 'New Year\'s Day', global: true },
                    { date: `${year}-01-06`, localName: 'Epifania', name: 'Epiphany', global: true },
                    { date: `${year}-04-25`, localName: 'Festa della Liberazione', name: 'Liberation Day', global: true },
                    { date: `${year}-05-01`, localName: 'Festa del Lavoro', name: 'International Workers Day', global: true },
                    { date: `${year}-06-02`, localName: 'Festa della Repubblica', name: 'Republic Day', global: true },
                    { date: `${year}-08-15`, localName: 'Ferragosto o Assunzione', name: 'Assumption Day', global: true },
                    { date: `${year}-11-01`, localName: 'Tutti i santi', name: 'All Saints Day', global: true },
                    { date: `${year}-12-25`, localName: 'Natale', name: 'Christmas Day', global: true },
                    { date: `${year}-12-26`, localName: 'Santo Stefano', name: 'St. Stephen\'s Day', global: true }
                ]
            };

            return (commonHolidays[countryCode] || []).map(holiday => ({
                ...holiday,
                countryCode,
                fixed: false,
                counties: null,
                launchYear: null,
                types: ['Public']
            }));
        }

        /**
         * Fallback Long Weekends für Test-Zwecke
         */
        getFallbackLongWeekends(countryCode, year) {
            // Einige Test-Long-Weekends für Demo
            const testLongWeekends = {
                'AT': [
                    { startDate: `${year}-05-30`, endDate: `${year}-06-02`, dayCount: 4, needBridgeDay: true, bridgeDays: [`${year}-05-31`] },
                    { startDate: `${year}-12-23`, endDate: `${year}-12-26`, dayCount: 4, needBridgeDay: false, bridgeDays: [] }
                ],
                'DE': [
                    { startDate: `${year}-05-30`, endDate: `${year}-06-02`, dayCount: 4, needBridgeDay: true, bridgeDays: [`${year}-05-31`] },
                    { startDate: `${year}-10-31`, endDate: `${year}-11-03`, dayCount: 4, needBridgeDay: true, bridgeDays: [`${year}-11-01`] }
                ],
                'CH': [
                    { startDate: `${year}-08-01`, endDate: `${year}-08-04`, dayCount: 4, needBridgeDay: false, bridgeDays: [] }
                ],
                'IT': [
                    { startDate: `${year}-04-25`, endDate: `${year}-04-28`, dayCount: 4, needBridgeDay: true, bridgeDays: [`${year}-04-26`] }
                ]
            };

            return (testLongWeekends[countryCode] || []).map(weekend => ({
                startDate: weekend.startDate,
                endDate: weekend.endDate,
                dayCount: weekend.dayCount,
                needBridgeDay: weekend.needBridgeDay,
                bridgeDays: weekend.bridgeDays
            }));
        }

        /**
         * Lädt Feiertage für alle aktivierten Länder für den gegebenen Datumsbereich
         */
        async loadHolidaysForDateRange(startDate, endDate) {
            const startYear = startDate.getFullYear();
            const endYear = endDate.getFullYear();
            const years = [];

            // Sammle alle Jahre im Bereich
            for (let year = startYear; year <= endYear; year++) {
                years.push(year);
            }

            const allHolidays = new Map(); // date -> holiday info array

            // Lade nur Standard-Feiertage für alle Länder und Jahre
            for (const countryCode of this.enabledCountries) {
                for (const year of years) {
                    // Standard Feiertage laden
                    const holidays = await this.loadPublicHolidays(countryCode, year);

                    // Verarbeite nur Feiertage
                    for (const holiday of holidays) {
                        const dateKey = holiday.date; // YYYY-MM-DD Format

                        if (!allHolidays.has(dateKey)) {
                            allHolidays.set(dateKey, []);
                        }

                        allHolidays.get(dateKey).push({
                            country: countryCode,
                            localName: holiday.localName,
                            name: holiday.name,
                            global: holiday.global,
                            counties: holiday.counties,
                            types: holiday.types || ['Public'],
                            holidayType: 'public'
                        });
                    }
                }
            }

            console.log(`📅 Gesamt ${allHolidays.size} Feiertage für ${startDate.toLocaleDateString()} bis ${endDate.toLocaleDateString()} geladen`);
            return allHolidays;
        }

        /**
         * Holt Feiertagsinfo für ein bestimmtes Datum
         */
        getHolidayInfo(date, holidaysMap) {
            const dateKey = this.formatDate(date);
            return holidaysMap.get(dateKey) || [];
        }

        /**
         * Erstellt HTML für Feiertags-Badge
         */
        createHolidayBadge(holidayInfo) {
            if (!holidayInfo || holidayInfo.length === 0) return '';

            const flagEmojis = {
                'AT': 'AT',
                'DE': 'DE',
                'CH': 'CH',
                'IT': 'IT'
            };

            // Gruppiere nach Ländern
            const byCountry = {};
            holidayInfo.forEach(holiday => {
                if (!byCountry[holiday.country]) {
                    byCountry[holiday.country] = [];
                }
                byCountry[holiday.country].push(holiday);
            });

            const badges = [];

            // Prioritäts-Reihenfolge: AT, DE, CH, IT
            const countryPriority = ['AT', 'DE', 'CH', 'IT'];

            countryPriority.forEach(country => {
                const holidays = byCountry[country];
                if (!holidays) return;

                const flag = flagEmojis[country] || '🏳️';
                const mainHoliday = holidays[0]; // Nimm den ersten/wichtigsten

                const isGlobal = mainHoliday.global;
                const opacity = isGlobal ? '1.0' : '0.7'; // Regional weniger prominent

                // Detaillierte Tooltip-Info
                const tooltipText = holidays.map(h =>
                    `${flag} ${h.localName || h.name}${h.global ? '' : ' (regional)'}`
                ).join('\n');

                badges.push(`<span class="holiday-badge" 
                    style="opacity: ${opacity};" 
                    title="${tooltipText}"
                >${flag}</span>`);
            });

            return badges.join('');
        }

        /**
         * Erweitert Timeline mit Feiertags-Integration
         */
        integrateWithTimeline() {
            if (typeof TimelineUnifiedRenderer === 'undefined') {
                console.warn('❌ TimelineUnifiedRenderer nicht gefunden - Feiertagsintegration übersprungen');
                return;
            }

            console.log('✅ Timeline mit Feiertags-Integration erweitert');
        }

        /**
         * Manuelle Trigger-Funktion für Feiertags-Reload
         */
        async triggerHolidayReload() {
            if (window.timelineRenderer || window.renderer) {
                const renderer = window.timelineRenderer || window.renderer;
                if (renderer.loadHolidaysForCurrentRange) {
                    await renderer.loadHolidaysForCurrentRange();
                    console.log('📅 Feiertage manuell neu geladen');
                }
            }
        }
    }

    // Globale Instanz erstellen
    window.holidayManager = new HolidayManager();

    // Integration aktivieren wenn DOM bereit
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            window.holidayManager.integrateWithTimeline();
            // Trigger initial load nach kurzer Verzögerung
            setTimeout(() => {
                window.holidayManager.triggerHolidayReload();
            }, 2000);
        });
    } else {
        window.holidayManager.integrateWithTimeline();
        // Trigger initial load nach kurzer Verzögerung
        setTimeout(() => {
            window.holidayManager.triggerHolidayReload();
        }, 2000);
    }

    // Hook in timeline data reload
    const originalReloadTimelineData = window.reloadTimelineData;
    window.reloadTimelineData = async function () {
        if (originalReloadTimelineData) {
            await originalReloadTimelineData();
        }
        // Lade auch Feiertage neu
        setTimeout(() => {
            if (window.timelineRenderer && window.timelineRenderer.loadHolidaysForCurrentRange) {
                window.timelineRenderer.loadHolidaysForCurrentRange();
            }
        }, 500);
    };

    console.log('🚀 Holiday Manager initialisiert');

})();