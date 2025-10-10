<?php
/**
 * DEBUG: AV-Belegung vs. Quota Berechnung
 * Verifiziert dass ALLE AV-Reservierungen (unabhängig von Kategorie) abgezogen werden
 */

require_once 'config.php';

$testDate = '2026-02-13'; // Ein Tag wo es Quota und Reservierungen gibt

echo "🔍 DEBUG: AV-Belegung für $testDate\n";
echo str_repeat("=", 80) . "\n\n";

// 1. Hole ALLE Reservierungen für diesen Tag (av_id > 0)
$sql = "SELECT id, av_id, anreise, abreise, nachname, vorname,
        COALESCE(lager, 0) as lager,
        COALESCE(betten, 0) as betten,
        COALESCE(dz, 0) as dz,
        COALESCE(sonder, 0) as sonder
        FROM `AV-Res`
        WHERE DATE(anreise) <= ?
          AND DATE(abreise) > ?
          AND av_id > 0
          AND (storno IS NULL OR storno = 0)
        ORDER BY av_id, nachname";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param('ss', $testDate, $testDate);
$stmt->execute();
$result = $stmt->get_result();

$totalAV = [
    'lager' => 0,
    'betten' => 0,
    'dz' => 0,
    'sonder' => 0
];

echo "📋 AV-Reservierungen (av_id > 0) für $testDate:\n";
echo str_repeat("-", 80) . "\n";
printf("%-8s %-6s %-25s %6s %6s %6s %6s %6s\n", 
    "ID", "AV-ID", "Gast", "Lager", "Betten", "DZ", "Sonder", "Total");
echo str_repeat("-", 80) . "\n";

$count = 0;
while ($row = $result->fetch_assoc()) {
    $name = trim($row['nachname'] . ' ' . $row['vorname']);
    $lager = (int)$row['lager'];
    $betten = (int)$row['betten'];
    $dz = (int)$row['dz'];
    $sonder = (int)$row['sonder'];
    $total = $lager + $betten + $dz + $sonder;
    
    printf("%-8s %-6s %-25s %6d %6d %6d %6d %6d\n",
        $row['id'], $row['av_id'], substr($name, 0, 25), 
        $lager, $betten, $dz, $sonder, $total);
    
    $totalAV['lager'] += $lager;
    $totalAV['betten'] += $betten;
    $totalAV['dz'] += $dz;
    $totalAV['sonder'] += $sonder;
    $count++;
}

echo str_repeat("-", 80) . "\n";
$totalAVBeds = array_sum($totalAV);
printf("TOTAL: %d Reservierungen, %d Betten gesamt\n", $count, $totalAVBeds);
printf("  → Lager: %d, Betten: %d, DZ: %d, Sonder: %d\n\n",
    $totalAV['lager'], $totalAV['betten'], $totalAV['dz'], $totalAV['sonder']);

// 2. Hole INTERNE Reservierungen (av_id = 0 oder NULL)
$sql2 = "SELECT id, COALESCE(av_id, 0) as av_id, anreise, abreise, nachname, vorname,
        COALESCE(lager, 0) as lager,
        COALESCE(betten, 0) as betten,
        COALESCE(dz, 0) as dz,
        COALESCE(sonder, 0) as sonder
        FROM `AV-Res`
        WHERE DATE(anreise) <= ?
          AND DATE(abreise) > ?
          AND (av_id IS NULL OR av_id = 0)
          AND (storno IS NULL OR storno = 0)
        ORDER BY nachname";

$stmt2 = $mysqli->prepare($sql2);
$stmt2->bind_param('ss', $testDate, $testDate);
$stmt2->execute();
$result2 = $stmt2->get_result();

$totalInternal = [
    'lager' => 0,
    'betten' => 0,
    'dz' => 0,
    'sonder' => 0
];

echo "📋 INTERNE Reservierungen (av_id = 0/NULL) für $testDate:\n";
echo str_repeat("-", 80) . "\n";
printf("%-8s %-6s %-25s %6s %6s %6s %6s %6s\n", 
    "ID", "AV-ID", "Gast", "Lager", "Betten", "DZ", "Sonder", "Total");
echo str_repeat("-", 80) . "\n";

$countInternal = 0;
while ($row = $result2->fetch_assoc()) {
    $name = trim($row['nachname'] . ' ' . $row['vorname']);
    $lager = (int)$row['lager'];
    $betten = (int)$row['betten'];
    $dz = (int)$row['dz'];
    $sonder = (int)$row['sonder'];
    $total = $lager + $betten + $dz + $sonder;
    
    printf("%-8s %-6s %-25s %6d %6d %6d %6d %6d\n",
        $row['id'], $row['av_id'], substr($name, 0, 25), 
        $lager, $betten, $dz, $sonder, $total);
    
    $totalInternal['lager'] += $lager;
    $totalInternal['betten'] += $betten;
    $totalInternal['dz'] += $dz;
    $totalInternal['sonder'] += $sonder;
    $countInternal++;
}

echo str_repeat("-", 80) . "\n";
$totalInternalBeds = array_sum($totalInternal);
printf("TOTAL: %d Reservierungen, %d Betten gesamt\n", $countInternal, $totalInternalBeds);
printf("  → Lager: %d, Betten: %d, DZ: %d, Sonder: %d\n\n",
    $totalInternal['lager'], $totalInternal['betten'], $totalInternal['dz'], $totalInternal['sonder']);

// 3. Hole Quota-Daten für diesen Tag
$sqlQuota = "SELECT 
    hq.id, hq.date_from, hq.date_to,
    SUM(CASE WHEN hqc.category_id = 1958 THEN COALESCE(hqc.total_beds, 0) ELSE 0 END) as quota_lager,
    SUM(CASE WHEN hqc.category_id = 2293 THEN COALESCE(hqc.total_beds, 0) ELSE 0 END) as quota_betten,
    SUM(CASE WHEN hqc.category_id = 2381 THEN COALESCE(hqc.total_beds, 0) ELSE 0 END) as quota_dz,
    SUM(CASE WHEN hqc.category_id = 6106 THEN COALESCE(hqc.total_beds, 0) ELSE 0 END) as quota_sonder
    FROM hut_quota hq
    LEFT JOIN hut_quota_categories hqc ON hq.id = hqc.hut_quota_id
    WHERE ? >= hq.date_from AND ? < hq.date_to
    GROUP BY hq.id, hq.date_from, hq.date_to";

$stmtQuota = $mysqli->prepare($sqlQuota);
$stmtQuota->bind_param('ss', $testDate, $testDate);
$stmtQuota->execute();
$resultQuota = $stmtQuota->get_result();

echo "📊 QUOTA-Daten für $testDate:\n";
echo str_repeat("-", 80) . "\n";

$totalQuota = [
    'lager' => 0,
    'betten' => 0,
    'dz' => 0,
    'sonder' => 0
];

while ($row = $resultQuota->fetch_assoc()) {
    $quotaLager = (int)$row['quota_lager'];
    $quotaBetten = (int)$row['quota_betten'];
    $quotaDz = (int)$row['quota_dz'];
    $quotaSonder = (int)$row['quota_sonder'];
    $quotaTotal = $quotaLager + $quotaBetten + $quotaDz + $quotaSonder;
    
    printf("Quota ID %d (%s bis %s):\n", 
        $row['id'], $row['date_from'], $row['date_to']);
    printf("  Lager: %d, Betten: %d, DZ: %d, Sonder: %d → Total: %d\n",
        $quotaLager, $quotaBetten, $quotaDz, $quotaSonder, $quotaTotal);
    
    $totalQuota['lager'] += $quotaLager;
    $totalQuota['betten'] += $quotaBetten;
    $totalQuota['dz'] += $quotaDz;
    $totalQuota['sonder'] += $quotaSonder;
}

echo str_repeat("-", 80) . "\n";
$totalQuotaBeds = array_sum($totalQuota);
printf("TOTAL QUOTA: %d Betten\n", $totalQuotaBeds);
printf("  → Lager: %d, Betten: %d, DZ: %d, Sonder: %d\n\n",
    $totalQuota['lager'], $totalQuota['betten'], $totalQuota['dz'], $totalQuota['sonder']);

// 4. Zielkapazität (hardcoded für Franzsennh ütte)
$targetCapacity = 117; // Franzsennh ütte Gesamtkapazität

echo "🎯 BERECHNUNG für $testDate:\n";
echo str_repeat("=", 80) . "\n";
printf("Zielkapazität:        %3d Betten\n", $targetCapacity);
printf("AV-Belegung:         -%3d Betten (ALLE Kategorien)\n", $totalAVBeds);
printf("Interne Belegung:    -%3d Betten\n", $totalInternalBeds);
echo str_repeat("-", 80) . "\n";
$availableQuota = $targetCapacity - $totalAVBeds - $totalInternalBeds;
printf("Verfügbare Quota:     %3d Betten\n\n", max(0, $availableQuota));

echo "✅ FORMEL: Verfügbare Quota = Ziel - AV (alle Kategorien) - Intern\n";
echo "✅ WICHTIG: AV-Belegung = Lager + Betten + DZ + Sonder (alle Kategorien!)\n\n";

echo "📦 Detail nach Kategorien:\n";
echo str_repeat("-", 80) . "\n";
printf("%-15s %8s %8s %8s %8s\n", "Kategorie", "Quota", "AV-Bel.", "Intern", "Verfügbar");
echo str_repeat("-", 80) . "\n";

foreach (['lager', 'betten', 'dz', 'sonder'] as $cat) {
    $available = $totalQuota[$cat] - $totalAV[$cat] - $totalInternal[$cat];
    printf("%-15s %8d %8d %8d %8d\n",
        ucfirst($cat),
        $totalQuota[$cat],
        $totalAV[$cat],
        $totalInternal[$cat],
        max(0, $available)
    );
}

echo str_repeat("-", 80) . "\n";
printf("%-15s %8d %8d %8d %8d\n",
    "GESAMT",
    $totalQuotaBeds,
    $totalAVBeds,
    $totalInternalBeds,
    max(0, $availableQuota)
);

echo "\n";
?>
