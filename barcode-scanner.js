// Barcode-Scanner System
// Fängt globale Tastatureingaben ab und leitet sie an das Suchfeld weiter

class BarcodeScanner {
    constructor(searchInputId = 'searchInput') {
        this.searchInput = document.getElementById(searchInputId);
        this.buffer = '';
        this.lastKeyTime = 0;
        this.timeout = null;
        this.isActive = false;
        this.isScannerInput = false;
        this.fastKeysCount = 0; // Zählt schnelle Tastenanschläge

        if (!this.searchInput) {
            console.error('❌ SearchInput nicht gefunden:', searchInputId);
            return;
        }

        this.init();
    }

    init() {
        console.log('🔍 Barcode-Scanner wird initialisiert...');

        // Globaler Keydown-Listener mit höchster Priorität
        document.addEventListener('keydown', (event) => {
            this.handleKeydown(event);
        }, true); // Capture-Phase

        console.log('✅ Barcode-Scanner aktiv');
        console.log('💡 Tippen Sie ins Suchfeld oder scannen Sie einen Barcode');
        console.log('🎯 Automatisches Fokussieren deaktiviert für bessere manuelle Eingabe');
    }

    handleKeydown(event) {
        // Prüfen ob ein Input-Element fokussiert ist
        const activeElement = document.activeElement;
        const isInputFocused = activeElement && (
            activeElement.tagName === 'INPUT' ||
            activeElement.tagName === 'TEXTAREA' ||
            activeElement.contentEditable === 'true'
        );

        // Spezialbehandlung: Wenn searchInput fokussiert ist, Scanner-Logik trotzdem aktivieren
        const isSearchInputFocused = activeElement && activeElement.id === 'searchInput';

        // Wenn ein anderes Input-Feld (nicht searchInput) fokussiert ist, normale Browser-Behandlung
        if (isInputFocused && !isSearchInputFocused) {
            console.log('📝 Anderes Input-Feld fokussiert, normale Behandlung:', activeElement.id);
            return;
        }

        // Scanner-Logik für globale Eingaben oder searchInput
        const currentTime = Date.now();
        const timeDiff = currentTime - this.lastKeyTime;

        console.log('⌨️ Key:', event.key, 'TimeDiff:', timeDiff + 'ms', isSearchInputFocused ? '(SearchInput)' : '(Global)');

        // Enter beendet Eingabe
        if (event.key === 'Enter') {
            console.log('↩️ Enter - Buffer:', this.buffer);
            this.finalize();
            if (!isSearchInputFocused) {
                event.preventDefault();
            }
            return;
        }

        // Escape löscht Buffer
        if (event.key === 'Escape') {
            this.reset();
            if (!isSearchInputFocused) {
                event.preventDefault();
            }
            return;
        }

        // Spezielle Tasten ignorieren
        if (event.key === 'Shift' || event.key === 'Control' || event.key === 'Alt') {
            return;
        }

        // Normale Zeichen verarbeiten
        if (event.key.length === 1) {
            // Bei großer Pause: Reset
            if (timeDiff > 500 && this.buffer.length > 0) {
                console.log('🔄 Reset wegen Pause');
                this.reset();
            }

            // Scanner-Erkennung ZUERST prüfen
            if (timeDiff < 50 && this.buffer.length > 0) {
                this.fastKeysCount++;
                console.log('⚡ Schnelle Taste #' + this.fastKeysCount + ' (TimeDiff: ' + timeDiff + 'ms)');

                // Ab 2 schnellen Tasten = definitiv Scanner
                if (this.fastKeysCount >= 2) {
                    this.isScannerInput = true;
                    console.log('🤖 SCANNER-MODUS aktiviert!');

                    // Bei Scanner: Event stoppen um doppelte Eingabe zu vermeiden
                    if (isSearchInputFocused) {
                        event.preventDefault();
                        event.stopPropagation();
                    }
                }
            } else if (timeDiff > 150) {
                // Bei langsamer Eingabe: Normale Eingabe
                this.fastKeysCount = 0;
                this.isScannerInput = false;
            }

            // Event bei globalem Scanner IMMER stoppen
            if (!isSearchInputFocused) {
                event.preventDefault();
                event.stopPropagation();
            }

            // Buffer erweitern, aber auf max 50 Zeichen begrenzen (mehr als 20 für komplette Barcode-Aufzeichnung)
            this.buffer += event.key;
            if (this.buffer.length > 50) {
                this.buffer = this.buffer.substring(0, 50);
                console.log('✂️ Buffer auf 50 Zeichen gekürzt');
            }
            this.lastKeyTime = currentTime;

            console.log('📝 Buffer:', this.buffer, '(Scanner:', this.isScannerInput + ')');

            // Ins Suchfeld weiterleiten (nur wenn nicht bereits fokussiert) - mit 20 Zeichen Limit
            const searchTerm = this.buffer.substring(0, 20);
            if (!isSearchInputFocused) {
                this.searchInput.focus();
                this.searchInput.value = searchTerm;
            } else if (this.isScannerInput) {
                // Bei Scanner im searchInput: manuell setzen um Timing-Probleme zu vermeiden
                this.searchInput.value = searchTerm;
            }

            // Visuelles Feedback bei Scanner
            if (this.isScannerInput) {
                this.showFeedback(this.buffer, false);
            }

            // Auto-Finalize Timer
            clearTimeout(this.timeout);
            this.timeout = setTimeout(() => {
                console.log('⏰ Auto-Finalize');
                this.finalize();
            }, 200);
        }
    }

    finalize() {
        if (this.buffer.length >= 1) {
            // Prüfen ob es ein Scanner-Input war (viele schnelle Zeichen)
            if (this.isScannerInput && this.buffer.length >= 3) {
                console.log('🎯 BARCODE ERKANNT:', this.buffer);
                this.handleBarcodeScanned(this.buffer);
            } else {
                console.log('⌨️ NORMALE EINGABE:', this.buffer);
                this.handleNormalInput(this.buffer);
            }
        }
        this.reset();
    }

    handleBarcodeScanned(barcode) {
        // Begrenzen auf max 20 Zeichen von links für Suche
        const searchTerm = barcode.substring(0, 20);

        // Msgbox mit gescanntem Text (zeige Original und Suchterm)
        let message = `📊 Barcode gescannt:\n\n${barcode}`;
        if (barcode.length > 20) {
            message += `\n\nSuche mit ersten 20 Zeichen:\n${searchTerm}`;
        }
        message += `\n\n(Diese Funktion wird später erweitert)`;
        alert(message);

        // Ins Suchfeld mit begrenztem Text und suchen
        this.searchInput.value = searchTerm;
        this.triggerSearch(searchTerm);

        // Feedback mit Original-Barcode
        this.showFeedback(barcode, true);

        console.log(`🎯 Barcode gescannt: "${barcode}" -> Suche mit: "${searchTerm}"`);
    }

    handleNormalInput(text) {
        // Normale Eingabe: Direkt ins Suchfeld und suchen (auch hier 20 Zeichen max)
        const searchTerm = text.substring(0, 20);
        this.searchInput.value = searchTerm;
        this.searchInput.focus();
        this.triggerSearch(searchTerm);

        console.log(`🔍 Normale Suche gestartet: "${text}" -> Suche mit: "${searchTerm}"`);
    }

    triggerSearch(searchText) {
        // Suche auslösen
        const inputEvent = new Event('input', { bubbles: true });
        this.searchInput.dispatchEvent(inputEvent);
    }

    reset() {
        this.buffer = '';
        this.lastKeyTime = 0;
        this.fastKeysCount = 0;
        this.isScannerInput = false;
        clearTimeout(this.timeout);
        console.log('🧹 Buffer reset');
    }

    showFeedback(text, final = false) {
        const indicator = document.getElementById('connection-indicator');
        if (!indicator) return;

        const statusText = indicator.querySelector('.status-text');
        const statusDot = indicator.querySelector('.status-dot');

        if (statusText && statusDot) {
            const prefix = final ? '🎯 Barcode:' : '📊 Scanner:';
            statusText.textContent = `${prefix} ${text}`;
            statusDot.style.backgroundColor = final ? '#e74c3c' : '#2ecc71';

            if (final) {
                setTimeout(() => {
                    statusText.textContent = 'Online';
                    statusDot.style.backgroundColor = '';
                }, 3000);
            }
        }
    }
}

// Auto-Initialize nach DOM-Load
document.addEventListener('DOMContentLoaded', () => {
    // Kleine Verzögerung um sicherzustellen dass andere Scripts geladen sind
    setTimeout(() => {
        window.barcodeScanner = new BarcodeScanner('searchInput');
    }, 500);
});
