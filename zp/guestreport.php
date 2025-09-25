<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function h(?string $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function parseDateTime(?string $value): ?DateTimeImmutable
{
    if (!$value) {
        return null;
    }
    $value = trim($value);
    $formats = ['Y-m-d H:i:s', 'Y-m-d'];
    foreach ($formats as $format) {
        $dt = DateTimeImmutable::createFromFormat($format, $value);
        if ($dt instanceof DateTimeImmutable) {
            return $dt;
        }
    }
    $timestamp = strtotime($value);
    return $timestamp !== false ? (new DateTimeImmutable())->setTimestamp($timestamp) : null;
}

function formatLongGerman(DateTimeImmutable $date): string
{
    $weekdayNames = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
    $weekday = $weekdayNames[(int)$date->format('w')];
    return $weekday . ', ' . $date->format('d.m.Y');
}

function getArrangementKey(array $reservation): string
{
    $label = trim((string)($reservation['arrangement_label'] ?? ''));
    if ($label !== '') {
        return $label;
    }
    $text = trim((string)($reservation['arrangement_text'] ?? ''));
    if ($text !== '') {
        return $text;
    }
    return 'n/a';
}

$reportDateParam = $_GET['date'] ?? date('Y-m-d');
$reportDate = parseDateTime($reportDateParam) ?? new DateTimeImmutable('today');
$reportDate = $reportDate->setTime(0, 0, 0);

$daysBefore = 7;
$daysAfter = 7;
$timelineStart = $reportDate->modify('-' . $daysBefore . ' day');
$timelineEnd = $reportDate->modify('+' . $daysAfter . ' day');

$nextTwoWeeksEnd = $reportDate->modify('+13 day');

try {
    if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
        throw new RuntimeException('Keine gültige Datenbankverbindung verfügbar.');
    }
    if ($mysqli->connect_errno) {
        throw new RuntimeException('Datenbankfehler: ' . $mysqli->connect_error);
    }

    $sql = <<<SQL
        SELECT
            r.id,
            r.anreise,
            r.abreise,
            r.nachname,
            r.vorname,
            r.lager,
            r.betten,
            r.dz,
            r.sonder,
            arr.kbez AS arrangement_label,
            arr.bez  AS arrangement_text
        FROM `AV-Res` r
        LEFT JOIN arr ON r.arr = arr.ID
        WHERE r.anreise <= ?
          AND r.abreise >= ?
          AND (r.storno IS NULL OR r.storno = 0)
        ORDER BY r.anreise ASC, r.nachname ASC
    SQL;

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Vorbereitung der Reservierungsabfrage fehlgeschlagen: ' . $mysqli->error);
    }

    $stmt->bind_param(
        'ss',
        $timelineEnd->format('Y-m-d 23:59:59'),
        $timelineStart->format('Y-m-d 00:00:00')
    );

    if (!$stmt->execute()) {
        throw new RuntimeException('Reservierungen konnten nicht geladen werden: ' . $stmt->error);
    }

    $result = $stmt->get_result();
    $reservations = [];
    while ($row = $result->fetch_assoc()) {
        $start = parseDateTime($row['anreise']);
        $end = parseDateTime($row['abreise']);
        if (!$start || !$end) {
            continue;
        }
        $start = $start->setTime(0, 0, 0);
        $end = $end->setTime(0, 0, 0);

        $guestName = trim(($row['nachname'] ?? '') . ' ' . ($row['vorname'] ?? ''));
        if ($guestName === '') {
            $guestName = 'Unbekannt';
        }

        $totalGuests = (int)($row['lager'] ?? 0)
            + (int)($row['betten'] ?? 0)
            + (int)($row['dz'] ?? 0)
            + (int)($row['sonder'] ?? 0);
        if ($totalGuests <= 0) {
            $totalGuests = 1;
        }

        $reservations[] = [
            'id' => (int)$row['id'],
            'start' => $start,
            'end' => $end,
            'guest_name' => $guestName,
            'guest_count' => $totalGuests,
            'arrangement_label' => $row['arrangement_label'] ?? '',
            'arrangement_text' => $row['arrangement_text'] ?? '',
        ];
    }
    $stmt->close();

    if (empty($reservations)) {
        $pageTitle = 'Gästebericht – keine Daten';
        $errorMessage = 'Für den gewählten Zeitraum sind keine aktiven Aufenthalte vorhanden.';
    } else {
        $pageTitle = 'Gästebericht für ' . formatLongGerman($reportDate);
    }

    // Arrangement-Farbpalette
    $basePalette = [
        '#1f78ff', '#ef4444', '#0ea5e9', '#f97316', '#22c55e', '#8b5cf6',
        '#ec4899', '#14b8a6', '#f59e0b', '#6366f1', '#84cc16', '#a855f7'
    ];
    $arrangementColors = [];
    $colorIndex = 0;

    foreach ($reservations as $reservation) {
        $key = getArrangementKey($reservation);
        if (!isset($arrangementColors[$key])) {
            $arrangementColors[$key] = $basePalette[$colorIndex % count($basePalette)];
            $colorIndex++;
        }
    }

    $timelineDays = [];
    $current = $timelineStart;
    while ($current <= $timelineEnd) {
        $timelineDays[] = $current;
        $current = $current->modify('+1 day');
    }

    $twoWeekDays = [];
    $current = $reportDate;
    while ($current <= $nextTwoWeeksEnd) {
        $twoWeekDays[] = $current;
        $current = $current->modify('+1 day');
    }

    $twoWeekTable = [];
    foreach ($twoWeekDays as $day) {
        $row = [
            'date' => $day,
            'byArrangement' => [],
            'total' => 0,
        ];
        foreach ($reservations as $reservation) {
            if ($reservation['start'] <= $day && $reservation['end'] > $day) {
                $key = getArrangementKey($reservation);
                $row['byArrangement'][$key] = ($row['byArrangement'][$key] ?? 0) + $reservation['guest_count'];
                $row['total'] += $reservation['guest_count'];
            }
        }
        $twoWeekTable[] = $row;
    }

    $uniqueArrangements = array_keys($arrangementColors);
    sort($uniqueArrangements, SORT_NATURAL | SORT_FLAG_CASE);

} catch (Throwable $error) {
    http_response_code(500);
    $pageTitle = 'Gästebericht – Fehler';
    $errorMessage = $error->getMessage();
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pageTitle) ?></title>
    <style>
        :root {
            color-scheme: light dark;
            font-family: 'Inter', 'Segoe UI', system-ui, sans-serif;
            font-size: 14px;
            --bg: #0f172a;
            --surface: rgba(15,23,42,0.72);
            --surface-alt: rgba(15,23,42,0.55);
            --grid: rgba(148,163,184,0.25);
            --text: #f8fafc;
            --text-muted: #cbd5f5;
            --today: rgba(253,224,71,0.3);
            --past: rgba(14,165,233,0.12);
            --future: rgba(248,113,113,0.12);
        }
        body {
            margin: 0;
            background: radial-gradient(circle at top, #1e293b 0%, #0f172a 60%);
            color: var(--text);
        }
        header {
            padding: clamp(24px, 4vw, 48px) clamp(20px, 8vw, 64px) clamp(18px, 3vw, 36px);
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        header h1 {
            margin: 0;
            font-size: clamp(2rem, 3vw, 2.6rem);
            font-weight: 600;
        }
        header .subtitle {
            color: var(--text-muted);
            font-size: 1rem;
        }
        header .actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 8px;
        }
        header button {
            padding: 10px 18px;
            border-radius: 999px;
            border: 1px solid rgba(248,250,252,0.35);
            background: rgba(248,250,252,0.08);
            color: var(--text);
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.15s ease, box-shadow 0.2s ease;
        }
        header button:hover {
            transform: translateY(-1px);
            box-shadow: 0 12px 26px rgba(15,23,42,0.35);
        }
        main {
            padding: clamp(16px, 4vw, 48px);
            display: flex;
            flex-direction: column;
            gap: 32px;
        }
        .panel {
            background: var(--surface);
            border-radius: 18px;
            box-shadow: 0 18px 40px rgba(15,23,42,0.45);
            overflow: hidden;
        }
        .panel header {
            background: rgba(15,23,42,0.35);
            border-bottom: 1px solid rgba(148,163,184,0.2);
            padding: 18px 24px;
        }
        .panel header h2 {
            margin: 0;
            font-size: 1.2rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }
        .panel .content {
            padding: 18px clamp(12px, 3vw, 32px) 24px;
        }

        .timeline-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            font-size: 0.9rem;
        }
        .timeline-table thead th {
            text-align: center;
            padding: 12px 6px;
            color: var(--text-muted);
            border-bottom: 1px solid var(--grid);
        }
        .timeline-table tbody td {
            border-bottom: 1px solid rgba(148,163,184,0.16);
            padding: 6px 6px;
            position: relative;
        }
        .timeline-table tbody tr:last-child td {
            border-bottom: none;
        }
        .guest-cell {
            min-width: 180px;
            max-width: 240px;
            text-align: left;
            font-weight: 600;
        }
        .guest-meta {
            display: block;
            font-size: 0.75rem;
            font-weight: 400;
            color: var(--text-muted);
        }

        .day-cell {
            height: 44px;
            position: relative;
        }
        .day-cell::after {
            content: "";
            position: absolute;
            inset: 4px;
            border-radius: 8px;
            background: rgba(148,163,184,0.08);
            opacity: 0;
            transition: opacity 0.2s ease;
        }
        .day-cell:hover::after {
            opacity: 1;
        }
        .occupancy-bar {
            position: absolute;
            inset: 8px 6px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 600;
            color: #fff;
        }
        .day-past { background: var(--past); }
        .day-today { background: var(--today); }
        .day-future { background: var(--future); }

        .bar-label {
            padding: 2px 6px;
            border-radius: 999px;
            background: rgba(15,23,42,0.45);
            backdrop-filter: blur(4px);
            margin-left: 6px;
        }

        .legend {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 16px;
            color: var(--text-muted);
            font-size: 0.8rem;
        }
        .legend span {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(15,23,42,0.42);
            border-radius: 999px;
            padding: 4px 10px;
        }
        .legend .swatch {
            width: 16px;
            height: 16px;
            border-radius: 50%;
        }

        .two-week-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }
        .two-week-table thead th {
            padding: 12px;
            text-align: center;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.04em;
            border-bottom: 1px solid rgba(148,163,184,0.25);
        }
        .two-week-table tbody td {
            padding: 10px 12px;
            text-align: right;
            border-bottom: 1px solid rgba(148,163,184,0.15);
        }
        .two-week-table tbody tr:last-child td {
            border-bottom: none;
        }
        .two-week-table tbody td:first-child {
            text-align: left;
            color: var(--text-muted);
            font-weight: 600;
        }
        .two-week-table tbody tr:nth-child(2n) td {
            background: rgba(15,23,42,0.35);
        }

        @media print {
            body { background: #fff; color: #000; }
            header button { display: none; }
            .panel { box-shadow: none; background: #fff; }
            .panel header { background: none; border-bottom: 1px solid #ccc; }
            .legend span { background: none; border: 1px solid #ccc; }
        }
    </style>
</head>
<body>
<header>
    <h1><?= h($pageTitle) ?></h1>
    <div class="subtitle">Aufenthaltsübersicht mit Kontext vor und nach dem Stichtag</div>
    <div class="actions">
        <button type="button" onclick="window.print()">Drucken</button>
        <button type="button" onclick="history.back()">Zurück</button>
    </div>
</header>
<main>
    <?php if (isset($errorMessage)): ?>
        <div class="panel" style="max-width:560px;margin:auto;">
            <header><h2>Hinweis</h2></header>
            <div class="content">
                <p><?= h($errorMessage) ?></p>
            </div>
        </div>
    <?php else: ?>
        <section class="panel">
            <header><h2>Timeline ±7 Tage</h2></header>
            <div class="content">
                <table class="timeline-table">
                    <thead>
                        <tr>
                            <th class="guest-cell">Gast</th>
                            <?php foreach ($timelineDays as $day): ?>
                                <?php
                                    $classes = 'day-cell-header';
                                    if ($day < $reportDate) {
                                        $classes .= ' day-past';
                                    } elseif ($day > $reportDate) {
                                        $classes .= ' day-future';
                                    } else {
                                        $classes .= ' day-today';
                                    }
                                ?>
                                <th class="<?= h($classes) ?>">
                                    <div><?= h($day->format('D')) ?></div>
                                    <div><?= h($day->format('d.m.')) ?></div>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reservations as $reservation):
                            $arrKey = getArrangementKey($reservation);
                            $color = $arrangementColors[$arrKey] ?? '#334155';
                        ?>
                            <tr>
                                <td class="guest-cell">
                                    <?= h($reservation['guest_name']) ?>
                                    <span class="guest-meta">
                                        <?= h($reservation['guest_count']) ?> Personen · <?= h($arrKey) ?>
                                    </span>
                                </td>
                                <?php foreach ($timelineDays as $day):
                                    $classes = 'day-cell';
                                    if ($day < $reportDate) {
                                        $classes .= ' day-past';
                                    } elseif ($day > $reportDate) {
                                        $classes .= ' day-future';
                                    } else {
                                        $classes .= ' day-today';
                                    }

                                    $active = $reservation['start'] <= $day && $reservation['end'] > $day;
                                ?>
                                    <td class="<?= h($classes) ?>">
                                        <?php if ($active): ?>
                                            <div class="occupancy-bar" style="background: <?= h($color) ?>;">
                                                <?= h((string)$reservation['guest_count']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="legend">
                    <?php foreach ($arrangementColors as $label => $color): ?>
                        <span><span class="swatch" style="background: <?= h($color) ?>;"></span><?= h($label) ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="panel">
            <header><h2>Nächste 14 Tage – Kapazitäten pro Arrangement</h2></header>
            <div class="content">
                <table class="two-week-table">
                    <thead>
                        <tr>
                            <th>Datum</th>
                            <?php foreach ($uniqueArrangements as $label): ?>
                                <th><?= h($label) ?></th>
                            <?php endforeach; ?>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($twoWeekTable as $row):
                            $day = $row['date'];
                            $isWeekend = in_array($day->format('N'), ['6', '7'], true);
                        ?>
                            <tr<?= $isWeekend ? ' style="background: rgba(248,113,113,0.12);"' : '' ?>>
                                <td><?= h($day->format('D d.m.')) ?></td>
                                <?php foreach ($uniqueArrangements as $label): ?>
                                    <td><?= h((string)($row['byArrangement'][$label] ?? 0)) ?></td>
                                <?php endforeach; ?>
                                <td><?= h((string)$row['total']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
