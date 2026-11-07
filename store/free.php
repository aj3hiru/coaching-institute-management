<?php
// A free course: a YouTube playlist (or video) played on our site.  /store/free?id=N  (without id: all free courses)
require_once dirname(__DIR__) . '/includes/config.php';
require_once INCLUDES_PATH . '/shop.php';
$pdo = sh_db();
$id = (int)($_GET['id'] ?? 0);
$all = $pdo->query("SELECT * FROM hm_free WHERE active = 1 ORDER BY sort, id DESC")->fetchAll(PDO::FETCH_ASSOC);
$f = null; foreach ($all as $x) if ((int)$x['id'] === $id) $f = $x;
if ($id && !$f) { http_response_code(404); require ROOT_PATH . '/templates/page_not_found.php'; exit; }
$stIndex = true;
$stTitle = $f ? $f['title'] : 'Free Courses';
$stDesc = $f ? 'Free course: ' . $f['title'] . ($f['videos'] ? ' — ' . ((int)$f['videos'] >= 100 ? '100+' : (int)$f['videos']) . ' video lessons' : '') . '. Watch free on ' . edu_site_name() . '.' : 'Free video courses from ' . edu_site_name() . ' — full playlists, free to watch.';
if ($f && $f['thumb']) $stImage = $f['thumb'];
require ROOT_PATH . '/components/store-open.php';
$ico = fn($n) => sh_icon($n);
$src = function (array $x) { return 'https://www.youtube-nocookie.com/embed/' . ($x['list_id'] ? ($x['video_id'] ? rawurlencode($x['video_id']) . '?list=' . rawurlencode($x['list_id']) . '&' : 'videoseries?list=' . rawurlencode($x['list_id']) . '&') : rawurlencode((string)$x['video_id']) . '?') . 'rel=0&modestbranding=1'; };
?>
<p class="st-crumb"><a href="/">Home</a> › <a href="/store/free">Free Courses</a><?= $f ? ' › ' . sh_e($f['title']) : '' ?></p>
<?php if ($f): ?>
  <h1 class="fr-h"><?= sh_e($f['title']) ?></h1>
  <p class="st-lead"><?= $f['channel'] ? sh_e($f['channel']) . ' · ' : '' ?><?= $f['videos'] ? ((int)$f['videos'] >= 100 ? '100+' : (int)$f['videos']) . ' videos · ' : '' ?>Free — no account needed<?= sh_category_name($pdo, (int)$f['category_id']) !== '' ? ' · ' . sh_e(sh_category_name($pdo, (int)$f['category_id'])) : '' ?></p>
  <div class="fr-play"><iframe src="<?= sh_e($src($f)) ?>" title="<?= sh_e($f['title']) ?>" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen loading="lazy"></iframe></div>
  <p class="fr-tip">Use the <b>playlist button</b> in the player to choose any lesson of this course.</p>
  <?php $more = array_values(array_filter($all, fn($x) => (int)$x['id'] !== (int)$f['id'])); ?>
  <?php if ($more): ?><h2 class="fr-h2">More free courses</h2><?php endif; ?>
<?php else: ?>
  <h1>Free Courses</h1><p class="st-lead">Complete free courses — watch any time.</p>
  <?php $more = $all; if (!$more): ?><div class="st-empty">Free courses are coming soon.</div><?php endif; ?>
<?php endif; ?>
<div class="st-grid">
<?php foreach ($more as $x): ?>
  <a class="st-card fr-card" href="/store/free?id=<?= (int)$x['id'] ?>"><span class="fr-th"><img src="<?= sh_e($x['thumb'] ?: '') ?>" alt="" loading="lazy" onerror="this.remove()"><em><?= $ico('playsolid') ?><?= $x['videos'] ? ((int)$x['videos'] >= 100 ? '100+' : (int)$x['videos']) . ' videos' : 'Watch' ?></em></span>
    <div class="st-cb"><h3><?= sh_e($x['title']) ?></h3><span class="st-meta"><?= sh_e($x['channel'] ?: 'Free course') ?></span><div class="st-price"><span class="st-own">Free</span></div></div></a>
<?php endforeach; ?>
</div>
<?php require ROOT_PATH . '/components/store-close.php'; ?>
