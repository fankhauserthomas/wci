<?php
require_once 'hp-db-config.php';

$resid = isset($_GET['resid']) ? intval($_GET['resid']) : 0;
if ($resid <= 0) {
    die('Keine gültige Reservierungs-ID');
}

// Daten für Etiketten laden - gleiche Funktion wie in print-receipt.php
function getPrintData($resid) {
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
            WHERE hp.resid = " . intval($resid) . "
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

$printData = getPrintData($resid);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Etiketten - Reservierung <?php echo $resid; ?></title>
    <style>
        @page { 
            size: A4; 
            margin: 15mm; 
        }
        body { 
            font-family: Arial, sans-serif; 
            margin: 0;
            color: #000;
        }
        .header { 
            text-align: center; 
            margin-bottom: 30px;
        }
        .label {
            border: 3px solid #000;
            margin: 20px 0;
            padding: 20px;
            text-align: center;
            page-break-inside: avoid;
            min-height: 80px;
        }
        .label-tisch {
            font-size: 24pt;
            font-weight: bold;
            margin-bottom: 15px;
            color: #000;
        }
        .label-details {
            font-size: 14pt;
            line-height: 1.3;
        }
        .label-name {
            font-weight: bold;
            margin-bottom: 5px;
        }
    </style>
</head>
<body onload="window.print();">
    <div class="header">
        <h1>Tisch-Etiketten - Reservierung #<?php echo $resid; ?></h1>
    </div>
    
    <?php foreach ($printData as $row): ?>
        <div class="label">
            <div class="label-tisch"><?php echo htmlspecialchars($row['Tisch']); ?></div>
            <div class="label-details">
                <div class="label-name"><?php echo htmlspecialchars($row['nam']); ?></div>
                <div><?php echo htmlspecialchars($row['anz']); ?> Personen</div>
                <?php if (!empty($row['bem'])): ?>
                    <div style="font-style: italic; color: #666; margin-top: 5px;">
                        <?php echo htmlspecialchars($row['bem']); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</body>
</html>
