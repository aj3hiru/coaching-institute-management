<?php
// Visit statistics used by the Dashboard and Analytics: per post, per day, per traffic source and country
// (post_stats_daily), and anonymous daily visitor ids for unique-visitor counts (visitor_log).
function edu_stats_tables(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS post_stats_daily (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, post_id INT UNSIGNED NOT NULL, stat_date DATE NOT NULL,
        source VARCHAR(20) NOT NULL DEFAULT 'direct', country VARCHAR(2) NOT NULL DEFAULT 'XX', views INT UNSIGNED NOT NULL DEFAULT 0,
        UNIQUE KEY uniq_post_date_source_country (post_id, stat_date, source, country), KEY idx_date (stat_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS visitor_log (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, visit_date DATE NOT NULL, visitor_id VARCHAR(64) NOT NULL, post_id INT UNSIGNED NOT NULL,
        UNIQUE KEY uniq_visit (visit_date, visitor_id, post_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Upgrades the stats table once: a device column (mobile / tablet / desktop) in the per-day key. */
function edu_stats_upgrade(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        if (!$pdo->query("SHOW COLUMNS FROM post_stats_daily LIKE 'device'")->fetch()) {
            $pdo->exec("ALTER TABLE post_stats_daily ADD COLUMN device VARCHAR(8) NOT NULL DEFAULT 'unknown', DROP INDEX uniq_post_date_source_country, ADD UNIQUE KEY uniq_day (post_id, stat_date, source, country, device), ADD KEY idx_source (source)");
        }
    } catch (Throwable $e) { error_log('stats upgrade: ' . $e->getMessage()); }
}

/** Traffic source names shown in Analytics (older rows used search / social). */
const EDU_SOURCES = [
    'organic' => ['Organic Search', 'fa-magnifying-glass', '#16a34a'], 'discover' => ['Google Discover', 'fa-compass', '#ea4335'],
    'gnews' => ['Google News', 'fa-newspaper', '#4285f4'], 'push' => ['Push Notifications', 'fa-bell', '#7c3aed'],
    'facebook' => ['Facebook', 'fa-facebook', '#1877f2'], 'whatsapp' => ['WhatsApp', 'fa-whatsapp', '#25d366'], 'telegram' => ['Telegram', 'fa-telegram', '#229ed9'],
    'twitter' => ['X (Twitter)', 'fa-x-twitter', '#111827'], 'instagram' => ['Instagram', 'fa-instagram', '#e1306c'], 'youtube' => ['YouTube', 'fa-youtube', '#ff0000'],
    'linkedin' => ['LinkedIn', 'fa-linkedin', '#0a66c2'], 'social' => ['Other social', 'fa-share-nodes', '#db2777'], 'search' => ['Organic Search (old)', 'fa-magnifying-glass', '#22c55e'],
    'referral' => ['Other websites', 'fa-link', '#f59e0b'], 'internal' => ['Within the site', 'fa-arrows-rotate', '#0891b2'], 'direct' => ['Direct', 'fa-arrow-right-to-bracket', '#64748b'],
];

function edu_stats_source(string $ref, string $query = ''): string
{
    parse_str(ltrim($query, '?'), $q);
    $utm = strtolower((string)($q['utm_source'] ?? ''));
    if ($utm !== '') {
        if (in_array($utm, ['push', 'pushnotification', 'web-push', 'notification'], true)) return 'push';
        foreach (['facebook' => 'facebook', 'fb' => 'facebook', 'whatsapp' => 'whatsapp', 'telegram' => 'telegram', 'twitter' => 'twitter', 'x' => 'twitter', 'instagram' => 'instagram', 'youtube' => 'youtube', 'linkedin' => 'linkedin', 'google' => 'organic', 'discover' => 'discover'] as $k => $v) if ($utm === $k) return $v;
    }
    $ref = strtolower(trim($ref));
    if ($ref === '') return 'direct';
    if (str_starts_with($ref, 'android-app://com.google.android.googlequicksearchbox')) return 'discover'; // Google app feed (Discover)
    if (str_starts_with($ref, 'android-app://com.google.android.apps.magazines')) return 'gnews';
    $h = (string)parse_url($ref, PHP_URL_HOST);
    if ($h === '') return 'referral';
    $own = strtolower((string)parse_url(SITE_URL, PHP_URL_HOST));
    if ($h === $own || $h === 'www.' . $own) return 'internal';
    if ($h === 'news.google.com' || str_ends_with($h, '.news.google.com')) return 'gnews';
    if ($h === 'discover.google.com') return 'discover';
    if (preg_match('/(^|\.)(google|bing|yahoo|duckduckgo|yandex|baidu|ecosia|brave|qwant|naver|seznam)\./', $h) || $h === 'search.brave.com') return 'organic';
    if (preg_match('/(^|\.)(facebook\.com|fb\.com|fb\.me|messenger\.com)$/', $h)) return 'facebook';
    if (preg_match('/(^|\.)(whatsapp\.com|wa\.me)$/', $h)) return 'whatsapp';
    if (preg_match('/(^|\.)(telegram\.org|telegram\.me|t\.me)$/', $h)) return 'telegram';
    if (preg_match('/(^|\.)(twitter\.com|x\.com|t\.co)$/', $h)) return 'twitter';
    if (preg_match('/(^|\.)instagram\.com$/', $h)) return 'instagram';
    if (preg_match('/(^|\.)(youtube\.com|youtu\.be)$/', $h)) return 'youtube';
    if (preg_match('/(^|\.)(linkedin\.com|lnkd\.in)$/', $h)) return 'linkedin';
    if (preg_match('/(pinterest|reddit|threads\.net|quora|snapchat|sharechat|koo)/', $h)) return 'social';
    return 'referral';
}

function edu_stats_device(string $ua): string
{
    if ($ua === '') return 'unknown';
    if (preg_match('/bot|crawl|lighthouse|headless|preview|spider|slurp|facebookexternalhit/i', $ua)) return 'bot';
    if (preg_match('/iPad|Tablet|Nexus 7|Nexus 10|SM-T|Kindle|Silk|(Android(?!.*Mobile))/i', $ua)) return 'tablet';
    if (preg_match('/Mobi|iPhone|iPod|Android.*Mobile|Opera Mini|IEMobile|BlackBerry/i', $ua)) return 'mobile';
    return 'desktop';
}

function edu_stats_record(PDO $pdo, int $postId, string $referrer = '', string $query = ''): void
{
    edu_stats_tables($pdo);
    edu_stats_upgrade($pdo);
    $day = date('Y-m-d');
    $country = strtoupper(substr((string)($_SERVER['HTTP_CF_IPCOUNTRY'] ?? 'XX'), 0, 2));
    if (!preg_match('/^[A-Z]{2}$/', $country)) $country = 'XX';
    $source = edu_stats_source($referrer, $query);
    $device = edu_stats_device((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($device === 'bot') return; // crawlers are not readers
    $pdo->prepare("INSERT INTO post_stats_daily (post_id, stat_date, source, country, device, views) VALUES (?, ?, ?, ?, ?, 1) ON DUPLICATE KEY UPDATE views = views + 1")
        ->execute([$postId, $day, $source, $country, $device]);
    // A visitor who came from a push notification counts as a click on that notification.
    if ($source === 'push') {
        parse_str(ltrim($query, '?'), $q);
        $cid = (int)($q['utm_campaign'] ?? $q['pn'] ?? 0);
        if ($cid > 0) {
            try {
                if (!$pdo->query("SHOW COLUMNS FROM push_campaigns LIKE 'clicks'")->fetch()) $pdo->exec("ALTER TABLE push_campaigns ADD COLUMN clicks INT UNSIGNED NOT NULL DEFAULT 0");
                $pdo->prepare("UPDATE push_campaigns SET clicks = clicks + 1 WHERE id = ?")->execute([$cid]);
            } catch (Throwable $e) {}
        }
    }
    // Anonymous: a one-way hash of the connection + browser + day (no IP is stored, and it changes every day).
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    $vid = hash('sha256', $ip . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|' . $day . '|' . (defined('PUSH_SECRET') ? PUSH_SECRET : 'edu'));
    $pdo->prepare("INSERT IGNORE INTO visitor_log (visit_date, visitor_id, post_id) VALUES (?, ?, ?)")->execute([$day, $vid, $postId]);
}

/** Today's views per hour from the tracker's hourly file (last 24 hours). */
function edu_stats_hourly(): array
{
    $f = ROOT_PATH . '/cj_smart_cache/views_tracking.json';
    $data = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    $out = array_fill(0, 24, 0);
    $today = date('Y-m-d');
    foreach ($data as $h => $posts) {
        if (substr($h, 0, 10) !== $today) continue;
        $out[(int)substr($h, 11, 2)] += array_sum((array)$posts);
    }
    return $out;
}

/** Views per post per day for the last 7 days (tracker's daily file). */
function edu_stats_daily(): array
{
    $f = ROOT_PATH . '/cj_smart_cache/daily_performance.json';
    return is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
}

function edu_country_name(string $cc): string
{
    static $names = ['IN' => 'India', 'US' => 'United States', 'GB' => 'United Kingdom', 'NP' => 'Nepal', 'BD' => 'Bangladesh', 'PK' => 'Pakistan', 'AE' => 'UAE', 'SA' => 'Saudi Arabia', 'CA' => 'Canada', 'AU' => 'Australia', 'DE' => 'Germany', 'SG' => 'Singapore', 'QA' => 'Qatar', 'KW' => 'Kuwait', 'OM' => 'Oman', 'LK' => 'Sri Lanka', 'FR' => 'France', 'NL' => 'Netherlands', 'XX' => 'Unknown'];
    return $names[$cc] ?? $cc;
}

function edu_country_flag(string $cc): string
{
    if (!preg_match('/^[A-Z]{2}$/', $cc) || $cc === 'XX') return '🌐';
    return mb_chr(0x1F1E6 + ord($cc[0]) - 65) . mb_chr(0x1F1E6 + ord($cc[1]) - 65);
}

/** Traffic Adjustment was removed — every number shown is the real number (kept so older callers keep working). */
function edu_adjust_factor(PDO $pdo, array $viewer, string $from, string $to, string $inPosts = ''): float
{
    return 1.0;
}

function edu_adjust(int|float $n, float $f): int
{
    return (int)round($n * $f);
}

/** A deleted post leaves no numbers behind: its views, daily stats, visitor rows and the counts in the tracker files. */
function edu_stats_forget_post(PDO $pdo, int $postId): void
{
    foreach (['post_views', 'post_stats_daily', 'visitor_log'] as $t) {
        try { $pdo->prepare("DELETE FROM $t WHERE post_id = ?")->execute([$postId]); } catch (Throwable $e) {}
    }
    foreach (['blog_views.json', 'views_tracking.json', 'daily_performance.json'] as $name) {
        $f = ROOT_PATH . '/cj_smart_cache/' . $name;
        if (!is_file($f) || !($fp = fopen($f, 'c+'))) continue;
        if (flock($fp, LOCK_EX)) {
            $size = fstat($fp)['size'];
            $data = json_decode($size ? (string)fread($fp, $size) : '{}', true);
            if (is_array($data)) {
                unset($data[$postId], $data[(string)$postId]);
                foreach ($data as $k => $v) if (is_array($v)) { unset($data[$k][$postId], $data[$k][(string)$postId]); if (!$data[$k]) unset($data[$k]); }
                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, json_encode($data ?: new stdClass()));
                fflush($fp);
            }
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }
}
