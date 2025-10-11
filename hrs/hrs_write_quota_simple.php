<?php
/**
 * HRS Simple Quota Writer
 * ========================
 * 
 * Schreibt berechnete Quotas direkt ins HRS (Akkumulierte Werte)
 * Handles multi-day quota splitting automatically
 * 
 * FORMEL: Q_write = assigned_guests + Q_berechnet
 * 
 * INPUT:
 * {
 *   "quotas": [
 *     {
 *       "date": "2026-02-12",
 *       "quota_lager": 80,    // = assigned_guests_lager + calculated_lager
 *       "quota_betten": 0,
 *       "quota_dz": 0,
 *       "quota_sonder": 0,
 *       "targetCapacity": 92
 *     }
 *   ],
 *   "operation": "update"
 * }
 */

// Start output buffering EARLY
ob_start();

// Disable all error output to stdout
ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/hrs_login.php';

class SimpleQuotaWriter {
    private $mysqli;
    private $hrsLogin;
    private $hutId = 675;
    
    private $categoryMap = [
        'lager'  => 1958,
        'betten' => 2293,
        'dz'     => 2381,
        'sonder' => 6106
    ];
    
    public function __construct($mysqli, HRSLogin $hrsLogin) {
        $this->mysqli = $mysqli;
        $this->hrsLogin = $hrsLogin;
    }
    
    public function updateQuotas($quotas) {
        $this->mysqli->begin_transaction();
        
        try {
            $createdQuotas = [];
            $deletedQuotas = [];
            
            foreach ($quotas as $quota) {
                $date = $quota['date'];
                
                // 1. Lösche alte Quotas
                $deleted = $this->deleteQuotasForDate($date);
                $deletedQuotas = array_merge($deletedQuotas, $deleted);
                
                // 2. Erstelle neue Quotas (nur wenn > 0)
                if ($quota['quota_lager'] > 0) {
                    $id = $this->createQuota($date, 'lager', $quota['quota_lager']);
                    if ($id) $createdQuotas[] = $id;
                }
                
                if ($quota['quota_betten'] > 0) {
                    $id = $this->createQuota($date, 'betten', $quota['quota_betten']);
                    if ($id) $createdQuotas[] = $id;
                }
                
                if ($quota['quota_dz'] > 0) {
                    $id = $this->createQuota($date, 'dz', $quota['quota_dz']);
                    if ($id) $createdQuotas[] = $id;
                }
                
                if ($quota['quota_sonder'] > 0) {
                    $id = $this->createQuota($date, 'sonder', $quota['quota_sonder']);
                    if ($id) $createdQuotas[] = $id;
                }
            }
            
            $this->mysqli->commit();
            
            return [
                'success' => true,
                'createdQuotas' => $createdQuotas,
                'deletedQuotas' => $deletedQuotas,
                'message' => count($quotas) . ' Quotas erfolgreich gespeichert'
            ];
            
        } catch (Exception $e) {
            $this->mysqli->rollback();
            throw $e;
        }
    }
    
    private function deleteQuotasForDate($date) {
        $stmt = $this->mysqli->prepare("
            SELECT id FROM `AV-Res` 
            WHERE `anreise` = ? 
            AND `abreise` = DATE_ADD(?, INTERVAL 1 DAY)
            AND `av_id` IS NULL
        ");
        
        $stmt->bind_param('ss', $date, $date);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $deleted = [];
        $deleter = new HRSQuotaDeleter($this->mysqli, $this->hrsLogin);
        
        while ($row = $result->fetch_assoc()) {
            if ($deleter->deleteQuota($row['id'])) {
                $deleted[] = $row['id'];
            }
        }
        
        return $deleted;
    }
    
    private function createQuota($date, $category, $quantity) {
        $categoryId = $this->categoryMap[$category];
        $abreise = date('Y-m-d', strtotime($date . ' +1 day'));
        
        $url = 'https://interface.hrs-destination.com/api/quota/create';
        
        $body = [
            'hut_id' => $this->hutId,
            'arrival' => $date,
            'departure' => $abreise,
            'category_id' => $categoryId,
            'quantity' => $quantity,
            'comment' => 'Timeline Quota (Auto)'
        ];
        
        $response = $this->hrsLogin->makeAuthenticatedRequest($url, 'POST', $body);
        
        if (!$response || !$response['success']) {
            throw new Exception("HRS API Error: " . ($response['error'] ?? 'Unknown'));
        }
        
        return $response['quota_id'] ?? null;
    }
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['quotas'])) {
        throw new Exception('Invalid input');
    }
    
    $hrsLogin = new HRSLogin($mysqli);
    $writer = new SimpleQuotaWriter($mysqli, $hrsLogin);
    $result = $writer->updateQuotas($input['quotas']);
    
    ob_end_clean();
    echo json_encode($result, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
