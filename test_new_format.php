<?php
// Direkter Test der generateTischPNG Funktion
require_once 'hp-db-config.php';

// Mock-Daten für Test
$testNamen = [
    ['name' => 'Max Mustermann', 'anzahl' => 2],
    ['name' => 'Anna Müller', 'anzahl' => 1],
    ['name' => 'Hans Übermäßig Langer Nachname', 'anzahl' => 4],
    ['name' => 'Öäü Test Unicode', 'anzahl' => 3]
];

function generateTestPNG($namen, $tischName, $outputPath) {
    // TrueType Schriftarten definieren
    $font_path = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
    $font_bold_path = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
    
    if (!file_exists($font_path)) {
        echo "Schriftart nicht gefunden: $font_path\n";
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
    $gray_medium = imagecolorallocate($image, 128, 128, 128);
    $gray_dark = imagecolorallocate($image, 64, 64, 64);
    
    // Hintergrund füllen
    imagefill($image, 0, 0, $white);
    
    // Einfacher Rahmen
    imagerectangle($image, 10, 10, $width-10, $height-10, $gray_dark);
    
    // Überschrift "Reserviert für"
    $title_text = "Reserviert für";
    $title_size = 24;
    $title_bbox = imagettfbbox($title_size, 0, $font_bold_path, $title_text);
    $title_width = $title_bbox[4] - $title_bbox[0];
    $title_x = intval(($width - $title_width) / 2);
    $title_y = 60;
    imagettftext($image, $title_size, 0, $title_x, $title_y, $black, $font_bold_path, $title_text);
    
    // Linie unter Überschrift
    imageline($image, 100, 90, $width-100, 90, $gray_medium);
    
    // Namen optimal darstellen
    if (!empty($namen)) {
        // Verfügbaren Bereich berechnen
        $content_start_y = 110;
        $content_end_y = $height - 80;
        $content_height = $content_end_y - $content_start_y;
        $content_width = $width - 100;
        
        // Optimale Schriftgröße berechnen
        $optimal_font_size = 16; // Starten mit fester Größe für Test
        
        $current_y = $content_start_y + 50;
        
        foreach ($namen as $gast) {
            $name_text = $gast['name'];
            if (strlen($name_text) > 35) {
                $name_text = substr($name_text, 0, 32) . '...';
            }
            $full_text = $name_text . " (" . $gast['anzahl'] . ")";
            
            // Text horizontal zentrieren
            $bbox = imagettfbbox($optimal_font_size, 0, $font_path, $full_text);
            $text_width = $bbox[4] - $bbox[0];
            $text_height = $bbox[1] - $bbox[7];
            $text_x = intval(($width - $text_width) / 2);
            
            // Namen zeichnen
            imagettftext($image, $optimal_font_size, 0, $text_x, $current_y, $black, $font_path, $full_text);
            
            $current_y += 40;
        }
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

// Test ausführen
$outputPath = '/var/www/html/wci/erd/TEST_new_format.png';
echo "Teste PNG-Generierung...\n";
if (generateTestPNG($testNamen, 'T1', $outputPath)) {
    echo "PNG erfolgreich erstellt: $outputPath\n";
} else {
    echo "Fehler beim Erstellen der PNG-Datei\n";
}
?>
