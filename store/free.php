<?php
// A free course: a YouTube playlist (or video) played on our site.  /free-courses/ID-name  (/free-courses/: all)  — old /store/free?id=N redirects
require_once dirname(__DIR__) . '/includes/config.php';
require_once INCLUDES_PATH . '/shop.php';
$pdo = sh_db();
$id = (int)($_GET['id'] ?? 0);
$all = $pdo->query("SELECT * FROM hm_free WHERE active = 1 ORDER BY sort, id DESC")->fetchAll(PDO::FETCH_ASSOC);
$f = null; foreach ($all as $x) if ((int)$x['id'] === $id) $f = $x;
if ($id && !$f) { http_response_code(404); require ROOT_PATH . '/templates/page_not_found.php'; exit; }
sh_redirect_clean($f ? sh_free_url($f) : '/free-courses/');
if ($f) $seo_canonical = rtrim(SITE_URL, '/') . sh_free_url($f);
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
      <ol id="frList"><?php foreach ($vids as $k => [$vid, $vt]): ?><li><button type="button" data-v="<?= sh_e($vid) ?>"<?= $vid === $first ? ' class="on" aria-current="true"' : '' ?>><span class="fr-n"><?= $k + 1 ?></span><img src="https://i.ytimg.com/vi/<?= sh_e($vid) ?>/mqdefault.jpg" alt="" loading="lazy"><span class="fr-t"><?= sh_e($vt !== '' ? $vt : 'Lesson ' . ($k + 1)) ?></span></button></li><?php endforeach; ?></ol>
    </aside>
    <script>
    (function () { // pick a lesson / next lesson plays by itself at the end
      var box = document.getElementById('frPlayer'), list = document.getElementById('frList');
      function pick(btn, play) {
        [].forEach.call(list.querySelectorAll('button'), function (b) { b.classList.toggle('on', b === btn); b.removeAttribute('aria-current'); });
        btn.setAttribute('aria-current', 'true'); btn.scrollIntoView({ block: 'nearest' });
        if (window.YtLock) YtLock.load(box, btn.getAttribute('data-v'), play);
      }
      list.addEventListener('click', function (e) { var b = e.target.closest('button'); if (b) { pick(b, true); if (window.innerWidth < 900) box.scrollIntoView({ behavior: 'smooth', block: 'start' }); } });
      box.addEventListener('yt-ended', function () { var on = list.querySelector('button.on'), li = on && on.parentNode.nextElementSibling; if (li) pick(li.querySelector('button'), true); });
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
<div class="st-grid">
<?php foreach ($more as $x): ?>
  <a class="st-card fr-card" href="<?= sh_e(sh_free_url($x)) ?>"><span class="fr-th"><img src="<?= sh_e($x['thumb'] ?: '') ?>" alt="" loading="lazy" onerror="this.remove()"><em><?= $ico('playsolid') ?><?= $x['videos'] ? ((int)$x['videos'] >= 100 ? '100+' : (int)$x['videos']) . ' videos' : 'Watch' ?></em></span>
    <div class="st-cb"><h3><?= sh_e($x['title']) ?></h3><span class="st-meta"><?= sh_e($x['channel'] ?: 'Free course') ?></span><div class="st-price"><span class="st-own">Free</span></div></div></a>
<?php endforeach; ?>
</div>
<?php require ROOT_PATH . '/components/store-close.php'; ?>
