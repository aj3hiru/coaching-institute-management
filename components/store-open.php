<?php
// Page frame for the online store and buyer account pages: site head, header, <main>. Set $stTitle (and $stDesc, $stIndex) first.
require_once INCLUDES_PATH . '/minify.php';
require_once INCLUDES_PATH . '/functions.php';
require_once INCLUDES_PATH . '/seo.php';
require_once INCLUDES_PATH . '/ads.php';
header('Cache-Control: private, no-store');
$seo = [
    'title' => ($stTitle ?? 'Store') . ' | ' . edu_site_name(),
    'description' => $stDesc ?? ($stTitle ?? 'Courses and notes'),
    'image' => defined('SEO_DEFAULT_IMAGE') ? SEO_DEFAULT_IMAGE : '',
    'type' => 'website',
    'robots' => !empty($stIndex) ? 'index, follow' : 'noindex, nofollow',
];
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
<main class="st">
