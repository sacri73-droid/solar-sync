<?php
declare(strict_types=1);

$secrets = require __DIR__ . '/private/zcs_secrets.php';

function json_response(array $data): void
{
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}

function safePdo(array $secrets): ?PDO
{
    try {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            $secrets['db_host'],
            $secrets['db_name'],
            $secrets['db_charset']
        );

        return new PDO($dsn, $secrets['db_user'], $secrets['db_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    } catch (Throwable $e) {
        return null; // 🔥 NON blocca tutto
    }
}

try {
    $pdo = safePdo($secrets);

    // === API SOLARMAN ===
    $url = "https://globalpro.solarmanpv.com/device-s/device/238269913/stats/dayrange?startDay=2026%2F04%2F06&endDay=2026%2F04%2F12&lan=it";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Cookie: " . $secrets['solarman_cookie'],
            "Authorization: Bearer " . $secrets['solarman_bearer_token'],
            "User-Agent: Mozilla/5.0",
            "Accept: application/json"
        ],
    ]);

    $response = curl_exec($ch);

    if (!$response) {
        throw new Exception("Errore CURL");
    }

    $data = json_decode($response, true);

    if (!is_array($data)) {
        throw new Exception("JSON non valido");
    }

    $rows = 0;
    $deviceSn = $secrets['solarman_device_sn'];

    // 🔥 fallback file
    $historyFile = __DIR__ . '/data/voltage_history.json';
    $fileData = [];

    foreach ($data as $param) {
        if (($param['storageName'] ?? '') !== 'AV1') {
            continue;
        }

        foreach ($param['detailList'] as $row) {
            $ts = (int)$row['collectionTime'];
            $value = (float)$row['value'];
            $dt = date('Y-m-d H:i:s', $ts);

            // ✅ salva su DB se disponibile
            if ($pdo) {
                $stmt = $pdo->prepare("
                    INSERT INTO solar_voltage_history
                    (device_sn, sample_ts, datetime_local, voltage_ac, raw_json)
                    VALUES (:sn, :ts, :dt, :v, :raw)
                    ON DUPLICATE KEY UPDATE voltage_ac = VALUES(voltage_ac)
                ");

                $stmt->execute([
                    'sn' => $deviceSn,
                    'ts' => $ts,
                    'dt' => $dt,
                    'v'  => $value,
                    'raw'=> json_encode($row)
                ]);
            }

            // ✅ sempre salva su file
            $fileData[] = [
                'ts' => $ts,
                'datetime' => $dt,
                'voltage' => $value
            ];

            $rows++;
        }
    }

    file_put_contents($historyFile, json_encode($fileData, JSON_PRETTY_PRINT));

    json_response([
        'ok' => true,
        'rows' => $rows,
        'db_used' => $pdo !== null,
        'file_saved' => true
    ]);

} catch (Throwable $e) {
    json_response([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}
