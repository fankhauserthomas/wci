<?php
// Zentraler AuthManager für alle Bereiche des Systems
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

class AuthManager {
    private static $APP_PASSWORD = 'er1234tf';

    private static function getPassword(): string {
        if (defined('WCI_AUTH_PASSWORD')) {
            return WCI_AUTH_PASSWORD;
        }

        $envPassword = getenv('WCI_AUTH_PASSWORD');
        if ($envPassword !== false && $envPassword !== '') {
            return $envPassword;
        }

        if (isset($GLOBALS['WCI_AUTH_PASSWORD']) && $GLOBALS['WCI_AUTH_PASSWORD'] !== '') {
            return $GLOBALS['WCI_AUTH_PASSWORD'];
        }

        return self::$APP_PASSWORD;
    }

    public static function isAuthenticated(): bool {
        return !empty($_SESSION['authenticated']);
    }

    public static function authenticate(string $password): bool {
        try {
            if (hash_equals(self::getPassword(), (string) $password)) {
                $_SESSION['authenticated'] = true;
                $_SESSION['auth_time'] = time();
                $_SESSION['user_ip'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                session_regenerate_id(true);

                error_log('WebCheckin Login successful from IP: ' . $_SESSION['user_ip']);
                return true;
            }

            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            error_log('WebCheckin Login failed from IP: ' . $ip);
            return false;
        } catch (Exception $e) {
            error_log('Auth error in authenticate: ' . $e->getMessage());
            return false;
        }
    }

    public static function logout(): void {
        try {
            if (isset($_SESSION['user_ip'])) {
                error_log('WebCheckin Logout from IP: ' . $_SESSION['user_ip']);
            }

            $_SESSION = [];

            if (session_status() === PHP_SESSION_ACTIVE) {
                session_unset();
                session_destroy();
            }
        } catch (Exception $e) {
            error_log('Auth error in logout: ' . $e->getMessage());
        }
    }

    public static function checkSession(): bool {
        try {
            if (self::isAuthenticated()) {
                $sessionAge = time() - (int) ($_SESSION['auth_time'] ?? 0);
                if ($sessionAge > 8 * 3600) {
                    self::logout();
                    return false;
                }

                return true;
            }

            return false;
        } catch (Exception $e) {
            error_log('Auth error in checkSession: ' . $e->getMessage());
            return false;
        }
    }

    public static function requireAuth(): void {
        try {
            if (!self::checkSession()) {
                http_response_code(401);
                header('Content-Type: application/json');
                echo json_encode([
                    'error' => 'Nicht authentifiziert',
                    'message' => 'Bitte melden Sie sich an, um auf diese Funktion zuzugreifen.',
                    'redirect' => 'login.html',
                ]);
                exit;
            }
        } catch (Exception $e) {
            error_log('Auth error in requireAuth: ' . $e->getMessage());
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode([
                'error' => 'Server-Fehler',
                'message' => 'Ein interner Fehler ist aufgetreten.',
            ]);
            exit;
        }
    }

    public static function getSessionInfo(): array {
        try {
            if (self::isAuthenticated()) {
                $loginTime = (int) ($_SESSION['auth_time'] ?? time());
                $sessionAge = time() - $loginTime;

                return [
                    'authenticated' => true,
                    'login_time' => $loginTime,
                    'session_age' => $sessionAge,
                    'expires_in' => max(0, (8 * 3600) - $sessionAge),
                ];
            }

            return ['authenticated' => false];
        } catch (Exception $e) {
            error_log('Auth error in getSessionInfo: ' . $e->getMessage());
            return ['authenticated' => false, 'error' => $e->getMessage()];
        }
    }
}
