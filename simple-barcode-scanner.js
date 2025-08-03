// Einfacher Barcode-Scanner Modal
// Nur für Modal-Dialog, keine globale Tastaturabfangung

class SimpleBarcodeScanner {
    constructor() {
        this.modal = document.getElementById('barcodeModal');
        this.input = document.getElementById('barcodeInput');
        this.status = document.getElementById('barcodeStatus');
        this.searchInput = document.getElementById('searchInput');

        this.buffer = '';
        this.lastKeyTime = 0;
        this.scannerActive = false;
        this.isSearching = false; // Verhindert mehrfache Suchen

        this.init();
    }

    init() {
        console.log('🎴 Einfacher Karten-Scanner initialisiert');

        // Barcode Button Event
        const barcodeBtn = document.getElementById('barcodeBtn');
        if (barcodeBtn) {
            barcodeBtn.addEventListener('click', () => this.openModal());
        }

        // Modal schließen Events
        const closeBtn = document.getElementById('barcodeModalClose');
        const cancelBtn = document.getElementById('barcodeCancelBtn');

        if (closeBtn) closeBtn.addEventListener('click', () => this.closeModal());
        if (cancelBtn) cancelBtn.addEventListener('click', () => this.closeModal());

        // Suchen Button
        const searchBtn = document.getElementById('barcodeSearchBtn');
        if (searchBtn) {
            searchBtn.addEventListener('click', () => this.performSearch());
        }

        // Enter-Taste für Suche
        if (this.input) {
            this.input.addEventListener('keydown', (event) => {
                if (event.key === 'Enter') {
                    this.performSearch();
                }
            });

            // Scanner-Erkennung nur im Modal
            this.input.addEventListener('keydown', (event) => {
                this.handleKeydown(event);
            });
        }

        // Modal außerhalb klicken = schließen
        if (this.modal) {
            this.modal.addEventListener('click', (event) => {
                if (event.target === this.modal) {
                    this.closeModal();
                }
            });
        }
    }

    openModal() {
        if (this.modal) {
            this.modal.style.display = 'block';
            this.input.value = '';

            // Readonly-Trick für Bildschirmtastatur-Verhinderung
            this.input.setAttribute('readonly', '');
            this.input.focus();

            // Nach kurzem Timeout readonly entfernen für Scanner-Input
            setTimeout(() => {
                this.input.removeAttribute('readonly');
            }, 100);

            this.updateStatus('Bereit zum Scannen...');
            this.scannerActive = true;
            console.log('🎴 Karten-Scanner-Modal geöffnet');
        }
    }

    closeModal() {
        if (this.modal) {
            this.modal.style.display = 'none';
            this.scannerActive = false;
            this.isSearching = false;
            this.reset();
            console.log('🎴 Karten-Scanner-Modal geschlossen');
        }
    }

    handleKeydown(event) {
        if (!this.scannerActive || this.isSearching) return;

        const currentTime = Date.now();
        const timeDiff = currentTime - this.lastKeyTime;

        // Nur normale Zeichen verfolgen
        if (event.key.length === 1) {
            // Bei großer Pause: Reset
            if (timeDiff > 300 && this.buffer.length > 0) {
                this.reset();
            }

            // Buffer erweitern, aber auf max 50 Zeichen begrenzen
            this.buffer += event.key;
            if (this.buffer.length > 50) {
                this.buffer = this.buffer.substring(0, 50);
                console.log('✂️ Scanner Buffer auf 50 Zeichen gekürzt');
            }
            this.lastKeyTime = currentTime;

            // Input-Feld nur aktualisieren wenn es sich vom Buffer unterscheidet
            // Aber Input-Feld auf 20 Zeichen für Suche begrenzen
            const displayValue = this.buffer.substring(0, 20);
            if (this.input.value !== displayValue) {
                this.input.value = displayValue;
            }

            // Scanner-Erkennung: Schnelle Eingabe
            if (timeDiff < 100 && this.buffer.length > 2) {
                this.updateStatus('📊 Scanner erkannt...', 'scanning');
                console.log('⚡ Scanner-Eingabe erkannt:', this.buffer);
            } else if (timeDiff > 200) {
                this.updateStatus('⌨️ Manuelle Eingabe', 'manual');
            }

            // Auto-Reset und Suche nach Pause (nur einmal ausführen)
            clearTimeout(this.resetTimeout);
            this.resetTimeout = setTimeout(() => {
                if (this.buffer.length >= 3 && !this.isSearching) {
                    // Stelle sicher, dass Input-Feld den finalen Buffer-Wert hat
                    this.input.value = this.buffer;
                    this.updateStatus('✅ Barcode erkannt: ' + this.buffer, 'detected');

                    // Automatische Suche wenn Scanner-Eingabe erkannt wurde und länger als 5 Zeichen
                    if (this.buffer.length > 5) {
                        // Kurze Verzögerung für automatische Suche
                        setTimeout(() => {
                            if (!this.isSearching) {
                                this.performSearch();
                            }
                        }, 300);
                    }
                }
                // Buffer NICHT zurücksetzen, bis die Suche abgeschlossen ist
            }, 400); // Etwas kürzerer Timeout
        }
    }

    performSearch() {
        if (this.isSearching) {
            console.log('🚫 Suche bereits im Gange, überspringe...');
            return;
        }

        const barcode = this.input.value.trim();
        if (!barcode) {
            alert('Bitte geben Sie einen Barcode ein!');
            return;
        }

        this.isSearching = true;
        console.log('🔍 Karten-Suche gestartet:', barcode);
        console.log('🔍 Buffer-Inhalt:', this.buffer);
        console.log('🔍 Input-Inhalt:', this.input.value);
        this.updateStatus('🔍 Suche läuft...', 'searching');

        // Suche in der Datenbank nach CardName
        fetch(`searchBarcode.php?barcode=${encodeURIComponent(barcode)}`)
            .then(response => response.json())
            .then(data => {
                this.isSearching = false;
                console.log('📦 Suchergebnis:', data);

                if (data.success && data.data && data.data.av_id) {
                    // Karte gefunden - Formular öffnen
                    const av_id = data.data.av_id;
                    const guestName = `${data.data.vorname || ''} ${data.data.nachname || ''}`.trim();

                    console.log('✅ Karte gefunden:', data.data);

                    // Modal schließen
                    this.closeModal();

                    // Reservierungsformular öffnen mit Hervorhebungsparameter (max 20 Zeichen)
                    const cardNameForHighlight = (data.data.cardName || barcode).substring(0, 20);
                    const highlightName = encodeURIComponent(cardNameForHighlight);
                    console.log(`✅ Navigiere zu Reservierung mit Highlight (ersten 20 Zeichen): "${cardNameForHighlight}"`);
                    window.location.href = `reservation.html?id=${av_id}&highlight=${highlightName}&source=barcode`;

                } else {
                    // Karte nicht gefunden
                    console.log('❌ Karte nicht gefunden:', barcode);
                    this.updateStatus('❌ Karte nicht gefunden', 'error');

                    // Fallback: Normal suchen mit max 20 Zeichen von links
                    if (this.searchInput) {
                        const searchTerm = barcode.substring(0, 20); // Max 20 Zeichen von links
                        console.log(`🔍 Fallback-Suche mit ersten 20 Zeichen: "${searchTerm}" (Original: "${barcode}")`);
                        this.searchInput.value = searchTerm;
                        const inputEvent = new Event('input', { bubbles: true });
                        this.searchInput.dispatchEvent(inputEvent);
                    }

                    // Modal nach kurzer Zeit schließen
                    setTimeout(() => {
                        this.closeModal();
                    }, 2000);
                }
            })
            .catch(error => {
                this.isSearching = false;
                console.error('❌ Fehler bei der Karten-Suche:', error);
                this.updateStatus('❌ Suchfehler', 'error');

                // Fallback: Normal suchen mit max 20 Zeichen von links
                if (this.searchInput) {
                    const searchTerm = barcode.substring(0, 20); // Max 20 Zeichen von links
                    console.log(`🔍 Fallback-Suche nach Fehler mit ersten 20 Zeichen: "${searchTerm}" (Original: "${barcode}")`);
                    this.searchInput.value = searchTerm;
                    const inputEvent = new Event('input', { bubbles: true });
                    this.searchInput.dispatchEvent(inputEvent);
                }

                // Modal nach kurzer Zeit schließen
                setTimeout(() => {
                    this.closeModal();
                }, 2000);
            });
    }

    updateStatus(text, type = '') {
        if (this.status) {
            this.status.textContent = text;
            this.status.className = 'barcode-status' + (type ? ' ' + type : '');
        }
    }

    reset() {
        this.buffer = '';
        this.lastKeyTime = 0;
        clearTimeout(this.resetTimeout);
        // Input-Feld auch leeren
        if (this.input && !this.isSearching) {
            this.input.value = '';
        }
    }
}

// Auto-Initialize nach DOM-Load
document.addEventListener('DOMContentLoaded', () => {
    // Kleine Verzögerung um sicherzustellen dass andere Scripts geladen sind
    setTimeout(() => {
        window.simpleBarcodeScanner = new SimpleBarcodeScanner();
    }, 100);
});
