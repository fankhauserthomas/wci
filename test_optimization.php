<?php
// Schneller Test der neuen Schriftarten und Optimierung
require_once 'hp-db-config.php';

// Test-Daten
$testNamen = [
    ['name' => 'Max Mustermann', 'anzahl' => 2],
    ['name' => 'Anna Müller', 'anzahl' => 1],
    ['name' => 'Hans Übermäßig Langer Nachname', 'anzahl' => 4],
    ['name' => 'Öäü Test Unicode', 'anzahl' => 3],
    ['name' => 'Kurz', 'anzahl' => 1]
];

// TrueType Schriftarten definieren
$font_path = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
$font_bold_italic_path = '/usr/share/fonts/truetype/liberation/LiberationSans-BoldItalic.ttf';
$font_title_path = '/usr/share/fonts/truetype/liberation/LiberationSerif-Italic.ttf';

echo "<h1>Schriftarten-Test</h1>";

if (!file_exists($font_bold_italic_path)) {
    echo "<p>❌ Bold-Italic nicht gefunden: $font_bold_italic_path</p>";
} else {
    echo "<p>✅ Bold-Italic gefunden: $font_bold_italic_path</p>";
}

if (!file_exists($font_title_path)) {
    echo "<p>❌ Titel-Schrift nicht gefunden: $font_title_path</p>";
} else {
    echo "<p>✅ Titel-Schrift gefunden: $font_title_path</p>";
}

// Bildgröße
$width = 960;
$height = 540;

// Bild erstellen
$image = imagecreate($width, $height);

// Farben
$white = imagecolorallocate($image, 255, 255, 255);
$black = imagecolorallocate($image, 0, 0, 0);
$gray_medium = imagecolorallocate($image, 128, 128, 128);
$gray_dark = imagecolorallocate($image, 64, 64, 64);

// Hintergrund
imagefill($image, 0, 0, $white);
imagerectangle($image, 10, 10, $width-10, $height-10, $gray_dark);

// Titel mit neuer Schrift (1,5x Größe)
$title_text = "Reserviert für";
$title_size = 36; // 24 * 1.5
$title_bbox = imagettfbbox($title_size, 0, $font_title_path, $title_text);
$title_width = $title_bbox[4] - $title_bbox[0];
$title_x = intval(($width - $title_width) / 2);
$title_y = 60;
imagettftext($image, $title_size, 0, $title_x, $title_y, $black, $font_title_path, $title_text);

// Linie
imageline($image, 100, 90, $width-100, 90, $gray_medium);

// Optimierte Schriftgrößenberechnung
$content_start_y = 110;
$content_end_y = $height - 80;
$content_height = $content_end_y - $content_start_y;
$content_width = $width - 100;
$name_count = count($testNamen);
$line_spacing = 5;

echo "<p>Verfügbarer Bereich: {$content_width}x{$content_height}px für $name_count Namen</p>";

// Optimale Schriftgröße finden
$max_font_size = 48;
$min_font_size = 10;
$optimal_font_size = $min_font_size;

for ($test_font_size = $max_font_size; $test_font_size >= $min_font_size; $test_font_size -= 1) {
    $total_height_needed = 0;
    $max_width_needed = 0;
    $line_heights = [];
    
    foreach ($testNamen as $gast) {
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

echo "<p><strong>Optimale Schriftgröße: {$optimal_font_size}pt</strong></p>";

// Namen rendern
$line_heights_final = [];
foreach ($testNamen as $gast) {
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

foreach ($testNamen as $index => $gast) {
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

// Fußzeile
$footer_text = "Franz-Senn-Hütte - " . date('d.m.Y H:i');
$footer_size = 12;
$footer_bbox = imagettfbbox($footer_size, 0, $font_path, $footer_text);
$footer_width = $footer_bbox[4] - $footer_bbox[0];
$footer_x = intval(($width - $footer_width) / 2);
$footer_y = $height - 30;
imagettftext($image, $footer_size, 0, $footer_x, $footer_y, $gray_medium, $font_path, $footer_text);

// PNG speichern
$output_path = '/var/www/html/wci/erd/TEST_optimized.png';
if (imagepng($image, $output_path)) {
    echo "<p>✅ <strong>PNG erfolgreich erstellt:</strong> $output_path</p>";
    echo "<p>📊 <strong>Bereichsausnutzung:</strong> {$total_content_height}px von {$content_height}px verfügbar (" . round($total_content_height/$content_height*100, 1) . "%)</p>";
} else {
    echo "<p>❌ Fehler beim Erstellen der PNG-Datei</p>";
}

imagedestroy($image);

echo "<p><a href='erd/TEST_optimized.png' target='_blank'>🖼️ PNG ansehen</a></p>";
?>
