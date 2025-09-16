<?php
// tisch-namen-uebersicht.php - Tisch-Namen-Übersicht
require_once __DIR__ . '/auth.php';
require_once 'hp-db-config.php';

// Authentifizierung prüfen
if (!AuthManager::checkSession()) {
    header('Location: login.html');
    exit;
}

/**
 * Prüft ob GD-Extension verfügbar ist
 */
function isGDAvailable() {
    return extension_loaded('gd') && function_exists('imagecreate');
}

/**
 * Generiert ein BMP-Bild für einen Tisch (nur wenn GD verfügbar)
 */
function generateTischBMP($tischName, $namen, $outputPath) {
    if (!isGDAvailable()) {
        error_log("GD Extension nicht verfügbar für BMP-Generierung");
        return false;
    }
    
    // Schriftart-Pfade überprüfen
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
    
    // Bild erstellen (TrueColor für bessere BMP-Qualität)
    $image = imagecreatetruecolor($width, $height);
    
    // Graustufen-Farben definieren
    $white = imagecolorallocate($image, 255, 255, 255);
    $black = imagecolorallocate($image, 0, 0, 0);
    $gray_light = imagecolorallocate($image, 240, 240, 240);
    $gray_medium = imagecolorallocate($image, 128, 128, 128);
    $gray_dark = imagecolorallocate($image, 64, 64, 64);
    
    // Hintergrund füllen
    imagefill($image, 0, 0, $white);
    
    // TrueType Schriftarten definieren (nach den Überprüfungen)
    // $font_path und $font_bold_path sind bereits oben definiert
    
    // Schriftgrößen (in Punkten für TrueType)
    $base_title_size = 24;
    $title_size = intval($base_title_size * 3.0); // Doppelte Schriftgröße = 72pt (3x24)
    $small_size = 12; // Kleine Schrift für Details
    
    // Überschrift "Reserviert für" mit Liberation Serif Italic Bold
    $title_text = "Reserviert für";
    $title_bbox = imagettfbbox($title_size, 0, $font_bold_italic_path, $title_text);
    $title_width = $title_bbox[4] - $title_bbox[0];
    $title_x = intval(($width - $title_width) / 2);
    $title_y = 80;
    imagettftext($image, $title_size, 0, $title_x, $title_y, $black, $font_bold_italic_path, $title_text);
    
    // Dicke Linie unter Überschrift
    imagesetthickness($image, 3);
    imageline($image, 100, 90, $width-100, 90, $gray_medium);
    imagesetthickness($image, 1);
    
    // Namen optimal darstellen
    if (!empty($namen)) {
        $content_start_y = 110;
        $content_end_y = $height - 80;
        $content_height = $content_end_y - $content_start_y;
        $content_width = $width - 100;
        
        $name_count = count($namen);
        
        // Optimale Schriftgröße berechnen
        $max_font_size = 48;
        $min_font_size = 10;
        $optimal_font_size = $min_font_size;
        $line_spacing = 5;
        
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
        
        // Namen darstellen
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
            
            imagettftext($image, $optimal_font_size, 0, $text_x, intval($current_y + $text_height), $black, $font_bold_italic_path, $full_text);
            
            $current_y += $text_height + $line_spacing;
        }
    } else {
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
    $footer_bbox = imagettfbbox($small_size, 0, $font_path, $footer_text);
    $footer_width = $footer_bbox[4] - $footer_bbox[0];
    $footer_x = intval(($width - $footer_width) / 2);
    $footer_y = $height - 30;
    imagettftext($image, $small_size, 0, $footer_x, $footer_y, $gray_medium, $font_path, $footer_text);
    
    // BMP speichern (GD unterstützt kein natives BMP, also konvertieren wir)
    $result = saveBMP($image, $outputPath);
    imagedestroy($image);
    
    return $result;
}
    $title_y = 80; // 20 Pixel nach unten verschoben
    imagettftext($image, $title_size, 0, $title_x, $title_y, $black, $font_bold_italic_path, $title_text);
    
    // Dicke Linie unter Überschrift (3-fache Stärke)
    imagesetthickness($image, 3);
    imageline($image, 100, 90, $width-100, 90, $gray_medium);
    imagesetthickness($image, 1); // Zurück auf normale Dicke
    
    // Namen optimal darstellen
    if (!empty($namen)) {
        // Verfügbaren Bereich berechnen
        $content_start_y = 110;
        $content_end_y = $height - 80; // Platz für Fußzeile lassen
        $content_height = $content_end_y - $content_start_y;
        $content_width = $width - 100; // 50px Rand links und rechts
        
        // Anzahl der Namen zählen
        $name_count = count($namen);
        
        // Optimale Schriftgröße iterativ berechnen - präzise Bereichsausnutzung
        $max_font_size = 48;  // Größere Maximalschrift für bessere Ausnutzung
        $min_font_size = 10;
        $optimal_font_size = $min_font_size;
        $line_spacing = 5; // Minimaler Abstand zwischen Zeilen
        
        // Iteriere durch Schriftgrößen (feinere Schritte für Präzision)
        for ($test_font_size = $max_font_size; $test_font_size >= $min_font_size; $test_font_size -= 1) {
            $total_height_needed = 0;
            $max_width_needed = 0;
            $line_heights = []; // Sammle individuelle Zeilenhöhen
            
            foreach ($namen as $gast) {
                $name_text = $gast['name'];
                if (strlen($name_text) > 35) {
                    $name_text = substr($name_text, 0, 32) . '...';
                }
                $full_text = $name_text . " (" . $gast['anzahl'] . ")";
                
                // Bounding Box für Bold-Italic berechnen
                $bbox = imagettfbbox($test_font_size, 0, $font_bold_italic_path, $full_text);
                $text_width = $bbox[4] - $bbox[0];
                $text_height = $bbox[1] - $bbox[7]; // Präzise Höhe von Baseline
                
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
        $line_heights_final = []; // Für präzise Zentrierung
        
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
            $text_height = $line_heights_final[$index]; // Verwende vorab berechnete Höhe
            $text_x = intval(($width - $text_width) / 2);
            
            // Namen zeichnen in Bold-Italic
            imagettftext($image, $optimal_font_size, 0, $text_x, intval($current_y + $text_height), $black, $font_bold_italic_path, $full_text);
            
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
    $footer_bbox = imagettfbbox($small_size, 0, $font_path, $footer_text);
    $footer_width = $footer_bbox[4] - $footer_bbox[0];
    $footer_x = intval(($width - $footer_width) / 2);
    $footer_y = $height - 30;
    imagettftext($image, $small_size, 0, $footer_x, $footer_y, $gray_medium, $font_path, $footer_text);
    
    // BMP speichern (GD unterstützt kein natives BMP, also konvertieren wir)
    $result = saveBMP($image, $outputPath);
    imagedestroy($image);
    
    return $result;
}

/**
 * Generiert HTML-basierte "Bild"-Vorschau als Alternative
 */
function generateHTMLPreview($tischName, $namen) {
    $html = '<div class="html-preview" data-tisch="' . htmlspecialchars($tischName) . '">';
    $html .= '<div class="preview-header">Reserviert für</div>';
    $html .= '<div class="preview-tisch">' . htmlspecialchars($tischName) . '</div>';
    $html .= '<div class="preview-line"></div>';
    
    if (!empty($namen)) {
        foreach ($namen as $gast) {
            $html .= '<div class="preview-name">';
            $html .= htmlspecialchars($gast['name']) . ' (' . intval($gast['anzahl']) . ')';
            $html .= '</div>';
            if (!empty($gast['bemerkung']) && $gast['bemerkung'] !== '-') {
                $html .= '<div class="preview-remark">' . htmlspecialchars($gast['bemerkung']) . '</div>';
            }
        }
    } else {
        $html .= '<div class="preview-name">Keine Namen verfügbar</div>';
    }
    
    $html .= '<div class="preview-footer">Franz-Senn-Hütte</div>';
    $html .= '</div>';
    
    return $html;
}

// Daten von HP-Datenbank laden
function getTischNamenUebersicht() {
    $hpConn = getHpDbConnection();
    if (!$hpConn) {
        return ['error' => 'HP-Datenbank nicht verfügbar'];
    }
    
    try {
        // SQL-Query um Tische mit Namen zu laden
        $sql = "
            SELECT 
                t.bez AS Tisch,
                hp.iid,
                hp.nam,
                hp.anz,
                hp.bem
            FROM a_hp_data hp
            JOIN a_hp_tisch t ON hp.iid = t.iid
            WHERE hp.an <= NOW() AND hp.ab > NOW()
              AND hp.nam IS NOT NULL 
              AND hp.nam != ''
            ORDER BY t.bez, hp.nam
        ";
        
        $result = $hpConn->query($sql);
        if (!$result) {
            return ['error' => 'Query fehlgeschlagen: ' . $hpConn->error];
        }
        
        $tischNamenData = [];
        $tischGruppen = [];
        
        while ($row = $result->fetch_assoc()) {
            $tischName = $row['Tisch'];
            
            // Gruppiere nach einzelnem Tisch (nicht mehr GROUP_CONCAT)
            if (!isset($tischGruppen[$tischName])) {
                $tischGruppen[$tischName] = [];
            }
            
            $tischGruppen[$tischName][] = [
                'name' => $row['nam'],
                'anzahl' => $row['anz'],
                'bemerkung' => $row['bem'],
                'iid' => $row['iid']
            ];
        }
        
        // Konvertiere zu gewünschtem Format und generiere BMPs/Vorschauen
        foreach ($tischGruppen as $tischName => $namen) {
            // Dateiname für BMP
            $safe_tisch_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $tischName);
            $bmp_filename = $safe_tisch_name . '.bmp';
            $bmp_path = __DIR__ . '/erd/' . $bmp_filename;
            
            // BMP generieren (nur wenn GD verfügbar)
            $bmp_generated = false;
            $bmp_exists = false;
            $use_html_preview = false;
            
            if (isGDAvailable()) {
                $bmp_generated = generateTischBMP($tischName, $namen, $bmp_path);
                $bmp_exists = file_exists($bmp_path);
                
                // CRC-Datei generieren wenn BMP erfolgreich erstellt wurde
                if ($bmp_generated && $bmp_exists) {
                    $crc_data = calculateBMPCRC($bmp_path);
                    if ($crc_data) {
                        $crc_filename = $safe_tisch_name . '.crc';
                        $crc_path = __DIR__ . '/erd/' . $crc_filename;
                        saveCRCFile($crc_path, $crc_data);
                    }
                }
            } else {
                // Fallback: HTML-Vorschau verwenden
                $use_html_preview = true;
                error_log("GD Extension nicht verfügbar - verwende HTML-Vorschau für Tisch: " . $tischName);
            }
            
            $tischNamenData[] = [
                'tisch' => $tischName,
                'namen' => $namen,
                'namen_count' => count($namen),
                'bmp_filename' => $bmp_filename,
                'bmp_exists' => $bmp_exists,
                'bmp_generated' => $bmp_generated,
                'use_html_preview' => $use_html_preview,
                'html_preview' => $use_html_preview ? generateHTMLPreview($tischName, $namen) : null
            ];
        }
        
        return $tischNamenData;
        
    } catch (Exception $e) {
        return ['error' => 'Fehler beim Laden der Tisch-Namen-Daten: ' . $e->getMessage()];
    }
}

$tischNamenData = getTischNamenUebersicht();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tisch-Namen-Übersicht - Franz-Senn-Hütte</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: #f8f9fa;
            color: #2c3e50;
            line-height: 1.6;
        }
        
        .back-button {
            background: #6c757d;
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            text-decoration: none;
            font-size: 0.9rem;
            transition: background-color 0.2s;
        }
        
        .back-button:hover {
            background: #5a6268;
            text-decoration: none;
            color: white;
        }
        
        .container {
            max-width: 100vw;
            margin: 0;
            padding: 0;
            height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        /* Ribbon-Style Button Bar */
        .ribbon-bar {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-bottom: 2px solid #dee2e6;
            padding: 8px 12px;
            display: flex;
            gap: 8px;
            align-items: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            min-height: 56px;
        }
        
        .ribbon-button {
            width: 32px;
            height: 32px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            color: #495057;
            transition: all 0.2s ease;
            position: relative;
            text-decoration: none;
        }
        
        .ribbon-button:hover {
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            border-color: #2196f3;
            color: #1976d2;
            transform: translateY(-1px);
            box-shadow: 0 2px 6px rgba(0,0,0,0.15);
        }
        
        .ribbon-button:active {
            transform: translateY(0);
            box-shadow: 0 1px 3px rgba(0,0,0,0.2);
        }
        
        .ribbon-button-tooltip {
            position: absolute;
            bottom: -30px;
            left: 50%;
            transform: translateX(-50%);
            background: #2c3e50;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            white-space: nowrap;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s ease;
            z-index: 1000;
        }
        
        .ribbon-button:hover .ribbon-button-tooltip {
            opacity: 1;
        }
        
        /* Separator zwischen Button-Gruppen */
        .ribbon-separator {
            width: 1px;
            height: 24px;
            background: #ced4da;
            margin: 0 4px;
        }
        
        .error, .info {
            background: white;
            border-radius: 8px;
            padding: 2rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
        }
        
        .error h2, .info h2 {
            color: #dc3545;
            margin-bottom: 1rem;
        }
        
        .info h2 {
            color: #6c757d;
        }
        
        .table-container {
            flex: 1;
            background: white;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            height: calc(100vh - 56px); /* Abzug der Ribbon-Höhe */
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
            border: 2px solid #adb5bd;
        }
        
        .table thead {
            position: sticky;
            top: 0;
            z-index: 10;
            background: #2ecc71;
        }
        
        .table th {
            background: #2ecc71;
            color: white;
            padding: 12px 8px;
            text-align: center;
            font-weight: 600;
            border-bottom: 2px solid #27ae60;
            border-right: 2px solid rgba(255, 255, 255, 0.3);
            font-size: 0.9rem;
            white-space: nowrap;
            line-height: 1.2;
        }
        
        .table td {
            padding: 12px 8px;
            border-bottom: 2px solid #ced4da;
            border-right: 2px solid #ced4da;
            vertical-align: top;
            line-height: 1.3;
        }
        
        .table tr:hover {
            background-color: #e3f2fd !important;
        }
        
        .table tr:last-child td {
            border-bottom: 2px solid #ced4da;
        }
        
        .table td:last-child,
        .table th:last-child {
            border-right: none;
        }
        
        /* Tischname-Spalte */
        .tisch-cell {
            text-align: center !important;
            font-size: 1.1rem !important;
            font-weight: 600 !important;
            color: #0d47a1 !important;
            background-color: #f0f8ff !important;
            width: 200px;
            max-width: 200px;
            white-space: nowrap;
        }
        
        /* Namen-Liste-Spalte */
        .namen-cell {
            text-align: left !important;
            font-size: 0.9rem;
            color: #2c3e50;
            width: 60%;
            min-width: 400px;
        }
        
        .name-item {
            padding: 4px 0;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .name-item:last-child {
            border-bottom: none;
        }
        
        .name-text {
            font-weight: 500;
            color: #2c3e50;
        }
        
        .name-details {
            font-size: 0.8rem;
            color: #6c757d;
            font-style: italic;
        }
        
        .name-count {
            background: #2ecc71;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            min-width: 24px;
            text-align: center;
        }
        
        /* Vorschau-Spalte */
        .vorschau-cell {
            text-align: center !important;
            width: 150px;
            max-width: 150px;
            font-size: 0.8rem;
            color: #6c757d;
            background-color: #f8f9fa;
            padding: 8px;
        }
        
        .preview-thumbnail {
            width: 120px;
            height: 68px; /* 960x540 Verhältnis */
            border: 2px solid #dee2e6;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.2s ease;
            object-fit: cover;
            background-color: #f8f9fa;
        }
        
        .preview-thumbnail:hover {
            border-color: #2ecc71;
            transform: scale(1.05);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .preview-button {
            background: #2ecc71;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            font-size: 0.75rem;
            cursor: pointer;
            transition: background-color 0.2s;
            margin-top: 4px;
        }
        
        .preview-button:hover {
            background: #27ae60;
        }
        
        .preview-error {
            color: #dc3545;
            font-size: 0.7rem;
            font-style: italic;
        }
        
        /* HTML-Vorschau als Alternative zu BMP */
        .html-preview {
            width: 120px;
            height: 68px;
            border: 2px solid #dee2e6;
            border-radius: 4px;
            background: linear-gradient(to bottom, #f8f9fa 0%, #e9ecef 100%);
            font-family: Arial, sans-serif;
            font-size: 8px;
            color: #333;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s ease;
            overflow: hidden;
            position: relative;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .html-preview:hover {
            border-color: #2ecc71;
            transform: scale(1.05);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        
        .preview-header {
            font-weight: bold;
            font-size: 7px;
            margin: 3px 0 1px 0;
            color: #000;
        }
        
        .preview-tisch {
            font-weight: bold;
            font-size: 9px;
            color: #333;
            margin-bottom: 2px;
        }
        
        .preview-line {
            width: 80%;
            height: 1px;
            background: #ccc;
            margin: 2px auto;
        }
        
        .preview-name {
            font-size: 6px;
            margin: 1px 2px;
            line-height: 1.1;
            color: #000;
        }
        
        .preview-remark {
            font-size: 5px;
            color: #666;
            font-style: italic;
            margin: 0 2px 1px 2px;
        }
        
        .preview-footer {
            position: absolute;
            bottom: 2px;
            left: 0;
            right: 0;
            font-size: 5px;
            color: #999;
        }
        
        /* GD-Status Anzeige */
        .gd-status {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 4px;
            padding: 8px 12px;
            margin: 10px;
            font-size: 0.85rem;
            color: #856404;
        }
        
        .gd-status.error {
            background: #f8d7da;
            border-color: #f5c6cb;
            color: #721c24;
        }
        
        .gd-status.success {
            background: #d4edda;
            border-color: #c3e6cb;
            color: #155724;
        }
        
        /* Modal für Bildvorschau */
        .image-modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(5px);
        }
        
        .image-modal.active {
            display: flex;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.3s ease;
        }
        
        .modal-content-img {
            max-width: 90%;
            max-height: 90%;
            border-radius: 8px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.5);
        }
        
        .modal-close {
            position: absolute;
            top: 20px;
            right: 30px;
            color: white;
            font-size: 40px;
            font-weight: bold;
            cursor: pointer;
            z-index: 1001;
        }
        
        .modal-close:hover {
            opacity: 0.7;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        /* Zebra-Streifen */
        .table tbody tr:nth-child(even) {
            background-color: #f8f9fa;
        }
        
        .table tbody tr:nth-child(odd) {
            background-color: #ffffff;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .table-container {
                height: calc(100vh - 56px);
            }
            
            .table {
                font-size: 0.8rem;
                min-width: 1000px;
            }
            
            .table th,
            .table td {
                padding: 8px 6px;
            }
            
            .tisch-cell {
                font-size: 1rem !important;
            }
            
            .namen-cell {
                font-size: 0.8rem;
                min-width: 300px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if (isset($tischNamenData['error'])): ?>
            <div class="error">
                <h2>Fehler</h2>
                <p><?php echo htmlspecialchars($tischNamenData['error']); ?></p>
                <a href="index.php" class="back-button">← Zurück zum Dashboard</a>
            </div>
        <?php elseif (empty($tischNamenData)): ?>
            <div class="info">
                <h2>Keine Daten</h2>
                <p>Keine Tische mit Namen gefunden.</p>
                <a href="index.php" class="back-button">← Zurück zum Dashboard</a>
            </div>
        <?php else: ?>
            <!-- Ribbon Button Bar -->
            <div class="ribbon-bar">
                <a href="index.php" class="ribbon-button" title="Zurück zum Dashboard">
                    <span>←</span>
                    <div class="ribbon-button-tooltip">Dashboard</div>
                </a>
                
                <div class="ribbon-separator"></div>
                
                <a href="tisch-uebersicht.php" class="ribbon-button" title="Zur Tischübersicht">
                    <span>📋</span>
                    <div class="ribbon-button-tooltip">Tischübersicht</div>
                </a>
                
                <button class="ribbon-button" onclick="refreshData()" title="Daten aktualisieren">
                    <span>⟲</span>
                    <div class="ribbon-button-tooltip">Aktualisieren</div>
                </button>
            </div>
            
            <!-- GD Extension Status -->
            <?php if (!isGDAvailable()): ?>
                <div class="gd-status error">
                    ⚠️ <strong>GD Extension nicht verfügbar:</strong> BMP-Generierung deaktiviert. Verwende HTML-Vorschau als Alternative.
                    <br><small>Für BMP-Support: <code>sudo apt-get install php-gd</code> und Webserver neu starten.</small>
                </div>
            <?php else: ?>
                <div class="gd-status success">
                    ✅ <strong>GD Extension aktiv:</strong> BMP-Generierung verfügbar.
                </div>
            <?php endif; ?>
            
            <!-- Tabelle -->
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Tischname</th>
                            <th>Namen (Gäste)</th>
                            <th>Vorschau</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tischNamenData as $row): ?>
                            <tr>
                                <!-- Tischname -->
                                <td class="tisch-cell">
                                    <?php echo htmlspecialchars($row['tisch']); ?>
                                </td>
                                
                                <!-- Namen-Liste -->
                                <td class="namen-cell">
                                    <?php if (!empty($row['namen'])): ?>
                                        <?php foreach ($row['namen'] as $gast): ?>
                                            <div class="name-item">
                                                <div>
                                                    <span class="name-text"><?php echo htmlspecialchars($gast['name']); ?></span>
                                                    <?php if (!empty($gast['bemerkung']) && $gast['bemerkung'] !== '-'): ?>
                                                        <div class="name-details"><?php echo htmlspecialchars($gast['bemerkung']); ?></div>
                                                    <?php endif; ?>
                                                </div>
                                                <span class="name-count"><?php echo intval($gast['anzahl']); ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="name-item">
                                            <span class="name-text" style="color: #6c757d; font-style: italic;">Keine Namen vorhanden</span>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                
                                <!-- Vorschau (BMP oder HTML-Alternative) -->
                                <td class="vorschau-cell">
                                    <?php if ($row['use_html_preview']): ?>
                                        <!-- HTML-Vorschau als GD-Alternative -->
                                        <div onclick="showHTMLModal('<?php echo htmlspecialchars($row['tisch']); ?>')">
                                            <?php echo $row['html_preview']; ?>
                                        </div>
                                        <button class="preview-button" 
                                                onclick="downloadHTMLAsImage('<?php echo htmlspecialchars($row['tisch']); ?>')">
                                            📄 HTML
                                        </button>
                                    <?php elseif ($row['bmp_exists'] && $row['bmp_generated']): ?>
                                        <!-- BMP-Vorschau -->
                                        <img src="erd/<?php echo htmlspecialchars($row['bmp_filename']); ?>?<?php echo time(); ?>" 
                                             alt="Tisch <?php echo htmlspecialchars($row['tisch']); ?>" 
                                             class="preview-thumbnail"
                                             onclick="showImageModal('erd/<?php echo htmlspecialchars($row['bmp_filename']); ?>', '<?php echo htmlspecialchars($row['tisch']); ?>')">
                                        <br>
                                        <button class="preview-button" 
                                                onclick="downloadImage('erd/<?php echo htmlspecialchars($row['bmp_filename']); ?>', '<?php echo htmlspecialchars($row['tisch']); ?>')">
                                            💾 BMP
                                        </button>
                                    <?php elseif ($row['bmp_generated'] === false): ?>
                                        <!-- BMP-Fehler -->
                                        <div class="preview-error">
                                            Fehler beim<br>Generieren
                                        </div>
                                        <button class="preview-button" onclick="regenerateImage('<?php echo htmlspecialchars($row['tisch']); ?>')">
                                            🔄 Neu generieren
                                        </button>
                                    <?php else: ?>
                                        <!-- Allgemeiner Fehler -->
                                        <div class="preview-error">
                                            Vorschau nicht<br>verfügbar
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Bild-Modal -->
    <div id="imageModal" class="image-modal">
        <span class="modal-close" onclick="closeImageModal()">&times;</span>
        <img id="modalImage" class="modal-content-img" src="" alt="">
    </div>

    <script>
        // Auto-refresh alle 5 Minuten
        setTimeout(() => {
            window.location.reload();
        }, 5 * 60 * 1000);
        
        // Daten manuell aktualisieren
        function refreshData() {
            console.log('Aktualisiere Tisch-Namen-Daten...');
            window.location.reload();
        }
        
        // Bild-Modal öffnen
        function showImageModal(imagePath, tischName) {
            const modal = document.getElementById('imageModal');
            const modalImg = document.getElementById('modalImage');
            
            modalImg.src = imagePath;
            modalImg.alt = 'Tisch ' + tischName;
            modal.classList.add('active');
            
            // ESC-Taste zum Schließen
            document.addEventListener('keydown', handleModalKeydown);
        }
        
        // Bild-Modal schließen
        function closeImageModal() {
            const modal = document.getElementById('imageModal');
            modal.classList.remove('active');
            document.removeEventListener('keydown', handleModalKeydown);
        }
        
        // ESC-Taste Handler
        function handleModalKeydown(e) {
            if (e.key === 'Escape') {
                closeImageModal();
            }
        }
        
        // Bild herunterladen
        function downloadImage(imagePath, tischName) {
            const link = document.createElement('a');
            link.href = imagePath;
            link.download = 'Tisch_' + tischName + '.bmp';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
        
        // Bild neu generieren
        function regenerateImage(tischName) {
            console.log('Regeneriere Bild für Tisch:', tischName);
            // Könnte AJAX-Request sein, für jetzt einfach reload
            window.location.reload();
        }
        
        // HTML-Modal für Vorschau
        function showHTMLModal(tischName) {
            console.log('Zeige HTML-Vorschau für Tisch:', tischName);
            alert('HTML-Vorschau für ' + tischName + '\n\nHier könnte eine vergrößerte HTML-Version angezeigt werden.');
        }
        
        // HTML als "Bild" herunterladen (Platzhalter)
        function downloadHTMLAsImage(tischName) {
            console.log('HTML-Export für Tisch:', tischName);
            alert('HTML-Export für ' + tischName + '\n\nHier könnte die HTML-Vorschau als PDF/Bild exportiert werden.');
        }
        
        // Click außerhalb Modal zum Schließen
        document.getElementById('imageModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeImageModal();
            }
        });
        
        // Tastatur-Navigation
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Backspace') {
                window.location.href = 'index.php';
            } else if (e.key === 'F5') {
                e.preventDefault();
                refreshData();
            }
        });
    </script>
</body>
</html>
