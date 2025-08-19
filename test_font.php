<?php
// Test der TrueType Schriftart
$width = 960;
$height = 540;

$image = imagecreate($width, $height);
$white = imagecolorallocate($image, 255, 255, 255);
$black = imagecolorallocate($image, 0, 0, 0);

$font_path = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';

if (!file_exists($font_path)) {
    echo "Schriftart nicht gefunden: $font_path";
    exit;
}

$text = "Test Unicode: äöüß Hütte für";
$font_size = 16;

// TrueType Text zeichnen
imagettftext($image, $font_size, 0, 50, 100, $black, $font_path, $text);

// PNG speichern
$output_path = '/var/www/html/wci/erd/test_unicode.png';
if (imagepng($image, $output_path)) {
    echo "PNG erfolgreich erstellt: $output_path";
} else {
    echo "Fehler beim Erstellen der PNG-Datei";
}

imagedestroy($image);
?>
