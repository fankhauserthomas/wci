<?php
// Direkte Datenbank-Prüfung für HutQuota-Änderungen
try {
    $pdo = new PDO('mysql:host=lamp_db;dbname=wci', 'root', 'password');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "=== HutQuota Einträge für 29.08-31.08.2025 ===\n";
    
    $stmt = $pdo->prepare("
        SELECT * FROM HutQuota 
        WHERE date_from >= '2025-08-29' AND date_from <= '2025-08-31' 
        ORDER BY date_from, created_at DESC
    ");
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($results)) {
        echo "❌ KEINE HutQuota-Einträge für diesen Zeitraum gefunden!\n";
        
        // Prüfe alle HutQuota-Einträge
        $stmt2 = $pdo->prepare("SELECT COUNT(*) as total FROM HutQuota");
        $stmt2->execute();
        $total = $stmt2->fetch()['total'];
        echo "📊 Gesamt HutQuota-Einträge in Datenbank: {$total}\n";
        
        if ($total > 0) {
            $stmt3 = $pdo->prepare("SELECT * FROM HutQuota ORDER BY created_at DESC LIMIT 5");
            $stmt3->execute();
            $recent = $stmt3->fetchAll(PDO::FETCH_ASSOC);
            
            echo "\n🔍 Letzte 5 HutQuota-Einträge:\n";
            foreach ($recent as $row) {
                echo "- ID: {$row['id']}, Date: {$row['date_from']} - {$row['date_to']}, Quota: {$row['quota']}, Created: {$row['created_at']}\n";
            }
        }
    } else {
        echo "✅ Gefundene HutQuota-Einträge:\n";
        foreach ($results as $row) {
            echo "- ID: {$row['id']}, Date: {$row['date_from']} - {$row['date_to']}, Quota: {$row['quota']}, HRS-ID: {$row['hrs_id']}, Created: {$row['created_at']}\n";
        }
    }
    
} catch (PDOException $e) {
    echo "❌ Datenbank-Fehler: " . $e->getMessage() . "\n";
}
?>
