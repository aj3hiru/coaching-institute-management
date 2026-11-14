<?php
// Online store → Products: video courses and PDF notes packs (price, access period, thumbnail, status).
// A course is made here with its details and an intro video; its YouTube playlist, chapters and lectures are arranged
// on the Lectures page (admin/shop-lectures.php). Notes packs keep their PDF files below the form.
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/institute.php';
require_once INCLUDES_PATH . '/shop.php';
$me = sh_page_start($pdo, ['products']);
sh_tables($pdo);

/** Saves an uploaded thumbnail like a post's featured image: WebP, 16:9 (1200 × 675, centre crop) + smaller sizes, light
    (about 100 KB); JPG / PNG / WebP up to 8 MB. Without WebP support on the server the picture is kept as it is. */
function shp_thumb(array $f, string $slug): string
{
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('The picture could not be uploaded (it may be too large).');
    if ($f['size'] > 8 * 1024 * 1024) throw new RuntimeException('The picture is larger than 8 MB.');
    $info = @getimagesize($f['tmp_name']);
    $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2] ?? 0] ?? null;
    if (!$ext) throw new RuntimeException('The picture must be a JPG, PNG or WebP image.');
    $dir = ROOT_PATH . '/uploads/store';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new RuntimeException('The uploads folder is not writable.');
    $base = substr(preg_replace('/[^a-z0-9-]+/', '-', strtolower($slug)), 0, 60) . '-' . bin2hex(random_bytes(4));
    if (function_exists('imagewebp')) {
        $src = match ($ext) { 'jpg' => @imagecreatefromjpeg($f['tmp_name']), 'png' => @imagecreatefrompng($f['tmp_name']), 'webp' => @imagecreatefromwebp($f['tmp_name']) };
        if ($src && $ext === 'jpg' && function_exists('exif_read_data')) {
            $o = (int)(@exif_read_data($f['tmp_name'])['Orientation'] ?? 1);
            if ($o === 3) $src = imagerotate($src, 180, 0); elseif ($o === 6) $src = imagerotate($src, -90, 0); elseif ($o === 8) $src = imagerotate($src, 90, 0);
        }
        if ($src) {
            require_once INCLUDES_PATH . '/media.php';
            [$path] = edu_media_save_webp($src, $dir, 'uploads/store', $base, true); // the same as a post's featured image
            imagedestroy($src);
            return '/' . $path;
        }
    }
    $name = $base . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) throw new RuntimeException('The picture could not be saved.');
    return '/uploads/store/' . $name;
}

if (isset($_GET['introinfo'])) { // the course intro video: title, picture, length
    header('Content-Type: application/json; charset=utf-8'); session_write_close();
    exit(json_encode(sh_yt_video_info((string)$_GET['introinfo']), JSON_UNESCAPED_UNICODE));
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
            edu_clear_page_cache(); // homepage, lists, category pages and posts with course cards show the new picture / price at once
            if ($type === 'course') { // Free Courses list (the box is ticked by itself when the price is 0)
                $pdo->prepare("UPDATE sh_products SET free_list = ? WHERE id = ?")->execute([!empty($_POST['free_list']) ? 1 : 0, $id]);
            }
            if ($type === 'course') { // batch end date
                $ends = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['ends_at'] ?? '')) ? (string)$_POST['ends_at'] : null;
                $pdo->prepare("UPDATE sh_products SET ends_at = ? WHERE id = ?")->execute([$ends, $id]);
            }
            if ($type === 'course') { // intro video (any link; YouTube shows its picture)
                $intro = trim((string)($_POST['intro_url'] ?? ''));
                if ($intro !== '' && !preg_match('#^https?://\S+$#i', $intro)) $intro = 'https://' . ltrim($intro, '/');
                $pdo->prepare("UPDATE sh_products SET intro_url = ? WHERE id = ?")->execute([$intro !== '' ? mb_substr($intro, 0, 500) : null, $id]);
            }
            admin_flash(isset($_POST['go_content']) ? 'Saved. Now give the course playlist and arrange the ' . ($type === 'course' ? 'lectures' : 'PDFs') . '.' : 'Saved.');
            $back = isset($_POST['go_content']) ? ($type === 'course' ? '/admin/shop-lectures.php?course=' . $id : '/admin/shop-products.php?edit=' . $id . '&type=' . $type . '#lectures') : (!empty($_POST['stay']) ? '/admin/shop-products.php?edit=' . $id . '&type=' . $type : '/admin/shop-products.php?type=' . $type);
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
            if ($me['role'] !== 'admin') throw new RuntimeException('Only an admin can delete.'); // admins: with its access, orders, progress, discussion …
            $media = [];
            foreach ($pdo->query("SELECT video_file, variants, pdf_file FROM sh_lessons WHERE product_id = $id")->fetchAll(PDO::FETCH_ASSOC) as $l) foreach (array_filter(array_merge([$l['video_file'], $l['pdf_file']], array_values(sh_variants($l)))) as $f) $media[] = $f;
            foreach ($pdo->query("SELECT file FROM sh_files WHERE product_id = $id")->fetchAll(PDO::FETCH_COLUMN) as $f) $media[] = $f;
            $pdo->beginTransaction();
            $pdo->exec("DELETE FROM sh_progress WHERE lesson_id IN (SELECT id FROM sh_lessons WHERE product_id = $id)");
            foreach (['sh_access', 'sh_discuss', 'sh_lessons', 'sh_sections', 'sh_units', 'sh_files', 'sh_orders', 'sh_reviews', 'sh_doubts'] as $t) $pdo->exec("DELETE FROM $t WHERE product_id = $id");
            $pdo->exec("DELETE FROM sh_products WHERE id = $id");
            $pdo->commit();
            foreach ($media as $f) sh_media_unlink($f);
            admin_log('shop_product', "Deleted store product #$id");
            admin_flash('Deleted.');
        }
    } catch (PDOException $e) { // a failed delete changes nothing
        if ($pdo->inTransaction()) $pdo->rollBack();
        admin_flash('Could not finish: ' . $e->getMessage(), 'error');
    } catch (RuntimeException $e) {
        admin_flash($e->getMessage(), 'error');
        if ($a === 'save') $back = '/admin/shop-products.php?' . ($id ? 'edit=' . $id : 'new=1&type=' . (($_POST['type'] ?? '') === 'notes' ? 'notes' : 'course'));
    }
    header('Location: ' . $back);
    exit;
}

if (isset($_GET['addpl'])) { header('Location: /admin/shop-playlist.php'); exit; }
$edit = (int)($_GET['edit'] ?? 0);
$ep0 = $edit ? sh_product($pdo, $edit, false) : null;
$fType = $ep0 ? $ep0['type'] : (($_GET['type'] ?? '') === 'notes' ? 'notes' : 'course'); // two sections: Online Courses and PDF Notes
$isC = $fType === 'course';
$classesL = ci_lists($pdo)['classes'];
$teachers = sh_teachers($pdo);
$F = ['q' => trim((string)($_GET['q'] ?? '')), 'status' => (string)($_GET['status'] ?? ''), 'cat' => (int)($_GET['cat'] ?? 0), 'teacher' => (int)($_GET['teacher'] ?? 0),
    'price' => (string)($_GET['price'] ?? ''), 'batch' => array_key_exists('batch', $_GET) ? (string)$_GET['batch'] : 'running', /* Course Status: Running unless another is picked */ 'content' => (string)($_GET['content'] ?? ''), 'access' => (string)($_GET['access'] ?? ''),
    'sales' => (string)($_GET['sales'] ?? ''), 'sort' => (string)($_GET['sort'] ?? '')];
$fStatus = in_array($F['status'], ['draft', 'published', 'hidden'], true) ? $F['status'] : '';
$w = ["p.type = " . $pdo->quote($fType)]; $hv = [];
if ($fStatus) $w[] = "p.status = " . $pdo->quote($fStatus);
if ($F['q'] !== '') $w[] = "(p.title LIKE " . $pdo->quote('%' . $F['q'] . '%') . " OR p.subtitle LIKE " . $pdo->quote('%' . $F['q'] . '%') . " OR p.slug LIKE " . $pdo->quote('%' . $F['q'] . '%') . ")";
if ($F['cat'] === -1) $w[] = "p.category_id IS NULL"; elseif ($F['cat'] > 0) $w[] = "p.category_id = " . $F['cat'];
if ($F['teacher'] === -1) $w[] = "p.teacher_id IS NULL"; elseif ($F['teacher'] > 0) $w[] = "p.teacher_id = " . $F['teacher'];
$w[] = ['free' => 'p.price <= 0', 'paid' => 'p.price > 0', 'offer' => 'p.price > 0 AND p.mrp > p.price'][$F['price']] ?? '1';
if ($isC) $w[] = ['upcoming' => 'p.starts_at >= CURDATE()', 'running' => '(p.starts_at IS NULL OR p.starts_at < CURDATE()) AND (p.ends_at IS NULL OR p.ends_at >= CURDATE())', 'completed' => 'p.ends_at < CURDATE()', 'nodate' => 'p.starts_at IS NULL AND p.ends_at IS NULL'][$F['batch']] ?? '1';
$w[] = ['lifetime' => 'p.validity_days = 0', 'limited' => 'p.validity_days > 0'][$F['access']] ?? '1';
$hv = array_filter([($isC ? ['playlist' => 'ytl > 0', 'noplaylist' => 'ytl = 0 AND lessons > 0', 'empty' => 'lessons = 0', 'pdfs' => 'files > 0'] : ['has' => 'files > 0', 'empty' => 'files = 0'])[$F['content']] ?? '',
    ['buyers' => 'buyers > 0', 'nobuyers' => 'buyers = 0', 'sales' => 'sales > 0'][$F['sales']] ?? '']);
$order = ['new' => 'id DESC', 'old' => 'id ASC', 'az' => 'title', 'za' => 'title DESC', 'buyers' => 'buyers DESC, id DESC', 'sales' => 'sales DESC, id DESC', 'plow' => 'price, id DESC', 'phigh' => 'price DESC, id DESC', 'start' => 'starts_at IS NULL, starts_at, id DESC'][$F['sort']] ?? 'sort, id DESC';
$fOn = count(array_filter($F, fn($v, $k) => $k !== 'sort' && $v !== '' && $v !== 0 && !($k === 'batch' && $v === 'running'), ARRAY_FILTER_USE_BOTH)); // Running is the normal view, not a filter
$rows = $pdo->query("SELECT * FROM (SELECT p.*, (SELECT COUNT(*) FROM sh_lessons l WHERE l.product_id = p.id) AS lessons, (SELECT COUNT(*) FROM sh_files f WHERE f.product_id = p.id) AS files,
    (SELECT COUNT(*) FROM sh_access a WHERE a.product_id = p.id AND a.revoked = 0 AND a.source <> 'block') AS buyers, (SELECT COALESCE(SUM(o.amount),0) FROM sh_orders o WHERE o.product_id = p.id AND o.status = 'paid') AS sales,
    ((SELECT COUNT(*) FROM sh_sections s WHERE s.product_id = p.id AND s.yt_list IS NOT NULL) + (p.yt_list IS NOT NULL)) AS ytl
    FROM sh_products p WHERE " . implode(' AND ', $w) . ") x" . ($hv ? ' WHERE ' . implode(' AND ', $hv) : '') . " ORDER BY $order")->fetchAll(PDO::FETCH_ASSOC);
$fTotal = (int)$pdo->query("SELECT COUNT(*) FROM sh_products WHERE type = " . $pdo->quote($fType))->fetchColumn();
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
.shp-intro{display:flex;gap:12px;align-items:center;margin-top:10px;padding:10px;border:1px solid #e5e7eb;background:#fafbfc}.shp-intro img{width:160px;aspect-ratio:16/9;object-fit:cover;flex:0 0 auto}.shp-intro b{display:block}.shp-intro .ye{color:#b91c1c}
.shp-yt-on{border-color:#fca5a5;box-shadow:0 0 0 3px #fee2e2}
.shp-ylinked{font-size:13px;margin:0 0 8px;padding:7px 10px;background:#f0fdf4;border:1px solid #bbf7d0}.shp-ylinked i{color:#15803d}
.shp-lect-h{display:flex;align-items:center;justify-content:space-between;gap:10px;margin:26px 0 12px;padding-top:18px;border-top:2px solid #e5e7eb}.shp-lect-h h2{margin:0;font-size:19px}
.shp-filt{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:10px 12px;align-items:end}.shp-filt .ci-f{min-width:0;margin:0}.shp-filt .shp-fq{grid-column:span 2}.shp-filt select,.shp-filt input{width:100%}
@media (max-width:600px){.shp-filt{grid-template-columns:1fr 1fr}.shp-filt .shp-fq,.shp-filt .shp-fbtn{grid-column:1/-1}}
.sx-fl{display:flex;gap:8px;align-items:center;font-weight:600;cursor:pointer}
.shp-fcount{margin:0 0 10px;font-size:13.5px;color:#6b7280}
.shp-ytb{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:700;color:#b91c1c;background:#fef2f2;border:1px solid #fecaca;padding:1px 6px;vertical-align:middle}
.shp-yrow{display:flex;gap:8px}.shp-yrow input{flex:1}
.shp-ypv{margin-top:10px;border:1px solid #e5e7eb;background:#fafafa}
.shp-ypv .yh{display:flex;justify-content:space-between;gap:10px;padding:8px 10px;border-bottom:1px solid #e5e7eb;font-size:13px}.shp-ypv .yh b{color:#15803d}
.shp-ypv ol{margin:0;padding:6px 10px 8px 34px;max-height:280px;overflow:auto;font-size:13px}.shp-ypv li{padding:3px 0}
.shp-ypv .ye{padding:8px 10px;color:#b91c1c;font-size:13px}
.shp-count{display:inline-flex;gap:6px;align-items:center;font-weight:700;color:#0c6878;background:#eef7f8;padding:8px 12px}</style>
</head>
<body>
<?php admin_shell_open($open ? ($ep ? ($isC ? 'Edit course' : 'Edit notes') : ($isC ? 'Add a new course' : 'Add a notes pack')) : ($isC ? 'Online Courses' : 'PDF Notes'), $open ? ($ep ? ci_e($ep['title']) : ($isC ? 'A recorded video course for the website' : 'A PDF notes pack for the website')) : ($isC ? 'Recorded video courses sold on the website' : 'PDF notes packs sold on the website'), $open ? '<a class="ci-btn ci-ghost" href="/admin/shop-products.php?type=' . $fType . '"><i class="fas fa-arrow-left"></i> All ' . ($isC ? 'courses' : 'notes') . '</a>' : '<a class="ci-btn ci-ghost" href="' . ($isC ? '/store/' : '/notes/') . '" target="_blank"><i class="fas fa-store"></i> View on site</a>' . ($isC ? '<a class="ci-btn ci-ghost" href="/admin/shop-lectures.php"><i class="fas fa-table-list"></i> Lectures</a>' : '') . '<a class="ci-btn" href="?new=1&type=' . $fType . '"><i class="fas fa-plus"></i> ' . ($isC ? 'Add Course' : 'Add Notes') . '</a>'); ?>
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
    <?php if ($isC): ?>
    <div class="meta-panel" id="introPanel"><div class="meta-panel-header"><span><i class="fab fa-youtube" style="color:#dc2626"></i> Intro video</span></div><div class="meta-panel-body">
      <div class="shp-yrow"><input name="intro_url" id="introUrl" maxlength="500" placeholder="https://www.youtube.com/watch?v=… or https://youtu.be/…" value="<?= ci_e($ep['intro_url'] ?? '') ?>"><button type="button" class="ci-btn ci-ghost" id="introFetch"><i class="fas fa-magnifying-glass"></i> Fetch</button></div>
      <div class="shp-intro" id="introPrev" hidden></div>
    </div></div>
    <div class="meta-panel"><div class="meta-panel-header"><span>Web address</span></div><div class="meta-panel-body"><div class="sx-link"><span>Web address:</span> <span class="ci-muted"><?= ci_e($base) ?></span><input name="slug" id="sxSlug" maxlength="150" placeholder="made-from-the-name" value="<?= ci_e($ep['slug'] ?? '') ?>"></div></div></div>
    <?php endif; ?>
    <?php if (!$isC && !$ep): // a new notes pack: its PDFs can be chosen right here (saved right after the pack is made) ?>
    <div class="meta-panel" id="newPdfPanel"><div class="meta-panel-header"><span><i class="fas fa-file-pdf" style="color:#dc2626"></i> PDF files</span></div><div class="meta-panel-body">
      <div class="ci-f"><label>Upload PDF file(s)</label><input type="file" id="newPdfFiles" accept="application/pdf,.pdf" multiple><span class="ci-hint">One or many PDFs, up to 300 MB each.</span></div>
      <div class="ci-f" style="margin-top:12px"><label>or a PDF link</label><input type="url" id="newPdfLink" maxlength="1000" placeholder="Google Drive, Dropbox or a direct .pdf link — https://…"><span class="ci-hint">Google Drive: sharing "Anyone with the link". The PDF is copied here; students never see the link.</span></div>
      <p class="ci-muted" id="newPdfSt" style="margin:10px 0 0;font-size:13px" hidden></p>
    </div></div>
    <?php endif; ?>
    <div class="meta-panel"><div class="meta-panel-header"><span><?= $isC ? 'About this course' : 'About these notes' ?></span></div><div class="meta-panel-body">
      <textarea name="description" rows="9" maxlength="20000" placeholder="Describe the <?= $isC ? 'course' : 'notes' ?> — chapters, who it is for, how it helps."><?= ci_e($ep['description'] ?? '') ?></textarea>
    </div></div>
    <div class="meta-panel"><div class="meta-panel-header"><span>What students get</span></div><div class="meta-panel-body">
      <div class="shp-rep" id="shpInc"><?php foreach ($epInc ?: [''] as $v): ?><div class="shp-row"><input name="inc[]" maxlength="200" placeholder="e.g. Doubt solving by the teacher" value="<?= ci_e($v) ?>"><button type="button" class="ci-ico" data-i="trash" aria-label="Remove" data-rm><?= ci_svg('trash') ?></button></div><?php endforeach; ?></div>
      <div><button type="button" class="ci-btn ci-ghost ci-sm" data-add="shpInc"><i class="fas fa-plus"></i> Add point</button></div>
    </div></div>
    <div class="meta-panel"><div class="meta-panel-header"><span>FAQ</span></div><div class="meta-panel-body">
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
    <?php if ($isC): $flOn = $ep ? (int)($ep['free_list'] ?? 0) === 1 : true; ?>
    <div class="meta-panel"><div class="meta-panel-header"><span>Free content</span></div><div class="meta-panel-body">
      <label class="sx-fl"><input type="checkbox" name="free_list" value="1" id="sxFree"<?= $flOn ? ' checked' : '' ?> data-touched="0" data-new="<?= $ep ? '0' : '1' ?>"> Add to Free Courses</label>
    </div></div>
    <?php endif; ?>
    <div class="meta-panel"><div class="meta-panel-header"><span>Picture</span></div><div class="meta-panel-body">
      <label class="sx-thumb" id="sxThumb"><?php if (!empty($ep['thumb'])): ?><img src="<?= ci_e($ep['thumb']) ?>" alt=""><?php else: ?><span><i class="fas fa-image"></i> Choose a picture<small>16:9 · JPG, PNG or WebP · up to 8 MB · saved as WebP 1200×675</small></span><?php endif; ?><input type="file" name="thumb" accept="image/jpeg,image/png,image/webp" hidden></label>
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
      <?php if ($isC): ?><div><label>Batch starts on</label><input type="date" name="starts_at" value="<?= ci_e($ep['starts_at'] ?? '') ?>"><small class="ci-muted">A future date shows it as an Upcoming batch.</small></div>
      <div><label>Batch ends on</label><input type="date" name="ends_at" value="<?= ci_e($ep['ends_at'] ?? '') ?>"><small class="ci-muted">After this date the batch counts as Completed.</small></div><?php endif; ?>
      <div><label>Access for</label><select name="validity_days"><?php $vd = (int)($ep['validity_days'] ?? 0); $opts = [0 => 'Lifetime', 30 => '1 month', 90 => '3 months', 180 => '6 months', 365 => '1 year', 730 => '2 years']; if (!isset($opts[$vd])) $opts[$vd] = $vd . ' days'; foreach ($opts as $k => $l): ?><option value="<?= $k ?>" <?= $vd === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    </div></div>
  </div>
</div>
</form>
<?php if ($ep): $p = $ep; ?>
<div class="shp-lect-h" id="lectures"><h2><i class="fas <?= $isC ? 'fa-film' : 'fa-file-pdf' ?>"></i> <?= $isC ? 'Lectures' : 'PDF files' ?></h2><?php if ($isC): ?><a class="ci-btn" href="/admin/shop-lectures.php?course=<?= (int)$ep['id'] ?>"><i class="fas fa-table-list"></i> Open Lectures</a><?php endif; ?></div>
<?php $lcSummary = $isC; require __DIR__ . '/components/course-lectures.php'; ?>
<?php endif; ?>
<?php if ($open && !$isC && !$ep): ?>
<script>window.SHC = { pid: 0, csrf: <?= json_encode(csrf_token(), JSON_HEX_TAG) ?>, self: '' };</script>
<script src="/assets/js/shop-admin.js?v=<?= @filemtime(ROOT_PATH . '/assets/js/shop-admin.js') ?: 1 ?>" defer></script>
<script>
(function () { // new notes pack with PDFs chosen: save the pack, then add the link and upload the files into it, then open it
  var form = document.querySelector('form.sx-form'), files = document.getElementById('newPdfFiles'), link = document.getElementById('newPdfLink'), st = document.getElementById('newPdfSt');
  if (!form || !files) return;
  var csrf = (form.querySelector('[name=_csrf],[name=csrf_token]') || {}).value || (window.SHC || {}).csrf;
  function say(t, bad) { st.hidden = false; st.textContent = t; st.style.color = bad ? '#b91c1c' : ''; }
  form.addEventListener('submit', function (e) {
    var fl = [].slice.call(files.files || []), url = link.value.trim();
    if (!fl.length && !url) return; // nothing to attach: the normal save
    var bad = fl.filter(function (f) { return !/\.pdf$/i.test(f.name); });
    if (bad.length) { e.preventDefault(); say(bad[0].name + ' is not a PDF.', true); return; }
    if (!form.reportValidity()) { e.preventDefault(); return; }
    e.preventDefault();
    var btns = form.querySelectorAll('button[type=submit]'); btns.forEach(function (b) { b.disabled = true; });
    var fd = new FormData(form); fd.set('go_content', '1');
    say('Saving the notes pack…');
    fetch(location.pathname + location.search, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) {
      var m = r.url.match(/[?&]edit=(\d+)/); if (!m) throw { form: 1 };
      var id = +m[1], chain = Promise.resolve(), problems = [];
      if (url) chain = chain.then(function () {
        say('Downloading the PDF from the link…');
        var b = new URLSearchParams({ action: 'pdf_link', ajax: '1', url: url, _csrf: csrf, csrf_token: csrf });
        return fetch('/admin/shop-content.php?id=' + id, { method: 'POST', body: b, credentials: 'same-origin' }).then(function (x) { return x.json(); })
          .then(function (d) { if (d.error) problems.push('Link: ' + d.error); }).catch(function () { problems.push('Link: could not add it.'); });
      });
      fl.forEach(function (f, i) { chain = chain.then(function () {
        return window.shUpload(f, { kind: 'f', target: id, title: '', onProgress: function (fr) { say('Uploading ' + (fl.length > 1 ? (i + 1) + ' of ' + fl.length + ' — ' : '') + f.name + ' · ' + Math.round(fr * 100) + '%'); } })
          .catch(function (err) { problems.push(f.name + ': ' + (err && err.message || 'upload failed')); });
      }); });
      return chain.then(function () {
        if (problems.length) alert('The notes pack is saved, but:\n' + problems.join('\n') + '\n\nYou can add the PDF again on the next page.');
        location.href = '/admin/shop-products.php?edit=' + id + '&type=notes#lectures';
      });
    }).catch(function (err) {
      if (err && err.form) { btns.forEach(function (b) { b.disabled = false; }); st.hidden = true; form.submit(); return; } // the server had something to say about the form: show it
      btns.forEach(function (b) { b.disabled = false; }); say('Could not save — check the internet and try again.', true);
    });
  });
})();
</script>
<?php endif; ?>
<script>
(function () { // intro video: show its picture, name and length; fill the course name if it is empty
  var b = document.getElementById('introFetch'), u = document.getElementById('introUrl'), pv = document.getElementById('introPrev'); if (!b) return;
  function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : t; return d.innerHTML; }
  function go(fill) {
    var v = u.value.trim(); if (!v) { pv.hidden = true; return; }
    pv.hidden = false; pv.innerHTML = '<span class="ci-muted">Fetching the video…</span>';
    fetch('/admin/shop-products.php?introinfo=' + encodeURIComponent(v), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      if (d.error) { pv.innerHTML = '<span class="ye">✗ ' + esc(d.error) + '</span>'; return; }
      pv.innerHTML = '<img src="' + esc(d.thumb) + '" alt=""><div><b>✓ ' + esc(d.title) + '</b><span class="ci-muted">' + esc(d.channel) + (d.duration ? ' · ' + esc(d.duration) : '') + '</span></div>';
      var t = document.getElementById('sxTitle'); if (fill && t && !t.value.trim() && d.title) { t.value = d.title; t.dispatchEvent(new Event('input', { bubbles: true })); }
    }).catch(function () { pv.innerHTML = '<span class="ye">✗ Could not fetch the video right now.</span>'; });
  }
  b.addEventListener('click', function () { go(true); }); u.addEventListener('paste', function () { setTimeout(function () { go(true); }, 60); });
  if (u.value.trim()) go(false);
})();
(function () {
  var t = document.getElementById('sxTitle'), sl = document.getElementById('sxSlug'), touched = sl.value !== '';
  sl.addEventListener('input', function () { touched = true; });
  t.addEventListener('input', function () { if (!touched) sl.placeholder = t.value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'made-from-the-name'; });
  var mrp = document.getElementById('sxMrp'), pr = document.getElementById('sxPrice'), pv = document.getElementById('sxPriceView');
  function price() { var a = +pr.value, m = +mrp.value; if (pr.value === '') { pv.innerHTML = ''; return; } pv.innerHTML = a <= 0 ? '<b>Free</b>' : '<b>₹' + a.toLocaleString('en-IN') + '</b>' + (m > a ? ' <s>₹' + m.toLocaleString('en-IN') + '</s> <em>' + Math.round(100 - a * 100 / m) + '% off</em>' : ''); }
  pr.addEventListener('input', price); mrp.addEventListener('input', price); price();
  var fl = document.getElementById('sxFree'); // ticked by itself when the sale price is 0 (until it is changed by hand)
  if (fl) { var auto = function () { if (fl.dataset.touched === '1' || pr.value === '') return; fl.checked = +pr.value <= 0; };
    fl.addEventListener('change', function () { fl.dataset.touched = '1'; }); pr.addEventListener('input', auto); if (fl.dataset.new === '1') auto(); }
  var box = document.getElementById('sxThumb'), fi = box.querySelector('input[type=file]');
  fi.addEventListener('change', function () { var f = fi.files[0]; if (!f) return; [].forEach.call(box.querySelectorAll('img, span'), function (x) { x.remove(); }); var i = new Image(); i.src = URL.createObjectURL(f); box.insertBefore(i, fi); });
})();
</script>
<?php endif; ?>

<?php if (!$open): ?>
<?php $fo = function (string $k, array $opts) use ($F) { $h = ''; foreach ($opts as $v => $l) $h .= '<option value="' . ci_e($v) . '"' . ((string)$F[$k] === (string)$v ? ' selected' : '') . '>' . ci_e($l) . '</option>'; return $h; };
$catsF = ['0' => 'All categories', '-1' => 'No category']; foreach (sh_categories($pdo, true) as $k => $c) $catsF[(string)$k] = $c['name'];
$tchF = ['0' => 'All teachers', '-1' => 'No teacher']; foreach ($teachers as $k => $l) $tchF[(string)$k] = $l; ?>
<form class="ci-filters shp-filt" method="get"><input type="hidden" name="type" value="<?= $fType ?>">
  <div class="ci-f shp-fq"><label>Search</label><input type="search" name="q" value="<?= ci_e($F['q']) ?>" placeholder="<?= $isC ? 'Course name…' : 'Notes name…' ?>"></div>
  <div class="ci-f"><label>Category</label><select name="cat" data-search><?= $fo('cat', $catsF) ?></select></div>
  <?php if ($isC): ?><div class="ci-f"><label>Teacher</label><select name="teacher"><?= $fo('teacher', $tchF) ?></select></div><?php endif; ?>
  <div class="ci-f"><label>Price</label><select name="price"><?= $fo('price', ['' => 'Free & paid', 'free' => 'Free', 'paid' => 'Paid', 'offer' => 'Paid with discount']) ?></select></div>
  <?php if ($isC): ?><div class="ci-f"><label>Course Status</label><select name="batch"><?= $fo('batch', ['running' => 'Running', 'upcoming' => 'Upcoming', 'completed' => 'Completed', 'nodate' => 'No start / end date', '' => 'All courses']) ?></select></div><?php endif; ?>
  <div class="ci-f"><label>Status</label><select name="status"><?= $fo('status', ['' => 'All', 'published' => 'Published', 'draft' => 'Draft', 'hidden' => 'Hidden']) ?></select></div>
  <div class="ci-f"><label>Content</label><select name="content"><?= $fo('content', $isC ? ['' => 'Any', 'playlist' => 'YouTube playlist linked', 'noplaylist' => 'Lectures, no playlist', 'pdfs' => 'Has course PDFs', 'empty' => 'No lectures yet'] : ['' => 'Any', 'has' => 'Has PDFs', 'empty' => 'No PDFs yet']) ?></select></div>
  <div class="ci-f"><label>Access</label><select name="access"><?= $fo('access', ['' => 'Any', 'lifetime' => 'Lifetime', 'limited' => 'Limited days']) ?></select></div>
  <div class="ci-f"><label>Buyers</label><select name="sales"><?= $fo('sales', ['' => 'Any', 'buyers' => 'Has students', 'nobuyers' => 'No students yet', 'sales' => 'Has paid sales']) ?></select></div>
  <div class="ci-f"><label>Sort by</label><select name="sort"><?= $fo('sort', ['' => 'Own order', 'new' => 'Newest first', 'old' => 'Oldest first', 'az' => 'Name A–Z', 'za' => 'Name Z–A', 'buyers' => 'Most students', 'sales' => 'Most sales', 'plow' => 'Price: low to high', 'phigh' => 'Price: high to low'] + ($isC ? ['start' => 'Batch start date'] : [])) ?></select></div>
  <div class="ci-f shp-fbtn"><label>&nbsp;</label><div style="display:flex;gap:8px"><button class="ci-btn" type="submit"><i class="fas fa-filter"></i> Filter</button><?php if ($fOn || $F['sort'] !== ''): ?><a class="ci-btn ci-ghost" href="?type=<?= $fType ?>">Clear</a><?php endif; ?></div></div>
</form>
<p class="shp-fcount"><?= $fOn ? '<b>' . count($rows) . '</b> of ' . $fTotal . ($isC ? ' courses' : ' notes packs') . ' match the filters' : '<b>' . $fTotal . '</b> ' . ($isC ? 'courses' : 'notes packs') ?></p>
<script>document.querySelectorAll('.shp-filt select').forEach(function (s) { s.addEventListener('change', function () { s.form.submit(); }); });</script>
<div class="ci-card"><div class="ci-card-b ci-p0 ci-table-wrap"><table data-sno class="ci-table">
<thead><tr><th><?= $isC ? 'Course' : 'Notes' ?></th><th><?= $isC ? 'Teacher' : 'Category' ?></th><th>Inside</th><th class="ci-num">Price</th><th>Access</th><th class="ci-num">Buyers</th><th class="ci-num">Sales</th><th>Status</th><th>Action</th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="9" class="ci-empty"><?= $fOn ? 'Nothing matches these filters. <a href="?type=' . $fType . '">Clear filters</a>' : 'No products yet. Add your first course or notes.' ?></td></tr><?php endif; ?>
<?php foreach ($rows as $p): ?>
<tr><td><div style="display:flex;gap:10px;align-items:center"><?= $p['thumb'] ? '<img class="shp-th" src="' . ci_e($p['thumb']) . '" alt="">' : '<span class="shp-thp">' . ($p['type'] === 'notes' ? 'PDF' : 'VIDEO') . '</span>' ?><div><b><?= ci_e($p['title']) ?></b><?= (int)$p['ytl'] ? ' <span class="shp-ytb" title="Its lectures follow a YouTube playlist"><i class="fab fa-youtube"></i> Playlist</span>' : '' ?><?= !empty($p['free_list']) ? ' <span class="shp-ytb" style="color:#15803d;background:#f0fdf4;border-color:#bbf7d0" title="Shown in Free Courses">Free content</span>' : '' ?><?= $p['subtitle'] ? '<small class="ci-muted" style="display:block">' . ci_e(mb_strimwidth($p['subtitle'], 0, 70, '…')) . '</small>' : '' ?></div></div></td>
<td><?= $p['type'] === 'notes' ? ci_e(sh_category_name($pdo, (int)$p['category_id']) ?: '—') : '' ?><?= $p['type'] === 'course' ? ($p['teacher_id'] ? '' : '<span class="ci-muted">—</span>') : '' ?><?= $p['type'] === 'course' && $p['teacher_id'] ? ci_e($teachers[(int)$p['teacher_id']] ?? '—') : '' ?></td>
<td class="ci-nw"><a href="<?= $p['type'] === 'course' ? '/admin/shop-lectures.php?course=' . (int)$p['id'] : '/admin/shop-products.php?edit=' . (int)$p['id'] . '&amp;type=notes#lectures' ?>"><?= $p['type'] === 'course' ? (int)$p['lessons'] . ' videos' . ((int)$p['files'] ? ' · ' . (int)$p['files'] . ' PDFs' : '') : (int)$p['files'] . ' PDFs' ?></a></td>
<td class="ci-num"><?= (float)$p['price'] > 0 ? ci_money0($p['price']) : 'Free' ?><?= $p['mrp'] !== null ? '<small class="ci-muted" style="display:block;text-decoration:line-through">' . ci_money0($p['mrp']) . '</small>' : '' ?></td>
<td class="ci-nw"><?= (int)$p['validity_days'] ? (int)$p['validity_days'] . ' days' : 'Lifetime' ?></td>
<td class="ci-num"><?= (int)$p['buyers'] ?></td><td class="ci-num"><?= ci_money0($p['sales']) ?></td><td><?= $badge($p['status']) ?></td>
<td><div class="ci-icos"><?= ci_ico('edit', '?edit=' . (int)$p['id'], 'Edit') ?><?= $p['type'] === 'course' ? ci_ico('plus', '/admin/shop-lectures.php?course=' . (int)$p['id'], 'Lectures') : '' ?><?= ci_ico('book-plus', '/admin/shop-products.php?edit=' . (int)$p['id'] . '&type=' . $p['type'] . '#lectures', $p['type'] === 'course' ? 'Chapters & lectures' : 'PDFs') ?><?= ci_ico('eye', sh_url($p) . '&preview=1', 'View on the store', 'link', ' target="_blank"') ?>
<?php if ($me['role'] === 'admin'): ?><?= ci_post_ico('trash', 'Delete', ['action' => 'delete', 'id' => $p['id']], 'Delete ' . $p['title'] . ' with all its videos and PDFs?' . ((int)$p['buyers'] ? "\n\n" . (int)$p['buyers'] . ' student(s) have it — their access, orders and progress for it are deleted too.' : '') . "\nThis cannot be undone.") ?><?php endif; ?></div></td></tr>
<?php endforeach; ?>
</tbody></table></div></div>
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
