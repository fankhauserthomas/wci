<?php
/**
 * Check: Was wurde beim letzten Update gelöscht?
 */

require_once __DIR__ . '/config.php';

echo "=== GELÖSCHTE QUOTAS (letzte 10) ===\n\n";

// Prüfe ob es eine History-Tabelle gibt
$sql = "SHOW TABLES LIKE 'hut_quota_deleted'";
$result = $mysqli->query($sql);

if ($result && $result->num_rows > 0) {
    echo "📋 Deleted-Tabelle gefunden\n\n";
    
    $sql = "SELECT * FROM hut_quota_deleted ORDER BY deleted_at DESC LIMIT 10";
    $result = $mysqli->query($sql);
    
    while ($row = $result->fetch_assoc()) {
        echo "Deleted: ID={$row['id']}, HRS-ID={$row['hrs_id']}, Date={$row['date_from']}\n";
    }
} else {
    echo "⚠️ Keine hut_quota_deleted Tabelle - Quotas werden direkt gelöscht\n\n";
    
    // Schaue letzte Änderungen
    echo "=== LETZTE QUOTA-ÄNDERUNGEN ===\n\n";
    $sql = "SELECT * FROM hut_quota WHERE date_from BETWEEN '2026-02-10' AND '2026-02-15' ORDER BY created_at DESC LIMIT 10";
    $result = $mysqli->query($sql);
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            echo "📋 ID: {$row['id']}, HRS-ID: {$row['hrs_id']}, Title: {$row['title']}, Date: {$row['date_from']}, Created: {$row['created_at']}\n";
        }
    }
}

echo "\n=== PROBLEM-ANALYSE ===\n\n";
echo "Im HRS-System sehe ich 'Auto-120226' mit 80 Plätzen.\n";
echo "In lokaler DB habe ich 'Timeline-120226' (HRS-ID 1909190304) mit 111 Plätzen.\n";
echo "\n";
echo "Mögliche Ursachen:\n";
echo "1. Die alte 'Auto-120226' Quota wurde nicht gelöscht\n";
echo "2. Die neue 'Timeline-120226' wurde nicht hochgeladen\n";
echo "3. HRS-System zeigt gecachte Daten\n";
echo "\n";
echo "Lösung: Manuell im HRS prüfen welche Quotas für 12.02.2026 existieren\n";

?>
