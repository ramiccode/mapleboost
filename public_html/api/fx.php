<?php
/**
 * MapleBoost FX endpoint — proxies the Bank of Canada Valet API and returns a
 * clean JSON conversion rate. BoC series FXxxxCAD = CAD per 1 unit of xxx.
 * Usage: /api/fx?from=USD&to=CAD&amount=100&date=2026-06-01  (date optional)
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Currencies the Bank of Canada publishes daily (CAD is the base).
$SUPPORTED = ['CAD','USD','EUR','GBP','AUD','BRL','CNY','HKD','INR','IDR','JPY',
  'MYR','MXN','NZD','NOK','PEN','PLN','RUB','SAR','SGD','ZAR','KRW','SEK','CHF',
  'TWD','THB','TRY','VND'];

function fail($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

$from = strtoupper(preg_replace('/[^A-Za-z]/', '', $_GET['from'] ?? 'USD'));
$to   = strtoupper(preg_replace('/[^A-Za-z]/', '', $_GET['to']   ?? 'CAD'));
if (!in_array($from, $SUPPORTED, true)) fail("Unsupported 'from' currency: $from");
if (!in_array($to,   $SUPPORTED, true)) fail("Unsupported 'to' currency: $to");

$amount = isset($_GET['amount']) ? (float) $_GET['amount'] : 1.0;
if (!is_finite($amount)) $amount = 1.0;

$dateParam = trim($_GET['date'] ?? '');
$isNow = ($dateParam === '' || strtolower($dateParam) === 'now');

$endDate = null;
if (!$isNow) {
    $d = DateTime::createFromFormat('Y-m-d', $dateParam);
    if (!$d || $d->format('Y-m-d') !== $dateParam) fail("Invalid date. Use YYYY-MM-DD or omit for the latest rate.");
    $today = new DateTime('today');
    if ($d > $today) $d = $today;                          // clamp future to today
    if ($d < (new DateTime('today'))->modify('-1 year'))  // trailing 12 months only
        fail("This converter supports dates within the past 12 months.");
    $endDate = $d->format('Y-m-d');
}

// Same currency -> 1:1.
if ($from === $to) {
    echo json_encode(['ok'=>true,'from'=>$from,'to'=>$to,'amount'=>$amount,
        'rate'=>1.0,'result'=>$amount,'inverseRate'=>1.0,
        'observationDate'=>$isNow?null:$endDate,'requestedDate'=>$isNow?'now':$endDate,
        'isLatest'=>$isNow,'source'=>'Bank of Canada Valet API']);
    exit;
}

// Which BoC series do we need?
$series = [];
if ($from !== 'CAD') $series[] = 'FX' . $from . 'CAD';
if ($to   !== 'CAD') $series[] = 'FX' . $to   . 'CAD';
sort($series);

// Disk cache: latest buckets hourly, historical cached 30 days.
$cacheDir = sys_get_temp_dir() . '/mb_fx_cache';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0700, true);
$cacheTag  = $isNow ? ('now-' . gmdate('Y-m-d-H')) : $endDate;
$cacheFile = $cacheDir . '/' . md5(implode(',', $series) . '|' . $cacheTag) . '.json';
$cacheTtl  = $isNow ? 3600 : 2592000;

$payload = null;
if (is_file($cacheFile) && (time() - filemtime($cacheFile) < $cacheTtl)) {
    $payload = json_decode(@file_get_contents($cacheFile), true);
}

if (!$payload) {
    $base = 'https://www.bankofcanada.ca/valet/observations/' . implode(',', $series) . '/json';
    if ($isNow) {
        $url = $base . '?recent=1';
    } else {
        $start = (clone $d)->modify('-14 days')->format('Y-m-d');
        $url = $base . '?start_date=' . $start . '&end_date=' . $endDate;
    }

    $body = fx_http_get($url);
    if ($body === null) fail('Could not reach the Bank of Canada rate service. Please try again.', 502);

    $data = json_decode($body, true);
    if (!is_array($data) || !isset($data['observations']))
        fail('Unexpected response from the Bank of Canada rate service.', 502);

    // Keep the most recent row that has every series (weekend/holiday fallback).
    $obsDate = null; $rates = [];
    foreach ($data['observations'] as $obs) {
        $ok = true; $rr = [];
        foreach ($series as $s) {
            if (isset($obs[$s]['v']) && $obs[$s]['v'] !== '') $rr[$s] = (float) $obs[$s]['v'];
            else { $ok = false; break; }
        }
        if ($ok) { $obsDate = $obs['d']; $rates = $rr; }
    }
    if ($obsDate === null)
        fail('No exchange rate is available for that date. Markets are closed on weekends and holidays.', 404);

    $payload = ['observationDate' => $obsDate, 'rates' => $rates];
    @file_put_contents($cacheFile, json_encode($payload));
}

$cadPerFrom = ($from === 'CAD') ? 1.0 : ($payload['rates']['FX' . $from . 'CAD'] ?? null);
$cadPerTo   = ($to   === 'CAD') ? 1.0 : ($payload['rates']['FX' . $to   . 'CAD'] ?? null);
if (!$cadPerFrom || !$cadPerTo) fail('Rate data was incomplete for that date. Please try another date.', 502);

$rate = $cadPerFrom / $cadPerTo;   // units of `to` per 1 unit of `from`

echo json_encode(['ok'=>true,'from'=>$from,'to'=>$to,'amount'=>$amount,
    'rate'=>$rate,'result'=>$amount*$rate,'inverseRate'=>1/$rate,
    'observationDate'=>$payload['observationDate'],'requestedDate'=>$isNow?'now':$endDate,
    'isLatest'=>$isNow,'source'=>'Bank of Canada Valet API']);

/** HTTP GET via curl, falling back to file_get_contents. Returns body or null. */
function fx_http_get($url) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT => 'MapleBoost/1.0 (+https://mapleboost.ca)',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body !== false && $code >= 200 && $code < 300) return $body;
    }
    if (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => ['timeout' => 8,
            'header' => "Accept: application/json\r\nUser-Agent: MapleBoost/1.0\r\n"]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body !== false) return $body;
    }
    return null;
}
