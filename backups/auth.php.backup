<?php
// auth.php - Vereinfachte Version für Debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Session sicher starten
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

class AuthManager {
    // WICHTIG: Ändern Sie dieses Passwort zu einem sicheren Wert!
    private static $APP_PASSWORD = 'er1234tf';
    
    public static function isAuthenticated() {
        return isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;
    }
    
    public static function authenticate($password) {
        try {
            if ($password === self::$APP_PASSWORD) {
                $_SESSION['authenticated'] = true;
                $_SESSION['auth_time'] = time();
                $_SESSION['user_ip'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                
                // Logging für Sicherheit
                error_log("WebCheckin Login successful from IP: " . $_SESSION['user_ip']);
                return true;
            } else {
                // Logging für failed attempts
                $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                error_log("WebCheckin Login failed from IP: " . $ip);
                return false;
            }
        } catch (Exception $e) {
            error_log("Auth error in authenticate: " . $e->getMessage());
            return false;
        }
    }
    
    public static function logout() {
        try {
            if (isset($_SESSION['user_ip'])) {
                error_log("WebCheckin Logout from IP: " . $_SESSION['user_ip']);
            }
            session_destroy();
        } catch (Exception $e) {
            error_log("Auth error in logout: " . $e->getMessage());
        }
    }
    
    public static function checkSession() {
        try {
            // Session läuft nach 8 Stunden ab
            if (self::isAuthenticated()) {
                $sessionAge = time() - ($_SESSION['auth_time'] ?? 0);
                if ($sessionAge > (8 * 3600)) {
                    self::logout();
                    return false;
                }
                return true;
            }
            return false;
        } catch (Exception $e) {
            error_log("Auth error in checkSession: " . $e->getMessage());
            return false;
        }
    }
    
    public static function requireAuth() {
        try {
            if (!self::checkSession()) {
                http_response_code(401);
                header('Content-Type: application/json');
                echo json_encode([
                    'error' => 'Nicht authentifiziert',
                    'message' => 'Bitte melden Sie sich an, um auf diese Funktion zuzugreifen.',
                    'redirect' => 'login.html'
                ]);
                exit;
            }
        } catch (Exception $e) {
            error_log("Auth error in requireAuth: " . $e->getMessage());
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode([
                'error' => 'Server-Fehler',
                'message' => 'Ein interner Fehler ist aufgetreten.'
            ]);
            exit;
        }
    }
    
    public static function getSessionInfo() {
        try {
            if (self::isAuthenticated()) {
                return [
                    'authenticated' => true,
                    'login_time' => $_SESSION['auth_time'],
                    'session_age' => time() - $_SESSION['auth_time'],
                    'expires_in' => (8 * 3600) - (time() - $_SESSION['auth_time'])
                ];
            }
            return ['authenticated' => false];
        } catch (Exception $e) {
            error_log("Auth error in getSessionInfo: " . $e->getMessage());
            return ['authenticated' => false, 'error' => $e->getMessage()];
        }
    }
}
?>
