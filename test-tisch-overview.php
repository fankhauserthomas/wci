<?php
// test-tisch-overview.php - Test ohne Authentifizierung
require_once 'hp-db-config.php';

// Direkt die tisch-namen-uebersicht Funktionen laden, aber ohne Auth
function isGDAvailable() {
    return extension_loaded('gd') && function_exists('imagecreate');
}

function generateTischPNG($namen, $tischName, $outputPath) {
    // TrueType Schriftarten definieren
    $font_path = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
    $font_bold_path = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
    $font_bold_italic_path = '/usr/share/fonts/truetype/liberation/LiberationSans-BoldItalic.ttf';
    $font_title_path = '/usr/share/fonts/truetype/liberation/LiberationSerif-Italic.ttf'; // Brush-ähnlicher Stil
    
    if (!file_exists($font_path)) {
        error_log("Schriftart nicht gefunden: $font_path");
        return false;
    }
    
    if (!file_exists($font_bold_italic_path)) {
        error_log("Bold-Italic Schriftart nicht gefunden: $font_bold_italic_path");
        return false;
    }
    
    if (!file_exists($font_title_path)) {
        error_log("Titel-Schriftart nicht gefunden: $font_title_path");
        return false;
    }

    // Bildgröße
    $width = 960;
    $height = 540;
    
    // Bild erstellen
    $image = imagecreate($width, $height);
    
    // Graustufen-Farben definieren
    $white = imagecolorallocate($image, 255, 255, 255);
    $black = imagecolorallocate($image, 0, 0, 0);
    $gray_light = imagecolorallocate($image, 240, 240, 240);
    $gray_medium = imagecolorallocate($image, 128, 128, 128);
    $gray_dark = imagecolorallocate($image, 64, 64, 64);
    
    // Hintergrund füllen
    imagefill($image, 0, 0, $white);
    
    // Einfacher Rahmen
    imagerectangle($image, 10, 10, $width-10, $height-10, $gray_dark);
    
    // Überschrift "Reserviert für" mit Brush Script-ähnlicher Schrift
    $title_text = "Reserviert für";
    $base_title_size = 24;
    $title_size = intval($base_title_size * 1.5); // 1,5-facher Schriftgrad = 36pt
    $title_bbox = imagettfbbox($title_size, 0, $font_title_path, $title_text);
    $title_width = $title_bbox[4] - $title_bbox[0];
    $title_x = intval(($width - $title_width) / 2);
    $title_y = 60;
    imagettftext($image, $title_size, 0, $title_x, $title_y, $black, $font_title_path, $title_text);
    
    // Linie unter Überschrift
    imageline($image, 100, 90, $width-100, 90, $gray_medium);
    
    // Namen optimal darstellen mit iterativer Schriftgrößenoptimierung
    if (!empty($namen)) {
        // Verfügbaren Bereich berechnen
        $content_start_y = 110;
        $content_end_y = $height - 80;
        $content_height = $content_end_y - $content_start_y;
        $content_width = $width - 100;
        $name_count = count($namen);
        $line_spacing = 5;
        
        // Optimale Schriftgröße iterativ berechnen
        $max_font_size = 48;
        $min_font_size = 10;
        $optimal_font_size = $min_font_size;
        
        for ($test_font_size = $max_font_size; $test_font_size >= $min_font_size; $test_font_size -= 1) {
            $total_height_needed = 0;
            $max_width_needed = 0;
            $line_heights = [];
            
            foreach ($namen as $gast) {
                $name_text = $gast['name'];
                if (strlen($name_text) > 35) {
                    $name_text = substr($name_text, 0, 32) . '...';
                }
                $full_text = $name_text . " (" . $gast['anzahl'] . ")";
                
                $bbox = imagettfbbox($test_font_size, 0, $font_bold_italic_path, $full_text);
                $text_width = $bbox[4] - $bbox[0];
                $text_height = $bbox[1] - $bbox[7];
                
                $line_heights[] = $text_height;
                $max_width_needed = max($max_width_needed, $text_width);
            }
            
            $total_height_needed = array_sum($line_heights) + ($name_count - 1) * $line_spacing;
            
            if ($total_height_needed <= $content_height && $max_width_needed <= $content_width) {
                $optimal_font_size = $test_font_size;
                break;
            }
        }
        
        // Namen rendern mit optimaler Schriftgröße
        $line_heights_final = [];
        foreach ($namen as $gast) {
            $name_text = $gast['name'];
            if (strlen($name_text) > 35) {
                $name_text = substr($name_text, 0, 32) . '...';
            }
            $full_text = $name_text . " (" . $gast['anzahl'] . ")";
            $bbox = imagettfbbox($optimal_font_size, 0, $font_bold_italic_path, $full_text);
            $text_height = $bbox[1] - $bbox[7];
            $line_heights_final[] = $text_height;
        }
        
        $total_content_height = array_sum($line_heights_final) + ($name_count - 1) * $line_spacing;
        $start_y = $content_start_y + ($content_height - $total_content_height) / 2;
        $current_y = $start_y;
        
        foreach ($namen as $index => $gast) {
            $name_text = $gast['name'];
            if (strlen($name_text) > 35) {
                $name_text = substr($name_text, 0, 32) . '...';
            }
            $full_text = $name_text . " (" . $gast['anzahl'] . ")";
            
            $bbox = imagettfbbox($optimal_font_size, 0, $font_bold_italic_path, $full_text);
            $text_width = $bbox[4] - $bbox[0];
            $text_height = $line_heights_final[$index];
            $text_x = intval(($width - $text_width) / 2);
            
            // Namen in Bold-Italic zeichnen
            imagettftext($image, $optimal_font_size, 0, $text_x, $current_y + $text_height, $black, $font_bold_italic_path, $full_text);
            
            $current_y += $text_height + $line_spacing;
        }
    } else {
        // Fallback wenn keine Namen - vertikal und horizontal zentriert
        $no_names = "Keine Namen verfügbar";
        $no_names_bbox = imagettfbbox(20, 0, $font_path, $no_names);
        $no_names_width = $no_names_bbox[4] - $no_names_bbox[0];
        $no_names_height = $no_names_bbox[1] - $no_names_bbox[7];
        $no_names_x = intval(($width - $no_names_width) / 2);
        $no_names_y = intval(($height - $no_names_height) / 2);
        imagettftext($image, 20, 0, $no_names_x, $no_names_y, $gray_medium, $font_path, $no_names);
    }
    
    // Fußzeile
    $footer_text = "Franz-Senn-Hütte - " . date('d.m.Y H:i');
    $footer_size = 12;
    $footer_bbox = imagettfbbox($footer_size, 0, $font_path, $footer_text);
    $footer_width = $footer_bbox[4] - $footer_bbox[0];
    $footer_x = intval(($width - $footer_width) / 2);
    $footer_y = $height - 30;
    imagettftext($image, $footer_size, 0, $footer_x, $footer_y, $gray_medium, $font_path, $footer_text);
    
    // PNG speichern
    $result = imagepng($image, $outputPath);
    imagedestroy($image);
    
    return $result;
}

// Alle Tische aus der Datenbank holen und PNGs erstellen
try {
    $pdo = getHPDatabase();
    $stmt = $pdo->query("SELECT DISTINCT tisch FROM hp_arrangements WHERE tisch IS NOT NULL AND tisch != '' ORDER BY tisch");
    $tische = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "<h1>Test: PNG-Generierung für alle Tische</h1>";
    echo "<p>Erstelle PNGs mit neuem Format...</p>";
    
    foreach ($tische as $tisch) {
        // Namen für diesen Tisch holen
        $stmt = $pdo->prepare("
            SELECT name, anzahl, bemerkung 
            FROM hp_arrangements 
            WHERE tisch = ? AND name IS NOT NULL AND name != ''
            ORDER BY name
        ");
        $stmt->execute([$tisch]);
        $namen = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $outputPath = __DIR__ . '/erd/' . $tisch . '.png';
        
        if (generateTischPNG($namen, $tisch, $outputPath)) {
            echo "<p>✅ PNG erstellt für Tisch: $tisch (" . count($namen) . " Namen)</p>";
        } else {
            echo "<p>❌ Fehler bei Tisch: $tisch</p>";
        }
    }
    
    echo "<p><strong>Fertig! Alle PNGs wurden erstellt.</strong></p>";
    echo "<p><a href='erd/'>📁 Zum erd-Verzeichnis</a></p>";
    
} catch (Exception $e) {
    echo "<p>Fehler: " . $e->getMessage() . "</p>";
}
?>
