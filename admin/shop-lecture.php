<?php
// Online store → Add / edit a lecture, step by step:
//   type (video / PDF) → course → chapter (+ new) → unit (+ new, optional) → lecture no. (next by default) → name (made for you)
//   → video: upload to our server OR any link (YouTube, Google Drive, Vimeo, an MP4 on any server) checked at once
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
    $src = (string)($_POST['source'] ?? 'upload');
    $url = $kind === 'video' && $src === 'link' ? trim((string)($_POST['video_url'] ?? '')) : '';
    if ($kind === 'video' && $src === 'link' && !preg_match('#^https?://#i', $url)) $fail('Paste the video link (it starts with https://).');
    $dur = preg_match('/^\d{1,3}:\d{2}(:\d{2})?$/', trim((string)($_POST['duration'] ?? ''))) ? trim((string)$_POST['duration']) : null;
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
if (!$selSec && $secs) $selSec = (int)array_key_last($secs);
$selUnit = $L ? (int)$L['unit_id'] : (int)($_GET['unit'] ?? 0);
$vvi = $L ? sh_vvi($L) : ['qa' => [], 'links' => []];
$quiz = $L ? sh_quiz($L) : ['items' => [], 'links' => []];
$notesF = $L ? $pdo->query("SELECT id, title, pages, size FROM sh_files WHERE lesson_id = " . (int)$L['id'] . " ORDER BY sort, id")->fetchAll(PDO::FETCH_ASSOC) : [];
$isLink = $L ? (!$L['video_file'] && $L['video_url']) : false;
$kind = $L['kind'] ?? 'video';
$prefix = $P ? trim(($P['class_id'] && isset($classes[$P['class_id']]) ? $classes[$P['class_id']] . ' ' : '') . preg_replace('/\s*[—–-]\s*(Complete|Full)?\s*Course.*$/i', '', preg_replace('/\s*\(Demo\)\s*/', '', $P['title']))) : '';
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

<div class="ci-card"><div class="ci-card-b slc-step"><span class="slc-n">1</span><div>
  <label style="font-weight:700;display:block;margin-bottom:8px">What are you adding?</label>
  <div class="slc-seg"><label><input type="radio" name="kind" value="video" <?= $kind !== 'pdf' ? 'checked' : '' ?>> Video lecture</label><label><input type="radio" name="kind" value="pdf" <?= $kind === 'pdf' ? 'checked' : '' ?>> PDF lecture</label></div>
</div></div></div>

<div class="ci-card"><div class="ci-card-b slc-step"><span class="slc-n">2</span><div>
  <div class="ci-grid3">
    <div class="ci-f" style="grid-column:1/-1"><label>Course</label><select id="slcCourse" <?= $L ? 'disabled' : '' ?> data-search><option value="">— Choose a course —</option><?php foreach ($courses as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)($P['id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= ci_e($c['title'] . ($c['status'] !== 'published' ? ' (' . $c['status'] . ')' : '')) ?></option><?php endforeach; ?></select><span class="ci-hint">Online courses are made in <a href="/admin/shop-products.php?type=course">Online Courses</a>.</span></div>
    <?php if ($P): ?>
    <div class="ci-f"><label>Chapter</label><select name="section_id" id="slcSec"><?php foreach ($secs as $k => $t): ?><option value="<?= $k ?>" <?= $selSec === (int)$k ? 'selected' : '' ?> data-next="<?= $nextNo[(int)$k] ?? 1 ?>"><?= ci_e($t) ?></option><?php endforeach; ?><option value="-1" data-next="1" <?= !$secs ? 'selected' : '' ?>>+ New chapter…</option></select>
      <div class="slc-new" id="slcSecNew" hidden><input name="new_chapter" maxlength="200" placeholder="New chapter name, e.g. Real Numbers"></div></div>
    <div class="ci-f"><label>Unit <span class="ci-muted" style="font-weight:400">(optional)</span></label><select name="unit_id" id="slcUnit"><option value="0" data-sec="*">— No unit —</option><?php foreach ($units as $un): ?><option value="<?= (int)$un['id'] ?>" data-sec="<?= (int)$un['section_id'] ?>" <?= $selUnit === (int)$un['id'] ? 'selected' : '' ?>><?= ci_e($un['title']) ?></option><?php endforeach; ?><option value="-1" data-sec="*">+ New unit…</option></select>
      <div class="slc-new" id="slcUnitNew" hidden><input name="new_unit" maxlength="200" placeholder="New unit name, e.g. Euclid's Lemma"></div></div>
    <div class="ci-f"><label>Lecture number</label><input name="lecture_no" id="slcNo" type="number" min="1" value="<?= (int)($L['lecture_no'] ?? ($nextNo[$selSec] ?? 1)) ?>"><span class="ci-hint">Next number of the chapter.</span></div>
    <div class="ci-f" style="grid-column:1/-1"><label>Lecture name</label><input name="title" id="slcTitle" required maxlength="200" value="<?= ci_e($L['title'] ?? '') ?>" data-prefix="<?= ci_e($prefix) ?>" data-auto="<?= $L ? '0' : '1' ?>"><span class="ci-hint">Made for you from the class, course, chapter, unit and lecture number — you can change it.</span></div>
    <?php endif; ?>
  </div>
</div></div></div>

<?php if ($P): ?>
<div class="ci-card slc-video"><div class="ci-card-b slc-step"><span class="slc-n">3</span><div>
  <label style="font-weight:700;display:block;margin-bottom:8px">Video</label>
  <div class="slc-seg"><label><input type="radio" name="source" value="upload" <?= $isLink ? '' : 'checked' ?>> Upload to our server</label><label><input type="radio" name="source" value="link" <?= $isLink ? 'checked' : '' ?>> Video link</label></div>
  <div class="slc-up" style="margin-top:12px"><input type="file" id="slcFile" accept="video/mp4,video/webm,video/quicktime,.mp4,.m4v,.webm,.mov"><p class="slc-sub" style="margin-top:6px"><?= !empty($L['video_file']) ? 'A video is uploaded (' . round($L['size'] / 1048576, 1) . ' MB). Choose a file only to replace it.' : 'Any size — sent in small pieces. MP4 (H.264) plays on every phone.' ?></p>
    <?php if (!empty($L['video_file'])): ?><p class="slc-sub">Smaller qualities for the player's ⚙ menu (optional): <?php foreach (['720', '480', '360'] as $qq): ?><label style="display:inline-flex;gap:6px;align-items:center;margin-right:12px"><?= $qq ?>p <?= isset($vv[$qq]) ? '<span class="ci-badge ci-b-green">added</span>' : '' ?> <input type="file" class="slcQ" data-q="<?= $qq ?>" accept="video/mp4,.mp4,.webm" style="max-width:190px"></label><?php endforeach; ?></p><?php endif; ?>
  </div>
  <div class="slc-link" style="margin-top:12px"><div class="ci-f"><input name="video_url" id="slcUrl" maxlength="500" placeholder="YouTube, Google Drive, Vimeo or an MP4 link from any server" value="<?= ci_e($L['video_url'] ?? '') ?>"></div>
    <button type="button" class="ci-btn ci-ghost ci-sm" id="slcFetch" style="margin-top:8px"><i class="fas fa-magnifying-glass"></i> Check link</button>
    <div class="slc-probe" id="slcProbe"></div></div>
  <div class="ci-f" style="margin-top:12px;max-width:220px"><label>Duration</label><input name="duration" id="slcDur" maxlength="9" placeholder="filled in by itself" value="<?= ci_e($L['duration'] ?? '') ?>"></div>
  <div class="slc-bar" id="slcBar"><i></i></div><p class="slc-sub" id="slcSt" style="margin-top:6px"></p>
</div></div></div>

<div class="ci-card slc-pdf"><div class="ci-card-b slc-step"><span class="slc-n">3</span><div>
  <label style="font-weight:700;display:block;margin-bottom:8px">Lecture PDF</label>
  <input type="file" id="slcPdf" accept="application/pdf,.pdf"><p class="slc-sub" style="margin-top:6px"><?= !empty($L['pdf_file']) ? 'A PDF is uploaded (' . (int)$L['pdf_pages'] . ' pages). Choose a file only to replace it.' : 'Students read it on the screen with their own watermark.' ?></p>
</div></div></div>

<div class="ci-card"><div class="ci-card-b slc-step"><span class="slc-n">4</span><div>
  <label style="font-weight:700;display:block;margin-bottom:4px">Notes of this lecture <span class="ci-muted" style="font-weight:400">(PDF, optional)</span></label>
  <p class="slc-sub">Shown in the <b>PDF Notes</b> tab under the video.</p>
  <?php foreach ($notesF as $nf): ?><label class="slc-note"><input type="checkbox" name="rm_note[]" value="<?= (int)$nf['id'] ?>"> Remove <b><?= ci_e($nf['title']) ?></b> <span class="ci-muted">(<?= (int)$nf['pages'] ?> pages)</span></label><?php endforeach; ?>
  <input type="file" id="slcNotes" accept="application/pdf,.pdf" multiple>
</div></div></div>

<div class="ci-card"><div class="ci-card-b slc-step"><span class="slc-n">5</span><div>
  <label style="font-weight:700;display:block;margin-bottom:4px">VVI questions <span class="ci-muted" style="font-weight:400">(optional)</span></label>
  <p class="slc-sub">Write important questions with answers, and / or add links (an article or MCQ post on your website, a PDF…). Students see the questions first, then the links.</p>
  <div class="slc-rep" id="slcVqa"><?php foreach ($vvi['qa'] ?: [['', '']] as [$qq, $aa]): ?><div class="slc-row"><div class="slc-in"><input name="vq[]" maxlength="1000" placeholder="Question" value="<?= ci_e($qq) ?>"><textarea name="va[]" rows="2" maxlength="5000" placeholder="Answer"><?= ci_e($aa) ?></textarea></div><button type="button" class="ci-ico" data-i="trash" aria-label="Remove" data-rm><?= ci_svg('trash') ?></button></div><?php endforeach; ?></div>
  <button type="button" class="ci-btn ci-ghost ci-sm" data-add="slcVqa" style="margin-top:8px"><i class="fas fa-plus"></i> Add question</button>
  <div class="slc-rep" id="slcVl" style="margin-top:12px"><?php foreach ($vvi['links'] ?: [['', '']] as [$tt, $uu]): ?><div class="slc-row"><div class="slc-in" style="flex-direction:row"><input name="vlt[]" maxlength="150" placeholder="Button text, e.g. Important questions (article)" value="<?= ci_e($tt) ?>"><input name="vlu[]" maxlength="500" placeholder="https://…" value="<?= ci_e($uu) ?>"></div><button type="button" class="ci-ico" data-i="trash" aria-label="Remove" data-rm><?= ci_svg('trash') ?></button></div><?php endforeach; ?></div>
  <button type="button" class="ci-btn ci-ghost ci-sm" data-add="slcVl" style="margin-top:8px"><i class="fas fa-link"></i> Add link</button>
</div></div></div>

<div class="ci-card"><div class="ci-card-b slc-step"><span class="slc-n">6</span><div>
  <label style="font-weight:700;display:block;margin-bottom:4px">Quiz <span class="ci-muted" style="font-weight:400">(optional)</span></label>
  <p class="slc-sub">MCQs that students can attempt right under the video (tick the right option), and / or links to a quiz.</p>
  <div class="slc-rep" id="slcQz"><?php $qItems = $quiz['items'] ?: [['', ['', '', '', ''], 0, '']]; foreach ($qItems as $n => [$qq, $oo, $aa, $ee]): $oo = array_pad($oo, 4, ''); ?>
    <div class="slc-row"><div class="slc-in"><input name="qq[<?= $n ?>]" maxlength="1000" placeholder="Question" value="<?= ci_e($qq) ?>">
      <div class="slc-opts"><?php foreach ($oo as $k => $ov): ?><label class="slc-opt"><input type="radio" name="qa[<?= $n ?>]" value="<?= $k ?>" <?= (int)$aa === $k ? 'checked' : '' ?> title="Right answer"><input name="qo[<?= $n ?>][]" maxlength="300" placeholder="Option <?= chr(65 + $k) ?>" value="<?= ci_e($ov) ?>"></label><?php endforeach; ?></div>
      <input name="qe[<?= $n ?>]" maxlength="1000" placeholder="Explanation (optional, shown after checking)" value="<?= ci_e($ee) ?>"></div><button type="button" class="ci-ico" data-i="trash" aria-label="Remove" data-rm><?= ci_svg('trash') ?></button></div>
  <?php endforeach; ?></div>
  <button type="button" class="ci-btn ci-ghost ci-sm" data-add="slcQz" data-renum style="margin-top:8px"><i class="fas fa-plus"></i> Add MCQ</button>
  <div class="slc-rep" id="slcQl" style="margin-top:12px"><?php foreach ($quiz['links'] ?: [['', '']] as [$tt, $uu]): ?><div class="slc-row"><div class="slc-in" style="flex-direction:row"><input name="qlt[]" maxlength="150" placeholder="Button text, e.g. Chapter 1 MCQ test" value="<?= ci_e($tt) ?>"><input name="qlu[]" maxlength="500" placeholder="https://…" value="<?= ci_e($uu) ?>"></div><button type="button" class="ci-ico" data-i="trash" aria-label="Remove" data-rm><?= ci_svg('trash') ?></button></div><?php endforeach; ?></div>
  <button type="button" class="ci-btn ci-ghost ci-sm" data-add="slcQl" style="margin-top:8px"><i class="fas fa-link"></i> Add quiz link</button>
</div></div></div>

<div class="ci-card"><div class="ci-card-b slc-step"><span class="slc-n">7</span><div>
  <label style="display:flex;gap:8px;align-items:center;font-weight:600;margin:0 0 8px"><input type="checkbox" name="doubts_on" value="1" <?= ($L['doubts_on'] ?? 1) ? 'checked' : '' ?>> Students can ask doubts &amp; problems on this lecture <span class="ci-muted" style="font-weight:400">(the teacher answers in Store → Doubts)</span></label>
  <label style="display:flex;gap:8px;align-items:center;font-weight:600;margin:0 0 8px"><input type="checkbox" name="free" value="1" <?= !empty($L['free']) ? 'checked' : '' ?>> Free preview — anyone can open this lecture</label>
  <div class="ci-f"><label>About this lecture <span class="ci-muted" style="font-weight:400">(optional)</span></label><textarea name="notes" rows="2" maxlength="5000"><?= ci_e($L['notes'] ?? '') ?></textarea></div>
</div></div></div>

<div class="slc-actions"><button class="ci-btn" type="submit"><i class="fas fa-check"></i> Save lecture</button><button class="ci-btn ci-ghost" type="submit" data-next><i class="fas fa-forward"></i> Save &amp; add next lecture</button><span class="ci-muted" id="slcMsg"></span></div>
<?php endif; ?>
</form>
<?php endif; ?>
</div>
<?php admin_shell_close(); ?>
<script>window.SHC = { pid: <?= (int)($P['id'] ?? 0) ?>, csrf: <?= json_encode(csrf_token()) ?>, self: '' };</script>
<script src="/assets/js/shop-lecture.js?v=<?= @filemtime(ROOT_PATH . '/assets/js/shop-lecture.js') ?: 1 ?>" defer></script>
</body>
</html>
