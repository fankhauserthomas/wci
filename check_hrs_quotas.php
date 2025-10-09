<?php
/**
 * Prüfe HRS Quotas für 12.02.2026
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/hrs/hrs_login.php';

echo "=== HRS QUOTAS FÜR 12.02.2026 ===\n\n";

try {
    $hrsLogin = new HRSLogin();
    
    // API Call
    $dateFrom = '12.02.2025';
    $dateTo = '12.02.2027';
    $hutId = 675;
    
    $url = "https://www.hut-reservation.org/api/v1/manage/hutQuota?hutId={$hutId}&page=0&size=100&sortList=BeginDate&sortOrder=DESC&open=true&dateFrom={$dateFrom}&dateTo={$dateTo}";
    
    $cookies = $hrsLogin->getCookies();
    $cookie_parts = [];
    foreach ($cookies as $name => $value) {
        $cookie_parts[] = "$name=$value";
    }
    $cookie_header = implode('; ', $cookie_parts);
    
    $headers = [
        'Accept: application/json',
        'X-XSRF-TOKEN: ' . $hrsLogin->getCsrfToken(),
        'Cookie: ' . $cookie_header
    ];
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code === 200) {
        $data = json_decode($response, true);
        
        if (isset($data['content'])) {
            echo "Gefunden: " . count($data['content']) . " Quotas\n\n";
            
            foreach ($data['content'] as $quota) {
                $dateFrom = $quota['dateFrom'] ?? '';
                $dateTo = $quota['dateTo'] ?? '';
                
                // Filtere nur 12.02.2026
                if (strpos($dateFrom, '12.02.2026') !== false || strpos($dateFrom, '2026-02-12') !== false) {
                    echo "📅 Quota ID: {$quota['id']}\n";
                    echo "   Title: {$quota['title']}\n";
                    echo "   Von: {$dateFrom}\n";
                    echo "   Bis: {$dateTo}\n";
                    echo "   Mode: {$quota['reservationMode']}\n";
                    
                    if (isset($quota['hutBedCategoryDTOs'])) {
                        echo "   Kategorien:\n";
                        $total = 0;
                        foreach ($quota['hutBedCategoryDTOs'] as $cat) {
                            $catName = match($cat['categoryId']) {
                                1958 => 'Lager (ML)',
                                2293 => 'Betten (MBZ)',
                                2381 => 'DZ (2BZ)',
                                6106 => 'Sonder (SK)',
                                default => 'Unknown (' . $cat['categoryId'] . ')'
                            };
                            echo "     {$catName}: {$cat['totalBeds']} Plätze\n";
                            $total += $cat['totalBeds'];
                        }
                        echo "   TOTAL: $total Plätze\n";
                    }
                    echo "\n";
                }
            }
        } else {
            echo "Keine Quotas gefunden\n";
        }
    } else {
        echo "❌ HTTP Fehler: $http_code\n";
        echo $response . "\n";
    }
    
} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
}

?>
