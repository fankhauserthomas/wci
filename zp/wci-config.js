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
                wci: 'http://192.168.15.14:8080/wci',
                zp: 'http://192.168.15.14:8080/wci/zp',
                reservations: 'http://192.168.15.14:8080/wci/reservierungen',
                pictures: 'http://192.168.15.14:8080/wci/pic'
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
                rooms: '/wci/zp/getRooms.php',
                av_reservation_data: '/wci/zp/getAVReservationData.php',
                arrangements: '/wci/zp/getArrangements.php',
                origins: '/wci/zp/getOrigins.php',
                countries: '/wci/zp/getCountries.php',
                update_room_detail: '/wci/zp/updateRoomDetail.php',
                update_room_detail_attributes: '/wci/zp/updateRoomDetailAttributes.php',
                update_reservation_master_data: '/wci/zp/updateReservationMasterData.php',
                assign_rooms: '/wci/zp/assignRoomsToReservation.php',
                split_reservation_detail: '/wci/reservierungen/api/splitReservationDetail.php',
                split_reservation_by_date: '/wci/reservierungen/api/splitReservationByDate.php',
                delete_reservation_detail: '/wci/reservierungen/api/deleteReservationDetail.php',
                delete_reservation_all_details: '/wci/reservierungen/api/deleteReservationAllDetails.php',
                update_reservation_designation: '/wci/reservierungen/api/updateReservationDesignation.php'
            }
        };
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
        const baseUrl = this.get('urls.base', '');
        const endpointPath = this.get(`endpoints.${endpointName}`);
        
        if (!endpointPath) {
            console.warn(`🔄 WCIConfig: Endpoint '${endpointName}' not found`);
            return `${baseUrl}/wci/api/${endpointName}.php`;
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