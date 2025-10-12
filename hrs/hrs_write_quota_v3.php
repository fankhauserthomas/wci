<?php
/**
 * HRS Quota Writer V3 - With Management API
 * ==========================================
 * 
 * Verwendet die BEWÄHRTE Management API (wie hrs_create_quota_batch.php)
 * statt der REST API die 405 Fehler produziert!
 * 
 * FORMEL: Q_write = assigned_guests + Q_berechnet
 */

ob_start();
ini_set('display_errors', '0');
error_reporting(E_ALL);

// LOGGING: Schreibe in Datei relative zum Skript (robust bei Symlinks)
$logFilePath = __DIR__ . '/debug_v3.log';
$GLOBALS['hrs_v3_log'] = $logFilePath;
$GLOBALS['hrs_v3_buffer'] = [];
function logV3($msg) {
    $timestamp = date('Y-m-d H:i:s');
    $line = "[$timestamp] $msg\n";
    $GLOBALS['hrs_v3_buffer'][] = trim($line);
    if (file_put_contents($GLOBALS['hrs_v3_log'], $line, FILE_APPEND) === false) {
        error_log("hrs_write_quota_v3.log_failed: " . $line);
    }
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/hrs_login.php';
if (!defined('HRS_QUOTA_IMPORTER_NO_MAIN')) {
    define('HRS_QUOTA_IMPORTER_NO_MAIN', true);
}
require_once __DIR__ . '/hrs_imp_quota.php';

class QuotaWriterV3 {
    private $mysqli;
    private $hrsLogin;
    private $hutId = 675;
    
    private $categoryMap = [
        'lager'  => 1958,
        'betten' => 2293,
        'dz'     => 2381,
        'sonder' => 6106
    ];
    
    private function resolveQuotaField(array $item, array $candidateKeys) {
        foreach ($candidateKeys as $key) {
            if (isset($item[$key]) && $item[$key] !== null && $item[$key] !== '') {
                return $item[$key];
            }
        }
        
        if (isset($item['quota']) && is_array($item['quota'])) {
            foreach ($candidateKeys as $key) {
                if (isset($item['quota'][$key]) && $item['quota'][$key] !== null && $item['quota'][$key] !== '') {
                    return $item['quota'][$key];
                }
            }
        }
        
        return null;
    }
    
    private function parseQuotaDate($value) {
        if (!$value) {
            return null;
        }
        
        try {
            return new DateTime($value);
        } catch (Exception $e) {
            if (preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $value)) {
                $dt = DateTime::createFromFormat('d.m.Y', $value);
                if ($dt instanceof DateTime) {
                    return $dt;
                }
            }
        }
        
        return null;
    }
    
    public function __construct($mysqli, HRSLogin $hrsLogin) {
        $this->mysqli = $mysqli;
        $this->hrsLogin = $hrsLogin;
    }

    private function normalizeDateString($value) {
        $dt = $this->parseQuotaDate($value);
        return $dt ? $dt->format('Y-m-d') : null;
    }

    private function groupContiguousDateRanges(array $dates) {
        $ranges = [];
        if (empty($dates)) {
            return $ranges;
        }

        $sorted = array_values(array_unique($dates));
        sort($sorted);

        $current = [
            'start' => $sorted[0],
            'end' => $sorted[0],
            'dates' => [$sorted[0]]
        ];

        for ($i = 1, $n = count($sorted); $i < $n; $i++) {
            $prevDate = new DateTime($current['end']);
            $prevDate->modify('+1 day');
            if ($sorted[$i] === $prevDate->format('Y-m-d')) {
                $current['end'] = $sorted[$i];
                $current['dates'][] = $sorted[$i];
            } else {
                $ranges[] = $current;
                $current = [
                    'start' => $sorted[$i],
                    'end' => $sorted[$i],
                    'dates' => [$sorted[$i]]
                ];
            }
        }

        $ranges[] = $current;
        return $ranges;
    }

    private function expandDayRange(DateTime $start, DateTime $endExclusive) {
        $days = [];
        $cursor = clone $start;
        while ($cursor < $endExclusive) {
            $days[] = $cursor->format('Y-m-d');
            $cursor->modify('+1 day');
        }
        return $days;
    }

    private function extractQuotaQuantities(array $quota) {
        $quantities = [
            'lager' => 0,
            'betten' => 0,
            'dz' => 0,
            'sonder' => 0
        ];

        $candidateSets = [];
        if (isset($quota['hutBedCategoryDTOs']) && is_array($quota['hutBedCategoryDTOs'])) {
            $candidateSets[] = $quota['hutBedCategoryDTOs'];
        }
        if (isset($quota['categories']) && is_array($quota['categories'])) {
            $candidateSets[] = $quota['categories'];
        }
        if (isset($quota['quota']['hutBedCategoryDTOs']) && is_array($quota['quota']['hutBedCategoryDTOs'])) {
            $candidateSets[] = $quota['quota']['hutBedCategoryDTOs'];
        }
        if (isset($quota['quota']['categories']) && is_array($quota['quota']['categories'])) {
            $candidateSets[] = $quota['quota']['categories'];
        }

        foreach ($candidateSets as $set) {
            foreach ($set as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $categoryId = null;
                if (isset($entry['categoryId'])) {
                    $categoryId = $entry['categoryId'];
                } elseif (isset($entry['category']['id'])) {
                    $categoryId = $entry['category']['id'];
                } elseif (isset($entry['bedCategory']['id'])) {
                    $categoryId = $entry['bedCategory']['id'];
                } elseif (isset($entry['id'])) {
                    $categoryId = $entry['id'];
                }

                if ($categoryId === null) {
                    continue;
                }

                $beds = null;
                if (isset($entry['totalBeds'])) {
                    $beds = $entry['totalBeds'];
                } elseif (isset($entry['beds'])) {
                    $beds = $entry['beds'];
                } elseif (isset($entry['total'])) {
                    $beds = $entry['total'];
                } elseif (isset($entry['capacity'])) {
                    $beds = $entry['capacity'];
                }

                if ($beds === null) {
                    continue;
                }

                foreach ($this->categoryMap as $name => $id) {
                    if ((int)$categoryId === (int)$id) {
                        $quantities[$name] = (int)$beds;
                        break;
                    }
                }
            }
        }

        $directMap = [
            'quota_lager' => 'lager',
            'quota_betten' => 'betten',
            'quota_dz' => 'dz',
            'quota_sonder' => 'sonder',
            'lager' => 'lager',
            'betten' => 'betten',
            'dz' => 'dz',
            'sonder' => 'sonder'
        ];
        foreach ($directMap as $key => $name) {
            if (isset($quota[$key]) && is_numeric($quota[$key])) {
                $quantities[$name] = (int)$quota[$key];
            }
        }

        return $quantities;
    }

    private function isQuotaClosed(array $quota) {
        $modeCandidates = [];
        foreach (['mode', 'reservationMode', 'status', 'state'] as $key) {
            if (isset($quota[$key])) {
                $modeCandidates[] = $quota[$key];
            }
        }
        if (isset($quota['quota']) && is_array($quota['quota'])) {
            foreach (['mode', 'reservationMode', 'status', 'state'] as $key) {
                if (isset($quota['quota'][$key])) {
                    $modeCandidates[] = $quota['quota'][$key];
                }
            }
        }
        if (isset($quota['closed'])) {
            if ($quota['closed'] === true || $quota['closed'] === 'true') {
                return true;
            }
        }
        if (isset($quota['open']) && $quota['open'] === false) {
            return true;
        }

        foreach ($modeCandidates as $mode) {
            if (!is_string($mode)) {
                continue;
            }
            if (strcasecmp($mode, 'CLOSED') === 0) {
                return true;
            }
        }

        return false;
    }

    private function refreshLocalQuotaCache(array $dates) {
        if (empty($dates)) {
            return null;
        }

        $minDate = min($dates);
        $maxDate = max($dates);

        $fromDt = DateTime::createFromFormat('Y-m-d', $minDate) ?: DateTime::createFromFormat('Y-m-d H:i:s', $minDate);
        $toDt = DateTime::createFromFormat('Y-m-d', $maxDate) ?: DateTime::createFromFormat('Y-m-d H:i:s', $maxDate);

        if (!$fromDt) {
            $fromDt = new DateTime($minDate);
        }
        if (!$toDt) {
            $toDt = new DateTime($maxDate);
        }

        $dateFromStr = $fromDt->format('d.m.Y');
        $dateToStr = $toDt->format('d.m.Y');

        logV3("♻️ V3: Refreshing local quota cache ($dateFromStr → $dateToStr)");

        try {
            $importer = new HRSQuotaImporter($this->mysqli, $this->hrsLogin, ['silent' => true]);
            $success = $importer->importQuotas($dateFromStr, $dateToStr);
            $logs = $importer->getLogs();

            logV3("♻️ V3: Local quota cache refresh " . ($success ? 'successful' : 'failed'));

            return [
                'success' => $success,
                'dateFrom' => $dateFromStr,
                'dateTo' => $dateToStr,
                'logs' => $logs
            ];
        } catch (Exception $e) {
            logV3("❌ V3: Local quota cache refresh error: " . $e->getMessage());
            return [
                'success' => false,
                'dateFrom' => $dateFromStr,
                'dateTo' => $dateToStr,
                'error' => $e->getMessage()
            ];
        }
    }
    
    public function updateQuotas($quotas) {
        try {
            $createdQuotas = [];
            $preservedQuotas = [];
            $deletedQuotas = [];
            $adjustedClosed = [];
            $blockedDates = [];

            $normalizedDates = [];
            $quotasByDate = [];

            foreach ($quotas as $quota) {
                if (!isset($quota['date'])) {
                    logV3('⚠️ V3: Quota ohne Datum ignoriert: ' . json_encode($quota));
                    continue;
                }

                $normalizedDate = $this->normalizeDateString($quota['date']);
                if (!$normalizedDate) {
                    logV3("⚠️ V3: Ungültiges Datum in Quota: " . json_encode($quota));
                    continue;
                }

                $normalizedDates[] = $normalizedDate;
                $quotasByDate[$normalizedDate][] = $quota;
            }

            if (empty($normalizedDates)) {
                throw new Exception('Keine gültigen Datumswerte in den Quotas');
            }

            $uniqueDates = array_values(array_unique($normalizedDates));
            sort($uniqueDates);
            logV3("📅 V3: Processing " . count($uniqueDates) . " unique dates: " . implode(', ', $uniqueDates));

            $ranges = $this->groupContiguousDateRanges($uniqueDates);
            $rangeSummary = array_map(function ($range) {
                if ($range['start'] === $range['end']) {
                    return $range['start'];
                }
                return $range['start'] . '→' . $range['end'];
            }, $ranges);
            logV3("🧭 V3: Identified " . count($ranges) . " contiguous range(s): " . implode('; ', $rangeSummary));

            $deletionResult = $this->deleteOverlappingQuotas($uniqueDates, $ranges);
            $deletedQuotas = $deletionResult['deleted'] ?? [];
            $preservedQuotas = $deletionResult['splitCreated'] ?? [];
            $adjustedClosed = $deletionResult['processedClosed'] ?? [];
            $blockedDates = $deletionResult['blockedDates'] ?? [];

            logV3("🗑️ V3: Deleted " . count($deletedQuotas) . " overlapping quotas");
            if (!empty($adjustedClosed)) {
                logV3("🚧 V3: Adjusted " . count($adjustedClosed) . " closed quota(s)");
            }
            if (!empty($preservedQuotas)) {
                logV3("🔁 V3: Recreated " . count($preservedQuotas) . " preserved quota day(s) outside selection");
            }

            foreach ($ranges as $range) {
                logV3("➡️ V3: Processing range {$range['start']} → {$range['end']} (" . count($range['dates']) . " day(s))");
                logV3("   📋 Range dates: " . implode(', ', $range['dates']));
                
                foreach ($range['dates'] as $date) {
                    logV3("   🔍 Processing date: {$date}");
                    
                    if (isset($blockedDates[$date])) {
                        $blocked = $blockedDates[$date];
                        logV3(sprintf(
                            "⛔ V3: Skipping %s due to closed quota %s (%s-%s)",
                            $date,
                            $blocked['id'],
                            $blocked['from'] ?? '?',
                            $blocked['to'] ?? '?'
                        ));
                        continue;
                    }

                    if (!isset($quotasByDate[$date])) {
                        logV3("⚠️ V3: Keine Quota-Daten für $date gefunden (übersprungen)");
                        continue;
                    }

                    logV3("   ✓ Found quota data for {$date}");
                    
                    foreach ($quotasByDate[$date] as $quotaIndex => $quota) {
                        logV3("   📦 Processing quota #{$quotaIndex} for {$date}");
                        
                        $quantities = [];
                        $totalQuantity = 0;
                        foreach ($this->categoryMap as $categoryName => $categoryId) {
                            $fieldName = 'quota_' . $categoryName;
                            $quantity = isset($quota[$fieldName]) ? (int)$quota[$fieldName] : 0;
                            $quantities[$categoryName] = max(0, $quantity);
                            $totalQuantity += $quantities[$categoryName];
                        }

                        logV3("   💯 Total quantity for {$date}: {$totalQuantity}");

                        if ($totalQuantity <= 0 && empty($adjustedClosed)) {
                            logV3("ℹ️ V3: Skipping quota for $date (all categories = 0)");
                            continue;
                        }

                        $quotaCreation = $this->createQuota($date, $quantities, 'SERVICED');
                        if ($quotaCreation !== false) {
                            $createdQuotas[] = [
                                'date' => $date,
                                'quantities' => $quantities,
                                'id' => ($quotaCreation === true) ? null : $quotaCreation
                            ];
                            logV3(sprintf(
                                "✅ V3: Created quota ID %s for %s (L=%d, B=%d, DZ=%d, S=%d)",
                                ($quotaCreation === true ? 'n/a' : $quotaCreation),
                                $date,
                                $quantities['lager'],
                                $quantities['betten'],
                                $quantities['dz'],
                                $quantities['sonder']
                            ));
                        } else {
                            logV3("❌ V3: Failed to create quota for {$date}");
                        }
                    }
                }
            }

            $localRefresh = $this->refreshLocalQuotaCache($uniqueDates);

            $messageParts = [];
            $messageParts[] = count($createdQuotas) . ' Quotas erstellt';
            $messageParts[] = count($deletedQuotas) . ' gelöscht';
            if (!empty($adjustedClosed)) {
                $messageParts[] = count($adjustedClosed) . ' geschlossene angepasst';
            }
            if (!empty($preservedQuotas)) {
                $messageParts[] = count($preservedQuotas) . ' Teilbereiche erhalten';
            }
            if ($localRefresh && isset($localRefresh['success'])) {
                $messageParts[] = $localRefresh['success'] ? 'Import aktualisiert' : 'Import fehlgeschlagen';
            }

            return [
                'success' => true,
                'createdQuotas' => $createdQuotas,
                'deletedQuotas' => $deletedQuotas,
                'adjustedClosedQuotas' => array_values($adjustedClosed),
                'skippedClosedQuotas' => array_values($adjustedClosed),
                'preservedQuotas' => $preservedQuotas,
                'blockedDates' => array_values($blockedDates),
                'localRefresh' => $localRefresh,
                'createdCount' => count($createdQuotas),
                'deletedCount' => count($deletedQuotas),
                'adjustedClosedCount' => count($adjustedClosed),
                'preservedCount' => count($preservedQuotas),
                'affectedDateRange' => [
                    'from' => min($uniqueDates),
                    'to' => max($uniqueDates)
                ],
                'message' => implode(', ', $messageParts)
            ];

        } catch (Exception $e) {
            logV3("❌ V3 Error in updateQuotas: " . $e->getMessage());
            throw $e;
        }
    }
    
    private function deleteOverlappingQuotas(array $dates, array $ranges) {
        $result = [
            'deleted' => [],
            'splitCreated' => [],
            'processedClosed' => [],
            'blockedDates' => []
        ];

        if (empty($dates)) {
            return $result;
        }
        
        try {
            $selectedDateSet = array_fill_keys($dates, true);
            $minDate = min($dates);
            $maxDate = max($dates);

            $dateFrom = date('d.m.Y', strtotime($minDate . ' -30 days'));
            $dateTo = date('d.m.Y', strtotime($maxDate . ' +30 days'));

            logV3("🔍 V3: Searching quotas from $dateFrom to $dateTo (" . count($ranges) . " range(s))");

            $toProcess = [];
            $page = 0;
            $maxPages = 25;
            $headers = ['X-XSRF-TOKEN' => $this->hrsLogin->getCsrfToken()];
            $totalFound = 0;

            do {
                $url = "/api/v1/manage/hutQuota?hutId={$this->hutId}&page={$page}&size=200&sortList=BeginDate&sortOrder=ASC&open=true&dateFrom={$dateFrom}&dateTo={$dateTo}";
                logV3("🌐 V3: FETCH quotas page {$page}");

                $responseArray = $this->hrsLogin->makeRequest($url, 'GET', null, $headers);
                logV3("📬 V3: GET quotas page {$page} status " . ($responseArray['status'] ?? 'n/a'));
                if (isset($responseArray['body'])) {
                    logV3("📄 V3: Page {$page} body preview: " . substr($responseArray['body'], 0, 200));
                }

                if (!$responseArray || !isset($responseArray['body'])) {
                    logV3("❌ V3: No response from HRS API (page {$page})");
                    break;
                }

                $data = json_decode($responseArray['body'], true);
                if (!$data) {
                    logV3("❌ V3: Failed to parse HRS API response (page {$page})");
                    break;
                }

                $dumpFile = __DIR__ . '/debug_v3_fetch_' . date('Ymd_His') . "_p{$page}.json";
                file_put_contents($dumpFile, $responseArray['body']);

                $quotasList = null;
                if (isset($data['_embedded']['bedCapacityChangeResponseDTOList'])) {
                    $quotasList = $data['_embedded']['bedCapacityChangeResponseDTOList'];
                } elseif (isset($data['content'])) {
                    $quotasList = $data['content'];
                } elseif (isset($data['quotas']) && is_array($data['quotas'])) {
                    $quotasList = $data['quotas'];
                } elseif (isset($data['items']) && is_array($data['items'])) {
                    $quotasList = $data['items'];
                } elseif (is_array($data)) {
                    $quotasList = $data;
                }

                $currentCount = is_array($quotasList) ? count($quotasList) : 0;
                logV3("📄 V3: Page {$page} returned {$currentCount} quotas");

                if (!$quotasList || $currentCount === 0) {
                    break;
                }

                $totalFound += $currentCount;

                foreach ($quotasList as $quota) {
                    $beginDateRaw = $this->resolveQuotaField($quota, ['beginDate', 'dateFrom', 'date_from', 'startDate', 'date_from_formatted']);
                    $endDateRaw = $this->resolveQuotaField($quota, ['endDate', 'dateTo', 'date_to', 'endDate', 'date_to_formatted']);

                    $quotaId = null;
                    if (isset($quota['id']) && $quota['id']) {
                        $quotaId = $quota['id'];
                    } elseif (isset($quota['quotaId']) && $quota['quotaId']) {
                        $quotaId = $quota['quotaId'];
                    } elseif (isset($quota['quota']['id']) && $quota['quota']['id']) {
                        $quotaId = $quota['quota']['id'];
                    }

                    if (!$quotaId) {
                        logV3("⚠️ V3: Unable to determine quota ID for entry: " . json_encode($quota));
                        continue;
                    }

                    $quotaStart = $this->parseQuotaDate($beginDateRaw);
                    $quotaEndRaw = $this->parseQuotaDate($endDateRaw);

                    if (!$quotaStart || !$quotaEndRaw) {
                        logV3(sprintf(
                            "⚠️ V3: Could not parse dates for quota %s (begin=%s, end=%s)",
                            $quotaId,
                            $beginDateRaw,
                            $endDateRaw
                        ));
                        continue;
                    }

                    if ($quotaEndRaw < $quotaStart) {
                        $quotaEndRaw = clone $quotaStart;
                    }

                    $diffDays = (int)$quotaStart->diff($quotaEndRaw)->format('%a');
                    $quotaEnd = clone $quotaEndRaw;

                    if ($quotaEnd <= $quotaStart) {
                        $quotaEnd = (clone $quotaStart)->modify('+1 day');
                    } elseif ($diffDays >= 2) {
                        $quotaEnd = (clone $quotaEndRaw)->modify('+1 day');
                    } elseif ($diffDays === 0) {
                        $quotaEnd = (clone $quotaStart)->modify('+1 day');
                    }

                    logV3(sprintf(
                        "🧮 V3: Quota %s range %s -> %s (rawEnd=%s, diff=%d)",
                        $quotaId,
                        $quotaStart->format('Y-m-d'),
                        $quotaEnd->format('Y-m-d'),
                        $quotaEndRaw->format('Y-m-d'),
                        $diffDays
                    ));

                    $daysInQuota = $this->expandDayRange($quotaStart, $quotaEnd);
                    if (empty($daysInQuota)) {
                        logV3("⚠️ V3: Quota {$quotaId} produced no day list (skipped)");
                        continue;
                    }

                    $intersectionDays = [];
                    foreach ($daysInQuota as $day) {
                        if (isset($selectedDateSet[$day])) {
                            $intersectionDays[] = $day;
                        }
                    }

                    if (empty($intersectionDays)) {
                        continue;
                    }

                    $modeRaw = $this->resolveQuotaField($quota, ['mode', 'reservationMode', 'status', 'state']);
                    $mode = $modeRaw ?: ($quota['mode'] ?? 'SERVICED');
                    $modeUpper = strtoupper((string)$mode);
                    $isClosedQuota = $modeUpper === 'CLOSED';
                    if ($isClosedQuota) {
                        $closedInfo = [
                            'id' => $quotaId,
                            'mode' => $modeUpper,
                            'from' => $quotaStart->format('Y-m-d'),
                            'to' => $quotaEnd->format('Y-m-d'),
                            'affectedDays' => $intersectionDays
                        ];
                        $result['processedClosed'][$quotaId] = $closedInfo;
                        logV3("🚧 V3: Closed quota {$quotaId} overlaps selection – will adjust surrounding closed segments");
                    }

                    $categoryValues = $this->extractQuotaQuantities($quota);

                    $toProcess[$quotaId] = [
                        'raw' => $quota,
                        'mode' => $modeUpper,
                        'isClosed' => $isClosedQuota,
                        'start' => clone $quotaStart,
                        'end' => clone $quotaEnd,
                        'displayFrom' => $beginDateRaw,
                        'displayTo' => $endDateRaw,
                        'days' => $daysInQuota,
                        'intersections' => $intersectionDays,
                        'categoryValues' => $categoryValues
                    ];

                    logV3("   ✅ Overlap detected for quota {$quotaId} (" . implode(', ', $intersectionDays) . ")");
                }

                $page++;
                $hasMore = false;

                if (isset($data['page']['totalPages'])) {
                    $totalPages = (int)$data['page']['totalPages'];
                    $hasMore = $page < $totalPages;
                } elseif (isset($data['totalPages'])) {
                    $totalPages = (int)$data['totalPages'];
                    $hasMore = $page < $totalPages;
                } else {
                    $hasMore = $currentCount === 200;
                }
            } while ($page < $maxPages && $hasMore);

            logV3("📊 V3: Fetched {$totalFound} quotas across {$page} pages");
            logV3("🎯 V3: Found " . count($toProcess) . " overlapping quota(s) to adjust");

            foreach ($toProcess as $quotaId => $info) {
                $displayFrom = $info['displayFrom'] ?? $info['start']->format('Y-m-d');
                $displayTo = $info['displayTo'] ?? $info['end']->format('Y-m-d');

                // Berechne welche Tage behalten werden sollen
                // Das sind ALLE Tage der Quota MINUS die tatsächlich selektierten Tage
                $quotaDays = $info['days'];
                $intersectionDays = $info['intersections'];
                
                // Tage behalten = Quota-Tage die NICHT in der Selektion sind
                $daysToKeep = array_values(array_diff($quotaDays, $intersectionDays));
                
                // Bestimme ob die Quota komplett gelöscht werden muss
                $deleteCompletely = empty($daysToKeep);

                logV3("🗑️ V3: Attempting to delete quota ID $quotaId ($displayFrom - $displayTo) " . ($deleteCompletely ? '[full]' : '[split]'));
                if (!$deleteCompletely) {
                    logV3("   🎯 Selected days to overwrite: " . implode(', ', $intersectionDays));
                    logV3("   💾 Days to preserve: " . implode(', ', $daysToKeep));
                }

                if ($this->deleteQuotaViaAPI($quotaId)) {
                    $result['deleted'][] = [
                        'id' => $quotaId,
                        'from' => $displayFrom,
                        'to' => $displayTo,
                        'mode' => $info['mode'] ?? null,
                        'action' => $deleteCompletely ? 'full' : 'split'
                    ];
                    logV3("✅ V3: Deleted quota ID $quotaId");

                    if (!$deleteCompletely && !empty($daysToKeep)) {
                        $categories = $info['categoryValues'];
                        $categorySum = array_sum($categories);
                        $preserveMode = $info['mode'] ?? 'SERVICED';
                        $preserveMode = $preserveMode ? strtoupper($preserveMode) : 'SERVICED';
                        $forcePreserve = !empty($info['isClosed']);

                        if ($categorySum <= 0 && !$forcePreserve) {
                            logV3("⚠️ V3: Quota {$quotaId} split but categories sum = 0, skip recreation");
                            continue;
                        }

                        $baseTitle = $this->resolveQuotaField($info['raw'], ['title']);
                        if (!is_string($baseTitle) || $baseTitle === '') {
                            $baseTitle = 'Timeline Split Quota';
                        }

                        // Gruppiere daysToKeep in zusammenhängende Bereiche
                        $preserveRanges = $this->groupContiguousDateRanges($daysToKeep);
                        
                        logV3("   🔄 Creating " . count($preserveRanges) . " preserved range(s)");
                        
                        foreach ($preserveRanges as $rangeIndex => $range) {
                            try {
                                $rangeStart = $range['start'];
                                $rangeEnd = $range['end'];
                                $rangeDays = $range['dates'];
                                
                                logV3("   🔁 Recreating preserved range {$rangeStart} to {$rangeEnd} (" . count($rangeDays) . " days)");
                                
                                // HRS API Limit: Max 80 Zeichen für Title
                                // Kürze den Base-Title falls nötig
                                $maxBaseLength = 60; // Reserviere 20 Zeichen für " (Split X)"
                                if (strlen($baseTitle) > $maxBaseLength) {
                                    $baseTitle = substr($baseTitle, 0, $maxBaseLength);
                                }
                                
                                $titleForRange = sprintf('%s (Split %d)', $baseTitle, $rangeIndex + 1);
                                
                                // End-Date: Der letzte Tag der Range (INKLUSIV)
                                // Die createQuota Funktion erwartet das Ende INKLUSIV
                                $newId = $this->createQuota($rangeStart, $categories, $preserveMode, $titleForRange, $forcePreserve, $rangeEnd);
                                
                                if ($newId) {
                                    $result['splitCreated'][] = [
                                        'sourceId' => $quotaId,
                                        'dateFrom' => $rangeStart,
                                        'dateTo' => $rangeEnd,
                                        'id' => $newId,
                                        'quantities' => $categories
                                    ];
                                    logV3("   ✅ Preserved quota created with ID {$newId} for range {$rangeStart} to {$rangeEnd}");
                                }
                            } catch (Exception $preserveEx) {
                                // Log den Fehler aber fahre mit dem nächsten Preserve-Range fort
                                logV3("   ⚠️ Failed to recreate preserved range {$rangeStart} to {$rangeEnd}: " . $preserveEx->getMessage());
                                $result['preserveFailed'][] = [
                                    'sourceId' => $quotaId,
                                    'dateFrom' => $rangeStart,
                                    'dateTo' => $rangeEnd,
                                    'error' => $preserveEx->getMessage()
                                ];
                            }
                        }
                    }
                } else {
                    logV3("⚠️ V3: Failed to delete quota ID $quotaId");
                }
            }

        } catch (Exception $e) {
            logV3("❌ V3 Error in deleteOverlappingQuotas: " . $e->getMessage());
        }

        return $result;
    }
    
    private function deleteQuotaViaAPI($quotaId) {
        try {
            logV3("🔧 V3: deleteQuotaViaAPI called for ID $quotaId");
            
            $queryParams = [
                'hutId' => $this->hutId,
                'quotaId' => (int)$quotaId,
                'canChangeMode' => 'false',
                'canOverbook' => 'false',
                'allSeries' => 'false'
            ];
            
            $queryString = http_build_query($queryParams);
            $url = "/api/v1/manage/deleteQuota?$queryString";
            
            logV3("🌐 V3: DELETE $url");
            
            $cookies = $this->hrsLogin->getCookies();
            $cookieParts = [];
            foreach ($cookies as $name => $value) {
                $cookieParts[] = "{$name}={$value}";
            }
            $cookieHeader = implode('; ', $cookieParts);
            
            $headers = [
                'Accept: application/json, text/plain, */*',
                'Origin: https://www.hut-reservation.org',
                'Referer: https://www.hut-reservation.org/hut/manage-hut/675',
                'X-XSRF-TOKEN: ' . $this->hrsLogin->getCsrfToken(),
                'Cookie: ' . $cookieHeader
            ];
            
            $fullUrl = "https://www.hut-reservation.org{$url}";
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $fullUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => 'DELETE',
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_FOLLOWLOCATION => true
            ]);
            
            $responseBody = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if ($curlError) {
                logV3("❌ V3: DELETE curl error for ID $quotaId: $curlError");
                return false;
            }
            
            logV3("📥 V3: DELETE response: HTTP " . $httpCode);
            logV3("📄 V3: DELETE body: " . substr($responseBody, 0, 200));
            
            if ($httpCode == 200) {
                logV3("✅ V3: DELETE successful for ID $quotaId");
                return true;
            }
            
            $decoded = json_decode($responseBody, true);
            if ($decoded && isset($decoded['messageId']) && (int)$decoded['messageId'] === 126) {
                logV3("✅ V3: DELETE treated as success (MessageID 126) for ID $quotaId");
                return true;
            }
            
            logV3("⚠️ V3: DELETE failed for ID $quotaId - HTTP $httpCode");
            return false;
            
        } catch (Exception $e) {
            logV3("❌ V3: DELETE exception for ID $quotaId: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * ✅ BEWÄHRTE MANAGEMENT API (wie in hrs_create_quota_batch.php)
     */
    private function createQuota($date, array $quantities, $mode = 'SERVICED', $title = null, $forceCreate = false, $dateTo = null) {
        try {
            $date_from = $date;
            // Wenn dateTo übergeben wird (INKLUSIV), dann ist das API dateTo = dateTo + 1
            // Die HRS API erwartet dateTo als exklusiv (letzter Tag + 1)
            if ($dateTo) {
                // dateTo ist der letzte Tag INKLUSIV, API braucht +1
                $date_to = date('Y-m-d', strtotime($dateTo . ' +1 day'));
            } else {
                // Single-day quota
                $date_to = date('Y-m-d', strtotime($date . ' +1 day'));
            }

            $normalizedMode = $mode ? strtoupper($mode) : 'SERVICED';
            $allowedModes = ['SERVICED', 'UNSERVICED', 'CLOSED'];
            if (!in_array($normalizedMode, $allowedModes, true)) {
                $normalizedMode = 'SERVICED';
            }
            $capacityValue = array_sum($quantities);
            if (!$forceCreate) {
                $capacityValue = max(0, $capacityValue);
            }
            $title = $title ?: 'Timeline Quota ' . $date;

            logV3(sprintf(
                "📤 V3: Creating quota via Management API: %s to %s (API dateTo=%s, Mode=%s | L=%d, B=%d, DZ=%d, S=%d)",
                $date,
                $dateTo ? $dateTo : $date,
                $date_to,
                $normalizedMode,
                $quantities['lager'],
                $quantities['betten'],
                $quantities['dz'],
                $quantities['sonder']
            ));
            
            $payload = array(
                'id' => 0,
                'title' => $title,
                'reservationMode' => $normalizedMode,
                'isRecurring' => null,
                'capacity' => $capacityValue,
                'languagesDataDTOs' => array(
                    array('language' => 'DE_DE', 'description' => ''),
                    array('language' => 'EN', 'description' => '')
                ),
                'hutBedCategoryDTOs' => array(
                    array('categoryId' => 1958, 'totalBeds' => $quantities['lager'] ?? 0),
                    array('categoryId' => 2293, 'totalBeds' => $quantities['betten'] ?? 0),
                    array('categoryId' => 2381, 'totalBeds' => $quantities['dz'] ?? 0),
                    array('categoryId' => 6106, 'totalBeds' => $quantities['sonder'] ?? 0)
                ),
                'monday' => null,
                'tuesday' => null,
                'wednesday' => null,
                'thursday' => null,
                'friday' => null,
                'saturday' => null,
                'sunday' => null,
                'weeksRecurrence' => null,
                'occurrencesNumber' => null,
                'seriesBeginDate' => '',
                'dateFrom' => date('d.m.Y', strtotime($date_from)),
                'dateTo' => date('d.m.Y', strtotime($date_to)),
                'canOverbook' => $normalizedMode === 'CLOSED' ? false : true,
                'canChangeMode' => false,
                'allSeries' => false
            );
            
            $json_payload = json_encode($payload);
            
            // ✅ DIREKTES CURL wie hrs_create_quota_batch.php (BEWÄHRT!)
            $url = "https://www.hut-reservation.org/api/v1/manage/hutQuota/{$this->hutId}";
            
            // Cookie-Header aus HRSLogin-Cookies erstellen
            $cookies = $this->hrsLogin->getCookies();
            $cookie_parts = array();
            foreach ($cookies as $name => $value) {
                $cookie_parts[] = "$name=$value";
            }
            $cookie_header = implode('; ', $cookie_parts);
            
            $headers = array(
                'Accept: application/json, text/plain, */*',
                'Content-Type: application/json',
                'Origin: https://www.hut-reservation.org',
                'Referer: https://www.hut-reservation.org/hut/manage-hut/675',
                'X-XSRF-TOKEN: ' . $this->hrsLogin->getCsrfToken(),
                'Cookie: ' . $cookie_header
            );
            
            logV3("🌐 V3: POST $url (Direct CURL like hrs_create_quota_batch.php)");
            logV3(sprintf(
                "📦 V3: Payload (per category): L=%d, B=%d, DZ=%d, S=%d on %s",
                $quantities['lager'],
                $quantities['betten'],
                $quantities['dz'],
                $quantities['sonder'],
                $date
            ));
            
            $ch = curl_init();
            curl_setopt_array($ch, array(
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $json_payload,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_FOLLOWLOCATION => true
            ));
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);
            
            if ($curl_error) {
                logV3("❌ V3: CURL Error: $curl_error");
                throw new Exception("CURL-Fehler: $curl_error");
            }
            
            $response_body = $response;
            
            logV3("📥 V3: HTTP $http_code");
            logV3("📥 V3: Response: " . substr($response_body, 0, 200));
            
            if ($http_code !== 200) {
                throw new Exception("HTTP-Fehler $http_code: $response_body");
            }
            
            $response_data = json_decode($response_body, true);
            if ($response_data && isset($response_data['messageId']) && $response_data['messageId'] == 120) {
                $quotaId = $response_data['param1'] ?? null;
                if (!$quotaId) {
                    logV3("✅ V3: Quota created successfully (MessageID: 120, ID unbekannt)");
                    return true;
                }
                logV3("✅ V3: Quota created successfully (MessageID: 120, ID: $quotaId)");
                return $quotaId;
            } else {
                logV3("⚠️ V3: Unexpected response: $response_body");
                throw new Exception("Unerwartete Antwort: " . $response_body);
            }
            
        } catch (Exception $e) {
            logV3("❌ V3 Exception creating quota: " . $e->getMessage());
            throw $e;
        }
    }
}

// ===== AJAX ENDPOINT =====

logV3("🚀 hrs_write_quota_v3.php START");

try {
    global $mysqli;
    
    if (!$mysqli || !($mysqli instanceof mysqli)) {
        throw new Exception('Database connection not available');
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['quotas'])) {
        throw new Exception('Invalid input - missing quotas array');
    }
    
    if (!isset($input['operation'])) {
        throw new Exception('Invalid input - missing operation');
    }
    
    logV3("📥 V3: Received " . count($input['quotas']) . " quotas");
    
    // HRS Login
    logV3("🔐 V3: Starting HRS Login...");
    $hrsLogin = new HRSLogin();
    
    if (!$hrsLogin->login()) {
        throw new Exception('HRS Login failed');
    }
    
    logV3("✅ V3: HRS Login successful");
    
    // Process quotas
    $writer = new QuotaWriterV3($mysqli, $hrsLogin);
    $result = $writer->updateQuotas($input['quotas']);
    
    // Send response
    ob_end_clean();
    if (!isset($result['log'])) {
        $result['log'] = $GLOBALS['hrs_v3_buffer'];
    }
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
    logV3("🎉 V3: Response sent successfully");
    
} catch (Exception $e) {
    logV3("❌ V3 Fatal error: " . $e->getMessage());
    
    ob_end_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'log' => $GLOBALS['hrs_v3_buffer']
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
