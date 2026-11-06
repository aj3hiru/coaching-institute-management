<?php
// Analytics in the style of Plausible: visitors, visits, page views, views per visit, bounce rate and visit duration,
// a graph, and where readers come from / what they read / where they are / what they use. Click any row to filter.
// Data: includes/ea.php (collected by assets/js/ea.js). Authors only see visits to their own posts.
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/ea.php';
$me = admin_guard(['admin', 'editor', 'author']);
if (!admin_can('analytics.view_basic')) { http_response_code(403); exit('Access Denied'); }
ea_tables($pdo);

$scope = null;
if ($me['role'] === 'author') {
    $st = $pdo->prepare("SELECT p.id FROM posts p JOIN authors a ON a.id = p.author_id WHERE a.user_id = ?");
    $st->execute([(int)$me['id']]);
    $scope = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

// ── JSON for the page's script ──────────────────────────────────────────────────────────────────────────────────────
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $periodKey = (string)($_GET['period'] ?? '28d');
    [$start, $end, $label, $interval] = ea_period($periodKey, $_GET['from'] ?? null, $_GET['to'] ?? null);
    $allowed = ['minute' => ['minute'], 'hour' => ['hour'], 'day' => ['day', 'week'], 'week' => ['day', 'week', 'month'], 'month' => ['day', 'week', 'month']][$interval];
    if (in_array($_GET['interval'] ?? '', $allowed, true)) $interval = $_GET['interval'];
    $filters = [];
    foreach ($_GET as $k => $v) if (str_starts_with($k, 'f_') && is_string($v) && $v !== '') $filters[substr($k, 2)] = mb_substr($v, 0, 255);
    try {
        if ($_GET['api'] === 'main') {
            $top = ea_top_stats($pdo, $filters, $start, $end, $scope);
            $prev = null;
            if (!in_array($periodKey, ['all', 'realtime'], true)) {
                // the same stretch just before (for periods still running: only up to the same point in time)
                $len = strtotime($end) - strtotime($start);
                $pStart = strtotime($start) - $len;
                $pEnd = $pStart + (strtotime($end) > time() ? max(0, time() - strtotime($start)) : $len);
                $prev = ea_top_stats($pdo, $filters, date('Y-m-d H:i:s', $pStart), date('Y-m-d H:i:s', $pEnd), $scope);
            }
            $first = $pdo->query("SELECT MIN(started) FROM ea_sessions")->fetchColumn();
            echo json_encode([
                'label' => $label, 'interval' => $interval, 'intervals' => $allowed, 'current' => ea_current_visitors($pdo, $scope),
                'top' => $top, 'prev' => $prev, 'series' => ea_timeseries($pdo, $filters, $start, $end, $interval, $scope),
                'since' => $first ? date('j M Y', strtotime($first)) : null, 'sinceTs' => $first ? strtotime($first) : null, 'startTs' => strtotime($start),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } elseif ($_GET['api'] === 'list') {
            $dim = (string)($_GET['dim'] ?? '');
            echo json_encode(ea_breakdown($pdo, $dim, $filters, $start, $end, $scope, (int)($_GET['limit'] ?? 9), mb_substr((string)($_GET['q'] ?? ''), 0, 100)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            echo '[]';
        }
    } catch (Throwable $e) {
        error_log('analytics: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Could not load the numbers.']);
    }
    exit;
}

$esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$host = (string)parse_url(SITE_URL, PHP_URL_HOST);
$fav = function_exists('es') ? (string)es('general', 'favicon') : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Analytics - <?= $esc(edu_site_name()) ?></title>
<?php admin_shell_head(); ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js" defer></script>
<style>
.ea{--bg:#f9fafb;--card:#fff;--line:#e5e7eb;--line2:#f1f2f4;--tx:#111827;--tx2:#374151;--mut:#6b7280;--acc:#6366f1;--acc2:#4f46e5;--bar:#eef2ff;--bar2:#e0e7ff;--up:#16a34a;--down:#dc2626;--hover:#f6f7f9;--shadow:0 1px 2px rgba(16,24,40,.05);max-width:1120px;margin:0 auto;color:var(--tx);font-size:14px}
.ea[data-theme=dark]{--bg:#0f1012;--card:#18181b;--line:#27272a;--line2:#202024;--tx:#f4f4f5;--tx2:#d4d4d8;--mut:#a1a1aa;--acc:#818cf8;--acc2:#a5b4fc;--bar:#26263a;--bar2:#30304a;--up:#4ade80;--down:#f87171;--hover:#1f1f23;--shadow:none;background:var(--bg);border-radius:14px;padding:12px;margin-top:-6px}
.ea *{box-sizing:border-box}
.ea button{font:inherit}
.ea-top{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin:4px 0 14px}
.ea-site{display:inline-flex;align-items:center;gap:9px;font-weight:700;font-size:15px;color:var(--tx)}
.ea-site img{width:20px;height:20px;border-radius:5px;object-fit:cover}
.ea-live{display:inline-flex;align-items:center;gap:8px;color:var(--mut);font-size:13.5px;cursor:pointer;background:none;border:0;padding:4px 2px}
.ea-live:hover{color:var(--tx)}
.ea-dot{width:9px;height:9px;border-radius:50%;background:#22c55e;position:relative}
.ea-dot::after{content:"";position:absolute;inset:-4px;border-radius:50%;background:#22c55e;opacity:.35;animation:eaPulse 1.8s ease-out infinite}
@keyframes eaPulse{0%{transform:scale(.5);opacity:.5}100%{transform:scale(1.6);opacity:0}}
.ea-sp{flex:1}
.ea-btn{display:inline-flex;align-items:center;gap:8px;height:36px;padding:0 13px;border:1px solid var(--line);border-radius:8px;background:var(--card);color:var(--tx2);font-weight:600;font-size:13.5px;cursor:pointer;box-shadow:var(--shadow);white-space:nowrap}
.ea-btn:hover{border-color:var(--acc);color:var(--tx)}
.ea-btn svg{width:15px;height:15px;flex-shrink:0}
.ea-dd{position:relative}
.ea-menu{position:absolute;right:0;top:calc(100% + 6px);z-index:60;min-width:230px;padding:6px;background:var(--card);border:1px solid var(--line);border-radius:10px;box-shadow:0 14px 34px rgba(15,23,42,.16)}
.ea-menu[hidden]{display:none}
.ea-menu button{display:flex;width:100%;justify-content:space-between;align-items:center;gap:10px;padding:8px 10px;border:0;border-radius:6px;background:none;color:var(--tx2);font-size:13.5px;text-align:left;cursor:pointer}
.ea-menu button:hover{background:var(--hover);color:var(--tx)}
.ea-menu button.on{color:var(--acc);font-weight:700}
.ea-menu kbd{font:600 11px/1 ui-monospace,monospace;color:var(--mut);border:1px solid var(--line);border-radius:4px;padding:2px 5px}
.ea-menu hr{border:0;border-top:1px solid var(--line);margin:5px 2px}
.ea-custom{display:grid;grid-template-columns:1fr 1fr;gap:6px;padding:6px 4px 2px}
.ea-custom input{height:34px;border:1px solid var(--line);border-radius:6px;padding:0 8px;background:var(--card);color:var(--tx);font-size:13px;min-width:0}
.ea-menu .ea-custom button{grid-column:1/-1;justify-content:center;background:var(--acc);color:#fff;font-weight:700}
.ea-menu .ea-custom button:hover{background:var(--acc2);color:#fff}
.ea-chips{display:flex;flex-wrap:wrap;gap:8px;margin:-4px 0 14px}
.ea-chips[hidden]{display:none}
.ea-chip{display:inline-flex;align-items:center;gap:7px;height:30px;padding:0 6px 0 11px;border-radius:7px;background:var(--card);border:1px solid var(--line);font-size:13px;color:var(--tx2);box-shadow:var(--shadow)}
.ea-chip b{color:var(--tx)}
.ea-chip button{width:20px;height:20px;border:0;border-radius:5px;background:none;color:var(--mut);cursor:pointer;display:grid;place-items:center}
.ea-chip button:hover{background:var(--hover);color:var(--down)}
.ea-chip.clear{background:none;border:0;box-shadow:none;color:var(--acc);font-weight:600;cursor:pointer;padding:0 6px}
.ea-card{background:var(--card);border:1px solid var(--line);border-radius:12px;box-shadow:var(--shadow);min-width:0}
.ea-stats{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:2px;padding:8px}
.ea-stat{position:relative;padding:10px 12px 12px;border:0;border-radius:8px;background:none;text-align:left;cursor:pointer;color:var(--tx);min-width:0}
.ea-stat:hover,.ea-stat.on{background:var(--hover)}
.ea-stat[disabled]{cursor:default;background:none}
.ea-stat.on .ea-sl{color:var(--acc)}
.ea-stat:not(:last-child)::after{content:"";position:absolute;right:-1px;top:14px;bottom:14px;width:1px;background:var(--line)}
.ea-sl{display:block;font-size:11.5px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--mut);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ea-sv{display:flex;align-items:baseline;gap:8px;margin-top:6px;flex-wrap:wrap}
.ea-sv b{font-size:22px;font-weight:800;letter-spacing:-.01em;line-height:1.1}
.ea-ch{font-size:12px;font-weight:700;white-space:nowrap}
.ea-ch.up{color:var(--up)}.ea-ch.down{color:var(--down)}.ea-ch.flat{color:var(--mut)}
.ea-graph{position:relative;padding:4px 16px 12px}
.ea-gtop{display:flex;justify-content:flex-end;align-items:center;gap:10px;min-height:26px}
.ea-gtop select{height:28px;border:1px solid var(--line);border-radius:6px;background:var(--card);color:var(--tx2);font-size:12.5px;font-weight:600;padding:0 8px}
.ea-gtop select[hidden]{display:none}
.ea-canvas{position:relative;height:300px}
.ea-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-top:16px}
.ea-panel{display:flex;flex-direction:column;padding:14px 18px 10px;min-height:440px}
.ea-ph{display:flex;align-items:center;gap:6px;border-bottom:1px solid var(--line);margin:0 0 10px}
.ea-tabs{display:flex;gap:16px;flex:1;min-width:0;overflow-x:auto;scrollbar-width:none}
.ea-tabs::-webkit-scrollbar{display:none}
.ea-tab{position:relative;padding:8px 0 10px;border:0;background:none;color:var(--mut);font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;cursor:pointer;white-space:nowrap}
.ea-tab:hover,.ea-tab.on{color:var(--tx)}
.ea-tab.on::after{content:"";position:absolute;left:0;right:0;bottom:-1px;height:2px;background:var(--acc);border-radius:2px}
.ea-ph{position:relative}.ea-tab .cv{display:inline-block;width:9px;height:9px;margin-left:4px;vertical-align:1px}
.ea-utm{position:absolute;left:0;top:calc(100% + 4px);z-index:20;min-width:180px}
.ea-x{width:28px;height:28px;border:0;border-radius:6px;background:none;color:var(--mut);cursor:pointer;display:grid;place-items:center;flex-shrink:0}
.ea-x:hover{background:var(--hover);color:var(--tx)}
.ea-x svg{width:15px;height:15px}
.ea-body{display:flex;flex-direction:column;flex:1}
.ea-lh{display:flex;justify-content:space-between;gap:10px;padding:2px 0 6px;font-size:12px;font-weight:700;color:var(--mut)}
.ea-list{flex:1}
.ea-row{position:relative;display:flex;align-items:center;gap:10px;height:34px;margin:0 0 3px;cursor:pointer}
.ea-row .bar{position:absolute;left:0;top:0;bottom:0;border-radius:6px;background:var(--bar);transition:width .35s ease}
.ea-row:hover .bar{background:var(--bar2)}
.ea-row .nm{position:relative;flex:1;min-width:0;display:flex;align-items:center;gap:8px;padding:0 8px;color:var(--tx);font-size:13.5px}
.ea-row .nm span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ea-row .nm img,.ea-row .nm .ic{width:16px;height:16px;flex-shrink:0;border-radius:3px}
.ea-row .nm .ic{display:grid;place-items:center;color:var(--mut)}
.ea-row .nm .ic svg{width:14px;height:14px}
.ea-row .nm .fl{font-size:16px;line-height:1;flex-shrink:0}
.ea-row .go{position:relative;display:none;color:var(--mut);flex-shrink:0}
.ea-row .go svg{width:13px;height:13px}
.ea-row:hover .go{display:inline-flex}
.ea-row .go:hover{color:var(--acc)}
.ea-row .vl{position:relative;min-width:56px;text-align:right;font-weight:700;font-size:13.5px;color:var(--tx);white-space:nowrap}
.ea-row .pc{position:relative;display:none;color:var(--mut);font-weight:600;font-size:12.5px;min-width:44px;text-align:right}
.ea-row:hover .pc{display:inline}
.ea-more{display:flex;justify-content:center;padding:8px 0 4px}
.ea-more button{border:0;background:none;color:var(--mut);font-size:12px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;cursor:pointer;display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:6px}
.ea-more button:hover{color:var(--tx);background:var(--hover)}
.ea-more svg{width:12px;height:12px}
.ea-empty{display:grid;place-items:center;flex:1;color:var(--mut);font-size:13.5px;text-align:center;padding:30px 10px;line-height:1.6}
.ea-spin{width:30px;height:30px;border-radius:50%;border:3px solid var(--line);border-top-color:var(--acc);animation:eaSpin .8s linear infinite;margin:auto}
@keyframes eaSpin{to{transform:rotate(360deg)}}
.ea-loading{display:grid;place-items:center;flex:1;min-height:200px}
.ea-note{margin:0 0 14px;padding:10px 14px;border-radius:10px;background:var(--card);border:1px solid var(--line);color:var(--tx2);font-size:13px;line-height:1.55;box-shadow:var(--shadow)}
.ea-note a{color:var(--acc);font-weight:600}
.ea-modal{position:fixed;inset:0;z-index:100050;display:grid;place-items:center;padding:20px;background:rgba(15,23,42,.45)}
.ea-modal[hidden]{display:none}
.ea-mbox{width:min(900px,100%);max-height:calc(100vh - 40px);display:flex;flex-direction:column;background:var(--card);color:var(--tx);border-radius:14px;box-shadow:0 24px 60px rgba(0,0,0,.3);overflow:hidden}
.ea-mh{display:flex;align-items:center;gap:12px;padding:16px 20px;border-bottom:1px solid var(--line)}
.ea-mh h3{margin:0;font-size:17px;flex:1;color:var(--tx)}
.ea-mh input{height:34px;width:min(260px,45vw);border:1px solid var(--line);border-radius:8px;padding:0 10px;background:var(--card);color:var(--tx);font-size:13px}
.ea-mb{overflow:auto;padding:8px 20px 16px}
.ea-tbl{width:100%;border-collapse:collapse;font-size:13.5px}
.ea-tbl th{position:sticky;top:0;background:var(--card);text-align:right;font-size:11.5px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--mut);padding:10px 8px;border-bottom:1px solid var(--line);white-space:nowrap}
.ea-tbl th:first-child,.ea-tbl td:first-child{text-align:left}
.ea-tbl td{padding:9px 8px;border-bottom:1px solid var(--line2);text-align:right;white-space:nowrap;color:var(--tx)}
.ea-tbl td:first-child{white-space:normal;word-break:break-all;cursor:pointer}
.ea-tbl td:first-child:hover{color:var(--acc)}
.ea-tbl tr:hover td{background:var(--hover)}
.ea-ff{display:grid;gap:10px;padding:16px 20px 20px}
.ea-ff label{display:grid;gap:6px;font-size:12.5px;font-weight:700;color:var(--mut);text-transform:uppercase;letter-spacing:.04em}
.ea-ff select,.ea-ff input{height:38px;border:1px solid var(--line);border-radius:8px;padding:0 10px;background:var(--card);color:var(--tx);font-size:14px;text-transform:none;letter-spacing:0;font-weight:500}
.ea-sug{display:grid;gap:2px;max-height:240px;overflow:auto}
.ea-sug button{display:flex;justify-content:space-between;gap:10px;padding:8px 10px;border:0;border-radius:6px;background:none;color:var(--tx2);text-align:left;cursor:pointer;font-size:13.5px}
.ea-sug button:hover{background:var(--hover);color:var(--tx)}
.ea-sug small{color:var(--mut);white-space:nowrap}
.ea-foot{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;margin:16px 2px 0;color:var(--mut);font-size:12.5px}
.ea-foot a{color:var(--acc);font-weight:600}
@media (max-width:980px){.ea-stats{grid-template-columns:repeat(3,minmax(0,1fr))}.ea-stat:nth-child(3n)::after{display:none}.ea-grid{grid-template-columns:minmax(0,1fr)}.ea-panel{min-height:0}}
@media (max-width:560px){.ea-stats{grid-template-columns:repeat(2,minmax(0,1fr))!important}.ea-stat::after{display:none}.ea-sv b{font-size:19px}.ea-canvas{height:220px}.ea-graph{padding:4px 8px 10px}.ea-panel{padding:12px 12px 8px}.ea-top{gap:10px}.ea-sp{display:none}.ea-btn .lbl{display:none}.ea-mh{flex-wrap:wrap}.ea-mh input{width:100%}.ea-menu{right:auto;left:0}}
</style>
</head>
<body>
<?php admin_shell_open('Analytics', 'Visitors, pages, sources, countries and devices'); ?>
<div class="ea" id="ea" data-theme="light">
  <div class="ea-top">
    <span class="ea-site"><?php if ($fav): ?><img src="<?= $esc($fav) ?>" alt=""><?php endif; ?><?= $esc($host) ?></span>
    <button type="button" class="ea-live" id="eaLive" title="Show Realtime"><span class="ea-dot"></span><span id="eaCur">0 current visitors</span></button>
    <span class="ea-sp"></span>
    <button type="button" class="ea-btn" id="eaFilterBtn"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 6h18M7 12h10M10 18h4"/></svg><span class="lbl">Filter</span></button>
    <div class="ea-dd">
      <button type="button" class="ea-btn" id="eaPeriodBtn"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg><span id="eaPeriodLbl">Today</span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="m6 9 6 6 6-6"/></svg></button>
      <div class="ea-menu" id="eaPeriodMenu" hidden>
        <button data-p="today">Today <kbd>D</kbd></button><button data-p="yesterday">Yesterday <kbd>E</kbd></button><button data-p="realtime">Realtime <kbd>R</kbd></button>
        <hr><button data-p="7d">Last 7 days <kbd>W</kbd></button><button data-p="28d">Last 28 days <kbd>F</kbd></button><button data-p="30d">Last 30 days <kbd>T</kbd></button><button data-p="91d">Last 91 days <kbd>N</kbd></button>
        <hr><button data-p="month">Month to date <kbd>M</kbd></button><button data-p="last_month">Last month <kbd>P</kbd></button>
        <hr><button data-p="year">Year to date <kbd>Y</kbd></button><button data-p="12mo">Last 12 months <kbd>L</kbd></button><button data-p="all">All time <kbd>A</kbd></button>
        <hr><div class="ea-custom"><input type="date" id="eaFrom" aria-label="From"><input type="date" id="eaTo" aria-label="To"><button type="button" id="eaCustomGo">Custom range</button></div>
      </div>
    </div>
    <button type="button" class="ea-btn" id="eaTheme" title="Light / dark"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg></button>
  </div>
  <div class="ea-chips" id="eaChips" hidden></div>
  <div id="eaNote"></div>
  <div class="ea-card">
    <div class="ea-stats" id="eaStats"><div class="ea-loading" style="grid-column:1/-1;min-height:70px"><div class="ea-spin"></div></div></div>
    <div class="ea-graph"><div class="ea-gtop"><select id="eaInterval" aria-label="Interval" hidden></select></div><div class="ea-canvas"><canvas id="eaChart"></canvas></div></div>
  </div>
  <div class="ea-grid">
    <div class="ea-card ea-panel" data-panel="sources" data-tabs='[["channel","Channels"],["source","Sources"],["utm","Campaigns"]]'></div>
    <div class="ea-card ea-panel" data-panel="pages" data-tabs='[["page","Top Pages"],["entry","Entry Pages"],["exit","Exit Pages"]]'></div>
    <div class="ea-card ea-panel" data-panel="locations" data-tabs='[["country","Countries"]]'></div>
    <div class="ea-card ea-panel" data-panel="devices" data-tabs='[["browser","Browsers"],["os","Operating Systems"],["device","Devices"]]'></div>
  </div>
  <div class="ea-foot"><span>No cookies · visitors are counted with a daily anonymous id · your own visits while logged in are not counted</span></div>

  <div class="ea-modal" id="eaModal" hidden><div class="ea-mbox" role="dialog" aria-modal="true">
    <div class="ea-mh"><h3 id="eaMTitle"></h3><input type="search" id="eaMSearch" placeholder="Search"><button type="button" class="ea-x" data-close aria-label="Close"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg></button></div>
    <div class="ea-mb" id="eaMBody"></div>
  </div></div>
  <div class="ea-modal" id="eaFModal" hidden><div class="ea-mbox" role="dialog" aria-modal="true" style="width:min(460px,100%)">
    <div class="ea-mh"><h3>Filter by</h3><button type="button" class="ea-x" data-close aria-label="Close"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg></button></div>
    <div class="ea-ff">
      <label>Property<select id="eaFDim"></select></label>
      <label>Value<input type="search" id="eaFQ" placeholder="Start typing…" autocomplete="off"></label>
      <div class="ea-sug" id="eaFSug"></div>
    </div>
  </div></div>
</div>
<?php admin_shell_close(); ?>
<script>
(function () {
  var root = document.getElementById("ea"), api = "/admin/analytics.php?api=";
  var DIMS = { channel: "Channel", source: "Source", utm_medium: "UTM medium", utm_source: "UTM source", utm_campaign: "UTM campaign", page: "Page", entry: "Entry page", exit: "Exit page", country: "Country", browser: "Browser", os: "Operating system", device: "Device" };
  var METRICS = [["visitors", "Unique visitors"], ["visits", "Total visits"], ["pageviews", "Total pageviews"], ["views_per_visit", "Views per visit"], ["bounce_rate", "Bounce rate"], ["visit_duration", "Visit duration"]];
  var PERIODS = { today: "Today", yesterday: "Yesterday", realtime: "Realtime", "7d": "Last 7 days", "28d": "Last 28 days", "30d": "Last 30 days", "91d": "Last 91 days", month: "Month to date", last_month: "Last month", year: "Year to date", "12mo": "Last 12 months", all: "All time" };
  var KEYS = { d: "today", e: "yesterday", r: "realtime", w: "7d", f: "28d", t: "30d", n: "91d", m: "month", p: "last_month", y: "year", l: "12mo", a: "all" };

  // ── state in the address bar ─────────────────────────────────────────────
  var S = { period: "today", from: "", to: "", metric: "visitors", interval: "", f: {} };
  (function () {
    var q = new URLSearchParams(location.search);
    if (q.get("period") && (PERIODS[q.get("period")] || q.get("period") === "custom")) S.period = q.get("period");
    S.from = q.get("from") || ""; S.to = q.get("to") || "";
    if (METRICS.some(function (m) { return m[0] === q.get("metric"); })) S.metric = q.get("metric");
    S.interval = q.get("interval") || "";
    q.forEach(function (v, k) { if (k.indexOf("f_") === 0 && DIMS[k.slice(2)] && v) S.f[k.slice(2)] = v; });
  })();
  function qs(extra) {
    var q = new URLSearchParams();
    q.set("period", S.period);
    if (S.period === "custom") { q.set("from", S.from); q.set("to", S.to); }
    if (S.interval) q.set("interval", S.interval);
    Object.keys(S.f).forEach(function (k) { q.set("f_" + k, S.f[k]); });
    if (extra) Object.keys(extra).forEach(function (k) { q.set(k, extra[k]); });
    return q.toString();
  }
  function pushUrl() {
    var q = new URLSearchParams(qs());
    if (S.metric !== "visitors") q.set("metric", S.metric);
    if (q.get("period") === "today") q.delete("period");
    history.replaceState(null, "", location.pathname + (q.toString() ? "?" + q : ""));
  }

  // ── helpers ──────────────────────────────────────────────────────────────
  function esc(s) { return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]; }); }
  function num(n) { n = +n || 0; if (n >= 1e6) return (n / 1e6).toFixed(1).replace(/\.0$/, "") + "M"; if (n >= 1e3) return (n / 1e3).toFixed(n >= 1e5 ? 0 : 1).replace(/\.0$/, "") + "k"; return String(Math.round(n * 100) / 100); }
  function dur(s) { s = Math.round(+s || 0); if (s < 60) return s + "s"; var m = Math.floor(s / 60), h = Math.floor(m / 60); return h ? h + "h " + (m % 60) + "m" : m + "m " + (s % 60) + "s"; }
  function fmt(metric, v) { return metric === "visit_duration" ? dur(v) : metric === "bounce_rate" ? Math.round(+v || 0) + "%" : metric === "views_per_visit" ? String(Math.round((+v || 0) * 100) / 100) : num(v); }
  var regionNames = null; try { regionNames = new Intl.DisplayNames(["en"], { type: "region" }); } catch (e) {}
  function country(cc) { if (!cc || cc === "XX") return "Unknown"; try { return regionNames ? regionNames.of(cc) : cc; } catch (e) { return cc; } }
  function flag(cc) { if (!cc || cc === "XX" || !/^[A-Z]{2}$/.test(cc)) return "🌐"; return String.fromCodePoint(127397 + cc.charCodeAt(0), 127397 + cc.charCodeAt(1)); }
  var ICON = {
    link: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/></svg>',
    bell: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>',
    ext: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M14 4h6v6M20 4l-9 9M19 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h5"/></svg>',
    expand: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/></svg>',
    mobile: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="7" y="2" width="10" height="20" rx="2"/></svg>',
    tablet: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="2" width="16" height="20" rx="2"/></svg>',
    laptop: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="4" width="16" height="11" rx="1.5"/><path d="M2 19h20"/></svg>',
    desktop: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="13" rx="2"/><path d="M8 21h8M12 16v5"/></svg>'
  };
  var SRC_DOMAIN = { "Google": "google.com", "Google Discover": "google.com", "Google News": "news.google.com", "Bing": "bing.com", "DuckDuckGo": "duckduckgo.com", "Yahoo!": "yahoo.com", "Yandex": "yandex.com", "Baidu": "baidu.com", "Ecosia": "ecosia.org", "Brave": "brave.com", "Facebook": "facebook.com", "Instagram": "instagram.com", "WhatsApp": "whatsapp.com", "Telegram": "telegram.org", "X (Twitter)": "x.com", "YouTube": "youtube.com", "LinkedIn": "linkedin.com", "Pinterest": "pinterest.com", "Reddit": "reddit.com", "Quora": "quora.com", "Threads": "threads.net" };
  function srcIcon(name) {
    if (name === "Direct / None") return '<span class="ic">' + ICON.link + "</span>";
    if (name === "Push notification") return '<span class="ic">' + ICON.bell + "</span>";
    var d = SRC_DOMAIN[name] || (/^[a-z0-9.-]+\.[a-z]{2,}$/i.test(name) ? name : "");
    return d ? '<img src="https://icons.duckduckgo.com/ip3/' + esc(d) + '.ico" alt="" loading="lazy" onerror="this.style.visibility=\'hidden\'">' : '<span class="ic">' + ICON.link + "</span>";
  }
  function label(dim, r) {
    var n = r.name;
    if (dim === "source") return srcIcon(n) + "<span>" + esc(n) + "</span>";
    if (dim === "country") return '<span class="fl">' + flag(n) + "</span><span>" + esc(country(n)) + "</span>";
    if (dim === "device") return '<span class="ic">' + (ICON[(n || "").toLowerCase()] || ICON.desktop) + "</span><span>" + esc(n) + "</span>";
    return "<span>" + esc(n === "" ? "(none)" : n) + "</span>";
  }
  function get(kind, extra) {
    return fetch(api + kind + "&" + qs(extra), { credentials: "same-origin" }).then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); });
  }

  // ── top stats + graph ────────────────────────────────────────────────────
  var chart = null, last = null;
  function change(m, cur, prev) {
    if (!last || !last.prev || !last.prev.visits) return "";
    var p = +prev, c = +cur;
    if (!p && !c) return '<span class="ea-ch flat">0%</span>';
    if (!p) return '<span class="ea-ch ' + (m === "bounce_rate" ? "down" : "up") + '">↑ 100%</span>';
    var pct = Math.round((c - p) / p * 100), good = m === "bounce_rate" ? pct < 0 : pct > 0;
    return '<span class="ea-ch ' + (pct === 0 ? "flat" : good ? "up" : "down") + '">' + (pct > 0 ? "↑ " : pct < 0 ? "↓ " : "") + Math.abs(pct) + "%</span>";
  }
  function drawStats() {
    var t = last.top, box = document.getElementById("eaStats"), list = METRICS, rt = S.period === "realtime";
    if (rt) { list = [["current", "Current visitors"], ["visitors", "Unique visitors (last 30 min)"], ["pageviews", "Pageviews (last 30 min)"]]; if (["visitors", "pageviews"].indexOf(S.metric) < 0) S.metric = "visitors"; }
    box.style.gridTemplateColumns = rt ? "repeat(3,minmax(0,1fr))" : "";
    box.innerHTML = list.map(function (m) {
      var v = m[0] === "current" ? last.current : t[m[0]];
      return '<button type="button" class="ea-stat' + (S.metric === m[0] ? " on" : "") + '" data-m="' + m[0] + '"' + (m[0] === "current" ? " disabled" : "") + '><span class="ea-sl">' + m[1] + '</span><span class="ea-sv"><b>' + fmt(m[0], v) + "</b>" + (m[0] === "current" ? "" : change(m[0], v, last.prev ? last.prev[m[0]] : 0)) + "</span></button>";
    }).join("");
  }
  function drawChart() {
    if (!window.Chart) return setTimeout(drawChart, 80);
    var css = getComputedStyle(root), acc = css.getPropertyValue("--acc").trim(), mut = css.getPropertyValue("--mut").trim(), line = css.getPropertyValue("--line").trim();
    var s = last.series, m = S.metric, nowIdx = -1;
    s.forEach(function (p, i) { if (p.now) nowIdx = i; });
    var ctx = document.getElementById("eaChart").getContext("2d");
    var g = ctx.createLinearGradient(0, 0, 0, 300); g.addColorStop(0, acc + "55"); g.addColorStop(1, acc + "00");
    var name = (METRICS.filter(function (x) { return x[0] === m; })[0] || ["", "Visitors"])[1];
    var data = { labels: s.map(function (p) { return p.label; }), datasets: [{ data: s.map(function (p) { return p[m]; }), borderColor: acc, backgroundColor: g, fill: true, tension: .3, borderWidth: 2.5, pointRadius: s.length > 45 ? 0 : 2.5, pointHoverRadius: 5, pointBackgroundColor: acc,
      segment: { borderDash: function (c) { return nowIdx > 0 && c.p1DataIndex === nowIdx ? [5, 5] : undefined; } } }] };
    var opts = { responsive: true, maintainAspectRatio: false, animation: { duration: 300 }, interaction: { mode: "index", intersect: false },
      plugins: { legend: { display: false }, tooltip: { backgroundColor: "#111827", padding: 10, displayColors: false, titleFont: { weight: "600" },
        callbacks: { title: function (it) { return s[it[0].dataIndex].full; }, label: function (it) { return name + ": " + fmt(m, it.raw); } } } },
      scales: { x: { grid: { display: false }, border: { display: false }, ticks: { color: mut, maxTicksLimit: 8, maxRotation: 0, autoSkipPadding: 18 } },
        y: { beginAtZero: true, border: { display: false }, grid: { color: line }, ticks: { color: mut, maxTicksLimit: 5, precision: m === "views_per_visit" ? undefined : 0, callback: function (v) { return fmt(m, v); } } } } };
    if (chart) { chart.data = data; chart.options = opts; chart.update(); } else chart = new Chart(ctx, { type: "line", data: data, options: opts });
  }
  function drawInterval() {
    var sel = document.getElementById("eaInterval"), names = { minute: "Minutes", hour: "Hours", day: "Days", week: "Weeks", month: "Months" };
    sel.hidden = last.intervals.length < 2;
    sel.innerHTML = last.intervals.map(function (i) { return '<option value="' + i + '"' + (i === last.interval ? " selected" : "") + ">" + names[i] + "</option>"; }).join("");
  }
  function drawNote() {
    var n = document.getElementById("eaNote");
    if (!last.since) n.innerHTML = '<p class="ea-note">No visits recorded yet — numbers appear here as soon as readers open the site.</p>';
    else if (last.sinceTs >= last.startTs + 86400 && S.period !== "realtime" && S.period !== "today")
      n.innerHTML = '<p class="ea-note">Visits are counted from <b>' + esc(last.since) + "</b>, when this analytics was switched on.</p>";
    else n.innerHTML = "";
  }
  function setCurrent(c) { document.getElementById("eaCur").textContent = c + " current visitor" + (c === 1 ? "" : "s"); }
  function loadMain() {
    return get("main").then(function (d) {
      last = d; setCurrent(d.current);
      document.getElementById("eaPeriodLbl").textContent = S.period === "custom" ? d.label : PERIODS[S.period];
      drawStats(); drawChart(); drawInterval(); drawNote();
    }).catch(function () { document.getElementById("eaStats").innerHTML = '<div class="ea-empty" style="grid-column:1/-1">Could not load the numbers. Reload the page.</div>'; });
  }

  // ── breakdown panels ─────────────────────────────────────────────────────
  var panels = [].slice.call(document.querySelectorAll(".ea-panel"));
  var COLS = { page: [["visitors", "Visitors"], ["pageviews", "Pageviews"], ["time_on_page", "Time on page"]], entry: [["visitors", "Visitors"], ["visits", "Entrances"], ["bounce_rate", "Bounce rate"], ["visit_duration", "Visit duration"]], exit: [["visitors", "Visitors"], ["visits", "Exits"]] };
  var HEAD = { channel: "Channel", source: "Source", utm_medium: "UTM Medium", utm_source: "UTM Source", utm_campaign: "UTM Campaign", page: "Page", entry: "Entry page", exit: "Exit page", country: "Country", browser: "Browser", os: "Operating system", device: "Device" };
  var TITLE = { channel: "Top Channels", source: "Top Sources", utm_medium: "UTM Mediums", utm_source: "UTM Sources", utm_campaign: "UTM Campaigns", page: "Top Pages", entry: "Entry Pages", exit: "Exit Pages", country: "Top Countries", browser: "Browsers", os: "Operating Systems", device: "Devices" };
  var UTM = [["utm_medium", "UTM Mediums"], ["utm_source", "UTM Sources"], ["utm_campaign", "UTM Campaigns"]];
  function dimOf(p) { return p._tab === "utm" ? p._utm : p._tab; }
  function cols(dim) { return COLS[dim] || [["visitors", "Visitors"], ["bounce_rate", "Bounce rate"], ["visit_duration", "Visit duration"]]; }
  function fmtCol(k, v) { return k === "bounce_rate" ? (v == null ? "—" : v + "%") : (k === "visit_duration" || k === "time_on_page") ? (v == null ? "—" : dur(v)) : num(v); }
  panels.forEach(function (p) {
    var tabs = JSON.parse(p.getAttribute("data-tabs")), saved = null;
    try { saved = localStorage.getItem("ea_tab_" + p.dataset.panel); } catch (e) {}
    p._utm = "utm_campaign";
    if (saved && saved.indexOf("utm_") === 0 && tabs.some(function (t) { return t[0] === "utm"; })) { p._utm = saved; saved = "utm"; }
    p._tab = tabs.some(function (t) { return t[0] === saved; }) ? saved : tabs[0][0];
    var cv = '<svg class="cv" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="m6 9 6 6 6-6"/></svg>';
    p.innerHTML = '<div class="ea-ph"><div class="ea-tabs">' + tabs.map(function (t) { return '<button type="button" class="ea-tab" data-t="' + t[0] + '">' + t[1] + (t[0] === "utm" ? cv : "") + "</button>"; }).join("") + '</div><button type="button" class="ea-x" data-expand title="Details">' + ICON.expand + '</button>' +
      (tabs.some(function (t) { return t[0] === "utm"; }) ? '<div class="ea-menu ea-utm" hidden>' + UTM.map(function (u) { return '<button type="button" data-u="' + u[0] + '">' + u[1] + "</button>"; }).join("") + "</div>" : "") + '</div><div class="ea-body"></div>';
    var um = p.querySelector(".ea-utm");
    p.querySelector(".ea-tabs").addEventListener("click", function (e) {
      var b = e.target.closest(".ea-tab"); if (!b) return;
      if (b.dataset.t === "utm" && um) { e.stopPropagation(); um.style.left = b.offsetLeft - p.querySelector(".ea-tabs").scrollLeft + "px"; um.hidden = !um.hidden; um.querySelectorAll("[data-u]").forEach(function (x) { x.classList.toggle("on", p._tab === "utm" && x.dataset.u === p._utm); }); return; }
      p._tab = b.dataset.t; try { localStorage.setItem("ea_tab_" + p.dataset.panel, p._tab); } catch (x) {} loadPanel(p);
    });
    if (um) {
      um.addEventListener("click", function (e) { var b = e.target.closest("[data-u]"); if (!b) return; p._tab = "utm"; p._utm = b.dataset.u; um.hidden = true; try { localStorage.setItem("ea_tab_" + p.dataset.panel, p._utm); } catch (x) {} loadPanel(p); });
      document.addEventListener("click", function (e) { if (!um.contains(e.target)) um.hidden = true; });
    }
    p.querySelector("[data-expand]").addEventListener("click", function () { openDetails(dimOf(p)); });
  });
  function loadPanel(p) {
    var dim = dimOf(p), body = p.querySelector(".ea-body");
    p.querySelectorAll(".ea-tab").forEach(function (b) { b.classList.toggle("on", b.dataset.t === p._tab); });
    var ut = p.querySelector('.ea-tab[data-t="utm"]');
    if (ut) ut.firstChild.textContent = p._tab === "utm" ? (UTM.filter(function (u) { return u[0] === p._utm; })[0] || ["", "Campaigns"])[1] : "Campaigns";
    if (!body.querySelector(".ea-list")) body.innerHTML = '<div class="ea-loading"><div class="ea-spin"></div></div>';
    get("list", { dim: dim, limit: 9 }).then(function (rows) {
      if (dimOf(p) !== dim) return;
      if (!rows.length) { body.innerHTML = '<div class="ea-empty">No data yet</div>'; return; }
      var max = Math.max.apply(null, rows.map(function (r) { return +r.visitors || 0; })) || 1, tot = last && last.top ? (last.top.visitors || 1) : 1;
      var valKey = dim === "entry" || dim === "exit" ? "visits" : "visitors";
      body.innerHTML = '<div class="ea-lh"><span>' + HEAD[dim] + "</span><span>" + (dim === "entry" ? "Entrances" : dim === "exit" ? "Exits" : "Visitors") + '</span></div><div class="ea-list">' + rows.map(function (r) {
        var v = +r[valKey] || 0, w = Math.max(2, Math.round((+r.visitors || 0) / max * 100));
        var go = (dim === "page" || dim === "entry" || dim === "exit") && String(r.name).charAt(0) === "/" ? '<a class="go" href="' + esc(r.name) + '" target="_blank" rel="noopener" title="Open page">' + ICON.ext + "</a>" : "";
        return '<div class="ea-row" data-dim="' + dim + '" data-v="' + esc(r.name) + '" title="' + esc(dim === "country" ? country(r.name) : r.name) + '"><span class="bar" style="width:' + w + '%"></span><span class="nm">' + label(dim, r) + go + '</span><span class="pc">' + Math.min(100, Math.round((+r.visitors || 0) / tot * 100)) + '%</span><span class="vl">' + num(v) + "</span></div>";
      }).join("") + "</div>" + (rows.length >= 9 ? '<div class="ea-more"><button type="button" data-more>' + ICON.expand + " Details</button></div>" : "");
      var more = body.querySelector("[data-more]"); if (more) more.onclick = function () { openDetails(dim); };
    }).catch(function () { body.innerHTML = '<div class="ea-empty">Could not load</div>'; });
  }
  function loadPanels() { panels.forEach(loadPanel); }

  // ── details modal ────────────────────────────────────────────────────────
  var modal = document.getElementById("eaModal"), mDim = "", mTimer = null;
  function openDetails(dim) {
    mDim = dim; document.getElementById("eaMTitle").textContent = TITLE[dim];
    document.getElementById("eaMSearch").value = ""; modal.hidden = false; loadDetails();
    setTimeout(function () { document.getElementById("eaMSearch").focus(); }, 50);
  }
  function loadDetails() {
    var body = document.getElementById("eaMBody"), dim = mDim, c = cols(dim), q = document.getElementById("eaMSearch").value.trim();
    body.innerHTML = '<div class="ea-loading"><div class="ea-spin"></div></div>';
    get("list", { dim: dim, limit: dim === "country" && q ? 300 : 200, q: dim === "country" ? "" : q }).then(function (rows) {
      if (dim === "country" && q) rows = rows.filter(function (r) { return country(r.name).toLowerCase().indexOf(q.toLowerCase()) > -1; });
      if (!rows.length) { body.innerHTML = '<div class="ea-empty">Nothing found</div>'; return; }
      body.innerHTML = '<table class="ea-tbl"><thead><tr><th>' + HEAD[dim] + "</th>" + c.map(function (x) { return "<th>" + x[1] + "</th>"; }).join("") + "</tr></thead><tbody>" + rows.map(function (r) {
        return '<tr><td data-dim="' + dim + '" data-v="' + esc(r.name) + '">' + (dim === "country" ? flag(r.name) + " " + esc(country(r.name)) : esc(r.name === "" ? "(none)" : r.name)) + "</td>" + c.map(function (x) { return "<td>" + fmtCol(x[0], r[x[0]]) + "</td>"; }).join("") + "</tr>";
      }).join("") + "</tbody></table>";
    }).catch(function () { body.innerHTML = '<div class="ea-empty">Could not load</div>'; });
  }
  document.getElementById("eaMSearch").addEventListener("input", function () { clearTimeout(mTimer); mTimer = setTimeout(loadDetails, 250); });
  document.getElementById("eaMBody").addEventListener("click", function (e) { var td = e.target.closest("td[data-dim]"); if (td) { modal.hidden = true; addFilter(td.dataset.dim, td.dataset.v); } });

  // ── filters ──────────────────────────────────────────────────────────────
  function addFilter(dim, v) { S.f[dim] = v; refresh(); }
  root.addEventListener("click", function (e) {
    if (e.target.closest(".go")) return;
    var r = e.target.closest(".ea-row"); if (r) addFilter(r.dataset.dim, r.dataset.v);
  });
  function drawChips() {
    var keys = Object.keys(S.f), box = document.getElementById("eaChips");
    box.hidden = !keys.length;
    box.innerHTML = keys.map(function (k) { return '<span class="ea-chip">' + esc(DIMS[k]) + " is <b>" + esc(k === "country" ? country(S.f[k]) : S.f[k]) + '</b><button type="button" data-rm="' + k + '" aria-label="Remove filter">✕</button></span>'; }).join("") + (keys.length > 1 ? '<button type="button" class="ea-chip clear" data-rm="*">Clear all</button>' : "");
  }
  document.getElementById("eaChips").addEventListener("click", function (e) { var b = e.target.closest("[data-rm]"); if (!b) return; if (b.dataset.rm === "*") S.f = {}; else delete S.f[b.dataset.rm]; refresh(); });
  var fModal = document.getElementById("eaFModal"), fDim = document.getElementById("eaFDim"), fQ = document.getElementById("eaFQ"), fSug = document.getElementById("eaFSug"), fT = null;
  fDim.innerHTML = Object.keys(DIMS).map(function (k) { return '<option value="' + k + '">' + DIMS[k] + "</option>"; }).join("");
  function suggest() {
    fSug.innerHTML = '<div class="ea-loading" style="min-height:60px"><div class="ea-spin"></div></div>';
    var q = fQ.value.trim(), dim = fDim.value;
    get("list", { dim: dim, limit: dim === "country" ? 300 : 12, q: dim === "country" ? "" : q }).then(function (rows) {
      if (dim === "country") rows = rows.filter(function (r) { return !q || country(r.name).toLowerCase().indexOf(q.toLowerCase()) > -1; }).slice(0, 12);
      fSug.innerHTML = rows.length ? rows.map(function (r) { return '<button type="button" data-v="' + esc(r.name) + '"><span>' + (dim === "country" ? flag(r.name) + " " + esc(country(r.name)) : esc(r.name || "(none)")) + "</span><small>" + num(r.visitors) + " visitors</small></button>"; }).join("") : '<div class="ea-empty" style="padding:14px">Nothing in this period</div>';
    });
  }
  document.getElementById("eaFilterBtn").onclick = function () { fModal.hidden = false; fQ.value = ""; suggest(); setTimeout(function () { fQ.focus(); }, 50); };
  fDim.onchange = suggest;
  fQ.oninput = function () { clearTimeout(fT); fT = setTimeout(suggest, 220); };
  fSug.onclick = function (e) { var b = e.target.closest("button[data-v]"); if (!b) return; fModal.hidden = true; addFilter(fDim.value, b.dataset.v); };
  [modal, fModal].forEach(function (m) { m.addEventListener("click", function (e) { if (e.target === m || e.target.closest("[data-close]")) m.hidden = true; }); });

  // ── period, metric, interval, theme ──────────────────────────────────────
  var pBtn = document.getElementById("eaPeriodBtn"), pMenu = document.getElementById("eaPeriodMenu");
  pBtn.onclick = function (e) { e.stopPropagation(); pMenu.hidden = !pMenu.hidden; pMenu.querySelectorAll("[data-p]").forEach(function (b) { b.classList.toggle("on", b.dataset.p === S.period); }); };
  document.addEventListener("click", function (e) { if (!pMenu.contains(e.target)) pMenu.hidden = true; });
  pMenu.addEventListener("click", function (e) { var b = e.target.closest("[data-p]"); if (!b) return; setPeriod(b.dataset.p); pMenu.hidden = true; });
  document.getElementById("eaCustomGo").onclick = function () {
    var f = document.getElementById("eaFrom").value, t = document.getElementById("eaTo").value || f;
    if (!f) return document.getElementById("eaFrom").focus();
    S.period = "custom"; S.from = f; S.to = t; S.interval = ""; pMenu.hidden = true; refresh();
  };
  function setPeriod(p) { S.period = p; S.interval = ""; refresh(); }
  document.getElementById("eaLive").onclick = function () { setPeriod("realtime"); };
  document.getElementById("eaStats").addEventListener("click", function (e) { var b = e.target.closest(".ea-stat[data-m]"); if (!b || b.disabled) return; S.metric = b.dataset.m; pushUrl(); drawStats(); drawChart(); });
  document.getElementById("eaInterval").onchange = function () { S.interval = this.value; pushUrl(); loadMain(); };
  document.addEventListener("keydown", function (e) {
    if (e.metaKey || e.ctrlKey || e.altKey || /input|select|textarea/i.test(e.target.tagName || "")) return;
    if (e.key === "Escape") { modal.hidden = true; fModal.hidden = true; pMenu.hidden = true; return; }
    var p = KEYS[(e.key || "").toLowerCase()]; if (p && modal.hidden && fModal.hidden) setPeriod(p);
  });
  var th = "light"; try { th = localStorage.getItem("ea_theme") || "light"; } catch (e) {}
  root.dataset.theme = th;
  document.getElementById("eaTheme").onclick = function () { root.dataset.theme = root.dataset.theme === "dark" ? "light" : "dark"; try { localStorage.setItem("ea_theme", root.dataset.theme); } catch (e) {} if (chart) { chart.destroy(); chart = null; } if (last) drawChart(); };

  function refresh() { pushUrl(); drawChips(); loadMain().then(loadPanels); }
  refresh();
  // keep "current visitors" (and today / realtime) fresh
  setInterval(function () {
    if (document.hidden) return;
    if (S.period === "realtime" || S.period === "today") { loadMain().then(function () { if (S.period === "realtime") loadPanels(); }); }
    else get("main").then(function (d) { setCurrent(d.current); }).catch(function () {});
  }, 30000);
})();
</script>
</body>
</html>
