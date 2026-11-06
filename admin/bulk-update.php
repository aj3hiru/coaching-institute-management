<?php
// Bulk Update — give posts or pages fresh publish / modified dates, spaced out as you choose (same idea as the CMS).
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/pages.php';
$me = admin_guard(['admin', 'editor']);
edu_pages_table($pdo);

$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $mode = $_POST['mode'] ?? 'single';
    $ids = [];
    $isPages = $mode === 'pages';
    if ($mode === 'single') $ids = array_map('intval', (array)($_POST['posts'] ?? []));
    elseif ($mode === 'category') {
        $c = array_map('intval', (array)($_POST['cats'] ?? [])) ?: [0];
        $ids = $pdo->query("SELECT DISTINCT p.id FROM posts p LEFT JOIN post_categories pc ON pc.post_id = p.id WHERE p.status = 'published' AND (p.category_id IN (" . implode(',', $c) . ") OR pc.category_id IN (" . implode(',', $c) . "))")->fetchAll(PDO::FETCH_COLUMN);
    } elseif ($mode === 'tag') {
        $t = array_map('intval', (array)($_POST['tags'] ?? [])) ?: [0];
        $ids = $pdo->query("SELECT DISTINCT p.id FROM posts p JOIN post_tag pt ON pt.post_id = p.id WHERE p.status = 'published' AND pt.tag_id IN (" . implode(',', $t) . ")")->fetchAll(PDO::FETCH_COLUMN);
    } elseif ($mode === 'all') $ids = $pdo->query("SELECT id FROM posts WHERE status = 'published'")->fetchAll(PDO::FETCH_COLUMN);
    elseif ($isPages) $ids = array_map('intval', (array)($_POST['pages'] ?? []));
    $ids = array_values(array_unique(array_map('intval', $ids)));

    if (!$ids) {
        admin_flash('Nothing selected — choose at least one post or page.', 'error');
        header('Location: /admin/bulk-update.php');
        exit;
    }
    $order = $_POST['order'] ?? 'newest';
    $table = $isPages ? 'site_pages' : 'posts';
    $dateCol = $isPages ? 'created_at' : 'date';
    $rows = $pdo->query("SELECT id, $dateCol AS d FROM $table WHERE id IN (" . implode(',', $ids) . ")")->fetchAll(PDO::FETCH_ASSOC);
    usort($rows, fn($a, $b) => $order === 'oldest' ? strcmp($a['d'], $b['d']) : strcmp($b['d'], $a['d']));
    if ($order === 'random') shuffle($rows);
    $start = ($_POST['start'] ?? 'now') === 'custom' && strtotime((string)($_POST['start_at'] ?? '')) ? strtotime((string)$_POST['start_at']) : time();
    $gap = max(0, min(1440, (int)($_POST['gap'] ?? 0))) * 60;
    $direction = ($_POST['direction'] ?? 'back') === 'forward' ? 1 : -1; // back = start time is the newest
    $which = ($_POST['which'] ?? 'both') === 'modified' ? 'modified' : 'both';
    $upd = $isPages
        ? $pdo->prepare($which === 'both' ? "UPDATE site_pages SET created_at = ?, updated_at = ? WHERE id = ?" : "UPDATE site_pages SET updated_at = ? WHERE id = ?")
        : $pdo->prepare($which === 'both' ? "UPDATE posts SET date = ?, updated_at = ? WHERE id = ?" : "UPDATE posts SET updated_at = ? WHERE id = ?");
    $first = $last = null;
    foreach ($rows as $i => $r) {
        $ts = date('Y-m-d H:i:s', $start + $direction * $i * $gap);
        $first ??= $ts; $last = $ts;
        $which === 'both' ? $upd->execute([$ts, $ts, $r['id']]) : $upd->execute([$ts, $r['id']]);
        if (!$isPages) @unlink(ROOT_PATH . '/cache/post_' . (int)$r['id'] . '.html');
    }
    edu_clear_page_cache();
    admin_log('bulk_update', 'Bulk-updated dates of ' . count($rows) . ($isPages ? ' pages' : ' posts'));
    admin_flash(count($rows) . ($isPages ? ' page' : ' post') . (count($rows) === 1 ? '' : 's') . ' updated — dates from ' . date('j M Y, g:i a', strtotime(min($first, $last))) . ' to ' . date('j M Y, g:i a', strtotime(max($first, $last))) . '. The sitemap and RSS feed show the new dates right away.');
    header('Location: /admin/bulk-update.php');
    exit;
}
$posts = $pdo->query("SELECT id, title, date FROM posts WHERE status = 'published' ORDER BY date DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
$cats = $pdo->query("SELECT c.id, c.name, COUNT(p.id) n FROM categories c LEFT JOIN posts p ON p.category_id = c.id AND p.status = 'published' GROUP BY c.id ORDER BY c.name")->fetchAll(PDO::FETCH_ASSOC);
$tags = $pdo->query("SELECT t.id, t.name, COUNT(pt.post_id) n FROM tags t LEFT JOIN post_tag pt ON pt.tag_id = t.id GROUP BY t.id HAVING n > 0 ORDER BY t.name")->fetchAll(PDO::FETCH_ASSOC);
$pages = $pdo->query("SELECT id, title, updated_at FROM site_pages ORDER BY title")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Bulk Update - <?= htmlspecialchars(edu_site_name()) ?></title>
<?php admin_shell_head(); ?>
<style>.bu-wrap{max-width:900px}.bu-intro{color:var(--gray-500,#6b7280);margin:0 0 16px}.bu-steps{display:flex;gap:10px;margin:0 0 16px}.bu-steps span{display:flex;align-items:center;gap:8px;padding:6px 14px;border-radius:99px;background:#f1f5f9;color:#64748b;font-weight:600;font-size:.85rem}.bu-steps span b{width:22px;height:22px;border-radius:50%;display:grid;place-items:center;background:#fff;font-size:.75rem}.bu-steps span.on{background:var(--primary);color:#fff}.bu-steps span.on b{color:var(--primary)}.bu-card{background:#fff;border:1px solid var(--gray-200,#e5e7eb);border-radius:14px;padding:20px}.bu-step{display:none}.bu-step.on{display:block}.bu-modes{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px;margin:0 0 16px}.bu-mode{display:block;padding:12px;border:1.5px solid #e5e7eb;border-radius:10px;cursor:pointer}.bu-mode input{display:none}.bu-mode strong{display:block}.bu-mode small{color:#6b7280}.bu-mode:has(input:checked){border-color:var(--primary);background:var(--primary-light,#ede9fe)}.bu-list{max-height:340px;overflow:auto;border:1px solid #e5e7eb;border-radius:10px}.bu-list label{display:flex;gap:10px;align-items:center;padding:9px 12px;border-bottom:1px solid #f1f5f9;font-size:.9rem}.bu-list label small{margin-left:auto;color:#9ca3af;white-space:nowrap}.bu-pick{display:none}.bu-pick.on{display:block}.bu-row{display:grid;grid-template-columns:200px 1fr;gap:12px;align-items:center;padding:12px 0;border-bottom:1px solid #f1f5f9}.bu-row select,.bu-row input[type=number],.bu-row input[type=datetime-local]{padding:8px 10px;border:1px solid #d1d5db;border-radius:8px}.bu-nav{display:flex;justify-content:space-between;margin-top:16px}.bu-sum{padding:14px;border-radius:10px;background:#f8fafc;font-size:.92rem;line-height:1.6}@media (max-width:640px){.bu-row{grid-template-columns:1fr}}</style>
</head>
<body>
<?php admin_shell_open('Bulk Update', 'Fresh publish &amp; modified dates for many posts at once'); ?>
<?= admin_flash() ?>
<div class="bu-wrap">
  <p class="bu-intro">Give posts and pages fresh publish &amp; modified dates — spaced out the way you choose. Google sees the new dates in the sitemap and the RSS feed right away.</p>
  <div class="bu-steps"><span class="on" data-s="1"><b>1</b> Select</span><span data-s="2"><b>2</b> Schedule</span><span data-s="3"><b>3</b> Update</span></div>
  <form class="bu-card" method="POST" id="bu-form"><?= csrf_field() ?>
    <div class="bu-step on" data-step="1">
      <h3 style="margin:0 0 12px">What do you want to update?</h3>
      <div class="bu-modes">
        <?php foreach (['single' => ['Single posts', 'Pick one or more posts'], 'category' => ['By category', 'Every post in the chosen categories'], 'tag' => ['By tag', 'Every post with the chosen tags'], 'all' => ['All posts', 'Every published post'], 'pages' => ['Pages', 'Pages made in Admin → Pages']] as $k => [$l, $s]): ?>
        <label class="bu-mode"><input type="radio" name="mode" value="<?= $k ?>" <?= $k === 'single' ? 'checked' : '' ?>><strong><?= $l ?></strong><small><?= $s ?></small></label>
        <?php endforeach; ?>
      </div>
      <div class="bu-pick on" data-pick="single"><div class="bu-list"><?php foreach ($posts as $p): ?><label><input type="checkbox" name="posts[]" value="<?= $p['id'] ?>"> <?= htmlspecialchars($p['title']) ?><small><?= date('d M Y, g:i a', strtotime($p['date'])) ?></small></label><?php endforeach; ?><?php if (!$posts): ?><p style="padding:12px;color:#9ca3af">No published posts.</p><?php endif; ?></div></div>
      <div class="bu-pick" data-pick="category"><div class="bu-list"><?php foreach ($cats as $c): ?><label><input type="checkbox" name="cats[]" value="<?= $c['id'] ?>"> <?= htmlspecialchars($c['name']) ?><small><?= (int)$c['n'] ?> posts</small></label><?php endforeach; ?></div></div>
      <div class="bu-pick" data-pick="tag"><div class="bu-list"><?php foreach ($tags as $t): ?><label><input type="checkbox" name="tags[]" value="<?= $t['id'] ?>"> <?= htmlspecialchars($t['name']) ?><small><?= (int)$t['n'] ?> posts</small></label><?php endforeach; ?><?php if (!$tags): ?><p style="padding:12px;color:#9ca3af">No tags in use.</p><?php endif; ?></div></div>
      <div class="bu-pick" data-pick="all"><p class="bu-sum">All <?= count($posts) ?> published posts will be updated.</p></div>
      <div class="bu-pick" data-pick="pages"><div class="bu-list"><?php foreach ($pages as $p): ?><label><input type="checkbox" name="pages[]" value="<?= $p['id'] ?>"> <?= htmlspecialchars($p['title']) ?><small><?= date('d M Y', strtotime($p['updated_at'])) ?></small></label><?php endforeach; ?><?php if (!$pages): ?><p style="padding:12px;color:#9ca3af">No pages yet — create them in Pages.</p><?php endif; ?></div></div>
      <div class="bu-nav"><span></span><button type="button" class="btn btn-primary" data-go="2">Next</button></div>
    </div>
    <div class="bu-step" data-step="2">
      <h3 style="margin:0 0 6px">Schedule</h3>
      <div class="bu-row"><strong>Start at</strong><div><label><input type="radio" name="start" value="now" checked> Now</label> &nbsp; <label><input type="radio" name="start" value="custom"> Custom</label> <input type="datetime-local" name="start_at" value="<?= date('Y-m-d\TH:i') ?>"></div></div>
      <div class="bu-row"><strong>Space them out</strong><div><input type="number" name="gap" min="0" max="1440" value="10"> minutes apart <small style="color:#9ca3af">(0 = all the same time)</small></div></div>
      <div class="bu-row"><strong>Direction</strong><select name="direction"><option value="back">Going back in time from the start (start = newest)</option><option value="forward">Going forward from the start</option></select></div>
      <div class="bu-row"><strong>Order</strong><select name="order"><option value="newest">Keep newest first</option><option value="oldest">Oldest first</option><option value="random">Random</option></select></div>
      <div class="bu-row"><strong>Which dates</strong><select name="which"><option value="both">Publish and modified date</option><option value="modified">Only the modified date</option></select></div>
      <div class="bu-nav"><button type="button" class="btn btn-secondary" data-go="1">Back</button><button type="button" class="btn btn-primary" data-go="3">Next</button></div>
    </div>
    <div class="bu-step" data-step="3">
      <h3 style="margin:0 0 10px">Ready to update</h3>
      <div class="bu-sum" id="bu-sum"></div>
      <div class="bu-nav"><button type="button" class="btn btn-secondary" data-go="2">Back</button><button type="submit" class="btn btn-primary"><i class="fas fa-clock-rotate-left"></i> Update now</button></div>
    </div>
  </form>
</div>
<?php admin_shell_close(); ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var form = document.getElementById('bu-form'), total = <?= count($posts) ?>;
  function mode() { return form.querySelector('[name=mode]:checked').value; }
  form.querySelectorAll('[name=mode]').forEach(function (r) { r.onchange = function () { document.querySelectorAll('.bu-pick').forEach(function (p) { p.classList.toggle('on', p.dataset.pick === mode()); }); }; });
  function count() { var m = mode(); if (m === 'all') return total + ' published posts'; var name = { single: 'posts[]', category: 'cats[]', tag: 'tags[]', pages: 'pages[]' }[m]; var n = form.querySelectorAll('[name="' + name + '"]:checked').length; return n + ' ' + ({ single: 'post', category: 'categor', tag: 'tag', pages: 'page' }[m]) + (m === 'category' ? (n === 1 ? 'y' : 'ies') : (n === 1 ? '' : 's')); }
  function go(s) {
    if (s > 1 && mode() !== 'all' && /^0 /.test(count())) { alert('Choose at least one item first.'); return; }
    document.querySelectorAll('.bu-step').forEach(function (x) { x.classList.toggle('on', +x.dataset.step === s); });
    document.querySelectorAll('.bu-steps span').forEach(function (x) { x.classList.toggle('on', +x.dataset.s <= s); });
    if (s === 3) {
      var start = form.querySelector('[name=start]:checked').value === 'now' ? 'now' : form.start_at.value.replace('T', ' ');
      document.getElementById('bu-sum').innerHTML = 'Updating <b>' + count() + '</b>.<br>Starting <b>' + start + '</b>, <b>' + form.gap.value + ' minutes</b> apart, ' + form.direction.options[form.direction.selectedIndex].text.toLowerCase() + '.<br>Order: ' + form.order.options[form.order.selectedIndex].text.toLowerCase() + ' · ' + form.which.options[form.which.selectedIndex].text.toLowerCase() + '.';
    }
  }
  form.querySelectorAll('[data-go]').forEach(function (b) { b.onclick = function () { go(+b.dataset.go); }; });
  form.addEventListener('submit', function (e) { if (!confirm('Change the dates now?')) e.preventDefault(); });
});
</script>
</body>
</html>
