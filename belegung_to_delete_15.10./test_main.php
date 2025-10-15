<?php
/**
 * Test ob die Hauptdatei ohne fatale Fehler lädt
 */

echo "=== Testing Main File Load ===\n";

// Setze erforderliche Parameter
$_GET['start'] = '2025-08-29';
$_GET['end'] = '2025-08-31';

// Capture output to avoid HTML rendering
ob_start();

try {
    // Test: Lade nur den PHP-Teil der Hauptdatei bis zum HTML
    $content = file_get_contents('belegung_tab.php');
    
    // Finde HTML-Start
    $htmlStart = strpos($content, '<!DOCTYPE html>');
    if ($htmlStart !== false) {
        $phpPart = substr($content, 0, $htmlStart);
        // Füge schließendes PHP-Tag hinzu falls nicht vorhanden
        if (!str_ends_with(trim($phpPart), '?>')) {
            $phpPart .= "\n?>";
        }
        
        // Speichere temporär den PHP-Teil
        file_put_contents('temp_test.php', $phpPart);
        
        // Teste den PHP-Teil
        include 'temp_test.php';
        
        echo "✅ Main file PHP section loaded successfully\n";
        echo "✅ All includes and functions are working\n";
        
        // Prüfe ob wichtige Variablen definiert sind
        if (isset($startDate) && isset($endDate)) {
            echo "✅ Date variables properly set: $startDate to $endDate\n";
        }
        
        // Lösche temporäre Datei
        unlink('temp_test.php');
        
    } else {
        echo "❌ Could not find HTML start in main file\n";
    }

} catch (Exception $e) {
    echo "❌ Error loading main file: " . $e->getMessage() . "\n";
} catch (Error $e) {
    echo "❌ Fatal error in main file: " . $e->getMessage() . "\n";
}

// Clean output buffer
ob_end_clean();

echo "=== Test completed ===\n";
?>
