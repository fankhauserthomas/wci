<?php
/**
 * Check Last Quota - Prüft die letzte gespeicherte Quota
 */

require_once __DIR__ . '/config.php';

echo "=== LETZTE QUOTA FÜR 12.02.2026 ===\n\n";

// Haupttabelle
$sql = "SELECT * FROM hut_quota WHERE date_from = '2026-02-12' ORDER BY id DESC LIMIT 1";
$result = $mysqli->query($sql);

if ($result && $row = $result->fetch_assoc()) {
    echo "📋 hut_quota:\n";
    echo "  ID: {$row['id']}\n";
    echo "  HRS-ID: {$row['hrs_id']}\n";
    echo "  Title: {$row['title']}\n";
    echo "  Date: {$row['date_from']} bis {$row['date_to']}\n";
    echo "  Mode: {$row['mode']}\n";
    echo "  Created: {$row['created_at']}\n";
    
    $quota_id = $row['id'];
    
    // Kategorien
    echo "\n📊 hut_quota_categories:\n";
    $sql2 = "SELECT * FROM hut_quota_categories WHERE hut_quota_id = ?";
    $stmt = $mysqli->prepare($sql2);
    $stmt->bind_param('i', $quota_id);
    $stmt->execute();
    $result2 = $stmt->get_result();
    
    $total = 0;
    while ($cat = $result2->fetch_assoc()) {
        $catName = match($cat['category_id']) {
            1958 => 'Lager (ML)',
            2293 => 'Betten (MBZ)',
            2381 => 'DZ (2BZ)',
            6106 => 'Sonder (SK)',
            default => 'Unknown'
        };
        echo "  {$catName}: {$cat['total_beds']} Plätze\n";
        $total += $cat['total_beds'];
    }
    echo "  TOTAL: $total Plätze\n";
    
} else {
    echo "❌ Keine Quota für 2026-02-12 gefunden!\n";
}

echo "\n=== HRS SYSTEM CHECK ===\n\n";
echo "Prüfe HRS manuell:\n";
echo "1. Öffne: https://www.hut-reservation.org/hut/manage-hut/675\n";
echo "2. Gehe zu 'Kapazitätsänderungen'\n";
echo "3. Suche 12.02.2026\n";
echo "4. Vergleiche mit obigen Werten\n";

?>
