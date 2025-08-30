<?php
// Test script to debug updateReservationDetails.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';

// Test data for ID 9227
$test_data = [
    'id' => 9227,
    'nachname' => 'Dennebaum',
    'vorname' => 'Yannik',
    'handy' => '',
    'email' => '',
    'bem' => '0',
    'bem_av' => '',
    'arr' => null,
    'origin' => null,
    'lager' => 1,  // <- This should be saved!
    'betten' => 0,
    'dz' => 0,
    'sonder' => 0,
    'storno' => 0,
    'hund' => 0,
    'invoice' => 0,
    'anreise' => '2025-08-30 00:00:00',
    'abreise' => '2025-08-31 00:00:00'
];

echo "<h1>Test Update für ID 9227</h1>";

// Check current data first
echo "<h2>Aktuelle Daten:</h2>";
$sql = "SELECT id, lager, betten, dz, sonder, nachname, vorname FROM `AV-Res` WHERE id = 9227";
$result = $mysqli->query($sql);
if ($result) {
    $current = $result->fetch_assoc();
    echo "<pre>";
    print_r($current);
    echo "</pre>";
} else {
    echo "Fehler beim Lesen: " . $mysqli->error;
}

// Simulate the update
echo "<h2>Update ausführen...</h2>";

$id = (int)$test_data['id'];

// Check if reservation exists and get av_id
$sql = "SELECT av_id FROM `AV-Res` WHERE id = ?";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param('i', $id);
$stmt->execute();
$stmt->bind_result($av_id);

if (!$stmt->fetch()) {
    echo "Reservierung nicht gefunden!";
    exit;
}
$stmt->close();

echo "av_id: $av_id<br>";

$isReadonly = $av_id && (int)$av_id > 0;
echo "isReadonly: " . ($isReadonly ? 'true' : 'false') . "<br>";

// Prepare update fields
$updateFields = [];
$params = [];
$types = '';

// Fields that can always be updated
$updateFields[] = "bem = ?";
$params[] = $test_data['bem'] ?? '';
$types .= 's';

$updateFields[] = "arr = ?";
$params[] = $test_data['arr'] ? (int)$test_data['arr'] : 0;
$types .= 'i';

$updateFields[] = "origin = ?";
$params[] = $test_data['origin'] ? (int)$test_data['origin'] : 0;
$types .= 'i';

// Schlafkategorien
$updateFields[] = "lager = ?";
$params[] = (int)($test_data['lager'] ?? 0);
$types .= 'i';

$updateFields[] = "betten = ?";
$params[] = (int)($test_data['betten'] ?? 0);
$types .= 'i';

$updateFields[] = "dz = ?";
$params[] = (int)($test_data['dz'] ?? 0);
$types .= 'i';

$updateFields[] = "sonder = ?";
$params[] = (int)($test_data['sonder'] ?? 0);
$types .= 'i';

$updateFields[] = "hund = ?";
$params[] = (int)($test_data['hund'] ?? 0);
$types .= 'i';

$updateFields[] = "invoice = ?";
$params[] = (int)($test_data['invoice'] ?? 0);
$types .= 'i';

$updateFields[] = "anreise = ?";
$params[] = $test_data['anreise'];
$types .= 's';

$updateFields[] = "abreise = ?";
$params[] = $test_data['abreise'];
$types .= 's';

// Fields that can only be updated if not readonly
if (!$isReadonly) {
    $updateFields[] = "nachname = ?";
    $params[] = $test_data['nachname'];
    $types .= 's';
    
    $updateFields[] = "vorname = ?";
    $params[] = $test_data['vorname'];
    $types .= 's';
    
    $updateFields[] = "bem_av = ?";
    $params[] = $test_data['bem_av'] ?? '';
    $types .= 's';
    
    $updateFields[] = "handy = ?";
    $params[] = $test_data['handy'] ?? '';
    $types .= 's';
    
    $updateFields[] = "email = ?";
    $params[] = $test_data['email'] ?? '';
    $types .= 's';
    
    $updateFields[] = "storno = ?";
    $params[] = (int)($test_data['storno'] ?? 0);
    $types .= 'i';
}

// Add ID parameter for WHERE clause
$params[] = $id;
$types .= 'i';

// Build and execute update query
$sql = "UPDATE `AV-Res` SET " . implode(", ", $updateFields) . " WHERE id = ?";
echo "<h3>SQL Query:</h3>";
echo "<pre>$sql</pre>";

echo "<h3>Parameters:</h3>";
echo "<pre>Types: $types</pre>";
echo "<pre>";
print_r($params);
echo "</pre>";

$stmt = $mysqli->prepare($sql);
if ($stmt === false) {
    echo "SQL-Vorbereitung fehlgeschlagen: " . $mysqli->error;
    exit;
}

$stmt->bind_param($types, ...$params);

if (!$stmt->execute()) {
    echo "Fehler beim Aktualisieren: " . $stmt->error;
    exit;
}

$affectedRows = $stmt->affected_rows;
$stmt->close();

echo "<h3>Ergebnis:</h3>";
echo "Affected rows: $affectedRows<br>";

// Check updated data
echo "<h2>Daten nach Update:</h2>";
$sql = "SELECT id, lager, betten, dz, sonder, nachname, vorname FROM `AV-Res` WHERE id = 9227";
$result = $mysqli->query($sql);
if ($result) {
    $updated = $result->fetch_assoc();
    echo "<pre>";
    print_r($updated);
    echo "</pre>";
} else {
    echo "Fehler beim Lesen: " . $mysqli->error;
}
?>
