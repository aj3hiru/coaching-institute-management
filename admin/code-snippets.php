<?php
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/perf.php';
admin_guard(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $clip = fn($k) => substr((string)($_POST[$k] ?? ''), 0, 100000);
    edu_settings_save('snippets', ['head' => $clip('head'), 'body' => $clip('body'), 'footer' => $clip('footer')]);
    admin_log('snippets_update', 'Updated Code Snippets');
    admin_flash('Snippets saved. They are live on the site now.');
    header('Location: /admin/code-snippets.php');
    exit;
}
$sn = edu_settings('snippets');
$fields = [
    'head'   => ['Header (&lt;head&gt;)', 'Analytics tags, meta verification, custom &lt;style&gt;.', '<!-- e.g. Google Analytics, meta verification -->'],
    'body'   => ['Body (right after header)', 'Banners, consent widgets.', '<!-- e.g. a notice bar -->'],
    'footer' => ['Footer (end of page)', 'Chat widgets, deferred scripts.', '<!-- e.g. chat widget -->'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Code Snippets - <?= htmlspecialchars(edu_site_name()) ?></title>
<?php admin_shell_head(); ?>
</head>
<body>
<?php admin_shell_open('Code Snippets', 'Inject scripts into the head, body, and /body sections'); ?>
<?= admin_flash() ?>
<form method="POST" style="display:flex;flex-direction:column;gap:1.5rem">
  <?= csrf_field() ?>
  <?php foreach ($fields as $k => [$label, $hint, $ph]): ?>
  <div class="card" style="padding:1.25rem">
    <label for="sn-<?= $k ?>" style="font-weight:700;display:block;margin-bottom:.25rem"><?= $label ?></label>
    <p style="font-size:.8125rem;color:var(--gray-500);margin:0 0 .75rem"><?= $hint ?></p>
    <div class="sc-editor"><div class="sc-gutter"></div><textarea class="sc-textarea" name="<?= $k ?>" id="sn-<?= $k ?>" placeholder="<?= htmlspecialchars($ph) ?>" spellcheck="false" autocomplete="off"><?= htmlspecialchars((string)($sn[$k] ?? '')) ?></textarea></div>
  </div>
  <?php endforeach; ?>
  <p style="margin:0;font-size:.8rem;color:var(--gray-500)">When “Delay ads and tracking scripts” is on (<a href="/admin/performance-settings.php">Performance</a>), scripts here wait for the visitor’s first scroll or tap.</p>
  <div><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Snippets</button></div>
</form>
<?php admin_shell_close(); ?>
<script>
document.querySelectorAll('.sc-editor').forEach(function (ed) {
  var ta = ed.querySelector('textarea'), g = ed.querySelector('.sc-gutter');
  function lines() { var n = ta.value.split('\n').length, h = ''; for (var i = 1; i <= n; i++) h += '<span>' + i + '</span>'; g.innerHTML = h; g.scrollTop = ta.scrollTop; }
  ta.addEventListener('input', lines); ta.addEventListener('scroll', function () { g.scrollTop = ta.scrollTop; });
  ta.addEventListener('keydown', function (e) { if (e.key === 'Tab') { e.preventDefault(); var s = ta.selectionStart; ta.setRangeText('  ', s, ta.selectionEnd, 'end'); lines(); } });
  lines();
});
</script>
</body>
</html>
