<?php
/**
 * HRS Session Manager - Singleton für persistente Sessions
 */

class HRSSessionManager {
    private static $instance = null;
    private $hrsLogin = null;
    private $isLoggedIn = false;
    private $lastLoginTime = 0;
    private $sessionTimeout = 3600; // 1 Stunde
    
    private function __construct() {
        // Private constructor für Singleton
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getHRSLogin() {
        // Prüfe ob Login noch gültig ist
        if (!$this->isLoggedIn || (time() - $this->lastLoginTime) > $this->sessionTimeout) {
            $this->refreshLogin();
        }
        
        return $this->hrsLogin;
    }
    
    private function refreshLogin() {
        error_log("HRS_SESSION: Refreshing login...");
        
        if ($this->hrsLogin === null) {
            require_once __DIR__ . '/hrs_login.php';
            $this->hrsLogin = new HRSLogin();
        }
        
        if ($this->hrsLogin->login()) {
            $this->isLoggedIn = true;
            $this->lastLoginTime = time();
            error_log("HRS_SESSION: Login successful");
        } else {
            $this->isLoggedIn = false;
            error_log("HRS_SESSION: Login failed");
            throw new Exception("HRS Login fehlgeschlagen");
        }
    }
    
    public function forceRelogin() {
        $this->isLoggedIn = false;
        $this->refreshLogin();
    }
}
?>
