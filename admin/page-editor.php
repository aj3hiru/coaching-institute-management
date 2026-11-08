<?php
// Add / Edit Page — same editor look as posts (title + permalink, content with toolbar and HTML tab, publish box, SEO box).
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/pages.php';
$me = admin_guard(['admin']) /* site pages / messages: not part of the blog roles */;
edu_pages_table($pdo);

$id = (int)($_GET['id'] ?? 0);
$page = ['title' => '', 'slug' => '', 'content' => '', 'meta_description' => '', 'seo_title' => '', 'noindex' => 0, 'template' => 'default', 'status' => 'published'];
if ($id) {
    $st = $pdo->prepare("SELECT * FROM site_pages WHERE id = ?");
    $st->execute([$id]);
    $page = $st->fetch(PDO::FETCH_ASSOC) ?: exit('Page not found');
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 200);
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string)($_POST['slug'] ?: $title))), '-');
    $slug = substr($slug, 0, 160);
    $content = (string)($_POST['content'] ?? '');
    $content = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $content); // no scripts in page content
    $data = [
        'title' => $title, 'slug' => $slug, 'content' => $content,
        'meta_description' => mb_substr(trim((string)($_POST['meta_description'] ?? '')), 0, 300),
        'seo_title' => mb_substr(trim((string)($_POST['seo_title'] ?? '')), 0, 200),
        'noindex' => !empty($_POST['noindex']) ? 1 : 0,
        'template' => ($_POST['template'] ?? '') === 'full' ? 'full' : 'default',
        'status' => ($_POST['status'] ?? '') === 'draft' ? 'draft' : 'published',
    ];
    $dupe = $pdo->prepare("SELECT COUNT(*) FROM site_pages WHERE slug = ? AND id <> ?");
    $dupe->execute([$slug, $id]);
    if ($title === '') $error = 'Please add a title.';
    elseif ($slug === '') $error = 'Please add an address (permalink).';
    elseif (edu_page_slug_taken($slug)) $error = 'The address “/' . htmlspecialchars($slug) . '” is already used by the site. Please choose another one.';
    elseif ($dupe->fetchColumn()) $error = 'Another page already uses “/' . htmlspecialchars($slug) . '”.';
    if ($error) {
        $page = $data + $page;
    } else {
        if ($id) {
            $pdo->prepare("UPDATE site_pages SET title=?, slug=?, content=?, meta_description=?, seo_title=?, noindex=?, template=?, status=? WHERE id=?")
                ->execute([$data['title'], $data['slug'], $data['content'], $data['meta_description'], $data['seo_title'], $data['noindex'], $data['template'], $data['status'], $id]);
        } else {
            $pdo->prepare("INSERT INTO site_pages (title, slug, content, meta_description, seo_title, noindex, template, status, author_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$data['title'], $data['slug'], $data['content'], $data['meta_description'], $data['seo_title'], $data['noindex'], $data['template'], $data['status'], $me['id']]);
            $id = (int)$pdo->lastInsertId();
        }
        if (!empty($page['slug']) && $page['slug'] !== $slug) edu_page_stub_remove($page['slug']);
        edu_page_stub_write($slug);
        admin_log('page_save', ($data['status'] === 'published' ? 'Published' : 'Saved draft') . ' page: ' . mb_strimwidth($title, 0, 60, '…'));
        admin_flash('Page saved. ' . ($data['status'] === 'published' ? 'It is live at /' . $slug : 'It is a draft (not visible to visitors).'));
        header('Location: /admin/page-editor.php?id=' . $id);
        exit;
    }
}
$host = preg_replace('#^https?://#', '', rtrim(SITE_URL, '/'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title><?= $id ? 'Edit Page' : 'New Page' ?> - <?= htmlspecialchars(edu_site_name()) ?></title>
<?php admin_shell_head(); ?>
<link href="/assets/css/post-editor.css?v=<?= @filemtime(ROOT_PATH . '/assets/css/post-editor.css') ?>" rel="stylesheet">
<?php $tinymce_src = defined('TINYMCE_API_KEY') && TINYMCE_API_KEY !== '' ? 'https://cdn.tiny.cloud/1/' . rawurlencode(TINYMCE_API_KEY) . '/tinymce/7/tinymce.min.js' : 'https://cdn.jsdelivr.net/npm/tinymce@7.6.1/tinymce.min.js'; ?>
<script src="<?= htmlspecialchars($tinymce_src) ?>" referrerpolicy="origin" crossorigin="anonymous"></script>
<style>#pg-visual .tox-tinymce{border:0;border-radius:0}#pg-visual .tox .tox-editor-header{position:sticky;top:36px;z-index:20;background:#f6f7f7;border-bottom:1px solid #dcdcde}#pg-visual .tox .tox-promotion,#pg-visual .tox .tox-statusbar__branding{display:none}</style>
<script src="/assets/js/admin-media.js?v=<?= @filemtime(ROOT_PATH . '/assets/js/admin-media.js') ?>" defer></script>
</head>
<body>
<?php admin_shell_open($id ? 'Edit Page' : 'New Page', $id ? 'Update this page' : 'Create a new page', $id && $page['status'] === 'published' ? '<a href="/' . htmlspecialchars($page['slug']) . '" target="_blank" class="btn btn-secondary btn-sm"><i class="fas fa-external-link-alt"></i> View Page</a>' : ''); ?>
<?= admin_flash() ?>
<?php if ($error): ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= $error ?></div><?php endif; ?>
<form method="POST" id="page-form" class="pe-form">
  <?= csrf_field() ?>
  <div class="editor-layout">
    <div class="editor-main">
      <div class="meta-panel"><div class="meta-panel-body" style="gap:.5rem">
        <input type="text" id="title" name="title" class="post-title-input" placeholder="Add title" required value="<?= htmlspecialchars($page['title']) ?>">
        <div class="permalink-row"><strong>Permalink:</strong><span class="permalink-base"><?= htmlspecialchars($host) ?>/</span><input type="text" id="slug" name="slug" class="permalink-input" placeholder="page-address" maxlength="160" value="<?= htmlspecialchars($page['slug']) ?>"></div>
      </div></div>
      <div class="meta-panel"><div class="meta-panel-body" style="padding:0;gap:0"><div class="be-wrap rte-wrap">
        <div class="be-tabs"><span class="be-tabs-label"><i class="fas fa-pen-to-square"></i> Content</span><div class="be-mode"><button type="button" class="is-active" data-mode="visual">Visual</button><button type="button" data-mode="html">HTML</button></div></div>
        <div id="pg-visual"><textarea id="editor"><?= htmlspecialchars((string)$page['content']) ?></textarea></div>
        <textarea id="html-source" class="pe-html" spellcheck="false" hidden></textarea>
        <div class="be-status" id="be-status">0 words</div>
        <input type="hidden" name="content" id="content">
      </div></div></div>
    </div>
    <div class="editor-sidebar-panel">
      <div class="meta-panel"><div class="meta-panel-header"><span>Publish</span></div><div class="meta-panel-body">
        <label class="seo-label">Status<select name="status"><option value="published" <?= $page['status'] === 'published' ? 'selected' : '' ?>>Published</option><option value="draft" <?= $page['status'] === 'draft' ? 'selected' : '' ?>>Draft</option></select></label>
        <label class="seo-label">Width<select name="template"><option value="default" <?= $page['template'] === 'default' ? 'selected' : '' ?>>Reading width</option><option value="full" <?= $page['template'] === 'full' ? 'selected' : '' ?>>Full width</option></select></label>
        <div class="publish-actions"><button type="submit" class="btn btn-primary" style="width:100%;border-radius:4px"><i class="fas fa-upload"></i> <?= $id ? 'Update Page' : 'Publish Page' ?></button></div>
      </div></div>
      <div class="meta-panel seo-box"><div class="meta-panel-header"><span><i class="fas fa-magnifying-glass-chart"></i> SEO</span></div><div class="meta-panel-body">
        <label class="seo-label">SEO title <span class="seo-hint">(empty = automatic)</span><input type="text" name="seo_title" maxlength="200" placeholder="Page title | <?= htmlspecialchars(edu_site_name()) ?>" value="<?= htmlspecialchars($page['seo_title'] ?? '') ?>"></label>
        <label class="seo-label">Meta description<textarea name="meta_description" rows="3" maxlength="300" placeholder="What the page is about, in 120–160 characters."><?= htmlspecialchars($page['meta_description'] ?? '') ?></textarea></label>
        <label class="seo-check"><input type="checkbox" name="noindex" value="1" <?= !empty($page['noindex']) ? 'checked' : '' ?>> No Index — hide this page from Google</label>
      </div></div>
    </div>
  </div>
</form>
<?php admin_shell_close(); ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var $ = function (id) { return document.getElementById(id); };
  var ed = null, mode = 'visual';
  var html = function () { return mode === 'html' ? $('html-source').value : (ed ? ed.getContent() : ''); };
  function words() { var t = html().replace(/<[^>]+>/g, ' ').replace(/&nbsp;/g, ' ').trim(); $('be-status').textContent = (t ? t.split(/\s+/).length : 0) + ' words'; }
  tinymce.init({
    selector: '#editor',
<?php if (!(defined('TINYMCE_API_KEY') && TINYMCE_API_KEY !== '')): ?>    license_key: 'gpl',
<?php endif; ?>    min_height: 520, menubar: false, branding: false, promotion: false, statusbar: true, resize: false,
    plugins: 'advlist autolink lists link image charmap searchreplace visualblocks code fullscreen media table autoresize wordcount',
    toolbar: 'blocks | bold italic underline strikethrough | forecolor backcolor | bullist numlist blockquote | alignleft aligncenter alignright | link mediaimg table | hr removeformat | undo redo | code fullscreen',
    block_formats: 'Paragraph=p; Heading 2=h2; Heading 3=h3; Heading 4=h4',
    table_toolbar: 'tableprops tabledelete | tableinsertrowbefore tableinsertrowafter tabledeleterow | tableinsertcolbefore tableinsertcolafter tabledeletecol | tablerowheader tablecolheader | tablemergecells tablesplitcells',
    table_header_type: 'sectionCells', table_default_styles: { width: '100%' }, entity_encoding: 'raw', convert_urls: false, relative_urls: false,
    content_style: 'body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;font-size:17px;line-height:1.75;color:#373737;padding:20px 28px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #9ca3af;padding:8px}img{max-width:100%;height:auto}',
    setup: function (e) {
      e.ui.registry.addButton('mediaimg', { icon: 'image', tooltip: 'Image from the Media Library', onAction: function () { EduMedia.pick(function (it) { e.insertContent('<img src="' + it.path + '" alt="' + String(it.alt || '').replace(/"/g, '&quot;') + '">'); }); } });
      e.on('init', function () { ed = e; words(); }); e.on('input change undo redo SetContent', words);
    }
  });
  document.querySelectorAll('.be-mode button').forEach(function (b) { b.onclick = function () {
    if (b.dataset.mode === mode || !ed) return;
    if (b.dataset.mode === 'html') { $('html-source').value = ed.getContent(); $('html-source').hidden = false; $('pg-visual').hidden = true; }
    else { ed.setContent($('html-source').value); $('html-source').hidden = true; $('pg-visual').hidden = false; }
    mode = b.dataset.mode; document.querySelectorAll('.be-mode button').forEach(function (x) { x.classList.toggle('is-active', x === b); });
  }; });
  var touched = !!$('slug').value;
  $('slug').addEventListener('input', function () { touched = true; });
  $('title').addEventListener('input', function () { if (!touched) $('slug').value = this.value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 160); });
  $('page-form').addEventListener('submit', function () { $('content').value = html(); });
});
</script>
</body>
</html>
