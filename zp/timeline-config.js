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
                    valueSpan.textContent = e.target.value + 'px';
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

        // Übernehme nur Farben vom Theme, behalte Layout-Einstellungen
        this.currentConfig = {
            sidebar: { ...theme.config.sidebar, fontSize: currentFontSize },
            header: { ...theme.config.header, fontSize: currentHeaderFontSize },
            master: { ...theme.config.master, fontSize: currentMasterFontSize, barHeight: currentMasterBarHeight },
            room: { ...theme.config.room, fontSize: currentRoomFontSize, barHeight: currentRoomBarHeight },
            histogram: { ...theme.config.histogram, fontSize: currentHistogramFontSize },
            dayWidth: currentDayWidth
        };

        this.updateInputsFromConfig();
        this.updatePreview();
    }

    updateInputsFromConfig() {
        // Sidebar
        document.getElementById('sidebar-bg').value = this.currentConfig.sidebar.bg;
        document.getElementById('sidebar-text').value = this.currentConfig.sidebar.text;
        document.getElementById('sidebar-font-size').value = this.currentConfig.sidebar.fontSize;
        document.getElementById('sidebar-font-size-value').textContent = this.currentConfig.sidebar.fontSize + 'px';

        // Header
        document.getElementById('header-bg').value = this.currentConfig.header.bg;
        document.getElementById('header-text').value = this.currentConfig.header.text;
        document.getElementById('header-font-size').value = this.currentConfig.header.fontSize;
        document.getElementById('header-font-size-value').textContent = this.currentConfig.header.fontSize + 'px';

        // Master
        document.getElementById('master-bg').value = this.currentConfig.master.bg;
        document.getElementById('master-bar').value = this.currentConfig.master.bar;
        document.getElementById('master-font-size').value = this.currentConfig.master.fontSize;
        document.getElementById('master-font-size-value').textContent = this.currentConfig.master.fontSize + 'px';
        document.getElementById('master-bar-height').value = this.currentConfig.master.barHeight;
        document.getElementById('master-bar-height-value').textContent = this.currentConfig.master.barHeight + 'px';

        // Room
        document.getElementById('room-bg').value = this.currentConfig.room.bg;
        document.getElementById('room-bar').value = this.currentConfig.room.bar;
        document.getElementById('room-font-size').value = this.currentConfig.room.fontSize;
        document.getElementById('room-font-size-value').textContent = this.currentConfig.room.fontSize + 'px';
        document.getElementById('room-bar-height').value = this.currentConfig.room.barHeight;
        document.getElementById('room-bar-height-value').textContent = this.currentConfig.room.barHeight + 'px';

        // Histogram
        document.getElementById('histogram-bg').value = this.currentConfig.histogram.bg;
        document.getElementById('histogram-bar').value = this.currentConfig.histogram.bar;
        document.getElementById('histogram-text').value = this.currentConfig.histogram.text;
        document.getElementById('histogram-font-size').value = this.currentConfig.histogram.fontSize;
        document.getElementById('histogram-font-size-value').textContent = this.currentConfig.histogram.fontSize + 'px';

        // Day Width
        document.getElementById('day-width').value = this.currentConfig.dayWidth;
        document.getElementById('day-width-value').textContent = this.currentConfig.dayWidth + 'px';
    }

    updateFromInputs() {
        // Sidebar
        this.currentConfig.sidebar.bg = document.getElementById('sidebar-bg').value;
        this.currentConfig.sidebar.text = document.getElementById('sidebar-text').value;
        this.currentConfig.sidebar.fontSize = parseInt(document.getElementById('sidebar-font-size').value);

        // Header
        this.currentConfig.header.bg = document.getElementById('header-bg').value;
        this.currentConfig.header.text = document.getElementById('header-text').value;
        this.currentConfig.header.fontSize = parseInt(document.getElementById('header-font-size').value);

        // Master
        this.currentConfig.master.bg = document.getElementById('master-bg').value;
        this.currentConfig.master.bar = document.getElementById('master-bar').value;
        this.currentConfig.master.fontSize = parseInt(document.getElementById('master-font-size').value);
        this.currentConfig.master.barHeight = parseInt(document.getElementById('master-bar-height').value);

        // Room
        this.currentConfig.room.bg = document.getElementById('room-bg').value;
        this.currentConfig.room.bar = document.getElementById('room-bar').value;
        this.currentConfig.room.fontSize = parseInt(document.getElementById('room-font-size').value);
        this.currentConfig.room.barHeight = parseInt(document.getElementById('room-bar-height').value);

        // Histogram
        this.currentConfig.histogram.bg = document.getElementById('histogram-bg').value;
        this.currentConfig.histogram.bar = document.getElementById('histogram-bar').value;
        this.currentConfig.histogram.text = document.getElementById('histogram-text').value;
        this.currentConfig.histogram.fontSize = parseInt(document.getElementById('histogram-font-size').value);

        // Day Width
        this.currentConfig.dayWidth = parseInt(document.getElementById('day-width').value);

        this.updatePreview();
    }

    updatePreview() {
        const preview = document.getElementById('preview-mini');
        const header = document.getElementById('preview-header');
        const sidebar = document.getElementById('preview-sidebar');
        const master = document.getElementById('preview-master');
        const rooms = document.getElementById('preview-rooms');
        const histogram = document.getElementById('preview-histogram');

        // Header
        header.style.backgroundColor = this.currentConfig.header.bg;
        header.style.color = this.currentConfig.header.text;
        header.style.fontSize = (this.currentConfig.header.fontSize * 0.8) + 'px';

        // Sidebar
        sidebar.style.backgroundColor = this.currentConfig.sidebar.bg;
        sidebar.style.color = this.currentConfig.sidebar.text;
        sidebar.style.fontSize = (this.currentConfig.sidebar.fontSize * 0.7) + 'px';

        // Master
        master.style.backgroundColor = this.currentConfig.master.bg;
        master.style.color = this.currentConfig.master.text;
        master.style.fontSize = (this.currentConfig.master.fontSize * 0.8) + 'px';

        // Rooms
        rooms.style.backgroundColor = this.currentConfig.room.bg;
        rooms.style.color = this.currentConfig.room.text;
        rooms.style.fontSize = (this.currentConfig.room.fontSize * 0.8) + 'px';

        // Histogram
        histogram.style.backgroundColor = this.currentConfig.histogram.bg;
        histogram.style.color = this.currentConfig.histogram.text;
        histogram.style.fontSize = (this.currentConfig.histogram.fontSize * 0.8) + 'px';
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

        // Default: Professional Theme
        return JSON.parse(JSON.stringify(this.themes.professional.config));
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
            dayWidth: 90
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
