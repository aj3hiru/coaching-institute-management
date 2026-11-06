<?php
// Backup & Restore — full-site snapshots (database + optional uploads) as ZIP files kept outside the public folder.
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/tools.php';
$me = admin_guard(['admin']);
@set_time_limit(600);
@ini_set('memory_limit', '512M');
$dir = edu_private_dir('backups');

function edu_make_backup(PDO $pdo, string $dir, string $label, bool $media): string
{
    $name = 'backup-' . date('Y-m-d-His') . ($label !== '' ? '-' . substr(preg_replace('/[^a-z0-9]+/', '-', strtolower($label)), 0, 40) : '') . '.zip';
    $sql = $dir . '/.dump-' . uniqid() . '.sql';
    $tables = edu_sql_dump($pdo, $sql);
    $zip = new ZipArchive();
    if ($zip->open($dir . '/' . $name, ZipArchive::CREATE) !== true) throw new RuntimeException('Could not create the backup file.');
    $zip->addFile($sql, 'database.sql');
    $files = $media ? edu_zip_dir($zip, ROOT_PATH . '/uploads', 'uploads') : 0;
    $zip->addFromString('backup.json', json_encode(['site' => SITE_URL, 'created' => date('c'), 'label' => $label, 'tables' => $tables, 'media_files' => $files, 'by' => $_SESSION['username'] ?? ''], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $zip->close();
    @unlink($sql);
    return $name;
}

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
        echo json_encode(['ok' => true, 'ready' => true, 'name' => $done['name'], 'size' => round($done['size'] / 1048576, 2)]);
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        if ($action === 'create') {
            $n = edu_make_backup($pdo, $dir, trim((string)($_POST['label'] ?? '')), !empty($_POST['media']));
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
            $safety = edu_make_backup($pdo, $dir, 'before-restore', true);
            $zip = new ZipArchive();
            if ($zip->open($src) !== true) throw new RuntimeException('Could not open the backup.');
            $tmp = edu_private_dir('restore-' . uniqid());
            $zip->extractTo($tmp);
            $zip->close();
            if (!is_file("$tmp/database.sql")) throw new RuntimeException('database.sql is missing in this backup.');
            edu_sql_restore($pdo, "$tmp/database.sql");
            $media = 0;
            if (is_dir("$tmp/uploads")) {
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$tmp/uploads", FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) {
                    if (!$f->isFile()) continue;
                    $rel = substr($f->getPathname(), strlen("$tmp/uploads") + 1);
                    if (preg_match('/\.(php\d?|phtml|phar|htaccess)$/i', $rel)) continue; // never restore code into uploads
                    $to = ROOT_PATH . '/uploads/' . $rel;
                    if (!is_dir(dirname($to))) mkdir(dirname($to), 0755, true);
                    copy($f->getPathname(), $to);
                    $media++;
                }
            }
            exec('rm -rf ' . escapeshellarg($tmp));
            if ($action === 'restore_upload') { @unlink($src); unset($_SESSION['edu_restore_file']); }
            edu_clear_page_cache();
            admin_log('backup_restore', 'Restored a backup (' . basename($src) . "), $media media files; safety copy $safety");
            admin_flash("Restored. A copy of the site from just before the restore was saved as $safety.");
        }
    } catch (Throwable $e) {
        admin_flash('Failed: ' . $e->getMessage(), 'error');
    }
    header('Location: /admin/backup-restore.php');
    exit;
}

$backups = [];
foreach (glob("$dir/backup-*.zip") ?: [] as $f) $backups[] = ['name' => basename($f), 'size' => filesize($f), 'time' => filemtime($f)];
usort($backups, fn($a, $b) => $b['time'] <=> $a['time']);
$dirSize = function ($d) { $s = 0; if (!is_dir($d)) return 0; foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS)) as $f) $s += $f->getSize(); return $s; };
$mb = fn($b) => number_format($b / 1048576, 2) . ' MB';
$posts = (int)$pdo->query("SELECT COUNT(*) FROM posts")->fetchColumn();
$pagesCount = 0; try { $pagesCount = (int)$pdo->query("SELECT COUNT(*) FROM site_pages")->fetchColumn(); } catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Backup &amp; Restore - <?= htmlspecialchars(edu_site_name()) ?></title>
<?php admin_shell_head(); ?>
<style>.br-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:1.25rem}.br-stat{background:#fff;border:1px solid var(--gray-200,#e5e7eb);border-radius:12px;padding:16px}.br-stat b{display:block;font-size:1.5rem}.br-stat span{color:var(--gray-500,#6b7280);font-size:.85rem}.br-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.br-card{background:#fff;border:1px solid var(--gray-200,#e5e7eb);border-radius:14px;padding:20px}.br-card h3{margin:0 0 4px;font-size:1.05rem}.br-card>p{margin:0 0 14px;color:var(--gray-500,#6b7280);font-size:.88rem}.br-field{display:block;margin:0 0 12px;font-weight:600;font-size:.88rem}.br-field input[type=text]{display:block;width:100%;margin-top:5px;padding:9px 12px;border:1px solid #d1d5db;border-radius:8px}.br-warn{padding:10px 12px;border-radius:8px;background:#fef2f2;color:#991b1b;font-size:.85rem;margin:0 0 12px}.br-drop{display:block;border:2px dashed #cbd5e1;border-radius:12px;padding:26px;text-align:center;color:#64748b;cursor:pointer}.br-drop:hover{border-color:var(--primary)}.br-prog{height:8px;border-radius:99px;background:#e5e7eb;overflow:hidden;margin:10px 0}.br-prog span{display:block;height:100%;width:0;background:var(--primary)}.br-table{width:100%;border-collapse:collapse;font-size:.88rem}.br-table th,.br-table td{padding:10px;border-bottom:1px solid #f1f5f9;text-align:left}.br-table td form{display:inline}@media (max-width:860px){.br-grid{grid-template-columns:1fr}.br-stats{grid-template-columns:repeat(2,minmax(0,1fr))}}</style>
<?= edu_chunk_js() ?>
</head>
<body>
<?php admin_shell_open('Backup &amp; Restore', 'Full-site snapshots — database, media and settings'); ?>
<?= admin_flash() ?>
<div class="br-stats">
  <div class="br-stat"><b><?= $posts ?></b><span>Posts</span></div>
  <div class="br-stat"><b><?= $pagesCount ?></b><span>Pages</span></div>
  <div class="br-stat"><b><?= $mb($dirSize(ROOT_PATH . '/uploads')) ?></b><span>Media size</span></div>
  <div class="br-stat"><b><?= count($backups) ?></b><span>Saved backups</span></div>
</div>
<div class="br-grid">
  <form class="br-card" method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="create">
    <h3><i class="fas fa-box-archive"></i> Create Backup</h3><p>Full site snapshot — database + media + settings, as a downloadable ZIP. Backups are stored outside the public website.</p>
    <label class="br-field">Label (optional)<input type="text" name="label" maxlength="40" placeholder="e.g. before-big-update"></label>
    <label class="seo-check"><input type="checkbox" name="media" value="1" checked> Include media files (uploads folder)</label>
    <p style="margin-top:14px"><button type="submit" class="btn btn-primary" onclick="this.disabled=true;this.form.submit();this.textContent='Creating…'"><i class="fas fa-download"></i> Create Backup Now</button></p>
  </form>
  <div class="br-card">
    <h3><i class="fas fa-rotate-left"></i> Restore from Backup</h3><p>Upload a backup ZIP to replace the entire site with it.</p>
    <div class="br-warn"><b>Warning:</b> restoring replaces all current posts, pages, media records and settings with the backup. A safety copy of the current site is made automatically first.</div>
    <label class="br-drop" id="br-drop"><i class="fas fa-cloud-arrow-up" style="font-size:1.6rem;display:block;margin-bottom:6px"></i><span id="br-drop-text">Click or drag a backup .zip here</span><input type="file" id="br-file" accept=".zip" hidden></label>
    <div class="br-prog" id="br-prog" hidden><span></span></div>
    <form method="POST" id="br-upload-form" hidden><?= csrf_field() ?><input type="hidden" name="action" value="restore_upload">
      <label class="br-field">Type RESTORE to confirm<input type="text" name="confirm" autocomplete="off" placeholder="RESTORE"></label>
      <button type="submit" class="btn btn-danger"><i class="fas fa-rotate-left"></i> Restore this backup</button></form>
  </div>
</div>
<div class="br-card" style="margin-top:16px"><h3><i class="fas fa-clock-rotate-left"></i> Saved Backups</h3><p>Stored on the server outside the website folder.</p>
  <?php if (!$backups): ?><p style="color:var(--gray-500)">No backups yet.</p><?php else: ?>
  <div style="overflow-x:auto"><table class="br-table"><thead><tr><th>File</th><th>Size</th><th>Created</th><th></th></tr></thead><tbody>
  <?php foreach ($backups as $b): ?><tr><td><?= htmlspecialchars($b['name']) ?></td><td><?= $mb($b['size']) ?></td><td><?= date('j M Y, g:i a', $b['time']) ?></td>
    <td style="white-space:nowrap"><a class="btn btn-secondary btn-sm" href="?action=download&amp;file=<?= urlencode($b['name']) ?>"><i class="fas fa-download"></i> Download</a>
      <form method="POST" onsubmit="var c=prompt('Restore the whole site from this backup? Type RESTORE to confirm.');if(c!=='RESTORE')return false;this.confirm.value=c"><?= csrf_field() ?><input type="hidden" name="action" value="restore_saved"><input type="hidden" name="file" value="<?= htmlspecialchars($b['name']) ?>"><input type="hidden" name="confirm"><button class="btn btn-secondary btn-sm"><i class="fas fa-rotate-left"></i> Restore</button></form>
      <form method="POST" onsubmit="return confirm('Delete this backup file?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="file" value="<?= htmlspecialchars($b['name']) ?>"><button class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button></form></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</div>
<?php admin_shell_close(); ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var drop = document.getElementById('br-drop'), input = document.getElementById('br-file'), prog = document.getElementById('br-prog'), txt = document.getElementById('br-drop-text');
  function go(file) {
    if (!file || !/\.zip$/i.test(file.name)) { alert('Please choose a .zip backup file.'); return; }
    prog.hidden = false; txt.textContent = 'Uploading ' + file.name + '…';
    eduChunkUpload('/admin/backup-restore.php', file, { action: 'chunk', _csrf: <?= json_encode(csrf_token()) ?> }, function (p) { prog.firstElementChild.style.width = p + '%'; })
      .then(function (d) { txt.textContent = '✓ ' + d.name + ' (' + d.size + ' MB) is ready to restore.'; document.getElementById('br-upload-form').hidden = false; })
      .catch(function (e) { txt.textContent = e.message; prog.hidden = true; });
  }
  input.onchange = function () { go(input.files[0]); };
  drop.addEventListener('dragover', function (e) { e.preventDefault(); });
  drop.addEventListener('drop', function (e) { e.preventDefault(); go(e.dataTransfer.files[0]); });
});
</script>
</body>
</html>
