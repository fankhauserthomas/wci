<?php
/**
 * CORS Proxy for External APIs
 * ===========================
 * 
 * Ermöglicht sichere Anfragen an externe APIs ohne CORS-Probleme
 * Speziell für Nager.Date Holiday API
 */

// CORS Headers setzen
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

// Options Request behandeln
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// URL Parameter validieren
if (!isset($_GET['url'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing url parameter']);
    exit;
}

$targetUrl = $_GET['url'];

// Sicherheitscheck: Nur erlaubte APIs
$allowedDomains = [
    'date.nager.at',
    'api.date.nager.at'
];

$parsedUrl = parse_url($targetUrl);
if (!$parsedUrl || !in_array($parsedUrl['host'], $allowedDomains)) {
    http_response_code(403);
    echo json_encode(['error' => 'Domain not allowed']);
    exit;
}

// Anfrage weiterleiten
$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => [
            'User-Agent: WCI Holiday Manager/1.0',
            'Accept: application/json'
        ],
        'timeout' => 10
    ]
]);

$response = @file_get_contents($targetUrl, false, $context);

if ($response === false) {
    // HTTP Response Code aus den Headers extrahieren
    $responseCode = 500;
    if (isset($http_response_header[0])) {
        preg_match('/HTTP\/\d\.\d\s+(\d+)/', $http_response_header[0], $matches);
        if (isset($matches[1])) {
            $responseCode = (int)$matches[1];
        }
    }
    
    http_response_code($responseCode);
    echo json_encode(['error' => 'Failed to fetch data from external API', 'url' => $targetUrl]);
    exit;
}

// Content-Type aus Response Headers übernehmen
foreach ($http_response_header as $header) {
    if (stripos($header, 'content-type:') === 0) {
        header($header);
        break;
    }
}

echo $response;
?>