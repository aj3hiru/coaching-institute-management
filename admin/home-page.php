<?php
// Homepage: the top slider (image + link), Free Courses (YouTube playlists), "Our Impact" numbers and "Why choose" points.
// Courses, categories, study materials and latest updates on the homepage fill in by themselves.
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/institute.php';
require_once INCLUDES_PATH . '/shop.php';
$me = sh_page_start($pdo, ['settings']);
$back = fn(string $h) => header('Location: /admin/home-page.php#' . $h);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a = (string)($_POST['action'] ?? ''); $id = (int)($_POST['id'] ?? 0); $go = 'slides';
    $link = function (string $u): ?string { $u = trim($u); if ($u === '') return null; if (!preg_match('#^(https?://|/)#i', $u)) $u = 'https://' . $u; return mb_substr($u, 0, 500); };
    try {
        if ($a === 'slide_save') {
            $row = $id ? $pdo->query("SELECT * FROM hm_slides WHERE id = $id")->fetch(PDO::FETCH_ASSOC) : null;
            $img = (string)($row['image'] ?? ''); $mob = (string)($row['mobile_image'] ?? '');
            if (!empty($_FILES['image']['name'])) { [$n, $e] = sh_image_save($_FILES['image'], 'slides', 6); if ($e) throw new RuntimeException($e); sh_image_drop($img); $img = $n; }
            if (!empty($_FILES['mobile_image']['name'])) { [$n, $e] = sh_image_save($_FILES['mobile_image'], 'slides', 6); if ($e) throw new RuntimeException($e); sh_image_drop($mob); $mob = $n; }
            if (!empty($_POST['mobile_rm'])) { sh_image_drop($mob); $mob = ''; }
            if ($img === '') throw new RuntimeException('Please choose the slide image.');
            $v = [$img, $mob ?: null, $link((string)($_POST['link'] ?? '')), mb_substr(trim((string)($_POST['title'] ?? '')), 0, 200) ?: null, !empty($_POST['new_tab']) ? 1 : 0, (int)($_POST['sort'] ?? 0), !empty($_POST['active']) ? 1 : 0];
            if ($row) { $v[] = $id; $pdo->prepare("UPDATE hm_slides SET image=?, mobile_image=?, link=?, title=?, new_tab=?, sort=?, active=? WHERE id=?")->execute($v); }
            else $pdo->prepare("INSERT INTO hm_slides (image, mobile_image, link, title, new_tab, sort, active) VALUES (?,?,?,?,?,?,?)")->execute($v);
            admin_flash('Slide saved.');
        } elseif ($a === 'slide_delete') {
            $row = $pdo->query("SELECT * FROM hm_slides WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
            if ($row) { sh_image_drop($row['image']); sh_image_drop($row['mobile_image']); $pdo->exec("DELETE FROM hm_slides WHERE id = $id"); }
            admin_flash('Slide deleted.');
        } elseif ($a === 'free_add' || $a === 'free_refresh') {
            $go = 'free';
            $url = $a === 'free_add' ? trim((string)($_POST['url'] ?? '')) : (string)$pdo->query("SELECT url FROM hm_free WHERE id = $id")->fetchColumn();
            $i = sh_yt_free_info($url);
            if ($i['error']) throw new RuntimeException($i['error']);
            if ($a === 'free_add') {
                $pdo->prepare("INSERT INTO hm_free (url, list_id, video_id, title, thumb, channel, videos, category_id, sort) VALUES (?,?,?,?,?,?,?,?,?)")->execute([mb_substr($url, 0, 500), $i['list'] ?: null, $i['video'] ?: null,
                    mb_substr(trim((string)($_POST['title'] ?? '')) ?: $i['title'], 0, 250), $i['thumb'] ?: null, mb_substr($i['channel'], 0, 150) ?: null, $i['videos'], (int)($_POST['category_id'] ?? 0) ?: null, (int)($_POST['sort'] ?? 0)]);
                admin_flash('Fetched successfully: "' . $i['title'] . '"' . ($i['videos'] ? ' — ' . ($i['videos'] >= 100 ? '100+' : $i['videos']) . ' videos' : '') . '.');
            } else {
                $pdo->prepare("UPDATE hm_free SET list_id=?, video_id=?, thumb=?, channel=?, videos=? WHERE id=?")->execute([$i['list'] ?: null, $i['video'] ?: null, $i['thumb'] ?: null, mb_substr($i['channel'], 0, 150) ?: null, $i['videos'], $id]);
                admin_flash('Fetched again from YouTube' . ($i['videos'] ? ' — ' . $i['videos'] . ' videos' : '') . '.');
            }
        } elseif ($a === 'free_save') {
            $go = 'free';
            $pdo->prepare("UPDATE hm_free SET title=?, category_id=?, sort=?, active=? WHERE id=?")->execute([mb_substr(trim((string)($_POST['title'] ?? '')), 0, 250) ?: 'Free course', (int)($_POST['category_id'] ?? 0) ?: null, (int)($_POST['sort'] ?? 0), !empty($_POST['active']) ? 1 : 0, $id]);
            admin_flash('Saved.');
        } elseif ($a === 'top_save') {
            $go = 'toppers';
            $row = $id ? $pdo->query("SELECT * FROM hm_toppers WHERE id = $id")->fetch(PDO::FETCH_ASSOC) : null;
            $ph = (string)($row['photo'] ?? '');
            if (!empty($_FILES['photo']['name'])) { [$n, $e] = sh_image_save($_FILES['photo'], 'toppers', 4); if ($e) throw new RuntimeException($e); sh_image_drop($ph); $ph = $n; }
            $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 120);
            if ($name === '') throw new RuntimeException('Please type the student\'s name.');
            $v = [$name, $ph ?: null, mb_substr(trim((string)($_POST['result'] ?? '')), 0, 160) ?: null, mb_substr(trim((string)($_POST['class_name'] ?? '')), 0, 80) ?: null,
                mb_substr(trim((string)($_POST['year'] ?? '')), 0, 10) ?: null, (int)($_POST['sort'] ?? 0), !empty($_POST['active']) ? 1 : 0];
            if ($row) { $v[] = $id; $pdo->prepare("UPDATE hm_toppers SET name=?, photo=?, result=?, class_name=?, year=?, sort=?, active=? WHERE id=?")->execute($v); }
            else $pdo->prepare("INSERT INTO hm_toppers (name, photo, result, class_name, year, sort, active) VALUES (?,?,?,?,?,?,?)")->execute($v);
            admin_flash('Topper saved.');
        } elseif ($a === 'top_delete') {
            $go = 'toppers';
            $ph = (string)$pdo->query("SELECT photo FROM hm_toppers WHERE id = $id")->fetchColumn();
            $pdo->exec("DELETE FROM hm_toppers WHERE id = $id"); sh_image_drop($ph);
            admin_flash('Topper removed.');
        } elseif ($a === 'free_delete') { $go = 'free'; $pdo->exec("DELETE FROM hm_free WHERE id = $id"); admin_flash('Free course removed.'); }
        elseif ($a === 'texts') {
            $go = 'impact';
            $imp = [];
            foreach ((array)($_POST['il'] ?? []) as $k => $l) { $l = trim((string)$l); if ($l === '') continue; $imp[] = ['k' => preg_replace('/[^a-z]/', '', (string)($_POST['ik'][$k] ?? '')), 'l' => mb_substr($l, 0, 60), 'v' => mb_substr(trim((string)($_POST['iv'][$k] ?? '')), 0, 20)]; }
            $why = [];
            foreach ((array)($_POST['wt'] ?? []) as $k => $t) { $t = trim((string)$t); $d = trim((string)($_POST['wd'][$k] ?? '')); if ($t !== '') $why[] = ['t' => mb_substr($t, 0, 80), 'd' => mb_substr($d, 0, 300)]; }
            edu_settings_save('home', ['impact' => json_encode($imp, JSON_UNESCAPED_UNICODE), 'why' => json_encode(array_slice($why, 0, 8), JSON_UNESCAPED_UNICODE), 'why_title' => mb_substr(trim((string)($_POST['why_title'] ?? '')), 0, 120)]);
            admin_flash('Saved.');
        }
        admin_log('home_page', "Homepage: $a" . ($id ? " #$id" : ''));
    } catch (RuntimeException $e) { admin_flash($e->getMessage(), 'error'); }
    sh_home_refresh();
    $back($go); exit;
}
$slides = $pdo->query("SELECT * FROM hm_slides ORDER BY sort, id")->fetchAll(PDO::FETCH_ASSOC);
$free = $pdo->query("SELECT * FROM hm_free ORDER BY sort, id DESC")->fetchAll(PDO::FETCH_ASSOC);
$tops = $pdo->query("SELECT * FROM hm_toppers ORDER BY sort, id")->fetchAll(PDO::FETCH_ASSOC);
$tf = fn(array $x): string => ci_e(json_encode(['id' => (int)$x['id'], 'name' => $x['name'], 'result' => (string)$x['result'], 'class_name' => (string)$x['class_name'], 'year' => (string)$x['year'], 'sort' => (int)$x['sort'], 'active' => (int)$x['active']], JSON_UNESCAPED_UNICODE));
$cats = sh_categories($pdo, true);
$cfg = sh_home_cfg();
$eS = isset($_GET['slide']) ? ($pdo->query("SELECT * FROM hm_slides WHERE id = " . (int)$_GET['slide'])->fetch(PDO::FETCH_ASSOC) ?: null) : null;
$catSel = function (int $cur) use ($cats) { $h = '<select name="category_id"><option value="0">— No category —</option>'; foreach ($cats as $k => $c) $h .= '<option value="' . $k . '"' . ($cur === (int)$k ? ' selected' : '') . '>' . ci_e($c['name']) . '</option>'; return $h . '</select>'; };
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Homepage - <?= ci_e(edu_site_name()) ?></title>
<?php admin_shell_head(); ci_head(); ?>
<style>
.hp-sl{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px}
.hp-s{border:1px solid #e5e7eb;background:#fff}.hp-s img{display:block;width:100%;aspect-ratio:16/6;object-fit:cover;background:#f3f4f6}.hp-s-b{padding:10px 12px;display:flex;justify-content:space-between;gap:8px;align-items:center}
.hp-s-b small{display:block;color:#6b7280;font-size:12px;word-break:break-all}.hp-s.off img{opacity:.45}
.hp-fr{display:grid;grid-template-columns:120px 1fr;gap:12px;align-items:start;border:1px solid #e5e7eb;background:#fff;padding:10px;margin-bottom:10px}
.hp-fr img{width:120px;aspect-ratio:16/9;object-fit:cover;display:block;background:#f3f4f6}.hp-fr form.hp-fe{display:grid;grid-template-columns:2fr 1fr 80px auto;gap:8px;align-items:end}
.hp-fr small{color:#6b7280;font-size:12px}.hp-row{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px}.hp-why{display:grid;grid-template-columns:1fr 2fr;gap:8px;margin-bottom:8px}
.hp-tops{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:10px}
.hp-top{display:grid;grid-template-columns:52px 1fr auto;gap:10px;align-items:center;border:1px solid #e5e7eb;padding:8px 10px;background:#fff;border-radius:8px}.hp-top.off{opacity:.55}
.hp-top-i{width:52px;height:64px;border-radius:6px;overflow:hidden;background:#f3f4f6;display:grid;place-items:center;color:#9ca3af}.hp-top-i img{width:100%;height:100%;object-fit:cover}
.hp-top b{display:block;font-size:14px}.hp-top small{color:#6b7280;font-size:12px}
.hp-row input,.hp-why input,.hp-why textarea,.hp-fe input,.hp-fe select{box-sizing:border-box;width:100%;min-height:38px;padding:8px 10px;border:1px solid #d1d5db;font:inherit;font-size:14px;background:#fff}
@media (max-width:760px){.hp-fr{grid-template-columns:1fr}.hp-fr form.hp-fe,.hp-why,.hp-row{grid-template-columns:1fr}}
</style>
</head>
<body>
<?php admin_shell_open('Homepage', 'Slider, free courses, impact numbers and why choose us — the rest of the homepage fills in by itself'); ?>
<div class="ci">
<?= admin_flash() ?>

<div class="ci-card" id="slides"><div class="ci-card-h"><h3>1. Top slider (<?= count($slides) ?>)</h3></div><div class="ci-card-b">
  <form method="post" enctype="multipart/form-data" style="margin-bottom:16px"><?= csrf_field() ?><input type="hidden" name="action" value="slide_save"><input type="hidden" name="id" value="<?= (int)($eS['id'] ?? 0) ?>">
    <b style="display:block;margin-bottom:8px"><?= $eS ? 'Edit slide' : 'Add a slide' ?></b>
    <div class="ci-grid3">
      <div class="ci-f"><label>Image <span class="ci-muted" style="font-weight:400">(1600 × 500 px)</span></label><input type="file" name="image" accept="image/png,image/jpeg,image/webp" <?= $eS ? '' : 'required' ?>><?php if ($eS): ?><span class="ci-hint">Leave empty to keep the current image.</span><?php endif; ?></div>
      <div class="ci-f"><label>Phone image <span class="ci-muted" style="font-weight:400">(optional, 800 × 400 px)</span></label><input type="file" name="mobile_image" accept="image/png,image/jpeg,image/webp"><?php if (!empty($eS['mobile_image'])): ?><label style="font-weight:400;display:flex;gap:6px;margin-top:4px"><input type="checkbox" name="mobile_rm" value="1"> Remove phone image</label><?php endif; ?></div>
      <div class="ci-f"><label>Order</label><input type="number" name="sort" value="<?= (int)($eS['sort'] ?? count($slides) + 1) ?>"></div>
      <div class="ci-f" style="grid-column:span 2"><label>Link when clicked <span class="ci-muted" style="font-weight:400">(optional)</span></label><input name="link" maxlength="500" placeholder="e.g. /courses/class-10-maths or https://…" value="<?= ci_e($eS['link'] ?? '') ?>"></div>
      <div class="ci-f"><label>Text for Google / screen readers</label><input name="title" maxlength="200" placeholder="e.g. Class 10 new batch" value="<?= ci_e($eS['title'] ?? '') ?>"></div>
    </div>
    <div style="display:flex;gap:18px;align-items:center;flex-wrap:wrap;margin-top:8px">
      <label style="display:flex;gap:6px;align-items:center;margin:0"><input type="checkbox" name="new_tab" value="1" <?= !empty($eS['new_tab']) ? 'checked' : '' ?>> Open the link in a new tab</label>
      <label style="display:flex;gap:6px;align-items:center;margin:0"><input type="checkbox" name="active" value="1" <?= !$eS || !empty($eS['active']) ? 'checked' : '' ?>> Show on the homepage</label>
      <button class="ci-btn" type="submit"><i class="fas fa-check"></i> <?= $eS ? 'Save slide' : 'Add slide' ?></button><?php if ($eS): ?><a href="/admin/home-page.php#slides">Cancel</a><?php endif; ?>
    </div>
  </form>
  <?php if (!$slides): ?><p class="ci-muted" style="margin:0">No slides yet — until you add one, the homepage shows a ready-made banner.</p><?php endif; ?>
  <div class="hp-sl"><?php foreach ($slides as $s): ?>
    <div class="hp-s<?= $s['active'] ? '' : ' off' ?>"><img src="<?= ci_e(sh_upload_url($s['image'])) ?>" alt="">
      <div class="hp-s-b"><div><b>#<?= (int)$s['sort'] ?><?= $s['active'] ? '' : ' · hidden' ?><?= $s['mobile_image'] ? ' · phone image' : '' ?></b><small><?= ci_e($s['link'] ?: 'no link') ?><?= $s['new_tab'] ? ' (new tab)' : '' ?></small></div>
      <div class="ci-icos"><?= ci_ico('edit', '/admin/home-page.php?slide=' . (int)$s['id'] . '#slides', 'Edit') ?><?= ci_post_ico('trash', 'Delete', ['action' => 'slide_delete', 'id' => $s['id']], 'Delete this slide?') ?></div></div></div>
  <?php endforeach; ?></div>
</div></div>

<div class="ci-card" id="free"><div class="ci-card-h"><h3>7. Free Courses — YouTube playlists (<?= count($free) ?>)</h3></div><div class="ci-card-b">
  <form method="post" class="ci-grid3" style="margin-bottom:14px"><?= csrf_field() ?><input type="hidden" name="action" value="free_add">
    <div class="ci-f" style="grid-column:span 2"><label>YouTube playlist link</label><input name="url" required maxlength="500" placeholder="https://www.youtube.com/playlist?list=…"><span class="ci-hint">Title, picture and number of videos are fetched from YouTube. The playlist must be Public or Unlisted.</span></div>
    <div class="ci-f"><label>Category</label><?= $catSel(0) ?></div>
    <div class="ci-f" style="grid-column:span 2"><label>Title <span class="ci-muted" style="font-weight:400">(optional — empty = YouTube's title)</span></label><input name="title" maxlength="250"></div>
    <div class="ci-f"><label>&nbsp;</label><button class="ci-btn" type="submit"><i class="fab fa-youtube"></i> Fetch &amp; add</button></div>
  </form>
  <?php if (!$free): ?><p class="ci-muted" style="margin:0">No free courses yet.</p><?php endif; ?>
  <?php foreach ($free as $f): ?>
    <div class="hp-fr"><img src="<?= ci_e($f['thumb'] ?: '') ?>" alt="" onerror="this.style.visibility='hidden'">
      <div><small><?= ci_e($f['channel'] ?: 'YouTube') ?><?= $f['videos'] ? ' · ' . ((int)$f['videos'] >= 100 ? '100+' : (int)$f['videos']) . ' videos' : '' ?> · <a href="<?= ci_e($f['url']) ?>" target="_blank" rel="noopener">open on YouTube</a> · <a href="<?= ci_e(sh_free_url($f)) ?>" target="_blank">view on site</a></small>
        <form method="post" class="hp-fe"><?= csrf_field() ?><input type="hidden" name="action" value="free_save"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
          <div class="ci-f"><label>Title</label><input name="title" maxlength="250" value="<?= ci_e($f['title']) ?>"></div>
          <div class="ci-f"><label>Category</label><?= $catSel((int)$f['category_id']) ?></div>
          <div class="ci-f"><label>Order</label><input type="number" name="sort" value="<?= (int)$f['sort'] ?>"></div>
          <div style="display:flex;gap:8px;align-items:center;padding-bottom:4px"><label style="display:flex;gap:5px;margin:0;font-size:13px"><input type="checkbox" name="active" value="1" <?= $f['active'] ? 'checked' : '' ?>> Show</label><button class="ci-btn ci-sm" type="submit">Save</button></div>
        </form>
        <div class="ci-icos" style="margin-top:6px"><?= ci_post_ico('refresh', 'Fetch again', ['action' => 'free_refresh', 'id' => $f['id']]) ?><?= ci_post_ico('trash', 'Delete', ['action' => 'free_delete', 'id' => $f['id']], 'Remove this free course?') ?></div></div></div>
  <?php endforeach; ?>
</div></div>

<div class="ci-card" id="toppers"><div class="ci-card-h"><h3>Meet our Toppers (<?= count($tops) ?>)</h3><button type="button" class="ci-btn ci-sm" data-mopen="#topForm" data-mtitle="Add a topper" data-fill='{"id":0,"active":1}'><i class="fas fa-plus"></i> Add topper</button></div><div class="ci-card-b">
  <?php if (!$tops): ?><p class="ci-muted" style="margin:0">No toppers yet. Add students with a passport-size photo, their result (e.g. 96.4% · Class 10 Board) and the year.</p><?php endif; ?>
  <div class="hp-tops"><?php foreach ($tops as $x): ?>
    <div class="hp-top<?= $x['active'] ? '' : ' off' ?>"><span class="hp-top-i"><?php if ($x['photo']): ?><img src="<?= ci_e(sh_upload_url($x['photo'])) ?>" alt=""><?php else: ?><i class="fas fa-user-graduate"></i><?php endif; ?></span>
      <div><b><?= ci_e($x['name']) ?></b><small><?= ci_e(trim($x['result'] . ($x['class_name'] ? ' · ' . $x['class_name'] : '') . ($x['year'] ? ' · ' . $x['year'] : ''), ' ·')) ?><?= $x['active'] ? '' : ' · hidden' ?></small></div>
      <div class="ci-icos"><?= ci_ico('edit', null, 'Edit', 'button', ' data-mopen="#topForm" data-mtitle="Edit topper" data-fill="' . $tf($x) . '"') ?><?= ci_post_ico('trash', 'Delete', ['action' => 'top_delete', 'id' => $x['id']], 'Remove this topper?') ?></div></div>
  <?php endforeach; ?></div>
  <p class="ci-muted" style="margin:12px 0 0;font-size:12.5px">Teachers for "Meet our teachers" come from <a href="/admin/user-manager.php">Users</a>: give a user the Teacher permission and fill the teacher profile.</p>
</div></div>
<div class="ci-modal" id="topForm" data-mform hidden><div class="ci-modal-box" role="dialog" aria-modal="true" aria-labelledby="topFormH">
<div class="ci-card-h"><h3 id="topFormH" data-mtitle-target>Add a topper</h3><button type="button" class="ci-x" data-close aria-label="Close"><i class="fas fa-times"></i></button></div><div class="ci-card-b">
<form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="action" value="top_save"><input type="hidden" name="id" value="0">
  <div class="ci-grid2 ci-mgrid">
    <div class="ci-f" style="grid-column:1/-1"><label>Photo <span class="ci-muted" style="font-weight:400">(passport size, e.g. 300 × 400)</span></label><input type="file" name="photo" accept="image/png,image/jpeg,image/webp"><span class="ci-hint">When editing, leave empty to keep the photo.</span></div>
    <div class="ci-f" style="grid-column:1/-1"><label>Student's name <span class="ci-req">*</span></label><input name="name" maxlength="120" required></div>
    <div class="ci-f" style="grid-column:1/-1"><label>Result</label><input name="result" maxlength="160" placeholder="e.g. 96.4% in Class 10 Board · State rank 12"></div>
    <div class="ci-f"><label>Class / exam</label><input name="class_name" maxlength="80" placeholder="e.g. Class 10"></div>
    <div class="ci-f"><label>Year</label><input name="year" maxlength="10" placeholder="e.g. 2026"></div>
    <div class="ci-f"><label>Order</label><input type="number" name="sort" value="0"></div>
    <div class="ci-f"><label>&nbsp;</label><label style="display:flex;gap:6px;align-items:center;font-weight:500;margin:0"><input type="checkbox" name="active" value="1"> Show on the homepage</label></div>
  </div>
  <div class="ci-mfoot"><button type="button" class="ci-btn ci-ghost" data-close>Cancel</button><button class="ci-btn" type="submit"><i class="fas fa-check"></i> Save topper</button></div>
</form></div></div></div>

<form method="post" id="impact"><?= csrf_field() ?><input type="hidden" name="action" value="texts">
<div class="ci-card"><div class="ci-card-h"><h3>8. Our Impact — numbers</h3></div><div class="ci-card-b">
  <p class="ci-muted" style="margin:0 0 10px">Leave a number empty and the site counts it for you (students, courses, free videos, PDF notes). Type a number like <b>5000+</b> to show your own.</p>
  <?php foreach ($cfg['impact'] as $i => $it): ?><div class="hp-row"><input type="hidden" name="ik[<?= $i ?>]" value="<?= ci_e($it['k'] ?? '') ?>"><input name="il[<?= $i ?>]" maxlength="60" value="<?= ci_e($it['l']) ?>" placeholder="Label" aria-label="Label"><input name="iv[<?= $i ?>]" maxlength="20" value="<?= ci_e($it['v']) ?>" placeholder="<?= in_array($it['k'] ?? '', ['students', 'courses', 'free', 'notes'], true) ? 'automatic' : 'e.g. 500+' ?>" aria-label="Number"></div><?php endforeach; ?>
  <?php $n = count($cfg['impact']); ?><div class="hp-row"><input type="hidden" name="ik[<?= $n ?>]" value=""><input name="il[<?= $n ?>]" maxlength="60" placeholder="Add another — label (optional)"><input name="iv[<?= $n ?>]" maxlength="20" placeholder="number"></div>
</div></div>
<div class="ci-card"><div class="ci-card-h"><h3>9. Why choose us</h3></div><div class="ci-card-b">
  <div class="ci-f" style="margin-bottom:10px"><label>Heading</label><input name="why_title" maxlength="120" placeholder="Why choose <?= ci_e(edu_site_name()) ?>" value="<?= ci_e($cfg['why_title']) ?>"></div>
  <?php $w = $cfg['why']; for ($i = 0; $i < max(6, count($w)); $i++): ?><div class="hp-why"><input name="wt[<?= $i ?>]" maxlength="80" placeholder="Point <?= $i + 1 ?> title" value="<?= ci_e($w[$i]['t'] ?? '') ?>"><textarea name="wd[<?= $i ?>]" rows="1" maxlength="300" placeholder="One line about it"><?= ci_e($w[$i]['d'] ?? '') ?></textarea></div><?php endfor; ?>
  <button class="ci-btn" type="submit"><i class="fas fa-check"></i> Save numbers &amp; points</button>
</div></div>
</form>
<p class="ci-muted">The other sections fill in by themselves: <b>Our Courses</b> (newest first), <b>Categories</b> (<a href="/admin/shop-categories.php">manage</a>), <b>Latest courses / Upcoming batches</b> (set "Batch starts on" in a course), <b>Study Materials</b> (PDF notes) and <b>Latest Update</b> (your newest blog posts).</p>
</div>
<?php admin_shell_close(); ?>
</body>
</html>
