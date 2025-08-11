<?php
// save-arrangement-inline.php - Complex input parsing for arrangements

// JSON Header sofort setzen
header('Content-Type: application/json');

// Error reporting deaktivieren für saubere JSON-Antworten
error_reporting(0);
ini_set('display_errors', 0);

/**
 * Parst komplexe Eingaben wie "3 Vollpension 2 ohne Frühstück 1" oder "1,2fleisch" oder "3:fl 2veg"
 * @param string $input
 * @return array Array mit ['anz' => int, 'bem' => string] Einträgen
 */
function parseComplexInput($input) {
    $input = trim($input);
    $entries = [];
    
    // Falls es nur eine Zahl ist, einfach zurückgeben
    if (is_numeric($input)) {
        $anz = intval($input);
        if ($anz > 0) {
            $entries[] = ['anz' => $anz, 'bem' => ''];
        }
        return $entries;
    }
    
    // Erweiterte Tokenization - Kommas und Doppelpunkte mit optionalen Leerzeichen durch Leerzeichen ersetzen
    $normalizedInput = preg_replace('/\s*[,:]\s*/', ' ', $input); // Kommas/Doppelpunkte mit optionalen Leerzeichen durch Leerzeichen ersetzen
    $normalizedInput = preg_replace('/\s+/', ' ', $normalizedInput); // Mehrfache Leerzeichen normalisieren
    
    // Tokenize - aufteilen in Zahlen und Text
    $tokens = [];
    $currentToken = '';
    $chars = str_split($normalizedInput);
    
    foreach ($chars as $char) {
        if (ctype_space($char)) {
            if (!empty($currentToken)) {
                $tokens[] = $currentToken;
                $currentToken = '';
            }
        } else {
            $currentToken .= $char;
        }
    }
    
    if (!empty($currentToken)) {
        $tokens[] = $currentToken;
    }
    
    // Erweiterte Logik für zusammenhängende Zahlen-Text-Kombinationen
    // z.B. "2fleisch", "3:fl", "4veg" -> separate Tokens
    $expandedTokens = [];
    foreach ($tokens as $token) {
        // Prüfe verschiedene Muster für Zahl-Text-Kombinationen
        if (preg_match('/^(\d+)([a-zA-ZäöüÄÖÜß].*)$/', $token, $matches)) {
            // Muster: "2fleisch" -> ["2", "fleisch"]
            $expandedTokens[] = $matches[1]; // Die Zahl
            $expandedTokens[] = $matches[2]; // Der Text
        } elseif (preg_match('/^([a-zA-ZäöüÄÖÜß]+)(\d+)$/', $token, $matches)) {
            // Muster: "fleisch2" -> ["fleisch", "2"] (falls so verwendet)
            $expandedTokens[] = $matches[2]; // Die Zahl zuerst
            $expandedTokens[] = $matches[1]; // Dann der Text
        } else {
            $expandedTokens[] = $token;
        }
    }
    
    $tokens = $expandedTokens;
    
    // Parsing: Zahlen identifizieren und Bemerkungen zuordnen
    $i = 0;
    while ($i < count($tokens)) {
        $token = $tokens[$i];
        
        // Ist es eine Zahl?
        if (is_numeric($token)) {
            $anz = intval($token);
            if ($anz > 0) {
                $bem = '';
                
                // Sammle alle nachfolgenden Text-Tokens bis zur nächsten Zahl
                $j = $i + 1;
                $bemParts = [];
                
                while ($j < count($tokens) && !is_numeric($tokens[$j])) {
                    $bemParts[] = $tokens[$j];
                    $j++;
                }
                
                if (!empty($bemParts)) {
                    $bem = implode(' ', $bemParts);
                }
                
                $entries[] = ['anz' => $anz, 'bem' => $bem];
                $i = $j; // Springe zur nächsten Zahl
            } else {
                $i++;
            }
        } else {
            // Text ohne vorherige Zahl überspringen
            $i++;
        }
    }
    
    // Gruppierung: Gleiche Bemerkungen zusammenfassen
    $grouped = [];
    foreach ($entries as $entry) {
        $bemerkung = $entry['bem'];
        $anzahl = $entry['anz'];
        
        if (isset($grouped[$bemerkung])) {
            $grouped[$bemerkung] += $anzahl;
        } else {
            $grouped[$bemerkung] = $anzahl;
        }
    }
    
    // Zurück in Array-Format konvertieren
    $results = [];
    foreach ($grouped as $bemerkung => $totalAnzahl) {
        $results[] = [
            'anz' => $totalAnzahl,
            'bem' => $bemerkung
        ];
    }
    
    return $results;
}

try {
    require_once 'hp-db-config.php';

    // Nur POST-Requests akzeptieren
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Nur POST-Requests erlaubt']);
        exit;
    }

    // JSON-Daten lesen
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Ungültiges JSON: ' . json_last_error_msg()]);
        exit;
    }

    // Pflichtfelder prüfen
    if (!isset($data['guest_id']) || !isset($data['arr_id']) || !isset($data['value'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Fehlende Pflichtfelder']);
        exit;
    }

    $guestId = intval($data['guest_id']);
    $arrId = intval($data['arr_id']);
    $value = trim($data['value']);

    // Validierung
    if ($guestId <= 0 || $arrId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Ungültige IDs']);
        exit;
    }

    // HP-Datenbank-Verbindung
    $hpConn = getHpDbConnection();
    if (!$hpConn) {
        throw new Exception('HP-Datenbank nicht verfügbar');
    }
    
    // Alle bestehenden Einträge für dieses Arrangement löschen
    $deleteStmt = $hpConn->prepare("DELETE FROM hpdet WHERE hp_id = ? AND arr_id = ?");
    $deleteStmt->bind_param("ii", $guestId, $arrId);
    $deleteStmt->execute();
    $deleteStmt->close();
    
    // Eingabe parsen für komplexe Eingaben
    if (!empty($value)) {
        $entries = parseComplexInput($value);
        
        foreach ($entries as $entry) {
            if ($entry['anz'] > 0) {
                $insertStmt = $hpConn->prepare("INSERT INTO hpdet (hp_id, arr_id, anz, bem) VALUES (?, ?, ?, ?)");
                $insertStmt->bind_param("iiis", $guestId, $arrId, $entry['anz'], $entry['bem']);
                $insertStmt->execute();
                $insertStmt->close();
            }
        }
    }
    
    // Erfolg
    echo json_encode(['success' => true, 'message' => 'Gespeichert']);
    
} catch (Exception $e) {
    error_log("save-arrangement-inline.php Error: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Speicherfehler']);
}
?>
