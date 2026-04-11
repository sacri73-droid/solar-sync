<?php
// file: zcs_solarman_history_sync.php

declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set('Europe/Rome');

const DATA_DIR = __DIR__ . '/data';
const HISTORY_FILE = DATA_DIR . '/voltage_history.json';
const HTTP_TIMEOUT = 45;
const HTTP_CONNECT_TIMEOUT = 15;
const MAX_POINTS = 20000;

if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0777, true);
}

$secrets = require __DIR__ . '/private/zcs_secrets.php';

function json_response(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function must_string(array $cfg, string $key): string
{
    if (!isset($cfg[$key]) || !is_string($cfg[$key]) || trim($cfg[$key]) === '') {
        throw new RuntimeException("Configurazione mancante o vuota: {$key}");
    }

    return trim($cfg[$key]);
}

function to_float_or_null(mixed $value): ?float
{
    return ($value !== null && $value !== '' && is_numeric($value)) ? (float)$value : null;
}

function read_json_file(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $raw = file_get_contents($path);
    $decoded = json_decode((string)$raw, true);

    return is_array($decoded) ? $decoded : [];
}

function write_json_file(string $path, array $payload): void
{
    file_put_contents(
        $path,
        json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        )
    );
}

function http_get_json(string $url, array $headers = []): array
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array_merge([
            'Accept: application/json, text/plain, */*',
            'User-Agent: Mozilla/5.0',
            'Origin: https://globalpro.solarmanpv.com',
            'Referer: https://globalpro.solarmanpv.com/',
        ], $headers),
        CURLOPT_TIMEOUT => HTTP_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => HTTP_CONNECT_TIMEOUT,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $raw = curl_exec($ch);

    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Errore cURL: ' . $err);
    }

    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($raw, true);

    if (!is_array($decoded)) {
        throw new RuntimeException('Risposta non JSON valida dallo storico Solarman.');
    }

    if ($status >= 400) {
        throw new RuntimeException(
            'HTTP ' . $status . ' dallo storico Solarman: ' . json_encode($decoded, JSON_UNESCAPED_UNICODE)
        );
    }

    return $decoded;
}

function fetch_history_from_solarman(array $cfg, string $startDay, string $endDay): array
{
    $deviceId = must_string($cfg, 'solarman_device_id');
    $cookie = must_string($cfg, 'solarman_cookie');
    $bearer = must_string($cfg, 'solarman_bearer_token');

    if (stripos($bearer, 'Bearer ') !== 0) {
        $bearer = 'Bearer ' . $bearer;
    }

    $url = sprintf(
        'https://globalpro.solarmanpv.com/device-s/device/%s/stats/dayrange?startDay=%s&endDay=%s&lan=it',
        rawurlencode($deviceId),
        rawurlencode($startDay),
        rawurlencode($endDay)
    );

    return http_get_json($url, [
        'Cookie: ' . $cookie,
        'Authorization: ' . $bearer,
    ]);
}

function normalize_history(array $data): array
{
    $wanted = [
        'AV1' => 'voltage_ac',
        'AC1' => 'current_ac',
        'A_Fo1' => 'frequency_ac',
        'T_AC_OP' => 'power_ac_output',
    ];

    $byTs = [];

    foreach ($data as $param) {
        $storageName = (string)($param['storageName'] ?? '');
        if (!isset($wanted[$storageName])) {
            continue;
        }

        $targetField = $wanted[$storageName];
        $detailList = $param['detailList'] ?? [];

        if (!is_array($detailList)) {
            continue;
        }

        foreach ($detailList as $row) {
            $ts = isset($row['collectionTime']) && is_numeric($row['collectionTime'])
                ? (int)$row['collectionTime']
                : 0;

            if ($ts <= 0) {
                continue;
            }

            if (!isset($byTs[$ts])) {
                $byTs[$ts] = [
                    'ts' => $ts,
                    'datetime' => date('Y-m-d H:i:s', $ts),
                    'voltage_ac' => null,
                    'current_ac' => null,
                    'frequency_ac' => null,
                    'power_ac_output' => null,
                ];
            }

            $byTs[$ts][$targetField] = to_float_or_null($row['value'] ?? null);
        }
    }

    ksort($byTs, SORT_NUMERIC);

    $rows = array_values($byTs);

    if (count($rows) > MAX_POINTS) {
        $rows = array_slice($rows, -MAX_POINTS);
    }

    return $rows;
}

try {
    $endDay = date('Y/m/d');
    $startDay = date('Y/m/d', strtotime('-7 days'));

    $remoteData = fetch_history_from_solarman($secrets, $startDay, $endDay);
    $newRows = normalize_history($remoteData);

    $existing = read_json_file(HISTORY_FILE);
    $existingRows = isset($existing['rows']) && is_array($existing['rows']) ? $existing['rows'] : [];

    $merged = [];

    foreach ($existingRows as $row) {
        if (isset($row['ts']) && is_numeric($row['ts'])) {
            $merged[(int)$row['ts']] = $row;
        }
    }

    foreach ($newRows as $row) {
        $merged[(int)$row['ts']] = $row;
    }

    ksort($merged, SORT_NUMERIC);
    $rows = array_values($merged);

    if (count($rows) > MAX_POINTS) {
        $rows = array_slice($rows, -MAX_POINTS);
    }

    $payload = [
        'ok' => true,
        'source' => 'solarman_dayrange',
        'updated_at' => date('Y-m-d H:i:s'),
        'range' => [
            'start' => $startDay,
            'end' => $endDay,
        ],
        'rows' => $rows,
    ];

    write_json_file(HISTORY_FILE, $payload);

    json_response([
        'ok' => true,
        'message' => 'Storico importato',
        'rows' => count($rows),
        'range' => [
            'start' => $startDay,
            'end' => $endDay,
        ],
    ]);
} catch (Throwable $e) {
    json_response([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 500);
}
