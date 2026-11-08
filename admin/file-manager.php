<?php
// File Manager — every file the site uses (post images, logo, author photos, PDFs, documents…) in one place:
// drag & drop upload, search, filter by type or uploader, details (alt / title / caption / description),
// copy the address, see where a file is used, delete one or many. "Find files" adds files that are in the
// uploads folder but not listed yet (older uploads), so the library shows everything.
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/media.php';
$me = admin_guard(['admin', 'editor', 'author']);
if (!admin_can('files.access_file_manager') && !admin_can('media.upload')) { http_response_code(403); exit('Access Denied'); }
$canDelete = admin_can('media.delete') || $me['role'] === 'admin';
// course notes PDFs (PDF Media of the online store) are a filter here too — for staff who manage courses
if (!function_exists('sh_perm')) require_once INCLUDES_PATH . '/shop.php';
$canNotes = sh_perm($me, 'products');
// without "Manage All" a user sees and deletes only the files they uploaded
$ownMedia = $me['role'] !== 'admin' && !admin_can('media.manage_all');
edu_media_columns($pdo);

function fm_json(array $d): void
{
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
/** Posts and settings that use this file (featured image or inside the text). */
function fm_usage(PDO $pdo, array $m): array
{
    $path = ltrim($m['file_path'], '/');
    $st = $pdo->prepare("SELECT id, title, slug FROM posts WHERE featured_image_id = ? OR content LIKE ? LIMIT 20");
    $st->execute([(int)$m['id'], '%' . $path . '%']);
    $posts = $st->fetchAll(PDO::FETCH_ASSOC);
    $st = $pdo->prepare("SELECT config_key FROM app_config WHERE config_key LIKE 'edu\\_%' AND config_value LIKE ?");
    $st->execute(['%' . str_replace('/', '\\\\/', $path) . '%']);
    $settings = $st->fetchAll(PDO::FETCH_COLUMN);
    $st2 = $pdo->prepare("SELECT config_key FROM app_config WHERE config_key LIKE 'edu\\_%' AND config_value LIKE ?");
    $st2->execute(['%' . $path . '%']);
    $settings = array_values(array_unique(array_merge($settings, $st2->fetchAll(PDO::FETCH_COLUMN))));
    $st = $pdo->prepare("SELECT name FROM authors WHERE profile_image = ? OR profile_image = ?");
    $st->execute([$path, '/' . $path]);
    return ['posts' => $posts, 'settings' => array_map(fn($k) => ucfirst(substr($k, 4)), $settings), 'authors' => $st->fetchAll(PDO::FETCH_COLUMN)];
}

$ajax = $_GET['ajax'] ?? ($_POST['ajax'] ?? '');
if ($ajax === 'list') {
    $type = in_array($_GET['type'] ?? '', ['image', 'pdf', 'document', 'media'], true) ? $_GET['type'] : '';
    $q = trim((string)($_GET['q'] ?? ''));
    $by = (int)($_GET['by'] ?? 0);
    $per = in_array((int)($_GET['per'] ?? 0), [24, 48, 96], true) ? (int)$_GET['per'] : 48;
    $page = max(1, (int)($_GET['page'] ?? 1));
    $img = "(m.file_type IN ('image','banner') OR m.file_path REGEXP '\\\\.(webp|jpe?g|png|gif|avif|svg|ico)$')";
    $pdf = "(m.file_type = 'pdf' OR m.file_path LIKE '%.pdf')";
    $where = [];
    $args = [];
    if ($ownMedia) { $where[] = 'm.uploaded_by = ?'; $args[] = (int)$me['id']; }
    if ($type === 'image') $where[] = $img;
    elseif ($type === 'pdf') $where[] = $pdf;
    elseif ($type === 'media') $where[] = "m.file_type IN ('video','audio')";
    elseif ($type === 'document') $where[] = "NOT $img AND NOT $pdf AND m.file_type NOT IN ('video','audio')";
    if ($q !== '') { $where[] = '(m.file_path LIKE ? OR m.alt_text LIKE ? OR m.title LIKE ?)'; array_push($args, "%$q%", "%$q%", "%$q%"); }
    if ($by) { $where[] = 'm.uploaded_by = ?'; $args[] = $by; }
    $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $st = $pdo->prepare("SELECT COUNT(*) FROM media m $w");
    $st->execute($args);
    $total = (int)$st->fetchColumn();
    $st = $pdo->prepare("SELECT m.*, u.username FROM media m LEFT JOIN users u ON u.id = m.uploaded_by $w ORDER BY m.id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
    $st->execute($args);
    $items = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $set = json_decode((string)$m['responsive_set'], true) ?: [];
        $kind = edu_media_kind($m);
        $items[] = ['id' => (int)$m['id'], 'path' => '/' . ltrim($m['file_path'], '/'), 'thumb' => '/' . ltrim($set['sm'] ?? $m['file_path'], '/'), 'kind' => $kind,
            'name' => basename($m['file_path']), 'ext' => strtoupper(pathinfo($m['file_path'], PATHINFO_EXTENSION)), 'title' => (string)$m['title'], 'alt' => (string)$m['alt_text'],
            'caption' => (string)$m['caption'], 'description' => (string)$m['description'], 'date' => $m['uploaded_at'] ? date('j M Y, g:i a', strtotime($m['uploaded_at'])) : '', 'by' => (string)$m['username']];
    }
    $c = $pdo->query("SELECT COUNT(*) total, SUM(file_type IN ('image','banner') OR file_path REGEXP '\\\\.(webp|jpe?g|png|gif|avif|svg|ico)$') images, SUM(file_type = 'pdf' OR file_path LIKE '%.pdf') pdfs, SUM(file_type IN ('video','audio')) media FROM media" . ($ownMedia ? ' WHERE uploaded_by = ' . (int)$me['id'] : ''))->fetch(PDO::FETCH_ASSOC);
    $c = array_map('intval', $c);
    $c['documents'] = $c['total'] - $c['images'] - $c['pdfs'] - $c['media'];
    fm_json(['items' => $items, 'total' => $total, 'page' => $page, 'pages' => max(1, (int)ceil($total / $per)), 'counts' => $c]);
}
if ($ajax === 'info') {
    $st = $pdo->prepare("SELECT m.*, u.username FROM media m LEFT JOIN users u ON u.id = m.uploaded_by WHERE m.id = ?");
    $st->execute([(int)($_GET['id'] ?? 0)]);
    $m = $st->fetch(PDO::FETCH_ASSOC);
    if (!$m) fm_json(['error' => 'This file no longer exists.']);
    $abs = ROOT_PATH . '/' . ltrim($m['file_path'], '/');
    $size = is_file($abs) ? filesize($abs) : 0;
    $dim = edu_media_kind($m) === 'image' && is_file($abs) ? @getimagesize($abs) : null;
    fm_json(['size' => $size ? ($size > 1048576 ? round($size / 1048576, 1) . ' MB' : max(1, round($size / 1024)) . ' KB') : 'File missing on disk', 'missing' => !$size,
        'dims' => $dim ? $dim[0] . ' × ' . $dim[1] . ' px' : '', 'usage' => fm_usage($pdo, $m), 'doc' => edu_media_kind($m) === 'pdf' ? rtrim(SITE_URL, '/') . '/documents/' . (int)$m['id'] : '']);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';
    if ($act === 'save') {
        $f = fn($k, $max) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
        $pdo->prepare("UPDATE media SET alt_text = ?, title = ?, caption = ?, description = ? WHERE id = ?")->execute([$f('alt', 200), $f('title', 255), $f('caption', 2000), $f('description', 5000), (int)($_POST['id'] ?? 0)]);
        fm_json(['ok' => true]);
    }
    if ($act === 'delete') {
        if (!$canDelete) fm_json(['ok' => false, 'error' => 'You may not delete files.']);
        $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
        if (!$ids) fm_json(['ok' => false, 'error' => 'Nothing selected.']);
        $rows = $pdo->query("SELECT * FROM media WHERE id IN (" . implode(',', $ids) . ")" . ($ownMedia ? ' AND uploaded_by = ' . (int)$me['id'] : ''))->fetchAll(PDO::FETCH_ASSOC);
        $n = 0;
        foreach ($rows as $m) {
            // Files can be shared between rows (e.g. imported copies) — only remove them from disk when no other row uses them.
            foreach (edu_media_files($m) as $rel) {
                $other = $pdo->prepare("SELECT COUNT(*) FROM media WHERE id <> ? AND (file_path = ? OR responsive_set LIKE ?)");
                $other->execute([$m['id'], $rel, '%' . str_replace('/', '\\\\/', $rel) . '%']);
                $abs = realpath(ROOT_PATH . '/' . $rel);
                if (!$other->fetchColumn() && $abs && str_starts_with($abs, realpath(ROOT_PATH) . '/') && preg_match('#/(uploads|files)/#', $abs)) @unlink($abs);
            }
            $pdo->prepare("DELETE FROM media WHERE id = ?")->execute([$m['id']]);
            $n++;
        }
        edu_clear_page_cache();
        admin_log('media_delete', "Deleted $n file(s) from the File Manager");
        fm_json(['ok' => true, 'deleted' => $n]);
    }
    if ($act === 'scan' && $me['role'] === 'admin') {
        // Register image/PDF/document files in /uploads and /files that are not in the library yet (size variants are skipped).
        $known = array_flip(array_map(fn($p) => ltrim($p, '/'), $pdo->query("SELECT file_path FROM media")->fetchAll(PDO::FETCH_COLUMN)));
        foreach ($pdo->query("SELECT responsive_set FROM media WHERE responsive_set IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $s) foreach (json_decode((string)$s, true) ?: [] as $p) $known[ltrim($p, '/')] = 1;
        $added = 0;
        $ins = $pdo->prepare("INSERT INTO media (file_path, file_type, alt_text, title, uploaded_at) VALUES (?, ?, '', ?, ?)");
        foreach (['uploads', 'files'] as $top) {
            if (!is_dir(ROOT_PATH . "/$top")) continue;
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ROOT_PATH . "/$top", FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (!$file->isFile()) continue;
                $rel = ltrim(str_replace(ROOT_PATH, '', $file->getPathname()), '/');
                $ext = strtolower($file->getExtension());
                if (isset($known[$rel]) || !in_array($ext, ['webp', 'jpg', 'jpeg', 'png', 'gif', 'avif', 'svg', 'pdf', 'docx', 'xlsx', 'pptx', 'zip', 'mp3', 'mp4'], true)) continue;
                if (preg_match('#-(480|768|1024|1280)\.\w+$#', $rel) || str_contains($rel, '/imported/')) continue;
                $type = $ext === 'pdf' ? 'pdf' : (in_array($ext, ['mp3'], true) ? 'audio' : ($ext === 'mp4' ? 'video' : (in_array($ext, ['docx', 'xlsx', 'pptx', 'zip'], true) ? 'document' : 'image')));
                $ins->execute([$rel, $type, ucfirst(trim(preg_replace('/[-_]+/', ' ', pathinfo($rel, PATHINFO_FILENAME)))), date('Y-m-d H:i:s', $file->getMTime())]);
                $known[$rel] = 1;
                $added++;
                if ($added >= 2000) break 2;
            }
        }
        admin_log('media_upload', "File Manager scan added $added file(s)");
        fm_json(['ok' => true, 'added' => $added]);
    }
    fm_json(['ok' => false, 'error' => 'Unknown action.']);
}
$users = $pdo->query("SELECT DISTINCT u.id, u.username FROM media m JOIN users u ON u.id = m.uploaded_by ORDER BY u.username")->fetchAll(PDO::FETCH_KEY_PAIR);
$esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>File Manager - <?= $esc(edu_site_name()) ?></title>
<?php admin_shell_head(); ?>
<style>
.fx-stats{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px}
.fx-stat{background:#fff;border:1px solid var(--gray-200);border-radius:10px;padding:9px 14px;font-size:.82rem;color:var(--gray-600)}.fx-stat b{color:var(--primary);font-size:1rem;margin-left:4px}
.fx-drop{display:block;border:2px dashed var(--gray-300,#d1d5db);border-radius:14px;background:#fff;padding:28px 16px;text-align:center;color:var(--gray-600);transition:.15s;margin-bottom:16px;cursor:pointer}
.fx-drop i{font-size:2rem;color:var(--primary);display:block;margin-bottom:8px}.fx-drop u{color:var(--primary)}.fx-drop small{display:block;color:var(--gray-400);margin-top:4px;font-size:.78rem}
.fx-drop.over{border-color:var(--primary);background:var(--primary-lighter,#f5f3ff)}
.fx-queue{display:flex;flex-direction:column;gap:6px;margin:-6px 0 16px}
.fx-q{display:flex;align-items:center;gap:10px;background:#fff;border:1px solid var(--gray-200);border-radius:9px;padding:7px 12px;font-size:.8rem}
.fx-q span{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.fx-q .bar{width:120px;height:6px;background:var(--gray-100);border-radius:9px;overflow:hidden}.fx-q .bar i{display:block;height:100%;background:var(--primary);width:0;transition:width .2s}
.fx-q.ok .bar i{background:#10b981}.fx-q.err{color:var(--danger)}
.fx-tools{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px}
.fx-search{position:relative;flex:1;min-width:200px;max-width:320px}.fx-search i{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--gray-400);font-size:13px}.fx-search input{padding-left:32px}
.fx-tools select{width:auto}.fx-right{margin-left:auto;display:flex;gap:8px;flex-wrap:wrap}
.fx-tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px}
.fx-tabs button{border:1px solid var(--gray-200);background:#fff;border-radius:99px;padding:6px 14px;font:inherit;font-size:.8125rem;font-weight:600;color:var(--gray-700);cursor:pointer;display:inline-flex;gap:6px;align-items:center}
.fx-tabs button em{font-style:normal;font-size:.7rem;background:var(--gray-100);border-radius:99px;padding:1px 7px;color:var(--gray-600)}
.fx-tabs button.on{background:var(--primary);border-color:var(--primary);color:#fff}.fx-tabs button.on em{background:rgba(255,255,255,.25);color:#fff}
.fx-bulk{display:none;align-items:center;gap:10px;background:var(--primary-lighter,#f5f3ff);border:1px solid var(--primary-light,#ede9fe);border-radius:10px;padding:8px 12px;margin-bottom:12px;font-size:.85rem}
.fx-bulk.show{display:flex}.fx-bulk b{margin-right:auto}
.fx-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px}
.fx-item{background:#fff;border:1px solid var(--gray-200);border-radius:12px;overflow:hidden;cursor:pointer;position:relative;transition:.15s;text-align:left;padding:0;font:inherit}
.fx-item:hover{border-color:var(--primary);box-shadow:0 4px 14px rgba(124,58,237,.12)}
.fx-item.sel{border-color:var(--primary);box-shadow:0 0 0 2px var(--primary)}
.fx-thumb{aspect-ratio:4/3;background:var(--gray-50,#f9fafb);display:grid;place-items:center;overflow:hidden}
.fx-thumb img{width:100%;height:100%;object-fit:cover;display:block}
.fx-thumb .ic{font-size:2.4rem}
.fx-info{padding:8px 10px}.fx-info b{display:block;font-size:.78rem;font-weight:600;color:var(--gray-800);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.fx-info small{font-size:.68rem;color:var(--gray-400);text-transform:uppercase;letter-spacing:.03em}
.fx-chk{position:absolute;top:8px;left:8px;width:22px;height:22px;border-radius:6px;background:#fff;border:2px solid var(--gray-300);display:none;place-items:center;color:#fff;font-size:12px}
.fx-selecting .fx-chk{display:grid}.fx-item.sel .fx-chk{background:var(--primary);border-color:var(--primary)}
.fx-empty{grid-column:1/-1;text-align:center;padding:50px 16px;color:var(--gray-500);background:#fff;border:1px solid var(--gray-200);border-radius:12px}.fx-empty i{font-size:2.4rem;display:block;margin-bottom:10px;color:var(--gray-300)}
.fx-pager{display:flex;justify-content:center;align-items:center;gap:12px;margin-top:18px;font-size:.85rem;color:var(--gray-600)}
.fx-modal .modal{max-width:980px}
.fx-mbody{display:grid;grid-template-columns:1.2fr 1fr;gap:20px}
.fx-prev{background:var(--gray-50,#f9fafb) repeating-conic-gradient(#f3f4f6 0 25%,#fff 0 50%) 50%/20px 20px;border:1px solid var(--gray-200);border-radius:12px;display:grid;place-items:center;min-height:280px;overflow:hidden}
.fx-prev img,.fx-prev video{max-width:100%;max-height:440px;display:block}.fx-prev iframe{width:100%;height:440px;border:0}
.fx-meta{font-size:.8rem;color:var(--gray-600);margin-top:10px;display:grid;gap:4px}.fx-meta b{color:var(--gray-800);font-weight:600}
.fx-use{margin-top:10px;font-size:.8rem;background:var(--gray-50,#f9fafb);border-radius:10px;padding:10px 12px}.fx-use ul{margin:4px 0 0;padding-left:18px}.fx-use a{color:var(--primary)}
.fx-fields .form-group{margin-bottom:12px}.fx-fields label{font-size:.8rem}
.fx-url{display:flex;gap:6px}.fx-url input{font:12px ui-monospace,Menlo,monospace}
@media (max-width:800px){.fx-mbody{grid-template-columns:1fr}.fx-right{margin-left:0}.fx-grid{grid-template-columns:repeat(2,1fr)}}
</style>
</head>
<body>
<?php admin_shell_open('File Manager', 'Upload and manage every file used on the site'); ?>
<?= admin_flash() ?>
<div class="fx-stats" id="fxStats"></div>
<div class="fx-drop" id="fxDrop" role="button" tabindex="0"><i class="fas fa-cloud-arrow-up"></i>Drag &amp; drop files here or <u>browse to upload</u><small>Images (made WebP automatically), PDF, Word, Excel, PowerPoint, ZIP, MP3, MP4 · the file name comes from the file's title</small><input type="file" id="fxFile" multiple hidden accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.csv,.mp3,.m4a,.mp4,.webm"></div>
<div class="fx-queue" id="fxQueue"></div>
<div class="fx-tools">
  <div class="fx-search"><i class="fas fa-search"></i><input type="search" class="form-control" id="fxQ" placeholder="Search by name, title or alt text…"></div>
  <?php if ($users): ?><select class="form-control" id="fxBy"><option value="">All users</option><?php foreach ($users as $id => $n): ?><option value="<?= (int)$id ?>"><?= $esc($n) ?></option><?php endforeach; ?></select><?php endif; ?>
  <select class="form-control" id="fxPer"><option value="24">24 per page</option><option value="48" selected>48 per page</option><option value="96">96 per page</option></select>
  <div class="fx-right">
    <?php if ($me['role'] === 'admin'): ?><button type="button" class="btn btn-secondary" id="fxScan" title="Add files that are in the uploads folder but not listed here yet"><i class="fas fa-folder-tree"></i> Find files</button><?php endif; ?>
    <?php if ($canDelete): ?><button type="button" class="btn btn-secondary" id="fxSelect"><i class="fas fa-square-check"></i> Select</button><?php endif; ?>
    <button type="button" class="btn btn-primary" onclick="document.getElementById('fxFile').click()"><i class="fas fa-plus"></i> Upload Files</button>
  </div>
</div>
<div class="fx-tabs" id="fxTabs"></div>
<div class="fx-bulk" id="fxBulk"><b id="fxBulkN">0 selected</b><button type="button" class="btn btn-secondary btn-sm" id="fxSelAll">Select all on page</button><button type="button" class="btn btn-danger btn-sm" id="fxDel"><i class="fas fa-trash"></i> Delete</button></div>
<div class="fx-grid" id="fxGrid"></div>
<div class="fx-pager" id="fxPager"></div>
<?php if ($canNotes): ?><div id="fxNotes" hidden></div>
<link rel="stylesheet" href="/assets/css/pdf-media.css?v=<?= @filemtime(ROOT_PATH . '/assets/css/pdf-media.css') ?: 1 ?>">
<script>window.FX_NOTES = true; window.PM_CFG = { csrf: <?= json_encode(csrf_token()) ?> };</script>
<script src="/assets/js/pdf-media.js?v=<?= @filemtime(ROOT_PATH . '/assets/js/pdf-media.js') ?: 1 ?>"></script><?php endif; ?>

<div class="modal-overlay fx-modal" id="fxModal"><div class="modal">
  <div class="modal-header"><div class="modal-title" id="fxMTitle" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"></div><button type="button" class="modal-close" data-close><i class="fas fa-times"></i></button></div>
  <div class="modal-body"><div class="fx-mbody">
    <div><div class="fx-prev" id="fxPrev"></div><div class="fx-meta" id="fxMeta"></div><div class="fx-use" id="fxUse"></div></div>
    <form class="fx-fields" id="fxForm">
      <input type="hidden" name="id">
      <div class="form-group" id="fxAltRow"><label>Alt text <small style="font-weight:400;color:var(--gray-400)">describes the image for Google &amp; screen readers</small></label><input class="form-control" name="alt" maxlength="200"></div>
      <div class="form-group"><label>Title</label><input class="form-control" name="title" maxlength="255"></div>
      <div class="form-group"><label>Caption</label><textarea class="form-control" name="caption" rows="2"></textarea></div>
      <div class="form-group"><label>Description</label><textarea class="form-control" name="description" rows="3"></textarea></div>
      <div class="form-group"><label>File address</label><div class="fx-url"><input class="form-control" id="fxUrl" readonly><button type="button" class="btn btn-secondary" data-copy="fxUrl" title="Copy"><i class="fas fa-copy"></i></button></div></div>
      <div class="form-group" id="fxDocRow" hidden><label>Document page (PDF viewer)</label><div class="fx-url"><input class="form-control" id="fxDoc" readonly><button type="button" class="btn btn-secondary" data-copy="fxDoc" title="Copy"><i class="fas fa-copy"></i></button></div></div>
    </form>
  </div></div>
  <div class="modal-footer"><?php if ($canDelete): ?><button type="button" class="btn btn-danger" id="fxMDel" style="margin-right:auto"><i class="fas fa-trash"></i> Delete</button><?php endif; ?><a class="btn btn-secondary" id="fxOpen" target="_blank" rel="noopener"><i class="fas fa-arrow-up-right-from-square"></i> Open</a><button type="button" class="btn btn-primary" id="fxSave"><i class="fas fa-save"></i> Save</button></div>
</div></div>
<?php admin_shell_close(); ?>
<script>
(function () {
  var token = <?= json_encode(csrf_token()) ?>, site = <?= json_encode(rtrim(SITE_URL, '/')) ?>;
  var st = { type: /[?&]tab=notes\b/.test(location.search) && window.FX_NOTES ? 'notes' : '', page: 1, items: [], selecting: false, sel: new Set(), cur: null };
  var $ = function (id) { return document.getElementById(id); };
  var ICON = { pdf: '<i class="fas fa-file-pdf ic" style="color:#dc2626"></i>', video: '<i class="fas fa-file-video ic" style="color:#7c3aed"></i>', audio: '<i class="fas fa-file-audio ic" style="color:#0891b2"></i>', document: '<i class="fas fa-file-lines ic" style="color:#2563eb"></i>' };
  function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : t; return d.innerHTML; }
  function post(d) { var fd = new FormData(); fd.append('_csrf', token); Object.keys(d).forEach(function (k) { [].concat(d[k]).forEach(function (v) { fd.append(Array.isArray(d[k]) ? k + '[]' : k, v); }); }); return fetch('/admin/file-manager.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }); }
  function tabs(c) {
    var list = [['', 'All Files', c.total], ['image', 'Images', c.images], ['pdf', 'PDFs', c.pdfs], ['document', 'Documents', c.documents], ['media', 'Audio & Video', c.media]].filter(function (t) { return t[0] === '' || t[0] === st.type || t[2]; });
    if (window.FX_NOTES) list.push(['notes', 'Course notes (PDF)', '']);
    $('fxTabs').innerHTML = list.map(function (t) { return '<button type="button" data-type="' + t[0] + '" class="' + (st.type === t[0] ? 'on' : '') + '">' + (t[0] === 'notes' ? '<i class="fas fa-file-pdf"></i> ' : '') + esc(t[1]) + (t[2] !== '' ? ' <em>' + t[2] + '</em>' : '') + '</button>'; }).join('');
  }
  var notesLib = null;
  function showNotes(on) { // the PDF Media library of the online store: notes PDFs that lectures use (private, each with a title)
    ['fxDrop', 'fxGrid', 'fxPager', 'fxBulk', 'fxQueue'].forEach(function (id) { var el = $(id); if (el) el.hidden = on; });
    var tl = document.querySelector('.fx-tools'); if (tl) tl.hidden = on;
    $('fxNotes').hidden = !on;
    if (on && !notesLib && window.PdfMedia) notesLib = PdfMedia.mount($('fxNotes'), { ownUpload: true });
  }
  function load(page) {
    if (st.type === 'notes') { tabs(st.c || { total: 0 }); showNotes(true); return; }
    if (window.FX_NOTES) showNotes(false);
    st.page = page || 1;
    var qs = new URLSearchParams({ ajax: 'list', type: st.type, q: $('fxQ').value.trim(), per: $('fxPer').value, page: st.page, by: $('fxBy') ? $('fxBy').value : '' });
    $('fxGrid').innerHTML = '<div class="fx-empty"><i class="fas fa-spinner fa-spin"></i>Loading…</div>';
    fetch('/admin/file-manager.php?' + qs, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      st.items = d.items; var c = d.counts;
      $('fxStats').innerHTML = '<span class="fx-stat">Total Files:<b>' + c.total + '</b></span><span class="fx-stat">Images:<b>' + c.images + '</b></span><span class="fx-stat">PDFs:<b>' + c.pdfs + '</b></span>' + (c.documents ? '<span class="fx-stat">Documents:<b>' + c.documents + '</b></span>' : '') + (c.media ? '<span class="fx-stat">Audio &amp; Video:<b>' + c.media + '</b></span>' : '');
      st.c = c; tabs(c);
      $('fxGrid').innerHTML = d.items.length ? d.items.map(function (it, i) {
        return '<button type="button" class="fx-item' + (st.sel.has(it.id) ? ' sel' : '') + '" data-i="' + i + '"><span class="fx-chk"><i class="fas fa-check"></i></span><div class="fx-thumb">' + (it.kind === 'image' ? '<img loading="lazy" src="' + esc(it.thumb) + '" alt="" onerror="this.outerHTML=\'<i class=&quot;fas fa-file-image ic&quot; style=&quot;color:#cbd5e1&quot; title=&quot;File missing&quot;></i>\'">' : ICON[it.kind] || ICON.document) + '</div><div class="fx-info"><b title="' + esc(it.name) + '">' + esc(it.title || it.name) + '</b><small>' + esc(it.ext) + ' · ' + esc(it.kind) + '</small></div></button>';
      }).join('') : '<div class="fx-empty"><i class="fas fa-folder-open"></i>' + ($('fxQ').value.trim() ? 'No files match your search.' : 'No files here yet — drop some above.') + '</div>';
      $('fxPager').innerHTML = d.pages > 1 ? '<button type="button" class="btn btn-secondary btn-sm" data-p="' + (d.page - 1) + '"' + (d.page <= 1 ? ' disabled' : '') + '>« Prev</button><span>Page ' + d.page + ' of ' + d.pages + ' · ' + d.total + ' files</span><button type="button" class="btn btn-secondary btn-sm" data-p="' + (d.page + 1) + '"' + (d.page >= d.pages ? ' disabled' : '') + '>Next »</button>' : (d.total ? '<span>' + d.total + ' file' + (d.total === 1 ? '' : 's') + '</span>' : '');
    });
  }
  $('fxTabs').addEventListener('click', function (e) { var b = e.target.closest('[data-type]'); if (b) { st.type = b.dataset.type; load(1); } });
  $('fxPager').addEventListener('click', function (e) { var b = e.target.closest('[data-p]'); if (b && !b.disabled) load(+b.dataset.p); });
  var t; $('fxQ').addEventListener('input', function () { clearTimeout(t); t = setTimeout(function () { load(1); }, 300); });
  $('fxPer').addEventListener('change', function () { load(1); }); if ($('fxBy')) $('fxBy').addEventListener('change', function () { load(1); });

  // Upload (several at once, one after another, with progress)
  function upload(files) {
    var list = Array.prototype.slice.call(files); if (!list.length) return;
    (function next() {
      var f = list.shift(); if (!f) { load(1); return; }
      var row = document.createElement('div'); row.className = 'fx-q'; row.innerHTML = '<i class="fas fa-file"></i><span>' + esc(f.name) + '</span><div class="bar"><i></i></div><small>0%</small>';
      $('fxQueue').prepend(row);
      var fd = new FormData(); fd.append('file', f);
      var x = new XMLHttpRequest(); x.open('POST', '/admin/api/media-upload.php');
      x.upload.onprogress = function (e) { if (e.lengthComputable) { var p = Math.round(e.loaded / e.total * 100); row.querySelector('.bar i').style.width = p + '%'; row.querySelector('small').textContent = p + '%'; } };
      x.onload = function () {
        var d = {}; try { d = JSON.parse(x.responseText); } catch (e) { d = { error: 'Upload failed (server error).' }; }
        if (d.error) { row.classList.add('err'); row.querySelector('small').textContent = d.error; }
        else { row.classList.add('ok'); row.querySelector('small').innerHTML = '<i class="fas fa-check" style="color:#10b981"></i> ' + esc(d.path.split('/').pop()); setTimeout(function () { row.remove(); }, 4000); }
        next();
      };
      x.onerror = function () { row.classList.add('err'); row.querySelector('small').textContent = 'Network error'; next(); };
      x.send(fd);
    })();
  }
  $('fxFile').addEventListener('change', function () { upload(this.files); this.value = ''; });
  var drop = $('fxDrop');
  ['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('over'); }); });
  ['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('over'); }); });
  drop.addEventListener('drop', function (e) { upload(e.dataTransfer.files); });
  drop.addEventListener('click', function (e) { if (e.target.id !== 'fxFile') $('fxFile').click(); });
  drop.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); $('fxFile').click(); } });

  // Select mode + delete
  function syncBulk() { $('fxBulk').classList.toggle('show', st.selecting); $('fxBulkN').textContent = st.sel.size + ' selected'; $('fxDel').disabled = !st.sel.size; }
  if ($('fxSelect')) $('fxSelect').onclick = function () { st.selecting = !st.selecting; st.sel.clear(); $('fxGrid').classList.toggle('fx-selecting', st.selecting); this.innerHTML = st.selecting ? '<i class="fas fa-xmark"></i> Cancel' : '<i class="fas fa-square-check"></i> Select'; document.querySelectorAll('.fx-item.sel').forEach(function (e) { e.classList.remove('sel'); }); syncBulk(); };
  $('fxSelAll').onclick = function () { st.items.forEach(function (it) { st.sel.add(it.id); }); document.querySelectorAll('.fx-item').forEach(function (e) { e.classList.add('sel'); }); syncBulk(); };
  function del(ids, done) {
    adminConfirm(ids.length + ' file(s) will be deleted permanently from the server. Posts or pages that show them will lose the image.', { title: 'Delete file' + (ids.length > 1 ? 's' : '') + '?', confirmText: 'Delete' }).then(function (ok) {
      if (!ok) return;
      post({ action: 'delete', ids: ids }).then(function (d) { if (!d.ok) return adminNotice(d.error || 'Could not delete.', { type: 'error' }); st.sel.clear(); syncBulk(); if (done) done(); load(st.page); adminNotice(d.deleted + ' file(s) deleted.', { type: 'success', autoClose: 2000 }); });
    });
  }
  $('fxDel').onclick = function () { if (st.sel.size) del(Array.from(st.sel)); };
  if ($('fxScan')) $('fxScan').onclick = function () {
    var b = this; b.disabled = true; b.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Looking…';
    post({ action: 'scan' }).then(function (d) { b.disabled = false; b.innerHTML = '<i class="fas fa-folder-tree"></i> Find files'; adminNotice(d.added ? d.added + ' file(s) from the uploads folder were added to the library.' : 'Every file in the uploads folder is already listed.', { type: 'success' }); load(1); });
  };

  // Details popup
  var m = $('fxModal'), f = $('fxForm'); document.body.appendChild(m);
  function close() { m.classList.remove('open'); document.body.style.overflow = ''; }
  m.addEventListener('click', function (e) { if (e.target === m || e.target.closest('[data-close]')) close(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && m.classList.contains('open')) close(); });
  $('fxGrid').addEventListener('click', function (e) {
    var b = e.target.closest('.fx-item'); if (!b) return;
    var it = st.items[+b.dataset.i];
    if (st.selecting) { if (st.sel.has(it.id)) st.sel.delete(it.id); else st.sel.add(it.id); b.classList.toggle('sel'); return syncBulk(); }
    st.cur = it;
    $('fxMTitle').textContent = it.name;
    $('fxPrev').innerHTML = it.kind === 'image' ? '<img src="' + esc(it.path) + '" alt="">' : it.kind === 'video' ? '<video src="' + esc(it.path) + '" controls></video>' : it.kind === 'audio' ? '<audio src="' + esc(it.path) + '" controls></audio>' : it.kind === 'pdf' ? '<iframe src="' + esc(it.path) + '#view=FitH" title="PDF preview"></iframe>' : ICON.document;
    f.id.value = it.id; f.alt.value = it.alt; f.title.value = it.title; f.caption.value = it.caption; f.description.value = it.description;
    $('fxAltRow').hidden = it.kind !== 'image';
    $('fxUrl').value = site + it.path; $('fxOpen').href = it.path;
    $('fxMeta').innerHTML = '<span><b>Uploaded:</b> ' + esc(it.date || '—') + (it.by ? ' by ' + esc(it.by) : '') + '</span><span id="fxSize"><b>Size:</b> …</span>';
    $('fxUse').innerHTML = '<i class="fas fa-spinner fa-spin"></i> Checking where this file is used…'; $('fxDocRow').hidden = true;
    m.classList.add('open'); document.body.style.overflow = 'hidden';
    fetch('/admin/file-manager.php?ajax=info&id=' + it.id, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      if (d.error) return;
      $('fxSize').innerHTML = '<b>Size:</b> ' + esc(d.size) + (d.dims ? ' · ' + esc(d.dims) : '');
      if (d.doc) { $('fxDocRow').hidden = false; $('fxDoc').value = d.doc; }
      var u = d.usage, parts = [];
      u.posts.forEach(function (p) { parts.push('<li>Post: <a href="/admin/post-manager.php?id=' + p.id + '" target="_blank">' + esc(p.title) + '</a></li>'); });
      u.settings.forEach(function (s) { parts.push('<li>Settings: ' + esc(s) + '</li>'); });
      u.authors.forEach(function (a) { parts.push('<li>Author photo: ' + esc(a) + '</li>'); });
      $('fxUse').innerHTML = parts.length ? '<b><i class="fas fa-link"></i> Used in</b><ul>' + parts.join('') + '</ul>' : '<i class="fas fa-circle-info"></i> Not used in any post, page setting or author profile.';
    });
  });
  $('fxSave').onclick = function () {
    var b = this; b.disabled = true;
    post({ action: 'save', id: f.id.value, alt: f.alt.value, title: f.title.value, caption: f.caption.value, description: f.description.value }).then(function (d) {
      b.disabled = false; if (!d.ok) return adminNotice(d.error || 'Could not save.', { type: 'error' });
      st.cur.alt = f.alt.value; st.cur.title = f.title.value; st.cur.caption = f.caption.value; st.cur.description = f.description.value;
      close(); load(st.page); adminNotice('File details saved.', { type: 'success', autoClose: 1800 });
    });
  };
  if ($('fxMDel')) $('fxMDel').onclick = function () { del([st.cur.id], close); };
  document.addEventListener('click', function (e) { var c = e.target.closest('[data-copy]'); if (!c) return; var i = $(c.dataset.copy); i.select(); (navigator.clipboard ? navigator.clipboard.writeText(i.value) : Promise.resolve(document.execCommand('copy'))).then(function () { c.innerHTML = '<i class="fas fa-check"></i>'; setTimeout(function () { c.innerHTML = '<i class="fas fa-copy"></i>'; }, 1500); }); });
  load(1);
})();
</script>
</body>
</html>
