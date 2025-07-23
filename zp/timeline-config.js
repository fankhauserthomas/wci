// Timeline Configuration Manager
// Verwaltet Theme-Einstellungen und Cookie-Speicherung

class TimelineConfigManager {
    constructor() {
        this.themes = {
            professional: {
                name: 'Professional',
                description: 'Elegante, dunkle Oberfläche für konzentriertes Arbeiten',
                config: {
                    sidebar: { bg: '#2c3e50', text: '#ecf0f1', fontSize: 12 },
                    header: { bg: '#34495e', text: '#ecf0f1', fontSize: 10 },
                    master: { bg: '#2c3e50', bar: '#3498db', text: '#ffffff', fontSize: 10 },
                    room: { bg: '#2c3e50', bar: '#27ae60', text: '#ffffff', fontSize: 10 },
                    histogram: { bg: '#34495e', bar: '#e74c3c', text: '#ecf0f1', fontSize: 9 }
                }
            },
            comfort: {
                name: 'Comfort',
                description: 'Warme, helle Farben für entspanntes Arbeiten',
                config: {
                    sidebar: { bg: '#f8f9fa', text: '#495057', fontSize: 12 },
                    header: { bg: '#ffffff', text: '#343a40', fontSize: 10 },
                    master: { bg: '#f1f3f4', bar: '#007bff', text: '#495057', fontSize: 10 },
                    room: { bg: '#f1f3f4', bar: '#28a745', text: '#495057', fontSize: 10 },
                    histogram: { bg: '#ffffff', bar: '#17a2b8', text: '#495057', fontSize: 9 }
                }
            },
            focus: {
                name: 'Focus',
                description: 'Tiefblaues Design für maximale Konzentration',
                config: {
                    sidebar: { bg: '#1a1a2e', text: '#ffffff', fontSize: 12 },
                    header: { bg: '#16213e', text: '#ffffff', fontSize: 10 },
                    master: { bg: '#0f3460', bar: '#e94560', text: '#ffffff', fontSize: 10 },
                    room: { bg: '#0f3460', bar: '#f39c12', text: '#ffffff', fontSize: 10 },
                    histogram: { bg: '#16213e', bar: '#e94560', text: '#ffffff', fontSize: 9 }
                }
            },
            nature: {
                name: 'Nature',
                description: 'Natürliche Grün- und Erdtöne',
                config: {
                    sidebar: { bg: '#2d5016', text: '#a8e6cf', fontSize: 12 },
                    header: { bg: '#5d4e75', text: '#ffffff', fontSize: 10 },
                    master: { bg: '#134e5e', bar: '#71b280', text: '#ffffff', fontSize: 10 },
                    room: { bg: '#134e5e', bar: '#ffd3a5', text: '#ffffff', fontSize: 10 },
                    histogram: { bg: '#5d4e75', bar: '#a8e6cf', text: '#ffffff', fontSize: 9 }
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

        // Theme visuell markieren
        document.querySelectorAll('.theme-card').forEach(card => {
            card.classList.remove('active');
        });
        document.querySelector(`[data-theme="${themeName}"]`).classList.add('active');

        // Konfiguration übernehmen
        this.currentConfig = JSON.parse(JSON.stringify(theme.config));
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
        document.getElementById('master-text').value = this.currentConfig.master.text;
        document.getElementById('master-font-size').value = this.currentConfig.master.fontSize;
        document.getElementById('master-font-size-value').textContent = this.currentConfig.master.fontSize + 'px';

        // Room
        document.getElementById('room-bg').value = this.currentConfig.room.bg;
        document.getElementById('room-bar').value = this.currentConfig.room.bar;
        document.getElementById('room-text').value = this.currentConfig.room.text;
        document.getElementById('room-font-size').value = this.currentConfig.room.fontSize;
        document.getElementById('room-font-size-value').textContent = this.currentConfig.room.fontSize + 'px';

        // Histogram
        document.getElementById('histogram-bg').value = this.currentConfig.histogram.bg;
        document.getElementById('histogram-bar').value = this.currentConfig.histogram.bar;
        document.getElementById('histogram-text').value = this.currentConfig.histogram.text;
        document.getElementById('histogram-font-size').value = this.currentConfig.histogram.fontSize;
        document.getElementById('histogram-font-size-value').textContent = this.currentConfig.histogram.fontSize + 'px';
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
        this.currentConfig.master.text = document.getElementById('master-text').value;
        this.currentConfig.master.fontSize = parseInt(document.getElementById('master-font-size').value);

        // Room
        this.currentConfig.room.bg = document.getElementById('room-bg').value;
        this.currentConfig.room.bar = document.getElementById('room-bar').value;
        this.currentConfig.room.text = document.getElementById('room-text').value;
        this.currentConfig.room.fontSize = parseInt(document.getElementById('room-font-size').value);

        // Histogram
        this.currentConfig.histogram.bg = document.getElementById('histogram-bg').value;
        this.currentConfig.histogram.bar = document.getElementById('histogram-bar').value;
        this.currentConfig.histogram.text = document.getElementById('histogram-text').value;
        this.currentConfig.histogram.fontSize = parseInt(document.getElementById('histogram-font-size').value);

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
            master: { bg: '#2c3e50', bar: '#3498db', text: '#ffffff', fontSize: 10 },
            room: { bg: '#2c3e50', bar: '#27ae60', text: '#ffffff', fontSize: 10 },
            histogram: { bg: '#34495e', bar: '#e74c3c', text: '#ecf0f1', fontSize: 9 }
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
