<?php
// Online Store → Lectures: choose a course, give its YouTube playlist, and every video of it comes in as a lecture in one
// sheet. Select videos to make chapters / units, mark free ones, change names, and open each lecture's Notes PDF,
// Quiz / MCQ and VVI right here (popup). Videos added to the playlist later come in by themselves (every 6 hours or "Scan").
//   ?course=ID                     the sheet of one course
//   POST ajax=1 action=…           link · scan · unlink · newvids · ch_add · ch_rename · ch_del · ch_move · unit_add · unit_del · save · remove
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/institute.php';
require_once INCLUDES_PATH . '/shop.php';
$me = sh_page_start($pdo, ['products']);
sh_tables($pdo);
$pid = (int)($_GET['course'] ?? $_POST['pid'] ?? 0);
$P = $pid ? ($pdo->query("SELECT * FROM sh_products WHERE id = $pid AND type = 'course'")->fetch(PDO::FETCH_ASSOC) ?: null) : null;
if ($pid && !$P) { header('Location: /admin/shop-lectures.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    header('Content-Type: application/json; charset=utf-8');
    $out = function (array $r) { exit(json_encode($r, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)); };
    if (!$P) $out(['ok' => false, 'error' => 'Choose the course first.']);
    $a = (string)($_POST['action'] ?? ''); $id = (int)($_POST['id'] ?? 0);
    $secOk = fn(int $s) => $s && (int)$pdo->query("SELECT COUNT(*) FROM sh_sections WHERE id = $s AND product_id = $pid")->fetchColumn();
    $name = fn() => mb_substr(trim(preg_replace('/\s+/', ' ', (string)($_POST['title'] ?? ''))), 0, 200);
    try {
        switch ($a) {
            case 'link': // the course's playlist: read it now, every new video becomes a lecture
                $list = sh_yt_list(trim((string)($_POST['url'] ?? '')));
                if (!$list) throw new RuntimeException('Paste the YouTube playlist link — it has "list=" in it.');
                if (!sh_yt_playlist($list)) throw new RuntimeException('Could not read this playlist. On YouTube set the playlist to Public or Unlisted (videos inside can be Unlisted), then try again.');
                $pdo->prepare("UPDATE sh_products SET yt_list = ?, yt_synced = NULL WHERE id = ?")->execute([$list, $pid]);
                $n = sh_yt_sync_course($pdo, $pid, max(0, min(3, (int)($_POST['free_first'] ?? 1))));
                admin_log('shop_lecture', "Linked YouTube playlist $list to course #$pid ($n videos added)");
                $out(['ok' => true, 'added' => max(0, $n)]);
            case 'scan':
                $n = sh_yt_sync_course($pdo, $pid);
                if ($n < 0) throw new RuntimeException('YouTube did not answer, or the playlist is now private. Try again in a minute.');
                $out(['ok' => true, 'added' => $n]);
            case 'unlink':
                $pdo->prepare("UPDATE sh_products SET yt_list = NULL, yt_synced = NULL WHERE id = ?")->execute([$pid]);
                $out(['ok' => true]);
            case 'newvids': // the chapter that new videos of the playlist go into
                $s = (int)($_POST['section_id'] ?? 0);
                $pdo->prepare("UPDATE sh_products SET yt_section = ? WHERE id = ?")->execute([$secOk($s) ? $s : null, $pid]);
                $out(['ok' => true]);
            case 'ch_add':
                $t = $name(); if ($t === '') throw new RuntimeException('Write the chapter name.');
                $sort = (int)$pdo->query("SELECT COALESCE(MAX(sort),0) + 1 FROM sh_sections WHERE product_id = $pid")->fetchColumn();
                $pdo->prepare("INSERT INTO sh_sections (product_id, title, sort) VALUES (?, ?, ?)")->execute([$pid, $t, $sort]);
                $out(['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'title' => $t]);
            case 'ch_rename':
                $t = $name(); if ($t === '' || !$secOk($id)) throw new RuntimeException('Write the chapter name.');
                $pdo->prepare("UPDATE sh_sections SET title = ? WHERE id = ?")->execute([$t, $id]);
                $out(['ok' => true, 'title' => $t]);
            case 'ch_del': // its lectures stay, without a chapter
                if (!$secOk($id)) throw new RuntimeException('Chapter not found.');
                $pdo->prepare("UPDATE sh_lessons SET section_id = NULL, unit_id = NULL WHERE section_id = ? AND product_id = ?")->execute([$id, $pid]);
                $pdo->prepare("DELETE FROM sh_units WHERE section_id = ? AND product_id = ?")->execute([$id, $pid]);
                $pdo->prepare("DELETE FROM sh_sections WHERE id = ?")->execute([$id]);
                $pdo->prepare("UPDATE sh_products SET yt_section = NULL WHERE id = ? AND yt_section = ?")->execute([$pid, $id]);
                $out(['ok' => true]);
            case 'ch_move':
                $rows = array_map('intval', $pdo->query("SELECT id FROM sh_sections WHERE product_id = $pid ORDER BY sort, id")->fetchAll(PDO::FETCH_COLUMN));
                $i = array_search($id, $rows, true); $j = $i === false ? -1 : $i + ((int)($_POST['dir'] ?? 1) < 0 ? -1 : 1);
                if ($i !== false && $j >= 0 && $j < count($rows)) { [$rows[$i], $rows[$j]] = [$rows[$j], $rows[$i]]; $st = $pdo->prepare("UPDATE sh_sections SET sort = ? WHERE id = ?"); foreach ($rows as $n => $r) $st->execute([$n + 1, $r]); }
                $out(['ok' => true]);
            case 'unit_add':
                $s = (int)($_POST['section_id'] ?? 0); $t = $name();
                if (!$secOk($s)) throw new RuntimeException('Choose the chapter of the unit.');
                if ($t === '') throw new RuntimeException('Write the unit name.');
                $sort = (int)$pdo->query("SELECT COALESCE(MAX(sort),0) + 1 FROM sh_units WHERE section_id = $s")->fetchColumn();
                $pdo->prepare("INSERT INTO sh_units (product_id, section_id, title, sort) VALUES (?, ?, ?, ?)")->execute([$pid, $s, $t, $sort]);
                $out(['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'title' => $t, 'section_id' => $s]);
            case 'unit_del':
                $pdo->prepare("UPDATE sh_lessons SET unit_id = NULL WHERE unit_id = ? AND product_id = ?")->execute([$id, $pid]);
                $pdo->prepare("DELETE FROM sh_units WHERE id = ? AND product_id = ?")->execute([$id, $pid]);
                $out(['ok' => true]);
            case 'save': // the whole sheet, in its order: name, chapter, unit, free; lecture numbers follow the order in each chapter
                $rows = json_decode((string)($_POST['rows'] ?? ''), true);
                if (!is_array($rows)) throw new RuntimeException('Nothing to save.');
                $mine = array_flip(array_map('intval', $pdo->query("SELECT id FROM sh_lessons WHERE product_id = $pid")->fetchAll(PDO::FETCH_COLUMN)));
                $secs = array_flip(array_map('intval', $pdo->query("SELECT id FROM sh_sections WHERE product_id = $pid")->fetchAll(PDO::FETCH_COLUMN)));
                $units = $pdo->query("SELECT id, section_id FROM sh_units WHERE product_id = $pid")->fetchAll(PDO::FETCH_KEY_PAIR);
                $st = $pdo->prepare("UPDATE sh_lessons SET title = ?, section_id = ?, unit_id = ?, free = ?, sort = ?, lecture_no = ? WHERE id = ? AND product_id = ?");
                $no = []; $n = 0;
                $pdo->beginTransaction();
                foreach ($rows as $r) {
                    $lid = (int)($r['id'] ?? 0); if (!isset($mine[$lid])) continue;
                    $s = (int)($r['section_id'] ?? 0); if (!isset($secs[$s])) $s = 0;
                    $u = (int)($r['unit_id'] ?? 0); if (!$s || (int)($units[$u] ?? 0) !== $s) $u = 0;
                    $t = mb_substr(trim(preg_replace('/\s+/', ' ', (string)($r['title'] ?? ''))), 0, 200);
                    if ($t === '') $t = (string)$pdo->query("SELECT title FROM sh_lessons WHERE id = $lid")->fetchColumn();
                    $no[$s] = ($no[$s] ?? 0) + 1;
                    $st->execute([$t, $s ?: null, $u ?: null, !empty($r['free']) ? 1 : 0, ++$n, $no[$s], $lid, $pid]);
                }
                $pdo->commit();
                $pdo->prepare("UPDATE sh_products SET updated_at = NOW() WHERE id = ?")->execute([$pid]);
                $out(['ok' => true, 'saved' => $n]);
            case 'remove': // lectures out of the course; their playlist videos do not come back by themselves
                $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
                if (!$ids) throw new RuntimeException('Select the lectures to remove.');
                $skip = json_decode((string)$P['yt_skip'], true) ?: [];
                $n = 0;
                foreach ($ids as $lid) {
                    $l = $pdo->query("SELECT * FROM sh_lessons WHERE id = $lid AND product_id = $pid")->fetch(PDO::FETCH_ASSOC); if (!$l) continue;
                    if ($v = sh_yt_id((string)$l['video_url'])) $skip[] = $v;
                    foreach (array_filter(array_merge([$l['video_file'], $l['pdf_file']], array_values(sh_variants($l)))) as $f) { $f = sh_media_path($f); if (is_file($f)) @unlink($f); }
                    foreach ($pdo->query("SELECT file FROM sh_files WHERE lesson_id = $lid")->fetchAll(PDO::FETCH_COLUMN) as $nf) sh_media_unlink($nf);
                    $pdo->exec("DELETE FROM sh_files WHERE lesson_id = $lid"); $pdo->exec("DELETE FROM sh_doubts WHERE lesson_id = $lid"); $pdo->exec("DELETE FROM sh_progress WHERE lesson_id = $lid");
                    $pdo->exec("DELETE FROM sh_lessons WHERE id = $lid");
                    $n++;
                }
                $pdo->prepare("UPDATE sh_products SET yt_skip = ? WHERE id = ?")->execute([$skip ? json_encode(array_values(array_unique($skip))) : null, $pid]);
                admin_log('shop_lecture', "Removed $n lecture(s) from course #$pid");
                $out(['ok' => true, 'removed' => $n]);
            case 'status': // Publish box: draft / published / hidden
                $st = in_array($_POST['status'] ?? '', ['draft', 'published', 'hidden'], true) ? $_POST['status'] : 'draft';
                $pdo->prepare("UPDATE sh_products SET status = ?, updated_at = NOW() WHERE id = ?")->execute([$st, $pid]);
                $out(['ok' => true, 'status' => $st]);
            case 'unskip': // let removed playlist videos come back with the next scan
                $pdo->prepare("UPDATE sh_products SET yt_skip = NULL WHERE id = ?")->execute([$pid]);
                $out(['ok' => true]);
        }
        $out(['ok' => false, 'error' => 'Unknown action.']);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $out(['ok' => false, 'error' => $e instanceof RuntimeException ? $e->getMessage() : 'Could not save. Please try again.']);
    }
}

if ($P) sh_yt_autosync($pdo, $pid); // new playlist videos (at most every 6 hours)
$courses = $pdo->query("SELECT p.id, p.title, p.status, (SELECT COUNT(*) FROM sh_lessons l WHERE l.product_id = p.id) n FROM sh_products p WHERE p.type = 'course' ORDER BY p.status = 'published' DESC, p.id DESC")->fetchAll(PDO::FETCH_ASSOC);
$secs = $units = $rows = [];
if ($P) {
    $P = $pdo->query("SELECT * FROM sh_products WHERE id = $pid")->fetch(PDO::FETCH_ASSOC);
    $secs = $pdo->query("SELECT id, title FROM sh_sections WHERE product_id = $pid ORDER BY sort, id")->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($pdo->query("SELECT id, section_id, title FROM sh_units WHERE product_id = $pid ORDER BY sort, id") as $u) $units[(int)$u['section_id']][] = ['id' => (int)$u['id'], 'title' => $u['title']];
    $notes = $pdo->query("SELECT lesson_id, COUNT(*) FROM sh_files WHERE product_id = $pid AND lesson_id IS NOT NULL GROUP BY lesson_id")->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($pdo->query("SELECT * FROM sh_lessons WHERE product_id = $pid ORDER BY sort, id") as $l) {
        $yt = sh_yt_id((string)$l['video_url']);
        $rows[] = ['id' => (int)$l['id'], 'title' => $l['title'], 'section_id' => isset($secs[(int)$l['section_id']]) ? (int)$l['section_id'] : 0, 'unit_id' => (int)$l['unit_id'], 'free' => (int)$l['free'],
            'dur' => (string)$l['duration'], 'yt' => $yt, 'thumb' => sh_lesson_thumb($l, $P), 'pdf' => ($l['kind'] ?? 'video') === 'pdf',
            'n' => (int)($notes[(int)$l['id']] ?? 0), 'q' => count(sh_quiz($l)['items']), 'v' => count(sh_vvi($l)['qa'])];
    }
}
$skipN = $P ? count(json_decode((string)$P['yt_skip'], true) ?: []) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Lectures - <?= ci_e(edu_site_name()) ?></title>
<?php admin_shell_head(); ci_head(); ?>
<style>
.ls-card input:not([type=checkbox]):not(.nm):not(.t),.ls-card select,.ls-pick select{padding:0 10px;border:1px solid #d1d5db;background:#fff;font:inherit;font-size:13.5px}
.ls-card input:not([type=checkbox]):focus{border-color:var(--ci-acc,#7c3aed);outline:0}
.ls-top{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:16px;align-items:start}
@media (max-width:1100px){.ls-top{grid-template-columns:1fr}}
.ls-side{min-width:0}
/* like the post editor: the page scrolls normally; the sidebar stays in view (sticky) and scrolls on its own when it is taller than the screen */
@media (min-width:1101px){
  .ls-side{position:sticky;top:var(--ls-stick,32px);max-height:calc(100vh - var(--ls-stick,32px) - 16px);overflow-y:auto;overscroll-behavior:contain;scrollbar-width:thin}
}
}
}
.ls-sf label{display:block;font-weight:600;font-size:13px;margin:0 0 6px}.ls-sf select{width:100%;height:38px}
.ls-facts{list-style:none;margin:12px 0;padding:0;font-size:13px;color:#374151}.ls-facts li{display:flex;gap:8px;align-items:baseline;padding:5px 0;border-bottom:1px dashed #eef0f3}.ls-facts li:last-child{border-bottom:0}.ls-facts i{width:16px;color:#9ca3af;text-align:center}
.ls-pub{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}.ls-pub .ci-btn{flex:1;justify-content:center}
#lsDirty2{display:block;margin-top:6px}
.ls-course img{width:100%;aspect-ratio:16/9;object-fit:cover;display:block;margin:0 0 10px}.ls-course b{display:block;line-height:1.35}.ls-course .ci-muted{font-size:13px}
.ls-pick{display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;padding:14px 16px;border:1px solid #e5e7eb;background:#fff;margin:0 0 14px}
.ls-pick .ci-f{min-width:min(460px,100%);flex:1;margin:0}
.ls-card{border:1px solid #e5e7eb;background:#fff;margin:0 0 14px}
.ls-card>h3{margin:0;padding:12px 16px;border-bottom:1px solid #eef0f3;font-size:15px;display:flex;align-items:center;gap:8px;justify-content:space-between;flex-wrap:wrap}
.ls-card>h3 small{font-weight:400;color:#6b7280;font-size:12.5px}
.ls-body{padding:14px 16px}
.ls-pl{display:flex;gap:8px;flex-wrap:wrap}.ls-pl input{flex:1;min-width:240px;height:40px}
.ls-pl .ci-btn{height:40px}
.ls-linked{display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding:10px 12px;background:#fef2f2;border:1px solid #fecaca;margin:0 0 10px;font-size:13.5px}
.ls-linked i.fa-youtube{color:#dc2626;font-size:18px}.ls-linked .sp{flex:1}
.ls-msg{margin-top:10px;font-size:13.5px}.ls-msg.ok{color:#15803d}.ls-msg.err{color:#b91c1c}
/* chapters */
.ls-chs{display:flex;flex-direction:column;gap:6px}
.ls-ch{display:grid;grid-template-columns:10px minmax(160px,1fr) auto auto;gap:10px;align-items:center;padding:6px 8px;border:1px solid #eef0f3;background:#fafbfc}
.ls-ch .bar{width:10px;align-self:stretch}
.ls-ch input.nm{border:1px solid transparent;background:transparent;font-weight:700;height:32px;padding:0 6px;width:100%}.ls-ch input.nm:hover,.ls-ch input.nm:focus{border-color:#d1d5db;background:#fff;outline:0}
.ls-ch .cnt{font-size:12px;color:#6b7280;white-space:nowrap}
.ls-units{grid-column:2/-1;display:flex;gap:6px;flex-wrap:wrap;align-items:center;font-size:12px}
.ls-units span{display:inline-flex;gap:4px;align-items:center;padding:2px 4px 2px 8px;background:#fff;border:1px solid #e5e7eb}
.ls-units span button,.ls-x{border:0;background:none;cursor:pointer;color:#9ca3af;padding:0 4px}.ls-units span button:hover,.ls-x:hover{color:#b91c1c}
.ls-units .add{border:1px dashed #c4b5fd;background:#faf8ff;color:var(--ci-acc,#7c3aed);font-weight:600;padding:2px 8px;cursor:pointer;font-size:12px}
.ls-chadd{display:flex;gap:8px;margin-top:10px}.ls-chadd input{flex:1;max-width:420px;height:36px}
/* sheet */
.ls-tools{position:sticky;top:var(--wp-admin--admin-bar--height,32px);z-index:5;display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:10px 16px;background:#fff;border-bottom:1px solid #eef0f3}
.ls-tools .sel{font-weight:700;font-size:13px;min-width:90px}
.ls-tools .grow{flex:1}
.ls-tools select{height:34px;min-width:170px}
.ls-tools input[type=search]{height:34px;width:170px}
.ls-tools .ci-btn{height:34px}
.ls-tools [data-needsel][disabled]{opacity:.45;pointer-events:none}
.ls-dirty{font-size:12.5px;color:#b45309;font-weight:600}
table.ls-t{width:100%;border-collapse:collapse;font-size:13.5px}
.ls-t th{background:#f8fafc;text-align:left;font-size:12px;text-transform:uppercase;letter-spacing:.03em;color:#6b7280;padding:8px;border-bottom:1px solid #e5e7eb;white-space:nowrap}
.ls-t td{padding:6px 8px;border-bottom:1px solid #f1f2f4;vertical-align:middle}
.ls-t tr.on td{background:#f5f3ff}
.ls-t tr.drag td{opacity:.4}.ls-t tr.over td{box-shadow:0 -2px 0 var(--ci-acc,#7c3aed) inset}
.ls-t td.cb{width:34px;border-left:5px solid transparent}
.ls-t td.no{width:36px;color:#9ca3af;font-weight:700;text-align:center}
.ls-t .hd{cursor:grab;color:#c0c4cc;padding:0 4px}
.ls-t .vid{display:flex;gap:8px;align-items:center;min-width:220px}
.ls-t .tw{flex:1;min-width:0;display:flex;flex-direction:column}.ls-t .tw small{font-size:11.5px;color:#9ca3af;padding:0 6px}
.ls-t .vid img,.ls-t .vid .ph{width:88px;aspect-ratio:16/9;object-fit:cover;background:#f3f4f6;flex:0 0 auto;display:grid;place-items:center;color:#9ca3af}
.ls-t .vid input{width:100%;min-width:0;height:30px;border:1px solid transparent;background:transparent;font-weight:600;padding:0 6px}.ls-t .vid input:hover,.ls-t .vid input:focus{border-color:#d1d5db;background:#fff;outline:0}
.ls-t select{height:32px;width:100%;min-width:150px;max-width:230px}
.ls-t td.cs{width:220px}.ls-t td.us{width:180px}
.ls-t .fr{text-align:center}
.ls-x3{display:flex;gap:4px;flex-wrap:nowrap}
.ls-x3 a{font-size:12px;font-weight:600;padding:3px 6px;min-width:34px;text-align:center;border:1px dashed #c4b5fd;color:var(--ci-acc,#7c3aed);text-decoration:none;background:#faf8ff;white-space:nowrap}
.ls-x3 a.has{border-style:solid;background:#f3effa}
.ls-x3 a:hover{border-style:solid}
.ls-empty{padding:30px;text-align:center;color:#6b7280}
.ls-wrap{overflow-x:auto}
@media (max-width:900px){.ls-tools{position:static}}
</style>
</head>
<body>
<?php admin_shell_open('Lectures', $P ? ci_e($P['title']) : 'Choose a course — give its YouTube playlist and arrange the lectures in one sheet',
    $P ? '<a class="ci-btn ci-ghost" href="' . ci_e(sh_url($P)) . '?preview=1" target="_blank"><i class="fas fa-eye"></i> View on site</a>' : ''); ?>
<div class="ci">
<?= admin_flash() ?>
<?php ob_start(); ?><form class="ls-pick" method="get"><div class="ci-f"><label>Course</label><select name="course" data-search onchange="this.form.submit()"><option value="">— Choose the course —</option><?php foreach ($courses as $c): ?><option value="<?= (int)$c['id'] ?>"<?= $pid === (int)$c['id'] ? ' selected' : '' ?>><?= ci_e($c['title']) ?> · <?= (int)$c['n'] ?> lectures<?= $c['status'] !== 'published' ? ' (' . $c['status'] . ')' : '' ?></option><?php endforeach; ?></select></div>
<?php if (!$courses): ?><p class="ci-muted" style="margin:0">No course yet — <a href="/admin/shop-products.php?new=1&type=course">add a course</a> first.</p><?php endif; ?></form><?php $lsPick = ob_get_clean(); if (!$P) echo $lsPick; ?>

<?php if ($P): ?>
<?php $stN = count($rows); $stFree = count(array_filter($rows, fn($r) => $r['free'])); $stNotes = array_sum(array_column($rows, 'n')); $stQ = array_sum(array_column($rows, 'q')); $stV = array_sum(array_column($rows, 'v')); ?>
<div class="ls-top"><div class="ls-main">
<?= $lsPick ?>
<div class="ls-card" id="lsPl"><h3><span><i class="fab fa-youtube" style="color:#dc2626"></i> YouTube playlist of this course</span></h3><div class="ls-body">
  <?php if ($P['yt_list']): ?>
  <div class="ls-linked"><i class="fab fa-youtube"></i><span class="sp"><b>Linked</b> · <a href="https://www.youtube.com/playlist?list=<?= ci_e($P['yt_list']) ?>" target="_blank" rel="noopener">open on YouTube</a><?= $P['yt_synced'] ? ' · checked ' . ci_e(date('d M, h:i A', strtotime($P['yt_synced']))) : '' ?></span>
    <?php if ($skipN): ?><a href="#" id="lsUnskip" class="ci-muted" style="font-size:12.5px"><?= $skipN ?> removed · bring back</a><?php endif; ?><button type="button" class="ci-btn ci-sm" id="lsScan"><i class="fas fa-rotate"></i> Scan now</button><button type="button" class="ci-btn ci-ghost ci-sm" id="lsUnlink"><i class="fas fa-link-slash"></i> Unlink</button></div>
  <?php endif; ?>
  <div class="ls-pl"><input id="lsUrl" maxlength="500" placeholder="<?= $P['yt_list'] ? 'Change the playlist — ' : '' ?>https://www.youtube.com/playlist?list=…"><button type="button" class="ci-btn" id="lsLink"><i class="fas fa-magnifying-glass"></i> <?= $P['yt_list'] ? 'Use this playlist' : 'Scan playlist' ?></button></div>
  <div class="ls-msg" id="lsMsg" hidden></div>
</div></div>

<div class="ls-card" id="lsChs"><h3><span><i class="fas fa-layer-group"></i> Chapters &amp; units</span></h3><div class="ls-body">
  <div class="ls-chs" id="lsChList"></div>
  <div class="ls-chadd"><input id="lsChName" maxlength="200" placeholder="New chapter name — e.g. Chapter 1 — Numbers"><button type="button" class="ci-btn ci-ghost" id="lsChAdd"><i class="fas fa-plus"></i> Add chapter</button></div>
</div></div>

</div>
<aside class="ls-side">
  <div class="ls-card"><h3><span>Publish</span></h3><div class="ls-body">
    <div class="ls-sf"><label for="lsStatus">Status</label><select id="lsStatus"><?php foreach (['published' => 'Published — students can buy it', 'draft' => 'Draft — not on the site', 'hidden' => 'Hidden — only with the link'] as $k => $l): ?><option value="<?= $k ?>"<?= $P['status'] === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <ul class="ls-facts">
      <li><i class="fas fa-film"></i> <b><?= $stN ?></b> lecture<?= $stN === 1 ? '' : 's' ?> · <b><?= count($secs) ?></b> chapter<?= count($secs) === 1 ? '' : 's' ?></li>
      <li><i class="fas fa-unlock"></i> <b><?= $stFree ?></b> free preview</li>
      <li><i class="fas fa-file-pdf"></i> <b><?= $stNotes ?></b> notes PDFs · <b><?= $stQ ?></b> MCQs · <b><?= $stV ?></b> VVI</li>
      <li><i class="fab fa-youtube"></i> <?= $P['yt_list'] ? 'Playlist linked' . ($P['yt_synced'] ? ' · checked ' . ci_e(date('d M, h:i A', strtotime($P['yt_synced']))) : '') : 'No playlist yet' ?></li>
    </ul>
    <span class="ls-dirty" id="lsDirty2" hidden>Unsaved changes in the sheet</span>
    <div class="ls-pub"><a class="ci-btn ci-ghost" href="<?= ci_e(sh_url($P)) ?>?preview=1" target="_blank"><i class="fas fa-eye"></i> Preview</a><button type="button" class="ci-btn" id="lsPublish"><i class="fas fa-check"></i> Save</button></div>
  </div></div>
  <div class="ls-card"><h3><span>Course</span></h3><div class="ls-body ls-course">
    <?php $cImg = $P['thumb'] ?: (($iy = sh_yt_id((string)$P['intro_url'])) ? 'https://i.ytimg.com/vi/' . $iy . '/hqdefault.jpg' : ''); ?>
    <?= $cImg ? '<img src="' . ci_e($cImg) . '" alt="">' : '' ?>
    <b><?= ci_e($P['title']) ?></b>
    <span class="ci-muted"><?= (float)$P['price'] > 0 ? '₹' . number_format((float)$P['price']) : 'Free' ?><?= $P['intro_url'] ? ' · has intro video' : '' ?></span>
    <div class="ls-pub"><a class="ci-btn ci-ghost ci-sm" href="/admin/shop-products.php?edit=<?= $pid ?>&amp;type=course"><i class="fas fa-pen"></i> Edit course details</a><a class="ci-btn ci-ghost ci-sm" data-embed href="/admin/shop-lecture.php?course=<?= $pid ?>&amp;embed=1"><i class="fas fa-plus"></i> Add lecture by link</a></div>
  </div></div>
</aside>
</div>

<div class="ls-card" id="lsSheet">
  <div class="ls-tools">
    <span class="sel" id="lsSel">0 selected</span>
    <button type="button" class="ci-btn ci-sm" data-needsel id="lsMk"><i class="fas fa-folder-plus"></i> Make chapter</button>
    <select id="lsTo" data-needsel aria-label="Move selected to chapter"><option value="">Move to chapter…</option></select>
    <select id="lsToU" data-needsel aria-label="Put selected in unit"><option value="">Put in unit…</option></select>
    <button type="button" class="ci-btn ci-ghost ci-sm" data-needsel id="lsFrOn"><i class="fas fa-unlock"></i> Free</button>
    <button type="button" class="ci-btn ci-ghost ci-sm" data-needsel id="lsFrOff"><i class="fas fa-lock"></i> Not free</button>
    <button type="button" class="ci-btn ci-ghost ci-sm" data-needsel id="lsRm" style="color:#b91c1c"><i class="fas fa-trash"></i> Remove</button>
    <span class="grow"></span>
    <input type="search" id="lsQ" placeholder="Search lectures…">
    <select id="lsF" aria-label="Show"><option value="all">All chapters</option><option value="0">No chapter</option></select>
    <span class="ls-dirty" id="lsDirty" hidden>Unsaved changes</span>
    <button type="button" class="ci-btn" id="lsSave"><i class="fas fa-check"></i> Save</button>
  </div>
  <div class="ls-wrap"><table class="ls-t" data-sheet="off"><thead><tr><th><input type="checkbox" id="lsAll" aria-label="Select all"></th><th>#</th><th>Lecture</th><th>Chapter</th><th>Unit</th><th>Free</th><th title="Notes PDF · Quiz / MCQ · VVI">PDF · MCQ · VVI</th><th></th></tr></thead><tbody id="lsBody"></tbody></table></div>
  <div class="ls-empty" id="lsEmpty" hidden>No lectures yet. Paste the course's YouTube playlist above and press <b>Scan playlist</b> — or <a data-embed href="/admin/shop-lecture.php?course=<?= $pid ?>&amp;embed=1">add a lecture by link</a>.</div>
</div>

<div class="ci-modal" id="lsMkF" data-mform hidden><div class="ci-modal-box" role="dialog" aria-modal="true" aria-labelledby="lsMkH">
<div class="ci-card-h"><h3 id="lsMkH">Make a chapter</h3><button type="button" class="ci-x" data-close aria-label="Close"><i class="fas fa-times"></i></button></div><div class="ci-card-b">
<form id="lsMkForm"><div class="ci-f"><label>Chapter name <span class="ci-req">*</span></label><input name="title" required maxlength="200" placeholder="e.g. Chapter 1 — Numbers"><span class="ci-hint" id="lsMkHint"></span></div>
<div class="ci-mfoot"><button type="button" class="ci-btn ci-ghost" data-close>Cancel</button><button class="ci-btn" type="submit"><i class="fas fa-check"></i> Make chapter</button></div></form></div></div></div>
<?php require __DIR__ . '/components/lecture-popup.php'; ?>
<?php endif; ?>
</div>
<?php admin_shell_close(); ?>
<?php if ($P): ?>
<script>
(function () { // split scrolling: the two columns fill the screen below the header
  function fit() { // the sidebar and the sheet toolbar stick just under the page header
    var tn = document.querySelector('.top-nav'), stuck = tn && /sticky|fixed/.test(getComputedStyle(tn).position);
    var top = stuck ? Math.round(parseFloat(getComputedStyle(tn).top) || 0) + tn.offsetHeight : (parseInt(getComputedStyle(document.documentElement).getPropertyValue('--wp-admin--admin-bar--height')) || 32);
    document.documentElement.style.setProperty('--ls-stick', top + 'px');
    var tb = document.querySelector('.ls-tools'); if (tb) tb.style.top = top + 'px';
  }
  fit(); window.addEventListener('resize', fit); window.addEventListener('load', function () { fit(); setTimeout(fit, 400); });
})();
</script>
<script>
window.LS = { pid: <?= $pid ?>, csrf: <?= json_encode(csrf_token()) ?>, secs: <?= json_encode(array_map(fn($k, $t) => ['id' => (int)$k, 'title' => $t], array_keys($secs), $secs), JSON_UNESCAPED_UNICODE) ?>,
  units: <?= json_encode((object)$units, JSON_UNESCAPED_UNICODE) ?>, rows: <?= json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?> };
</script>
<script src="/assets/js/shop-lectures.js?v=<?= @filemtime(ROOT_PATH . '/assets/js/shop-lectures.js') ?: 1 ?>"></script>
<?php endif; ?>
</body>
</html>
