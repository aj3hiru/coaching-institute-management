<?php
// Import & Export — posts or pages as a ZIP that this site, another site on this CMS and the StoryTimes CMS all read:
// content, address (same URL path), SEO fields, categories, tags, FAQs and every image.
// Import: the file is checked first (what it holds, what is already here), then "Start import" runs item by item
// with a log; posts can be given to the original author or to an author chosen here, like WordPress.
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/tools.php';
require_once INCLUDES_PATH . '/pages.php';
require_once INCLUDES_PATH . '/transfer.php';
require_once INCLUDES_PATH . '/transfer-store.php';
$me = admin_guard(['admin']);
@set_time_limit(600);
@ini_set('memory_limit', '512M');
edu_pages_table($pdo);

$action = $_POST['action'] ?? '';
// Courses & PDF notes (ZIP), students / orders / fee payments (CSV for Excel) — see includes/transfer-store.php
if (in_array($action, ['export_store', 'export_students', 'export_orders', 'export_fees'], true)) {
    csrf_check();
    try {
        if ($action === 'export_store') {
            [$zipFile, $n, $files] = edu_txs_export($pdo, $_POST, !empty($_POST['videos']));
            admin_log('export', "Exported $n courses / notes ($files files)");
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . basename($zipFile) . '"');
            header('Content-Length: ' . filesize($zipFile));
            readfile($zipFile); @unlink($zipFile); exit;
        }
        $d = date('Y-m-d');
        if ($action === 'export_students') { $rows = edu_csv_students($pdo, $_POST); admin_log('export', 'Exported ' . count($rows) . ' students (CSV)'); edu_csv_send("students-$d.csv", EDU_CSV_STUDENT_HEAD, $rows); exit; }
        if ($action === 'export_orders') { $rows = edu_csv_orders($pdo, $_POST); admin_log('export', 'Exported ' . count($rows) . ' orders (CSV)'); edu_csv_send("orders-$d.csv", EDU_CSV_ORDER_HEAD, $rows); exit; }
        if ($action === 'export_fees') { $rows = edu_csv_fees($pdo, $_POST); admin_log('export', 'Exported ' . count($rows) . ' fee payments (CSV)'); edu_csv_send("fee-payments-$d.csv", EDU_CSV_FEE_HEAD, $rows); exit; }
    } catch (Throwable $e) {
        admin_flash($e->getMessage(), 'error');
        header('Location: /admin/import-export.php?tab=' . ($action === 'export_store' ? 'store' : ($action === 'export_students' ? 'students' : 'orders')));
        exit;
    }
}
if ($action === 'import_students') {
    csrf_check();
    try {
        $f = $_FILES['csv'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Choose the CSV file first.');
        if (!preg_match('/\.(csv|txt)$/i', (string)$f['name'])) throw new RuntimeException('Please choose a .csv file (in Excel: File → Save as → CSV).');
        [$added, $skipped, $errs] = edu_csv_import_students($pdo, $f['tmp_name'], (int)$me['id']);
        admin_log('import', "Imported students: $added added, $skipped already there, " . count($errs) . ' errors');
        admin_flash("Students imported: $added added, $skipped already there (same name + mobile)." . ($errs ? ' Not added — ' . implode('; ', array_slice($errs, 0, 5)) . (count($errs) > 5 ? ' … and ' . (count($errs) - 5) . ' more' : '') : ''), $errs && !$added ? 'error' : 'success');
    } catch (Throwable $e) { admin_flash($e->getMessage(), 'error'); }
    header('Location: /admin/import-export.php?tab=students');
    exit;
}
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
        $isStore = false;
        $zz = new ZipArchive();
        if ($zz->open($done['path']) === true) { $mf = json_decode((string)$zz->getFromName('manifest.json'), true); $isStore = is_array($mf) && ($mf['format'] ?? '') === 'dk-store'; $zz->close(); }
        try {
            $data = $isStore ? edu_txs_read($done['path']) : edu_tx_read($done['path']);
        } catch (Throwable $e) {
            @unlink($done['path']);
            throw $e;
        }
        if (!empty($_SESSION['edu_import_file']) && $_SESSION['edu_import_file'] !== $done['path']) @unlink($_SESSION['edu_import_file']);
        $_SESSION['edu_import_file'] = $done['path'];
        $_SESSION['edu_import_kind'] = $isStore ? 'store' : 'content';
        $scan = $isStore ? edu_txs_scan($pdo, $data) : edu_tx_scan($pdo, $data);
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
        if (($_SESSION['edu_import_kind'] ?? '') === 'store') { // courses & notes: one per request (files can be big)
            $m = edu_txs_read($src);
            $offset = max(0, (int)($_POST['offset'] ?? 0));
            $decisions = json_decode((string)($_POST['decisions'] ?? '{}'), true) ?: [];
            $total = count($m['items']);
            $log = [];
            $zip = new ZipArchive(); $zip->open($src);
            if ($offset < $total) {
                $it = $m['items'][$offset];
                try {
                    $d = in_array($decisions[$offset] ?? '', ['skip', 'replace', 'keep_both'], true) ? $decisions[$offset] : 'keep_both';
                    $q = $pdo->prepare("SELECT COUNT(*) FROM sh_products WHERE slug = ?"); $q->execute([(string)$it['slug']]); $was = (int)$q->fetchColumn();
                    $r = edu_txs_import_item($pdo, $zip, $it, ['decision' => $d, 'status' => in_array($_POST['status'] ?? '', ['file', 'draft', 'published'], true) ? $_POST['status'] : 'file']);
                    if ($was && $r['outcome'] === 'imported') $r['outcome'] = 'renamed';
                    if (!empty($r['note'])) $r['error'] = $r['note'];
                } catch (Throwable $e) { $r = ['outcome' => 'failed', 'title' => (string)($it['title'] ?? 'Item'), 'url' => '', 'error' => $e->getMessage()]; }
                $log[] = $r;
            }
            $zip->close();
            $next = min($total, $offset + 1);
            if ($next >= $total) { edu_clear_page_cache(); @unlink($src); unset($_SESSION['edu_import_file'], $_SESSION['edu_import_kind']); admin_log('import', "Imported courses / notes from a ZIP ($total items)"); }
            echo json_encode(['ok' => true, 'next' => $next, 'total' => $total, 'done' => $next >= $total, 'log' => $log], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
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
sh_tables($pdo); ci_tables($pdo);
$shCats = $pdo->query("SELECT id, name FROM sh_categories ORDER BY sort, name")->fetchAll(PDO::FETCH_KEY_PAIR);
$shProducts = $pdo->query("SELECT id, title FROM sh_products ORDER BY title")->fetchAll(PDO::FETCH_KEY_PAIR);
$ciClasses = $pdo->query("SELECT id, name FROM ci_classes ORDER BY sort, name")->fetchAll(PDO::FETCH_KEY_PAIR);
$storeCount = $pdo->query("SELECT SUM(type = 'course') AS course, SUM(type = 'notes') AS notes FROM sh_products")->fetch(PDO::FETCH_ASSOC);
$vs = 0; foreach ($pdo->query("SELECT video_file, variants FROM sh_lessons WHERE video_file IS NOT NULL AND video_file <> ''") as $l) { foreach (array_merge([$l['video_file']], array_values((array)json_decode((string)$l['variants'], true))) as $rel) { $ab = sh_media_path((string)$rel); if ($rel && is_file($ab)) $vs += filesize($ab); } }
$storeVideoSize = $vs >= 1073741824 ? number_format($vs / 1073741824, 2) . ' GB' : number_format($vs / 1048576, 1) . ' MB';
$tab = in_array($_GET['tab'] ?? '', ['content', 'store', 'students', 'orders'], true) ? $_GET['tab'] : 'content';
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
/* export tabs */
.ie-tabs{display:flex;gap:4px;flex-wrap:wrap;margin:10px 0 14px;border-bottom:1px solid #e5e7eb}
.ie-tabs button{border:0;background:none;padding:9px 12px;font:600 .85rem inherit;font-family:inherit;color:#475569;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-1px}
.ie-tabs button.on{color:var(--primary);border-bottom-color:var(--primary)}.ie-tabs button i{margin-right:4px}
.ie-pane>p{margin:0 0 14px;color:var(--gray-500,#6b7280);font-size:.9rem}
.ie-chk{display:flex;gap:10px;align-items:flex-start;padding:10px 12px;border:1px solid #e5e7eb;border-radius:5px;margin:0 0 12px;cursor:pointer}.ie-chk input{margin-top:3px}.ie-chk b{display:block;font-size:.88rem}.ie-chk small{display:block;color:#6b7280;font-size:.8rem}
.ie-muted{font-size:.82rem;color:#6b7280;margin:0 0 12px}
.ie-sub{margin-top:20px;padding-top:16px;border-top:1px solid #eef0f3}.ie-sub h4,.ie-h4{margin:0 0 6px;font-size:.95rem}.ie-sub p{font-size:.85rem;color:#6b7280;margin:0 0 10px}
.ie-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.ie-row input[type=file]{flex:1;min-width:200px;height:auto;padding:6px}
@media(max-width:900px){.ie-grid{grid-template-columns:1fr}.ie-opts{grid-template-columns:1fr}.ie-conf-row{grid-template-columns:1fr}.ie-result{grid-template-columns:1fr 1fr}}
</style>
<?= edu_chunk_js() ?>
</head>
<body>
<?php admin_shell_open('Import &amp; Export', 'Posts, pages, courses, PDF notes, students, orders and fees — export with filters, import back'); ?>
<?= admin_flash() ?>
<div class="ie-grid">
  <div class="ie-card">
    <h3><i class="fas fa-file-export"></i> Export</h3>
    <nav class="ie-tabs" role="tablist"><button type="button" data-tab="content" class="on"><i class="fas fa-newspaper"></i> Posts &amp; Pages</button><button type="button" data-tab="store"><i class="fas fa-photo-film"></i> Courses &amp; Notes</button><button type="button" data-tab="students"><i class="fas fa-user-graduate"></i> Students</button><button type="button" data-tab="orders"><i class="fas fa-receipt"></i> Orders &amp; Fees</button></nav>
    <div class="ie-pane" data-pane="content">
    <p>Download posts or pages as a ZIP — content, address, SEO, tags, categories, FAQs and every image. It imports on this site, another site on this CMS or the StoryTimes CMS.</p>
    <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="export_posts">
      <strong style="font-size:.9rem">Posts by category</strong> <small style="color:#9ca3af">— none ticked = every published post (<?= $totalPub ?>)</small>
      <div class="ie-cats"><?php foreach ($cats as $c): ?><label><span><input type="checkbox" name="cats[]" value="<?= (int)$c['id'] ?>"> <?= htmlspecialchars($c['name']) ?></span><small><?= (int)$c['n'] ?></small></label><?php endforeach; ?></div>
      <button type="submit" class="btn btn-primary"><i class="fas fa-download"></i> Export posts</button></form>
    <form method="POST" style="margin-top:12px"><?= csrf_field() ?><input type="hidden" name="action" value="export_pages"><button type="submit" class="btn btn-secondary" <?= $pageCount ? '' : 'disabled' ?>><i class="fas fa-download"></i> Export pages (<?= $pageCount ?>)</button></form>
    </div>
    <div class="ie-pane" data-pane="store" hidden>
      <p>Courses and PDF notes as a ZIP — every detail, price, category, chapters, units, lectures, lecture PDFs, notes files and thumbnails. Import it on this site or another copy of it (the Import box on the right).</p>
      <form method="POST" class="ie-form"><?= csrf_field() ?><input type="hidden" name="action" value="export_store">
        <div class="ie-opts">
          <label>What<select class="form-control" name="type"><option value="">Courses and PDF notes</option><option value="course">Only courses</option><option value="notes">Only PDF notes</option></select></label>
          <label>Status<select class="form-control" name="status"><option value="">Published and drafts</option><option value="published">Only published</option><option value="draft">Only drafts</option></select></label>
          <label>Price<select class="form-control" name="price"><option value="">Paid and free</option><option value="paid">Only paid</option><option value="free">Only free</option></select></label>
          <label>Category<select class="form-control" name="category"><option value="0">All categories</option><?php foreach ($shCats as $cid => $cn): ?><option value="<?= (int)$cid ?>"><?= htmlspecialchars($cn) ?></option><?php endforeach; ?></select></label>
        </div>
        <label class="ie-chk"><input type="checkbox" name="videos" value="1"> <span><b>Include uploaded lecture videos</b><small>Videos make the file big (<?= htmlspecialchars($storeVideoSize) ?> now). YouTube lectures are always included as links.</small></span></label>
        <p class="ie-muted"><?= (int)$storeCount['course'] ?> courses · <?= (int)$storeCount['notes'] ?> PDF notes on this site</p>
        <button type="submit" class="btn btn-primary"><i class="fas fa-download"></i> Export courses / notes</button>
      </form>
    </div>
    <div class="ie-pane" data-pane="students" hidden>
      <p>Students as a CSV file (opens in Excel / Google Sheets): details, type, class, courses and batches, online courses, fee, paid and due.</p>
      <form method="POST" class="ie-form"><?= csrf_field() ?><input type="hidden" name="action" value="export_students">
        <div class="ie-opts">
          <label>Student type<select class="form-control" name="type"><option value="">All students</option><option value="institute">Institute only</option><option value="online">Online only</option><option value="both">Both</option></select></label>
          <label>Status<select class="form-control" name="status"><option value="">Any status</option><option value="active">Active</option><option value="left">Left</option><option value="completed">Completed</option></select></label>
          <label>Class<select class="form-control" name="class"><option value="0">All classes</option><?php foreach ($ciClasses as $cid => $cn): ?><option value="<?= (int)$cid ?>"><?= htmlspecialchars($cn) ?></option><?php endforeach; ?></select></label>
          <label>&nbsp;<span></span></label>
          <label>Admitted from<input class="form-control" type="date" name="from"></label>
          <label>Admitted to<input class="form-control" type="date" name="to"></label>
        </div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-file-csv"></i> Export students</button>
      </form>
      <div class="ie-sub">
        <h4><i class="fas fa-file-import"></i> Import students from a sheet</h4>
        <p>A CSV with the column names in the first row — at least <b>Name</b> and <b>Mobile</b> (the Export above has every column). A student with the same name and mobile is skipped; new students get the next reg. no.</p>
        <form method="POST" enctype="multipart/form-data" class="ie-row"><?= csrf_field() ?><input type="hidden" name="action" value="import_students"><input type="file" name="csv" accept=".csv,text/csv" required class="form-control"><button type="submit" class="btn btn-secondary"><i class="fas fa-upload"></i> Import</button></form>
      </div>
    </div>
    <div class="ie-pane" data-pane="orders" hidden>
      <p>Online orders and institute fee payments (receipts) as CSV files for Excel.</p>
      <form method="POST" class="ie-form"><?= csrf_field() ?><input type="hidden" name="action" value="export_orders">
        <h4 class="ie-h4">Online orders</h4>
        <div class="ie-opts">
          <label>From<input class="form-control" type="date" name="from" value="<?= date('Y-m-01') ?>"></label>
          <label>To<input class="form-control" type="date" name="to" value="<?= date('Y-m-d') ?>"></label>
          <label>Status<select class="form-control" name="status"><option value="paid">Paid</option><option value="">All</option><option value="created">Not paid</option><option value="failed">Failed</option></select></label>
          <label>Item<select class="form-control" name="product"><option value="0">All courses / notes</option><?php foreach ($shProducts as $pid => $pt): ?><option value="<?= (int)$pid ?>"><?= htmlspecialchars($pt) ?></option><?php endforeach; ?></select></label>
        </div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-file-csv"></i> Export orders</button>
      </form>
      <form method="POST" class="ie-form" style="margin-top:18px"><?= csrf_field() ?><input type="hidden" name="action" value="export_fees">
        <h4 class="ie-h4">Institute fee payments</h4>
        <div class="ie-opts">
          <label>From<input class="form-control" type="date" name="from" value="<?= date('Y-m-01') ?>"></label>
          <label>To<input class="form-control" type="date" name="to" value="<?= date('Y-m-d') ?>"></label>
        </div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-file-csv"></i> Export fee payments</button>
      </form>
    </div>
  </div>
  <div class="ie-card">
    <h3><i class="fas fa-file-import"></i> Import</h3><p>Add posts, pages or courses &amp; notes from an Export ZIP (from this site, another site on this CMS or the StoryTimes CMS). Each item keeps its address; images, SEO, categories and tags come along.</p>
    <div class="ie-steps"><span class="on" id="st1">1 Choose file</span><span id="st2">2 Check</span><span id="st3">3 Import</span></div>
    <label class="ie-drop" id="ie-drop"><i class="fas fa-cloud-arrow-up" style="font-size:1.6rem;display:block;margin-bottom:6px"></i><span id="ie-text">Drop a ZIP here or click to choose<br><small>posts, pages or courses export ZIP</small></span><input type="file" id="ie-file" accept=".zip,application/zip" hidden></label>
    <div class="ie-prog" id="ie-prog" hidden><span></span></div>
    <div id="ie-review" hidden>
      <div class="ie-sum"><div><b id="rv-count">0</b><span id="rv-type">posts</span></div><div><b id="rv-new">0</b><span>new here</span></div><div><b id="rv-dup">0</b><span>already here</span></div></div>
      <p class="ie-src" id="rv-src"></p>
      <ul class="ie-titles" id="rv-titles"></ul>
      <div class="ie-conf" id="rv-conf" hidden><h4><span><i class="fas fa-triangle-exclamation"></i> Already on this site</span><span>Set all: <button type="button" data-all="keep_both">Keep both</button> · <button type="button" data-all="replace">Replace</button> · <button type="button" data-all="skip">Skip</button></span></h4><div id="rv-conf-list"></div></div>
      <div class="ie-opts">
        <label id="rv-author-wrap">Give the posts to<select class="form-control" id="rv-author"><option value="0">The original author (matched by name)</option><?php foreach ($authors as $id => $n): ?><option value="<?= (int)$id ?>"><?= htmlspecialchars($n) ?></option><?php endforeach; ?></select><small style="font-weight:400;color:#64748b" id="rv-authors-note"></small></label>
        <label>Status after import<select class="form-control" id="rv-status"><option value="file">As in the file</option><option value="draft">Drafts</option><option value="published">Published</option></select></label>
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
  // export tabs (?tab=… opens one)
  var tabs = document.querySelectorAll('.ie-tabs [data-tab]');
  function showTab(k) { tabs.forEach(function (b) { b.classList.toggle('on', b.dataset.tab === k); }); document.querySelectorAll('.ie-pane').forEach(function (p) { p.hidden = p.dataset.pane !== k; }); }
  tabs.forEach(function (b) { b.onclick = function () { showTab(b.dataset.tab); }; });
  showTab(<?= json_encode($tab, JSON_HEX_TAG) ?>);
  var $ = function (id) { return document.getElementById(id); }, CSRF = <?= json_encode(csrf_token(), JSON_HEX_TAG) ?>, scan = null, decisions = {}, busy = false;
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
      $('rv-count').textContent = d.count; $('rv-type').textContent = d.type === 'store' ? 'courses / notes' : d.type === 'posts' ? (d.count === 1 ? 'post' : 'posts') : (d.count === 1 ? 'page' : 'pages');
      $('rv-dup').textContent = d.conflicts.length; $('rv-new').textContent = d.count - d.conflicts.length;
      var s = d.source || {}, app = /storytimes|next/i.test(s.app || '') ? 'StoryTimes CMS' : /edumint/i.test(s.app || '') ? 'This CMS' : '';
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
  $('ie-again').onclick = function () { scan = null; $('ie-review').hidden = true; $('ie-log').hidden = true; $('ie-result').hidden = true; prog.hidden = true; input.value = ''; txt.innerHTML = 'Drop a ZIP here or click to choose<br><small>posts, pages or courses export ZIP</small>'; step(1); };
});
</script>
</body>
</html>
