<?php
// Site analytics in the style of Plausible: every page view on the public site (assets/js/ea.js → api/ea.php),
// grouped into visits (a visit ends after 30 minutes without activity). No cookies and no IP addresses are stored —
// a visitor is a one-way hash of connection + browser that changes every day.
// Tables: ea_sessions (one row per visit) and ea_pageviews (one row per page view). All times are the site's time zone.

const EA_SESSION_GAP = 1800;

function ea_tables(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS ea_sessions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        visitor CHAR(16) NOT NULL, started DATETIME NOT NULL, last_seen DATETIME NOT NULL,
        pageviews SMALLINT UNSIGNED NOT NULL DEFAULT 0, duration INT UNSIGNED NOT NULL DEFAULT 0,
        entry_path VARCHAR(255) NOT NULL DEFAULT '', exit_path VARCHAR(255) NOT NULL DEFAULT '',
        channel VARCHAR(24) NOT NULL DEFAULT 'Direct', source VARCHAR(120) NOT NULL DEFAULT 'Direct / None', ref_host VARCHAR(150) NOT NULL DEFAULT '',
        utm_source VARCHAR(120) NOT NULL DEFAULT '', utm_medium VARCHAR(120) NOT NULL DEFAULT '', utm_campaign VARCHAR(160) NOT NULL DEFAULT '',
        country CHAR(2) NOT NULL DEFAULT 'XX', region VARCHAR(80) NOT NULL DEFAULT '', city VARCHAR(80) NOT NULL DEFAULT '',
        device VARCHAR(10) NOT NULL DEFAULT '', browser VARCHAR(30) NOT NULL DEFAULT '', os VARCHAR(30) NOT NULL DEFAULT '',
        KEY idx_started (started), KEY idx_visitor (visitor, last_seen), KEY idx_last (last_seen)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ea_pageviews (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        session_id BIGINT UNSIGNED NOT NULL, visitor CHAR(16) NOT NULL, ts DATETIME NOT NULL,
        path VARCHAR(255) NOT NULL, post_id INT UNSIGNED NOT NULL DEFAULT 0, engaged INT UNSIGNED NOT NULL DEFAULT 0,
        KEY idx_ts (ts), KEY idx_session (session_id), KEY idx_path (path(120)), KEY idx_post (post_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// ── What a visitor uses ─────────────────────────────────────────────────────────────────────────────────────────────

function ea_is_bot(string $ua): bool
{
    return $ua === '' || (bool)preg_match('/bot|crawl|spider|slurp|lighthouse|headless|phantom|preview|facebookexternalhit|whatsapp\/|telegrambot|python|curl|wget|java\/|go-http|axios|node-fetch|monitor|pingdom|uptime|gtmetrix|pagespeed|chrome-lighthouse|bytespider|petalbot|semrush|ahrefs|mj12/i', $ua);
}

function ea_browser(string $ua): string
{
    foreach ([
        'FBAN|FBAV|FB_IAB' => 'Facebook', 'Instagram' => 'Instagram', 'SamsungBrowser' => 'Samsung Internet', 'UCBrowser|UCWEB' => 'UC Browser',
        'MiuiBrowser|XiaoMi' => 'Mi Browser', 'Opera Mini|OPiOS' => 'Opera Mini', 'OPR\/|Opera' => 'Opera', 'Edg\/|EdgA\/|EdgiOS' => 'Microsoft Edge',
        'YaBrowser' => 'Yandex Browser', 'Firefox|FxiOS' => 'Firefox', 'GSA\/' => 'Google App', 'CriOS' => 'Chrome',
    ] as $re => $name) if (preg_match('/' . $re . '/', $ua)) return $name;
    if (preg_match('/; wv\)|Version\/[\d.]+ Chrome/', $ua)) return 'Android WebView';
    if (preg_match('/Chrome\//', $ua)) return 'Chrome';
    if (preg_match('/Safari\//', $ua) && preg_match('/Version\//', $ua)) return 'Safari';
    return 'Other';
}

function ea_os(string $ua): string
{
    if (preg_match('/iPhone|iPad|iPod/', $ua)) return 'iOS';
    if (preg_match('/Android/', $ua)) return 'Android';
    if (preg_match('/CrOS/', $ua)) return 'Chrome OS';
    if (preg_match('/Windows/', $ua)) return 'Windows';
    if (preg_match('/Mac OS X|Macintosh/', $ua)) return 'Mac';
    if (preg_match('/Linux/', $ua)) return 'GNU/Linux';
    return 'Other';
}

/** Device by screen width, like Plausible; the browser's own words when the width is missing. */
function ea_device(int $w, string $ua): string
{
    if ($w > 0) return $w < 576 ? 'Mobile' : ($w < 992 ? 'Tablet' : ($w < 1440 ? 'Laptop' : 'Desktop'));
    if (preg_match('/iPad|Tablet|(Android(?!.*Mobile))/i', $ua)) return 'Tablet';
    return preg_match('/Mobi|iPhone|Android/i', $ua) ? 'Mobile' : 'Desktop';
}

// ── Where a visit came from ─────────────────────────────────────────────────────────────────────────────────────────

/** [channel, source name, referrer host] from the referrer and the address's utm_* values. */
function ea_source(string $ref, array $utm, string $ownHost): array
{
    $us = strtolower(trim((string)($utm['utm_source'] ?? '')));
    $um = strtolower(trim((string)($utm['utm_medium'] ?? '')));
    $ref = trim($ref);
    $host = strtolower((string)parse_url($ref, PHP_URL_HOST));
    $host = preg_replace('/^(www\.|m\.|l\.|lm\.|mobile\.)/', '', $host);
    if (str_starts_with($ref, 'android-app://com.google.android.googlequicksearchbox')) return ['Google Discover', 'Google Discover', 'google app'];
    if (str_starts_with($ref, 'android-app://com.google.android.apps.magazines')) return ['Google News', 'Google News', 'google news app'];
    $own = preg_replace('/^www\./', '', strtolower($ownHost));
    if ($host === $own) $host = '';
    $named = [
        'google' => 'Google', 'bing' => 'Bing', 'duckduckgo' => 'DuckDuckGo', 'yahoo' => 'Yahoo!', 'yandex' => 'Yandex', 'baidu' => 'Baidu', 'ecosia' => 'Ecosia', 'brave' => 'Brave',
        'facebook' => 'Facebook', 'fb' => 'Facebook', 'instagram' => 'Instagram', 'whatsapp' => 'WhatsApp', 'wa' => 'WhatsApp', 'telegram' => 'Telegram', 't' => 'Telegram',
        'twitter' => 'X (Twitter)', 'x' => 'X (Twitter)', 'youtube' => 'YouTube', 'linkedin' => 'LinkedIn', 'pinterest' => 'Pinterest', 'reddit' => 'Reddit', 'quora' => 'Quora', 'threads' => 'Threads',
    ];
    $search = ['Google', 'Bing', 'DuckDuckGo', 'Yahoo!', 'Yandex', 'Baidu', 'Ecosia', 'Brave'];
    $social = ['Facebook', 'Instagram', 'WhatsApp', 'Telegram', 'X (Twitter)', 'YouTube', 'LinkedIn', 'Pinterest', 'Reddit', 'Quora', 'Threads'];
    if ($us !== '') {
        if (in_array($us, ['push', 'pushnotification', 'push-notification', 'web-push', 'webpush', 'notification'], true) || $um === 'push') return ['Push Notifications', 'Push notification', $host];
        $name = $named[$us] ?? ($named[preg_replace('/\.(com|me|org|net|in)$/', '', $us)] ?? $us);
        $ch = in_array($um, ['email', 'e-mail', 'newsletter'], true) ? 'Email'
            : (in_array($um, ['cpc', 'ppc', 'paid', 'paidsearch', 'paid-social', 'paidsocial', 'ads'], true) ? 'Paid'
            : (in_array($name, $search, true) ? 'Organic Search' : (in_array($name, $social, true) ? 'Organic Social' : ($host !== '' ? 'Referral' : 'Referral'))));
        return [$ch, $name, $host];
    }
    if ($host === '') return ['Direct', 'Direct / None', ''];
    if ($host === 'news.google.com' || str_ends_with($host, '.news.google.com')) return ['Google News', 'Google News', $host];
    if ($host === 'discover.google.com') return ['Google Discover', 'Google Discover', $host];
    if (preg_match('/(^|\.)(google|bing|duckduckgo|yahoo|yandex|baidu|ecosia)\.[a-z.]+$/', $host, $m) || $host === 'search.brave.com') {
        $k = $host === 'search.brave.com' ? 'brave' : $m[2];
        return ['Organic Search', $named[$k], $host];
    }
    foreach (['facebook.com' => 'Facebook', 'fb.com' => 'Facebook', 'fb.me' => 'Facebook', 'messenger.com' => 'Facebook', 'instagram.com' => 'Instagram', 'whatsapp.com' => 'WhatsApp', 'wa.me' => 'WhatsApp',
        't.me' => 'Telegram', 'telegram.me' => 'Telegram', 'telegram.org' => 'Telegram', 'twitter.com' => 'X (Twitter)', 'x.com' => 'X (Twitter)', 't.co' => 'X (Twitter)', 'youtube.com' => 'YouTube', 'youtu.be' => 'YouTube',
        'linkedin.com' => 'LinkedIn', 'lnkd.in' => 'LinkedIn', 'pinterest.com' => 'Pinterest', 'pin.it' => 'Pinterest', 'reddit.com' => 'Reddit', 'quora.com' => 'Quora', 'threads.net' => 'Threads'] as $d => $name) {
        if ($host === $d || str_ends_with($host, '.' . $d)) return ['Organic Social', $name, $host];
    }
    return ['Referral', $host, $host];
}

// ── Recording (called by api/ea.php) ───────────────────────────────────────────────────────────────────────────────

function ea_visitor_hash(string $ua): string
{
    $ip = (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
    $salt = date('Y-m-d') . '|' . (defined('PUSH_SECRET') ? PUSH_SECRET : (defined('DB_NAME') ? DB_NAME : 'edu'));
    return substr(hash('sha256', $salt . '|' . $ip . '|' . $ua), 0, 16);
}

/** A page view: starts a visit or adds to the current one. */
function ea_record_pageview(PDO $pdo, string $url, string $ref, int $width, int $postId, string $ua): void
{
    ea_tables($pdo);
    $own = (string)parse_url(SITE_URL, PHP_URL_HOST);
    $p = parse_url($url);
    if (!$p || empty($p['host'])) return;
    if (preg_replace('/^www\./', '', strtolower($p['host'])) !== preg_replace('/^www\./', '', strtolower($own))) return; // other sites / previews
    $path = '/' . ltrim(rawurldecode((string)($p['path'] ?? '/')), '/');
    $path = mb_substr($path === '/' ? '/' : rtrim($path, '/'), 0, 250);
    parse_str((string)($p['query'] ?? ''), $q);
    if (isset($q['page']) && ctype_digit((string)$q['page']) && (int)$q['page'] > 1) $path .= '?page=' . (int)$q['page'];
    $now = date('Y-m-d H:i:s');
    $vid = ea_visitor_hash($ua);
    $st = $pdo->prepare("SELECT id FROM ea_sessions WHERE visitor = ? AND last_seen >= ? ORDER BY id DESC LIMIT 1");
    $st->execute([$vid, date('Y-m-d H:i:s', time() - EA_SESSION_GAP)]);
    $sid = (int)$st->fetchColumn();
    if (!$sid) {
        [$channel, $source, $refHost] = ea_source($ref, $q, $own);
        $cut = fn($v, $n) => mb_substr(trim((string)$v), 0, $n);
        $cc = strtoupper(substr((string)($_SERVER['HTTP_CF_IPCOUNTRY'] ?? 'XX'), 0, 2));
        if (!preg_match('/^[A-Z]{2}$/', $cc) || $cc === 'T1') $cc = 'XX';
        $pdo->prepare("INSERT INTO ea_sessions (visitor, started, last_seen, pageviews, entry_path, exit_path, channel, source, ref_host, utm_source, utm_medium, utm_campaign, country, region, city, device, browser, os)
                       VALUES (?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$vid, $now, $now, $path, $path, $channel, $cut($source, 120), $cut($refHost, 150), $cut($q['utm_source'] ?? '', 120), $cut($q['utm_medium'] ?? '', 120), $cut($q['utm_campaign'] ?? '', 160),
                $cc, $cut($_SERVER['HTTP_CF_REGION'] ?? '', 80), $cut($_SERVER['HTTP_CF_IPCITY'] ?? '', 80), ea_device($width, $ua), ea_browser($ua), ea_os($ua)]);
        $sid = (int)$pdo->lastInsertId();
    }
    $pdo->prepare("INSERT INTO ea_pageviews (session_id, visitor, ts, path, post_id) VALUES (?, ?, ?, ?, ?)")->execute([$sid, $vid, $now, $path, max(0, $postId)]);
    $pdo->prepare("UPDATE ea_sessions SET pageviews = pageviews + 1, last_seen = ?, exit_path = ? WHERE id = ?")->execute([$now, $path, $sid]);
}

/** Time spent on a page (sent when the reader leaves or hides the tab). */
function ea_record_engagement(PDO $pdo, string $url, int $ms, string $ua): void
{
    ea_tables($pdo);
    $sec = (int)round(min(max($ms, 0), 1800000) / 1000);
    if ($sec <= 0) return;
    $path = '/' . ltrim(rawurldecode((string)parse_url($url, PHP_URL_PATH)), '/');
    $path = mb_substr($path === '/' ? '/' : rtrim($path, '/'), 0, 250);
    $vid = ea_visitor_hash($ua);
    $st = $pdo->prepare("SELECT id FROM ea_sessions WHERE visitor = ? AND last_seen >= ? ORDER BY id DESC LIMIT 1");
    $st->execute([$vid, date('Y-m-d H:i:s', time() - EA_SESSION_GAP)]);
    $sid = (int)$st->fetchColumn();
    if (!$sid) return;
    $pdo->prepare("UPDATE ea_sessions SET duration = duration + ?, last_seen = ? WHERE id = ?")->execute([$sec, date('Y-m-d H:i:s'), $sid]);
    $pdo->prepare("UPDATE ea_pageviews SET engaged = engaged + ? WHERE session_id = ? AND (path = ? OR path LIKE ?) ORDER BY id DESC LIMIT 1")->execute([$sec, $sid, $path, $path . '?%']);
}

// ── Reports (admin/analytics.php) ──────────────────────────────────────────────────────────────────────────────────

/** [start, end (exclusive), label, interval] for a period key. */
function ea_period(string $key, ?string $from = null, ?string $to = null): array
{
    $today = strtotime('today');
    $d = fn($ts) => date('Y-m-d H:i:s', $ts);
    switch ($key) {
        case 'realtime': return [$d(time() - 1800), $d(time() + 60), 'Realtime', 'minute'];
        case 'today': return [$d($today), $d($today + 86400), 'Today', 'hour'];
        case 'yesterday': return [$d($today - 86400), $d($today), 'Yesterday', 'hour'];
        case '7d': return [$d($today - 6 * 86400), $d($today + 86400), 'Last 7 days', 'day'];
        case '30d': return [$d($today - 29 * 86400), $d($today + 86400), 'Last 30 days', 'day'];
        case '91d': return [$d($today - 90 * 86400), $d($today + 86400), 'Last 91 days', 'week'];
        case 'month': return [$d(strtotime('first day of this month', $today)), $d($today + 86400), 'Month to date', 'day'];
        case 'last_month': return [$d(strtotime('first day of last month', $today)), $d(strtotime('first day of this month', $today)), date('F Y', strtotime('first day of last month', $today)), 'day'];
        case 'year': return [$d(strtotime(date('Y-01-01', $today))), $d($today + 86400), 'Year to date', 'month'];
        case '12mo': return [$d(strtotime('first day of -11 months', $today)), $d($today + 86400), 'Last 12 months', 'month'];
        case 'all': return ['2000-01-01 00:00:00', $d($today + 86400), 'All time', 'month'];
        case 'custom':
            $a = $from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? strtotime($from) : $today;
            $b = $to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ? strtotime($to) : $a;
            if ($b < $a) [$a, $b] = [$b, $a];
            $days = ($b - $a) / 86400 + 1;
            return [$d($a), $d($b + 86400), date('j M', $a) . ($a === $b ? '' : ' – ' . date('j M Y', $b)), $days <= 1 ? 'hour' : ($days <= 92 ? 'day' : ($days <= 400 ? 'week' : 'month'))];
        default: return [$d($today - 27 * 86400), $d($today + 86400), 'Last 28 days', 'day'];
    }
}

/** Filters a report can use: name → [column on ea_sessions s, label]. "page" is handled separately. */
const EA_FILTERS = [
    'channel' => ['s.channel', 'Channel'], 'source' => ['s.source', 'Source'], 'utm_source' => ['s.utm_source', 'UTM source'], 'utm_medium' => ['s.utm_medium', 'UTM medium'],
    'utm_campaign' => ['s.utm_campaign', 'UTM campaign'], 'entry' => ['s.entry_path', 'Entry page'], 'exit' => ['s.exit_path', 'Exit page'], 'country' => ['s.country', 'Country'],
    'browser' => ['s.browser', 'Browser'], 'os' => ['s.os', 'Operating system'], 'device' => ['s.device', 'Device'],
];

/** WHERE for visits in [start, end) with the filters; $scope limits to visits that saw one of these post ids (authors). */
function ea_where(array $filters, string $start, string $end, ?array $scope, array &$args): string
{
    $w = ['s.started >= ?', 's.started < ?'];
    $args = [$start, $end];
    foreach ($filters as $k => $v) {
        if ($v === '' || $v === null) continue;
        if ($k === 'page') { $w[] = 'EXISTS (SELECT 1 FROM ea_pageviews fp WHERE fp.session_id = s.id AND fp.path = ?)'; $args[] = (string)$v; continue; }
        if (!isset(EA_FILTERS[$k])) continue;
        $w[] = EA_FILTERS[$k][0] . ' = ?';
        $args[] = (string)$v;
    }
    if ($scope !== null) $w[] = $scope ? 'EXISTS (SELECT 1 FROM ea_pageviews sp WHERE sp.session_id = s.id AND sp.post_id IN (' . implode(',', array_map('intval', $scope)) . '))' : '0';
    return implode(' AND ', $w);
}

/** Pageviews counted for a report: only the filtered page / the author's posts when those are set. */
function ea_pv_cond(array $filters, ?array $scope, array &$args): string
{
    $c = [];
    if (($filters['page'] ?? '') !== '') { $c[] = 'p.path = ?'; $args[] = (string)$filters['page']; }
    if ($scope !== null) $c[] = $scope ? 'p.post_id IN (' . implode(',', array_map('intval', $scope)) . ')' : '0';
    return $c ? ' AND ' . implode(' AND ', $c) : '';
}

function ea_top_stats(PDO $pdo, array $filters, string $start, string $end, ?array $scope): array
{
    $a = [];
    $where = ea_where($filters, $start, $end, $scope, $a);
    $st = $pdo->prepare("SELECT COUNT(DISTINCT s.visitor) v, COUNT(*) n, COALESCE(SUM(s.pageviews = 1), 0) b, COALESCE(AVG(s.duration), 0) d FROM ea_sessions s WHERE $where");
    $st->execute($a);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    $pa = $a;
    $pc = ea_pv_cond($filters, $scope, $pa);
    $st = $pdo->prepare("SELECT COUNT(*) FROM ea_pageviews p JOIN ea_sessions s ON s.id = p.session_id WHERE $where$pc");
    $st->execute($pa);
    $pv = (int)$st->fetchColumn();
    $n = (int)$r['n'];
    return ['visitors' => (int)$r['v'], 'visits' => $n, 'pageviews' => $pv, 'views_per_visit' => $n ? round($pv / $n, 2) : 0,
        'bounce_rate' => $n ? (int)round($r['b'] / $n * 100) : 0, 'visit_duration' => (int)round((float)$r['d'])];
}

/** One point per minute / hour / day / week / month, empty ones included. */
function ea_timeseries(PDO $pdo, array $filters, string $start, string $end, string $interval, ?array $scope): array
{
    $fmt = ['minute' => '%Y-%m-%d %H:%i', 'hour' => '%Y-%m-%d %H:00', 'day' => '%Y-%m-%d', 'week' => '%x-%v', 'month' => '%Y-%m'][$interval] ?? '%Y-%m-%d';
    $a = [];
    $where = ea_where($filters, $start, $end, $scope, $a);
    $rows = [];
    $st = $pdo->prepare("SELECT DATE_FORMAT(s.started, '$fmt') k, COUNT(DISTINCT s.visitor) v, COUNT(*) n, SUM(s.pageviews = 1) b, AVG(s.duration) d FROM ea_sessions s WHERE $where GROUP BY k");
    $st->execute($a);
    foreach ($st as $r) $rows[$r['k']] = ['visitors' => (int)$r['v'], 'visits' => (int)$r['n'], 'bounce_rate' => $r['n'] ? (int)round($r['b'] / $r['n'] * 100) : 0, 'visit_duration' => (int)round((float)$r['d'])];
    $pa = $a;
    $pc = ea_pv_cond($filters, $scope, $pa);
    // page views by the time they happened (a visit can span hours)
    $pa2 = $pa;
    $st = $pdo->prepare("SELECT DATE_FORMAT(p.ts, '$fmt') k, COUNT(*) c FROM ea_pageviews p JOIN ea_sessions s ON s.id = p.session_id WHERE $where$pc GROUP BY k");
    $st->execute($pa2);
    $pvs = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    // the buckets
    $out = [];
    $t = strtotime($start);
    $endTs = min(strtotime($end), $interval === 'minute' ? time() : strtotime($end));
    if ($start === '2000-01-01 00:00:00') {
        $first = $pdo->query("SELECT MIN(started) FROM ea_sessions")->fetchColumn();
        $t = $first ? strtotime(date('Y-m-01', strtotime($first))) : strtotime('first day of this month');
    }
    if ($interval === 'week') $t = strtotime('monday this week', $t);
    if ($interval === 'month') $t = strtotime(date('Y-m-01', $t));
    $guard = 0;
    while ($t < $endTs && $guard++ < 2000) {
        $k = match ($interval) { 'minute' => date('Y-m-d H:i', $t), 'hour' => date('Y-m-d H:00', $t), 'week' => date('o-W', $t), 'month' => date('Y-m', $t), default => date('Y-m-d', $t) };
        $label = match ($interval) { 'minute' => date('H:i', $t), 'hour' => date('ga', $t), 'week' => date('j M', $t), 'month' => date('M Y', $t), default => date('j M', $t) };
        $r = $rows[$k] ?? ['visitors' => 0, 'visits' => 0, 'bounce_rate' => 0, 'visit_duration' => 0];
        $pv = (int)($pvs[$k] ?? 0);
        $out[] = ['k' => $k, 'label' => $label, 'full' => match ($interval) { 'minute' => date('j M, H:i', $t), 'hour' => date('D j M, ga', $t), 'week' => 'Week of ' . date('j M Y', $t), 'month' => date('F Y', $t), default => date('D, j M Y', $t) },
            'visitors' => $r['visitors'], 'visits' => $r['visits'], 'pageviews' => $pv, 'views_per_visit' => $r['visits'] ? round($pv / $r['visits'], 2) : 0,
            'bounce_rate' => $r['bounce_rate'], 'visit_duration' => $r['visit_duration'], 'now' => $t <= time() && $t + ['minute' => 60, 'hour' => 3600, 'day' => 86400, 'week' => 604800, 'month' => 2678400][$interval] > time()];
        $t = match ($interval) { 'minute' => $t + 60, 'hour' => $t + 3600, 'week' => $t + 7 * 86400, 'month' => strtotime('+1 month', $t), default => strtotime('+1 day', $t) };
    }
    return $out;
}

/** Breakdown lists. $dim: channel source utm_source utm_medium utm_campaign page entry exit country browser os device. */
function ea_breakdown(PDO $pdo, string $dim, array $filters, string $start, string $end, ?array $scope, int $limit = 9, string $search = ''): array
{
    $a = [];
    $where = ea_where($filters, $start, $end, $scope, $a);
    $like = $search !== '' ? '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%' : '';
    $limit = max(1, min(300, $limit));
    if ($dim === 'page') {
        $pc = ea_pv_cond($filters, $scope, $a);
        if ($like !== '') { $pc .= ' AND p.path LIKE ?'; $a[] = $like; }
        $st = $pdo->prepare("SELECT p.path name, COUNT(DISTINCT p.visitor) visitors, COUNT(*) pageviews, ROUND(AVG(NULLIF(p.engaged, 0))) time_on_page
                             FROM ea_pageviews p JOIN ea_sessions s ON s.id = p.session_id WHERE $where$pc GROUP BY p.path ORDER BY visitors DESC, pageviews DESC LIMIT $limit");
        $st->execute($a);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    $col = ['entry' => 's.entry_path', 'exit' => 's.exit_path'][$dim] ?? (EA_FILTERS[$dim][0] ?? null);
    if (!$col) return [];
    $skipEmpty = in_array($dim, ['utm_source', 'utm_medium', 'utm_campaign'], true) ? " AND $col <> ''" : '';
    if ($like !== '') { $skipEmpty .= " AND $col LIKE ?"; $a[] = $like; }
    $extra = $dim === 'entry' ? ', COUNT(*) visits, ROUND(SUM(s.pageviews = 1) / COUNT(*) * 100) bounce_rate, ROUND(AVG(s.duration)) visit_duration' : ($dim === 'exit' ? ', COUNT(*) visits' : ', ROUND(SUM(s.pageviews = 1) / COUNT(*) * 100) bounce_rate, ROUND(AVG(s.duration)) visit_duration');
    $extraSel = '';
    $st = $pdo->prepare("SELECT $col name, COUNT(DISTINCT s.visitor) visitors$extra$extraSel FROM ea_sessions s WHERE $where$skipEmpty GROUP BY $col ORDER BY visitors DESC LIMIT $limit");
    $st->execute($a);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function ea_current_visitors(PDO $pdo, ?array $scope): int
{
    $since = date('Y-m-d H:i:s', time() - 300);
    if ($scope !== null) {
        if (!$scope) return 0;
        $st = $pdo->prepare("SELECT COUNT(DISTINCT visitor) FROM ea_pageviews WHERE ts >= ? AND post_id IN (" . implode(',', array_map('intval', $scope)) . ")");
        $st->execute([$since]);
        return (int)$st->fetchColumn();
    }
    $st = $pdo->prepare("SELECT COUNT(DISTINCT visitor) FROM ea_sessions WHERE last_seen >= ?");
    $st->execute([$since]);
    return (int)$st->fetchColumn();
}
