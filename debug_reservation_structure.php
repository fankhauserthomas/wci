<?php
/**
 * Debug script to examine reservation structure from HRS API
 */
require_once 'config.php';

class HRSDebugger {
    private $baseUrl = 'https://www.hut-reservation.org';
    private $cookies = array();
    private $csrfToken;
    private $username;
    private $password;
    
    public function __construct() {
        // Load credentials from config if available
        $this->username = defined('HRS_USERNAME') ? HRS_USERNAME : 'office@franzsennhuette.at';
        $this->password = defined('HRS_PASSWORD') ? HRS_PASSWORD : 'Fsh2147m!3';
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
        
        $headers = array(
            'Accept: application/json, text/plain, */*',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        );
        
        if (!empty($customHeaders)) {
            $headers = array_merge($headers, $customHeaders);
        }
        
        if (!empty($this->cookies)) {
            $cookieString = '';
            foreach ($this->cookies as $name => $value) {
                $cookieString .= "$name=$value; ";
            }
            $headers[] = 'Cookie: ' . rtrim($cookieString, '; ');
        }
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        if (strtoupper($method) == 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            }
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        
        $headerString = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        
        // Extract cookies
        $lines = explode("\n", $headerString);
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
        
        return array('status' => $httpCode, 'body' => $body);
    }
    
    public function quickLogin() {
        echo "Logging in...\n";
        
        // Quick login process
        $response = $this->makeRequest('/login');
        $csrfResponse = $this->makeRequest('/api/v1/csrf');
        $csrfData = json_decode($csrfResponse['body'], true);
        $this->csrfToken = $csrfData['token'];
        
        $cookieCsrfToken = isset($this->cookies['XSRF-TOKEN']) ? $this->cookies['XSRF-TOKEN'] : $this->csrfToken;
        
        $verifyData = json_encode(array('userEmail' => $this->username, 'isLogin' => true));
        $verifyHeaders = array('Content-Type: application/json', 'Origin: https://www.hut-reservation.org', 'X-XSRF-TOKEN: ' . $cookieCsrfToken);
        $this->makeRequest('/api/v1/users/verifyEmail', 'POST', $verifyData, $verifyHeaders);
        
        $updatedCsrfToken = isset($this->cookies['XSRF-TOKEN']) ? $this->cookies['XSRF-TOKEN'] : $cookieCsrfToken;
        $loginData = 'username=' . urlencode($this->username) . '&password=' . urlencode($this->password);
        $loginHeaders = array('Content-Type: application/x-www-form-urlencoded', 'Origin: https://www.hut-reservation.org', 'X-XSRF-TOKEN: ' . $updatedCsrfToken);
        $this->makeRequest('/api/v1/users/login', 'POST', $loginData, $loginHeaders);
        
        if (isset($this->cookies['XSRF-TOKEN'])) {
            $this->csrfToken = $this->cookies['XSRF-TOKEN'];
        }
        
        echo "Login completed\n";
    }
    
    public function getSampleReservation() {
        $this->quickLogin();
        
        $params = array(
            'hutId' => defined('HUT_ID') ? HUT_ID : 675, 
            'sortList' => 'ArrivalDate', 
            'sortOrder' => 'ASC', 
            'dateFrom' => '20.08.2025', 
            'dateTo' => '27.08.2025', 
            'page' => 0, 
            'size' => 3
        );
        $url = '/api/v1/manage/reservation/list?' . http_build_query($params);
        $headers = array('Origin: https://www.hut-reservation.org', 'X-XSRF-TOKEN: ' . $this->csrfToken);
        $response = $this->makeRequest($url, 'GET', null, $headers);
        
        $data = json_decode($response['body'], true);
        if ($data && isset($data['_embedded']['reservationsDataModelDTOList'])) {
            return $data['_embedded']['reservationsDataModelDTOList'];
        }
        return null;
    }
}

// Execute
$debugger = new HRSDebugger();
$samples = $debugger->getSampleReservation();

if ($samples) {
    echo "\n=== RESERVATION DATA STRUCTURE ANALYSIS ===\n";
    
    for ($i = 0; $i < min(2, count($samples)); $i++) {
        echo "\n--- Sample " . ($i + 1) . " ---\n";
        echo "Full structure:\n";
        echo json_encode($samples[$i], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        echo "\n";
        
        // Analyze structure for reservationNumber
        echo "\n--- Analysis for reservationNumber ---\n";
        
        $reservation = $samples[$i];
        
        echo "Top level keys: " . implode(', ', array_keys($reservation)) . "\n";
        
        if (isset($reservation['header'])) {
            echo "Header keys: " . implode(', ', array_keys($reservation['header'])) . "\n";
        }
        
        if (isset($reservation['body'])) {
            echo "Body keys: " . implode(', ', array_keys($reservation['body'])) . "\n";
            
            if (isset($reservation['body']['leftList'])) {
                echo "Body leftList structure:\n";
                foreach ($reservation['body']['leftList'] as $idx => $item) {
                    echo "  Item $idx keys: " . implode(', ', array_keys($item)) . "\n";
                }
            }
            
            if (isset($reservation['body']['rightList'])) {
                echo "Body rightList structure:\n";
                foreach ($reservation['body']['rightList'] as $idx => $item) {
                    echo "  Item $idx keys: " . implode(', ', array_keys($item)) . "\n";
                }
            }
        }
        
        echo "\n";
    }
} else {
    echo "Could not get sample reservations\n";
}
?>
