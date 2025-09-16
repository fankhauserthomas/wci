<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config.php';

$sql = "SELECT id, bez FROM diet ORDER BY bez";
$res = $mysqli->query($sql);
if (!$res) {
    http_response_code(500);
    echo json_encode(['error'=>'SQL: '.$mysqli->error]);
    exit;
}

$out = [];
while ($row = $res->fetch_assoc()) {
    $out[] = ['id'=>$row['id'],'bez'=>$row['bez']];
}
echo json_encode($out);
