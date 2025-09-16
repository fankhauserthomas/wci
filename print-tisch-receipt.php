<?php
require_once __DIR__ . '/auth.php';
require_once 'hp-db-config.php';

// Authentifizierung prüfen
if (!AuthManager::checkSession()) {
    header('Location: login.html');
    exit;
}

// Daten laden (vereinfachte Version der Hauptfunktion)
function getPrintTischData() {
    $hpConn = getHpDbConnection();
    if (!$hpConn) return [];
    
    $sql = "
        SELECT 
            GROUP_CONCAT(DISTINCT t.bez ORDER BY t.bez ASC SEPARATOR ', ') AS Tisch,
            today.anz,
            today.nam,
            today.bem
        FROM (
            SELECT hp.iid, hp.anz, hp.nam, hp.bem
            FROM a_hp_data hp
            WHERE hp.an <= NOW() AND hp.ab > NOW()
            ORDER BY hp.srt
        ) today
        JOIN a_hp_tisch t ON today.iid = t.iid
        GROUP BY today.iid
        ORDER BY today.nam
    ";
    
    $result = $hpConn->query($sql);
    $data = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
    }
    return $data;
}

$printData = getPrintTischData();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tischübersicht - Rechnung</title>
    <style>
        @page { 
            size: A4; 
            margin: 20mm; 
        }
        body { 
            font-family: Arial, sans-serif; 
            font-size: 12pt; 
            line-height: 1.4;
            margin: 0;
            color: #000;
        }
        .header { 
            text-align: center; 
            border-bottom: 2px solid #000; 
            padding-bottom: 15px; 
            margin-bottom: 25px;
        }
        .header h1 { font-size: 24pt; margin: 0; }
        .header h2 { font-size: 16pt; margin: 10px 0 0 0; color: #666; }
        .table { 
            width: 100%; 
            border-collapse: collapse; 
            margin: 20px 0;
        }
        .table th, .table td { 
            border: 1px solid #000; 
            padding: 10px; 
            text-align: left;
        }
        .table th { 
            background: #f0f0f0; 
            font-weight: bold;
        }
        .footer {
            margin-top: 40px;
            text-align: center;
            font-size: 10pt;
            color: #666;
        }
    </style>
</head>
<body onload="window.print();">
    <div class="header">
        <h1>Franz-Senn-Hütte</h1>
        <h2>Tischübersicht - Rechnung</h2>
        <p><?php echo date('d.m.Y H:i'); ?></p>
    </div>
    
    <table class="table">
        <thead>
            <tr>
                <th>Tisch</th>
                <th>Anzahl</th>
                <th>Name</th>
                <th>Bemerkung</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($printData as $row): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['Tisch']); ?></td>
                    <td><?php echo htmlspecialchars($row['anz']); ?></td>
                    <td><?php echo htmlspecialchars($row['nam']); ?></td>
                    <td><?php echo htmlspecialchars($row['bem']); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    
    <div class="footer">
        <p>Gedruckt am <?php echo date('d.m.Y H:i:s'); ?></p>
        <p>Franz-Senn-Hütte - Reservierungssystem</p>
    </div>
</body>
</html>
