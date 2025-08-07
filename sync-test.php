<?php
// sync-test.php - Debug script for sync-database issues

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

header('Content-Type: text/plain');

echo "=== Sync Database Debug Test ===\n";
echo "PHP Version: " . phpversion() . "\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n\n";

// Test 1: File includes
echo "1. Testing file includes...\n";
try {
    if (file_exists('auth-simple.php')) {
        echo "   ✅ auth-simple.php exists\n";
        require_once 'auth-simple.php';
        echo "   ✅ auth-simple.php loaded\n";
    } else {
        echo "   ❌ auth-simple.php NOT found\n";
    }
} catch (Exception $e) {
    echo "   ❌ auth-simple.php error: " . $e->getMessage() . "\n";
}

try {
    if (file_exists('config.php')) {
        echo "   ✅ config.php exists\n";
        require_once 'config.php';
        echo "   ✅ config.php loaded\n";
    } else {
        echo "   ❌ config.php NOT found\n";
    }
} catch (Exception $e) {
    echo "   ❌ config.php error: " . $e->getMessage() . "\n";
}

try {
    if (file_exists('hp-db-config.php')) {
        echo "   ✅ hp-db-config.php exists\n";
        require_once 'hp-db-config.php';
        echo "   ✅ hp-db-config.php loaded\n";
    } else {
        echo "   ❌ hp-db-config.php NOT found\n";
    }
} catch (Exception $e) {
    echo "   ❌ hp-db-config.php error: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 2: Authentication
echo "2. Testing authentication...\n";
try {
    if (class_exists('AuthManager')) {
        echo "   ✅ AuthManager class exists\n";
        $authResult = AuthManager::checkSession();
        echo "   Auth result: " . ($authResult ? "✅ authenticated" : "❌ not authenticated") . "\n";
    } else {
        echo "   ❌ AuthManager class NOT found\n";
    }
} catch (Exception $e) {
    echo "   ❌ Auth error: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 3: Database connections
echo "3. Testing database connections...\n";
try {
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        echo "   ✅ \$mysqli variable exists\n";
        if ($mysqli->ping()) {
            echo "   ✅ MySQL connection active\n";
        } else {
            echo "   ❌ MySQL connection lost: " . $mysqli->error . "\n";
        }
    } else {
        echo "   ❌ \$mysqli variable not found or not mysqli instance\n";
        echo "   Variable type: " . gettype($mysqli ?? null) . "\n";
    }
} catch (Exception $e) {
    echo "   ❌ MySQL test error: " . $e->getMessage() . "\n";
}

try {
    if (function_exists('getHpDbConnection')) {
        echo "   ✅ getHpDbConnection function exists\n";
        $hpConn = getHpDbConnection();
        if ($hpConn && $hpConn instanceof mysqli) {
            echo "   ✅ HP connection created\n";
            if ($hpConn->ping()) {
                echo "   ✅ HP connection active\n";
            } else {
                echo "   ❌ HP connection lost: " . $hpConn->error . "\n";
            }
        } else {
            echo "   ❌ HP connection failed\n";
            echo "   HP connection type: " . gettype($hpConn) . "\n";
        }
    } else {
        echo "   ❌ getHpDbConnection function NOT found\n";
    }
} catch (Exception $e) {
    echo "   ❌ HP connection test error: " . $e->getMessage() . "\n";
}

echo "\n=== Debug Complete ===\n";
?>
