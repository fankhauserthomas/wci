<?php
// Quick test for sync_matrix.php references
require_once 'enhanced-wci-analyzer.php';

echo "Testing sync_matrix.php code references...\n\n";

// Manual grep to show what's actually there
echo "=== MANUAL GREP RESULTS ===\n";
$grepCommand = "grep -r 'sync_matrix.php' . --include='*.php' | head -10";
echo "Command: $grepCommand\n";
$grepOutput = shell_exec($grepCommand);
echo $grepOutput . "\n";

echo "=== ANALYZER RESULTS ===\n";
$analyzer = new UltraSecureFileSafetyAnalyzer();

// Set memory limit higher for testing
ini_set('memory_limit', '512M');

// Run full analysis which includes code scanning
$analyzer->performUltraSecureAnalysis(7);
$analysis = $analyzer->getAnalysis();

if (isset($analysis['code_referenced_files']['sync_matrix.php'])) {
    $refs = $analysis['code_referenced_files']['sync_matrix.php'];
    echo "sync_matrix.php found in analyzer!\n";
    echo "Referenced by " . count($refs) . " files:\n";
    foreach ($refs as $ref) {
        echo "  - $ref\n";
    }
} else {
    echo "sync_matrix.php NOT found in analyzer\n";
    echo "Available referenced files:\n";
    $referencedFiles = array_keys($analysis['code_referenced_files'] ?? []);
    $phpFiles = array_filter($referencedFiles, function($f) { return strpos($f, '.php') !== false; });
    echo "Total PHP files found: " . count($phpFiles) . "\n";
    foreach (array_slice($phpFiles, 0, 10) as $file) {
        echo "  - $file\n";
    }
    
    if (count($phpFiles) > 10) {
        echo "  ... and " . (count($phpFiles) - 10) . " more\n";
    }
}