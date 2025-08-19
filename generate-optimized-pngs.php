<?php
// tisch-namen-uebersicht-noauth.php - Version ohne Authentifizierung für PNG-Generierung
require_once 'hp-db-config.php';

// Direkt das optimierte generateTischPNG verwenden
function isGDAvailable() {
    return extension_loaded('gd') && function_exists('imagecreate');
}

function generateTischPNG($tischName, $namen, $outputPath) {
    if (!isGDAvailable()) {
        error_log("GD Extension nicht verfügbar für PNG-Generierung");
        return false;
    }

    // Schriftart-Pfade überprüfen
    $font_path = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
    $font_bold_path = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
    $font_bold_italic_path = '/usr/share/fonts/truetype/liberation/LiberationSans-BoldItalic.ttf';
    $font_title_path = '/usr/share/fonts/truetype/liberation/LiberationSerif-Italic.ttf';
    
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
    
    // Schriftgrößen
    $base_title_size = 24;
    $title_size = intval($base_title_size * 1.5); // 1,5-facher Schriftgrad = 36pt
    $small_size = 12;
    
    // Überschrift "Reserviert für" mit Brush Script-ähnlicher Schrift
    $title_text = "Reserviert für";
    $title_bbox = imagettfbbox($title_size, 0, $font_title_path, $title_text);
    $title_width = $title_bbox[4] - $title_bbox[0];
    $title_x = intval(($width - $title_width) / 2);
    $title_y = 60;
    imagettftext($image, $title_size, 0, $title_x, $title_y, $black, $font_title_path, $title_text);
    
    // Linie unter Überschrift
    imageline($image, 100, 90, $width-100, 90, $gray_medium);
    
    // Namen optimal darstellen
    if (!empty($namen)) {
        // Verfügbaren Bereich berechnen
        $content_start_y = 110;
        $content_end_y = $height - 80;
        $content_height = $content_end_y - $content_start_y;
        $content_width = $width - 100;
        $name_count = count($namen);
        $line_spacing = 5;
        
        // Optimale Schriftgröße iterativ berechnen - präzise Bereichsausnutzung
        $max_font_size = 48;
        $min_font_size = 10;
        $optimal_font_size = $min_font_size;
        
        // Iteriere durch Schriftgrößen (feinere Schritte für Präzision)
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
                
                // Bounding Box für Bold-Italic berechnen
                $bbox = imagettfbbox($test_font_size, 0, $font_bold_italic_path, $full_text);
                $text_width = $bbox[4] - $bbox[0];
                $text_height = $bbox[1] - $bbox[7];
                
                $line_heights[] = $text_height;
                $max_width_needed = max($max_width_needed, $text_width);
            }
            
            // Gesamthöhe = Summe aller Zeilenhöhen + Abstände zwischen Zeilen
            $total_height_needed = array_sum($line_heights) + ($name_count - 1) * $line_spacing;
            
            // Prüfe ob alles in den verfügbaren Bereich passt
            if ($total_height_needed <= $content_height && $max_width_needed <= $content_width) {
                $optimal_font_size = $test_font_size;
                break;
            }
        }
        
        // Namen mit optimaler Schriftgröße und Bold-Italic darstellen
        $line_heights_final = [];
        
        // Alle Zeilenhöhen für finale Schriftgröße berechnen
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
        
        // Gesamthöhe aller Namen für vertikale Zentrierung
        $total_content_height = array_sum($line_heights_final) + ($name_count - 1) * $line_spacing;
        
        // Vertikal zentrieren
        $start_y = $content_start_y + ($content_height - $total_content_height) / 2;
        $current_y = $start_y;
        
        foreach ($namen as $index => $gast) {
            $name_text = $gast['name'];
            if (strlen($name_text) > 35) {
                $name_text = substr($name_text, 0, 32) . '...';
            }
            $full_text = $name_text . " (" . $gast['anzahl'] . ")";
            
            // Text horizontal zentrieren mit Bold-Italic
            $bbox = imagettfbbox($optimal_font_size, 0, $font_bold_italic_path, $full_text);
            $text_width = $bbox[4] - $bbox[0];
            $text_height = $line_heights_final[$index];
            $text_x = intval(($width - $text_width) / 2);
            
            // Namen zeichnen in Bold-Italic
            imagettftext($image, $optimal_font_size, 0, $text_x, $current_y + $text_height, $black, $font_bold_italic_path, $full_text);
            
            $current_y += $text_height + $line_spacing;
        }
    } else {
        // Fallback wenn keine Namen - vertikal und horizontal zentriert
        $no_names = "Keine Namen verfügbar";
        $no_names_bbox = imagettfbbox(20, 0, $font_bold_italic_path, $no_names);
        $no_names_width = $no_names_bbox[4] - $no_names_bbox[0];
        $no_names_height = $no_names_bbox[1] - $no_names_bbox[7];
        $no_names_x = intval(($width - $no_names_width) / 2);
        $no_names_y = intval(($height - $no_names_height) / 2);
        imagettftext($image, 20, 0, $no_names_x, $no_names_y, $gray_medium, $font_bold_italic_path, $no_names);
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

// Alle Tische generieren
try {
    // HP-Datenbankverbindung über mysqli
    $hpConn = getHpDbConnection();
    if (!$hpConn) {
        throw new Exception("Konnte keine Verbindung zur HP-Datenbank herstellen");
    }
    
    $result = $hpConn->query("SELECT DISTINCT tisch FROM a_hp_data WHERE tisch IS NOT NULL AND tisch != '' ORDER BY tisch");
    $tische = [];
    while ($row = $result->fetch_assoc()) {
        $tische[] = $row['tisch'];
    }
    
    echo "<h1>🎨 Optimierte PNG-Generierung</h1>";
    echo "<p><strong>Neue Features:</strong></p>";
    echo "<ul>";
    echo "<li>✨ Titel in Liberation Serif Italic (Brush Script-ähnlich) mit 1,5-facher Größe</li>";
    echo "<li>💪 Namen in Bold-Italic für bessere Lesbarkeit</li>";
    echo "<li>🎯 Iterative Schriftgrößenoptimierung für maximale Bereichsausnutzung</li>";
    echo "<li>📐 Präzise Bounding Box-Berechnung mit 1px-Schritten</li>";
    echo "</ul>";
    echo "<hr>";
    
    $total_generated = 0;
    
    foreach ($tische as $tisch) {
        // Namen für diesen Tisch holen (mysqli)
        $stmt = $hpConn->prepare("
            SELECT name, anzahl, bemerkung 
            FROM a_hp_data 
            WHERE tisch = ? AND name IS NOT NULL AND name != ''
            ORDER BY name
        ");
        $stmt->bind_param("s", $tisch);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $namen = [];
        while ($row = $result->fetch_assoc()) {
            $namen[] = $row;
        }
        
        $outputPath = __DIR__ . '/erd/' . $tisch . '.png';
        
        if (generateTischPNG($tisch, $namen, $outputPath)) {
            $file_size = round(filesize($outputPath) / 1024, 1);
            
            // CRC-Datei generieren
            $checksum = generateCRCFile($outputPath, $tisch, $namen);
            $crc_short = substr($checksum, 0, 8); // Kurze Version für Anzeige
            
            echo "<p>✅ <strong>$tisch</strong>: " . count($namen) . " Namen, {$file_size}KB, CRC: $crc_short</p>";
            $total_generated++;
        } else {
            echo "<p>❌ <strong>Fehler bei Tisch: $tisch</strong></p>";
        }
    }
    
    echo "<hr>";
    echo "<p><strong>🎉 Fertig!</strong> $total_generated PNG-Dateien erstellt.</p>";
    echo "<p><a href='erd/' style='font-size: 18px; padding: 10px; background: #007cba; color: white; text-decoration: none; border-radius: 5px;'>📁 Zum erd-Verzeichnis</a></p>";
    
} catch (Exception $e) {
    echo "<p><strong>❌ Fehler:</strong> " . $e->getMessage() . "</p>";
}
?>
