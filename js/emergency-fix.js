// Emergency LoadingOverlay Fix
// Dieses Script versteckt sofort alle hängenden Loading-Overlays

(function() {
    console.log('🚨 Emergency LoadingOverlay Fix loaded');
    
    function emergencyFix() {
        // 1. LoadingOverlay forceHide wenn verfügbar
        if (typeof LoadingOverlay !== 'undefined') {
            console.log('🔧 Force hiding LoadingOverlay...');
            LoadingOverlay.forceHide();
            LoadingOverlay.isVisible = false;
            LoadingOverlay.currentOperations.clear();
            LoadingOverlay.delayedOperations.clear();
            
            if (LoadingOverlay.overlay) {
                LoadingOverlay.overlay.style.display = 'none !important';
                LoadingOverlay.overlay.classList.remove('visible');
            }
        }
        
        // 2. Body-Styles zurücksetzen
        console.log('🔧 Resetting body styles...');
        document.body.style.overflow = '';
        document.body.style.userSelect = '';
        document.body.style.pointerEvents = '';
        document.body.classList.remove('loading-active');
        
        // 3. Alle verdächtigen Overlays verstecken
        console.log('🔧 Hiding suspicious overlays...');
        const suspiciousElements = document.querySelectorAll([
            '.loading-overlay',
            '.overlay',
            '[class*="loading"]',
            '[style*="position: fixed"][style*="z-index"]'
        ].join(', '));
        
        suspiciousElements.forEach(el => {
            if (el.style.zIndex > 500) {
                console.log('🗑️ Hiding element:', el);
                el.style.display = 'none';
                el.classList.remove('visible', 'active', 'show');
            }
        });
        
        // 4. Connection Status erlauben (niedrigerer z-index)
        const connectionStatus = document.querySelector('.connection-status');
        if (connectionStatus) {
            connectionStatus.style.display = '';
            connectionStatus.style.zIndex = '999';
        }
        
        console.log('✅ Emergency fix completed');
    }
    
    // Sofort ausführen
    emergencyFix();
    
    // Nach DOM-Load nochmal ausführen
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', emergencyFix);
    }
    
    // Backup: Nach 100ms nochmal
    setTimeout(emergencyFix, 100);
    
    // Globale Funktion für manuelle Ausführung
    window.emergencyFixOverlays = emergencyFix;
    
    console.log('🚨 Emergency fix ready. Call emergencyFixOverlays() manually if needed.');
})();
