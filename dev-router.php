<?php
// LOCAL DEVELOPMENT ONLY (php -S localhost:8091 dev-router.php) — mirrors the live .htaccess. Never deployed.
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$file = __DIR__ . $uri;
if ($uri !== '/' && is_file($file) && !str_ends_with($uri, '.php')) return false;
if ($uri !== '/' && is_file($file)) { chdir(dirname($file)); require $file; return true; }
$run = function (string $t, array $get = []) {
  // Pages read their parameters with filter_input(), which only sees the real query string (LiteSpeed's
  // rewrite puts them there) — so locally we redirect once to "target.php?param=…".
  $missing = array_diff_key($get, $_GET);
  if ($missing) { header('Location: /' . $t . '?' . http_build_query($get + $_GET), true, 302); exit; }
  $_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/' . $t; // as on the live server (pages read it, e.g. the Ad Inserter page type)
  chdir(dirname(__DIR__ . '/' . $t)); require __DIR__ . '/' . $t;
};
// clean store addresses: parameters are given straight to the page (no local redirect), the address stays clean as on live
$runq = function (string $t, array $get) { $_GET = $get + $_GET; $_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/' . $t; chdir(dirname(__DIR__ . '/' . $t)); require __DIR__ . '/' . $t; };
$store = [
  '#^/courses/?$#' => fn($m) => isset($_GET['s']) ? $runq('store/item.php', []) : $runq('store/list.php', ['m' => 'courses']),
  '#^/upcoming-courses/?$#' => fn($m) => $runq('store/list.php', ['m' => 'upcoming']),
  '#^/all-categories/?$#' => fn($m) => $runq('store/list.php', ['m' => 'cats']),
  '#^/courses/([a-zA-Z0-9-]+)/?$#' => fn($m) => $runq('store/item.php', ['s' => $m[1]]),
  '#^/notes/?$#' => fn($m) => isset($_GET['s']) ? $runq('store/item.php', []) : $runq('store/list.php', ['m' => 'notes']),
  '#^/notes/([a-zA-Z0-9-]+)/?$#' => fn($m) => $runq('store/item.php', ['s' => $m[1]]),
  '#^/category/([a-zA-Z0-9-]+)/?$#' => fn($m) => $runq('store/index.php', ['c' => $m[1]]),
  '#^/learn/?$#' => fn($m) => $runq('store/learn.php', []),
  '#^/learn/([a-zA-Z0-9-]+)/?$#' => fn($m) => $runq('store/learn.php', ['s' => $m[1]]),
  '#^/free-courses/?$#' => fn($m) => $runq('store/free.php', []),
  '#^/free-courses/([0-9]+)(?:-[a-zA-Z0-9-]*)?/?$#' => fn($m) => $runq('store/free.php', ['id' => $m[1]]),
];
foreach ($store as $re => $fn) if (preg_match($re, $uri, $m)) { $fn($m); return true; }
$static = 'about-us|contact-us|terms-of-service|cookie-policy|privacy-policy|disclaimer|affiliate-disclaimer|editorial-policy|commercial-policy';
$routes = [
  "#^/($static)/?$#i" => fn($m) => $run("pages/{$m[1]}.php"),
  '#^/search/?$#' => fn() => $run('search.php'),
  '#^/categories/?$#' => fn() => $run('categories.php'),
  '#^/tag/([a-z0-9-]+)-([0-9]+)/?$#' => fn($m) => $run('tag.php', ['id' => $m[2]]),
  '#^/categories/([a-zA-Z0-9-]+)/?$#' => fn($m) => $run('category.php', ['slug' => $m[1]]),
  '#^/author/([a-zA-Z0-9_-]+)/?$#' => fn($m) => $run('author.php', ['slug' => $m[1]]),
  '#^/state-jobs/?$#' => fn() => $run('states.php'),
  '#^/state-jobs/([a-zA-Z0-9-]+)/?$#' => fn($m) => $run('state-jobs.php', ['slug' => $m[1]]),
  '#^/documents/(\d+)$#' => fn($m) => $run('doc.php', ['id' => $m[1]]),
  '#^/sitemap\.xml$#' => fn() => $run('sitemap.php'),
  '#^/news-sitemap\.xml$#' => fn() => $run('news-sitemap.php'),
  '#^/rss\.xml$#' => fn() => $run('rss.php'),
  '#^/([a-zA-Z0-9-]+)-([0-9]+)/?$#' => fn($m) => $run('post.php', ['id' => $m[2]]),
];
foreach ($routes as $re => $fn) if (preg_match($re, $uri, $m)) { $fn($m); return true; }
if ($uri === '/' || $uri === '') { $run('index.php'); return true; }
// a folder wins over name.php (live: "!-d"), and the server adds the trailing slash to a folder address
if (is_dir($file) && is_file($file . '/index.php')) { if (!str_ends_with($uri, '/')) { header('Location: ' . $uri . '/', true, 301); exit; } $run(trim($uri, '/') . '/index.php'); return true; }
if (is_file(__DIR__ . $uri . '.php')) { $run(ltrim($uri, '/') . '.php'); return true; }
if (preg_match('#^/([a-z0-9-]+)/?$#', $uri, $pm)) { $_GET['slug'] = $pm[1]; chdir(__DIR__); require __DIR__ . '/page.php'; return true; }
http_response_code(404); $run('templates/page_not_found.php'); return true;
