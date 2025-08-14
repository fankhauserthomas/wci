<?php
// namen-import.php - Dedizierte Namen Import Seite mit intelligenter Datums-/Alters-Erkennung

// Error Reporting für Debugging (in Produktion entfernen)
error_reporting(E_ALL);
ini_set('display_errors', 0); // Keine HTML-Fehler in JSON-Responses
ini_set('log_errors', 1);

require 'config.php';

// Verwende die bestehende mysqli Verbindung aus config.php
$mysqli = $GLOBALS['mysqli'];

// Reservierungsdetails abrufen wenn res_id übergeben wurde
$res_id = $_GET['res_id'] ?? '';
$reservation = null;

if ($res_id && ctype_digit($res_id)) {
    try {
        $stmt = $mysqli->prepare("SELECT * FROM `AV-Res` WHERE id = ?");
        $stmt->bind_param("i", $res_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $reservation = $result->fetch_assoc();
        $stmt->close();
    } catch (mysqli_sql_exception $e) {
        error_log("Fehler beim Laden der Reservierung: " . $e->getMessage());
    }
}

// API Endpoints für AJAX Anfragen
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    // Verhindere HTML-Output bei Fehlern
    ob_start();
    
    try {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'preview':
                handlePreview();
                break;
            case 'save':
                handleSave();
                break;
            case 'clean':
                handleClean();
                break;
            default:
                http_response_code(400);
                echo json_encode(['error' => 'Unbekannte Aktion']);
        }
    } catch (Exception $e) {
        error_log("Namen-Import Fehler: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Server-Fehler: ' . $e->getMessage()]);
    } finally {
        ob_end_flush();
    }
    exit;
}

function handlePreview() {
    $text = $_POST['text'] ?? '';
    $cleanString = $_POST['cleanString'] ?? '';
    $format = $_POST['format'] ?? 'firstname-lastname';
    
    $parser = new NamesBirthdateParser();
    $result = $parser->parseNames($text, $cleanString, $format);
    
    echo json_encode([
        'success' => true,
        'names' => $result['names'],
        'stats' => $result['stats']
    ]);
}

function handleSave() {
    // Verwende die globale mysqli Verbindung
    $mysqli = $GLOBALS['mysqli'];
    
    // Debug logging
    error_log("handleSave() gestartet");
    
    $res_id = $_POST['res_id'] ?? '';
    $names = json_decode($_POST['names'] ?? '[]', true);
    
    error_log("res_id: $res_id, names count: " . count($names ?? []));
    
    if (!$res_id || !ctype_digit($res_id) || !is_array($names)) {
        error_log("Validation fehler: res_id=$res_id, is_array=" . is_array($names));
        http_response_code(400);
        echo json_encode(['error' => 'Ungültige Daten']);
        return;
    }
    
    // Die gebdat Spalte existiert bereits in AV-ResNamen
    $hasGebdatColumn = true;
    
    error_log("gebdat Spalte existiert: ja (bereits vorhanden)");
    
    $stmt = $mysqli->prepare("INSERT INTO `AV-ResNamen` (av_id, vorname, nachname, gebdat) VALUES (?, ?, ?, ?)");
    
    $added = 0;
    $birthdates_added = 0;
    
    foreach ($names as $entry) {
        $fullName = trim($entry['name'] ?? '');
        $birthdate = !empty($entry['birthdate']) ? $entry['birthdate'] : null;
        
        if (empty($fullName)) continue;
        
        // Verwende die bereits geparsten firstName/lastName falls verfügbar
        $vorname = isset($entry['firstName']) ? trim($entry['firstName']) : '';
        $nachname = isset($entry['lastName']) ? trim($entry['lastName']) : '';
        
        // Fallback: Namen in Vor- und Nachname aufteilen wenn nicht bereits geparst
        if (empty($vorname) && empty($nachname)) {
            $nameParts = explode(' ', $fullName, 2);
            $vorname = trim($nameParts[0]);
            $nachname = isset($nameParts[1]) ? trim($nameParts[1]) : '';
        }
        
        try {
            $stmt->bind_param("isss", $res_id, $vorname, $nachname, $birthdate);
            $stmt->execute();
            if ($birthdate) $birthdates_added++;
            $added++;
        } catch (mysqli_sql_exception $e) {
            error_log("Fehler beim Speichern: " . $e->getMessage());
            continue;
        }
    }
    
    $stmt->close();
    
    echo json_encode([
        'success' => true,
        'added' => $added,
        'birthdates_added' => $birthdates_added
    ]);
}

function handleClean() {
    $text = $_POST['text'] ?? '';
    $cleanString = $_POST['cleanString'] ?? '';
    
    if (empty($cleanString)) {
        echo json_encode(['success' => true, 'text' => $text]);
        return;
    }
    
    // Case-insensitive Bereinigung
    $cleanedText = str_ireplace($cleanString, '', $text);
    
    echo json_encode([
        'success' => true,
        'text' => $cleanedText
    ]);
}

class NamesBirthdateParser {
    
    public function parseNames($text, $cleanString = '', $format = 'firstname-lastname') {
        $names = [];
        $stats = [
            'total' => 0,
            'with_birthdate' => 0,
            'age_detected' => 0,
            'birthyear_detected' => 0,
            'date_detected' => 0
        ];
        
        if (empty($text)) {
            return ['names' => $names, 'stats' => $stats];
        }
        
        // Text bereinigen falls cleanString angegeben
        if (!empty($cleanString)) {
            $text = str_ireplace($cleanString, '', $text);
        }
        
        // Zeilen aufteilen
        $lines = array_filter(array_map('trim', explode("\n", $text)));
        
        foreach ($lines as $line) {
            $parsed = $this->parseLine($line, $format);
            if ($parsed) {
                $names[] = $parsed;
                $stats['total']++;
                
                if ($parsed['birthdate']) {
                    $stats['with_birthdate']++;
                    
                    switch ($parsed['detection_type']) {
                        case 'age':
                            $stats['age_detected']++;
                            break;
                        case 'birthyear':
                            $stats['birthyear_detected']++;
                            break;
                        case 'date':
                            $stats['date_detected']++;
                            break;
                    }
                }
            }
        }
        
        return ['names' => $names, 'stats' => $stats];
    }
    
    private function parseLine($line, $format = 'firstname-lastname') {
        if (empty($line)) return null;
        
        // Regex für Name + mögliche Datums/Altersangabe
        // Sucht nach: Name(n) [optionale Zeichen] Zahl/Datum
        $patterns = [
            // Name + Datum (verschiedene Formate)
            '/^(.+?)\s*[,\-\s]+\s*(\d{1,2}[\.\/\-]\d{1,2}[\.\/\-]\d{2,4})$/u',
            // Name + 4-stellige Jahreszahl
            '/^(.+?)\s*[,\-\s]+\s*(\d{4})$/u',
            // Name + 1-3 stellige Zahl (Alter)
            '/^(.+?)\s*[,\-\s]+\s*(\d{1,3})$/u',
            // Name + Datum in Klammern
            '/^(.+?)\s*\(\s*(\d{1,2}[\.\/\-]\d{1,2}[\.\/\-]\d{2,4})\s*\)$/u',
            // Name + Jahr in Klammern
            '/^(.+?)\s*\(\s*(\d{4})\s*\)$/u',
            // Name + Alter in Klammern
            '/^(.+?)\s*\(\s*(\d{1,3})\s*\)$/u'
        ];
        
        $name = '';
        $dateInfo = '';
        $matched = false;
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line, $matches)) {
                $name = trim($matches[1]);
                $dateInfo = trim($matches[2]);
                $matched = true;
                break;
            }
        }
        
        // Wenn kein Pattern matched, ganzer Text ist Name
        if (!$matched) {
            $name = trim($line);
        }
        
        // Namen bereinigen (mehrfache Leerzeichen, etc.)
        $name = preg_replace('/\s+/', ' ', $name);
        
        if (empty($name)) return null;
        
        // Namen je nach Format splitten
        $firstName = '';
        $lastName = '';
        
        // Kommas entfernen falls vorhanden
        $cleanedName = str_replace(',', '', $name);
        $nameParts = explode(' ', $cleanedName);
        
        if (count($nameParts) >= 2) {
            if ($format === 'lastname-firstname') {
                // Format: "Nachname Vorname"
                $lastName = $nameParts[0];
                $firstName = implode(' ', array_slice($nameParts, 1));
            } else {
                // Standard Format: "Vorname Nachname" (firstname-lastname)
                $firstName = $nameParts[0];
                $lastName = implode(' ', array_slice($nameParts, 1));
            }
        } else {
            // Nur ein Name - als Nachname behandeln
            $lastName = $cleanedName;
        }
        
        $result = [
            'name' => $name,
            'firstName' => trim($firstName),
            'lastName' => trim($lastName),
            'birthdate' => null,
            'original_date_info' => $dateInfo,
            'detection_type' => null,
            'confidence' => 'none'
        ];
        
        // Datums/Altersanalyse
        if (!empty($dateInfo)) {
            $dateAnalysis = $this->analyzeDateInfo($dateInfo);
            $result = array_merge($result, $dateAnalysis);
        }
        
        return $result;
    }
    
    private function analyzeDateInfo($dateInfo) {
        $currentYear = date('Y');
        $result = [
            'birthdate' => null,
            'detection_type' => null,
            'confidence' => 'none'
        ];
        
        // 1. Prüfung auf Datum (TT.MM.JJJJ, MM/DD/YYYY, etc.)
        if (preg_match('/^(\d{1,2})[\.\/\-](\d{1,2})[\.\/\-](\d{2,4})$/', $dateInfo, $matches)) {
            $part1 = intval($matches[1]);
            $part2 = intval($matches[2]);
            $year = intval($matches[3]);
            
            // 2-stellige Jahre zu 4-stellig konvertieren
            if ($year < 100) {
                $year = ($year > 30) ? 1900 + $year : 2000 + $year;
            }
            
            // Prüfung ob Jahr plausibel ist
            if ($year >= 1900 && $year <= $currentYear) {
                // Deutsches Format TT.MM.JJJJ (Tag <= 31, Monat <= 12)
                if ($part1 <= 31 && $part2 <= 12) {
                    $result['birthdate'] = sprintf('%04d-%02d-%02d', $year, $part2, $part1);
                    $result['detection_type'] = 'date';
                    $result['confidence'] = 'high';
                }
                // Amerikanisches Format MM/DD/YYYY (Monat <= 12, Tag <= 31)
                elseif ($part1 <= 12 && $part2 <= 31) {
                    $result['birthdate'] = sprintf('%04d-%02d-%02d', $year, $part1, $part2);
                    $result['detection_type'] = 'date';
                    $result['confidence'] = 'medium';
                }
                // Fallback: Wenn beide Teile <= 12, nehme deutsches Format
                elseif ($part1 <= 12 && $part2 <= 12) {
                    $result['birthdate'] = sprintf('%04d-%02d-%02d', $year, $part2, $part1);
                    $result['detection_type'] = 'date';
                    $result['confidence'] = 'low';
                }
            }
        }
        // 2. Prüfung auf Geburtsjahr (1900-aktuelles Jahr)
        elseif (preg_match('/^\d{4}$/', $dateInfo)) {
            $year = intval($dateInfo);
            if ($year >= 1900 && $year <= $currentYear) {
                // Provisorisches Datum: 1. Juli des Jahres
                $result['birthdate'] = sprintf('%04d-07-01', $year);
                $result['detection_type'] = 'birthyear';
                $result['confidence'] = 'medium';
            }
        }
        // 3. Prüfung auf Alter (0-110 Jahre)
        elseif (preg_match('/^\d{1,3}$/', $dateInfo)) {
            $age = intval($dateInfo);
            if ($age >= 0 && $age <= 110) {
                $birthYear = $currentYear - $age;
                // Provisorisches Datum: 1. Juli des berechneten Geburtsjahres
                $result['birthdate'] = sprintf('%04d-07-01', $birthYear);
                $result['detection_type'] = 'age';
                $result['confidence'] = 'medium';
            }
        }
        
        return $result;
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Namen Import - <?= $reservation ? htmlspecialchars($reservation['nachname']) : 'Neue Reservierung' ?></title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            line-height: 1.6;
            color: #333;
            background: #f5f5f5;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            min-height: 100vh;
        }
        
        @media (max-width: 1000px) {
            .container {
                grid-template-columns: 1fr;
                gap: 15px;
            }
        }
        
        .card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 20px;
        }
        
        .header {
            grid-column: 1 / -1;
            text-align: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            margin-bottom: 0;
        }
        
        .header h1 {
            font-size: 1.8rem;
            margin-bottom: 5px;
        }
        
        .header .subtitle {
            opacity: 0.9;
            font-size: 0.95rem;
        }
        
        .input-section h2,
        .preview-section h2 {
            margin-bottom: 15px;
            color: #333;
            font-size: 1.3rem;
            border-bottom: 2px solid #eee;
            padding-bottom: 8px;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #555;
        }
        
        textarea {
            width: 100%;
            min-height: 300px;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 6px;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            line-height: 1.4;
            resize: vertical;
        }
        
        textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .clean-section {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .clean-input-row {
            display: flex;
            gap: 10px;
        }
        
        .clean-input {
            flex: 1;
            padding: 8px 12px;
            border: 2px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .clean-input:focus,
        select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 2px rgba(102, 126, 234, 0.1);
        }
        
        select {
            padding: 8px 12px;
            border: 2px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            background: white;
            cursor: pointer;
        }
        
        .button-row {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .btn-primary {
            background: #667eea;
            color: white;
        }
        
        .btn-primary:hover:not(:disabled) {
            background: #5a6fd8;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover:not(:disabled) {
            background: #5a6268;
        }
        
        .btn-success {
            background: #28a745;
            color: white;
        }
        
        .btn-success:hover:not(:disabled) {
            background: #218838;
        }
        
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        
        .btn-danger:hover:not(:disabled) {
            background: #c82333;
        }
        
        .btn-clean {
            background: #17a2b8;
            color: white;
            flex: 1;
        }
        
        .btn-clean:hover:not(:disabled) {
            background: #138496;
        }
        
        .preview-section {
            overflow: hidden;
        }
        
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 10px;
            margin-bottom: 15px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 6px;
            border-left: 4px solid #667eea;
        }
        
        .stat-item {
            text-align: center;
        }
        
        .stat-value {
            display: block;
            font-size: 1.5rem;
            font-weight: bold;
            color: #667eea;
        }
        
        .stat-label {
            font-size: 0.8rem;
            color: #666;
            margin-top: 2px;
        }
        
        .preview-table-container {
            max-height: 500px;
            overflow-y: auto;
            border: 1px solid #ddd;
            border-radius: 6px;
        }
        
        .preview-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        
        .preview-table th,
        .preview-table td {
            padding: 8px 10px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        .preview-table th {
            background: #f8f9fa;
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .preview-table tr:hover {
            background: #f5f5f5;
        }
        
        .confidence-badge {
            padding: 2px 6px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .confidence-high {
            background: #d4edda;
            color: #155724;
        }
        
        .confidence-medium {
            background: #fff3cd;
            color: #856404;
        }
        
        .confidence-low {
            background: #f8d7da;
            color: #721c24;
        }
        
        .confidence-none {
            background: #e2e3e5;
            color: #6c757d;
        }
        
        .detection-type {
            font-size: 11px;
            color: #666;
            font-style: italic;
        }
        
        .empty-state {
            text-align: center;
            color: #999;
            padding: 40px 20px;
            font-style: italic;
        }
        
        .loading {
            display: none;
            text-align: center;
            padding: 20px;
            color: #666;
        }
        
        .loading.show {
            display: block;
        }
        
        .alert {
            padding: 12px 16px;
            margin-bottom: 15px;
            border-radius: 6px;
            font-size: 14px;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .back-link {
            grid-column: 1 / -1;
            text-align: center;
        }
        
        .back-link a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }
        
        .back-link a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card header">
            <h1>Namen Import</h1>
            <div class="subtitle">
                <?php if ($reservation): ?>
                    Reservierung: <?= htmlspecialchars($reservation['nachname']) ?> 
                    (<?= htmlspecialchars($reservation['anreise']) ?> - <?= htmlspecialchars($reservation['abreise']) ?>)
                <?php else: ?>
                    Intelligente Datums- und Alterserkennung
                <?php endif; ?>
            </div>
        </div>
        
        <div class="card input-section">
            <h2>Namen eingeben</h2>
            
            <div class="form-group">
                <label for="nameText">Namen (ein Name pro Zeile):</label>
                <textarea id="nameText" placeholder="Max Mustermann, 25.12.1990&#10;Anna Schmidt 1985&#10;Peter Müller (35)&#10;Lisa Weber"></textarea>
            </div>
            
            <div class="clean-section">
                <label>String-Bereinigung:</label>
                <div class="clean-input-row">
                    <input type="text" id="cleanInput" class="clean-input" 
                           placeholder="Text zum Entfernen eingeben..." 
                           title="Text der aus dem Namen-Feld entfernt werden soll (Enter zum Anwenden)">
                    <button type="button" id="cleanBtn" class="btn btn-clean">Bereinigen</button>
                </div>
                <button type="button" id="hardCleanBtn" class="btn btn-secondary" style="width: 100%;">
                    Standard-Bereinigung (Zeilenumbrüche, etc.)
                </button>
            </div>
            
            <div class="form-group">
                <label for="nameFormat">Namen-Format:</label>
                <select id="nameFormat" class="clean-input" style="width: 100%;">
                    <option value="firstname-lastname">Vorname Nachname (z.B. "Hans Müller")</option>
                    <option value="lastname-firstname">Nachname Vorname (z.B. "Müller Hans")</option>
                </select>
            </div>
            
            <div class="button-row">
                <button type="button" id="previewBtn" class="btn btn-primary">
                    📋 Vorschau generieren
                </button>
            </div>
        </div>
        
        <div class="card preview-section">
            <h2>Vorschau</h2>
            
            <div id="loadingIndicator" class="loading">
                Analysiere Namen und Geburtsdaten...
            </div>
            
            <div id="alertContainer"></div>
            
            <div id="statsContainer" class="stats" style="display: none;">
                <div class="stat-item">
                    <span class="stat-value" id="totalNames">0</span>
                    <span class="stat-label">Namen</span>
                </div>
                <div class="stat-item">
                    <span class="stat-value" id="withBirthdate">0</span>
                    <span class="stat-label">mit Geburtsdatum</span>
                </div>
                <div class="stat-item">
                    <span class="stat-value" id="ageDetected">0</span>
                    <span class="stat-label">Alter erkannt</span>
                </div>
                <div class="stat-item">
                    <span class="stat-value" id="dateDetected">0</span>
                    <span class="stat-label">Datum erkannt</span>
                </div>
            </div>
            
            <div id="previewContainer">
                <div class="empty-state">
                    Klicken Sie auf "Vorschau generieren" um eine Analyse der Namen zu sehen
                </div>
            </div>
            
            <div class="button-row" id="actionButtons" style="display: none;">
                <button type="button" id="saveBtn" class="btn btn-success">
                    ✅ Namen übernehmen
                </button>
                <button type="button" id="cancelBtn" class="btn btn-danger">
                    ❌ Abbrechen
                </button>
            </div>
        </div>
        
        <?php if ($reservation): ?>
        <div class="card back-link">
            <a href="reservation.html?id=<?= $res_id ?>">← Zurück zur Reservierung</a>
        </div>
        <?php endif; ?>
    </div>

    <script>
        const nameText = document.getElementById('nameText');
        const cleanInput = document.getElementById('cleanInput');
        const cleanBtn = document.getElementById('cleanBtn');
        const hardCleanBtn = document.getElementById('hardCleanBtn');
        const previewBtn = document.getElementById('previewBtn');
        const saveBtn = document.getElementById('saveBtn');
        const cancelBtn = document.getElementById('cancelBtn');
        const loadingIndicator = document.getElementById('loadingIndicator');
        const alertContainer = document.getElementById('alertContainer');
        const statsContainer = document.getElementById('statsContainer');
        const previewContainer = document.getElementById('previewContainer');
        const actionButtons = document.getElementById('actionButtons');
        
        const resId = '<?= $res_id ?>';
        let currentPreviewData = null;
        
        // Event Listeners
        cleanInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                performClean();
            }
        });
        
        cleanBtn.addEventListener('click', performClean);
        hardCleanBtn.addEventListener('click', performHardClean);
        previewBtn.addEventListener('click', generatePreview);
        saveBtn.addEventListener('click', saveNames);
        cancelBtn.addEventListener('click', function() {
            if (resId) {
                window.location.href = `reservation.html?id=${resId}`;
            } else {
                window.history.back();
            }
        });
        
        function performClean() {
            const cleanString = cleanInput.value.trim();
            if (!cleanString) {
                showAlert('Bitte geben Sie einen Text zum Bereinigen ein', 'error');
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'clean');
            formData.append('text', nameText.value);
            formData.append('cleanString', cleanString);
            
            fetch(window.location.pathname, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    nameText.value = data.text;
                    cleanInput.value = '';
                    cleanInput.focus();
                    showAlert(`Text "${cleanString}" wurde entfernt`, 'success');
                } else {
                    showAlert('Fehler beim Bereinigen: ' + (data.error || 'Unbekannter Fehler'), 'error');
                }
            })
            .catch(error => {
                console.error('Clean error:', error);
                showAlert('Netzwerkfehler beim Bereinigen', 'error');
            });
        }
        
        function performHardClean() {
            let text = nameText.value;
            
            // Standard-Bereinigungen
            text = text.replace(/\r\n/g, '\n'); // Windows Zeilenenden
            text = text.replace(/\r/g, '\n');   // Mac Zeilenenden
            text = text.replace(/\n+/g, '\n');  // Mehrfache Zeilenumbrüche
            text = text.replace(/[ \t]+/g, ' '); // Mehrfache Leerzeichen/Tabs
            text = text.replace(/^\s+|\s+$/gm, ''); // Leerzeichen am Anfang/Ende jeder Zeile
            text = text.trim();
            
            nameText.value = text;
            showAlert('Standard-Bereinigung durchgeführt', 'success');
        }
        
        function generatePreview() {
            const text = nameText.value.trim();
            if (!text) {
                showAlert('Bitte geben Sie Namen ein', 'error');
                return;
            }
            
            showLoading(true);
            clearAlerts();
            
            const formData = new FormData();
            formData.append('action', 'preview');
            formData.append('text', text);
            formData.append('cleanString', cleanInput.value);
            formData.append('format', document.getElementById('nameFormat').value);
            
            fetch(window.location.pathname, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                showLoading(false);
                
                if (data.success) {
                    currentPreviewData = data.names;
                    displayPreview(data.names, data.stats);
                } else {
                    showAlert('Fehler bei der Vorschau: ' + (data.error || 'Unbekannter Fehler'), 'error');
                }
            })
            .catch(error => {
                showLoading(false);
                console.error('Preview error:', error);
                showAlert('Netzwerkfehler bei der Vorschau-Generierung', 'error');
            });
        }
        
        function displayPreview(names, stats) {
            // Statistiken anzeigen
            document.getElementById('totalNames').textContent = stats.total;
            document.getElementById('withBirthdate').textContent = stats.with_birthdate;
            document.getElementById('ageDetected').textContent = stats.age_detected;
            document.getElementById('dateDetected').textContent = stats.date_detected;
            
            statsContainer.style.display = 'grid';
            
            // Tabelle erstellen
            if (names.length === 0) {
                previewContainer.innerHTML = '<div class="empty-state">Keine Namen gefunden</div>';
                actionButtons.style.display = 'none';
                return;
            }
            
            let html = `
                <div class="preview-table-container">
                    <table class="preview-table">
                        <thead>
                            <tr>
                                <th>Vollständiger Name</th>
                                <th>Vorname</th>
                                <th>Nachname</th>
                                <th>Geburtsdatum</th>
                                <th>Erkennung</th>
                                <th>Vertrauen</th>
                                <th>Original</th>
                            </tr>
                        </thead>
                        <tbody>
            `;
            
            names.forEach(entry => {
                html += `
                    <tr>
                        <td><strong>${escapeHtml(entry.name)}</strong></td>
                        <td>${entry.firstName ? escapeHtml(entry.firstName) : '<em>-</em>'}</td>
                        <td>${entry.lastName ? escapeHtml(entry.lastName) : '<em>-</em>'}</td>
                        <td>${entry.birthdate || '<em>-</em>'}</td>
                        <td>
                            ${entry.detection_type ? 
                                `<span class="detection-type">${getDetectionTypeLabel(entry.detection_type)}</span>` : 
                                '<em>-</em>'}
                        </td>
                        <td>
                            <span class="confidence-badge confidence-${entry.confidence}">
                                ${getConfidenceLabel(entry.confidence)}
                            </span>
                        </td>
                        <td>${entry.original_date_info ? escapeHtml(entry.original_date_info) : '<em>-</em>'}</td>
                    </tr>
                `;
            });
            
            html += `
                        </tbody>
                    </table>
                </div>
            `;
            
            previewContainer.innerHTML = html;
            actionButtons.style.display = 'flex';
        }
        
        function saveNames() {
            if (!currentPreviewData || !resId) {
                showAlert('Keine Vorschaudaten oder Reservierungs-ID verfügbar', 'error');
                return;
            }
            
            showLoading(true);
            saveBtn.disabled = true;
            
            const formData = new FormData();
            formData.append('action', 'save');
            formData.append('res_id', resId);
            formData.append('names', JSON.stringify(currentPreviewData));
            
            fetch(window.location.pathname, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                showLoading(false);
                saveBtn.disabled = false;
                
                if (data.success) {
                    showAlert(`✅ Erfolgreich gespeichert: ${data.added} Namen` + 
                             (data.birthdates_added ? `, ${data.birthdates_added} mit Geburtsdatum` : ''), 'success');
                    
                    setTimeout(() => {
                        window.location.href = `reservation.html?id=${resId}`;
                    }, 2000);
                } else {
                    showAlert('Fehler beim Speichern: ' + (data.error || 'Unbekannter Fehler'), 'error');
                }
            })
            .catch(error => {
                showLoading(false);
                saveBtn.disabled = false;
                console.error('Save error:', error);
                showAlert('Netzwerkfehler beim Speichern', 'error');
            });
        }
        
        function showLoading(show) {
            if (show) {
                loadingIndicator.classList.add('show');
            } else {
                loadingIndicator.classList.remove('show');
            }
        }
        
        function showAlert(message, type) {
            const alert = document.createElement('div');
            alert.className = `alert alert-${type === 'error' ? 'error' : 'success'}`;
            alert.textContent = message;
            
            alertContainer.appendChild(alert);
            
            setTimeout(() => {
                if (alert.parentNode) {
                    alert.parentNode.removeChild(alert);
                }
            }, 5000);
        }
        
        function clearAlerts() {
            alertContainer.innerHTML = '';
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        function getDetectionTypeLabel(type) {
            const labels = {
                'age': 'Alter',
                'birthyear': 'Geburtsjahr',
                'date': 'Datum'
            };
            return labels[type] || type;
        }
        
        function getConfidenceLabel(confidence) {
            const labels = {
                'high': 'Hoch',
                'medium': 'Mittel',
                'low': 'Niedrig',
                'none': 'Keine'
            };
            return labels[confidence] || confidence;
        }
        
        // Fokus auf Textfeld setzen
        nameText.focus();
    </script>
</body>
</html>
