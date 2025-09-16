<?php
// Debug the code reference data flow
ini_set('memory_limit', '256M');
require_once 'enhanced-wci-analyzer.php';

echo "=== Debugging Code Reference Data Flow ===\n\n";

// Create analyzer and run analysis
$analyzer = new UltraSecureFileSafetyAnalyzer();
$analyzer->performUltraSecureAnalysis(7);

// Check if we can access the analysis property directly
$reflection = new ReflectionClass($analyzer);
$analysisProperty = $reflection->getProperty('analysis');
$analysisProperty->setAccessible(true);
$analysis = $analysisProperty->getValue($analyzer);

echo "1. Code referenced files found:\n";
if (isset($analysis['code_referenced_files'])) {
    $codeRefs = $analysis['code_referenced_files'];
    echo "   Total files with references: " . count($codeRefs) . "\n";
    
    if (isset($codeRefs['reservierungen/api/getReservationDetails.php'])) {
        $refs = $codeRefs['reservierungen/api/getReservationDetails.php'];
        echo "   reservierungen/api/getReservationDetails.php found with " . count($refs) . " references:\n";
        foreach (array_slice($refs, 0, 5) as $ref) {
            echo "     - $ref\n";
        }
    } else {
        echo "   reservierungen/api/getReservationDetails.php NOT found in code_referenced_files\n";
        echo "   Available files: " . implode(', ', array_slice(array_keys($codeRefs), 0, 10)) . "\n";
    }
} else {
    echo "   No code_referenced_files array found!\n";
}

echo "\n2. Testing getComprehensiveFileList:\n";
$fileList = $analyzer->getComprehensiveFileList(7);
$foundFile = null;
foreach ($fileList as $file) {
    if ($file['file_name'] === 'reservierungen/api/getReservationDetails.php') {
        $foundFile = $file;
        break;
    }
}

if ($foundFile) {
    echo "   File found in comprehensive list:\n";
    echo "   - code_referenced: " . ($foundFile['code_referenced'] ? 'true' : 'false') . "\n";
    echo "   - referenced_by_count: " . $foundFile['referenced_by_count'] . "\n";
    echo "   - referenced_by_files: " . print_r($foundFile['referenced_by_files'], true) . "\n";
} else {
    echo "   reservierungen/api/getReservationDetails.php NOT found in comprehensive list!\n";
}