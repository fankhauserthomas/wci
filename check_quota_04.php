<?php
$mysqli = new mysqli('localhost', 'wcidbuser', 'wcidbpass', 'wcidb', null, '/var/run/mysqld/mysqld.sock');
if ($mysqli->connect_error) {
    die('Connect Error: ' . $mysqli->connect_error);
}

$result = $mysqli->query("SELECT id, date_from, date_to, mode FROM hut_quota WHERE hut_id = 675 AND date_from >= '2026-04-03' AND date_to <= '2026-04-06' ORDER BY date_from");

echo "Quotas for 2026-04-03 to 2026-04-06:\n\n";
while ($row = $result->fetch_assoc()) {
    echo "ID: {$row['id']}, From: {$row['date_from']}, To: {$row['date_to']}, Mode: {$row['mode']}\n";
}

$mysqli->close();
