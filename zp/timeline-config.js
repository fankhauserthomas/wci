// Timeline Configuration Manager
// Verwaltet Theme-Einstellungen und Cookie-Speicherung

class TimelineConfigManager {
    constructor() {
        this.themes = {
            professional: {
                name: 'Professional',
                description: 'Elegante, dunkle Oberfläche für konzentriertes Arbeiten',
                config: {
                    sidebar: { bg: '#2c3e50', text: '#ecf0f1' },
                    header: { bg: '#34495e', text: '#ecf0f1' },
                    master: { bg: '#2c3e50', bar: '#3498db' },
                    room: { bg: '#2c3e50', bar: '#27ae60' },
                    histogram: { bg: '#34495e', bar: '#e74c3c', text: '#ecf0f1' }
                }
            },
            comfort: {
                name: 'Comfort',
                description: 'Warme, helle Farben für entspanntes Arbeiten',
                config: {
                    sidebar: { bg: '#f8f9fa', text: '#495057' },
                    header: { bg: '#ffffff', text: '#343a40' },
                    master: { bg: '#f1f3f4', bar: '#007bff' },
                    room: { bg: '#f1f3f4', bar: '#28a745' },
                    histogram: { bg: '#ffffff', bar: '#17a2b8', text: '#495057' }
                }
            },
            focus: {
                name: 'Focus',
                description: 'Tiefblaues Design für maximale Konzentration',
                config: {
                    sidebar: { bg: '#1a1a2e', text: '#ffffff' },
                    header: { bg: '#16213e', text: '#ffffff' },
                    master: { bg: '#0f3460', bar: '#e94560' },
                    room: { bg: '#0f3460', bar: '#f39c12' },
                    histogram: { bg: '#16213e', bar: '#e94560', text: '#ffffff' }
                }
            },
            nature: {
                name: 'Nature',
                description: 'Natürliche Grün- und Erdtöne',
                config: {
                    sidebar: { bg: '#2d5016', text: '#a8e6cf' },
                    header: { bg: '#5d4e75', text: '#ffffff' },
                    master: { bg: '#134e5e', bar: '#71b280' },
                    room: { bg: '#134e5e', bar: '#ffd3a5' },
                    histogram: { bg: '#5d4e75', bar: '#a8e6cf', text: '#ffffff' }
                }
            },
            ocean: {
                name: 'Ocean',
                description: 'Beruhigende Blau- und Türkistöne',
                config: {
                    sidebar: { bg: '#1e3a8a', text: '#dbeafe' },
                    header: { bg: '#1e40af', text: '#dbeafe' },
                    master: { bg: '#155e75', bar: '#06b6d4' },
                    room: { bg: '#155e75', bar: '#0891b2' },
                    histogram: { bg: '#1e40af', bar: '#0ea5e9', text: '#dbeafe' }
                }
            },
            sunset: {
                name: 'Sunset',
                description: 'Warme Orange- und Rottöne',
                config: {
                    sidebar: { bg: '#7c2d12', text: '#fed7aa' },
                    header: { bg: '#9a3412', text: '#fed7aa' },
                    master: { bg: '#a16207', bar: '#f59e0b' },
                    room: { bg: '#a16207', bar: '#dc2626' },
                    histogram: { bg: '#9a3412', bar: '#ea580c', text: '#fed7aa' }
                }
            },
            earth: {
                name: 'Earth',
                description: 'Warme Braun- und Naturtöne',
                config: {
                    sidebar: { bg: '#44403c', text: '#d6d3d1' },
                    header: { bg: '#57534e', text: '#d6d3d1' },
                    master: { bg: '#78716c', bar: '#a3a3a3' },
                    room: { bg: '#78716c', bar: '#84cc16' },
                    histogram: { bg: '#57534e', bar: '#eab308', text: '#d6d3d1' }
                }
            },
            rainbow: {
                name: 'Rainbow',
                description: 'Dezenter Regenbogen mit sanften Farben',
                config: {
                    sidebar: { bg: '#f1f5f9', text: '#475569' },
                    header: { bg: '#e2e8f0', text: '#475569' },
                    master: { bg: '#fef3c7', bar: '#8b5cf6' },
                    room: { bg: '#fef3c7', bar: '#10b981' },
                    histogram: { bg: '#e2e8f0', bar: '#f59e0b', text: '#475569' }
                }
            },
            grayscale: {
                name: 'Grayscale',
                description: 'Klassische Graustufen',
                config: {
                    sidebar: { bg: '#1f2937', text: '#f9fafb' },
                    header: { bg: '#374151', text: '#f9fafb' },
                    master: { bg: '#4b5563', bar: '#9ca3af' },
                    room: { bg: '#4b5563', bar: '#6b7280' },
                    histogram: { bg: '#374151', bar: '#d1d5db', text: '#f9fafb' }
                }
            }
        };

        this.currentConfig = this.loadConfiguration();
        this.init();
    }

    init() {
        this.setupEventListeners();
        this.loadCurrentValues();
        this.updatePreview();
    }

    setupEventListeners() {
        // Theme-Karten
        document.querySelectorAll('.theme-card').forEach(card => {
            card.addEventListener('click', (e) => {
                const theme = e.currentTarget.dataset.theme;
                this.selectTheme(theme);
            });
        });

        // Color inputs
        document.querySelectorAll('input[type="color"]').forEach(input => {
            input.addEventListener('change', () => this.updateFromInputs());
        });

        // Range inputs (Font sizes)
        document.querySelectorAll('input[type="range"]').forEach(input => {
            input.addEventListener('input', (e) => {
                const valueSpan = document.getElementById(e.target.id + '-value');
                if (valueSpan) {
                    const id = e.target.id;
                    const usePx = !(id === 'weeks-past' || id === 'weeks-future');
                    valueSpan.textContent = e.target.value + (usePx ? 'px' : '');
                }
                this.updateFromInputs();
            });
        });
    }

    selectTheme(themeName) {
        const theme = this.themes[themeName];
        if (!theme) return;

        // Sichere aktuelle Layout-Einstellungen
        const currentFontSize = this.currentConfig.sidebar?.fontSize || 12;
        const currentHeaderFontSize = this.currentConfig.header?.fontSize || 10;
        const currentMasterFontSize = this.currentConfig.master?.fontSize || 10;
        const currentMasterBarHeight = this.currentConfig.master?.barHeight || 14;
        const currentRoomFontSize = this.currentConfig.room?.fontSize || 10;
        const currentRoomBarHeight = this.currentConfig.room?.barHeight || 16;
        const currentHistogramFontSize = this.currentConfig.histogram?.fontSize || 9;
        const currentDayWidth = this.currentConfig.dayWidth || 90;
        const currentWeeksPast = this.currentConfig.weeksPast ?? 2;
        const currentWeeksFuture = this.currentConfig.weeksFuture ?? 104;

        // Übernehme nur Farben vom Theme, behalte Layout-Einstellungen
        this.currentConfig = {
            sidebar: { ...theme.config.sidebar, fontSize: currentFontSize },
            header: { ...theme.config.header, fontSize: currentHeaderFontSize },
            master: { ...theme.config.master, fontSize: currentMasterFontSize, barHeight: currentMasterBarHeight },
            room: { ...theme.config.room, fontSize: currentRoomFontSize, barHeight: currentRoomBarHeight },
            histogram: { ...theme.config.histogram, fontSize: currentHistogramFontSize },
            dayWidth: currentDayWidth,
            weeksPast: currentWeeksPast,
            weeksFuture: currentWeeksFuture
        };

        this.updateInputsFromConfig();
        this.updatePreview();
    }

    updateInputsFromConfig() {
        const sidebarBg = document.getElementById('sidebar-bg');
        if (sidebarBg) {
            sidebarBg.value = this.currentConfig.sidebar.bg;
            const sidebarText = document.getElementById('sidebar-text');
            const sidebarFontSize = document.getElementById('sidebar-font-size');
            const sidebarFontSizeValue = document.getElementById('sidebar-font-size-value');
            if (sidebarText) sidebarText.value = this.currentConfig.sidebar.text;
            if (sidebarFontSize) sidebarFontSize.value = this.currentConfig.sidebar.fontSize;
            if (sidebarFontSizeValue) sidebarFontSizeValue.textContent = this.currentConfig.sidebar.fontSize + 'px';
        }

        const headerBg = document.getElementById('header-bg');
        if (headerBg) {
            headerBg.value = this.currentConfig.header.bg;
            const headerText = document.getElementById('header-text');
            const headerFontSize = document.getElementById('header-font-size');
            const headerFontSizeValue = document.getElementById('header-font-size-value');
            if (headerText) headerText.value = this.currentConfig.header.text;
            if (headerFontSize) headerFontSize.value = this.currentConfig.header.fontSize;
            if (headerFontSizeValue) headerFontSizeValue.textContent = this.currentConfig.header.fontSize + 'px';
        }

        const masterBg = document.getElementById('master-bg');
        if (masterBg) {
            masterBg.value = this.currentConfig.master.bg;
            const masterBar = document.getElementById('master-bar');
            const masterFontSize = document.getElementById('master-font-size');
            const masterFontSizeValue = document.getElementById('master-font-size-value');
            const masterBarHeight = document.getElementById('master-bar-height');
            const masterBarHeightValue = document.getElementById('master-bar-height-value');
            if (masterBar) masterBar.value = this.currentConfig.master.bar;
            if (masterFontSize) masterFontSize.value = this.currentConfig.master.fontSize;
            if (masterFontSizeValue) masterFontSizeValue.textContent = this.currentConfig.master.fontSize + 'px';
            if (masterBarHeight) masterBarHeight.value = this.currentConfig.master.barHeight;
            if (masterBarHeightValue) masterBarHeightValue.textContent = this.currentConfig.master.barHeight + 'px';
        }

        const roomBg = document.getElementById('room-bg');
        if (roomBg) {
            roomBg.value = this.currentConfig.room.bg;
            const roomBar = document.getElementById('room-bar');
            const roomFontSize = document.getElementById('room-font-size');
            const roomFontSizeValue = document.getElementById('room-font-size-value');
            const roomBarHeight = document.getElementById('room-bar-height');
            const roomBarHeightValue = document.getElementById('room-bar-height-value');
            if (roomBar) roomBar.value = this.currentConfig.room.bar;
            if (roomFontSize) roomFontSize.value = this.currentConfig.room.fontSize;
            if (roomFontSizeValue) roomFontSizeValue.textContent = this.currentConfig.room.fontSize + 'px';
            if (roomBarHeight) roomBarHeight.value = this.currentConfig.room.barHeight;
            if (roomBarHeightValue) roomBarHeightValue.textContent = this.currentConfig.room.barHeight + 'px';
        }

        const histogramBg = document.getElementById('histogram-bg');
        if (histogramBg) {
            histogramBg.value = this.currentConfig.histogram.bg;
            const histogramBar = document.getElementById('histogram-bar');
            const histogramText = document.getElementById('histogram-text');
            const histogramFontSize = document.getElementById('histogram-font-size');
            const histogramFontSizeValue = document.getElementById('histogram-font-size-value');
            if (histogramBar) histogramBar.value = this.currentConfig.histogram.bar;
            if (histogramText) histogramText.value = this.currentConfig.histogram.text;
            if (histogramFontSize) histogramFontSize.value = this.currentConfig.histogram.fontSize;
            if (histogramFontSizeValue) histogramFontSizeValue.textContent = this.currentConfig.histogram.fontSize + 'px';
        }

        // Weeks range
        const wp = this.currentConfig.weeksPast ?? 2;
        const wf = this.currentConfig.weeksFuture ?? 104;
        const wpEl = document.getElementById('weeks-past');
        const wpVal = document.getElementById('weeks-past-value');
        const wfEl = document.getElementById('weeks-future');
        const wfVal = document.getElementById('weeks-future-value');
        if (wpEl && wpVal) { wpEl.value = wp; wpVal.textContent = String(wp); }
        if (wfEl && wfVal) { wfEl.value = wf; wfVal.textContent = String(wf); }
    }

    updateFromInputs() {
        const sidebarBg = document.getElementById('sidebar-bg');
        if (sidebarBg) {
            this.currentConfig.sidebar.bg = sidebarBg.value;
            const sidebarText = document.getElementById('sidebar-text');
            const sidebarFontSize = document.getElementById('sidebar-font-size');
            if (sidebarText) this.currentConfig.sidebar.text = sidebarText.value;
            if (sidebarFontSize) {
                const parsed = parseInt(sidebarFontSize.value, 10);
                if (!Number.isNaN(parsed)) this.currentConfig.sidebar.fontSize = parsed;
            }
        }

        const headerBg = document.getElementById('header-bg');
        if (headerBg) {
            this.currentConfig.header.bg = headerBg.value;
            const headerText = document.getElementById('header-text');
            const headerFontSize = document.getElementById('header-font-size');
            if (headerText) this.currentConfig.header.text = headerText.value;
            if (headerFontSize) {
                const parsed = parseInt(headerFontSize.value, 10);
                if (!Number.isNaN(parsed)) this.currentConfig.header.fontSize = parsed;
            }
        }

        const masterBg = document.getElementById('master-bg');
        if (masterBg) {
            this.currentConfig.master.bg = masterBg.value;
            const masterBar = document.getElementById('master-bar');
            const masterFontSize = document.getElementById('master-font-size');
            const masterBarHeight = document.getElementById('master-bar-height');
            if (masterBar) this.currentConfig.master.bar = masterBar.value;
            if (masterFontSize) {
                const parsedFont = parseInt(masterFontSize.value, 10);
                if (!Number.isNaN(parsedFont)) this.currentConfig.master.fontSize = parsedFont;
            }
            if (masterBarHeight) {
                const parsedHeight = parseInt(masterBarHeight.value, 10);
                if (!Number.isNaN(parsedHeight)) this.currentConfig.master.barHeight = parsedHeight;
            }
        }

        const roomBg = document.getElementById('room-bg');
        if (roomBg) {
            this.currentConfig.room.bg = roomBg.value;
            const roomBar = document.getElementById('room-bar');
            const roomFontSize = document.getElementById('room-font-size');
            const roomBarHeight = document.getElementById('room-bar-height');
            if (roomBar) this.currentConfig.room.bar = roomBar.value;
            if (roomFontSize) {
                const parsedFont = parseInt(roomFontSize.value, 10);
                if (!Number.isNaN(parsedFont)) this.currentConfig.room.fontSize = parsedFont;
            }
            if (roomBarHeight) {
                const parsedHeight = parseInt(roomBarHeight.value, 10);
                if (!Number.isNaN(parsedHeight)) this.currentConfig.room.barHeight = parsedHeight;
            }
        }

        const histogramBg = document.getElementById('histogram-bg');
        if (histogramBg) {
            this.currentConfig.histogram.bg = histogramBg.value;
            const histogramBar = document.getElementById('histogram-bar');
            const histogramText = document.getElementById('histogram-text');
            const histogramFontSize = document.getElementById('histogram-font-size');
            if (histogramBar) this.currentConfig.histogram.bar = histogramBar.value;
            if (histogramText) this.currentConfig.histogram.text = histogramText.value;
            if (histogramFontSize) {
                const parsed = parseInt(histogramFontSize.value, 10);
                if (!Number.isNaN(parsed)) this.currentConfig.histogram.fontSize = parsed;
            }
        }

        // Weeks range
        const weeksPastEl = document.getElementById('weeks-past');
        const weeksFutureEl = document.getElementById('weeks-future');
        if (weeksPastEl) {
            const parsedPast = parseInt(weeksPastEl.value, 10);
            if (!Number.isNaN(parsedPast)) this.currentConfig.weeksPast = parsedPast;
        }
        if (weeksFutureEl) {
            const parsedFuture = parseInt(weeksFutureEl.value, 10);
            if (!Number.isNaN(parsedFuture)) this.currentConfig.weeksFuture = parsedFuture;
        }

        this.updatePreview();
    }

    updatePreview() {
        // Preview section was removed from the interface
        // This function is kept for compatibility but no longer updates preview elements
        console.log('Preview update skipped - preview section removed');
    }

    loadCurrentValues() {
        this.updateInputsFromConfig();
    }

    // Cookie-Management
    saveConfiguration() {
        const configString = JSON.stringify(this.currentConfig);
        const expires = new Date();
        expires.setFullYear(expires.getFullYear() + 1); // 1 Jahr gültig
        document.cookie = `timeline_config=${encodeURIComponent(configString)}; expires=${expires.toUTCString()}; path=/`;

        // Zusätzlich im localStorage speichern als Backup
        localStorage.setItem('timeline_config', configString);

        alert('✅ Konfiguration erfolgreich gespeichert!');
    }

    loadConfiguration() {
        // Versuche aus Cookie zu laden
        const cookieValue = document.cookie
            .split('; ')
            .find(row => row.startsWith('timeline_config='))
            ?.split('=')[1];

        if (cookieValue) {
            try {
                return JSON.parse(decodeURIComponent(cookieValue));
            } catch (e) {
                console.warn('Fehler beim Laden der Cookie-Konfiguration:', e);
            }
        }

        // Fallback: localStorage
        const localStorageValue = localStorage.getItem('timeline_config');
        if (localStorageValue) {
            try {
                return JSON.parse(localStorageValue);
            } catch (e) {
                console.warn('Fehler beim Laden der localStorage-Konfiguration:', e);
            }
        }

        // Default: Professional Theme with layout defaults
        const base = JSON.parse(JSON.stringify(this.themes.professional.config));
        return { ...base, dayWidth: 90, weeksPast: 2, weeksFuture: 104 };
    }

    resetToDefaults() {
        if (confirm('⚠️ Möchten Sie wirklich alle Einstellungen zurücksetzen?')) {
            this.currentConfig = JSON.parse(JSON.stringify(this.themes.professional.config));
            this.updateInputsFromConfig();
            this.updatePreview();

            // Theme-Auswahl zurücksetzen
            document.querySelectorAll('.theme-card').forEach(card => {
                card.classList.remove('active');
            });
            document.querySelector('[data-theme="professional"]').classList.add('active');
        }
    }

    // Statische Methode zum Laden der Konfiguration für andere Module
    static loadConfig() {
        // Versuche aus Cookie zu laden
        const cookieValue = document.cookie
            .split('; ')
            .find(row => row.startsWith('timeline_config='))
            ?.split('=')[1];

        if (cookieValue) {
            try {
                return JSON.parse(decodeURIComponent(cookieValue));
            } catch (e) {
                console.warn('Fehler beim Laden der Cookie-Konfiguration:', e);
            }
        }

        // Fallback: localStorage
        const localStorageValue = localStorage.getItem('timeline_config');
        if (localStorageValue) {
            try {
                return JSON.parse(localStorageValue);
            } catch (e) {
                console.warn('Fehler beim Laden der localStorage-Konfiguration:', e);
            }
        }

        // Default: Professional Theme
        return {
            sidebar: { bg: '#2c3e50', text: '#ecf0f1', fontSize: 12 },
            header: { bg: '#34495e', text: '#ecf0f1', fontSize: 10 },
            master: { bg: '#2c3e50', bar: '#3498db', fontSize: 10, barHeight: 14 },
            room: { bg: '#2c3e50', bar: '#27ae60', fontSize: 10, barHeight: 16 },
            histogram: { bg: '#34495e', bar: '#e74c3c', text: '#ecf0f1', fontSize: 9 },
            dayWidth: 90,
            weeksPast: 2,
            weeksFuture: 104
        };
    }
}

// Global functions for button actions
function saveConfiguration() {
    if (window.configManager) {
        window.configManager.saveConfiguration();
    }
}

function resetToDefaults() {
    if (window.configManager) {
        window.configManager.resetToDefaults();
    }
}

function applyAndClose() {
    if (window.configManager) {
        window.configManager.saveConfiguration();
        // Kurze Verzögerung für das Alert, dann zur Timeline zurückkehren
        setTimeout(() => {
            window.location.href = 'timeline-unified.html';
        }, 1000);
    }
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    window.configManager = new TimelineConfigManager();
});

// Export für andere Module
window.TimelineConfigManager = TimelineConfigManager;
