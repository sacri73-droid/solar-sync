<?php
// file: zcs_solarman_sync.php

declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Rome');

const DATA_DIR = __DIR__ . '/data';
const HEARTBEAT_FILE = DATA_DIR . '/heartbeat.json';
const LATEST_FILE = DATA_DIR . '/latest.json';
const HTTP_TIMEOUT = 35;
const HTTP_CONNECT_TIMEOUT = 15;

if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0777, true);
}

function respond(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mustString(array $cfg, string $key): string
{
    if (!isset($cfg[$key]) || !is_string($cfg[$key]) || trim($cfg[$key]) === '') {
        throw new RuntimeException("Configurazione mancante o vuota: {$key}");
    }
    return trim($cfg[$key]);
}

function toFloatOrNull(mixed $value): ?float
{
    return ($value !== null && $value !== '' && is_numeric($value)) ? (float)$value : null;
}

function toIntOrNull(mixed $value): ?int
{
    return ($value !== null && $value !== '' && is_numeric($value)) ? (int)$value : null;
}

function writeJsonFile(string $path, array $payload): void
{
    file_put_contents(
        $path,
        json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

function httpPostJson(string $url, array $payload, array $headers = []): array
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($body === false) {
        throw new RuntimeException('Errore codifica JSON payload.');
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => array_merge([
            'Content-Type: application/json;charset=UTF-8',
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
        throw new RuntimeException('Risposta non JSON valida da Solarman. Raw: ' . mb_substr($raw, 0, 800));
    }

    if ($status >= 400) {
        throw new RuntimeException('HTTP ' . $status . ' da Solarman: ' . json_encode($decoded, JSON_UNESCAPED_UNICODE));
    }

    return $decoded;
}

function getSolarman(array $cfg): array
{
    $bearer = mustString($cfg, 'solarman_bearer_token');
    $cookie = mustString($cfg, 'solarman_cookie');

    if (stripos($bearer, 'Bearer ') !== 0) {
        $bearer = 'Bearer ' . $bearer;
    }

    $res = httpPostJson(
        'https://globalpro.solarmanpv.com/device-s/device/v3/detail',
        [
            'language' => 'it',
            'deviceId' => (int)mustString($cfg, 'solarman_device_id'),
            'siteId' => (int)mustString($cfg, 'solarman_site_id'),
            'needRealTimeDataFlag' => true,
        ],
        [
            'Authorization: ' . $bearer,
            'Cookie: ' . $cookie,
        ]
    );

    $detail = isset($res['data']) && is_array($res['data']) ? $res['data'] : $res;
    if (!isset($detail['paramCategoryList']) || !is_array($detail['paramCategoryList'])) {
        throw new RuntimeException('Risposta Solarman inattesa: paramCategoryList mancante.');
    }

    $flat = [];
    foreach ($detail['paramCategoryList'] as $cat) {
        foreach (($cat['fieldList'] ?? []) as $field) {
            $name = $field['storageName'] ?? null;
            if ($name) {
                $flat[$name] = $field['value'] ?? null;
            }
        }
    }

    $collectionTime = isset($detail['collectionTime']) && is_numeric($detail['collectionTime'])
        ? (int)$detail['collectionTime']
        : time();

    return [
        'ts' => $collectionTime,
        'datetime_local' => date('Y-m-d H:i:s', $collectionTime),
        'device_status' => toIntOrNull($detail['deviceState'] ?? null),
        'connect_status' => toIntOrNull($detail['connectStatus'] ?? null),
        'voltage_ac' => toFloatOrNull($flat['AV1'] ?? null),
        'load_power' => toFloatOrNull($flat['E_Puse_t1'] ?? null),
        'grid_power' => toFloatOrNull($flat['PG_Pt1'] ?? null),
        'power_pv_total' => toFloatOrNull($flat['PVTP'] ?? null),
        'battery_soc' => toFloatOrNull($flat['B_left_cap1'] ?? null),
        'battery_power' => toFloatOrNull($flat['B_P1'] ?? null),
        'raw_json' => json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
}

function computeFlow(array $row): array
{
    $pv = max(0.0, (float)($row['power_pv_total'] ?? 0));
    $load = max(0.0, (float)($row['load_power'] ?? 0));
    $grid = (float)($row['grid_power'] ?? 0);
    $batteryPower = (float)($row['battery_power'] ?? 0);

    return [
        'pv_to_home' => min($pv, $load),
        'pv_to_battery' => $batteryPower > 0 ? $batteryPower : 0.0,
        'battery_to_home' => $batteryPower < 0 ? abs($batteryPower) : 0.0,
        'grid_to_home' => $grid > 0 ? $grid : 0.0,
        'home_to_grid' => $grid < 0 ? abs($grid) : 0.0,
    ];
}

$runId = date('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 8);
$startedAt = microtime(true);

try {
    $cfg = require __DIR__ . '/private/zcs_secrets.php';
    if (!is_array($cfg)) {
        throw new RuntimeException('private/zcs_secrets.php non restituisce un array.');
    }

    $sample = getSolarman($cfg);
    $flow = computeFlow($sample);

    $heartbeat = [
        'ok' => true,
        'run_id' => $runId,
        'last_run' => date('Y-m-d H:i:s'),
        'sample_ts' => $sample['datetime_local'],
        'saved_rows' => 1,
        'elapsed_ms' => (int)round((microtime(true) - $startedAt) * 1000),
    ];

    $latest = [
        'ok' => true,
        'time' => date('Y-m-d H:i:s'),
        'sample_ts' => $sample['datetime_local'],
        'source' => 'github_json',
        'data' => [
            'ts' => $sample['ts'],
            'voltage' => $sample['voltage_ac'],
            'load' => $sample['load_power'],
            'grid' => $sample['grid_power'],
            'pv' => $sample['power_pv_total'],
            'battery_soc' => $sample['battery_soc'],
            'battery_power' => $sample['battery_power'],
        ],
        'flow' => $flow,
    ];

    writeJsonFile(HEARTBEAT_FILE, $heartbeat);
    writeJsonFile(LATEST_FILE, $latest);

    respond([
        'ok' => true,
        'message' => 'Sync completata',
        'run_id' => $runId,
        'saved_rows' => 1,
        'elapsed_ms' => (int)round((microtime(true) - $startedAt) * 1000),
        'sample_ts' => $sample['datetime_local'],
        'serial' => mustString($cfg, 'solarman_device_sn'),
        'summary' => [
            'voltage_ac' => $sample['voltage_ac'],
            'power_pv_total' => $sample['power_pv_total'],
            'load_power' => $sample['load_power'],
            'grid_power' => $sample['grid_power'],
            'battery_soc' => $sample['battery_soc'],
            'battery_power' => $sample['battery_power'],
        ],
        'flow' => $flow,
    ]);
} catch (Throwable $e) {
    writeJsonFile(HEARTBEAT_FILE, [
        'ok' => false,
        'run_id' => $runId,
        'last_run' => date('Y-m-d H:i:s'),
        'error' => $e->getMessage(),
        'elapsed_ms' => (int)round((microtime(true) - $startedAt) * 1000),
    ]);

    respond([
        'ok' => false,
        'error' => $e->getMessage(),
        'run_id' => $runId,
        'elapsed_ms' => (int)round((microtime(true) - $startedAt) * 1000),
    ], 500);
}
