<?php
// 404 page. Also the fallback router for the clean store addresses (/courses/…, /notes/…, /learn/…, /free-courses/…,
// /category/…): until LiteSpeed reloads the .htaccess rewrite rules, those addresses arrive here (ErrorDocument 404).
// (Reached as the ErrorDocument, or included by page.php / any page that did not find its item. EDU_NF_ROUTED stops
// a second pass when the store page itself then reports "not found".)
if (!defined('EDU_NF_ROUTED')) {
    define('EDU_NF_ROUTED', 1);
    // the address the visitor asked for: REQUEST_URI, or (as an ErrorDocument) one of the REDIRECT_* values
    $__u = '';
    foreach (['REQUEST_URI', 'REDIRECT_URL', 'REDIRECT_REQUEST_URI', 'REDIRECT_SCRIPT_URL', 'SCRIPT_URL'] as $__k) {
        $__v = (string)($_SERVER[$__k] ?? '');
        if (preg_match('#^/(courses|notes|learn|free-courses|category|upcoming-courses|all-categories)(/|$)#', $__v)) { $__u = $__v; break; }
    }
    if ($__u !== '' && !str_contains($__u, '?') && !empty($_SERVER['REDIRECT_QUERY_STRING'])) $__u .= '?' . $_SERVER['REDIRECT_QUERY_STRING'];
    $__path = (string)parse_url($__u, PHP_URL_PATH);
    if ($__u !== '') $_SERVER['REQUEST_URI'] = $__u; // the store pages read it (canonical, redirects)
    $__map = [
        '#^/courses/?$#' => [fn($q) => isset($q['s']) ? 'store/item.php' : 'store/list.php', fn($m) => ['m' => 'courses']],
        '#^/upcoming-courses/?$#' => ['store/list.php', fn($m) => ['m' => 'upcoming']],
        '#^/all-categories/?$#' => ['store/list.php', fn($m) => ['m' => 'cats']],
        '#^/courses/([a-zA-Z0-9-]+)/?$#' => ['store/item.php', fn($m) => ['s' => $m[1]]],
        '#^/notes/?$#' => [fn($q) => isset($q['s']) ? 'store/item.php' : 'store/list.php', fn($m) => ['m' => 'notes']],
        '#^/notes/([a-zA-Z0-9-]+)/?$#' => ['store/item.php', fn($m) => ['s' => $m[1]]],
        '#^/category/([a-zA-Z0-9-]+)/?$#' => ['store/index.php', fn($m) => ['c' => $m[1]]],
        '#^/learn/?$#' => ['store/learn.php', fn($m) => []],
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
        while (ob_get_level() > 0) @ob_end_clean(); // drop anything the first script buffered
        if ($__file instanceof Closure) $__file = $__file($_GET);
        $__abs = dirname(__DIR__) . '/' . $__file;
        $_SERVER['SCRIPT_FILENAME'] = $__abs;
        chdir(dirname($__abs));
        require $__abs;
        exit;
    }
}
$nf_kind = 'page'; require __DIR__ . '/not_found.php';
