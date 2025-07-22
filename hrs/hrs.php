<?php
$email = "offece@franzsennhuette.at";
$password = "Fsh2147m!3";
$baseUrl = "https://www.hut-reservation.org";

// Setup: Cookie-Datei zur Session-Speicherung
$cookieFile = tempnam(sys_get_temp_dir(), "hrscookies");

try {
    // 0. First visit the login page to establish session
    echo "0. Besuche Login-Seite für Session-Aufbau...\n";
    $loginPage = file_get_contents_with_cookies("$baseUrl/login", $cookieFile);
    echo "Login page visited (length: " . strlen($loginPage) . ")\n";
    
    // Check if there's a CSRF token in the login page
    if (preg_match('/name="_token".*?value="([^"]+)"/', $loginPage, $matches)) {
        echo "Found _token in login page: " . $matches[1] . "\n";
        $pageToken = $matches[1];
    } else {
        echo "No _token found in login page\n";
        $pageToken = null;
    }
    
    // 1. CSRF Token holen
    echo "1. Hole initial CSRF Token...\n";
    $csrfToken = getCsrfToken($baseUrl, $cookieFile);
    echo "Initial CSRF Token: $csrfToken\n";
    
    // Try direct login without email verification first
    echo "2. Versuche Email-Verification (wie VB.NET)...\n";
    $verifyResult = verifyEmailWithCookie($baseUrl, $email, $csrfToken, $cookieFile);
    echo "Email Verify Result: $verifyResult\n";
    
    // 2b. Fresh CSRF token after email verification (wie VB.NET)
    echo "2b. Hole neuen CSRF Token nach Email-Verification...\n";
    $csrfToken = getCsrfToken($baseUrl, $cookieFile);
    echo "Fresh CSRF Token after verify: $csrfToken\n";
    
    // 3. Login with form data (wie VB.NET)
    echo "3. Login mit Form-Data...\n";
    $loginResult = loginWithFormData($baseUrl, $email, $password, $csrfToken, $cookieFile);
    echo "Login Result: $loginResult\n";
    
    // 4. Nach Login: Neuen CSRF Token holen (wichtig!)
    echo "4. Hole neuen CSRF Token nach Login...\n";
    $csrfToken = getCsrfToken($baseUrl, $cookieFile);
    echo "Neuer CSRF Token: $csrfToken\n";
    
    // 4. Test-Request: Benutzer-Info abrufen
    echo "4. Teste Authentication mit User-Info...\n";
    $userInfo = file_get_contents_with_cookies(
        "$baseUrl/api/v1/users/info",
        $cookieFile,
        ['x-xsrf-token: ' . $csrfToken]
    );
    echo "User Info: $userInfo\n";
    
    // 5. Reservierungen abrufen
    echo "5. Lade Reservierungen...\n";
    $reservationsJson = file_get_contents_with_cookies(
        "$baseUrl/api/v1/manage/reservation/list?hutId=675&dateFrom=23.07.2025&dateTo=23.07.2025&page=0&size=20",
        $cookieFile,
        ['x-xsrf-token: ' . $csrfToken]
    );
    echo "Reservations: $reservationsJson\n";} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
} finally {
    // Cleanup
    if (file_exists($cookieFile)) {
        unlink($cookieFile);
    }
}


// FUNKTIONEN:

function getCsrfToken($baseUrl, $cookieFile) {
    $res = file_get_contents_with_cookies("$baseUrl/api/v1/csrf", $cookieFile);
    $data = json_decode($res, true);
    if (!$data || !isset($data['token'])) {
        throw new Exception("Failed to get CSRF token: $res");
    }
    return $data['token'];
}

function verifyEmailWithCookie($baseUrl, $email, $csrf, $cookieFile) {
    $data = json_encode([
        "userEmail" => $email,
        "isLogin" => true
    ]);
    
    $ch = curl_init("$baseUrl/api/v1/users/verifyEmail");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_HTTPHEADER => [
            'accept: application/json, text/plain, */*',
            'accept-encoding: gzip, deflate, br, zstd',
            'accept-language: de-DE,de;q=0.9',
            'cache-control: no-cache',
            'pragma: no-cache',
            'content-type: application/json',
            'origin: https://www.hut-reservation.org',
            'referer: https://www.hut-reservation.org',
            'sec-ch-ua: "Google Chrome";v="137", "Chromium";v="137", "Not/A)Brand";v="24"',
            'sec-ch-ua-mobile: ?0',
            'sec-ch-ua-platform: "Windows"',
            'sec-fetch-dest: empty',
            'sec-fetch-mode: cors',
            'sec-fetch-site: same-origin',
            'user-agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36',
            'x-xsrf-token: ' . $csrf,
            'cookie: XSRF-TOKEN=' . $csrf
        ],
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_ENCODING => 'gzip, deflate, br'
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    echo "POST verifyEmail - HTTP Code: $httpCode - Response: " . substr($res, 0, 200) . "\n";
    curl_close($ch);
    
    return $res;
}

function loginWithFormData($baseUrl, $email, $password, $csrf, $cookieFile) {
    $formData = "username=" . urlencode($email) . "&password=" . urlencode($password);
    
    $ch = curl_init("$baseUrl/api/v1/users/login");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $formData,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_HTTPHEADER => [
            'accept: application/json, text/plain, */*',
            'accept-encoding: gzip, deflate, br, zstd',
            'accept-language: de-DE,de;q=0.9',
            'cache-control: no-cache',
            'pragma: no-cache',
            'content-type: application/x-www-form-urlencoded',
            'origin: https://www.hut-reservation.org',
            'referer: https://www.hut-reservation.org',
            'sec-ch-ua: "Google Chrome";v="137", "Chromium";v="137", "Not/A)Brand";v="24"',
            'sec-ch-ua-mobile: ?0',
            'sec-ch-ua-platform: "Windows"',
            'sec-fetch-dest: empty',
            'sec-fetch-mode: cors',
            'sec-fetch-site: same-origin',
            'user-agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36',
            'x-xsrf-token: ' . $csrf,
            'cookie: XSRF-TOKEN=' . $csrf
        ],
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_ENCODING => 'gzip, deflate, br'
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    echo "POST login - HTTP Code: $httpCode - Response: " . substr($res, 0, 200) . "
";
    curl_close($ch);
    
    return $res;
}

function verifyEmail($baseUrl, $email, $csrf, $cookieFile) {
    $data = json_encode([
        "userEmail" => $email,
        "isLogin" => true
    ]);
    $result = post_json("$baseUrl/api/v1/users/verifyEmail", $data, $csrf, $cookieFile);
    
    // Check for errors
    $decoded = json_decode($result, true);
    if ($decoded && isset($decoded['statusCode']) && $decoded['statusCode'] !== 200) {
        throw new Exception("Email verification failed: $result");
    }
    
    return $result;
}

function loginDirect($baseUrl, $email, $password, $csrf, $pageToken, $cookieFile) {
    // Try multiple login approaches
    
    // Approach 1: Standard form login with both tokens
    $data1 = http_build_query([
        "username" => $email,
        "password" => $password,
        "_token" => $pageToken ?: $csrf
    ]);
    echo "Trying approach 1: Standard form with _token\n";
    $result1 = post_form("$baseUrl/api/v1/users/login", $data1, $csrf, $cookieFile);
    if ($result1 && !empty($result1)) {
        return $result1;
    }
    
    // Approach 2: Try without CSRF in form data
    $data2 = http_build_query([
        "username" => $email,
        "password" => $password
    ]);
    echo "Trying approach 2: Form without _token\n";
    $result2 = post_form("$baseUrl/api/v1/users/login", $data2, $csrf, $cookieFile);
    if ($result2 && !empty($result2)) {
        return $result2;
    }
    
    // Approach 3: Try JSON format
    $data3 = json_encode([
        "username" => $email,
        "password" => $password
    ]);
    echo "Trying approach 3: JSON format\n";
    $result3 = post_json("$baseUrl/api/v1/users/login", $data3, $csrf, $cookieFile);
    if ($result3 && !empty($result3)) {
        return $result3;
    }
    
    return "All login approaches failed";
}

function login($baseUrl, $email, $password, $csrf, $cookieFile) {
    $data = http_build_query([
        "username" => $email,
        "password" => $password
    ]);
    $result = post_form("$baseUrl/api/v1/users/login", $data, $csrf, $cookieFile);
    
    // Check for login errors
    $decoded = json_decode($result, true);
    if ($decoded && isset($decoded['statusCode']) && $decoded['statusCode'] !== 200) {
        throw new Exception("Login failed: $result");
    }
    
    return $result;
}

function file_get_contents_with_cookies($url, $cookieFile, $extraHeaders = []) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_HTTPHEADER => array_merge([
            'accept: application/json, text/plain, */*',
            'accept-encoding: gzip, deflate, br, zstd',
            'accept-language: de-DE,de;q=0.9',
            'cache-control: no-cache',
            'pragma: no-cache',
            'referer: https://www.hut-reservation.org',
            'sec-ch-ua: "Google Chrome";v="137", "Chromium";v="137", "Not/A)Brand";v="24"',
            'sec-ch-ua-mobile: ?0',
            'sec-ch-ua-platform: "Windows"',
            'sec-fetch-dest: empty',
            'sec-fetch-mode: cors',
            'sec-fetch-site: same-origin',
            'user-agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36',
        ], $extraHeaders),
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_ENCODING => 'gzip, deflate, br'
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    echo "GET $url - HTTP Code: $httpCode\n";
    curl_close($ch);
    return $res;
}

function post_json($url, $json, $csrf, $cookieFile) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json, text/plain, */*',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Origin: https://www.hut-reservation.org',
            'Referer: https://www.hut-reservation.org/login',
            'X-Requested-With: XMLHttpRequest',
            'x-xsrf-token: ' . $csrf,
            'Cookie: XSRF-TOKEN=' . $csrf
        ],
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    echo "POST $url - HTTP Code: $httpCode - Response: " . substr($res, 0, 200) . "\n";
    curl_close($ch);
    return $res;
}

function post_form($url, $formData, $csrf, $cookieFile) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $formData,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json, text/plain, */*',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Origin: https://www.hut-reservation.org',
            'Referer: https://www.hut-reservation.org/login',
            'X-Requested-With: XMLHttpRequest',
            'x-xsrf-token: ' . $csrf,
            'Cookie: XSRF-TOKEN=' . $csrf
        ],
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    echo "POST $url - HTTP Code: $httpCode - Response: " . substr($res, 0, 200) . "\n";
    curl_close($ch);
    return $res;
}
