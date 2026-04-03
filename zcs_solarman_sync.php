<?php
// file: energia.php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);
date_default_timezone_set('Europe/Rome');

require_once __DIR__ . '/config.php';

const GITHUB_LATEST_URL = 'https://raw.githubusercontent.com/sacri73-droid/solar-sync/main/data/latest.json';
const GITHUB_HEARTBEAT_URL = 'https://raw.githubusercontent.com/sacri73-droid/solar-sync/main/data/heartbeat.json';

function h(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function fnum(mixed $value, int $dec = 0, string $suffix = ''): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    return number_format((float)$value, $dec, ',', '.') . $suffix;
}

function fdt(?string $value): string
{
    if (!$value) {
        return '—';
    }
    $t = strtotime($value);
    return $t ? date('d/m/Y H:i:s', $t) : $value;
}

function minutes_diff(?string $dt): ?int
{
    if (!$dt) {
        return null;
    }
    $ts = strtotime($dt);
    if ($ts === false) {
        return null;
    }
    return (int)floor((time() - $ts) / 60);
}

function fetch_json_url(string $url): ?array
{
    $ch = curl_init($url . '?_=' . time());
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
            'User-Agent: Mozilla/5.0'
        ],
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        curl_close($ch);
        return null;
    }
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status >= 400) {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function db(): mysqli
{
    global $mysqli, $conn, $db, $host, $dbuser, $dbpass, $dbname;

    if (isset($mysqli) && $mysqli instanceof mysqli) {
        return $mysqli;
    }
    if (isset($conn) && $conn instanceof mysqli) {
        return $conn;
    }
    if (isset($db) && $db instanceof mysqli) {
        return $db;
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $m = new mysqli((string)$host, (string)$dbuser, (string)$dbpass, (string)$dbname);
    $m->set_charset('utf8mb4');
    return $m;
}

function one(mysqli $db, string $sql): ?array
{
    $r = $db->query($sql);
    $row = $r->fetch_assoc();
    return $row ?: null;
}

function all_rows(mysqli $db, string $sql): array
{
    $r = $db->query($sql);
    $out = [];
    while ($row = $r->fetch_assoc()) {
        $out[] = $row;
    }
    return $out;
}

function badge_voltage(?float $v): array
{
    if ($v === null) {
        return ['Dato non disponibile', 'warning'];
    }
    if ($v > 253) {
        return ['Fuori norma alta', 'danger'];
    }
    if ($v < 207) {
        return ['Fuori norma bassa', 'warning'];
    }
    return ['Regolare', 'ok'];
}

function flow_from_live(array $live): array
{
    return [
        'pv_to_home' => (float)($live['flow']['pv_to_home'] ?? 0),
        'pv_to_battery' => (float)($live['flow']['pv_to_battery'] ?? 0),
        'battery_to_home' => (float)($live['flow']['battery_to_home'] ?? 0),
        'grid_to_home' => (float)($live['flow']['grid_to_home'] ?? 0),
        'home_to_grid' => (float)($live['flow']['home_to_grid'] ?? 0),
    ];
}

function first_last_delta(mysqli $db, string $column, string $from, string $to): float
{
    $from = $db->real_escape_string($from . ' 00:00:00');
    $to = $db->real_escape_string($to . ' 23:59:59');

    $first = one($db, "
        SELECT {$column} AS v
        FROM solar_combined_samples
        WHERE datetime_local BETWEEN '{$from}' AND '{$to}'
          AND {$column} IS NOT NULL
        ORDER BY datetime_local ASC
        LIMIT 1
    ");

    $last = one($db, "
        SELECT {$column} AS v
        FROM solar_combined_samples
        WHERE datetime_local BETWEEN '{$from}' AND '{$to}'
          AND {$column} IS NOT NULL
        ORDER BY datetime_local DESC
        LIMIT 1
    ");

    if (!$first || !$last || !is_numeric($first['v']) || !is_numeric($last['v'])) {
        return 0.0;
    }

    return max(0.0, (float)$last['v'] - (float)$first['v']);
}

function energy_box(mysqli $db, string $from, string $to): array
{
    return [
        'prod' => first_last_delta($db, 'generation_total_kwh', $from, $to),
        'load' => first_last_delta($db, 'load_total_kwh', $from, $to),
        'grid_import' => first_last_delta($db, 'grid_import_total_kwh', $from, $to),
        'grid_export' => first_last_delta($db, 'grid_export_total_kwh', $from, $to),
    ];
}

$db = db();

$dateFrom = isset($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['from'])
    ? (string)$_GET['from']
    : date('Y-m-d', strtotime('-7 days'));

$dateTo = isset($_GET['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['to'])
    ? (string)$_GET['to']
    : date('Y-m-d');

if ($dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

$selectedDay = isset($_GET['day']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['day'])
    ? (string)$_GET['day']
    : $dateTo;

$dbLatest = one($db, "
    SELECT datetime_local, voltage_ac, power_pv_total, load_power, grid_power, battery_soc, battery_power, updated_at, created_at
    FROM solar_combined_samples
    ORDER BY id DESC
    LIMIT 1
") ?? [];

$githubLatest = fetch_json_url(GITHUB_LATEST_URL);
$githubHeartbeat = fetch_json_url(GITHUB_HEARTBEAT_URL);

$githubOk = is_array($githubLatest) && !empty($githubLatest['ok']) && isset($githubLatest['data']) && is_array($githubLatest['data']);
$githubSampleTs = $githubLatest['sample_ts'] ?? ($githubHeartbeat['sample_ts'] ?? null);
$githubAge = minutes_diff($githubSampleTs);

$useGithubLive = $githubOk && $githubAge !== null && $githubAge <= 30;

$liveSource = $useGithubLive ? 'GitHub JSON' : 'DB locale';
$liveSampleTs = $useGithubLive ? $githubSampleTs : ($dbLatest['datetime_local'] ?? null);
$liveAge = minutes_diff($liveSampleTs);

$liveVoltage = $useGithubLive ? ($githubLatest['data']['voltage'] ?? null) : ($dbLatest['voltage_ac'] ?? null);
$livePv = $useGithubLive ? ($githubLatest['data']['pv'] ?? null) : ($dbLatest['power_pv_total'] ?? null);
$liveLoad = $useGithubLive ? ($githubLatest['data']['load'] ?? null) : ($dbLatest['load_power'] ?? null);
$liveGrid = $useGithubLive ? ($githubLatest['data']['grid'] ?? null) : ($dbLatest['grid_power'] ?? null);
$liveBatterySoc = $useGithubLive ? ($githubLatest['data']['battery_soc'] ?? null) : ($dbLatest['battery_soc'] ?? null);
$liveBatteryPower = $useGithubLive ? ($githubLatest['data']['battery_power'] ?? null) : ($dbLatest['battery_power'] ?? null);

$liveFlow = $useGithubLive && isset($githubLatest['flow']) ? flow_from_live($githubLatest) : [
    'pv_to_home' => min(max(0, (float)$livePv), max(0, (float)$liveLoad)),
    'pv_to_battery' => (float)$liveBatteryPower > 0 ? (float)$liveBatteryPower : 0,
    'battery_to_home' => (float)$liveBatteryPower < 0 ? abs((float)$liveBatteryPower) : 0,
    'grid_to_home' => (float)$liveGrid > 0 ? (float)$liveGrid : 0,
    'home_to_grid' => (float)$liveGrid < 0 ? abs((float)$liveGrid) : 0,
];

$voltageBadge = badge_voltage($liveVoltage !== null ? (float)$liveVoltage : null);

$syncText = $useGithubLive ? 'Aggiornamento automatico attivo' : 'Live GitHub non aggiornato, fallback DB';
$syncClass = $useGithubLive ? 'ok' : 'warning';

$fromSql = $db->real_escape_string($dateFrom . ' 00:00:00');
$toSql = $db->real_escape_string($dateTo . ' 23:59:59');

$trendRows = all_rows($db, "
    SELECT datetime_local, voltage_ac, power_pv_total, load_power, grid_power, battery_soc
    FROM solar_combined_samples
    WHERE datetime_local BETWEEN '{$fromSql}' AND '{$toSql}'
    ORDER BY datetime_local ASC
");

$labels = [];
$voltageData = [];
$pvData = [];
$loadData = [];
$gridData = [];
$batterySocData = [];

foreach ($trendRows as $r) {
    $labels[] = date('d/m H:i', strtotime((string)$r['datetime_local']));
    $voltageData[] = is_numeric($r['voltage_ac']) ? (float)$r['voltage_ac'] : null;
    $pvData[] = is_numeric($r['power_pv_total']) ? (float)$r['power_pv_total'] : null;
    $loadData[] = is_numeric($r['load_power']) ? (float)$r['load_power'] : null;
    $gridData[] = is_numeric($r['grid_power']) ? (float)$r['grid_power'] : null;
    $batterySocData[] = is_numeric($r['battery_soc']) ? (float)$r['battery_soc'] : null;
}

$today = date('Y-m-d');
$monthFrom = date('Y-m-01');
$monthTo = date('Y-m-t');
$yearFrom = date('Y-01-01');
$yearTo = date('Y-12-31');

$minMax = one($db, "
    SELECT DATE(MIN(datetime_local)) AS min_d, DATE(MAX(datetime_local)) AS max_d
    FROM solar_combined_samples
") ?? ['min_d' => $today, 'max_d' => $today];

$installFrom = $minMax['min_d'] ?: $today;
$installTo = $minMax['max_d'] ?: $today;

$boxToday = energy_box($db, $today, $today);
$boxMonth = energy_box($db, $monthFrom, $monthTo);
$boxYear = energy_box($db, $yearFrom, $yearTo);
$boxInstall = energy_box($db, $installFrom, $installTo);

$dailyVoltage = all_rows($db, "
    SELECT
        DATE(datetime_local) AS day_key,
        MIN(voltage_ac) AS v_min,
        AVG(voltage_ac) AS v_avg,
        MAX(voltage_ac) AS v_max,
        SUM(CASE WHEN voltage_ac > 253 OR voltage_ac < 207 THEN 1 ELSE 0 END) AS anomalies,
        COUNT(*) AS samples
    FROM solar_combined_samples
    WHERE datetime_local BETWEEN '{$fromSql}' AND '{$toSql}'
      AND voltage_ac IS NOT NULL
    GROUP BY DATE(datetime_local)
    ORDER BY day_key DESC
");

$daySqlFrom = $db->real_escape_string($selectedDay . ' 00:00:00');
$daySqlTo = $db->real_escape_string($selectedDay . ' 23:59:59');

$dayRows = all_rows($db, "
    SELECT datetime_local, voltage_ac
    FROM solar_combined_samples
    WHERE datetime_local BETWEEN '{$daySqlFrom}' AND '{$daySqlTo}'
      AND voltage_ac IS NOT NULL
    ORDER BY datetime_local ASC
");

$logoPath = __DIR__ . '/assets/sacrisolar.png';
$wordmarkPath = __DIR__ . '/assets/sacrisolar_scritta.png';
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SACRISOLAR Energia</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
:root{--bg:#f4faf4;--card:#fff;--text:#1d3728;--muted:#708777;--line:#dce7de;--orange:#f68b1f;--green:#39a54a;--blue:#3f7be0;--red:#df3d3d;--yellow:#f7c948;--shadow:0 10px 24px rgba(35,82,48,.08);--radius:22px}
*{box-sizing:border-box}body{margin:0;background:linear-gradient(180deg,#f8fcf8,#eef6ef);font-family:Inter,Arial,sans-serif;color:var(--text)}.page{max-width:1680px;margin:0 auto;padding:12px}
.topbar{background:#fff;border:1px solid var(--line);box-shadow:var(--shadow);border-radius:30px;padding:14px 22px;margin-bottom:14px}
.brand{display:grid;grid-template-columns:110px 1fr;gap:20px;align-items:center}
.brand-logo{width:110px;height:110px;border-radius:24px;display:grid;place-items:center;border:1px solid #f0dfbd;background:linear-gradient(145deg,rgba(246,139,31,.12),rgba(57,165,74,.10));overflow:hidden}
.brand-logo img{width:92%;height:92%;object-fit:contain}
.brand-right{height:110px;display:flex;align-items:center;justify-content:center;overflow:hidden}
.brand-wordmark{height:110px;width:auto;object-fit:contain;transform:scale(1.9);transform-origin:center center}
.card{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow)}
.hero{padding:16px}.hero-grid{display:grid;grid-template-columns:minmax(430px,.95fr) minmax(520px,1.05fr);gap:18px}
.section-title{margin:0 0 14px;font-size:1.12rem;font-weight:950}
.toolbar,.toolbar-form{display:flex;flex-wrap:wrap;gap:10px}
.toolbar{margin-bottom:12px}
.input,.btn{border-radius:16px;border:1px solid var(--line);background:#fff;padding:13px 16px;font:inherit;font-weight:900;color:var(--text)}
.btn{text-decoration:none;display:inline-flex;align-items:center;gap:8px;cursor:pointer}
.btn-orange{background:linear-gradient(135deg,var(--orange),#ffb34a);color:#fff;border:none}.btn-green{background:linear-gradient(135deg,var(--green),#73ce3d);color:#fff;border:none}
.quick{display:grid;grid-template-columns:repeat(2,1fr);gap:12px}.q{background:#fff;border:1px solid var(--line);border-radius:18px;padding:16px;min-height:110px}
.q .l{font-size:.95rem;color:var(--muted);font-weight:900}.q .v{font-size:1.18rem;font-weight:950;margin-top:8px}.q .s{margin-top:6px;color:var(--muted)}
.info-line{margin-top:14px;font-size:1rem;font-weight:950}.pills{display:flex;flex-wrap:wrap;gap:10px;margin-top:12px}
.pill{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border-radius:999px;background:#fff;border:1px solid var(--line);font-weight:900}
.dot{width:12px;height:12px;border-radius:50%}.dot.ok{background:var(--green)}.dot.warning{background:var(--yellow)}.dot.bad{background:var(--red)}
.meta-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:12px}.meta-card{background:#fff;border:1px solid var(--line);border-radius:18px;padding:14px}.meta-card .k{font-size:.88rem;color:var(--muted);font-weight:900}.meta-card .n{margin-top:8px;font-size:1rem;font-weight:950}
.flow-shell{padding:16px;border-radius:28px;background:linear-gradient(#fff,#fff) padding-box,linear-gradient(135deg,rgba(246,139,31,.95),rgba(143,216,69,.98),rgba(57,165,74,.95)) border-box;border:1.5px solid transparent;box-shadow:0 0 0 1px rgba(246,139,31,.08),0 18px 40px rgba(35,82,48,.08)}
.flow-stage{position:relative;height:520px;border-radius:24px;overflow:hidden;border:1px solid rgba(57,165,74,.14);background:linear-gradient(rgba(90,125,100,.05) 1px,transparent 1px),linear-gradient(90deg,rgba(90,125,100,.05) 1px,transparent 1px),linear-gradient(180deg,#fbfdfb 0%,#f5faf5 100%);background-size:28px 28px,28px 28px,100% 100%}
.flow-svg{position:absolute;inset:0;width:100%;height:100%;pointer-events:none;z-index:1}.flow-line{fill:none;stroke-linecap:round;stroke-linejoin:round;opacity:.18}.flow-line.orange{stroke:#f68b1f;stroke-width:7}.flow-line.green{stroke:#39a54a;stroke-width:7}.flow-line.blue{stroke:#3f7be0;stroke-width:7}
.node{position:absolute;width:160px;min-height:132px;background:#fff;border:1px solid rgba(246,139,31,.35);border-radius:24px;padding:14px 12px;text-align:center;box-shadow:0 12px 24px rgba(35,82,48,.08);z-index:2}.node .ico{font-size:32px;line-height:1}.node .lab{margin-top:10px;color:var(--muted);font-weight:950}.node .num{margin-top:10px;font-size:1.12rem;font-weight:950}.node .sub{margin-top:8px;color:var(--muted);font-size:.84rem;line-height:1.2}
.node.solar{left:50%;top:26px;transform:translateX(-50%)}.node.house{left:50%;top:285px;transform:translateX(-50%)}.node.battery{left:46px;top:322px}.node.grid{right:46px;top:322px}
.flow-sum{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-top:12px}.flow-item{background:#fff;border:1px solid var(--line);border-radius:18px;padding:12px 14px}.flow-item .k{font-size:.84rem;color:var(--muted);font-weight:950}.flow-item .n{margin-top:8px;font-size:1rem;font-weight:950}
.charts{display:grid;grid-template-columns:1.15fr .85fr;gap:16px;margin-top:16px}.chart-card{padding:18px}.chart-box{height:340px}
.hist{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-top:16px}.hist-box{padding:18px}.hist-box h3{margin:0 0 12px;font-size:1.15rem}.hist-line{display:flex;justify-content:space-between;gap:10px;padding:10px 0;border-top:1px solid #edf3ee}.hist-line:first-of-type{border-top:none}
.tables{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:16px}.table-card{padding:18px}.table-wrap{overflow:auto;border-radius:18px;border:1px solid var(--line);background:#fff}table{width:100%;border-collapse:separate;border-spacing:0;min-width:680px}th{background:linear-gradient(135deg,var(--orange),#ffb54c);color:#fff;text-align:left;padding:14px}td{padding:13px 14px;border-top:1px solid #edf3ee}.tag{display:inline-flex;padding:6px 10px;border-radius:999px;font-size:.8rem;font-weight:900}.tag.ok{background:#eefaf0;color:#1c7633}.tag.warning{background:#fff8e8;color:#936000}.tag.danger{background:#fff0f0;color:#991b1b}
@media (max-width:1360px){.hero-grid,.charts,.hist,.tables,.meta-grid,.flow-sum{grid-template-columns:1fr}}
@media (max-width:900px){.brand{grid-template-columns:88px 1fr;gap:14px}.brand-logo{width:88px;height:88px}.brand-right{height:88px}.brand-wordmark{height:88px;transform:scale(1.7)}}
@media (max-width:820px){.quick{grid-template-columns:1fr}.flow-stage{height:auto;padding:16px;display:grid;grid-template-columns:1fr;gap:14px}.flow-svg{display:none}.node{position:relative;left:auto!important;right:auto!important;top:auto!important;transform:none!important;width:100%}}
</style>
</head>
<body>
<div class="page">
<header class="topbar">
  <div class="brand">
    <div class="brand-logo"><?php if (is_file($logoPath)): ?><img src="assets/sacrisolar.png" alt="SACRISOLAR"><?php endif; ?></div>
    <div class="brand-right"><?php if (is_file($wordmarkPath)): ?><img src="assets/sacrisolar_scritta.png" alt="SACRISOLAR" class="brand-wordmark"><?php endif; ?></div>
  </div>
</header>

<section class="card hero">
  <div class="hero-grid">
    <div>
      <h2 class="section-title">Panoramica impianto</h2>

      <div class="toolbar">
        <form method="get" class="toolbar-form">
          <input class="input" type="date" name="from" value="<?= h($dateFrom) ?>">
          <input class="input" type="date" name="to" value="<?= h($dateTo) ?>">
          <button class="btn" type="submit">Applica periodo</button>
        </form>
        <a class="btn btn-orange" href="?from=<?= h($dateFrom) ?>&to=<?= h($dateTo) ?>&export=pdf">📄 Esporta PDF ARERA</a>
        <a class="btn" href="?from=<?= h(date('Y-m-d', strtotime('-1 day'))) ?>&to=<?= h(date('Y-m-d')) ?>">🗓 Ultime 24 ore</a>
        <a class="btn" href="?from=<?= h(date('Y-m-d', strtotime('-7 days'))) ?>&to=<?= h(date('Y-m-d')) ?>">📈 Ultimi 7 giorni</a>
        <button class="btn btn-green" type="button" onclick="location.reload()">↻ Ricarica dati</button>
      </div>

      <div class="quick">
        <div class="q"><div class="l">Tensione live</div><div class="v"><?= fnum($liveVoltage, 1, ' V') ?></div><div class="s"><?= h($voltageBadge[0]) ?></div></div>
        <div class="q"><div class="l">Fotovoltaico</div><div class="v"><?= fnum($livePv, 0, ' W') ?></div><div class="s">Produzione istantanea</div></div>
        <div class="q"><div class="l">Casa</div><div class="v"><?= fnum($liveLoad, 0, ' W') ?></div><div class="s">Consumo istantaneo</div></div>
        <div class="q"><div class="l">Batteria</div><div class="v"><?= fnum($liveBatterySoc, 0, '%') ?></div><div class="s"><?= (float)$liveBatteryPower > 0 ? 'Carica ' . fnum($liveBatteryPower, 0, ' W') : ((float)$liveBatteryPower < 0 ? 'Scarica ' . fnum(abs((float)$liveBatteryPower), 0, ' W') : 'Stabile') ?></div></div>
      </div>

      <div class="info-line">
        <?= $syncClass === 'ok' ? '✅' : '⚠️' ?>
        <?= h($syncText) ?>
        • sorgente live: <?= h($liveSource) ?>
        • ultimo campione: <?= h(fdt($liveSampleTs)) ?>
      </div>

      <div class="pills">
        <div class="pill"><span class="dot <?= h($syncClass) ?>"></span><?= h($syncText) ?></div>
        <div class="pill">🕒 Campione live: <strong><?= h(fdt($liveSampleTs)) ?></strong></div>
        <div class="pill">☁️ GitHub heartbeat: <strong><?= h(fdt($githubHeartbeat['last_run'] ?? null)) ?></strong></div>
      </div>

      <div class="meta-grid">
        <div class="meta-card"><div class="k">Età live</div><div class="n"><?= $liveAge !== null ? h((string)$liveAge) . ' min' : '—' ?></div></div>
        <div class="meta-card"><div class="k">Sorgente live</div><div class="n"><?= h($liveSource) ?></div></div>
        <div class="meta-card"><div class="k">DB ultimo campione</div><div class="n"><?= h(fdt($dbLatest['datetime_local'] ?? null)) ?></div></div>
        <div class="meta-card"><div class="k">Stato aggiornamento</div><div class="n"><?= h($syncText) ?></div></div>
      </div>
    </div>

    <div class="flow-shell">
      <h2 class="section-title">Flow energia live</h2>
      <div class="flow-stage">
        <svg class="flow-svg" viewBox="0 0 900 520" preserveAspectRatio="none" aria-hidden="true">
          <path class="flow-line orange" d="M450 158 L450 308"/>
          <path class="flow-line green" d="M205 387 L270 387 L270 340 L370 340"/>
          <path class="flow-line blue" d="M530 340 L640 340 L640 387 L705 387"/>
        </svg>

        <div class="node solar"><div class="ico">☀️</div><div class="lab">FOTOVOLTAICO</div><div class="num"><?= fnum($livePv, 0, ' W') ?></div><div class="sub">Produzione istantanea</div></div>
        <div class="node house"><div class="ico">🏠</div><div class="lab">CASA</div><div class="num"><?= fnum($liveLoad, 0, ' W') ?></div><div class="sub">Consumo abitazione</div></div>
        <div class="node battery"><div class="ico">🔋</div><div class="lab">BATTERIA</div><div class="num"><?= fnum($liveBatterySoc, 0, '%') ?></div><div class="sub"><?= $liveFlow['battery_to_home'] > 0 ? 'Supporto alla casa' : ($liveFlow['pv_to_battery'] > 0 ? 'In carica' : 'Stabile') ?></div></div>
        <div class="node grid"><div class="ico">🗼</div><div class="lab">RETE</div><div class="num"><?= fnum(max($liveFlow['grid_to_home'], $liveFlow['home_to_grid']), 0, ' W') ?></div><div class="sub"><?= $liveFlow['grid_to_home'] > 0 ? 'Prelievo' : ($liveFlow['home_to_grid'] > 0 ? 'Immissione' : 'Nessuno scambio') ?></div></div>
      </div>

      <div class="flow-sum">
        <div class="flow-item"><div class="k">FV → CASA</div><div class="n"><?= fnum($liveFlow['pv_to_home'], 0, ' W') ?></div></div>
        <div class="flow-item"><div class="k">FV → BATTERIA</div><div class="n"><?= fnum($liveFlow['pv_to_battery'], 0, ' W') ?></div></div>
        <div class="flow-item"><div class="k">BATTERIA → CASA</div><div class="n"><?= fnum($liveFlow['battery_to_home'], 0, ' W') ?></div></div>
        <div class="flow-item"><div class="k">CASA → RETE</div><div class="n"><?= fnum($liveFlow['home_to_grid'], 0, ' W') ?></div></div>
        <div class="flow-item"><div class="k">RETE → CASA</div><div class="n"><?= fnum($liveFlow['grid_to_home'], 0, ' W') ?></div></div>
      </div>
    </div>
  </div>
</section>

<section class="charts">
  <article class="card chart-card"><h2 class="section-title">Tensione rete nel periodo selezionato</h2><div class="chart-box"><canvas id="voltageChart"></canvas></div></article>
  <article class="card chart-card"><h2 class="section-title">Trend potenze / batteria</h2><div class="chart-box"><canvas id="powerChart"></canvas></div></article>
</section>

<section class="hist">
  <article class="card hist-box"><h3>Storico energia • Oggi</h3><div class="hist-line"><span>Prodotto FV</span><strong><?= fnum($boxToday['prod'], 2, ' kWh') ?></strong></div><div class="hist-line"><span>Consumato casa</span><strong><?= fnum($boxToday['load'], 2, ' kWh') ?></strong></div><div class="hist-line"><span>Prelevato da rete</span><strong><?= fnum($boxToday['grid_import'], 2, ' kWh') ?></strong></div><div class="hist-line"><span>Immesso in rete</span><strong><?= fnum($boxToday['grid_export'], 2, ' kWh') ?></strong></div></article>
  <article class="card hist-box"><h3>Storico energia • Mese</h3><div class="hist-line"><span>Prodotto FV</span><strong><?= fnum($boxMonth['prod'], 2, ' kWh') ?></strong></div><div class="hist-line"><span>Consumato casa</span><strong><?= fnum($boxMonth['load'], 2, ' kWh') ?></strong></div><div class="hist-line"><span>Prelevato da rete</span><strong><?= fnum($boxMonth['grid_import'], 2, ' kWh') ?></strong></div><div class="hist-line"><span>Immesso in rete</span><strong><?= fnum($boxMonth['grid_export'], 2, ' kWh') ?></strong></div></article>
  <article class="card hist-box"><h3>Storico energia • Anno</h3><div class="hist-line"><span>Prodotto FV</span><strong><?= fnum($boxYear['prod'], 2, ' kWh') ?></strong></div><div class="hist-line"><span>Consumato casa</span><strong><?= fnum($boxYear['load'], 2, ' kWh') ?></strong></div><div class="hist-line"><span>Prelevato da rete</span><strong><?= fnum($boxYear['grid_import'], 2, ' kWh') ?></strong></div><div class="hist-line"><span>Immesso in rete</span><strong><?= fnum($boxYear['grid_export'], 2, ' kWh') ?></strong></div></article>
  <article class="card hist-box"><h3>Da installazione a oggi</h3><div class="hist-line"><span>Prodotto FV</span><strong><?= fnum($boxInstall['prod'], 2, ' kWh') ?></strong></div><div class="hist-line"><span>Consumato casa</span><strong><?= fnum($boxInstall['load'], 2, ' kWh') ?></strong></div><div class="hist-line"><span>Prelevato da rete</span><strong><?= fnum($boxInstall['grid_import'], 2, ' kWh') ?></strong></div><div class="hist-line"><span>Immesso in rete</span><strong><?= fnum($boxInstall['grid_export'], 2, ' kWh') ?></strong></div></article>
</section>

<section class="tables">
  <article class="card table-card">
    <h2 class="section-title">Storico tensione giorno per giorno</h2>
    <div class="table-wrap"><table><thead><tr><th>Giorno</th><th>Min</th><th>Media</th><th>Max</th><th>Anomalie</th><th>Campioni</th></tr></thead><tbody>
      <?php if (!$dailyVoltage): ?>
        <tr><td colspan="6">Nessun dato disponibile.</td></tr>
      <?php else: foreach ($dailyVoltage as $r): ?>
        <tr>
          <td><strong><?= h(date('d/m/Y', strtotime((string)$r['day_key']))) ?></strong></td>
          <td><?= fnum($r['v_min'], 2, ' V') ?></td>
          <td><?= fnum($r['v_avg'], 2, ' V') ?></td>
          <td><?= fnum($r['v_max'], 2, ' V') ?></td>
          <td><?= h((string)$r['anomalies']) ?></td>
          <td><?= h((string)$r['samples']) ?></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody></table></div>
  </article>

  <article class="card table-card">
    <h2 class="section-title">Dettaglio tensione del giorno</h2>
    <div class="table-wrap"><table><thead><tr><th>Ora</th><th>Tensione</th><th>Stato</th></tr></thead><tbody>
      <?php if (!$dayRows): ?>
        <tr><td colspan="3">Nessun dato disponibile per il giorno selezionato.</td></tr>
      <?php else: foreach ($dayRows as $r): $b = badge_voltage(is_numeric($r['voltage_ac']) ? (float)$r['voltage_ac'] : null); ?>
        <tr>
          <td><?= h(date('H:i:s', strtotime((string)$r['datetime_local']))) ?></td>
          <td><strong><?= fnum($r['voltage_ac'], 2, ' V') ?></strong></td>
          <td><span class="tag <?= h($b[1]) ?>"><?= h($b[0]) ?></span></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody></table></div>
  </article>
</section>
</div>

<script>
const labels = <?= json_encode($labels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const voltageData = <?= json_encode($voltageData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const pvData = <?= json_encode($pvData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const loadData = <?= json_encode($loadData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const gridData = <?= json_encode($gridData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const batterySocData = <?= json_encode($batterySocData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

new Chart(document.getElementById('voltageChart'), {
  type: 'line',
  data: {
    labels,
    datasets: [
      { label: 'Tensione rete', data: voltageData, borderColor: '#f68b1f', backgroundColor: 'rgba(246,139,31,.10)', borderWidth: 2.2, pointRadius: 0, tension: .25, fill: true },
      { label: 'Limite alto 253V', data: labels.map(() => 253), borderColor: '#df3d3d', borderDash: [7,6], pointRadius: 0, borderWidth: 1.4 },
      { label: 'Limite basso 207V', data: labels.map(() => 207), borderColor: '#f7c948', borderDash: [7,6], pointRadius: 0, borderWidth: 1.4 }
    ]
  },
  options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false } }
});

new Chart(document.getElementById('powerChart'), {
  data: {
    labels,
    datasets: [
      { type: 'line', label: 'FV', data: pvData, borderColor: '#f68b1f', pointRadius: 0, tension: .22, yAxisID: 'y' },
      { type: 'line', label: 'Casa', data: loadData, borderColor: '#39a54a', pointRadius: 0, tension: .22, yAxisID: 'y' },
      { type: 'line', label: 'Rete', data: gridData, borderColor: '#3f7be0', pointRadius: 0, tension: .22, yAxisID: 'y' },
      { type: 'line', label: 'Batteria %', data: batterySocData, borderColor: '#f7c948', pointRadius: 0, tension: .2, yAxisID: 'y1' }
    ]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    scales: {
      y: { type: 'linear', position: 'left' },
      y1: { type: 'linear', position: 'right', grid: { drawOnChartArea: false }, suggestedMin: 0, suggestedMax: 100 }
    }
  }
});

setTimeout(() => location.reload(), 60000);
</script>
</body>
</html>
