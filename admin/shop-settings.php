<?php
// Online store → Settings (admins): store on/off, Razorpay keys + webhook, upload limits, private media folder.
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/institute.php';
require_once INCLUDES_PATH . '/shop.php';
$me = ci_page_start($pdo, ['shop']);
if ($me['role'] !== 'admin') { http_response_code(403); exit('Access Denied'); }
sh_tables($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a = (string)($_POST['action'] ?? 'save');
    if ($a === 'test') {
        $k = sh_rzp_keys();
        if ($k['id'] === '' || $k['secret'] === '') admin_flash('Save the Key Id and Key Secret first.', 'error');
        else {
            $ch = curl_init('https://api.razorpay.com/v1/orders?count=1');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_USERPWD => $k['id'] . ':' . $k['secret']]);
            $r = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
            if ($code === 200) admin_flash('Razorpay connected (' . (str_starts_with($k['id'], 'rzp_test_') ? 'TEST mode — no real money' : 'LIVE mode — real payments') . ').');
            elseif ($code === 401) admin_flash('Razorpay says the keys are wrong. Copy them again from Razorpay → Account & Settings → API Keys.', 'error');
            else admin_flash('Could not reach Razorpay (' . ($err ?: 'HTTP ' . $code) . ').', 'error');
        }
    } else {
        $id = trim((string)($_POST['rzp_key_id'] ?? ''));
        if ($id !== '' && !preg_match('/^rzp_(test|live)_[A-Za-z0-9]{8,40}$/', $id)) { admin_flash('The Key Id looks wrong — it starts with rzp_test_ or rzp_live_.', 'error'); header('Location: /admin/shop-settings.php'); exit; }
        $save = ['enabled' => !empty($_POST['enabled']) ? '1' : '0', 'rzp_key_id' => $id, 'max_video_mb' => (string)max(50, min(20480, (int)($_POST['max_video_mb'] ?? 4096)))];
        foreach (['rzp_key_secret', 'rzp_webhook_secret'] as $f) { $v = trim((string)($_POST[$f] ?? '')); if ($v !== '') $save[$f] = $v; if (!empty($_POST['clear_' . $f])) $save[$f] = ''; } // empty = keep the saved one
        edu_settings_save('shop', $save);
        admin_log('shop_settings', 'Updated online store settings');
        admin_flash('Saved.');
    }
    header('Location: /admin/shop-settings.php');
    exit;
}
$k = sh_rzp_keys();
$dir = sh_media_dir();
$writable = is_dir($dir) && is_writable($dir);
$free = @disk_free_space($dir);
$hook = rtrim(SITE_URL, '/') . '/store/webhook';
$mask = fn($s) => $s === '' ? '' : str_repeat('•', 8) . substr($s, -4);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Store Settings - <?= ci_e(edu_site_name()) ?></title>
<?php admin_shell_head(); ci_head(); ?>
</head>
<body>
<?php admin_shell_open('Store Settings', 'Online payments, uploads and the private media folder'); ?>
<div class="ci">
<?= admin_flash() ?>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save">
<div class="ci-card"><div class="ci-card-h"><h3>Store</h3></div><div class="ci-card-b">
  <label style="display:flex;gap:8px;align-items:center;font-weight:600"><input type="checkbox" name="enabled" value="1" <?= sh_on() ? 'checked' : '' ?>> Store is open (<a href="/store" target="_blank">/store</a>, <a href="/account/" target="_blank">/account</a>)</label>
  <p class="ci-muted" style="margin:6px 0 0">Closing it hides the store pages; buyers' access is kept for when it opens again. Add "/store" to your site menu in Customize → Menus.</p>
</div></div>

<div class="ci-card"><div class="ci-card-h"><h3>Razorpay</h3><?= sh_rzp_ready() ? (str_starts_with($k['id'], 'rzp_test_') ? '<span class="ci-badge ci-b-amber">Test mode</span>' : '<span class="ci-badge ci-b-green">Live</span>') : '<span class="ci-badge ci-b-red">Not set up</span>' ?></div><div class="ci-card-b">
  <p class="ci-muted" style="margin:0 0 12px">Razorpay Dashboard → Account &amp; Settings → API Keys. Use <b>Test</b> keys first (no real money), then the <b>Live</b> keys after your account is activated.</p>
  <div class="ci-grid3">
    <div class="ci-f"><label>Key Id</label><input name="rzp_key_id" value="<?= ci_e($k['id']) ?>" placeholder="rzp_test_… or rzp_live_…" autocomplete="off"></div>
    <div class="ci-f"><label>Key Secret</label><input name="rzp_key_secret" type="password" placeholder="<?= $k['secret'] !== '' ? ci_e('saved ' . $mask($k['secret']) . ' — type to change') : 'paste the secret' ?>" autocomplete="new-password"><?php if ($k['secret'] !== ''): ?><label class="ci-hint"><input type="checkbox" name="clear_rzp_key_secret" value="1"> remove</label><?php endif; ?></div>
    <div class="ci-f"><label>Webhook Secret</label><input name="rzp_webhook_secret" type="password" placeholder="<?= $k['hook'] !== '' ? ci_e('saved ' . $mask($k['hook']) . ' — type to change') : 'your own secret word' ?>" autocomplete="new-password"><?php if ($k['hook'] !== ''): ?><label class="ci-hint"><input type="checkbox" name="clear_rzp_webhook_secret" value="1"> remove</label><?php endif; ?></div>
  </div>
  <div class="ci-f" style="margin-top:12px"><label>Webhook address (Razorpay → Webhooks → Add)</label><input value="<?= ci_e($hook) ?>" readonly onclick="this.select()"><span class="ci-hint">Events: <b>payment.captured</b>, <b>order.paid</b>, <b>payment.failed</b>. Use the same secret as above. With this, a buyer gets access even if their phone closes the page right after paying.</span></div>
</div></div>

<div class="ci-card"><div class="ci-card-h"><h3>Uploads &amp; storage</h3></div><div class="ci-card-b">
  <div class="ci-grid3">
    <div class="ci-f"><label>Largest video (MB)</label><input name="max_video_mb" type="number" min="50" max="20480" value="<?= (int)es('shop', 'max_video_mb', 4096) ?>"></div>
    <div class="ci-f" style="grid-column:span 2"><label>Private media folder</label><input value="<?= ci_e($dir) ?>" readonly><span class="ci-hint"><?= $writable ? '✓ Ready' : '✗ Not writable — uploads will fail' ?><?= $free !== false ? ' · ' . round($free / 1073741824, 1) . ' GB free on the server' : '' ?>. Outside the website folder, so files can only be opened through the store.</span></div>
  </div>
</div></div>
<button class="ci-btn" type="submit"><i class="fas fa-check"></i> Save settings</button>
</form>
<form method="post" style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="action" value="test"><button class="ci-btn ci-ghost" type="submit"><i class="fas fa-plug"></i> Test Razorpay connection</button></form>
</div>
<?php admin_shell_close(); ?>
</body>
</html>
