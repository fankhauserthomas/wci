<?php
// POST-Parameter simulieren für Test
$_POST = [
    'action' => 'analyze_specific_records',
    'av_ids' => '0,5235447'
];

// HTTP-Methode simulieren
$_SERVER['REQUEST_METHOD'] = 'POST';

// Backup-Analyse einbinden
include 'backup_analysis.php';
?>
