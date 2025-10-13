<?php
/**
 * SSE Helper Class for Quota Processing
 * =====================================
 * Sends real-time events during quota processing
 */

class QuotaSSEHelper {
    private $sessionId;
    private $sessionFile;
    private $events = [];
    
    public function __construct($sessionId = null) {
        $this->sessionId = $sessionId ?: uniqid('quota_', true);
        $this->sessionFile = $this->getSessionFile($this->sessionId);
        error_log("SSE Helper: Initializing session {$this->sessionId} with file {$this->sessionFile}");
        $this->initSession();
    }
    
    public function getSessionId() {
        return $this->sessionId;
    }
    
    private function getSessionFile($sessionId) {
        $tmpDir = sys_get_temp_dir();
        return $tmpDir . '/quota_sse_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId) . '.json';
    }
    
    private function initSession() {
        $data = [
            'session_id' => $this->sessionId,
            'started' => time(),
            'events' => [],
            'completed' => false
        ];
        file_put_contents($this->sessionFile, json_encode($data));
    }
    
    public function sendEvent($type, $data) {
        if (!$this->sessionId) return;
        
        $event = [
            'type' => $type,
            'data' => $data,
            'timestamp' => time(),
            'sent' => false
        ];
        
        // Read current session data
        $sessionData = [];
        if (file_exists($this->sessionFile)) {
            $content = file_get_contents($this->sessionFile);
            $sessionData = json_decode($content, true) ?: [];
        }
        
        if (!isset($sessionData['events'])) {
            $sessionData['events'] = [];
        }
        
        $sessionData['events'][] = $event;
        
        // Write back to session file with immediate flush
        $writeResult = file_put_contents($this->sessionFile, json_encode($sessionData), LOCK_EX);
        if ($writeResult === false) {
            error_log("SSE Helper: Failed to write event to session file: " . $this->sessionFile);
        } else {
            // Force file system flush
            if (function_exists('fsync')) {
                $handle = fopen($this->sessionFile, 'r');
                if ($handle) {
                    fsync($handle);
                    fclose($handle);
                }
            }
            error_log("SSE Helper: Event written successfully - Type: $type, Data: " . json_encode($data));
        }
    }
    
    public function quotaProcessingStarted($totalQuotas, $segmentInfo = null) {
        $this->sendEvent('quota_processing_started', [
            'total_quotas' => $totalQuotas,
            'segment_info' => $segmentInfo,
            'message' => "Starte Verarbeitung von {$totalQuotas} Quotas"
        ]);
    }
    
    public function quotaCreated($date, $quantities, $result = null) {
        $this->sendEvent('quota_created', [
            'date' => $date,
            'quantities' => $quantities,
            'result' => $result,
            'status' => 'success',
            'message' => "Quota für {$date} erstellt"
        ]);
    }
    
    public function quotaDeleted($date, $quotaId = null) {
        $this->sendEvent('quota_deleted', [
            'date' => $date,
            'quota_id' => $quotaId,
            'status' => 'deleted',
            'message' => "Quota für {$date} gelöscht"
        ]);
    }
    
    public function quotaError($date, $error) {
        $this->sendEvent('quota_error', [
            'date' => $date,
            'error' => $error,
            'status' => 'error',
            'message' => "Fehler bei {$date}: {$error}"
        ]);
    }
    
    public function segmentStarted($segmentInfo) {
        $this->sendEvent('segment_started', [
            'segment' => $segmentInfo,
            'message' => "Segment wird verarbeitet: {$segmentInfo['dateFrom']} - {$segmentInfo['dateTo']}"
        ]);
    }
    
    public function segmentCompleted($segmentInfo, $results) {
        $this->sendEvent('segment_completed', [
            'segment' => $segmentInfo,
            'results' => $results,
            'message' => "Segment abgeschlossen: {$results['created']} erstellt, {$results['deleted']} gelöscht"
        ]);
    }
    
    public function progressUpdate($current, $total, $message = null) {
        $percentage = $total > 0 ? round(($current / $total) * 100, 1) : 0;
        $this->sendEvent('progress_update', [
            'current' => $current,
            'total' => $total,
            'percentage' => $percentage,
            'message' => $message ?: "Fortschritt: {$current}/{$total} ({$percentage}%)"
        ]);
    }
    
    public function processingCompleted($summary) {
        $this->sendEvent('processing_completed', [
            'summary' => $summary,
            'message' => 'Quota-Verarbeitung abgeschlossen'
        ]);
        
        // Mark session as completed
        if (file_exists($this->sessionFile)) {
            $content = file_get_contents($this->sessionFile);
            $sessionData = json_decode($content, true) ?: [];
            $sessionData['completed'] = true;
            $sessionData['completed_at'] = time();
            file_put_contents($this->sessionFile, json_encode($sessionData));
        }
    }
    
    public function __destruct() {
        // Auto-complete session if not already completed
        if (file_exists($this->sessionFile)) {
            $content = file_get_contents($this->sessionFile);
            $sessionData = json_decode($content, true) ?: [];
            if (!isset($sessionData['completed']) || !$sessionData['completed']) {
                $sessionData['completed'] = true;
                $sessionData['completed_at'] = time();
                file_put_contents($this->sessionFile, json_encode($sessionData));
            }
        }
    }
}
?>