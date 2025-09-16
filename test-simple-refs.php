<?php
// Direct CLI test for code references
ini_set('memory_limit', '256M');

class SimpleCodeRefTester {
    public function testSync() {
        echo "=== Testing sync_matrix.php references ===\n\n";
        
        // Manual search first
        echo "Manual grep results:\n";
        $files = glob('*.php');
        $found = [];
        
        foreach ($files as $file) {
            if ($file === 'test-simple-refs.php') continue; // Skip self
            
            $content = file_get_contents($file);
            if ($content && strpos($content, 'sync_matrix.php') !== false) {
                $found[] = $file;
                echo "  - Found in: $file\n";
            }
        }
        
        echo "\nTotal files with 'sync_matrix.php': " . count($found) . "\n";
        
        // Test our regex patterns
        echo "\n=== Testing regex patterns ===\n";
        foreach ($found as $file) {
            echo "\nAnalyzing: $file\n";
            $content = file_get_contents($file);
            
            // Pattern 1: In quotes
            if (preg_match_all('/[\'"]([^\'"\s\/]{1,50}\.php)[\'"]/', $content, $matches)) {
                $phpFiles = array_filter($matches[1], function($f) { 
                    return strpos($f, 'sync_matrix.php') !== false; 
                });
                if (!empty($phpFiles)) {
                    echo "  Pattern 1 (quotes): " . implode(', ', $phpFiles) . "\n";
                }
            }
            
            // Pattern 2: In arrays
            if (preg_match_all("/['\"]([^'\"]*sync_matrix\.php)['\"]|sync_matrix\.php/", $content, $matches)) {
                echo "  Pattern 2 (general): Found " . count($matches[0]) . " matches\n";
            }
        }
    }
}

$tester = new SimpleCodeRefTester();
$tester->testSync();