<?php
/**
 * HRS Quota Data Analyzer - Zeigt echte Struktur der HRS API Response
 */

class HRSAnalyzer {
    private $baseUrl = 'https://www.hut-reservation.org';
    private $defaultHeaders;
    private $csrfToken;
    private $cookies = array();
    private $username = 'office@franzsennhuette.at';
    private $password = 'Fsh2147m!3';
    
    public function __construct() {
        $this->defaultHeaders = array(
            'Accept: application/json, text/plain, */*',
            'Accept-Encoding: gzip, deflate, br, zstd',
            'Accept-Language: de-DE,de;q=0.9',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
            'Referer: https://www.hut-reservation.org',
            'Sec-Ch-Ua: "Google Chrome";v="137", "Chromium";v="137", "Not/A)Brand";v="24"',
            'Sec-Ch-Ua-Mobile: ?0',
            'Sec-Ch-Ua-Platform: "Windows"',
            'Sec-Fetch-Dest: empty',
            'Sec-Fetch-Mode: cors',
            'Sec-Fetch-Site: same-origin',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36'
        );
    }
    
    private function makeRequest($url, $method = 'GET', $data = null, $customHeaders = array()) {
        $fullUrl = $this->baseUrl . $url;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $fullUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_NOBODY, false);
        
        $headers = $this->defaultHeaders;
        if (!empty($customHeaders)) {
            $headers = array_merge($headers, $customHeaders);
        }
        
        if (!empty($this->cookies)) {
            $cookieString = '';
            foreach ($this->cookies as $name => $value) {
                $cookieString .= "$name=$value; ";
            }
            $cookieHeader = 'Cookie: ' . rtrim($cookieString, '; ');
            $headers[] = $cookieHeader;
        }
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        switch (strtoupper($method)) {
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                if ($data) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                }
                break;
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        
        if (curl_error($ch)) {
            return false;
        }
        
        curl_close($ch);
        
        $headerString = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        
        $this->extractCookies($headerString);
        
        return array(
            'status' => $httpCode,
            'body' => $body
        );
    }
    
    private function extractCookies($headers) {
        $lines = explode("\n", $headers);
        foreach ($lines as $line) {
            if (stripos($line, 'Set-Cookie:') === 0) {
                $cookie = trim(substr($line, 11));
                $parts = explode(';', $cookie);
                $nameValue = explode('=', $parts[0], 2);
                if (count($nameValue) == 2) {
                    $this->cookies[trim($nameValue[0])] = trim($nameValue[1]);
                }
            }
        }
    }
    
    public function loginAndAnalyze() {
        echo "<h3>🔧 HRS Quota Data Structure Analyzer</h3>\n";
        
        // 1. Login
        echo "<p>📡 Verbinde mit HRS...</p>\n";
        
        $response = $this->makeRequest('/login');
        if (!$response || $response['status'] != 200) {
            echo "<p>❌ Login-Seite Fehler</p>\n";
            return false;
        }
        
        $csrfResponse = $this->makeRequest('/api/v1/csrf');
        if (!$csrfResponse || $csrfResponse['status'] != 200) {
            echo "<p>❌ CSRF Fehler</p>\n";
            return false;
        }
        
        $csrfData = json_decode($csrfResponse['body'], true);
        $this->csrfToken = $csrfData['token'];
        
        $cookieCsrfToken = isset($this->cookies['XSRF-TOKEN']) ? $this->cookies['XSRF-TOKEN'] : $this->csrfToken;
        
        $verifyData = json_encode(array(
            'userEmail' => $this->username,
            'isLogin' => true
        ));
        
        $verifyHeaders = array(
            'Content-Type: application/json',
            'Origin: https://www.hut-reservation.org',
            'X-XSRF-TOKEN: ' . $cookieCsrfToken
        );
        
        $this->makeRequest('/api/v1/users/verifyEmail', 'POST', $verifyData, $verifyHeaders);
        
        $updatedCsrfToken = isset($this->cookies['XSRF-TOKEN']) ? $this->cookies['XSRF-TOKEN'] : $cookieCsrfToken;
        
        $loginData = 'username=' . urlencode($this->username) . '&password=' . urlencode($this->password);
        
        $loginHeaders = array(
            'Content-Type: application/x-www-form-urlencoded',
            'Origin: https://www.hut-reservation.org',
            'X-XSRF-TOKEN: ' . $updatedCsrfToken
        );
        
        $loginResponse = $this->makeRequest('/api/v1/users/login', 'POST', $loginData, $loginHeaders);
        
        if (!$loginResponse || $loginResponse['status'] != 200) {
            echo "<p>❌ Login fehlgeschlagen</p>\n";
            return false;
        }
        
        if (isset($this->cookies['XSRF-TOKEN'])) {
            $this->csrfToken = $this->cookies['XSRF-TOKEN'];
        }
        
        echo "<p>✅ Bei HRS eingeloggt!</p>\n";
        
        // 2. Quota Data abrufen
        echo "<p>📊 Lade Quota-Daten für 29.08.2025 - 31.08.2025...</p>\n";
        
        $params = array(
            'hutId' => 675,
            'page' => 0,
            'size' => 100,
            'sortList' => 'BeginDate',
            'sortOrder' => 'DESC',
            'open' => 'true',
            'dateFrom' => '29.08.2025',
            'dateTo' => '31.08.2025'
        );
        
        $url = '/api/v1/manage/hutQuota?' . http_build_query($params);
        
        $headers = array(
            'Origin: https://www.hut-reservation.org',
            'Referer: https://www.hut-reservation.org/hut/manage-hut/675',
            'X-XSRF-TOKEN: ' . $this->csrfToken
        );
        
        $response = $this->makeRequest($url, 'GET', null, $headers);
        
        if (!$response || $response['status'] != 200) {
            echo "<p>❌ Quota-Abruf fehlgeschlagen</p>\n";
            return false;
        }
        
        echo "<p>✅ Quota-Daten erhalten: " . strlen($response['body']) . " bytes</p>\n";
        
        // 3. JSON analysieren
        $quotaData = json_decode($response['body'], true);
        
        echo "<h4>📋 JSON Struktur Analysis:</h4>\n";
        echo "<pre>" . json_encode($quotaData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>\n";
        
        if (isset($quotaData['_embedded']['bedCapacityChangeResponseDTOList'])) {
            $quotaChanges = $quotaData['_embedded']['bedCapacityChangeResponseDTOList'];
            echo "<h4>🔍 Gefundene Quota Changes: " . count($quotaChanges) . "</h4>\n";
            
            foreach ($quotaChanges as $i => $change) {
                echo "<h5>Change #" . ($i + 1) . ":</h5>\n";
                echo "<pre>" . json_encode($change, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>\n";
            }
        }
    }
}

$analyzer = new HRSAnalyzer();
$analyzer->loginAndAnalyze();
?>
