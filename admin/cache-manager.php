<?php
define('DROOT_PATH', $_SERVER['DOCUMENT_ROOT']);
define('ADMIN_PATH', DROOT_PATH . '/admin');
define('ADMIN_URL', '/admin');
require_once DROOT_PATH . '/includes/config.php';
require_once DROOT_PATH . '/includes/functions.php';
require_once ADMIN_PATH . '/components/guard.php';
require_once INCLUDES_PATH . '/perf.php';

// 1. Security & Auth Check
 if (!isset($_SESSION['user_id'])) {
    exit('Access Denied');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_check(); // clearing / deleting cache only from this site's own forms

$stmt = $pdo->prepare("SELECT status, permissions FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);

$user = $stmt->fetch(PDO::FETCH_ASSOC);

$permissions = json_decode($user['permissions'] ?? '{}', true);

if (
    !$user ||
    $user['status'] !== 'active' ||
    ($_SESSION['role'] ?? '') !== 'admin' // admins only: the staff roles (blog / teacher / accountant) never reach site tools
) {
    exit('Access Denied');
}

// 2. Configuration
$cache_dir = $_SERVER['DOCUMENT_ROOT'] . '/cache/';

// Ensure cache directory exists, create if not
if (!is_dir($cache_dir)) {
    mkdir($cache_dir, 0755, true);
}

// 3. Helper Function: Format Size
function formatSize($bytes) {
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
    return $bytes . ' bytes';
}

// 4. Handle Actions (Delete Single / Clear All)
$msg = '';
$msg_type = '';

if (isset($_POST['action'])) {
    if ($_POST['action'] === 'delete_file' && isset($_POST['file'])) {
        $file = basename($_POST['file']); // Security
        $path = $cache_dir . $file;
        
        if (file_exists($path) && is_file($path)) {
            if (unlink($path)) {
                $msg = "File '{$file}' deleted successfully.";
                $msg_type = 'success';
            } else {
                $msg = "Error deleting file.";
                $msg_type = 'error';
            }
        }
    }

    if ($_POST['action'] === 'clear_all') {
        $files = glob($cache_dir . '*');
        $count = 0;
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
                $count++;
            }
        }
        $msg = "Cache cleared! Deleted {$count} files.";
        $msg_type = 'success';
        admin_log('cache_clear', "Cleared the page cache ({$count} files)");
    }

    // Cache system ON / OFF (as in the CMS): off = pages are built fresh every time and nothing is saved
    if ($_POST['action'] === 'cache_off' || $_POST['action'] === 'cache_on') {
        $off = $_POST['action'] === 'cache_off';
        edu_settings_save('performance', ['cache_off' => $off ? '1' : '0']);
        edu_perf_write_cache_settings();
        $count = 0;
        if ($off) foreach (glob($cache_dir . '*.html') ?: [] as $file) { if (is_file($file) && unlink($file)) $count++; }
        $msg = $off ? "Cache system turned OFF — {$count} cached pages removed. Every page is now built fresh." : 'Cache system turned ON.';
        $msg_type = 'success';
        admin_log('cache_toggle', $off ? 'Turned the page cache off' : 'Turned the page cache on');
    }

    // The admin bars clear the cache in the background and stay on the page they are on
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') {
        header('Content-Type: application/json');
        echo json_encode(['ok' => $msg_type === 'success', 'message' => $msg]);
        exit;
    }
}
$cache_off = perf_opt('cache_off') === '1';

// 5. Scan Directory for List
$files = [];
if (is_dir($cache_dir)) {
    $scanned = scandir($cache_dir);
    foreach ($scanned as $file) {
        if ($file === '.' || $file === '..') continue;
        $path = $cache_dir . $file;
        if (is_file($path)) {
            $files[] = [
                'name' => $file,
                'size' => filesize($path),
                'date' => filemtime($path)
            ];
        }
    }
}

// Sort by date DESC (newest first)
usort($files, function($a, $b) {
    return $b['date'] - $a['date'];
});

// Calculate Stats
$total_files = count($files);
$total_size = array_sum(array_column($files, 'size'));

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Cache Manager - <?= htmlspecialchars(edu_site_name()) ?> Admin</title>
<?php require_once ADMIN_PATH . '/components/shell.php'; admin_shell_head(); ?>
<style>
.cm-files-table td, .cm-files-table th { white-space: nowrap; }
.cm-files-table .cm-file-name { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .82rem; color: var(--gray-800); }
.cm-file-actions { display: flex; gap: .4rem; justify-content: flex-end; }
.cm-file-actions form { margin: 0; }
.cm-empty { padding: 2.5rem 1rem; text-align: center; color: var(--gray-500); }
.cm-banner.off { background: #fff7ed; border-color: #fed7aa; }
.cm-banner-icon.off { background: #fee2e2; color: #b91c1c; }
.cm-empty i { display: block; font-size: 1.8rem; margin-bottom: .5rem; color: var(--success); }
</style>
</head>
<body>
<?php admin_shell_open('Cache Manager', 'Manage and flush cache files'); ?>

<div class="cm-banner<?= $cache_off ? ' off' : '' ?>">
  <div class="cm-banner-left">
    <div class="cm-banner-icon <?= $cache_off ? 'off' : 'on' ?>"><i class="fas <?= $cache_off ? 'fa-power-off' : 'fa-bolt' ?>"></i></div>
    <div>
      <div class="cm-banner-title">Cache System — <?= $cache_off ? 'OFF' : 'ON' ?></div>
      <div class="cm-banner-sub"><?= $cache_off ? 'Every page is built fresh from the database and nothing is saved in <code>/cache/</code>. Turn it on again for faster pages.' : 'Homepage and post pages are saved as HTML files in <code>/cache/</code> and served from there until they expire.' ?></div>
    </div>
  </div>
  <form method="POST" style="margin:0" onsubmit="return confirm(<?= $cache_off ? "'Turn the cache system ON?'" : "'Turn the cache system OFF? All cached pages are removed and every page is built fresh.'" ?>);">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $cache_off ? 'cache_on' : 'cache_off' ?>">
    <button type="submit" class="btn <?= $cache_off ? 'btn-primary' : 'btn-secondary' ?>"><i class="fas fa-power-off"></i> <?= $cache_off ? 'Turn Cache ON' : 'Turn Cache OFF' ?></button>
  </form>
  <?php if ($total_files > 0): ?>
  <form method="POST" onsubmit="return confirm('Clear the ENTIRE cache — <?= (int) $total_files ?> file(s)?');" style="margin:0">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="clear_all">
    <button type="submit" class="btn btn-danger"><i class="fas fa-trash-alt"></i> Clear All Cache</button>
  </form>
  <?php endif; ?>
</div>

<?php if ($msg): ?>
<div class="alert <?= $msg_type === 'success' ? 'alert-success' : 'alert-error' ?>"><i class="fas <?= $msg_type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i> <?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<div class="cm-tabs">
  <button type="button" class="cm-tab active" data-tab="overview">Overview</button>
  <button type="button" class="cm-tab" data-tab="files">Cached Files (<?= (int) $total_files ?>)</button>
</div>

<div class="cm-panel active" data-panel="overview">
  <div class="cm-stats-bar">
    <div class="cm-stat-card primary">
      <div class="cm-stat-icon"><i class="fas fa-file-alt"></i></div>
      <div class="cm-label">Total Files</div>
      <div class="cm-value"><?= (int) $total_files ?></div>
    </div>
    <div class="cm-stat-card">
      <div class="cm-stat-icon"><i class="fas fa-hdd"></i></div>
      <div class="cm-label">Total Size</div>
      <div class="cm-value"><?= formatSize($total_size) ?></div>
    </div>
    <div class="cm-stat-card">
      <div class="cm-stat-icon"><i class="fas fa-folder-open"></i></div>
      <div class="cm-label">Cache Path</div>
      <div class="cm-value" style="font-size:.9rem"><code>/cache/</code></div>
    </div>
    <div class="cm-stat-card">
      <div class="cm-stat-icon"><i class="fas fa-clock"></i></div>
      <div class="cm-label">Homepage TTL</div>
      <div class="cm-value" style="font-size:.9rem">1 hour</div>
    </div>
    <div class="cm-stat-card">
      <div class="cm-stat-icon"><i class="fas fa-clock"></i></div>
      <div class="cm-label">Post TTL</div>
      <div class="cm-value" style="font-size:.9rem">6 hours</div>
    </div>
  </div>
  <div class="cm-card">
    <h3><i class="fas fa-circle-info" style="color: var(--primary)"></i> How it works</h3>
    <p class="cm-hint">The first visitor of a page builds it; the HTML is saved in <code>/cache/</code> and the next visitors get that file instantly. The homepage is rebuilt after 1 hour, posts after 6 hours. Clearing removes the saved files, so every page is built fresh on its next visit.</p>
  </div>
</div>

<div class="cm-panel" data-panel="files">
  <div class="cm-card" style="padding:0;overflow-x:auto">
    <?php if (empty($files)): ?>
      <div class="cm-empty"><i class="fas fa-check-circle"></i>No cache files found. System is clean.</div>
    <?php else: ?>
    <table class="cm-files-table">
      <thead><tr><th>Filename</th><th>Size</th><th>Generated At</th><th style="text-align:right">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($files as $f): ?>
        <tr>
          <td><span class="cm-file-name"><?= htmlspecialchars($f['name']) ?></span></td>
          <td><?= formatSize($f['size']) ?></td>
          <td><?= date('M j, Y H:i:s', $f['date']) ?></td>
          <td>
            <div class="cm-file-actions">
              <a href="/cache/<?= rawurlencode($f['name']) ?>" target="_blank" rel="noopener" class="btn btn-secondary btn-sm"><i class="fas fa-eye"></i> View</a>
              <form method="POST" onsubmit="return confirm('Delete <?= htmlspecialchars(addslashes($f['name'])) ?>?');"><?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_file">
                <input type="hidden" name="file" value="<?= htmlspecialchars($f['name']) ?>">
                <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash-alt"></i> Delete</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php admin_shell_close(); ?>
<script>
document.querySelectorAll('.cm-tab').forEach(function (t) {
  t.addEventListener('click', function () {
    document.querySelectorAll('.cm-tab').forEach(function (x) { x.classList.toggle('active', x === t); });
    document.querySelectorAll('.cm-panel').forEach(function (p) { p.classList.toggle('active', p.dataset.panel === t.dataset.tab); });
  });
});
</script>
</body>
</html>
