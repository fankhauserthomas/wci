<?php
// Simple HRS Authentication Test
$email = "offece@franzsennhuette.at";
$password = "Fsh2147m!3";
$baseUrl = "https://www.hut-reservation.org";

// Create a cookie jar file
$cookieFile = tempnam(sys_get_temp_dir(), "hrs_cookies");

function makeRequest($url, $method = 'GET', $data = null, $headers = [], $cookieFile = null) {
    $ch = curl_init();
    
    // Base cURL options
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
    ]);
    
    // Cookie handling
    if ($cookieFile) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }
    
    // Method specific setup
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }
    }
    
    // Headers
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        throw new Exception("cURL Error: $error");
    }
    
    return ['code' => $httpCode, 'body' => $response];
}

try {
    echo "=== HRS Simple Authentication Test ===\n\n";
    
    // Step 1: Visit main page to get initial cookies
    echo "Step 1: Visiting main page...\n";
    $result = makeRequest($baseUrl, 'GET', null, [], $cookieFile);
    echo "Status: {$result['code']}\n";
    echo "Body length: " . strlen($result['body']) . "\n\n";
    
    // Step 2: Get CSRF token
    echo "Step 2: Getting CSRF token...\n";
    $result = makeRequest("$baseUrl/api/v1/csrf", 'GET', null, [
        'Accept: application/json'
    ], $cookieFile);
    echo "Status: {$result['code']}\n";
    
    if ($result['code'] === 200) {
        $csrfData = json_decode($result['body'], true);
        $csrfToken = $csrfData['token'] ?? null;
        echo "CSRF Token: $csrfToken\n\n";
        
        if ($csrfToken) {
            // Step 3: Try email verification
            echo "Step 3: Email verification...\n";
            $verifyData = json_encode([
                'userEmail' => $email,
                'isLogin' => true
            ]);
            
            $result = makeRequest("$baseUrl/api/v1/users/verifyEmail", 'POST', $verifyData, [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-XSRF-TOKEN: ' . $csrfToken
            ], $cookieFile);
            echo "Status: {$result['code']}\n";
            echo "Response: " . substr($result['body'], 0, 200) . "\n\n";
            
            // Step 4: Try login
            echo "Step 4: Login attempt...\n";
            $loginData = http_build_query([
                'username' => $email,
                'password' => $password
            ]);
            
            $result = makeRequest("$baseUrl/api/v1/users/login", 'POST', $loginData, [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
                'X-XSRF-TOKEN: ' . $csrfToken
            ], $cookieFile);
            echo "Status: {$result['code']}\n";
            echo "Response: " . substr($result['body'], 0, 200) . "\n\n";
            
            if ($result['code'] === 200) {
                echo "SUCCESS! Login worked!\n";
                
                // Test authenticated request
                echo "Step 5: Testing authenticated request...\n";
                $result = makeRequest("$baseUrl/api/v1/users/info", 'GET', null, [
                    'Accept: application/json',
                    'X-XSRF-TOKEN: ' . $csrfToken
                ], $cookieFile);
                echo "Status: {$result['code']}\n";
                echo "Response: " . substr($result['body'], 0, 200) . "\n";
            } else {
                echo "Login failed with status: {$result['code']}\n";
            }
        }
    } else {
        echo "Failed to get CSRF token\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
} finally {
    // Cleanup
    if (file_exists($cookieFile)) {
        unlink($cookieFile);
    }
}
?>
