<?php
// POST-Parameter simulieren für Test
$_POST = [
    'action' => 'compare_backups',
    'baseline' => 'AV_Res_Backup_2025-08-28_02-39-48',
    'after_old' => 'AV_Res_Backup_2025-08-28_03-14-35',
    'after_new' => 'AV_Res_Backup_2025-08-28_03-13-16',
    'start_date' => '2025-08-28',
    'end_date' => '2025-08-30'  // Kleinerer Bereich für Test
];

// HTTP-Methode simulieren
$_SERVER['REQUEST_METHOD'] = 'POST';

// Backup-Analyse einbinden
include 'backup_analysis.php';
?>
