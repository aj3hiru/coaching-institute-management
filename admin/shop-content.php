<?php
// Online store → what is inside one product: chapters, video lessons (uploaded to our server OR a YouTube / video link —
// your choice per lesson), many YouTube videos at once (links or a playlist), and PDF files.
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/institute.php';
require_once INCLUDES_PATH . '/shop.php';
$me = sh_page_start($pdo, ['products']);
sh_tables($pdo);
$pid = (int)($_GET['id'] ?? $_POST['pid'] ?? 0);
$p = $pid ? sh_product($pdo, $pid, false) : null;
if (!$p) { header('Location: /admin/shop-products.php'); exit; }
$self = '/admin/shop-content.php?id=' . $pid;
$dur = fn($v) => preg_match('/^\d{1,3}:\d{2}(:\d{2})?$/', trim((string)$v)) ? trim((string)$v) : null;

/** Deletes the uploaded files of a lesson (main video + its qualities). */
function shc_drop_media(PDO $pdo, int $id): void
{
    $l = $pdo->query("SELECT video_file, variants FROM sh_lessons WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
    if (!$l) return;
    foreach (array_filter(array_merge([$l['video_file']], array_values(sh_variants($l)))) as $f) { $f = sh_media_path($f); if (is_file($f)) @unlink($f); }
}
/** Swaps the order of a row with its neighbour (sh_sections / sh_lessons / sh_files) within the same product (and section). */
function shc_move(PDO $pdo, string $table, int $id, int $pid, int $dir, string $extra = ''): void
{
    $rows = $pdo->query("SELECT id FROM $table WHERE product_id = $pid $extra ORDER BY sort, id")->fetchAll(PDO::FETCH_COLUMN);
    $i = array_search($id, array_map('intval', $rows), true);
    if ($i === false) return;
    $j = $i + $dir;
    if ($j < 0 || $j >= count($rows)) return;
    [$rows[$i], $rows[$j]] = [$rows[$j], $rows[$i]];
    $st = $pdo->prepare("UPDATE $table SET sort = ? WHERE id = ?");
    foreach ($rows as $n => $rid) $st->execute([$n + 1, $rid]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $ajax = !empty($_POST['ajax']);
    try {
        switch ($a) {
            case 'add_section':
                $t = trim((string)($_POST['title'] ?? ''));
                if ($t === '') throw new RuntimeException('Enter the chapter name.');
                $sort = (int)$pdo->query("SELECT COALESCE(MAX(sort),0) + 1 FROM sh_sections WHERE product_id = $pid")->fetchColumn();
                $pdo->prepare("INSERT INTO sh_sections (product_id, title, sort) VALUES (?, ?, ?)")->execute([$pid, mb_substr($t, 0, 200), $sort]);
                admin_flash('Chapter added.');
                break;
            case 'rename_section':
                $t = trim((string)($_POST['title'] ?? ''));
                if ($t === '') throw new RuntimeException('Enter the chapter name.');
                $pdo->prepare("UPDATE sh_sections SET title = ? WHERE id = ? AND product_id = ?")->execute([mb_substr($t, 0, 200), $id, $pid]);
                admin_flash('Chapter renamed.');
                break;
            case 'delete_section':
                $pdo->prepare("UPDATE sh_lessons SET section_id = NULL WHERE section_id = ? AND product_id = ?")->execute([$id, $pid]);
                $pdo->prepare("DELETE FROM sh_sections WHERE id = ? AND product_id = ?")->execute([$id, $pid]);
                admin_flash('Chapter removed. Its videos are kept (now without a chapter).');
                break;
            case 'move_section':
                shc_move($pdo, 'sh_sections', $id, $pid, (int)($_POST['dir'] ?? 1) < 0 ? -1 : 1);
                break;
            case 'save_lesson':
                $t = trim((string)($_POST['title'] ?? ''));
                if ($t === '') throw new RuntimeException('Enter the video title.');
                $sec = (int)($_POST['section_id'] ?? 0);
                if ($sec && !(int)$pdo->query("SELECT COUNT(*) FROM sh_sections WHERE id = $sec AND product_id = $pid")->fetchColumn()) $sec = 0;
                $src = (string)($_POST['source'] ?? 'upload');
                $url = trim((string)($_POST['video_url'] ?? ''));
                if ($src === 'link') {
                    if ($url === '' || !preg_match('#^https://#i', $url)) throw new RuntimeException('Paste the YouTube (or other video) link — it must start with https://');
                    if (preg_match('~youtu\.?be~i', $url) && !sh_yt_id($url)) throw new RuntimeException('This YouTube link has no video in it. Open the video and copy its link.');
                } else $url = '';
                $d = $dur($_POST['duration'] ?? '');
                if ($src === 'link' && !$d && ($yt = sh_yt_id($url))) $d = sh_yt_duration($yt) ?: null;
                $free = !empty($_POST['free']) ? 1 : 0;
                $notes = trim((string)($_POST['notes'] ?? '')) ?: null;
                if ($id) {
                    if (!(int)$pdo->query("SELECT COUNT(*) FROM sh_lessons WHERE id = $id AND product_id = $pid")->fetchColumn()) throw new RuntimeException('Video not found.');
                    if ($src === 'link') { // switching to a link: the uploaded files are no longer needed
                        shc_drop_media($pdo, $id);
                        $pdo->prepare("UPDATE sh_lessons SET title=?, section_id=?, video_url=?, video_file=NULL, variants=NULL, height=NULL, size=0, duration=?, free=?, notes=? WHERE id=?")->execute([mb_substr($t, 0, 200), $sec ?: null, $url, $d, $free, $notes, $id]);
                    } else {
                        $pdo->prepare("UPDATE sh_lessons SET title=?, section_id=?, duration=COALESCE(?, duration), free=?, notes=? WHERE id=?")->execute([mb_substr($t, 0, 200), $sec ?: null, $d, $free, $notes, $id]);
                    }
                } else {
                    $sort = (int)$pdo->query("SELECT COALESCE(MAX(sort),0) + 1 FROM sh_lessons WHERE product_id = $pid")->fetchColumn();
                    $pdo->prepare("INSERT INTO sh_lessons (product_id, section_id, title, video_url, duration, free, notes, sort) VALUES (?,?,?,?,?,?,?,?)")
                        ->execute([$pid, $sec ?: null, mb_substr($t, 0, 200), $url ?: null, $d, $free, $notes, $sort]);
                    $id = (int)$pdo->lastInsertId();
                }
                if ($ajax) { header('Content-Type: application/json'); exit(json_encode(['ok' => true, 'id' => $id])); }
                admin_flash($src === 'link' ? 'Video saved (YouTube / link).' : 'Video saved.');
                break;
            case 'bulk_youtube':
                $sec = (int)($_POST['section_id'] ?? 0);
                if ($sec && !(int)$pdo->query("SELECT COUNT(*) FROM sh_sections WHERE id = $sec AND product_id = $pid")->fetchColumn()) $sec = 0;
                $lines = preg_split('/\R/', (string)($_POST['links'] ?? ''));
                $items = []; $bad = 0;
                foreach ($lines as $ln) {
                    $ln = trim($ln); if ($ln === '') continue;
                    $title = ''; $link = $ln;
                    if (str_contains($ln, '|')) { [$title, $link] = array_map('trim', explode('|', $ln, 2)); if (!preg_match('#https?://#', $link) && preg_match('#https?://#', $title)) [$title, $link] = [$link, $title]; }
                    $vid = sh_yt_id($link); $list = sh_yt_list($link);
                    if ($list && (!$vid || !empty($_POST['whole_list']))) { // a playlist: every video in it
                        $pl = sh_yt_playlist($list);
                        if (!$pl) { $bad++; continue; }
                        foreach ($pl as [$v, $tt]) $items[$v] ??= [$v, $tt];
                    } elseif ($vid) $items[$vid] ??= [$vid, $title];
                    else $bad++;
                }
                if (!$items) throw new RuntimeException('No YouTube videos found. Paste video links (one per line) or a playlist link. The playlist must be Public or Unlisted.');
                if (count($items) > 300) throw new RuntimeException('Please add at most 300 videos at a time.');
                $have = array_flip(array_filter(array_map(fn($u) => sh_yt_id((string)$u), $pdo->query("SELECT video_url FROM sh_lessons WHERE product_id = $pid AND video_url IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN))));
                $sort = (int)$pdo->query("SELECT COALESCE(MAX(sort),0) FROM sh_lessons WHERE product_id = $pid")->fetchColumn();
                $ins = $pdo->prepare("INSERT INTO sh_lessons (product_id, section_id, title, video_url, duration, free, sort) VALUES (?,?,?,?,?,?,?)");
                $added = 0; $skipped = 0; $freeFirst = max(0, (int)($_POST['free_first'] ?? 0)); @set_time_limit(300);
                foreach (array_values($items) as $n => [$v, $tt]) {
                    if (isset($have[$v])) { $skipped++; continue; }
                    if ($tt === '') $tt = sh_yt_title($v) ?: 'Video ' . ($sort + 1);
                    $ins->execute([$pid, $sec ?: null, mb_substr($tt, 0, 200), 'https://www.youtube.com/watch?v=' . $v, count($items) <= 40 ? (sh_yt_duration($v) ?: null) : null, $added < $freeFirst ? 1 : 0, ++$sort]);
                    $added++;
                }
                admin_log('shop_content', "Added $added YouTube videos to store product #$pid");
                admin_flash($added . ' YouTube video' . ($added === 1 ? '' : 's') . ' added.' . ($skipped ? " $skipped already in this course were skipped." : '') . ($bad ? " $bad line(s) were not YouTube links / playlists." : ''), $added ? 'success' : 'error');
                break;
            case 'delete_lesson':
                if (!(int)$pdo->query("SELECT COUNT(*) FROM sh_lessons WHERE id = $id AND product_id = $pid")->fetchColumn()) break;
                shc_drop_media($pdo, $id);
                $pdo->prepare("DELETE FROM sh_lessons WHERE id = ? AND product_id = ?")->execute([$id, $pid]);
                $pdo->prepare("DELETE FROM sh_progress WHERE lesson_id = ?")->execute([$id]);
                admin_flash('Video deleted.');
                break;
            case 'remove_quality': // drop one smaller quality of a lesson video
                $l = $pdo->query("SELECT variants FROM sh_lessons WHERE id = $id AND product_id = $pid")->fetch(PDO::FETCH_ASSOC);
                $vv = $l ? sh_variants($l) : []; $qq = (string)($_POST['q'] ?? '');
                if (isset($vv[$qq])) { $f = sh_media_path($vv[$qq]); if (is_file($f)) @unlink($f); unset($vv[$qq]); $pdo->prepare("UPDATE sh_lessons SET variants = ? WHERE id = ?")->execute([$vv ? json_encode($vv) : null, $id]); }
                admin_flash($qq . 'p quality removed.');
                admin_flash('Uploaded video removed.');
                break;
            case 'move_lesson':
                $sec = $pdo->query("SELECT section_id FROM sh_lessons WHERE id = $id AND product_id = $pid")->fetchColumn();
                shc_move($pdo, 'sh_lessons', $id, $pid, (int)($_POST['dir'] ?? 1) < 0 ? -1 : 1, $sec ? "AND section_id = " . (int)$sec : "AND section_id IS NULL");
                break;
            case 'toggle_free':
                $t = ($_POST['what'] ?? '') === 'file' ? 'sh_files' : 'sh_lessons';
                $pdo->prepare("UPDATE $t SET free = 1 - free WHERE id = ? AND product_id = ?")->execute([$id, $pid]);
                break;
            case 'rename_file':
                $t = trim((string)($_POST['title'] ?? ''));
                if ($t === '') throw new RuntimeException('Enter the PDF title.');
                $pdo->prepare("UPDATE sh_files SET title = ? WHERE id = ? AND product_id = ?")->execute([mb_substr($t, 0, 200), $id, $pid]);
                admin_flash('PDF renamed.');
                break;
            case 'delete_file':
                $old = $pdo->query("SELECT file FROM sh_files WHERE id = $id AND product_id = $pid")->fetchColumn();
                $pdo->prepare("DELETE FROM sh_files WHERE id = ? AND product_id = ?")->execute([$id, $pid]);
                if ($old) { $f = sh_media_path($old); if (is_file($f)) @unlink($f); }
                admin_flash('PDF deleted.');
                break;
            case 'move_file':
                shc_move($pdo, 'sh_files', $id, $pid, (int)($_POST['dir'] ?? 1) < 0 ? -1 : 1);
                break;
        }
    } catch (RuntimeException $e) {
        if ($ajax) { header('Content-Type: application/json'); http_response_code(400); exit(json_encode(['error' => $e->getMessage()])); }
        admin_flash($e->getMessage(), 'error');
    }
    header('Location: ' . $self . (!empty($_POST['anchor']) ? '#' . preg_replace('/[^a-z0-9-]/', '', (string)$_POST['anchor']) : ''));
    exit;
}

$isCourse = $p['type'] === 'course';
$cur = $isCourse ? sh_curriculum($pdo, $pid) : [];
$secs = $pdo->query("SELECT id, title FROM sh_sections WHERE product_id = $pid ORDER BY sort, id")->fetchAll(PDO::FETCH_KEY_PAIR);
$files = sh_files($pdo, $pid);
$editL = (int)($_GET['lesson'] ?? 0);
$el = $editL ? ($pdo->query("SELECT * FROM sh_lessons WHERE id = $editL AND product_id = $pid")->fetch(PDO::FETCH_ASSOC) ?: null) : null;
$mb = fn($b) => $b ? ($b >= 1073741824 ? round($b / 1073741824, 2) . ' GB' : round($b / 1048576, 1) . ' MB') : '';
$used = (int)$pdo->query("SELECT COALESCE(SUM(size),0) FROM sh_lessons WHERE product_id = $pid")->fetchColumn() + (int)$pdo->query("SELECT COALESCE(SUM(size),0) FROM sh_files WHERE product_id = $pid")->fetchColumn();
$free = @disk_free_space(sh_media_dir());
$secOpts = function (int $sel) use ($secs) { $h = '<option value="0">— No chapter —</option>'; foreach ($secs as $k => $t) $h .= '<option value="' . $k . '"' . ($sel === (int)$k ? ' selected' : '') . '>' . ci_e($t) . '</option>'; return $h; };
$btn = function (string $action, int $id, string $icon, string $label, array $more = [], string $confirm = '') {
    $f = '<form method="post" style="display:inline"' . ($confirm ? ' onsubmit="return confirm(' . ci_e(json_encode($confirm)) . ')"' : '') . '>' . csrf_field() . '<input type="hidden" name="action" value="' . $action . '"><input type="hidden" name="id" value="' . $id . '">';
    foreach ($more as $k => $v) $f .= '<input type="hidden" name="' . ci_e($k) . '" value="' . ci_e($v) . '">';
    return $f . '<button type="submit" class="ci-ico" aria-label="' . ci_e($label) . '" data-i="' . $icon . '">' . ci_svg($icon) . '</button></form>';
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title><?= ci_e($p['title']) ?> — content</title>
<?php admin_shell_head(); ci_head(); ?>
<style>
.shc-src{display:flex;gap:0;margin:0 0 10px;border:1px solid #d1d5db;width:max-content;max-width:100%}
.shc-src label{display:flex;align-items:center;gap:6px;padding:8px 14px;cursor:pointer;font-weight:600;font-size:13.5px;margin:0}
.shc-src label:has(input:checked){background:var(--ci-acc,#4a3369);color:#fff}
.shc-src input{accent-color:#fff;margin:0}
.shc-tag{display:inline-block;font-size:11.5px;font-weight:700;padding:1px 7px;border:1px solid}
.shc-tag.up{color:#1e40af;border-color:#bfdbfe;background:#eff6ff}.shc-tag.yt{color:#b91c1c;border-color:#fecaca;background:#fef2f2}.shc-tag.no{color:#92400e;border-color:#fde68a;background:#fffbeb}
.shc-sec{border-top:2px solid #e5e7eb}.shc-sec-h{display:flex;align-items:center;gap:8px;padding:9px 12px;background:#f8fafc;flex-wrap:wrap}
.shc-sec-h form.rn{display:flex;gap:6px;flex:1;min-width:200px}.shc-sec-h form.rn input{flex:1;font-weight:700;border:1px solid transparent;background:transparent;padding:4px 6px}
.shc-sec-h form.rn input:focus{border-color:#cbd5e1;background:#fff}
.shc-bar{height:6px;background:#e5e7eb;margin-top:6px;display:none}.shc-bar i{display:block;height:100%;width:0;background:var(--ci-acc,#4a3369);transition:width .2s}
.shc-up.busy .shc-bar{display:block}
.shc-free{font-size:12px;font-weight:700;border:1px solid #d1d5db;background:#fff;padding:2px 8px;cursor:pointer;color:#6b7280}.shc-free.on{color:#15803d;border-color:#86efac;background:#f0fdf4}
.shc-small{font-size:12.5px;color:#6b7280}
.shc-sec .ci-table,#pdfs .ci-table{table-layout:fixed;width:100%}
.shc-sec .ci-table td:nth-child(1){width:46%}.shc-sec .ci-table td:nth-child(2){width:20%}.shc-sec .ci-table td:nth-child(3){width:14%}.shc-sec .ci-table td:nth-child(4){width:20%}
#pdfs .ci-table td:nth-child(1){width:46%}#pdfs .ci-table td:nth-child(2){width:20%}#pdfs .ci-table td:nth-child(3){width:14%}#pdfs .ci-table td:nth-child(4){width:20%}
@media (max-width:700px){.shc-sec .ci-table,#pdfs .ci-table{table-layout:auto}}
</style>
</head>
<body>
<?php admin_shell_open(ci_e($p['title']), ($isCourse ? 'Video course' : 'PDF notes') . ' — chapters, videos and PDFs', '<a class="ci-btn ci-ghost" href="/admin/shop-products.php?edit=' . $pid . '"><i class="fas fa-pen"></i> Details &amp; price</a><a class="ci-btn ci-ghost" href="' . ci_e(sh_url($p)) . '" target="_blank"><i class="fas fa-eye"></i> View</a>'); ?>
<div class="ci">
<?= admin_flash() ?>
<?php if ($p['status'] !== 'published'): ?><div class="ci-alert">This product is <b><?= ci_e($p['status']) ?></b> — buyers cannot see it yet. Publish it in <a href="/admin/shop-products.php?edit=<?= $pid ?>">Details &amp; price</a> when the content is ready.</div><?php endif; ?>
<p class="shc-small">Files are kept in a private folder (not reachable by address). Used by this product: <?= $mb($used) ?: '0 MB' ?><?= $free !== false ? ' · free on the server: ' . $mb($free) : '' ?></p>

<?php if ($isCourse): ?>
<!-- add / edit a video -->
<div class="ci-card" id="add"><div class="ci-card-h"><h3><?= $el ? 'Edit video: ' . ci_e($el['title']) : 'Add a video' ?></h3><?php if ($el): ?><a href="<?= $self ?>">Cancel</a><?php endif; ?></div><div class="ci-card-b">
<form method="post" id="shcLesson" class="shc-up"><?= csrf_field() ?><input type="hidden" name="action" value="save_lesson"><input type="hidden" name="id" value="<?= (int)($el['id'] ?? 0) ?>"><input type="hidden" name="pid" value="<?= $pid ?>">
  <?php $isLink = $el ? (!$el['video_file'] && $el['video_url']) : false; ?>
  <div class="shc-src" role="radiogroup" aria-label="Where is the video?">
    <label><input type="radio" name="source" value="upload" <?= $isLink ? '' : 'checked' ?>> Upload to our server</label>
    <label><input type="radio" name="source" value="link" <?= $isLink ? 'checked' : '' ?>> YouTube / video link</label>
  </div>
  <div class="ci-grid3">
    <div class="ci-f" style="grid-column:span 2"><label>Title</label><input name="title" required maxlength="200" placeholder="e.g. Chapter 1 — Real Numbers (Part 1)" value="<?= ci_e($el['title'] ?? '') ?>"></div>
    <div class="ci-f"><label>Chapter</label><select name="section_id"><?= $secOpts((int)($el['section_id'] ?? ($_GET['section'] ?? 0))) ?></select></div>
    <div class="ci-f shc-when-up" style="grid-column:span 2"><label><?= !empty($el['video_file']) ? 'Replace the video (optional)' : 'Video file (MP4)' ?></label><input type="file" name="vfile" accept="video/mp4,video/webm,video/quicktime,.mp4,.m4v,.webm,.mov"><span class="ci-hint">Any size — it is sent in small pieces. MP4 (H.264) plays on every phone.</span></div>
    <div class="ci-f shc-when-link" style="grid-column:span 2"><label>YouTube or video link</label><input name="video_url" maxlength="500" placeholder="https://www.youtube.com/watch?v=…" value="<?= ci_e($el['video_url'] ?? '') ?>"><span class="ci-hint">YouTube (Unlisted works), Vimeo, or a Bunny / other embed link.</span></div>
    <div class="ci-f"><label>Duration</label><input name="duration" maxlength="9" placeholder="filled in by itself" value="<?= ci_e($el['duration'] ?? '') ?>"><span class="ci-hint">mm:ss — read from the file / YouTube.</span></div>
  </div>
  <?php if (!empty($el['video_file'])): $vv = sh_variants($el); ?>
  <div class="shc-q shc-when-up" style="margin-top:12px;border:1px solid #e5e7eb;padding:10px 12px">
    <b style="font-size:13.5px">Qualities for the player's ⚙ menu</b> <span class="shc-small">— original<?= $el['height'] ? ' ' . (int)$el['height'] . 'p' : '' ?> is always there. Add smaller files (made on your computer, e.g. with HandBrake) so slow internet can choose them.</span>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:8px;margin-top:8px">
    <?php foreach (['720', '480', '360'] as $qq): ?>
      <div style="border:1px dashed #d1d5db;padding:8px"><b><?= $qq ?>p</b> <?= isset($vv[$qq]) ? '<span class="shc-tag up">Uploaded</span>' : '<span class="shc-small">not added</span>' ?>
        <input type="file" class="shc-qf" data-q="<?= $qq ?>" accept="video/mp4,video/webm,.mp4,.m4v,.webm" style="display:block;margin-top:6px;max-width:100%">
        <?php if (isset($vv[$qq])): ?><button type="button" class="shc-free" style="margin-top:6px" data-rmq="<?= $qq ?>">Remove <?= $qq ?>p</button><?php endif; ?>
      </div>
    <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
  <div class="ci-f" style="margin-top:10px"><label>Notes under the video (optional)</label><textarea name="notes" rows="2" maxlength="5000"><?= ci_e($el['notes'] ?? '') ?></textarea></div>
  <label style="display:flex;gap:8px;align-items:center;margin:10px 0 0;font-weight:600"><input type="checkbox" name="free" value="1" <?= !empty($el['free']) ? 'checked' : '' ?>> Free preview (anyone can watch this video)</label>
  <div class="shc-bar"><i></i></div><p class="shc-small" data-st style="margin:6px 0 0"></p>
  <div style="margin-top:12px"><button class="ci-btn" type="submit"><i class="fas fa-check"></i> <?= $el ? 'Save video' : 'Add video' ?></button></div>
</form></div></div>

<!-- many YouTube videos at once -->
<details class="ci-card"><summary class="ci-card-h" style="cursor:pointer"><h3>Add many YouTube videos at once</h3><span class="shc-small">links or a playlist</span></summary><div class="ci-card-b">
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="bulk_youtube"><input type="hidden" name="pid" value="<?= $pid ?>">
  <div class="ci-f"><label>YouTube links — one per line (or one playlist link)</label><textarea name="links" rows="6" required placeholder="https://www.youtube.com/watch?v=AAAAAAAAAAA&#10;Chapter 2 — Polynomials | https://youtu.be/BBBBBBBBBBB&#10;https://www.youtube.com/playlist?list=PL…"></textarea>
  <span class="ci-hint">Titles come from YouTube by themselves; write "Title | link" to use your own. A playlist adds all its videos (it must be Public or Unlisted). Videos already in this course are skipped.</span></div>
  <div class="ci-grid3"><div class="ci-f"><label>Chapter</label><select name="section_id"><?= $secOpts(0) ?></select></div>
  <div class="ci-f"><label>Free preview</label><select name="free_first"><option value="0">None</option><option value="1">First video</option><option value="2">First 2 videos</option><option value="3">First 3 videos</option></select></div>
  <div class="ci-f"><label>&nbsp;</label><label style="font-weight:400;display:flex;gap:6px;align-items:center"><input type="checkbox" name="whole_list" value="1"> For a video link inside a playlist, add the whole playlist</label></div></div>
  <div style="margin-top:10px"><button class="ci-btn" type="submit"><i class="fab fa-youtube"></i> Add YouTube videos</button></div>
</form></div></details>

<!-- chapters + videos -->
<div class="ci-card" id="list"><div class="ci-card-h"><h3>Chapters &amp; videos</h3>
  <form method="post" style="display:flex;gap:6px"><?= csrf_field() ?><input type="hidden" name="action" value="add_section"><input type="hidden" name="pid" value="<?= $pid ?>"><input type="hidden" name="anchor" value="list"><input name="title" required maxlength="200" placeholder="New chapter name" style="height:34px;padding:0 10px;border:1px solid #d1d5db"><button class="ci-btn ci-sm" type="submit"><i class="fas fa-plus"></i> Add chapter</button></form>
</div><div class="ci-card-b ci-p0">
<?php if (!$cur && !$secs): ?><p class="ci-empty">No videos yet. Add the first one above.</p><?php endif; ?>
<?php
$secRows = [];
foreach ($secs as $sid => $st) $secRows[(int)$sid] = ['id' => (int)$sid, 'title' => $st, 'lessons' => []];
$loose = [];
foreach ($cur as $s) { if ($s['id']) $secRows[$s['id']]['lessons'] = $s['lessons']; else $loose = $s['lessons']; }
$blocks = $loose ? array_merge([['id' => 0, 'title' => '', 'lessons' => $loose]], array_values($secRows)) : array_values($secRows);
$si = 0;
foreach ($blocks as $s): ?>
<div class="shc-sec">
  <?php if ($s['id']): $si++; ?><div class="shc-sec-h">
    <form method="post" class="rn"><?= csrf_field() ?><input type="hidden" name="action" value="rename_section"><input type="hidden" name="id" value="<?= $s['id'] ?>"><input type="hidden" name="anchor" value="list"><input name="title" value="<?= ci_e($s['title']) ?>" maxlength="200" aria-label="Chapter name" onchange="this.form.submit()"></form>
    <span class="shc-small"><?= count($s['lessons']) ?> videos</span>
    <a class="ci-btn ci-ghost ci-sm" href="?id=<?= $pid ?>&section=<?= $s['id'] ?>#add"><i class="fas fa-plus"></i> Video</a>
    <div class="ci-icos"><?= $btn('move_section', $s['id'], 'up', 'Move chapter up', ['dir' => -1, 'anchor' => 'list']) ?><?= $btn('move_section', $s['id'], 'down', 'Move chapter down', ['dir' => 1, 'anchor' => 'list']) ?><?= $btn('delete_section', $s['id'], 'trash', 'Remove chapter', ['anchor' => 'list'], 'Remove the chapter "' . $s['title'] . '"? Its videos are kept.') ?></div>
  </div><?php elseif ($loose): ?><div class="shc-sec-h"><b>Without a chapter</b></div><?php endif; ?>
  <?php if ($s['lessons']): ?><div class="ci-table-wrap"><table class="ci-table"><tbody>
  <?php foreach ($s['lessons'] as $l): ?>
  <tr><td><b><?= ci_e($l['title']) ?></b><?= $l['duration'] ? ' <span class="shc-small">' . ci_e($l['duration']) . '</span>' : '' ?></td>
    <td><?= $l['video_file'] ? '<span class="shc-tag up">On our server · ' . $mb((int)$l['size']) . '</span>' : ($l['video_url'] ? '<span class="shc-tag yt">' . (sh_yt_id((string)$l['video_url']) ? 'YouTube' : 'Video link') . '</span>' : '<span class="shc-tag no">No video yet</span>') ?></td>
    <td><form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_free"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><input type="hidden" name="anchor" value="list"><button class="shc-free<?= $l['free'] ? ' on' : '' ?>" type="submit"><?= $l['free'] ? 'Free preview' : 'Paid' ?></button></form></td>
    <td><div class="ci-icos"><?= ci_ico('edit', '?id=' . $pid . '&lesson=' . (int)$l['id'] . '#add', 'Edit / upload video') ?><?= $btn('move_lesson', (int)$l['id'], 'up', 'Move up', ['dir' => -1, 'anchor' => 'list']) ?><?= $btn('move_lesson', (int)$l['id'], 'down', 'Move down', ['dir' => 1, 'anchor' => 'list']) ?><?= $btn('delete_lesson', (int)$l['id'], 'trash', 'Delete video', ['anchor' => 'list'], 'Delete the video "' . $l['title'] . '"?' . ($l['video_file'] ? ' The uploaded file is removed from the server.' : '')) ?></div></td></tr>
  <?php endforeach; ?>
  </tbody></table></div><?php elseif ($s['id']): ?><p class="shc-small" style="padding:8px 12px;margin:0">No videos in this chapter yet.</p><?php endif; ?>
</div>
<?php endforeach; ?>
</div></div>
<?php endif; ?>

<!-- PDFs -->
<div class="ci-card" id="pdfs"><div class="ci-card-h"><h3><?= $isCourse ? 'PDF notes with this course' : 'PDF files' ?></h3><span class="shc-small"><?= count($files) ?> file<?= count($files) === 1 ? '' : 's' ?></span></div><div class="ci-card-b">
<form id="shcPdf" class="shc-up" onsubmit="return false"><div class="ci-grid3">
  <div class="ci-f" style="grid-column:span 2"><label>PDF file(s)</label><input type="file" name="pfile" accept="application/pdf,.pdf" multiple required><span class="ci-hint">Choose one or many PDFs (up to 300 MB each).</span></div>
  <div class="ci-f"><label>Title (for one file)</label><input name="ptitle" maxlength="200" placeholder="the file name if empty"></div></div>
  <div class="shc-bar"><i></i></div><p class="shc-small" data-st style="margin:6px 0 0"></p>
  <div style="margin-top:10px"><button class="ci-btn" type="submit"><i class="fas fa-upload"></i> Upload PDF</button></div>
</form>
</div><div class="ci-card-b ci-p0">
<?php if ($files): ?><div class="ci-table-wrap"><table class="ci-table"><tbody>
<?php foreach ($files as $f): ?>
<tr><td><form method="post" style="display:flex;gap:6px"><?= csrf_field() ?><input type="hidden" name="action" value="rename_file"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><input type="hidden" name="anchor" value="pdfs"><input name="title" value="<?= ci_e($f['title']) ?>" maxlength="200" aria-label="PDF title" style="flex:1;border:1px solid transparent;background:transparent;font-weight:700;padding:4px 6px" onchange="this.form.submit()"></form></td>
  <td class="shc-small"><?= $mb((int)$f['size']) ?><?= $f['pages'] ? ' · ' . (int)$f['pages'] . ' pages' : '' ?></td>
  <td><form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_free"><input type="hidden" name="what" value="file"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><input type="hidden" name="anchor" value="pdfs"><button class="shc-free<?= $f['free'] ? ' on' : '' ?>" type="submit"><?= $f['free'] ? 'Free preview' : 'Paid' ?></button></form></td>
  <td><div class="ci-icos"><?= $btn('move_file', (int)$f['id'], 'up', 'Move up', ['dir' => -1, 'anchor' => 'pdfs']) ?><?= $btn('move_file', (int)$f['id'], 'down', 'Move down', ['dir' => 1, 'anchor' => 'pdfs']) ?><?= $btn('delete_file', (int)$f['id'], 'trash', 'Delete PDF', ['anchor' => 'pdfs'], 'Delete the PDF "' . $f['title'] . '"?') ?></div></td></tr>
<?php endforeach; ?>
</tbody></table></div><?php endif; ?>
</div></div>
</div>
<?php admin_shell_close(); ?>
<script>window.SHC = { pid: <?= $pid ?>, csrf: <?= json_encode(csrf_token()) ?>, self: <?= json_encode($self) ?> };</script>
<script src="/assets/js/shop-admin.js?v=<?= @filemtime(ROOT_PATH . '/assets/js/shop-admin.js') ?: 1 ?>" defer></script>
</body>
</html>
