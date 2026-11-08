<?php
// Online store → Products: video courses and PDF notes packs (price, access period, thumbnail, status).
// The edit page holds everything: details, a YouTube playlist (its videos become lectures and new ones come in by
// themselves) and, below the form, the Lectures section (chapters, lectures with their PDFs / MCQs, course PDFs).
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/institute.php';
require_once INCLUDES_PATH . '/shop.php';
$me = sh_page_start($pdo, ['products']);
sh_tables($pdo);

/** Saves an uploaded thumbnail (JPG/PNG/WebP, max 3 MB) to /uploads/store and returns its address. */
function shp_thumb(array $f, string $slug): string
{
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('The picture could not be uploaded (it may be too large).');
    if ($f['size'] > 3 * 1024 * 1024) throw new RuntimeException('The picture is larger than 3 MB.');
    $info = @getimagesize($f['tmp_name']);
    $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2] ?? 0] ?? null;
    if (!$ext) throw new RuntimeException('The picture must be a JPG, PNG or WebP image.');
    $dir = ROOT_PATH . '/uploads/store';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new RuntimeException('The uploads folder is not writable.');
    $name = substr($slug, 0, 60) . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) throw new RuntimeException('The picture could not be saved.');
    return '/uploads/store/' . $name;
}

if (isset($_GET['ytpreview'])) { // the videos of a YouTube playlist, before saving the course
    header('Content-Type: application/json; charset=utf-8'); session_write_close();
    $list = sh_yt_list((string)$_GET['ytpreview']);
    if (!$list) exit(json_encode(['error' => 'Paste the YouTube playlist link — it has "list=" in it.']));
    $pl = sh_yt_playlist($list);
    if (!$pl) exit(json_encode(['error' => 'Could not read this playlist. On YouTube set it to Public or Unlisted, then try again.']));
    $o = json_decode(sh_http_get('https://www.youtube.com/oembed?format=json&url=' . rawurlencode('https://www.youtube.com/playlist?list=' . $list), 12), true) ?: [];
    exit(json_encode(['list' => $list, 'title' => (string)($o['title'] ?? ''), 'channel' => (string)($o['author_name'] ?? ''), 'count' => count($pl),
        'items' => array_map(fn($x) => ['id' => $x[0], 'title' => $x[1]], array_values(array_slice($pl, 0, 200)))], JSON_UNESCAPED_UNICODE));
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $back = '/admin/shop-products.php';
    try {
        if ($a === 'save') {
            $title = trim((string)($_POST['title'] ?? ''));
            if ($title === '') throw new RuntimeException('Enter the name.');
            $type = ($_POST['type'] ?? '') === 'notes' ? 'notes' : 'course';
            $price = round(max(0, (float)($_POST['price'] ?? 0)), 2);
            $mrp = trim((string)($_POST['mrp'] ?? '')) === '' ? null : round(max(0, (float)$_POST['mrp']), 2);
            if ($mrp !== null && $mrp <= $price) $mrp = null; // a "was" price only when it is higher
            $status = in_array($_POST['status'] ?? '', ['draft', 'published', 'hidden'], true) ? $_POST['status'] : 'draft';
            $days = max(0, min(3650, (int)($_POST['validity_days'] ?? 0)));
            $slugIn = trim((string)($_POST['slug'] ?? ''));
            $slug = sh_unique_slug($pdo, $slugIn !== '' ? $slugIn : $title, $id);
            $thumb = $id ? (string)$pdo->query("SELECT thumb FROM sh_products WHERE id = $id")->fetchColumn() : '';
            if (!empty($_FILES['thumb']['name'])) $thumb = shp_thumb($_FILES['thumb'], $slug);
            if (!empty($_POST['thumb_remove'])) $thumb = '';
            $cat = (int)($_POST['category_id'] ?? 0); $cat = $cat && isset(sh_categories($pdo, true)[$cat]) ? $cat : null;
            $starts = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['starts_at'] ?? '')) ? (string)$_POST['starts_at'] : null;
            $teacher = (int)($_POST['teacher_id'] ?? 0); $teacher = $teacher && isset(sh_teachers($pdo)[$teacher]) ? $teacher : null;
            $faq = [];
            foreach ((array)($_POST['faq_q'] ?? []) as $i => $q) { $q = trim((string)$q); $an = trim((string)($_POST['faq_a'][$i] ?? '')); if ($q !== '' && $an !== '') $faq[] = ['q' => mb_substr($q, 0, 300), 'a' => mb_substr($an, 0, 3000)]; }
            $inc = array_values(array_filter(array_map(fn($v) => mb_substr(trim((string)$v), 0, 200), (array)($_POST['inc'] ?? [])), fn($v) => $v !== ''));
            $vals = [$type, mb_substr($title, 0, 200), $slug, mb_substr(trim((string)($_POST['subtitle'] ?? '')), 0, 300) ?: null, trim((string)($_POST['description'] ?? '')) ?: null,
                $thumb ?: null, $price, $mrp, $days, $status, (int)($_POST['sort'] ?? 0), $cat, $starts, $teacher, $faq ? json_encode($faq, JSON_UNESCAPED_UNICODE) : null, $inc ? json_encode($inc, JSON_UNESCAPED_UNICODE) : null];
            if ($id) { $vals[] = $id; $pdo->prepare("UPDATE sh_products SET type=?, title=?, slug=?, subtitle=?, description=?, thumb=?, price=?, mrp=?, validity_days=?, status=?, sort=?, category_id=?, starts_at=?, teacher_id=?, faq=?, includes=?, updated_at=NOW() WHERE id=?")->execute($vals); }
            else { $pdo->prepare("INSERT INTO sh_products (type, title, slug, subtitle, description, thumb, price, mrp, validity_days, status, sort, category_id, starts_at, teacher_id, faq, includes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute($vals); $id = (int)$pdo->lastInsertId(); }
            admin_log('shop_product', "Saved store product #$id $title");
            // a YouTube playlist: its videos become the lectures (a chapter follows the playlist)
            $ytMsg = ''; $ytOk = false;
            if ($type === 'course' && trim((string)($_POST['yt_url'] ?? '')) !== '') {
                try { [, $nAdd] = sh_link_playlist($pdo, $id, (string)$_POST['yt_url'], (int)($_POST['yt_section'] ?? 0), trim((string)($_POST['yt_chapter'] ?? '')), (int)($_POST['yt_free'] ?? 1));
                    $ytOk = true; $ytMsg = ' YouTube playlist linked — ' . $nAdd . ' video' . ($nAdd === 1 ? '' : 's') . ' added as lectures.'; }
                catch (RuntimeException $ye) { $ytMsg = ' The playlist was not linked: ' . $ye->getMessage(); }
            }
            admin_flash((isset($_POST['go_content']) ? 'Saved. Now add the ' . ($type === 'course' ? 'lectures' : 'PDFs') . '.' : 'Saved.') . $ytMsg, $ytMsg !== '' && !$ytOk ? 'error' : 'success');
            $back = $ytOk ? '/admin/shop-products.php?edit=' . $id . '&type=' . $type . '#lectures'
                : (isset($_POST['go_content']) ? ($type === 'course' ? '/admin/shop-lecture.php?course=' . $id : '/admin/shop-products.php?edit=' . $id . '&type=' . $type . '#lectures') : (!empty($_POST['stay']) ? '/admin/shop-products.php?edit=' . $id . '&type=' . $type : '/admin/shop-products.php?type=' . $type));
        } elseif (in_array($a, ['pl_add', 'pl_refresh', 'pl_save', 'pl_delete'], true)) { // single YouTube playlist courses (free, shown on /free-courses)
            $back = '/admin/shop-products.php?type=course#playlists';
            if ($a === 'pl_add' || $a === 'pl_refresh') {
                $url = $a === 'pl_add' ? trim((string)($_POST['url'] ?? '')) : (string)$pdo->query("SELECT url FROM hm_free WHERE id = $id")->fetchColumn();
                $i = sh_yt_free_info($url);
                if (!empty($i['error'])) throw new RuntimeException($i['error']);
                if ($a === 'pl_add') {
                    $pdo->prepare("INSERT INTO hm_free (url, list_id, video_id, title, thumb, channel, videos, category_id, sort, active) VALUES (?,?,?,?,?,?,?,?,?,1)")->execute([mb_substr($url, 0, 500), $i['list'] ?: null, $i['video'] ?: null,
                        mb_substr(trim((string)($_POST['title'] ?? '')) ?: $i['title'], 0, 250), $i['thumb'] ?: null, mb_substr((string)$i['channel'], 0, 150) ?: null, $i['videos'], (int)($_POST['category_id'] ?? 0) ?: null, (int)($_POST['sort'] ?? 0)]);
                    if (($tid = (int)($_POST['teacher_id'] ?? 0)) && isset(sh_teachers($pdo)[$tid])) $pdo->prepare("UPDATE hm_free SET teacher_id = ? WHERE id = ?")->execute([$tid, (int)$pdo->lastInsertId()]);
                    admin_flash('Playlist added: "' . $i['title'] . '"' . ($i['videos'] ? ' — ' . ($i['videos'] >= 100 ? '100+' : $i['videos']) . ' videos' : '') . '.');
                } else {
                    $pdo->prepare("UPDATE hm_free SET list_id=?, video_id=?, thumb=?, channel=?, videos=? WHERE id=?")->execute([$i['list'] ?: null, $i['video'] ?: null, $i['thumb'] ?: null, mb_substr((string)$i['channel'], 0, 150) ?: null, $i['videos'], $id]);
                    admin_flash('Fetched again from YouTube.');
                }
            } elseif ($a === 'pl_save') {
                $tid = (int)($_POST['teacher_id'] ?? 0);
                $pdo->prepare("UPDATE hm_free SET title=?, category_id=?, teacher_id=?, sort=?, active=? WHERE id=?")->execute([mb_substr(trim((string)($_POST['title'] ?? '')), 0, 250) ?: 'Free course', (int)($_POST['category_id'] ?? 0) ?: null,
                    $tid && isset(sh_teachers($pdo)[$tid]) ? $tid : null, (int)($_POST['sort'] ?? 0), !empty($_POST['active']) ? 1 : 0, $id]);
                admin_flash('Saved.');
            } else { $pdo->prepare("DELETE FROM hm_free WHERE id = ?")->execute([$id]); admin_flash('Playlist course removed.'); }
            if (function_exists('sh_home_refresh')) sh_home_refresh();
        } elseif ($a === 'delete' && $id) {
            $sold = (int)$pdo->query("SELECT (SELECT COUNT(*) FROM sh_access WHERE product_id = $id) + (SELECT COUNT(*) FROM sh_orders WHERE product_id = $id AND status = 'paid')")->fetchColumn();
            if ($sold) throw new RuntimeException('People have bought or been given this item, so it cannot be deleted. Set it to Hidden instead.');
            $media = [];
            foreach ($pdo->query("SELECT video_file, variants, pdf_file FROM sh_lessons WHERE product_id = $id")->fetchAll(PDO::FETCH_ASSOC) as $l) foreach (array_filter(array_merge([$l['video_file'], $l['pdf_file']], array_values(sh_variants($l)))) as $f) $media[] = $f;
            foreach ($pdo->query("SELECT file FROM sh_files WHERE product_id = $id")->fetchAll(PDO::FETCH_COLUMN) as $f) $media[] = $f;
            foreach (['sh_lessons', 'sh_sections', 'sh_units', 'sh_files', 'sh_orders', 'sh_reviews', 'sh_doubts'] as $t) $pdo->exec("DELETE FROM $t WHERE product_id = $id");
            $pdo->exec("DELETE FROM sh_products WHERE id = $id");
            foreach ($media as $f) sh_media_unlink($f);
            admin_log('shop_product', "Deleted store product #$id");
            admin_flash('Deleted.');
        }
    } catch (RuntimeException $e) {
        admin_flash($e->getMessage(), 'error');
        if ($a === 'save') $back = '/admin/shop-products.php?' . ($id ? 'edit=' . $id : 'new=1&type=' . (($_POST['type'] ?? '') === 'notes' ? 'notes' : 'course'));
    }
    header('Location: ' . $back);
    exit;
}

$edit = (int)($_GET['edit'] ?? 0);
$ep0 = $edit ? sh_product($pdo, $edit, false) : null;
$fType = $ep0 ? $ep0['type'] : (($_GET['type'] ?? '') === 'notes' ? 'notes' : 'course'); // two sections: Online Courses and PDF Notes
$isC = $fType === 'course';
$classesL = ci_lists($pdo)['classes'];
$teachers = sh_teachers($pdo);
$fStatus = in_array($_GET['status'] ?? '', ['draft', 'published', 'hidden'], true) ? $_GET['status'] : '';
$w = []; if ($fType) $w[] = "p.type = " . $pdo->quote($fType); if ($fStatus) $w[] = "p.status = " . $pdo->quote($fStatus);
$rows = $pdo->query("SELECT p.*, (SELECT COUNT(*) FROM sh_lessons l WHERE l.product_id = p.id) AS lessons, (SELECT COUNT(*) FROM sh_files f WHERE f.product_id = p.id) AS files,
    (SELECT COUNT(*) FROM sh_access a WHERE a.product_id = p.id AND a.revoked = 0) AS buyers, (SELECT COALESCE(SUM(o.amount),0) FROM sh_orders o WHERE o.product_id = p.id AND o.status = 'paid') AS sales,
    (SELECT COUNT(*) FROM sh_sections s WHERE s.product_id = p.id AND s.yt_list IS NOT NULL) AS ytl
    FROM sh_products p" . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY p.sort, p.id DESC")->fetchAll(PDO::FETCH_ASSOC);
$ep = $ep0;
$epFaq = $ep ? sh_json_list($ep['faq']) : []; $epInc = $ep ? sh_json_list($ep['includes']) : [];
$epLect = $ep ? (int)$pdo->query("SELECT COUNT(*) FROM sh_lessons WHERE product_id = " . (int)$ep['id'])->fetchColumn() : 0;
$open = $ep || isset($_GET['new']);
$badge = fn($s) => '<span class="ci-badge ' . ['published' => 'ci-b-green', 'draft' => 'ci-b-amber', 'hidden' => 'ci-b-grey'][$s] . '">' . ['published' => 'Published', 'draft' => 'Draft', 'hidden' => 'Hidden'][$s] . '</span>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title><?= $isC ? 'Online Courses' : 'PDF Notes' ?> - <?= ci_e(edu_site_name()) ?></title>
<?php admin_shell_head(); ci_head(); ?>
<style>.shp-th{width:72px;height:40px;object-fit:cover;display:block;background:#eef}.shp-thp{width:72px;height:40px;display:grid;place-items:center;background:linear-gradient(135deg,#0c6878,#4a3369);color:#fff;font-size:10px;font-weight:700}
.shp-prev{display:flex;gap:10px;align-items:center;margin-top:8px}.shp-prev img{width:120px;height:68px;object-fit:cover}
.shp-rep{display:flex;flex-direction:column;gap:8px}.shp-row{display:grid;grid-template-columns:1fr 34px;gap:8px;align-items:start;border:1px solid #e5e7eb;padding:10px;background:#fafafa}
.shp-row .shp-qa{display:flex;flex-direction:column;gap:6px}.shp-row input,.shp-row textarea{width:100%}.shp-sec{margin-top:18px;padding-top:14px;border-top:1px solid #eef0f3}.shp-sec > b{display:block;font-size:15px;margin-bottom:8px}
.shp-yt .meta-panel-body input[name=yt_url],.shp-yt input[name=yt_chapter]{width:100%}
.shp-yt-on{border-color:#fca5a5;box-shadow:0 0 0 3px #fee2e2}
.shp-ylinked{font-size:13px;margin:0 0 8px;padding:7px 10px;background:#f0fdf4;border:1px solid #bbf7d0}.shp-ylinked i{color:#15803d}
.shp-lect-h{display:flex;align-items:center;justify-content:space-between;gap:10px;margin:26px 0 12px;padding-top:18px;border-top:2px solid #e5e7eb}.shp-lect-h h2{margin:0;font-size:19px}
.shp-ytb{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:700;color:#b91c1c;background:#fef2f2;border:1px solid #fecaca;padding:1px 6px;vertical-align:middle}
.shp-yrow{display:flex;gap:8px}.shp-yrow input{flex:1}
.shp-ypv{margin-top:10px;border:1px solid #e5e7eb;background:#fafafa}
.shp-ypv .yh{display:flex;justify-content:space-between;gap:10px;padding:8px 10px;border-bottom:1px solid #e5e7eb;font-size:13px}.shp-ypv .yh b{color:#15803d}
.shp-ypv ol{margin:0;padding:6px 10px 8px 34px;max-height:280px;overflow:auto;font-size:13px}.shp-ypv li{padding:3px 0}
.shp-ypv .ye{padding:8px 10px;color:#b91c1c;font-size:13px}
.shp-count{display:inline-flex;gap:6px;align-items:center;font-weight:700;color:#0c6878;background:#eef7f8;padding:8px 12px}</style>
</head>
<body>
<?php admin_shell_open($open ? ($ep ? ($isC ? 'Edit course' : 'Edit notes') : ($isC ? (isset($_GET['yt']) ? 'New YouTube course' : 'Add a new course') : 'Add a notes pack')) : ($isC ? 'Online Courses' : 'PDF Notes'), $open ? ($ep ? ci_e($ep['title']) : ($isC ? 'A recorded video course for the website' : 'A PDF notes pack for the website')) : ($isC ? 'Recorded video courses sold on the website' : 'PDF notes packs sold on the website'), $open ? '<a class="ci-btn ci-ghost" href="/admin/shop-products.php?type=' . $fType . '"><i class="fas fa-arrow-left"></i> All ' . ($isC ? 'courses' : 'notes') . '</a>' : '<a class="ci-btn ci-ghost" href="' . ($isC ? '/store/' : '/notes/') . '" target="_blank"><i class="fas fa-store"></i> View on site</a>' . ($isC ? '<a class="ci-btn ci-ghost" href="/admin/shop-lecture.php"><i class="fas fa-film"></i> Add Lecture</a><a class="ci-btn ci-ghost" href="?new=1&amp;type=course&amp;yt=1"><i class="fab fa-youtube" style="color:#dc2626"></i> Course</a>' : '') . '<a class="ci-btn" href="?new=1&type=' . $fType . '"><i class="fas fa-plus"></i> ' . ($isC ? 'Add Course' : 'Add Notes') . '</a>'); ?>
<div class="ci">
<?= admin_flash() ?>
<?php if (!sh_rzp_ready() && !sh_pay_demo()): ?><div class="ci-alert ci-alert-err">Online payment is not set up yet — buyers cannot pay until the Razorpay keys are saved in <a href="/admin/shop-settings.php">Store Settings</a>. Free items work already.</div><?php endif; ?>

<?php if ($open): $pv = (float)($ep['price'] ?? 0); $mv = isset($ep['mrp']) && $ep['mrp'] !== null ? (float)$ep['mrp'] : null; $base = preg_replace('#^https?://#', '', rtrim(SITE_URL, '/')) . ($isC ? '/courses/' : '/notes/'); ?>
<form method="post" enctype="multipart/form-data" class="sx-form"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($ep['id'] ?? 0) ?>">
<input type="hidden" name="type" value="<?= $fType ?>">
<div class="editor-layout sx-layout">
  <div class="editor-main">
    <div class="meta-panel"><div class="meta-panel-body sx-titlebox">
      <input type="text" name="title" id="sxTitle" class="post-title-input" required maxlength="200" placeholder="<?= $isC ? 'Course name — e.g. Class 10 Maths — Full Course' : 'Notes name — e.g. Class 10 Maths Formula Notes' ?>" value="<?= ci_e($ep['title'] ?? '') ?>">
      <input type="text" name="subtitle" class="sx-sub" maxlength="300" placeholder="Short line shown under the name — e.g. All chapters with solved examples, in Hindi" value="<?= ci_e($ep['subtitle'] ?? '') ?>">
<?php if (!$isC): ?>      <div class="sx-link"><span>Web address:</span> <span class="ci-muted"><?= ci_e($base) ?></span><input name="slug" id="sxSlug" maxlength="150" placeholder="made-from-the-name" value="<?= ci_e($ep['slug'] ?? '') ?>"></div><?php endif; ?>
    </div></div>
    <?php if ($isC): $epYt = $ep ? $pdo->query("SELECT s.id, s.title, s.yt_list, s.yt_synced, (SELECT COUNT(*) FROM sh_lessons l WHERE l.section_id = s.id) n FROM sh_sections s WHERE s.product_id = " . (int)$ep['id'] . " AND s.yt_list IS NOT NULL ORDER BY s.sort, s.id")->fetchAll(PDO::FETCH_ASSOC) : []; $epSecs = $ep ? $pdo->query("SELECT id, title FROM sh_sections WHERE product_id = " . (int)$ep['id'] . " ORDER BY sort, id")->fetchAll(PDO::FETCH_KEY_PAIR) : []; ?>
    <div class="meta-panel shp-yt<?= isset($_GET['yt']) ? ' shp-yt-on' : '' ?>" id="ytPanel"><div class="meta-panel-header"><span><i class="fab fa-youtube" style="color:#dc2626"></i> YouTube playlist</span><small class="ci-muted">its videos become the lectures — new ones come in by themselves</small></div><div class="meta-panel-body">
      <?php foreach ($epYt as $y): ?><div class="shp-ylinked"><i class="fas fa-link"></i> <b><?= ci_e($y['title']) ?></b> <span class="ci-muted">· <?= (int)$y['n'] ?> lectures<?= $y['yt_synced'] ? ' · checked ' . ci_e(date('d M, h:i A', strtotime($y['yt_synced']))) : '' ?></span> <a href="https://www.youtube.com/playlist?list=<?= ci_e($y['yt_list']) ?>" target="_blank" rel="noopener">open on YouTube</a></div><?php endforeach; ?>
      <div class="shp-yrow"><input name="yt_url" id="ytUrl" maxlength="500" placeholder="<?= $epYt ? 'Add another playlist — https://www.youtube.com/playlist?list=…' : 'https://www.youtube.com/playlist?list=…' ?>"<?= isset($_GET['yt']) ? ' autofocus' : '' ?>><button type="button" class="ci-btn ci-ghost" id="ytFetch"><i class="fas fa-magnifying-glass"></i> Fetch videos</button></div>
      <div class="sx-two" style="margin-top:8px">
        <div><label>Put its videos in</label><select name="yt_section"><option value="0">A new chapter</option><?php foreach ($epSecs as $k => $t): ?><option value="<?= (int)$k ?>"><?= ci_e($t) ?></option><?php endforeach; ?></select></div>
        <div><label>Free preview</label><select name="yt_free"><option value="0">None</option><option value="1" selected>First video</option><option value="2">First 2 videos</option><option value="3">First 3 videos</option></select></div>
      </div>
      <input name="yt_chapter" maxlength="200" style="margin-top:8px" placeholder="New chapter name (empty = the playlist's name)">
      <div class="shp-ypv" id="ytPrev" hidden></div>
      <small class="ci-muted" style="display:block;margin-top:6px">Make a playlist on YouTube for this course and keep your videos <b>Unlisted</b> in it (the playlist itself Public or Unlisted). Saving adds every video as a lecture; videos you add to the playlist later come in by themselves. After saving, every lecture in the Lectures section below has its own <b>Notes PDF</b>, <b>Quiz / MCQ</b> and <b>VVI questions</b> buttons — they open right on this page.</small>
    </div></div>
    <div class="meta-panel"><div class="meta-panel-header"><span>Web address</span></div><div class="meta-panel-body"><div class="sx-link"><span>Web address:</span> <span class="ci-muted"><?= ci_e($base) ?></span><input name="slug" id="sxSlug" maxlength="150" placeholder="made-from-the-name" value="<?= ci_e($ep['slug'] ?? '') ?>"></div></div></div>
    <?php endif; ?>
    <div class="meta-panel"><div class="meta-panel-header"><span><?= $isC ? 'About this course' : 'About these notes' ?></span><small class="ci-muted">what students will learn</small></div><div class="meta-panel-body">
      <textarea name="description" rows="9" maxlength="20000" placeholder="Describe the <?= $isC ? 'course' : 'notes' ?> — chapters, who it is for, how it helps."><?= ci_e($ep['description'] ?? '') ?></textarea>
    </div></div>
    <div class="meta-panel"><div class="meta-panel-header"><span>What students get</span><small class="ci-muted">shown in "What's included"</small></div><div class="meta-panel-body">
      <div class="shp-rep" id="shpInc"><?php foreach ($epInc ?: [''] as $v): ?><div class="shp-row"><input name="inc[]" maxlength="200" placeholder="e.g. Doubt solving by the teacher" value="<?= ci_e($v) ?>"><button type="button" class="ci-ico" data-i="trash" aria-label="Remove" data-rm><?= ci_svg('trash') ?></button></div><?php endforeach; ?></div>
      <div><button type="button" class="ci-btn ci-ghost ci-sm" data-add="shpInc"><i class="fas fa-plus"></i> Add point</button></div>
    </div></div>
    <div class="meta-panel"><div class="meta-panel-header"><span>FAQ</span><small class="ci-muted">shown first in "Frequently asked questions"</small></div><div class="meta-panel-body">
      <div class="shp-rep" id="shpFaq"><?php foreach ($epFaq ?: [['q' => '', 'a' => '']] as $f): ?><div class="shp-row"><div class="shp-qa"><input name="faq_q[]" maxlength="300" placeholder="Question — e.g. Is this course in Hindi?" value="<?= ci_e($f['q'] ?? '') ?>"><textarea name="faq_a[]" rows="2" maxlength="3000" placeholder="Answer"><?= ci_e($f['a'] ?? '') ?></textarea></div><button type="button" class="ci-ico" data-i="trash" aria-label="Remove" data-rm><?= ci_svg('trash') ?></button></div><?php endforeach; ?></div>
      <div><button type="button" class="ci-btn ci-ghost ci-sm" data-add="shpFaq"><i class="fas fa-plus"></i> Add question</button></div>
    </div></div>
  </div>
  <div class="editor-sidebar-panel">
    <div class="meta-panel"><div class="meta-panel-header"><span>Publish</span></div><div class="meta-panel-body">
      <div><label>Status</label><select name="status"><?php foreach (['draft' => 'Draft', 'published' => 'Published', 'hidden' => 'Hidden'] as $k => $l): ?><option value="<?= $k ?>" <?= ($ep['status'] ?? 'draft') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
      <div><label>Order on the site</label><input name="sort" type="number" value="<?= (int)($ep['sort'] ?? 0) ?>"></div>
      <?php if ($ep): ?><a class="sx-view" href="<?= ci_e(sh_url($ep)) ?>" target="_blank"><i class="fas fa-arrow-up-right-from-square"></i> View on site</a><?php endif; ?>
      <div class="sx-pub"><button class="ci-btn ci-ghost" type="submit" name="stay" value="1">Save</button><button class="ci-btn" type="submit" name="go_content" value="1"><i class="fas fa-check"></i> Save &amp; add <?= $isC ? 'lectures' : 'PDFs' ?></button></div>
      <a class="sx-cancel" href="/admin/shop-products.php?type=<?= $fType ?>">Cancel</a>
    </div></div>
    <div class="meta-panel"><div class="meta-panel-header"><span>Price</span></div><div class="meta-panel-body">
      <div class="sx-two"><div><label>Original (₹)</label><input name="mrp" id="sxMrp" type="number" min="0" step="1" value="<?= $mv !== null ? ci_e($mv) : '' ?>" placeholder="struck out"></div>
      <div><label>Sale price (₹) <span class="req-star">*</span></label><input name="price" id="sxPrice" type="number" min="0" step="1" required value="<?= $ep ? ci_e($pv) : '' ?>" placeholder="0 = free"></div></div>
      <div class="sx-price" id="sxPriceView"></div>
    </div></div>
    <div class="meta-panel"><div class="meta-panel-header"><span>Picture</span></div><div class="meta-panel-body">
      <label class="sx-thumb" id="sxThumb"><?php if (!empty($ep['thumb'])): ?><img src="<?= ci_e($ep['thumb']) ?>" alt=""><?php else: ?><span><i class="fas fa-image"></i> Choose a picture<small>16:9 · JPG, PNG or WebP · up to 3 MB</small></span><?php endif; ?><input type="file" name="thumb" accept="image/jpeg,image/png,image/webp" hidden></label>
      <?php if (!empty($ep['thumb'])): ?><label style="font-weight:400;display:flex;gap:6px;align-items:center;margin:0"><input type="checkbox" name="thumb_remove" value="1"> Remove picture</label><?php endif; ?>
    </div></div>
    <div class="meta-panel"><div class="meta-panel-header"><span>Category</span><a href="/admin/shop-categories.php" target="_blank" style="font-size:12px;font-weight:500">Manage</a></div><div class="meta-panel-body">
      <select name="category_id"><option value="0">— No category —</option><?php foreach (sh_categories($pdo, true) as $k => $c): ?><option value="<?= $k ?>" <?= (int)($ep['category_id'] ?? 0) === (int)$k ? 'selected' : '' ?>><?= ci_e($c['name']) ?></option><?php endforeach; ?></select>
    </div></div>
    <?php if ($isC): ?><div class="meta-panel"><div class="meta-panel-header"><span>Teacher</span></div><div class="meta-panel-body">
      <select name="teacher_id"><option value="0">— Choose a teacher —</option><?php foreach ($teachers as $k => $l): ?><option value="<?= $k ?>" <?= (int)($ep['teacher_id'] ?? 0) === $k ? 'selected' : '' ?>><?= ci_e($l) ?></option><?php endforeach; ?></select>
      <?php if (!$teachers): ?><small class="ci-muted">Give a user the Teacher permission: Users → Advance Access → Online Store.</small><?php endif; ?>
    </div></div><?php endif; ?>
    <div class="meta-panel"><div class="meta-panel-header"><span><?= $isC ? 'Batch &amp; access' : 'Access' ?></span></div><div class="meta-panel-body">
      <?php if ($isC): ?><div><label>Batch starts on</label><input type="date" name="starts_at" value="<?= ci_e($ep['starts_at'] ?? '') ?>"><small class="ci-muted">A future date shows it as an Upcoming batch.</small></div><?php endif; ?>
      <div><label>Access for</label><select name="validity_days"><?php $vd = (int)($ep['validity_days'] ?? 0); $opts = [0 => 'Lifetime', 30 => '1 month', 90 => '3 months', 180 => '6 months', 365 => '1 year', 730 => '2 years']; if (!isset($opts[$vd])) $opts[$vd] = $vd . ' days'; foreach ($opts as $k => $l): ?><option value="<?= $k ?>" <?= $vd === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    </div></div>
  </div>
</div>
</form>
<?php if ($ep): $p = $ep; ?>
<div class="shp-lect-h" id="lectures"><h2><i class="fas <?= $isC ? 'fa-film' : 'fa-file-pdf' ?>"></i> <?= $isC ? 'Lectures' : 'PDF files' ?></h2><?php if ($isC): ?><a class="ci-btn" data-embed href="/admin/shop-lecture.php?course=<?= (int)$ep['id'] ?>&amp;embed=1"><i class="fas fa-plus"></i> Add lecture</a><?php endif; ?></div>
<?php require __DIR__ . '/components/course-lectures.php'; ?>
<?php endif; ?>
<script>
(function () { // YouTube playlist: show its videos before saving; fill the course name if it is empty
  var b = document.getElementById('ytFetch'), u = document.getElementById('ytUrl'), pv = document.getElementById('ytPrev'); if (!b) return;
  function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : t; return d.innerHTML; }
  function go() {
    var v = u.value.trim(); if (!v) { u.focus(); return; }
    pv.hidden = false; pv.innerHTML = '<div class="yh">Fetching the playlist…</div>';
    fetch('/admin/shop-products.php?ytpreview=' + encodeURIComponent(v), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      if (d.error) { pv.innerHTML = '<div class="ye">✗ ' + esc(d.error) + '</div>'; return; }
      pv.innerHTML = '<div class="yh"><span><b>✓ ' + d.count + ' video' + (d.count === 1 ? '' : 's') + '</b> · ' + esc(d.title) + (d.channel ? ' · ' + esc(d.channel) : '') + '</span><span class="ci-muted">They become the lectures when you save</span></div><ol>' + d.items.map(function (it) { return '<li>' + esc(it.title || it.id) + '</li>'; }).join('') + '</ol>';
      var t = document.getElementById('sxTitle'); if (t && !t.value.trim() && d.title) { t.value = d.title; t.dispatchEvent(new Event('input', { bubbles: true })); }
    }).catch(function () { pv.innerHTML = '<div class="ye">✗ Could not fetch the playlist right now.</div>'; });
  }
  b.addEventListener('click', go); u.addEventListener('paste', function () { setTimeout(go, 60); });
})();
(function () {
  var t = document.getElementById('sxTitle'), sl = document.getElementById('sxSlug'), touched = sl.value !== '';
  sl.addEventListener('input', function () { touched = true; });
  t.addEventListener('input', function () { if (!touched) sl.placeholder = t.value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'made-from-the-name'; });
  var mrp = document.getElementById('sxMrp'), pr = document.getElementById('sxPrice'), pv = document.getElementById('sxPriceView');
  function price() { var a = +pr.value, m = +mrp.value; if (pr.value === '') { pv.innerHTML = ''; return; } pv.innerHTML = a <= 0 ? '<b>Free</b>' : '<b>₹' + a.toLocaleString('en-IN') + '</b>' + (m > a ? ' <s>₹' + m.toLocaleString('en-IN') + '</s> <em>' + Math.round(100 - a * 100 / m) + '% off</em>' : ''); }
  pr.addEventListener('input', price); mrp.addEventListener('input', price); price();
  var box = document.getElementById('sxThumb'), fi = box.querySelector('input[type=file]');
  fi.addEventListener('change', function () { var f = fi.files[0]; if (!f) return; [].forEach.call(box.querySelectorAll('img, span'), function (x) { x.remove(); }); var i = new Image(); i.src = URL.createObjectURL(f); box.insertBefore(i, fi); });
})();
</script>
<?php endif; ?>

<?php if (!$open): ?>
<form class="ci-filters" method="get"><input type="hidden" name="type" value="<?= $fType ?>"><div class="ci-f"><label>Status</label><select name="status" onchange="this.form.submit()"><option value="">All</option><?php foreach (['published' => 'Published', 'draft' => 'Draft', 'hidden' => 'Hidden'] as $k => $l): ?><option value="<?= $k ?>" <?= $fStatus === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div></form>
<div class="ci-card"><div class="ci-card-b ci-p0 ci-table-wrap"><table class="ci-table">
<thead><tr><th><?= $isC ? 'Course' : 'Notes' ?></th><th><?= $isC ? 'Teacher' : 'Category' ?></th><th>Inside</th><th class="ci-num">Price</th><th>Access</th><th class="ci-num">Buyers</th><th class="ci-num">Sales</th><th>Status</th><th>Action</th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="9" class="ci-empty">No products yet. Add your first course or notes.</td></tr><?php endif; ?>
<?php foreach ($rows as $p): ?>
<tr><td><div style="display:flex;gap:10px;align-items:center"><?= $p['thumb'] ? '<img class="shp-th" src="' . ci_e($p['thumb']) . '" alt="">' : '<span class="shp-thp">' . ($p['type'] === 'notes' ? 'PDF' : 'VIDEO') . '</span>' ?><div><b><?= ci_e($p['title']) ?></b><?= (int)$p['ytl'] ? ' <span class="shp-ytb" title="Its lectures follow a YouTube playlist"><i class="fab fa-youtube"></i> Playlist</span>' : '' ?><?= $p['subtitle'] ? '<small class="ci-muted" style="display:block">' . ci_e(mb_strimwidth($p['subtitle'], 0, 70, '…')) . '</small>' : '' ?></div></div></td>
<td><?= $p['type'] === 'notes' ? ci_e(sh_category_name($pdo, (int)$p['category_id']) ?: '—') : '' ?><?= $p['type'] === 'course' ? ($p['teacher_id'] ? '' : '<span class="ci-muted">—</span>') : '' ?><?= $p['type'] === 'course' && $p['teacher_id'] ? ci_e($teachers[(int)$p['teacher_id']] ?? '—') : '' ?></td>
<td class="ci-nw"><a href="/admin/shop-products.php?edit=<?= (int)$p['id'] ?>&amp;type=<?= $p['type'] ?>#lectures"><?= $p['type'] === 'course' ? (int)$p['lessons'] . ' videos' . ((int)$p['files'] ? ' · ' . (int)$p['files'] . ' PDFs' : '') : (int)$p['files'] . ' PDFs' ?></a></td>
<td class="ci-num"><?= (float)$p['price'] > 0 ? ci_money0($p['price']) : 'Free' ?><?= $p['mrp'] !== null ? '<small class="ci-muted" style="display:block;text-decoration:line-through">' . ci_money0($p['mrp']) . '</small>' : '' ?></td>
<td class="ci-nw"><?= (int)$p['validity_days'] ? (int)$p['validity_days'] . ' days' : 'Lifetime' ?></td>
<td class="ci-num"><?= (int)$p['buyers'] ?></td><td class="ci-num"><?= ci_money0($p['sales']) ?></td><td><?= $badge($p['status']) ?></td>
<td><div class="ci-icos"><?= ci_ico('edit', '?edit=' . (int)$p['id'], 'Edit') ?><?= $p['type'] === 'course' ? ci_ico('plus', '/admin/shop-lecture.php?course=' . (int)$p['id'], 'Add lecture') : '' ?><?= ci_ico('book-plus', '/admin/shop-products.php?edit=' . (int)$p['id'] . '&type=' . $p['type'] . '#lectures', $p['type'] === 'course' ? 'Chapters & lectures' : 'PDFs') ?><?= ci_ico('eye', sh_url($p) . '&preview=1', 'View on the store', 'link', ' target="_blank"') ?>
<?php if (!(int)$p['buyers']): ?><?= ci_post_ico('trash', 'Delete', ['action' => 'delete', 'id' => $p['id']], 'Delete ' . $p['title'] . ' with all its videos and PDFs?') ?><?php endif; ?></div></td></tr>
<?php endforeach; ?>
</tbody></table></div></div>
<?php if ($isC): $pls = $pdo->query("SELECT * FROM hm_free ORDER BY sort, id DESC")->fetchAll(PDO::FETCH_ASSOC); $plCats = sh_categories($pdo, true); ?>
<div class="ci-card" id="playlists"><div class="ci-card-h"><h3><i class="fab fa-youtube" style="color:#dc2626"></i> YouTube playlist courses (free) <span class="ci-muted" style="font-weight:400;font-size:13px">· <?= count($pls) ?> — shown on <a href="/free-courses/" target="_blank">Free Courses</a></span></h3><button type="button" class="ci-btn ci-sm" data-mopen="#plForm" data-mtitle="Add a playlist course" data-fill='{"id":0,"active":1}'><i class="fas fa-plus"></i> Add Playlist</button></div>
<div class="ci-card-b ci-p0 ci-table-wrap"><table class="ci-table"><thead><tr><th>Playlist course</th><th>Teacher</th><th>Category</th><th class="ci-num">Videos</th><th>Order</th><th>Status</th><th>Action</th></tr></thead><tbody>
<?php if (!$pls): ?><tr><td colspan="7" class="ci-empty">No playlist course yet. Press <b>Add Playlist</b> and paste a YouTube playlist link.</td></tr><?php endif; ?>
<?php foreach ($pls as $f): $fthumb = !empty($f['thumb_custom']) ? sh_upload_url($f['thumb_custom']) : (string)$f['thumb']; ?>
<tr><td><div style="display:flex;gap:10px;align-items:center"><?= $fthumb !== '' ? '<img class="shp-th" src="' . ci_e($fthumb) . '" alt="" loading="lazy">' : '<span class="shp-thp">YOUTUBE</span>' ?><div><b><?= ci_e($f['title']) ?></b> <span class="shp-ytb"><i class="fab fa-youtube"></i> Playlist</span><small class="ci-muted" style="display:block"><?= ci_e($f['channel'] ?: 'YouTube') ?></small></div></div></td>
<td><?= $f['teacher_id'] ? ci_e($teachers[(int)$f['teacher_id']] ?? '—') : '<span class="ci-muted">—</span>' ?></td>
<td><?= $f['category_id'] && isset($plCats[(int)$f['category_id']]) ? ci_e($plCats[(int)$f['category_id']]['name']) : '<span class="ci-muted">—</span>' ?></td>
<td class="ci-num"><?= $f['videos'] ? ((int)$f['videos'] >= 100 ? '100+' : (int)$f['videos']) : '—' ?></td><td><?= (int)$f['sort'] ?></td>
<td><?= $f['active'] ? '<span class="ci-badge ci-b-green">Shown</span>' : '<span class="ci-badge ci-b-grey">Hidden</span>' ?></td>
<td><div class="ci-icos"><?= ci_ico('edit', null, 'Edit', 'button', ' data-mopen="#plForm" data-mtitle="Edit playlist course" data-fill="' . ci_e(json_encode(['id' => (int)$f['id'], 'title' => $f['title'], 'category_id' => (int)$f['category_id'], 'teacher_id' => (int)$f['teacher_id'], 'sort' => (int)$f['sort'], 'active' => (int)$f['active'], 'url' => $f['url']], JSON_UNESCAPED_UNICODE)) . '"') ?><?= ci_ico('eye', sh_free_url($f), 'View on site', 'link', ' target="_blank"') ?><?= ci_post_ico('refresh', 'Fetch again from YouTube', ['action' => 'pl_refresh', 'id' => $f['id']]) ?><?= ci_post_ico('trash', 'Delete', ['action' => 'pl_delete', 'id' => $f['id']], 'Remove this playlist course?') ?></div></td></tr>
<?php endforeach; ?>
</tbody></table></div></div>
<div class="ci-modal" id="plForm" data-mform<?= isset($_GET['addpl']) ? ' data-open-now' : '' ?> hidden><div class="ci-modal-box ci-modal-wide" role="dialog" aria-modal="true" aria-labelledby="plH">
<div class="ci-card-h"><h3 id="plH" data-mtitle-target>Add a playlist course</h3><button type="button" class="ci-x" data-close aria-label="Close"><i class="fas fa-times"></i></button></div><div class="ci-card-b">
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="pl_add"><input type="hidden" name="id" value="0">
<div class="ci-grid2 ci-mgrid">
  <div class="ci-f pl-new" style="grid-column:1/-1"><label>YouTube playlist link <span class="ci-req">*</span></label><input name="url" required maxlength="500" placeholder="https://www.youtube.com/playlist?list=…"><span class="ci-hint">Title, picture and number of videos come from YouTube. The playlist must be Public or Unlisted. Students watch it free on the site.</span></div>
  <div class="ci-f" style="grid-column:1/-1"><label>Title <span class="ci-muted" style="font-weight:400">(empty = YouTube's title)</span></label><input name="title" maxlength="250"></div>
  <div class="ci-f"><label>Category</label><select name="category_id"><option value="0">— No category —</option><?php foreach ($plCats as $k => $c): ?><option value="<?= (int)$k ?>"><?= ci_e($c['name']) ?></option><?php endforeach; ?></select></div>
  <div class="ci-f"><label>Teacher</label><select name="teacher_id"><option value="0">— No teacher —</option><?php foreach ($teachers as $k => $l): ?><option value="<?= (int)$k ?>"><?= ci_e($l) ?></option><?php endforeach; ?></select></div>
  <div class="ci-f"><label>Order</label><input name="sort" type="number" value="0"></div>
  <label hidden class="ci-f pl-old" style="display:flex;flex-direction:row;gap:8px;align-items:center;font-weight:600"><input type="checkbox" name="active" value="1"> Show on the site</label>
</div>
<div class="ci-mfoot"><button type="button" class="ci-btn ci-ghost" data-close>Cancel</button><button class="ci-btn" type="submit"><i class="fas fa-check"></i> Save</button></div>
</form></div></div></div>
<script>
document.addEventListener('click', function (e) { // one form for Add (needs the link) and Edit (link stays, Show box)
  var b = e.target.closest('[data-mopen="#plForm"]'); if (!b) return;
  setTimeout(function () { var f = document.querySelector('#plForm form'), edit = +f.id.value > 0;
    f.action.value = edit ? 'pl_save' : 'pl_add'; f.querySelector('.pl-new').hidden = edit; f.url.required = !edit; f.querySelector('.pl-old').hidden = !edit; }, 0);
});
</script>
<?php endif; ?>
<?php endif; ?>
</div>
<?php admin_shell_close(); ?>
<script>
document.addEventListener('click', function (e) {
  var add = e.target.closest('[data-add]'), rm = e.target.closest('[data-rm]');
  if (add) { var box = document.getElementById(add.getAttribute('data-add')), row = box.lastElementChild.cloneNode(true); row.querySelectorAll('input, textarea').forEach(function (f) { f.value = ''; }); box.appendChild(row); row.querySelector('input, textarea').focus(); }
  if (rm) { var r = rm.closest('.shp-row'), b = r.parentNode; if (b.children.length > 1) r.remove(); else r.querySelectorAll('input, textarea').forEach(function (f) { f.value = ''; }); }
});
</script>
</body>
</html>
