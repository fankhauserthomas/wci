// Automatischer Barcode-Scanner
// Erkennt Barcodes anhand von Präfix "{" und Suffix "}"
// Keine Modals, keine Buttons - vollautomatisch

class AutoBarcodeScanner {
    constructor() {
        this.buffer = '';
        this.scannerActive = false;
        this.searchInput = document.getElementById('searchInput');

        // Prüfe welche Seite aktiv ist
        this.isReservationPage = window.location.pathname.includes('reservation.html');
        this.isReservationListPage = window.location.pathname.includes('reservierungen.html') ||
            window.location.pathname.includes('index.html') ||
            document.getElementById('searchInput') !== null;

        if (!this.searchInput && !this.isReservationPage) {
            console.error('❌ SearchInput nicht gefunden und keine Reservierungsseite');
            return;
        }

        this.init();
    }

    init() {
        if (this.isReservationPage) {
            console.log('🔍 Automatischer Barcode-Scanner initialisiert (Reservierungsseite)');
        } else {
            console.log('🔍 Automatischer Barcode-Scanner initialisiert (Suchseite)');
        }

        // Globaler Keydown-Listener
        document.addEventListener('keydown', (event) => {
            this.handleKeydown(event);
        }, true);

        console.log('✅ Barcode-Scanner wartet auf "{...}" Pattern');
    }

    handleKeydown(event) {
        // Prüfen ob ein Input-Element fokussiert ist (außer searchInput)
        const activeElement = document.activeElement;
        const isOtherInputFocused = activeElement &&
            (activeElement.tagName === 'INPUT' || activeElement.tagName === 'TEXTAREA') &&
            activeElement.id !== 'searchInput';

        // Wenn anderes Input-Feld fokussiert ist, normale Browser-Behandlung
        if (isOtherInputFocused) {
            return;
        }

        // Nur normale Zeichen verarbeiten
        if (event.key.length === 1) {
            const char = event.key;

            // Barcode-Start erkennen: "{"
            if (char === '{') {
                this.startBarcodeCapture();
                event.preventDefault();
                return;
            }

            // Wenn Scanner aktiv ist
            if (this.scannerActive) {
                // Barcode-Ende erkennen: "}"
                if (char === '}') {
                    this.finalizeBarcodeCapture();
                    event.preventDefault();
                    return;
                }

                // Barcode-Zeichen sammeln
                this.buffer += char;
                event.preventDefault();
                console.log('📊 Barcode-Zeichen:', char, '| Buffer:', this.buffer);
                return;
            }
        }

        // Escape-Taste: Scanner-Modus abbrechen
        if (event.key === 'Escape' && this.scannerActive) {
            this.cancelBarcodeCapture();
            event.preventDefault();
        }
    }

    startBarcodeCapture() {
        this.scannerActive = true;
        this.buffer = '';
        console.log('🎯 Barcode-Erfassung gestartet...');

        // Visuelles Feedback
        this.showStatus('📊 Barcode wird gelesen...', '#3498db');
    }

    finalizeBarcodeCapture() {
        if (!this.scannerActive) return;

        console.log('✅ Barcode komplett:', this.buffer);

        // Barcode verarbeiten
        if (this.buffer.length > 0) {
            this.processBarcodeData(this.buffer);
        } else {
            console.log('⚠️ Leerer Barcode ignoriert');
        }

        this.resetScanner();
    }

    cancelBarcodeCapture() {
        console.log('❌ Barcode-Erfassung abgebrochen');
        this.showStatus('❌ Barcode abgebrochen', '#e74c3c');
        this.resetScanner();
    }

    resetScanner() {
        this.scannerActive = false;
        this.buffer = '';

        // Status nach kurzer Zeit zurücksetzen
        setTimeout(() => {
            this.clearStatus();
        }, 2000);
    }

    processBarcodeData(barcode) {
        console.log('🔍 Verarbeite Barcode:', barcode);

        // Barcode auf max 20 Zeichen für Suche begrenzen
        const searchTerm = barcode.substring(0, 20);

        // Status zeigen
        let statusMessage = `🎯 Barcode: ${barcode}`;
        if (barcode.length > 20) {
            statusMessage += ` (Suche: ${searchTerm})`;
        }
        this.showStatus(statusMessage, '#2ecc71');

        // 1. Versuche Karten-Suche in der Datenbank
        this.searchCardInDatabase(barcode, searchTerm);
    }

    searchCardInDatabase(originalBarcode, searchTerm) {
        console.log('🔍 Suche Karte in Datenbank:', originalBarcode);

        fetch(`searchBarcode.php?barcode=${encodeURIComponent(originalBarcode)}`)
            .then(response => response.json())
            .then(data => {
                console.log('📦 Karten-Suchergebnis:', data);

                if (data.success && data.data && data.data.av_id) {
                    // Karte gefunden - Reservierung öffnen
                    const av_id = data.data.av_id;
                    const guestName = `${data.data.vorname || ''} ${data.data.nachname || ''}`.trim();

                    console.log('✅ Karte gefunden für Gast:', guestName);
                    this.showStatus(`✅ Gefunden: ${guestName}`, '#27ae60');

                    // Zur Reservierung navigieren
                    setTimeout(() => {
                        const cardNameForHighlight = (data.data.cardName || originalBarcode).substring(0, 20);
                        const highlightName = encodeURIComponent(cardNameForHighlight);
                        window.location.href = `reservation.html?id=${av_id}&highlight=${highlightName}&source=barcode`;
                    }, 1000);

                } else {
                    // Karte nicht gefunden - Fallback auf Namenssuche
                    console.log('❌ Karte nicht in Datenbank gefunden');
                    this.performNameSearch(searchTerm, originalBarcode);
                }
            })
            .catch(error => {
                console.error('❌ Fehler bei Karten-Suche:', error);
                this.performNameSearch(searchTerm, originalBarcode);
            });
    }

    performNameSearch(searchTerm, originalBarcode) {
        if (this.isReservationPage) {
            // Auf Reservierungsseite: Zur Suchseite navigieren
            console.log(`🔍 Reservierungsseite: Navigiere zur Suchseite mit: "${searchTerm}"`);

            this.showStatus(`🔍 Suche: ${searchTerm} → Navigation...`, '#f39c12');

            // Zur Reservierungsliste mit Suchterm navigieren
            setTimeout(() => {
                window.location.href = `reservierungen.html?search=${encodeURIComponent(searchTerm)}`;
            }, 1000);

        } else {
            // Auf Suchseite: Normal ins Suchfeld eintragen
            console.log(`🔍 Suchseite: Fallback-Namenssuche mit: "${searchTerm}"`);

            // Ins Suchfeld eintragen und Suche auslösen
            this.searchInput.value = searchTerm;
            this.searchInput.focus();

            // Input-Event für Live-Suche auslösen
            const inputEvent = new Event('input', { bubbles: true });
            this.searchInput.dispatchEvent(inputEvent);

            this.showStatus(`🔍 Suche: ${searchTerm}`, '#f39c12');
        }

        console.log(`✅ Namenssuche gestartet mit "${searchTerm}" (Original: "${originalBarcode}")`);
    }

    showStatus(message, color = '#3498db') {
        // Connection Status Indikator verwenden
        const indicator = document.getElementById('connection-indicator');
        if (!indicator) return;

        const statusText = indicator.querySelector('.status-text');
        const statusDot = indicator.querySelector('.status-dot');

        if (statusText && statusDot) {
            statusText.textContent = message;
            statusDot.style.backgroundColor = color;
        }
    }

    clearStatus() {
        // Status zurücksetzen
        const indicator = document.getElementById('connection-indicator');
        if (!indicator) return;

        const statusText = indicator.querySelector('.status-text');
        const statusDot = indicator.querySelector('.status-dot');

        if (statusText && statusDot) {
            statusText.textContent = 'Online';
            statusDot.style.backgroundColor = '';
        }
    }
}

// Auto-Initialize nach DOM-Load
document.addEventListener('DOMContentLoaded', () => {
    // Kleine Verzögerung um sicherzustellen dass andere Scripts geladen sind
    setTimeout(() => {
        window.autoBarcodeScanner = new AutoBarcodeScanner();
    }, 100);
});
