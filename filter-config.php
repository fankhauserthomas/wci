<?php
// Filter-Konfiguration für WCI Access Analytics

return [
    'system_files' => [
        'ping.php',
        'sync_matrix.php', 
        'syncTrigger.php',
        'checkAuth.php',
        'api-access-stats.php',
        'health-check.php'
    ],
    
    'development_files' => [
        'debug.php',
        'debug-db.php', 
        'debug-hp-db.php',
        'test.php',
        'db-test.php',
        'minimal-test.php',
        'loading-test.html'
    ],
    
    'api_files' => [
        'include/data.php',
        'reservierungen/api/getArrangements.php',
        'reservierungen/api/getReservationNames.php',
        'getDashboardStats-simple.php'
    ],
    
    'presets' => [
        'all' => [],
        'business_only' => ['system_files'],
        'production_focus' => ['system_files', 'development_files'],
        'user_activity' => ['system_files', 'api_files']
    ]
];
?>
