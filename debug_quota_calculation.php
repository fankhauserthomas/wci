<?php
/**
 * Debug Quota Calculation
 * Analysiert die Berechnung für 12.2. - 18.2.2025
 */

require_once __DIR__ . '/config.php';

$conn = $mysqli; // Use global connection

echo "=== QUOTA CALCULATION DEBUG ===\n\n";
echo "Analysiere Zeitraum: 12.2.2026 - 18.2.2026\n\n";

// Für jeden Tag
$dates = [
    '2026-02-12',
    '2026-02-13',
    '2026-02-14',
    '2026-02-15',
    '2026-02-16',
    '2026-02-17',
    '2026-02-18'
];

$targetCapacity = 100; // Beispiel-Zielkapazität

echo "Annahme: Zielkapazität = $targetCapacity\n\n";
echo str_repeat("=", 80) . "\n\n";

foreach ($dates as $date) {
    echo "📅 DATUM: $date\n";
    echo str_repeat("-", 80) . "\n";
    
    // 1. Alle Reservierungen die an diesem Tag übernachten
    $sql = "SELECT 
                id,
                CONCAT(COALESCE(vorname,''), ' ', COALESCE(nachname,'')) as name,
                anreise,
                abreise,
                COALESCE(lager,0) + COALESCE(betten,0) + COALESCE(dz,0) + COALESCE(sonder,0) as pers,
                av_id,
                COALESCE(lager,0) as lager,
                COALESCE(betten,0) as betten,
                COALESCE(dz,0) as dz,
                COALESCE(sonder,0) as sonder,
                storno
            FROM `AV-Res`
            WHERE anreise <= ? 
              AND abreise > ?
              AND (storno = 0 OR storno IS NULL)
            ORDER BY av_id DESC, nachname";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $date, $date);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $av_personen = 0;
    $interne_personen = 0;
    $av_lager = 0;
    $av_betten = 0;
    $av_dz = 0;
    $av_sonder = 0;
    $intern_lager = 0;
    $intern_betten = 0;
    $intern_dz = 0;
    $intern_sonder = 0;
    
    echo "\nReservierungen:\n";
    
    while ($row = $result->fetch_assoc()) {
        $is_av = ($row['av_id'] && $row['av_id'] > 0);
        $type = $is_av ? "AV" : "INTERN";
        
        echo sprintf(
            "  [%s] ID=%d, Name=%s, Pers=%d, av_id=%s, Lager=%d, Betten=%d, DZ=%d, Sonder=%d\n",
            $type,
            $row['id'],
            substr($row['name'], 0, 30),
            $row['pers'],
            $row['av_id'] ?: 'NULL',
            $row['lager'] ?: 0,
            $row['betten'] ?: 0,
            $row['dz'] ?: 0,
            $row['sonder'] ?: 0
        );
        
        if ($is_av) {
            $av_personen += $row['pers'];
            $av_lager += $row['lager'] ?: 0;
            $av_betten += $row['betten'] ?: 0;
            $av_dz += $row['dz'] ?: 0;
            $av_sonder += $row['sonder'] ?: 0;
        } else {
            $interne_personen += $row['pers'];
            $intern_lager += $row['lager'] ?: 0;
            $intern_betten += $row['betten'] ?: 0;
            $intern_dz += $row['dz'] ?: 0;
            $intern_sonder += $row['sonder'] ?: 0;
        }
    }
    
    echo "\n";
    echo "📊 ZUSAMMENFASSUNG:\n";
    echo "  AV-Reservierungen (av_id > 0):\n";
    echo "    Personen: $av_personen\n";
    echo "    Lager: $av_lager, Betten: $av_betten, DZ: $av_dz, Sonder: $av_sonder\n";
    echo "    Summe Plätze: " . ($av_lager + $av_betten + $av_dz + $av_sonder) . "\n";
    
    echo "  Interne Reservierungen (av_id = 0/NULL):\n";
    echo "    Personen: $interne_personen\n";
    echo "    Lager: $intern_lager, Betten: $intern_betten, DZ: $intern_dz, Sonder: $intern_sonder\n";
    echo "    Summe Plätze: " . ($intern_lager + $intern_betten + $intern_dz + $intern_sonder) . "\n";
    
    $total_av = $av_lager + $av_betten + $av_dz + $av_sonder;
    $total_intern = $intern_lager + $intern_betten + $intern_dz + $intern_sonder;
    $total_all = $total_av + $total_intern;
    
    echo "  GESAMT:\n";
    echo "    Total AV: $total_av\n";
    echo "    Total Intern: $total_intern\n";
    echo "    Total Alle: $total_all\n";
    
    echo "\n";
    echo "🧮 QUOTA-BERECHNUNG:\n";
    
    // Alte (falsche?) Formel
    $old_quota = max(0, $targetCapacity - $total_all);
    echo "  ❌ ALTE Formel: Quota = max(0, Ziel - (AV + Intern))\n";
    echo "     Quota = max(0, $targetCapacity - $total_all) = $old_quota\n";
    
    // Neue (korrekte?) Formel aus VB.NET
    $new_quota = max(0, $targetCapacity - $total_intern);
    echo "  ✅ NEUE Formel: Quota = max(0, Ziel - Intern)\n";
    echo "     Quota = max(0, $targetCapacity - $total_intern) = $new_quota\n";
    
    // Alternative Formel aus Frontend
    $frontend_quota = max(0, $targetCapacity + $total_av - $total_intern);
    echo "  🔄 FRONTEND Formel: Quota = max(0, Ziel + AV - Intern)\n";
    echo "     Quota = max(0, $targetCapacity + $total_av - $total_intern) = $frontend_quota\n";
    
    echo "\n" . str_repeat("=", 80) . "\n\n";
}

$conn->close();
?>
