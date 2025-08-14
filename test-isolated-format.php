<?php
// Test für das Name Format Feature - isoliert
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
        
        // Datums/Altersanalyse (vereinfacht für Test)
        if (!empty($dateInfo)) {
            if (preg_match('/^\d{4}$/', $dateInfo)) {
                $result['birthdate'] = $dateInfo . '-07-01'; // Dummy-Datum
                $result['detection_type'] = 'birthyear';
                $result['confidence'] = 'medium';
            }
        }
        
        return $result;
    }
}

// Test Daten
$testNames = "Hans Müller 1980
Schmidt, Anna 1990
Dr. Peter Weber
Maria Rodriguez Gonzalez
von Habsburg Anna Maria
O'Connor Michael 2000";

echo "=== Namen-Format Feature Test ===\n\n";

// Test 1: Firstname-Lastname Format (Standard)
echo "1. Test: Firstname-Lastname Format (Standard)\n";
echo "Eingabe:\n$testNames\n\n";

$parser = new NamesBirthdateParser();
$result1 = $parser->parseNames($testNames, '', 'firstname-lastname');

echo "Ergebnisse:\n";
foreach ($result1['names'] as $name) {
    $bd = $name['birthdate'] ? " [Geburtsjahr: " . substr($name['birthdate'], 0, 4) . "]" : "";
    echo "- Vollname: '{$name['name']}' → Vorname: '{$name['firstName']}', Nachname: '{$name['lastName']}'$bd\n";
}
echo "\n";

// Test 2: Lastname-Firstname Format
echo "2. Test: Lastname-Firstname Format\n";
$result2 = $parser->parseNames($testNames, '', 'lastname-firstname');

echo "Ergebnisse:\n";
foreach ($result2['names'] as $name) {
    $bd = $name['birthdate'] ? " [Geburtsjahr: " . substr($name['birthdate'], 0, 4) . "]" : "";
    echo "- Vollname: '{$name['name']}' → Vorname: '{$name['firstName']}', Nachname: '{$name['lastName']}'$bd\n";
}
echo "\n";

// Test 3: Edge Cases
$edgeCases = "Müller
Anna Maria Fernandez García
Jean-Claude van Damme 1960
Dr. med. Hans Peter Schmidt-Weber";

echo "3. Test: Edge Cases\n";
echo "Eingabe:\n$edgeCases\n\n";

echo "Firstname-Lastname Format:\n";
$result3a = $parser->parseNames($edgeCases, '', 'firstname-lastname');
foreach ($result3a['names'] as $name) {
    echo "- '{$name['name']}' → Vorname: '{$name['firstName']}', Nachname: '{$name['lastName']}'\n";
}

echo "\nLastname-Firstname Format:\n";
$result3b = $parser->parseNames($edgeCases, '', 'lastname-firstname');
foreach ($result3b['names'] as $name) {
    echo "- '{$name['name']}' → Vorname: '{$name['firstName']}', Nachname: '{$name['lastName']}'\n";
}

echo "\n=== Test abgeschlossen ===\n";
?>
