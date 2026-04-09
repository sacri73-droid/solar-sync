<?php
// file: zcs_solarman_history_sync.php

declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set('Europe/Rome');

const DATA_DIR = __DIR__ . '/data';
const LATEST_FILE = DATA_DIR . '/latest.json';
const HISTORY_FILE = DATA_DIR . '/voltage_history.json';
const MAX_HISTORY_ROWS = 20000;

function out(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function loadJsonFile(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $raw = file_get_contents($path);
    $data = json_decode((string)$raw, true);

    return is_array($data) ? $data : [];
}

function saveJsonFile(string $path, array $data): void
{
    file_put_contents(
        $path,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

$startedAt = microtime(true);

try {
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0777, true);
    }

    if (!is_file(LATEST_FILE)) {
        throw new RuntimeException('data/latest.json non trovato');
    }

    $latest = loadJsonFile(LATEST_FILE);

    if (!($latest['ok'] ?? false)) {
        throw new RuntimeException('latest.json non valido');
    }

    $sampleTsText = isset($latest['sample_ts']) ? (string)$latest['sample_ts'] : '';
    $sampleTs = isset($latest['data']['ts']) && is_numeric($latest['data']['ts'])
        ? (int)$latest['data']['ts']
        : strtotime($sampleTsText);

    if (!$sampleTs) {
        throw new RuntimeException('sample_ts non valido');
    }

    $datetimeLocal = date('Y-m-d H:i:s', $sampleTs);
    $voltage = isset($latest['data']['voltage']) && is_numeric($latest['data']['voltage'])
        ? (float)$latest['data']['voltage']
        : null;

    $history = loadJsonFile(HISTORY_FILE);

    if (!isset($history['rows']) || !is_array($history['rows'])) {
        $history = [
            'ok' => true,
            'source' => 'github_json',
            'updated_at' => null,
            'rows' => [],
        ];
    }

    $key = (string)$sampleTs;
    $history['rows'][$key] = [
        'sample_ts' => $sampleTs,
        'datetime_local' => $datetimeLocal,
        'voltage_ac' => $voltage,
        'source' => 'github_json',
    ];

    ksort($history['rows'], SORT_NUMERIC);

    if (count($history['rows']) > MAX_HISTORY_ROWS) {
        $history['rows'] = array_slice($history['rows'], -MAX_HISTORY_ROWS, null, true);
    }

    $history['ok'] = true;
    $history['source'] = 'github_json';
    $history['updated_at'] = date('Y-m-d H:i:s');

    saveJsonFile(HISTORY_FILE, $history);

    out([
        'ok' => true,
        'message' => 'Storico tensione salvato su JSON',
        'sample_ts' => $datetimeLocal,
        'voltage_ac' => $voltage,
        'rows_count' => count($history['rows']),
        'elapsed_ms' => (int)round((microtime(true) - $startedAt) * 1000),
    ]);
} catch (Throwable $e) {
    out([
        'ok' => false,
        'error' => $e->getMessage(),
        'elapsed_ms' => (int)round((microtime(true) - $startedAt) * 1000),
    ], 500);
}
