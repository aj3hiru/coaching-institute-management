<?php
// Front-end admin bar (the site's pages are cached, so the bar is filled in by the browser):
// the WordPress-style toolbar for the signed-in user, incl. "Edit Post" / "Edit Page" for the page being viewed.
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/wpbar.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex');

$ctx = [
    'kind' => preg_replace('/[^a-z]/', '', (string)($_GET['kind'] ?? '')),
    'id' => (int)($_GET['id'] ?? 0),
    'slug' => (string)($_GET['slug'] ?? ''),
];
if ($ctx['kind'] === 'admin') $ctx['kind'] = '';
$html = wpbar_html($ctx);
echo json_encode($html === '' ? ['in' => false] : ['in' => true, 'html' => $html, 'assets' => wpbar_assets()], JSON_UNESCAPED_SLASHES);
