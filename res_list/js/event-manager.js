/* ==============================================
   EVENT MANAGER - UI EVENT HANDLING
   ============================================== */

class EventManager {
    constructor() {
        this.filters = {
            type: 'arrival',
            date: new Date().toISOString().split('T')[0],
            storno: 'no-storno',
            status: 'all',
            search: ''
        };

        this.elements = {};
        this.init();
    }

    init() {
        console.log('EventManager initialisiert');
        this.cacheElements();
        this.setupEventListeners();
        this.loadInitialFilters();
    }

    cacheElements() {
        this.elements = {
            // Filter Controls
            typeToggle: document.getElementById('type-toggle'),
            dateFilter: document.getElementById('date-filter'),
            stornoToggle: document.getElementById('storno-toggle'),
            statusToggle: document.getElementById('status-toggle'),
            searchInput: document.getElementById('search-input'),
            searchClear: document.getElementById('search-clear'),
            newReservationBtn: document.getElementById('new-reservation'),

            // Progress Indicators
            guestsProgressFill: document.getElementById('guests-progress-fill'),
            guestsProgressText: document.getElementById('guests-progress-text'),
            reservationsProgressFill: document.getElementById('reservations-progress-fill'),
            reservationsProgressText: document.getElementById('reservations-progress-text'),

            // Modals
            infoModal: document.getElementById('info-modal'),
            qrModal: document.getElementById('qr-modal'),
            newReservationModal: document.getElementById('new-reservation-modal'),

            // Loading
            loadingOverlay: document.getElementById('loading-overlay')
        };
    }

    setupEventListeners() {
        // Filter Events
        this.setupFilterEvents();

        // Table Events
        this.setupTableEvents();

        // Modal Events
        this.setupModalEvents();

        // Data Manager Events
        this.setupDataEvents();

        // Global Events
        this.setupGlobalEvents();
    }

    setupFilterEvents() {
        // Type Toggle
        if (this.elements.typeToggle) {
            this.elements.typeToggle.addEventListener('click', () => {
                const currentType = this.filters.type;
                const newType = currentType === 'arrival' ? 'departure' : 'arrival';
                this.updateFilter('type', newType);

                // Update button text
                this.elements.typeToggle.querySelector('.toggle-text').textContent =
                    newType === 'arrival' ? 'Anreise' : 'Abreise';
            });
        }

        // Date Filter
        if (this.elements.dateFilter) {
            this.elements.dateFilter.addEventListener('change', (e) => {
                this.updateFilter('date', e.target.value);
            });
        }

        // Storno Toggle
        if (this.elements.stornoToggle) {
            this.elements.stornoToggle.addEventListener('click', () => {
                const current = this.filters.storno;
                let next;

                switch (current) {
                    case 'no-storno':
                        next = 'only-storno';
                        break;
                    case 'only-storno':
                        next = 'all';
                        break;
                    default:
                        next = 'no-storno';
                }

                this.updateFilter('storno', next);
                this.updateToggleButton(this.elements.stornoToggle, {
                    'no-storno': 'Ohne Storno',
                    'only-storno': 'Nur Storno',
                    'all': 'Alle'
                }[next]);
            });
        }

        // Status Toggle
        if (this.elements.statusToggle) {
            this.elements.statusToggle.addEventListener('click', () => {
                const current = this.filters.status;
                let next;

                switch (current) {
                    case 'all':
                        next = 'checked-in';
                        break;
                    case 'checked-in':
                        next = 'pending';
                        break;
                    default:
                        next = 'all';
                }

                this.updateFilter('status', next);
                this.updateToggleButton(this.elements.statusToggle, {
                    'all': 'Alle',
                    'checked-in': 'Eingecheckt',
                    'pending': 'Ausstehend'
                }[next]);
            });
        }

        // Search Input
        if (this.elements.searchInput) {
            const debouncedSearch = Utils.debounce((value) => {
                this.updateFilter('search', value);
            }, 300);

            this.elements.searchInput.addEventListener('input', (e) => {
                debouncedSearch(e.target.value);

                // Show/hide clear button
                if (this.elements.searchClear) {
                    this.elements.searchClear.style.display =
                        e.target.value ? 'block' : 'none';
                }
            });
        }

        // Search Clear
        if (this.elements.searchClear) {
            this.elements.searchClear.addEventListener('click', () => {
                this.elements.searchInput.value = '';
                this.elements.searchClear.style.display = 'none';
                this.updateFilter('search', '');
            });
        }

        // New Reservation Button
        if (this.elements.newReservationBtn) {
            this.elements.newReservationBtn.addEventListener('click', () => {
                this.showModal('new-reservation-modal');
            });
        }
    }

    setupTableEvents() {
        // Event Delegation für Tabellen-Klicks
        if (tableManager && tableManager.tbody) {
            tableManager.tbody.addEventListener('click', (e) => {
                const target = e.target;

                // Name Klicks
                if (target.classList.contains('clickable-name')) {
                    e.preventDefault();
                    const reservationId = target.dataset.reservationId;
                    this.handleNameClick(reservationId);
                }

                // Status Klicks
                if (target.classList.contains('status-indicator')) {
                    const row = target.closest('tr');
                    if (row) {
                        const reservationId = row.dataset.id;
                        this.handleStatusClick(reservationId);
                    }
                }
            });
        }
    }

    setupModalEvents() {
        // Modal Close Buttons
        document.querySelectorAll('.modal-close').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const modalId = e.target.dataset.modal;
                if (modalId) {
                    this.hideModal(modalId);
                }
            });
        });

        // Modal Background Clicks
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    this.hideModal(modal.id);
                }
            });
        });

        // Escape Key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                this.hideAllModals();
            }
        });
    }

    setupDataEvents() {
        // Loading States
        dataManager.on('request:start', () => {
            this.showLoading();
        });

        dataManager.on('request:success', () => {
            this.hideLoading();
        });

        dataManager.on('request:error', (error) => {
            this.hideLoading();
            this.showError('Fehler beim Laden der Daten: ' + error.message);
        });

        // Data Updates
        dataManager.on('data:reservations:loaded', (data) => {
            this.updateProgressIndicators(data);
        });

        // Table Events
        Utils.eventBus.on('table:data:updated', (stats) => {
            console.log('Tabelle aktualisiert:', stats);
        });

        Utils.eventBus.on('table:filtered', (stats) => {
            console.log('Tabelle gefiltert:', stats);
        });
    }

    setupGlobalEvents() {
        // Keyboard Shortcuts
        document.addEventListener('keydown', (e) => {
            // Strg+F für Suche
            if (e.ctrlKey && e.key === 'f') {
                e.preventDefault();
                if (this.elements.searchInput) {
                    this.elements.searchInput.focus();
                }
            }

            // Strg+N für neue Reservierung
            if (e.ctrlKey && e.key === 'n') {
                e.preventDefault();
                this.showModal('new-reservation-modal');
            }
        });

        // URL Parameter überwachen
        window.addEventListener('popstate', () => {
            this.loadFiltersFromUrl();
        });
    }

    updateFilter(key, value) {
        this.filters[key] = value;

        // URL aktualisieren
        Utils.setUrlParam(key, value);

        // Filter anwenden
        this.applyFilters();

        // Event emittieren
        Utils.eventBus.emit('filters:changed', { key, value, filters: this.filters });
    }

    applyFilters() {
        // Laden von neuen Daten wenn nötig
        if (['type', 'date'].includes(Object.keys(this.filters).find(k => this.filters[k] !== this.lastAppliedFilters?.[k]))) {
            this.loadData();
        } else {
            // Nur Client-seitige Filter anwenden
            this.applyClientFilters();
        }

        this.lastAppliedFilters = { ...this.filters };
    }

    applyClientFilters() {
        if (!tableManager) return;

        tableManager.filter(reservation => {
            // Storno Filter
            if (this.filters.storno === 'no-storno' && reservation.storno) {
                return false;
            }
            if (this.filters.storno === 'only-storno' && !reservation.storno) {
                return false;
            }

            // Status Filter
            if (this.filters.status !== 'all' && reservation.status !== this.filters.status) {
                return false;
            }

            return true;
        });

        // Search anwenden
        if (this.filters.search) {
            tableManager.search(this.filters.search);
        }
    }

    loadData() {
        const { type, date } = this.filters;

        // Einfacher API-Aufruf zu unserem Test-Endpoint
        dataManager.loadReservations({
            date: date,
            type: type
        });

        // HP-Daten laden (erstmal deaktiviert)
        // dataManager.loadHpData();
    }

    loadInitialFilters() {
        // URL Parameter laden
        this.loadFiltersFromUrl();

        // UI Elemente aktualisieren
        this.updateFilterUI();

        // Initiale Daten laden
        this.loadData();
    }

    loadFiltersFromUrl() {
        const params = Utils.getUrlParams();

        Object.keys(this.filters).forEach(key => {
            if (params[key]) {
                this.filters[key] = params[key];
            }
        });
    }

    updateFilterUI() {
        if (this.elements.dateFilter) {
            this.elements.dateFilter.value = this.filters.date;
        }

        if (this.elements.searchInput) {
            this.elements.searchInput.value = this.filters.search;
        }

        // Toggle Buttons aktualisieren
        this.updateToggleButton(this.elements.typeToggle,
            this.filters.type === 'arrival' ? 'Anreise' : 'Abreise');

        this.updateToggleButton(this.elements.stornoToggle, {
            'no-storno': 'Ohne Storno',
            'only-storno': 'Nur Storno',
            'all': 'Alle'
        }[this.filters.storno]);

        this.updateToggleButton(this.elements.statusToggle, {
            'all': 'Alle',
            'checked-in': 'Eingecheckt',
            'pending': 'Ausstehend'
        }[this.filters.status]);
    }

    updateToggleButton(button, text) {
        if (button && button.querySelector('.toggle-text')) {
            button.querySelector('.toggle-text').textContent = text;
        }
    }

    handleNameClick(reservationId) {
        const reservation = tableManager.getRowData(reservationId);
        if (!reservation) return;

        // Zeige QR-Code Modal
        this.showQrCode(reservation);

        // Highlighte Zeile
        tableManager.highlightRow(reservationId);
    }

    handleStatusClick(reservationId) {
        const reservation = tableManager.getRowData(reservationId);
        if (!reservation) return;

        // Zeige Info Modal
        this.showReservationInfo(reservation);
    }

    showQrCode(reservation) {
        const qrContainer = document.getElementById('qr-container');
        if (!qrContainer) return;

        // QR Code generieren
        qrContainer.innerHTML = '';

        const qrData = {
            id: reservation.id,
            name: reservation.fullName,
            arrival: reservation.anreise,
            departure: reservation.abreise
        };

        new QRCode(qrContainer, {
            text: JSON.stringify(qrData),
            width: 256,
            height: 256,
            colorDark: '#000000',
            colorLight: '#ffffff'
        });

        this.showModal('qr-modal');
    }

    showReservationInfo(reservation) {
        const content = document.getElementById('info-modal-content');
        if (!content) return;

        content.innerHTML = `
            <h3>${Utils.escapeHtml(reservation.fullName)}</h3>
            <div class="info-grid">
                <div><strong>Anreise:</strong> ${Utils.formatDate(reservation.anreise)}</div>
                <div><strong>Abreise:</strong> ${Utils.formatDate(reservation.abreise)}</div>
                <div><strong>Gäste:</strong> ${reservation.guest_count}</div>
                <div><strong>Status:</strong> ${reservation.status}</div>
                ${reservation.herkunft ? `<div><strong>Herkunft:</strong> ${Utils.escapeHtml(reservation.herkunft)}</div>` : ''}
                ${reservation.bemerkung ? `<div><strong>Bemerkung:</strong> ${Utils.escapeHtml(reservation.bemerkung)}</div>` : ''}
            </div>
        `;

        this.showModal('info-modal');
    }

    showModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }
    }

    hideModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.add('hidden');
            document.body.style.overflow = '';
        }
    }

    hideAllModals() {
        document.querySelectorAll('.modal:not(.hidden)').forEach(modal => {
            this.hideModal(modal.id);
        });
    }

    showLoading() {
        if (this.elements.loadingOverlay) {
            this.elements.loadingOverlay.classList.remove('hidden');
        }
    }

    hideLoading() {
        if (this.elements.loadingOverlay) {
            this.elements.loadingOverlay.classList.add('hidden');
        }
    }

    showError(message) {
        // Einfache Error-Anzeige - kann später erweitert werden
        console.error(message);
        alert(message);
    }

    updateProgressIndicators(data) {
        if (!data.stats) return;

        const stats = data.stats;

        // Gäste Progress
        if (this.elements.guestsProgressFill && this.elements.guestsProgressText) {
            const guestPercent = stats.total_guests > 0
                ? (stats.checked_in_guests / stats.total_guests) * 100
                : 0;

            this.elements.guestsProgressFill.style.width = `${guestPercent}%`;
            this.elements.guestsProgressText.textContent =
                `${stats.checked_in_guests} / ${stats.total_guests}`;
        }

        // Reservierungen Progress
        if (this.elements.reservationsProgressFill && this.elements.reservationsProgressText) {
            const resPercent = stats.total_reservations > 0
                ? (stats.checked_in_reservations / stats.total_reservations) * 100
                : 0;

            this.elements.reservationsProgressFill.style.width = `${resPercent}%`;
            this.elements.reservationsProgressText.textContent =
                `${stats.checked_in_reservations} / ${stats.total_reservations}`;
        }
    }

    // Debugging
    getCurrentFilters() {
        return { ...this.filters };
    }

    getElementStates() {
        return Object.keys(this.elements).reduce((acc, key) => {
            const element = this.elements[key];
            acc[key] = {
                exists: !!element,
                visible: element ? !element.classList.contains('hidden') : false
            };
            return acc;
        }, {});
    }
}

// Global verfügbar machen
window.eventManager = new EventManager();
