<?php
header('Content-Type: text/html; charset=utf-8');
header('Access-Control-Allow-Origin: *');
require 'config.php';

$day = isset($_GET['day']) ? $_GET['day'] : date('Y-m-d');
$roomId = isset($_GET['room']) ? (int)$_GET['room'] : null;

function h($s){return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');}
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8" />
<title>Debug Tagesbelegung</title>
<style>
body{font-family:system-ui,Segoe UI,Arial,sans-serif;background:#111;color:#ddd;padding:16px}
code,pre{background:#1f2937;color:#e5e7eb;border:1px solid #374151;border-radius:6px;padding:8px}
.table{border-collapse:collapse;width:100%;margin:12px 0}
.table th,.table td{border:1px solid #374151;padding:6px 8px}
.bad{color:#fca5a5}
.good{color:#a7f3d0}
</style>
</head>
<body>
<h2>Debug Tagesbelegung — <?php echo h($day); ?></h2>
<p>Query: rd.von <= day AND rd.bis > day; storno=0</p>
<?php
$dayTs = strtotime($day);
if ($dayTs === false){ echo '<p class="bad">Ungültiges Datum</p>'; exit; }
$dayStr = date('Y-m-d', $dayTs);

// Zimmerliste
$rooms = [];
$res = $mysqli->query("SELECT id, caption, kapazitaet FROM zp_zimmer WHERE visible=1 ORDER BY sort, caption");
while ($row = $res->fetch_assoc()) { $rooms[(int)$row['id']] = $row; }

// Details für den Tag
$sql = "SELECT rd.id, rd.zimID, rd.resid, rd.anz, rd.von, rd.bis, r.storno
        FROM AV_ResDet rd
        JOIN `AV-Res` r ON rd.resid = r.id
        WHERE rd.von <= ? AND rd.bis > ? AND IFNULL(r.storno,0)=0";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param('ss', $dayStr, $dayStr);
$stmt->execute();
$det = $stmt->get_result();

$byRoom = [];
while ($d = $det->fetch_assoc()){
  $rid = (int)$d['zimID'];
  if ($roomId && $rid !== $roomId) continue;
  if (!isset($byRoom[$rid])) $byRoom[$rid] = [];
  $byRoom[$rid][] = $d;
}

echo '<table class="table"><tr><th>Zimmer</th><th>Kap</th><th>Belegt (Sum)</th><th>Details</th></tr>';
foreach ($byRoom as $rid => $list){
  $cap = isset($rooms[$rid]) ? (int)$rooms[$rid]['kapazitaet'] : 0;
  $cap = max(0,$cap);
  $sum = 0; $items = [];
  foreach ($list as $d){ $sum += (int)$d['anz']; $items[] = h($d['anz'].' von '.$d['von'].' bis '.$d['bis'].' (res '.$d['resid'].')'); }
  $frei = max(0, $cap - $sum);
  echo '<tr><td>'.h($rooms[$rid]['caption'] ?? ('#'.$rid)).'</td><td>'.$cap.'</td><td>'.$sum.'</td><td>'.implode('<br>', $items).'</td></tr>';
}
if (empty($byRoom)) echo '<tr><td colspan="4">Keine Belegung gefunden</td></tr>';
echo '</table>';
?>
</body></html>