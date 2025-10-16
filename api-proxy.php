<?php
/**
 * Simple API Proxy for Holiday Manager
 * Handles CORS issues when accessing external APIs
 */

// Enable CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Get target URL
$targetUrl = $_GET['url'] ?? '';

if (empty($targetUrl)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing url parameter']);
    exit();
}

// Validate URL (security check)
$allowedDomains = [
    'date.nager.at',
    'api.date.nager.at'
];

$parsedUrl = parse_url($targetUrl);
if (!$parsedUrl || !in_array($parsedUrl['host'], $allowedDomains)) {
    http_response_code(403);
    echo json_encode(['error' => 'Domain not allowed']);
    exit();
}

try {
    // Initialize cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $targetUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_USERAGENT, 'WCI Holiday Manager/1.0');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'Content-Type: application/json'
    ]);
    
    // Execute request
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        throw new Exception('cURL Error: ' . $curlError);
    }
    
    // Return response with proper HTTP code
    http_response_code($httpCode);
    
    if ($httpCode === 200) {
        // Validate JSON
        $jsonData = json_decode($response);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON response');
        }
        echo $response;
    } else {
        echo json_encode(['error' => 'API request failed', 'http_code' => $httpCode]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>