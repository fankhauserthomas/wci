/* ==============================================
   DATA MANAGER - ZENTRALISIERTE DATENVERWALTUNG
   ============================================== */

class DataManager {
    constructor() {
        this.cache = new Map();
        this.cacheExpiry = new Map();
        this.defaultCacheDuration = 5 * 60 * 1000; // 5 Minuten
        this.loadingStates = new Set();
        this.retryAttempts = new Map();
        this.maxRetries = 3;

        // Event Emitter Setup
        this.events = {};

        // Initialisierung
        this.init();
    }

    /**
     * Initialisierung
     */
    init() {
        console.log('DataManager initialisiert');

        // Cache Cleanup alle 10 Minuten
        setInterval(() => this.cleanExpiredCache(), 10 * 60 * 1000);

        // Event Listener für Netzwerk-Status
        window.addEventListener('online', () => this.handleNetworkChange(true));
        window.addEventListener('offline', () => this.handleNetworkChange(false));
    }

    /**
     * Event System
     */
    on(event, callback) {
        if (!this.events[event]) {
            this.events[event] = [];
        }
        this.events[event].push(callback);
    }

    off(event, callback) {
        if (!this.events[event]) return;
        const index = this.events[event].indexOf(callback);
        if (index > -1) {
            this.events[event].splice(index, 1);
        }
    }

    emit(event, data) {
        if (!this.events[event]) return;
        this.events[event].forEach(callback => {
            try {
                callback(data);
            } catch (error) {
                console.error(`Event handler error for ${event}:`, error);
            }
        });
    }

    /**
     * Cache Management
     */
    setCache(key, data, duration = this.defaultCacheDuration) {
        this.cache.set(key, data);
        this.cacheExpiry.set(key, Date.now() + duration);
    }

    getCache(key) {
        const expiry = this.cacheExpiry.get(key);
        if (!expiry || Date.now() > expiry) {
            this.cache.delete(key);
            this.cacheExpiry.delete(key);
            return null;
        }
        return this.cache.get(key);
    }

    invalidateCache(pattern = null) {
        if (pattern) {
            // Pattern-basierte Invalidierung
            for (const key of this.cache.keys()) {
                if (key.includes(pattern)) {
                    this.cache.delete(key);
                    this.cacheExpiry.delete(key);
                }
            }
        } else {
            // Komplette Cache-Löschung
            this.cache.clear();
            this.cacheExpiry.clear();
        }
        this.emit('cache:invalidated', { pattern });
    }

    cleanExpiredCache() {
        const now = Date.now();
        for (const [key, expiry] of this.cacheExpiry.entries()) {
            if (now > expiry) {
                this.cache.delete(key);
                this.cacheExpiry.delete(key);
            }
        }
    }

    /**
     * HTTP Request Handler mit Retry-Logic
     */
    async request(url, options = {}) {
        const cacheKey = `${url}:${JSON.stringify(options)}`;

        // Cache Check
        if (options.cache !== false) {
            const cached = this.getCache(cacheKey);
            if (cached) {
                return cached;
            }
        }

        // Loading State Management
        if (this.loadingStates.has(cacheKey)) {
            // Bereits laufende Anfrage - warten
            return new Promise((resolve, reject) => {
                const checkComplete = () => {
                    if (!this.loadingStates.has(cacheKey)) {
                        const result = this.getCache(cacheKey);
                        if (result) {
                            resolve(result);
                        } else {
                            reject(new Error('Request failed'));
                        }
                    } else {
                        setTimeout(checkComplete, 100);
                    }
                };
                checkComplete();
            });
        }

        this.loadingStates.add(cacheKey);

        try {
            const result = await this.performRequest(url, options);

            // Cache Result
            if (options.cache !== false) {
                this.setCache(cacheKey, result, options.cacheDuration);
            }

            this.retryAttempts.delete(cacheKey);
            this.emit('request:success', { url, result });

            return result;
        } catch (error) {
            const attempts = this.retryAttempts.get(cacheKey) || 0;

            if (attempts < this.maxRetries && this.shouldRetry(error)) {
                this.retryAttempts.set(cacheKey, attempts + 1);
                console.warn(`Request failed, retrying (${attempts + 1}/${this.maxRetries}):`, url);

                // Exponential Backoff
                await new Promise(resolve => setTimeout(resolve, Math.pow(2, attempts) * 1000));

                this.loadingStates.delete(cacheKey);
                return this.request(url, options);
            }

            this.emit('request:error', { url, error, attempts });
            throw error;
        } finally {
            this.loadingStates.delete(cacheKey);
        }
    }

    async performRequest(url, options) {
        const defaultOptions = {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
            },
            credentials: 'same-origin'
        };

        const config = { ...defaultOptions, ...options };

        const response = await fetch(url, config);

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }

        const contentType = response.headers.get('content-type');
        if (contentType && contentType.includes('application/json')) {
            return await response.json();
        } else {
            return await response.text();
        }
    }

    shouldRetry(error) {
        // Retry bei Netzwerkfehlern, aber nicht bei 4xx Fehlern
        return !(error.message.includes('4') && error.message.includes('HTTP'));
    }

    /**
     * Spezifische Daten-APIs
     */

    // Reservierungen laden
    async loadReservations(filters = {}) {
        Utils.performance.mark('loadReservations:start');

        const params = new URLSearchParams(filters);
        const url = `api/test-simple.php?${params}`;

        try {
            const data = await this.request(url, { cacheDuration: 2 * 60 * 1000 }); // 2 Minuten Cache

            // Datenvalidierung
            if (!data.success || !Array.isArray(data.data)) {
                throw new Error('Invalid reservations data format');
            }

            // Datenverarbeitung
            const processedData = this.processReservations(data.data);

            Utils.performance.measure('loadReservations:start');
            this.emit('data:reservations:loaded', {
                reservations: processedData,
                stats: this.calculateStats(processedData)
            });

            return processedData;
        } catch (error) {
            console.error('Fehler beim Laden der Reservierungen:', error);
            this.emit('data:reservations:error', error);
            throw error;
        }
    }

    // HP-Daten laden
    async loadHpData() {
        Utils.performance.mark('loadHpData:start');

        try {
            const data = await this.request('api/hp-data.php', { cacheDuration: 5 * 60 * 1000 }); // 5 Minuten Cache

            // Datenvalidierung
            if (!data.arrangements || !data.checkins) {
                throw new Error('Invalid HP data format');
            }

            const processedData = this.processHpData(data);

            Utils.performance.measure('loadHpData:start');
            this.emit('data:hp:loaded', processedData);

            return processedData;
        } catch (error) {
            console.error('Fehler beim Laden der HP-Daten:', error);
            this.emit('data:hp:error', error);
            throw error;
        }
    }

    // Arrangements laden
    async loadArrangements() {
        try {
            const data = await this.request('api/arrangements.php', { cacheDuration: 30 * 60 * 1000 }); // 30 Minuten Cache
            this.emit('data:arrangements:loaded', data);
            return data;
        } catch (error) {
            console.error('Fehler beim Laden der Arrangements:', error);
            this.emit('data:arrangements:error', error);
            throw error;
        }
    }

    // Herkunftsorte laden
    async loadOrigins() {
        try {
            const data = await this.request('api/origins.php', { cacheDuration: 60 * 60 * 1000 }); // 1 Stunde Cache
            this.emit('data:origins:loaded', data);
            return data;
        } catch (error) {
            console.error('Fehler beim Laden der Herkunftsorte:', error);
            this.emit('data:origins:error', error);
            throw error;
        }
    }

    /**
     * Datenverarbeitung
     */
    processReservations(reservations) {
        return reservations.map(reservation => ({
            ...reservation,
            // Datumskonvertierung
            anreise: reservation.anreise ? new Date(reservation.anreise) : null,
            abreise: reservation.abreise ? new Date(reservation.abreise) : null,
            // Status-Normalisierung
            status: this.normalizeStatus(reservation.status),
            // Vollständiger Name
            fullName: `${reservation.vorname || ''} ${reservation.nachname || ''}`.trim(),
            // Guest Count
            guest_count: reservation.anzahl || reservation.guest_count || 0,
            // Suchbarer Text
            searchText: [
                reservation.nachname,
                reservation.vorname,
                reservation.herkunft,
                reservation.arrangement,
                reservation.bemerkung
            ].filter(Boolean).join(' ').toLowerCase(),
            // HP-Marker berechnen
            hpMarker: this.calculateHpMarker(reservation)
        }));
    }

    calculateStats(reservations) {
        const total = reservations.length;
        const checkedIn = reservations.filter(r => r.status === 'checked-in').length;
        const cancelled = reservations.filter(r => r.storno).length;
        const totalGuests = reservations.reduce((sum, r) => sum + (r.guest_count || 0), 0);
        const checkedInGuests = reservations
            .filter(r => r.status === 'checked-in')
            .reduce((sum, r) => sum + (r.guest_count || 0), 0);

        return {
            total_reservations: total,
            active_reservations: total - cancelled,
            cancelled_reservations: cancelled,
            checked_in_reservations: checkedIn,
            total_guests: totalGuests,
            checked_in_guests: checkedInGuests
        };
    }

    processHpData(data) {
        // Arrangements nach Namen indexieren
        const arrangementsByName = {};
        data.arrangements.forEach(arr => {
            const key = `${arr.nachname}_${arr.vorname}`;
            if (!arrangementsByName[key]) {
                arrangementsByName[key] = 0;
            }
            arrangementsByName[key]++;
        });

        // Check-ins nach Namen indexieren
        const checkinsByName = {};
        data.checkins.forEach(checkin => {
            const key = `${checkin.nachname}_${checkin.vorname}`;
            if (!checkinsByName[key]) {
                checkinsByName[key] = 0;
            }
            checkinsByName[key]++;
        });

        return {
            arrangements: arrangementsByName,
            checkins: checkinsByName,
            lastUpdated: new Date()
        };
    }

    normalizeStatus(status) {
        const statusMap = {
            'eingecheckt': 'checked-in',
            'checkedin': 'checked-in',
            'storniert': 'cancelled',
            'storno': 'cancelled',
            'offen': 'pending',
            'pending': 'pending'
        };

        return statusMap[status?.toLowerCase()] || 'pending';
    }

    calculateHpMarker(reservation) {
        // Diese Logik wird mit echten HP-Daten kombiniert
        return {
            status: 'no-data',
            title: 'Keine HP-Daten verfügbar'
        };
    }

    /**
     * CRUD Operationen
     */
    async createReservation(data) {
        try {
            const result = await this.request('api/reservations.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(data),
                cache: false
            });

            // Cache invalidieren
            this.invalidateCache('reservations');
            this.emit('data:reservation:created', result);

            return result;
        } catch (error) {
            console.error('Fehler beim Erstellen der Reservierung:', error);
            this.emit('data:reservation:create:error', error);
            throw error;
        }
    }

    async updateReservation(id, data) {
        try {
            const result = await this.request(`api/reservations.php?id=${id}`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(data),
                cache: false
            });

            // Cache invalidieren
            this.invalidateCache('reservations');
            this.emit('data:reservation:updated', { id, data: result });

            return result;
        } catch (error) {
            console.error('Fehler beim Aktualisieren der Reservierung:', error);
            this.emit('data:reservation:update:error', error);
            throw error;
        }
    }

    async deleteReservation(id) {
        try {
            const result = await this.request(`api/reservations.php?id=${id}`, {
                method: 'DELETE',
                cache: false
            });

            // Cache invalidieren
            this.invalidateCache('reservations');
            this.emit('data:reservation:deleted', { id });

            return result;
        } catch (error) {
            console.error('Fehler beim Löschen der Reservierung:', error);
            this.emit('data:reservation:delete:error', error);
            throw error;
        }
    }

    /**
     * Utility Methods
     */
    handleNetworkChange(isOnline) {
        if (isOnline) {
            console.log('Netzwerk wieder verfügbar - Cache wird aktualisiert');
            this.emit('network:online');
            // Optional: Cache invalidieren und neu laden
        } else {
            console.log('Netzwerk nicht verfügbar - Cache wird verwendet');
            this.emit('network:offline');
        }
    }

    getLoadingStates() {
        return Array.from(this.loadingStates);
    }

    isLoading(key = null) {
        if (key) {
            return this.loadingStates.has(key);
        }
        return this.loadingStates.size > 0;
    }

    // Debugging
    getCacheStats() {
        return {
            cacheSize: this.cache.size,
            loadingStates: this.loadingStates.size,
            retryAttempts: this.retryAttempts.size
        };
    }
}

// Globale Instanz erstellen
window.dataManager = new DataManager();
