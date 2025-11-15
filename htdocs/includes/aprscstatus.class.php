<?php

class AprscStatus
{
    private const STATUS_URL = 'http://127.0.0.1:14501/status.json';
    private const CACHE_TTL = 10; // seconds
    private const REQUEST_HEADERS = "User-Agent: APRSdirect Status Monitor\r\n"
        . "Accept: application/json\r\n"
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

        if (is_readable($cacheFile)) {
            $cachedPayload = json_decode((string) file_get_contents($cacheFile), true);
            if (
                is_array($cachedPayload)
                && isset($cachedPayload['timestamp'])
                && ($now - (int) $cachedPayload['timestamp']) < self::CACHE_TTL
            ) {
                return self::normalizeSummary($cachedPayload['data'] ?? []);
            }
        }

        $summary = [
            'connected' => false,
            'users_online' => null,
            'pkts_tx' => null,
            'pkts_rx' => null,
            'pkts_rtx' => null,
            'tx_active' => false,
            'rx_active' => false,
        ];

        $response = self::fetchStatus(self::STATUS_URL);
        if ($response !== null && $response['ok']) {
            $parsed = self::parseJson($response['body']);
            if (!empty($parsed)) {
                $summary = array_merge($summary, $parsed);
            }
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
     */
    private static function isHttpStatusSuccessful(array $headers): bool
    {
        if (!isset($headers[0])) {
            return false;
        }

        return (bool) preg_match('/^HTTP\/\d+\.\d+\s+2\d\d\b/', $headers[0]);
    }

    /**
     * @return array<string, int|null|bool>
     */
    private static function parseJson(string $payload): array
    {
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            return [];
        }

        $usersOnline = self::extractUsersOnline($decoded);

        $result = [
            'users_online' => $usersOnline,
        ];

        if ($usersOnline !== null) {
            $result['connected'] = true;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function extractUsersOnline(array $data): ?int
    {
        $paths = [
            ['clients', 'total'],
            ['clients', 'connected'],
            ['status', 'clients', 'total'],
            ['listeners', 'total'],
        ];

        foreach ($paths as $path) {
            $value = self::resolvePath($data, $path);
            $numeric = self::castValue($value);
            if ($numeric !== null) {
                return $numeric;
            }
        }

        if (isset($data['clients']) && is_array($data['clients'])) {
            $sum = 0;
            $found = false;
            foreach ($data['clients'] as $clientData) {
                if (!is_array($clientData)) {
                    continue;
                }

                foreach (['connected', 'inuse', 'current', 'count', 'clients', 'value', 'total'] as $key) {
                    if (!array_key_exists($key, $clientData)) {
                        continue;
                    }

                    $numeric = self::castValue($clientData[$key]);
                    if ($numeric !== null) {
                        $sum += $numeric;
                        $found = true;
                        break;
                    }
                }
            }

            if ($found) {
                return $sum;
            }
        }

        return null;
    }

    private static function writeCache(string $cacheFile, int $timestamp, array $summary): void
    {
        @file_put_contents($cacheFile, json_encode([
            'timestamp' => $timestamp,
            'data' => $summary,
        ]));
    }

    /**
     * @param mixed $value
     */
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

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '' || !preg_match('/[0-9]/', $trimmed)) {
                return null;
            }

            $normalized = str_replace([",", "\xc2\xa0", ' '], ['', '', ''], $trimmed);
            if (!is_numeric($normalized)) {
                $normalized = preg_replace('/[^0-9.-]/', '', $normalized ?? '');
            }

            if ($normalized === '' || !is_numeric($normalized)) {
                return null;
            }

            return (int) round((float) $normalized);
        }

        return null;
    }

    /**
     * @param array<string, mixed>|mixed $data
     * @param array<int, string> $path
     * @return mixed
     */
    private static function resolvePath($data, array $path)
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
}
