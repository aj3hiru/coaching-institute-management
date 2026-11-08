<?php
// Online Courses → a free YouTube playlist course (hm_free, shown on /free-courses): add or edit everything —
// playlist link, title, picture (YouTube's or your own from the Media Library), category, teacher, order, shown or hidden.
//   ?id=N edit · no id = add · POST action=save | refresh | delete
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/institute.php';
require_once INCLUDES_PATH . '/shop.php';
$me = sh_page_start($pdo, ['products']);
sh_tables($pdo);
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$F = $id ? ($pdo->query("SELECT * FROM hm_free WHERE id = $id")->fetch(PDO::FETCH_ASSOC) ?: null) : null;
if ($id && !$F) { header('Location: /admin/shop-products.php?type=course#playlists'); exit; }
$teachers = sh_teachers($pdo);
$cats = sh_categories($pdo, true);
$list = '/admin/shop-products.php?type=course#playlists';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a = (string)($_POST['action'] ?? 'save');
    try {
        if ($a === 'delete' && $F) {
            $pdo->prepare("DELETE FROM hm_free WHERE id = ?")->execute([$id]);
            admin_log('shop_playlist', "Deleted playlist course #$id {$F['title']}");
            admin_flash('Playlist course removed.'); header('Location: ' . $list); exit;
        }
        $url = trim((string)($_POST['url'] ?? ($F['url'] ?? '')));
        if ($url === '') throw new RuntimeException('Paste the YouTube playlist link.');
        $changed = !$F || $url !== (string)$F['url'] || $a === 'refresh';
        $i = $changed ? sh_yt_free_info($url) : null;
        if ($i && !empty($i['error'])) throw new RuntimeException($i['error']);
        $title = mb_substr(trim(preg_replace('/\s+/', ' ', (string)($_POST['title'] ?? ''))), 0, 250) ?: ($i['title'] ?? ($F['title'] ?? '')) ?: 'Free course';
        $cat = (int)($_POST['category_id'] ?? 0); $cat = $cat && isset($cats[$cat]) ? $cat : null;
        $tid = (int)($_POST['teacher_id'] ?? 0); $tid = $tid && isset($teachers[$tid]) ? $tid : null;
        $pic = ltrim(trim((string)($_POST['thumb_custom'] ?? '')), '/');
        $pic = preg_match('#^uploads/[\w./-]+\.(webp|jpe?g|png|gif|avif)$#i', $pic) && !str_contains($pic, '..') ? $pic : null;
        $vals = [mb_substr($url, 0, 500), $title, $cat, $tid, (int)($_POST['sort'] ?? 0), !empty($_POST['active']) ? 1 : 0, $pic];
        if ($F) {
            $pdo->prepare("UPDATE hm_free SET url = ?, title = ?, category_id = ?, teacher_id = ?, sort = ?, active = ?, thumb_custom = ? WHERE id = ?")->execute(array_merge($vals, [$id]));
        } else {
            $pdo->prepare("INSERT INTO hm_free (url, title, category_id, teacher_id, sort, active, thumb_custom) VALUES (?,?,?,?,?,?,?)")->execute($vals);
            $id = (int)$pdo->lastInsertId();
        }
        if ($i) $pdo->prepare("UPDATE hm_free SET list_id = ?, video_id = ?, thumb = ?, channel = ?, videos = ? WHERE id = ?")->execute([$i['list'] ?: null, $i['video'] ?: null, $i['thumb'] ?: null, mb_substr((string)$i['channel'], 0, 150) ?: null, $i['videos'], $id]);
        if (function_exists('sh_home_refresh')) sh_home_refresh();
        admin_log('shop_playlist', ($F ? 'Edited' : 'Added') . " playlist course #$id $title");
        admin_flash($a === 'refresh' ? 'Saved and fetched again from YouTube.' : ($F ? 'Saved.' : 'Playlist course added' . ($i && $i['videos'] ? ' — ' . ($i['videos'] >= 100 ? '100+' : $i['videos']) . ' videos' : '') . '.'));
        header('Location: /admin/shop-playlist.php?id=' . $id); exit;
    } catch (RuntimeException $e) {
        admin_flash($e->getMessage(), 'error');
        header('Location: /admin/shop-playlist.php' . ($id ? '?id=' . $id : '')); exit;
    }
}
$thumbYt = (string)($F['thumb'] ?? '');
$thumbOwn = !empty($F['thumb_custom']) ? sh_upload_url($F['thumb_custom']) : '';
$vids = $F && $F['list_id'] ? sh_yt_playlist_cached((string)$F['list_id']) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title><?= $F ? 'Edit playlist course' : 'Add playlist course' ?> - <?= ci_e(edu_site_name()) ?></title>
<?php admin_shell_head(); ci_head(); ?>
<style>
.pe-layout{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:18px;align-items:start}
@media (max-width:1000px){.pe-layout{grid-template-columns:1fr}}
.pe-card{border:1px solid #e5e7eb;background:#fff;margin:0 0 14px}
.pe-card>h3{margin:0;padding:12px 16px;border-bottom:1px solid #eef0f3;font-size:15px;display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap}
.pe-card>h3 small{font-weight:400;color:#6b7280;font-size:12.5px}
.pe-b{padding:14px 16px}
.pe-b input:not([type=checkbox]),.pe-b select{width:100%;height:40px;padding:0 10px;border:1px solid #d1d5db;font:inherit;background:#fff}
.pe-b input:focus{border-color:var(--ci-acc,#7c3aed);outline:0}
.pe-title{font-size:20px!important;font-weight:700;height:48px!important}
.pe-b label{display:block;font-weight:600;font-size:13px;margin:0 0 6px}
.pe-row{display:flex;gap:8px}.pe-row input{flex:1}
.pe-f{margin:0 0 12px}
.pe-pic{aspect-ratio:16/9;background:#f3f4f6;display:grid;place-items:center;overflow:hidden;margin:0 0 10px;color:#9ca3af}
.pe-pic img{width:100%;height:100%;object-fit:cover}
.pe-acts{display:flex;gap:8px;flex-wrap:wrap}
.pe-chk{display:flex!important;gap:8px;align-items:center;font-weight:600}
.pe-vids{max-height:420px;overflow:auto;margin:0;padding:0;list-style:none;counter-reset:v}
.pe-vids li{display:flex;gap:10px;align-items:center;padding:6px 0;border-bottom:1px solid #f1f2f4;font-size:13.5px;counter-increment:v}
.pe-vids li:before{content:counter(v);width:26px;color:#9ca3af;font-weight:700;text-align:center;flex:0 0 auto}
.pe-vids img{width:88px;aspect-ratio:16/9;object-fit:cover;flex:0 0 auto;background:#f3f4f6}
.pe-b .ci-hint{display:block;font-size:12.5px;color:#6b7280;margin-top:5px;line-height:1.4}
.pe-meta{font-size:13px;color:#6b7280;display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}
</style>
</head>
<body>
<?php admin_shell_open($F ? 'Edit playlist course' : 'Add playlist course', $F ? ci_e($F['title']) : 'A free course from a YouTube playlist — shown on Free Courses',
    '<a class="ci-btn ci-ghost" href="' . $list . '"><i class="fas fa-arrow-left"></i> Online Courses</a>' . ($F ? '<a class="ci-btn ci-ghost" href="' . ci_e(sh_free_url($F)) . '" target="_blank"><i class="fas fa-eye"></i> View on site</a>' : '')); ?>
<div class="ci">
<?= admin_flash() ?>
<form method="post" id="peForm"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$id ?>">
<input type="hidden" name="thumb_custom" id="peOwn" value="<?= ci_e($F['thumb_custom'] ?? '') ?>">
<div class="pe-layout">
  <div>
    <div class="pe-card"><div class="pe-b">
      <div class="pe-f"><input class="pe-title" name="title" maxlength="250" placeholder="Course name (empty = the playlist's name on YouTube)" value="<?= ci_e($F['title'] ?? '') ?>"></div>
      <div class="pe-f" style="margin:0"><label><i class="fab fa-youtube" style="color:#dc2626"></i> YouTube playlist link <span class="ci-req">*</span></label>
        <input name="url" required maxlength="500" placeholder="https://www.youtube.com/playlist?list=…" value="<?= ci_e($F['url'] ?? '') ?>">
        <span class="ci-hint">The playlist must be Public or Unlisted. Students watch it free on the site. Change the link to use another playlist.</span>
        <?php if ($F): ?><div class="pe-meta"><span><?= ci_e($F['channel'] ?: 'YouTube') ?></span><span>·</span><span><?= $F['videos'] ? ((int)$F['videos'] >= 100 ? '100+' : (int)$F['videos']) . ' videos' : '—' ?></span><span>·</span><a href="<?= ci_e($F['url']) ?>" target="_blank" rel="noopener">open on YouTube</a></div><?php endif; ?>
      </div>
    </div></div>
    <?php if ($F): ?>
    <div class="pe-card"><h3><span>Videos in this playlist</span><small>from YouTube — change them on YouTube</small></h3><div class="pe-b">
      <?php if ($vids): ?><ol class="pe-vids"><?php foreach ($vids as [$v, $t]): ?><li><img src="https://i.ytimg.com/vi/<?= ci_e($v) ?>/mqdefault.jpg" alt="" loading="lazy"><span><?= ci_e($t ?: 'Video') ?></span></li><?php endforeach; ?></ol>
      <?php else: ?><p class="ci-muted" style="margin:0">The video list could not be read right now (or this link is a single video).</p><?php endif; ?>
    </div></div>
    <?php endif; ?>
  </div>
  <aside>
    <div class="pe-card"><h3><span>Publish</span></h3><div class="pe-b">
      <label class="pe-chk pe-f"><input type="checkbox" name="active" value="1"<?= !$F || $F['active'] ? ' checked' : '' ?>> Show on the site</label>
      <div class="pe-acts"><button class="ci-btn" type="submit" name="action" value="save"><i class="fas fa-check"></i> <?= $F ? 'Save' : 'Add playlist course' ?></button>
      <?php if ($F): ?><button class="ci-btn ci-ghost" type="submit" name="action" value="refresh"><i class="fas fa-rotate"></i> Fetch again</button><?php endif; ?></div>
      <?php if ($F): ?><button class="ci-btn ci-ghost" type="submit" name="action" value="delete" formnovalidate style="margin-top:10px;color:#b91c1c" onclick="return confirm('Remove this playlist course from the site?')"><i class="fas fa-trash"></i> Delete</button><?php endif; ?>
    </div></div>
    <div class="pe-card"><h3><span>Picture</span></h3><div class="pe-b">
      <div class="pe-pic" id="pePic"><?php if ($thumbOwn || $thumbYt): ?><img src="<?= ci_e($thumbOwn ?: $thumbYt) ?>" alt=""><?php else: ?><span>YouTube's picture after saving</span><?php endif; ?></div>
      <div class="pe-acts"><button type="button" class="ci-btn ci-ghost ci-sm" id="pePick"><i class="fas fa-image"></i> Choose own picture</button><button type="button" class="ci-btn ci-ghost ci-sm" id="peYt"<?= $thumbOwn ? '' : ' hidden' ?>><i class="fas fa-rotate-left"></i> Use YouTube's</button></div>
    </div></div>
    <div class="pe-card"><h3><span>Details</span></h3><div class="pe-b">
      <div class="pe-f"><label>Category</label><select name="category_id"><option value="0">— No category —</option><?php foreach ($cats as $k => $c): ?><option value="<?= (int)$k ?>"<?= (int)($F['category_id'] ?? 0) === (int)$k ? ' selected' : '' ?>><?= ci_e($c['name']) ?></option><?php endforeach; ?></select></div>
      <div class="pe-f"><label>Teacher</label><select name="teacher_id"><option value="0">— No teacher —</option><?php foreach ($teachers as $k => $l): ?><option value="<?= (int)$k ?>"<?= (int)($F['teacher_id'] ?? 0) === (int)$k ? ' selected' : '' ?>><?= ci_e($l) ?></option><?php endforeach; ?></select></div>
      <div class="pe-f" style="margin:0"><label>Order</label><input name="sort" type="number" value="<?= (int)($F['sort'] ?? 0) ?>"><span class="ci-hint">Smaller numbers come first on Free Courses.</span></div>
    </div></div>
  </aside>
</div>
</form>
</div>
<?php admin_shell_close(); ?>
<script src="/assets/js/admin-media.js?v=<?= @filemtime(ROOT_PATH . '/assets/js/admin-media.js') ?>"></script>
<script>
(function () {
  var own = document.getElementById('peOwn'), pic = document.getElementById('pePic'), yt = document.getElementById('peYt'), ytSrc = <?= json_encode($thumbYt) ?>;
  function show(src) { pic.innerHTML = src ? '<img src="' + src.replace(/"/g, '&quot;') + '" alt="">' : '<span>YouTube\'s picture after saving</span>'; }
  document.getElementById('pePick').addEventListener('click', function () { EduMedia.pick(function (it) { own.value = it.path; show(it.path); yt.hidden = false; }); });
  yt.addEventListener('click', function () { own.value = ''; show(ytSrc); yt.hidden = true; });
})();
</script>
</body>
</html>
