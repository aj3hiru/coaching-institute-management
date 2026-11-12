<?php
// Free Courses: every course ticked "Show in Free Courses" (course form sidebar).  /free-courses/
// Old /free-courses/ID-name addresses (free YouTube playlists, now courses) go to their course.
require_once dirname(__DIR__) . '/includes/config.php';
require_once INCLUDES_PATH . '/shop.php';
$pdo = sh_db();
$id = (int)($_GET['id'] ?? 0);
if ($id) { // an old free playlist course: it is a course now
    $to = 0; try { $to = (int)$pdo->query("SELECT moved_to FROM hm_free WHERE id = $id")->fetchColumn(); } catch (Throwable) {}
    $tp = $to ? $pdo->query("SELECT * FROM sh_products WHERE id = $to")->fetch(PDO::FETCH_ASSOC) : null;
    header('Location: ' . ($tp ? sh_url($tp) : '/free-courses/'), true, 301); exit;
}
sh_redirect_clean('/free-courses/');
$all = sh_free_courses($pdo);
$f = null;
$stIndex = true;
$stTitle = $f ? $f['title'] : 'Free Courses';
$stDesc = $f ? 'Free course: ' . $f['title'] . ($f['videos'] ? ' — ' . ((int)$f['videos'] >= 100 ? '100+' : (int)$f['videos']) . ' video lessons' : '') . '. Watch free on ' . edu_site_name() . '.' : 'Free video courses from ' . edu_site_name() . ' — full playlists, free to watch.';
if ($f && $f['thumb']) $stImage = $f['thumb'];
require ROOT_PATH . '/components/store-open.php';
$ico = fn($n) => sh_icon($n);
?>
<p class="st-crumb"><a href="/">Home</a> › <a href="/free-courses/">Free Courses</a><?= $f ? ' › ' . sh_e($f['title']) : '' ?></p>
<?php if ($f): ?>
  <h1 class="fr-h"><?= sh_e($f['title']) ?></h1>
  <p class="st-lead"><?= $f['channel'] ? sh_e($f['channel']) . ' · ' : '' ?><?= $f['videos'] ? ((int)$f['videos'] >= 100 ? '100+' : (int)$f['videos']) . ' videos · ' : '' ?>Free — no account needed<?= sh_category_name($pdo, (int)$f['category_id']) !== '' ? ' · ' . sh_e(sh_category_name($pdo, (int)$f['category_id'])) : '' ?></p>
  <?php // locked player + our own lesson list (YouTube's playlist menu is not reachable inside the locked player)
  $vids = $f['list_id'] ? sh_yt_playlist_cached((string)$f['list_id']) : [];
  if (!$vids && $f['video_id']) $vids = [[(string)$f['video_id'], (string)$f['title']]];
  $first = (string)($f['video_id'] && array_filter($vids, fn($v) => $v[0] === $f['video_id']) ? $f['video_id'] : ($vids[0][0] ?? '')); ?>
  <?php if ($first !== ''): ?>
  <div class="fr-wrap<?= count($vids) > 1 ? ' has-list' : '' ?>">
    <div class="fr-play"><?= sh_yt_lock_html($first, (string)$f['title'], ' id="frPlayer"') ?></div>
    <?php if (count($vids) > 1): ?>
    <aside class="fr-list" aria-label="Lessons"><div class="fr-list-h"><b>Lessons</b><span><?= count($vids) ?> videos</span></div>
      <ol id="frList"><?php foreach ($vids as $k => [$vid, $vt]): ?><li><button type="button" data-v="<?= sh_e(sh_yt_tok($vid)) ?>"<?= $vid === $first ? ' class="on" aria-current="true"' : '' ?>><span class="fr-n"><?= $k + 1 ?></span><img src="<?= sh_e(sh_yt_thumb($vid, 'mq')) ?>" alt="" loading="lazy"><span class="fr-t"><?= sh_e($vt !== '' ? $vt : 'Lesson ' . ($k + 1)) ?></span></button></li><?php endforeach; ?></ol>
    </aside>
    <script>
    (function () { // pick a lesson / next lesson plays by itself at the end
      var box = document.getElementById('frPlayer'), list = document.getElementById('frList');
      function pick(btn, play) {
        [].forEach.call(list.querySelectorAll('button'), function (b) { b.classList.toggle('on', b === btn); b.removeAttribute('aria-current'); });
        btn.setAttribute('aria-current', 'true'); btn.scrollIntoView({ block: 'nearest' });
        if (window.VPlayer) VPlayer.load(box, btn.getAttribute('data-v'), play);
      }
      list.addEventListener('click', function (e) { var b = e.target.closest('button'); if (b) { pick(b, true); if (window.innerWidth < 900) box.scrollIntoView({ behavior: 'smooth', block: 'start' }); } });
      box.addEventListener('vp-ended', function () { var on = list.querySelector('button.on'), li = on && on.parentNode.nextElementSibling; if (li) pick(li.querySelector('button'), true); });
    })();
    </script>
    <?php endif; ?>
  </div>
  <?php else: ?><div class="st-empty">This course's videos could not be loaded right now. Please try again in a little while.</div><?php endif; ?>
  <?php $more = array_values(array_filter($all, fn($x) => (int)$x['id'] !== (int)$f['id'])); ?>
  <?php if ($more): ?><h2 class="fr-h2">More free courses</h2><?php endif; ?>
<?php else: ?>
  <h1>Free Courses</h1><p class="st-lead">Complete free courses — watch any time.</p>
  <?php $more = $all; if (!$more): ?><div class="st-empty">Free courses are coming soon.</div><?php endif; ?>
<?php endif; ?>
<?php $fcCats = sh_categories($pdo); ?>
<link rel="stylesheet" href="/assets/css/home.css?v=<?= @filemtime(ROOT_PATH . '/assets/css/home.css') ?: 1 ?>">
<div class="hm-grid fr-grid"><?php foreach ($more as $x) echo sh_free_card($x, $x['category_id'] && isset($fcCats[(int)$x['category_id']]) ? $fcCats[(int)$x['category_id']]['name'] : ''); ?></div>
<?php require ROOT_PATH . '/components/store-close.php'; ?>
