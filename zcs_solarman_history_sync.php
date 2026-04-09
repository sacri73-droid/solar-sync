<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set('Europe/Rome');

const DATA_DIR = __DIR__ . '/data';
const LATEST_FILE = DATA_DIR . '/latest.json';
const HISTORY_FILE = DATA_DIR . '/voltage_history.json';
const MAX_ROWS = 20000;

function json_out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function load_json(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $raw = file_get_contents($path);
    $decoded = json_decode((string)$raw, true);

    return is_array($decoded) ? $decoded : [];
}

function save_json(string $path, array $data): void
{
    file_put_contents(
        $path,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

$started = microtime(true);

try {
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0777, true);
    }

    $latest = load_json(LATEST_FILE);

    if (!($latest['ok'] ?? false)) {
        throw new RuntimeException('latest.json mancante o non valido');
    }

    $sampleTs = null;

    if (isset($latest['data']['ts']) && is_numeric($latest['data']['ts'])) {
        $sampleTs = (int)$latest['data']['ts'];
    } elseif (!empty($latest['sample_ts'])) {
        $sampleTs = strtotime((string)$latest['sample_ts']);
    }

    if (!$sampleTs) {
        throw new RuntimeException('Timestamp campione non valido');
    }

    $datetimeLocal = date('Y-m-d H:i:s', $sampleTs);
    $voltage = isset($latest['data']['voltage']) && is_numeric($latest['data']['voltage'])
        ? (float)$latest['data']['voltage']
        : null;

    $history = load_json(HISTORY_FILE);

    if (!isset($history['rows']) || !is_array($history['rows'])) {
        $history = [
            'ok' => true,
            'source' => 'github_json',
            'updated_at' => null,
            'rows' => [],
        ];
    }

    $history['rows'][(string)$sampleTs] = [
        'sample_ts' => $sampleTs,
        'datetime_local' => $datetimeLocal,
        'voltage_ac' => $voltage,
        'source' => 'github_json',
    ];

    ksort($history['rows'], SORT_NUMERIC);

    if (count($history['rows']) > MAX_ROWS) {
        $history['rows'] = array_slice($history['rows'], -MAX_ROWS, null, true);
    }

    $history['ok'] = true;
    $history['source'] = 'github_json';
    $history['updated_at'] = date('Y-m-d H:i:s');

    save_json(HISTORY_FILE, $history);

    json_out([
        'ok' => true,
        'message' => 'Storico tensione salvato',
        'sample_ts' => $datetimeLocal,
        'voltage_ac' => $voltage,
        'rows_count' => count($history['rows']),
        'elapsed_ms' => (int)round((microtime(true) - $started) * 1000),
    ]);
} catch (Throwable $e) {
    json_out([
        'ok' => false,
        'error' => $e->getMessage(),
        'elapsed_ms' => (int)round((microtime(true) - $started) * 1000),
    ], 500);
}
