<?php

class AprscStatus
{
    /**
     * Candidate endpoints. The helper will iterate until it finds a response it can parse.
     *
     * @var array<int, string>
     */
    private const STATUS_URLS = [
        'https://aprsc.aprsdirect.de/json',
        'https://aprsc.aprsdirect.de/status.json',
        'https://aprsc.aprsdirect.de/status_json',
        'https://aprsc.aprsdirect.de/status-json',
        'https://aprsc.aprsdirect.de/status?format=json',
        'https://aprsc.aprsdirect.de/?format=json',
        'https://aprsc.aprsdirect.de/',
    ];

    private const CACHE_TTL = 10; // seconds
    private const REQUEST_HEADERS = "User-Agent: APRSdirect Layout Updater\r\n"
        . "Accept: application/json, text/plain;q=0.9, */*;q=0.8\r\n"
        . "Accept-Language: en-US,en;q=0.9\r\n"
        . "Cache-Control: no-cache\r\n"
        . "Pragma: no-cache\r\n";

    /**
     * Fetch and summarize APRSC status metrics.
     *
     * @return array{
     *     users_online: ?int,
     *     pkts_tx: ?int,
     *     pkts_rx: ?int,
     *     pkts_rtx: ?int,
     *     tx_active: bool,
     *     rx_active: bool,
     *     connected: bool
     * }
     */
    public static function getSummary(): array
    {
        $cacheFile = self::getCacheFilePath();
        $now = time();
        $cachedPayload = null;

        if (is_readable($cacheFile)) {
            $cachedPayload = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cachedPayload) && isset($cachedPayload['timestamp']) && ($now - (int) $cachedPayload['timestamp']) < self::CACHE_TTL) {
                return self::normalizeSummary($cachedPayload['data'] ?? []);
            }
        }

        $defaults = [
            'connected' => false,
            'users_online' => null,
            'pkts_tx' => null,
            'pkts_rx' => null,
            'pkts_rtx' => null,
            'tx_active' => false,
            'rx_active' => false,
        ];

        $previousData = [];
        if (is_array($cachedPayload) && isset($cachedPayload['data']) && is_array($cachedPayload['data'])) {
            $previousData = $cachedPayload['data'];
        }

        $previousSummary = self::normalizeSummary(array_merge($defaults, $previousData));

        $summary = $defaults;

        foreach (self::STATUS_URLS as $endpoint) {
            $response = self::fetchStatus($endpoint);
            if ($response === null || !$response['ok']) {
                continue;
            }

            $parsed = self::parseResponse($response['body'], $response['headers']);
            if (!empty($parsed)) {
                foreach (['users_online', 'pkts_tx', 'pkts_rx', 'pkts_rtx'] as $metricKey) {
                    if (array_key_exists($metricKey, $parsed) && $parsed[$metricKey] !== null) {
                        $summary[$metricKey] = $parsed[$metricKey];
                    }
                }

                if (array_key_exists('connected', $parsed)) {
                    $summary['connected'] = $parsed['connected'];
                }

                if ($summary['users_online'] !== null && $summary['pkts_tx'] !== null && $summary['pkts_rx'] !== null) {
                    break;
                }
            }
        }

        $summary['users_online'] = self::castValue($summary['users_online'] ?? null);
        $summary['pkts_tx'] = self::castValue($summary['pkts_tx'] ?? null);
        $summary['pkts_rx'] = self::castValue($summary['pkts_rx'] ?? null);
        $summary['pkts_rtx'] = self::castValue($summary['pkts_rtx'] ?? null);

        if (array_key_exists('connected', $summary)) {
            $summary['connected'] = !empty($summary['connected']);
        }

        if (!$summary['connected'] && self::hasNumericMetrics($summary)) {
            $summary['connected'] = true;
        }

        if ($summary['connected']) {
            $summary['tx_active'] = self::isTrafficActive($summary['pkts_tx'], $previousSummary['pkts_tx']);
            $summary['rx_active'] = self::isTrafficActive($summary['pkts_rx'], $previousSummary['pkts_rx']);
        } else {
            $summary['tx_active'] = false;
            $summary['rx_active'] = false;
        }

        $summary = self::normalizeSummary($summary);

        if ($summary['connected']) {
            self::writeCache($cacheFile, $now, $summary);
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{
     *     users_online: ?int,
     *     pkts_tx: ?int,
     *     pkts_rx: ?int,
     *     pkts_rtx: ?int,
     *     tx_active: bool,
     *     rx_active: bool,
     *     connected: bool
     * }
     */
    private static function normalizeSummary(array $data): array
    {
        return [
            'connected' => !empty($data['connected']),
            'users_online' => array_key_exists('users_online', $data) ? self::castValue($data['users_online']) : null,
            'pkts_tx' => array_key_exists('pkts_tx', $data) ? self::castValue($data['pkts_tx']) : null,
            'pkts_rx' => array_key_exists('pkts_rx', $data) ? self::castValue($data['pkts_rx']) : null,
            'pkts_rtx' => array_key_exists('pkts_rtx', $data) ? self::castValue($data['pkts_rtx']) : null,
            'tx_active' => !empty($data['tx_active']),
            'rx_active' => !empty($data['rx_active']),
        ];
    }

    /**
     * @param array<int, string> $headers
     */
    private static function isHttpStatusSuccessful(array $headers): bool
    {
        if (!isset($headers[0])) {
            return false;
        }

        return (bool) preg_match('/^HTTP\/\d+\.\d+\s+2\d\d\b/', $headers[0]);
    }

    private static function getCacheFilePath(): string
    {
        $tmpDir = sys_get_temp_dir();
        if (!is_dir($tmpDir) || !is_writable($tmpDir)) {
            $tmpDir = ROOT . '/cache';
            if (!is_dir($tmpDir)) {
                @mkdir($tmpDir, 0777, true);
            }
        }

        return rtrim($tmpDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'aprsc_status_summary.json';
    }

    /**
     * @return array{body: string, headers: array<int, string>, ok: bool}|null
     */
    private static function fetchStatus(string $url): ?array
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 4,
                'ignore_errors' => true,
                'header' => self::REQUEST_HEADERS,
            ],
        ]);

        $payload = @file_get_contents($url, false, $context);
        if ($payload === false || $payload === '') {
            return null;
        }

        global $http_response_header;
        $headers = [];
        if (isset($http_response_header) && is_array($http_response_header)) {
            $headers = $http_response_header;
        }

        return [
            'body' => $payload,
            'headers' => $headers,
            'ok' => self::isHttpStatusSuccessful($headers),
        ];
    }

    /**
     * @param array<int, string> $headers
     * @return array<string, int|null|bool>
     */
    private static function parseResponse(string $payload, array $headers): array
    {
        if ($payload === '') {
            return [];
        }

        $contentType = self::detectContentType($headers);
        if (self::looksLikeJson($payload, $contentType)) {
            $parsed = self::parseJsonPayload($payload);
            if (!empty($parsed)) {
                return $parsed;
            }
        }

        return self::parseHtml($payload);
    }

    /**
     * @return array<string, int|null|bool>
     */
    private static function parseJsonPayload(string $payload): array
    {
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            return [];
        }

        return self::extractMetricsFromJson($decoded);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, int|null|bool>
     */
    private static function extractMetricsFromJson(array $data): array
    {
        $result = [];

        $usersOnline = self::extractClientsFromJson($data);
        if ($usersOnline !== null) {
            $result['users_online'] = $usersOnline;
        }

        $pktsRx = self::extractTrafficFromJson($data, ['rx', 'receive', 'received', 'inbound', 'in', 'input']);
        if ($pktsRx !== null) {
            $result['pkts_rx'] = $pktsRx;
        }

        $pktsTx = self::extractTrafficFromJson($data, ['tx', 'transmit', 'transmitted', 'outbound', 'out', 'sent', 'output']);
        if ($pktsTx !== null) {
            $result['pkts_tx'] = $pktsTx;
        }

        $pktsRtx = self::extractTrafficFromJson($data, ['rtx', 'retry', 'retransmit', 'resend', 'retries']);
        if ($pktsRtx !== null) {
            $result['pkts_rtx'] = $pktsRtx;
        }

        if (self::hasNumericMetrics($result)) {
            $result['connected'] = true;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function extractClientsFromJson(array $data): ?int
    {
        $paths = [
            ['clients', 'total'],
            ['clients', 'connected'],
            ['clients', 'current'],
            ['status', 'clients', 'total'],
            ['status', 'listeners', 'total'],
            ['global', 'clients', 'total'],
        ];

        foreach ($paths as $path) {
            $value = self::resolvePath($data, $path);
            $numeric = self::castValue($value);
            if ($numeric !== null) {
                return $numeric;
            }
        }

        return self::extractNumericByKeyPatterns($data, ['clients', 'client', 'listeners', 'users'], ['total', 'count', 'value', 'connected', 'current']);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, string> $primaryKeys
     */
    private static function extractTrafficFromJson(array $data, array $primaryKeys): ?int
    {
        $sections = [];
        $containers = [$data];

        foreach (['traffic', 'totals', 'counts', 'statistics', 'stats'] as $containerKey) {
            if (isset($data[$containerKey]) && is_array($data[$containerKey])) {
                $containers[] = $data[$containerKey];
            }
        }

        foreach ($containers as $container) {
            if (!is_array($container)) {
                continue;
            }

            foreach ($primaryKeys as $key) {
                if (isset($container[$key])) {
                    $sections[] = $container[$key];
                }
            }

            foreach (['counts', 'totals', 'packets', 'frames'] as $subKey) {
                if (isset($container[$subKey]) && is_array($container[$subKey])) {
                    foreach ($primaryKeys as $key) {
                        if (isset($container[$subKey][$key])) {
                            $sections[] = $container[$subKey][$key];
                        }
                    }
                }
            }
        }

        foreach ($sections as $section) {
            $numeric = self::extractNumericCandidate($section, ['total', 'packets', 'frames', 'count', 'value']);
            if ($numeric !== null) {
                return $numeric;
            }
        }

        return self::extractNumericByKeyPatterns($data, $primaryKeys, ['total', 'packets', 'frames', 'count', 'value']);
    }

    /**
     * @return array<string, int|null>
     */
    private static function parseHtml(string $html): array
    {
        $result = [];

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        if ($dom->loadHTML($html)) {
            $xpath = new DOMXPath($dom);
            foreach ($xpath->query('//tr') as $row) {
                $cells = [];
                foreach ($row->childNodes as $cell) {
                    if ($cell instanceof DOMElement && in_array(strtolower($cell->tagName), ['td', 'th'], true)) {
                        $cells[] = trim(preg_replace('/\s+/', ' ', $cell->textContent));
                    }
                }

                $cellsCount = count($cells);
                if ($cellsCount === 0) {
                    continue;
                }

                $label = strtolower($cells[0]);

                if (!array_key_exists('users_online', $result) || $result['users_online'] === null) {
                    if (self::matchesAny($label, ['users online', 'listeners', 'clients'])) {
                        $value = self::extractFirstNumber(array_slice($cells, 1));
                        if ($value !== null) {
                            $result['users_online'] = $value;
                        }
                        continue;
                    }
                }

                $isTotalsRow = self::matchesAny($label, ['packets', 'frames', 'traffic']);

                if ($cellsCount >= 3 && $isTotalsRow) {
                    if (!isset($result['pkts_rx'])) {
                        $result['pkts_rx'] = self::extractNumber($cells[1]);
                    }
                    if (!isset($result['pkts_tx'])) {
                        $result['pkts_tx'] = self::extractNumber($cells[2]);
                    }
                    if ($cellsCount >= 4 && !isset($result['pkts_rtx'])) {
                        $result['pkts_rtx'] = self::extractNumber($cells[3]);
                    }
                    continue;
                }

                if ($cellsCount >= 2) {
                    if (!isset($result['pkts_tx']) && self::matchesAny($label, ['tx', 'transmit'])) {
                        $result['pkts_tx'] = self::extractNumber($cells[1]);
                        continue;
                    }
                    if (!isset($result['pkts_rx']) && self::matchesAny($label, ['rx', 'receive'])) {
                        $result['pkts_rx'] = self::extractNumber($cells[1]);
                        continue;
                    }
                    if (!isset($result['pkts_rtx']) && self::matchesAny($label, ['rtx', 'retry'])) {
                        $result['pkts_rtx'] = self::extractNumber($cells[1]);
                        continue;
                    }
                }
            }
        }
        libxml_clear_errors();

        if (!isset($result['users_online'])) {
            $result['users_online'] = self::extractClientsCountFromHtml($html);
            if ($result['users_online'] === null) {
                $result['users_online'] = self::extractNumberFromHtml($html, ['users online', 'listeners', 'clients']);
            }
        }
        if (!isset($result['pkts_tx'])) {
            $result['pkts_tx'] = self::extractNumberFromHtml($html, ['tx', 'tx ok', 'transmit']);
        }
        if (!isset($result['pkts_rx'])) {
            $result['pkts_rx'] = self::extractNumberFromHtml($html, ['rx', 'receive']);
        }
        if (!isset($result['pkts_rtx'])) {
            $result['pkts_rtx'] = self::extractNumberFromHtml($html, ['rtx', 'retry']);
        }

        return $result;
    }

    private static function extractClientsCountFromHtml(string $html): ?int
    {
        $flags = ENT_QUOTES;
        if (defined('ENT_HTML5')) {
            $flags |= ENT_HTML5;
        }

        $decoded = html_entity_decode($html, $flags, 'UTF-8');

        $patterns = [
            '/clients[^<]*<[^>]*>([^<]+)<\/[^>]+>/i',
            '/clients[^\d]*([\d][\d\s,.]*)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $decoded, $matches)) {
                $value = $matches[1] ?? '';
                $number = self::extractNumber($value);
                if ($number !== null) {
                    return $number;
                }
            }
        }

        return null;
    }

    private static function matchesAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            $needleLower = strtolower($needle);
            if (strlen($needleLower) <= 2) {
                $pattern = sprintf('/(^|[^a-z0-9])%s($|[^a-z0-9])/i', preg_quote($needleLower, '/'));
                if (preg_match($pattern, $haystack)) {
                    return true;
                }
                continue;
            }

            if (strpos($haystack, $needleLower) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $values
     */
    private static function extractFirstNumber(array $values): ?int
    {
        foreach ($values as $value) {
            $number = self::extractNumber($value);
            if ($number !== null) {
                return $number;
            }
        }

        return null;
    }

    private static function extractNumber(string $value): ?int
    {
        if (preg_match('/([\d][\d\s,.]*)/', $value, $matches)) {
            $numeric = str_replace(["\xc2\xa0", ' '], '', $matches[1]);
            $numeric = str_replace(',', '.', $numeric);

            if ($numeric === '') {
                return null;
            }

            if (substr_count($numeric, '.') > 1) {
                $numeric = str_replace('.', '', $numeric);
            }

            $floatValue = (float) $numeric;

            if (!is_finite($floatValue)) {
                return null;
            }

            return (int) floor($floatValue + 0.5);
        }

        return null;
    }

    private static function extractNumberFromHtml(string $html, array $keywords): ?int
    {
        foreach ($keywords as $keyword) {
            $pattern = sprintf('/%s[^\d]*([\d][\d\s,.]*)/i', preg_quote($keyword, '/'));
            if (preg_match($pattern, $html, $matches)) {
                return self::extractNumber($matches[1]);
            }
        }

        return null;
    }

    private static function castValue($value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) round($value);
        }

        if (is_numeric($value)) {
            return (int) round((float) $value);
        }

        return self::extractNumber((string) $value);
    }

    private static function isTrafficActive(?int $currentValue, $previousValue): bool
    {
        $previous = self::castValue($previousValue);

        if ($currentValue === null || $previous === null) {
            return false;
        }

        if ($currentValue === $previous) {
            return false;
        }

        // Consider both increases and counter resets as activity.
        return true;
    }

    private static function hasNumericMetrics(array $summary): bool
    {
        foreach (['users_online', 'pkts_tx', 'pkts_rx', 'pkts_rtx'] as $key) {
            if (array_key_exists($key, $summary) && $summary[$key] !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, string> $path
     * @return mixed
     */
    private static function resolvePath(array $data, array $path)
    {
        $node = $data;
        foreach ($path as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }

        return $node;
    }

    private static function detectContentType(array $headers): string
    {
        foreach ($headers as $header) {
            if (stripos($header, 'content-type:') === 0) {
                return trim(substr($header, strlen('content-type:')));
            }
        }

        return '';
    }

    private static function looksLikeJson(string $payload, string $contentType): bool
    {
        if ($contentType !== '' && stripos($contentType, 'application/json') !== false) {
            return true;
        }

        $trimmed = ltrim($payload);

        return $trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[');
    }

    private static function writeCache(string $cacheFile, int $timestamp, array $summary): void
    {
        @file_put_contents($cacheFile, json_encode([
            'timestamp' => $timestamp,
            'data' => $summary,
        ]));
    }

    /**
     * @param array<string, mixed>|mixed $value
     */
    private static function extractNumericCandidate($value, array $preferredKeys): ?int
    {
        if (is_array($value)) {
            foreach ($preferredKeys as $preferredKey) {
                if (array_key_exists($preferredKey, $value)) {
                    $numeric = self::castValue($value[$preferredKey]);
                    if ($numeric !== null) {
                        return $numeric;
                    }
                }
            }

            $best = null;
            foreach ($value as $subValue) {
                $candidate = self::extractNumericCandidate($subValue, $preferredKeys);
                if ($candidate !== null) {
                    if ($best === null || $candidate > $best) {
                        $best = $candidate;
                    }
                }
            }

            return $best;
        }

        return self::castValue($value);
    }

    /**
     * @param array<string, mixed>|mixed $data
     * @param array<int, string> $patterns
     */
    private static function extractNumericByKeyPatterns($data, array $patterns, array $preferredKeys): ?int
    {
        $patterns = array_map('strtolower', $patterns);
        $candidates = [];
        self::collectNumericCandidates($data, $patterns, $preferredKeys, $candidates);

        if (empty($candidates)) {
            return null;
        }

        rsort($candidates, SORT_NUMERIC);

        return $candidates[0];
    }

    /**
     * @param array<string, mixed>|mixed $node
     * @param array<int, string> $patterns
     * @param array<int, string> $preferredKeys
     * @param array<int, int> $candidates
     */
    private static function collectNumericCandidates($node, array $patterns, array $preferredKeys, array &$candidates): void
    {
        if (!is_array($node)) {
            return;
        }

        foreach ($node as $key => $value) {
            $keyLower = is_string($key) ? strtolower($key) : '';

            if ($keyLower !== '' && self::matchesAny($keyLower, $patterns)) {
                $candidate = self::extractNumericCandidate($value, $preferredKeys);
                if ($candidate !== null) {
                    $candidates[] = $candidate;
                }
            }

            if (is_array($value)) {
                self::collectNumericCandidates($value, $patterns, $preferredKeys, $candidates);
                continue;
            }

            if ($keyLower !== '' && self::matchesAny($keyLower, $patterns)) {
                $candidate = self::castValue($value);
                if ($candidate !== null) {
                    $candidates[] = $candidate;
                }
            }
        }
    }
}
