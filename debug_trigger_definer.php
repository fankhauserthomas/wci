<?php
// debug_trigger_definer.php - Check Trigger Definer Issues

echo "=== TRIGGER DEFINER DEBUG ===\n\n";

try {
    // Remote DB Connection
    $remoteDb = new mysqli('booking.franzsennhuette.at', 'booking_franzsen', '~2Y@76', 'booking_franzsen');
    if ($remoteDb->connect_error) {
        die('Remote DB connection failed: ' . $remoteDb->connect_error);
    }
    
    echo "✅ Remote DB connected successfully\n\n";
    
    // Check triggers for AV-Res table
    echo "--- AV-Res TRIGGERS ---\n";
    $result = $remoteDb->query("SHOW TRIGGERS LIKE 'AV-Res'");
    
    if ($result && $result->num_rows > 0) {
        while ($trigger = $result->fetch_assoc()) {
            echo "Trigger: {$trigger['Trigger']}\n";
            echo "Event: {$trigger['Event']}\n"; 
            echo "Table: {$trigger['Table']}\n";
            echo "Definer: {$trigger['Definer']}\n";
            echo "Statement: " . substr($trigger['Statement'], 0, 100) . "...\n\n";
        }
    } else {
        echo "❌ No triggers found for AV-Res\n\n";
    }
    
    // Check current user permissions
    echo "--- USER PERMISSIONS ---\n";
    $result = $remoteDb->query("SELECT USER(), CURRENT_USER()");
    if ($result) {
        $user = $result->fetch_assoc();
        echo "Connected as: {$user['USER()']}\n";
        echo "Current user: {$user['CURRENT_USER()']}\n\n";
    }
    
    // Check if user exists
    echo "--- USER EXISTENCE CHECK ---\n";
    $result = $remoteDb->query("SELECT User, Host FROM mysql.user WHERE User LIKE '%booking_franzsen%'");
    if ($result && $result->num_rows > 0) {
        while ($user = $result->fetch_assoc()) {
            echo "User: {$user['User']}@{$user['Host']}\n";
        }
    } else {
        echo "❌ No matching users found\n";
    }
    
    echo "\n--- DEFINER PROBLEM ANALYSIS ---\n";
    echo "The error suggests triggers are defined with a user that doesn't exist\n";
    echo "or has different hostname. This happens when triggers are imported\n";
    echo "from a different server.\n\n";
    
    echo "SOLUTION: Recreate triggers with correct definer\n";
    
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}
?>
