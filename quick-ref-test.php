<?php
// Quick reference count test
ini_set('memory_limit', '512M');
define('SUPPRESS_WEB_INTERFACE', true);
require_once 'enhanced-wci-analyzer.php';

echo "=== Testing reservierungen/api/getReservationDetails.php References ===\n";

$analyzer = new UltraSecureFileSafetyAnalyzer();
$analyzer->performUltraSecureAnalysis(7);

// Get analysis via reflection
$reflection = new ReflectionClass($analyzer);
$analysisProperty = $reflection->getProperty('analysis');
$analysisProperty->setAccessible(true);
$analysis = $analysisProperty->getValue($analyzer);

if (isset($analysis['code_referenced_files']['reservierungen/api/getReservationDetails.php'])) {
    $refs = $analysis['code_referenced_files']['reservierungen/api/getReservationDetails.php'];
    echo "✓ Found " . count($refs) . " references to reservierungen/api/getReservationDetails.php\n";
    echo "Target: 125 references in 41 files (VS Code)\n";
    echo "Progress: " . round((count($refs)/125)*100, 1) . "% of target\n\n";
    
    if (count($refs) <= 10) {
        echo "References found:\n";
        foreach ($refs as $ref) {
            echo "  - $ref\n";
        }
    } else {
        echo "First 10 references:\n";
        foreach (array_slice($refs, 0, 10) as $ref) {
            echo "  - $ref\n";
        }
        echo "  ... and " . (count($refs) - 10) . " more\n";
    }
} else {
    echo "✗ reservierungen/api/getReservationDetails.php not found in references\n";
}

echo "\nTotal files analyzed: " . count($analysis['all_files'] ?? []) . "\n";
echo "Total code-referenced files: " . count($analysis['code_referenced_files'] ?? []) . "\n";