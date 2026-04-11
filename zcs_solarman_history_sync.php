<?php

declare(strict_types=1);

$secrets = require __DIR__ . '/private/zcs_secrets.php';

function json_response(array $data): void
{
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}

try {
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $secrets['db_host'],
        $secrets['db_name'],
        $secrets['db_charset']
    );

    $pdo = new PDO($dsn, $secrets['db_user'], $secrets['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    // === CHIAMATA API SOLARMAN ===
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

    $insert = $pdo->prepare("
        INSERT INTO solar_voltage_history
        (device_sn, sample_ts, datetime_local, voltage_ac, raw_json)
        VALUES (:sn, :ts, :dt, :v, :raw)
        ON DUPLICATE KEY UPDATE
        voltage_ac = VALUES(voltage_ac)
    ");

    $deviceSn = $secrets['solarman_device_sn'];
    $rows = 0;

    foreach ($data as $param) {
        if (($param['storageName'] ?? '') !== 'AV1') {
            continue; // SOLO tensione
        }

        foreach ($param['detailList'] as $row) {
            $ts = (int)$row['collectionTime'];
            $value = (float)$row['value'];

            $dt = date('Y-m-d H:i:s', $ts);

            $insert->execute([
                'sn' => $deviceSn,
                'ts' => $ts,
                'dt' => $dt,
                'v'  => $value,
                'raw'=> json_encode($row)
            ]);

            $rows++;
        }
    }

    json_response([
        'ok' => true,
        'message' => 'Storico tensione importato',
        'rows' => $rows
    ]);

} catch (Throwable $e) {
    json_response([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}
