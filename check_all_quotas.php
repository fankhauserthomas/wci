<?php
/**
 * Zeige alle Quotas für 12.02.2026
 */

require_once __DIR__ . '/config.php';

echo "=== ALLE QUOTAS FÜR 12.02.2026 IN DB ===\n\n";

$sql = "SELECT q.*, 
        GROUP_CONCAT(CONCAT(c.category_id, ':', c.total_beds) SEPARATOR ', ') as categories
        FROM hut_quota q
        LEFT JOIN hut_quota_categories c ON c.hut_quota_id = q.id
        WHERE q.date_from = '2026-02-12'
        GROUP BY q.id
        ORDER BY q.created_at DESC";

$result = $mysqli->query($sql);

if ($result && $result->num_rows > 0) {
    echo "Gefunden: {$result->num_rows} Quotas\n\n";
    
    while ($row = $result->fetch_assoc()) {
        echo "📋 ID: {$row['id']}\n";
        echo "   HRS-ID: {$row['hrs_id']}\n";
        echo "   Title: {$row['title']}\n";
        echo "   Von-Bis: {$row['date_from']} → {$row['date_to']}\n";
        echo "   Mode: {$row['mode']}\n";
        echo "   Kategorien: {$row['categories']}\n";
        echo "   Erstellt: {$row['created_at']}\n";
        echo "\n";
    }
} else {
    echo "❌ Keine Quotas gefunden\n";
}

echo "\n=== KATEGORIE-MAPPING ===\n";
echo "1958 = Lager (ML)\n";
echo "2293 = Betten (MBZ)\n";
echo "2381 = DZ (2BZ)\n";
echo "6106 = Sonder (SK)\n";

?>
