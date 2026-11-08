<?php
// Online store → Add / edit a lecture, step by step:
//   type (video / PDF) → course → chapter (+ new) → unit (+ new, optional) → lecture no. (next by default) → name (made for you)
//   → video: a link only (YouTube, Google Drive, Vimeo, an MP4 on any server) — its name, picture and length are fetched
//     at once; videos are no longer uploaded to our server (lectures uploaded before keep playing)
//   → notes PDF of the lecture → VVI questions (your own Q&A + website / PDF links) → quiz (MCQs + links) → doubts on / off.
//   /admin/shop-lecture.php?course=ID (&chapter=ID)   /admin/shop-lecture.php?id=LESSON
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/institute.php';
require_once INCLUDES_PATH . '/shop.php';
$me = sh_page_start($pdo, ['products']);

if (isset($_GET['probe'])) { // check a video link (JSON)
    header('Content-Type: application/json');
    session_write_close();
    exit(json_encode(sh_video_probe((string)$_GET['probe']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

$lid = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$L = null;
if ($lid) { $q = $pdo->prepare("SELECT * FROM sh_lessons WHERE id = ?"); $q->execute([$lid]); $L = $q->fetch(PDO::FETCH_ASSOC) ?: null; if (!$L) { header('Location: /admin/shop-products.php'); exit; } }
$pid = $L ? (int)$L['product_id'] : (int)($_GET['course'] ?? $_POST['course'] ?? 0);
$courses = $pdo->query("SELECT p.id, p.title, p.class_id, p.status FROM sh_products p WHERE p.type = 'course' ORDER BY p.status = 'published' DESC, p.title")->fetchAll(PDO::FETCH_ASSOC);
$P = $pid ? sh_product($pdo, $pid, false) : null;
if ($P && $P['type'] !== 'course') $P = null;
$classes = ci_lists($pdo)['classes'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $P) {
    csrf_check();
    $ajax = !empty($_POST['ajax']);
    $fail = function (string $m) use ($ajax, $P, $lid) {
        if ($ajax) { header('Content-Type: application/json'); http_response_code(400); exit(json_encode(['error' => $m])); }
        admin_flash($m, 'error'); header('Location: /admin/shop-lecture.php?' . ($lid ? 'id=' . $lid : 'course=' . $P['id'])); exit;
    };
    $pidI = (int)$P['id'];
    // chapter: chosen, or a new one
    $sec = (int)($_POST['section_id'] ?? 0);
    $newSec = trim((string)($_POST['new_chapter'] ?? ''));
    if ($sec === -1 || ($newSec !== '' && !$sec)) {
        if ($newSec === '') $fail('Write the name of the new chapter.');
        $sort = (int)$pdo->query("SELECT COALESCE(MAX(sort),0) + 1 FROM sh_sections WHERE product_id = $pidI")->fetchColumn();
        $pdo->prepare("INSERT INTO sh_sections (product_id, title, sort) VALUES (?, ?, ?)")->execute([$pidI, mb_substr($newSec, 0, 200), $sort]);
        $sec = (int)$pdo->lastInsertId();
    } elseif (!$sec || !(int)$pdo->query("SELECT COUNT(*) FROM sh_sections WHERE id = $sec AND product_id = $pidI")->fetchColumn()) $fail('Choose the chapter (or make a new one).');
    // unit: optional, chosen or new
    $unit = (int)($_POST['unit_id'] ?? 0);
    $newUnit = trim((string)($_POST['new_unit'] ?? ''));
    if ($unit === -1) {
        if ($newUnit === '') $fail('Write the name of the new unit, or choose "No unit".');
        $sort = (int)$pdo->query("SELECT COALESCE(MAX(sort),0) + 1 FROM sh_units WHERE section_id = $sec")->fetchColumn();
        $pdo->prepare("INSERT INTO sh_units (product_id, section_id, title, sort) VALUES (?, ?, ?, ?)")->execute([$pidI, $sec, mb_substr($newUnit, 0, 200), $sort]);
        $unit = (int)$pdo->lastInsertId();
    } elseif ($unit && !(int)$pdo->query("SELECT COUNT(*) FROM sh_units WHERE id = $unit AND section_id = $sec")->fetchColumn()) $unit = 0;
    $kind = ($_POST['kind'] ?? '') === 'pdf' ? 'pdf' : 'video';
    $no = max(1, (int)($_POST['lecture_no'] ?? 1));
    $title = trim((string)($_POST['title'] ?? ''));
    if ($title === '') $fail('Write the lecture name.');
    $url = $kind === 'video' ? trim((string)($_POST['video_url'] ?? '')) : '';
    $keepUpload = $kind === 'video' && $url === '' && $L && !empty($L['video_file']); // a video uploaded before keeps playing until a link replaces it
    $src = $url !== '' ? 'link' : 'keep';
    if ($kind === 'video' && !$keepUpload && !preg_match('#^https?://\S+$#i', $url)) $fail('Paste the video link (YouTube, Google Drive, Vimeo or an MP4 link — it starts with https://).');
    $dur = preg_match('/^\d{1,3}:\d{2}(:\d{2})?$/', trim((string)($_POST['duration'] ?? ''))) ? trim((string)$_POST['duration']) : null;
    if ($kind === 'video' && $url !== '' && !$dur) { $pr = sh_video_probe($url); if (!empty($pr['duration']) && preg_match('/^\d{1,3}:\d{2}(:\d{2})?$/', $pr['duration'])) $dur = $pr['duration']; } // length fetched from the link
    // VVI: Q&A + links
    $vqa = []; foreach ((array)($_POST['vq'] ?? []) as $i => $qq) { $qq = trim((string)$qq); $aa = trim((string)($_POST['va'][$i] ?? '')); if ($qq !== '') $vqa[] = ['q' => mb_substr($qq, 0, 1000), 'a' => mb_substr($aa, 0, 5000)]; }
    $vl = []; foreach ((array)($_POST['vlu'] ?? []) as $i => $uu) { $uu = trim((string)$uu); if (preg_match('#^https?://\S+$#i', $uu)) $vl[] = ['t' => mb_substr(trim((string)($_POST['vlt'][$i] ?? '')) ?: 'Read more', 0, 150), 'u' => mb_substr($uu, 0, 500)]; }
    // quiz: MCQs + links
    $qi = [];
    foreach ((array)($_POST['qq'] ?? []) as $i => $qq) {
        $qq = trim((string)$qq); if ($qq === '') continue;
        $opts = array_map(fn($v) => mb_substr(trim((string)$v), 0, 300), (array)($_POST['qo'][$i] ?? []));
        $ans = (int)($_POST['qa'][$i] ?? 0);
        $keep = []; $newAns = 0;
        foreach ($opts as $k => $v) if ($v !== '') { if ($k === $ans) $newAns = count($keep); $keep[] = $v; }
        if (count($keep) < 2) $fail('Quiz question ' . ($i + 1) . ' needs at least 2 options.');
        $qi[] = ['q' => mb_substr($qq, 0, 1000), 'o' => $keep, 'a' => $newAns, 'e' => mb_substr(trim((string)($_POST['qe'][$i] ?? '')), 0, 1000)];
    }
    $ql = []; foreach ((array)($_POST['qlu'] ?? []) as $i => $uu) { $uu = trim((string)$uu); if (preg_match('#^https?://\S+$#i', $uu)) $ql[] = ['t' => mb_substr(trim((string)($_POST['qlt'][$i] ?? '')) ?: 'Take the quiz', 0, 150), 'u' => mb_substr($uu, 0, 500)]; }
    $vvi = ($vqa || $vl) ? json_encode(['qa' => $vqa, 'links' => $vl], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    $quiz = ($qi || $ql) ? json_encode(['items' => $qi, 'links' => $ql], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    $free = !empty($_POST['free']) ? 1 : 0; $doubts = !empty($_POST['doubts_on']) ? 1 : 0;
    $about = trim((string)($_POST['notes'] ?? '')) ?: null;
    if ($L) {
        $set = "section_id=?, unit_id=?, lecture_no=?, title=?, kind=?, duration=COALESCE(?, duration), free=?, notes=?, vvi=?, quiz=?, doubts_on=?";
        $vals = [$sec, $unit ?: null, $no, mb_substr($title, 0, 200), $kind, $dur, $free, $about, $vvi, $quiz, $doubts];
        if ($kind === 'video' && $src === 'link') { // a link replaces an uploaded video
            foreach (array_filter(array_merge([$L['video_file']], array_values(sh_variants($L)))) as $f) { $f = sh_media_path($f); if (is_file($f)) @unlink($f); }
            $set .= ", video_url=?, video_file=NULL, variants=NULL, height=NULL, size=0"; $vals[] = $url;
        }
        if ($kind === 'video' && $L['pdf_file']) { $f = sh_media_path($L['pdf_file']); if (is_file($f)) @unlink($f); $set .= ", pdf_file=NULL, pdf_pages=NULL"; }
        if ($kind === 'pdf') $set .= ", video_url=NULL";
        $vals[] = $L['id'];
        $pdo->prepare("UPDATE sh_lessons SET $set WHERE id=?")->execute($vals);
        $newId = (int)$L['id'];
    } else {
        // place it after the last lecture of its chapter (or at the end)
        $after = $pdo->query("SELECT COALESCE(MAX(sort), -1) FROM sh_lessons WHERE product_id = $pidI AND section_id = $sec")->fetchColumn();
        $sort = $after >= 0 ? (int)$after + 1 : (int)$pdo->query("SELECT COALESCE(MAX(sort),0) + 1 FROM sh_lessons WHERE product_id = $pidI")->fetchColumn();
        $pdo->prepare("UPDATE sh_lessons SET sort = sort + 1 WHERE product_id = ? AND sort >= ?")->execute([$pidI, $sort]);
        $pdo->prepare("INSERT INTO sh_lessons (product_id, section_id, unit_id, lecture_no, title, kind, video_url, duration, free, notes, vvi, quiz, doubts_on, sort) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$pidI, $sec, $unit ?: null, $no, mb_substr($title, 0, 200), $kind, $url ?: null, $dur, $free, $about, $vvi, $quiz, $doubts, $sort]);
        $newId = (int)$pdo->lastInsertId();
    }
    foreach ((array)($_POST['note_title'] ?? []) as $fid => $nt) { // rename the notes PDFs
        $nt = trim((string)$nt); if ($nt === '') continue;
        $pdo->prepare("UPDATE sh_files SET title = ? WHERE id = ? AND lesson_id = ?")->execute([mb_substr($nt, 0, 200), (int)$fid, $newId]);
    }
    // notes PDFs of this lecture that the admin chose to remove
    foreach ((array)($_POST['rm_note'] ?? []) as $fid) {
        $f = $pdo->prepare("SELECT file FROM sh_files WHERE id = ? AND lesson_id = ?"); $f->execute([(int)$fid, $newId]);
        if ($path = $f->fetchColumn()) { $pdo->prepare("DELETE FROM sh_files WHERE id = ?")->execute([(int)$fid]); $pp = sh_media_path($path); if (is_file($pp)) @unlink($pp); }
    }
    admin_log('shop_lecture', ($L ? 'Edited' : 'Added') . " lecture #$newId \"$title\" in course #$pidI");
    $next = !empty($_POST['next']);
    $go = $next ? '/admin/shop-lecture.php?course=' . $pidI . '&chapter=' . $sec . '&unit=' . $unit : '/admin/shop-content.php?id=' . $pidI . '#list';
    if ($ajax) { header('Content-Type: application/json'); exit(json_encode(['ok' => true, 'id' => $newId, 'go' => $go])); }
    admin_flash($L ? 'Lecture saved.' : 'Lecture added.' . ($next ? ' Add the next one.' : ''));
    header('Location: ' . $go); exit;
}

$secs = $P ? $pdo->query("SELECT id, title FROM sh_sections WHERE product_id = " . (int)$P['id'] . " ORDER BY sort, id")->fetchAll(PDO::FETCH_KEY_PAIR) : [];
$units = $P ? $pdo->query("SELECT id, section_id, title FROM sh_units WHERE product_id = " . (int)$P['id'] . " ORDER BY sort, id")->fetchAll(PDO::FETCH_ASSOC) : [];
$cnt = $P ? $pdo->query("SELECT section_id, COUNT(*) n, COALESCE(MAX(lecture_no),0) mx FROM sh_lessons WHERE product_id = " . (int)$P['id'] . ($L ? " AND id <> " . (int)$L['id'] : '') . " GROUP BY section_id")->fetchAll(PDO::FETCH_ASSOC) : [];
$nextNo = []; foreach ($cnt as $c) $nextNo[(int)$c['section_id']] = max((int)$c['n'], (int)$c['mx']) + 1;
$selSec = $L ? (int)$L['section_id'] : (int)($_GET['chapter'] ?? 0);
if (!$selSec && $P) $selSec = (int)$pdo->query("SELECT id FROM sh_sections WHERE product_id = " . (int)$P['id'] . " AND yt_list IS NOT NULL ORDER BY sort, id LIMIT 1")->fetchColumn(); // a chapter linked to a YouTube playlist first
if (!$selSec && $secs) $selSec = (int)array_key_last($secs);
$selUnit = $L ? (int)$L['unit_id'] : (int)($_GET['unit'] ?? 0);
$vvi = $L ? sh_vvi($L) : ['qa' => [], 'links' => []];
$quiz = $L ? sh_quiz($L) : ['items' => [], 'links' => []];
$notesF = $L ? $pdo->query("SELECT id, title, pages, size FROM sh_files WHERE lesson_id = " . (int)$L['id'] . " ORDER BY sort, id")->fetchAll(PDO::FETCH_ASSOC) : [];
$isLink = $L ? (!$L['video_file'] && $L['video_url']) : false;
$kind = $L['kind'] ?? 'video';
$clsName = $P && !empty($P['category_id']) ? sh_category_name($pdo, (int)$P['category_id']) : '';
if ($clsName === '' && $P && $P['class_id'] && isset($classes[$P['class_id']])) $clsName = (string)$classes[$P['class_id']];
if (preg_match('/^(\d{1,2})(st|nd|rd|th)$/i', $clsName, $cm)) $clsName = 'Class ' . $cm[1]; // "10th" → "Class 10"
$cTitle = $P ? trim(preg_replace('/\s*[—–-]?\s*(Complete|Full)?\s*Course\b.*$/i', '', preg_replace('/\s*\(Demo\)\s*/', '', $P['title']))) : '';
$prefix = trim(($clsName !== '' && stripos($cTitle, $clsName) === false ? $clsName . ' ' : '') . $cTitle);
$vv = $L ? sh_variants($L) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title><?= $L ? 'Edit lecture' : 'Add lecture' ?> - <?= ci_e(edu_site_name()) ?></title>
<?php admin_shell_head(); ci_head(); ?>
<style>
.slc{max-width:980px}
.slc-step{display:flex;gap:14px;align-items:flex-start}.slc-n{width:30px;height:30px;flex:0 0 auto;display:grid;place-items:center;background:var(--ci-acc,#4a3369);color:#fff;font-weight:800;font-size:14px}
.slc-step > div{flex:1;min-width:0}
.slc-seg{display:flex;border:1px solid #d1d5db;width:max-content;max-width:100%}.slc-seg label{display:flex;align-items:center;gap:8px;padding:10px 16px;cursor:pointer;font-weight:700;font-size:14px;margin:0}
.slc-seg label:has(input:checked){background:var(--ci-acc,#4a3369);color:#fff}.slc-seg input{accent-color:#fff;margin:0}
.slc-new{display:flex;gap:8px;margin-top:6px}.slc-new input{flex:1}
.meta-panel.slc-locked{opacity:.5}.meta-panel.slc-locked .meta-panel-body{pointer-events:none;user-select:none}
.slc-need{display:block;margin:-2px 0 0;padding:6px 12px;background:#fffbeb;border-bottom:1px solid #fde68a;color:#92400e;font-size:12.5px;font-weight:600}
.slc-probe{margin-top:10px;border:1px solid #e5e7eb;padding:10px 12px;display:none;gap:12px;align-items:center;font-size:14px}
.slc-probe.ok{display:flex;border-color:#86efac;background:#f0fdf4}.slc-probe.bad{display:flex;border-color:#fecaca;background:#fef2f2;color:#991b1b}.slc-probe.wait{display:flex;color:#6b7280}
.slc-probe img{width:96px;height:54px;object-fit:cover;flex:0 0 auto}.slc-probe b{display:block}.slc-probe small{color:#6b7280}
.slc-rep{display:flex;flex-direction:column;gap:10px}
.slc-row{display:grid;grid-template-columns:1fr 34px;gap:8px;border:1px solid #e5e7eb;background:#fafafa;padding:10px}
.slc-row .slc-in{display:flex;flex-direction:column;gap:6px}.slc-row input,.slc-row textarea{width:100%}
.slc-opts{display:grid;grid-template-columns:1fr 1fr;gap:6px}.slc-opt{display:flex;gap:6px;align-items:center}.slc-opt input[type=radio]{width:auto;accent-color:#15803d}
.slc-sub{font-size:12.5px;color:#6b7280;margin:0 0 8px}
.slc-bar{height:6px;background:#e5e7eb;margin-top:8px;display:none}.slc-bar i{display:block;height:100%;width:0;background:var(--ci-acc,#4a3369);transition:width .2s}
.slc-actions{position:sticky;bottom:0;background:#fff;border-top:1px solid #e5e7eb;padding:12px 0;display:flex;gap:10px;flex-wrap:wrap;align-items:center;z-index:5}
.slc-note{display:flex;gap:8px;align-items:center;font-size:13.5px;padding:6px 0}
.slc-plus{margin-left:6px;border:1px solid var(--ci-acc,#4a3369);background:#fff;color:var(--ci-acc,#4a3369);font:700 11.5px inherit;font-family:inherit;padding:2px 8px;cursor:pointer}.slc-plus:hover{background:var(--ci-acc,#4a3369);color:#fff}
.slc-notes{display:flex;flex-direction:column;gap:8px}
.slc-nrow{display:grid;grid-template-columns:42px 1fr auto auto;gap:10px;align-items:center;border:1px solid #e5e7eb;background:#fafafa;padding:8px 10px}
.slc-nrow input[type=text],.slc-nrow input:not([type]){box-sizing:border-box;width:100%;min-height:36px;padding:7px 10px;border:1px solid #d1d5db;font:inherit;font-size:14px;background:#fff}
.slc-nico{display:grid;place-items:center;height:32px;background:#fee2e2;color:#b91c1c;font-weight:800;font-size:11px}.slc-nrow small{color:#6b7280;font-size:12px;white-space:nowrap}.slc-nrm{display:flex;gap:5px;align-items:center;font-size:12.5px;color:#b91c1c;margin:0}
@media (max-width:700px){.slc-nrow{grid-template-columns:42px 1fr}.slc-nrow small,.slc-nrm{grid-column:2}}
@media (max-width:700px){.slc-opts{grid-template-columns:1fr}}
</style>
</head>
<body>
<?php admin_shell_open($L ? 'Edit lecture' : 'Add lecture', $P ? ci_e($P['title']) : 'Choose a course first', $P ? '<a class="ci-btn ci-ghost" href="/admin/shop-content.php?id=' . (int)$P['id'] . '"><i class="fas fa-list"></i> All lectures</a>' : ''); ?>
<div class="ci slc">
<?= admin_flash() ?>
<?php if (!$courses): ?>
  <div class="ci-card"><div class="ci-card-b ci-empty">No online course yet. <a href="/admin/shop-products.php?new=1&type=course">Add a course</a> first.</div></div>
<?php else: ?>
<form method="post" id="slcForm"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)($L['id'] ?? 0) ?>"><input type="hidden" name="course" value="<?= (int)($P['id'] ?? 0) ?>"><input type="hidden" name="next" id="slcNext" value="">
<div class="editor-layout sx-layout">
<div class="editor-main">
<?php if ($P): ?>
  <div class="meta-panel" data-step="2" data-need="Choose the chapter first (on the right)."><div class="meta-panel-body sx-titlebox"><div class="ci-f slc-titlef"><input name="title" id="slcTitle" class="post-title-input" required maxlength="200" placeholder="Lecture name" value="<?= ci_e($L['title'] ?? '') ?>" data-prefix="<?= ci_e($prefix) ?>" data-auto="<?= $L ? '0' : '1' ?>"><span class="ci-hint">Made for you: class + course – chapter – lecture number – unit. You can change it.</span></div></div></div>
  <div class="meta-panel slc-video" data-step="3" data-need="Write the lecture name first."><div class="meta-panel-header"><span>Video link</span><small class="ci-muted">YouTube or any other source</small></div><div class="meta-panel-body">
    <input type="hidden" name="source" value="link">
    <?php if (!empty($L['video_file'])): ?><p class="slc-sub slc-kept" style="margin:0 0 10px;padding:8px 10px;background:#fffbeb;border:1px solid #fde68a">This lecture plays a video that was uploaded to the server before (<?= round($L['size'] / 1048576, 1) ?> MB). It keeps playing — paste a link below only to replace it.</p><?php endif; ?>
  <div class="slc-link"><div class="ci-f"><input name="video_url" id="slcUrl" maxlength="500" placeholder="Paste the video link — YouTube, Google Drive, Vimeo or an MP4 link from any server" value="<?= ci_e($L['video_url'] ?? '') ?>"></div>
    <button type="button" class="ci-btn ci-ghost ci-sm" id="slcFetch" style="margin-top:8px"><i class="fas fa-magnifying-glass"></i> Fetch video details</button>
    <div class="slc-probe" id="slcProbe"></div></div>
  <div class="ci-f" style="margin-top:12px;max-width:220px"><label>Duration</label><input name="duration" id="slcDur" maxlength="9" placeholder="filled in by itself" value="<?= ci_e($L['duration'] ?? '') ?>"></div>
  <div class="slc-bar" id="slcBar"><i></i></div><p class="slc-sub" id="slcSt" style="margin-top:6px"></p>
  </div></div>
  <div class="meta-panel slc-pdf" data-step="3" data-need="Write the lecture name first."><div class="meta-panel-header"><span>Lecture PDF</span></div><div class="meta-panel-body">
    <input type="file" id="slcPdf" accept="application/pdf,.pdf"<?= !empty($L['pdf_file']) ? ' data-has="1"' : '' ?>><p class="slc-sub" style="margin-top:6px"><?= !empty($L['pdf_file']) ? 'A PDF is uploaded (' . (int)$L['pdf_pages'] . ' pages). Choose a file only to replace it.' : 'Students read it on their phone or computer.' ?></p>
  </div></div>
  <div class="meta-panel" data-step="4"><div class="meta-panel-header"><span>Notes of this lecture</span><small class="ci-muted">PDF · optional · as many as you like</small></div><div class="meta-panel-body">
  <p class="slc-sub">Shown in the <b>PDF Notes</b> tab under the video. Give each one a clear name, e.g. "Class notes", "Formula sheet", "Practice questions".</p>
  <div class="slc-notes" id="slcNoteList">
    <?php foreach ($notesF as $nf): ?><div class="slc-nrow"><span class="slc-nico">PDF</span><input name="note_title[<?= (int)$nf['id'] ?>]" maxlength="200" value="<?= ci_e($nf['title']) ?>" aria-label="Notes name"><small><?= (int)$nf['pages'] ?> pages · uploaded</small><label class="slc-nrm"><input type="checkbox" name="rm_note[]" value="<?= (int)$nf['id'] ?>"> Remove</label></div><?php endforeach; ?>
  </div>
  <input type="file" id="slcNotes" accept="application/pdf,.pdf" multiple hidden>
  <button type="button" class="ci-btn ci-ghost ci-sm" id="slcNotesAdd" style="margin-top:8px"><i class="fas fa-plus"></i> Add notes PDF</button>
  </div></div>
  <div class="meta-panel" data-step="4"><div class="meta-panel-header"><span>VVI questions</span><small class="ci-muted">optional</small></div><div class="meta-panel-body">
  <p class="slc-sub">Write important questions with answers, and / or add links (an article or MCQ post on your website, a PDF…). Students see the questions first, then the links.</p>
  <div class="slc-rep" id="slcVqa"><?php foreach ($vvi['qa'] ?: [['', '']] as [$qq, $aa]): ?><div class="slc-row"><div class="slc-in"><input name="vq[]" maxlength="1000" placeholder="Question" value="<?= ci_e($qq) ?>"><textarea name="va[]" rows="2" maxlength="5000" placeholder="Answer"><?= ci_e($aa) ?></textarea></div><button type="button" class="ci-ico" data-i="trash" aria-label="Remove" data-rm><?= ci_svg('trash') ?></button></div><?php endforeach; ?></div>
  <button type="button" class="ci-btn ci-ghost ci-sm" data-add="slcVqa" style="margin-top:8px"><i class="fas fa-plus"></i> Add question</button>
  <div class="slc-rep" id="slcVl" style="margin-top:12px"><?php foreach ($vvi['links'] ?: [['', '']] as [$tt, $uu]): ?><div class="slc-row"><div class="slc-in" style="flex-direction:row"><input name="vlt[]" maxlength="150" placeholder="Button text, e.g. Important questions (article)" value="<?= ci_e($tt) ?>"><input name="vlu[]" maxlength="500" placeholder="https://…" value="<?= ci_e($uu) ?>"></div><button type="button" class="ci-ico" data-i="trash" aria-label="Remove" data-rm><?= ci_svg('trash') ?></button></div><?php endforeach; ?></div>
  <button type="button" class="ci-btn ci-ghost ci-sm" data-add="slcVl" style="margin-top:8px"><i class="fas fa-link"></i> Add link</button>
  </div></div>
  <div class="meta-panel" data-step="4"><div class="meta-panel-header"><span>Quiz</span><small class="ci-muted">optional</small></div><div class="meta-panel-body">
  <p class="slc-sub">MCQs that students can attempt right under the video (tick the right option), and / or links to a quiz.</p>
  <div class="slc-rep" id="slcQz"><?php $qItems = $quiz['items'] ?: [['', ['', '', '', ''], 0, '']]; foreach ($qItems as $n => [$qq, $oo, $aa, $ee]): $oo = array_pad($oo, 4, ''); ?>
    <div class="slc-row"><div class="slc-in"><input name="qq[<?= $n ?>]" maxlength="1000" placeholder="Question" value="<?= ci_e($qq) ?>">
      <div class="slc-opts"><?php foreach ($oo as $k => $ov): ?><label class="slc-opt"><input type="radio" name="qa[<?= $n ?>]" value="<?= $k ?>" <?= (int)$aa === $k ? 'checked' : '' ?> title="Right answer"><input name="qo[<?= $n ?>][]" maxlength="300" placeholder="Option <?= chr(65 + $k) ?>" value="<?= ci_e($ov) ?>"></label><?php endforeach; ?></div>
      <input name="qe[<?= $n ?>]" maxlength="1000" placeholder="Explanation (optional, shown after checking)" value="<?= ci_e($ee) ?>"></div><button type="button" class="ci-ico" data-i="trash" aria-label="Remove" data-rm><?= ci_svg('trash') ?></button></div>
  <?php endforeach; ?></div>
  <button type="button" class="ci-btn ci-ghost ci-sm" data-add="slcQz" data-renum style="margin-top:8px"><i class="fas fa-plus"></i> Add MCQ</button>
  <div class="slc-rep" id="slcQl" style="margin-top:12px"><?php foreach ($quiz['links'] ?: [['', '']] as [$tt, $uu]): ?><div class="slc-row"><div class="slc-in" style="flex-direction:row"><input name="qlt[]" maxlength="150" placeholder="Button text, e.g. Chapter 1 MCQ test" value="<?= ci_e($tt) ?>"><input name="qlu[]" maxlength="500" placeholder="https://…" value="<?= ci_e($uu) ?>"></div><button type="button" class="ci-ico" data-i="trash" aria-label="Remove" data-rm><?= ci_svg('trash') ?></button></div><?php endforeach; ?></div>
  <button type="button" class="ci-btn ci-ghost ci-sm" data-add="slcQl" style="margin-top:8px"><i class="fas fa-link"></i> Add quiz link</button>
  </div></div>
<?php else: ?>
  <div class="meta-panel"><div class="meta-panel-body"><p class="ci-muted" style="margin:0">Choose the course on the right to add a lecture. Courses are made in <a href="/admin/shop-products.php?type=course">Online Courses</a>.</p></div></div>
<?php endif; ?>
</div>
<div class="editor-sidebar-panel">
  <?php if ($P): ?><div class="meta-panel" data-step="4"><div class="meta-panel-header"><span>Publish</span></div><div class="meta-panel-body">
    <div class="sx-pub slc-pubb"><button class="ci-btn" type="submit"><i class="fas fa-check"></i> Save lecture</button><button class="ci-btn ci-ghost" type="submit" data-next><i class="fas fa-forward"></i> Save &amp; add next</button></div>
    <span class="ci-muted" id="slcMsg"></span>
  </div></div><?php endif; ?>
  <div class="meta-panel"><div class="meta-panel-header"><span>Lecture type</span></div><div class="meta-panel-body"><div class="slc-seg"><label><input type="radio" name="kind" value="video" <?= $kind !== 'pdf' ? 'checked' : '' ?>> Video lecture</label><label><input type="radio" name="kind" value="pdf" <?= $kind === 'pdf' ? 'checked' : '' ?>> PDF lecture</label></div></div></div>
  <div class="meta-panel"><div class="meta-panel-header"><span>Course &amp; chapter</span></div><div class="meta-panel-body slc-side">
    <div class="ci-f"><label>Course</label><select id="slcCourse" <?= $L ? 'disabled' : '' ?> data-search><option value="">— Choose a course —</option><?php foreach ($courses as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)($P['id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= ci_e($c['title'] . ($c['status'] !== 'published' ? ' (' . $c['status'] . ')' : '')) ?></option><?php endforeach; ?></select><span class="ci-hint">Online courses are made in <a href="/admin/shop-products.php?type=course">Online Courses</a>.</span></div>
    <?php if ($P): ?><div class="ci-f"><label>Chapter <button type="button" class="slc-plus" data-newfor="slcSec">+ New</button></label><select name="section_id" id="slcSec"><?php foreach ($secs as $k => $t): ?><option value="<?= $k ?>" <?= $selSec === (int)$k ? 'selected' : '' ?> data-next="<?= $nextNo[(int)$k] ?? 1 ?>"><?= ci_e($t) ?></option><?php endforeach; ?><option value="-1" data-next="1" <?= !$secs ? 'selected' : '' ?>>+ New chapter…</option></select>
      <div class="slc-new" id="slcSecNew" hidden><input name="new_chapter" maxlength="200" placeholder="New chapter name, e.g. Real Numbers"></div></div><div class="ci-f"><label>Unit <span class="ci-muted" style="font-weight:400">(optional)</span> <button type="button" class="slc-plus" data-newfor="slcUnit">+ New</button></label><select name="unit_id" id="slcUnit"><option value="0" data-sec="*">— No unit —</option><?php foreach ($units as $un): ?><option value="<?= (int)$un['id'] ?>" data-sec="<?= (int)$un['section_id'] ?>" <?= $selUnit === (int)$un['id'] ? 'selected' : '' ?>><?= ci_e($un['title']) ?></option><?php endforeach; ?><option value="-1" data-sec="*">+ New unit…</option></select>
      <div class="slc-new" id="slcUnitNew" hidden><input name="new_unit" maxlength="200" placeholder="New unit name, e.g. Euclid's Lemma"></div></div><div class="ci-f"><label>Lecture number</label><input name="lecture_no" id="slcNo" type="number" min="1" value="<?= (int)($L['lecture_no'] ?? ($nextNo[$selSec] ?? 1)) ?>"><span class="ci-hint">Next number of the chapter.</span></div><?php endif; ?>
  </div></div>
  <?php if ($P): ?><div class="meta-panel" data-step="4"><div class="meta-panel-header"><span>Options</span></div><div class="meta-panel-body"><label style="display:flex;gap:8px;align-items:center;font-weight:600;margin:0 0 8px"><input type="checkbox" name="doubts_on" value="1" <?= ($L['doubts_on'] ?? 1) ? 'checked' : '' ?>> Students can ask doubts &amp; problems on this lecture <span class="ci-muted" style="font-weight:400">(the teacher answers in Store → Doubts)</span></label>
  <label style="display:flex;gap:8px;align-items:center;font-weight:600;margin:0 0 8px"><input type="checkbox" name="free" value="1" <?= !empty($L['free']) ? 'checked' : '' ?>> Free preview — anyone can open this lecture</label>
  <input type="hidden" name="notes" value="<?= ci_e($L['notes'] ?? '') ?>"></div></div><?php endif; ?>
</div>
</div>
</form>
<?php endif; ?>
</div>
<?php admin_shell_close(); ?>
<script>window.SHC = { pid: <?= (int)($P['id'] ?? 0) ?>, csrf: <?= json_encode(csrf_token()) ?>, self: '' };</script>
<script src="/assets/js/shop-lecture.js?v=<?= @filemtime(ROOT_PATH . '/assets/js/shop-lecture.js') ?: 1 ?>" defer></script>
</body>
</html>
