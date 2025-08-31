<?php
/* ==============================================
   OPTIMIERTE RESERVIERUNGEN API
   ============================================== */

// Error Reporting für Development
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Security Headers
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// CORS für lokale Entwicklung
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    exit(0);
}

// Konfiguration laden
require_once '../../config.php';

// DB Konstanten definieren
define('DB_HOST', $GLOBALS['dbHost']);
define('DB_USER', $GLOBALS['dbUser']);
define('DB_PASS', $GLOBALS['dbPass']);
define('DB_NAME', $GLOBALS['dbName']);

class ReservationsAPI {
    private $pdo;
    private $allowed_methods = ['GET', 'POST', 'PUT', 'DELETE'];
    
    public function __construct() {
        $this->connectDatabase();
    }
    
    private function connectDatabase() {
        try {
            $this->pdo = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            
            if ($this->pdo->connect_error) {
                throw new Exception("Connection failed: " . $this->pdo->connect_error);
            }
            
            $this->pdo->set_charset("utf8mb4");
            
        } catch (Exception $e) {
            $this->sendError('Datenbankverbindung fehlgeschlagen', 500, [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    public function handleRequest() {
        $method = $_SERVER['REQUEST_METHOD'];
        
        if (!in_array($method, $this->allowed_methods)) {
            $this->sendError('Methode nicht erlaubt', 405);
        }
        
        try {
            switch ($method) {
                case 'GET':
                    $this->handleGet();
                    break;
                case 'POST':
                    $this->handlePost();
                    break;
                case 'PUT':
                    $this->handlePut();
                    break;
                case 'DELETE':
                    $this->handleDelete();
                    break;
            }
        } catch (Exception $e) {
            $this->sendError('Server Fehler', 500, [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
    
    private function handleGet() {
        $filters = $this->getFilters();
        $reservations = $this->getReservations($filters);
        $stats = $this->getStats($filters);
        
        $this->sendSuccess([
            'reservations' => $reservations,
            'stats' => $stats,
            'filters' => $filters,
            'timestamp' => date('c')
        ]);
    }
    
    private function handlePost() {
        $data = $this->getRequestData();
        
        // Validierung
        $validation = $this->validateReservationData($data);
        if (!$validation['valid']) {
            $this->sendError('Validierungsfehler', 400, [
                'errors' => $validation['errors']
            ]);
        }
        
        $reservationId = $this->createReservation($data);
        
        $this->sendSuccess([
            'id' => $reservationId,
            'message' => 'Reservierung erfolgreich erstellt'
        ], 201);
    }
    
    private function handlePut() {
        $id = $_GET['id'] ?? null;
        
        if (!$id) {
            $this->sendError('Reservierungs-ID erforderlich', 400);
        }
        
        $data = $this->getRequestData();
        
        // Validierung
        $validation = $this->validateReservationData($data, $id);
        if (!$validation['valid']) {
            $this->sendError('Validierungsfehler', 400, [
                'errors' => $validation['errors']
            ]);
        }
        
        $updated = $this->updateReservation($id, $data);
        
        if (!$updated) {
            $this->sendError('Reservierung nicht gefunden', 404);
        }
        
        $this->sendSuccess([
            'id' => $id,
            'message' => 'Reservierung erfolgreich aktualisiert'
        ]);
    }
    
    private function handleDelete() {
        $id = $_GET['id'] ?? null;
        
        if (!$id) {
            $this->sendError('Reservierungs-ID erforderlich', 400);
        }
        
        $deleted = $this->deleteReservation($id);
        
        if (!$deleted) {
            $this->sendError('Reservierung nicht gefunden', 404);
        }
        
        $this->sendSuccess([
            'id' => $id,
            'message' => 'Reservierung erfolgreich gelöscht'
        ]);
    }
    
    private function getFilters() {
        return [
            'type' => $_GET['type'] ?? 'arrival',
            'date' => $_GET['date'] ?? date('Y-m-d'),
            'search' => $_GET['search'] ?? '',
            'storno' => $_GET['storno'] ?? 'no-storno',
            'status' => $_GET['status'] ?? 'all',
            'limit' => min((int)($_GET['limit'] ?? 100), 1000),
            'offset' => max((int)($_GET['offset'] ?? 0), 0)
        ];
    }
    
    private function getReservations($filters) {
        $sql = "
            SELECT 
                r.id,
                r.nachname,
                r.vorname,
                r.anreise,
                r.abreise,
                r.arrangement,
                r.herkunft,
                r.bemerkung,
                r.storno,
                r.status,
                r.anzahl_dz,
                r.anzahl_betten,
                r.anzahl_lager,
                r.anzahl_sonder,
                r.created_at,
                r.updated_at,
                COALESCE(
                    (SELECT COUNT(*) FROM `AV-ResNamen` rn WHERE rn.res_id = r.id), 
                    (r.anzahl_dz + r.anzahl_betten + r.anzahl_lager + r.anzahl_sonder)
                ) as guest_count,
                COALESCE(
                    (SELECT COUNT(*) FROM `AV-ResNamen` rn WHERE rn.res_id = r.id AND rn.eingecheckt = 1), 
                    0
                ) as checked_in_count
            FROM `AV-Res` r
            WHERE 1=1
        ";
        
        $params = [];
        
        // Datum-Filter
        if ($filters['type'] === 'arrival') {
            $sql .= " AND DATE(r.anreise) = :date";
        } else {
            $sql .= " AND DATE(r.abreise) = :date";
        }
        $params['date'] = $filters['date'];
        
        // Storno-Filter
        if ($filters['storno'] === 'no-storno') {
            $sql .= " AND (r.storno IS NULL OR r.storno = 0)";
        } elseif ($filters['storno'] === 'only-storno') {
            $sql .= " AND r.storno = 1";
        }
        
        // Status-Filter
        if ($filters['status'] !== 'all') {
            $sql .= " AND r.status = :status";
            $params['status'] = $filters['status'];
        }
        
        // Such-Filter
        if (!empty($filters['search'])) {
            $sql .= " AND (
                r.nachname LIKE :search 
                OR r.vorname LIKE :search 
                OR r.herkunft LIKE :search 
                OR r.arrangement LIKE :search
                OR r.bemerkung LIKE :search
            )";
            $params['search'] = '%' . $filters['search'] . '%';
        }
        
        // Sortierung
        $orderField = $filters['type'] === 'arrival' ? 'r.anreise' : 'r.abreise';
        $sql .= " ORDER BY {$orderField} ASC, r.nachname ASC";
        
        // Pagination
        $sql .= " LIMIT :limit OFFSET :offset";
        $params['limit'] = $filters['limit'];
        $params['offset'] = $filters['offset'];
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    }
    
    private function getStats($filters) {
        // Basis-Query für Statistiken
        $sql = "
            SELECT 
                COUNT(*) as total_reservations,
                SUM(CASE WHEN (storno IS NULL OR storno = 0) THEN 1 ELSE 0 END) as active_reservations,
                SUM(CASE WHEN storno = 1 THEN 1 ELSE 0 END) as cancelled_reservations,
                SUM(CASE WHEN status = 'checked-in' THEN 1 ELSE 0 END) as checked_in_reservations,
                SUM(anzahl_dz + anzahl_betten + anzahl_lager + anzahl_sonder) as total_guests,
                SUM(CASE WHEN status = 'checked-in' THEN (anzahl_dz + anzahl_betten + anzahl_lager + anzahl_sonder) ELSE 0 END) as checked_in_guests
            FROM `AV-Res` r
            WHERE 1=1
        ";
        
        $params = [];
        
        // Datum-Filter anwenden
        if ($filters['type'] === 'arrival') {
            $sql .= " AND DATE(r.anreise) = :date";
        } else {
            $sql .= " AND DATE(r.abreise) = :date";
        }
        $params['date'] = $filters['date'];
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetch();
    }
    
    private function createReservation($data) {
        $sql = "
            INSERT INTO `AV-Res` (
                nachname, vorname, anreise, abreise, arrangement, 
                herkunft, bemerkung, anzahl_dz, anzahl_betten, 
                anzahl_lager, anzahl_sonder, status, created_at
            ) VALUES (
                :nachname, :vorname, :anreise, :abreise, :arrangement,
                :herkunft, :bemerkung, :anzahl_dz, :anzahl_betten,
                :anzahl_lager, :anzahl_sonder, :status, NOW()
            )
        ";
        
        $params = [
            'nachname' => $data['nachname'],
            'vorname' => $data['vorname'] ?? '',
            'anreise' => $data['anreise'],
            'abreise' => $data['abreise'],
            'arrangement' => $data['arrangement'] ?? '',
            'herkunft' => $data['herkunft'] ?? '',
            'bemerkung' => $data['bemerkung'] ?? '',
            'anzahl_dz' => (int)($data['anzahl_dz'] ?? 0),
            'anzahl_betten' => (int)($data['anzahl_betten'] ?? 0),
            'anzahl_lager' => (int)($data['anzahl_lager'] ?? 0),
            'anzahl_sonder' => (int)($data['anzahl_sonder'] ?? 0),
            'status' => $data['status'] ?? 'pending'
        ];
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        return $this->pdo->lastInsertId();
    }
    
    private function updateReservation($id, $data) {
        $sql = "
            UPDATE `AV-Res` SET 
                nachname = :nachname,
                vorname = :vorname,
                anreise = :anreise,
                abreise = :abreise,
                arrangement = :arrangement,
                herkunft = :herkunft,
                bemerkung = :bemerkung,
                anzahl_dz = :anzahl_dz,
                anzahl_betten = :anzahl_betten,
                anzahl_lager = :anzahl_lager,
                anzahl_sonder = :anzahl_sonder,
                status = :status,
                updated_at = NOW()
            WHERE id = :id
        ";
        
        $params = [
            'id' => $id,
            'nachname' => $data['nachname'],
            'vorname' => $data['vorname'] ?? '',
            'anreise' => $data['anreise'],
            'abreise' => $data['abreise'],
            'arrangement' => $data['arrangement'] ?? '',
            'herkunft' => $data['herkunft'] ?? '',
            'bemerkung' => $data['bemerkung'] ?? '',
            'anzahl_dz' => (int)($data['anzahl_dz'] ?? 0),
            'anzahl_betten' => (int)($data['anzahl_betten'] ?? 0),
            'anzahl_lager' => (int)($data['anzahl_lager'] ?? 0),
            'anzahl_sonder' => (int)($data['anzahl_sonder'] ?? 0),
            'status' => $data['status'] ?? 'pending'
        ];
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->rowCount() > 0;
    }
    
    private function deleteReservation($id) {
        // Soft Delete
        $sql = "UPDATE `AV-Res` SET storno = 1, updated_at = NOW() WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
        
        return $stmt->rowCount() > 0;
    }
    
    private function validateReservationData($data, $id = null) {
        $errors = [];
        
        // Pflichtfelder
        if (empty($data['nachname'])) {
            $errors['nachname'] = 'Nachname ist erforderlich';
        }
        
        if (empty($data['anreise'])) {
            $errors['anreise'] = 'Anreisedatum ist erforderlich';
        } elseif (!$this->isValidDate($data['anreise'])) {
            $errors['anreise'] = 'Ungültiges Anreisedatum';
        }
        
        if (empty($data['abreise'])) {
            $errors['abreise'] = 'Abreisedatum ist erforderlich';
        } elseif (!$this->isValidDate($data['abreise'])) {
            $errors['abreise'] = 'Ungültiges Abreisedatum';
        }
        
        // Datum-Logik prüfen
        if (!empty($data['anreise']) && !empty($data['abreise'])) {
            $anreise = new DateTime($data['anreise']);
            $abreise = new DateTime($data['abreise']);
            
            if ($abreise <= $anreise) {
                $errors['abreise'] = 'Abreisedatum muss nach Anreisedatum liegen';
            }
        }
        
        // Gästeanzahl prüfen
        $guestCount = (int)($data['anzahl_dz'] ?? 0) + 
                     (int)($data['anzahl_betten'] ?? 0) + 
                     (int)($data['anzahl_lager'] ?? 0) + 
                     (int)($data['anzahl_sonder'] ?? 0);
        
        if ($guestCount <= 0) {
            $errors['guests'] = 'Mindestens ein Gast muss angegeben werden';
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    private function isValidDate($date) {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }
    
    private function getRequestData() {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->sendError('Ungültige JSON-Daten', 400);
        }
        
        return $data ?? [];
    }
    
    private function sendSuccess($data = [], $status = 200) {
        http_response_code($status);
        echo json_encode([
            'success' => true,
            'data' => $data
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
    
    private function sendError($message, $status = 400, $details = []) {
        http_response_code($status);
        echo json_encode([
            'success' => false,
            'error' => $message,
            'details' => $details
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
}

// API ausführen
$api = new ReservationsAPI();
$api->handleRequest();
?>
