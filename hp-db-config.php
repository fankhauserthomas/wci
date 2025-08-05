<?php
// hp-db-config.php - Completely separate HP database connection

// HP_DB Konfiguration
$HP_DB_CONFIG = [
    'host' => '192.168.2.81',
    'user' => 'fsh',
    'pass' => 'er1234tf',
    'name' => 'fsh-res'
];

// Function to get HP DB connection when needed
function getHpDbConnection() {
    global $HP_DB_CONFIG;
    
    static $hpConnection = null;
    
    if ($hpConnection === null) {
        try {
            $hpConnection = new mysqli(
                $HP_DB_CONFIG['host'],
                $HP_DB_CONFIG['user'],
                $HP_DB_CONFIG['pass'],
                $HP_DB_CONFIG['name']
            );
            
            if ($hpConnection->connect_error) {
                error_log('HP_DB connection failed: ' . $hpConnection->connect_error);
                return false;
            }
            
            $hpConnection->set_charset('utf8mb4');
            
        } catch (Exception $e) {
            error_log('HP_DB exception: ' . $e->getMessage());
            return false;
        }
    }
    
    return $hpConnection;
}

// Function to check if HP DB is available
function isHpDbAvailable() {
    $conn = getHpDbConnection();
    return $conn !== false;
}
