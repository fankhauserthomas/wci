<?php
// Test der erweiterten parseComplexInput Funktion

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
    
    // Erweiterte Tokenization - Kommas durch Leerzeichen ersetzen und dann tokenisieren
    $normalizedInput = preg_replace('/,+/', ' ', $input); // Kommas durch Leerzeichen ersetzen
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
    
    // Zusätzliche Logik für zusammenhängende Zahlen-Text-Kombinationen
    // z.B. "2fleisch" -> ["2", "fleisch"]
    $expandedTokens = [];
    foreach ($tokens as $token) {
        // Prüfe ob Token mit Zahl beginnt aber auch Text enthält
        if (preg_match('/^(\d+)([a-zA-ZäöüÄÖÜß].*)$/', $token, $matches)) {
            $expandedTokens[] = $matches[1]; // Die Zahl
            $expandedTokens[] = $matches[2]; // Der Text
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

// Test-Fälle - Erweitert mit Komma-Unterstützung
$testCases = [
    // Ursprüngliche Tests
    '5',
    '3 Vollpension',
    '2 Halbpension 3 Vollpension',
    '4 ohne Frühstück 2',
    
    // Neue Komma-Tests
    '1,2fleisch',
    '1 2fleisch',  
    '1 2 fleisch',
    '1,2 fleisch',
    '3,4,5',
    '2,3fleisch 4vegetarisch',
    '1,2 sehr lange Bemerkung mit Leerzeichen 3',
    '5fleisch,6fisch',
];

echo "=== ERWEITETER PARSER TEST (mit Komma-Support) ===\n";
foreach ($testCases as $test) {
    echo "\nEingabe: '$test'\n";
    $result = parseComplexInput($test);
    echo "Ergebnis:\n";
    foreach ($result as $entry) {
        echo "  - Anzahl: {$entry['anz']}, Bemerkung: '{$entry['bem']}'\n";
    }
}
?>
