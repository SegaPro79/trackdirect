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
     *     connected: bool,
     *     users_online: ?int
     * }
     */
    public static function getSummary(): array
    {
        $cached = self::readCache();
        if ($cached !== null) {
            return $cached;
        }

        $summary = [
            'connected' => false,
            'users_online' => null,
        ];

        $statusData = self::fetchStatusData();
        if ($statusData === null) {
            return $summary;
        }

        $summary['connected'] = true;
        $summary['users_online'] = self::extractUsersOnline($statusData);

        self::writeCache($summary);

        return $summary;
    }

    private static function readCache(): ?array
    {
        $cacheFile = self::getCacheFilePath();
        if (!is_readable($cacheFile)) {
            return null;
        }

        $payload = json_decode((string) file_get_contents($cacheFile), true);
        if (
            !is_array($payload)
            || !isset($payload['timestamp'])
            || !isset($payload['data'])
        ) {
            return null;
        }

        if ((time() - (int) $payload['timestamp']) >= self::CACHE_TTL) {
            return null;
        }

        return self::normalizeSummary($payload['data']);
    }

    private static function writeCache(array $summary): void
    {
        if (!$summary['connected']) {
            return;
        }

        $cacheFile = self::getCacheFilePath();
        @file_put_contents($cacheFile, json_encode([
            'timestamp' => time(),
            'data' => $summary,
        ]));
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
     * @return array<string, mixed>|null
     */
    private static function fetchStatusData(): ?array
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 4,
                'ignore_errors' => true,
                'header' => self::REQUEST_HEADERS,
            ],
        ]);

        $payload = @file_get_contents(self::STATUS_URL, false, $context);
        if ($payload === false || $payload === '') {
            return null;
        }

        // file_get_contents populates $http_response_header when ignore_errors is true.
        if (isset($http_response_header) && is_array($http_response_header)) {
            $statusLine = $http_response_header[0] ?? '';
            if (is_string($statusLine) && preg_match('/^HTTP\/\S+\s+(\d+)/', $statusLine, $matches)) {
                $statusCode = (int) $matches[1];
                if ($statusCode < 200 || $statusCode >= 300) {
                    return null;
                }
            }
        }

        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function extractUsersOnline(array $data): ?int
    {
        if (isset($data['totals']['clients']) && is_numeric($data['totals']['clients'])) {
            return max(0, (int) $data['totals']['clients']);
        }

        if (
            isset($data['listeners'])
            && is_array($data['listeners'])
            && isset($data['listeners'][0])
            && is_array($data['listeners'][0])
            && isset($data['listeners'][0]['clients'])
            && is_numeric($data['listeners'][0]['clients'])
        ) {
            return max(0, (int) $data['listeners'][0]['clients']);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $summary
     * @return array{connected: bool, users_online: ?int}
     */
    private static function normalizeSummary(array $summary): array
    {
        $connected = !empty($summary['connected']);
        $usersOnline = null;

        if (array_key_exists('users_online', $summary) && is_numeric($summary['users_online'])) {
            $usersOnline = (int) $summary['users_online'];
        }

        return [
            'connected' => $connected,
            'users_online' => $usersOnline,
        ];
    }
}
