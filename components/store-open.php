<?php
// Page frame for the online store and buyer account pages: site head, header, <main>. Set $stTitle (and $stDesc, $stIndex) first.
require_once INCLUDES_PATH . '/minify.php';
ob_start(fn($h) => function_exists('sh_hide_yt') ? sh_hide_yt($h) : $h); // no YouTube ids in store / lecture pages
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/seo.php';
require_once INCLUDES_PATH . '/ads.php';
header('Cache-Control: private, no-store');
$seo = [
    'title' => !empty($stFullTitle) ? $stFullTitle : ($stTitle ?? 'Store') . ' | ' . edu_site_name(), // $stFullTitle: a page's own SEO title
    'description' => $stDesc ?? ($stTitle ?? 'Courses and notes'),
    'image' => !empty($stImage) ? $stImage : (defined('SEO_DEFAULT_IMAGE') ? SEO_DEFAULT_IMAGE : ''),
    'type' => 'website',
    // indexed store pages may show a large picture in Google Search / Discover
    'robots' => !empty($stRobots) ? $stRobots : (!empty($stIndex) ? 'index, follow, max-snippet:-1, max-video-preview:-1, max-image-preview:large' : 'noindex, nofollow'),
];
if (!empty($stOgTitle)) $seo['og_title'] = $stOgTitle;          // what a shared link shows (e.g. a lecture shows its course)
if (!empty($stOgDesc)) $seo['og_description'] = $stOgDesc;
if (!empty($stCanonical)) $seo_canonical = $stCanonical;       // the page Google should count (e.g. a lecture → its course page)
if (empty($stIndex) && !headers_sent()) header('X-Robots-Tag: noindex');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($seo['title'], ENT_QUOTES, 'UTF-8') ?></title>
<?php require_once ROOT_PATH . '/components/head_script.php'; ?>
<link rel="stylesheet" href="/assets/css/store.css?v=<?= @filemtime(ROOT_PATH . '/assets/css/store.css') ?: 1 ?>">
</head>
<body>
<?php require_once ROOT_PATH . '/components/header.php'; ?>
<main class="st<?= !empty($stMain) ? ' ' . htmlspecialchars($stMain, ENT_QUOTES, 'UTF-8') : '' ?>">
