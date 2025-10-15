<?php
/**
 * Utility Functions für Belegungsanalyse
 * Kleine Hilfsfunktionen ohne externe Abhängigkeiten
 */

/**
 * Hintergrundfarbe für Zellen basierend auf Wertänderungen
 * @param mixed $originalValue Der ursprüngliche Wert
 * @param mixed $newValue Der neue Wert
 * @return string CSS-Hintergrundfarbe
 */
function getCellBackgroundColor($originalValue, $newValue) {
    if ($originalValue == $newValue) {
        return '#f8f9fa'; // Normal grau
    } elseif ($newValue > $originalValue) {
        return '#cce5ff'; // Bläulich für Erhöhung
    } else {
        return '#ffcccc'; // Rötlich für Reduzierung
    }
}
?>
