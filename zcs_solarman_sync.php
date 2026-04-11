<?php
// file: zcs_solarman_sync.php

declare(strict_types=1);

date_default_timezone_set('Europe/Rome');

const DATA_DIR = __DIR__ . '/data';
const LATEST_FILE = DATA_DIR . '/latest.json';
const HEARTBEAT_FILE = DATA_DIR . '/heartbeat.json';

if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0777, true);
}

$cfg = require __DIR__ . '/private/zcs_secrets.php';

function json_response(array $payload, int $exitCode = 0): void
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }

    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit($exitCode);
}

function cfg(array $cfg, string $key, mixed $default = null): mixed
{
    return $cfg[$key] ?? $default;
}

function to_float(mixed $value): float
{
    if ($value === null || $value === '') {
        return 0.0;
    }

    if (is_string($value)) {
        $value = str_replace(',', '.', trim($value));
    }

    return is_numeric($value) ? (float) $value : 0.0;
}

function to_int(mixed $value): int
{
    return (int) round(to_float($value));
}

function http_post_json(string $url, array $payload, array $headers = []): array
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => array_merge([
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: SacriSolar/1.0',
        ], $headers),
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 20,
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Errore CURL: ' . $error);
    }

    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($response, true);

    if (!is_array($decoded)) {
        throw new RuntimeException('Risposta Solarman non valida');
    }

    if ($httpCode >= 400) {
        throw new RuntimeException('HTTP ' . $httpCode . ' da Solarman');
    }

    return $decoded;
}

function fetch_solarman_live(array $cfg): array
{
    $response = http_post_json(
        'https://globalpro.solarmanpv.com/device-s/device/v3/detail',
        [
            'deviceId' => (int) cfg($cfg, 'solarman_device_id', 0),
            'siteId' => (int) cfg($cfg, 'solarman_site_id', 0),
            'needRealTimeDataFlag' => true,
        ],
        [
            'Authorization: ' . (string) cfg($cfg, 'solarman_bearer_token', ''),
            'Cookie: ' . (string) cfg($cfg, 'solarman_cookie', ''),
        ]
    );

    $flat = [];

    foreach (($response['paramCategoryList'] ?? []) as $category) {
        foreach (($category['fieldList'] ?? []) as $field) {
            $storageName = (string) ($field['storageName'] ?? '');
            if ($storageName === '') {
                continue;
            }
            $flat[$storageName] = $field['value'] ?? null;
        }
    }

    if ($flat === []) {
        throw new RuntimeException('Nessun parametro live ricevuto da Solarman');
    }

    return $flat;
}

function compute_flow(array $summary): array
{
    $pv = max(0, $summary['power_pv_total']);
    $load = max(0, $summary['load_power']);
    $grid = $summary['grid_power'];
    $batteryPower = $summary['battery_power'];

    $pvToHome = min($pv, $load);
    $pvToBattery = $batteryPower > 0 ? $batteryPower : 0;
    $batteryToHome = $batteryPower < 0 ? abs($batteryPower) : 0;
    $gridToHome = $grid > 0 ? $grid : 0;
    $homeToGrid = $grid < 0 ? abs($grid) : 0;

    return [
        'pv_to_home' => to_int($pvToHome),
        'pv_to_battery' => to_int($pvToBattery),
        'battery_to_home' => to_int($batteryToHome),
        'grid_to_home' => to_int($gridToHome),
        'home_to_grid' => to_int($homeToGrid),
    ];
}

function build_summary(array $flat): array
{
    return [
        'voltage_ac' => to_float($flat['AV1'] ?? 0),
        'current_ac' => to_float($flat['AC1'] ?? 0),
        'frequency_ac' => to_float($flat['A_Fo1'] ?? 0),
        'voltage_pv1' => to_float($flat['DV1'] ?? 0),
        'voltage_pv2' => to_float($flat['DV2'] ?? 0),
        'current_pv1' => to_float($flat['DC1'] ?? 0),
        'current_pv2' => to_float($flat['DC2'] ?? 0),
        'power_pv1' => to_float($flat['DP1'] ?? 0),
        'power_pv2' => to_float($flat['DP2'] ?? 0),
        'power_pv_total' => to_float($flat['PVTP'] ?? 0),
        'power_generation_total' => to_float($flat['TPG'] ?? 0),
        'power_dc_input_total' => to_float($flat['DPi_t1'] ?? 0),
        'power_ac_output' => to_float($flat['T_AC_OP'] ?? 0),
        'voltage_offgrid_r' => to_float($flat['Vog_o1'] ?? 0),
        'generation_total_kwh' => to_float($flat['Et_ge0'] ?? 0),
        'generation_today_kwh' => to_float($flat['Etdy_ge1'] ?? 0),
        'grid_frequency' => to_float($flat['PG_F1'] ?? 0),
        'grid_power' => to_float($flat['PG_Pt1'] ?? 0),
        'grid_export_total_kwh' => to_float($flat['t_gc1'] ?? 0),
        'grid_import_total_kwh' => to_float($flat['Et_pu1'] ?? 0),
        'grid_export_today_kwh' => to_float($flat['t_gc_tdy1'] ?? 0),
        'grid_import_today_kwh' => to_float($flat['Etdy_pu1'] ?? 0),
        'load_current' => to_float($flat['E_Cuse1'] ?? 0),
        'load_power' => to_float($flat['E_Puse_t1'] ?? 0),
        'load_total_kwh' => to_float($flat['Et_use1'] ?? 0),
        'load_today_kwh' => to_float($flat['Etdy_use1'] ?? 0),
        'battery_status_text' => (string) ($flat['battery_status_text'] ?? ''),
        'battery_power' => to_float($flat['B_P1'] ?? 0),
        'battery_soc' => to_float($flat['B_left_cap1'] ?? 0),
        'battery_soh' => to_float($flat['B_HLT_EXP1'] ?? 0),
        'battery_charge_total_kwh' => to_float($flat['t_cg_n1'] ?? 0),
        'battery_discharge_total_kwh' => to_float($flat['t_dcg_n1'] ?? 0),
        'battery_charge_today_kwh' => to_float($flat['Etdy_cg1'] ?? 0),
        'battery_discharge_today_kwh' => to_float($flat['Etdy_dcg1'] ?? 0),
        'battery_nominal_voltage' => to_float($flat['BC_VN'] ?? 0),
        'battery_pack_voltage' => to_float($flat['P_RT_T_V'] ?? 0),
        'battery_pack1_voltage' => to_float($flat['Vtr1_BAP1'] ?? 0),
        'battery_pack1_current' => to_float($flat['Cr1_BAP1'] ?? 0),
        'battery_pack1_soc' => to_float($flat['SOC_BAP1'] ?? 0),
        'battery_pack1_temp' => to_float($flat['T_BAP1'] ?? 0),
        'module_temp' => to_float($flat['T_MDU1'] ?? 0),
        'ambient_temp' => to_float($flat['SPAT'] ?? 0),
        'radiator_temp' => to_float($flat['T_RDT2'] ?? 0),
        'bus_voltage' => to_float($flat['Bus_V1'] ?? 0),
        'realtime_capacity' => to_float($flat['BRC'] ?? 0),
        'other_total_voltage' => to_float($flat['TV'] ?? 0),
        'other_total_current' => to_float($flat['TC'] ?? 0),
        'cell_avg_temp' => to_float($flat['CAT'] ?? 0),
        'inverter_status_text' => (string) ($flat['inverter_status_text'] ?? ''),
        'zcs_p_dc_tot' => to_float($flat['TPG'] ?? 0),
        'zcs_p_self' => 0.0,
        'zcs_p_ext' => max(0.0, -to_float($flat['PG_Pt1'] ?? 0)),
        'zcs_p_exp' => max(0.0, to_float($flat['PG_Pt1'] ?? 0)),
        'zcs_p_load' => to_float($flat['E_Puse_t1'] ?? 0),
        'zcs_bp1' => to_float($flat['B_P1'] ?? 0),
        'zcs_bp2' => 0.0,
        'zcs_alerts' => '',
    ];
}

function build_latest_payload(array $summary, array $flow, int $sampleTs, string $serial): array
{
    return [
        'ok' => true,
        'message' => 'Sync completata',
        'saved_rows' => 1,
        'sample_ts' => date('Y-m-d H:i:s', $sampleTs),
        'serial' => $serial,
        'summary' => [
            'voltage_ac' => round($summary['voltage_ac'], 1),
            'power_pv_total' => to_int($summary['power_pv_total']),
            'load_power' => to_int($summary['load_power']),
            'grid_power' => to_int($summary['grid_power']),
            'battery_soc' => round($summary['battery_soc'], 1),
            'battery_power' => to_int($summary['battery_power']),
        ],
        'flow' => $flow,
    ];
}

function save_json_files(array $latestPayload): void
{
    file_put_contents(
        LATEST_FILE,
        json_encode($latestPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    file_put_contents(
        HEARTBEAT_FILE,
        json_encode([
            'ok' => true,
            'last_run' => date('Y-m-d H:i:s'),
            'sample_ts' => $latestPayload['sample_ts'] ?? null,
            'serial' => $latestPayload['serial'] ?? null,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

function db_connect(array $cfg): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        (string) cfg($cfg, 'db_host', ''),
        (string) cfg($cfg, 'db_name', ''),
        (string) cfg($cfg, 'db_charset', 'utf8mb4')
    );

    return new PDO($dsn, (string) cfg($cfg, 'db_user', ''), (string) cfg($cfg, 'db_pass', ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function save_sample(PDO $pdo, array $cfg, array $summary, array $flat, int $sampleTs, string $serial): int
{
    $sql = <<<SQL
INSERT INTO solar_combined_samples (
    serial,
    site_id,
    ts,
    datetime_local,
    voltage_ac,
    current_ac,
    frequency_ac,
    voltage_pv1,
    voltage_pv2,
    current_pv1,
    current_pv2,
    power_pv1,
    power_pv2,
    power_pv_total,
    power_generation_total,
    power_dc_input_total,
    power_ac_output,
    voltage_offgrid_r,
    generation_total_kwh,
    generation_today_kwh,
    grid_frequency,
    grid_power,
    grid_export_total_kwh,
    grid_import_total_kwh,
    grid_export_today_kwh,
    grid_import_today_kwh,
    load_current,
    load_power,
    load_total_kwh,
    load_today_kwh,
    battery_status_text,
    battery_power,
    battery_soc,
    battery_soh,
    battery_charge_total_kwh,
    battery_discharge_total_kwh,
    battery_charge_today_kwh,
    battery_discharge_today_kwh,
    battery_nominal_voltage,
    battery_pack_voltage,
    battery_pack1_voltage,
    battery_pack1_current,
    battery_pack1_soc,
    battery_pack1_temp,
    module_temp,
    ambient_temp,
    radiator_temp,
    bus_voltage,
    realtime_capacity,
    other_total_voltage,
    other_total_current,
    cell_avg_temp,
    inverter_status_text,
    zcs_day_key,
    zcs_ts,
    zcs_p_dc_tot,
    zcs_p_self,
    zcs_p_ext,
    zcs_p_exp,
    zcs_p_load,
    zcs_bp1,
    zcs_bp2,
    zcs_alerts,
    raw_json,
    created_at,
    updated_at
) VALUES (
    :serial,
    :site_id,
    :ts,
    :datetime_local,
    :voltage_ac,
    :current_ac,
    :frequency_ac,
    :voltage_pv1,
    :voltage_pv2,
    :current_pv1,
    :current_pv2,
    :power_pv1,
    :power_pv2,
    :power_pv_total,
    :power_generation_total,
    :power_dc_input_total,
    :power_ac_output,
    :voltage_offgrid_r,
    :generation_total_kwh,
    :generation_today_kwh,
    :grid_frequency,
    :grid_power,
    :grid_export_total_kwh,
    :grid_import_total_kwh,
    :grid_export_today_kwh,
    :grid_import_today_kwh,
    :load_current,
    :load_power,
    :load_total_kwh,
    :load_today_kwh,
    :battery_status_text,
    :battery_power,
    :battery_soc,
    :battery_soh,
    :battery_charge_total_kwh,
    :battery_discharge_total_kwh,
    :battery_charge_today_kwh,
    :battery_discharge_today_kwh,
    :battery_nominal_voltage,
    :battery_pack_voltage,
    :battery_pack1_voltage,
    :battery_pack1_current,
    :battery_pack1_soc,
    :battery_pack1_temp,
    :module_temp,
    :ambient_temp,
    :radiator_temp,
    :bus_voltage,
    :realtime_capacity,
    :other_total_voltage,
    :other_total_current,
    :cell_avg_temp,
    :inverter_status_text,
    :zcs_day_key,
    :zcs_ts,
    :zcs_p_dc_tot,
    :zcs_p_self,
    :zcs_p_ext,
    :zcs_p_exp,
    :zcs_p_load,
    :zcs_bp1,
    :zcs_bp2,
    :zcs_alerts,
    :raw_json,
    NOW(),
    NOW()
)
ON DUPLICATE KEY UPDATE
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
    voltage_offgrid_r = VALUES(voltage_offgrid_r),
    generation_total_kwh = VALUES(generation_total_kwh),
    generation_today_kwh = VALUES(generation_today_kwh),
    grid_frequency = VALUES(grid_frequency),
    grid_power = VALUES(grid_power),
    grid_export_total_kwh = VALUES(grid_export_total_kwh),
    grid_import_total_kwh = VALUES(grid_import_total_kwh),
    grid_export_today_kwh = VALUES(grid_export_today_kwh),
    grid_import_today_kwh = VALUES(grid_import_today_kwh),
    load_current = VALUES(load_current),
    load_power = VALUES(load_power),
    load_total_kwh = VALUES(load_total_kwh),
    load_today_kwh = VALUES(load_today_kwh),
    battery_status_text = VALUES(battery_status_text),
    battery_power = VALUES(battery_power),
    battery_soc = VALUES(battery_soc),
    battery_soh = VALUES(battery_soh),
    battery_charge_total_kwh = VALUES(battery_charge_total_kwh),
    battery_discharge_total_kwh = VALUES(battery_discharge_total_kwh),
    battery_charge_today_kwh = VALUES(battery_charge_today_kwh),
    battery_discharge_today_kwh = VALUES(battery_discharge_today_kwh),
    battery_nominal_voltage = VALUES(battery_nominal_voltage),
    battery_pack_voltage = VALUES(battery_pack_voltage),
    battery_pack1_voltage = VALUES(battery_pack1_voltage),
    battery_pack1_current = VALUES(battery_pack1_current),
    battery_pack1_soc = VALUES(battery_pack1_soc),
    battery_pack1_temp = VALUES(battery_pack1_temp),
    module_temp = VALUES(module_temp),
    ambient_temp = VALUES(ambient_temp),
    radiator_temp = VALUES(radiator_temp),
    bus_voltage = VALUES(bus_voltage),
    realtime_capacity = VALUES(realtime_capacity),
    other_total_voltage = VALUES(other_total_voltage),
    other_total_current = VALUES(other_total_current),
    cell_avg_temp = VALUES(cell_avg_temp),
    inverter_status_text = VALUES(inverter_status_text),
    zcs_day_key = VALUES(zcs_day_key),
    zcs_ts = VALUES(zcs_ts),
    zcs_p_dc_tot = VALUES(zcs_p_dc_tot),
    zcs_p_self = VALUES(zcs_p_self),
    zcs_p_ext = VALUES(zcs_p_ext),
    zcs_p_exp = VALUES(zcs_p_exp),
    zcs_p_load = VALUES(zcs_p_load),
    zcs_bp1 = VALUES(zcs_bp1),
    zcs_bp2 = VALUES(zcs_bp2),
    zcs_alerts = VALUES(zcs_alerts),
    raw_json = VALUES(raw_json),
    updated_at = NOW()
SQL;

    $stmt = $pdo->prepare($sql);

    $params = $summary;
    $params['serial'] = $serial;
    $params['site_id'] = (string) cfg($cfg, 'solarman_site_id', '');
    $params['ts'] = $sampleTs;
    $params['datetime_local'] = date('Y-m-d H:i:s', $sampleTs);
    $params['zcs_day_key'] = date('Y-m-d', $sampleTs);
    $params['zcs_ts'] = $sampleTs;
    $params['raw_json'] = json_encode($flat, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $stmt->execute($params);

    return 1;
}

$startedAt = microtime(true);

try {
    $flat = fetch_solarman_live($cfg);
    $summary = build_summary($flat);
    $sampleTs = time();
    $serial = (string) cfg($cfg, 'solarman_device_sn', '');

    $flow = compute_flow($summary);
    $savedRows = save_sample(db_connect($cfg), $cfg, $summary, $flat, $sampleTs, $serial);

    $latestPayload = build_latest_payload($summary, $flow, $sampleTs, $serial);
    save_json_files($latestPayload);

    json_response([
        'ok' => true,
        'message' => 'Sync completata',
        'run_id' => date('Ymd_His') . '_' . substr(md5((string) microtime(true)), 0, 8),
        'saved_rows' => $savedRows,
        'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        'sample_ts' => date('Y-m-d H:i:s', $sampleTs),
        'serial' => $serial,
        'summary' => $latestPayload['summary'],
        'flow' => $flow,
    ]);
} catch (Throwable $e) {
    json_response([
        'ok' => false,
        'error' => $e->getMessage(),
        'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
    ], 1);
}
