<?php
// Online Store → PDF Media: a library of PDFs only. Each PDF has a title (the name students see and the name of the
// file when they download it). Lectures pick their notes from here (Add notes PDF opens this library).
//   page:  /admin/shop-pdfs.php            upload (from the device), rename, open, delete (only when no lecture uses it)
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
if (isset($_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
    $qq = trim((string)($_GET['q'] ?? ''));
    $st = $pdo->prepare("SELECT m.*, (SELECT COUNT(*) FROM sh_files f WHERE f.file = m.file) AS used FROM sh_pdf_media m" . ($qq !== '' ? " WHERE m.title LIKE ?" : '') . " ORDER BY m.id DESC LIMIT 500");
    $st->execute($qq !== '' ? ["%$qq%"] : []);
    exit(json_encode(['items' => array_map(fn($r) => ['id' => (int)$r['id'], 'title' => $r['title'], 'pages' => $r['pages'] === null ? null : (int)$r['pages'], 'size' => (int)$r['size'],
        'date' => date('d M Y', strtotime((string)$r['created_at'])), 'used' => (int)$r['used'], 'view' => '/admin/shop-pdfs.php?view=' . (int)$r['id']], $st->fetchAll(PDO::FETCH_ASSOC))], JSON_UNESCAPED_UNICODE));
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $ajax = !empty($_POST['ajax']); $id = (int)($_POST['id'] ?? 0); $a = (string)($_POST['action'] ?? '');
    $res = ['ok' => false];
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
<script>window.PM_CFG = { csrf: <?= json_encode(csrf_token()) ?> };</script>
<script src="/assets/js/pdf-media.js?v=<?= @filemtime(ROOT_PATH . '/assets/js/pdf-media.js') ?: 1 ?>"></script>
<script>PdfMedia.mount(document.getElementById('pmPage'), { uploadButton: document.getElementById('pmUploadBtn') });</script>
</body>
</html>
