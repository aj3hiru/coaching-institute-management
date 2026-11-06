<?php
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/perf.php';
require_once INCLUDES_PATH . '/theme.php';
admin_guard(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $int = fn($k, $min, $max, $d) => max($min, min($max, (int)($_POST[$k] ?? $d)));
    edu_settings_save('performance', [
        'delay_scripts' => in_array($_POST['delay_scripts'] ?? '', ['off', 'interaction', 'timeout'], true) ? $_POST['delay_scripts'] : 'off',
        'delay_timeout' => $int('delay_timeout', 1, 20, 3),
        'ttl_home' => $int('ttl_home', 60, 86400, 3600), 'ttl_post' => $int('ttl_post', 60, 604800, 21600), 'ttl_list' => $int('ttl_list', 60, 86400, 3600),
        'cdn_headers' => !empty($_POST['cdn_headers']) ? '1' : '0', 'cdn_max_age' => $int('cdn_max_age', 0, 86400, 60), 'cdn_swr' => !empty($_POST['cdn_swr']) ? '1' : '0',
    ]);
    if (isset($_POST['system_font'])) {
        // no Google fonts: remove them from the Font Manager and from the Typography rules that used them
        $__t = edu_theme();
        $__g = array_column(array_filter((array)$__t['fonts'], fn($f) => !empty($f['google'])), 'family');
        if ($__g) edu_settings_save('theme', [
            'font_body' => 'system', 'font_heading' => 'system',
            'fonts' => array_values(array_filter((array)$__t['fonts'], fn($f) => empty($f['google']))),
            'typography' => array_map(fn($r) => in_array($r['family'] ?? '', $__g, true) ? array_merge($r, ['family' => '']) : $r, (array)$__t['typography']),
        ]);
    }
    edu_perf_write_cache_settings();
    admin_log('performance_update', 'Updated Performance settings');
    admin_flash('Performance settings saved.');
    header('Location: /admin/performance-settings.php');
    exit;
}
$p = fn($k) => perf_opt($k);
$cacheFiles = count(glob(ROOT_PATH . '/cache/*.html') ?: []);
$sysFont = edu_google_fonts_href() === ''; // no Google fonts in Customize → Typography
$durations = [60 => '1 minute', 300 => '5 minutes', 900 => '15 minutes', 1800 => '30 minutes', 3600 => '1 hour', 10800 => '3 hours', 21600 => '6 hours', 43200 => '12 hours', 86400 => '1 day', 604800 => '1 week'];
$sel = function ($name, $value, array $opts) { $o = ''; foreach ($opts as $k => $l) $o .= '<option value="' . $k . '"' . ((string)$k === (string)$value ? ' selected' : '') . '>' . $l . '</option>'; return '<select id="' . $name . '" name="' . $name . '" class="ps-dur-select">' . $o . '</select>'; };
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Performance Settings - <?= htmlspecialchars(edu_site_name()) ?></title>
<?php admin_shell_head(); ?>
</head>
<body>
<?php admin_shell_open('Performance Settings', 'Speed and caching controls'); ?>
<?= admin_flash() ?>
<div class="pf-wrap">
  <div class="pf-status">
    <div class="pf-st"><span class="pf-dot ok"><i class="fas fa-image"></i></span><span><span class="pf-st-label">Image optimisation</span><span class="pf-st-note ok">Featured images: WebP, 4 sizes</span></span></div>
    <a class="pf-st" href="/admin/cache-manager.php"><span class="pf-dot ok"><i class="fas fa-layer-group"></i></span><span><span class="pf-st-label">Page cache</span><?php if (perf_opt('cache_off') === '1'): ?><span class="pf-st-note">Off</span><?php else: ?><span class="pf-st-note ok">On · <?= $cacheFiles ?> pages</span><?php endif; ?></span></a>
    <div class="pf-st"><span class="pf-dot <?= $p('delay_scripts') !== 'off' ? 'ok' : 'off' ?>"><i class="fas fa-hourglass-half"></i></span><span><span class="pf-st-label">Delayed scripts</span><span class="pf-st-note <?= $p('delay_scripts') !== 'off' ? 'ok' : '' ?>"><?= $p('delay_scripts') !== 'off' ? 'On' : 'Off' ?></span></span></div>
    <div class="pf-st"><span class="pf-dot <?= $sysFont ? 'ok' : 'off' ?>"><i class="fas fa-font"></i></span><span><span class="pf-st-label">Fonts</span><span class="pf-st-note <?= $sysFont ? 'ok' : '' ?>"><?= $sysFont ? 'System font' : 'Google font' ?></span></span></div>
    <div class="pf-st"><span class="pf-dot <?= $p('cdn_headers') === '1' ? 'ok' : 'off' ?>"><i class="fas fa-cloud"></i></span><span><span class="pf-st-label">CDN cache headers</span><span class="pf-st-note <?= $p('cdn_headers') === '1' ? 'ok' : '' ?>"><?= $p('cdn_headers') === '1' ? 'On' : 'Off' ?></span></span></div>
  </div>
  <form method="POST">
    <?= csrf_field() ?>
    <div class="pf-grid">
      <section class="pf-card">
        <div class="pf-card-head"><span class="ps-sec-icon" style="background:#ede9fe;color:#6366f1"><i class="fas fa-font"></i></span><div><div class="ps-sec-title">Scripts &amp; Fonts</div><div class="ps-sec-desc">What the browser has to load before showing a page</div></div></div>
        <div class="pf-card-body">
          <div class="pf-field" style="align-items:flex-start;flex-direction:column;gap:.5rem">
            <label for="delay_scripts" style="display:flex;align-items:center;gap:.4rem">Delay ads and tracking scripts <span class="pf-badge">Big speed win</span></label>
            <?= str_replace('class="ps-dur-select"', 'class="ps-dur-select" style="width:100%"', $sel('delay_scripts', $p('delay_scripts'), ['off' => 'Off — load them with the page', 'interaction' => 'Until the reader first scrolls or taps (fastest)', 'timeout' => 'Until the reader first scrolls or taps — or the timeout below'])) ?>
            <label for="delay_timeout" style="margin-top:.25rem">Start anyway after</label>
            <?= $sel('delay_timeout', $p('delay_timeout'), [1 => '1 second after the page has loaded', 2 => '2 seconds after the page has loaded', 3 => '3 seconds after the page has loaded', 5 => '5 seconds after the page has loaded', 8 => '8 seconds after the page has loaded']) ?>
            <span class="ps-toggle-hint">Ads (Ad Inserter) and Code Snippets wait, so the page appears and responds first. With “first scroll”, visitors who leave without touching the page are not counted by Analytics.</span>
          </div>
          <label class="ps-toggle-row" style="cursor:pointer"><div class="ps-toggle-info"><div class="ps-toggle-label">Use system font</div><div class="ps-toggle-hint">Show text in the phone’s/computer’s own font instead of a Google font. Pages appear a little faster. (Same as Customize → Typography.)</div></div><span class="ps-sw"><input type="checkbox" name="system_font" <?= $sysFont ? 'checked disabled' : '' ?>><span class="ps-sl"></span></span></label>
        </div>
      </section>
      <section class="pf-card">
        <div class="pf-card-head"><span class="ps-sec-icon" style="background:#dbeafe;color:#2563eb"><i class="fas fa-layer-group"></i></span><div><div class="ps-sec-title">Page Cache</div><div class="ps-sec-desc">Ready-made pages served without touching the database</div></div></div>
        <div class="pf-card-body">
          <div class="pf-field"><label for="ttl_home">Homepage</label><?= $sel('ttl_home', $p('ttl_home'), array_slice($durations, 0, 9, true)) ?></div>
          <div class="pf-field"><label for="ttl_post">Posts</label><?= $sel('ttl_post', $p('ttl_post'), $durations) ?></div>
          <div class="pf-field"><label for="ttl_list">Latest Updates page</label><?= $sel('ttl_list', $p('ttl_list'), array_slice($durations, 0, 9, true)) ?></div>
          <span class="ps-toggle-hint">A post’s cached copy is replaced as soon as you update it. Clear everything in the <a href="/admin/cache-manager.php">Cache Manager</a>.</span>
        </div>
      </section>
      <section class="pf-card">
        <div class="pf-card-head"><span class="ps-sec-icon" style="background:#d1fae5;color:#059669"><i class="fas fa-cloud"></i></span><div><div class="ps-sec-title">CDN &amp; Browser Cache</div><div class="ps-sec-desc">Cache-Control headers on public pages (Cloudflare)</div></div></div>
        <div class="pf-card-body">
          <label class="ps-toggle-row" style="cursor:pointer"><div class="ps-toggle-info"><div class="ps-toggle-label">Send cache headers</div><div class="ps-toggle-hint">Lets a CDN like Cloudflare keep a copy of public pages for visitors. Signed-in staff always get fresh pages.</div></div><span class="ps-sw"><input type="checkbox" name="cdn_headers" <?= $p('cdn_headers') === '1' ? 'checked' : '' ?>><span class="ps-sl"></span></span></label>
          <div class="pf-field"><label for="cdn_max_age">Keep a copy for</label><?= $sel('cdn_max_age', $p('cdn_max_age'), [60 => '1 minute', 300 => '5 minutes', 600 => '10 minutes', 1800 => '30 minutes', 3600 => '1 hour']) ?></div>
          <label class="ps-toggle-row" style="cursor:pointer"><div class="ps-toggle-info"><div class="ps-toggle-label">Serve stale while refreshing</div><div class="ps-toggle-hint">When the copy expires, visitors still get the old copy instantly while a fresh one is fetched in the background.</div></div><span class="ps-sw"><input type="checkbox" name="cdn_swr" <?= $p('cdn_swr') === '1' ? 'checked' : '' ?>><span class="ps-sl"></span></span></label>
        </div>
      </section>
    </div>
    <div class="pf-links"><a class="pf-link" href="/admin/cache-manager.php"><i class="fas fa-broom"></i> Cache Manager <span>Clear the page cache</span></a><?php if (is_file(__DIR__ . '/cron-manager.php')): ?><a class="pf-link" href="/admin/cron-manager.php"><i class="fas fa-clock"></i> Cron Manager <span>Scheduled jobs</span></a><?php endif; ?></div>
    <div class="ps-savebar pf-savebar"><span class="ps-save-hint">Changes apply to new page loads right away.</span><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button></div>
  </form>
</div>
<?php admin_shell_close(); ?>
</body>
</html>
