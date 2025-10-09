<?php
/**
 * Check WebImp Staging Table Status
 * 
 * Diagnostic tool to verify if data is stuck in AV-Res-webImp
 */

require_once __DIR__ . '/config.php';

if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    die("ERROR: No MySQL connection available\n");
}

echo "=== AV-Res-webImp Staging Table Status ===\n\n";

// 1. Count total records
$result = $mysqli->query("SELECT COUNT(*) as total FROM `AV-Res-webImp`");
if (!$result) {
    die("ERROR: Could not query AV-Res-webImp: " . $mysqli->error . "\n");
}
$row = $result->fetch_assoc();
$totalWebImp = $row['total'];

echo "Total records in AV-Res-webImp: $totalWebImp\n";

if ($totalWebImp == 0) {
    echo "\n⚠️  WARNING: AV-Res-webImp is EMPTY!\n";
    echo "No data to transfer to AV-Res.\n";
    exit(0);
}

// 2. Count by status
echo "\nRecords by status (vorgang):\n";
$result = $mysqli->query("
    SELECT vorgang, COUNT(*) as count 
    FROM `AV-Res-webImp` 
    GROUP BY vorgang
    ORDER BY count DESC
");

while ($row = $result->fetch_assoc()) {
    $status = $row['vorgang'] ?: '(null)';
    $count = $row['count'];
    echo "  - $status: $count\n";
}

// 3. Count records eligible for import (CONFIRMED + DISCARDED)
$result = $mysqli->query("
    SELECT COUNT(*) as eligible 
    FROM `AV-Res-webImp` 
    WHERE UPPER(TRIM(vorgang)) IN ('CONFIRMED', 'DISCARDED')
");
$row = $result->fetch_assoc();
$eligible = $row['eligible'];

echo "\n✅ Records eligible for import (CONFIRMED + DISCARDED): $eligible\n";
echo "🚫 Records that will be skipped (SUBMITTED + ON_WAITING_LIST): " . ($totalWebImp - $eligible) . "\n";

// 4. Check AV-Res production table
$result = $mysqli->query("SELECT COUNT(*) as total FROM `AV-Res`");
$row = $result->fetch_assoc();
$totalProduction = $row['total'];

echo "\nTotal records in AV-Res (production): $totalProduction\n";

// 5. Sample from AV-Res-webImp
echo "\n=== Sample records from AV-Res-webImp (first 3) ===\n";
$result = $mysqli->query("
    SELECT av_id, anreise, abreise, vorgang, nachname, vorname, 
           COALESCE(dz,0) + COALESCE(betten,0) + COALESCE(lager,0) + COALESCE(sonder,0) as total_pax
    FROM `AV-Res-webImp` 
    ORDER BY av_id 
    LIMIT 3
");

while ($row = $result->fetch_assoc()) {
    echo "\nAV-ID: {$row['av_id']}\n";
    echo "  Name: {$row['vorname']} {$row['nachname']}\n";
    echo "  Dates: {$row['anreise']} - {$row['abreise']}\n";
    echo "  Status: {$row['vorgang']}\n";
    echo "  Persons: {$row['total_pax']}\n";
}

// 6. Check if import script exists
echo "\n=== Import Script Check ===\n";
$importScriptPath = __DIR__ . '/hrs/import_webimp.php';
if (file_exists($importScriptPath)) {
    echo "✅ Import script exists: $importScriptPath\n";
    echo "   File size: " . filesize($importScriptPath) . " bytes\n";
    echo "   Last modified: " . date('Y-m-d H:i:s', filemtime($importScriptPath)) . "\n";
} else {
    echo "❌ ERROR: Import script NOT FOUND: $importScriptPath\n";
}

$orchestratorPath = __DIR__ . '/belegung/hrs_import.php';
if (file_exists($orchestratorPath)) {
    echo "✅ Orchestrator exists: $orchestratorPath\n";
} else {
    echo "❌ ERROR: Orchestrator NOT FOUND: $orchestratorPath\n";
}

echo "\n=== DIAGNOSIS ===\n";
if ($totalWebImp == 0) {
    echo "❌ No data in staging table - nothing to import\n";
} elseif ($eligible == 0) {
    echo "⚠️  Data exists but ALL records have wrong status (need CONFIRMED/DISCARDED)\n";
} else {
    echo "✅ $eligible records ready for import\n";
    echo "\nTo trigger import manually, run:\n";
    echo "  php " . __DIR__ . "/belegung/hrs_import.php --from=2026-02-01 --to=2026-03-31 --data-types=reservations\n";
    echo "\nOr trigger via web:\n";
    echo "  http://your-server/wci/belegung/hrs_import.php?from=2026-02-01&to=2026-03-31&data-types=reservations\n";
}

echo "\n";
?>
