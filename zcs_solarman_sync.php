<?php
// file: zcs_solarman_history_sync.php

declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set('Europe/Rome');

const DATA_DIR = __DIR__ . '/data';
const LATEST_FILE = DATA_DIR . '/latest.json';

function out(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mustString(array $cfg, string $key): string
{
    if (!isset($cfg[$key]) || !is_string($cfg[$key]) || trim($cfg[$key]) === '') {
        throw new RuntimeException("Configurazione mancante: {$key}");
    }

    return trim($cfg[$key]);
}

function dbConnect(array $cfg): PDO
{
    $charset = isset($cfg['db_charset']) && is_string($cfg['db_charset']) && trim($cfg['db_charset']) !== ''
        ? trim($cfg['db_charset'])
        : 'utf8mb4';

    $dsn = 'mysql:host=' . mustString($cfg, 'db_host')
        . ';dbname=' . mustString($cfg, 'db_name')
        . ';charset=' . $charset;

    return new PDO(
        $dsn,
        mustString($cfg, 'db_user'),
        mustString($cfg, 'db_pass'),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
}

$startedAt = microtime(true);

try {
    $cfg = require __DIR__ . '/private/zcs_secrets.php';

    if (!is_array($cfg)) {
        throw new RuntimeException('private/zcs_secrets.php non restituisce un array');
    }

    if (!is_file(LATEST_FILE)) {
        throw new RuntimeException('data/latest.json non trovato');
    }

    $latestRaw = file_get_contents(LATEST_FILE);
    $latest = json_decode((string)$latestRaw, true);

    if (!is_array($latest) || !($latest['ok'] ?? false)) {
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

    $pdo = dbConnect($cfg);

    $sql = "
        INSERT INTO solar_voltage_history (
            sample_ts,
            datetime_local,
            voltage_ac,
            source,
            created_at,
            updated_at
        ) VALUES (
            :sample_ts,
            :datetime_local,
            :voltage_ac,
            :source,
            NOW(),
            NOW()
        )
        ON DUPLICATE KEY UPDATE
            voltage_ac = VALUES(voltage_ac),
            source = VALUES(source),
            updated_at = NOW()
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':sample_ts' => $sampleTs,
        ':datetime_local' => $datetimeLocal,
        ':voltage_ac' => $voltage,
        ':source' => 'github_json',
    ]);

    out([
        'ok' => true,
        'message' => 'Storico tensione salvato',
        'sample_ts' => $datetimeLocal,
        'voltage_ac' => $voltage,
        'elapsed_ms' => (int)round((microtime(true) - $startedAt) * 1000),
    ]);
} catch (Throwable $e) {
    out([
        'ok' => false,
        'error' => $e->getMessage(),
        'elapsed_ms' => (int)round((microtime(true) - $startedAt) * 1000),
    ], 500);
}
