<?php
// Alternative HRS Authentication - Browser Simulation
error_reporting(E_ALL);
ini_set('display_errors', 1);

$email = "offece@franzsennhuette.at";
$password = "Fsh2147m!3";
$baseUrl = "https://www.hut-reservation.org";

echo "=== HRS Browser Simulation Test ===\n\n";

// Try using file_get_contents with a comprehensive context
function makeHttpRequest($url, $method = 'GET', $data = null, $headers = []) {
    $defaultHeaders = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
        'Accept-Language: de-DE,de;q=0.8,en-US;q=0.5,en;q=0.3',
        'Accept-Encoding: gzip, deflate, br',
        'DNT: 1',
        'Connection: keep-alive',
        'Upgrade-Insecure-Requests: 1',
        'Sec-Fetch-Dest: document',
        'Sec-Fetch-Mode: navigate',
        'Sec-Fetch-Site: none',
        'Sec-Fetch-User: ?1'
    ];
    
    $allHeaders = array_merge($defaultHeaders, $headers);
    
    $context = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $allHeaders),
            'follow_location' => true,
            'max_redirects' => 5,
            'timeout' => 30,
            'ignore_errors' => true
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ];
    
    if ($data && $method === 'POST') {
        $context['http']['content'] = $data;
    }
    
    $streamContext = stream_context_create($context);
    $result = file_get_contents($url, false, $streamContext);
    
    // Get response headers
    $headers = $http_response_header ?? [];
    $statusLine = $headers[0] ?? '';
    preg_match('/HTTP\/\d\.\d\s+(\d+)/', $statusLine, $matches);
    $statusCode = $matches[1] ?? '0';
    
    return [
        'code' => (int)$statusCode,
        'body' => $result,
        'headers' => $headers
    ];
}

try {
    // Step 1: Visit main page
    echo "Step 1: Visiting main page with file_get_contents...\n";
    $result = makeHttpRequest($baseUrl);
    echo "Status: {$result['code']}\n";
    echo "Body length: " . strlen($result['body']) . "\n";
    echo "Headers count: " . count($result['headers']) . "\n\n";
    
    if ($result['code'] === 200) {
        // Step 2: Get CSRF token
        echo "Step 2: Getting CSRF token...\n";
        $result = makeHttpRequest("$baseUrl/api/v1/csrf", 'GET', null, [
            'Accept: application/json, text/plain, */*',
            'X-Requested-With: XMLHttpRequest'
        ]);
        echo "Status: {$result['code']}\n";
        echo "Response: " . substr($result['body'], 0, 200) . "\n\n";
        
        if ($result['code'] === 200) {
            $csrfData = json_decode($result['body'], true);
            $csrfToken = $csrfData['token'] ?? null;
            
            if ($csrfToken) {
                echo "CSRF Token obtained: " . substr($csrfToken, 0, 20) . "...\n\n";
                
                // Step 3: Try a simple API call that might not require authentication
                echo "Step 3: Testing basic API access...\n";
                $result = makeHttpRequest("$baseUrl/api/v1/csrf", 'GET', null, [
                    'Accept: application/json',
                    'X-XSRF-TOKEN: ' . $csrfToken
                ]);
                echo "Status: {$result['code']}\n";
                echo "Response: " . substr($result['body'], 0, 100) . "\n\n";
            }
        }
    }
    
    echo "If we're still getting 403 errors, it suggests the WAF is blocking our script.\n";
    echo "The VB.NET code works because Playwright uses a real browser engine.\n";
    echo "For production use, consider:\n";
    echo "1. Running this from a different IP/server\n";
    echo "2. Using a headless browser like Puppeteer or Playwright\n";
    echo "3. Adding delays between requests\n";
    echo "4. Contacting the site administrators for API access\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
