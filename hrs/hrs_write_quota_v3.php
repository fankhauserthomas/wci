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
require_once __DIR__ . '/quota_sse_helper.php';

class QuotaWriterV3 {
    private $mysqli;
    private $hrsLogin;
    private $hutId;
    private $sseHelper = null;
    
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
    
    public function __construct($mysqli, HRSLogin $hrsLogin, $sseSessionId = null) {
        $this->mysqli = $mysqli;
        $this->hrsLogin = $hrsLogin;
        
        // Load hutId from config
        $this->hutId = defined('HUT_ID') ? HUT_ID : 675; // Fallback to 675 if not defined
        
        // Initialize SSE Helper if session ID provided
        if ($sseSessionId) {
            $this->sseHelper = new QuotaSSEHelper($sseSessionId);
            logV3("✅ SSE Helper initialized with session: " . $this->sseHelper->getSessionId());
        }
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

    private function buildSegmentsFromDayList(array $dayList) {
        logV3("🧩 buildSegmentsFromDayList() called with " . count($dayList) . " days: " . implode(', ', $dayList));
        
        $segments = [];
        if (empty($dayList)) {
            logV3("🧩 → Empty dayList, returning 0 segments");
            return $segments;
        }

        $normalized = array_values(array_unique($dayList));
        sort($normalized);
        logV3("🧩 → Normalized/sorted days: " . implode(', ', $normalized));

        $currentSegment = [];
        $lastDate = null;

        foreach ($normalized as $dateStr) {
            if (empty($currentSegment)) {
                $currentSegment[] = $dateStr;
                $lastDate = $dateStr;
                continue;
            }

            $expectedNext = date('Y-m-d', strtotime($lastDate . ' +1 day'));
            logV3("🧩 → Processing $dateStr: last=$lastDate, expected=$expectedNext, match=" . ($dateStr === $expectedNext ? 'YES' : 'NO'));
            
            if ($dateStr === $expectedNext) {
                $currentSegment[] = $dateStr;
                $lastDate = $dateStr;
                logV3("🧩 → Added to current segment: " . implode(', ', $currentSegment));
            } else {
                $segmentToClose = [
                    'start' => $currentSegment[0],
                    'end' => $currentSegment[count($currentSegment) - 1],
                    'dates' => $currentSegment
                ];
                $segments[] = $segmentToClose;
                logV3("🧩 → Closed segment: {$segmentToClose['start']} → {$segmentToClose['end']} (Days: " . implode(', ', $segmentToClose['dates']) . ")");
                
                $currentSegment = [$dateStr];
                $lastDate = $dateStr;
                logV3("🧩 → Started new segment with: $dateStr");
            }
        }

        if (!empty($currentSegment)) {
            $finalSegment = [
                'start' => $currentSegment[0],
                'end' => $currentSegment[count($currentSegment) - 1],
                'dates' => $currentSegment
            ];
            $segments[] = $finalSegment;
            logV3("🧩 → Final segment: {$finalSegment['start']} → {$finalSegment['end']} (Days: " . implode(', ', $finalSegment['dates']) . ")");
        }

        logV3("🧩 → TOTAL SEGMENTS CREATED: " . count($segments));
        foreach ($segments as $idx => $seg) {
            logV3("🧩 →   Segment " . ($idx + 1) . ": {$seg['start']} → {$seg['end']} (" . count($seg['dates']) . " days)");
        }

        return $segments;
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
            
            // SSE: Notify processing started
            if ($this->sseHelper) {
                $this->sseHelper->quotaProcessingStarted(count($uniqueDates), [
                    'dates' => $uniqueDates,
                    'total_quotas' => count($quotas)
                ]);
            }

            // ✅ PHASE 1: Gruppiere neue Quotas in zusammenhängende Bereiche
            $contiguousRanges = $this->groupContiguousDateRanges($uniqueDates);
            $rangeSummary = array_map(function ($range) {
                if ($range['start'] === $range['end']) {
                    return $range['start'];
                }
                return $range['start'] . '→' . $range['end'];
            }, $contiguousRanges);
            logV3("🧭 V3: Identified " . count($contiguousRanges) . " contiguous range(s): " . implode('; ', $rangeSummary));

            // ✅ LOG COMPLETE DEPLOYMENT PLAN
            logV3("");
            logV3("================================================================================");
            logV3("📋 COMPLETE DEPLOYMENT PLAN");
            logV3("================================================================================");
            logV3("🎯 USER SELECTED DATES: " . count($uniqueDates) . " total");
            logV3("   📅 Selected: " . implode(', ', $uniqueDates));
            logV3("");
            logV3("🧭 PROCESSING STRATEGY: " . count($contiguousRanges) . " separate range(s)");
            foreach ($contiguousRanges as $idx => $rng) {
                $dayCount = count($rng['dates']);
                logV3("   📦 Range " . ($idx + 1) . ": {$rng['start']} → {$rng['end']} ({$dayCount} day" . ($dayCount > 1 ? 's' : '') . ")");
                logV3("      📅 Days: " . implode(', ', $rng['dates']));
            }
            logV3("");
            logV3("🔄 OPERATION SEQUENCE FOR EACH RANGE:");
            logV3("   [STEP 1] 🔍 FIND overlapping existing quotas");
            logV3("   [STEP 2] 🗑️ DELETE overlapping quotas (with segment preservation)");
            logV3("   [STEP 3] ✅ CREATE new quotas for selected dates");
            logV3("================================================================================");
            logV3("");

            // ✅ PHASE 2: Verarbeite jeden zusammenhängenden Bereich separat
            $totalQuotasToProcess = count($uniqueDates);
            $processedQuotasCount = 0;
            
            foreach ($contiguousRanges as $rangeIndex => $range) {
                $rangeDates = $range['dates'];
                logV3("🚀 STARTING RANGE " . ($rangeIndex + 1) . "/" . count($contiguousRanges) . ": {$range['start']} → {$range['end']} (" . count($rangeDates) . " day(s))");
                logV3("   � Range will deploy quotas on: " . implode(', ', $rangeDates));

                logV3("");
                logV3("🔍 [STEP 1] SEARCHING FOR OVERLAPPING QUOTAS IN RANGE " . ($rangeIndex + 1));
                logV3("   🎯 Target range: {$range['start']} → {$range['end']}");
                logV3("   🔍 Will search for ANY existing quota that touches these dates...");
                
                // ✅ Lösche überlappende Quotas für diesen spezifischen Bereich
                $deletionResult = $this->deleteOverlappingQuotas($rangeDates, [$range]);
                $rangeDeletedQuotas = $deletionResult['deleted'] ?? [];
                $rangePreservedQuotas = $deletionResult['splitCreated'] ?? [];
                $rangeAdjustedClosed = $deletionResult['processedClosed'] ?? [];
                $rangeBlockedDates = $deletionResult['blockedDates'] ?? [];
                
                logV3("📊 [STEP 1] OVERLAP SEARCH RESULTS:");
                logV3("   🗑️ Quotas to delete: " . count($rangeDeletedQuotas));
                logV3("   🔁 Segments to recreate: " . count($rangePreservedQuotas));
                logV3("   🚧 Closed quotas adjusted: " . count($rangeAdjustedClosed));
                logV3("   ⛔ Blocked dates: " . count($rangeBlockedDates));

                // Sammle Ergebnisse
                $deletedQuotas = array_merge($deletedQuotas, $rangeDeletedQuotas);
                $preservedQuotas = array_merge($preservedQuotas, $rangePreservedQuotas);
                $adjustedClosed = array_merge($adjustedClosed, $rangeAdjustedClosed);
                $blockedDates = array_merge($blockedDates, $rangeBlockedDates);

                logV3("");
                logV3("📋 [STEP 2 COMPLETED] DELETION & PRESERVATION SUMMARY:");
                logV3("   ✅ Deleted quotas: " . count($rangeDeletedQuotas));
                foreach ($rangeDeletedQuotas as $del) {
                    logV3("      🗑️ Deleted ID {$del['id']}: {$del['from']} → {$del['to']} [{$del['action']}]");
                }
                if (!empty($rangePreservedQuotas)) {
                    logV3("   � Preserved segments: " . count($rangePreservedQuotas));
                    foreach ($rangePreservedQuotas as $pres) {
                        logV3("      💾 Preserved ID {$pres['id']}: {$pres['dateFrom']} → {$pres['dateTo']} (from source {$pres['sourceId']})");
                    }
                }
                if (!empty($rangeAdjustedClosed)) {
                    logV3("   � Adjusted closed: " . count($rangeAdjustedClosed));
                }
                if (!empty($rangeBlockedDates)) {
                    logV3("   ⛔ Blocked dates: " . implode(', ', array_keys($rangeBlockedDates)));
                }

                logV3("");
                logV3("🚀 [STEP 3] CREATING NEW QUOTAS FOR RANGE " . ($rangeIndex + 1));
                logV3("   📅 Will create quotas for: " . implode(', ', $rangeDates));

                foreach ($rangeDates as $dateIdx => $date) {
                    logV3("");
                    logV3("   � [DATE " . ($dateIdx + 1) . "/" . count($rangeDates) . "] PROCESSING: {$date}");
                    
                    if (isset($rangeBlockedDates[$date])) {
                        $blocked = $rangeBlockedDates[$date];
                        logV3("      ⛔ SKIPPED: Due to closed quota {$blocked['id']} ({$blocked['from']}-{$blocked['to']})");
                        
                        // SSE: Notify skipped quota
                        $processedQuotasCount++;
                        if ($this->sseHelper) {
                            $this->sseHelper->quotaError($date, "Übersprungen: Geschlossene Tage");
                        }
                        continue;
                    }

                    if (!isset($quotasByDate[$date])) {
                        logV3("      ⚠️ SKIPPED: No quota data found for {$date}");
                        
                        // SSE: Notify skipped quota
                        $processedQuotasCount++;
                        if ($this->sseHelper) {
                            $this->sseHelper->quotaError($date, "Keine Quota-Daten gefunden");
                        }
                        continue;
                    }

                    logV3("      ✓ Found quota data for {$date}");
                    
                    foreach ($quotasByDate[$date] as $quotaIndex => $quota) {
                        logV3("      📦 Processing quota data #{$quotaIndex} for {$date}");
                        
                        $quantities = [];
                        $totalQuantity = 0;
                        foreach ($this->categoryMap as $categoryName => $categoryId) {
                            $fieldName = 'quota_' . $categoryName;
                            $quantity = isset($quota[$fieldName]) ? (int)$quota[$fieldName] : 0;
                            $quantities[$categoryName] = max(0, $quantity);
                            $totalQuantity += $quantities[$categoryName];
                        }

                        logV3("      💯 Calculated quotas: L={$quantities['lager']}, B={$quantities['betten']}, DZ={$quantities['dz']}, S={$quantities['sonder']} (Total: {$totalQuantity})");
                        logV3("      🚀 CALLING createQuota() for {$date}...");

                        // SSE: Notify quota creation started
                        $processedQuotasCount++;
                        if ($this->sseHelper) {
                            $this->sseHelper->progressUpdate(
                                $processedQuotasCount,
                                $totalQuotasToProcess,
                                "Erstelle Quota für {$date}... ({$processedQuotasCount}/{$totalQuotasToProcess})"
                            );
                        }

                        $quotaCreation = $this->createQuota($date, $quantities, 'SERVICED');
                        if ($quotaCreation !== false) {
                            $createdQuotas[] = [
                                'date' => $date,
                                'quantities' => $quantities,
                                'id' => ($quotaCreation === true) ? null : $quotaCreation,
                                'range' => $rangeIndex + 1
                            ];
                            
                                // SSE: Notify successful quota creation with detailed info
                            if ($this->sseHelper) {
                                $this->sseHelper->quotaCreated($date, $quantities, [
                                    'id' => ($quotaCreation === true) ? null : $quotaCreation,
                                    'total_capacity' => $totalQuantity,
                                    'current' => $processedQuotasCount,
                                    'total' => $totalQuotasToProcess,
                                    'range' => $rangeIndex + 1
                                ]);
                                
                                // Force immediate file write for real-time updates
                                if (function_exists('fastcgi_finish_request')) {
                                    fastcgi_finish_request();
                                }
                            }
                            
                            // Log with timestamp for debugging
                            logV3("✅ [" . date('H:i:s.u') . "] Quota created for {$date} - SSE event sent");
                            logV3(sprintf(
                                "      ✅ SUCCESS: Created quota ID %s for %s (L=%d, B=%d, DZ=%d, S=%d) [Range %d]",
                                ($quotaCreation === true ? 'n/a' : $quotaCreation),
                                $date,
                                $quantities['lager'],
                                $quantities['betten'],
                                $quantities['dz'],
                                $quantities['sonder'],
                                $rangeIndex + 1
                            ));
                        } else {
                            logV3("      ❌ FAILED: Could not create quota for {$date} [Range " . ($rangeIndex + 1) . "]");
                        }
                    }
                }
            }

            // ✅ ERWEITERTE REIMPORT-BEREICHE für Quota-Splitting
            // Sammle alle betroffenen Daten: benutzergewählte + gelöschte Quota-Bereiche
            $allAffectedDates = $uniqueDates; // Start mit benutzergewählten Daten
            
            // Füge alle Daten von gelöschten Quotas hinzu (diese können gesplittet worden sein)
            foreach ($deletedQuotas as $deleted) {
                if (isset($deleted['from']) && isset($deleted['to'])) {
                    // Konvertiere HRS-Format (dd.mm.yyyy) zu Y-m-d
                    $fromParts = explode('.', $deleted['from']);
                    $toParts = explode('.', $deleted['to']);
                    
                    if (count($fromParts) === 3 && count($toParts) === 3) {
                        $fromDate = $fromParts[2] . '-' . str_pad($fromParts[1], 2, '0', STR_PAD_LEFT) . '-' . str_pad($fromParts[0], 2, '0', STR_PAD_LEFT);
                        $toDate = $toParts[2] . '-' . str_pad($toParts[1], 2, '0', STR_PAD_LEFT) . '-' . str_pad($toParts[0], 2, '0', STR_PAD_LEFT);
                        
                        $allAffectedDates[] = $fromDate;
                        $allAffectedDates[] = $toDate;
                    }
                }
            }
            
            // Füge auch alle Daten von erhaltenen Quota-Segmenten hinzu
            foreach ($preservedQuotas as $preserved) {
                if (isset($preserved['from']) && isset($preserved['to'])) {
                    $fromParts = explode('.', $preserved['from']);
                    $toParts = explode('.', $preserved['to']);
                    
                    if (count($fromParts) === 3 && count($toParts) === 3) {
                        $fromDate = $fromParts[2] . '-' . str_pad($fromParts[1], 2, '0', STR_PAD_LEFT) . '-' . str_pad($fromParts[0], 2, '0', STR_PAD_LEFT);
                        $toDate = $toParts[2] . '-' . str_pad($toParts[1], 2, '0', STR_PAD_LEFT) . '-' . str_pad($toParts[0], 2, '0', STR_PAD_LEFT);
                        
                        $allAffectedDates[] = $fromDate;
                        $allAffectedDates[] = $toDate;
                    }
                }
            }
            
            // Erweitere um +/-1 Tag für Sicherheit (falls Splitting an Rändern)
            $allAffectedDates = array_unique($allAffectedDates);
            $expandedDates = [];
            
            foreach ($allAffectedDates as $date) {
                $dateObj = DateTime::createFromFormat('Y-m-d', $date);
                if ($dateObj) {
                    // Original-Datum
                    $expandedDates[] = $dateObj->format('Y-m-d');
                    // -1 Tag
                    $expandedDates[] = (clone $dateObj)->modify('-1 day')->format('Y-m-d');
                    // +1 Tag  
                    $expandedDates[] = (clone $dateObj)->modify('+1 day')->format('Y-m-d');
                }
            }
            
            $finalImportDates = array_unique($expandedDates);
            sort($finalImportDates);
            
            logV3("♻️ V3: Expanded import range from " . count($uniqueDates) . " user dates to " . count($finalImportDates) . " total dates (includes splitting buffer)");
            logV3("   Original user dates: " . implode(', ', $uniqueDates));
            logV3("   Final import range: " . min($finalImportDates) . " → " . max($finalImportDates) . " (" . count($finalImportDates) . " days)");

            $localRefresh = $this->refreshLocalQuotaCache($finalImportDates);

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

            // SSE: Notify processing completed
            if ($this->sseHelper) {
                $this->sseHelper->processingCompleted([
                    'created' => count($createdQuotas),
                    'deleted' => count($deletedQuotas),
                    'adjusted_closed' => count($adjustedClosed),
                    'preserved' => count($preservedQuotas),
                    'message' => implode(', ', $messageParts)
                ]);
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
                'processedRanges' => count($contiguousRanges),
                'affectedDateRange' => [
                    'from' => min($finalImportDates),
                    'to' => max($finalImportDates)
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

            // ✅ ERWEITERTE SUCHE: Längere Zeitspanne um auch benachbarte überlappende Quotas zu finden
            $dateFrom = date('d.m.Y', strtotime($minDate . ' -60 days'));
            $dateTo = date('d.m.Y', strtotime($maxDate . ' +60 days'));

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
                    
                    // ✅ KORREKTUR: HRS API liefert quotaEndRaw als EXKLUSIV
                    // Für interne Verarbeitung brauchen wir INKLUSIV (letzter echter Tag)
                    // REGEL: quotaEndRaw ist EXKLUSIV vom HRS API
                    //        quotaEndInclusive = quotaEndRaw - 1 Tag
                    $quotaEndInclusive = (clone $quotaEndRaw)->modify('-1 day');
                    
                    // Für Day-Range-Expansion verwenden wir INKLUSIVE Grenzen
                    if ($quotaEndInclusive < $quotaStart) {
                        // Fallback für beschädigte Daten: mindestens 1 Tag
                        $quotaEndInclusive = clone $quotaStart;
                    }
                    
                    logV3("🔄 V3: Date conversion for quota {$quotaId}: API EXCLUSIVE ({$quotaStart->format('Y-m-d')} → {$quotaEndRaw->format('Y-m-d')}) => INTERNAL INCLUSIVE ({$quotaStart->format('Y-m-d')} → {$quotaEndInclusive->format('Y-m-d')})");
                    
                    // Für die weitere Verarbeitung verwenden wir quotaEnd = quotaEndInclusive
                    $quotaEnd = $quotaEndInclusive;

                    logV3(sprintf(
                        "🧮 V3: Quota %s range %s -> %s (rawEnd=%s, diff=%d)",
                        $quotaId,
                        $quotaStart->format('Y-m-d'),
                        $quotaEnd->format('Y-m-d'),
                        $quotaEndRaw->format('Y-m-d'),
                        $diffDays
                    ));

                    // expandDayRange erwartet EXKLUSIV end, aber $quotaEnd ist jetzt INKLUSIV
                    // Konvertiere zurück zu EXKLUSIV für expandDayRange
                    $quotaEndExclusiveForExpansion = (clone $quotaEnd)->modify('+1 day');
                    $daysInQuota = $this->expandDayRange($quotaStart, $quotaEndExclusiveForExpansion);
                    if (empty($daysInQuota)) {
                        logV3("⚠️ V3: Quota {$quotaId} produced no day list (skipped)");
                        continue;
                    }

                    logV3("🔍 Checking overlap for quota {$quotaId}:");
                    logV3("   📅 Quota days: " . implode(', ', $daysInQuota));
                    logV3("   🎯 Selected dates: " . implode(', ', array_keys($selectedDateSet)));
                    
                    $intersectionDays = [];
                    foreach ($daysInQuota as $day) {
                        if (isset($selectedDateSet[$day])) {
                            $intersectionDays[] = $day;
                            logV3("   ✅ OVERLAP found: $day");
                        }
                    }

                    logV3("   📊 Total intersections: " . count($intersectionDays) . " (" . implode(', ', $intersectionDays) . ")");

                    if (empty($intersectionDays)) {
                        logV3("   ⏭️ No overlap, skipping quota {$quotaId}");
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
            
            if (empty($toProcess)) {
                logV3("✅ V3: No overlapping quotas found - nothing to delete");
                return $result;
            }
            
            logV3("");
            logV3("📋 OVERLAPPING QUOTAS FOUND:");
            foreach ($toProcess as $qId => $qInfo) {
                $qDays = $qInfo['days'];
                $qIntersect = $qInfo['intersections'];
                logV3("   📅 Quota ID {$qId}: {$qInfo['displayFrom']} → {$qInfo['displayTo']} ({$qInfo['mode']})");
                logV3("      🔍 Quota days: " . implode(', ', $qDays));
                logV3("      ⚡ Intersections: " . implode(', ', $qIntersect));
            }

            foreach ($toProcess as $quotaId => $info) {
                $displayFrom = $info['displayFrom'] ?? $info['start']->format('Y-m-d');
                $displayTo = $info['displayTo'] ?? $info['end']->format('Y-m-d');

                // ✅ PHASE 3: Lückenlose Erhaltung - Alle Tage außer Intersection
                $quotaDays = $info['days'];
                $intersectionDays = $info['intersections'];
                
                logV3("🔍 DETAILED ANALYSIS for Quota ID $quotaId:");
                logV3("   📅 Original quota days: " . implode(', ', $quotaDays));
                logV3("   ❌ Intersection days (to remove): " . implode(', ', $intersectionDays));
                
                // Erstelle Set für schnelle Lookup der zu überschreibenden Tage
                $intersectionSet = array_fill_keys($intersectionDays, true);
                logV3("   🔍 Intersection set keys: " . implode(', ', array_keys($intersectionSet)));
                
                // Sammle ALLE Tage die behalten werden sollen (alles außer intersections)
                $daysToPreserve = [];
                foreach ($quotaDays as $day) {
                    if (!isset($intersectionSet[$day])) {
                        $daysToPreserve[] = $day;
                        logV3("   ✅ PRESERVING day: $day");
                    } else {
                        logV3("   ❌ REMOVING day: $day (found in intersection set)");
                    }
                }
                
                logV3("   💾 FINAL days to preserve: " . implode(', ', $daysToPreserve));
                
                // ✅ Erstelle zusammenhängende Segmente aus allen zu erhaltenden Tagen
                $segmentsToPreserve = $this->buildSegmentsFromDayList($daysToPreserve);
                
                logV3("   🧩 Generated " . count($segmentsToPreserve) . " segments to preserve:");
                foreach ($segmentsToPreserve as $idx => $seg) {
                    $segDays = $seg['dates'] ?? [];
                    logV3("     Segment " . ($idx + 1) . ": {$seg['start']} → {$seg['end']} (Days: " . implode(', ', $segDays) . ")");
                }

                $deleteCompletely = empty($segmentsToPreserve);

                logV3("");
                logV3("🔄 PROCESSING QUOTA ID {$quotaId} ({$displayFrom} - {$displayTo})");
                logV3("   📝 Decision: " . ($deleteCompletely ? 'FULL DELETE' : 'SPLIT INTO ' . count($segmentsToPreserve) . ' SEGMENTS'));
                
                if (!$deleteCompletely) {
                    $segmentPreview = [];
                    foreach ($segmentsToPreserve as $segment) {
                        $start = $segment['start'];
                        $end = $segment['end'];
                        $segmentPreview[] = ($start === $end) ? $start : "{$start}→{$end}";
                    }
                    
                    logV3("   🎯 DETAILED SPLIT ANALYSIS:");
                    logV3("   � Original range: $displayFrom → $displayTo");
                    logV3("   📝 Original days (" . count($quotaDays) . "): " . implode(', ', $quotaDays));
                    logV3("   ❌ Days to remove (" . count($intersectionDays) . "): " . implode(', ', $intersectionDays));
                    logV3("   💾 Days to preserve (" . count($daysToPreserve) . "): " . implode(', ', $daysToPreserve));
                    logV3("   🧩 Segments to create (" . count($segmentsToPreserve) . "):");
                    
                    foreach ($segmentsToPreserve as $idx => $segment) {
                        $start = $segment['start'];
                        $end = $segment['end'];
                        $segDays = $segment['dates'] ?? [];
                        $preview = ($start === $end) ? $start : "{$start}→{$end}";
                        logV3("     → Segment " . ($idx + 1) . ": $preview (Days: " . implode(', ', $segDays) . ")");
                    }
                    logV3("   � Days to preserve: " . implode(', ', $daysToPreserve));
                }

                logV3("   🗑️ EXECUTING DELETE for quota ID {$quotaId}...");
                
                // SSE: Notify quota deletion started
                if ($this->sseHelper) {
                    $this->sseHelper->quotaDeleted($displayFrom . ($displayFrom !== $displayTo ? ' - ' . $displayTo : ''), $quotaId);
                }
                
                if ($this->deleteQuotaViaAPI($quotaId)) {
                    $result['deleted'][] = [
                        'id' => $quotaId,
                        'from' => $displayFrom,
                        'to' => $displayTo,
                        'mode' => $info['mode'] ?? null,
                        'action' => $deleteCompletely ? 'full' : 'split'
                    ];
                    logV3("   ✅ SUCCESS: Deleted quota ID {$quotaId}");
                    
                    // ✅ TIMING FIX: Kurze Pause damit die Löschung committed wird
                    if (!$deleteCompletely) {
                        logV3("   ⏱️ Waiting 500ms for deletion to commit before recreating segments...");
                        usleep(500000); // 500ms Pause
                    }

                    $preservedSuccessDays = [];

                    // ✅ Erstelle alle erhaltenen Segmente
                    if (!$deleteCompletely) {
                        $categories = $info['categoryValues'];
                        $preserveMode = $info['mode'] ?? 'SERVICED';
                        $preserveMode = $preserveMode ? strtoupper($preserveMode) : 'SERVICED';
                        $forcePreserve = !empty($info['isClosed']);

                        $baseTitle = $this->resolveQuotaField($info['raw'], ['title']);
                        if (!is_string($baseTitle) || $baseTitle === '') {
                            $baseTitle = 'Timeline Split Quota';
                        }
                        $maxBaseLength = 60; // Reserviere 20 Zeichen für " (Split X)"

                        logV3("");
                        logV3("   🔄 CREATING " . count($segmentsToPreserve) . " PRESERVED SEGMENT(S):");
                        logV3("   📦 Base quota info - Mode: $preserveMode, Force: " . ($forcePreserve ? 'YES' : 'NO'));
                        logV3("   📦 Categories: L=" . $categories['lager'] . ", B=" . $categories['betten'] . ", DZ=" . $categories['dz'] . ", S=" . $categories['sonder']);
                        logV3("");
                        logV3("   📋 SEGMENT CREATION SEQUENCE:");

                        foreach ($segmentsToPreserve as $segmentIndex => $segmentInfo) {
                            $segmentDays = $segmentInfo['dates'] ?? [];
                            if (empty($segmentDays)) {
                                logV3("   ⚠️ SKIPPING empty segment " . ($segmentIndex + 1));
                                continue;
                            }

                            $segmentStart = $segmentInfo['start'];
                            $segmentEnd = $segmentInfo['end'];

                            logV3("   � SEGMENT " . ($segmentIndex + 1) . "/" . count($segmentsToPreserve) . ":");
                            logV3("      📅 Range: {$segmentStart} → {$segmentEnd}");
                            logV3("      📝 Days (" . count($segmentDays) . "): " . implode(', ', $segmentDays));
                            logV3("      🏷️  Title: " . sprintf('%s (Split %d)', $baseTitle, $segmentIndex + 1));
                            logV3("      🚀 CALLING createQuota() for segment...");

                            $trimmedTitle = $baseTitle;
                            if (strlen($trimmedTitle) > $maxBaseLength) {
                                $trimmedTitle = substr($trimmedTitle, 0, $maxBaseLength);
                            }

                            $titleForRange = sprintf('%s (Split %d)', $trimmedTitle, $segmentIndex + 1);

                            try {
                                $newId = $this->createQuota(
                                    $segmentStart, 
                                    $categories, 
                                    $preserveMode, 
                                    $titleForRange,
                                    $forcePreserve,
                                    $segmentEnd
                                );

                                if ($newId) {
                                    $result['splitCreated'][] = [
                                        'sourceId' => $quotaId,
                                        'dateFrom' => $segmentStart,
                                        'dateTo' => $segmentEnd,
                                        'id' => $newId,
                                        'quantities' => $categories,
                                        'days' => $segmentDays
                                    ];
                                    logV3("     ✅ SUCCESS: Preserved segment created with ID {$newId}");
                                    logV3("     ✅ Segment covers days: " . implode(', ', $segmentDays));
                                    foreach ($segmentDays as $preservedDay) {
                                        $preservedSuccessDays[$preservedDay] = true;
                                    }
                                    
                                    // ✅ SEGMENT SPACING: Kurze Pause zwischen Segment-Erstellungen
                                    if (($segmentIndex + 1) < count($segmentsToPreserve)) {
                                        logV3("     ⏱️ Waiting 200ms before creating next segment...");
                                        usleep(200000); // 200ms zwischen Segmenten
                                    }
                                } else {
                                    logV3("     ❌ FAILED: Could not create preserved segment (returned false/null)");
                                }
                            } catch (Exception $preserveEx) {
                                $errorMsg = $preserveEx->getMessage();
                                logV3("   ⚠️ Failed to recreate preserved segment {$segmentStart} to {$segmentEnd}: " . $errorMsg);
                                
                                // ✅ OVERLAP DEBUGGING: Spezielle Behandlung für overlap-Fehler
                                if (strpos($errorMsg, 'Cannot overlap quotas') !== false || strpos($errorMsg, 'overlap') !== false) {
                                    logV3("   🚨 OVERLAP ERROR DETECTED! This indicates another quota exists in this range.");
                                    logV3("   🔍 Segment details: {$segmentStart} → {$segmentEnd}, Days: " . implode(', ', $segmentDays));
                                    logV3("   💡 Possible causes: Race condition, incomplete deletion, or existing overlapping quota");
                                    
                                    // ✅ IMMEDIATE RE-SEARCH für diesen spezifischen Bereich
                                    logV3("   🔍 IMMEDIATE RE-SEARCH: Looking for quotas in failed range...");
                                    $debugSearchFrom = date('d.m.Y', strtotime($segmentStart . ' -7 days'));
                                    $debugSearchTo = date('d.m.Y', strtotime($segmentEnd . ' +7 days'));
                                    logV3("   🔍 DEBUG SEARCH RANGE: {$debugSearchFrom} → {$debugSearchTo}");
                                    
                                    try {
                                        $debugUrl = "/api/v1/manage/hutQuota?hutId={$this->hutId}&page=0&size=50&sortList=BeginDate&sortOrder=ASC&open=true&dateFrom={$debugSearchFrom}&dateTo={$debugSearchTo}";
                                        $debugResponse = $this->hrsLogin->makeRequest($debugUrl, 'GET', null, ['X-XSRF-TOKEN' => $this->hrsLogin->getCsrfToken()]);
                                        
                                        if ($debugResponse && isset($debugResponse['body'])) {
                                            $debugData = json_decode($debugResponse['body'], true);
                                            if ($debugData && isset($debugData['_embedded']['bedCapacityChangeResponseDTOList'])) {
                                                $debugQuotas = $debugData['_embedded']['bedCapacityChangeResponseDTOList'];
                                                logV3("   🔍 DEBUG SEARCH FOUND " . count($debugQuotas) . " quota(s) in search range:");
                                                
                                                foreach ($debugQuotas as $dq) {
                                                    $dqId = $dq['id'] ?? 'unknown';
                                                    $dqFrom = $this->resolveQuotaField($dq, ['beginDate', 'dateFrom']);
                                                    $dqTo = $this->resolveQuotaField($dq, ['endDate', 'dateTo']);
                                                    logV3("     🎯 CONFLICTING QUOTA ID {$dqId}: {$dqFrom} → {$dqTo}");
                                                }
                                            } else {
                                                logV3("   🔍 DEBUG SEARCH: No quotas found in range - API issue?");
                                            }
                                        } else {
                                            logV3("   🔍 DEBUG SEARCH FAILED: No response from API");
                                        }
                                    } catch (Exception $debugEx) {
                                        logV3("   🔍 DEBUG SEARCH EXCEPTION: " . $debugEx->getMessage());
                                    }
                                }
                                
                                $result['preserveFailed'][] = [
                                    'sourceId' => $quotaId,
                                    'dateFrom' => $segmentStart,
                                    'dateTo' => $segmentEnd,
                                    'error' => $errorMsg,
                                    'errorType' => (strpos($errorMsg, 'overlap') !== false) ? 'OVERLAP' : 'OTHER'
                                ];
                            }
                        }
                    }
                } else {
                    logV3("   ❌ FAILED: Could not delete quota ID {$quotaId}");
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
            
            // ✅ KORREKTUR: Konsistente INKLUSIV→EXKLUSIV Konvertierung
            // REGEL: Unsere interne Logik arbeitet mit INKLUSIVEN Daten
            //        HRS API erwartet EXKLUSIVE dateTo (letzter Tag + 1)
            if ($dateTo) {
                // dateTo Parameter ist INKLUSIV (letzter Tag der Quota)
                // API erwartet EXKLUSIV (letzter Tag + 1)
                $date_to = date('Y-m-d', strtotime($dateTo . ' +1 day'));
                logV3("📅 V3: Multi-day quota: {$date} → {$dateTo} (INCLUSIVE) => API dateTo: {$date_to} (EXCLUSIVE)");
            } else {
                // Single-day quota: API dateTo = date + 1
                $date_to = date('Y-m-d', strtotime($date . ' +1 day'));
                logV3("📅 V3: Single-day quota: {$date} => API dateTo: {$date_to} (EXCLUSIVE)");
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
    
    // Check for SSE session ID
    $sseSessionId = $_GET['sse_session'] ?? $input['sse_session'] ?? null;
    
    // Process quotas
    $writer = new QuotaWriterV3($mysqli, $hrsLogin, $sseSessionId);
    $result = $writer->updateQuotas($input['quotas']);
    
    // Add SSE session ID to response
    if ($sseSessionId) {
        $result['sse_session'] = $sseSessionId;
    }
    
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
