<?php
require_once __DIR__ . '/components/guard.php';
admin_guard(['admin']);

$g = edu_settings('general');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $str = fn($k, $max = 300) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
    $url = fn($k) => ($v = trim((string)($_POST[$k] ?? ''))) !== '' && filter_var($v, FILTER_VALIDATE_URL) ? $v : '';
    $save = [
        'site_title'       => $str('site_title', 100),
        'tagline'          => $str('tagline', 200),
        'logo'             => $str('logo', 300),
        'logo_w'           => max(20, min(600, (int)($_POST['logo_w'] ?? 0))) ?: edu_logo_size()[0],
        'header_logo'      => !empty($_POST['header_logo']) ? '1' : '0',
        'favicon'          => $str('favicon', 300),
        'admin_email'      => filter_var(trim((string)($_POST['admin_email'] ?? '')), FILTER_VALIDATE_EMAIL) ?: '',
        'contact_email'    => filter_var(trim((string)($_POST['contact_email'] ?? '')), FILTER_VALIDATE_EMAIL) ?: '',
        'phone'            => $str('phone', 40),
        'address'          => $str('address', 300),
        'footer_tagline'   => $str('footer_tagline', 300),
        'home_description' => $str('home_description', 300),
        'home_keywords'    => $str('home_keywords', 300),
        'date_format'      => in_array($_POST['date_format'] ?? '', ['j F Y', 'M j, Y', 'Y-m-d', 'd/m/Y', 'm/d/Y'], true) ? $_POST['date_format'] : 'j F Y',
    ];
    $save['smtp_host'] = $str('smtp_host', 200);
    $save['smtp_port'] = (string)max(0, min(65535, (int)($_POST['smtp_port'] ?? 465)));
    $save['smtp_secure'] = in_array($_POST['smtp_secure'] ?? '', ['ssl', 'tls', 'none'], true) ? $_POST['smtp_secure'] : 'ssl';
    $save['smtp_user'] = $str('smtp_user', 200);
    if (trim((string)($_POST['smtp_pass'] ?? '')) !== '') $save['smtp_pass'] = (string)$_POST['smtp_pass'];
    if (!empty($_POST['smtp_clear_pass'])) $save['smtp_pass'] = '';
    $save['smtp_from'] = filter_var(trim((string)($_POST['smtp_from'] ?? '')), FILTER_VALIDATE_EMAIL) ?: '';
    $save['smtp_from_name'] = $str('smtp_from_name', 100);
    foreach (['facebook', 'twitter', 'instagram', 'whatsapp', 'telegram', 'linkedin', 'threads', 'youtube'] as $s) {
        $save['social_' . $s] = $url('social_' . $s);
    }
    edu_settings_save('general', $save);
    admin_log('settings_update', 'Updated General Settings');
    admin_flash('Settings saved.');
    header('Location: /admin/general-settings.php');
    exit;
}

$v = fn($k, $d = '') => htmlspecialchars((string)($g[$k] ?? $d), ENT_QUOTES, 'UTF-8');
$host = preg_replace('#^https?://#', '', rtrim(SITE_URL, '/'));
$social = edu_social();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>General Settings - <?= htmlspecialchars(edu_site_name()) ?></title>
<?php admin_shell_head(); ?>
<script src="/assets/js/admin-media.js?v=<?= @filemtime(ROOT_PATH . '/assets/js/admin-media.js') ?>" defer></script>
<style>
/* like Reports / Store Settings: a list of sections on the left, one section at a time on the right */
.gs-wrap{display:grid;grid-template-columns:250px minmax(0,1fr);gap:18px;align-items:start;max-width:none;background:none;border:0;border-radius:0;padding:0}
.gs-wrap::before,.gs-wrap::after{content:none!important;display:none!important}
.gs-nav,.gs-main{grid-row:1}.gs-nav{grid-column:1}.gs-main{grid-column:2}
@media (max-width:820px){.gs-nav,.gs-main{grid-column:1;grid-row:auto}}
.gs-main{background:#fff;border:1px solid #e5e7eb;padding:.25rem 1.75rem 1.5rem}
@media (max-width:782px){.gs-main{padding:.25rem 1rem 1.25rem}}
@media (max-width:820px){.gs-wrap{grid-template-columns:1fr}}
.gs-nav{display:flex;flex-direction:column;border:1px solid #e5e7eb;background:#fff}
@media (max-width:820px){.gs-nav{position:static}}
.gs-nav button{display:flex;align-items:center;gap:10px;padding:11px 14px;border:0;border-bottom:1px solid #e5e7eb;background:#fff;text-align:left;font:600 13.5px inherit;font-family:inherit;color:#374151;cursor:pointer}
.gs-nav button:last-child{border-bottom:0}
.gs-nav button i{width:18px;text-align:center}
.gs-nav button.on{background:var(--ci-acc,#4a3369);color:#fff}
.gs-nav button:not(.on):hover{background:#faf8fd;color:var(--ci-acc,#4a3369)}
.gs-main{min-width:0}
.gs-main .wps-section{margin-top:0}
.gs-main .wps-submit{margin-top:14px}
</style>
</head>
<body>
<?php admin_shell_open('General Settings', 'Site tagline, logo, site icon, contact details, social links &amp; more'); ?>
<?= admin_flash() ?>
<?php $gsTabs = ['identity' => ['fa-id-card', 'Site Identity'], 'contact' => ['fa-address-book', 'Address & Contact'], 'smtp' => ['fa-envelope', 'Email (SMTP)'],
  'social' => ['fa-share-nodes', 'Social Profiles'], 'seo' => ['fa-magnifying-glass', 'Search Engines'], 'footer' => ['fa-shoe-prints', 'Footer'], 'date' => ['fa-calendar-day', 'Date Format']]; ?>
<form class="wps-wrap gs-wrap" method="POST">
  <nav class="gs-nav" role="tablist" aria-label="Settings sections">
    <?php foreach ($gsTabs as $k => [$ic, $lb]): ?><button type="button" role="tab" data-tab="<?= $k ?>"><i class="fas <?= $ic ?>"></i> <?= htmlspecialchars($lb) ?></button><?php endforeach; ?>
  </nav>
  <div class="gs-main">
  <?= csrf_field() ?>
  <section class="wps-section" data-tab="identity">
    <h2>Site Identity</h2>
    <p class="wps-section-desc">How the site is named and recognised — in the header, browser tabs and search results.</p>
    <div class="wps-table">
      <div class="wps-row"><div class="wps-th"><label for="site_title">Site Title</label></div><div class="wps-td"><input id="site_title" name="site_title" class="wps-input" maxlength="100" placeholder="<?= htmlspecialchars(SITE_NAME) ?>" value="<?= htmlspecialchars((string)($g['site_title'] ?? '') !== '' ? (string)$g['site_title'] : edu_site_name()) ?>"><p class="wps-desc">Shown in the header and every page title. Also in Customize → Site Identity.</p></div></div>
      <div class="wps-row"><div class="wps-th"><label for="tagline">Tagline</label></div><div class="wps-td"><input id="tagline" name="tagline" class="wps-input" placeholder="Latest government jobs, results and admit cards" value="<?= $v('tagline') ?>"><p class="wps-desc">In a few words, explain what this site is about. Example: “Latest government jobs, results and admit cards.”</p></div></div>
      <div class="wps-row"><div class="wps-th"><span>Site Logo</span></div><div class="wps-td">
        <div class="wps-media<?= empty($g['logo']) ? ' empty' : '' ?>" id="logo-prev"><?= !empty($g['logo']) ? '<img src="' . $v('logo') . '" alt="">' : '<span>No logo selected</span>' ?></div>
        <div class="wps-media-actions"><button type="button" class="wps-secondary" data-pick="logo">Media Library</button><button type="button" class="wps-secondary" data-clear="logo">Remove</button></div>
        <input type="hidden" name="logo" id="logo" value="<?= $v('logo') ?>">
        <p class="wps-desc">Best: a transparent PNG or SVG, at least 300px wide.</p></div></div>
      <div class="wps-row"><div class="wps-th"><span>Logo Width</span></div><div class="wps-td"><div class="wps-inline"><label class="wps-mini"><input type="number" class="wps-input small" min="20" max="600" name="logo_w" value="<?= (int)edu_logo_size()[0] ?>">px</label></div><p class="wps-desc">Width of the logo in the site header; the height follows the logo’s own shape.</p></div></div>
      <div class="wps-row"><div class="wps-th"><span>Header</span></div><div class="wps-td"><label class="wps-check"><input type="checkbox" name="header_logo" value="1" <?= ($g['header_logo'] ?? '1') === '1' ? 'checked' : '' ?>>Show the uploaded logo in the header (instead of the built-in logo)</label></div></div>
      <div class="wps-row"><div class="wps-th"><span>Site Icon</span></div><div class="wps-td">
        <div class="wps-icon-row"><div class="wps-icon<?= empty($g['favicon']) ? ' empty' : '' ?>" id="favicon-prev"><?= !empty($g['favicon']) ? '<img src="' . $v('favicon') . '" alt="">' : '<i class="fas fa-image"></i>' ?></div></div>
        <div class="wps-media-actions"><button type="button" class="wps-secondary" data-pick="favicon">Media Library</button><button type="button" class="wps-secondary" data-clear="favicon">Remove</button></div>
        <input type="hidden" name="favicon" id="favicon" value="<?= $v('favicon') ?>">
        <p class="wps-desc">The icon in browser tabs and bookmarks. Square PNG, ICO or SVG, at least 512 × 512 pixels. Empty = the current favicon.ico.</p></div></div>
    </div>
  </section>

  <section class="wps-section" data-tab="contact">
    <h2>Site Address &amp; Contact</h2>
    <div class="wps-table">
      <div class="wps-row"><div class="wps-th"><span>Site Address (URL)</span></div><div class="wps-td"><input class="wps-input code" value="<?= htmlspecialchars(SITE_URL) ?>" disabled><p class="wps-desc">Set in the site configuration. Used in links, sitemaps and sharing.</p></div></div>
      <div class="wps-row"><div class="wps-th"><label for="admin_email">Administration Email</label></div><div class="wps-td"><input id="admin_email" type="email" name="admin_email" class="wps-input" value="<?= $v('admin_email') ?>"><p class="wps-desc">Used for admin notifications, like new contact messages.</p></div></div>
      <div class="wps-row"><div class="wps-th"><label for="contact_email">Contact Email</label></div><div class="wps-td"><input id="contact_email" type="email" name="contact_email" class="wps-input" placeholder="<?= htmlspecialchars(CONTACT_EMAIL) ?>" value="<?= $v('contact_email') ?>"><p class="wps-desc">Shown to visitors. Empty = <?= htmlspecialchars(CONTACT_EMAIL) ?>.</p></div></div>
      <div class="wps-row"><div class="wps-th"><label for="phone">Phone</label></div><div class="wps-td"><input id="phone" name="phone" class="wps-input" placeholder="<?= htmlspecialchars(PHONE_NUMBER) ?>" value="<?= $v('phone') ?>"></div></div>
      <div class="wps-row"><div class="wps-th"><label for="address">Address</label></div><div class="wps-td"><input id="address" name="address" class="wps-input" placeholder="<?= htmlspecialchars(COMPANY_ADDRESS) ?>" value="<?= $v('address') ?>"></div></div>
    </div>
  </section>

  <section class="wps-section" data-tab="smtp">
    <h2>Email (SMTP)</h2>
    <p class="wps-section-desc">Used to email you new contact-form messages. Get these details from your email/hosting provider. Without them, messages are still saved in Contact Messages.</p>
    <div class="wps-table">
      <div class="wps-row"><div class="wps-th"><label for="smtp_host">SMTP Host</label></div><div class="wps-td"><input id="smtp_host" name="smtp_host" class="wps-input" placeholder="mail.<?= htmlspecialchars(preg_replace('/^www\./', '', (string)parse_url(SITE_URL, PHP_URL_HOST))) ?>" value="<?= $v('smtp_host') ?>"></div></div>
      <div class="wps-row"><div class="wps-th"><span>Port &amp; Security</span></div><div class="wps-td"><div class="wps-inline"><label class="wps-mini">Port<input type="number" name="smtp_port" class="wps-input small" value="<?= $v('smtp_port', 465) ?>"></label><label class="wps-mini">Security<select name="smtp_secure" class="wps-input small"><?php foreach (['ssl' => 'SSL (465)', 'tls' => 'TLS (587)', 'none' => 'None'] as $k => $l): ?><option value="<?= $k ?>" <?= ($g['smtp_secure'] ?? 'ssl') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label></div></div></div>
      <div class="wps-row"><div class="wps-th"><label for="smtp_user">Username</label></div><div class="wps-td"><input id="smtp_user" name="smtp_user" class="wps-input" autocomplete="off" placeholder="no-reply@<?= htmlspecialchars(preg_replace('/^www\./', '', (string)parse_url(SITE_URL, PHP_URL_HOST))) ?>" value="<?= $v('smtp_user') ?>"></div></div>
      <div class="wps-row"><div class="wps-th"><label for="smtp_pass">Password</label></div><div class="wps-td"><input id="smtp_pass" type="password" name="smtp_pass" class="wps-input" autocomplete="new-password" placeholder="<?= !empty($g['smtp_pass']) ? '•••••••• saved — leave empty to keep' : 'SMTP password' ?>"><?php if (!empty($g['smtp_pass'])): ?><label class="wps-check" style="margin-top:6px"><input type="checkbox" name="smtp_clear_pass" value="1">Remove the saved password</label><?php endif; ?></div></div>
      <div class="wps-row"><div class="wps-th"><label for="smtp_from">From Email / Name</label></div><div class="wps-td"><div class="wps-inline"><input id="smtp_from" type="email" name="smtp_from" class="wps-input" placeholder="Same as username" value="<?= $v('smtp_from') ?>"><input name="smtp_from_name" class="wps-input" placeholder="<?= htmlspecialchars(edu_site_name()) ?>" value="<?= $v('smtp_from_name') ?>"></div><p class="wps-desc">New messages are sent to the Administration Email above.</p></div></div>
    </div>
  </section>

  <section class="wps-section" data-tab="social">
    <h2>Social Profiles</h2>
    <p class="wps-section-desc">Shown in the header, the mobile menu and the footer, and added to Google's organisation data. Leave empty to hide.</p>
    <div class="wps-table">
      <?php $ph = ['facebook' => 'https://www.facebook.com/yourpage', 'twitter' => 'https://x.com/yourname', 'instagram' => 'https://www.instagram.com/yourname', 'whatsapp' => 'https://whatsapp.com/channel/…', 'telegram' => 'https://t.me/yourchannel', 'linkedin' => 'https://www.linkedin.com/company/yourname', 'threads' => 'https://www.threads.net/@yourname', 'youtube' => 'https://www.youtube.com/@yourchannel']; ?>
      <?php foreach (['facebook' => 'Facebook', 'twitter' => 'X (Twitter)', 'instagram' => 'Instagram', 'whatsapp' => 'WhatsApp channel', 'telegram' => 'Telegram', 'linkedin' => 'LinkedIn', 'threads' => 'Threads', 'youtube' => 'YouTube'] as $k => $l): ?>
      <div class="wps-row"><div class="wps-th"><label for="s-<?= $k ?>"><?= $l ?></label></div><div class="wps-td"><input id="s-<?= $k ?>" type="url" name="social_<?= $k ?>" class="wps-input" placeholder="<?= htmlspecialchars($ph[$k]) ?>" value="<?= htmlspecialchars($social[$k]) ?>"></div></div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="wps-section" data-tab="seo">
    <h2>Search Engines</h2>
    <p class="wps-section-desc">What Google and other search engines show for the home page.</p>
    <div class="wps-table">
      <div class="wps-row"><div class="wps-th"><label for="home_description">Meta Description</label></div><div class="wps-td">
        <textarea id="home_description" name="home_description" class="wps-input large" rows="3" placeholder="Example: Latest Sarkari Naukri, government job notifications, admit cards and results — updated daily."><?= $v('home_description') ?></textarea>
        <p class="wps-desc"><span class="wps-count" id="desc-count">0 characters</span> — 120 to 160 works best. Empty = the built-in homepage description. A description set in <a href="/admin/seo.php?view=titles&amp;tab=homepage">SEO → Titles &amp; Meta → Homepage</a> is used first.</p>
        <div class="wps-serp"><span class="wps-serp-url"><?= htmlspecialchars($host) ?></span><span class="wps-serp-title"><?= htmlspecialchars(edu_site_name()) ?></span><span class="wps-serp-text" id="serp-text">Your meta description will appear here.</span></div>
      </div></div>
      <div class="wps-row"><div class="wps-th"><label for="home_keywords">Meta Keywords</label></div><div class="wps-td"><input id="home_keywords" name="home_keywords" class="wps-input" placeholder="sarkari naukri, govt jobs, admit card, result" value="<?= $v('home_keywords') ?>"><p class="wps-desc">Comma separated. Optional — most search engines ignore it.</p></div></div>
    </div>
  </section>

  <section class="wps-section" data-tab="footer">
    <h2>Footer</h2>
    <p class="wps-section-desc">Homepage sections, menus and colors are set in <a href="/admin/customize.php">Customize</a>.</p>
    <div class="wps-table">
      <div class="wps-row"><div class="wps-th"><label for="footer_tagline">Footer Text</label></div><div class="wps-td"><textarea id="footer_tagline" name="footer_tagline" class="wps-input large" rows="2" placeholder="<?= htmlspecialchars(SITE_FOOTER_TAGLINE) ?>"><?= $v('footer_tagline') ?></textarea></div></div>
    </div>
  </section>

  <section class="wps-section" data-tab="date">
    <h2>Date Format</h2>
    <div class="wps-table">
      <div class="wps-row"><div class="wps-th"><span>Date Format</span></div><div class="wps-td">
        <?php foreach (['j F Y', 'M j, Y', 'Y-m-d', 'd/m/Y', 'm/d/Y'] as $f): ?>
        <label class="wps-radio"><input type="radio" name="date_format" value="<?= $f ?>" <?= ($g['date_format'] ?? 'j F Y') === $f ? 'checked' : '' ?>><span class="wps-radio-label"><?= date($f) ?></span><code><?= $f ?></code></label>
        <?php endforeach; ?>
        <p class="wps-desc">Used for post dates on the site. Times are shown in India time (Asia/Kolkata).</p>
      </div></div>
    </div>
  </section>

  <p class="wps-submit"><button type="submit" class="wps-primary">Save Changes</button></p>
  </div>
</form>
<?php admin_shell_close(); ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  function setImg(key, path) {
    document.getElementById(key).value = path || '';
    var p = document.getElementById(key + '-prev');
    p.classList.toggle('empty', !path);
    p.innerHTML = path ? '<img src="' + path + '" alt="">' : (key === 'logo' ? '<span>No logo selected</span>' : '<i class="fas fa-image"></i>');
  }
  document.querySelectorAll('[data-pick]').forEach(function (b) { b.addEventListener('click', function () { EduMedia.pick(function (it) { setImg(b.dataset.pick, it.path); }); }); });
  document.querySelectorAll('[data-clear]').forEach(function (b) { b.addEventListener('click', function () { setImg(b.dataset.clear, ''); }); });
  var d = document.getElementById('home_description');
  function count() { var n = d.value.length; var c = document.getElementById('desc-count'); c.textContent = n + ' characters'; c.className = 'wps-count ' + (n === 0 ? '' : n < 120 || n > 160 ? 'warn' : 'good'); document.getElementById('serp-text').textContent = d.value || 'Your meta description will appear here.'; }
  d.addEventListener('input', count); count();

  // section list on the left: one section at a time (all of them are still saved together)
  var nav = document.querySelector('.gs-nav'), secs = [].slice.call(document.querySelectorAll('.gs-main > .wps-section'));
  function show(id) {
    secs.forEach(function (s) { s.hidden = s.dataset.tab !== id; });
    [].forEach.call(nav.children, function (b) { b.classList.toggle('on', b.dataset.tab === id); b.setAttribute('aria-selected', b.dataset.tab === id); });
    try { history.replaceState(null, '', '#' + id); } catch (e) {}
  }
  nav.addEventListener('click', function (e) { var b = e.target.closest('[data-tab]'); if (b) { show(b.dataset.tab); if (window.innerWidth <= 820) document.querySelector('.gs-main').scrollIntoView({ behavior: 'smooth', block: 'start' }); } });
  var old = { 'site-identity': 'identity', 'site-address-and-contact': 'contact', 'email-smtp': 'smtp', 'social-profiles': 'social', 'search-engines': 'seo', 'footer': 'footer', 'date-format': 'date' }; // earlier #links
  var start = (location.hash || '').slice(1); start = old[start] || start;
  show(secs.some(function (s) { return s.dataset.tab === start; }) ? start : secs[0].dataset.tab);
  window.addEventListener('hashchange', function () { var h = location.hash.slice(1); h = old[h] || h; if (secs.some(function (s) { return s.dataset.tab === h; })) show(h); });
  // a field that fails validation in a hidden tab: open that tab so the browser can point at it
  document.querySelector('.gs-wrap').addEventListener('invalid', function (e) { var s = e.target.closest('.wps-section'); if (s && s.hidden) show(s.dataset.tab); }, true);
});
</script>
</body>
</html>
