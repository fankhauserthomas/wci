<?php
// Simulate the exact API call
$_GET['id'] = 6608;
$_SERVER['REQUEST_METHOD'] = 'GET';

// Capture output
ob_start();
include 'reservierungen/api/get-hp-arrangements-table.php';
$output = ob_get_clean();

echo "API Output:\n";
echo $output;
echo "\n\nAPI Output Length: " . strlen($output) . " bytes\n";

// Try to decode JSON to see if it's valid
$decoded = json_decode($output, true);
if ($decoded === null) {
    echo "JSON Error: " . json_last_error_msg() . "\n";
    echo "First 200 chars of output:\n";
    echo substr($output, 0, 200) . "\n";
} else {
    echo "JSON is valid!\n";
    print_r($decoded);
}
?>
