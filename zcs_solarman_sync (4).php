<?php
// file: zcs_solarman_sync.php

declare(strict_types=1);

date_default_timezone_set('Europe/Rome');

const DATA_DIR = __DIR__ . '/data';
const HEARTBEAT_FILE = DATA_DIR . '/heartbeat.json';
const LATEST_FILE = DATA_DIR . '/latest.json';

if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0777, true);
}

// 👉 CONFIG (copia dal tuo zcs_secrets)
$cfg = require __DIR__ . '/private/zcs_secrets.php';

// 👉 funzione semplice HTTP
function httpPostJson(string $url, array $payload, array $headers = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => array_merge([
            'Content-Type: application/json'
        ], $headers)
    ]);

    $res = curl_exec($ch);
    if ($res === false) {
        throw new Exception(curl_error($ch));
    }

    return json_decode($res, true);
}

// 👉 SOLARMAN
function getSolarman($cfg) {
    $res = httpPostJson(
        'https://globalpro.solarmanpv.com/device-s/device/v3/detail',
        [
            'deviceId' => (int)$cfg['solarman_device_id'],
            'siteId' => (int)$cfg['solarman_site_id'],
            'needRealTimeDataFlag' => true
        ],
        [
            'Authorization: ' . $cfg['solarman_bearer_token'],
            'Cookie: ' . $cfg['solarman_cookie']
        ]
    );

    $flat = [];
    foreach ($res['paramCategoryList'] ?? [] as $cat) {
        foreach ($cat['fieldList'] ?? [] as $f) {
            $flat[$f['storageName']] = $f['value'];
        }
    }

    return [
        'ts' => time(),
        'voltage' => (float)($flat['AV1'] ?? 0),
        'load' => (float)($flat['E_Puse_t1'] ?? 0),
        'grid' => (float)($flat['PG_Pt1'] ?? 0),
        'pv' => (float)($flat['PVTP'] ?? 0),
        'battery_soc' => (float)($flat['B_left_cap1'] ?? 0),
        'battery_power' => (float)($flat['B_P1'] ?? 0),
    ];
}

// 👉 LOGICA FLOW
function computeFlow($d) {
    return [
        'pv_to_home' => min($d['pv'], $d['load']),
        'pv_to_battery' => $d['battery_power'] > 0 ? $d['battery_power'] : 0,
        'battery_to_home' => $d['battery_power'] < 0 ? abs($d['battery_power']) : 0,
        'grid_to_home' => $d['grid'] > 0 ? $d['grid'] : 0,
        'home_to_grid' => $d['grid'] < 0 ? abs($d['grid']) : 0,
    ];
}

// 👉 MAIN
try {
    $data = getSolarman($cfg);
    $flow = computeFlow($data);

    $out = [
        'ok' => true,
        'time' => date('Y-m-d H:i:s'),
        'data' => $data,
        'flow' => $flow
    ];

    file_put_contents(LATEST_FILE, json_encode($out, JSON_PRETTY_PRINT));
    file_put_contents(HEARTBEAT_FILE, json_encode([
        'last_run' => date('Y-m-d H:i:s')
    ]));

    echo json_encode($out, JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}