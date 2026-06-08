#!/usr/local/bin/php.cli
<?php
/**
 * MapleBoost — FX history page generator.
 * Builds static history pages + CSV downloads for a set of currencies vs CAD,
 * using official Bank of Canada daily rates (Valet API, full history from 2017).
 *
 * Run on the host (cron):   php /home/USER/public_html/gen-fx-history.php
 * It fetches fresh data, caches it, regenerates every page, then refreshes the
 * sitemap if gen-sitemap.php is present.
 *
 * Offline/dev: if the Bank of Canada can't be reached, it falls back to the
 * cached data in data/fx/cache/boc-combined.csv (so it still rebuilds pages).
 */

if (php_sapi_name() !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

date_default_timezone_set('America/Toronto');

$ROOT  = __DIR__;
$DATA  = $ROOT . '/data';
$FXDIR = $DATA . '/fx';
$CACHE = $FXDIR . '/cache';
@mkdir($CACHE, 0755, true);

$BASEURL = 'https://mapleboost.ca';
$LIVE_START = '2026-01-01';   // everything before this is the frozen archive; cron fetches only this onward

// Currencies to publish. `intro` is unique per page (SEO + avoids thin/dupe).
$CURRENCIES = [
  ['code'=>'USD','slug'=>'usd-to-cad-history','name'=>'US Dollar','sym'=>'US$',
   'intro'=>'The US dollar is the currency most Canadian businesses deal with — invoices to American clients, USD supplier bills, cross-border SaaS, and ad spend. The USD to CAD rate moves with interest-rate gaps between the Federal Reserve and the Bank of Canada, oil prices, and risk sentiment.'],
  ['code'=>'EUR','slug'=>'eur-to-cad-history','name'=>'Euro','sym'=>'€',
   'intro'=>'The euro matters for Canadian firms importing from the EU or selling into European markets. The EUR to CAD rate reflects the European Central Bank vs Bank of Canada policy gap and the relative strength of the eurozone economy.'],
  ['code'=>'GBP','slug'=>'gbp-to-cad-history','name'=>'British Pound','sym'=>'£',
   'intro'=>'The pound is common for Canadian businesses with UK clients, suppliers, or contractors. GBP to CAD has been unusually volatile since Brexit and tracks Bank of England rate decisions and UK growth data.'],
  ['code'=>'CNY','slug'=>'cny-to-cad-history','name'=>'Chinese Yuan','sym'=>'¥',
   'intro'=>'The yuan (renminbi) is key for importers sourcing goods from China. CNY to CAD is heavily managed by the People’s Bank of China, so it tends to move in tighter bands than free-floating currencies.'],
  ['code'=>'MXN','slug'=>'mxn-to-cad-history','name'=>'Mexican Peso','sym'=>'Mex$',
   'intro'=>'The Mexican peso is increasingly relevant as supply chains shift to North America under CUSMA. MXN to CAD reflects Banxico’s high policy rates and Mexico’s role as a manufacturing hub.'],
  ['code'=>'JPY','slug'=>'jpy-to-cad-history','name'=>'Japanese Yen','sym'=>'¥',
   'intro'=>'The yen is traded by Canadian importers of Japanese vehicles, machinery, and electronics. JPY to CAD has trended weak for years as the Bank of Japan held ultra-low rates while other central banks hiked.'],
  ['code'=>'AUD','slug'=>'aud-to-cad-history','name'=>'Australian Dollar','sym'=>'A$',
   'intro'=>'The Australian dollar is a fellow commodity currency, so AUD to CAD is relatively stable and often used as a comparison for the loonie. It moves with metals prices and Reserve Bank of Australia policy.'],
  ['code'=>'INR','slug'=>'inr-to-cad-history','name'=>'Indian Rupee','sym'=>'₹',
   'intro'=>'The Indian rupee is widely used by Canadian businesses with offshore teams, IT vendors, and family remittances. INR to CAD has drifted lower over time as Indian inflation runs above Canada’s.'],
];

$codes = array_map(fn($c)=>$c['code'], $CURRENCIES);
$rows  = fx_get_rows($codes, $CACHE, $LIVE_START);   // [date => [code => "value"]]
$dates = array_keys($rows);                              // ascending
if (count($dates) < 2) { fwrite(STDERR, "Not enough data.\n"); exit(1); }

$built = [];
foreach ($CURRENCIES as $c) {
    $ser = fx_series($rows, $c['code']);   // [date => float], ascending, no gaps
    if (count($ser) < 2) { fwrite(STDERR, "Skipping {$c['code']} (no data)\n"); continue; }
    write_csv($FXDIR . '/' . strtolower($c['code']) . '-cad.csv', $c['code'], $ser);
    file_put_contents($DATA . '/' . $c['slug'] . '.html', render_page($c, $ser, $CURRENCIES, $BASEURL));
    $built[] = $c;
}
file_put_contents($DATA . '/exchange-rate-history.html', render_hub($built, $rows, $BASEURL));
echo "Built " . count($built) . " currency pages + hub.\n";

// Refresh sitemap if gen-sitemap.php is present AND parses cleanly.
if (is_file($ROOT . '/gen-sitemap.php')) {
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($ROOT . '/gen-sitemap.php') . ' 2>&1', $o, $rc);
    if ($rc === 0) { @passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($ROOT . '/gen-sitemap.php')); }
    else { echo "Note: gen-sitemap.php has a syntax error; skipped. Update sitemap.xml manually.\n"; }
}

// ---------------- data helpers ----------------

function fx_get_rows($codes, $cacheDir, $liveStart) {
    sort($codes);
    $archive  = $cacheDir . '/boc-archive.csv';  // FROZEN history before $liveStart (committed, never refetched)
    $liveFile = $cacheDir . '/boc-live.csv';     // refreshed each run: $liveStart .. today

    // 1) Frozen archive (rates never change, so we never re-download these).
    $rows = is_file($archive) ? fx_parse(file_get_contents($archive)) : [];

    // 2) Live slice: fetch ONLY from $liveStart onward (small + light on the BoC API).
    $series = array_map(fn($c)=>'FX'.$c.'CAD', $codes);
    $url = 'https://www.bankofcanada.ca/valet/observations/' . implode(',', $series)
         . '/csv?start_date=' . $liveStart;
    $live = fx_http_get($url);
    if ($live !== null && stripos($live, 'date') !== false) {
        @file_put_contents($liveFile, $live);          // refresh cache on success
    } elseif (is_file($liveFile)) {
        $live = file_get_contents($liveFile);          // offline/failure fallback
    }
    if ($live) {
        foreach (fx_parse($live) as $d => $rec) $rows[$d] = $rec;   // live overrides/extends archive
    }

    if (!$rows) { fwrite(STDERR, "No archive and no live data available.\n"); exit(1); }
    ksort($rows);
    return $rows;
}
/** Parse BoC CSV (or our cache). Returns [date => [code => "value"]] ascending. */
function fx_parse($text) {
    $lines = preg_split('/\r\n|\r|\n/', $text);
    $header = null; $cols = []; $out = [];
    foreach ($lines as $line) {
        if ($line === '') continue;
        $f = str_getcsv($line);
        if ($header === null) {
            if (isset($f[0]) && strtolower(trim($f[0])) === 'date') {
                $header = $f;
                foreach ($f as $i => $h) { if ($i > 0) $cols[$i] = trim($h); }
            }
            continue;
        }
        $date = trim($f[0]);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;
        $rec = [];
        foreach ($cols as $i => $code) {
            $v = isset($f[$i]) ? trim($f[$i]) : '';
            if ($v !== '') $rec[preg_replace('/^FX|CAD$/', '', $code)] = $v;
        }
        if ($rec) $out[$date] = $rec;
    }
    ksort($out);
    return $out;
}

/** Per-currency ascending [date => float], dropping dates with no value. */
function fx_series($rows, $code) {
    $s = [];
    foreach ($rows as $d => $rec) {
        if (isset($rec[$code]) && $rec[$code] !== '') $s[$d] = (float) $rec[$code];
    }
    return $s;
}

function write_csv($path, $code, $ser) {
    $fp = fopen($path, 'w');
    fputcsv($fp, ['date', 'CAD_per_' . $code]);
    foreach ($ser as $d => $v) fputcsv($fp, [$d, $v]);
    fclose($fp);
}

function fx_http_get($url) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>true,
            CURLOPT_TIMEOUT=>30, CURLOPT_CONNECTTIMEOUT=>8,
            CURLOPT_USERAGENT=>'MapleBoost/1.0 (+https://mapleboost.ca)']);
        $b = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($b !== false && $code >= 200 && $code < 300) return $b;
    }
    if (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http'=>['timeout'=>30,
            'header'=>"User-Agent: MapleBoost/1.0\r\n"]]);
        $b = @file_get_contents($url, false, $ctx);
        if ($b !== false) return $b;
    }
    return null;
}

// ---------------- stats + formatting ----------------

function val_asof($ser, $targetDate) {
    $best = null;
    foreach ($ser as $d => $v) { if ($d <= $targetDate) $best = $v; else break; }
    return $best;
}

function fx_stats($ser) {
    $dates = array_keys($ser); $vals = array_values($ser);
    $n = count($vals);
    $lastDate = $dates[$n-1]; $last = $vals[$n-1];
    $prev = $vals[$n-2]; $prevDate = $dates[$n-2];
    $yAgo = date('Y-m-d', strtotime($lastDate . ' -1 year'));
    $yVal = val_asof($ser, $yAgo);
    // 52-week window
    $hi52=null;$lo52=null;
    foreach ($ser as $d=>$v) { if ($d >= $yAgo) { $hi52 = $hi52===null?$v:max($hi52,$v); $lo52 = $lo52===null?$v:min($lo52,$v); } }
    // all-time
    $atH=$vals[0];$atHd=$dates[0];$atL=$vals[0];$atLd=$dates[0];$sum=0;
    foreach ($ser as $d=>$v){ if($v>$atH){$atH=$v;$atHd=$d;} if($v<$atL){$atL=$v;$atLd=$d;} }
    // 1y average
    $c1=0;$s1=0; foreach($ser as $d=>$v){ if($d>=$yAgo){$s1+=$v;$c1++;} }
    return [
      'last'=>$last,'lastDate'=>$lastDate,'prev'=>$prev,'prevDate'=>$prevDate,
      'dayChg'=>$last-$prev,'dayPct'=>$prev?($last-$prev)/$prev*100:0,
      'yVal'=>$yVal,'yAgo'=>$yAgo,'yPct'=>$yVal?($last-$yVal)/$yVal*100:0,
      'hi52'=>$hi52,'lo52'=>$lo52,'atH'=>$atH,'atHd'=>$atHd,'atL'=>$atL,'atLd'=>$atLd,
      'avg1y'=>$c1?$s1/$c1:$last,'first'=>$vals[0],'firstDate'=>$dates[0],'n'=>$n,
    ];
}

function fr($v) { // format a rate with sensible precision
    if ($v === null) return '—';
    $dp = $v < 0.1 ? 6 : 4;
    return number_format($v, $dp);
}
function fpct($v) { $s = $v>=0?'+':''; return $s . number_format($v, 2) . '%'; }
function nice_date($d) { return date('M j, Y', strtotime($d)); }
function e($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// ---------------- page rendering ----------------

function render_page($c, $ser, $all, $base) {
    $code=$c['code']; $name=$c['name']; $slug=$c['slug'];
    $st = fx_stats($ser);
    $url = $base . '/data/' . $slug;
    $title = "$code to CAD Exchange Rate History (Bank of Canada) | MapleBoost";
    $desc  = "Full $name to Canadian dollar ($code/CAD) exchange rate history from the Bank of Canada. Interactive chart, daily rate table since 2017, and a free CSV download. Updated daily.";
    $today = date('Y-m-d');
    $dayWord = $st['dayChg']>=0 ? 'up' : 'down';

    // chart arrays (ascending)
    $labels = json_encode(array_keys($ser));
    $data   = json_encode(array_map(fn($v)=>round($v, 6), array_values($ser)));

    // related links
    $rel = '';
    foreach ($all as $o) if ($o['code'] !== $code) $rel .= '<li><a href="/data/'.$o['slug'].'">'.$o['code'].' to CAD</a></li>';

    // table rows (reverse chronological) with day-over-day change
    $rowsHtml = '';
    $rd = array_reverse($ser, true); $prevV = null;
    foreach ($rd as $d=>$v) {
        $chg = $prevV===null ? null : $v-$prevV; // note: iterating newest->oldest, so chg vs older handled below
        $rowsHtml .= '<tr><td>'.e($d).'</td><td>'.fr($v).'</td></tr>';
        $prevV = $v;
    }
    // day-over-day change computed correctly (vs previous *trading day*)
    $rowsHtml = '';
    $keys = array_keys($ser); // ascending
    for ($i = count($keys)-1; $i >= 0; $i--) {
        $d = $keys[$i]; $v = $ser[$d];
        $chgCell = '<td class="muted">—</td>';
        if ($i > 0) { $pv = $ser[$keys[$i-1]]; $diff = $v-$pv; $pct = $pv?($diff/$pv*100):0;
            $cls = $diff>0?'up':($diff<0?'down':'');
            $chgCell = '<td class="'.$cls.'">'.($diff>=0?'+':'').number_format($diff, ($v<0.1?6:4)).' ('.fpct($pct).')</td>';
        }
        $rowsHtml .= '<tr><td>'.e($d).'</td><td>'.fr($v).'</td>'.$chgCell.'</tr>';
    }

    // JSON-LD
    $dataset = json_encode([
      '@context'=>'https://schema.org','@type'=>'Dataset',
      'name'=>"$code to CAD daily exchange rate history",
      'description'=>"Daily average $name/Canadian dollar exchange rate from the Bank of Canada, $st[firstDate] to $st[lastDate].",
      'url'=>$url,'license'=>'https://www.bankofcanada.ca/terms/',
      'creator'=>['@type'=>'Organization','name'=>'Bank of Canada'],
      'temporalCoverage'=>$st['firstDate'].'/'.$st['lastDate'],
      'distribution'=>['@type'=>'DataDownload','encodingFormat'=>'text/csv',
        'contentUrl'=>$base.'/data/fx/'.strtolower($code).'-cad.csv'],
    ], JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);

    $faq = json_encode([
      '@context'=>'https://schema.org','@type'=>'FAQPage','mainEntity'=>[
        ['@type'=>'Question','name'=>"What is the $code to CAD exchange rate today?",
         'acceptedAnswer'=>['@type'=>'Answer','text'=>"As of {$st['lastDate']}, 1 $code = ".fr($st['last'])." CAD, per the Bank of Canada daily average rate. The table and chart on this page show the full history since {$st['firstDate']}."]],
        ['@type'=>'Question','name'=>"Where does this $code/CAD data come from?",
         'acceptedAnswer'=>['@type'=>'Answer','text'=>"Directly from the Bank of Canada Valet API — the official daily average exchange rates published each business day. The full series is free to download as CSV from this page."]],
        ['@type'=>'Question','name'=>"Which $code/CAD rate does the CRA accept?",
         'acceptedAnswer'=>['@type'=>'Answer','text'=>"The CRA generally accepts the Bank of Canada daily rate for the transaction date, or an annual average for recurring items. Use the table here to find the exact daily rate for your transaction date."]],
      ],
    ], JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);

    $crumb = json_encode([
      '@context'=>'https://schema.org','@type'=>'BreadcrumbList','itemListElement'=>[
        ['@type'=>'ListItem','position'=>1,'name'=>'Home','item'=>$base.'/'],
        ['@type'=>'ListItem','position'=>2,'name'=>'Data','item'=>$base.'/data'],
        ['@type'=>'ListItem','position'=>3,'name'=>'Exchange rate history','item'=>$base.'/data/exchange-rate-history'],
        ['@type'=>'ListItem','position'=>4,'name'=>"$code to CAD",'item'=>$url],
      ],
    ], JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);

    ob_start(); ?>
<!doctype html>
<html lang="en-CA">
<head>
<!--#include virtual="/inc/head.html" -->
<title><?=e($title)?></title>
<meta name="description" content="<?=e($desc)?>">
<link rel="canonical" href="<?=e($url)?>">
<meta property="og:title" content="<?=e("$code to CAD Exchange Rate History | MapleBoost")?>">
<meta property="og:description" content="<?=e("Full $code/CAD history from the Bank of Canada. Chart, daily table, free CSV.")?>">
<meta property="og:url" content="<?=e($url)?>">
<meta name="twitter:title" content="<?=e("$code to CAD Exchange Rate History")?>">
<meta name="twitter:description" content="Bank of Canada daily rates. Chart, table, CSV download.">
<script type="application/ld+json"><?=$dataset?></script>
<script type="application/ld+json"><?=$faq?></script>
<script type="application/ld+json"><?=$crumb?></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js" defer></script>
<style>
  .fx-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:20px 0 24px}
  .fx-stat{background:var(--bg-tint);border:1px solid var(--line);border-radius:var(--radius);padding:14px 16px}
  .fx-stat .k{font-size:.78rem;color:var(--ink-2);margin-bottom:4px}
  .fx-stat .v{font-size:1.3rem;font-weight:700;font-variant-numeric:tabular-nums;color:var(--ink)}
  .fx-stat .s{font-size:.8rem;margin-top:2px}
  .up{color:#0a7d33}.down{color:var(--maple-red)}
  .fx-chart-wrap{background:#fff;border:1px solid var(--line);border-radius:var(--radius);padding:16px;margin:16px 0}
  .fx-ranges{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px}
  .fx-ranges button{border:1px solid var(--line);background:#fff;border-radius:999px;padding:5px 12px;cursor:pointer;font-family:var(--sans);font-size:.85rem;color:var(--ink-2)}
  .fx-ranges button.active{background:var(--ink);color:#fff;border-color:var(--ink)}
  .fx-table-scroll{max-height:520px;overflow:auto;border:1px solid var(--line);border-radius:var(--radius);margin:12px 0}
  .fx-table-scroll table{width:100%;border-collapse:collapse;font-variant-numeric:tabular-nums}
  .fx-table-scroll th{position:sticky;top:0;background:var(--bg-tint);text-align:left;padding:8px 12px;font-size:.85rem;border-bottom:1px solid var(--line)}
  .fx-table-scroll td{padding:6px 12px;border-bottom:1px solid var(--line);font-size:.9rem}
  @media(max-width:640px){.fx-stats{grid-template-columns:repeat(2,1fr)}}
</style>
</head>
<body>
<!--#include virtual="/inc/nav.html" -->
<main id="content">
<div class="container">
  <article class="article-wrap">
    <div class="article">
      <span class="eyebrow">Data &middot; Exchange rates</span>
      <h1><?=e("$name to Canadian Dollar — $code/CAD history")?></h1>
      <p class="lead">As of <strong><?=nice_date($st['lastDate'])?></strong>, <strong>1 <?=$code?> = <?=fr($st['last'])?> CAD</strong> (<?=$dayWord?> <?=number_format(abs($st['dayChg']), ($st['last']<0.1?6:4))?>, <?=fpct($st['dayPct'])?> from the prior business day). Source: Bank of Canada daily average rates.</p>
      <!--#include virtual="/inc/disclosure.html" -->

      <div class="fx-stats">
        <div class="fx-stat"><div class="k">Latest (<?=nice_date($st['lastDate'])?>)</div><div class="v"><?=fr($st['last'])?></div><div class="s <?=$st['dayChg']>=0?'up':'down'?>"><?=fpct($st['dayPct'])?> day</div></div>
        <div class="fx-stat"><div class="k">1-year change</div><div class="v"><?=fr($st['yVal'])?></div><div class="s <?=$st['yPct']>=0?'up':'down'?>"><?=fpct($st['yPct'])?></div></div>
        <div class="fx-stat"><div class="k">52-week range</div><div class="v" style="font-size:1.05rem"><?=fr($st['lo52'])?>–<?=fr($st['hi52'])?></div><div class="s muted">low–high</div></div>
        <div class="fx-stat"><div class="k">Since 2017</div><div class="v" style="font-size:1.05rem"><?=fr($st['atL'])?>–<?=fr($st['atH'])?></div><div class="s muted">all-time range</div></div>
      </div>

      <div class="fx-chart-wrap">
        <div class="fx-ranges" id="fx-ranges">
          <button data-d="30">1M</button><button data-d="180">6M</button>
          <button data-d="365" class="active">1Y</button><button data-d="1825">5Y</button>
          <button data-d="0">Max</button>
        </div>
        <canvas id="fxChart" height="110"></canvas>
      </div>

      <p><?=e($c['intro'])?></p>

      <h2>How to use this for taxes and accounting</h2>
      <p>For Canadian tax filing, the CRA generally accepts the Bank of Canada daily rate for the day a transaction took place, or an average rate for the year for items that recur throughout the year. To convert a single <?=$code?> amount, find the transaction date in the table below and multiply by that day’s rate. For a one-off conversion, use our <a href="/tools/currency-converter">currency converter</a>; for the yearly average, the figures here let you compute it from the raw series.</p>

      <h2>Download the full <?=$code?>/CAD data</h2>
      <p>The complete daily series (<?=number_format($st['n'])?> business days, <?=nice_date($st['firstDate'])?> to <?=nice_date($st['lastDate'])?>) is free to download:</p>
      <p><a class="btn btn-primary" href="/data/fx/<?=strtolower($code)?>-cad.csv" download>Download <?=$code?>/CAD CSV</a></p>

      <h2>Daily <?=$code?> to CAD rate table</h2>
      <p class="muted small">Most recent first. “Change” is versus the previous business day.</p>
      <div class="fx-table-scroll">
        <table>
          <thead><tr><th>Date</th><th>1 <?=$code?> in CAD</th><th>Change</th></tr></thead>
          <tbody><?=$rowsHtml?></tbody>
        </table>
      </div>

      <h2>FAQ</h2>
      <h3>What is the <?=$code?> to CAD rate today?</h3>
      <p>As of <?=nice_date($st['lastDate'])?>, 1 <?=$code?> = <?=fr($st['last'])?> CAD (Bank of Canada daily average). It was <?=fr($st['yVal'])?> a year earlier, a <?=fpct($st['yPct'])?> change.</p>
      <h3>Is this a live trading rate?</h3>
      <p>No — it’s the Bank of Canada’s official daily average, published once each business day around 16:30 ET. Your bank or card will add a markup on top.</p>

      <p class="muted small">Last updated: <?=nice_date($today)?>. Data: Bank of Canada Valet API. <a href="/contact">Spot an error?</a></p>
    </div>

    <aside class="sidebar">
      <aside class="cta-card" aria-label="Recommended partner">
        <span class="cta-eyebrow">Cut the markup</span>
        <h3 class="cta-title">Wise Business for <?=$code?></h3>
        <p class="cta-body">These are benchmark rates — banks add 2–4%. Wise Business uses the mid-market rate with one clear fee, ideal for paying <?=$name?> invoices or getting paid in <?=$code?>.</p>
        <a class="btn btn-primary" href="/go?id=wise_biz" rel="sponsored nofollow noopener" target="_blank">Try Wise Business &rarr;</a>
      </aside>
      <aside class="cta-card" aria-label="Tools">
        <h3 class="cta-title">Convert an amount</h3>
        <p class="cta-body">Need a specific figure? Use the live converter with any date.</p>
        <a class="btn" href="/tools/currency-converter">Currency converter &rarr;</a>
      </aside>
      <aside class="cta-card" aria-label="Other currencies">
        <h3 class="cta-title">Other currencies</h3>
        <ul class="cta-list"><?=$rel?></ul>
      </aside>
    </aside>
  </article>
</div>

<script>
  var FX_LABELS = <?=$labels?>;
  var FX_DATA = <?=$data?>;
  (function(){
    function build(){
      if (typeof Chart === 'undefined') { return setTimeout(build, 120); }
      var ctx = document.getElementById('fxChart').getContext('2d');
      var chart = new Chart(ctx, {
        type:'line',
        data:{ labels:FX_LABELS, datasets:[{ data:FX_DATA, borderColor:'#c8102e',
          borderWidth:1.5, pointRadius:0, tension:.1, fill:true,
          backgroundColor:'rgba(200,16,46,.06)' }] },
        options:{ responsive:true, maintainAspectRatio:true, animation:false,
          plugins:{ legend:{display:false}, tooltip:{ callbacks:{ label:function(c){ return c.parsed.y + ' CAD'; } } } },
          scales:{ x:{ ticks:{ maxTicksLimit:8, maxRotation:0 }, grid:{display:false} },
                   y:{ ticks:{ maxTicksLimit:6 } } } }
      });
      function setRange(days){
        var L=FX_LABELS, D=FX_DATA;
        if(days>0 && L.length>days){ L=L.slice(-days); D=D.slice(-days); }
        chart.data.labels=L; chart.data.datasets[0].data=D; chart.update();
      }
      setRange(365);
      document.getElementById('fx-ranges').addEventListener('click', function(ev){
        var b=ev.target.closest('button'); if(!b) return;
        [].forEach.call(this.querySelectorAll('button'), function(x){x.classList.remove('active');});
        b.classList.add('active'); setRange(parseInt(b.dataset.d,10));
      });
    }
    build();
  })();
</script>
<!--#include virtual="/inc/footer.html" -->
<?php
    return ob_get_clean();
}

function render_hub($built, $rows, $base) {
    $url = $base . '/data/exchange-rate-history';
    $cards = '';
    $rowsTable = '';
    foreach ($built as $c) {
        $ser = fx_series($rows, $c['code']);
        $st = fx_stats($ser);
        $cls = $st['yPct']>=0?'up':'down';
        $rowsTable .= '<tr><td><a href="/data/'.$c['slug'].'">'.$c['code'].' / CAD</a></td>'
          .'<td>'.e($c['name']).'</td><td style="text-align:right">'.fr($st['last']).'</td>'
          .'<td style="text-align:right" class="'.$cls.'">'.fpct($st['yPct']).'</td></tr>';
    }
    $crumb = json_encode(['@context'=>'https://schema.org','@type'=>'BreadcrumbList','itemListElement'=>[
        ['@type'=>'ListItem','position'=>1,'name'=>'Home','item'=>$base.'/'],
        ['@type'=>'ListItem','position'=>2,'name'=>'Data','item'=>$base.'/data'],
        ['@type'=>'ListItem','position'=>3,'name'=>'Exchange rate history','item'=>$url],
    ]], JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
    $today = date('Y-m-d');
    ob_start(); ?>
<!doctype html>
<html lang="en-CA">
<head>
<!--#include virtual="/inc/head.html" -->
<title>Exchange Rate History vs the Canadian Dollar (Bank of Canada) | MapleBoost</title>
<meta name="description" content="Historical exchange rates for major currencies against the Canadian dollar, from the Bank of Canada. Interactive charts, daily tables since 2017, and free CSV downloads.">
<link rel="canonical" href="<?=e($url)?>">
<meta property="og:title" content="Exchange Rate History vs the Canadian Dollar | MapleBoost">
<meta property="og:description" content="Charts, daily tables, and CSV downloads for major currencies vs CAD.">
<meta property="og:url" content="<?=e($url)?>">
<script type="application/ld+json"><?=$crumb?></script>
</head>
<body>
<!--#include virtual="/inc/nav.html" -->
<main id="content">
<div class="container">
  <article class="article-wrap">
    <div class="article">
      <span class="eyebrow">Data</span>
      <h1>Exchange rate history vs the Canadian dollar</h1>
      <p class="lead">Official Bank of Canada daily rates for the currencies Canadian businesses use most. Each page has an interactive chart, the full daily table since 2017, and a free CSV download.</p>
      <table class="data-table">
        <thead><tr><th>Pair</th><th>Currency</th><th style="text-align:right">Latest (CAD)</th><th style="text-align:right">1-yr</th></tr></thead>
        <tbody><?=$rowsTable?></tbody>
      </table>
      <p style="margin-top:18px">Need a specific amount or date? Use the <a href="/tools/currency-converter">currency converter</a>.</p>
      <p class="muted small">Last updated: <?=nice_date($today)?>. Source: Bank of Canada Valet API.</p>
    </div>
    <aside class="sidebar">
      <aside class="cta-card"><span class="cta-eyebrow">Cut the markup</span>
        <h3 class="cta-title">Wise Business</h3>
        <p class="cta-body">Mid-market FX with one transparent fee — for paying overseas suppliers or invoicing in foreign currencies.</p>
        <a class="btn btn-primary" href="/go?id=wise_biz" rel="sponsored nofollow noopener" target="_blank">Try Wise Business &rarr;</a></aside>
    </aside>
  </article>
</div>
<!--#include virtual="/inc/footer.html" -->
<?php
    return ob_get_clean();
}
