document.addEventListener('DOMContentLoaded', () => {
    const urlParams = new URLSearchParams(window.location.search);
    const resId = urlParams.get('id');
    const form = document.getElementById('reservationForm');
    const loadingIndicator = document.getElementById('loadingIndicator');
    const messageArea = document.getElementById('messageArea');
    const statusIndicator = document.getElementById('statusIndicator');

    let isReadonlyMode = false;
    let originalData = {};

    // Check if ID is provided
    if (!resId) {
        showError('Keine Reservierungs-ID angegeben');
        return;
    }

    // Initialize form
    initializeForm();

    // Invoice toggle button event listener
    document.getElementById('invoiceBtn').addEventListener('click', function () {
        const currentValue = parseInt(this.getAttribute('data-value'));
        const newValue = currentValue === 1 ? 0 : 1;

        this.setAttribute('data-value', newValue);
        this.querySelector('.payment-text').textContent = newValue ? 'Debitor' : 'zahlt hier';

        console.log('Invoice toggled to:', newValue);
    });

    async function initializeForm() {
        try {
            // Load lookups first
            await Promise.all([
                loadArrangements(),
                loadOrigins()
            ]);

            // Then load reservation data
            await loadReservationData();

        } catch (error) {
            console.error('Error initializing form:', error);
            showError('Fehler beim Laden der Daten: ' + error.message);
        }
    }

    async function loadArrangements() {
        try {
            const arrangements = window.HttpUtils
                ? await HttpUtils.requestJsonWithLoading('getArrangements.php', {}, { retries: 2, timeout: 8000 }, 'Arrangements werden geladen...')
                : window.LoadingOverlay
                    ? await LoadingOverlay.wrapFetch(() => fetch('getArrangements.php').then(response => response.json()), 'Arrangements')
                    : await fetch('getArrangements.php').then(response => response.json());

            const select = document.getElementById('arr');
            select.innerHTML = '<option value="">Bitte wählen...</option>';

            arrangements.forEach(arr => {
                const option = document.createElement('option');
                option.value = arr.id;
                option.textContent = arr.kbez;
                select.appendChild(option);
            });
        } catch (error) {
            console.error('Error loading arrangements:', error);
            showError('Fehler beim Laden der Arrangements. Bitte Seite neu laden.');
        }
    }

    async function loadOrigins() {
        try {
            // Use origin table instead of countries
            const origins = window.HttpUtils
                ? await HttpUtils.requestJsonWithLoading('getOrigins.php', {}, { retries: 2, timeout: 8000 }, 'Herkunftsdaten werden geladen...')
                : window.LoadingOverlay
                    ? await LoadingOverlay.wrapFetch(() => fetch('getOrigins.php').then(response => response.json()), 'Herkunftsdaten')
                    : await fetch('getOrigins.php').then(response => response.json());

            const select = document.getElementById('origin');
            select.innerHTML = '<option value="">Bitte wählen...</option>';

            origins.forEach(origin => {
                const option = document.createElement('option');
                option.value = origin.id;
                option.textContent = origin.bez;
                select.appendChild(option);
            });
        } catch (error) {
            console.error('Error loading origins:', error);
            // Fallback: try countries if origins doesn't exist
            try {
                const countries = window.HttpUtils
                    ? await HttpUtils.requestJsonWithLoading('getCountries.php', {}, { retries: 2, timeout: 8000 }, 'Länderdaten werden geladen...')
                    : window.LoadingOverlay
                        ? await LoadingOverlay.wrapFetch(() => fetch('getCountries.php').then(response => response.json()), 'Länderdaten')
                        : await fetch('getCountries.php').then(response => response.json());

                const select = document.getElementById('origin');
                select.innerHTML = '<option value="">Bitte wählen...</option>';

                countries.forEach(country => {
                    const option = document.createElement('option');
                    option.value = country.id;
                    option.textContent = country.bez;
                    select.appendChild(option);
                });
            } catch (fallbackError) {
                console.error('Error loading countries as fallback:', fallbackError);
                showError('Fehler beim Laden der Herkunftsdaten. Bitte Seite neu laden.');
            }
        }
    }

    async function loadReservationData() {
        try {
            const result = window.HttpUtils
                ? await HttpUtils.requestJsonWithLoading(`getReservationDetailsFull.php?id=${resId}`, {}, { retries: 3, timeout: 10000 }, 'Reservierungsdetails werden geladen...')
                : window.LoadingOverlay
                    ? await LoadingOverlay.wrapFetch(() =>
                        fetch(`getReservationDetailsFull.php?id=${resId}`)
                            .then(response => {
                                if (!response.ok) {
                                    throw new Error('Reservierung nicht gefunden');
                                }
                                return response.json();
                            }), 'Reservierungsdetails'
                    )
                    : await fetch(`getReservationDetailsFull.php?id=${resId}`)
                        .then(response => {
                            if (!response.ok) {
                                throw new Error('Reservierung nicht gefunden');
                            }
                            return response.json();
                        });

            if (!result.success) {
                throw new Error(result.error || 'Fehler beim Laden der Daten');
            }

            const detail = result.data;

            if (!detail) {
                throw new Error('Keine Reservierungsdetails gefunden');
            }

            originalData = { ...detail };

            // Check if this is readonly mode (av_id > 0)
            isReadonlyMode = detail.av_id && parseInt(detail.av_id) > 0;

            // Populate form fields
            populateForm(detail);

            // Set readonly mode if needed
            if (isReadonlyMode) {
                setReadonlyMode();
            }

            // Update status indicator
            updateStatusIndicator(detail.storno);

            // Hide loading indicator and show form
            loadingIndicator.style.display = 'none';
            form.style.display = 'block';

        } catch (error) {
            console.error('Error loading reservation data:', error);
            showError('Fehler beim Laden der Reservierungsdaten: ' + error.message);
            loadingIndicator.style.display = 'none';
        }
    }

    function populateForm(data) {
        // Basic fields
        document.getElementById('id').value = data.id || '';
        document.getElementById('av_id').value = data.av_id || '';
        document.getElementById('nachname').value = data.nachname || '';
        document.getElementById('vorname').value = data.vorname || '';
        document.getElementById('handy').value = data.handy || '';
        document.getElementById('email').value = data.email || '';
        document.getElementById('bem').value = data.bem || '';
        document.getElementById('bem_av').value = data.bem_av || '';

        // Checkboxes
        document.getElementById('storno').checked = Boolean(data.storno);
        document.getElementById('hund').checked = Boolean(data.hund);

        // Invoice toggle button
        const invoiceBtn = document.getElementById('invoiceBtn');
        const invoiceValue = data.invoice ? 1 : 0;
        invoiceBtn.setAttribute('data-value', invoiceValue);
        invoiceBtn.querySelector('.payment-text').textContent = invoiceValue ? 'Debitor' : 'zahlt hier';

        // Dropdowns
        if (data.arr) {
            document.getElementById('arr').value = data.arr;
        }
        if (data.origin) {
            document.getElementById('origin').value = data.origin;
        }

        // Sleeping categories
        document.getElementById('lager').value = data.lager || 0;
        document.getElementById('betten').value = data.betten || 0;
        document.getElementById('dz').value = data.dz || 0;
        document.getElementById('sonder').value = data.sonder || 0;

        // Dates (convert from MySQL datetime format to HTML datetime-local format)
        if (data.anreise) {
            document.getElementById('anreise').value = convertMySQLDateToLocal(data.anreise);
        }
        if (data.abreise) {
            document.getElementById('abreise').value = convertMySQLDateToLocal(data.abreise);
        }
    }

    function setReadonlyMode() {
        const readonlyFields = [
            'nachname', 'vorname',
            'bem_av', 'handy', 'email', 'storno'
        ];

        readonlyFields.forEach(fieldId => {
            const field = document.getElementById(fieldId);
            if (field) {
                if (field.type === 'checkbox') {
                    field.disabled = true;
                } else {
                    field.readOnly = true;
                }
                field.closest('.form-group').classList.add('readonly');
            }
        });

        // Note: Schlafkategorien (lager, betten, dz, sonder), hund, anreise und abreise bleiben immer editierbar
    }

    function updateStatusIndicator(isStorno) {
        statusIndicator.innerHTML = isStorno
            ? '<span class="status-indicator status-cancelled">Storniert</span>'
            : '<span class="status-indicator status-active">Aktiv</span>';
    }

    function convertMySQLDateToLocal(mysqlDate) {
        if (!mysqlDate) return '';

        // MySQL format: 2024-01-15 14:30:00
        // HTML datetime-local format: 2024-01-15T14:30
        const date = new Date(mysqlDate);

        if (isNaN(date.getTime())) {
            // Try parsing different format
            const parts = mysqlDate.split(' ');
            if (parts.length === 2) {
                return parts[0] + 'T' + parts[1].substring(0, 5);
            }
            return '';
        }

        // Convert to local timezone and format for datetime-local input
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        const hours = String(date.getHours()).padStart(2, '0');
        const minutes = String(date.getMinutes()).padStart(2, '0');

        return `${year}-${month}-${day}T${hours}:${minutes}`;
    }

    function convertLocalDateToMySQL(localDate) {
        if (!localDate) return null;

        // HTML datetime-local format: 2024-01-15T14:30
        // MySQL format: 2024-01-15 14:30:00
        return localDate.replace('T', ' ') + ':00';
    }

    // Form submission
    form.addEventListener('submit', async (e) => {
        e.preventDefault();

        try {
            // Collect form data
            const formData = new FormData(form);
            const invoiceBtn = document.getElementById('invoiceBtn');
            const data = {
                id: resId,
                nachname: formData.get('nachname'),
                vorname: formData.get('vorname'),
                handy: formData.get('handy') || '',
                email: formData.get('email') || '',
                bem: formData.get('bem') || '',
                bem_av: formData.get('bem_av') || '',
                arr: formData.get('arr') || null,
                origin: formData.get('origin') || null,
                lager: parseInt(formData.get('lager')) || 0,
                betten: parseInt(formData.get('betten')) || 0,
                dz: parseInt(formData.get('dz')) || 0,
                sonder: parseInt(formData.get('sonder')) || 0,
                storno: formData.has('storno') ? 1 : 0,
                hund: formData.has('hund') ? 1 : 0,
                invoice: parseInt(invoiceBtn.getAttribute('data-value')) || 0,
                anreise: convertLocalDateToMySQL(formData.get('anreise')),
                abreise: convertLocalDateToMySQL(formData.get('abreise'))
            };

            // Validate required fields
            if (!data.nachname || !data.anreise || !data.abreise) {
                showError('Bitte füllen Sie alle Pflichtfelder aus');
                return;
            }

            // Validate dates
            const anreiseDate = new Date(data.anreise);
            const abreiseDate = new Date(data.abreise);

            if (abreiseDate <= anreiseDate) {
                showError('Das Abreisedatum muss nach dem Anreisedatum liegen');
                return;
            }

            // Save data
            await saveReservationData(data);

        } catch (error) {
            console.error('Error submitting form:', error);
            showError('Fehler beim Speichern: ' + error.message);
        }
    });

    async function saveReservationData(data) {
        try {
            const result = window.HttpUtils
                ? await HttpUtils.postJsonWithLoading('updateReservationDetails.php', data, { retries: 2, timeout: 10000 }, 'Reservierungsdetails werden gespeichert...')
                : window.LoadingOverlay
                    ? await LoadingOverlay.wrap(async () => {
                        const response = await fetch('updateReservationDetails.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify(data)
                        });

                        const responseText = await response.text();

                        try {
                            return JSON.parse(responseText);
                        } catch (parseError) {
                            console.error('JSON Parse Error:', parseError);
                            console.error('Response Text:', responseText);
                            throw new Error('Ungültige Server-Antwort. Bitte versuchen Sie es erneut.');
                        }
                    }, 'Reservierungsdetails werden gespeichert...')
                    : await fetch('updateReservationDetails.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify(data)
                    }).then(async response => {
                        const responseText = await response.text();

                        try {
                            return JSON.parse(responseText);
                        } catch (parseError) {
                            console.error('JSON Parse Error:', parseError);
                            console.error('Response Text:', responseText);
                            throw new Error('Ungültige Server-Antwort. Bitte versuchen Sie es erneut.');
                        }
                    });

            if (!result.success) {
                throw new Error(result.error || 'Speichern fehlgeschlagen');
            }

            showSuccess('Reservierungsdetails erfolgreich gespeichert');

            // Update status indicator if storno changed
            updateStatusIndicator(data.storno);

            // Update original data
            originalData = { ...data };

            // Auto-redirect back to previous page after 1.5 seconds
            setTimeout(() => {
                goBack();
            }, 1500);

        } catch (error) {
            throw new Error('Fehler beim Speichern: ' + error.message);
        }
    }

    function showError(message) {
        messageArea.innerHTML = `<div class="error">${message}</div>`;
        messageArea.scrollIntoView({ behavior: 'smooth' });
    }

    function showSuccess(message) {
        messageArea.innerHTML = `<div class="success">${message}</div>`;
        messageArea.scrollIntoView({ behavior: 'smooth' });

        // Auto-hide success message after 3 seconds
        setTimeout(() => {
            messageArea.innerHTML = '';
        }, 3000);
    }

    // === Navigation Status Update ===
    function updateNavigationStatus() {
        const monitor = window.connectionMonitor;
        if (!monitor) return;

        const navStatus = document.getElementById('nav-connection-status');
        if (!navStatus) return;

        const dot = navStatus.querySelector('.status-dot');
        const text = navStatus.querySelector('.status-text');

        const quality = monitor.getQuality();
        const isOnline = monitor.isOnline();

        if (!isOnline) {
            dot.style.backgroundColor = '#dc3545';
            text.textContent = 'Offline';
            navStatus.title = 'Verbindung: Offline - Klicken für Details';
        } else {
            switch (quality) {
                case 'excellent':
                case 'good':
                    dot.style.backgroundColor = '#28a745';
                    text.textContent = 'Online';
                    navStatus.title = `Verbindung: ${quality === 'excellent' ? 'Ausgezeichnet' : 'Gut'} - Klicken für Details`;
                    break;
                case 'fair':
                    dot.style.backgroundColor = '#ffc107';
                    text.textContent = 'Langsam';
                    navStatus.title = 'Verbindung: Mäßig - Klicken für Details';
                    break;
                case 'poor':
                    dot.style.backgroundColor = '#fd7e14';
                    text.textContent = 'Sehr langsam';
                    navStatus.title = 'Verbindung: Schlecht - Klicken für Details';
                    break;
                default:
                    dot.style.backgroundColor = '#6c757d';
                    text.textContent = 'Unbekannt';
                    navStatus.title = 'Verbindung: Unbekannt - Klicken für Details';
            }
        }
    }

    // Globale Funktion verfügbar machen
    window.updateNavigationStatus = updateNavigationStatus;

    // Navigation Status Click Handler
    const navStatus = document.getElementById('nav-connection-status');
    if (navStatus) {
        navStatus.addEventListener('click', () => {
            if (window.connectionMonitor && window.HttpUtils) {
                HttpUtils.showDetailedConnectionStatus(window.connectionMonitor);
            }
        });
    }

    // Update Navigation Status alle 5 Sekunden
    setInterval(updateNavigationStatus, 5000);

    // Initial Status Update nach kurzer Verzögerung
    setTimeout(updateNavigationStatus, 2000);

    // === Universelle Verbindungsstatus-Funktionen ===
    // Stelle sicher, dass updateNavigationStatus global verfügbar ist, auch wenn keine Navigation vorhanden
    if (!window.updateNavigationStatus) {
        window.updateNavigationStatus = function () {
            // Fallback für Seiten ohne Navigation-Status
            console.log('[CONNECTION] Navigation status not available on this page');
        };
    }

    // Stelle globale Connection-Update-Funktion zur Verfügung
    window.updateConnectionStatus = function () {
        if (window.connectionMonitor && window.HttpUtils) {
            window.connectionMonitor.testConnection().then(() => {
                HttpUtils.updatePermanentIndicator(window.connectionMonitor);
                if (window.updateNavigationStatus) {
                    window.updateNavigationStatus();
                }
            }).catch(() => {
                // Silent fail
            });
        }
    };
});

// Global function for back button
function goBack() {
    if (window.history.length > 1) {
        window.history.back();
    } else {
        window.location.href = 'reservierungen.html';
    }
}
