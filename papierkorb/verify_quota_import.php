<?php
/**
 * Quota Import Verifikation
 */

require_once 'config.php';

try {
    // Verifikation der importierten Quotas
    $query = "SELECT hq.hrs_id, hq.title, hq.date_from, hq.date_to, hq.capacity, hq.mode,
              (SELECT COUNT(*) FROM hut_quota_categories hqc WHERE hqc.hut_quota_id = hq.id) as categories_count,
              (SELECT COUNT(*) FROM hut_quota_languages hql WHERE hql.hut_quota_id = hq.id) as languages_count
              FROM hut_quota hq 
              WHERE hq.date_from >= '2025-08-20' AND hq.date_to <= '2025-09-01' 
              ORDER BY hq.date_from DESC";
    
    $result = $mysqli->query($query);
    
    if ($result) {
        echo "=== HRS Quota Import Verifikation ===\n\n";
        echo sprintf("%-8s %-15s %-12s %-12s %-8s %-10s %-4s %-4s\n", 
            "HRS_ID", "TITLE", "DATE_FROM", "DATE_TO", "CAPACITY", "MODE", "CAT", "LANG");
        echo str_repeat("-", 80) . "\n";
        
        $count = 0;
        while ($row = $result->fetch_assoc()) {
            $count++;
            echo sprintf("%-8s %-15s %-12s %-12s %-8s %-10s %-4s %-4s\n",
                $row['hrs_id'],
                substr($row['title'], 0, 15),
                $row['date_from'],
                $row['date_to'],
                $row['capacity'],
                $row['mode'],
                $row['categories_count'],
                $row['languages_count']
            );
        }
        
        echo "\n✅ Total imported quotas: $count\n";
        
        // Kategorie-Details für ein Beispiel
        $categoryQuery = "SELECT hqc.category_id, hqc.total_beds 
                         FROM hut_quota_categories hqc 
                         JOIN hut_quota hq ON hqc.hut_quota_id = hq.id 
                         WHERE hq.hrs_id = 42205";
        
        $catResult = $mysqli->query($categoryQuery);
        if ($catResult && $catResult->num_rows > 0) {
            echo "\n=== Kategorie-Details für Quota 42205 'yxc' ===\n";
            while ($catRow = $catResult->fetch_assoc()) {
                echo "Category {$catRow['category_id']}: {$catRow['total_beds']} beds\n";
            }
        }
        
    } else {
        echo "❌ Database query failed: " . $mysqli->error . "\n";
    }
    
} catch (Exception $e) {
    echo "❌ Exception: " . $e->getMessage() . "\n";
}
?>
