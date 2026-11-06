<?php
// Push Notifications (LaraPush-style): send to everyone or a segment, subscriber analytics (growth, device, OS,
// browser, country, state), segments with live estimate, history with clicks, and settings
// (collecting on/off, icon, key reset, export / import).
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/components/guard.php';
require_once INCLUDES_PATH . '/push.php';
require_once INCLUDES_PATH . '/theme.php';
admin_guard(['admin', 'editor', 'author']);
if (!admin_can('push_notifications.send')) { http_response_code(403); exit('Access Denied'); }

$pdo = edu_push_db();
$isAdmin = admin_shell_user()['role'] === 'admin';
$tabs = ['send' => ['fa-paper-plane', 'Send'], 'reports' => ['fa-chart-simple', 'Reports'], 'history' => ['fa-clock-rotate-left', 'History']];
if ($isAdmin) $tabs['settings'] = ['fa-gear', 'Settings'];
$tab = ['analytics' => 'reports', 'segments' => 'history'][$_GET['tab'] ?? ''] ?? ($_GET['tab'] ?? '');
if (!isset($tabs[$tab])) $tab = 'send';

$total = (int)$pdo->query("SELECT COUNT(*) FROM push_subscriptions s JOIN push_keys k ON k.id = s.key_id")->fetchColumn();
$newToday = (int)$pdo->query("SELECT COUNT(*) FROM push_subscriptions WHERE created_at >= CURDATE()")->fetchColumn();
$week = (int)$pdo->query("SELECT COUNT(*) FROM push_subscriptions WHERE created_at >= NOW() - INTERVAL 7 DAY")->fetchColumn();
$delivered = (int)$pdo->query("SELECT COALESCE(SUM(sent),0) FROM push_campaigns")->fetchColumn();
$clicks = (int)$pdo->query("SELECT COALESCE(SUM(clicks),0) FROM push_campaigns")->fetchColumn();
$campCount = (int)$pdo->query("SELECT COUNT(*) FROM push_campaigns")->fetchColumn();
$segments = $pdo->query("SELECT * FROM push_segments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
foreach ($segments as &$sg) { $sg['rules'] = edu_push_clean_rules(json_decode((string)$sg['rules'], true)); $sg['count'] = edu_push_estimate($sg['rules']); }
unset($sg);
$days = in_array((int)($_GET['days'] ?? 30), [7, 30, 90, 365], true) ? (int)($_GET['days'] ?? 30) : 30;
$stats = edu_push_stats($days);
$activeKey = edu_push_active_key($pdo);
$siteHost = (string)parse_url(SITE_URL, PHP_URL_HOST);
$defaultIcon = edu_push_default_icon();
$mask = fn($k) => strlen((string)$k) > 16 ? substr($k, 0, 10) . '…' . substr($k, -6) : '—';
$fmtDate = fn($d) => $d ? date('j M Y, g:i a', strtotime($d)) : '—';

// History (25 per page)
$hp = max(1, (int)($_GET['hp'] ?? 1));
$hPages = max(1, (int)ceil($campCount / 25));
$campaigns = $pdo->query("SELECT * FROM push_campaigns ORDER BY id DESC LIMIT 25 OFFSET " . (($hp - 1) * 25))->fetchAll(PDO::FETCH_ASSOC);
$allSubs = (int)$pdo->query("SELECT COUNT(*) FROM push_subscriptions")->fetchColumn();
$keySets = $isAdmin ? $pdo->query("SELECT k.id, k.public_key, k.active, k.created_at, COUNT(s.id) n FROM push_keys k LEFT JOIN push_subscriptions s ON s.key_id = k.id GROUP BY k.id ORDER BY k.active DESC, k.id DESC")->fetchAll(PDO::FETCH_ASSOC) : [];

$prefill = null;
if (!empty($_GET['post'])) {
    $st = $pdo->prepare("SELECT p.id, p.title, p.slug, m.file_path FROM posts p LEFT JOIN media m ON m.id = p.featured_image_id WHERE p.id = ?");
    $st->execute([(int)$_GET['post']]);
    $prefill = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
$pct = fn($n, $t) => $t > 0 ? round($n / $t * 100, 1) : 0;
$pageUrl = fn(array $q) => '?' . http_build_query($q);
$clickRate = $delivered > 0 ? round($clicks / $delivered * 100, 1) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Push Notifications - <?= htmlspecialchars(edu_site_name()) ?></title>
<?php admin_shell_head(); ?>
<script src="/assets/js/admin-media.js?v=<?= @filemtime(ROOT_PATH . '/assets/js/admin-media.js') ?>" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js" defer></script>
<style>
/* Push page — one calm layout: numbers strip, four tabs, plain cards */
.px{max-width:1240px}
.px .pn-pane{display:none}.px .pn-pane.active{display:block}
.px-strip{display:flex;align-items:stretch;background:#fff;border:1px solid #e5e7eb;border-radius:14px;margin-bottom:18px;overflow:hidden}
.px-num{flex:1;padding:16px 20px;min-width:0}.px-num+.px-num{border-left:1px solid #f1f5f9}
.px-num span{display:block;font-size:12.5px;color:#64748b;font-weight:500}
.px-num strong{display:block;font-size:24px;line-height:1.2;color:#0f172a;font-weight:700;margin-top:2px}
.px-num small{font-size:12px;color:#94a3b8;font-weight:500}.px-num small.up{color:#16a34a}
.px-state{display:flex;align-items:center;padding:0 20px;border-left:1px solid #f1f5f9}
.px-chip{display:inline-flex;align-items:center;gap:7px;padding:6px 12px;border-radius:99px;font-size:12.5px;font-weight:600;white-space:nowrap;text-decoration:none}
.px-chip::before{content:"";width:8px;height:8px;border-radius:50%;background:currentColor}
.px-chip.on{background:#dcfce7;color:#15803d}.px-chip.off{background:#f1f5f9;color:#64748b}
.px .pn-tabs{display:inline-flex;gap:2px;padding:4px;margin:0 0 18px;background:#f1f5f9;border:0;border-radius:12px;max-width:100%;overflow-x:auto}
.px .pn-tabs button{padding:8px 18px;border:0;border-radius:9px;margin:0;font-size:14px;font-weight:600;color:#64748b;background:none}
.px .pn-tabs button i{font-size:13px}
.px .pn-tabs button.active{background:#fff;color:#0f172a;box-shadow:0 1px 3px rgba(15,23,42,.1)}
.px .pn-card{border-radius:14px;padding:20px}
.px .pn-card h3{font-size:15px;margin:0 0 4px}.px .pn-card h3 i{display:none}
.px-sub{margin:0 0 16px;font-size:13px;color:#94a3b8}
.px-send{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:18px;align-items:start}
.px-step{padding:18px 0;border-top:1px solid #f1f5f9}.px-step:first-of-type{border-top:0;padding-top:4px}
.px-step>b{display:flex;align-items:center;gap:8px;font-size:13px;color:#0f172a;margin-bottom:12px}
.px-step>b em{display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:50%;background:var(--primary-lighter,#f3e8ff);color:var(--primary);font-style:normal;font-size:11.5px}
.px .pn-src{display:inline-flex;margin:0 0 10px;border-radius:9px}.px .pn-src button{flex:none;padding:6px 12px;font-size:12.5px}
.px-search{position:relative}.px-search i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:13px}.px-search .pn-input{padding-left:34px}
.px .pn-posts{max-height:228px;margin-top:8px;gap:4px}
.px .pn-posts button{padding:6px 8px;border-color:transparent}.px .pn-posts img,.px .pn-noimg{width:52px;height:34px}
.px-two{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.px details.px-more{margin-top:2px}.px details.px-more>summary{cursor:pointer;font-size:13px;font-weight:600;color:var(--primary);list-style:none;display:inline-flex;gap:6px;align-items:center}
.px details.px-more>summary::-webkit-details-marker{display:none}.px details.px-more>summary::before{content:"+";font-weight:700}.px details.px-more[open]>summary::before{content:"−"}
.px details.px-more>div{margin-top:12px}
.px .pn-img-prev{max-height:90px}.px .pn-icon-prev{width:36px;height:36px}
.px-go{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.px-go .pn-btn{padding:11px 22px}
.px-preview{position:sticky;top:76px}
.px-preview .pn-phone{width:236px;height:470px;border-radius:38px}.px-preview .pn-phone-screen{border-radius:29px;padding-top:56px}
.px-preview .pn-phone-time{font-size:42px}.px-preview .pn-phone-date{margin-bottom:20px}
.px-grid3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-top:14px}
.px-grid2{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin-top:14px}
.px-bars{list-style:none;margin:0;padding:0}.px-bars li{display:grid;grid-template-columns:1fr auto;gap:2px 10px;padding:7px 0;font-size:13.5px;color:#334155}
.px-bars .px-bn{font-weight:700;color:#0f172a}.px-bars .px-bn small{font-weight:500;color:#94a3b8;margin-left:4px}
.px-bb{display:block;grid-column:1/-1;height:6px;border-radius:9px;background:#f1f5f9;overflow:hidden}.px-bb i{display:block;height:100%;background:var(--primary);border-radius:9px}
.px-ranges{display:inline-flex;gap:2px;padding:3px;background:#f1f5f9;border-radius:9px}
.px-ranges a{padding:5px 11px;border-radius:7px;font-size:12.5px;font-weight:600;color:#64748b;text-decoration:none}.px-ranges a.on{background:#fff;color:#0f172a;box-shadow:0 1px 2px rgba(15,23,42,.1)}
.px .pn-head{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px}.px .pn-head h3{margin:0}
.px .pn-growth{position:relative;height:230px}
.px .pn-table th{font-size:11.5px;letter-spacing:.02em;background:#f8fafc}.px .pn-table td{vertical-align:middle}
.px .pn-table td.num,.px .pn-table th.num{text-align:right;white-space:nowrap}
.px-t strong{display:block;font-size:13.5px;color:#0f172a;line-height:1.4}.px-t small{display:block;color:#94a3b8;font-size:12px;margin-top:2px}
.px-t .pn-id{color:#94a3b8;font-weight:600;margin-right:4px}
.px-del{font-size:12px;color:#94a3b8}.px-del b{color:#0f172a;font-size:13.5px}
.px .pn-badge{display:inline-block;padding:3px 10px;border-radius:99px;font-size:12px;font-weight:600;white-space:nowrap}.px .pn-badge.completed{background:#dcfce7;color:#166534}.px .pn-badge.processing,.px .pn-badge.pending{background:#fef3c7;color:#92400e}.px .pn-badge.failed{background:#fee2e2;color:#991b1b}
.px .pn-progress{height:8px;border-radius:99px;background:#e5e7eb;overflow:hidden;margin:12px 0 4px}.px .pn-progress span{display:block;height:100%;width:0;background:var(--primary);transition:width .3s}
.px .pn-msg{margin-top:10px;font-size:.85rem}.px .pn-msg.ok{color:#16a34a}.px .pn-msg.err{color:#dc2626}
.px .pn-btn-light{display:inline-flex;align-items:center;gap:7px;color:#1e293b;font-size:13px}.px .pn-btn-light:hover{border-color:var(--primary);color:var(--primary)}
.px .pn-btn-danger{background:#dc2626}.px .pn-btn-danger:hover{background:#b91c1c}
.px .pn-note{padding:12px 14px;border-radius:10px;background:#f8fafc;border:1px solid #e2e8f0;color:#475569;font-size:13px;line-height:1.55}
.px .pn-rule{display:grid;grid-template-columns:160px 110px 1fr 34px;gap:8px;align-items:center;margin-bottom:8px}.px .pn-rule select,.px .pn-rule input{width:100%}
.px .pn-chips{display:flex;flex-wrap:wrap;gap:6px;margin:2px 0 6px}.px .pn-chips button{padding:3px 9px;border:1px solid #e2e8f0;border-radius:99px;background:#f8fafc;font-size:12px;cursor:pointer;color:#334155}.px .pn-chips button:hover{border-color:var(--primary);color:var(--primary)}
.px .pn-est{display:inline-flex;align-items:center;gap:8px;padding:7px 12px;border-radius:9px;background:#f0fdf4;color:#166534;font-weight:700;font-size:13.5px}
.px .pn-keyline{font-family:ui-monospace,Menlo,monospace;font-size:12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:8px 10px;word-break:break-all;color:#475569}
.px .pn-pager{display:flex;gap:6px;justify-content:flex-end;margin-top:12px;flex-wrap:wrap}.px .pn-pager a,.px .pn-pager span{padding:5px 11px;border:1px solid #e5e7eb;border-radius:8px;font-size:13px;color:#334155;text-decoration:none}.px .pn-pager span{background:var(--primary);border-color:var(--primary);color:#fff}.px .pn-pager em{font-style:normal;color:#94a3b8;padding:5px 2px}
.px .pn-switch{display:flex;gap:12px;align-items:flex-start;padding:12px 0;margin:0;border-bottom:1px solid #f1f5f9;font-size:14px;color:#334155;cursor:pointer}
.px .pn-switch input{width:38px;height:22px;appearance:none;-webkit-appearance:none;border-radius:22px;background:#cbd5e1;position:relative;cursor:pointer;flex-shrink:0;transition:background .2s;margin:0}
.px .pn-switch input::after{content:"";position:absolute;top:3px;left:3px;width:16px;height:16px;border-radius:50%;background:#fff;transition:transform .2s}
.px .pn-switch input:checked{background:var(--primary)}.px .pn-switch input:checked::after{transform:translateX(16px)}
.px .pn-switch small{display:block;color:#94a3b8;font-size:12.5px;margin-top:2px}
.px-set{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:14px;align-items:start}.px-col{display:flex;flex-direction:column;gap:14px}
.px-danger{border-color:#fecaca!important}.px-danger h3{color:#b91c1c!important}
.px-ok{display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:600;color:#15803d;margin:4px 0 10px}
.px-seg-row{display:flex;align-items:center;gap:12px;padding:12px 0;border-bottom:1px solid #f1f5f9}.px-seg-row:last-child{border:0}
.px-seg-row div{flex:1;min-width:0}.px-seg-row strong{display:block;font-size:14px;color:#0f172a}.px-seg-row small{display:block;color:#94a3b8;font-size:12px;margin-top:2px}
.px-seg-row .px-n{font-weight:700;color:#0f172a;font-size:14px;white-space:nowrap}
.px-fetch{display:flex;gap:8px}.px-fetch .pn-input{flex:1;min-width:0}.px-fetch .pn-btn{white-space:nowrap}
.px-hint{margin:8px 0 10px}
.px-rep{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:14px;align-items:stretch}
.px-big{font-size:30px;font-weight:700;color:#0f172a;line-height:1.1;margin-top:4px}
.px-donut{position:relative;height:150px;margin:6px 0 10px}
.px-legend{list-style:none;margin:0;padding:0;font-size:13px}.px-legend li{display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid #f1f5f9}.px-legend li:last-child{border:0}
.px-legend i{width:10px;height:10px;border-radius:3px;flex-shrink:0}.px-legend b{margin-left:auto;color:#0f172a}.px-legend small{width:44px;text-align:right;color:#94a3b8}
.px-ctry td{padding:9px 10px}
.px-gap{margin-top:14px}
.px-fail{color:#dc2626}.px-pc{color:#94a3b8}
tr.px-camp{cursor:pointer}tr.px-camp:hover td{background:#fafafa}tr.px-camp.open td{background:#f8fafc;border-bottom-color:transparent}
.px-acts{white-space:nowrap;text-align:right}.px-chev{display:inline-flex;width:28px;justify-content:center;color:#94a3b8;transition:transform .2s;vertical-align:middle}tr.px-camp.open .px-chev{transform:rotate(180deg)}
.px-logrow td{background:#f8fafc;padding:0 12px 14px!important}
.px-log{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:14px 16px;font-size:13px;color:#475569}
.px-log-nums{display:flex;flex-wrap:wrap;gap:6px 18px}.px-log-nums b{color:#0f172a;font-size:15px;margin-right:3px}.px-log-nums .ok b{color:#16a34a}.px-log-nums .bad b{color:#dc2626}.px-log-nums .wait b{color:#d97706}
.px-log-bar{display:flex;height:8px;border-radius:99px;overflow:hidden;background:#e5e7eb;margin:10px 0}.px-log-bar i{display:block;height:100%}.px-log-bar .ok{background:#16a34a}.px-log-bar .bad{background:#dc2626}.px-log-bar .wait{background:#f59e0b}
.px-log-meta{margin:0;color:#94a3b8;font-size:12.5px;word-break:break-all}.px-log-meta a{color:var(--primary)}
.px-log-h{margin:14px 0 6px;font-weight:600;color:#0f172a;font-size:12.5px}
.px-log-err{margin:0;padding-left:18px}.px-log-err li{margin:3px 0}
.px-log-list{display:flex;flex-direction:column;max-height:260px;overflow:auto}.px-log-list div{display:flex;align-items:center;gap:10px;padding:6px 0;border-bottom:1px solid #f1f5f9}.px-log-list span:nth-child(2){flex:1;min-width:0}.px-log-list small{color:#94a3b8;white-space:nowrap}
.px .px-rule{grid-template-columns:minmax(0,1fr) 110px 34px;padding:12px;border:1px solid #e5e7eb;border-radius:12px;background:#fcfcfd;margin-bottom:10px}
.px-pick{grid-column:1/-1}.px-pick-q{margin-bottom:6px}
.px-pick-list{display:flex;flex-wrap:wrap;gap:6px;max-height:190px;overflow:auto}
.px-opt{display:inline-flex;align-items:center;gap:6px;padding:5px 10px;border:1px solid #e2e8f0;border-radius:99px;background:#fff;font-size:13px;color:#334155;cursor:pointer;user-select:none}
.px-opt input{margin:0;accent-color:var(--primary)}.px-opt small{color:#94a3b8;font-size:11.5px}.px-opt:has(input:checked){border-color:var(--primary);background:var(--primary-lighter,#f5f3ff);color:#0f172a}
.npv{background:#f8fafc;border:1px solid #e5e7eb;border-radius:14px;padding:14px}
.npv-label{margin:0 0 6px;font-size:11.5px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.04em}
.npv-label+div{margin-bottom:14px}
.npv-android,.npv-desk{background:#fff;border-radius:14px;box-shadow:0 1px 3px rgba(15,23,42,.08),0 4px 14px rgba(15,23,42,.06);overflow:hidden;font-family:Roboto,system-ui,-apple-system,"Segoe UI",sans-serif}
.npv-android{padding:11px 13px}.npv-desk{border-radius:8px;margin-bottom:0!important}
.npv-head{display:flex;align-items:center;gap:6px;font-size:11.5px;color:#64748b;margin-bottom:6px;min-width:0}.npv-head>span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.npv-sico{width:16px;height:16px;border-radius:4px;object-fit:cover;flex-shrink:0}
.npv-row{display:flex;gap:10px;align-items:flex-start}.npv-row>div{flex:1;min-width:0}
.npv-row strong{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;font-size:13.5px;line-height:1.35;color:#111827}
.npv-row p{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;margin:2px 0 0;font-size:12.5px;line-height:1.4;color:#4b5563}
.npv-lico{width:40px;height:40px;border-radius:8px;object-fit:cover;flex-shrink:0}
.npv-android .npv-img{display:block;width:100%;aspect-ratio:2/1;object-fit:cover;border-radius:8px;margin-top:9px;background:#f1f5f9}
.npv-desk .npv-img{display:block;width:100%;aspect-ratio:2/1;object-fit:cover;background:#f1f5f9}
.npv-img[hidden]{display:none!important}
.npv-desk .npv-row{padding:12px}
.npv-dico{width:44px;height:44px;border-radius:6px;object-fit:cover;flex-shrink:0}
.npv-desk small{display:block;margin-top:4px;font-size:11.5px;color:#94a3b8}
@media(max-width:900px){.px-rep{grid-template-columns:1fr}}
@media(max-width:700px){.px-fetch{flex-direction:column}.px-sent tr.px-logrow{padding:0}.px-sent tr.px-logrow td{display:block;position:static;padding:0 0 12px!important}.px-acts .px-chev{display:none}}
@media(max-width:1100px){.px-grid3{grid-template-columns:1fr 1fr}}
@media(max-width:900px){.px-send,.px-set,.px-grid2{grid-template-columns:1fr}.px-preview{position:static}}
@media(max-width:700px){.px-strip{flex-wrap:wrap}.px-num{flex:1 1 45%}.px-num+.px-num{border-left:0}.px-num:nth-child(2n){border-left:1px solid #f1f5f9}.px-num:nth-child(n+3){border-top:1px solid #f1f5f9}.px-state{flex:1 1 100%;border-left:0;border-top:1px solid #f1f5f9;padding:12px 20px}
  .px-grid3,.px-two{grid-template-columns:1fr}.px .pn-rule{grid-template-columns:1fr 1fr}.px .pn-rule input{grid-column:1/-1}.px .pn-tabs{display:flex}.px .pn-tabs button{flex:1;padding:8px 6px;font-size:13.5px}.px .pn-tabs button i{display:none}
  .px-sent thead{display:none}.px-sent,.px-sent tbody,.px-sent tr{display:block}.px-sent tr{position:relative;padding:12px 40px 12px 0;border-bottom:1px solid #f1f5f9}
  .px-sent td{display:inline-block;padding:2px 12px 2px 0;border:0;max-width:none!important;vertical-align:top}.px-sent td.px-t:first-child{display:block;padding-bottom:6px}
  .px-sent td.num{text-align:left}.px-sent td[data-l]::before{content:attr(data-l) " ";color:#94a3b8;font-size:11.5px}.px-sent td[data-l] br{display:none}.px-sent .px-pc{margin-left:5px;color:#94a3b8}
  .px-sent td:last-child{position:absolute;top:12px;right:0;padding:0}
  .px-preview .pn-phone{transform:scale(.82);transform-origin:top center;margin-bottom:-80px}}
</style>
</head>
<body>
<?php admin_shell_open('Push Notifications', 'Send browser notifications to your readers'); ?>
<div class="pn-wrap px">
  <div class="px-strip">
    <div class="px-num"><span>Subscribers</span><strong><?= number_format($total) ?></strong><small class="<?= $newToday ? 'up' : '' ?>">+<?= number_format($newToday) ?> today</small></div>
    <div class="px-num"><span>New this week</span><strong><?= number_format($week) ?></strong><small>last 7 days</small></div>
    <div class="px-num"><span>Delivered</span><strong><?= number_format($delivered) ?></strong><small><?= number_format($campCount) ?> notification<?= $campCount === 1 ? '' : 's' ?></small></div>
    <div class="px-num"><span>Click rate</span><strong><?= $clickRate ?>%</strong><small><?= number_format($clicks) ?> click<?= $clicks === 1 ? '' : 's' ?></small></div>
    <div class="px-state"><?php if (edu_push_collecting()): ?><span class="px-chip on" title="Visitors are asked to allow notifications">Collecting</span><?php else: ?><a class="px-chip off" <?= $isAdmin ? 'href="?tab=settings"' : '' ?> title="Visitors are not asked; existing subscribers still get notifications">Paused</a><?php endif; ?></div>
  </div>

  <div class="pn-tabs">
    <?php foreach ($tabs as $k => [$ic, $l]): ?>
    <button type="button" data-tab="<?= $k ?>" class="<?= $tab === $k ? 'active' : '' ?>"><i class="fas <?= $ic ?>"></i> <?= $l ?></button>
    <?php endforeach; ?>
  </div>

  <!-- ===== Send ===== -->
  <div class="pn-pane" data-pane="send">
    <div class="px-send">
      <div class="pn-card">
        <div class="px-step"><b><em>1</em> Post URL</b>
          <div class="px-fetch"><input class="pn-input" id="pn-fetch-url" inputmode="url" placeholder="Paste any article link — your site, a WordPress site, any site"><button type="button" class="pn-btn" id="pn-fetch"><i class="fas fa-wand-magic-sparkles"></i> <span>Fetch</span></button></div>
          <div class="pn-msg" id="pn-fetch-msg"></div>
          <p class="px-sub px-hint">Title, short text, featured image and the site icon are filled in for you — edit anything below.</p>
          <details class="px-more" id="pn-list"><summary>Or pick one of your posts</summary>
            <div><div class="px-search"><i class="fas fa-magnifying-glass"></i><input class="pn-input" id="pn-q" placeholder="Search your posts…"></div>
            <div class="pn-posts" id="pn-posts"></div></div>
          </details>
        </div>
        <div class="px-step"><b><em>2</em> Write the notification</b>
          <label class="pn-field">Title <em id="pn-tc">0/80</em><input class="pn-input" id="pn-title" maxlength="150" placeholder="Breaking: …" value="<?= htmlspecialchars($prefill['title'] ?? '') ?>"></label>
          <label class="pn-field">Message <em id="pn-bc">0/180</em><textarea class="pn-input" id="pn-body" rows="2" maxlength="300" placeholder="One or two short lines"></textarea></label>
          <label class="pn-field">Link<input class="pn-input" id="pn-url" placeholder="/post-address or https://…" value="<?= htmlspecialchars($prefill ? postUrl($prefill['slug'], $prefill['id']) : '') ?>"></label>
          <details class="px-more"><summary>Image and icon</summary>
            <div class="px-two">
              <div class="pn-field">Image<div class="pn-img-row"><img class="pn-img-prev" id="pn-img-prev" alt="" <?= !empty($prefill['file_path']) ? 'src="/' . htmlspecialchars($prefill['file_path']) . '"' : 'hidden' ?>><button type="button" class="pn-btn-light" id="pn-img-pick">Choose</button> <button type="button" class="pn-btn-light" id="pn-img-clear" <?= !empty($prefill['file_path']) ? '' : 'hidden' ?>>Remove</button></div></div>
              <div class="pn-field">Icon<div class="pn-img-row"><img class="pn-icon-prev" id="pn-icon-prev" alt="" src="<?= htmlspecialchars($defaultIcon) ?>"><button type="button" class="pn-btn-light" id="pn-icon-pick">Change</button> <button type="button" class="pn-btn-light" id="pn-icon-reset" hidden>Site icon</button></div></div>
            </div>
          </details>
        </div>
        <div class="px-step"><b><em>3</em> Send</b>
          <label class="pn-field"<?= $segments ? '' : ' hidden' ?>>Send to
            <select class="pn-input" id="pn-seg">
              <option value="" data-n="<?= $total ?>">All subscribers (<?= number_format($total) ?>)</option>
              <?php foreach ($segments as $sg): ?><option value="<?= (int)$sg['id'] ?>" data-n="<?= (int)$sg['count'] ?>"><?= htmlspecialchars($sg['name']) ?> (<?= number_format($sg['count']) ?>)</option><?php endforeach; ?>
            </select>
          </label>
          <input type="hidden" id="pn-image" value="<?= !empty($prefill['file_path']) ? '/' . htmlspecialchars($prefill['file_path']) : '' ?>"><input type="hidden" id="pn-post" value="<?= (int)($prefill['id'] ?? 0) ?: '' ?>"><input type="hidden" id="pn-icon" value="">
          <div class="px-go">
            <button type="button" class="pn-btn" id="pn-send" <?= $total ? '' : 'disabled' ?>><i class="fas fa-paper-plane"></i> <span id="pn-send-l">Send to <?= number_format($total) ?> subscribers</span></button>
            <button type="button" class="pn-btn-light" id="pn-test"><i class="fas fa-vial"></i> Send a test to me</button>
          </div>
          <div id="pn-status" hidden><div class="pn-progress"><span id="pn-bar"></span></div><div class="pn-msg" id="pn-msg"></div></div>
          <div class="pn-msg" id="pn-test-msg"></div>
          <?php if (!edu_push_ready()): ?><p class="pn-msg err"><i class="fas fa-triangle-exclamation"></i> Push keys or the web-push library are missing on the server.</p><?php endif; ?>
        </div>
      </div>
      <div class="px-preview">
        <div class="npv">
          <p class="npv-label">Android</p>
          <div class="npv-android">
            <div class="npv-head"><img class="npv-sico" data-pv-icon alt="" src="<?= htmlspecialchars(edu_push_abs($defaultIcon)) ?>"><span>Chrome · <span data-pv-site><?= htmlspecialchars($siteHost) ?></span> · now</span></div>
            <div class="npv-row"><div><strong data-pv-title>Notification title</strong><p data-pv-body>A short line about the post…</p></div><img class="npv-lico" data-pv-icon alt="" src="<?= htmlspecialchars(edu_push_abs($defaultIcon)) ?>"></div>
            <img class="npv-img" data-pv-img alt="" hidden>
          </div>
          <p class="npv-label">Desktop</p>
          <div class="npv-desk">
            <img class="npv-img" data-pv-img alt="" hidden>
            <div class="npv-row"><img class="npv-dico" data-pv-icon alt="" src="<?= htmlspecialchars(edu_push_abs($defaultIcon)) ?>"><div><strong data-pv-title>Notification title</strong><p data-pv-body>A short line about the post…</p><small>Google Chrome · <span data-pv-site><?= htmlspecialchars($siteHost) ?></span></small></div></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ===== Reports ===== -->
  <div class="pn-pane" data-pane="reports">
    <div class="px-rep">
      <div class="pn-card">
        <div class="pn-head"><div><h3>Total subscribers</h3><div class="px-big"><?= number_format($total) ?></div></div>
          <div class="px-ranges"><?php foreach ([7, 30, 90, 365] as $d): ?><a href="<?= $pageUrl(['tab' => 'reports', 'days' => $d]) ?>" class="<?= $days === $d ? 'on' : '' ?>"><?= $d === 365 ? '1 year' : $d . ' days' ?></a><?php endforeach; ?></div></div>
        <div class="pn-growth"><canvas id="pn-growth"></canvas></div>
      </div>
      <div class="pn-card"><h3>Devices</h3><p class="px-sub">Android, iPhone / iPad and desktop</p>
        <?php if (!$stats['platform']): ?><p class="pn-muted">No subscribers yet.</p><?php else: ?>
        <div class="px-donut"><canvas id="pn-platform"></canvas></div>
        <ul class="px-legend"><?php $pc = ['Android' => '#16a34a', 'iOS (iPhone / iPad)' => '#0ea5e9', 'Desktop' => '#7c3aed', 'Other' => '#cbd5e1']; foreach ($stats['platform'] as $l => $v): ?><li><i style="background:<?= $pc[$l] ?? '#94a3b8' ?>"></i><span><?= htmlspecialchars($l) ?></span><b><?= number_format((int)$v) ?></b><small><?= $pct((int)$v, $stats['total']) ?>%</small></li><?php endforeach; ?></ul>
        <?php endif; ?>
      </div>
    </div>
    <?php $known = array_values(array_filter($stats['countries'], fn($c) => $c['c'] !== '')); $unknownC = $stats['total'] - array_sum(array_map(fn($c) => (int)$c['t'], $known)); ?>
    <div class="pn-card px-gap"><h3>Subscribers by country</h3><p class="px-sub">Only countries where you have subscribers<?= $unknownC > 0 ? ' · ' . number_format($unknownC) . ' without a known country' : '' ?></p>
      <?php if (!$known): ?><p class="pn-muted">No country known yet — it is saved when a reader subscribes (through Cloudflare).</p><?php else: ?>
      <div style="overflow-x:auto"><table class="pn-table px-ctry"><thead><tr><th>Country</th><th class="num">Subscribers</th><th class="num">Share</th><th></th></tr></thead><tbody>
      <?php foreach ($known as $c): $p = $pct((int)$c['t'], $stats['total']); ?><tr><td><?= htmlspecialchars(edu_push_country_name((string)$c['c'])) ?> <small class="pn-muted"><?= htmlspecialchars($c['c']) ?></small></td><td class="num"><b><?= number_format((int)$c['t']) ?></b></td><td class="num"><?= $p ?>%</td><td style="width:35%"><span class="px-bb"><i style="width:<?= $p ?>%"></i></span></td></tr><?php endforeach; ?>
      </tbody></table></div><?php endif; ?>
    </div>
  </div>

  <!-- ===== History ===== -->
  <div class="pn-pane" data-pane="history">
    <div class="pn-card">
      <div class="pn-head"><div><h3>Push history</h3><p class="px-sub" style="margin:0">Click a notification to see how it was delivered.</p></div>
        <?php if ($isAdmin && $campaigns): ?><button type="button" class="pn-btn-light" id="pn-clear"><i class="fas fa-broom"></i> Clear history</button><?php endif; ?></div>
      <?php if (!$campaigns): ?><p class="pn-muted">Nothing sent yet. Notifications you send appear here with their deliveries and clicks.</p><?php else: ?>
      <div style="overflow-x:auto"><table class="pn-table px-sent"><thead><tr><th>Notification</th><th>Domain / Segment</th><th class="num">Delivered</th><th class="num">Clicks</th><th>Status</th><th>Created</th><th></th></tr></thead><tbody>
      <?php foreach ($campaigns as $c): $stale = in_array($c['status'], ['pending', 'processing'], true) && strtotime($c['created_at']) < strtotime('-1 day'); $st = $stale ? 'failed' : $c['status']; ?>
        <tr class="px-camp" data-log="<?= (int)$c['id'] ?>" tabindex="0" title="Show the delivery log"><td class="px-t" style="max-width:380px"><strong><span class="pn-id">#<?= (int)$c['id'] ?></span><?= htmlspecialchars($c['title']) ?></strong><small><?= htmlspecialchars(mb_strimwidth((string)$c['body'], 0, 90, '…')) ?></small></td>
        <td class="px-t"><strong style="font-weight:500"><?= htmlspecialchars((string)(parse_url((string)$c['url'], PHP_URL_HOST) ?: $siteHost)) ?></strong><small><?= htmlspecialchars($c['segment_name'] ?: 'All subscribers') ?></small></td>
        <td class="num px-del" data-l="Delivered"><b><?= number_format((int)$c['sent']) ?></b> / <?= number_format((int)$c['total_subscribers']) ?><?= (int)$c['failed'] ? '<br><span class="px-fail">' . number_format((int)$c['failed']) . ' failed</span>' : '' ?></td>
        <td class="num px-del" data-l="Clicks"><b><?= number_format((int)$c['clicks']) ?></b><?= (int)$c['sent'] > 0 ? '<br><span class="px-pc">' . $pct((int)$c['clicks'], (int)$c['sent']) . '%</span>' : '' ?></td>
        <td><span class="pn-badge <?= $st ?>"><?= $stale ? 'Not sent (expired)' : ucfirst($st) ?></span></td>
        <td class="px-del" style="white-space:nowrap"><?= $fmtDate($c['created_at']) ?></td>
        <td class="px-acts"><span class="px-chev"><i class="fas fa-chevron-down"></i></span><?php if ($isAdmin): ?><button type="button" class="pn-icon" data-camp-del="<?= (int)$c['id'] ?>" title="Delete"><i class="fas fa-trash"></i></button><?php endif; ?></td></tr>
        <tr class="px-logrow" data-log-for="<?= (int)$c['id'] ?>" hidden><td colspan="7"><div class="px-log">Loading…</div></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
      <?php if ($hPages > 1): $pl = array_values(array_unique(array_filter([1, $hp - 1, $hp, $hp + 1, $hPages], fn($n) => $n >= 1 && $n <= $hPages))); sort($pl); ?>
      <div class="pn-pager"><?= $hp > 1 ? '<a href="' . $pageUrl(['tab' => 'history', 'hp' => $hp - 1]) . '">‹ Prev</a>' : '' ?><?php foreach ($pl as $k => $n): ?><?= $k && $n - $pl[$k - 1] > 1 ? '<em>…</em>' : '' ?><?= $n === $hp ? '<span>' . $n . '</span>' : '<a href="' . $pageUrl(['tab' => 'history', 'hp' => $n]) . '">' . $n . '</a>' ?><?php endforeach; ?><?= $hp < $hPages ? '<a href="' . $pageUrl(['tab' => 'history', 'hp' => $hp + 1]) . '">Next ›</a>' : '' ?></div>
      <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($isAdmin): ?>
  <!-- ===== Settings ===== -->
  <div class="pn-pane" data-pane="settings">
    <div class="px-set">
      <div class="pn-card"><h3>General</h3>
        <label class="pn-switch"><input type="checkbox" id="set-collect" <?= edu_push_collecting() ? 'checked' : '' ?>><span><b>Collect subscribers</b><small>Visitors see the browser's “Allow notifications” prompt. Off: no prompt and no bell — existing subscribers still get notifications.</small></span></label>
        <label class="pn-switch"><input type="checkbox" id="set-bell" <?= th_on('h_bell') ? 'checked' : '' ?>><span><b>Bell icon in the header</b><small>Hidden once a reader has subscribed.</small></span></label>
        <label class="pn-switch"><input type="checkbox" id="set-auto" <?= es('push', 'auto_send', '0') === '1' ? 'checked' : '' ?>><span><b>Send automatically for new posts</b><small>When a post is published, all subscribers get it.</small></span></label>
        <div class="pn-field" style="margin-top:14px">Notification icon<div class="pn-img-row"><img class="pn-icon-prev" id="set-icon-prev" alt="" src="<?= htmlspecialchars($defaultIcon) ?>"><button type="button" class="pn-btn-light" id="set-icon-pick">Change</button> <button type="button" class="pn-btn-light" id="set-icon-reset" <?= es('push', 'icon', '') !== '' ? '' : 'hidden' ?>>Use site icon</button></div><small class="pn-muted" style="font-weight:400">Square PNG, 192×192. Default: the site icon.</small></div>
        <input type="hidden" id="set-icon" value="<?= htmlspecialchars((string)es('push', 'icon', '')) ?>">
        <div class="px-go"><button type="button" class="pn-btn" id="set-save"><i class="fas fa-floppy-disk"></i> Save</button><span class="pn-msg" id="set-msg"></span></div>
      </div>
      <div class="px-col">
        <div class="pn-card"><h3>Keys</h3>
          <?php if ($activeKey): ?><p class="px-ok"><i class="fas fa-circle-check"></i> Active since <?= $fmtDate($activeKey['created_at']) ?></p>
          <div class="pn-keyline" title="Public key"><?= htmlspecialchars($activeKey['public_key']) ?></div>
          <?php else: ?><p class="pn-msg err"><i class="fas fa-triangle-exclamation"></i> No key yet — use “Reset keys” below to create one.</p><?php endif; ?>
          <?php if (count($keySets) > 1): ?>
          <table class="pn-table" style="margin-top:10px"><thead><tr><th>Key set</th><th class="num">Subscribers</th></tr></thead><tbody>
          <?php foreach ($keySets as $k): ?><tr><td><code><?= htmlspecialchars($mask($k['public_key'])) ?></code> <?= $k['active'] ? '<span class="pn-badge completed">Active</span>' : '<small class="pn-muted">imported</small>' ?></td><td class="num"><?= number_format((int)$k['n']) ?></td></tr><?php endforeach; ?>
          </tbody></table><?php endif; ?>
        </div>
        <div class="pn-card"><h3>Backup</h3><p class="px-sub">One file with every key and subscriber — to restore, or to move push to another site.</p>
          <div class="px-go"><a class="pn-btn-light" href="/admin/api/push.php?action=export"><i class="fas fa-download"></i> Export</a></div>
          <div class="pn-field" style="margin:14px 0 0">Import a file<div class="pn-row" style="display:flex;gap:8px;flex-wrap:wrap"><input type="file" id="imp-file" accept=".json,application/json" class="pn-input" style="flex:1;min-width:160px"><button type="button" class="pn-btn-light" id="imp-go"><i class="fas fa-upload"></i> Import</button></div></div>
          <div class="pn-msg" id="imp-msg"></div>
        </div>
        <div class="pn-card px-danger"><h3>Danger zone</h3><p class="px-sub">Reset makes a new key and erases the old key<?= count($keySets) > 1 ? 's' : '' ?> and all <?= number_format($allSubs) ?> subscriber<?= $allSubs === 1 ? '' : 's' ?>. Readers who allowed notifications re-subscribe on their next visit. Export first.</p>
          <button type="button" class="pn-btn pn-btn-danger" id="key-reset"><i class="fas fa-rotate"></i> Reset keys</button>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php admin_shell_close(); ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var $ = function (id) { return document.getElementById(id); }, CSRF = <?= json_encode(csrf_token()) ?>, PUBKEY = <?= json_encode($activeKey['public_key'] ?? '') ?>;
  var DEFAULT_ICON = <?= json_encode($defaultIcon) ?>;
  var ask = window.adminConfirm || function (m) { return Promise.resolve(confirm(m)); };
  var tell = function (m, type) { (window.adminNotice || function (x) { alert(x); })(m, { type: type || 'info' }); };
  function post(action, data, file) {
    var fd = new FormData(); fd.append('_csrf', CSRF); fd.append('action', action);
    Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); }); if (file) fd.append('file', file);
    return fetch('/admin/api/push.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json().catch(function () { return { error: 'The server answered with an error (' + r.status + ').' }; }); });
  }
  function msg(el, text, ok) { el.className = 'pn-msg ' + (ok ? 'ok' : 'err'); el.textContent = text; }
  function show(t) { document.querySelectorAll('.pn-pane').forEach(function (p) { p.classList.toggle('active', p.dataset.pane === t); }); document.querySelectorAll('.pn-tabs button').forEach(function (b) { b.classList.toggle('active', b.dataset.tab === t); }); var u = new URL(location.href); u.searchParams.set('tab', t); history.replaceState(null, '', u.pathname + u.search); if (t === 'reports') charts(); }
  document.querySelectorAll('.pn-tabs button').forEach(function (b) { b.onclick = function () { show(b.dataset.tab); }; });

  /* ---- Send ---- */
  var SITE = <?= json_encode($siteHost) ?>, site = SITE;
  function all(sel, fn) { document.querySelectorAll(sel).forEach(fn); }
  function preview() {
    var t = $('pn-title').value, b = $('pn-body').value, img = $('pn-image').value, ico = $('pn-icon').value || DEFAULT_ICON;
    all('[data-pv-title]', function (e) { e.textContent = t || 'Notification title'; });
    all('[data-pv-body]', function (e) { e.textContent = b || 'A short line about the post…'; });
    all('[data-pv-site]', function (e) { e.textContent = site; });
    all('[data-pv-img]', function (e) { e.hidden = !img; if (img) e.src = img; });
    all('[data-pv-icon]', function (e) { e.src = ico; });
    $('pn-tc').textContent = t.length + '/80'; $('pn-bc').textContent = b.length + '/180';
  }
  ['pn-title', 'pn-body'].forEach(function (id) { $(id).addEventListener('input', preview); }); preview();
  var timer, listLoaded = false;
  function setImg(p) { $('pn-image').value = p || ''; $('pn-img-prev').hidden = !p; if (p) $('pn-img-prev').src = p; $('pn-img-clear').hidden = !p; preview(); }
  function setIcon(p) { $('pn-icon').value = p || ''; $('pn-icon-prev').src = p || DEFAULT_ICON; $('pn-icon-reset').hidden = !p; preview(); }
  function loadPosts() {
    fetch('/admin/api/push.php?action=posts&q=' + encodeURIComponent($('pn-q').value.trim()), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      var box = $('pn-posts'); box.innerHTML = '';
      (d.posts || []).forEach(function (p) {
        var b = document.createElement('button'); b.type = 'button';
        b.innerHTML = (p.thumb ? '<img alt="" src="' + p.thumb + '">' : '<span class="pn-noimg"></span>') + '<span><strong></strong><small>' + p.date + '</small></span>';
        b.querySelector('strong').textContent = p.title;
        // same as fetching the post's URL: title, short text, image and the site icon
        b.onclick = function () { box.querySelectorAll('button').forEach(function (x) { x.classList.toggle('active', x === b); }); $('pn-fetch-url').value = location.origin + p.url; doFetch(); };
        box.appendChild(b);
      });
      if (!(d.posts || []).length) box.innerHTML = '<p class="pn-muted">No posts found.</p>';
    });
  }
  $('pn-q').addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(loadPosts, 300); });
  $('pn-list').addEventListener('toggle', function () { if (this.open && !listLoaded) { listLoaded = true; loadPosts(); } });

  /* LaraPush-style: any article URL → title, short text, featured image and that site's icon */
  function doFetch() {
    var u = $('pn-fetch-url').value.trim(), btn = $('pn-fetch'), out = $('pn-fetch-msg');
    if (!u) { msg(out, 'Paste an article link first.', false); return; }
    btn.disabled = true; btn.querySelector('span').textContent = 'Fetching…'; out.className = 'pn-msg'; out.textContent = '';
    fetch('/admin/api/push.php?action=fetch&url=' + encodeURIComponent(u), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      if (!d.ok) { msg(out, d.error || 'Could not read that page.', false); return; }
      $('pn-title').value = d.title || ''; $('pn-body').value = d.body || ''; $('pn-url').value = d.url || u; $('pn-post').value = d.post_id || '';
      site = d.site || SITE; setImg(d.image || ''); setIcon(d.icon || '');
      if ((d.image || d.icon) && !document.querySelector('.px-more:not(#pn-list)').open) document.querySelector('.px-more:not(#pn-list)').open = true;
    }).catch(function () { msg(out, 'Could not read that page.', false); }).then(function () { btn.disabled = false; btn.querySelector('span').textContent = 'Fetch'; });
  }
  $('pn-fetch').onclick = doFetch;
  $('pn-fetch-url').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); doFetch(); } });
  $('pn-url').addEventListener('input', function () { $('pn-post').value = ''; });
  $('pn-img-pick').onclick = function () { EduMedia.pick(function (it) { setImg(it.path); }); };
  $('pn-img-clear').onclick = function () { setImg(''); };
  $('pn-icon-pick').onclick = function () { EduMedia.pick(function (it) { setIcon(it.path); }); };
  $('pn-icon-reset').onclick = function () { setIcon(''); };
  function segCount() { var o = $('pn-seg').selectedOptions[0]; return +(o && o.dataset.n || 0); }
  function sendLabel() { var n = segCount(); $('pn-send-l').textContent = 'Send to ' + n.toLocaleString() + ' subscriber' + (n === 1 ? '' : 's'); $('pn-send').disabled = n === 0; }
  $('pn-seg').onchange = sendLabel; sendLabel();
  $('pn-send').onclick = function () {
    var btn = this, title = $('pn-title').value.trim(), url = $('pn-url').value.trim(), seg = $('pn-seg').selectedOptions[0];
    if (!title || !url) { tell('Please add a title and a link.', 'error'); return; }
    ask('Send this notification to ' + seg.textContent.trim() + ' now?', { title: 'Send notification', confirmText: 'Send now', danger: false }).then(function (ok) {
      if (!ok) return;
      btn.disabled = true; $('pn-status').hidden = false; $('pn-bar').style.width = '5%'; $('pn-msg').className = 'pn-msg'; $('pn-msg').textContent = 'Queuing…';
      function fail(e) { msg($('pn-msg'), (e && e.message) || 'Something went wrong.', false); btn.disabled = false; }
      post('send', { title: title, body: $('pn-body').value.trim(), url: url, image: $('pn-image').value, post_id: $('pn-post').value, segment_id: $('pn-seg').value, icon: $('pn-icon').value }).then(function (d) {
        if (!d.ok) throw new Error(d.error || 'Could not send');
        var total = d.total, tries = 0;
        if (!total) { $('pn-bar').style.width = '100%'; msg($('pn-msg'), 'Nobody matches this segment right now.', false); btn.disabled = false; return; }
        (function step() {
          post('process', { campaign: d.campaign }).then(function (r) {
            if (r.error) throw new Error(r.error);
            $('pn-bar').style.width = Math.max(5, Math.min(100, Math.round((r.sent + r.failed) / total * 100))) + '%';
            if (r.done) { $('pn-bar').style.width = '100%'; msg($('pn-msg'), '✓ Done — delivered to ' + r.sent.toLocaleString() + ' subscriber' + (r.sent === 1 ? '' : 's') + (r.failed ? ', ' + r.failed + ' failed (expired subscriptions are removed automatically)' : '') + '.', true); btn.disabled = false; return; }
            $('pn-msg').className = 'pn-msg';
            $('pn-msg').textContent = r.ahead ? 'Waiting in the queue — ' + r.ahead + ' notification' + (r.ahead === 1 ? '' : 's') + ' before this one…' : 'Delivered ' + r.sent.toLocaleString() + ' of ' + total.toLocaleString() + (r.failed ? ' · failed ' + r.failed : '') + ' · sending…';
            // after ~10 minutes the page stops watching; the background job finishes the rest
            if (++tries > 300) { msg($('pn-msg'), 'Still sending in the background — you can close this page. Progress is shown in History.', true); btn.disabled = false; return; }
            setTimeout(step, r.busy || r.ahead ? 2000 : 400);
          }).catch(fail);
        })();
      }).catch(fail);
    });
  };

  /* ---- Test push to this browser ---- */
  function b64(s) { var p = '='.repeat((4 - s.length % 4) % 4), b = atob((s + p).replace(/-/g, '+').replace(/_/g, '/')); return Uint8Array.from(b, function (c) { return c.charCodeAt(0); }); }
  function testFields() { return { title: $('pn-title').value.trim(), body: $('pn-body').value.trim(), url: $('pn-url').value.trim(), image: $('pn-image').value, icon: $('pn-icon').value }; }
  $('pn-test').onclick = function () {
    var out = $('pn-test-msg'), btn = this;
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) { msg(out, 'This browser does not support push notifications.', false); return; }
    if (!PUBKEY) { msg(out, 'No push key yet — create one in Settings.', false); return; }
    btn.disabled = true; out.className = 'pn-msg'; out.textContent = 'Asking this browser…';
    Notification.requestPermission().then(function (p) {
      if (p !== 'granted') throw new Error('Notifications are blocked in this browser. Allow them from the lock icon next to the address.');
      return navigator.serviceWorker.register('/sw.js?v=12102026').then(function () { return navigator.serviceWorker.ready; });
    }).then(function (reg) {
      return reg.pushManager.getSubscription().then(function (sub) {
        var k = sub && sub.options && sub.options.applicationServerKey, cur = '';
        if (k) { var a = new Uint8Array(k), s = ''; for (var i = 0; i < a.length; i++) s += String.fromCharCode(a[i]); cur = btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''); }
        if (sub && cur && cur !== PUBKEY) return sub.unsubscribe().then(function () { return null; });
        return sub;
      }).then(function (sub) { return sub || reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64(PUBKEY) }); });
    }).then(function (sub) {
      return fetch('/push_notifications/save-subscriptions.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ subscription: sub.toJSON(), key: PUBKEY, lang: navigator.language || '', tz: (Intl.DateTimeFormat().resolvedOptions().timeZone || '') }) })
        .then(function (r) { return r.json(); }).then(function (d) { if (!d.ok) throw new Error(d.error || 'Could not save this browser.'); return sub; });
    }).then(function (sub) {
      out.textContent = 'Sending…';
      return post('test', Object.assign(testFields(), { endpoint: sub.endpoint }));
    }).then(function (r) {
      if (!r.ok) throw new Error(r.error || 'Not delivered');
      msg(out, '✓ Test notification sent to this browser.', true);
    }).catch(function (e) { msg(out, e.message || 'Test failed.', false); }).then(function () { btn.disabled = false; });
  };

  /* ---- Analytics ---- */
  var STATS = <?= json_encode(['growth' => $stats['growth'], 'platform' => $stats['platform']], JSON_UNESCAPED_UNICODE) ?>, drawn = false;
  function charts() {
    if (drawn) return; if (!window.Chart) { setTimeout(charts, 150); return; } drawn = true;
    var g = STATS.growth, gc = $('pn-growth');
    if (gc) new Chart(gc, { data: { labels: g.map(function (x) { return new Date(x.d + 'T00:00:00').toLocaleDateString([], { day: 'numeric', month: 'short' }); }),
      datasets: [{ type: 'line', label: 'Total subscribers', data: g.map(function (x) { return x.total; }), borderColor: '#7c3aed', backgroundColor: 'rgba(124,58,237,.08)', fill: true, tension: .3, pointRadius: 0, yAxisID: 'y' },
                 { type: 'bar', label: 'New', data: g.map(function (x) { return x.new; }), backgroundColor: '#22c55e', borderRadius: 4, yAxisID: 'y1' }] },
      options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true } } },
        scales: { x: { grid: { display: false }, ticks: { maxTicksLimit: 12 } }, y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#f1f5f9' } }, y1: { beginAtZero: true, position: 'right', grid: { display: false }, ticks: { precision: 0 } } } } });
    var pc = $('pn-platform'), PCOL = { 'Android': '#16a34a', 'iOS (iPhone / iPad)': '#0ea5e9', 'Desktop': '#7c3aed', 'Other': '#cbd5e1' }, L = Object.keys(STATS.platform || {});
    if (pc && L.length) new Chart(pc, { type: 'doughnut', data: { labels: L, datasets: [{ data: L.map(function (k) { return +STATS.platform[k]; }), backgroundColor: L.map(function (k) { return PCOL[k] || '#94a3b8'; }), borderWidth: 0 }] },
      options: { responsive: true, maintainAspectRatio: false, cutout: '68%', plugins: { legend: { display: false } } } });
  }

  /* ---- Delivery log (inside History) ---- */
  function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }
  function openLog(tr) {
    var id = tr.dataset.log, row = document.querySelector('[data-log-for="' + id + '"]'), box = row.querySelector('.px-log');
    var open = row.hidden; row.hidden = !open; tr.classList.toggle('open', open); if (!open || box.dataset.done) return;
    fetch('/admin/api/push.php?action=log&id=' + id, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      if (!d.ok) { box.textContent = d.error || 'Could not load the log.'; return; }
      box.dataset.done = '1';
      var t = d.total || 0, w = function (n) { return t ? (n / t * 100).toFixed(1) : 0; };
      var h = '<div class="px-log-nums"><span><b>' + t.toLocaleString() + '</b> queued</span><span class="ok"><b>' + d.sent.toLocaleString() + '</b> delivered</span><span class="bad"><b>' + d.failed.toLocaleString() + '</b> failed</span>' + (d.waiting ? '<span class="wait"><b>' + d.waiting.toLocaleString() + '</b> ' + (d.expired ? 'not sent (expired)' : 'waiting') + '</span>' : '') + '<span><b>' + d.clicks.toLocaleString() + '</b> clicks' + (d.sent ? ' (' + (d.clicks / d.sent * 100).toFixed(1) + '%)' : '') + '</span></div>';
      h += '<div class="px-log-bar"><i class="ok" style="width:' + w(d.sent) + '%"></i><i class="bad" style="width:' + w(d.failed) + '%"></i><i class="wait" style="width:' + w(d.waiting) + '%"></i></div>';
      h += '<p class="px-log-meta">' + esc(d.segment) + (d.started ? ' · started ' + esc(d.started) : '') + (d.finished ? ' · finished ' + esc(d.finished) : '') + ' · <a href="' + esc(d.url) + '" target="_blank" rel="noopener">' + esc(d.url) + '</a></p>';
      if (d.errors.length) h += '<p class="px-log-h">Why some failed</p><ul class="px-log-err">' + d.errors.map(function (e) { return '<li><b>' + e.n.toLocaleString() + '</b> ' + esc(e.why) + '</li>'; }).join('') + '</ul>';
      if (d.rows.length) h += '<p class="px-log-h">Latest deliveries</p><div class="px-log-list">' + d.rows.map(function (r) { return '<div><span class="pn-badge ' + (r.status === 'sent' ? 'completed' : r.status === 'failed' ? 'failed' : 'pending') + '">' + esc(r.status === 'sent' ? 'Delivered' : r.status === 'failed' ? 'Failed' : d.expired ? 'Not sent' : 'Waiting') + '</span><span>' + esc(r.who) + '</span><small>' + esc(r.at) + (r.tries > 1 ? ' · ' + r.tries + ' tries' : '') + '</small></div>'; }).join('') + '</div>';
      box.innerHTML = h;
    }).catch(function () { box.textContent = 'Could not load the log.'; });
  }
  document.querySelectorAll('tr.px-camp').forEach(function (tr) {
    tr.addEventListener('click', function (e) { if (!e.target.closest('[data-camp-del]')) openLog(tr); });
    tr.addEventListener('keydown', function (e) { if (e.key === 'Enter') openLog(tr); });
  });
  if (/[?&]log=(\d+)/.test(location.search)) { var lt = document.querySelector('tr.px-camp[data-log="' + RegExp.$1 + '"]'); if (lt) openLog(lt); }

  /* ---- History / subscribers ---- */
  document.querySelectorAll('[data-camp-del]').forEach(function (b) { b.onclick = function () { ask('Delete this notification from the history?', { title: 'Delete', confirmText: 'Delete' }).then(function (ok) { if (ok) post('delete_campaign', { id: b.dataset.campDel }).then(function () { var tr = b.closest('tr'), lr = document.querySelector('[data-log-for="' + b.dataset.campDel + '"]'); if (lr) lr.remove(); tr.remove(); }); }); }; });
  var clr = $('pn-clear'); if (clr) clr.onclick = function () { ask('Clear the whole notification history and its delivery logs? Notifications still being delivered stay.', { title: 'Clear history', confirmText: 'Clear history' }).then(function (ok) { if (ok) post('clear_history', {}).then(function (d) { if (d.ok) location.href = '?tab=history'; else tell(d.error || 'Could not clear.', 'error'); }); }); };

  /* ---- Settings ---- */
  var sv = $('set-save');
  if (sv) {
    var setIco = function (p) { $('set-icon').value = p || ''; $('set-icon-prev').src = p || <?= json_encode((string)es('general', 'favicon', '') ?: '/assets/icons/android-icon-192x192.png') ?>; $('set-icon-reset').hidden = !p; };
    $('set-icon-pick').onclick = function () { EduMedia.pick(function (it) { setIco(it.path); }); };
    $('set-icon-reset').onclick = function () { setIco(''); };
    sv.onclick = function () {
      post('settings', { collect: $('set-collect').checked ? '1' : '0', bell: $('set-bell').checked ? '1' : '0', auto_send: $('set-auto').checked ? '1' : '', icon: $('set-icon').value }).then(function (d) {
        if (d.ok) { msg($('set-msg'), ' Saved.', true); } else msg($('set-msg'), ' ' + (d.error || 'Could not save.'), false);
      });
    };
    $('key-reset').onclick = function () {
      ask('This creates a new key and completely erases the old key and all <?= number_format($allSubs) ?> subscribers linked to it. Readers who allowed notifications are subscribed again automatically on their next visit.', { title: 'Reset push keys?', confirmText: 'Continue' }).then(function (ok) {
        if (!ok) return;
        var t = prompt('Type RESET to erase the old keys and subscribers'); if (t !== 'RESET') { if (t !== null) tell('Nothing changed — the word did not match.', 'info'); return; }
        post('reset_keys', { confirm: 'RESET' }).then(function (d) { if (d.ok) { tell('New keys are ready. ' + d.removed + ' old subscriber' + (d.removed === 1 ? ' was' : 's were') + ' erased.', 'success'); setTimeout(function () { location.href = '?tab=settings'; }, 1500); } else tell(d.error || 'Could not reset.', 'error'); });
      });
    };
    $('imp-go').onclick = function () {
      var f = $('imp-file').files[0], out = $('imp-msg'); if (!f) { msg(out, 'Please choose an export file.', false); return; }
      out.className = 'pn-msg'; out.textContent = 'Importing…';
      post('import', {}, f).then(function (d) {
        if (!d.ok) { msg(out, d.error || 'Import failed.', false); return; }
        msg(out, '✓ Imported: ' + d.keys + ' new key set' + (d.keys === 1 ? '' : 's') + ', ' + d.added + ' new subscriber' + (d.added === 1 ? '' : 's') + ', ' + d.updated + ' updated' + (d.skipped ? ', ' + d.skipped + ' skipped (no key or broken)' : '') + '.', true);
      });
    };
  }
  show(<?= json_encode($tab) ?>);
});
</script>
</body>
</html>
