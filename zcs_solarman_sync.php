<?php
// file: zcs_solarman_sync.php

declare(strict_types=1);

date_default_timezone_set('Europe/Rome');

const DATA_DIR = __DIR__ . '/data';
const HEARTBEAT_FILE = DATA_DIR . '/heartbeat.json';
const LATEST_FILE = DATA_DIR . '/latest.json';
const LOCK_FILE = DATA_DIR . '/zcs_solarman_sync.lock';
const HTTP_TIMEOUT = 35;
const HTTP_CONNECT_TIMEOUT = 15;

if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0777, true);
}

$cfg = require __DIR__ . '/private/zcs_secrets.php';
if (!is_array($cfg)) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Configurazione non valida: private/zcs_secrets.php deve restituire un array.'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$runId = date('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
$startedAt = microtime(true);

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
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
        throw new RuntimeException('Risposta non JSON valida da Solarman.');
    }

    if ($status >= 400) {
        throw new RuntimeException('HTTP ' . $status . ' da Solarman: ' . json_encode($decoded, JSON_UNESCAPED_UNICODE));
    }

    return $decoded;
}

function acquireLock()
{
    $fp = fopen(LOCK_FILE, 'c+');
    if (!$fp) {
        throw new RuntimeException('Impossibile aprire lock file.');
    }

    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Sync già in esecuzione.');
    }

    ftruncate($fp, 0);
    fwrite($fp, (string)getmypid());
    fflush($fp);

    return $fp;
}

function releaseLock($fp): void
{
    if (is_resource($fp)) {
        @flock($fp, LOCK_UN);
        @fclose($fp);
    }
}

function dbConnect(array $cfg): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        mustString($cfg, 'db_host'),
        mustString($cfg, 'db_name'),
        isset($cfg['db_charset']) && is_string($cfg['db_charset']) && trim($cfg['db_charset']) !== ''
            ? trim($cfg['db_charset'])
            : 'utf8mb4'
    );

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

function getSolarman(array $cfg): array
{
    $url = 'https://globalpro.solarmanpv.com/device-s/device/v3/detail';
    $bearer = mustString($cfg, 'solarman_bearer_token');
    $cookie = mustString($cfg, 'solarman_cookie');

    $authHeader = stripos($bearer, 'Bearer ') === 0 ? $bearer : ('Bearer ' . $bearer);

    $payload = [
        'language' => 'it',
        'deviceId' => (int)mustString($cfg, 'solarman_device_id'),
        'siteId' => (int)mustString($cfg, 'solarman_site_id'),
        'needRealTimeDataFlag' => true,
    ];

    $res = httpPostJson($url, $payload, [
        'Authorization: ' . $authHeader,
        'Cookie: ' . $cookie,
    ]);

    $detail = $res['data'] ?? $res;
    $categories = $detail['paramCategoryList'] ?? null;

    if (!is_array($categories)) {
        throw new RuntimeException('Risposta Solarman inattesa: paramCategoryList mancante.');
    }

    $flat = [];
    foreach ($categories as $cat) {
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
        'current_ac' => toFloatOrNull($flat['AC1'] ?? null),
        'frequency_ac' => toFloatOrNull($flat['A_Fo1'] ?? null),
        'voltage_pv1' => toFloatOrNull($flat['DV1'] ?? null),
        'voltage_pv2' => toFloatOrNull($flat['DV2'] ?? null),
        'current_pv1' => toFloatOrNull($flat['DC1'] ?? null),
        'current_pv2' => toFloatOrNull($flat['DC2'] ?? null),
        'power_pv1' => toFloatOrNull($flat['DP1'] ?? null),
        'power_pv2' => toFloatOrNull($flat['DP2'] ?? null),
        'power_pv_total' => toFloatOrNull($flat['PVTP'] ?? null),
        'power_generation_total' => toFloatOrNull($flat['TPG'] ?? null),
        'power_dc_input_total' => toFloatOrNull($flat['DPi_t1'] ?? null),
        'power_ac_output' => toFloatOrNull($flat['T_AC_OP'] ?? null),
        'voltage_offgrid_r' => toFloatOrNull($flat['Vog_o1'] ?? null),
        'generation_total_kwh' => toFloatOrNull($flat['Et_ge0'] ?? null),
        'generation_today_kwh' => toFloatOrNull($flat['Etdy_ge1'] ?? null),
        'grid_frequency' => toFloatOrNull($flat['PG_F1'] ?? null),
        'grid_power' => toFloatOrNull($flat['PG_Pt1'] ?? null),
        'grid_export_total_kwh' => toFloatOrNull($flat['t_gc1'] ?? null),
        'grid_import_total_kwh' => toFloatOrNull($flat['Et_pu1'] ?? null),
        'grid_export_today_kwh' => toFloatOrNull($flat['t_gc_tdy1'] ?? null),
        'grid_import_today_kwh' => toFloatOrNull($flat['Etdy_pu1'] ?? null),
        'load_current' => toFloatOrNull($flat['E_Cuse1'] ?? null),
        'load_power' => toFloatOrNull($flat['E_Puse_t1'] ?? null),
        'load_total_kwh' => toFloatOrNull($flat['Et_use1'] ?? null),
        'load_today_kwh' => toFloatOrNull($flat['Etdy_use1'] ?? null),
        'battery_status_text' => isset($flat['B_ST1']) ? (string)$flat['B_ST1'] : null,
        'battery_power' => toFloatOrNull($flat['B_P1'] ?? null),
        'battery_soc' => toFloatOrNull($flat['B_left_cap1'] ?? null),
        'battery_soh' => toFloatOrNull($flat['B_HLT_EXP1'] ?? null),
        'battery_charge_total_kwh' => toFloatOrNull($flat['t_cg_n1'] ?? null),
        'battery_discharge_total_kwh' => toFloatOrNull($flat['t_dcg_n1'] ?? null),
        'battery_charge_today_kwh' => toFloatOrNull($flat['Etdy_cg1'] ?? null),
        'battery_discharge_today_kwh' => toFloatOrNull($flat['Etdy_dcg1'] ?? null),
        'battery_nominal_voltage' => toFloatOrNull($flat['BC_VN'] ?? null),
        'battery_pack_voltage' => toFloatOrNull($flat['P_RT_T_V'] ?? null),
        'battery_pack1_voltage' => toFloatOrNull($flat['Vtr1_BAP1'] ?? null),
        'battery_pack1_current' => toFloatOrNull($flat['Cr1_BAP1'] ?? null),
        'battery_pack1_soc' => toFloatOrNull($flat['SOC_BAP1'] ?? null),
        'battery_pack1_temp' => toFloatOrNull($flat['T_BAP1'] ?? null),
        'module_temp' => toFloatOrNull($flat['T_MDU1'] ?? null),
        'ambient_temp' => toFloatOrNull($flat['SPAT'] ?? null),
        'radiator_temp' => toFloatOrNull($flat['T_RDT2'] ?? null),
        'bus_voltage' => toFloatOrNull($flat['Bus_V1'] ?? null),
        'realtime_capacity' => toFloatOrNull($flat['RC'] ?? null),
        'other_total_voltage' => toFloatOrNull($flat['TV'] ?? null),
        'other_total_current' => toFloatOrNull($flat['TC'] ?? null),
        'cell_avg_temp' => toFloatOrNull($flat['CAT'] ?? null),
        'inverter_status_text' => isset($flat['INV_ST1']) ? (string)$flat['INV_ST1'] : null,
        'raw_json' => json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
}

function computeFlow(array $d): array
{
    $pv = max(0.0, (float)($d['power_pv_total'] ?? 0));
    $load = max(0.0, (float)($d['load_power'] ?? 0));
    $grid = (float)($d['grid_power'] ?? 0);
    $batteryPower = (float)($d['battery_power'] ?? 0);

    return [
        'pv_to_home' => min($pv, $load),
        'pv_to_battery' => $batteryPower > 0 ? $batteryPower : 0.0,
        'battery_to_home' => $batteryPower < 0 ? abs($batteryPower) : 0.0,
        'grid_to_home' => $grid > 0 ? $grid : 0.0,
        'home_to_grid' => $grid < 0 ? abs($grid) : 0.0,
    ];
}

function saveCombinedSample(PDO $pdo, array $cfg, array $row): void
{
    $sql = "INSERT INTO solar_combined_samples (
        serial, site_id, ts, datetime_local, device_status, connect_status, voltage_ac, current_ac, frequency_ac,
        voltage_pv1, voltage_pv2, current_pv1, current_pv2, power_pv1, power_pv2, power_pv_total, power_generation_total,
        power_dc_input_total, power_ac_output, voltage_offgrid_r, generation_total_kwh, generation_today_kwh, grid_frequency,
        grid_power, grid_export_total_kwh, grid_import_total_kwh, grid_export_today_kwh, grid_import_today_kwh, load_current,
        load_power, load_total_kwh, load_today_kwh, battery_status_text, battery_power, battery_soc, battery_soh,
        battery_charge_total_kwh, battery_discharge_total_kwh, battery_charge_today_kwh, battery_discharge_today_kwh,
        battery_nominal_voltage, battery_pack_voltage, battery_pack1_voltage, battery_pack1_current, battery_pack1_soc,
        battery_pack1_temp, module_temp, ambient_temp, radiator_temp, bus_voltage, realtime_capacity, other_total_voltage,
        other_total_current, cell_avg_temp, inverter_status_text, raw_json, created_at, updated_at
    ) VALUES (
        :serial, :site_id, :ts, :datetime_local, :device_status, :connect_status, :voltage_ac, :current_ac, :frequency_ac,
        :voltage_pv1, :voltage_pv2, :current_pv1, :current_pv2, :power_pv1, :power_pv2, :power_pv_total, :power_generation_total,
        :power_dc_input_total, :power_ac_output, :voltage_offgrid_r, :generation_total_kwh, :generation_today_kwh, :grid_frequency,
        :grid_power, :grid_export_total_kwh, :grid_import_total_kwh, :grid_export_today_kwh, :grid_import_today_kwh, :load_current,
        :load_power, :load_total_kwh, :load_today_kwh, :battery_status_text, :battery_power, :battery_soc, :battery_soh,
        :battery_charge_total_kwh, :battery_discharge_total_kwh, :battery_charge_today_kwh, :battery_discharge_today_kwh,
        :battery_nominal_voltage, :battery_pack_voltage, :battery_pack1_voltage, :battery_pack1_current, :battery_pack1_soc,
        :battery_pack1_temp, :module_temp, :ambient_temp, :radiator_temp, :bus_voltage, :realtime_capacity, :other_total_voltage,
        :other_total_current, :cell_avg_temp, :inverter_status_text, :raw_json, NOW(), NOW()
    ) ON DUPLICATE KEY UPDATE
        updated_at = NOW(),
        datetime_local = VALUES(datetime_local),
        voltage_ac = VALUES(voltage_ac),
        current_ac = VALUES(current_ac),
        frequency_ac = VALUES(frequency_ac),
        voltage_pv1 = VALUES(voltage_pv1),
        voltage_pv2 = VALUES(voltage_pv2),
        current_pv1 = VALUES(current_pv1),
        current_pv2 = VALUES(current_pv2),
        power_pv1 = VALUES(power_pv1),
        power_pv2 = VALUES(power_pv2),
        power_pv_total = VALUES(power_pv_total),
        power_generation_total = VALUES(power_generation_total),
        power_dc_input_total = VALUES(power_dc_input_total),
        power_ac_output = VALUES(power_ac_output),
        generation_total_kwh = VALUES(generation_total_kwh),
        generation_today_kwh = VALUES(generation_today_kwh),
        grid_power = VALUES(grid_power),
        load_power = VALUES(load_power),
        battery_power = VALUES(battery_power),
        battery_soc = VALUES(battery_soc),
        raw_json = VALUES(raw_json)";

    $stmt = $pdo->prepare($sql);

    $params = [
        ':serial' => mustString($cfg, 'solarman_device_sn'),
        ':site_id' => mustString($cfg, 'solarman_site_id'),
        ':ts' => $row['ts'],
        ':datetime_local' => $row['datetime_local'],
        ':device_status' => $row['device_status'],
        ':connect_status' => $row['connect_status'],
        ':voltage_ac' => $row['voltage_ac'],
        ':current_ac' => $row['current_ac'],
        ':frequency_ac' => $row['frequency_ac'],
        ':voltage_pv1' => $row['voltage_pv1'],
        ':voltage_pv2' => $row['voltage_pv2'],
        ':current_pv1' => $row['current_pv1'],
        ':current_pv2' => $row['current_pv2'],
        ':power_pv1' => $row['power_pv1'],
        ':power_pv2' => $row['power_pv2'],
        ':power_pv_total' => $row['power_pv_total'],
        ':power_generation_total' => $row['power_generation_total'],
        ':power_dc_input_total' => $row['power_dc_input_total'],
        ':power_ac_output' => $row['power_ac_output'],
        ':voltage_offgrid_r' => $row['voltage_offgrid_r'],
        ':generation_total_kwh' => $row['generation_total_kwh'],
        ':generation_today_kwh' => $row['generation_today_kwh'],
        ':grid_frequency' => $row['grid_frequency'],
        ':grid_power' => $row['grid_power'],
        ':grid_export_total_kwh' => $row['grid_export_total_kwh'],
        ':grid_import_total_kwh' => $row['grid_import_total_kwh'],
        ':grid_export_today_kwh' => $row['grid_export_today_kwh'],
        ':grid_import_today_kwh' => $row['grid_import_today_kwh'],
        ':load_current' => $row['load_current'],
        ':load_power' => $row['load_power'],
        ':load_total_kwh' => $row['load_total_kwh'],
        ':load_today_kwh' => $row['load_today_kwh'],
        ':battery_status_text' => $row['battery_status_text'],
        ':battery_power' => $row['battery_power'],
        ':battery_soc' => $row['battery_soc'],
        ':battery_soh' => $row['battery_soh'],
        ':battery_charge_total_kwh' => $row['battery_charge_total_kwh'],
        ':battery_discharge_total_kwh' => $row['battery_discharge_total_kwh'],
        ':battery_charge_today_kwh' => $row['battery_charge_today_kwh'],
        ':battery_discharge_today_kwh' => $row['battery_discharge_today_kwh'],
        ':battery_nominal_voltage' => $row['battery_nominal_voltage'],
        ':battery_pack_voltage' => $row['battery_pack_voltage'],
        ':battery_pack1_voltage' => $row['battery_pack1_voltage'],
        ':battery_pack1_current' => $row['battery_pack1_current'],
        ':battery_pack1_soc' => $row['battery_pack1_soc'],
        ':battery_pack1_temp' => $row['battery_pack1_temp'],
        ':module_temp' => $row['module_temp'],
        ':ambient_temp' => $row['ambient_temp'],
        ':radiator_temp' => $row['radiator_temp'],
        ':bus_voltage' => $row['bus_voltage'],
        ':realtime_capacity' => $row['realtime_capacity'],
        ':other_total_voltage' => $row['other_total_voltage'],
        ':other_total_current' => $row['other_total_current'],
        ':cell_avg_temp' => $row['cell_avg_temp'],
        ':inverter_status_text' => $row['inverter_status_text'],
        ':raw_json' => $row['raw_json'],
    ];

    $stmt->execute($params);
}

function writeJsonFile(string $path, array $payload): void
{
    file_put_contents(
        $path,
        json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

$lock = null;

try {
    $lock = acquireLock();

    $pdo = dbConnect($cfg);
    $sample = getSolarman($cfg);
    $flow = computeFlow($sample);

    $pdo->beginTransaction();
    saveCombinedSample($pdo, $cfg, $sample);
    $pdo->commit();

    $heartbeat = [
        'ok' => true,
        'run_id' => $runId,
        'last_run' => date('Y-m-d H:i:s'),
        'sample_ts' => $sample['datetime_local'],
        'saved_rows' => 1,
        'elapsed_ms' => (int)round((microtime(true) - $startedAt) * 1000),
        'summary' => [
            'voltage_ac' => $sample['voltage_ac'],
            'power_pv_total' => $sample['power_pv_total'],
            'load_power' => $sample['load_power'],
            'grid_power' => $sample['grid_power'],
            'battery_soc' => $sample['battery_soc'],
            'battery_power' => $sample['battery_power'],
        ],
    ];

    $latest = [
        'ok' => true,
        'run_id' => $runId,
        'time' => date('Y-m-d H:i:s'),
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
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

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
} finally {
    releaseLock($lock);
}
