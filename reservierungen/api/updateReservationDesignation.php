<?php
// updateReservationDesignation.php - Backend für das Aktualisieren der Bezeichnung in AV_ResDet

// Disable error output for clean JSON response
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config.php';

try {
    // Check database connection
    if (!isset($mysqli) || $mysqli->connect_error) {
        throw new Exception('Database connection failed');
    }

    // Input lesen
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        throw new Exception('Keine gültigen JSON-Daten erhalten');
    }
    
    $detailId = (int)($data['detailId'] ?? 0);
    $newDesignation = trim($data['designation'] ?? '');
    $updateAll = (bool)($data['updateAll'] ?? false);
    
    if ($detailId <= 0) {
        throw new Exception('Ungültige Detail-ID');
    }

    // Prüfe ob Detail-Datensatz existiert und hole resid
    $stmt = $mysqli->prepare("SELECT ID, resid, bez FROM AV_ResDet WHERE ID = ?");
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $mysqli->error);
    }
    
    $stmt->bind_param('i', $detailId);
    $stmt->execute();
    $result = $stmt->get_result();
    $detail = $result->fetch_assoc();
    $stmt->close();
    
    if (!$detail) {
        throw new Exception('Detail-Datensatz nicht gefunden');
    }

    $resId = $detail['resid'];
    $oldDesignation = $detail['bez'];

    // Transaction starten für sicheres Update
    $mysqli->begin_transaction();

    try {
        $updatedCount = 0;
        
        if ($updateAll) {
            // Alle Detail-Datensätze mit gleicher resid aktualisieren
            $stmt = $mysqli->prepare("UPDATE AV_ResDet SET bez = ? WHERE resid = ?");
            if (!$stmt) {
                throw new Exception('Prepare failed for update all: ' . $mysqli->error);
            }
            $stmt->bind_param('si', $newDesignation, $resId);
            
            if (!$stmt->execute()) {
                throw new Exception('Fehler beim Aktualisieren aller Bezeichnungen: ' . $stmt->error);
            }
            
            $updatedCount = $stmt->affected_rows;
            $stmt->close();
            
        } else {
            // Nur den spezifischen Detail-Datensatz aktualisieren
            $stmt = $mysqli->prepare("UPDATE AV_ResDet SET bez = ? WHERE ID = ?");
            if (!$stmt) {
                throw new Exception('Prepare failed for single update: ' . $mysqli->error);
            }
            $stmt->bind_param('si', $newDesignation, $detailId);
            
            if (!$stmt->execute()) {
                throw new Exception('Fehler beim Aktualisieren der Bezeichnung: ' . $stmt->error);
            }
            
            $updatedCount = $stmt->affected_rows;
            $stmt->close();
        }

        if ($updatedCount === 0) {
            throw new Exception('Keine Datensätze wurden aktualisiert');
        }

        // Transaction committen
        $mysqli->commit();
        
        // Auto-sync auslösen falls verfügbar
        if (function_exists('triggerAutoSync')) {
            triggerAutoSync('update_designation');
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Bezeichnung erfolgreich aktualisiert',
            'updatedCount' => $updatedCount,
            'detailId' => $detailId,
            'resId' => $resId,
            'oldDesignation' => $oldDesignation,
            'newDesignation' => $newDesignation,
            'updateAll' => $updateAll
        ]);

    } catch (Exception $e) {
        $mysqli->rollback();
        throw $e;
    }

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'debug_info' => [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]
    ]);
} catch (Error $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Fatal error: ' . $e->getMessage(),
        'debug_info' => [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]
    ]);
}
?>