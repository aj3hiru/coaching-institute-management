<?php
// Online Store → PDF Media: a library of PDFs only. Each PDF has a title (the name students see and the name of the
// file when they download it). Lectures pick their notes from here (Add notes PDF opens this library).
//   page:  shown in File Manager → "Course notes (PDF)" (this address moves there)
//   JSON:  ?json=1[&q=]                     list for the picker      POST action=rename|delete (ajax=1)
//   view:  ?view=ID                         opens the PDF (admins only)
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/institute.php';
require_once INCLUDES_PATH . '/shop.php';
$me = sh_page_start($pdo, ['products']);
sh_tables($pdo);
$used = fn(string $file) => (int)$pdo->query("SELECT COUNT(*) FROM sh_files WHERE file = " . $pdo->quote($file))->fetchColumn();

if (isset($_GET['view'])) {
    $q = $pdo->prepare("SELECT * FROM sh_pdf_media WHERE id = ?"); $q->execute([(int)$_GET['view']]);
    $m = $q->fetch(PDO::FETCH_ASSOC); $path = $m ? sh_media_path($m['file']) : '';
    if (!$m || !is_file($path)) { http_response_code(404); exit('PDF not found'); }
    session_write_close();
    sh_send_file($path, 'application/pdf', $m['title'] . '.pdf', true); exit;
}
if (isset($_GET['site'])) { // PDFs of File Manager (the website's public files), to add as course notes
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
    $qq = trim((string)($_GET['q'] ?? ''));
    $st = $pdo->prepare("SELECT id, file_path, title, uploaded_at FROM media WHERE (file_type = 'pdf' OR file_path LIKE '%.pdf')" . ($qq !== '' ? " AND (title LIKE ? OR file_path LIKE ?)" : '') . " ORDER BY id DESC LIMIT 300");
    $st->execute($qq !== '' ? ["%$qq%", "%$qq%"] : []);
    $root = rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/');
    exit(json_encode(['items' => array_values(array_filter(array_map(function ($r) use ($root) { $f = $root . '/' . ltrim($r['file_path'], '/'); if (!is_file($f)) return null;
        return ['id' => (int)$r['id'], 'title' => trim((string)$r['title']) ?: preg_replace('/\.pdf$/i', '', basename($r['file_path'])), 'size' => filesize($f), 'date' => date('d M Y', strtotime((string)$r['uploaded_at'])), 'view' => '/' . ltrim($r['file_path'], '/')]; }, $st->fetchAll(PDO::FETCH_ASSOC))))], JSON_UNESCAPED_UNICODE));
}
if (isset($_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
    $qq = trim((string)($_GET['q'] ?? ''));
    $st = $pdo->prepare("SELECT m.*, (SELECT COUNT(*) FROM sh_files f WHERE f.file = m.file) AS used FROM sh_pdf_media m" . ($qq !== '' ? " WHERE m.title LIKE ?" : '') . " ORDER BY m.id DESC LIMIT 500");
    $st->execute($qq !== '' ? ["%$qq%"] : []);
    exit(json_encode(['items' => array_map(fn($r) => ['id' => (int)$r['id'], 'title' => $r['title'], 'pages' => $r['pages'] === null ? null : (int)$r['pages'], 'size' => (int)$r['size'],
        'date' => date('d M Y', strtotime((string)$r['created_at'])), 'used' => (int)$r['used'], 'view' => '/admin/shop-pdfs.php?view=' . (int)$r['id']], $st->fetchAll(PDO::FETCH_ASSOC))], JSON_UNESCAPED_UNICODE));
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /admin/file-manager.php?tab=notes'); exit; } // the library is shown in File Manager → Course notes (PDF)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $ajax = !empty($_POST['ajax']); $id = (int)($_POST['id'] ?? 0); $a = (string)($_POST['action'] ?? '');
    $res = ['ok' => false];
    if ($a === 'import') { // a File Manager PDF becomes course notes: a private copy (only buyers can open it), with its title
        $q = $pdo->prepare("SELECT * FROM media WHERE id = ? AND (file_type = 'pdf' OR file_path LIKE '%.pdf')"); $q->execute([(int)($_POST['media_id'] ?? 0)]); $fm = $q->fetch(PDO::FETCH_ASSOC);
        $src = $fm ? rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/') . '/' . ltrim($fm['file_path'], '/') : '';
        if (!$fm || !is_file($src)) $res['error'] = 'That PDF was not found in File Manager.';
        else {
            $dir = sh_media_dir() . '/library'; if (!is_dir($dir)) @mkdir($dir, 0750, true);
            $rel = 'library/pm-' . bin2hex(random_bytes(6)) . '.pdf';
            if (!@copy($src, sh_media_dir() . '/' . $rel)) $res['error'] = 'Could not copy the PDF.';
            else {
                @chmod(sh_media_dir() . '/' . $rel, 0640);
                $t = mb_substr(trim((string)($_POST['title'] ?? '')) ?: (trim((string)$fm['title']) ?: preg_replace('/\.pdf$/i', '', basename($fm['file_path']))), 0, 200);
                $pages = preg_match_all('#/Type\s*/Page[^s]#', (string)file_get_contents($src, false, null, 0, 20 * 1024 * 1024)) ?: null;
                $pdo->prepare("INSERT INTO sh_pdf_media (title, file, size, pages, created_by) VALUES (?, ?, ?, ?, ?)")->execute([$t, $rel, filesize($src), $pages, (int)$me['id']]);
                $res = ['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'title' => $t, 'pages' => $pages, 'size' => filesize($src)];
            }
        }
        if ($ajax) { header('Content-Type: application/json; charset=utf-8'); exit(json_encode($res, JSON_UNESCAPED_UNICODE)); }
    }
    $q = $pdo->prepare("SELECT * FROM sh_pdf_media WHERE id = ?"); $q->execute([$id]); $m = $q->fetch(PDO::FETCH_ASSOC);
    if (!$m) $res['error'] = 'PDF not found.';
    elseif ($a === 'rename') {
        $t = mb_substr(trim(preg_replace('/\s+/', ' ', (string)($_POST['title'] ?? ''))), 0, 200);
        if ($t === '') $res['error'] = 'Write the PDF title.';
        else { $pdo->prepare("UPDATE sh_pdf_media SET title = ? WHERE id = ?")->execute([$t, $id]); $res = ['ok' => true, 'title' => $t]; }
    } elseif ($a === 'delete') {
        if ($n = $used($m['file'])) $res['error'] = "This PDF is used in $n lecture" . ($n === 1 ? '' : 's') . '. Remove it from ' . ($n === 1 ? 'that lecture' : 'those lectures') . ' first.';
        else { $pdo->prepare("DELETE FROM sh_pdf_media WHERE id = ?")->execute([$id]); $p = sh_media_path($m['file']); if (is_file($p)) @unlink($p); admin_log('shop_pdf_media', "Deleted PDF \"{$m['title']}\""); $res = ['ok' => true]; }
    }
    if ($ajax) { header('Content-Type: application/json; charset=utf-8'); exit(json_encode($res, JSON_UNESCAPED_UNICODE)); }
    admin_flash($res['ok'] ? 'Saved.' : ($res['error'] ?? 'Could not save.'), $res['ok'] ? 'success' : 'error');
    header('Location: /admin/shop-pdfs.php'); exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>PDF Media - <?= ci_e(edu_site_name()) ?></title>
<?php admin_shell_head(); ci_head(); ?>
<link rel="stylesheet" href="/assets/css/pdf-media.css?v=<?= @filemtime(ROOT_PATH . '/assets/css/pdf-media.css') ?: 1 ?>">
</head>
<body>
<?php admin_shell_open('PDF Media', 'Every notes PDF in one place — give each a clear title; lectures add their notes from here', '<button type="button" class="ci-btn" id="pmUploadBtn"><i class="fas fa-upload"></i> Upload PDF</button>'); ?>
<div class="ci">
<?= admin_flash() ?>
<div class="pm-root" id="pmPage" data-mode="page"></div>
</div>
<?php admin_shell_close(); ?>
<script>window.PM_CFG = { csrf: <?= json_encode(csrf_token(), JSON_HEX_TAG) ?> };</script>
<script src="/assets/js/pdf-media.js?v=<?= @filemtime(ROOT_PATH . '/assets/js/pdf-media.js') ?: 1 ?>"></script>
<script>PdfMedia.mount(document.getElementById('pmPage'), { uploadButton: document.getElementById('pmUploadBtn') });</script>
</body>
</html>
