<?php

class AprscStatus
{
    private const STATUS_URL = 'https://aprsc.aprsdirect.de/';
    private const CACHE_TTL = 10; // seconds

    /**
     * Fetch and summarize APRSC status metrics.
     *
     * @return array{
     *     users_online: ?int,
     *     pkts_tx: ?int,
     *     pkts_rx: ?int,
     *     pkts_rtx: ?int,
     *     tx_active: bool,
     *     rx_active: bool
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

        $previousData = [];
        if (is_array($cachedPayload) && isset($cachedPayload['data']) && is_array($cachedPayload['data'])) {
            $previousData = $cachedPayload['data'];
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

        $context = stream_context_create([
            'http' => [
                'timeout' => 3,
                'ignore_errors' => true,
                'header' => "User-Agent: APRSdirect Layout Updater\r\n",
            ],
        ]);

        $html = @file_get_contents(self::STATUS_URL, false, $context);
        if ($html !== false && $html !== '') {
            $parsed = self::parseHtml($html);
            $summary = array_merge($summary, array_intersect_key($parsed, $summary));
            $summary['connected'] = true;
        }

        $summary['tx_active'] = self::isTrafficActive($summary['pkts_tx'], $previousData['pkts_tx'] ?? null);
        $summary['rx_active'] = self::isTrafficActive($summary['pkts_rx'], $previousData['pkts_rx'] ?? null);

        if ($summary['connected']) {
            @file_put_contents($cacheFile, json_encode([
                'timestamp' => $now,
                'data' => $summary,
            ]));
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
     *     rx_active: bool
     * }
     */
    private static function normalizeSummary(array $data): array
    {
        return [
            'connected' => !empty($data['connected']),
            'users_online' => isset($data['users_online']) ? self::castValue($data['users_online']) : null,
            'pkts_tx' => isset($data['pkts_tx']) ? self::castValue($data['pkts_tx']) : null,
            'pkts_rx' => isset($data['pkts_rx']) ? self::castValue($data['pkts_rx']) : null,
            'pkts_rtx' => isset($data['pkts_rtx']) ? self::castValue($data['pkts_rtx']) : null,
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
            $result['users_online'] = self::extractNumberFromHtml($html, ['users online', 'listeners', 'clients']);
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

    private static function matchesAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (strpos($haystack, $needle) !== false) {
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
            $numeric = str_replace([' ', ','], ['', ''], $matches[1]);
            $numeric = str_replace('..', '.', $numeric);
            $numeric = str_replace('.', '', $numeric);
            if ($numeric !== '') {
                return (int) $numeric;
            }
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

        if (is_numeric($value)) {
            return (int) $value;
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
}
