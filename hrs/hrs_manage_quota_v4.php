<?php
/**
 * HRS Quota Management API V4
 * ===========================
 *
 * Zentrale Schnittstelle zum Löschen, Erstellen und Ändern von Quotas
 * direkt über die bewährte HRS Management API.
 *
 * FEATURES
 * --------
 * - Login über bestehende HRSLogin-Klasse (Session + CSRF)
 * - Automatische Aufteilung mehrtägiger Quotas in eintägige Segmente
 * - Optionales Vorab-Löschen aller überlappenden Quotas im Zielzeitraum
 * - Update einzelner Quotas per HRS-ID
 * - Replikation bestehender Quotas beim Splitten, damit keine Tage verloren gehen
 * - Umfangreiches Logging zur Analyse
 *
 * INPUT (JSON)
 * ------------
 * {
 *   "actions": [
 *     { "type": "delete", "hrs_id": 44892 },
 *     { "type": "create", "title": "test", "dateFrom": "14.02.2027", "dateTo": "16.02.2027",
 *       "hutBedCategoryDTOs": [...], "reservationMode": "SERVICED" },
 *     { "type": "update", "id": 44809, "dateFrom": "12.02.2026", "dateTo": "13.02.2026",
 *       "hutBedCategoryDTOs": [...] }
 *   ],
 *   "options": {
 *     "splitMultiDay": true,
 *     "cleanupExisting": true,
 *     "canOverbook": false,
 *     "canChangeMode": false,
 *     "allSeries": false
 *   }
 * }
 *
 * OUTPUT (JSON)
 * -------------
 * {
 *   "success": true,
 *   "deleted": [...],
 *   "updated": [...],
 *  "created": [...],
 *   "logs": [...]
 * }
 */

ob_start();
ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/hrs_login.php';

class HRSQuotaManagerV4 {
    private $mysqli;
    private $hrsLogin;
    private $hutId = 675;

    private $categoryMap = [
        'lager'  => 1958,
        'betten' => 2293,
        'dz'     => 2381,
        'sonder' => 6106
    ];

    private $logs = [];

    public function __construct($mysqli, HRSLogin $hrsLogin) {
        $this->mysqli = $mysqli;
        $this->hrsLogin = $hrsLogin;
    }

    /**
     * Einstiegspunkt für alle Aktionen.
     *
     * @param array $actions
     * @param array $options
     * @return array
     * @throws Exception
     */
    public function handle(array $actions, array $options = []): array {
        if (empty($actions)) {
            throw new Exception('Keine Aktionen übergeben');
        }

        $options = array_merge([
            'splitMultiDay'   => true,
            'cleanupExisting' => true,
            'canOverbook'     => false,
            'canChangeMode'   => false,
            'allSeries'       => false
        ], $options);

        $prepared = [
            'delete' => [],
            'update' => [],
            'create' => []
        ];

        $targetDayData = [];   // Y-m-d => payload (für neue Werte)
        $updateIds = [];       // HRS-IDs die später ge-updatet werden (nicht löschen)

        // 1) Aktionen normalisieren & aufteilen
        foreach ($actions as $idx => $actionRaw) {
            if (!is_array($actionRaw)) {
                throw new Exception("Aktion #$idx ist kein gültiges Objekt");
            }

            $type = strtolower(trim($actionRaw['type'] ?? ''));
            if (!in_array($type, ['delete', 'create', 'update'], true)) {
                throw new Exception("Aktion #$idx hat unbekannten Typ '{$actionRaw['type']}'");
            }

            if ($type === 'delete') {
                $hrsId = (int)($actionRaw['hrs_id'] ?? $actionRaw['id'] ?? 0);
                if ($hrsId <= 0) {
                    throw new Exception("Delete-Aktion #$idx ohne gültige hrs_id");
                }
                $prepared['delete'][] = [
                    'hrs_id'        => $hrsId,
                    'canOverbook'   => $this->boolOption($actionRaw, 'canOverbook', $options['canOverbook']),
                    'canChangeMode' => $this->boolOption($actionRaw, 'canChangeMode', $options['canChangeMode']),
                    'allSeries'     => $this->boolOption($actionRaw, 'allSeries', $options['allSeries']),
                    'source'        => 'input'
                ];
                $this->logs[] = "🗑️ Request DELETE für HRS-ID {$hrsId}";
                continue;
            }

            // Ab hier Create / Update
            $dateFrom = $actionRaw['dateFrom'] ?? $actionRaw['date_from'] ?? $actionRaw['date'] ?? null;
            $dateTo   = $actionRaw['dateTo'] ?? $actionRaw['date_to'] ?? null;
            if (!$dateFrom) {
                throw new Exception("Aktion #$idx ohne dateFrom");
            }
            if (!$dateTo) {
                // Falls nur einzelnes Datum gegeben ist → +1 Tag
                $dateTo = $this->formatDisplayDate(
                    $this->parseDateString($dateFrom, "Aktion #$idx dateFrom")
                        ->modify('+1 day')
                );
            }

            $segments = $this->splitIntoDailySegments($dateFrom, $dateTo);
            if (empty($segments)) {
                throw new Exception("Aktion #$idx konnte nicht in Tagessegmente aufgeteilt werden");
            }

            if ($type === 'update' && count($segments) > 1) {
                // Multi-Day Update → Delete + Create je Tag
                $originalId = (int)($actionRaw['id'] ?? 0);
                if ($originalId <= 0) {
                    throw new Exception("Update-Aktion #$idx ohne gültige id");
                }

                $prepared['delete'][] = [
                    'hrs_id'        => $originalId,
                    'canOverbook'   => $this->boolOption($actionRaw, 'canOverbook', $options['canOverbook']),
                    'canChangeMode' => $this->boolOption($actionRaw, 'canChangeMode', $options['canChangeMode']),
                    'allSeries'     => $this->boolOption($actionRaw, 'allSeries', $options['allSeries']),
                    'source'        => 'auto-split-update'
                ];
                $this->logs[] = "🔀 Update-Aktion #$idx für HRS-ID {$originalId} wird in Delete + tägliche Create-Aktionen gesplittet";

                foreach ($segments as $segment) {
                    $dailyPayload = $this->buildQuotaPayload($actionRaw, $segment['start'], $segment['end'], $options, false);
                    $prepared['create'][] = [
                        'payload' => $dailyPayload,
                        'source'  => 'update-split'
                    ];
                    $dayKey = $segment['start']->format('Y-m-d');
                    $targetDayData[$dayKey][] = $dailyPayload;
                }
                continue;
            }

            if ($type === 'update') {
                $hrsId = (int)($actionRaw['id'] ?? 0);
                if ($hrsId <= 0) {
                    throw new Exception("Update-Aktion #$idx ohne gültige id");
                }
                $updateIds[] = $hrsId;
            }

            foreach ($segments as $segment) {
                $dailyPayload = $this->buildQuotaPayload(
                    $actionRaw,
                    $segment['start'],
                    $segment['end'],
                    $options,
                    $type === 'update'
                );

                if ($type === 'update') {
                    $prepared['update'][] = [
                        'payload' => $dailyPayload,
                        'source'  => 'input'
                    ];
                    $dayKey = $segment['start']->format('Y-m-d');
                    $targetDayData[$dayKey][] = $dailyPayload;
                    $this->logs[] = "✏️ Update für HRS-ID {$dailyPayload['id']} am {$dayKey}";
                } else {
                    $prepared['create'][] = [
                        'payload' => $dailyPayload,
                        'source'  => 'input'
                    ];
                    $dayKey = $segment['start']->format('Y-m-d');
                    $targetDayData[$dayKey][] = $dailyPayload;
                    $this->logs[] = "➕ Create vorbereitet für {$dayKey} (Titel {$dailyPayload['title']})";
                }
            }
        }

        // 2) Bestehende Quotas im Zeitraum löschen & replizieren falls nötig
        if ($options['cleanupExisting'] && !empty($targetDayData)) {
            $uniqueDays = array_keys($targetDayData);
            sort($uniqueDays);
            $minDay = $this->parseDateString($uniqueDays[0], 'cleanup-min');
            $maxDay = $this->parseDateString(end($uniqueDays), 'cleanup-max');

            // Puffer hinzufügen (damit multi-day Quotas sicher erfasst werden)
            $rangeStart = (clone $minDay)->modify('-1 day');
            $rangeEnd   = (clone $maxDay)->modify('+1 day');

            $existing = $this->fetchHRSQuotas($rangeStart, $rangeEnd);
            $this->logs[] = "🔍 Cleanup: Gefundene HRS-Quotas im Zeitraum {$rangeStart->format('Y-m-d')} bis {$rangeEnd->format('Y-m-d')}: " . count($existing);

            foreach ($existing as $quota) {
                $quotaId = (int)($quota['id'] ?? 0);
                if ($quotaId <= 0) {
                    continue;
                }
                if (in_array($quotaId, $updateIds, true)) {
                    // Wird unmittelbar aktualisiert → nicht löschen
                    $this->logs[] = "⏭️ Überspringe HRS-ID {$quotaId} (geplant für Update)";
                    continue;
                }

                $segments = $this->splitIntoDailySegments($quota['dateFrom'], $quota['dateTo']);
                if (empty($segments)) {
                    continue;
                }

                $overlaps = false;
                foreach ($segments as $segment) {
                    $dayKey = $segment['start']->format('Y-m-d');
                    if (isset($targetDayData[$dayKey])) {
                        $overlaps = true;
                        break;
                    }
                }

                if (!$overlaps) {
                    // Kein Zieltag betroffen → optional splitten?
                    if ($options['splitMultiDay'] && count($segments) > 1) {
                        // Multi-Day in Einzel-Tage replizieren, falls explizit gefordert
                        $this->logs[] = "⚠️ Multi-Day Quota {$quotaId} wird zur Vereinheitlichung gesplittet";
                        $prepared['delete'][] = [
                            'hrs_id'        => $quotaId,
                            'canOverbook'   => $options['canOverbook'],
                            'canChangeMode' => $options['canChangeMode'],
                            'allSeries'     => $options['allSeries'],
                            'source'        => 'auto-split-cleanup'
                        ];
                        foreach ($segments as $segment) {
                            $payload = $this->buildPayloadFromExistingQuota($quota, $segment['start'], $segment['end'], $options);
                            $prepared['create'][] = [
                                'payload' => $payload,
                                'source'  => 'auto-split-cleanup'
                            ];
                        }
                    }
                    continue;
                }

                // Quota muss entfernt werden (überschneidet Zieltage)
                $prepared['delete'][] = [
                    'hrs_id'        => $quotaId,
                    'canOverbook'   => $options['canOverbook'],
                    'canChangeMode' => $options['canChangeMode'],
                    'allSeries'     => $options['allSeries'],
                    'source'        => 'auto-overlap'
                ];
                $this->logs[] = "🗑️ Bestehende Quota {$quotaId} wird entfernt (Overlap)";

                // Für alle Tage ohne neue Zielwerte alte Quota replizieren
                foreach ($segments as $segment) {
                    $dayKey = $segment['start']->format('Y-m-d');
                    if (isset($targetDayData[$dayKey])) {
                        continue; // Dieser Tag bekommt neue Werte
                    }
                    $payload = $this->buildPayloadFromExistingQuota($quota, $segment['start'], $segment['end'], $options);
                    $prepared['create'][] = [
                        'payload' => $payload,
                        'source'  => 'auto-replicate'
                    ];
                    $this->logs[] = "🔁 Repliziere ursprüngliche Quota {$quotaId} für Tag {$dayKey}";
                }
            }
        }

        // Delete-Aktionen nach HRS-ID eindeutigen
        $prepared['delete'] = $this->dedupeDeletes($prepared['delete']);

        // 3) Ausführung
        $deleteResults = [];
        foreach ($prepared['delete'] as $deleteAction) {
            $deleteResults[] = $this->performDelete($deleteAction);
        }

        $updateResults = [];
        foreach ($prepared['update'] as $updateAction) {
            $updateResults[] = $this->performUpdate($updateAction['payload']);
        }

        $createResults = [];
        foreach ($prepared['create'] as $createAction) {
            $createResults[] = $this->performCreate($createAction['payload']);
        }

        $overallSuccess = $this->aggregateSuccess($deleteResults, $updateResults, $createResults);

        return [
            'success' => $overallSuccess,
            'deleted' => $deleteResults,
            'updated' => $updateResults,
            'created' => $createResults,
            'logs'    => $this->logs
        ];
    }

    /**
     * Führt einen Delete-Call gegen die HRS API aus.
     */
    private function performDelete(array $action): array {
        $hrsId = (int)$action['hrs_id'];
        $query = http_build_query([
            'hutId'        => $this->hutId,
            'quotaId'      => $hrsId,
            'canChangeMode'=> $action['canChangeMode'] ? 'true' : 'false',
            'canOverbook'  => $action['canOverbook'] ? 'true' : 'false',
            'allSeries'    => $action['allSeries'] ? 'true' : 'false'
        ]);
        $url = "/api/v1/manage/deleteQuota?{$query}";

        $headers = [
            'X-XSRF-TOKEN: ' . $this->hrsLogin->getCsrfToken(),
            'Accept: application/json, text/plain, */*',
            'Origin: https://www.hut-reservation.org',
            'Referer: https://www.hut-reservation.org/hut/manage-hut/' . $this->hutId,
            'Cookie: ' . $this->buildCookieHeader()
        ];

        $response = $this->hrsLogin->makeRequest($url, 'DELETE', null, $headers);
        $status = $response['status'] ?? 0;
        $body   = $response['body'] ?? '';
        $decoded = $body ? json_decode($body, true) : null;

        $success = ($status === 200);
        if ($decoded && isset($decoded['messageId'])) {
            if ((int)$decoded['messageId'] === 126) {
                $success = true; // Already deleted / treated as success
            } elseif ((int)$decoded['messageId'] === 121) {
                $success = false;
            }
        }

        $result = [
            'hrs_id' => $hrsId,
            'httpCode' => $status,
            'response' => $body,
            'success' => $success,
            'source'  => $action['source'] ?? 'n/a'
        ];

        if (!$success) {
            $this->logs[] = "❌ DELETE fehlgeschlagen für {$hrsId}: HTTP {$status}";
        } else {
            $this->logs[] = "✅ DELETE erfolgreich für {$hrsId}";
        }

        return $result;
    }

    /**
     * Führt einen Update-Call gegen die HRS API aus.
     */
    private function performUpdate(array $payload): array {
        $payload['id'] = (int)($payload['id'] ?? 0);
        if ($payload['id'] <= 0) {
            return [
                'success' => false,
                'message' => 'Update ohne gültige ID',
                'payload' => $payload
            ];
        }

        $result = $this->sendQuotaPayload($payload);
        if (!($result['success'] ?? false)) {
            $message = $result['message'] ?? 'Unbekannt';
            $this->logs[] = "❌ UPDATE fehlgeschlagen für ID {$payload['id']}: {$message}";
        } else {
            $this->logs[] = "✅ UPDATE erfolgreich für ID {$payload['id']}";
        }

        return $result;
    }

    /**
     * Führt einen Create-Call gegen die HRS API aus.
     */
    private function performCreate(array $payload): array {
        // Für neue Quotas sollte id = -1 oder 0 sein
        $payload['id'] = isset($payload['id']) ? (int)$payload['id'] : -1;
        if ($payload['id'] >= 0) {
            $payload['id'] = -1;
        }

        $result = $this->sendQuotaPayload($payload);
        if (!($result['success'] ?? false)) {
            $message = $result['message'] ?? 'Unbekannt';
            $this->logs[] = "❌ CREATE fehlgeschlagen ({$payload['title']}): {$message}";
        } else {
            $hrsId = $result['hrsId'] ?? 'n/a';
            $this->logs[] = "✅ CREATE erfolgreich ({$payload['title']} → HRS-ID {$hrsId})";
        }

        return $result;
    }

    /**
     * Sendet Payload (Create oder Update) an die HRS-API.
     */
    private function sendQuotaPayload(array $payload): array {
        $url = "https://www.hut-reservation.org/api/v1/manage/hutQuota/{$this->hutId}";
        $cookieHeader = $this->buildCookieHeader();

        $headers = [
            'Accept: application/json, text/plain, */*',
            'Content-Type: application/json',
            'Origin: https://www.hut-reservation.org',
            'Referer: https://www.hut-reservation.org/hut/manage-hut/' . $this->hutId,
            'X-XSRF-TOKEN: ' . $this->hrsLogin->getCsrfToken(),
            'Cookie: ' . $cookieHeader
        ];

        $jsonPayload = json_encode($payload);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true
        ]);

        $responseBody = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $result = [
            'payload'  => $payload,
            'httpCode' => $httpCode,
            'response' => $responseBody
        ];

        if ($curlError) {
            $result['success'] = false;
            $result['message'] = "cURL Error: {$curlError}";
            return $result;
        }

        $decoded = json_decode($responseBody, true);
        if ($decoded !== null) {
            $result['decoded'] = $decoded;
        }

        if ($httpCode !== 200) {
            $result['success'] = false;
            $result['message'] = "HTTP {$httpCode}";
            return $result;
        }

        if ($decoded && isset($decoded['statusCode']) && (int)$decoded['statusCode'] >= 400) {
            $result['success'] = false;
            $result['message'] = $decoded['description'] ?? ('HTTP ' . $decoded['statusCode']);
            return $result;
        }

        if ($decoded && isset($decoded['messageId'])) {
            $messageId = (int)$decoded['messageId'];
            if ($messageId === 120) {
                $result['success'] = true;
                $result['hrsId'] = isset($decoded['param1']) ? (int)$decoded['param1'] : null;
                return $result;
            }
            if ($messageId === 121) {
                $result['success'] = false;
                $result['message'] = $decoded['description'] ?? 'Cannot overlap quotas';
                return $result;
            }
        }

        // Fallback: Wenn keine Fehlermeldung erkennbar, als Erfolg interpretieren
        $result['success'] = true;
        return $result;
    }

    /**
     * Holt HRS quotas für einen Zeitraum.
     */
    private function fetchHRSQuotas(DateTime $from, DateTime $to): array {
        $results = [];
        $page = 0;
        $maxPages = 25;

        do {
            $url = sprintf(
                '/api/v1/manage/hutQuota?hutId=%d&page=%d&size=200&sortList=BeginDate&sortOrder=ASC&open=true&dateFrom=%s&dateTo=%s',
                $this->hutId,
                $page,
                $from->format('d.m.Y'),
                $to->format('d.m.Y')
            );

            $headers = [
                'X-XSRF-TOKEN: ' . $this->hrsLogin->getCsrfToken()
            ];

            $response = $this->hrsLogin->makeRequest($url, 'GET', null, $headers);
            if (!$response || !isset($response['body'])) {
                $this->logs[] = "⚠️ fetchHRSQuotas: Keine Response (page {$page})";
                break;
            }

            $decoded = json_decode($response['body'], true);
            if ($decoded === null) {
                $this->logs[] = "⚠️ fetchHRSQuotas: JSON Decode Error (page {$page})";
                break;
            }

            $list = null;
            if (isset($decoded['_embedded']['bedCapacityChangeResponseDTOList']) && is_array($decoded['_embedded']['bedCapacityChangeResponseDTOList'])) {
                $list = $decoded['_embedded']['bedCapacityChangeResponseDTOList'];
            } elseif (isset($decoded['content']) && is_array($decoded['content'])) {
                $list = $decoded['content'];
            } elseif (isset($decoded['items']) && is_array($decoded['items'])) {
                $list = $decoded['items'];
            } elseif (is_array($decoded)) {
                $list = $decoded;
            }

            if (!$list) {
                break;
            }

            foreach ($list as $item) {
                $normalized = [
                    'id'                => $item['id'] ?? ($item['quotaId'] ?? null),
                    'title'             => $item['title'] ?? '',
                    'reservationMode'   => $item['mode'] ?? ($item['reservationMode'] ?? 'SERVICED'),
                    'dateFrom'          => $item['dateFrom'] ?? ($item['beginDate'] ?? null),
                    'dateTo'            => $item['dateTo'] ?? ($item['endDate'] ?? null),
                    'hutBedCategoryDTOs'=> $item['hutBedCategoryDTOs'] ?? ($item['categories'] ?? []),
                    'raw'               => $item
                ];

                if ($normalized['id'] && $normalized['dateFrom'] && $normalized['dateTo']) {
                    $results[] = $normalized;
                }
            }

            $totalPages = $decoded['page']['totalPages'] ?? ($decoded['totalPages'] ?? ($decoded['totalPage'] ?? ($decoded['total_page'] ?? null)));
            if ($totalPages === null) {
                $hasMore = count($list) === 200;
            } else {
                $hasMore = ($page + 1) < (int)$totalPages;
            }

            $page++;
        } while ($page < $maxPages && $hasMore);

        return $results;
    }

    /**
     * Baut Payload anhand bestehender Quota (z.B. für Replikation).
     */
    private function buildPayloadFromExistingQuota(array $quota, DateTime $start, DateTime $end, array $options): array {
        $base = [
            'title'             => $quota['title'] ?? ('Timeline-' . $start->format('dmy')),
            'reservationMode'   => $quota['reservationMode'] ?? 'SERVICED',
            'hutBedCategoryDTOs'=> $quota['hutBedCategoryDTOs'] ?? [],
            'canOverbook'       => $options['canOverbook'],
            'canChangeMode'     => $options['canChangeMode'],
            'allSeries'         => $options['allSeries']
        ];
        return $this->buildQuotaPayload($base, $start, $end, $options, false);
    }

    /**
     * Erstellt vollständiges Payload-Array für Create/Update.
     */
    private function buildQuotaPayload(array $action, DateTime $start, DateTime $end, array $options, bool $includeId): array {
        $categories = $this->extractCategories($action);

        $payload = [
            'id'                => $includeId ? (int)($action['id'] ?? 0) : -1,
            'title'             => $action['title'] ?? ('Timeline-' . $start->format('dmy')),
            'reservationMode'   => $action['reservationMode'] ?? 'SERVICED',
            'isRecurring'       => $action['isRecurring'] ?? null,
            'capacity'          => array_sum($categories),
            'languagesDataDTOs' => $this->buildLanguagePayload($action),
            'hutBedCategoryDTOs'=> $this->buildHutBedCategoryDTOs($categories),
            'monday'            => $action['monday'] ?? null,
            'tuesday'           => $action['tuesday'] ?? null,
            'wednesday'         => $action['wednesday'] ?? null,
            'thursday'          => $action['thursday'] ?? null,
            'friday'            => $action['friday'] ?? null,
            'saturday'          => $action['saturday'] ?? null,
            'sunday'            => $action['sunday'] ?? null,
            'weeksRecurrence'   => $action['weeksRecurrence'] ?? null,
            'occurrencesNumber' => $action['occurrencesNumber'] ?? null,
            'seriesBeginDate'   => $action['seriesBeginDate'] ?? '',
            'dateFrom'          => $this->formatDisplayDate($start),
            'dateTo'            => $this->formatDisplayDate($end),
            'canOverbook'       => $this->boolOption($action, 'canOverbook', $options['canOverbook']),
            'canChangeMode'     => $this->boolOption($action, 'canChangeMode', $options['canChangeMode']),
            'allSeries'         => $this->boolOption($action, 'allSeries', $options['allSeries'])
        ];

        if (isset($action['seriesEndDate'])) {
            $payload['seriesEndDate'] = $action['seriesEndDate'];
        }

        return $payload;
    }

    /**
     * Baut kategorien-Array als DTO-Liste.
     */
    private function buildHutBedCategoryDTOs(array $categories): array {
        return [
            ['categoryId' => $this->categoryMap['lager'],  'totalBeds' => $categories['lager']],
            ['categoryId' => $this->categoryMap['betten'], 'totalBeds' => $categories['betten']],
            ['categoryId' => $this->categoryMap['dz'],     'totalBeds' => $categories['dz']],
            ['categoryId' => $this->categoryMap['sonder'], 'totalBeds' => $categories['sonder']]
        ];
    }

    /**
     * Extrahiert Kategorien aus Aktion (unterstützt unterschiedliche Schlüssel).
     */
    private function extractCategories(array $action): array {
        $categories = [
            'lager'  => 0,
            'betten' => 0,
            'dz'     => 0,
            'sonder' => 0
        ];

        if (isset($action['hutBedCategoryDTOs']) && is_array($action['hutBedCategoryDTOs'])) {
            foreach ($action['hutBedCategoryDTOs'] as $dto) {
                if (!isset($dto['categoryId'])) continue;
                foreach ($this->categoryMap as $name => $id) {
                    if ((int)$dto['categoryId'] === $id) {
                        $categories[$name] = (int)($dto['totalBeds'] ?? 0);
                    }
                }
            }
        }

        if (isset($action['categories']) && is_array($action['categories'])) {
            foreach ($categories as $name => $value) {
                if (isset($action['categories'][$name])) {
                    $categories[$name] = (int)$action['categories'][$name];
                }
            }
        }

        $mapKeys = [
            'quota_lager'  => 'lager',
            'quota_betten' => 'betten',
            'quota_dz'     => 'dz',
            'quota_sonder' => 'sonder'
        ];
        foreach ($mapKeys as $key => $name) {
            if (isset($action[$key])) {
                $categories[$name] = (int)$action[$key];
            }
        }

        return $categories;
    }

    /**
     * Baut Sprach-Payload (DE/EN, optional custom).
     */
    private function buildLanguagePayload(array $action): array {
        $languages = $action['languagesDataDTOs'] ?? null;
        if (is_array($languages) && !empty($languages)) {
            return $languages;
        }

        $descriptionDE = $action['description_de'] ?? ($action['description'] ?? '');
        $descriptionEN = $action['description_en'] ?? '';

        return [
            ['language' => 'DE_DE', 'description' => $descriptionDE],
            ['language' => 'EN',    'description' => $descriptionEN]
        ];
    }

    /**
     * Teilt Datumsbereich in eintägige Segmente (DateTime Objekte).
     *
     * @return array<int,array{start:DateTime,end:DateTime}>
     * @throws Exception
     */
    private function splitIntoDailySegments($dateFrom, $dateTo): array {
        $start = $this->parseDateString($dateFrom, 'dateFrom');
        $end   = $this->parseDateString($dateTo, 'dateTo');

        if (!$end || $end <= $start) {
            $end = (clone $start)->modify('+1 day');
        }

        $segments = [];
        $cursor = clone $start;
        while ($cursor < $end) {
            $next = (clone $cursor)->modify('+1 day');
            $segments[] = [
                'start' => clone $cursor,
                'end'   => clone $next
            ];
            $cursor = $next;
        }

        return $segments;
    }

    /**
     * Konvertiert Datum in Anzeigeformat dd.mm.YYYY.
     */
    private function formatDisplayDate(DateTime $dt): string {
        return $dt->format('d.m.Y');
    }

    /**
     * Deduplication für Delete-Aktionen nach HRS-ID.
     */
    private function dedupeDeletes(array $deleteActions): array {
        $seen = [];
        $result = [];
        foreach ($deleteActions as $action) {
            $hrsId = (int)$action['hrs_id'];
            if (isset($seen[$hrsId])) {
                continue;
            }
            $seen[$hrsId] = true;
            $result[] = $action;
        }
        return $result;
    }

    private function boolOption(array $action, string $key, bool $default): bool {
        if (!array_key_exists($key, $action)) {
            return $default;
        }
        $value = $action[$key];
        if (is_bool($value)) return $value;
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }
        return (bool)$value;
    }

    /**
     * Parse Datumsstring (unterstützt dd.mm.YYYY & YYYY-MM-DD & ISO).
     *
     * @throws Exception
     */
    private function parseDateString($value, string $context): DateTime {
        if (!$value) {
            throw new Exception("Ungültiges Datum ({$context})");
        }

        $value = trim($value);
        if (preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $value)) {
            $dt = DateTime::createFromFormat('d.m.Y', $value);
            if ($dt === false) {
                throw new Exception("Datum konnte nicht geparst werden ({$context}={$value})");
            }
            return $dt;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return new DateTime($value);
        }

        try {
            return new DateTime($value);
        } catch (Exception $e) {
            throw new Exception("Datum konnte nicht geparst werden ({$context}={$value})");
        }
    }

    /**
     * Baut Cookie-Header aus Login-Cookies.
     */
    private function buildCookieHeader(): string {
        $cookies = $this->hrsLogin->getCookies();
        $parts = [];
        foreach ($cookies as $name => $value) {
            $parts[] = "{$name}={$value}";
        }
        return implode('; ', $parts);
    }

    /**
     * Aggregiert Erfolg aller Operationen.
     */
    private function aggregateSuccess(array ...$lists): bool {
        foreach ($lists as $list) {
            foreach ($list as $entry) {
                if (!($entry['success'] ?? false)) {
                    return false;
                }
            }
        }
        return true;
    }
}

// ============ MAIN EXECUTION ============

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        throw new Exception('Ungültiger JSON-Input');
    }

    $actions = $input['actions'] ?? null;
    if (!is_array($actions) || empty($actions)) {
        throw new Exception('actions Array fehlt oder ist leer');
    }

    $options = $input['options'] ?? [];

    if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
        throw new Exception('Keine Datenbankverbindung verfügbar (config.php prüfen)');
    }

    $hrsLogin = new HRSLogin();
    if (!$hrsLogin->login()) {
        throw new Exception('HRS Login fehlgeschlagen');
    }

    $manager = new HRSQuotaManagerV4($mysqli, $hrsLogin);
    $result = $manager->handle($actions, $options);

    ob_end_clean();
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
        'trace'   => $e->getTraceAsString()
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
