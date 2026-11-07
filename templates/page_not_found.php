<?php
// 404 page. Also the fallback router for the clean store addresses (/courses/…, /notes/…, /learn/…, /free-courses/…,
// /category/…): until LiteSpeed reloads the .htaccess rewrite rules, those addresses arrive here (ErrorDocument 404).
if (!defined('EDU_NF_ROUTED') && basename((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === 'page_not_found.php') {
    define('EDU_NF_ROUTED', 1);
    $__u = (string)($_SERVER['REQUEST_URI'] ?? '');
    $__path = (string)parse_url($__u, PHP_URL_PATH);
    $__map = [
        '#^/courses/?$#' => ['store/index.php', fn($m) => ['t' => 'course']],
        '#^/courses/([a-zA-Z0-9-]+)/?$#' => ['store/item.php', fn($m) => ['s' => $m[1]]],
        '#^/notes/?$#' => ['store/notes.php', fn($m) => []],
        '#^/notes/([a-zA-Z0-9-]+)/?$#' => ['store/item.php', fn($m) => ['s' => $m[1]]],
        '#^/category/([a-zA-Z0-9-]+)/?$#' => ['store/index.php', fn($m) => ['c' => $m[1]]],
        '#^/learn/([a-zA-Z0-9-]+)/?$#' => ['store/learn.php', fn($m) => ['s' => $m[1]]],
        '#^/free-courses/?$#' => ['store/free.php', fn($m) => []],
        '#^/free-courses/([0-9]+)(?:-[a-zA-Z0-9-]*)?/?$#' => ['store/free.php', fn($m) => ['id' => $m[1]]],
    ];
    foreach ($__map as $__re => [$__file, $__get]) {
        if (!preg_match($__re, $__path, $__m)) continue;
        $__q = []; parse_str((string)parse_url($__u, PHP_URL_QUERY), $__q);
        $_GET = $__get($__m) + $__q + $_GET;
        $_SERVER['QUERY_STRING'] = http_build_query($_GET);
        define('SH_VIA_404', 1);
        http_response_code(200);
        $__abs = dirname(__DIR__) . '/' . $__file;
        $_SERVER['SCRIPT_FILENAME'] = $__abs;
        chdir(dirname($__abs));
        require $__abs;
        exit;
    }
}
$nf_kind = 'page'; require __DIR__ . '/not_found.php';
