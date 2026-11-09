<?php
// Backup & Restore — full-site snapshots (database + optional uploads) as ZIP files kept outside the public folder.
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/tools.php';
require_once INCLUDES_PATH . '/backup.php';
$me = admin_guard(['admin']);
@set_time_limit(600);
@ini_set('memory_limit', '512M');
$dir = edu_private_dir('backups');

$action = $_POST['action'] ?? $_GET['action'] ?? '';
if ($action === 'download') {
    $f = basename((string)($_GET['file'] ?? ''));
    if (!preg_match('/^backup-[a-z0-9-]+\.zip$/', $f) || !is_file("$dir/$f")) { http_response_code(404); exit('Not found'); }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $f . '"');
    header('Content-Length: ' . filesize("$dir/$f"));
    readfile("$dir/$f");
    exit;
}
if ($action === 'chunk') {
    header('Content-Type: application/json');
    csrf_check();
    try {
        $done = edu_chunk_receive();
        if (!$done) { echo '{"ok":true}'; exit; }
        $zip = new ZipArchive();
        if ($zip->open($done['path']) !== true || $zip->locateName('database.sql') === false) { @unlink($done['path']); throw new RuntimeException('This is not an EduMint backup ZIP (database.sql is missing).'); }
        $zip->close();
        $_SESSION['edu_restore_file'] = $done['path'];
        $inf = edu_br_info($done['path']);
        echo json_encode(['ok' => true, 'ready' => true, 'name' => $done['name'], 'size' => round($done['size'] / 1048576, 2), 'created' => isset($inf['created']) ? date('j M Y, g:i a', strtotime($inf['created'])) : '', 'site' => $inf['site'] ?? '', 'has' => $inf['has'] ?? [], 'counts' => $inf['counts'] ?? new stdClass()], JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        if ($action === 'create') {
            $n = edu_make_backup($pdo, $dir, trim((string)($_POST['label'] ?? '')), ['uploads' => !empty($_POST['media']), 'store' => !empty($_POST['store']), 'site' => !empty($_POST['site'])]);
            admin_log('backup_create', "Created backup $n");
            admin_flash("Backup created: $n");
        } elseif ($action === 'delete') {
            $f = basename((string)($_POST['file'] ?? ''));
            if (preg_match('/^backup-[a-z0-9-]+\.zip$/', $f) && is_file("$dir/$f")) { unlink("$dir/$f"); admin_log('backup_delete', "Deleted backup $f"); admin_flash('Backup deleted.'); }
        } elseif ($action === 'restore_saved' || $action === 'restore_upload') {
            if (trim((string)($_POST['confirm'] ?? '')) !== 'RESTORE') throw new RuntimeException('Type RESTORE to confirm.');
            $src = $action === 'restore_saved' ? "$dir/" . basename((string)($_POST['file'] ?? '')) : (string)($_SESSION['edu_restore_file'] ?? '');
            if (!is_file($src)) throw new RuntimeException('The backup file was not found.');
            // Safety copy of the current site first.
            $want = ['database' => !empty($_POST['r_db']), 'uploads' => !empty($_POST['r_uploads']), 'store' => !empty($_POST['r_store'])];
            if (!array_filter($want)) throw new RuntimeException('Choose what to restore.');
            $safety = edu_make_backup($pdo, $dir, 'before-restore', ['uploads' => true, 'store' => true, 'site' => false]);
            $zip = new ZipArchive();
            if ($zip->open($src) !== true) throw new RuntimeException('Could not open the backup.');
            $tmp = edu_private_dir('restore-' . uniqid());
            $zip->extractTo($tmp);
            $zip->close();
            if ($want['database']) {
                if (!is_file("$tmp/database.sql")) throw new RuntimeException('database.sql is missing in this backup.');
                edu_sql_restore($pdo, "$tmp/database.sql");
            }
            $media = $want['uploads'] ? edu_br_copy_back("$tmp/uploads", ROOT_PATH . '/uploads') : 0;
            $storeN = $want['store'] ? edu_br_copy_back("$tmp/store", edu_br_store_dir()) : 0;
            exec('rm -rf ' . escapeshellarg($tmp));
            if ($action === 'restore_upload') { @unlink($src); unset($_SESSION['edu_restore_file']); }
            edu_clear_page_cache();
            admin_log('backup_restore', 'Restored a backup (' . basename($src) . '): ' . implode(', ', array_keys(array_filter($want))) . " — $media media files, $storeN course files; safety copy $safety");
            admin_flash('Restored: ' . implode(', ', array_map(fn($k) => ['database' => 'database', 'uploads' => "$media images / media files", 'store' => "$storeN course videos / PDFs"][$k], array_keys(array_filter($want)))) . ". A copy of the site from just before the restore was saved as $safety.");
        }
    } catch (Throwable $e) {
        admin_flash('Failed: ' . $e->getMessage(), 'error');
    }
    header('Location: /admin/backup-restore.php');
    exit;
}

$backups = [];
foreach (glob("$dir/backup-*.zip") ?: [] as $f) $backups[] = ['name' => basename($f), 'size' => filesize($f), 'time' => filemtime($f), 'info' => edu_br_info($f)];
usort($backups, fn($a, $b) => $b['time'] <=> $a['time']);
$dirSize = function ($d) { $s = 0; if (!is_dir($d)) return 0; foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS)) as $f) $s += $f->getSize(); return $s; };
$mb = fn($b) => $b >= 1073741824 ? number_format($b / 1073741824, 2) . ' GB' : number_format($b / 1048576, 1) . ' MB';
$cnt = function (string $t) use ($pdo) { try { return (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn(); } catch (Throwable) { return 0; } };
$cntW = function (string $sql) use ($pdo) { try { return (int)$pdo->query($sql)->fetchColumn(); } catch (Throwable) { return 0; } };
$dbSize = (int)$cntW("SELECT COALESCE(SUM(data_length + index_length),0) FROM information_schema.TABLES WHERE table_schema = DATABASE()");
$sizes = ['uploads' => $dirSize(ROOT_PATH . '/uploads'), 'store' => $dirSize(edu_br_store_dir())];
$siteSize = 0; foreach (new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator(ROOT_PATH, FilesystemIterator::SKIP_DOTS), fn($f) => !($f->isDir() && in_array(substr($f->getPathname(), strlen(ROOT_PATH) + 1), ['uploads', 'cache', 'node_modules', '.git'], true)))) as $f) if ($f->isFile()) $siteSize += $f->getSize();
$stats = [['fa-newspaper', $cnt('posts'), 'Posts'], ['fa-file-lines', $cnt('site_pages'), 'Pages'], ['fa-photo-film', $cntW("SELECT COUNT(*) FROM sh_products WHERE type = 'course'"), 'Courses'],
    ['fa-file-pdf', $cntW("SELECT COUNT(*) FROM sh_products WHERE type = 'notes'"), 'PDF notes'], ['fa-play', $cnt('sh_lessons'), 'Lectures'], ['fa-user-graduate', $cnt('ci_students'), 'Students'],
    ['fa-users', $cnt('sh_users'), 'Online accounts'], ['fa-cart-shopping', $cnt('sh_orders'), 'Orders'], ['fa-receipt', $cnt('ci_payments'), 'Fee receipts'], ['fa-database', $mb($dbSize), 'Database']];
$partTxt = function (array $inf): string {
    $h = $inf['has'] ?? []; $o = [];
    if (!empty($h['database'])) $o[] = 'Database';
    if (!empty($h['uploads'])) $o[] = 'Images & media';
    if (!empty($h['store'])) $o[] = 'Course videos & PDFs';
    if (!empty($h['site'])) $o[] = 'Site files';
    return implode(' · ', $o);
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Backup &amp; Restore - <?= htmlspecialchars(edu_site_name()) ?></title>
<?php admin_shell_head(); ?>
<style>
.br-stats{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin-bottom:1.25rem}
.br-stat{display:flex;gap:10px;align-items:center;background:#fff;border:1px solid var(--gray-200,#e5e7eb);border-radius:5px;padding:12px 14px}
.br-stat i{width:34px;height:34px;flex:0 0 auto;display:grid;place-items:center;background:var(--primary-lighter,#f3effa);color:var(--primary,#7c3aed);border-radius:5px}
.br-stat b{display:block;font-size:1.15rem;line-height:1.2}.br-stat span{font-size:.78rem;color:var(--gray-500,#6b7280)}
.br-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:start}
.br-card{background:#fff;border:1px solid var(--gray-200,#e5e7eb);border-radius:5px;padding:20px}
.br-card h3{margin:0 0 4px;display:flex;align-items:center;gap:8px}.br-card h3 i{color:var(--primary)}
.br-card>p{margin:0 0 14px;color:var(--gray-500,#6b7280);font-size:.9rem}
.br-field{display:block;font-size:.85rem;font-weight:600;margin:0 0 12px}.br-field input{display:block;width:100%;margin-top:4px;height:40px;padding:0 10px;border:1px solid #d1d5db;border-radius:5px}
.br-parts{display:flex;flex-direction:column;gap:8px;margin:0 0 14px}
.br-part{display:flex;gap:10px;align-items:flex-start;padding:10px 12px;border:1px solid #e5e7eb;border-radius:5px;cursor:pointer}
.br-part input{margin-top:3px}.br-part b{display:block;font-size:.9rem}.br-part small{display:block;color:#6b7280;font-size:.8rem}.br-part em{margin-left:auto;font-style:normal;font-size:.8rem;color:#374151;white-space:nowrap;font-weight:600}
.br-part.is-fixed{background:#f9fafb;cursor:default}
.br-warn{border:1px solid #fde68a;background:#fffbeb;color:#92400e;border-radius:5px;padding:10px 12px;font-size:.85rem;margin:0 0 12px}
.br-drop{display:block;border:2px dashed #cbd5e1;border-radius:5px;padding:24px;text-align:center;color:#64748b;cursor:pointer}
.br-drop:hover{border-color:var(--primary)}
.br-prog{height:8px;border-radius:99px;background:#e5e7eb;overflow:hidden;margin:10px 0}.br-prog span{display:block;height:100%;width:0;background:var(--primary)}
.br-info{border:1px solid #e5e7eb;border-radius:5px;padding:10px 12px;margin:10px 0;font-size:.85rem}.br-info b{display:block;margin-bottom:4px}
.br-table{width:100%;border-collapse:collapse;font-size:.88rem}.br-table th,.br-table td{padding:10px;border-bottom:1px solid #eef0f3;text-align:left;vertical-align:middle}
.br-table td small{display:block;color:#6b7280}.br-table form{display:inline}
.br-acts{display:flex;gap:6px;flex-wrap:wrap}
.br-modal{position:fixed;inset:0;z-index:9999;background:rgba(15,23,42,.45);display:grid;place-items:center;padding:16px}.br-modal[hidden]{display:none}
.br-modal>div{background:#fff;border-radius:5px;padding:20px;width:100%;max-width:480px}
@media (max-width:1100px){.br-stats{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media (max-width:820px){.br-grid{grid-template-columns:1fr}.br-stats{grid-template-columns:1fr 1fr}}
</style>
<?= edu_chunk_js() ?>
</head>
<body>
<?php admin_shell_open('Backup &amp; Restore', 'The whole website — database, images, course videos & PDFs and site files'); ?>
<?= admin_flash() ?>
<div class="br-stats"><?php foreach ($stats as [$ic, $v, $l]): ?><div class="br-stat"><i class="fas <?= $ic ?>"></i><div><b><?= is_int($v) ? number_format($v) : $v ?></b><span><?= $l ?></span></div></div><?php endforeach; ?></div>
<div class="br-grid">
  <form class="br-card" method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="create">
    <h3><i class="fas fa-box-archive"></i> Create Backup</h3><p>One ZIP with everything you tick. Backups are kept on the server outside the public website — download a copy to your computer too.</p>
    <label class="br-field">Label (optional)<input type="text" name="label" maxlength="40" placeholder="e.g. before-big-update"></label>
    <div class="br-parts">
      <label class="br-part is-fixed"><input type="checkbox" checked disabled><span><b>Database</b><small>Posts, pages, categories, courses, PDF notes, lectures, students, batches, fees, receipts, online accounts, orders, settings — every table</small></span><em><?= $mb($dbSize) ?></em></label>
      <label class="br-part"><input type="checkbox" name="media" value="1" checked><span><b>Images &amp; media library</b><small>The uploads folder: post images, thumbnails, logos, File Manager files</small></span><em><?= $mb($sizes['uploads']) ?></em></label>
      <label class="br-part"><input type="checkbox" name="store" value="1" checked><span><b>Course videos &amp; PDFs</b><small>Uploaded lecture videos, lecture PDFs and notes files (private folder)</small></span><em><?= $mb($sizes['store']) ?></em></label>
      <label class="br-part"><input type="checkbox" name="site" value="1" checked><span><b>Site files</b><small>The website's code, theme, fonts and settings files — to move the site to another server (not used by Restore)</small></span><em><?= $mb($siteSize) ?></em></label>
    </div>
    <p style="font-size:.82rem;color:var(--gray-500);margin:0 0 12px"><i class="fas fa-clock"></i> Automatic backup: <b><?= ['off' => 'off', 'daily' => 'every day', 'weekly' => 'once a week'][(string)es('cron', 'auto_backup', 'off')] ?? 'off' ?></b> — change it in <a href="/admin/cron-manager.php">Cron Manager</a>.</p>
    <button type="submit" class="btn btn-primary" onclick="this.disabled=true;this.form.submit();this.innerHTML='<i class=&quot;fas fa-spinner fa-spin&quot;></i> Creating…'"><i class="fas fa-box-archive"></i> Create Backup Now</button>
  </form>
  <div class="br-card">
    <h3><i class="fas fa-rotate-left"></i> Restore from a Backup ZIP</h3><p>Upload a backup ZIP made here. You will see what it holds and choose what to restore.</p>
    <div class="br-warn"><b>Careful:</b> restoring the database replaces all current posts, courses, students, fees, orders and settings with the backup. A safety copy of the current site is made automatically first.</div>
    <label class="br-drop" id="br-drop"><i class="fas fa-cloud-arrow-up" style="font-size:1.6rem;display:block;margin-bottom:6px"></i><span id="br-drop-text">Click or drag a backup .zip here</span><input type="file" id="br-file" accept=".zip" hidden></label>
    <div class="br-prog" id="br-prog" hidden><span></span></div>
    <form method="POST" id="br-upload-form" hidden><?= csrf_field() ?><input type="hidden" name="action" value="restore_upload">
      <div class="br-info" id="br-up-info"></div>
      <div class="br-parts" data-parts></div>
      <label class="br-field">Type RESTORE to confirm<input type="text" name="confirm" autocomplete="off" placeholder="RESTORE"></label>
      <button type="submit" class="btn btn-danger"><i class="fas fa-rotate-left"></i> Restore</button></form>
  </div>
</div>
<div class="br-card" style="margin-top:16px"><h3><i class="fas fa-clock-rotate-left"></i> Saved Backups</h3><p>Stored on the server outside the website folder.</p>
  <?php if (!$backups): ?><p style="color:var(--gray-500)">No backups yet.</p><?php else: ?>
  <div style="overflow-x:auto"><table class="br-table"><thead><tr><th>Backup</th><th>Holds</th><th>Size</th><th>Created</th><th></th></tr></thead><tbody>
  <?php foreach ($backups as $b): $inf = $b['info']; ?><tr><td><b><?= htmlspecialchars($b['name']) ?></b><?php if (!empty($inf['counts'])): ?><small><?= htmlspecialchars(implode(' · ', array_map(fn($k, $v) => number_format((int)$v) . ' ' . $k, array_keys($inf['counts']), $inf['counts']))) ?></small><?php endif; ?></td>
    <td><?= htmlspecialchars($partTxt($inf)) ?></td><td><?= $mb($b['size']) ?></td><td class="ci-nw"><?= date('j M Y, g:i a', $b['time']) ?></td>
    <td><div class="br-acts"><a class="btn btn-secondary btn-sm" href="?action=download&amp;file=<?= urlencode($b['name']) ?>"><i class="fas fa-download"></i> Download</a>
      <button type="button" class="btn btn-secondary btn-sm" data-restore="<?= htmlspecialchars($b['name']) ?>" data-has="<?= htmlspecialchars(json_encode($inf['has'] ?? [])) ?>"><i class="fas fa-rotate-left"></i> Restore</button>
      <form method="POST" onsubmit="return confirm('Delete this backup file?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="file" value="<?= htmlspecialchars($b['name']) ?>"><button class="btn btn-danger btn-sm" type="submit"><i class="fas fa-trash"></i></button></form></div></td></tr>
  <?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</div>
<div class="br-modal" id="br-modal" hidden><div>
  <h3 style="margin:0 0 6px"><i class="fas fa-rotate-left"></i> Restore <span id="br-m-name"></span></h3>
  <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="restore_saved"><input type="hidden" name="file" value="">
    <div class="br-warn">Restoring the database replaces everything now on the site with this backup. A safety copy is made first.</div>
    <div class="br-parts" data-parts></div>
    <label class="br-field">Type RESTORE to confirm<input type="text" name="confirm" autocomplete="off" placeholder="RESTORE"></label>
    <div style="display:flex;gap:8px;justify-content:flex-end"><button type="button" class="btn btn-secondary" data-close>Cancel</button><button type="submit" class="btn btn-danger">Restore</button></div>
  </form></div></div>
<?php admin_shell_close(); ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var LBL = { database: ['r_db', 'Database', 'every table: posts, courses, students, fees, orders, settings'], uploads: ['r_uploads', 'Images & media library', 'files are added / replaced'], store: ['r_store', 'Course videos & PDFs', 'files are added / replaced'] };
  function parts(box, has) {
    box.innerHTML = Object.keys(LBL).filter(function (k) { return has[k]; }).map(function (k) { var l = LBL[k]; return '<label class="br-part"><input type="checkbox" name="' + l[0] + '" value="1" checked><span><b>' + l[1] + '</b><small>' + l[2] + '</small></span></label>'; }).join('') || '<p style="color:#b91c1c">Nothing in this file can be restored.</p>';
  }
  var drop = document.getElementById('br-drop'), input = document.getElementById('br-file'), prog = document.getElementById('br-prog'), txt = document.getElementById('br-drop-text'), uf = document.getElementById('br-upload-form');
  function go(file) {
    if (!file || !/\.zip$/i.test(file.name)) { alert('Please choose a .zip backup file.'); return; }
    prog.hidden = false; uf.hidden = true; txt.textContent = 'Uploading ' + file.name + '…';
    eduChunkUpload('/admin/backup-restore.php', file, { action: 'chunk', _csrf: <?= json_encode(csrf_token()) ?> }, function (p) { prog.firstElementChild.style.width = p + '%'; })
      .then(function (d) {
        txt.textContent = '✓ ' + d.name + ' (' + d.size + ' MB) is ready.';
        var c = d.counts || {}, list = Object.keys(c).map(function (k) { return Number(c[k]).toLocaleString('en-IN') + ' ' + k; }).join(' · ');
        document.getElementById('br-up-info').innerHTML = '<b>In this backup</b>' + (d.created ? 'Made ' + d.created + (d.site ? ' on ' + d.site : '') + '<br>' : '') + (list || '');
        parts(uf.querySelector('[data-parts]'), d.has || {}); uf.hidden = false;
      })
      .catch(function (e) { txt.textContent = e.message; prog.hidden = true; });
  }
  input.onchange = function () { go(input.files[0]); };
  drop.addEventListener('dragover', function (e) { e.preventDefault(); });
  drop.addEventListener('drop', function (e) { e.preventDefault(); go(e.dataTransfer.files[0]); });
  var m = document.getElementById('br-modal'), mf = m.querySelector('form');
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-restore]');
    if (b) { mf.file.value = b.getAttribute('data-restore'); mf.confirm.value = ''; document.getElementById('br-m-name').textContent = b.getAttribute('data-restore'); parts(mf.querySelector('[data-parts]'), JSON.parse(b.getAttribute('data-has') || '{}')); m.hidden = false; return; }
    if (e.target === m || e.target.closest('[data-close]')) m.hidden = true;
  });
  [uf, mf].forEach(function (f) { f.addEventListener('submit', function (e) { if (f.confirm.value.trim() !== 'RESTORE') { e.preventDefault(); alert('Type RESTORE to confirm.'); } else if (!f.querySelector('[data-parts] input:checked')) { e.preventDefault(); alert('Choose what to restore.'); } }); });
});
</script>
</body>
</html>
