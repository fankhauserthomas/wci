<?php
/**
 * Belegungsanalyse - Neue Version mit SQL-Query
 * Zeigt Gäste im Haus, Quotas und Daily Summary Daten
 */

require_once 'config.php';

// Parameter
$startDate = $_GET['start'] ?? date('Y-m-d');
$endDate = $_GET['end'] ?? date('Y-m-d', strtotime($startDate . ' +31 days'));

try {
    // SQL-Abfrage ausführen
    $sql = "
    -- SQL-Abfrage: Gäste IM HAUS + Quotas + Daily Summary
    WITH 
    -- 1. Alle Tage im Zeitraum generieren
    date_range AS (
        SELECT 
            DATE(?) + INTERVAL (a.a + (10 * b.a) + (100 * c.a)) DAY as datum
        FROM 
            (SELECT 0 as a UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) as a
            CROSS JOIN (SELECT 0 as a UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) as b
            CROSS JOIN (SELECT 0 as a UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) as c
        WHERE 
            DATE(?) + INTERVAL (a.a + (10 * b.a) + (100 * c.a)) DAY <= DATE(?)
    ),

    -- 2. HRS Gäste IM HAUS (av_id > 0)
    hrs_data AS (
        SELECT 
            dr.datum as tag,
            SUM(COALESCE(av.sonder, 0)) as hrs_sonder,
            SUM(COALESCE(av.lager, 0)) as hrs_lager,
            SUM(COALESCE(av.betten, 0)) as hrs_betten,
            SUM(COALESCE(av.dz, 0)) as hrs_dz
        FROM date_range dr
        LEFT JOIN `AV-Res` av ON (
            DATE(av.anreise) <= dr.datum 
            AND DATE(av.abreise) > dr.datum
            AND av.av_id > 0
            AND (av.storno = 0 OR av.storno IS NULL OR av.storno = false)
        )
        GROUP BY dr.datum
    ),

    -- 3. Lokale Gäste IM HAUS (av_id = 0)
    lokal_data AS (
        SELECT 
            dr.datum as tag,
            SUM(COALESCE(av.sonder, 0)) as lokal_sonder,
            SUM(COALESCE(av.lager, 0)) as lokal_lager,
            SUM(COALESCE(av.betten, 0)) as lokal_betten,
            SUM(COALESCE(av.dz, 0)) as lokal_dz
        FROM date_range dr
        LEFT JOIN `AV-Res` av ON (
            DATE(av.anreise) <= dr.datum 
            AND DATE(av.abreise) > dr.datum
            AND av.av_id = 0
            AND (av.storno = 0 OR av.storno IS NULL OR av.storno = false)
        )
        GROUP BY dr.datum
    ),

    -- 4. Quota-Daten mit korrektem Category-Mapping
    quota_categories AS (
        SELECT 
            hq.id as quota_id,
            hq.title as quota_name,
            hq.hrs_id,
            hq.date_from,
            hq.date_to,
            hq.mode,
            SUM(CASE WHEN hqc.category_id = 1958 THEN COALESCE(hqc.total_beds, 0) ELSE 0 END) as quota_lager,
            SUM(CASE WHEN hqc.category_id = 2293 THEN COALESCE(hqc.total_beds, 0) ELSE 0 END) as quota_betten,
            SUM(CASE WHEN hqc.category_id = 2381 THEN COALESCE(hqc.total_beds, 0) ELSE 0 END) as quota_dz,
            SUM(CASE WHEN hqc.category_id = 6106 THEN COALESCE(hqc.total_beds, 0) ELSE 0 END) as quota_sonder
        FROM hut_quota hq
        LEFT JOIN hut_quota_categories hqc ON hq.id = hqc.hut_quota_id
        GROUP BY hq.id, hq.title, hq.hrs_id, hq.date_from, hq.date_to, hq.mode
    ),

    -- 5. Aktive Quotas für jeden Tag
    active_quotas AS (
        SELECT DISTINCT
            dr.datum,
            qc.quota_id,
            qc.quota_name,
            qc.hrs_id,
            qc.mode,
            qc.date_from,
            qc.date_to,
            qc.quota_lager,
            qc.quota_betten,
            qc.quota_dz,
            qc.quota_sonder
        FROM date_range dr
        LEFT JOIN quota_categories qc ON dr.datum >= qc.date_from AND dr.datum < qc.date_to
    ),

    -- 6. Daily Summary Daten aggregiert
    daily_summary_data AS (
        SELECT 
            DATE(ds.day) as tag,
            ds.total_guests,
            -- Assigned guests pro Kategorie
            SUM(CASE WHEN dsc.category_type = 'ML' THEN COALESCE(dsc.assigned_guests, 0) ELSE 0 END) as assigned_lager,
            SUM(CASE WHEN dsc.category_type = 'MBZ' THEN COALESCE(dsc.assigned_guests, 0) ELSE 0 END) as assigned_betten,
            SUM(CASE WHEN dsc.category_type = '2BZ' THEN COALESCE(dsc.assigned_guests, 0) ELSE 0 END) as assigned_dz,
            SUM(CASE WHEN dsc.category_type = 'SK' THEN COALESCE(dsc.assigned_guests, 0) ELSE 0 END) as assigned_sonder,
            -- Free places pro Kategorie
            SUM(CASE WHEN dsc.category_type = 'ML' THEN COALESCE(dsc.free_places, 0) ELSE 0 END) as free_lager,
            SUM(CASE WHEN dsc.category_type = 'MBZ' THEN COALESCE(dsc.free_places, 0) ELSE 0 END) as free_betten,
            SUM(CASE WHEN dsc.category_type = '2BZ' THEN COALESCE(dsc.free_places, 0) ELSE 0 END) as free_dz,
            SUM(CASE WHEN dsc.category_type = 'SK' THEN COALESCE(dsc.free_places, 0) ELSE 0 END) as free_sonder
        FROM daily_summary ds
        LEFT JOIN daily_summary_categories dsc ON ds.id = dsc.daily_summary_id
        WHERE 
            DATE(ds.day) >= ? 
            AND DATE(ds.day) <= ?
        GROUP BY DATE(ds.day), ds.total_guests
    )

    -- Hauptabfrage: Alle Daten kombiniert
    SELECT 
        -- Datum formatiert
        CONCAT(
            CASE DAYOFWEEK(dr.datum)
                WHEN 1 THEN 'So'
                WHEN 2 THEN 'Mo' 
                WHEN 3 THEN 'Di'
                WHEN 4 THEN 'Mi'
                WHEN 5 THEN 'Do'
                WHEN 6 THEN 'Fr'
                WHEN 7 THEN 'Sa'
            END,
            ' ',
            DATE_FORMAT(dr.datum, '%d.%m.')
        ) as datum_formatiert,
        
        -- Quota-Informationen
        aq.hrs_id,
        aq.quota_name as quota_bezeichnung,
        aq.mode as quota_mode,
        
        -- Quota-Kategorien
        NULLIF(aq.quota_sonder, 0) as quota_sonder,
        NULLIF(aq.quota_lager, 0) as quota_lager,
        NULLIF(aq.quota_betten, 0) as quota_betten,
        NULLIF(aq.quota_dz, 0) as quota_dz,
        
        -- HRS Gäste IM HAUS
        NULLIF(h.hrs_sonder, 0) as hrs_sonder,
        NULLIF(h.hrs_lager, 0) as hrs_lager,
        NULLIF(h.hrs_betten, 0) as hrs_betten,
        NULLIF(h.hrs_dz, 0) as hrs_dz,
        
        -- Lokale Gäste IM HAUS
        NULLIF(l.lokal_sonder, 0) as lokal_sonder,
        NULLIF(l.lokal_lager, 0) as lokal_lager,
        NULLIF(l.lokal_betten, 0) as lokal_betten,
        NULLIF(l.lokal_dz, 0) as lokal_dz,
        
        -- Gesamt pro Kategorie (IM HAUS)
        NULLIF(COALESCE(h.hrs_sonder, 0) + COALESCE(l.lokal_sonder, 0), 0) as gesamt_sonder,
        NULLIF(COALESCE(h.hrs_lager, 0) + COALESCE(l.lokal_lager, 0), 0) as gesamt_lager,
        NULLIF(COALESCE(h.hrs_betten, 0) + COALESCE(l.lokal_betten, 0), 0) as gesamt_betten,
        NULLIF(COALESCE(h.hrs_dz, 0) + COALESCE(l.lokal_dz, 0), 0) as gesamt_dz,
        
        -- Gesamt belegt (alle Gäste IM HAUS)
        NULLIF((COALESCE(h.hrs_sonder, 0) + COALESCE(h.hrs_lager, 0) + COALESCE(h.hrs_betten, 0) + COALESCE(h.hrs_dz, 0) +
                COALESCE(l.lokal_sonder, 0) + COALESCE(l.lokal_lager, 0) + COALESCE(l.lokal_betten, 0) + COALESCE(l.lokal_dz, 0)), 0) as gesamt_belegt,
        
        -- Daily Summary Daten
        NULLIF(dsd.total_guests, 0) as total_guests,
        
        -- Daily Summary: Assigned Guests pro Kategorie
        NULLIF(dsd.assigned_sonder, 0) as assigned_sonder,
        NULLIF(dsd.assigned_lager, 0) as assigned_lager,
        NULLIF(dsd.assigned_betten, 0) as assigned_betten,
        NULLIF(dsd.assigned_dz, 0) as assigned_dz,
        
        -- Daily Summary: Free Places pro Kategorie
        NULLIF(dsd.free_sonder, 0) as free_sonder,
        NULLIF(dsd.free_lager, 0) as free_lager,
        NULLIF(dsd.free_betten, 0) as free_betten,
        NULLIF(dsd.free_dz, 0) as free_dz,
        
        -- Hilfsspalten
        dr.datum as datum_raw,
        DAYOFWEEK(dr.datum) IN (1, 7) as ist_wochenende,
        aq.quota_id

    FROM date_range dr
    LEFT JOIN hrs_data h ON dr.datum = h.tag
    LEFT JOIN lokal_data l ON dr.datum = l.tag
    LEFT JOIN active_quotas aq ON dr.datum = aq.datum
    LEFT JOIN daily_summary_data dsd ON dr.datum = dsd.tag

    ORDER BY dr.datum;
    ";

    // Statement vorbereiten und ausführen
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('sssss', $startDate, $startDate, $endDate, $startDate, $endDate);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }

} catch (Exception $e) {
    die("Fehler beim Laden der Daten: " . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Belegungsanalyse</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            background: #f5f5f5;
        }
        
        .container {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        h1 {
            color: #333;
            text-align: center;
            margin-bottom: 30px;
        }
        
        .controls {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            display: flex;
            gap: 15px;
            align-items: center;
        }
        
        .controls label {
            font-weight: bold;
        }
        
        .controls input {
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        
        .controls button {
            background: #007bff;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
        }
        
        .controls button:hover {
            background: #0056b3;
        }
        
        .table-container {
            overflow-x: auto;
            border: 1px solid #ddd;
            border-radius: 6px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            min-width: 1200px;
        }
        
        th {
            background: #e9ecef;
            padding: 8px 4px;
            text-align: center;
            border: 1px solid #ddd;
            font-weight: bold;
            white-space: nowrap;
        }
        
        td {
            padding: 6px 4px;
            text-align: center;
            border: 1px solid #ddd;
            white-space: nowrap;
        }
        
        tr:nth-child(even) {
            background: #f8f9fa;
        }
        
        tr:hover {
            background: #e3f2fd;
        }
        
        .weekend {
            background: #ffe6e6 !important;
        }
        
        .datum {
            text-align: left;
            font-weight: bold;
            background: #f5f5f5;
        }
        
        .quota-name {
            text-align: left;
            max-width: 150px;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .number {
            text-align: right;
        }
        
        .empty {
            color: #999;
        }
        
        /* Kategorie-spezifische Farben */
        .sonder { color: #9966CC; }
        .lager { color: #66CC66; }
        .betten { color: #CCCC66; }
        .dz { color: #FF6666; }
        
        .hrs { font-weight: bold; }
        .lokal { font-style: italic; }
    </style>
</head>
<body>
    <div class="container">
        <h1>📊 Belegungsanalyse</h1>
        
        <div class="controls">
            <label for="start">Von:</label>
            <input type="date" id="start" name="start" value="<?= htmlspecialchars($startDate) ?>">
            
            <label for="end">Bis:</label>
            <input type="date" id="end" name="end" value="<?= htmlspecialchars($endDate) ?>">
            
            <button onclick="updateTable()">📅 Zeitraum laden</button>
            
            <span style="margin-left: 20px; color: #666;">
                Zeitraum: <?= date('d.m.Y', strtotime($startDate)) ?> - <?= date('d.m.Y', strtotime($endDate)) ?>
            </span>
        </div>
        
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th rowspan="2">Datum</th>
                        <th colspan="3">Quota</th>
                        <th colspan="4">Quota Kategorien</th>
                        <th colspan="4">HRS Gäste</th>
                        <th colspan="4">Lokal Gäste</th>
                        <th colspan="4">Gesamt Gäste</th>
                        <th rowspan="2">Total</th>
                        <th colspan="4">Daily Summary: Assigned</th>
                        <th colspan="4">Daily Summary: Free</th>
                    </tr>
                    <tr>
                        <!-- Quota -->
                        <th>HRS-ID</th>
                        <th>Bezeichnung</th>
                        <th>Mode</th>
                        
                        <!-- Quota Kategorien -->
                        <th class="sonder">Q-S</th>
                        <th class="lager">Q-L</th>
                        <th class="betten">Q-B</th>
                        <th class="dz">Q-D</th>
                        
                        <!-- HRS Gäste -->
                        <th class="sonder hrs">H-S</th>
                        <th class="lager hrs">H-L</th>
                        <th class="betten hrs">H-B</th>
                        <th class="dz hrs">H-D</th>
                        
                        <!-- Lokal Gäste -->
                        <th class="sonder lokal">L-S</th>
                        <th class="lager lokal">L-L</th>
                        <th class="betten lokal">L-B</th>
                        <th class="dz lokal">L-D</th>
                        
                        <!-- Gesamt Gäste -->
                        <th class="sonder">G-S</th>
                        <th class="lager">G-L</th>
                        <th class="betten">G-B</th>
                        <th class="dz">G-D</th>
                        
                        <!-- Daily Summary Assigned -->
                        <th class="sonder">A-S</th>
                        <th class="lager">A-L</th>
                        <th class="betten">A-B</th>
                        <th class="dz">A-D</th>
                        
                        <!-- Daily Summary Free -->
                        <th class="sonder">F-S</th>
                        <th class="lager">F-L</th>
                        <th class="betten">F-B</th>
                        <th class="dz">F-D</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($data as $row): ?>
                        <tr class="<?= $row['ist_wochenende'] ? 'weekend' : '' ?>">
                            <!-- Datum -->
                            <td class="datum"><?= htmlspecialchars($row['datum_formatiert']) ?></td>
                            
                            <!-- Quota Info -->
                            <td class="number"><?= $row['hrs_id'] ?: '<span class="empty">-</span>' ?></td>
                            <td class="quota-name" title="<?= htmlspecialchars($row['quota_bezeichnung'] ?: '') ?>">
                                <?= $row['quota_bezeichnung'] ? htmlspecialchars($row['quota_bezeichnung']) : '<span class="empty">-</span>' ?>
                            </td>
                            <td><?= $row['quota_mode'] ?: '<span class="empty">-</span>' ?></td>
                            
                            <!-- Quota Kategorien -->
                            <td class="number sonder"><?= $row['quota_sonder'] ?: '<span class="empty">-</span>' ?></td>
                            <td class="number lager"><?= $row['quota_lager'] ?: '<span class="empty">-</span>' ?></td>
                            <td class="number betten"><?= $row['quota_betten'] ?: '<span class="empty">-</span>' ?></td>
                            <td class="number dz"><?= $row['quota_dz'] ?: '<span class="empty">-</span>' ?></td>
                            
                            <!-- HRS Gäste -->
                            <td class="number sonder hrs"><?= $row['hrs_sonder'] ?: '<span class="empty">-</span>' ?></td>
                            <td class="number lager hrs"><?= $row['hrs_lager'] ?: '<span class="empty">-</span>' ?></td>
                            <td class="number betten hrs"><?= $row['hrs_betten'] ?: '<span class="empty">-</span>' ?></td>
                            <td class="number dz hrs"><?= $row['hrs_dz'] ?: '<span class="empty">-</span>' ?></td>
                            
                            <!-- Lokal Gäste -->
                            <td class="number sonder lokal"><?= $row['lokal_sonder'] ?: '<span class="empty">-</span>' ?></td>
                            <td class="number lager lokal"><?= $row['lokal_lager'] ?: '<span class="empty">-</span>' ?></td>
                            <td class="number betten lokal"><?= $row['lokal_betten'] ?: '<span class="empty">-</span>' ?></td>
                            <td class="number dz lokal"><?= $row['lokal_dz'] ?: '<span class="empty">-</span>' ?></td>
                            
                            <!-- Gesamt Gäste -->
                            <td class="number sonder"><?= $row['gesamt_sonder'] ?: '<span class="empty">-</span>' ?></td>
                            <td class="number lager"><?= $row['gesamt_lager'] ?: '<span class="empty">-</span>' ?></td>
                            <td class="number betten"><?= $row['gesamt_betten'] ?: '<span class="empty">-</span>' ?></td>
                            <td class="number dz"><?= $row['gesamt_dz'] ?: '<span class="empty">-</span>' ?></td>
                            
                            <!-- Total Belegt -->
                            <td class="number" style="background: #e8f5e8; font-weight: bold;">
                                <?= $row['gesamt_belegt'] ?: '<span class="empty">-</span>' ?>
                            </td>
                            
                            <!-- Daily Summary Assigned -->
                            <td class="number sonder"><?= $row['assigned_sonder'] ?: '<span class="empty">-</span>' ?></td>
                            <td class="number lager"><?= $row['assigned_lager'] ?: '<span class="empty">-</span>' ?></td>
                            <td class="number betten"><?= $row['assigned_betten'] ?: '<span class="empty">-</span>' ?></td>
                            <td class="number dz"><?= $row['assigned_dz'] ?: '<span class="empty">-</span>' ?></td>
                            
                            <!-- Daily Summary Free -->
                            <td class="number sonder"><?= $row['free_sonder'] ?: '<span class="empty">-</span>' ?></td>
                            <td class="number lager"><?= $row['free_lager'] ?: '<span class="empty">-</span>' ?></td>
                            <td class="number betten"><?= $row['free_betten'] ?: '<span class="empty">-</span>' ?></td>
                            <td class="number dz"><?= $row['free_dz'] ?: '<span class="empty">-</span>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div style="margin-top: 20px; color: #666; font-size: 11px;">
            <p><strong>Legende:</strong></p>
            <p>
                <span class="sonder">■ Sonder (S)</span> | 
                <span class="lager">■ Lager (L)</span> | 
                <span class="betten">■ Betten (B)</span> | 
                <span class="dz">■ DZ (D)</span>
            </p>
            <p>
                <strong>Q-</strong> = Quota | 
                <strong>H-</strong> = HRS | 
                <strong>L-</strong> = Lokal | 
                <strong>G-</strong> = Gesamt | 
                <strong>A-</strong> = Assigned | 
                <strong>F-</strong> = Free
            </p>
            <p>Anzahl Datensätze: <?= count($data) ?> Tage</p>
        </div>
    </div>

    <script>
        function updateTable() {
            const start = document.getElementById('start').value;
            const end = document.getElementById('end').value;
            
            if (start && end) {
                const url = new URL(window.location);
                url.searchParams.set('start', start);
                url.searchParams.set('end', end);
                window.location.href = url.toString();
            } else {
                alert('Bitte beide Datumsfelder ausfüllen.');
            }
        }
        
        // Enter-Taste in Eingabefeldern
        document.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                updateTable();
            }
        });
    </script>
</body>
</html>
