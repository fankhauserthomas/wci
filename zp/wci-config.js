/**
 * WCI Configuration Manager
 * ========================
 * 
 * Zentrale JavaScript-Konfiguration für das Timeline-System.
 * Lädt alle Konfigurationsdaten vom Server und stellt sie systemweit zur Verfügung.
 * 
 * Verwendung:
 * -----------
 * await WCIConfig.init();
 * const baseUrl = WCIConfig.get('urls.base');
 * const hutId = WCIConfig.get('hut.id');
 * const endpoint = WCIConfig.getEndpoint('rooms');
 * 
 * @author Timeline System Refactoring 2025
 * @version 1.0.0
 */

class WCIConfigManager {
    constructor() {
        this.config = null;
        this.initialized = false;
        this.initPromise = null;
    }

    /**
     * Initialisiert die Konfiguration vom Server
     * @returns {Promise<boolean>} Success status
     */
    async init() {
        if (this.initialized) {
            return true;
        }

        // Prevent multiple simultaneous init calls
        if (this.initPromise) {
            return this.initPromise;
        }

        this.initPromise = this._loadConfig();
        return this.initPromise;
    }

    /**
     * Lädt Konfiguration vom getConfig.php Endpoint
     * @private
     */
    async _loadConfig() {
        try {
            console.log('🔧 WCIConfig: Loading configuration from server...');

            // Try to determine base path dynamically
            const currentPath = window.location.pathname;
            const wciPath = currentPath.includes('/wci/') ?
                currentPath.substring(0, currentPath.indexOf('/wci/') + 4) :
                '/wci';

            const configUrl = `${wciPath}/zp/getConfig.php`;

            const response = await fetch(configUrl);

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const data = await response.json();

            if (!data.success) {
                throw new Error(data.error || 'Configuration load failed');
            }

            this.config = data.config;
            this.initialized = true;

            console.log('✅ WCIConfig: Configuration loaded successfully', this.config);
            return true;

        } catch (error) {
            console.error('❌ WCIConfig: Failed to load configuration:', error);

            // Fallback configuration for development
            console.warn('🔄 WCIConfig: Using fallback configuration');
            this.config = this._getFallbackConfig();
            this.initialized = true;

            return false;
        }
    }

    /**
     * Fallback-Konfiguration für Entwicklung/Notfall
     * @private
     */
    _getFallbackConfig() {
        return {
            hut: {
                id: 675,
                name: 'Franzsennhütte',
                short: 'FSH'
            },
            urls: {
                base: 'http://192.168.15.14:8080',
                fallback: 'http://192.168.15.14:8080',
                wci: 'http://192.168.15.14:8080/wci',
                zp: 'http://192.168.15.14:8080/wci/zp',
                reservations: 'http://192.168.15.14:8080/wci/reservierungen',
                pictures: 'http://192.168.15.14:8080/wci/pic',
                hrs: 'http://192.168.15.14:8080/wci/hrs',
                api: 'http://192.168.15.14:8080/wci/api'
            },
            paths: {
                wci: '/wci',
                zp: '/wci/zp',
                reservations: '/wci/reservierungen',
                pictures: '/wci/pic',
                hrs: '/wci/hrs',
                api: '/wci/api'
            },
            hrs: {
                base_url: 'https://www.hut-reservation.org',
                hut_id: 675,
                api_base: '/api/v1'
            },
            timeline: {
                default_room_height: 40,
                default_day_width: 60,
                master_bar_height: 14,
                max_occupancy_days: 365,
                default_timeline_days: 30
            },
            endpoints: {
                // Standard API-Endpunkte
                rooms: '/wci/zp/getRooms.php',
                av_reservation_data: '/wci/zp/getAVReservationData.php',
                arrangements: '/wci/zp/getArrangements.php',
                origins: '/wci/zp/getOrigins.php',
                countries: '/wci/zp/getCountries.php',

                // Timeline-Endpunkte
                updateRoomDetail: '/wci/zp/updateRoomDetail.php',
                updateRoomDetailAttributes: '/wci/zp/updateRoomDetailAttributes.php',
                updateReservationMasterData: '/wci/zp/updateReservationMasterData.php',
                getReservationMasterData: '/wci/zp/getReservationMasterData.php',
                assignRoomsToReservation: '/wci/zp/assignRoomsToReservation.php',
                quotaInputModal: '/wci/zp/quota-input-modal.html',
                getArrangementsRoot: '/wci/get-arrangements.php',

                // Reservierungen API-Endpunkte  
                splitReservationDetail: '/wci/reservierungen/api/splitReservationDetail.php',
                splitReservationByDate: '/wci/reservierungen/api/splitReservationByDate.php',
                deleteReservationDetail: '/wci/reservierungen/api/deleteReservationDetail.php',
                deleteReservationAllDetails: '/wci/reservierungen/api/deleteReservationAllDetails.php',
                updateReservationDesignation: '/wci/reservierungen/api/updateReservationDesignation.php',

                // HRS Import-Endpunkte
                hrsImportDaily: '/wci/hrs/hrs_imp_daily_stream.php',
                hrsImportQuota: '/wci/hrs/hrs_imp_quota_stream.php',
                hrsImportReservations: '/wci/hrs/hrs_imp_res_stream.php',
                hrsWriteQuota: '/wci/hrs/hrs_write_quota_v3.php',

                // AV API-Endpunkte
                avCapacityRange: '/wci/api/imps/get_av_cap_range_stream.php',

                // Assets/Bilder
                cautionIcon: '/wci/pic/caution.svg',
                dogIcon: '/wci/pic/DogProfile.svg',
                dogProfile: '/wci/pic/dog.svg',
                leaveIcon: '/wci/pic/leave.png',
                dogPng: '/wci/pic/DogProfile.png'
            }
        };
    }

    /**
     * Ruft API-Endpunkt-URL ab (kombiniert base URL mit Endpunkt-Pfad)
     * @param {string} endpointName - Name des Endpunkts
     * @param {Object} params - Query-Parameter als Objekt
     * @returns {string} Vollständige URL zum Endpunkt
     */
    getEndpoint(endpointName, params = {}) {
        if (!this.initialized) {
            console.warn(`🔄 WCIConfig: Configuration not initialized – cannot resolve endpoint '${endpointName}'`);
            return null;
        }

        const endpointPath = this.get(`endpoints.${endpointName}`);
        if (!endpointPath) {
            console.warn(`🔄 WCIConfig: Endpoint '${endpointName}' not found`);
            return null;
        }

        const baseUrl = this.get('urls.base');
        let url = baseUrl + endpointPath;

        // Query-Parameter hinzufügen
        if (Object.keys(params).length > 0) {
            const queryString = new URLSearchParams(params).toString();
            url += (url.includes('?') ? '&' : '?') + queryString;
        }

        return url;
    }

    /**
     * Ruft Konfigurationswert über Pfad ab
     * @param {string} path - Dot-notation path (e.g., 'hut.id', 'urls.base')
     * @param {*} defaultValue - Default value if path not found
     * @returns {*} Configuration value
     */
    get(path, defaultValue = null) {
        if (!this.initialized) {
            console.warn('🔄 WCIConfig: Not initialized yet, returning default value');
            return defaultValue;
        }

        const parts = path.split('.');
        let current = this.config;

        for (const part of parts) {
            if (current && typeof current === 'object' && part in current) {
                current = current[part];
            } else {
                return defaultValue;
            }
        }

        return current;
    }

    /**
     * Erstellt vollständige URL für API-Endpoint
     * @param {string} endpointName - Name des Endpoints aus der Konfiguration
     * @param {Object} params - URL-Parameter
     * @returns {string} Vollständige URL
     */
    getEndpoint(endpointName, params = {}) {
        if (!this.initialized) {
            console.warn(`🔄 WCIConfig: Configuration not initialized – cannot resolve endpoint '${endpointName}'`);
            return null;
        }

        const baseUrl = this.get('urls.base', '');
        const endpointPath = this.get(`endpoints.${endpointName}`);

        if (!endpointPath) {
            console.warn(`🔄 WCIConfig: Endpoint '${endpointName}' not found`);
            return null;
        }

        let url = `${baseUrl}${endpointPath}`;

        // Add URL parameters
        if (Object.keys(params).length > 0) {
            const urlParams = new URLSearchParams(params);
            url += `?${urlParams.toString()}`;
        }

        return url;
    }

    /**
     * Erstellt URL für HRS API-Endpunkt
     * @param {string} path - API-Pfad (ohne /api/v1)
     * @param {Object} params - URL-Parameter (hutId wird automatisch hinzugefügt)
     * @returns {string} Vollständige HRS API-URL
     */
    getHRSEndpoint(path, params = {}) {
        const baseUrl = this.get('hrs.base_url', 'https://www.hut-reservation.org');
        const apiBase = this.get('hrs.api_base', '/api/v1');
        const hutId = this.get('hut.id', 675);

        // Ensure hutId is always included
        const allParams = { hutId, ...params };
        const urlParams = new URLSearchParams(allParams);

        return `${baseUrl}${apiBase}${path}?${urlParams.toString()}`;
    }

    /**
     * Prüft ob Konfiguration initialisiert ist
     * @returns {boolean} Initialization status
     */
    isInitialized() {
        return this.initialized;
    }

    /**
     * Gibt die gesamte Konfiguration zurück (für Debugging)
     * @returns {Object} Complete configuration
     */
    getAll() {
        return this.config;
    }
}

// Globale Instanz erstellen
const WCIConfig = new WCIConfigManager();

// Auto-Initialize bei Import
if (typeof window !== 'undefined') {
    // Browser environment - initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => WCIConfig.init());
    } else {
        WCIConfig.init();
    }
}

// Export für verschiedene Module-Systeme
if (typeof module !== 'undefined' && module.exports) {
    // Node.js
    module.exports = WCIConfig;
} else if (typeof define === 'function' && define.amd) {
    // AMD
    define(() => WCIConfig);
} else {
    // Browser global
    window.WCIConfig = WCIConfig;
}
