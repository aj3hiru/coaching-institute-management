<?php
// Report us: logged-in students send a message (name filled in) with an optional image / PDF to the institute.
require_once dirname(__DIR__) . '/includes/config.php';
require_once INCLUDES_PATH . '/shop.php';
$pdo = sh_db();
$u = sh_need_login();
$topics = ['payment' => 'Payment or order', 'course' => 'Course or video', 'notes' => 'PDF notes', 'account' => 'My account / login', 'other' => 'Something else'];
$err = ''; $sent = isset($_GET['sent']); $f = ['topic' => (string)($_GET['topic'] ?? 'other'), 'message' => ''];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $f['topic'] = isset($topics[$_POST['topic'] ?? '']) ? $_POST['topic'] : 'other';
    $f['message'] = trim(preg_replace("/\r\n?/", "\n", (string)($_POST['message'] ?? '')));
    $tok = (string)($_POST['file'] ?? '');
    try {
        if (!sh_csrf_ok()) throw new RuntimeException('This page had expired. Please try again.');
        if (mb_strlen($f['message']) < 5) throw new RuntimeException('Please write your message.');
        if (mb_strlen($f['message']) > 5000) throw new RuntimeException('Please keep the message under 5000 characters.');
        $n = $pdo->prepare("SELECT COUNT(*) FROM sh_reports WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)"); $n->execute([$u['id']]);
        if ((int)$n->fetchColumn() >= 10) throw new RuntimeException('You have sent many messages today. Please wait until tomorrow, or call us.');
        $att = $tok !== '' ? ($_SESSION['sh_rep_up'][$tok] ?? null) : null;
        if ($tok !== '' && !$att) throw new RuntimeException('The attachment was not found — please attach it again.');
        $pdo->prepare("INSERT INTO sh_reports (user_id, name, topic, message, file, file_name, size) VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$u['id'], $u['name'], $f['topic'], $f['message'], $att['file'] ?? null, $att['name'] ?? null, (int)($att['size'] ?? 0)]);
        if ($tok !== '') unset($_SESSION['sh_rep_up'][$tok]);
        header('Location: /account/report?sent=1'); exit;
    } catch (RuntimeException $e) { $err = $e->getMessage(); }
}
$mine = $pdo->prepare("SELECT id, topic, message, status, note, created_at FROM sh_reports WHERE user_id = ? ORDER BY id DESC LIMIT 10");
$mine->execute([$u['id']]); $mine = $mine->fetchAll(PDO::FETCH_ASSOC);
$stTitle = 'Support';
require ROOT_PATH . '/components/store-open.php';
$ico = fn($n) => sh_icon($n);
?>
<div class="ac">
  <?= sh_account_nav($u, 'report') ?>
  <div class="ac-main">
    <div class="ac-h"><h1>Support</h1></div>
    <p class="ac-lead">Found a problem with a payment, a video or your account? Tell us — our team will look into it.</p>
    <?php if ($sent): ?><div class="st-msg ok">Thank you! Your message has been sent. We will get back to you soon.</div><?php endif; ?>
    <?php if ($err): ?><div class="st-msg err"><?= sh_e($err) ?></div><?php endif; ?>
    <div class="ac-box">
      <form method="post" class="ac-form" id="acRep" novalidate><?= sh_csrf_field() ?><input type="hidden" name="file" id="acRepFile" value="">
        <div class="ac-two">
          <div class="st-f"><label for="rp-n">Your name</label><input id="rp-n" value="<?= sh_e($u['name']) ?>" readonly></div>
          <div class="st-f"><label for="rp-t">About</label><select id="rp-t" name="topic" class="ac-sel"><?php foreach ($topics as $k => $l): ?><option value="<?= $k ?>" <?= $f['topic'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="st-f"><label for="rp-m">Message</label><textarea id="rp-m" name="message" rows="6" maxlength="5000" required placeholder="Tell us what happened. For a payment, add the payment id from Razorpay's SMS / email."><?= sh_e($f['message']) ?></textarea></div>
        <div class="st-f"><label>Attachment <span style="font-weight:400;color:#6b7280">(optional — image or PDF)</span></label>
          <label class="ac-drop" id="acDrop"><input type="file" id="acRepPick" accept="image/jpeg,image/png,image/webp,application/pdf,.pdf,.jpg,.jpeg,.png,.webp" hidden>
            <span class="ac-drop-i"><?= $ico('clip') ?></span><span class="ac-drop-t" id="acDropT">Add a screenshot or PDF</span><span class="ac-drop-x" id="acDropX" hidden>Remove</span></label>
          <div class="ac-upbar" id="acUpBar" hidden><i></i></div>
          <p class="ac-warn" id="acWarn" hidden></p>
        </div>
        <button class="ac-btn" type="submit" id="acRepGo">Send message</button>
      </form>
    </div>
    <?php if ($mine): ?>
    <h2 class="ac-sub">Your messages</h2>
    <div class="ac-orders"><?php foreach ($mine as $r): ?>
      <div class="ac-ord"><div class="ac-ord-i"><?= $ico('headset') ?></div><div class="ac-ord-t"><b><?= sh_e($topics[$r['topic']] ?? 'Support') ?></b><small><?= date('d M Y, h:i A', strtotime($r['created_at'])) ?> · <?= sh_e(mb_strimwidth($r['message'], 0, 90, '…')) ?></small><?= $r['note'] ? '<small class="ac-reply">Reply: ' . sh_e($r['note']) . '</small>' : '' ?></div>
        <div class="ac-ord-r"><span class="st-badge <?= $r['status'] === 'closed' ? 'paid' : 'created' ?>"><?= ['new' => 'Sent', 'seen' => 'Seen', 'closed' => 'Solved'][$r['status']] ?? 'Sent' ?></span></div></div>
    <?php endforeach; ?></div>
    <?php endif; ?>
  </div>
</div>
<script>
(function () {
  var MAX = 20 * 1024 * 1024, CH = 4 * 1024 * 1024;
  var pick = document.getElementById('acRepPick'), t = document.getElementById('acDropT'), x = document.getElementById('acDropX'), warn = document.getElementById('acWarn'),
      bar = document.getElementById('acUpBar'), hid = document.getElementById('acRepFile'), go = document.getElementById('acRepGo'), form = document.getElementById('acRep');
  var tok = <?= json_encode(sh_csrf(), JSON_HEX_TAG) ?>, busy = false;
  function say(m) { warn.textContent = m; warn.hidden = !m; }
  function reset() { pick.value = ''; hid.value = ''; t.textContent = 'Add a screenshot or PDF'; x.hidden = true; bar.hidden = true; bar.firstChild.style.width = '0'; }
  x.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); reset(); say(''); });
  pick.addEventListener('change', function () {
    var f = pick.files[0]; say(''); if (!f) return;
    if (!/\.(jpe?g|png|webp|pdf)$/i.test(f.name)) { reset(); say('Only images (JPG, PNG, WebP) and PDF files can be attached.'); return; }
    if (f.size > MAX) { reset(); say('This file is too big — please choose a file smaller than 20 MB.'); return; }
    var id = Array.prototype.map.call(crypto.getRandomValues(new Uint8Array(12)), function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
    var total = Math.max(1, Math.ceil(f.size / CH)); busy = true; go.disabled = true; bar.hidden = false; t.textContent = 'Uploading ' + f.name + '…';
    (function send(i) {
      fetch('/account/report-upload', { method: 'POST', credentials: 'same-origin', body: f.slice(i * CH, Math.min(f.size, (i + 1) * CH)),
        headers: { 'Content-Type': 'application/octet-stream', 'X-CSRF': tok, 'X-Upload': id, 'X-Index': String(i), 'X-Total': String(total), 'X-Size': String(f.size), 'X-Name': encodeURIComponent(f.name) } })
        .then(function (r) { return r.json(); }).then(function (d) {
          if (d.error) throw new Error(d.error);
          bar.firstChild.style.width = Math.round((i + 1) * 100 / total) + '%';
          if (d.done) { hid.value = d.token; t.textContent = f.name + ' · attached'; x.hidden = false; busy = false; go.disabled = false; return; }
          send(i + 1);
        }).catch(function (e) { busy = false; go.disabled = false; reset(); say(e.message || 'The file could not be attached. Please try again.'); });
    })(0);
  });
  form.addEventListener('submit', function (e) { if (busy) { e.preventDefault(); return; } if (document.getElementById('rp-m').value.trim().length < 5) { e.preventDefault(); say('Please write your message.'); document.getElementById('rp-m').focus(); } });
})();
</script>
<?php require ROOT_PATH . '/components/store-close.php'; ?>
