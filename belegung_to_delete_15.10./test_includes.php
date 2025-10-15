<?php
/**
 * Test-Script um die Include-Dateien zu testen
 */

echo "=== Testing Include Files ===\n";

try {
    // Test utility_functions.php
    require_once 'includes/utility_functions.php';
    echo "✅ utility_functions.php loaded successfully\n";
    
    // Test getCellBackgroundColor function
    $color1 = getCellBackgroundColor(10, 10);
    $color2 = getCellBackgroundColor(10, 15);
    $color3 = getCellBackgroundColor(15, 10);
    
    echo "✅ getCellBackgroundColor function works:\n";
    echo "   Same values (10,10): $color1\n";
    echo "   Increase (10,15): $color2\n";
    echo "   Decrease (15,10): $color3\n";
    
} catch (Exception $e) {
    echo "❌ Error with utility_functions.php: " . $e->getMessage() . "\n";
}

try {
    // Test quota_functions.php
    require_once 'includes/quota_functions.php';
    echo "✅ quota_functions.php loaded successfully\n";
    
    // Test generateIntelligentQuotaName function
    $name = generateIntelligentQuotaName([], '2025-08-29', '2025-08-31');
    echo "✅ generateIntelligentQuotaName function works: $name\n";
    
    // Test getQuotasForDate function
    $testQuotas = [
        ['date_from' => '2025-08-29', 'date_to' => '2025-08-31', 'title' => 'Test Quota', 'hrs_id' => 1]
    ];
    $result = getQuotasForDate($testQuotas, '2025-08-29');
    echo "✅ getQuotasForDate function works: " . count($result) . " quotas found\n";
    
} catch (Exception $e) {
    echo "❌ Error with quota_functions.php: " . $e->getMessage() . "\n";
}

echo "=== Test completed ===\n";
?>
