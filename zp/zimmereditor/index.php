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
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
    :root{
        --bs-body-bg:#f8fafc;
        --bs-body-color:#0f172a;
        --bs-border-color:#e2e8f0;
        --bg:#f8fafc; --panel:#ffffff; --border:#e2e8f0; --text:#0f172a; --muted:#64748b;
        --primary:#3b82f6; --primary-600:#2563eb; --danger:#ef4444; --danger-600:#dc2626; --ok:#22c55e;
        --row-alt:#f1f5f9; --input:#ffffff; --chip:#e0e7ff;
    }
    body{background:var(--bg);color:var(--text);}
    
    /* Light theme overrides for Bootstrap */
    .card{background:var(--panel);border-color:var(--border);color:var(--text);box-shadow:0 1px 3px rgba(0,0,0,0.1);}
    .form-control, .form-select{background:var(--input);color:var(--text);border-color:var(--border);}
    .form-control:focus, .form-select:focus{background:var(--input);color:var(--text);border-color:var(--primary);box-shadow:0 0 0 0.25rem rgba(59,130,246,0.25);}
    .table{color:var(--text);}
    .table thead th{background:#f8fafc;border-color:var(--border);position:sticky;top:0;z-index:1;}
    .table tbody td{border-color:var(--border);}
    .table tbody tr:nth-child(odd){background:#ffffff;}
    .table tbody tr:nth-child(even){background:var(--row-alt);}
    .table tbody tr.table-active{background:rgba(59,130,246,0.15);outline:2px solid var(--primary);}
    .btn-primary{background:linear-gradient(180deg,var(--primary),var(--primary-600));border-color:#1d4ed8;color:#ffffff;}
    .btn-danger{background:linear-gradient(180deg,var(--danger),var(--danger-600));border-color:#b91c1c;color:#ffffff;}
    .btn-secondary{background:linear-gradient(180deg,#e2e8f0,#cbd5e1);border-color:#94a3b8;color:#0f172a;}
    .btn-secondary:hover{border-color:#64748b;background:linear-gradient(180deg,#cbd5e1,#94a3b8);}
    
    .navbar{background:var(--panel)!important;border-bottom:1px solid var(--border);box-shadow:0 1px 3px rgba(0,0,0,0.1);}
    .navbar-brand{color:var(--text)!important;font-size:16px;font-weight:600;}
    
    /* Custom styles */
    .table-wrap{max-height:60vh;overflow-y:auto;overflow-x:hidden;position:relative;}
    #grid{table-layout:fixed;width:100%;}
    #grid th, #grid td{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
    #grid input, #grid select{font-size:13px;padding:2px 4px;}
    .drag-col{width:32px;min-width:32px;}
    .drag-handle{background:none;border:none;color:#64748b;cursor:grab;font-size:16px;padding:0;width:24px;height:24px;touch-action:none;user-select:none;}
    .drag-handle:active{cursor:grabbing;}
    .drag-handle:disabled{opacity:0.4;cursor:not-allowed;}
    .drag-indicator{position:absolute;left:0;right:0;height:2px;background:var(--primary);box-shadow:0 0 0 1px #1d4ed8;pointer-events:none;z-index:5;display:none;}
    
    .vis-toggle{background:none;border:1px solid #cbd5e1;border-radius:6px;width:34px;height:28px;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;}
    .vis-toggle.on{color:#16a34a;border-color:#86efac;background:#f0fdf4;}
    .vis-toggle.off{color:#b91c1c;border-color:#fecaca;background:#fef2f2;}
    
    .delete-btn{background:none;border:1px solid #fecaca;border-radius:6px;width:34px;height:28px;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;color:#dc2626;font-size:16px;transition:all 0.2s;}
    .delete-btn:hover{background:#fee;border-color:#dc2626;}
    .delete-btn:disabled{opacity:0.4;cursor:not-allowed;}
    
    .color-cell{display:flex;gap:8px;align-items:center;}
    .color-swatch{width:22px;height:22px;border-radius:4px;border:1px solid var(--border);cursor:pointer;}
    
    .color-palette{position:fixed;background:#ffffff;color:#111827;border:1px solid #cbd5e1;box-shadow:0 10px 25px rgba(0,0,0,0.25);padding:8px;border-radius:10px;display:grid;grid-template-columns:repeat(6, 26px);gap:8px;z-index:9999;}
    .color-palette button{width:26px;height:26px;border-radius:6px;border:1px solid #cbd5e1;cursor:pointer;}
    .color-palette button:focus{outline:2px solid #2563eb;}
    
    #preview{display:block;width:100%;background:#1e293b;}
    input[readonly]{background:#f3f4f6!important;color:#6b7280!important;}
    
    /* Preview card lighter canvas */
    #previewCard .card-body{background:#ffffff!important;color:#111827;padding:0.5rem!important;}
    
    /* Config info styling */
    .config-info{font-size:12px;color:var(--muted);}
    
    /* Hide ID column */
    #grid th[data-col="id"], #grid td[data-col="id"]{display:none;}

    canvas{display:block;width:100%;background:#0b1220;touch-action:none;user-select:none;}
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
</style>
</head>
<body>
<nav class="navbar navbar-dark sticky-top">
    <div class="container-fluid">
        <span class="navbar-brand">Zimmerverwaltung</span>
        <div class="d-flex gap-2 align-items-center flex-wrap w-100">
            <button id="btnAdd" class="btn btn-secondary btn-sm">➕ Neu</button>
            <button id="btnSave" class="btn btn-primary btn-sm">Anwenden</button>
            <input id="filter" class="form-control form-control-sm" style="max-width:300px" placeholder="Filter (Bezeichnung & Kategorie)…" />
            <div class="flex-grow-1"></div>
            <select id="configSelect" class="form-select form-select-sm" style="width:auto;min-width:200px;">
                <option value="__working__">Lade Konfigurationen…</option>
            </select>
            <button id="btnConfigSaveAs" class="btn btn-secondary btn-sm" title="Aktuelle Arbeitskopie als neue Konfiguration sichern">Speichern als…</button>
            <button id="btnConfigDelete" class="btn btn-danger btn-sm" title="Ausgewählte Konfiguration löschen">Löschen</button>
            <button id="btnClose" class="btn btn-secondary btn-sm" title="Fenster schließen">✖ Fenster schließen</button>
            <span id="configInfo" class="config-info ms-2"></span>
        </div>
    </div>
</nav>

<div class="container-fluid mt-3">
    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card" id="gridCard">
                <div class="card-header">Zimmerliste</div>
                <div class="card-body p-0">
                    <div id="status"></div>
                    <div class="table-wrap" id="tableWrap">
                        <table class="table table-sm mb-0" id="grid">
                            <thead>
                                <tr>
                                    <th class="drag-col"></th>
                                    <th data-col="id" style="width:56px">ID</th>
                                    <th>Bezeichnung</th>
                                    <th style="width:50px">Etage</th>
                                    <th style="width:50px">Kap.</th>
                                    <th style="width:130px">Kategorie</th>
                                    <th style="width:80px">Farbe</th>
                                    <th style="width:70px">Sicht.</th>
                                    <th style="width:50px">Akt.</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                        <div id="dragIndicator" class="drag-indicator"></div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-5">
            <div class="card" id="previewCard">
                <div class="card-header">Vorschau / Layout</div>
                <div class="card-body p-2">
                    <canvas id="preview" width="800" height="520"></canvas>
                </div>
            </div>
        </div>
    </div>
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
        filter: document.getElementById('filter'),
        btnAdd: document.getElementById('btnAdd'),
        btnSave: document.getElementById('btnSave'),
        canvas: document.getElementById('preview'),
        ctx: document.getElementById('preview').getContext('2d'),
        tableWrap: document.getElementById('tableWrap'),
        dragIndicator: document.getElementById('dragIndicator'),
        configSelect: document.getElementById('configSelect'),
        btnConfigSaveAs: document.getElementById('btnConfigSaveAs'),
        btnConfigDelete: document.getElementById('btnConfigDelete'),
        configInfo: document.getElementById('configInfo'),
        btnClose: document.getElementById('btnClose'),
    };

    function normalizeConfigKey(name){
        if(!name) return '';
        const slug = String(name)
            .trim()
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '_')
            .replace(/^_+|_+$/g, '');
        if(!slug || slug === 'zimmer') return '';
        return slug;
    }

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
        if(state.selection.has(r.id)) tr.classList.add('table-active');

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
        dh.addEventListener('touchstart', (ev) => startRowDrag(ev, r), { passive: false });
        const dragTd = document.createElement('td'); dragTd.className = 'drag-col'; dragTd.appendChild(dh);
        tr.appendChild(dragTd);

        // ID
        const idInput = input(r.id, 'number'); idInput.readOnly = true;
        const idTd = cell(idInput); idTd.setAttribute('data-col','id');
        tr.appendChild(idTd);

        // Caption
        const cap = input(r.caption);
        cap.style.minWidth = '120px';
        if (!isAblage) {
            cap.addEventListener('input', () => markUpdated(r, { caption: cap.value }));
        }
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

        // Löschen Button
        const deleteBtn = document.createElement('button');
        deleteBtn.type = 'button';
        deleteBtn.className = 'delete-btn';
        deleteBtn.textContent = '🗑';
        deleteBtn.title = 'Zimmer löschen';
        if (isAblage) {
            deleteBtn.disabled = true;
            deleteBtn.title = 'Ablage kann nicht gelöscht werden';
        }
        deleteBtn.addEventListener('click', async (ev) => {
            ev.stopPropagation();
            if (isAblage) return;
            
            const roomName = r.caption || 'Zimmer ' + r.id;
            if (await ModalHelper.confirm(`Soll das Zimmer "${roomName}" wirklich gelöscht werden?`)) {
                removeRoom(r);
            }
        });
        tr.appendChild(cell(deleteBtn));

        // selection via click
        tr.addEventListener('click', (ev) => {
            // Ignore clicks on input/select/button elements to allow editing
            if (ev.target.tagName === 'INPUT' || ev.target.tagName === 'SELECT' || ev.target.tagName === 'BUTTON') {
                return;
            }
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
        
        // Add both mouse and touch event listeners
        document.addEventListener('mousemove', onRowDragMove);
        document.addEventListener('touchmove', onRowDragMove, { passive: false });
        document.addEventListener('mouseup', onRowDragEnd, { once: true });
        document.addEventListener('touchend', onRowDragEnd, { once: true });
        document.addEventListener('touchcancel', onRowDragEnd, { once: true });
        
        updateDragIndicator(fromIdx, 'init');
    }
    function onRowDragMove(ev){
        if(!rowDrag) return;
        
        // Support both mouse and touch events
        const y = ev.type.startsWith('touch') ? ev.touches[0].clientY : ev.clientY;
        
        // Prevent scrolling on touch devices while dragging
        if (ev.type.startsWith('touch')) {
            ev.preventDefault();
        }
        
        const rows = Array.from(els.tbody.children);
        const wrapRect = els.tableWrap.getBoundingClientRect();
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
        
        // Remove all event listeners
        document.removeEventListener('mousemove', onRowDragMove);
        document.removeEventListener('touchmove', onRowDragMove);
        document.removeEventListener('mouseup', onRowDragEnd);
        document.removeEventListener('touchend', onRowDragEnd);
        document.removeEventListener('touchcancel', onRowDragEnd);
        
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

    async function removeRoom(room){
        if(!room || !room.id) return;
        const id = room.id;
        // Prevent deleting Ablage
        if (String(room.caption).trim().toLowerCase() === 'ablage') {
            await ModalHelper.alert('Die Zeile "Ablage" kann nicht gelöscht werden.');
            return;
        }
        
        // Mark for deletion
        if (id > 0) {
            state.dirty.deleted.add(id);
        }
        // Remove from rows
        state.rows = state.rows.filter(r => r.id !== id);
        // Remove from selection if present
        state.selection.delete(id);
        renderTable();
    }

    async function removeSelected(){
        const ids = Array.from(state.selection).filter(id => id>0);
        if(ids.length===0){ await ModalHelper.alert('Bitte Zeilen auswählen.'); return; }
        // Prevent deleting Ablage
        const abl = state.rows.find(r => ids.includes(r.id) && String(r.caption).trim().toLowerCase()==='ablage');
        if(abl){ await ModalHelper.alert('Die Zeile "Ablage" kann nicht gelöscht werden.'); return; }
        if(!await ModalHelper.confirm('Ausgewählte Zeilen wirklich löschen?')) return;
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
            await ModalHelper.alert('Fehler beim Wechseln der Konfiguration: ' + (err && err.message ? err.message : err));
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
            await ModalHelper.alert('Aktuelle Konfiguration konnte nicht gespeichert werden: ' + (err && err.message ? err.message : err));
            return false;
        }
    }

    async function handleConfigSaveAs(){
        const suggestedKey = state.selectedConfig !== '__working__' ? state.selectedConfig : '';
        const suggestedConfig = findConfigByKey(suggestedKey);
        const defaultNewName = suggestedConfig && !suggestedConfig.protected ? `${suggestedConfig.label} Kopie` : '';
        const result = await ModalHelper.configSaveAs({
            configs: state.configs,
            suggestedKey,
            defaultNewName
        });
        if(!result){ return; }

        const targetName = result.name;
        const overwrite = result.overwrite === true;
        if(!targetName){ return; }

        try{
            setStatus(overwrite ? 'Konfiguration wird überschrieben…' : 'Konfiguration wird gespeichert…');
            const payload = { name: targetName, source: '__working__' };
            if(overwrite){ payload.overwrite = true; }
            const res = await API.configCreate(payload);
            if(res.configs){
                state.configs = res.configs;
            }
            let targetKey = overwrite ? targetName : (res.created && res.created.key) ? res.created.key : normalizeConfigKey(targetName);
            if(!targetKey){
                targetKey = state.selectedConfig || '__working__';
            }
            state.selectedConfig = targetKey;
            await refreshConfigs(true);
            setStatus(overwrite ? 'Konfiguration überschrieben.' : 'Konfiguration gespeichert.');
        }catch(e){
            console.error(e);
            await ModalHelper.alert('Fehler beim Speichern der Konfiguration: '+e.message);
        } finally {
            setTimeout(()=>setStatus(''), 1500);
        }
    }


    async function handleConfigDelete(){
        const key = state.selectedConfig;
        if(!key || key === '__working__'){
            await ModalHelper.alert('Die Arbeitskopie kann nicht gelöscht werden.');
            return;
        }
        const cfg = findConfigByKey(key);
        const label = cfg ? cfg.label : key;
        if(!await ModalHelper.confirm(`Konfiguration "${label}" wirklich löschen?`)){
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
            await ModalHelper.alert('Fehler beim Löschen: '+e.message);
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
            await ModalHelper.alert('Fehler beim Speichern: '+e.message);
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
    const ctx = els.ctx; const canvas = els.canvas; const W = canvas.width; const H = canvas.height; const dpr = window.devicePixelRatio || 1;
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
                
                // Responsive font size: derive from CSS pixel height and upscale for high-DPI canvases
                const isMobile = window.innerWidth < 768;
                const cssBoxHeight = baseH / dpr;
                const cssFontSize = isMobile
                    ? Math.max(cssBoxHeight * 0.6, 20)
                    : Math.max(cssBoxHeight * 0.45, 16);
                const fontSize = cssFontSize * dpr;

                ctx.fillStyle = '#111827'; 
                ctx.font = `600 ${fontSize}px "Inter", "Segoe UI", Arial, sans-serif`; 
                ctx.textAlign='center'; 
                ctx.textBaseline='middle';
                
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

        // Responsive font size for ghost: match room sizing and account for high-DPI displays
        const dpr = window.devicePixelRatio || 1;
        const isMobile = window.innerWidth < 768;
        const cssGhostHeight = state.ghost.h / dpr;
        const cssFontSize = isMobile
            ? Math.max(cssGhostHeight * 0.6, 20)
            : Math.max(cssGhostHeight * 0.45, 16);
        const fontSize = cssFontSize * dpr;
        ctx.font = `600 ${fontSize}px "Inter", "Segoe UI", Arial, sans-serif`;
        
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
    
    // Helper function to get coordinates from mouse or touch event
    function getEventCoords(e, rect) {
        const scaleX = els.canvas.width / rect.width;
        const scaleY = els.canvas.height / rect.height;
        const clientX = e.type.startsWith('touch') ? e.touches[0].clientX : e.clientX;
        const clientY = e.type.startsWith('touch') ? e.touches[0].clientY : e.clientY;
        return {
            x: (clientX - rect.left) * scaleX,
            y: (clientY - rect.top) * scaleY
        };
    }
    
    // Canvas mousedown/touchstart handler
    function onCanvasStart(e) {
        const rect = els.canvas.getBoundingClientRect();
        const {x, y} = getEventCoords(e, rect);
        const hit = hitTest(x,y);
        if(hit){
            if (e.type.startsWith('touch')) {
                e.preventDefault(); // Prevent scrolling while dragging
            }
            const rr = hit.row.__rects && hit.row.__rects[0] ? hit.row.__rects[0] : {x: x-60, y: y-20, w: 120, h: 40};
            dragInfo = { row: hit.row, startX: x, startY: y, offsetX: x-rr.x, offsetY: y-rr.y, fromPX: hit.row.px, fromPY: hit.row.py };
            state.ghost = { x: rr.x, y: rr.y, w: rr.w, h: rr.h, color: toRGB(hit.row.col||'#FF93C5FD'), label: hit.row.caption };
            redrawCanvas();
        }
    }
    
    els.canvas.addEventListener('mousedown', onCanvasStart);
    els.canvas.addEventListener('touchstart', onCanvasStart, { passive: false });
    
    // Canvas mouseup/touchend handler
    function onCanvasEnd(e) {
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
    }
    
    window.addEventListener('mouseup', onCanvasEnd);
    window.addEventListener('touchend', onCanvasEnd);
    window.addEventListener('touchcancel', onCanvasEnd);
    
    // Canvas mousemove/touchmove handler
    function onCanvasMove(e) {
        if(!dragInfo) return;
        
        if (e.type.startsWith('touch')) {
            e.preventDefault(); // Prevent scrolling while dragging
        }
        
        const rect = els.canvas.getBoundingClientRect();
        const {x, y} = getEventCoords(e, rect);
        if(!state.geom) return;
        const { offX, offY, a4W, a4H } = state.geom;
        const nx = Math.max(offX, Math.min(offX + a4W - (state.ghost?.w||0), x - dragInfo.offsetX));
        const ny = Math.max(offY, Math.min(offY + a4H - (state.ghost?.h||0), y - dragInfo.offsetY));
        if(state.ghost){ state.ghost.x = nx; state.ghost.y = ny; }
        redrawCanvas();
    }
    
    window.addEventListener('mousemove', onCanvasMove);
    window.addEventListener('touchmove', onCanvasMove, { passive: false });

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
    els.btnSave.addEventListener('click', save);
    if(els.configSelect) els.configSelect.addEventListener('change', () => { onConfigSelectChange(); });
    if(els.btnConfigSaveAs) els.btnConfigSaveAs.addEventListener('click', handleConfigSaveAs);
    if(els.btnConfigDelete) els.btnConfigDelete.addEventListener('click', handleConfigDelete);
    if(els.btnClose) els.btnClose.addEventListener('click', () => { window.close(); });

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

// Bootstrap 5 Modal Helpers
const ModalHelper = {
    alertModal: null,
    confirmModal: null,
    promptModal: null,
    saveAsModal: null,
    
    createAlertModal() {
        if (this.alertModal) return;
        const html = `
            <div class="modal fade" id="alertModal" tabindex="-1">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Hinweis</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body" id="alertModalBody"></div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
                        </div>
                    </div>
                </div>
            </div>`;
        document.body.insertAdjacentHTML('beforeend', html);
        this.alertModal = new bootstrap.Modal(document.getElementById('alertModal'));
    },
    
    createConfirmModal() {
        if (this.confirmModal) return;
        const html = `
            <div class="modal fade" id="confirmModal" tabindex="-1">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Bestätigung</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body" id="confirmModalBody"></div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" id="confirmCancel">Abbrechen</button>
                            <button type="button" class="btn btn-primary" id="confirmOk">OK</button>
                        </div>
                    </div>
                </div>
            </div>`;
        document.body.insertAdjacentHTML('beforeend', html);
        this.confirmModal = new bootstrap.Modal(document.getElementById('confirmModal'));
    },
    
    createPromptModal() {
        if (this.promptModal) return;
        const html = `
            <div class="modal fade" id="promptModal" tabindex="-1">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="promptModalTitle">Eingabe</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <label id="promptModalLabel" class="form-label"></label>
                            <input type="text" class="form-control" id="promptModalInput" />
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" id="promptCancel">Abbrechen</button>
                            <button type="button" class="btn btn-primary" id="promptOk">OK</button>
                        </div>
                    </div>
                </div>
            </div>`;
        document.body.insertAdjacentHTML('beforeend', html);
        this.promptModal = new bootstrap.Modal(document.getElementById('promptModal'));
    },

    createSaveAsModal() {
        if (this.saveAsModal) return;
        const html = `
            <div class="modal fade" id="saveAsModal" tabindex="-1">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Konfiguration speichern</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="radio" name="saveAsMode" id="saveAsModeNew" value="new">
                                <label class="form-check-label" for="saveAsModeNew">Neue Konfiguration anlegen</label>
                            </div>
                            <div class="ps-4 mb-3" id="saveAsNewWrap">
                                <input type="text" class="form-control" id="saveAsNewName" placeholder="Name eingeben…">
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="radio" name="saveAsMode" id="saveAsModeOverwrite" value="overwrite">
                                <label class="form-check-label" for="saveAsModeOverwrite">Bestehende Konfiguration überschreiben</label>
                            </div>
                            <div class="ps-4" id="saveAsExistingWrap">
                                <select class="form-select" id="saveAsExistingSelect"></select>
                            </div>
                            <div class="form-text text-danger mt-3" id="saveAsError" style="display:none"></div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" id="saveAsCancel">Abbrechen</button>
                            <button type="button" class="btn btn-primary" id="saveAsOk">Speichern</button>
                        </div>
                    </div>
                </div>
            </div>`;
        document.body.insertAdjacentHTML('beforeend', html);
        this.saveAsModal = new bootstrap.Modal(document.getElementById('saveAsModal'));
    },
    
    configSaveAs({ configs = [], suggestedKey = '', defaultNewName = '' } = {}) {
        return new Promise((resolve) => {
            this.createSaveAsModal();

            const modalEl = document.getElementById('saveAsModal');
            const radioNew = document.getElementById('saveAsModeNew');
            const radioOverwrite = document.getElementById('saveAsModeOverwrite');
            const newWrap = document.getElementById('saveAsNewWrap');
            const newInput = document.getElementById('saveAsNewName');
            const existingWrap = document.getElementById('saveAsExistingWrap');
            const existingSelect = document.getElementById('saveAsExistingSelect');
            const errorEl = document.getElementById('saveAsError');
            const btnOk = document.getElementById('saveAsOk');
            const btnCancel = document.getElementById('saveAsCancel');

            const availableConfigs = (configs || []).filter(cfg => cfg && cfg.key && cfg.key !== '__working__' && !cfg.protected);
            existingSelect.innerHTML = '';
            if(availableConfigs.length === 0){
                const opt = document.createElement('option');
                opt.value = '';
                opt.textContent = 'Keine gespeicherten Konfigurationen';
                opt.disabled = true;
                opt.selected = true;
                existingSelect.appendChild(opt);
            } else {
                availableConfigs.forEach(cfg => {
                    const opt = document.createElement('option');
                    opt.value = cfg.key;
                    const rowsSuffix = typeof cfg.rows === 'number' ? ` (${cfg.rows})` : '';
                    opt.textContent = `${cfg.label}${rowsSuffix}`;
                    existingSelect.appendChild(opt);
                });
            }

            const showError = (msg) => {
                if(msg){
                    errorEl.textContent = msg;
                    errorEl.style.display = 'block';
                } else {
                    errorEl.textContent = '';
                    errorEl.style.display = 'none';
                }
            };

            const hasOverwrite = availableConfigs.length > 0;
            radioOverwrite.disabled = !hasOverwrite;
            radioNew.disabled = false;

            if(hasOverwrite && availableConfigs.some(cfg => cfg.key === suggestedKey)){
                existingSelect.value = suggestedKey;
                radioOverwrite.checked = true;
                radioNew.checked = false;
            } else {
                radioNew.checked = true;
                radioOverwrite.checked = false;
                if(hasOverwrite){
                    existingSelect.value = availableConfigs[0].key;
                }
            }

            newInput.value = defaultNewName || '';

            const updateModeUI = () => {
                const useNew = radioOverwrite.disabled || radioNew.checked;
                radioNew.checked = useNew;
                radioOverwrite.checked = !useNew && !radioOverwrite.disabled;
                const isNew = radioNew.checked;
                newInput.disabled = !isNew;
                newWrap.classList.toggle('opacity-50', !isNew);
                existingSelect.disabled = isNew || !hasOverwrite;
                existingWrap.classList.toggle('opacity-50', existingSelect.disabled);
                showError('');
            };

            updateModeUI();

            let finished = false;
            const cleanup = () => {
                radioNew.removeEventListener('change', updateModeUI);
                radioOverwrite.removeEventListener('change', updateModeUI);
                btnOk.removeEventListener('click', handleOk);
                btnCancel.removeEventListener('click', handleCancel);
                modalEl.removeEventListener('hidden.bs.modal', handleHide);
                modalEl.removeEventListener('shown.bs.modal', handleShown);
                newInput.removeEventListener('keypress', handleEnter);
                existingSelect.removeEventListener('keypress', handleEnter);
            };

            const finish = (value) => {
                if(finished) return;
                finished = true;
                cleanup();
                resolve(value);
            };

            const handleOk = () => {
                const isNew = radioNew.checked || radioOverwrite.disabled;
                if(isNew){
                    const val = newInput.value.trim();
                    if(!val){
                        showError('Bitte einen Namen eingeben.');
                        newInput.focus();
                        return;
                    }
                    finish({ name: val, overwrite: false });
                    this.saveAsModal.hide();
                } else {
                    const val = existingSelect.value;
                    if(!val){
                        showError('Bitte eine Konfiguration auswählen.');
                        existingSelect.focus();
                        return;
                    }
                    finish({ name: val, overwrite: true });
                    this.saveAsModal.hide();
                }
            };

            const handleCancel = () => {
                finish(null);
                this.saveAsModal.hide();
            };

            const handleHide = () => {
                finish(null);
            };

            const handleShown = () => {
                const isNew = radioNew.checked || radioOverwrite.disabled;
                setTimeout(() => {
                    if(isNew){ newInput.focus(); } else { existingSelect.focus(); }
                }, 50);
            };

            const handleEnter = (e) => {
                if(e.key === 'Enter'){
                    e.preventDefault();
                    handleOk();
                }
            };

            radioNew.addEventListener('change', updateModeUI);
            radioOverwrite.addEventListener('change', updateModeUI);
            btnOk.addEventListener('click', handleOk);
            btnCancel.addEventListener('click', handleCancel);
            modalEl.addEventListener('hidden.bs.modal', handleHide, { once: false });
            modalEl.addEventListener('shown.bs.modal', handleShown, { once: false });
            newInput.addEventListener('keypress', handleEnter);
            existingSelect.addEventListener('keypress', handleEnter);

            this.saveAsModal.show();
        });
    },

    alert(message) {
        return new Promise((resolve) => {
            this.createAlertModal();
            document.getElementById('alertModalBody').textContent = message;
            this.alertModal.show();
            const handleHide = () => {
                document.getElementById('alertModal').removeEventListener('hidden.bs.modal', handleHide);
                resolve();
            };
            document.getElementById('alertModal').addEventListener('hidden.bs.modal', handleHide);
        });
    },
    
    confirm(message) {
        return new Promise((resolve) => {
            this.createConfirmModal();
            document.getElementById('confirmModalBody').textContent = message;
            
            const handleOk = () => {
                cleanup();
                this.confirmModal.hide();
                resolve(true);
            };
            const handleCancel = () => {
                cleanup();
                this.confirmModal.hide();
                resolve(false);
            };
            const handleHide = () => {
                cleanup();
                resolve(false);
            };
            
            const cleanup = () => {
                document.getElementById('confirmOk').removeEventListener('click', handleOk);
                document.getElementById('confirmCancel').removeEventListener('click', handleCancel);
                document.getElementById('confirmModal').removeEventListener('hidden.bs.modal', handleHide);
            };
            
            document.getElementById('confirmOk').addEventListener('click', handleOk);
            document.getElementById('confirmCancel').addEventListener('click', handleCancel);
            document.getElementById('confirmModal').addEventListener('hidden.bs.modal', handleHide);
            
            this.confirmModal.show();
        });
    },
    
    prompt(message, defaultValue = '') {
        return new Promise((resolve) => {
            this.createPromptModal();
            document.getElementById('promptModalLabel').textContent = message;
            document.getElementById('promptModalInput').value = defaultValue;
            
            const handleOk = () => {
                const value = document.getElementById('promptModalInput').value;
                cleanup();
                this.promptModal.hide();
                resolve(value);
            };
            const handleCancel = () => {
                cleanup();
                this.promptModal.hide();
                resolve(null);
            };
            const handleHide = () => {
                cleanup();
                resolve(null);
            };
            const handleEnter = (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    handleOk();
                }
            };
            
            const cleanup = () => {
                document.getElementById('promptOk').removeEventListener('click', handleOk);
                document.getElementById('promptCancel').removeEventListener('click', handleCancel);
                document.getElementById('promptModal').removeEventListener('hidden.bs.modal', handleHide);
                document.getElementById('promptModalInput').removeEventListener('keypress', handleEnter);
            };
            
            document.getElementById('promptOk').addEventListener('click', handleOk);
            document.getElementById('promptCancel').addEventListener('click', handleCancel);
            document.getElementById('promptModal').addEventListener('hidden.bs.modal', handleHide);
            document.getElementById('promptModalInput').addEventListener('keypress', handleEnter);
            
            this.promptModal.show();
            setTimeout(() => document.getElementById('promptModalInput').focus(), 100);
        });
    }
};
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
