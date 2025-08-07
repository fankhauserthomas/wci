<?php

// Test-Datei für Gruppierung mit Komma/Doppelpunkt Delimitern

// Test-Funktion
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

// Gruppierungs-Tests
echo "=== Gruppierungs-Tests mit Kommas und Doppelpunkten ===\n\n";

// Test 1: Ihr Beispiel "2, 2 , 3 veg 2 veg" sollte gruppiert werden
$input1 = "2, 2 , 3 veg 2 veg";
echo "Input: \"$input1\"\n";
$result1 = parseComplexInput($input1);
foreach ($result1 as $entry) {
    $bem = empty($entry['bem']) ? '""' : '"' . $entry['bem'] . '"';
    echo "Anz: {$entry['anz']}, Bez: $bem\n";
}
echo "\n";

// Test 2: Gemischte Delimiter mit Gruppierung
$input2 = "1,2:veg,3fl,1fl,2:veg";
echo "Input: \"$input2\"\n";
$result2 = parseComplexInput($input2);
foreach ($result2 as $entry) {
    $bem = empty($entry['bem']) ? '""' : '"' . $entry['bem'] . '"';
    echo "Anz: {$entry['anz']}, Bez: $bem\n";
}
echo "\n";

// Test 3: Leerzeichen vor und nach Delimitern ignorieren
$input3 = "3 , 2   :   veg  ,  1 fl , 2 fl";
echo "Input: \"$input3\"\n";
$result3 = parseComplexInput($input3);
foreach ($result3 as $entry) {
    $bem = empty($entry['bem']) ? '""' : '"' . $entry['bem'] . '"';
    echo "Anz: {$entry['anz']}, Bez: $bem\n";
}
echo "\n";

// Test 4: Komplexere Gruppierung
$input4 = "2fleisch, 1 , 3veg , 1 ohne extras, 2 fleisch , 1veg";
echo "Input: \"$input4\"\n";
$result4 = parseComplexInput($input4);
foreach ($result4 as $entry) {
    $bem = empty($entry['bem']) ? '""' : '"' . $entry['bem'] . '"';
    echo "Anz: {$entry['anz']}, Bez: $bem\n";
}
echo "\n";

?>
