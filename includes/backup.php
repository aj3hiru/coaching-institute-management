<?php
// Full-site backups (Admin → Backup & Restore and the automatic backup in Cron Manager).
require_once __DIR__ . '/tools.php';

/** Course videos / PDFs / notes files of the online store (kept outside the public folder). */
function edu_br_store_dir(): string
{
    if (is_file(INCLUDES_PATH . '/shop.php')) { require_once INCLUDES_PATH . '/shop.php'; if (function_exists('sh_media_dir')) return sh_media_dir(); }
    return dirname(ROOT_PATH) . '/dk-private/shop';
}

/** Adds the site's own files (code, theme, assets) — not uploads, cache or backups. */
function edu_br_zip_site(ZipArchive $zip): int
{
    $n = 0; $root = ROOT_PATH;
    $it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        fn($f) => !($f->isDir() && in_array(substr($f->getPathname(), strlen($root) + 1), ['uploads', 'cache', 'node_modules', '.git'], true))));
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $rel = substr($f->getPathname(), strlen($root) + 1);
        if (preg_match('#(^|/)(\.DS_Store|error_log)$#', $rel)) continue;
        $zip->addFile($f->getPathname(), 'site/' . $rel);
        $n++;
    }
    return $n;
}

/**
 * Full-site backup ZIP: database.sql (every table: posts, pages, courses, notes, lessons, students, fees, orders, settings …)
 * + uploads/ (images, media library) + store/ (course videos, PDFs, notes files) + site/ (the code, theme and assets).
 * $parts: ['uploads' => bool, 'store' => bool, 'site' => bool]; the database is always in it.
 */
function edu_make_backup(PDO $pdo, string $dir, string $label, $parts): string
{
    if (!is_array($parts)) $parts = ['uploads' => (bool)$parts, 'store' => (bool)$parts, 'site' => false];
    $name = 'backup-' . date('Y-m-d-His') . ($label !== '' ? '-' . substr(preg_replace('/[^a-z0-9]+/', '-', strtolower($label)), 0, 40) : '') . '.zip';
    $sql = $dir . '/.dump-' . uniqid() . '.sql';
    $tables = edu_sql_dump($pdo, $sql);
    $zip = new ZipArchive();
    if ($zip->open($dir . '/' . $name, ZipArchive::CREATE) !== true) throw new RuntimeException('Could not create the backup file.');
    $zip->addFile($sql, 'database.sql');
    $files = !empty($parts['uploads']) ? edu_zip_dir($zip, ROOT_PATH . '/uploads', 'uploads') : 0;
    $store = !empty($parts['store']) ? edu_zip_dir($zip, edu_br_store_dir(), 'store') : 0;
    $site = !empty($parts['site']) ? edu_br_zip_site($zip) : 0;
    $count = function (string $t) use ($pdo) { try { return (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn(); } catch (Throwable) { return null; } };
    $zip->addFromString('backup.json', json_encode(['site' => SITE_URL, 'created' => date('c'), 'label' => $label, 'tables' => $tables,
        'parts' => ['database' => true, 'uploads' => $files > 0, 'store' => $store > 0, 'site' => $site > 0],
        'media_files' => $files, 'store_files' => $store, 'site_files' => $site, 'by' => $_SESSION['username'] ?? '',
        'counts' => array_filter(['posts' => $count('posts'), 'pages' => $count('site_pages'), 'courses' => $count('sh_products'), 'lessons' => $count('sh_lessons'),
            'students' => $count('ci_students'), 'online accounts' => $count('sh_users'), 'orders' => $count('sh_orders'), 'fee receipts' => $count('ci_payments')], fn($v) => $v !== null)],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $zip->close();
    @unlink($sql);
    return $name;
}

/** Copies a folder from an unpacked backup into place (never code files into uploads / store). */
function edu_br_copy_back(string $from, string $to): int
{
    $n = 0;
    if (!is_dir($from)) return 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS)) as $f) {
        if (!$f->isFile()) continue;
        $rel = substr($f->getPathname(), strlen($from) + 1);
        if (preg_match('/\.(php\d?|phtml|phar|htaccess)$/i', $rel)) continue;
        $dest = $to . '/' . $rel;
        if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0755, true);
        copy($f->getPathname(), $dest);
        $n++;
    }
    return $n;
}

/** What a backup ZIP holds (backup.json + which folders are in it). */
function edu_br_info(string $file): array
{
    $zip = new ZipArchive();
    if ($zip->open($file) !== true) return [];
    $j = json_decode((string)$zip->getFromName('backup.json'), true) ?: [];
    $has = ['database' => $zip->locateName('database.sql') !== false, 'uploads' => false, 'store' => false, 'site' => false];
    for ($i = 0; $i < $zip->numFiles; $i++) { $n = (string)$zip->getNameIndex($i); foreach (['uploads', 'store', 'site'] as $k) if (str_starts_with($n, $k . '/')) $has[$k] = true; }
    $zip->close();
    return $j + ['has' => $has];
}

/** The automatic backup (Cron Manager → Automatic backup): runs from the daily job when it is due; keeps the newest N. */
function edu_auto_backup(PDO $pdo): string
{
    $mode = (string)es('cron', 'auto_backup', 'off'); // off unless turned on (backups are kept on the owner's computer)
    if (!in_array($mode, ['daily', 'weekly'], true)) return 'automatic backup off';
    $dir = edu_private_dir('backups');
    $autos = glob($dir . '/backup-*-auto.zip') ?: [];
    usort($autos, fn($a, $b) => filemtime($b) <=> filemtime($a));
    $last = $autos ? filemtime($autos[0]) : 0;
    if ($mode === 'weekly' && $last > time() - 6.5 * 86400) return 'automatic backup: next one ' . date('d M', $last + 7 * 86400);
    $name = edu_make_backup($pdo, $dir, 'auto', ['uploads' => true, 'store' => true, 'site' => false]);
    $keep = max(1, min(30, (int)es('cron', 'backup_keep', 4)));
    $autos = glob($dir . '/backup-*-auto.zip') ?: [];
    usort($autos, fn($a, $b) => filemtime($b) <=> filemtime($a));
    $gone = 0; foreach (array_slice($autos, $keep) as $old) if (@unlink($old)) $gone++;
    return "automatic backup made ($name)" . ($gone ? ", $gone old one(s) removed" : '');
}
