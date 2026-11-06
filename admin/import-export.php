<?php
// Import & Export — posts or pages as a ZIP that this site, another EduMint site and the StoryTimes CMS all read:
// content, address (same URL path), SEO fields, categories, tags, FAQs and every image.
// Import: the file is checked first (what it holds, what is already here), then "Start import" runs item by item
// with a log; posts can be given to the original author or to an author chosen here, like WordPress.
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/tools.php';
require_once INCLUDES_PATH . '/pages.php';
require_once INCLUDES_PATH . '/transfer.php';
$me = admin_guard(['admin']);
@set_time_limit(600);
@ini_set('memory_limit', '512M');
edu_pages_table($pdo);

$action = $_POST['action'] ?? '';
if ($action === 'export_posts' || $action === 'export_pages') {
    csrf_check();
    try {
        [$zipFile, $count] = edu_tx_export($pdo, $action === 'export_posts' ? 'posts' : 'pages', (array)($_POST['cats'] ?? []));
    } catch (Throwable $e) {
        admin_flash($e->getMessage(), 'error');
        header('Location: /admin/import-export.php');
        exit;
    }
    admin_log('export', "Exported $count " . ($action === 'export_posts' ? 'posts' : 'pages'));
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . basename($zipFile) . '"');
    header('Content-Length: ' . filesize($zipFile));
    readfile($zipFile);
    @unlink($zipFile);
    exit;
}

// Step 1: the ZIP arrives in pieces; when complete it is checked (what it holds, what is already on this site)
if ($action === 'chunk') {
    header('Content-Type: application/json');
    csrf_check();
    try {
        $done = edu_chunk_receive();
        if (!$done) { echo '{"ok":true}'; exit; }
        try {
            $data = edu_tx_read($done['path']);
        } catch (Throwable $e) {
            @unlink($done['path']);
            throw $e;
        }
        if (!empty($_SESSION['edu_import_file']) && $_SESSION['edu_import_file'] !== $done['path']) @unlink($_SESSION['edu_import_file']);
        $_SESSION['edu_import_file'] = $done['path'];
        $scan = edu_tx_scan($pdo, $data);
        echo json_encode(['ok' => true, 'ready' => true, 'name' => $done['name'], 'size' => $done['size']] + $scan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// Step 2: "Start import" — a few items per request, each one reported for the log
if ($action === 'import_step') {
    header('Content-Type: application/json');
    csrf_check();
    try {
        $src = (string)($_SESSION['edu_import_file'] ?? '');
        if (!is_file($src)) throw new RuntimeException('Please choose the export ZIP again.');
        $data = edu_tx_read($src);
        $offset = max(0, (int)($_POST['offset'] ?? 0));
        $decisions = json_decode((string)($_POST['decisions'] ?? '{}'), true) ?: [];
        $myAuthor = $pdo->prepare("SELECT id FROM authors WHERE user_id = ? LIMIT 1");
        $myAuthor->execute([$me['id']]);
        $opt = [
            'author' => (int)($_POST['author'] ?? 0),
            'fallback_author' => (int)($myAuthor->fetchColumn() ?: $pdo->query("SELECT id FROM authors ORDER BY id LIMIT 1")->fetchColumn()),
            'status' => in_array($_POST['status'] ?? '', ['file', 'draft', 'published'], true) ? $_POST['status'] : 'file',
            'user_id' => (int)$me['id'],
        ];
        if ($opt['author']) {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM authors WHERE id = ?");
            $chk->execute([$opt['author']]);
            if (!$chk->fetchColumn()) throw new RuntimeException('The chosen author no longer exists.');
        }
        $zip = new ZipArchive();
        $zip->open($src);
        $cache = $_SESSION['edu_import_media'] ?? [];
        if ($offset === 0) $cache = [];
        $log = [];
        $per = 4;
        $total = count($data['items']);
        for ($i = $offset; $i < min($total, $offset + $per); $i++) {
            $it = $data['items'][$i];
            try {
                $o = $opt + ['decision' => in_array($decisions[$i] ?? '', ['skip', 'replace', 'keep_both'], true) ? $decisions[$i] : 'keep_both'];
                $r = edu_tx_import_item($pdo, $zip, $it, $data['type'], $o, $cache);
            } catch (Throwable $e) {
                $r = ['outcome' => 'failed', 'title' => (string)($it['title'] ?? 'Item ' . ($i + 1)), 'url' => '', 'error' => $e->getMessage()];
            }
            $log[] = $r;
        }
        $zip->close();
        $_SESSION['edu_import_media'] = $cache;
        $next = min($total, $offset + $per);
        $finished = $next >= $total;
        if ($finished) {
            if ($data['type'] === 'posts') edu_slugmap_write($pdo); // addresses like /ssc-cgl-2026
            edu_clear_page_cache();
            @unlink($src);
            unset($_SESSION['edu_import_file'], $_SESSION['edu_import_media']);
            $sum = (array)json_decode((string)($_POST['summary'] ?? '{}'), true);
            foreach ($log as $l) $sum[$l['outcome']] = (int)($sum[$l['outcome']] ?? 0) + 1;
            admin_log('import', "Imported {$data['type']}: " . implode(', ', array_map(fn($k, $v) => "$v $k", array_keys($sum), $sum)));
        }
        echo json_encode(['ok' => true, 'next' => $next, 'total' => $total, 'done' => $finished, 'log' => $log], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

$cats = $pdo->query("SELECT c.id, c.name, COUNT(DISTINCT p.id) n FROM categories c LEFT JOIN posts p ON p.category_id = c.id AND p.status = 'published' GROUP BY c.id ORDER BY c.name")->fetchAll(PDO::FETCH_ASSOC);
$totalPub = (int)$pdo->query("SELECT COUNT(*) FROM posts WHERE status = 'published'")->fetchColumn();
$pageCount = (int)$pdo->query("SELECT COUNT(*) FROM site_pages")->fetchColumn();
$authors = $pdo->query("SELECT id, name FROM authors ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Import &amp; Export - <?= htmlspecialchars(edu_site_name()) ?></title>
<?php admin_shell_head(); ?>
<style>
.ie-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:start}
.ie-card{background:#fff;border:1px solid var(--gray-200,#e5e7eb);border-radius:14px;padding:20px}
.ie-card h3{margin:0 0 4px;display:flex;align-items:center;gap:8px}.ie-card h3 i{color:var(--primary)}
.ie-card>p{margin:0 0 14px;color:var(--gray-500,#6b7280);font-size:.9rem}
.ie-cats{max-height:260px;overflow:auto;border:1px solid #e5e7eb;border-radius:10px;padding:8px 12px;margin:8px 0 12px}
.ie-cats label{display:flex;justify-content:space-between;gap:8px;padding:4px 0;font-size:.88rem;cursor:pointer}.ie-cats small{color:#9ca3af}
.ie-steps{display:flex;gap:6px;margin:0 0 14px;font-size:.8rem;flex-wrap:wrap}
.ie-steps span{padding:4px 10px;border-radius:99px;background:#f1f5f9;color:#64748b;font-weight:600}.ie-steps span.on{background:var(--primary);color:#fff}.ie-steps span.done{background:#dcfce7;color:#166534}
.ie-drop{display:block;border:2px dashed #cbd5e1;border-radius:12px;padding:26px;text-align:center;color:#64748b;cursor:pointer;transition:border-color .15s,background .15s}
.ie-drop:hover,.ie-drop.drag{border-color:var(--primary);background:var(--primary-lighter,#f5f3ff)}
.ie-prog{height:8px;border-radius:99px;background:#e5e7eb;overflow:hidden;margin:10px 0}.ie-prog span{display:block;height:100%;width:0;background:var(--primary);transition:width .25s}
.ie-sum{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin:0 0 12px}
.ie-sum div{padding:10px;border-radius:10px;background:#f8fafc;border:1px solid #eef2f7;text-align:center}.ie-sum b{display:block;font-size:1.3rem;color:#0f172a}.ie-sum span{font-size:.75rem;color:#64748b}
.ie-src{font-size:.85rem;color:#475569;margin:0 0 10px}
.ie-titles{margin:0 0 12px;padding-left:18px;font-size:.84rem;color:#334155}
.ie-conf{border:1px solid #fde68a;background:#fffbeb;border-radius:10px;padding:10px;margin:0 0 12px}
.ie-conf h4{margin:0 0 6px;font-size:.88rem;color:#92400e;display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap}
.ie-conf h4 span button{border:0;background:none;color:var(--primary);font-weight:600;cursor:pointer;font-size:.8rem}
.ie-conf-row{display:grid;grid-template-columns:1fr 150px;gap:8px;align-items:center;padding:6px 0;border-top:1px dashed #fde68a;font-size:.84rem}
.ie-conf-row small{display:block;color:#92400e}
.ie-opts{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:0 0 14px}
.ie-opts label{font-size:.8rem;font-weight:600;color:#334155}.ie-opts select{margin-top:4px}
.ie-log{margin-top:14px;max-height:320px;overflow:auto;border:1px solid #e5e7eb;border-radius:10px;font-size:.84rem}
.ie-log div{display:flex;gap:8px;align-items:flex-start;padding:7px 10px;border-bottom:1px solid #f1f5f9}.ie-log div:last-child{border:0}
.ie-log i{margin-top:3px}.ie-log .ok i{color:#16a34a}.ie-log .skip i{color:#94a3b8}.ie-log .rep i{color:#2563eb}.ie-log .bad i{color:#dc2626}
.ie-log a{margin-left:auto;white-space:nowrap;font-size:.78rem}
.ie-result{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:12px}
.ie-result div{padding:10px;border-radius:10px;background:#f8fafc;text-align:center}.ie-result b{display:block;font-size:1.2rem}.ie-result span{font-size:.75rem;color:#64748b}
@media(max-width:900px){.ie-grid{grid-template-columns:1fr}.ie-opts{grid-template-columns:1fr}.ie-conf-row{grid-template-columns:1fr}.ie-result{grid-template-columns:1fr 1fr}}
</style>
<?= edu_chunk_js() ?>
</head>
<body>
<?php admin_shell_open('Import &amp; Export', 'Move posts and pages between this site, other EduMint sites and the StoryTimes CMS'); ?>
<?= admin_flash() ?>
<div class="ie-grid">
  <div class="ie-card">
    <h3><i class="fas fa-file-export"></i> Export</h3><p>Download posts or pages as a ZIP — content, address, SEO, tags, categories, FAQs and every image. It imports on this site, another EduMint site or the StoryTimes CMS.</p>
    <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="export_posts">
      <strong style="font-size:.9rem">Posts by category</strong> <small style="color:#9ca3af">— none ticked = every published post (<?= $totalPub ?>)</small>
      <div class="ie-cats"><?php foreach ($cats as $c): ?><label><span><input type="checkbox" name="cats[]" value="<?= (int)$c['id'] ?>"> <?= htmlspecialchars($c['name']) ?></span><small><?= (int)$c['n'] ?></small></label><?php endforeach; ?></div>
      <button type="submit" class="btn btn-primary"><i class="fas fa-download"></i> Export posts</button></form>
    <form method="POST" style="margin-top:12px"><?= csrf_field() ?><input type="hidden" name="action" value="export_pages"><button type="submit" class="btn btn-secondary" <?= $pageCount ? '' : 'disabled' ?>><i class="fas fa-download"></i> Export pages (<?= $pageCount ?>)</button></form>
  </div>
  <div class="ie-card">
    <h3><i class="fas fa-file-import"></i> Import</h3><p>Add posts or pages from an Export ZIP (from this site, another EduMint site or the StoryTimes CMS). Each item keeps its address; images, SEO, categories and tags come along.</p>
    <div class="ie-steps"><span class="on" id="st1">1 Choose file</span><span id="st2">2 Check</span><span id="st3">3 Import</span></div>
    <label class="ie-drop" id="ie-drop"><i class="fas fa-cloud-arrow-up" style="font-size:1.6rem;display:block;margin-bottom:6px"></i><span id="ie-text">Drop a ZIP here or click to choose<br><small>posts_export_….zip or pages_export_….zip</small></span><input type="file" id="ie-file" accept=".zip,application/zip" hidden></label>
    <div class="ie-prog" id="ie-prog" hidden><span></span></div>
    <div id="ie-review" hidden>
      <div class="ie-sum"><div><b id="rv-count">0</b><span id="rv-type">posts</span></div><div><b id="rv-new">0</b><span>new here</span></div><div><b id="rv-dup">0</b><span>already here</span></div></div>
      <p class="ie-src" id="rv-src"></p>
      <ul class="ie-titles" id="rv-titles"></ul>
      <div class="ie-conf" id="rv-conf" hidden><h4><span><i class="fas fa-triangle-exclamation"></i> Already on this site</span><span>Set all: <button type="button" data-all="keep_both">Keep both</button> · <button type="button" data-all="replace">Replace</button> · <button type="button" data-all="skip">Skip</button></span></h4><div id="rv-conf-list"></div></div>
      <div class="ie-opts">
        <label id="rv-author-wrap">Give the posts to<select class="form-control" id="rv-author"><option value="0">The original author (matched by name)</option><?php foreach ($authors as $id => $n): ?><option value="<?= (int)$id ?>"><?= htmlspecialchars($n) ?></option><?php endforeach; ?></select><small style="font-weight:400;color:#64748b" id="rv-authors-note"></small></label>
        <label>Status after import<select class="form-control" id="rv-status"><option value="file">As in the file</option><option value="draft">Drafts (check before publishing)</option><option value="published">Published</option></select></label>
      </div>
      <button type="button" class="btn btn-primary" id="ie-start"><i class="fas fa-play"></i> Start import</button>
      <button type="button" class="btn btn-secondary" id="ie-again" hidden><i class="fas fa-rotate-left"></i> Import another file</button>
    </div>
    <div class="ie-result" id="ie-result" hidden></div>
    <div class="ie-log" id="ie-log" hidden></div>
    <p style="font-size:.82rem;color:var(--gray-500);margin-top:14px">Need a copy of the whole site (database + all media)? Use <a href="/admin/backup-restore.php">Backup &amp; Restore</a>.</p>
  </div>
</div>
<?php admin_shell_close(); ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var $ = function (id) { return document.getElementById(id); }, CSRF = <?= json_encode(csrf_token()) ?>, scan = null, decisions = {}, busy = false;
  var drop = $('ie-drop'), input = $('ie-file'), prog = $('ie-prog'), txt = $('ie-text');
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function step(n) { [1, 2, 3].forEach(function (i) { $('st' + i).className = i < n ? 'done' : i === n ? 'on' : ''; }); }
  function bar(p) { prog.hidden = false; prog.firstElementChild.style.width = p + '%'; }
  function go(file) {
    if (busy) return;
    if (!file || !/\.zip$/i.test(file.name)) { (window.adminNotice || alert)('Please choose a .zip file made by Export.', { type: 'error' }); return; }
    $('ie-review').hidden = true; $('ie-log').hidden = true; $('ie-result').hidden = true; bar(0);
    txt.innerHTML = 'Uploading ' + esc(file.name) + '…';
    eduChunkUpload('/admin/import-export.php', file, { action: 'chunk', _csrf: CSRF }, bar).then(function (d) {
      scan = d; decisions = {}; step(2);
      txt.innerHTML = '<i class="fas fa-circle-check" style="color:#16a34a"></i> ' + esc(file.name) + ' — checked, it is a valid export';
      $('rv-count').textContent = d.count; $('rv-type').textContent = d.type === 'posts' ? (d.count === 1 ? 'post' : 'posts') : (d.count === 1 ? 'page' : 'pages');
      $('rv-dup').textContent = d.conflicts.length; $('rv-new').textContent = d.count - d.conflicts.length;
      var s = d.source || {}, app = /storytimes|next/i.test(s.app || '') ? 'StoryTimes CMS' : /edumint/i.test(s.app || '') ? 'EduMint' : '';
      $('rv-src').innerHTML = 'From ' + (s.name ? '<b>' + esc(s.name) + '</b> ' : '') + (s.url ? esc(s.url.replace(/^https?:\/\//, '')) + ' ' : 'another site ') + (app ? '(' + app + ')' : '') + (s.date ? ' · exported ' + esc(new Date(s.date).toLocaleString()) : '');
      $('rv-titles').innerHTML = d.titles.map(function (t) { return '<li>' + esc(t) + '</li>'; }).join('') + (d.count > d.titles.length ? '<li style="list-style:none;color:#94a3b8">… and ' + (d.count - d.titles.length) + ' more</li>' : '');
      $('rv-conf').hidden = !d.conflicts.length;
      $('rv-conf-list').innerHTML = d.conflicts.map(function (c) { decisions[c.i] = 'keep_both'; return '<div class="ie-conf-row"><div><b>' + esc(c.title) + '</b><small>' + esc(c.path) + ' — already here as “' + esc(c.existing) + '”</small></div><select class="form-control" data-i="' + c.i + '"><option value="keep_both">Keep both</option><option value="replace">Replace existing</option><option value="skip">Skip</option></select></div>'; }).join('');
      $('rv-conf-list').querySelectorAll('select').forEach(function (s) { s.onchange = function () { decisions[s.dataset.i] = s.value; }; });
      $('rv-author-wrap').hidden = d.type !== 'posts';
      $('rv-authors-note').textContent = d.authors && d.authors.length ? 'Authors in the file: ' + d.authors.join(', ') + '. Names not found here go to you.' : '';
      $('ie-start').hidden = false; $('ie-start').disabled = false; $('ie-again').hidden = true;
      $('ie-review').hidden = false;
    }).catch(function (e) { txt.innerHTML = '<span style="color:#dc2626"><i class="fas fa-circle-xmark"></i> ' + esc(e.message) + '</span>'; prog.hidden = true; step(1); });
  }
  document.querySelectorAll('[data-all]').forEach(function (b) { b.onclick = function () { $('rv-conf-list').querySelectorAll('select').forEach(function (s) { s.value = b.dataset.all; decisions[s.dataset.i] = b.dataset.all; }); }; });
  input.onchange = function () { go(input.files[0]); };
  drop.addEventListener('dragover', function (e) { e.preventDefault(); drop.classList.add('drag'); });
  drop.addEventListener('dragleave', function () { drop.classList.remove('drag'); });
  drop.addEventListener('drop', function (e) { e.preventDefault(); drop.classList.remove('drag'); go(e.dataTransfer.files[0]); });
  var ICON = { imported: ['ok', 'fa-circle-check', 'Imported'], renamed: ['ok', 'fa-clone', 'Kept both'], replaced: ['rep', 'fa-rotate', 'Replaced'], skipped: ['skip', 'fa-forward', 'Skipped'], failed: ['bad', 'fa-circle-xmark', 'Failed'] };
  $('ie-start').onclick = function () {
    if (!scan || busy) return;
    busy = true; this.disabled = true; step(3);
    var log = $('ie-log'), sum = {}, total = scan.count; log.hidden = false; log.innerHTML = ''; bar(0);
    var body = { decisions: JSON.stringify(decisions), author: $('rv-author').value, status: $('rv-status').value };
    (function next(offset) {
      var fd = new FormData(); fd.append('_csrf', CSRF); fd.append('action', 'import_step'); fd.append('offset', offset); fd.append('summary', JSON.stringify(sum));
      Object.keys(body).forEach(function (k) { fd.append(k, body[k]); });
      fetch('/admin/import-export.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
        if (d.error) throw new Error(d.error);
        d.log.forEach(function (l) {
          var k = ICON[l.outcome] || ICON.imported; sum[l.outcome] = (sum[l.outcome] || 0) + 1;
          var row = document.createElement('div'); row.className = k[0];
          row.innerHTML = '<i class="fas ' + k[1] + '"></i><span><b>' + k[2] + ':</b> ' + esc(l.title) + (l.error ? ' — ' + esc(l.error) : '') + '</span>' + (l.url ? '<a href="' + esc(l.url) + '" target="_blank">' + esc(l.url) + '</a>' : '');
          log.appendChild(row); log.scrollTop = log.scrollHeight;
        });
        bar(Math.round(d.next / Math.max(1, d.total) * 100));
        if (!d.done) return next(d.next);
        busy = false; step(4); $('ie-start').hidden = true; $('ie-again').hidden = false;
        var res = $('ie-result'); res.hidden = false;
        res.innerHTML = [['imported', 'New'], ['renamed', 'Kept both'], ['replaced', 'Replaced'], ['skipped', 'Skipped']].map(function (x) { return '<div><b>' + (sum[x[0]] || 0) + '</b><span>' + x[1] + '</span></div>'; }).join('') + (sum.failed ? '<div style="grid-column:1/-1;color:#dc2626"><b>' + sum.failed + '</b><span>failed — see the list</span></div>' : '');
        (window.adminNotice || alert)('Import finished: ' + total + ' item' + (total === 1 ? '' : 's') + ' processed.', { type: sum.failed ? 'error' : 'success' });
      }).catch(function (e) {
        busy = false; $('ie-start').disabled = false;
        var row = document.createElement('div'); row.className = 'bad'; row.innerHTML = '<i class="fas fa-circle-xmark"></i><span>' + esc(e.message) + '</span>'; log.appendChild(row);
      });
    })(0);
  };
  $('ie-again').onclick = function () { scan = null; $('ie-review').hidden = true; $('ie-log').hidden = true; $('ie-result').hidden = true; prog.hidden = true; input.value = ''; txt.innerHTML = 'Drop a ZIP here or click to choose<br><small>posts_export_….zip or pages_export_….zip</small>'; step(1); };
});
</script>
</body>
</html>
