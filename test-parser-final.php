<?php
// Test der finalen parseComplexInput Funktion mit allen Delimitern

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
    
    // Erweiterte Tokenization - Kommas und Doppelpunkte durch Leerzeichen ersetzen
    $normalizedInput = preg_replace('/[,:]+/', ' ', $input); // Kommas und Doppelpunkte durch Leerzeichen ersetzen
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
    
    return $entries;
}

// Test-Fälle - Finale Version mit allen Delimitern
$testCases = [
    // Ursprüngliche Tests
    '5',
    '3 Vollpension',
    '2 Halbpension 3 Vollpension',
    
    // Komma-Tests
    '1,2fleisch',
    '1 2fleisch',  
    '1 2 fleisch',
    '1,2 fleisch',
    
    // Neue Doppelpunkt- und Misch-Tests
    '1, 2 veg,3fl',
    '2veg 3fl 1',
    '3:fl 1 2veg',
    '4:fleisch 2:fisch',
    '1,2:veg,3fl 4',
    '5veg:6fl:7',
    '2 ohne extras,3:fleisch 1',
];

echo "=== FINALE PARSER TEST (Komma, Doppelpunkt, Misch-Support) ===\n";
foreach ($testCases as $test) {
    echo "\nEingabe: '$test'\n";
    $result = parseComplexInput($test);
    echo "Ergebnis:\n";
    foreach ($result as $entry) {
        echo "  - Anzahl: {$entry['anz']}, Bemerkung: '{$entry['bem']}'\n";
    }
}
?>
