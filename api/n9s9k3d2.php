<?php
// api/fetch_og.php — link preview (title / picture of a web page). Signed-in staff only, http(s) only, and never an
// address inside the server or its network (it was open to everyone and could be pointed at internal addresses).
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
header('Content-Type: application/json');
if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'blog_admin', 'editor', 'author'], true)) { http_response_code(403); exit(json_encode(['success' => false, 'error' => 'Access denied'])); }

$input = json_decode(file_get_contents('php://input'), true);
$url = is_array($input) && is_string($input['url'] ?? null) ? trim($input['url']) : '';

$host = (string)parse_url($url, PHP_URL_HOST);
$ips = $host !== '' ? (filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: [])) : [];
$public = $ips && !array_filter($ips, fn($ip) => !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE));
if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url) || !$public || (parse_url($url, PHP_URL_PORT) ?: 0) && !in_array((int)parse_url($url, PHP_URL_PORT), [80, 443], true)) {
    echo json_encode(['success' => false, 'error' => 'Invalid URL']);
    exit;
}

$context = stream_context_create([
    'http' => [
        'method' => 'GET', 'timeout' => 6, 'follow_location' => 0, 'max_redirects' => 0,
        'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36\r\n" .
                    "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8\r\n"
    ]
]);

$html = @file_get_contents($url, false, $context, 0, 1024 * 1024); // at most 1 MB

if (!$html) {
    echo json_encode(['success' => false, 'error' => 'Failed to load page']);
    exit;
}

$doc = new DOMDocument();
libxml_use_internal_errors(true);
@$doc->loadHTML('<?xml encoding="UTF-8">' . $html);
libxml_clear_errors();

$xpath = new DOMXPath($doc);

function cleanText($text) {
    return trim(preg_replace('/\s+/', ' ', $text));
}

// TITLE
$title = $xpath->evaluate('string(//meta[@property="og:title"]/@content)') ?:
         $xpath->evaluate('string(//meta[@name="twitter:title"]/@content)') ?:
         $xpath->evaluate('string(//title)') ?:
         $xpath->evaluate('string(//h1[1]') ?:
         $url;

// IMAGE
$image = $xpath->evaluate('string(//meta[@property="og:image"]/@content)') ?:
         $xpath->evaluate('string(//meta[@name="twitter:image"]/@content)') ?? '';

// SITE NAME
$site_name = $xpath->evaluate('string(//meta[@property="og:site_name"]/@content)') ?:
             parse_url($url, PHP_URL_HOST);

echo json_encode([
    'success' => true,
    'data' => [
        'title'       => cleanText($title),
        'image'       => $image,
        'site_name'   => cleanText($site_name)
    ]
]);