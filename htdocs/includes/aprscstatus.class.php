<?php

class AprscStatus
{
    private const STATUS_URL = 'http://127.0.0.1:14501/status.json';

    /**
     * Liefert nur:
     *  - connected: ob status.json erreicht und gelesen werden konnte
     *  - users_online: Anzahl Clients (totals.clients oder listeners[0].clients)
     */
    public static function getSummary(): array
    {
        $result = [
            'connected'    => false,
            'users_online' => null,
        ];

        $context = stream_context_create([
            'http' => [
                'timeout'       => 3,
                'ignore_errors' => true,
                'header'        =>
                    "User-Agent: APRSdirect Status Client\r\n" .
                    "Accept: application/json, text/plain;q=0.9, */*;q=0.8\r\n",
            ],
        ]);

        $body = @file_get_contents(self::STATUS_URL, false, $context);
        if ($body === false || $body === '') {
            return $result;
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return $result;
        }

        $usersOnline = null;

        // 1. totals.clients
        if (isset($data['totals']['clients']) && is_numeric($data['totals']['clients'])) {
            $usersOnline = (int) $data['totals']['clients'];
        }

        // 2. Fallback: listeners[0].clients
        if (
            $usersOnline === null &&
            isset($data['listeners']) &&
            is_array($data['listeners']) &&
            isset($data['listeners'][0]) &&
            is_array($data['listeners'][0]) &&
            isset($data['listeners'][0]['clients']) &&
            is_numeric($data['listeners'][0]['clients'])
        ) {
            $usersOnline = (int) $data['listeners'][0]['clients'];
        }

        $result['connected']    = true;
        $result['users_online'] = $usersOnline;

        return $result;
    }
}
