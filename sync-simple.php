<?php
// sync-simple.php - Simplified sync test

// Enable error reporting temporarily
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

try {
    $steps = [];
    
    $steps[] = "Step 1: Starting";
    
    // Test auth.php
    if (file_exists('auth.php')) {
        $steps[] = "Step 2: auth.php exists";
        require_once __DIR__ . '/auth.php';
        $steps[] = "Step 3: auth.php loaded";
    } else {
        throw new Exception("auth.php not found");
    }
    
    // Test config.php
    if (file_exists('config.php')) {
        $steps[] = "Step 4: config.php exists";
        require_once 'config.php';
        $steps[] = "Step 5: config.php loaded";
    } else {
        throw new Exception("config.php not found");
    }
    
    // Test hp-db-config.php
    if (file_exists('hp-db-config.php')) {
        $steps[] = "Step 6: hp-db-config.php exists";
        require_once 'hp-db-config.php';
        $steps[] = "Step 7: hp-db-config.php loaded";
    } else {
        throw new Exception("hp-db-config.php not found");
    }
    
    $steps[] = "Step 8: All includes successful";
    
    // Test authentication
    if (class_exists('AuthManager')) {
        $steps[] = "Step 9: AuthManager class found";
        $authResult = AuthManager::checkSession();
        $steps[] = "Step 10: Auth check result: " . ($authResult ? "authenticated" : "not authenticated");
    } else {
        throw new Exception("AuthManager class not found");
    }
    
    header('Content-Type: application/json');
    
    echo json_encode([
        'success' => true,
        'message' => 'All includes working',
        'steps' => $steps,
        'authenticated' => $authResult ?? false
    ]);
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'steps' => $steps ?? [],
        'line' => $e->getLine(),
        'file' => $e->getFile()
    ]);
}
?>
