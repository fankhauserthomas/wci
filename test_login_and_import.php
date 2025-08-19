<?php
require_once 'hrs_login_debug.php';

echo "=== HRS Login + HutQuota Import Test ===\n";

$hrs = new HRSLoginDebug();

// 1. Erst Login
echo "🔐 Step 1: HRS Login...\n";
$loginResult = $hrs->login();

if (!$loginResult) {
    echo "❌ Login failed, stopping test\n";
    exit(1);
}

echo "✅ Login successful!\n\n";

// 2. Dann HutQuota Import
echo "🏠 Step 2: HutQuota Import...\n";
$hutId = 675;
$months = 1;

$importResult = $hrs->testFullHutQuotaImport($months, $hutId);

if ($importResult && is_array($importResult)) {
    echo "\n✅ IMPORT SUCCESS!\n";
    echo "Imported: " . $importResult['imported'] . "\n";
    echo "Updated: " . $importResult['updated'] . "\n"; 
    echo "Errors: " . $importResult['errors'] . "\n";
    echo "Total: " . $importResult['total'] . "\n";
} else {
    echo "\n❌ IMPORT FAILED\n";
    var_dump($importResult);
}
