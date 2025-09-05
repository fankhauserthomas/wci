<?php
// sync-info-api.php - Einfache API für Sync-Informationen
require_once 'SyncManager.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

try {
    $logFile = __DIR__ . '/logs/sync.log';
    $stats = [
        'lastSync' => 'Nie',
        'recordsToday' => 0,
        'recordsThisWeek' => 0,
        'errorsToday' => 0,
        'cronActive' => false
    ];
    
    // Prüfe Cron-Status - schaue auf letzte Log-Aktivität
    $stats['cronActive'] = false;
    if (file_exists($logFile)) {
        $lastModified = filemtime($logFile);
        $now = time();
        // Wenn Log in letzten 5 Minuten aktualisiert wurde, ist Cron wahrscheinlich aktiv
        if (($now - $lastModified) < 300) {
            $stats['cronActive'] = true;
        }
    }
    
    // Lese Log-Datei falls vorhanden
    if (file_exists($logFile)) {
        $logs = file_get_contents($logFile);
        $today = date('Y-m-d');
        $weekAgo = date('Y-m-d', strtotime('-7 days'));
        
        // Finde letzten Sync
        $lines = explode("\n", $logs);
        $lines = array_reverse($lines);
        
        foreach ($lines as $line) {
            if (strpos($line, 'Sync erfolgreich') !== false || strpos($line, 'erfolgreich') !== false || strpos($line, 'Sync abgeschlossen') !== false) {
                preg_match('/\[([\d\-\s:]+)\]/', $line, $matches);
                if ($matches) {
                    $stats['lastSync'] = $matches[1];
                    break;
                }
            }
        }
        
        // Zähle Records heute
        $todayLines = array_filter(explode("\n", $logs), function($line) use ($today) {
            return strpos($line, "[$today") !== false;
        });
        
        foreach ($todayLines as $line) {
            // Suche nach Patterns wie "synced 0 records" oder JSON mit results
            if (preg_match('/synced (\d+) records?/i', $line, $matches)) {
                $stats['recordsToday'] += intval($matches[1]);
            } elseif (preg_match('/"local_to_remote":(\d+).*"remote_to_local":(\d+)/i', $line, $matches)) {
                $stats['recordsToday'] += intval($matches[1]) + intval($matches[2]);
            } elseif (strpos($line, 'Sync erfolgreich') !== false && strpos($line, '0,') === false) {
                $stats['recordsToday']++;
            }
        }
        
        // Zähle Records diese Woche
        $weekLines = array_filter(explode("\n", $logs), function($line) use ($weekAgo) {
            preg_match('/\[(\d{4}-\d{2}-\d{2})/', $line, $matches);
            return $matches && $matches[1] >= $weekAgo;
        });
        
        foreach ($weekLines as $line) {
            if (preg_match('/synced (\d+) records?/i', $line, $matches)) {
                $stats['recordsThisWeek'] += intval($matches[1]);
            } elseif (preg_match('/"local_to_remote":(\d+).*"remote_to_local":(\d+)/i', $line, $matches)) {
                $stats['recordsThisWeek'] += intval($matches[1]) + intval($matches[2]);
            } elseif (strpos($line, 'Sync erfolgreich') !== false) {
                $stats['recordsThisWeek']++;
            }
        }
        
        // Zähle Fehler heute
        $errorLines = array_filter($todayLines, function($line) {
            return strpos($line, 'Fehler') !== false || strpos($line, 'ERROR') !== false || strpos($line, 'KRITISCHER FEHLER') !== false;
        });
        $stats['errorsToday'] = count($errorLines);
    }
    
    echo json_encode([
        'success' => true,
        'stats' => $stats
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'stats' => [
            'lastSync' => 'Fehler',
            'recordsToday' => 0,
            'recordsThisWeek' => 0,
            'errorsToday' => 1,
            'cronActive' => false
        ]
    ]);
}
?>
