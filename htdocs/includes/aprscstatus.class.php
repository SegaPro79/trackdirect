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
        $cacheFile = self::getCacheFilePath();
        $now = time();

        if (is_readable($cacheFile)) {
            $cachedPayload = json_decode((string) file_get_contents($cacheFile), true);
            if (
                is_array($cachedPayload)
                && isset($cachedPayload['timestamp'])
                && ($now - (int) $cachedPayload['timestamp']) < self::CACHE_TTL
                && isset($cachedPayload['data'])
            ) {
                return self::coerceSummary($cachedPayload['data']);
            }
        }

        $summary = [
            'connected' => false,
            'users_online' => null,
        ];

        $response = self::fetchStatus(self::STATUS_URL);
        if ($response !== null && $response['ok']) {
            $parsed = self::parseJson($response['body']);
            $summary = array_merge($summary, $parsed);
        }

        $summary = self::coerceSummary($summary);

        if ($summary['connected']) {
            self::writeCache($cacheFile, $now, $summary);
        }

        return $summary;
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
     * @return array{connected: bool, users_online: ?int}
     */
    private static function parseJson(string $payload): array
    {
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            return [
                'connected' => false,
                'users_online' => null,
            ];
        }

        $usersOnline = null;

        if (isset($decoded['totals']['clients']) && is_numeric($decoded['totals']['clients'])) {
            $usersOnline = (int) $decoded['totals']['clients'];
        } elseif (
            isset($decoded['listeners'])
            && is_array($decoded['listeners'])
            && isset($decoded['listeners'][0])
            && is_array($decoded['listeners'][0])
            && isset($decoded['listeners'][0]['clients'])
            && is_numeric($decoded['listeners'][0]['clients'])
        ) {
            $usersOnline = (int) $decoded['listeners'][0]['clients'];
        }

        return [
            'connected' => true,
            'users_online' => $usersOnline,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{connected: bool, users_online: ?int}
     */
    private static function coerceSummary(array $data): array
    {
        $connected = !empty($data['connected']);
        $usersOnline = null;
        if (array_key_exists('users_online', $data)) {
            $value = $data['users_online'];
            if (is_numeric($value)) {
                $usersOnline = (int) $value;
            }
        }

        return [
            'connected' => $connected,
            'users_online' => $usersOnline,
        ];
    }

    private static function writeCache(string $cacheFile, int $timestamp, array $summary): void
    {
        @file_put_contents($cacheFile, json_encode([
            'timestamp' => $timestamp,
            'data' => $summary,
        ]));
    }

}
