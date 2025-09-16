<?php
header('Content-Type: application/json');
require 'config.php';

// id aus GET
$id = $_GET['id'] ?? '';
if (!ctype_digit($id)) {
    http_response_code(400);
    echo json_encode(['error'=>'Ungültige ID']);
    exit;
}

// Baue URL
$apiUrl = sprintf(
    'https://booking.franzsennhuette.at/webform/api/gettokenfsh.php?id=%s&pwd=%s',
    $id,
    API_PWD
);

// rufe externes API ab
$ch = curl_init($apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

if ($err || !$response) {
    http_response_code(502);
    echo json_encode(['error'=>'API-Fehler']);
    exit;
}

// einfach weiterreichen
echo $response;
