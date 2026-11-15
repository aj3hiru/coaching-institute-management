<?php
defined('DROOT_PATH') || define('DROOT_PATH', $_SERVER['DOCUMENT_ROOT']);
defined('ADMIN_PATH') || define('ADMIN_PATH', DROOT_PATH . '/admin');
defined('ADMIN_URL') || define('ADMIN_URL', '/admin');
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

// kinds of cache (Overview: count, size, clear one kind)
$cmTypes = [
    'home' => ['label' => 'Homepage', 'icon' => 'fa-house', 'glob' => ['index_home*.html'], 'ttl' => 'ttl_home'],
    'lists' => ['label' => 'Blog & list pages', 'icon' => 'fa-list', 'glob' => ['index_latest_*.html', 'latest-posts*.html', 'latest-jobs-*.html', 'latest-updates*.html'], 'ttl' => 'ttl_list'],
    'posts' => ['label' => 'Posts', 'icon' => 'fa-newspaper', 'glob' => ['post_*.html', 'post_*.url'], 'ttl' => 'ttl_post'],
    'yt' => ['label' => 'YouTube playlist data', 'icon' => 'fa-youtube', 'glob' => ['yt/*.json'], 'ttl' => ''],
];
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

    // one kind of cache: homepage / list pages / posts / YouTube playlist data
    if ($_POST['action'] === 'clear_type') {
        $k = (string)($_POST['type'] ?? '');
        $pat = $cmTypes[$k]['glob'] ?? null;
        $count = 0;
        if ($pat) foreach ((array)$pat as $g) foreach (glob($cache_dir . $g) ?: [] as $file) if (is_file($file) && unlink($file)) $count++;
        $msg = $pat ? ($cmTypes[$k]['label'] . ": {$count} file(s) cleared.") : 'Unknown cache.';
        $msg_type = $pat ? 'success' : 'error';
        if ($pat) admin_log('cache_clear', "Cleared {$cmTypes[$k]['label']} cache ($count files)");
    }
    // one page by its address
    if ($_POST['action'] === 'clear_url') {
        $u = trim((string)($_POST['url'] ?? ''));
        $path = '/' . trim((string)parse_url($u, PHP_URL_PATH), '/');
        $hits = [];
        if ($path === '/') $hits = glob($cache_dir . 'index_home*.html') ?: [];
        elseif (in_array($path, ['/blog', '/latest-update'], true)) $hits = glob($cache_dir . 'index_latest_*.html') ?: [];
        elseif ($path === '/latest-posts') $hits = glob($cache_dir . 'latest-posts*.html') ?: [];
        elseif ($path === '/latest-jobs') $hits = glob($cache_dir . 'latest-jobs-*.html') ?: [];
        else {
            $slug = basename($path);
            $q = $pdo->prepare("SELECT id FROM posts WHERE slug = ? LIMIT 1"); $q->execute([$slug]);
            $pid = (int)$q->fetchColumn();
            if (!$pid && preg_match('/-(\d+)$/', $slug, $mm)) $pid = (int)$mm[1];
            if ($pid) $hits = array_filter([$cache_dir . "post_$pid.html", $cache_dir . "post_$pid.url"], 'is_file');
        }
        $count = 0; foreach ($hits as $file) if (is_file($file) && unlink($file)) $count++;
        $msg = $count ? "Cleared the cached copy of $path ($count file(s)). The next visitor gets a fresh page." : "No cached copy of $path was found — it is already fresh (pages that are not cached, like courses, are always fresh).";
        $msg_type = 'success';
        if ($count) admin_log('cache_clear', "Cleared cache of $path");
    }
    // PHP's own code cache (after files are changed on the server)
    if ($_POST['action'] === 'opcache') {
        $ok = function_exists('opcache_reset') && @opcache_reset();
        $msg = $ok ? 'PHP code cache (OPcache) cleared.' : 'OPcache is not on for this website — nothing to clear.';
        $msg_type = 'success';
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
$cmDur = function (int $s): string { return $s >= 86400 ? round($s / 86400, 1) . ' day' . ($s >= 172800 ? 's' : '') : ($s >= 3600 ? round($s / 3600, 1) . ' hour' . ($s >= 7200 ? 's' : '') : round($s / 60) . ' min'); };

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
.cm-types{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:10px}
.cm-type{display:flex;gap:10px;align-items:center;padding:12px;border:1px solid #e5e7eb;border-radius:5px}.cm-type>i{width:34px;height:34px;display:grid;place-items:center;background:var(--primary-lighter,#f3effa);color:var(--primary);border-radius:5px;flex:0 0 auto}
.cm-type>div{flex:1;min-width:0}.cm-type b{display:block;font-size:.9rem}.cm-type small{display:block;color:#6b7280;font-size:.78rem}
.cm-url{display:flex;gap:8px;flex-wrap:wrap}.cm-url input{flex:1;min-width:240px}
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
      <div class="cm-value" style="font-size:.9rem"><?= htmlspecialchars($cmDur((int)perf_opt('ttl_home'))) ?></div>
    </div>
    <div class="cm-stat-card">
      <div class="cm-stat-icon"><i class="fas fa-clock"></i></div>
      <div class="cm-label">Post TTL</div>
      <div class="cm-value" style="font-size:.9rem"><?= htmlspecialchars($cmDur((int)perf_opt('ttl_post'))) ?></div>
    </div>
  </div>
  <div class="cm-card">
    <h3><i class="fas fa-layer-group" style="color: var(--primary)"></i> What is cached</h3>
    <div class="cm-types">
    <?php foreach ($cmTypes as $k => $t): $tf = []; foreach ($t['glob'] as $g) $tf = array_merge($tf, glob($cache_dir . $g) ?: []); $ts = array_sum(array_map('filesize', array_filter($tf, 'is_file'))); ?>
      <div class="cm-type"><i class="<?= $k === 'yt' ? 'fab' : 'fas' ?> <?= $t['icon'] ?>"></i><div><b><?= htmlspecialchars($t['label']) ?></b><small><?= count($tf) ?> file(s) · <?= formatSize($ts) ?><?= $t['ttl'] ? ' · fresh every ' . htmlspecialchars($cmDur((int)perf_opt($t['ttl']))) : ' · fresh every 6 hours' ?></small></div>
        <form method="POST" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="clear_type"><input type="hidden" name="type" value="<?= $k ?>"><button class="btn btn-secondary btn-sm" type="submit" <?= $tf ? '' : 'disabled' ?>>Clear</button></form></div>
    <?php endforeach; ?>
    </div>
  </div>
  <div class="cm-card">
    <h3><i class="fas fa-link" style="color: var(--primary)"></i> Clear one page</h3>
    <p class="cm-hint">Changed something and the page still shows the old version? Paste its address.</p>
    <form method="POST" class="cm-url"><?= csrf_field() ?><input type="hidden" name="action" value="clear_url"><input class="form-control" name="url" required placeholder="<?= htmlspecialchars(rtrim(SITE_URL, '/')) ?>/your-post-address"><button class="btn btn-primary" type="submit"><i class="fas fa-broom"></i> Clear this page</button></form>
    <form method="POST" style="margin-top:12px"><?= csrf_field() ?><input type="hidden" name="action" value="opcache"><button class="btn btn-secondary btn-sm" type="submit"><i class="fas fa-microchip"></i> Clear PHP code cache (OPcache)</button> <small class="cm-hint" style="display:inline">only needed after files are changed on the server</small></form>
  </div>
  <div class="cm-card">
    <h3><i class="fas fa-circle-info" style="color: var(--primary)"></i> How it works</h3>
    <p class="cm-hint">The first visitor of a page builds it; the HTML is saved in <code>/cache/</code> and the next visitors get that file instantly. The homepage is rebuilt after <?= htmlspecialchars($cmDur((int)perf_opt('ttl_home'))) ?>, posts after <?= htmlspecialchars($cmDur((int)perf_opt('ttl_post'))) ?>. Clearing removes the saved files, so every page is built fresh on its next visit.</p>
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
