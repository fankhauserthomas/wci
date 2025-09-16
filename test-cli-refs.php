<?php
// Direct CLI test without HTML output interference
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('memory_limit', '512M');

// Force CLI mode
if (php_sapi_name() !== 'cli') {
    echo "Error: This script must be run from CLI\n";
    exit(1);
}

require_once 'enhanced-wci-analyzer.php';

echo "=== Direct CLI Code Reference Test ===\n\n";

try {
    // Create analyzer
    $analyzer = new UltraSecureFileSafetyAnalyzer();
    echo "✓ Analyzer created\n";
    
    // Run analysis
    echo "Running analysis...\n";
    $analyzer->performUltraSecureAnalysis(7);
    echo "✓ Analysis completed\n";
    
    // Get analysis data via reflection
    $reflection = new ReflectionClass($analyzer);
    $analysisProperty = $reflection->getProperty('analysis');
    $analysisProperty->setAccessible(true);
    $analysis = $analysisProperty->getValue($analyzer);
    
    // Check code references
    if (isset($analysis['code_referenced_files']['getReservationDetails.php'])) {
        $refs = $analysis['code_referenced_files']['getReservationDetails.php'];
        echo "\n✓ getReservationDetails.php found with " . count($refs) . " references:\n";
        foreach ($refs as $ref) {
            echo "  - $ref\n";
        }
    } else {
        echo "\n✗ getReservationDetails.php NOT found in code references\n";
        echo "Available files with references:\n";
        $codeRefs = $analysis['code_referenced_files'] ?? [];
        $phpFiles = array_filter(array_keys($codeRefs), function($f) { 
            return strpos($f, '.php') !== false; 
        });
        foreach (array_slice($phpFiles, 0, 10) as $file) {
            echo "  - $file (" . count($codeRefs[$file]) . " refs)\n";
        }
    }
    
    echo "\nTotal code-referenced files: " . count($analysis['code_referenced_files'] ?? []) . "\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}