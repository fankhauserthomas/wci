<?php
// test-save-arrangements.php - Debug script for save-arrangements.php

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Testing save-arrangements.php functionality</h2>";

// Test data that mimics what JavaScript is sending
$testData = [
    'guest_id' => 37302,
    'arrangements' => [
        1 => [null, null, null, ['anz' => 1, 'bem' => 'Test']], // Sparse array with empty elements
    ],
    'guest_remark' => 'Test remark'
];

echo "<h3>Test Data:</h3>";
echo "<pre>" . json_encode($testData, JSON_PRETTY_PRINT) . "</pre>";

// Simulate the POST request
$_POST = [];
$GLOBALS['HTTP_RAW_POST_DATA'] = json_encode($testData);

// Capture output
ob_start();

// Simulate the file_get_contents('php://input')
$originalInput = 'php://input';
file_put_contents('php://memory', json_encode($testData));

// Set up a temporary input stream
$tempInput = tmpfile();
fwrite($tempInput, json_encode($testData));
rewind($tempInput);

echo "<h3>Simulating save-arrangements.php...</h3>";

// We'll need to modify our approach since we can't easily mock php://input
// Let's create a direct function call instead

require_once __DIR__ . '/auth.php';
require_once 'hp-db-config.php';

try {
    // Check auth first
    if (!AuthManager::checkSession()) {
        echo "<div style='color: red'>ERROR: Not authenticated</div>";
    } else {
        echo "<div style='color: green'>Authentication OK</div>";
    }
    
    $guestId = intval($testData['guest_id']);
    $arrangements = $testData['arrangements'];
    $guestRemark = trim($testData['guest_remark']);
    
    echo "<h4>Processed Data:</h4>";
    echo "Guest ID: $guestId<br>";
    echo "Guest Remark: '$guestRemark'<br>";
    echo "Arrangements structure:<br>";
    
    foreach ($arrangements as $arrId => $items) {
        echo "- Arrangement ID $arrId: ";
        if (is_array($items)) {
            $validItems = [];
            foreach ($items as $index => $item) {
                if ($item !== null && $item !== '') {
                    $validItems[] = "Index $index: " . json_encode($item);
                } else {
                    $validItems[] = "Index $index: EMPTY/NULL";
                }
            }
            echo implode(", ", $validItems);
        } else {
            echo "NOT AN ARRAY: " . json_encode($items);
        }
        echo "<br>";
    }
    
    echo "<h4>Database Connection Test:</h4>";
    $hpConn = getHpDbConnection();
    if (!$hpConn) {
        echo "<div style='color: red'>ERROR: HP database connection failed</div>";
    } else {
        echo "<div style='color: green'>HP database connection OK</div>";
        
        // Test if tables exist
        $tableCheck = $hpConn->query("SHOW TABLES LIKE 'hpdet'");
        if ($tableCheck && $tableCheck->num_rows > 0) {
            echo "<div style='color: green'>Table 'hpdet' exists</div>";
        } else {
            echo "<div style='color: red'>ERROR: Table 'hpdet' does not exist</div>";
        }
        
        $tableCheck = $hpConn->query("SHOW TABLES LIKE 'hp_data'");
        if ($tableCheck && $tableCheck->num_rows > 0) {
            echo "<div style='color: green'>Table 'hp_data' exists</div>";
        } else {
            echo "<div style='color: red'>ERROR: Table 'hp_data' does not exist</div>";
        }
    }
    
} catch (Exception $e) {
    echo "<div style='color: red'>ERROR: " . $e->getMessage() . "</div>";
    echo "<div style='color: red'>Stack trace: " . $e->getTraceAsString() . "</div>";
}

$output = ob_get_clean();
echo $output;
?>
