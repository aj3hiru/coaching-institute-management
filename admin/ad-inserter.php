<?php
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/ads.php';
admin_guard(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $in = json_decode((string)($_POST['config'] ?? '{}'), true) ?: [];
    $ins = ['disabled', 'before_post', 'before_content', 'before_featured_image', 'after_featured_image', 'before_paragraph', 'after_paragraph', 'after_content', 'after_post', 'before_comments', 'after_comments', 'footer'];
    $align = ['default', 'left', 'center', 'right', 'float-left', 'float-right'];
    $blocks = [];
    for ($i = 0; $i < 16; $i++) {
        $b = $in['blocks'][$i] ?? [];
        $blocks[] = [
            'code' => substr((string)($b['code'] ?? ''), 0, 50000), 'enabled' => !empty($b['enabled']),
            'pages' => array_values(array_intersect((array)($b['pages'] ?? []), ['post', 'homepage', 'category', 'search', 'tag'])),
            'insertion' => in_array($b['insertion'] ?? '', $ins, true) ? $b['insertion'] : 'disabled',
            'alignment' => in_array($b['alignment'] ?? '', $align, true) ? $b['alignment'] : 'default',
            'paragraph' => max(1, min(50, (int)($b['paragraph'] ?? 1))), 'min_height' => max(0, min(1000, (int)($b['min_height'] ?? 0))),
        ];
    }
    $adsTxt = substr(str_replace("\r", '', (string)($in['ads_txt'] ?? '')), 0, 100000);
    $adsOn = !empty($in['ads_txt_enabled']);
    edu_settings_save('ads', [
        'blocks' => $blocks, 'global_header' => substr((string)($in['global_header'] ?? ''), 0, 50000),
        'global_footer' => substr((string)($in['global_footer'] ?? ''), 0, 50000), 'ads_txt_enabled' => $adsOn, 'ads_txt' => $adsTxt,
    ]);
    // ads.txt is served as a real file at the site root.
    $file = ROOT_PATH . '/ads.txt';
    if ($adsOn && trim($adsTxt) !== '') @file_put_contents($file, rtrim($adsTxt) . "\n");
    elseif (is_file($file)) @unlink($file);
    admin_log('ads_update', 'Updated Ad Inserter settings');
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}
$cfg = edu_ads_config();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Ad Inserter - <?= htmlspecialchars(edu_site_name()) ?></title>
<?php admin_shell_head(); ?>
<style>.ai-pane{display:none}.ai-pane.active{display:block}.ai-msg{margin-left:12px;font-size:.85rem;color:#16a34a}.ai-para[hidden]{display:none}
/* block tabs + on/off switch on one line */
.ai-top{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin:0 0 16px}
.ai-top .ai-block-tabs{margin:0;flex:1;min-width:0}
.ai-onoff{display:inline-flex;align-items:center;gap:10px;cursor:pointer;user-select:none;padding:6px 12px 6px 6px;border:1px solid #e5e7eb;border-radius:999px;background:#fff;font-weight:600;font-size:.9rem;color:#6b7280;flex-shrink:0}
.ai-onoff input{position:absolute;opacity:0;pointer-events:none}
.ai-onoff .ai-sw{position:relative;width:44px;height:24px;border-radius:999px;background:#d1d5db;transition:background .18s}
.ai-onoff .ai-sw::after{content:"";position:absolute;top:3px;left:3px;width:18px;height:18px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.25);transition:transform .18s}
.ai-onoff input:checked+.ai-sw{background:#16a34a}.ai-onoff input:checked+.ai-sw::after{transform:translateX(20px)}
.ai-onoff input:focus-visible+.ai-sw{outline:2px solid #7c3aed;outline-offset:2px}
.ai-onoff:has(input:checked){border-color:#bbf7d0;background:#f0fdf4;color:#166534}
/* Insertion · Paragraph · Alignment · Reserve height — one row */
.ai-settings-row{display:grid !important;grid-template-columns:minmax(0,2.2fr) minmax(0,1fr) minmax(0,1.4fr) minmax(0,1.1fr);gap:14px;align-items:end;margin-top:14px}
.ai-settings-row .form-group{margin:0}
.ai-settings-row .form-control{height:42px}
.ai-settings-row:not(:has(.ai-para:not([hidden]))){grid-template-columns:minmax(0,2.2fr) minmax(0,1.4fr) minmax(0,1.1fr)}
.ai-settings-hint{margin:8px 0 0;font-size:.8rem;color:#6b7280}
@media (max-width:860px){.ai-settings-row,.ai-settings-row:not(:has(.ai-para:not([hidden]))){grid-template-columns:1fr 1fr}}
@media (max-width:520px){.ai-settings-row,.ai-settings-row:not(:has(.ai-para:not([hidden]))){grid-template-columns:1fr}}
</style>
</head>
<body>
<?php admin_shell_open('Ad Inserter', 'Manage ad placements across your site'); ?>
<div class="toolbar"><h2 class="toolbar-title">Ad Inserter</h2></div>
<div class="ai-tabs"><button type="button" class="ai-tab active" data-pane="blocks">Blocks</button><button type="button" class="ai-tab" data-pane="global">Header / Footer</button><button type="button" class="ai-tab" data-pane="adstxt">Ads.txt</button></div>

<div class="card ai-card ai-pane active" data-pane="blocks">
  <div class="ai-top">
    <div class="ai-block-tabs" id="ai-block-tabs"></div>
    <label class="ai-onoff" title="Show this block on the site"><input type="checkbox" id="ai-enabled"><span class="ai-sw" aria-hidden="true"></span><span id="ai-onoff-text">Off</span></label>
  </div>
  <textarea class="form-control ai-code" id="ai-code" rows="8" placeholder="Ad network code (AdSense, script, widget, etc.)" spellcheck="false"></textarea>
  <div class="ai-pages-row" id="ai-pages">
    <?php foreach (['post' => 'Posts', 'homepage' => 'Homepage', 'category' => 'Category & list pages', 'search' => 'Search pages', 'tag' => 'Tag / Author pages'] as $k => $l): ?><label class="ai-page-check"><input type="checkbox" value="<?= $k ?>"><?= $l ?></label><?php endforeach; ?>
  </div>
  <div class="ai-settings-row">
    <div class="form-group"><label>Insertion</label><select class="form-control" id="ai-insertion">
      <?php foreach (['disabled' => 'Disabled', 'before_post' => 'Before post (posts only)', 'before_content' => 'Before content', 'before_featured_image' => 'Before featured image (posts only)', 'after_featured_image' => 'After featured image (posts only)', 'before_paragraph' => 'Before paragraph (posts only)', 'after_paragraph' => 'After paragraph (posts only)', 'after_content' => 'After content', 'after_post' => 'After post (posts only)', 'before_comments' => 'Before comments (posts only)', 'after_comments' => 'After comments (posts only)', 'footer' => 'Footer'] as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?>
    </select></div>
    <div class="form-group ai-para" id="ai-para-wrap"><label>Paragraph number</label><input type="number" min="1" max="50" class="form-control" id="ai-paragraph"></div>
    <div class="form-group"><label>Alignment</label><select class="form-control" id="ai-alignment"><?php foreach (['default' => 'Default', 'left' => 'Left', 'center' => 'Center', 'right' => 'Right', 'float-left' => 'Float left', 'float-right' => 'Float right'] as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label>Reserve height (px)</label><input type="number" min="0" max="1000" class="form-control" id="ai-minh" placeholder="0"></div>
  </div>
  <p class="ai-settings-hint">Reserve height keeps space for the ad while it loads, so the page doesn’t jump. It is a minimum only — a bigger ad still shows in full.</p>
  <div class="ai-save-row"><button type="button" class="btn btn-primary" data-save>Save Settings 1 - 16</button><span class="ai-msg" data-msg></span></div>
</div>

<div class="card ai-card ai-pane" data-pane="global">
  <div class="form-group"><label>Header code (inside &lt;head&gt; on every page)</label><textarea class="form-control ai-code" id="ai-gh" rows="8" placeholder="e.g. the AdSense auto-ads script" spellcheck="false"></textarea></div>
  <div class="form-group"><label>Footer code (end of every page)</label><textarea class="form-control ai-code" id="ai-gf" rows="8" spellcheck="false"></textarea></div>
  <div class="ai-save-row"><button type="button" class="btn btn-primary" data-save>Save Settings</button><span class="ai-msg" data-msg></span></div>
</div>

<div class="card ai-card ai-pane" data-pane="adstxt">
  <label class="ai-toggle" style="margin-bottom:12px"><input type="checkbox" id="ai-adstxt-on"><span class="ai-toggle-track" aria-hidden="true"></span>Serve ads.txt at <?= htmlspecialchars(rtrim(SITE_URL, '/')) ?>/ads.txt</label>
  <textarea class="form-control ai-code" id="ai-adstxt" rows="10" placeholder="google.com, pub-0000000000000000, DIRECT, f08c47fec0942fa0" spellcheck="false"></textarea>
  <div class="ai-save-row"><button type="button" class="btn btn-primary" data-save>Save ads.txt</button> <a class="btn btn-secondary" href="/ads.txt" target="_blank">View</a><span class="ai-msg" data-msg></span></div>
</div>
<?php admin_shell_close(); ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var cfg = <?= json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>, cur = 0, $ = function (id) { return document.getElementById(id); };
  var postOnly = ['before_post', 'before_featured_image', 'after_featured_image', 'before_paragraph', 'after_paragraph', 'after_post', 'before_comments', 'after_comments'];
  function status(b) { if (!b.enabled) return ''; var ok = b.code.trim() && b.insertion !== 'disabled' && b.pages.length && (postOnly.indexOf(b.insertion) < 0 || b.pages.indexOf('post') >= 0); return ok ? 'live' : 'broken'; }
  function tabs() { $('ai-block-tabs').innerHTML = cfg.blocks.map(function (b, i) { var s = status(b); return '<button type="button" class="ai-block-tab' + (i === cur ? ' active' : '') + (s ? ' ' + s : '') + '" data-i="' + i + '" title="Block ' + (i + 1) + ' — ' + (s === 'live' ? 'live' : s === 'broken' ? 'enabled, but will not show' : 'off') + '">' + (i + 1) + (s ? '<i class="ai-dot ' + s + '"></i>' : '') + '</button>'; }).join(''); }
  function load() {
    var b = cfg.blocks[cur];
    $('ai-enabled').checked = !!b.enabled; $('ai-code').value = b.code; $('ai-insertion').value = b.insertion; $('ai-alignment').value = b.alignment; $('ai-paragraph').value = b.paragraph; $('ai-minh').value = b.min_height;
    document.querySelectorAll('#ai-pages input').forEach(function (c) { c.checked = b.pages.indexOf(c.value) >= 0; });
    $('ai-para-wrap').hidden = !/paragraph/.test(b.insertion); onoff(); tabs();
  }
  function onoff() { $('ai-onoff-text').textContent = $('ai-enabled').checked ? 'Enabled' : 'Off'; }
  function store() {
    var b = cfg.blocks[cur]; b.enabled = $('ai-enabled').checked; b.code = $('ai-code').value; b.insertion = $('ai-insertion').value; b.alignment = $('ai-alignment').value; b.paragraph = +$('ai-paragraph').value || 1; b.min_height = +$('ai-minh').value || 0;
    b.pages = [].slice.call(document.querySelectorAll('#ai-pages input:checked')).map(function (c) { return c.value; });
    $('ai-para-wrap').hidden = !/paragraph/.test(b.insertion); onoff(); tabs();
  }
  $('ai-block-tabs').addEventListener('click', function (e) { var t = e.target.closest('[data-i]'); if (t) { store(); cur = +t.dataset.i; load(); } });
  ['ai-enabled', 'ai-code', 'ai-insertion', 'ai-alignment', 'ai-paragraph', 'ai-minh'].forEach(function (id) { $(id).addEventListener('input', store); $(id).addEventListener('change', store); });
  document.querySelectorAll('#ai-pages input').forEach(function (c) { c.addEventListener('change', store); });
  $('ai-gh').value = cfg.global_header; $('ai-gf').value = cfg.global_footer; $('ai-adstxt').value = cfg.ads_txt; $('ai-adstxt-on').checked = !!cfg.ads_txt_enabled;
  document.querySelectorAll('.ai-tab').forEach(function (t) { t.onclick = function () { document.querySelectorAll('.ai-tab').forEach(function (x) { x.classList.toggle('active', x === t); }); document.querySelectorAll('.ai-pane').forEach(function (p) { p.classList.toggle('active', p.dataset.pane === t.dataset.pane); }); }; });
  document.querySelectorAll('[data-save]').forEach(function (btn) { btn.onclick = function () {
    store(); cfg.global_header = $('ai-gh').value; cfg.global_footer = $('ai-gf').value; cfg.ads_txt = $('ai-adstxt').value; cfg.ads_txt_enabled = $('ai-adstxt-on').checked;
    var fd = new FormData(); fd.append('_csrf', <?= json_encode(csrf_token()) ?>); fd.append('config', JSON.stringify(cfg));
    btn.disabled = true; var msg = btn.parentNode.querySelector('[data-msg]'); msg.textContent = 'Saving…';
    fetch('/admin/ad-inserter.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function () { msg.textContent = 'Saved — live on the site now.'; }).catch(function () { msg.textContent = 'Could not save.'; msg.style.color = '#dc2626'; }).finally(function () { btn.disabled = false; });
  }; });
  load();
});
</script>
</body>
</html>
