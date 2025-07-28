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
            this.reset();
            console.log('🎴 Karten-Scanner-Modal geschlossen');
        }
    }

    handleKeydown(event) {
        if (!this.scannerActive) return;

        const currentTime = Date.now();
        const timeDiff = currentTime - this.lastKeyTime;

        // Nur normale Zeichen verfolgen
        if (event.key.length === 1) {
            // Bei großer Pause: Reset
            if (timeDiff > 300 && this.buffer.length > 0) {
                this.reset();
            }

            this.buffer += event.key;
            this.lastKeyTime = currentTime;

            // Scanner-Erkennung: Schnelle Eingabe
            if (timeDiff < 100 && this.buffer.length > 2) {
                this.updateStatus('📊 Scanner erkannt...', 'scanning');
                console.log('⚡ Scanner-Eingabe erkannt:', this.buffer);
            } else if (timeDiff > 200) {
                this.updateStatus('⌨️ Manuelle Eingabe', 'manual');
            }

            // Auto-Reset nach Pause
            clearTimeout(this.resetTimeout);
            this.resetTimeout = setTimeout(() => {
                if (this.buffer.length >= 3) {
                    this.updateStatus('✅ Barcode erkannt: ' + this.input.value, 'detected');
                }
                this.reset();
            }, 500);
        }
    }

    performSearch() {
        const barcode = this.input.value.trim();
        if (!barcode) {
            alert('Bitte geben Sie einen Barcode ein!');
            return;
        }

        console.log('🔍 Karten-Suche:', barcode);

        // Barcode ins Hauptsuchfeld übertragen
        if (this.searchInput) {
            this.searchInput.value = barcode;

            // Suche auslösen
            const inputEvent = new Event('input', { bubbles: true });
            this.searchInput.dispatchEvent(inputEvent);
        }

        // Modal schließen
        this.closeModal();

        // Erfolgsmeldung
        alert(`🎴 Karte gescannt:\n\n${barcode}\n\nSuche wurde gestartet.`);
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
    }
}

// Auto-Initialize nach DOM-Load
document.addEventListener('DOMContentLoaded', () => {
    // Kleine Verzögerung um sicherzustellen dass andere Scripts geladen sind
    setTimeout(() => {
        window.simpleBarcodeScanner = new SimpleBarcodeScanner();
    }, 100);
});
