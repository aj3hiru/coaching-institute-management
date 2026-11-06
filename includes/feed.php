<?php
// RSS 2.0 and Atom feeds in WordPress's format (as on a WordPress + Rank Math site):
//   /feed/ (RSS 2.0) · /feed/atom/ · /rss, /rss/ and /feed/rss2/ → 301 to /feed/ · /rss.xml (older address, same feed)
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/seo.php';
if (isset($pdo) && !isset($GLOBALS['pdo'])) $GLOBALS['pdo'] = $pdo; // included from inside a function

function edu_feed_x(string $s): string
{
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function edu_feed_cdata(string $s): string
{
    return '<![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', $s) . ']]>';
}

/** Newest published posts with author, categories, tags, image and comment count. */
/** hi-IN when most of the latest posts are in Hindi, else en-US. */
function edu_feed_lang(array $posts): string
{
    $hi = 0;
    foreach ($posts as $p) if (edu_text_lang((string)$p['title'] . ' ' . mb_substr((string)$p['content'], 0, 2000)) === 'hi') $hi++;
    return $posts && $hi * 2 >= count($posts) ? 'hi-IN' : 'en-US';
}

function edu_feed_posts(PDO $pdo, int $limit = 50): array
{
    $rows = $pdo->query("SELECT p.id, p.slug, p.title, p.content, p.date, p.updated_at, a.name AS author, c.name AS category,
            m.file_path AS img, m.alt_text AS alt, m.file_type AS img_type,
            (SELECT meta_value FROM post_meta pm WHERE pm.post_id = p.id AND pm.meta_key = 'summary' LIMIT 1) AS summary,
            (SELECT COUNT(*) FROM comments cm WHERE cm.post_id = p.id AND cm.status = 'approved') AS comments
        FROM posts p
        LEFT JOIN authors a ON a.id = p.author_id
        LEFT JOIN categories c ON c.id = p.category_id
        LEFT JOIN media m ON m.id = p.featured_image_id
        WHERE p.status = 'published' AND (p.date IS NULL OR p.date <= NOW())
        ORDER BY p.date DESC LIMIT " . max(1, min(200, $limit)))->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return [];
    $ids = implode(',', array_map('intval', array_column($rows, 'id')));
    $tags = [];
    foreach ($pdo->query("SELECT pt.post_id, t.name FROM post_tag pt JOIN tags t ON t.id = pt.tag_id WHERE pt.post_id IN ($ids)") as $t) $tags[$t['post_id']][] = $t['name'];
    foreach ($rows as &$r) $r['tags'] = $tags[$r['id']] ?? [];
    return $rows;
}

function edu_feed_excerpt(array $p): string
{
    $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string)($p['summary'] ?: $p['content'])), ENT_QUOTES, 'UTF-8')));
    if (mb_strlen($text) > 300) $text = rtrim(preg_replace('/\s+\S*$/u', '', mb_substr($text, 0, 300))) . ' [&#8230;]';
    return $text;
}

function edu_feed_img_type(string $path, ?string $type): string
{
    if ($type && str_contains($type, '/')) return $type;
    $ext = strtolower(pathinfo(parse_url($path, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
    return ['png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif', 'svg' => 'image/svg+xml'][$ext] ?? 'image/jpeg';
}

function edu_feed_rss2(PDO $pdo): string
{
    $base = rtrim(SITE_URL, '/');
    $posts = edu_feed_posts($pdo);
    $last = 0;
    foreach ($posts as $p) $last = max($last, strtotime((string)($p['updated_at'] ?: $p['date'])));
    $o = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"
	xmlns:content="http://purl.org/rss/1.0/modules/content/"
	xmlns:wfw="http://wellformedweb.org/CommentAPI/"
	xmlns:dc="http://purl.org/dc/elements/1.1/"
	xmlns:atom="http://www.w3.org/2005/Atom"
	xmlns:sy="http://purl.org/rss/1.0/modules/syndication/"
	xmlns:slash="http://purl.org/rss/1.0/modules/slash/"
	xmlns:media="http://search.yahoo.com/mrss/" >

<channel>
	<title>' . edu_feed_x(edu_site_name()) . '</title>
	<atom:link href="' . edu_feed_x($base . '/feed/') . '" rel="self" type="application/rss+xml" />
	<link>' . edu_feed_x($base) . '</link>
	<description>' . edu_feed_x(defined('SEO_DEFAULT_DESCRIPTION') ? SEO_DEFAULT_DESCRIPTION : '') . '</description>
	<lastBuildDate>' . gmdate('D, d M Y H:i:s', $last ?: time()) . ' +0000</lastBuildDate>
	<language>' . edu_feed_lang($posts) . '</language>
	<sy:updatePeriod>
	hourly	</sy:updatePeriod>
	<sy:updateFrequency>
	1	</sy:updateFrequency>
	<generator>' . edu_feed_x($base) . '</generator>
';
    foreach ($posts as $p) {
        $link = $base . postUrl($p['slug'], $p['id']);
        $img = $p['img'] ? edu_seo_abs((string)$p['img']) : '';
        $type = $img ? edu_feed_img_type($img, $p['img_type']) : '';
        $lead = $img ? '<p><img src="' . edu_feed_x($img) . '" alt="' . edu_feed_x($p['alt'] ?: $p['title']) . '" /></p>' : '';
        $cats = array_values(array_unique(array_filter(array_merge([(string)$p['category']], $p['tags']))));
        $o .= '	<item>
		<title>' . edu_feed_x($p['title']) . '</title>
		<link>' . edu_feed_x($link) . '</link>
		<comments>' . edu_feed_x($link) . '#respond</comments>
		<dc:creator>' . edu_feed_cdata((string)($p['author'] ?: edu_site_name())) . '</dc:creator>
		<pubDate>' . gmdate('D, d M Y H:i:s', strtotime((string)$p['date'])) . ' +0000</pubDate>
' . implode('', array_map(fn($c) => '		<category>' . edu_feed_cdata($c) . "</category>\n", $cats)) . '		<guid isPermaLink="false">' . edu_feed_x($base . '/?p=' . $p['id']) . '</guid>

		<description>' . edu_feed_cdata(edu_feed_excerpt($p)) . '</description>
		<content:encoded>' . edu_feed_cdata($lead . edu_seo_abs_content((string)$p['content'])) . '</content:encoded>
		<wfw:commentRss>' . edu_feed_x($link) . '#comments</wfw:commentRss>
		<slash:comments>' . (int)$p['comments'] . '</slash:comments>
' . ($img ? '		<enclosure url="' . edu_feed_x($img) . '" length="' . edu_feed_size((string)$p['img']) . '" type="' . $type . '" />
		<media:content url="' . edu_feed_x($img) . '" medium="image" type="' . $type . '">
			<media:title type="plain">' . edu_feed_x($p['alt'] ?: $p['title']) . '</media:title>
		</media:content>
		<media:thumbnail url="' . edu_feed_x($img) . '" />
' : '') . '	</item>
';
    }
    return $o . "</channel>\n</rss>\n";
}

function edu_feed_atom(PDO $pdo): string
{
    $base = rtrim(SITE_URL, '/');
    $posts = edu_feed_posts($pdo);
    $last = 0;
    foreach ($posts as $p) $last = max($last, strtotime((string)($p['updated_at'] ?: $p['date'])));
    $d = fn($t) => gmdate('Y-m-d\TH:i:s\Z', $t);
    $o = '<?xml version="1.0" encoding="UTF-8"?><feed
	xmlns="http://www.w3.org/2005/Atom"
	xmlns:thr="http://purl.org/syndication/thread/1.0"
	xml:lang="' . edu_feed_lang($posts) . '"
	>
	<title type="text">' . edu_feed_x(edu_site_name()) . '</title>
	<subtitle type="text">' . edu_feed_x(defined('SEO_DEFAULT_DESCRIPTION') ? SEO_DEFAULT_DESCRIPTION : '') . '</subtitle>

	<updated>' . $d($last ?: time()) . '</updated>

	<link rel="alternate" type="text/html" href="' . edu_feed_x($base . '/') . '" />
	<id>' . edu_feed_x($base . '/feed/atom/') . '</id>
	<link rel="self" type="application/atom+xml" href="' . edu_feed_x($base . '/feed/atom/') . '" />

	<generator uri="' . edu_feed_x($base . '/') . '">' . edu_feed_x(edu_site_name()) . '</generator>
';
    foreach ($posts as $p) {
        $link = $base . postUrl($p['slug'], $p['id']);
        $cats = array_values(array_unique(array_filter(array_merge([(string)$p['category']], $p['tags']))));
        $o .= '	<entry>
		<author>
			<name>' . edu_feed_x((string)($p['author'] ?: edu_site_name())) . '</name>
		</author>

		<title type="html">' . edu_feed_cdata($p['title']) . '</title>
		<link rel="alternate" type="text/html" href="' . edu_feed_x($link) . '" />

		<id>' . edu_feed_x($base . '/?p=' . $p['id']) . '</id>
		<updated>' . $d(strtotime((string)($p['updated_at'] ?: $p['date']))) . '</updated>
		<published>' . $d(strtotime((string)$p['date'])) . '</published>
' . implode('', array_map(fn($c) => '		<category scheme="' . edu_feed_x($base) . '" term="' . edu_feed_x($c) . "\" />\n", $cats)) . '
		<summary type="html">' . edu_feed_cdata(edu_feed_excerpt($p)) . '</summary>

		<content type="html" xml:base="' . edu_feed_x($link) . '">' . edu_feed_cdata(edu_seo_abs_content((string)$p['content'])) . '</content>
		<link rel="replies" type="text/html" href="' . edu_feed_x($link) . '#comments" thr:count="' . (int)$p['comments'] . '" />
		<thr:total>' . (int)$p['comments'] . '</thr:total>
	</entry>
';
    }
    return $o . "</feed>\n";
}

function edu_feed_size(string $path): int
{
    if (preg_match('#^https?://#', $path)) return 0;
    $f = ROOT_PATH . '/' . ltrim($path, '/');
    return is_file($f) ? (int)filesize($f) : 0;
}

/** Relative image / link addresses in post content become absolute (feed readers have no base URL). */
function edu_seo_abs_content(string $html): string
{
    $base = rtrim(SITE_URL, '/');
    return preg_replace('#\b(src|href)=(["\'])/(?!/)#i', '$1=$2' . $base . '/', $html);
}

/** Sends a feed with WordPress's headers; ?type=atom for Atom. */
function edu_feed_send(string $type = 'rss2'): void
{
    $pdo = $GLOBALS['pdo'];
    if (session_status() === PHP_SESSION_ACTIVE) session_abort();
    header_remove('Set-Cookie');
    header('Content-Type: ' . ($type === 'atom' ? 'application/atom+xml' : 'application/rss+xml') . '; charset=UTF-8');
    header('Cache-Control: public, max-age=600');
    header('X-Robots-Tag: noindex, follow');
    echo $type === 'atom' ? edu_feed_atom($pdo) : edu_feed_rss2($pdo);
}

/** 301 to /feed/ (the WordPress address), as WordPress does for /rss, /rss/, /feed and /feed/rss2/. */
function edu_feed_redirect(): void
{
    header('Location: ' . rtrim(SITE_URL, '/') . '/feed/', true, 301);
    exit;
}
