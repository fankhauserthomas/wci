<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../vendor/fpdf/fpdf.php';

/**
 * Helper: HTML escape
 */
function h(?string $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Helper: parse database date/time values
 */
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

/**
 * Helper: German long date (e.g., Dienstag, 25.09.2025)
 */
function formatGermanLong(DateTimeImmutable $date): string
{
    $weekdayNames = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
    $weekday = $weekdayNames[(int)$date->format('w')];
    return $weekday . ', ' . $date->format('d.m.Y');
}

function ptToMm(float $pt): float
{
    return $pt * 25.4 / 72.0;
}

function pdfEncode(string $text): string
{
    $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $text);
    if ($converted === false) {
        $converted = @mb_convert_encoding($text, 'ISO-8859-1', 'UTF-8');
    }
    if ($converted === false) {
        return $text;
    }
    return $converted;
}

function pdfStringWidth(FPDF $pdf, string $text): float
{
    return $pdf->GetStringWidth(pdfEncode($text));
}

/**
 * Compute parent link message similar to legacy VB implementation
 */
function getParentLinkMessage(array $detail, DateTimeImmutable $planDate, array $detailsById, array $roomsById): string
{
    $linked = null;
    $parentId = (int)($detail['parent_id'] ?? 0);

    if ($parentId > 0 && isset($detailsById[$parentId])) {
        $linked = $detailsById[$parentId];
    } else {
        $detailId = (int)($detail['id'] ?? 0);
        if ($detailId > 0) {
            foreach ($detailsById as $candidate) {
                if ((int)($candidate['parent_id'] ?? 0) === $detailId) {
                    $linked = $candidate;
                    break;
                }
            }
        }
    }

    if (!$linked) {
        return '';
    }

    $detailStart = $detail['start'] ?? null;
    $detailEnd = $detail['end'] ?? null;
    $linkedStart = $linked['start'] ?? null;
    $linkedEnd = $linked['end'] ?? null;

    if (!$detailStart instanceof DateTimeImmutable || !$detailEnd instanceof DateTimeImmutable) {
        return '';
    }
    if (!$linkedStart instanceof DateTimeImmutable || !$linkedEnd instanceof DateTimeImmutable) {
        return '';
    }

    // Determine chronological order
    $first = $detailStart <= $linkedStart ? $detail : $linked;
    $second = $first === $detail ? $linked : $detail;

    $firstRoom = $roomsById[$first['room_id']]['caption'] ?? '';
    $secondRoom = $roomsById[$second['room_id']]['caption'] ?? '';

    $messages = [];

    // Message for later stay ("morgen" / "in X Tg")
    if ($second['start'] instanceof DateTimeImmutable) {
        $daysAhead = (int)floor(($second['start']->getTimestamp() - $planDate->getTimestamp()) / 86400);
        if ($daysAhead >= 1) {
            if ($daysAhead === 1) {
                $messages[] = 'morgen ' . $secondRoom;
            } else {
                $messages[] = 'in ' . $daysAhead . 'Tg ' . $secondRoom;
            }
        }
    }

    // Message for previous stay ("gestern" / "vor X Tagen")
    if ($first['end'] instanceof DateTimeImmutable) {
        $daysOffset = (int)floor(($first['end']->getTimestamp() - $planDate->getTimestamp()) / 86400) - 1;
        if ($first['end'] <= $planDate) {
            if ($daysOffset === -1) {
                $messages[] = 'gestern ' . $firstRoom;
            } elseif ($daysOffset < -1) {
                $messages[] = 'vor ' . abs($daysOffset) . 'Tagen ' . $firstRoom;
            }
        }
    }

    return trim(implode(' ', $messages));
}

$planDateParam = $_GET['date'] ?? date('Y-m-d');
$planDate = parseDateTime($planDateParam) ?? new DateTimeImmutable('today');
$planDate = $planDate->setTime(0, 0, 0);
$planDatePlusOne = $planDate->modify('+1 day');

$windowStart = $planDate->modify('-7 day');
$windowEnd = $planDate->modify('+8 day');

try {
    if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
        throw new RuntimeException('Keine gültige Datenbankverbindung verfügbar.');
    }
    if ($mysqli->connect_errno) {
        throw new RuntimeException('Datenbankfehler: ' . $mysqli->connect_error);
    }

    // --- Lade Zimmer (RowHeaders) ---
    $roomsSql = 'SELECT id, caption, kapazitaet, px, py, sort, col FROM zp_zimmer WHERE visible = 1 ORDER BY sort ASC, caption ASC';
    $roomsResult = $mysqli->query($roomsSql);
    if (!$roomsResult) {
        throw new RuntimeException('Zimmer konnten nicht geladen werden: ' . $mysqli->error);
    }

    $rooms = [];
    $roomsById = [];
    $skipRoomIds = [];
    $skipRoomCaptions = ['ablage'];
    $minPx = $minPy = PHP_INT_MAX;
    $maxPx = $maxPy = PHP_INT_MIN;

    while ($row = $roomsResult->fetch_assoc()) {
        $roomId = (int)$row['id'];
        $entry = [
            'id' => $roomId,
            'caption' => $row['caption'],
            'capacity' => (int)$row['kapazitaet'],
            'px' => (int)($row['px'] ?? 1),
            'py' => (int)($row['py'] ?? 1),
            'color' => $row['col'] ?? null,
        ];
        $roomsById[$roomId] = $entry;

        $normalizedCaption = strtolower(trim((string)$entry['caption']));
        if (in_array($normalizedCaption, $skipRoomCaptions, true)) {
            $skipRoomIds[$roomId] = true;
            continue;
        }

        $rooms[] = $entry;

        $minPx = min($minPx, $entry['px']);
        $maxPx = max($maxPx, $entry['px']);
        $minPy = min($minPy, $entry['py']);
        $maxPy = max($maxPy, $entry['py']);
    }

    if (empty($rooms)) {
        throw new RuntimeException('Keine sichtbaren Zimmer gefunden.');
    }

    $cols = $maxPx >= $minPx ? ($maxPx - $minPx + 1) : 1;
    $rows = $maxPy >= $minPy ? ($maxPy - $minPy + 1) : 1;

    // --- Lade Reservierungsdetails ---
    $detailSql = <<<SQL
        SELECT
            rd.ID               AS detail_id,
            rd.ParentID         AS parent_id,
            rd.resid            AS resid,
            rd.zimID            AS room_id,
            rd.von              AS start_at,
            rd.bis              AS end_at,
            rd.anz              AS guest_count,
            rd.bez              AS caption,
            rd.col              AS color,
            rd.arr              AS detail_arr_id,
            rd.note             AS note,
            rd.dx               AS dx,
            rd.dy               AS dy,
            rd.hund             AS has_dog,
            r.id                AS master_id,
            r.anreise           AS master_start,
            r.abreise           AS master_end,
            r.nachname          AS guest_lastname,
            r.vorname           AS guest_firstname,
            r.lager             AS lager,
            r.betten            AS betten,
            r.dz                AS dz,
            r.sonder            AS sonder,
            r.av_id             AS av_id,
            arrDet.kbez         AS detail_arr_label,
            arrDet.bez          AS detail_arr_text,
            arrMaster.kbez      AS master_arr_label,
            arrMaster.bez       AS master_arr_text
        FROM AV_ResDet rd
        JOIN `AV-Res` r ON rd.resid = r.id
        LEFT JOIN arr arrDet ON rd.arr = arrDet.ID
        LEFT JOIN arr arrMaster ON r.arr = arrMaster.ID
        JOIN zp_zimmer z ON rd.zimID = z.id AND z.visible = 1
        WHERE rd.von < ?
          AND rd.bis > ?
          AND (r.storno IS NULL OR r.storno = 0)
        ORDER BY rd.von ASC, rd.ID ASC
    SQL;

    $stmt = $mysqli->prepare($detailSql);
    if (!$stmt) {
        throw new RuntimeException('Abfragen der Reservierungsdetails fehlgeschlagen: ' . $mysqli->error);
    }

    $endParam = $windowEnd->format('Y-m-d 23:59:59');
    $startParam = $windowStart->format('Y-m-d 00:00:00');
    $stmt->bind_param('ss', $endParam, $startParam);
    if (!$stmt->execute()) {
        throw new RuntimeException('Reservierungsdetails konnten nicht geladen werden: ' . $stmt->error);
    }

    $result = $stmt->get_result();
    $details = [];
    $detailsById = [];
    $detailsByRoom = [];
    $dailyOccupancy = [];

    foreach ($rooms as $room) {
        $dailyOccupancy[$room['id']] = array_fill(0, 8, 0);
        $detailsByRoom[$room['id']] = [];
    }

    while ($row = $result->fetch_assoc()) {
        $roomId = (int)$row['room_id'];
        if (!isset($roomsById[$roomId])) {
            continue;
        }

        $start = parseDateTime($row['start_at']);
        $end = parseDateTime($row['end_at']);
        if ($start) {
            $start = $start->setTime(0, 0, 0);
        }
        if ($end) {
            $end = $end->setTime(0, 0, 0);
        }

        $totalHp = (int)($row['lager'] ?? 0)
            + (int)($row['betten'] ?? 0)
            + (int)($row['dz'] ?? 0)
            + (int)($row['sonder'] ?? 0);

        $detail = [
            'id' => (int)$row['detail_id'],
            'parent_id' => (int)($row['parent_id'] ?? 0),
            'res_id' => (int)$row['resid'],
            'room_id' => $roomId,
            'start' => $start,
            'end' => $end,
            'guest_count' => (int)($row['guest_count'] ?? 0),
            'caption' => $row['caption'] ?? '',
            'color' => $row['color'] ?? '',
            'arrangement' => $row['detail_arr_label'] ?? $row['detail_arr_text'] ?? '',
            'guest_name' => trim(($row['guest_lastname'] ?? '') . ' ' . ($row['guest_firstname'] ?? '')),
            'master_total_hp' => $totalHp,
            'master_arrangement' => $row['master_arr_label'] ?? $row['master_arr_text'] ?? '',
            'note' => $row['note'] ?? '',
            'dx' => (int)($row['dx'] ?? 0),
            'dy' => (int)($row['dy'] ?? 0),
            'av_id' => (int)($row['av_id'] ?? 0),
            'has_dog' => (int)($row['has_dog'] ?? 0) === 1,
        ];

        $details[] = $detail;
        if ($detail['id'] > 0) {
            $detailsById[$detail['id']] = $detail;
        }

        if (isset($skipRoomIds[$roomId])) {
            continue;
        }

        $detailsByRoom[$roomId][] = $detail;

        // Update occupancy for offsets 0..7 (plan date + following 7 days)
        for ($offset = 0; $offset <= 7; $offset++) {
            $day = $planDate->modify('+' . $offset . ' day');
            if ($start && $end && $start <= $day && $end > $day) {
                $dailyOccupancy[$roomId][$offset] += $detail['guest_count'];
            }
        }
    }

    $stmt->close();

    $roomSummaries = [];
    foreach ($rooms as $room) {
        $roomId = $room['id'];
        $capacity = max(0, (int)$room['capacity']);
        $detailsInRoom = $detailsByRoom[$roomId] ?? [];

        $activeDetails = array_filter($detailsInRoom, function (array $detail) use ($planDate): bool {
            $start = $detail['start'];
            $end = $detail['end'];
            if (!$start instanceof DateTimeImmutable || !$end instanceof DateTimeImmutable) {
                return false;
            }
            return $start <= $planDate && $end > $planDate;
        });

        usort($activeDetails, function (array $a, array $b): int {
            $startA = $a['start'] instanceof DateTimeImmutable ? $a['start']->getTimestamp() : 0;
            $startB = $b['start'] instanceof DateTimeImmutable ? $b['start']->getTimestamp() : 0;
            return $startA <=> $startB;
        });

        $detailDisplays = [];
        $departingAll = !empty($activeDetails);
        $preparedDetails = [];

        foreach ($activeDetails as $detail) {
            $linkMessage = getParentLinkMessage($detail, $planDate, $detailsById, $roomsById);
            $startsToday = $detail['start'] instanceof DateTimeImmutable
                && $detail['start']->getTimestamp() === $planDate->getTimestamp();
            $endsTomorrow = $detail['end'] instanceof DateTimeImmutable
                && $detail['end']->getTimestamp() === $planDatePlusOne->getTimestamp();
            $isDeparting = $endsTomorrow && $linkMessage === '';

            $preparedDetails[] = [
                'detail' => $detail,
                'linkMessage' => $linkMessage,
                'startsToday' => $startsToday,
                'endsTomorrow' => $endsTomorrow,
                'isDeparting' => $isDeparting,
            ];

            $departingAll = $departingAll && $isDeparting;
        }

        foreach ($preparedDetails as $item) {
            $detail = $item['detail'];
            $labelBase = $detail['caption'] ?: ($detail['guest_name'] ?: 'Unbekannt');
            $guestCount = $detail['guest_count'];
            $totalHp = $detail['master_total_hp'];
            $arrLabel = $detail['arrangement'] ?: $detail['master_arrangement'];

            $capacityInfo = $guestCount;
            if ($totalHp > 0) {
                $capacityInfo .= '/' . $totalHp;
            }

            $detailText = trim($labelBase);
            $detailText .= ' (' . $capacityInfo;
            if ($arrLabel) {
                $detailText .= ' ' . $arrLabel;
            }
            $detailText .= ')';

            $detailDisplays[] = [
                'text' => $detailText,
                'isArrival' => $item['startsToday'],
                'linkMessage' => $item['linkMessage'],
                'showAbr' => $item['isDeparting'],
                'showSmallCircle' => (!$departingAll && $item['isDeparting']),
                'hasDog' => !empty($detail['has_dog']),
            ];
        }

        $usedToday = $dailyOccupancy[$roomId][0] ?? 0;
        $freeToday = $capacity - $usedToday;

        $dailyStatus = [];
        for ($offset = 1; $offset <= 7; $offset++) {
            $used = $dailyOccupancy[$roomId][$offset] ?? 0;
            $free = $capacity - $used;
            if ($free >= $capacity) {
                $dailyStatus[] = ['label' => 'F', 'class' => 'status-free'];
            } elseif ($free <= 0) {
                $dailyStatus[] = ['label' => 'V', 'class' => 'status-full'];
            } else {
                $dailyStatus[] = ['label' => (string)$free, 'class' => 'status-partial'];
            }
        }

        $roomSummaries[$roomId] = [
            'room' => $room,
            'usedToday' => $usedToday,
            'freeToday' => $freeToday,
            'departingAll' => $departingAll,
            'details' => $detailDisplays,
            'dailyStatus' => $dailyStatus,
        ];
    }

    $pageTitle = 'Zimmerplan vom ' . formatGermanLong($planDate);

    $format = strtolower($_GET['format'] ?? 'pdf');
    if ($format === 'pdf') {
        if (!class_exists('RoomplanPdf')) {
            class RoomplanPdf extends FPDF
            {
                // Removed transparency handling

                public function circleShape(float $x, float $y, float $r, string $style = 'D'): void
                {
                    $this->ellipsePath($x, $y, $r, $r, $style);
                }

                private function ellipsePath(float $x, float $y, float $rx, float $ry, string $style = 'D'): void
                {
                    $op = match ($style) {
                        'F' => 'f',
                        'FD', 'DF' => 'B',
                        default => 'S',
                    };

                    $lx = 4 / 3 * (sqrt(2) - 1);
                    $ly = $lx;
                    $k = $this->k;
                    $h = $this->h;

                    $this->_out(sprintf('%.2F %.2F m', ($x + $rx) * $k, ($h - $y) * $k));
                    $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c',
                        ($x + $rx) * $k, ($h - ($y - $ly * $ry)) * $k,
                        ($x + $lx * $rx) * $k, ($h - ($y - $ry)) * $k,
                        $x * $k, ($h - ($y - $ry)) * $k));
                    $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c',
                        ($x - $lx * $rx) * $k, ($h - ($y - $ry)) * $k,
                        ($x - $rx) * $k, ($h - ($y - $ly * $ry)) * $k,
                        ($x - $rx) * $k, ($h - $y) * $k));
                    $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c',
                        ($x - $rx) * $k, ($h - ($y + $ly * $ry)) * $k,
                        ($x - $lx * $rx) * $k, ($h - ($y + $ry)) * $k,
                        $x * $k, ($h - ($y + $ry)) * $k));
                    $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c',
                        ($x + $lx * $rx) * $k, ($h - ($y + $ry)) * $k,
                        ($x + $rx) * $k, ($h - ($y + $ly * $ry)) * $k,
                        ($x + $rx) * $k, ($h - $y) * $k));
                    $this->_out($op);
                }

                // Removed transparency handling methods
            }
        }

        $pdf = new RoomplanPdf('P', 'mm', 'A4');
        $pdf->SetTitle($pageTitle);
        $pdf->AddPage();
        $pdf->SetAutoPageBreak(false);

        $marginLeft = 10.0;
        $marginRight = 10.0;
        $marginTop = 20.0;
        $marginBottom = 10.0;

        $usableWidth = 210.0 - $marginLeft - $marginRight;
        $usableHeight = 297.0 - $marginTop - $marginBottom;

        $cellWidth = $usableWidth / max(1, $cols);
        $cellHeight = $usableHeight / max(1, $rows);

    $pdf->SetFont('Helvetica', 'B', 16);
    $pdf->SetTextColor(40, 60, 150);
    $pdf->SetY(5.0);
    $pdf->Cell(0, ptToMm(16) + 2.0, pdfEncode($pageTitle), 0, 0, 'C');
        $pdf->SetDrawColor(211, 211, 211);
        $pdf->SetLineWidth(0.2);

        $captionFontPt = 12.0;
        $detailFontPt = 9.0;
        $infoFontPt = 6.0;
        $statusFontPt = 6.0;

        $dogIconPath = __DIR__ . '/../pic/DogProfile.png';
        $dogIconAvailable = is_file($dogIconPath);
        $dogIconRatio = 1.0;
        if ($dogIconAvailable) {
            $dogInfo = @getimagesize($dogIconPath);
            if (!$dogInfo || empty($dogInfo[0]) || empty($dogInfo[1])) {
                $dogIconAvailable = false;
            } else {
                $dogIconRatio = max(0.1, $dogInfo[0] / $dogInfo[1]);
            }
        }
        $dogIconHeightMm = ptToMm($detailFontPt) * 1.5;
        $dogIconWidthMm = $dogIconHeightMm * $dogIconRatio;
        $dogIconBaselineAdjust = ptToMm($detailFontPt) * 0.35;

        $leaveIconPath = __DIR__ . '/../pic/leave.png';
        $leaveIconAvailable = is_file($leaveIconPath);
        $leaveIconRatio = 1.0;
        if ($leaveIconAvailable) {
            $leaveInfo = @getimagesize($leaveIconPath);
            if (!$leaveInfo || empty($leaveInfo[0]) || empty($leaveInfo[1])) {
                $leaveIconAvailable = false;
            } else {
                $leaveIconRatio = max(0.1, $leaveInfo[0] / $leaveInfo[1]);
            }
        }
        $leaveIconHeightLarge = $cellHeight * 0.9;
        $leaveIconWidthLarge = $leaveIconHeightLarge * $leaveIconRatio;
        $leaveIconHeightSmall = ptToMm($detailFontPt);
        $leaveIconWidthSmall = $leaveIconHeightSmall * $leaveIconRatio;
    $leaveIconBaselineAdjust = ptToMm($detailFontPt) * 0.2;
    $leaveIconGapMm = 1.0;

        foreach ($roomSummaries as $summary) {
            $room = $summary['room'];
            $colIndex = $room['px'] - $minPx;
            $rowIndex = $room['py'] - $minPy;

            $x = $marginLeft + $colIndex * $cellWidth;
            $y = $marginTop + $rowIndex * $cellHeight;

            $capacity = max(0, (int)$room['capacity']);
            $freeToday = $summary['freeToday'];

            if ($capacity <= 0) {
                $titleColor = [80, 80, 80];
            } elseif ($freeToday <= 0) {
                $titleColor = [200, 0, 0];
            } elseif ($freeToday === $capacity) {
                $titleColor = [18, 132, 77];
            } else {
                $titleColor = [216, 118, 20];
            }

            if ($summary['departingAll']) {
                $diameter = $cellHeight * 0.9;
                if ($leaveIconAvailable) {
                    $centerX = $x + ($diameter / 2);
                    $centerY = $y + ($cellHeight / 2);
                    $iconX = $centerX - ($leaveIconWidthLarge / 2);
                    $iconY = $centerY - ($leaveIconHeightLarge / 2);
                    $pdf->Image($leaveIconPath, $iconX, $iconY, $leaveIconWidthLarge, $leaveIconHeightLarge);
                } else {
                    $pdf->SetDrawColor(200, 0, 0);
                    $pdf->SetLineWidth(0.3);
                    $pdf->circleShape($x + ($diameter / 2), $y + ($cellHeight / 2), $diameter / 2);
                }
            }

            $pdf->SetTextColor(...$titleColor);
            $pdf->SetFont('Helvetica', 'B', $captionFontPt);
            $captionY = $y + 2.5;
            $pdf->Text($x, $captionY, pdfEncode($room['caption']));

            $infoText = sprintf('(%d/%d)', $freeToday, $capacity);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont('Helvetica', '', $infoFontPt);
            $infoY = $captionY + ptToMm($captionFontPt) - 0.2;
            $pdf->Text($x, $infoY, pdfEncode($infoText));

            $dateY = $infoY + ptToMm($infoFontPt) - 0.1;
            $dayIndex = 1;
            foreach ($summary['dailyStatus'] as $status) {
                switch ($status['class'] ?? '') {
                    case 'status-free':
                        $pdf->SetTextColor(18, 132, 77);
                        break;
                    case 'status-full':
                        $pdf->SetTextColor(200, 0, 0);
                        break;
                    default:
                        $pdf->SetTextColor(216, 118, 20);
                }
                $symbolX = $x - 1.0 + 1.6 * $dayIndex;
                $pdf->SetFont('Helvetica', 'B', $statusFontPt);
                $pdf->Text($symbolX, $dateY, pdfEncode((string)$status['label']));
                $dayIndex++;
            }

            $usedToday = $summary['usedToday'];
            if ($usedToday > 0 && !empty($summary['details'])) {
                $detailLineY = $y + $cellHeight - 1.0;
                $currentY = $detailLineY - 1.0;
                $detailLineGap = ptToMm($detailFontPt) + 0.2;
                $indentBase = 18.0;
                $dogIconGapMm = 1.2;
                $detailIndex = 0;

                foreach ($summary['details'] as $detail) {
                    $indentX = $indentBase + 2.0 * $detailIndex;
                    if ($detail['isArrival']) {
                        $pdf->SetTextColor(200, 0, 0);
                    } else {
                        $pdf->SetTextColor(0, 0, 255);
                    }
                    $pdf->SetFont('Helvetica', '', $detailFontPt);
                    $detailX = $x + $indentX;
                    if (!empty($detail['hasDog']) && $dogIconAvailable) {
                        $iconX = $detailX;
                        $iconY = $currentY - $dogIconHeightMm + $dogIconBaselineAdjust;
                        $pdf->Image($dogIconPath, $iconX, $iconY, $dogIconWidthMm, $dogIconHeightMm);
                        $detailX += $dogIconWidthMm + $dogIconGapMm;
                    }
                    if (!$summary['departingAll'] && $detail['showSmallCircle']) {
                        if ($leaveIconAvailable) {
                            $iconX = $detailX;
                            $iconY = $currentY - $leaveIconHeightSmall + $leaveIconBaselineAdjust;
                            $pdf->Image($leaveIconPath, $iconX, $iconY, $leaveIconWidthSmall, $leaveIconHeightSmall);
                            $detailX += $leaveIconWidthSmall + $leaveIconGapMm;
                        } else {
                            $fallbackDiameter = $leaveIconHeightSmall;
                            $centerX = $detailX + ($fallbackDiameter / 2);
                            $centerY = $currentY - ($fallbackDiameter / 2) + $leaveIconBaselineAdjust;
                            $pdf->SetDrawColor(200, 0, 0);
                            $pdf->SetLineWidth(0.3);
                            $pdf->circleShape($centerX, $centerY, $fallbackDiameter / 2);
                            $detailX += $fallbackDiameter + $leaveIconGapMm;
                        }
                    }
                    $pdf->Text($detailX, $currentY, pdfEncode($detail['text']));

                    $textWidth = pdfStringWidth($pdf, $detail['text']);
                    $cursorX = $detailX + $textWidth;

                    if ($detail['showAbr']) {
                        $pdf->SetTextColor(200, 0, 0);
                        $pdf->SetFont('Helvetica', 'B', $infoFontPt);
                        $pdf->Text($cursorX + 1.0, $currentY, pdfEncode('Abr.'));
                        $cursorX += pdfStringWidth($pdf, ' Abr.');
                    }

                    if ($detail['linkMessage'] !== '') {
                        $pdf->SetTextColor(34, 139, 34);
                        $pdf->SetFont('Helvetica', '', $infoFontPt);
                        $pdf->Text($cursorX + 1.2, $currentY, pdfEncode('(' . $detail['linkMessage'] . ')'));
                    }

                    $currentY -= $detailLineGap;
                    $detailIndex++;
                }
            }

            $pdf->SetDrawColor(211, 211, 211);
            $pdf->SetLineWidth(0.2);
            $lineY = $y + $cellHeight - 1.0;
            $pdf->Line($x, $lineY, $x + $cellWidth - 5.0, $lineY);
        }

        $outputName = 'Zimmerplan_' . $planDate->format('Ymd') . '.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $outputName . '"');
        $pdf->Output('I', $outputName);
        exit;
    }
} catch (Throwable $error) {
    http_response_code(500);
    $pageTitle = 'Zimmerplan – Fehler';
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
            --bg: #f8fafc;
            --surface: rgba(255,255,255,0.82);
            --surface-border: rgba(148,163,184,0.35);
            --text: #0f172a;
            --text-muted: #475569;
            --accent: #2563eb;
            --danger: #dc2626;
            --warning: #d97706;
            --success: #16a34a;
        }
        body {
            margin: 0;
            background: linear-gradient(135deg, #ecf3ff 0%, #f8fafc 100%);
            color: var(--text);
        }
        header {
            padding: 24px clamp(24px, 4vw, 64px);
            display: flex;
            flex-direction: column;
            gap: 12px;
            background: rgba(15,23,42,0.04);
            border-bottom: 1px solid rgba(148,163,184,0.25);
        }
        header h1 {
            margin: 0;
            font-size: clamp(1.8rem, 2.6vw, 2.4rem);
            font-weight: 600;
        }
        header .subtitle {
            font-size: 1rem;
            color: var(--text-muted);
        }
        header .actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        header button {
            padding: 10px 18px;
            border-radius: 999px;
            border: 1px solid rgba(37,99,235,0.35);
            background: linear-gradient(90deg, rgba(37,99,235,0.15), rgba(59,130,246,0.25));
            color: var(--accent);
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.15s ease, box-shadow 0.2s ease;
        }
        header button:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 24px rgba(37,99,235,0.18);
        }

        main {
            padding: clamp(16px, 3vw, 40px);
        }

        .plan-grid {
            display: grid;
            gap: 18px;
        }
        @media (min-width: 720px) {
            .plan-grid {
                grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            }
        }

        .room-card {
            position: relative;
            background: var(--surface);
            border: 1px solid var(--surface-border);
            border-radius: 16px;
            padding: 18px;
            box-shadow: 0 12px 32px rgba(15,23,42,0.08);
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .room-card.room-card--departing::before {
            content: "";
            position: absolute;
            width: 72px;
            height: 72px;
            border-radius: 50%;
            border: 4px solid rgba(220,38,38,0.65);
            top: 18px;
            left: 18px;
            pointer-events: none;
        }
        .room-header {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: baseline;
        }
        .room-name {
            font-size: 1.1rem;
            font-weight: 600;
        }
        .room-name--full { color: var(--danger); }
        .room-name--partial { color: var(--warning); }
        .room-name--free { color: var(--success); }
        .room-occupancy {
            font-family: 'JetBrains Mono', 'SFMono-Regular', monospace;
            font-size: 0.9rem;
            color: var(--text-muted);
        }

        .status-row {
            display: flex;
            gap: 6px;
        }
        .status {
            width: 28px;
            height: 28px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .status-free { background: rgba(34,197,94,0.15); color: var(--success); }
        .status-full { background: rgba(220,38,38,0.15); color: var(--danger); }
        .status-partial { background: rgba(249,115,22,0.15); color: var(--warning); }

        .details {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .detail-item {
            position: relative;
            padding-left: 18px;
            line-height: 1.35;
        }
        .detail-item .indicator {
            position: absolute;
            left: 0;
            top: 0.55em;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            border: 2px solid rgba(220,38,38,0.8);
        }
        .detail-text {
            font-weight: 500;
            color: var(--text);
        }
        .detail-text--arrival { color: var(--danger); }
        .detail-meta {
            margin-left: 4px;
            font-size: 0.78rem;
            color: var(--text-muted);
        }
        .detail-meta strong { color: var(--danger); font-weight: 600; }

        .legend {
            margin-top: 32px;
            padding: 16px 24px;
            border-radius: 14px;
            background: rgba(15,23,42,0.05);
            color: var(--text-muted);
            font-size: 0.85rem;
            display: flex;
            flex-wrap: wrap;
            gap: 18px;
        }
        .legend span { display: inline-flex; align-items: center; gap: 8px; }
        .legend .swatch {
            display: inline-block;
            width: 18px;
            height: 18px;
            border-radius: 6px;
        }

        @media print {
            header, .legend { border: none; background: none; box-shadow: none; }
            header button { display: none; }
            body { background: #fff; }
            .room-card { break-inside: avoid; page-break-inside: avoid; }
        }
    </style>
</head>
<body>
<header>
    <h1><?= h($pageTitle) ?></h1>
    <div class="subtitle">Kompass für Belegung, Abreisen und Zimmerstatus</div>
    <div class="actions">
        <button type="button" onclick="window.print()">Drucken</button>
        <button type="button" onclick="history.back()">Zurück</button>
    </div>
</header>
<main>
    <?php if (isset($errorMessage)): ?>
        <div class="room-card" style="max-width:480px;margin:auto;">
            <strong>Fehler:</strong>
            <p><?= h($errorMessage) ?></p>
        </div>
    <?php else: ?>
        <section class="plan-grid">
            <?php foreach ($roomSummaries as $summary):
                $room = $summary['room'];
                $capacity = max(0, (int)$room['capacity']);
                $freeToday = $summary['freeToday'];
                $usedToday = $summary['usedToday'];

                if ($capacity <= 0) {
                    $nameClass = 'room-name';
                } elseif ($freeToday <= 0) {
                    $nameClass = 'room-name room-name--full';
                } elseif ($freeToday === $capacity) {
                    $nameClass = 'room-name room-name--free';
                } else {
                    $nameClass = 'room-name room-name--partial';
                }

                $cardClass = 'room-card';
                if ($summary['departingAll']) {
                    $cardClass .= ' room-card--departing';
                }
            ?>
                <article class="<?= h($cardClass) ?>">
                    <div class="room-header">
                        <span class="<?= h($nameClass) ?>"><?= h($room['caption']) ?></span>
                        <span class="room-occupancy">(<?= h((string)$freeToday) ?>/<?= h((string)$capacity) ?> frei)</span>
                    </div>

                    <div class="status-row" aria-label="Verfügbarkeit der kommenden 7 Tage">
                        <?php foreach ($summary['dailyStatus'] as $dayStatus): ?>
                            <span class="status <?= h($dayStatus['class']) ?>"><?= h($dayStatus['label']) ?></span>
                        <?php endforeach; ?>
                    </div>

                    <?php if (!empty($summary['details'])): ?>
                        <div class="details">
                            <?php foreach ($summary['details'] as $detail): ?>
                                <div class="detail-item">
                                    <?php if ($detail['showSmallCircle']): ?>
                                        <span class="indicator" aria-hidden="true"></span>
                                    <?php endif; ?>
                                    <span class="detail-text <?= $detail['isArrival'] ? 'detail-text--arrival' : '' ?>">
                                        <?= h($detail['text']) ?>
                                    </span>
                                    <?php if ($detail['showAbr']): ?>
                                        <span class="detail-meta"><strong>Abr.</strong></span>
                                    <?php endif; ?>
                                    <?php if ($detail['linkMessage'] !== ''): ?>
                                        <span class="detail-meta">(<?= h($detail['linkMessage']) ?>)</span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="details" style="color:var(--text-muted); font-style:italic;">
                            Keine Belegung am Stichtag.
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </section>

        <section class="legend">
            <span><span class="swatch status-free"></span>F = gesamte Kapazität frei</span>
            <span><span class="swatch status-full"></span>V = voll belegt</span>
            <span><span class="swatch status-partial"></span>Zahl = freie Plätze</span>
            <span><span class="swatch" style="border:2px solid rgba(220,38,38,0.65);"></span>Markierung = alle Gäste reisen am Folgetag ab</span>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
