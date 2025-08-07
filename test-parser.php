<?php
// Test der parseComplexInput Funktion

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
    
    // Tokenize - aufteilen in Zahlen und Text
    $tokens = [];
    $currentToken = '';
    $chars = str_split($input);
    
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

// Test-Fälle
$testCases = [
    '5',
    '3 Vollpension',
    '2 Halbpension 3 Vollpension',
    '4 ohne Frühstück 2',
    '1 sehr langer Text mit Leerzeichen 3 kurz',
    '2 Vollpension 1 3 ohne Extras',
    '10'
];

echo "=== PARSER TEST ===\n";
foreach ($testCases as $test) {
    echo "\nEingabe: '$test'\n";
    $result = parseComplexInput($test);
    echo "Ergebnis:\n";
    foreach ($result as $entry) {
        echo "  - Anzahl: {$entry['anz']}, Bemerkung: '{$entry['bem']}'\n";
    }
}
?>
