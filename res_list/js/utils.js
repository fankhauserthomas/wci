/* ==============================================
   UTILITY FUNKTIONEN - HILFSBIBLIOTHEK
   ============================================== */

const Utils = {
    /**
     * Debounce Funktion für Performance-Optimierung
     */
    debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    },

    /**
     * Formatiert Datum für Anzeige
     */
    formatDate(dateString, format = 'dd.mm.yyyy') {
        if (!dateString) return '';

        const date = new Date(dateString);
        if (isNaN(date.getTime())) return dateString;

        const day = date.getDate().toString().padStart(2, '0');
        const month = (date.getMonth() + 1).toString().padStart(2, '0');
        const year = date.getFullYear();

        switch (format) {
            case 'dd.mm.yyyy':
                return `${day}.${month}.${year}`;
            case 'yyyy-mm-dd':
                return `${year}-${month}-${day}`;
            case 'relative':
                return this.getRelativeDate(date);
            default:
                return date.toLocaleDateString('de-DE');
        }
    },

    /**
     * Berechnet relative Datumsanzeige
     */
    getRelativeDate(date) {
        const now = new Date();
        const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        const targetDate = new Date(date.getFullYear(), date.getMonth(), date.getDate());

        const diffTime = targetDate - today;
        const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));

        if (diffDays === 0) return 'Heute';
        if (diffDays === 1) return 'Morgen';
        if (diffDays === -1) return 'Gestern';
        if (diffDays > 0) return `in ${diffDays} Tagen`;
        if (diffDays < 0) return `vor ${Math.abs(diffDays)} Tagen`;

        return this.formatDate(date);
    },

    /**
     * Escape HTML für XSS-Schutz
     */
    escapeHtml(text) {
        if (typeof text !== 'string') return text;

        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };

        return text.replace(/[&<>"']/g, m => map[m]);
    },

    /**
     * Deep Clone für Objektkopien
     */
    deepClone(obj) {
        if (obj === null || typeof obj !== 'object') return obj;
        if (obj instanceof Date) return new Date(obj.getTime());
        if (obj instanceof Array) return obj.map(item => this.deepClone(item));
        if (typeof obj === 'object') {
            const clonedObj = {};
            for (const key in obj) {
                if (obj.hasOwnProperty(key)) {
                    clonedObj[key] = this.deepClone(obj[key]);
                }
            }
            return clonedObj;
        }
        return obj;
    },

    /**
     * String zu Slug konvertieren
     */
    slugify(str) {
        return str
            .toLowerCase()
            .trim()
            .replace(/[^\w\s-]/g, '')
            .replace(/[\s_-]+/g, '-')
            .replace(/^-+|-+$/g, '');
    },

    /**
     * Fuzzy Search Implementierung
     */
    fuzzySearch(needle, haystack, threshold = 0.6) {
        if (!needle || !haystack) return false;

        needle = needle.toLowerCase();
        haystack = haystack.toLowerCase();

        // Exakte Übereinstimmung
        if (haystack.includes(needle)) return true;

        // Levenshtein-Distanz für Fuzzy-Matching
        const distance = this.levenshteinDistance(needle, haystack);
        const maxLength = Math.max(needle.length, haystack.length);
        const similarity = 1 - (distance / maxLength);

        return similarity >= threshold;
    },

    /**
     * Levenshtein-Distanz berechnen
     */
    levenshteinDistance(str1, str2) {
        const matrix = [];

        for (let i = 0; i <= str2.length; i++) {
            matrix[i] = [i];
        }

        for (let j = 0; j <= str1.length; j++) {
            matrix[0][j] = j;
        }

        for (let i = 1; i <= str2.length; i++) {
            for (let j = 1; j <= str1.length; j++) {
                if (str2.charAt(i - 1) === str1.charAt(j - 1)) {
                    matrix[i][j] = matrix[i - 1][j - 1];
                } else {
                    matrix[i][j] = Math.min(
                        matrix[i - 1][j - 1] + 1,
                        matrix[i][j - 1] + 1,
                        matrix[i - 1][j] + 1
                    );
                }
            }
        }

        return matrix[str2.length][str1.length];
    },

    /**
     * URL Parameter extrahieren
     */
    getUrlParams() {
        const params = {};
        const searchParams = new URLSearchParams(window.location.search);

        for (const [key, value] of searchParams) {
            params[key] = value;
        }

        return params;
    },

    /**
     * URL Parameter setzen ohne Reload
     */
    setUrlParam(key, value) {
        const url = new URL(window.location);
        if (value) {
            url.searchParams.set(key, value);
        } else {
            url.searchParams.delete(key);
        }
        window.history.replaceState({}, '', url);
    },

    /**
     * Element Visibility Check
     */
    isElementVisible(element) {
        if (!element) return false;

        const rect = element.getBoundingClientRect();
        const viewHeight = Math.max(document.documentElement.clientHeight, window.innerHeight);

        return !(rect.bottom < 0 || rect.top - viewHeight >= 0);
    },

    /**
     * Smooth Scroll zu Element
     */
    scrollToElement(element, offset = 0) {
        if (!element) return;

        const rect = element.getBoundingClientRect();
        const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
        const targetPosition = rect.top + scrollTop - offset;

        window.scrollTo({
            top: targetPosition,
            behavior: 'smooth'
        });
    },

    /**
     * Local Storage mit Fehlerbehandlung
     */
    storage: {
        set(key, value) {
            try {
                localStorage.setItem(key, JSON.stringify(value));
                return true;
            } catch (e) {
                console.warn('LocalStorage nicht verfügbar:', e);
                return false;
            }
        },

        get(key, defaultValue = null) {
            try {
                const item = localStorage.getItem(key);
                return item ? JSON.parse(item) : defaultValue;
            } catch (e) {
                console.warn('LocalStorage nicht verfügbar:', e);
                return defaultValue;
            }
        },

        remove(key) {
            try {
                localStorage.removeItem(key);
                return true;
            } catch (e) {
                console.warn('LocalStorage nicht verfügbar:', e);
                return false;
            }
        },

        clear() {
            try {
                localStorage.clear();
                return true;
            } catch (e) {
                console.warn('LocalStorage nicht verfügbar:', e);
                return false;
            }
        }
    },

    /**
     * API Helper für AJAX Calls
     */
    async fetchApi(url, options = {}) {
        const defaultOptions = {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
            },
            credentials: 'same-origin'
        };

        const config = { ...defaultOptions, ...options };

        try {
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
        } catch (error) {
            console.error('API Request fehlgeschlagen:', error);
            throw error;
        }
    },

    /**
     * Form Data Helper
     */
    serializeForm(form) {
        if (!form) return {};

        const formData = new FormData(form);
        const data = {};

        for (const [key, value] of formData.entries()) {
            if (data[key]) {
                if (Array.isArray(data[key])) {
                    data[key].push(value);
                } else {
                    data[key] = [data[key], value];
                }
            } else {
                data[key] = value;
            }
        }

        return data;
    },

    /**
     * Event Bus für Komponenten-Kommunikation
     */
    eventBus: {
        events: {},

        on(event, callback) {
            if (!this.events[event]) {
                this.events[event] = [];
            }
            this.events[event].push(callback);
        },

        off(event, callback) {
            if (!this.events[event]) return;

            const index = this.events[event].indexOf(callback);
            if (index > -1) {
                this.events[event].splice(index, 1);
            }
        },

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
    },

    /**
     * Performance Monitoring
     */
    performance: {
        marks: {},

        mark(name) {
            this.marks[name] = performance.now();
        },

        measure(startMark, endMark = null) {
            const start = this.marks[startMark];
            const end = endMark ? this.marks[endMark] : performance.now();

            if (start === undefined) {
                console.warn(`Performance mark '${startMark}' not found`);
                return 0;
            }

            const duration = end - start;
            console.log(`Performance: ${startMark} took ${duration.toFixed(2)}ms`);
            return duration;
        }
    }
};

// Global verfügbar machen
window.Utils = Utils;
