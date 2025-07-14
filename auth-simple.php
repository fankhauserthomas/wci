<?php
// auth.php - Einfache Authentifizierung (PHP 7.0 kompatibel)

// Session sicher starten
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

class AuthManager {
    private static $APP_PASSWORD = 'er1234tf';
    
    public static function isAuthenticated() {
        return isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;
    }
    
    public static function authenticate($password) {
        if ($password === self::$APP_PASSWORD) {
            $_SESSION['authenticated'] = true;
            $_SESSION['auth_time'] = time();
            $_SESSION['user_ip'] = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
            return true;
        }
        return false;
    }
    
    public static function logout() {
        session_destroy();
    }
    
    public static function checkSession() {
        if (self::isAuthenticated()) {
            $sessionAge = time() - (isset($_SESSION['auth_time']) ? $_SESSION['auth_time'] : 0);
            if ($sessionAge > (8 * 3600)) { // 8 Stunden
                self::logout();
                return false;
            }
            return true;
        }
        return false;
    }
    
    public static function requireAuth() {
        if (!self::checkSession()) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(array(
                'error' => 'Nicht authentifiziert',
                'message' => 'Bitte melden Sie sich an.',
                'redirect' => 'login.html'
            ));
            exit;
        }
    }
}
?>
