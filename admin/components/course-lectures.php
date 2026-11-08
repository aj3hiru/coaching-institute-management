<?php
// Lectures of a course (or the PDFs of a notes pack), shown on the course edit page (shop-products.php?edit=ID):
// summary, YouTube playlist tools, chapters with their lectures, course PDFs. Every form posts to shop-content.php,
// which saves and comes back to the edit page. Needs $pdo and $p (the product).
$pid = (int)$p['id'];
$isCourse = $p['type'] === 'course';
$cur = $isCourse ? sh_curriculum($pdo, $pid) : [];
$secs = $pdo->query("SELECT id, title FROM sh_sections WHERE product_id = $pid ORDER BY sort, id")->fetchAll(PDO::FETCH_KEY_PAIR);
$ytl = $pdo->query("SELECT id, yt_list, yt_synced FROM sh_sections WHERE product_id = $pid AND yt_list IS NOT NULL")->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
$files = array_values(array_filter(sh_files($pdo, $pid), fn($f) => empty($f['lesson_id']))); // lecture notes are managed on the lecture
$editL = (int)($_GET['lesson'] ?? 0);
$el = $editL ? ($pdo->query("SELECT * FROM sh_lessons WHERE id = $editL AND product_id = $pid")->fetch(PDO::FETCH_ASSOC) ?: null) : null;
$mb = fn($b) => $b ? ($b >= 1073741824 ? round($b / 1073741824, 2) . ' GB' : round($b / 1048576, 1) . ' MB') : '';
$used = (int)$pdo->query("SELECT COALESCE(SUM(size),0) FROM sh_lessons WHERE product_id = $pid")->fetchColumn() + (int)$pdo->query("SELECT COALESCE(SUM(size),0) FROM sh_files WHERE product_id = $pid")->fetchColumn();
$free = @disk_free_space(sh_media_dir());
$secOpts = function (int $sel) use ($secs) { $h = '<option value="0">— No chapter —</option>'; foreach ($secs as $k => $t) $h .= '<option value="' . $k . '"' . ($sel === (int)$k ? ' selected' : '') . '>' . ci_e($t) . '</option>'; return $h; };
$btn = function (string $action, int $id, string $icon, string $label, array $more = [], string $confirm = '') use ($pid) {
    $f = '<form method="post" action="/admin/shop-content.php?id=' . $pid . '" style="display:inline"' . ($confirm ? ' onsubmit="return confirm(' . ci_e(json_encode($confirm)) . ')"' : '') . '>' . csrf_field() . '<input type="hidden" name="action" value="' . $action . '"><input type="hidden" name="id" value="' . $id . '">';
    foreach ($more as $k => $v) $f .= '<input type="hidden" name="' . ci_e($k) . '" value="' . ci_e($v) . '">';
    return $f . '<button type="submit" class="ci-ico" aria-label="' . ci_e($label) . '" data-i="' . $icon . '">' . ci_svg($icon) . '</button></form>';
};
$allL = []; foreach ($cur as $s0) foreach ($s0['lessons'] as $l0) $allL[] = $l0;
$notesN = (int)$pdo->query("SELECT COUNT(*) FROM sh_files WHERE product_id = $pid AND lesson_id IS NOT NULL")->fetchColumn();
$secsTotal = 0; $mcqT = 0; $ytN = 0; $freeN = 0;
foreach ($allL as $l0) {
    if (preg_match('/^(?:(\d+):)?(\d{1,2}):(\d{2})$/', (string)$l0['duration'], $dm)) $secsTotal += (int)$dm[1] * 3600 + (int)$dm[2] * 60 + (int)$dm[3];
    $mcqT += count(sh_quiz($l0)['items']); if (!empty($l0['video_url']) && sh_yt_id((string)$l0['video_url'])) $ytN++; if ((int)$l0['free']) $freeN++;
}
$hm = $secsTotal ? (intdiv($secsTotal, 3600) ? intdiv($secsTotal, 3600) . 'h ' : '') . intdiv($secsTotal % 3600, 60) . 'm' : '—';
?>
<style>
.shc-small{font-size:12.5px;color:#6b7280}
.shc-tag{display:inline-flex;align-items:center;gap:4px;font-size:11.5px;font-weight:700;padding:2px 8px;border:1px solid;white-space:nowrap}
.shc-tag.up{color:#1e40af;border-color:#bfdbfe;background:#eff6ff}.shc-tag.yt{color:#b91c1c;border-color:#fecaca;background:#fef2f2}.shc-tag.no{color:#92400e;border-color:#fde68a;background:#fffbeb}.shc-tag.gr{color:#374151;border-color:#e5e7eb;background:#f9fafb}
/* top: course strip + actions */
.lc-top{display:flex;gap:16px;align-items:center;justify-content:space-between;flex-wrap:wrap;padding:16px 18px;border:1px solid #e5e7eb;background:#fff;margin:0 0 14px}
.lc-top .lc-cimg{width:120px;aspect-ratio:16/9;object-fit:cover;background:#f3f4f6;flex:0 0 auto}
.lc-top .lc-ci{display:flex;gap:14px;align-items:center;min-width:0;flex:1}
.lc-top h2{margin:0 0 4px;font-size:18px;line-height:1.3}.lc-top .lc-sub{font-size:13px;color:#6b7280}
.lc-acts{display:flex;gap:8px;flex-wrap:wrap}
.lc-stats{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;margin:0 0 14px}
.lc-stat{border:1px solid #e5e7eb;background:#fff;padding:12px 14px;display:flex;gap:10px;align-items:center}
.lc-stat i{width:34px;height:34px;display:grid;place-items:center;background:#f3effa;color:var(--ci-acc,#7c3aed);font-size:15px;flex:0 0 auto}
.lc-stat b{display:block;font-size:18px;line-height:1.1}.lc-stat small{color:#6b7280;font-size:12px}
.lc-panel{border:1px solid #e5e7eb;background:#fff;margin:0 0 14px}
.lc-panel > summary{list-style:none;cursor:pointer;display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 16px;font-weight:700}
.lc-panel > summary::-webkit-details-marker{display:none}
.lc-panel > summary small{font-weight:400;color:#6b7280;font-size:12.5px}
.lc-panel > summary::after{content:"\f078";font-family:"Font Awesome 6 Free";font-weight:900;font-size:11px;color:#9ca3af;transition:transform .2s}
.lc-panel[open] > summary::after{transform:rotate(180deg)}
.lc-panel > div{padding:4px 16px 16px;border-top:1px solid #f1f3f5}
/* chapters */
.lc-head{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin:6px 0 10px}
.lc-head h3{margin:0;font-size:16px}
.lc-newch{display:flex;gap:6px}.lc-newch input{height:36px;padding:0 10px;border:1px solid #d1d5db;min-width:220px;font:inherit}
.lc-ch{border:1px solid #e5e7eb;background:#fff;margin:0 0 12px}
.lc-ch > summary{list-style:none;cursor:pointer;display:flex;align-items:center;gap:10px;padding:10px 14px;background:#f8fafc;flex-wrap:wrap}
.lc-ch > summary::-webkit-details-marker{display:none}
.lc-ch[open] > summary{border-bottom:1px solid #e5e7eb}
.lc-no{width:28px;height:28px;display:grid;place-items:center;background:var(--ci-acc,#7c3aed);color:#fff;font-weight:800;font-size:13px;flex:0 0 auto}
.lc-ch form.rn{display:flex;flex:1;min-width:200px}.lc-ch form.rn input{flex:1;font-weight:700;font-size:14.5px;border:1px solid transparent;background:transparent;padding:5px 8px}
.lc-ch form.rn input:hover{border-color:#e5e7eb;background:#fff}.lc-ch form.rn input:focus{border-color:#cbd5e1;background:#fff;outline:0}
.lc-ch .lc-cnt{font-size:12.5px;color:#6b7280;white-space:nowrap}
.lc-ch .lc-tools{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.lc-row{display:grid;grid-template-columns:28px 112px minmax(0,1fr) auto auto;gap:12px;align-items:center;padding:10px 14px;border-bottom:1px solid #f1f3f5}
.lc-row:last-child{border-bottom:0}
.lc-row:hover{background:#fafbfc}
.lc-row .lc-ln{font-weight:800;color:#9ca3af;font-size:13px;text-align:center}
.lc-row .lc-th{width:112px;aspect-ratio:16/9;object-fit:cover;background:#f3f4f6;display:block}
.lc-row .lc-th.none{display:grid;place-items:center;color:#9ca3af;font-size:18px}
.lc-row .lc-t{min-width:0}.lc-row .lc-t a{font-weight:700;color:#111827;text-decoration:none;display:block;line-height:1.35}.lc-row .lc-t a:hover{color:var(--ci-acc,#7c3aed)}
.lc-row .lc-meta{display:flex;gap:6px;flex-wrap:wrap;margin-top:5px;align-items:center}
.lc-free{font-size:12px;font-weight:700;border:1px solid #d1d5db;background:#fff;padding:4px 10px;cursor:pointer;color:#6b7280;white-space:nowrap}.lc-free.on{color:#15803d;border-color:#86efac;background:#f0fdf4}
.lc-quick{display:flex;gap:6px;flex-wrap:wrap;margin-top:6px}.lc-quick a{font-size:12px;font-weight:600;padding:2px 8px;border:1px dashed #c4b5fd;color:var(--ci-acc,#7c3aed);text-decoration:none;background:#faf8ff}.lc-quick a:hover{border-style:solid;background:#f3effa}
.lc-sum{padding:16px 18px;border:1px solid #e5e7eb;background:#fff;margin:0 0 14px}
.lc-empty{padding:16px;color:#6b7280;font-size:13.5px}
.lc-empty a{font-weight:600}
.shc-bar{height:6px;background:#e5e7eb;margin-top:6px;display:none}.shc-bar i{display:block;height:100%;width:0;background:var(--ci-acc,#4a3369);transition:width .2s}.shc-up.busy .shc-bar{display:block}
.shc-free{font-size:12px;font-weight:700;border:1px solid #d1d5db;background:#fff;padding:2px 8px;cursor:pointer;color:#6b7280}.shc-free.on{color:#15803d;border-color:#86efac;background:#f0fdf4}
#pdfs .ci-table{table-layout:fixed;width:100%}
@media (max-width:760px){.lc-row{grid-template-columns:80px minmax(0,1fr);gap:10px}.lc-row .lc-ln{display:none}.lc-row .lc-th{width:80px}.lc-row .lc-free,.lc-row .ci-icos{grid-column:2}.lc-top .lc-cimg{display:none}}
</style>
<div class="lc-wrap" id="lectures">
<?php if ($isCourse && !empty($lcSummary)): ?>
<div class="lc-sum">
  <div class="lc-stats">
    <div class="lc-stat"><i class="fas fa-layer-group"></i><div><b><?= count($secs) ?></b><small>Chapters</small></div></div>
    <div class="lc-stat"><i class="fas fa-film"></i><div><b><?= count($allL) ?></b><small>Lectures<?= $secsTotal ? ' · ' . $hm : '' ?></small></div></div>
    <div class="lc-stat"><i class="fas fa-file-pdf"></i><div><b><?= $notesN ?></b><small>Notes PDFs</small></div></div>
    <div class="lc-stat"><i class="fas fa-list-check"></i><div><b><?= $mcqT ?></b><small>MCQs</small></div></div>
  </div>
  <p class="ci-muted" style="margin:10px 0 12px">The YouTube playlist, chapters, units, and each lecture's notes PDF, MCQ and VVI questions are managed on the Lectures page.</p>
  <a class="ci-btn" href="/admin/shop-lectures.php?course=<?= $pid ?>"><i class="fas fa-table-list"></i> Open Lectures</a>
</div>
<?php elseif ($isCourse): ?>
<div class="lc-stats">
  <div class="lc-stat"><i class="fas fa-layer-group"></i><div><b><?= count($secs) ?></b><small>Chapters</small></div></div>
  <div class="lc-stat"><i class="fas fa-film"></i><div><b><?= count($allL) ?></b><small>Lectures<?= $secsTotal ? ' · ' . $hm : '' ?></small></div></div>
  <div class="lc-stat"><i class="fab fa-youtube"></i><div><b><?= $ytN ?></b><small>YouTube videos</small></div></div>
  <div class="lc-stat"><i class="fas fa-unlock"></i><div><b><?= $freeN ?></b><small>Free lectures</small></div></div>
  <div class="lc-stat"><i class="fas fa-file-pdf"></i><div><b><?= $notesN ?></b><small>Notes PDFs</small></div></div>
  <div class="lc-stat"><i class="fas fa-list-check"></i><div><b><?= $mcqT ?></b><small>MCQs</small></div></div>
</div>

<details class="lc-panel"><summary><span><i class="fas fa-link"></i> Add many YouTube videos at once</span><small>links or one playlist</small></summary><div>
<form method="post" action="/admin/shop-content.php?id=<?= $pid ?>"><?= csrf_field() ?><input type="hidden" name="action" value="bulk_youtube"><input type="hidden" name="pid" value="<?= $pid ?>">
  <div class="ci-f"><label>YouTube links — one per line (or one playlist link)</label><textarea name="links" rows="6" required placeholder="https://www.youtube.com/watch?v=AAAAAAAAAAA&#10;Chapter 2 — Polynomials | https://youtu.be/BBBBBBBBBBB&#10;https://www.youtube.com/playlist?list=PL…"></textarea>
  <span class="ci-hint">Titles come from YouTube by themselves; write "Title | link" to use your own. A playlist adds all its videos (it must be Public or Unlisted). Videos already in this course are skipped.</span></div>
  <div class="ci-grid3"><div class="ci-f"><label>Chapter</label><select name="section_id"><?= $secOpts(0) ?></select></div>
  <div class="ci-f"><label>Free preview</label><select name="free_first"><option value="0">None</option><option value="1">First video</option><option value="2">First 2 videos</option><option value="3">First 3 videos</option></select></div>
  <div class="ci-f"><label>&nbsp;</label><label style="font-weight:400;display:flex;gap:6px;align-items:center"><input type="checkbox" name="whole_list" value="1"> For a video link inside a playlist, add the whole playlist</label></div></div>
  <div style="margin-top:10px"><button class="ci-btn" type="submit"><i class="fab fa-youtube"></i> Add YouTube videos</button></div>
</form>
</div></details>

<div id="list">
<div class="lc-head"><h3>Chapters &amp; lectures</h3>
  <form method="post" action="/admin/shop-content.php?id=<?= $pid ?>" class="lc-newch"><?= csrf_field() ?><input type="hidden" name="action" value="add_section"><input type="hidden" name="pid" value="<?= $pid ?>"><input type="hidden" name="anchor" value="list"><input name="title" required maxlength="200" placeholder="New chapter name"><button class="ci-btn ci-ghost" type="submit"><i class="fas fa-plus"></i> Add chapter</button></form></div>
<?php if (!$cur && !$secs): ?><div class="lc-ch"><p class="lc-empty">No lectures yet. Press <a href="/admin/shop-lecture.php?course=<?= $pid ?>">Add lecture</a>, link a YouTube playlist, or add a chapter first.</p></div><?php endif; ?>
<?php
$secRows = [];
foreach ($secs as $sid => $st) $secRows[(int)$sid] = ['id' => (int)$sid, 'title' => $st, 'lessons' => []];
$loose = [];
foreach ($cur as $s) { if ($s['id']) $secRows[$s['id']]['lessons'] = $s['lessons']; else $loose = $s['lessons']; }
$blocks = $loose ? array_merge([['id' => 0, 'title' => '', 'lessons' => $loose]], array_values($secRows)) : array_values($secRows);
$si = 0;
foreach ($blocks as $s): $open = count($blocks) <= 3 || $si < 2; ?>
<details class="lc-ch"<?= $open ? ' open' : '' ?>>
  <summary>
  <?php if ($s['id']): $si++; ?>
    <span class="lc-no"><?= $si ?></span>
    <form method="post" action="/admin/shop-content.php?id=<?= $pid ?>" class="rn" onclick="event.stopPropagation()"><?= csrf_field() ?><input type="hidden" name="action" value="rename_section"><input type="hidden" name="id" value="<?= $s['id'] ?>"><input type="hidden" name="anchor" value="list"><input name="title" value="<?= ci_e($s['title']) ?>" maxlength="200" aria-label="Chapter name" onkeydown="if(event.key===' ')event.stopPropagation()" onchange="this.form.submit()"></form>
    <span class="lc-cnt"><?= count($s['lessons']) ?> lecture<?= count($s['lessons']) === 1 ? '' : 's' ?></span>
    <span class="lc-tools" onclick="event.stopPropagation()">
      <?php if (isset($ytl[$s['id']])): $yl = $ytl[$s['id']]; ?><span class="shc-tag yt" title="New videos of this playlist come in by themselves<?= $yl['yt_synced'] ? ' · checked ' . ci_e(date('d M, h:i A', strtotime($yl['yt_synced']))) : '' ?>"><i class="fab fa-youtube"></i> Playlist</span>
        <?= $btn('sync_section', $s['id'], 'refresh', 'Sync now — fetch new videos from the playlist', ['anchor' => 'list']) ?><?= $btn('unlink_playlist', $s['id'], 'unlink', 'Unlink playlist (lectures stay)', ['anchor' => 'list'], 'Unlink the playlist from this chapter? The lectures already added stay.') ?><?php endif; ?>
      <a class="ci-btn ci-ghost ci-sm" data-embed href="/admin/shop-lecture.php?course=<?= $pid ?>&amp;chapter=<?= $s['id'] ?>&amp;embed=1"><i class="fas fa-plus"></i> Lecture</a>
      <span class="ci-icos"><?= $btn('move_section', $s['id'], 'up', 'Move chapter up', ['dir' => -1, 'anchor' => 'list']) ?><?= $btn('move_section', $s['id'], 'down', 'Move chapter down', ['dir' => 1, 'anchor' => 'list']) ?><?= $btn('delete_section', $s['id'], 'trash', 'Remove chapter', ['anchor' => 'list'], 'Remove the chapter "' . $s['title'] . '"? Its lectures are kept.') ?></span>
    </span>
  <?php else: ?><span class="lc-no" style="background:#9ca3af">–</span><b style="flex:1">Without a chapter</b><span class="lc-cnt"><?= count($s['lessons']) ?> lecture<?= count($s['lessons']) === 1 ? '' : 's' ?></span><?php endif; ?>
  </summary>
  <?php if (!$s['lessons']): ?><p class="lc-empty">No lectures in this chapter yet. <a href="/admin/shop-lecture.php?course=<?= $pid ?>&chapter=<?= $s['id'] ?>">Add the first one</a>.</p><?php endif; ?>
  <?php foreach ($s['lessons'] as $l): $th = sh_lesson_thumb($l, $p); $xv = sh_vvi($l); $xq = sh_quiz($l); $xn = (int)$pdo->query("SELECT COUNT(*) FROM sh_files WHERE lesson_id = " . (int)$l['id'])->fetchColumn(); $isPdf = ($l['kind'] ?? 'video') === 'pdf'; ?>
  <div class="lc-row">
    <span class="lc-ln"><?= $l['lecture_no'] ? 'L' . (int)$l['lecture_no'] : '' ?></span>
    <a href="/admin/shop-lecture.php?id=<?= (int)$l['id'] ?>" tabindex="-1"><?php if ($th): ?><img class="lc-th" src="<?= ci_e($th) ?>" alt="" loading="lazy"><?php else: ?><span class="lc-th none"><i class="fas <?= $isPdf ? 'fa-file-pdf' : 'fa-film' ?>"></i></span><?php endif; ?></a>
    <div class="lc-t"><a href="/admin/shop-lecture.php?id=<?= (int)$l['id'] ?>"><?= ci_e($l['title']) ?></a>
      <div class="lc-meta">
        <?php if ($l['duration']): ?><span class="shc-tag gr"><i class="far fa-clock"></i> <?= ci_e($l['duration']) ?></span><?php endif; ?>
        <?= $isPdf ? ($l['pdf_file'] ? '<span class="shc-tag up">PDF · ' . (int)$l['pdf_pages'] . ' pages</span>' : '<span class="shc-tag no">No PDF yet</span>') : ($l['video_file'] ? '<span class="shc-tag up">Uploaded video</span>' : ($l['video_url'] ? '<span class="shc-tag yt">' . (sh_yt_id((string)$l['video_url']) ? '<i class="fab fa-youtube"></i> YouTube' : (str_contains((string)$l['video_url'], 'drive.google') ? 'Google Drive' : 'Video link')) . '</span>' : '<span class="shc-tag no">No video yet</span>')) ?>
        <?php if ($l['unit_title']): ?><span class="shc-tag gr">Unit: <?= ci_e($l['unit_title']) ?></span><?php endif; ?>
        <?php if ($xn): ?><span class="shc-tag gr"><i class="fas fa-file-pdf"></i> <?= $xn ?> notes</span><?php endif; ?>
        <?php if ($xv['qa'] || $xv['links']): ?><span class="shc-tag gr">VVI <?= count($xv['qa']) ?></span><?php endif; ?>
        <?php if ($xq['items'] || $xq['links']): ?><span class="shc-tag gr">MCQ <?= count($xq['items']) ?></span><?php endif; ?>
        <?php if (!$l['doubts_on']): ?><span class="shc-tag no">Doubts off</span><?php endif; ?>
      </div>
      <div class="lc-quick"><a data-embed href="/admin/shop-lecture.php?id=<?= (int)$l['id'] ?>&amp;embed=1#notes"><i class="fas fa-file-pdf"></i> Notes PDF<?= $xn ? ' (' . $xn . ')' : '' ?></a><a data-embed href="/admin/shop-lecture.php?id=<?= (int)$l['id'] ?>&amp;embed=1#quiz"><i class="fas fa-list-check"></i> Quiz / MCQ<?= $xq['items'] ? ' (' . count($xq['items']) . ')' : '' ?></a><a data-embed href="/admin/shop-lecture.php?id=<?= (int)$l['id'] ?>&amp;embed=1#vvi"><i class="fas fa-circle-question"></i> VVI questions<?= $xv['qa'] ? ' (' . count($xv['qa']) . ')' : '' ?></a></div></div>
    <form method="post" action="/admin/shop-content.php?id=<?= $pid ?>"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_free"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><input type="hidden" name="anchor" value="list"><button class="lc-free<?= $l['free'] ? ' on' : '' ?>" type="submit" title="Click to change"><?= $l['free'] ? '<i class="fas fa-unlock"></i> Free' : '<i class="fas fa-lock"></i> Paid' ?></button></form>
    <div class="ci-icos"><button type="button" class="ci-ico" aria-label="Quick edit" data-i="edit" data-qedit='<?= ci_e(json_encode(['id' => (int)$l['id'], 'title' => $l['title'], 'video_url' => (string)$l['video_url'], 'duration' => (string)$l['duration'], 'section_id' => (int)$l['section_id'], 'free' => (int)$l['free'], 'doubts_on' => (int)$l['doubts_on'], 'kept' => !empty($l['video_file'])], JSON_UNESCAPED_UNICODE)) ?>'><?= ci_svg('edit') ?></button><a class="ci-ico" data-embed href="/admin/shop-lecture.php?id=<?= (int)$l['id'] ?>&amp;embed=1" aria-label="Full editor (notes PDF, quiz, VVI)" data-i="view"><i class="fas fa-up-right-from-square"></i></a><?= $btn('move_lesson', (int)$l['id'], 'up', 'Move up', ['dir' => -1, 'anchor' => 'list']) ?><?= $btn('move_lesson', (int)$l['id'], 'down', 'Move down', ['dir' => 1, 'anchor' => 'list']) ?><?= $btn('delete_lesson', (int)$l['id'], 'trash', 'Delete lecture', ['anchor' => 'list'], 'Delete the lecture "' . $l['title'] . '"?') ?></div>
  </div>
  <?php endforeach; ?>
</details>
<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- PDFs -->
<div class="ci-card" id="pdfs"><div class="ci-card-h"><h3><?= $isCourse ? 'PDF notes for the whole course' : 'PDF files' ?></h3><span class="shc-small"><?= count($files) ?> file<?= count($files) === 1 ? '' : 's' ?></span></div><div class="ci-card-b">
<form id="shcPdf" class="shc-up" onsubmit="return false"><div class="ci-grid3">
  <div class="ci-f" style="grid-column:span 2"><label>PDF file(s)</label><input type="file" name="pfile" accept="application/pdf,.pdf" multiple required><span class="ci-hint">Choose one or many PDFs (up to 300 MB each).</span></div>
  <div class="ci-f"><label>Title (for one file)</label><input name="ptitle" maxlength="200" placeholder="the file name if empty"></div></div>
  <div class="shc-bar"><i></i></div><p class="shc-small" data-st style="margin:6px 0 0"></p>
  <div style="margin-top:10px"><button class="ci-btn" type="submit"><i class="fas fa-upload"></i> Upload PDF</button></div>
</form>
</div><div class="ci-card-b ci-p0">
<?php if ($files): ?><div class="ci-table-wrap"><table class="ci-table"><tbody>
<?php foreach ($files as $f): ?>
<tr><td><form method="post" action="/admin/shop-content.php?id=<?= $pid ?>" style="display:flex;gap:6px"><?= csrf_field() ?><input type="hidden" name="action" value="rename_file"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><input type="hidden" name="anchor" value="pdfs"><input name="title" value="<?= ci_e($f['title']) ?>" maxlength="200" aria-label="PDF title" style="flex:1;border:1px solid transparent;background:transparent;font-weight:700;padding:4px 6px" onchange="this.form.submit()"></form></td>
  <td class="shc-small"><?= $mb((int)$f['size']) ?><?= $f['pages'] ? ' · ' . (int)$f['pages'] . ' pages' : '' ?></td>
  <td><form method="post" action="/admin/shop-content.php?id=<?= $pid ?>" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_free"><input type="hidden" name="what" value="file"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><input type="hidden" name="anchor" value="pdfs"><button class="shc-free<?= $f['free'] ? ' on' : '' ?>" type="submit"><?= $f['free'] ? 'Free preview' : 'Paid' ?></button></form></td>
  <td><div class="ci-icos"><?= $btn('move_file', (int)$f['id'], 'up', 'Move up', ['dir' => -1, 'anchor' => 'pdfs']) ?><?= $btn('move_file', (int)$f['id'], 'down', 'Move down', ['dir' => 1, 'anchor' => 'pdfs']) ?><?= $btn('delete_file', (int)$f['id'], 'trash', 'Delete PDF', ['anchor' => 'pdfs'], 'Delete the PDF "' . $f['title'] . '"?') ?></div></td></tr>
<?php endforeach; ?>
</tbody></table></div><?php endif; ?>
</div></div>
</div>
<div class="ci-modal" id="lcQuick" data-mform hidden><div class="ci-modal-box ci-modal-wide" role="dialog" aria-modal="true" aria-labelledby="lcQH">
<div class="ci-card-h"><h3 id="lcQH">Quick edit lecture</h3><button type="button" class="ci-x" data-close aria-label="Close"><i class="fas fa-times"></i></button></div><div class="ci-card-b">
<form method="post" action="/admin/shop-content.php?id=<?= $pid ?>"><?= csrf_field() ?><input type="hidden" name="action" value="quick_lesson"><input type="hidden" name="id" value=""><input type="hidden" name="anchor" value="list">
<div class="ci-grid2 ci-mgrid">
  <div class="ci-f" style="grid-column:1/-1"><label>Lecture name <span class="ci-req">*</span></label><input name="title" required maxlength="200"></div>
  <div class="ci-f" style="grid-column:1/-1"><label>Video link</label><input name="video_url" maxlength="500" placeholder="YouTube or any other source — https://…"><span class="ci-hint" id="lcQKept" hidden>This lecture plays a video uploaded before — leave empty to keep it.</span></div>
  <div class="ci-f"><label>Chapter</label><select name="section_id"><option value="0">— No chapter —</option><?php foreach ($secs as $k => $t): ?><option value="<?= (int)$k ?>"><?= ci_e($t) ?></option><?php endforeach; ?></select></div>
  <div class="ci-f"><label>Length</label><input name="duration" maxlength="9" placeholder="filled in from YouTube"></div>
  <label class="ci-f" style="flex-direction:row;display:flex;gap:8px;align-items:center;font-weight:600"><input type="checkbox" name="free" value="1"> This lecture is free</label>
  <label class="ci-f" style="flex-direction:row;display:flex;gap:8px;align-items:center;font-weight:600"><input type="checkbox" name="doubts_on" value="1"> Doubts &amp; problems</label>
</div>
<p class="ci-muted" style="margin:10px 0 0;font-size:12.5px">Notes PDF, Quiz / MCQ and VVI questions: the buttons under each lecture open them right here.</p>
<div class="ci-mfoot"><a class="ci-btn ci-ghost" id="lcQFull" href="#"><i class="fas fa-up-right-from-square"></i> Full editor</a><span style="flex:1"></span><button type="button" class="ci-btn ci-ghost" data-close>Cancel</button><button class="ci-btn" type="submit"><i class="fas fa-check"></i> Save lecture</button></div>
</form></div></div></div>
<script>
document.addEventListener('click', function (e) {
  var b = e.target.closest('[data-qedit]'); if (!b) return; e.preventDefault();
  var d = JSON.parse(b.getAttribute('data-qedit')), m = document.getElementById('lcQuick'), f = m.querySelector('form');
  f.id.value = d.id; f.title.value = d.title; f.video_url.value = d.video_url; f.duration.value = d.duration; f.section_id.value = String(d.section_id || 0);
  f.free.checked = !!d.free; f.doubts_on.checked = !!d.doubts_on; document.getElementById('lcQKept').hidden = !d.kept; document.getElementById('lcQFull').href = '/admin/shop-lecture.php?id=' + d.id + '&embed=1'; document.getElementById('lcQFull').setAttribute('data-embed', '');
  window.ciModal.open('#lcQuick');
});
</script>
<?php require __DIR__ . '/lecture-popup.php'; ?>
<script>window.SHC = { pid: <?= $pid ?>, csrf: <?= json_encode(csrf_token()) ?>, self: <?= json_encode('/admin/shop-products.php?edit=' . $pid . '&type=' . $p['type']) ?> };</script>
<script src="/assets/js/shop-admin.js?v=<?= @filemtime(ROOT_PATH . '/assets/js/shop-admin.js') ?: 1 ?>" defer></script>
