<?php
// sync-database.php - Database synchronization from AV-Res to HP database

// Error reporting unterdrücken für saubere JSON-Ausgabe
error_reporting(0);
ini_set('display_errors', 0);

try {
    // Erst alle Includes OHNE Header
    require_once 'auth-simple.php';
    require_once 'config.php';
    require_once 'hp-db-config.php';

    // Authentifizierung prüfen
    if (!AuthManager::checkSession()) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Nicht authentifiziert']);
        exit;
    }
    
    // JSON Header setzen NACH Session-Checks
    header('Content-Type: application/json');
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Setup-Fehler: ' . $e->getMessage()]);
    exit;
}

function syncDatabases() {
    global $mysqli;
    
    if (!isset($mysqli)) {
        return ['success' => false, 'error' => 'Datenbankverbindung nicht verfügbar'];
    }
    
    if (!($mysqli instanceof mysqli)) {
        return ['success' => false, 'error' => 'Ungültige Datenbankverbindung'];
    }
    
    try {
        $hpConn = getHpDbConnection();
        
        if (!$mysqli || !$hpConn) {
            return ['success' => false, 'error' => 'Datenbankverbindung fehlgeschlagen'];
        }
        
        $totalSynced = 0;
        
        // Zeitraum: 7 Tage rückwärts, 2 Tage vorwärts
        $von = date('Y-m-d', strtotime('-7 days'));
        $bis = date('Y-m-d', strtotime('+2 days'));
        
        // Test database connections
        if (!$mysqli->ping()) {
            return ['success' => false, 'error' => 'MySQL Verbindung verloren'];
        }
        
        if (!$hpConn->ping()) {
            return ['success' => false, 'error' => 'HP-Datenbank Verbindung verloren'];
        }
        
        // MySQL Query (AV-Res) - Vollständiger Sync
        $sql = "
            SELECT r.*, a.kbez AS arrkbez 
            FROM `AV-Res` AS r 
            LEFT JOIN `arr` AS a ON r.arr = a.id 
            WHERE r.storno = 0 
            AND r.abreise > ? 
            AND r.anreise <= ?
        ";
        
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            return ['success' => false, 'error' => 'MySQL Prepare Error: ' . $mysqli->error];
        }
        
        $stmt->bind_param('ss', $von, $bis);
        $stmt->execute();
        $result = $stmt->get_result();
        
        // HP Database - Test if res table exists
        $tableCheck = $hpConn->query("SHOW TABLES LIKE 'res'");
        if ($tableCheck->num_rows === 0) {
            return ['success' => false, 'error' => 'Tabelle "res" existiert nicht in HP-Datenbank'];
        }
        
        // Clear res table
        if (!$hpConn->query("DELETE FROM res")) {
            return ['success' => false, 'error' => 'Fehler beim Leeren der res Tabelle: ' . $hpConn->error];
        }
        
        $n = 0;
        while ($row = $result->fetch_assoc()) {
            $n++;
            
            // Helper functions
            $noNullT = function($val) { return $val ?? ''; };
            $noNullI = function($val) { return intval($val ?? 0); };
            
            $name = trim($noNullT($row['vorname']) . ' ' . $noNullT($row['nachname']));
            $arrkbez = trim($noNullT($row['arrkbez']));
            
            // Boolean values based on arrkbez
            $hp = ($arrkbez === 'HP') ? 1 : 0;
            $bhp = ($arrkbez === 'BHP') ? 1 : 0;
            $fstk = ($arrkbez === 'FSTK') ? 1 : 0;
            $ala = ($arrkbez === 'ALAC') ? 1 : 0;
            
            $erstellt = $row['timestamp'] ? $row['timestamp'] : date('Y-m-d H:i:s');
            
            // Insert into HP res table - Full version like VB.NET
            $insertSql = "
                INSERT INTO res (
                    iid, name, von, bis, pl, lg, mbz, dz, 
                    hp, bhp, fstk, ala, hund, bem, storno, 
                    mail, note, erstellt, resid
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";
            
            $insertStmt = $hpConn->prepare($insertSql);
            if (!$insertStmt) {
                continue;
            }
            
            // Prepare variables for bind_param (matching VB.NET code exactly)
            $iid = $noNullI($row['id']);        // Convert.ToInt32(rs("id"))
            $pl = $noNullI($row['sonder']);     // NoNullI(rs("sonder"))
            $lg = $noNullI($row['lager']);      // NoNullI(rs("lager"))
            $mbz = $noNullI($row['betten']);    // NoNullI(rs("betten"))
            $dz = $noNullI($row['dz']);         // NoNullI(rs("dz"))
            $hund = $noNullI($row['hund']);     // NoNullI(rs("hund"))
            $bem = $noNullT($row['bem']);       // NoNullT(rs("bem"))
            $storno = ($row['storno'] == 1) ? 1 : 0;  // rs("storno") = 1
            $mail = $noNullT($row['email']);    // NoNullT(rs("email"))
            $note = 0;                          // False in VB = 0
            $resid = $noNullI($row['id']);      // NoNullI(rs("id"))
            
            // Dynamic type string building
            $params = [
                [$iid, 'i'],                    // iid 
                [$name, 's'],                   // name 
                [$row['anreise'], 's'],         // von 
                [$row['abreise'], 's'],         // bis 
                [$pl, 'i'],                     // pl 
                [$lg, 'i'],                     // lg 
                [$mbz, 'i'],                    // mbz 
                [$dz, 'i'],                     // dz 
                [$hp, 'i'],                     // hp 
                [$bhp, 'i'],                    // bhp 
                [$fstk, 'i'],                   // fstk 
                [$ala, 'i'],                    // ala 
                [$hund, 'i'],                   // hund 
                [$bem, 's'],                    // bem 
                [$storno, 'i'],                 // storno 
                [$mail, 's'],                   // mail 
                [$note, 'i'],                   // note 
                [$erstellt, 's'],               // erstellt 
                [$resid, 'i']                   // resid 
            ];
            
            $typeString = '';
            $values = [];
            foreach ($params as $param) {
                $typeString .= $param[1];
                $values[] = $param[0];
            }
            
            $insertStmt->bind_param($typeString, ...$values);
            
            if (!$insertStmt->execute()) {
                continue;
            }
            
            // Optional: Update hp_data
            $updateSql = "
                UPDATE hp_data 
                INNER JOIN res ON hp_data.resid = res.iid 
                SET hp_data.an = res.von, hp_data.ab = res.bis, hp_data.resid = res.iid 
                WHERE hp_data.resid = ?
            ";
            
            $updateStmt = $hpConn->prepare($updateSql);
            if ($updateStmt) {
                $updateStmt->bind_param('i', $row['id']);
                $updateStmt->execute();
                $updateStmt->close();
            }
            
            $insertStmt->close();
        }
        
        $totalSynced += $n;
        $stmt->close();
        
        // Sync ResDet
        $sql = "
            SELECT * FROM `AV_ResDet` 
            WHERE `bis` > ? AND `von` <= ?
        ";
        
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            return ['success' => false, 'error' => 'MySQL ResDet Prepare Error: ' . $mysqli->error];
        }
        
        $stmt->bind_param('ss', $von, $bis);
        $stmt->execute();
        $result = $stmt->get_result();
        
        // HP Database - Test if resdet table exists
        $tableCheck = $hpConn->query("SHOW TABLES LIKE 'resdet'");
        if ($tableCheck->num_rows === 0) {
            return ['success' => false, 'error' => 'Tabelle "resdet" existiert nicht in HP-Datenbank'];
        }
        
        // Clear resdet table
        if (!$hpConn->query("DELETE FROM resdet")) {
            return ['success' => false, 'error' => 'Fehler beim Leeren der resdet Tabelle: ' . $hpConn->error];
        }
        
        $n = 0;
        while ($row = $result->fetch_assoc()) {
            $n++;
            
            $safeInt = function($val) { return intval($val ?? 0); };
            $noNullT = function($val) { return $val ?? ''; };
            
            // Insert into HP resdet table
            $insertSql = "
                INSERT INTO resdet (
                    iid, resid, von, bis, anz, zimid, nam, col, 
                    bem, verpfl, par, note, dx, dy, hund
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";
            
            $insertStmt = $hpConn->prepare($insertSql);
            if (!$insertStmt) {
                continue;
            }
            
            // Prepare variables for bind_param (to avoid "by reference" warnings)
            $iid = $safeInt($row['ID']);
            $resid = $safeInt($row['resid']);
            $anz = $safeInt($row['anz']);
            $zimid = $safeInt($row['zimID']);
            $nam = $noNullT($row['bez']);
            $col = 0;
            $bem = '';
            $verpfl = $safeInt($row['arr']);
            $par = $safeInt($row['ParentID']);
            $note = $noNullT($row['note']);
            $dx = $safeInt($row['dx']);
            $dy = $safeInt($row['dy']);
            $hund = $safeInt($row['hund']);
            
            // Dynamic parameter binding for ResDet
            $params = [
                [$iid, 'i'],              // iid
                [$resid, 'i'],            // resid
                [$row['von'], 's'],       // von
                [$row['bis'], 's'],       // bis
                [$anz, 'i'],              // anz
                [$zimid, 'i'],            // zimid
                [$nam, 's'],              // nam
                [$col, 'i'],              // col (always 0)
                [$bem, 's'],              // bem (empty as per VB code)
                [$verpfl, 'i'],           // verpfl
                [$par, 'i'],              // par
                [$note, 's'],             // note
                [$dx, 'i'],               // dx
                [$dy, 'i'],               // dy
                [$hund, 'i']              // hund
            ];
            
            $typeString = '';
            $values = [];
            foreach ($params as $param) {
                $typeString .= $param[1];
                $values[] = $param[0];
            }
            
            $insertStmt->bind_param($typeString, ...$values);
            
            if (!$insertStmt->execute()) {
                continue;
            }
            
            $insertStmt->close();
        }
        
        $totalSynced += $n;
        $stmt->close();
        
        return [
            'success' => true,
            'message' => 'Sync erfolgreich'
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => 'Sync-Fehler: ' . $e->getMessage(),
            'details' => [
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]
        ];
    }
}

// Execute sync and return result
try {
    $result = syncDatabases();
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode([
        'success' => false, 
        'error' => 'Unerwarteter Fehler: ' . $e->getMessage(),
        'details' => [
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]
    ], JSON_UNESCAPED_UNICODE);
}
?>
