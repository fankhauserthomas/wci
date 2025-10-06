<?php
// Zimmereditor – Verwaltung der Schlafräume (webbasiert)
// Single-file implementation: serves HTML UI and JSON API (list/save)

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

// Robust config include from nested path
$included = false;
$tryPaths = [__DIR__ . '/../config.php', __DIR__ . '/../../config.php', __DIR__ . '/../config-safe.php'];
foreach ($tryPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $included = true;
        break;
    }
}
if (!$included) {
    http_response_code(500);
    echo 'Konfiguration nicht gefunden (config.php)';
    exit;
}

// Expect $mysqli from config.php
if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    // Try building connection from constants if available
    if (defined('DB_HOST') && defined('DB_USER') && defined('DB_PASS') && defined('DB_NAME')) {
        $mysqli = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    }
}
if ($mysqli->connect_error) {
    http_response_code(500);
    echo 'Datenbankverbindung fehlgeschlagen: ' . htmlspecialchars($mysqli->connect_error);
    exit;
}
$mysqli->set_charset('utf8mb4');

// Utility helpers
function json_response($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function sanitize_room($row) {
    return [
        'id' => isset($row['id']) ? (int)$row['id'] : 0,
        'caption' => isset($row['caption']) ? trim((string)$row['caption']) : '',
        'etage' => isset($row['etage']) ? (int)$row['etage'] : 0,
        'kapazitaet' => isset($row['kapazitaet']) ? (int)$row['kapazitaet'] : 0,
        'kategorie' => isset($row['kategorie']) ? trim((string)$row['kategorie']) : '',
        'col' => isset($row['col']) ? trim((string)$row['col']) : '#FFDDDDDD',
        'px' => isset($row['px']) ? (int)$row['px'] : 1,
        'py' => isset($row['py']) ? (int)$row['py'] : 1,
        'visible' => isset($row['visible']) && ($row['visible'] === true || $row['visible'] === 1 || $row['visible'] === '1') ? 1 : 0,
        'sort' => isset($row['sort']) ? (int)$row['sort'] : 1,
    ];
}

function isReadOnlyCaption($caption) {
    return mb_strtolower(trim((string)$caption)) === 'ablage';
}

const ZIMMER_WORKING_TABLE = 'zp_zimmer';
const ZIMMER_TABLE_PREFIX = 'zp_zimmer_';

function zimmer_config_slug($name) {
    $slug = trim((string)$name);
    if ($slug === '') {
        return '';
    }
    $slug = mb_strtolower($slug, 'UTF-8');
    $slug = preg_replace('/[^a-z0-9]+/u', '_', $slug);
    $slug = trim($slug, '_');
    if ($slug === 'zimmer' || $slug === '') {
        return '';
    }
    return $slug;
}

function zimmer_config_label($slug) {
    if ($slug === '__working__' || $slug === '' || $slug === null) {
        return 'Aktive Konfiguration';
    }
    $parts = array_filter(explode('_', (string)$slug));
    $parts = array_map(function ($p) {
        return mb_convert_case($p, MB_CASE_TITLE, 'UTF-8');
    }, $parts);
    return implode(' ', $parts) ?: $slug;
}

function zimmer_config_table_from_key($key) {
    if ($key === '__working__' || $key === '' || $key === null) {
        return ZIMMER_WORKING_TABLE;
    }
    return ZIMMER_TABLE_PREFIX . $key;
}

function zimmer_config_normalize_key($value) {
    if ($value === null) {
        return '__working__';
    }
    $value = trim((string)$value);
    if ($value === '' || $value === '__working__') {
        return '__working__';
    }
    if (strpos($value, ZIMMER_TABLE_PREFIX) === 0) {
        $value = substr($value, strlen(ZIMMER_TABLE_PREFIX));
    }
    $slug = zimmer_config_slug($value);
    if ($slug === '') {
        return '';
    }
    return $slug;
}

function zimmer_config_table_exists(mysqli $mysqli, $tableName) {
    $sql = 'SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1';
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $tableName);
    $stmt->execute();
    $res = $stmt->get_result();
    $exists = $res && $res->num_rows > 0;
    $stmt->close();
    return $exists;
}

function zimmer_config_row_count(mysqli $mysqli, $tableName) {
    if (!preg_match('/^[a-z0-9_]+$/i', $tableName)) {
        return 0;
    }
    $sql = sprintf('SELECT COUNT(*) AS c FROM `%s`', $tableName);
    $res = $mysqli->query($sql);
    if (!$res) {
        return 0;
    }
    $row = $res->fetch_assoc();
    $res->close();
    return isset($row['c']) ? (int)$row['c'] : 0;
}

function zimmer_config_list(mysqli $mysqli) {
    $configs = [];

    $configs[] = [
        'key' => '__working__',
        'label' => zimmer_config_label('__working__'),
        'table' => ZIMMER_WORKING_TABLE,
        'rows' => zimmer_config_row_count($mysqli, ZIMMER_WORKING_TABLE),
        'protected' => true
    ];

    $sql = "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '" . ZIMMER_TABLE_PREFIX . "%'";
    $res = $mysqli->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $table = $row['TABLE_NAME'];
            if ($table === ZIMMER_WORKING_TABLE) {
                continue;
            }
            $suffix = substr($table, strlen(ZIMMER_TABLE_PREFIX));
            if (!$suffix) {
                continue;
            }
            $configs[] = [
                'key' => $suffix,
                'label' => zimmer_config_label($suffix),
                'table' => $table,
                'rows' => zimmer_config_row_count($mysqli, $table),
                'protected' => false
            ];
        }
        $res->close();
    }

    usort($configs, function ($a, $b) {
        if ($a['key'] === '__working__') {
            return -1;
        }
        if ($b['key'] === '__working__') {
            return 1;
        }
        return strcasecmp($a['label'], $b['label']);
    });

    return $configs;
}

function zimmer_config_create(mysqli $mysqli, $targetSlug, $sourceKey = '__working__', $overwrite = false) {
    if ($targetSlug === '__working__' || $targetSlug === '' || $targetSlug === null) {
        throw new Exception('Ungültiger Zielname.');
    }

    $targetTable = zimmer_config_table_from_key($targetSlug);
    if (!preg_match('/^[a-z0-9_]+$/', $targetTable)) {
        throw new Exception('Ungültiger Tabellenname.');
    }

    $sourceTable = zimmer_config_table_from_key($sourceKey);
    if (!zimmer_config_table_exists($mysqli, $sourceTable)) {
        throw new Exception('Quellkonfiguration nicht gefunden.');
    }

    $alreadyExists = zimmer_config_table_exists($mysqli, $targetTable);
    if ($alreadyExists && !$overwrite) {
        throw new Exception('Konfiguration existiert bereits.');
    }

    $mysqli->begin_transaction();
    try {
        if ($alreadyExists && $overwrite) {
            $sqlDrop = sprintf('DROP TABLE `%s`', $targetTable);
            if (!$mysqli->query($sqlDrop)) {
                throw new Exception($mysqli->error);
            }
        }

        $sqlCreate = sprintf('CREATE TABLE `%s` LIKE `%s`', $targetTable, $sourceTable);
        if (!$mysqli->query($sqlCreate)) {
            throw new Exception($mysqli->error);
        }

        $sqlInsert = sprintf('INSERT INTO `%s` SELECT * FROM `%s`', $targetTable, $sourceTable);
        if (!$mysqli->query($sqlInsert)) {
            throw new Exception($mysqli->error);
        }

        $mysqli->commit();
    } catch (Throwable $e) {
        $mysqli->rollback();
        throw $e;
    }
}

function zimmer_config_load_into_working(mysqli $mysqli, $sourceSlug) {
    if ($sourceSlug === '__working__' || $sourceSlug === '' || $sourceSlug === null) {
        return;
    }

    $sourceTable = zimmer_config_table_from_key($sourceSlug);
    if (!zimmer_config_table_exists($mysqli, $sourceTable)) {
        throw new Exception('Konfiguration nicht gefunden.');
    }

    $mysqli->begin_transaction();
    try {
        $sqlTruncate = sprintf('TRUNCATE TABLE `%s`', ZIMMER_WORKING_TABLE);
        if (!$mysqli->query($sqlTruncate)) {
            throw new Exception($mysqli->error);
        }

        $sqlInsert = sprintf('INSERT INTO `%s` SELECT * FROM `%s`', ZIMMER_WORKING_TABLE, $sourceTable);
        if (!$mysqli->query($sqlInsert)) {
            throw new Exception($mysqli->error);
        }

        $mysqli->commit();
    } catch (Throwable $e) {
        $mysqli->rollback();
        throw $e;
    }
}

function zimmer_config_delete(mysqli $mysqli, $slug) {
    if ($slug === '__working__' || $slug === '' || $slug === null) {
        throw new Exception('Die aktive Konfiguration kann nicht gelöscht werden.');
    }

    $table = zimmer_config_table_from_key($slug);
    if (!zimmer_config_table_exists($mysqli, $table)) {
        throw new Exception('Konfiguration nicht gefunden.');
    }

    $sql = sprintf('DROP TABLE `%s`', $table);
    if (!$mysqli->query($sql)) {
        throw new Exception($mysqli->error);
    }
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'config_list') {
    $configs = zimmer_config_list($mysqli);
    json_response(['success' => true, 'configs' => $configs]);
}

if ($action === 'config_create') {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }

    $targetSlug = zimmer_config_normalize_key($payload['name'] ?? '');
    if ($targetSlug === '' || $targetSlug === '__working__') {
        json_response(['success' => false, 'error' => 'Ungültiger Konfigurationsname.'], 422);
    }

    $sourceKeyRaw = $payload['source'] ?? '__working__';
    $sourceKey = zimmer_config_normalize_key($sourceKeyRaw);
    if ($sourceKey === '') {
        $sourceKey = '__working__';
    }

    $overwrite = !empty($payload['overwrite']);

    try {
        zimmer_config_create($mysqli, $targetSlug, $sourceKey, $overwrite);
    } catch (Throwable $e) {
        $code = ($e->getMessage() === 'Konfiguration existiert bereits.') ? 409 : 500;
        json_response(['success' => false, 'error' => $e->getMessage()], $code);
    }

    $table = zimmer_config_table_from_key($targetSlug);
    $configs = zimmer_config_list($mysqli);
    json_response([
        'success' => true,
        'created' => [
            'key' => $targetSlug,
            'table' => $table,
            'source' => $sourceKey,
            'overwrite' => (bool)$overwrite
        ],
        'configs' => $configs
    ]);
}

if ($action === 'config_load') {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }

    $slug = zimmer_config_normalize_key($payload['name'] ?? ($payload['key'] ?? ''));
    if ($slug === '' || $slug === '__working__') {
        json_response(['success' => false, 'error' => 'Bitte eine gespeicherte Konfiguration wählen.'], 422);
    }

    try {
        zimmer_config_load_into_working($mysqli, $slug);
    } catch (Throwable $e) {
        json_response(['success' => false, 'error' => $e->getMessage()], 500);
    }

    json_response(['success' => true, 'loaded' => $slug]);
}

if ($action === 'config_delete') {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }

    $slug = zimmer_config_normalize_key($payload['name'] ?? ($payload['key'] ?? ''));
    if ($slug === '' || $slug === '__working__') {
        json_response(['success' => false, 'error' => 'Diese Konfiguration kann nicht gelöscht werden.'], 422);
    }

    try {
        zimmer_config_delete($mysqli, $slug);
    } catch (Throwable $e) {
        json_response(['success' => false, 'error' => $e->getMessage()], 500);
    }

    $configs = zimmer_config_list($mysqli);
    json_response(['success' => true, 'deleted' => $slug, 'configs' => $configs]);
}

if ($action === 'list') {
    // Return all rooms and distinct categories
    $sql = 'SELECT id, caption, etage, kapazitaet, kategorie, col, px, py, visible, sort FROM zp_zimmer ORDER BY sort';
    $res = $mysqli->query($sql);
    if (!$res) {
        json_response(['success' => false, 'error' => $mysqli->error], 500);
    }
    $rows = [];
    $cats = [];
    while ($r = $res->fetch_assoc()) {
        $rows[] = [
            'id' => (int)$r['id'],
            'caption' => $r['caption'],
            'etage' => (int)$r['etage'],
            'kapazitaet' => (int)$r['kapazitaet'],
            'kategorie' => $r['kategorie'],
            'col' => $r['col'],
            'px' => (int)$r['px'],
            'py' => (int)$r['py'],
            'visible' => (int)$r['visible'],
            'sort' => (int)$r['sort'],
        ];
        if (!empty($r['kategorie'])) $cats[$r['kategorie']] = true;
    }
    $defaults = ['Standard','Einzel','Doppel','Suite','Lager','Gästehaus','Alles'];
    foreach ($defaults as $d) { $cats[$d] = true; }
    $categories = array_values(array_keys($cats));
    sort($categories, SORT_NATURAL | SORT_FLAG_CASE);
    json_response(['success' => true, 'data' => $rows, 'categories' => $categories]);
}

if ($action === 'save') {
    // Bulk save: expects JSON in request body
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        json_response(['success' => false, 'error' => 'Ungültige JSON-Daten'], 400);
    }
    $newRows = isset($payload['newRows']) && is_array($payload['newRows']) ? $payload['newRows'] : [];
    $updatedRows = isset($payload['updatedRows']) && is_array($payload['updatedRows']) ? $payload['updatedRows'] : [];
    $deletedIds = isset($payload['deletedIds']) && is_array($payload['deletedIds']) ? $payload['deletedIds'] : [];

    $mysqli->begin_transaction();
    try {
        // Deletes first
        $deletedCount = 0;
        if (!empty($deletedIds)) {
            // Prevent deletion of "Ablage"
            $placeholders = implode(',', array_fill(0, count($deletedIds), '?'));
            $stmt = $mysqli->prepare("SELECT id, caption FROM zp_zimmer WHERE id IN ($placeholders)");
            $types = str_repeat('i', count($deletedIds));
            $stmt->bind_param($types, ...$deletedIds);
            $stmt->execute();
            $res = $stmt->get_result();
            $blocked = [];
            $allow = [];
            while ($row = $res->fetch_assoc()) {
                if (isReadOnlyCaption($row['caption'])) { $blocked[] = (int)$row['id']; }
                else { $allow[] = (int)$row['id']; }
            }
            $stmt->close();
            if (!empty($allow)) {
                $ph = implode(',', array_fill(0, count($allow), '?'));
                $stmt = $mysqli->prepare("DELETE FROM zp_zimmer WHERE id IN ($ph)");
                $t = str_repeat('i', count($allow));
                $stmt->bind_param($t, ...$allow);
                $stmt->execute();
                $deletedCount = $stmt->affected_rows;
                $stmt->close();
            }
        }

        // Inserts
        $inserted = 0;
        if (!empty($newRows)) {
            $stmt = $mysqli->prepare("INSERT INTO zp_zimmer (caption, etage, kapazitaet, kategorie, col, px, py, visible, sort) VALUES (?,?,?,?,?,?,?,?,?)");
            foreach ($newRows as $r) {
                $s = sanitize_room($r);
                if (isReadOnlyCaption($s['caption'])) { throw new Exception('"Ablage" darf nicht neu angelegt werden.'); }
                $stmt->bind_param('siissiiii', $s['caption'], $s['etage'], $s['kapazitaet'], $s['kategorie'], $s['col'], $s['px'], $s['py'], $s['visible'], $s['sort']);
                if (!$stmt->execute()) throw new Exception($stmt->error);
                $inserted++;
            }
            $stmt->close();
        }

        // Updates
        $updated = 0;
        if (!empty($updatedRows)) {
            $stmt = $mysqli->prepare("UPDATE zp_zimmer SET caption=?, etage=?, kapazitaet=?, kategorie=?, col=?, px=?, py=?, visible=?, sort=? WHERE id=?");
            foreach ($updatedRows as $r) {
                $s = sanitize_room($r);
                if ($s['id'] <= 0) continue;
                // Enforce read-only for Ablage rows
                $chk = $mysqli->prepare('SELECT caption FROM zp_zimmer WHERE id=? LIMIT 1');
                $chk->bind_param('i', $s['id']);
                $chk->execute();
                $res = $chk->get_result();
                $row = $res->fetch_assoc();
                $chk->close();
                if ($row && isReadOnlyCaption($row['caption'])) {
                    // disallow editing
                    continue;
                }
                $stmt->bind_param('siissiiiii', $s['caption'], $s['etage'], $s['kapazitaet'], $s['kategorie'], $s['col'], $s['px'], $s['py'], $s['visible'], $s['sort'], $s['id']);
                if (!$stmt->execute()) throw new Exception($stmt->error);
                $updated += $stmt->affected_rows;
            }
            $stmt->close();
        }

    $mysqli->commit();
    json_response(['success' => true, 'inserted' => $inserted, 'updated' => $updated, 'deleted' => $deletedCount]);
    } catch (Throwable $e) {
        $mysqli->rollback();
        json_response(['success' => false, 'error' => $e->getMessage()], 500);
    }
}

// No action -> serve HTML UI
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Zimmerverwaltung</title>
<style>
    :root{
        --bg:#0f172a; --panel:#111827; --border:#1f2937; --text:#e5e7eb; --muted:#9ca3af;
        --primary:#3b82f6; --primary-600:#2563eb; --danger:#ef4444; --danger-600:#dc2626; --ok:#22c55e;
        --row-alt:#0b1220; --input:#0b1220; --chip:#1e293b;
    }
    *{box-sizing:border-box}
    body{margin:0;background:var(--bg);color:var(--text);font:14px/1.4 system-ui,Segoe UI,Roboto,Arial}
    header{display:flex;flex-wrap:wrap;gap:12px;align-items:center;padding:10px 12px;border-bottom:1px solid var(--border);background:var(--panel);position:sticky;top:0;z-index:2}
    header h1{font-size:16px;margin:0;font-weight:600}
    .spacer{flex:1}
    .btn{appearance:none;border:1px solid var(--border);background:linear-gradient(180deg,#1f2937,#0f172a);color:var(--text);padding:8px 12px;border-radius:8px;cursor:pointer}
    .btn:hover{border-color:#334155}
    .btn.primary{background:linear-gradient(180deg,var(--primary),var(--primary-600));border-color:#1d4ed8}
    .btn.danger{background:linear-gradient(180deg,var(--danger),var(--danger-600));border-color:#b91c1c}
    .toolbar{display:flex;gap:8px;align-items:center}
    .input{background:var(--input);border:1px solid var(--border);color:var(--text);border-radius:8px;padding:8px 10px}
    .config-controls{display:flex;gap:8px;align-items:center;flex-wrap:wrap;justify-content:flex-end;min-width:240px}
    .config-controls select{min-width:180px}
    .config-info{font-size:12px;color:var(--muted)}

    .wrap{display:grid;grid-template-columns: 1.2fr 1fr;gap:12px;padding:12px}
    .card{background:var(--panel);border:1px solid var(--border);border-radius:10px;overflow:hidden}
    .card h2{margin:0;padding:10px 12px;border-bottom:1px solid var(--border);font-size:14px}

    table{width:100%;border-collapse:separate;border-spacing:0}
    thead th{position:sticky;top:0;background:var(--panel);border-bottom:1px solid var(--border);padding:6px 6px;text-align:left;font-weight:600;z-index:1}
    tbody td{padding:4px 6px;border-bottom:1px solid var(--border)}
    tbody tr:nth-child(odd){background:var(--row-alt)}
    tbody tr.selected{outline:2px solid var(--primary)}
    td input, td select{width:100%;background:var(--input);color:var(--text);border:1px solid var(--border);border-radius:6px;padding:4px 6px}
    td input[type="checkbox"]{width:auto}
    td.color-cell{display:flex;gap:8px;align-items:center}
    .color-swatch{width:22px;height:22px;border-radius:4px;border:1px solid var(--border);cursor:pointer}

    /* Scrollable table body with sticky header */
    .table-wrap{ position:relative; max-height:60vh; overflow:auto; }
    .drag-indicator{ position:absolute; left:0; right:0; height:2px; background:var(--primary); box-shadow:0 0 0 1px #1d4ed8; pointer-events:none; z-index:5; display:none }
    .drag-col{ width:32px }
    .drag-handle{ background:none; border:none; color:#64748b; cursor:grab; font-size:16px; line-height:1; padding:0; width:24px; height:24px }
    .drag-handle:active{ cursor:grabbing }
    .vis-toggle{ background:none; border:1px solid #cbd5e1; border-radius:6px; width:34px; height:28px; display:inline-flex; align-items:center; justify-content:center; cursor:pointer }
    .vis-toggle.on{ color:#16a34a; border-color:#86efac; background:#f0fdf4 }
    .vis-toggle.off{ color:#b91c1c; border-color:#fecaca; background:#fef2f2 }

    canvas{display:block;width:100%;background:#0b1220}
    .status{padding:8px 12px;color:var(--muted)}
    .chips{display:none}
    .chip{display:none}

    /* Light theme just for the grid card to improve readability */
    #gridCard{ background:#f9fafb; color:#111827; border-color:#e5e7eb }
    #gridCard h2{ border-color:#e5e7eb }
    #gridCard table thead th{ background:#f3f4f6; border-bottom:1px solid #e5e7eb; color:#0f172a }
    #gridCard table tbody td{ border-bottom:1px solid #e5e7eb }
    #gridCard table tbody tr:nth-child(odd){ background:#ffffff }
    #gridCard table tbody tr:nth-child(even){ background:#f8fafc }
    #gridCard td input, #gridCard td select{ background:#ffffff; color:#111827; border:1px solid #cbd5e1 }
    #gridCard td input[type=number]{ min-width:84px; font-size:14px }
    #gridCard td select{ min-width:120px }
    /* Light theme for preview card */
    #previewCard{ background:#f9fafb; color:#111827; border-color:#e5e7eb }
    #previewCard h2{ border-color:#e5e7eb }
    #previewCard canvas{ background:#ffffff; border-top:1px solid #e5e7eb }
    /* Hide ID column explicitly */
    #grid th[data-col="id"], #grid td[data-col="id"]{ display:none }
    /* Color palette popover */
    .color-palette{ position:fixed; background:#ffffff; color:#111827; border:1px solid #cbd5e1; box-shadow:0 10px 25px rgba(0,0,0,0.25); padding:8px; border-radius:10px; display:grid; grid-template-columns:repeat(6, 26px); gap:8px; z-index:9999 }
    .color-palette button{ width:26px; height:26px; border-radius:6px; border:1px solid #cbd5e1; cursor:pointer }
    .color-palette button:focus{ outline:2px solid #2563eb }
    @media (max-width: 1100px){ .wrap{grid-template-columns: 1fr} }
</style>
</head>
<body>
<header>
    <h1>Zimmerverwaltung</h1>
    <div class="toolbar">
        <button id="btnBack" class="btn">⬅️ Zurück</button>
        <button id="btnAdd" class="btn">➕ Neu</button>
        <button id="btnRemove" class="btn danger">🗑 Löschen</button>
        <button id="btnSave" class="btn primary">Anwenden</button>
        <input id="filter" class="input" placeholder="Filter (Bezeichnung &amp; Kategorie)…" />
    </div>
    <div class="spacer"></div>
    <div class="config-controls">
        <select id="configSelect" class="input">
            <option value="__working__">Lade Konfigurationen…</option>
        </select>
        <button id="btnConfigSaveAs" class="btn" title="Aktuelle Arbeitskopie als neue Konfiguration sichern">Speichern als…</button>
        <button id="btnConfigDelete" class="btn danger" title="Ausgewählte Konfiguration löschen">Löschen</button>
        <span id="configInfo" class="config-info"></span>
    </div>
</header>

<div class="wrap">
    <section class="card" id="gridCard">
        <h2>Zimmerliste</h2>
        <div class="status" id="status"></div>
        <div class="table-wrap" id="tableWrap">
            <table id="grid">
                <thead>
                    <tr>
                        <th class="drag-col"></th>
                        <th data-col="id" style="width:56px">ID</th>
                        <th>Bezeichnung</th>
                        <th style="width:70px">Etage</th>
                        <th style="width:90px">Kapazität</th>
                        <th style="width:140px">Kategorie</th>
                        <th style="width:110px">Farbe</th>
                        <th style="width:90px">Sichtbar</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
            <div id="dragIndicator" class="drag-indicator"></div>
        </div>
    <!-- Chips entfernt -->
    </section>

    <section class="card" id="previewCard">
        <h2>Vorschau / Layout</h2>
        <canvas id="preview" width="800" height="520"></canvas>
    </section>
</div>

<script>
(() => {
    const state = {
        rows: [], // full list
        filtered: [],
        selection: new Set(),
        categories: [],
        dirty: { inserted: [], updated: new Set(), deleted: new Set() },
        hitRects: [], // for hit-testing in draw order
        geom: null,   // cached geometry of A4 area
        ghost: null,  // ghost rectangle while dragging
        configs: [],
        selectedConfig: '__working__',
        loadedConfig: '__working__'
    };

    const els = {
        grid: document.getElementById('grid'),
        tbody: document.querySelector('#grid tbody'),
        status: document.getElementById('status'),
        chips: document.getElementById('chips'),
        filter: document.getElementById('filter'),
        btnAdd: document.getElementById('btnAdd'),
    btnBack: document.getElementById('btnBack'),
        btnRemove: document.getElementById('btnRemove'),
        btnSave: document.getElementById('btnSave'),
        canvas: document.getElementById('preview'),
        ctx: document.getElementById('preview').getContext('2d'),
        tableWrap: document.getElementById('tableWrap'),
        dragIndicator: document.getElementById('dragIndicator'),
    configSelect: document.getElementById('configSelect'),
    btnConfigSaveAs: document.getElementById('btnConfigSaveAs'),
    btnConfigDelete: document.getElementById('btnConfigDelete'),
        configInfo: document.getElementById('configInfo'),
    };

    // Fixed 5x6 color palette
    const PALETTE_COLORS = [
        '#fca5a5','#f87171','#ef4444','#dc2626','#b91c1c',
        '#fdba74','#fb923c','#f97316','#ea580c','#c2410c',
        '#fde047','#facc15','#eab308','#ca8a04','#a16207',
        '#86efac','#4ade80','#22c55e','#16a34a','#15803d',
        '#93c5fd','#60a5fa','#3b82f6','#2563eb','#1d4ed8',
        '#c4b5fd','#a78bfa','#8b5cf6','#7c3aed','#6d28d9'
    ];
    let paletteEl = null;
    function openColorPalette(anchor, onPick){
        console.debug('[Zimmereditor] openColorPalette()', { anchor, hasOnPick: !!onPick });
        if(!paletteEl){
            paletteEl = document.createElement('div');
            paletteEl.className = 'color-palette';
            // Build buttons once; always call the latest handler stored on the element
            PALETTE_COLORS.forEach(c => {
                const b = document.createElement('button');
                b.type = 'button'; b.style.background = c; b.title = c;
                b.addEventListener('click', (ev) => {
                    ev.stopPropagation();
                    const handler = paletteEl && paletteEl.__onPick;
                    console.debug('[Zimmereditor] palette pick', { hex: c, argb: toARGB(c), hasHandler: !!handler });
                    if (typeof handler === 'function') handler(c);
                    closePalette();
                });
                paletteEl.appendChild(b);
            });
            document.body.appendChild(paletteEl);
        }
        // Update handler to the current callback every time we open
        paletteEl.__onPick = onPick;
        const r = anchor.getBoundingClientRect();
        paletteEl.style.left = Math.round(r.left) + 'px';
        paletteEl.style.top = Math.round(r.bottom + 6) + 'px';
        paletteEl.style.display = 'grid';
        const onDoc = (ev) => { if(!paletteEl.contains(ev.target) && ev.target !== anchor){ closePalette(); } };
        requestAnimationFrame(()=>{ document.addEventListener('mousedown', onDoc, { once:true }); });
        function closePalette(){ if(paletteEl){ paletteEl.style.display='none'; } }
    }

    const API = {
        async list(){
            const res = await fetch(`?action=list&_=${Date.now()}`, {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'Cache-Control': 'no-cache', 'Pragma': 'no-cache' }
            });
            if(!res.ok) throw new Error('HTTP '+res.status);
            const j = await res.json();
            if(!j.success) throw new Error(j.error||'Fehler beim Laden');
            return j;
        },
        async save(payload){
            console.debug('[Zimmereditor] API.save payload', payload);
            const res = await fetch('?action=save', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify(payload)
            });
            const j = await res.json().catch(()=>({success:false,error:'Ungültige Antwort'}));
            console.debug('[Zimmereditor] API.save response', { status: res.status, json: j });
            if(!res.ok || !j.success) throw new Error(j.error || ('HTTP '+res.status));
            return j;
        },
        async configList(){
            const res = await fetch(`?action=config_list&_=${Date.now()}`, {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'Cache-Control': 'no-cache', 'Pragma': 'no-cache' }
            });
            if(!res.ok) throw new Error('HTTP '+res.status);
            const j = await res.json().catch(()=>({ success:false, error:'Ungültige Antwort' }));
            if(!j.success) throw new Error(j.error || ('HTTP '+res.status));
            return j;
        },
        async configCreate(payload){
            const res = await fetch('?action=config_create', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify(payload)
            });
            const j = await res.json().catch(()=>({ success:false, error:'Ungültige Antwort' }));
            if(!res.ok || !j.success) throw new Error(j.error || ('HTTP '+res.status));
            return j;
        },
        async configLoad(payload){
            const res = await fetch('?action=config_load', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify(payload)
            });
            const j = await res.json().catch(()=>({ success:false, error:'Ungültige Antwort' }));
            if(!res.ok || !j.success) throw new Error(j.error || ('HTTP '+res.status));
            return j;
        },
        async configDelete(payload){
            const res = await fetch('?action=config_delete', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify(payload)
            });
            const j = await res.json().catch(()=>({ success:false, error:'Ungültige Antwort' }));
            if(!res.ok || !j.success) throw new Error(j.error || ('HTTP '+res.status));
            return j;
        }
    };

    function setStatus(msg){ els.status.textContent = msg || ''; }

    function clearDirtyState(){
        state.dirty = { inserted: [], updated: new Set(), deleted: new Set() };
    }

    function hasUnsavedChanges(){
        return state.dirty.inserted.length > 0 || state.dirty.updated.size > 0 || state.dirty.deleted.size > 0;
    }

    function findConfigByKey(key){
        if(!Array.isArray(state.configs)) return null;
        return state.configs.find(cfg => cfg.key === key) || null;
    }

    function updateConfigSelect(nextKey){
        if(!els.configSelect) return;
        const configs = Array.isArray(state.configs) ? state.configs : [];
        let targetKey = nextKey;
        if(!targetKey || !configs.some(c => c.key === targetKey)) {
            if(configs.some(c => c.key === state.selectedConfig)) {
                targetKey = state.selectedConfig;
            } else if (configs.length > 0) {
                targetKey = configs[0].key;
            } else {
                targetKey = '__working__';
            }
        }
        state.selectedConfig = targetKey;
        els.configSelect.innerHTML = '';
        configs.forEach(cfg => {
            const opt = document.createElement('option');
            opt.value = cfg.key;
            const rowsSuffix = typeof cfg.rows === 'number' ? ` (${cfg.rows})` : '';
            opt.textContent = `${cfg.label}${rowsSuffix}`;
            if(cfg.key === targetKey) opt.selected = true;
            if(cfg.protected) opt.dataset.protected = '1';
            els.configSelect.appendChild(opt);
        });
        if(configs.length === 0){
            const opt = document.createElement('option');
            opt.value = '__working__';
            opt.textContent = 'Arbeitskopie';
            opt.selected = true;
            els.configSelect.appendChild(opt);
            state.selectedConfig = '__working__';
        }
        updateConfigControls();
    }

    function updateConfigControls(){
        const cfg = findConfigByKey(state.selectedConfig);
        const isProtected = !cfg || cfg.protected || cfg.key === '__working__';
        if(els.btnConfigDelete) els.btnConfigDelete.disabled = isProtected;
        updateConfigInfo();
    }

    function updateConfigInfo(){
        if(!els.configInfo) return;
        const key = state.loadedConfig || '__working__';
        const cfg = findConfigByKey(key);
        if(!cfg || key === '__working__'){
            els.configInfo.textContent = 'Arbeitskopie aktiv';
        } else {
            const rowsSuffix = typeof cfg.rows === 'number' ? ` (${cfg.rows})` : '';
            els.configInfo.textContent = `Aktiv: ${cfg.label}${rowsSuffix}`;
        }
    }

    async function refreshConfigs(preserveSelection = true){
        try{
            const data = await API.configList();
            state.configs = data.configs || [];
            let nextKey = '__working__';
            if(preserveSelection && state.selectedConfig && state.configs.some(c => c.key === state.selectedConfig)){
                nextKey = state.selectedConfig;
            } else if (state.configs.length > 0) {
                nextKey = state.configs[0].key;
            }
            updateConfigSelect(nextKey);
            updateConfigInfo();
        }catch(err){
            console.error('[Zimmereditor] refreshConfigs()', err);
            if(els.configInfo){
                els.configInfo.textContent = 'Konfigurationen konnten nicht geladen werden';
            }
        }
    }

    function renderChips(){}

    function applyFilter(){
        const q = (els.filter.value || '').toLowerCase().trim();
        if(!q) { state.filtered = [...state.rows]; return; }
        state.filtered = state.rows.filter(r => (r.caption||'').toLowerCase().includes(q) || (r.kategorie||'').toLowerCase().includes(q));
    }

    function renderTable(){
        applyFilter();
        els.tbody.innerHTML = '';
        const frag = document.createDocumentFragment();
        state.filtered.forEach(r => frag.appendChild(renderRow(r)));
        els.tbody.appendChild(frag);
        syncPreviewHeight();
        redrawCanvas();
    }

    function renderRow(r){
        const tr = document.createElement('tr');
        tr.dataset.id = String(r.id);
        if(state.selection.has(r.id)) tr.classList.add('selected');

        function cell(content){ const td = document.createElement('td'); td.appendChild(content); return td; }
        function input(val, type='text', width){ const el = document.createElement('input'); el.type=type; el.value = (val ?? ''); if(width) el.style.width=width; return el; }
        function select(options, val){ const s=document.createElement('select'); options.forEach(o=>{ const opt=document.createElement('option'); opt.value=o; opt.textContent=o; if(o===val) opt.selected=true; s.appendChild(opt);}); return s; }

        // Drag handle
        const dh = document.createElement('button');
        dh.type = 'button'; dh.className = 'drag-handle'; dh.textContent = '⠿'; dh.title = 'Ziehen zum Sortieren';
        // Disable dragging for Ablage
        const isAblage = String(r.caption).trim().toLowerCase() === 'ablage';
        if(isAblage) { dh.disabled = true; dh.style.opacity = '0.4'; }
        dh.addEventListener('mousedown', (ev) => startRowDrag(ev, r));
        const dragTd = document.createElement('td'); dragTd.className = 'drag-col'; dragTd.appendChild(dh);
        tr.appendChild(dragTd);

        // ID
        const idInput = input(r.id, 'number'); idInput.readOnly = true; idInput.style.background = '#111827';
        const idTd = cell(idInput); idTd.setAttribute('data-col','id');
        tr.appendChild(idTd);

        // Caption
        const cap = input(r.caption);
        cap.addEventListener('input', () => markUpdated(r, { caption: cap.value }));
        tr.appendChild(cell(cap));

        // Etage
        const et = input(r.etage, 'number'); et.min=0; et.addEventListener('input', () => markUpdated(r, { etage: toInt(et.value) }));
        tr.appendChild(cell(et));

        // Kapazität
        const ka = input(r.kapazitaet, 'number'); ka.min=0; ka.addEventListener('input', () => markUpdated(r, { kapazitaet: toInt(ka.value) }));
        tr.appendChild(cell(ka));

        // Kategorie
        const cat = select(state.categories, r.kategorie||'');
        cat.addEventListener('change', () => markUpdated(r, { kategorie: cat.value }));
        tr.appendChild(cell(cat));

        // Farbe
        const colorTd = document.createElement('td'); colorTd.className='color-cell';
        const sw = document.createElement('button'); sw.type='button'; sw.className='color-swatch'; sw.style.background = toRGB(r.col || '#FFDDDDDD'); sw.title='Farbe wählen';
        sw.addEventListener('click', (ev)=>{
            ev.stopPropagation();
            console.debug('[Zimmereditor] swatch click', { id: r.id, caption: r.caption, currentARGB: r.col, currentRGB: toRGB(r.col) });
            openColorPalette(sw, (c)=>{
                const nextARGB = toARGB(c);
                console.debug('[Zimmereditor] apply color to row', { id: r.id, caption: r.caption, pick: c, nextARGB });
                sw.style.background=c;
                markUpdated(r, { col: nextARGB });
            });
        });
        colorTd.appendChild(sw);
        tr.appendChild(colorTd);

        // Sichtbar (icon toggle)
        const visBtn = document.createElement('button');
        const setVisBtn = () => {
            const on = !!r.visible;
            visBtn.className = 'vis-toggle ' + (on ? 'on' : 'off');
            visBtn.title = on ? 'Sichtbar' : 'Ausgeblendet';
            visBtn.textContent = on ? '👁' : '✖';
        };
        setVisBtn();
        visBtn.addEventListener('click', (ev) => {
            ev.stopPropagation();
            // prevent changing for Ablage
            if (String(r.caption).trim().toLowerCase() === 'ablage') return;
            r.visible = r.visible ? 0 : 1;
            markUpdated(r, { visible: r.visible });
            setVisBtn();
        });
        tr.appendChild(cell(visBtn));

        // selection via click
        tr.addEventListener('click', (ev) => {
            if(ev.shiftKey || ev.ctrlKey || ev.metaKey){
                if(state.selection.has(r.id)) state.selection.delete(r.id); else state.selection.add(r.id);
            } else {
                state.selection.clear(); state.selection.add(r.id);
            }
            renderTable();
        });

        // Readonly styling for Ablage
        if (isAblage) {
            tr.style.opacity = 0.7;
            // Disable all inputs/selects to prevent editing of 'Ablage'
            setTimeout(() => {
                Array.from(tr.querySelectorAll('input, select')).forEach(el => { el.disabled = true; });
            }, 0);
        }

        return tr;
    }

    function normalizeColorForPicker(hex){
        // Accept #RRGGBB or #AARRGGBB; return #RRGGBB
        if(!hex) return '#ffffff';
        const h = hex.trim();
        if(/^#([0-9a-fA-F]{6})$/.test(h)) return h;
        if(/^#([0-9a-fA-F]{8})$/.test(h)) return '#' + h.slice(3);
        try{ return '#'+h.replace('#','').slice(-6); }catch{ return '#ffffff'; }
    }
    function normalizeSwatch(hex){ return normalizeColorForPicker(hex || '#ffffff'); }

    // Color helpers: save as ARGB (#AARRGGBB), display/draw as RGB (#RRGGBB)
    function toARGB(hex){
        if(!hex) return '#FFDDDDDD';
        let h = String(hex).trim();
        if(!h.startsWith('#')) h = '#' + h;
        const m3 = /^#([0-9a-fA-F]{3})$/.exec(h);
        if(m3){ const s = m3[1]; h = '#' + s[0]+s[0] + s[1]+s[1] + s[2]+s[2]; }
        if(/^#([0-9a-fA-F]{8})$/.test(h)) return h.toUpperCase();
        if(/^#([0-9a-fA-F]{6})$/.test(h)) return ('#FF' + h.slice(1)).toUpperCase();
        return '#FFDDDDDD';
    }
    function toRGB(hex){
        if(!hex) return '#DDDDDD';
        let h = String(hex).trim();
        if(!h.startsWith('#')) h = '#' + h;
        if(/^#([0-9a-fA-F]{8})$/.test(h)) return ('#' + h.slice(3)).toUpperCase();
        if(/^#([0-9a-fA-F]{6})$/.test(h)) return h.toUpperCase();
        return normalizeSwatch(h).toUpperCase();
    }

    function toInt(v){ const n = parseInt(v,10); return isFinite(n)?n:0; }

    function markUpdated(row, patch){
        const prev = { ...row };
        Object.assign(row, patch);
        if ('col' in patch) {
            try {
                console.debug('[Zimmereditor] markUpdated col', { id: row.id, caption: row.caption, prevARGB: prev.col, prevRGB: toRGB(prev.col), nextARGB: row.col, nextRGB: toRGB(row.col) });
            } catch(e) { /* no-op */ }
        }
        if(row.id && row.id > 0){ state.dirty.updated.add(row.id); }
        else {
            // new row present in inserted list
            const idx = state.dirty.inserted.findIndex(x => x.__uid === row.__uid);
            if(idx>=0){ Object.assign(state.dirty.inserted[idx], row); }
        }
        redrawCanvas();
    }

    // Drag & drop sorting within visible (filtered) rows
    let rowDrag = null;
    function startRowDrag(ev, row){
        ev.preventDefault();
        const filtered = state.filtered;
        const fromIdx = filtered.findIndex(r => r === row);
        if(fromIdx < 0) return;
        rowDrag = { rowId: row.id, fromIdx, toIdx: fromIdx };
        document.addEventListener('mousemove', onRowDragMove);
        document.addEventListener('mouseup', onRowDragEnd, { once: true });
        updateDragIndicator(fromIdx, 'init');
    }
    function onRowDragMove(ev){
        if(!rowDrag) return;
        const rows = Array.from(els.tbody.children);
        const wrapRect = els.tableWrap.getBoundingClientRect();
        const y = ev.clientY;
        let targetIdx = rows.length; // default to after last
        for(let i=0;i<rows.length;i++){
            const rr = rows[i].getBoundingClientRect();
            const mid = rr.top + rr.height/2;
            if(y < mid){ targetIdx = i; break; }
        }
        rowDrag.toIdx = targetIdx;
        updateDragIndicator(targetIdx);
        // auto-scroll
        const margin = 30;
        if(y < wrapRect.top + margin){ els.tableWrap.scrollTop -= 10; }
        else if(y > wrapRect.bottom - margin){ els.tableWrap.scrollTop += 10; }
    }
    function onRowDragEnd(){
        if(!rowDrag) return;
        els.dragIndicator.style.display = 'none';
        document.removeEventListener('mousemove', onRowDragMove);
        const { fromIdx, toIdx } = rowDrag;
        if(toIdx !== fromIdx){
            reorderWithinFiltered(fromIdx, toIdx);
        }
        rowDrag = null;
    }
    function updateDragIndicator(targetIdx){
        const rows = Array.from(els.tbody.children);
        if(rows.length === 0){ els.dragIndicator.style.display='none'; return; }
        let top;
        if(targetIdx <= 0){ top = rows[0].getBoundingClientRect().top; }
        else if(targetIdx >= rows.length){ const r = rows[rows.length-1].getBoundingClientRect(); top = r.bottom; }
        else { const r = rows[targetIdx].getBoundingClientRect(); top = r.top; }
        const wrapRect = els.tableWrap.getBoundingClientRect();
        els.dragIndicator.style.top = (top - wrapRect.top + els.tableWrap.scrollTop - 1) + 'px';
        els.dragIndicator.style.display = 'block';
    }
    function reorderWithinFiltered(fromIdx, toIdx){
        // Normalize target to be within [0, filtered.length]
        const filtered = [...state.filtered];
        const item = filtered.splice(fromIdx, 1)[0];
        const adjTo = toIdx > fromIdx ? (toIdx - 1) : toIdx; // dropping after removes one slot
        filtered.splice(adjTo, 0, item);
        // Positions of filtered rows in full rows array
        const positions = [];
        state.rows.forEach((r, idx) => { if(state.filtered.includes(r)) positions.push(idx); });
        // Build the reordered filtered sequence according to 'filtered'
        const reorderedFiltered = filtered;
        const newRows = [...state.rows];
        for(let i=0;i<positions.length;i++){
            newRows[positions[i]] = reorderedFiltered[i];
        }
        // Reassign sort across all rows in new order
        const isAblage = (r) => String(r.caption).trim().toLowerCase()==='ablage';
        const oldSortById = new Map(state.rows.map(r => [r.id, r.sort]));
        state.rows = newRows;
        state.rows.forEach((r, i) => {
            const newSort = i + 1;
            if(r.sort !== newSort){
                r.sort = newSort;
                if(!isAblage(r)) state.dirty.updated.add(r.id);
            }
        });
        renderTable();
    }

    function onFilterChange(){ renderTable(); }

    function addRow(){
        const maxSort = state.rows.reduce((m,r)=>Math.max(m, r.sort||0),0);
        const newRow = { id: 0, caption: 'Neues Zimmer', etage: 1, kapazitaet: 1, kategorie: '', col: '#FFDDDDDD', px: 1, py: 1, visible: 1, sort: maxSort+1, __uid: Math.random().toString(36).slice(2) };
        state.rows.push(newRow);
        state.dirty.inserted.push({...newRow});
        renderTable();
    }

    function removeSelected(){
        const ids = Array.from(state.selection).filter(id => id>0);
        if(ids.length===0){ alert('Bitte Zeilen auswählen.'); return; }
        // Prevent deleting Ablage
        const abl = state.rows.find(r => ids.includes(r.id) && String(r.caption).trim().toLowerCase()==='ablage');
        if(abl){ alert('Die Zeile "Ablage" kann nicht gelöscht werden.'); return; }
        if(!confirm('Ausgewählte Zeilen wirklich löschen?')) return;
        ids.forEach(id => state.dirty.deleted.add(id));
        state.rows = state.rows.filter(r => !ids.includes(r.id));
        state.selection.clear();
        renderTable();
    }

    async function onConfigSelectChange(){
        if(!els.configSelect) return;
        const nextKey = els.configSelect.value || '__working__';
        const previousSelected = state.selectedConfig;

        const restoreSelection = () => {
            state.selectedConfig = previousSelected;
            updateConfigSelect(previousSelected);
            updateConfigControls();
        };

        try{
            if(els.configSelect) els.configSelect.disabled = true;

            state.selectedConfig = previousSelected;
            updateConfigControls();

            const saved = await save();
            if(!saved){
                restoreSelection();
                return;
            }

            const persisted = await persistCurrentConfig();
            if(!persisted){
                restoreSelection();
                return;
            }

            state.selectedConfig = nextKey;
            updateConfigSelect(nextKey);
            updateConfigControls();

            await switchToConfig(nextKey);
        }catch(err){
            console.error('[Zimmereditor] onConfigSelectChange error', err);
            alert('Fehler beim Wechseln der Konfiguration: ' + (err && err.message ? err.message : err));
            restoreSelection();
        } finally {
            if(els.configSelect) els.configSelect.disabled = false;
            setTimeout(()=>setStatus(''), 1500);
        }
    }

    async function switchToConfig(targetKey){
        const key = targetKey || state.selectedConfig || '__working__';
        const previous = state.loadedConfig || '__working__';
        if(key === '__working__'){
            if(previous !== '__working__' || !state.rows.length){
                setStatus('Arbeitskopie wird geladen…');
                await load();
                await refreshConfigs(true);
            }
            state.loadedConfig = '__working__';
            updateConfigInfo();
            setStatus('Arbeitskopie aktiv.');
            return;
        }

        setStatus('Konfiguration wird geladen…');
        await API.configLoad({ name: key });
        await load();
        state.loadedConfig = key;
        updateConfigInfo();
        await refreshConfigs(true);
        setStatus('Konfiguration geladen.');
    }

    async function persistCurrentConfig(){
        const currentKey = state.loadedConfig || '__working__';
        if(currentKey === '__working__'){
            return true;
        }
        try{
            setStatus('Aktuelle Konfiguration wird gesichert…');
            const res = await API.configCreate({ name: currentKey, source: '__working__', overwrite: true });
            if(res.configs){
                state.configs = res.configs;
            }
            await refreshConfigs(true);
            updateConfigInfo();
            return true;
        }catch(err){
            console.error('[Zimmereditor] persistCurrentConfig error', err);
            alert('Aktuelle Konfiguration konnte nicht gespeichert werden: ' + (err && err.message ? err.message : err));
            return false;
        }
    }

    async function handleConfigSaveAs(){
        const suggested = state.selectedConfig !== '__working__' ? state.selectedConfig : '';
        const name = prompt('Name für neue Konfiguration:', suggested);
        if(!name){ return; }
        try{
            setStatus('Konfiguration wird gespeichert…');
            const res = await API.configCreate({ name, source: '__working__' });
            if(res.configs){
                state.configs = res.configs;
            }
            const createdKey = res.created && res.created.key ? res.created.key : null;
            if(createdKey){
                state.selectedConfig = createdKey;
            }
            updateConfigSelect(state.selectedConfig);
            updateConfigInfo();
            setStatus('Konfiguration gespeichert.');
        }catch(e){
            console.error(e);
            alert('Fehler beim Speichern der Konfiguration: '+e.message);
        } finally {
            setTimeout(()=>setStatus(''), 1500);
        }
    }


    async function handleConfigDelete(){
        const key = state.selectedConfig;
        if(!key || key === '__working__'){
            alert('Die Arbeitskopie kann nicht gelöscht werden.');
            return;
        }
        const cfg = findConfigByKey(key);
        const label = cfg ? cfg.label : key;
        if(!confirm(`Konfiguration "${label}" wirklich löschen?`)){
            return;
        }
        try{
            setStatus('Konfiguration wird gelöscht…');
            const res = await API.configDelete({ name: key });
            if(res.configs){
                state.configs = res.configs;
            } else {
                state.configs = state.configs.filter(c => c.key !== key);
            }
            if(state.loadedConfig === key){
                state.loadedConfig = '__working__';
            }
            state.selectedConfig = '__working__';
            updateConfigSelect(state.selectedConfig);
            updateConfigInfo();
            setStatus('Konfiguration gelöscht.');
        }catch(e){
            console.error(e);
            alert('Fehler beim Löschen: '+e.message);
        } finally {
            setTimeout(()=>setStatus(''), 1500);
        }
    }

    async function save(){
        try{
            setStatus('Speichere…');
            const newRows = state.dirty.inserted.map(r => sanitize(r));
            const updatedRows = state.rows.filter(r => state.dirty.updated.has(r.id)).map(r => sanitize(r));
            const deletedIds = Array.from(state.dirty.deleted);
            const payload = { newRows, updatedRows, deletedIds };
            console.debug('[Zimmereditor] save() payload', payload);
            console.time('[Zimmereditor] save:request');
            const res = await API.save(payload);
            console.timeEnd('[Zimmereditor] save:request');
            console.debug('[Zimmereditor] save() result', res);
            setStatus(`Gespeichert: +${res.inserted} / ~${res.updated} / -${res.deleted}`);
            // reload fresh from server after save
            clearDirtyState();
            await load();
            await refreshConfigs(true);
            updateConfigInfo();
            return true;
        }catch(e){
            console.error(e);
            alert('Fehler beim Speichern: '+e.message);
            return false;
        } finally {
            setStatus('');
        }
    }

    function sanitize(r){
        const out = { id: r.id|0, caption: String(r.caption||'').trim(), etage: toInt(r.etage), kapazitaet: toInt(r.kapazitaet), kategorie: String(r.kategorie||'').trim(), col: toARGB(r.col||'#FFDDDDDD'), px: Math.max(1,toInt(r.px)), py: Math.max(1,toInt(r.py)), visible: (r.visible?1:0), sort: toInt(r.sort) };
        try { console.debug('[Zimmereditor] sanitize()', { id: r.id, caption: r.caption, inCol: r.col, outARGB: out.col, outRGB: toRGB(out.col) }); } catch(e){}
        return out;
    }

    // Preview rendering (A4-like grid)
    function drawPreview(){
        const ctx = els.ctx; const canvas = els.canvas; const W = canvas.width; const H = canvas.height;
        ctx.clearRect(0,0,W,H);
        // Filtered visible rows (except Ablage)
        const rows = state.filtered.filter(r => !!r.visible && String(r.caption).trim().toLowerCase() !== 'ablage');
        state.hitRects = [];
        if(rows.length===0){ state.geom = null; return; }
        const maxX = Math.max(1, ...rows.map(r=>r.px||1));
        const maxY = Math.max(1, ...rows.map(r=>r.py||1));
        const a4Ratio = 210/297; // portrait
        let a4W, a4H; if (W/H > a4Ratio){ a4H = H-20; a4W = Math.floor(a4H*a4Ratio);} else { a4W = W-20; a4H = Math.floor(a4W/a4Ratio);} 
        const offX = Math.floor((W-a4W)/2), offY = Math.floor((H-a4H)/2);
        const cellW = a4W / maxX, cellH = a4H / maxY;
        state.geom = { W,H, offX, offY, a4W, a4H, cellW, cellH, maxX, maxY };
        // grid
        ctx.strokeStyle = '#1f2937'; ctx.lineWidth = 1;
        for(let i=0;i<=maxX;i++){ const x = offX + Math.round(i*cellW); ctx.beginPath(); ctx.moveTo(x, offY); ctx.lineTo(x, offY+a4H); ctx.stroke(); }
        for(let j=0;j<=maxY;j++){ const y = offY + Math.round(j*cellH); ctx.beginPath(); ctx.moveTo(offX, y); ctx.lineTo(offX+a4W, y); ctx.stroke(); }
        // groups by px,py
        const groups = new Map();
        rows.forEach(r => {
            const key = (r.px||1)+'_'+(r.py||1);
            if(!groups.has(key)) groups.set(key, []);
            groups.get(key).push(r);
        });
        groups.forEach((grp, key) => {
            const [ix,iy] = key.split('_').map(n=>parseInt(n,10));
            const cellX = offX + (ix-1)*cellW; const cellY = offY + (iy-1)*cellH;
            const baseH = Math.floor(cellH*0.8); let baseW = baseH*6; if(baseW>cellW) baseW = Math.floor(cellW*0.9);
            const vOv = Math.floor(baseH*0.3); const hOv = Math.floor(baseW*0.1);
            grp.forEach((r, idx) => {
                const x = Math.floor(cellX + (cellW-baseW)/2 + idx*hOv);
                const y = Math.floor(cellY + (cellH-baseH)/2 + idx*vOv);
                // color parse
                const col = toRGB(r.col || '#FFDDDDDD');
                ctx.fillStyle = col; ctx.strokeStyle = '#64748b'; ctx.lineWidth = 1;
                ctx.fillRect(x, y, baseW, baseH); ctx.strokeRect(x, y, baseW, baseH);
                ctx.fillStyle = '#111827'; ctx.font = '12px Segoe UI, system-ui'; ctx.textAlign='center'; ctx.textBaseline='middle';
                const label = (r.caption||'').toString();
                ctx.fillText(label, x+baseW/2, y+baseH/2, baseW-8);
                // attach hit area for dragging
                if(!r.__rects) r.__rects = []; r.__rects[0] = {x,y,w:baseW,h:baseH};
                state.hitRects.push({ row: r, x, y, w: baseW, h: baseH });
            });
        });
    }

    function drawGhost(){
        if(!state.ghost) return;
        const ctx = els.ctx;
        ctx.save();
        ctx.globalAlpha = 0.75;
        ctx.fillStyle = state.ghost.color || '#93c5fd';
        ctx.strokeStyle = '#1d4ed8';
        ctx.lineWidth = 2;
        ctx.setLineDash([6,4]);
        ctx.fillRect(state.ghost.x, state.ghost.y, state.ghost.w, state.ghost.h);
        ctx.strokeRect(state.ghost.x, state.ghost.y, state.ghost.w, state.ghost.h);
        ctx.setLineDash([]);
        ctx.fillStyle = '#0b1220';
        ctx.font = '12px Segoe UI, system-ui';
        ctx.textAlign='center'; ctx.textBaseline='middle';
        if (state.ghost.label) ctx.fillText(state.ghost.label, state.ghost.x+state.ghost.w/2, state.ghost.y+state.ghost.h/2, state.ghost.w-8);
        ctx.restore();
    }

    function redrawCanvas(){
        drawPreview();
        drawGhost();
    }

    function syncPreviewHeight(){
        try{
            const wrap = els.tableWrap;
            const canvas = els.canvas;
            if(!wrap || !canvas) return;
            // Zielhöhe: gleiche optische Höhe wie der Tabellenbereich (scrollbarer Body)
            const targetCSSHeight = wrap.getBoundingClientRect().height;
            // Set CSS height
            canvas.style.height = Math.max(280, Math.round(targetCSSHeight)) + 'px';
            // Match internal resolution to CSS px with devicePixelRatio
            const dpr = window.devicePixelRatio || 1;
            const rect = canvas.getBoundingClientRect();
            const needW = Math.max(1, Math.round(rect.width * dpr));
            const needH = Math.max(1, Math.round(rect.height * dpr));
            if(canvas.width !== needW || canvas.height !== needH){
                canvas.width = needW; canvas.height = needH;
            }
        }catch(e){ /* no-op */ }
    }

    // Simple drag within canvas to change px/py
    let dragInfo = null;
    els.canvas.addEventListener('mousedown', (e) => {
        const rect = els.canvas.getBoundingClientRect();
        const scaleX = els.canvas.width / rect.width;
        const scaleY = els.canvas.height / rect.height;
        const x = (e.clientX - rect.left) * scaleX, y = (e.clientY - rect.top) * scaleY;
        const hit = hitTest(x,y);
        if(hit){
            const rr = hit.row.__rects && hit.row.__rects[0] ? hit.row.__rects[0] : {x: x-60, y: y-20, w: 120, h: 40};
            dragInfo = { row: hit.row, startX: x, startY: y, offsetX: x-rr.x, offsetY: y-rr.y, fromPX: hit.row.px, fromPY: hit.row.py };
            state.ghost = { x: rr.x, y: rr.y, w: rr.w, h: rr.h, color: toRGB(hit.row.col||'#FF93C5FD'), label: hit.row.caption };
            redrawCanvas();
        }
    });
    window.addEventListener('mouseup', (e)=> {
        if(dragInfo && state.geom && state.ghost){
            const { offX, offY, cellW, cellH, maxX, maxY, a4W, a4H } = state.geom;
            const cx = state.ghost.x + state.ghost.w/2;
            const cy = state.ghost.y + state.ghost.h/2;
            let ix = Math.floor((cx - offX)/cellW) + 1;
            let iy = Math.floor((cy - offY)/cellH) + 1;
            // Detect intent to extend columns: ghost reached right border (within tolerance)
            const ghostRight = state.ghost.x + state.ghost.w;
            const gridRight = offX + a4W;
            const gridBottom = offY + a4H;
            const rightTolerance = Math.max(2, Math.round(cellW * 0.15));
            const bottomTolerance = Math.max(2, Math.round(cellH * 0.15));
            const atRightBorder = (ghostRight >= gridRight - rightTolerance);
            const ghostBottom = state.ghost.y + state.ghost.h;
            const atBottomBorder = (ghostBottom >= gridBottom - bottomTolerance);
            // Allow extending only by one column to avoid gaps
            if (atRightBorder && ix >= maxX) {
                ix = maxX + 1;
            } else {
                ix = Math.max(1, Math.min(maxX, ix));
            }
            if (atBottomBorder && iy >= maxY) {
                iy = maxY + 1;
            } else {
                iy = Math.max(1, Math.min(maxY, iy));
            }
            try { console.debug('[Zimmereditor] drop', { from: { px: dragInfo.fromPX, py: dragInfo.fromPY }, to: { ix, iy }, maxX, maxY, atRightBorder, atBottomBorder }); } catch(_) {}
            if(ix !== dragInfo.row.px || iy !== dragInfo.row.py){
                dragInfo.row.px = ix; dragInfo.row.py = iy; state.dirty.updated.add(dragInfo.row.id);
            }
        }
        dragInfo=null; state.ghost=null; redrawCanvas();
    });
    window.addEventListener('mousemove', (e) => {
        if(!dragInfo) return;
        const rect = els.canvas.getBoundingClientRect();
        const scaleX = els.canvas.width / rect.width;
        const scaleY = els.canvas.height / rect.height;
        const x = (e.clientX - rect.left) * scaleX, y = (e.clientY - rect.top) * scaleY;
        if(!state.geom) return;
        const { offX, offY, a4W, a4H } = state.geom;
        const nx = Math.max(offX, Math.min(offX + a4W - (state.ghost?.w||0), x - dragInfo.offsetX));
        const ny = Math.max(offY, Math.min(offY + a4H - (state.ghost?.h||0), y - dragInfo.offsetY));
        if(state.ghost){ state.ghost.x = nx; state.ghost.y = ny; }
        redrawCanvas();
    });

    function hitTest(x,y){
        // Hit test topmost-first; slightly inflate rectangles for easier grabbing
        const INF = 2;
        for(let i = state.hitRects.length - 1; i >= 0; i--){
            const r = state.hitRects[i];
            const rx = r.x - INF, ry = r.y - INF, rw = r.w + 2*INF, rh = r.h + 2*INF;
            if(x>=rx && x<=rx+rw && y>=ry && y<=ry+rh) return { row: r.row };
        }
        return null;
    }

    // wire buttons and filter
    els.filter.addEventListener('input', onFilterChange);
    els.btnAdd.addEventListener('click', addRow);
    els.btnBack.addEventListener('click', ()=>{ if (document.referrer) { history.back(); } else { window.location.href = '../timeline-unified.html'; } });
    els.btnRemove.addEventListener('click', removeSelected);
    els.btnSave.addEventListener('click', save);
    if(els.configSelect) els.configSelect.addEventListener('change', () => { onConfigSelectChange(); });
    if(els.btnConfigSaveAs) els.btnConfigSaveAs.addEventListener('click', handleConfigSaveAs);
    if(els.btnConfigDelete) els.btnConfigDelete.addEventListener('click', handleConfigDelete);

    window.addEventListener('resize', () => { syncPreviewHeight(); redrawCanvas(); });

    async function load(){
        setStatus('Lade Daten…');
        const data = await API.list();
        console.debug('[Zimmereditor] load() data', data);
    state.rows = (data.data || []).map(r => ({ ...r }));
        state.filtered = [...state.rows];
        state.categories = data.categories || [];
        state.selection = new Set();
        clearDirtyState();
        renderChips();
        renderTable();
        syncPreviewHeight();
        redrawCanvas();
        updateConfigInfo();
        setStatus('');
    }

    // bootstrap
    // Expose state for quick inspection
    async function bootstrap(){
        await refreshConfigs(false);
        await load();
    }

    Object.defineProperty(window, '__zimmerState', { get(){ return state; } });
    bootstrap().catch(err => { console.error(err); setStatus('Fehler beim Laden: '+err.message); });
})();
</script>
</body>
</html>
