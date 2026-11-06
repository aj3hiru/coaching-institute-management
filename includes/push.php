<?php
// Push notifications: keys, subscribers (with device / browser / country), segments, campaigns and delivery.
// Keys live in the push_keys table (the first set came from config.php); every subscriber remembers the key it
// subscribed with, so imported subscribers that use other keys keep working.
use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;

require_once __DIR__ . '/settings.php';

const EDU_PUSH_SCHEMA = '4';

function edu_push_db(): PDO
{
    $pdo = edu_db();
    if (!$pdo) throw new RuntimeException('No database connection');
    edu_push_schema($pdo);
    return $pdo;
}

/** Creates / upgrades the push tables once (keys, segments, subscriber details). */
function edu_push_schema(PDO $pdo): void
{
    static $done = false;
    if ($done || es('push', 'schema_v', '') === EDU_PUSH_SCHEMA) { $done = true; return; }
    $done = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS push_keys (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        public_key VARCHAR(255) NOT NULL,
        private_key VARCHAR(255) NOT NULL,
        subject VARCHAR(255) NOT NULL DEFAULT '',
        active TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_public (public_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS push_segments (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        rules TEXT NOT NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $has = fn($t, $c) => (bool)$pdo->query("SHOW COLUMNS FROM `$t` LIKE " . $pdo->quote($c))->fetch();
    foreach ([
        'endpoint_hash' => 'CHAR(64) NULL', 'key_id' => 'INT UNSIGNED NULL', 'device' => "VARCHAR(20) NOT NULL DEFAULT ''",
        'os' => "VARCHAR(30) NOT NULL DEFAULT ''", 'browser' => "VARCHAR(30) NOT NULL DEFAULT ''", 'country' => "VARCHAR(2) NOT NULL DEFAULT ''",
        'region' => "VARCHAR(80) NOT NULL DEFAULT ''", 'city' => "VARCHAR(80) NOT NULL DEFAULT ''", 'lang' => "VARCHAR(20) NOT NULL DEFAULT ''",
        'tz' => "VARCHAR(60) NOT NULL DEFAULT ''", 'updated_at' => 'TIMESTAMP NULL DEFAULT NULL',
    ] as $c => $def) {
        if (!$has('push_subscriptions', $c)) $pdo->exec("ALTER TABLE push_subscriptions ADD COLUMN `$c` $def");
    }
    foreach (['segment_id' => 'INT UNSIGNED NULL', 'segment_name' => 'VARCHAR(120) NULL', 'icon' => 'VARCHAR(255) NULL', 'clicks' => 'INT UNSIGNED NOT NULL DEFAULT 0'] as $c => $def) {
        if (!$has('push_campaigns', $c)) $pdo->exec("ALTER TABLE push_campaigns ADD COLUMN `$c` $def");
    }
    // v4: room for long image / icon / link addresses fetched from other sites (only ever widened)
    foreach (['image' => 'VARCHAR(500) NULL', 'icon' => 'VARCHAR(500) NULL', 'url' => 'VARCHAR(500) NOT NULL'] as $c => $def) {
        $col = $pdo->query("SHOW COLUMNS FROM push_campaigns LIKE " . $pdo->quote($c))->fetch(PDO::FETCH_ASSOC);
        if ($col && preg_match('/varchar\((\d+)\)/i', (string)$col['Type'], $m) && (int)$m[1] < 500) $pdo->exec("ALTER TABLE push_campaigns MODIFY `$c` $def");
    }

    // the keys from config.php become the first (active) key set
    if (!(int)$pdo->query("SELECT COUNT(*) FROM push_keys")->fetchColumn() && defined('VAPID_PUBLIC_KEY') && VAPID_PUBLIC_KEY !== '' && defined('VAPID_PRIVATE_KEY')) {
        $pdo->prepare("INSERT INTO push_keys (public_key, private_key, subject, active) VALUES (?, ?, ?, 1)")
            ->execute([VAPID_PUBLIC_KEY, VAPID_PRIVATE_KEY, defined('VAPID_SUBJECT') ? VAPID_SUBJECT : '']);
    }
    $active = (int)$pdo->query("SELECT id FROM push_keys WHERE active = 1 ORDER BY id DESC LIMIT 1")->fetchColumn();

    // existing subscribers: endpoint hash (one row per browser), key, browser guess from the push service
    $seen = [];
    foreach ($pdo->query("SELECT id, subscription, endpoint_hash, key_id, browser FROM push_subscriptions ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $ep = (string)(json_decode((string)$s['subscription'], true)['endpoint'] ?? '');
        $h = $ep !== '' ? hash('sha256', $ep) : '';
        if ($h === '' || isset($seen[$h])) { // broken row, or a second row of the same browser
            $pdo->prepare("DELETE FROM push_subscriptions WHERE id = ?")->execute([$s['id']]);
            continue;
        }
        $seen[$h] = true;
        if ($s['endpoint_hash'] === $h && $s['key_id'] && $s['browser'] !== '') continue;
        $pdo->prepare("UPDATE push_subscriptions SET endpoint_hash = ?, key_id = COALESCE(key_id, ?), browser = IF(browser = '', ?, browser) WHERE id = ?")
            ->execute([$h, $active ?: null, edu_push_service_browser($ep), $s['id']]);
    }
    $idx = fn($t, $k) => (bool)$pdo->query("SHOW INDEX FROM `$t` WHERE Key_name = " . $pdo->quote($k))->fetch();
    if (!$idx('push_subscriptions', 'uq_endpoint')) $pdo->exec("ALTER TABLE push_subscriptions ADD UNIQUE KEY uq_endpoint (endpoint_hash)");
    if (!$idx('push_subscriptions', 'idx_geo')) $pdo->exec("ALTER TABLE push_subscriptions ADD KEY idx_geo (country, region)");
    if (!$idx('push_subscriptions', 'idx_key')) $pdo->exec("ALTER TABLE push_subscriptions ADD KEY idx_key (key_id)");
    edu_settings_save('push', ['schema_v' => EDU_PUSH_SCHEMA]);
}

/** The key set new visitors subscribe with: ['id', 'public_key', 'private_key', 'subject'] or null. */
function edu_push_active_key(?PDO $pdo = null): ?array
{
    $pdo ??= edu_push_db();
    return $pdo->query("SELECT id, public_key, private_key, subject, created_at FROM push_keys WHERE active = 1 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;
}

function edu_push_ready(): bool
{
    if (!is_file(ROOT_PATH . '/vendor/autoload.php')) return false;
    try { return edu_push_active_key() !== null; } catch (Throwable) { return false; }
}

/** Collect new subscribers (Push Notifications → Settings). Off = no prompt and no bell. */
function edu_push_collecting(): bool
{
    return es('push', 'collect', '1') !== '0';
}

/** Small notification icon: the one set in Push settings, else the site icon. */
function edu_push_default_icon(): string
{
    return (string)es('push', 'icon', '') ?: ((string)es('general', 'favicon', '') ?: '/assets/icons/android-icon-192x192.png');
}

/** Browser name from the push service, for subscribers saved before device details were collected. */
function edu_push_service_browser(string $endpoint): string
{
    $host = (string)(parse_url($endpoint, PHP_URL_HOST) ?: '');
    return match (true) {
        str_contains($host, 'fcm.googleapis') || str_contains($host, 'android.googleapis') => 'Chrome',
        str_contains($host, 'mozilla') => 'Firefox',
        str_contains($host, 'apple') => 'Safari',
        str_contains($host, 'notify.windows') || str_contains($host, 'wns') => 'Edge',
        default => '',
    };
}

/** "Chrome · Android"-style label of a subscriber row (or of a bare subscription JSON). */
function edu_push_browser(array|string $row): string
{
    if (is_string($row)) return edu_push_service_browser((string)(json_decode($row, true)['endpoint'] ?? '')) ?: 'Unknown';
    $parts = array_filter([$row['browser'] ?? '', $row['os'] ?? '']);
    return $parts ? implode(' · ', $parts) : (edu_push_service_browser((string)(json_decode((string)($row['subscription'] ?? ''), true)['endpoint'] ?? '')) ?: 'Unknown');
}

/** [device, os, browser] from a user agent. $touchMac = an iPad that reports itself as a Mac. */
function edu_push_parse_ua(string $ua, bool $touchMac = false): array
{
    $os = match (true) {
        (bool)preg_match('/Android/i', $ua) => 'Android',
        (bool)preg_match('/iPhone|iPad|iPod/i', $ua), $touchMac && str_contains($ua, 'Macintosh') => 'iOS',
        (bool)preg_match('/CrOS/', $ua) => 'ChromeOS',
        (bool)preg_match('/Windows/i', $ua) => 'Windows',
        (bool)preg_match('/Macintosh|Mac OS X/i', $ua) => 'macOS',
        (bool)preg_match('/Linux|X11/i', $ua) => 'Linux',
        default => 'Other',
    };
    $browser = match (true) {
        (bool)preg_match('/SamsungBrowser/i', $ua) => 'Samsung Internet',
        (bool)preg_match('/Edg(e|A|iOS)?\//', $ua) => 'Edge',
        (bool)preg_match('/OPR\/|Opera/', $ua) => 'Opera',
        (bool)preg_match('/YaBrowser/', $ua) => 'Yandex',
        (bool)preg_match('/UCBrowser/', $ua) => 'UC Browser',
        (bool)preg_match('/Firefox|FxiOS/', $ua) => 'Firefox',
        (bool)preg_match('/Chrome|CriOS|Chromium/', $ua) => 'Chrome',
        (bool)preg_match('/Safari/', $ua) => 'Safari',
        default => 'Other',
    };
    $device = in_array($os, ['Android', 'iOS'], true) || preg_match('/Mobi|Tablet/i', $ua) ? 'mobile' : 'desktop';
    return [$device, $os, $browser];
}

/**
 * Saves (or refreshes) a browser's subscription. $data = {subscription, key, tz, lang, touch} (old clients post the
 * bare subscription). Returns ['ok' => true] or ['error' => …, 'key' => current public key when the browser used an
 * unknown key, so it can subscribe again].
 */
function edu_push_save_subscription(array $data, array $server): array
{
    $pdo = edu_push_db();
    $sub = is_array($data['subscription'] ?? null) ? $data['subscription'] : $data;
    $ep = (string)($sub['endpoint'] ?? '');
    $p256 = (string)($sub['keys']['p256dh'] ?? '');
    $auth = (string)($sub['keys']['auth'] ?? '');
    if (!preg_match('#^https://\S+$#', $ep) || strlen($ep) > 1000 || $p256 === '' || $auth === '' || strlen($p256) > 200 || strlen($auth) > 100) {
        return ['error' => 'Invalid subscription'];
    }
    $active = edu_push_active_key($pdo);
    $keyId = $active ? (int)$active['id'] : null;
    if (!empty($data['key'])) {
        $st = $pdo->prepare("SELECT id FROM push_keys WHERE public_key = ?");
        $st->execute([(string)$data['key']]);
        $keyId = (int)$st->fetchColumn() ?: null;
        if (!$keyId) return ['error' => 'Unknown key', 'key' => $active['public_key'] ?? ''];
    }
    [$device, $os, $browser] = edu_push_parse_ua((string)($server['HTTP_USER_AGENT'] ?? ''), !empty($data['touch']));
    $hdr = function (string $k, int $max) use ($server) {
        $v = trim((string)($server[$k] ?? ''));
        if (str_contains($v, '%')) $v = rawurldecode($v);
        return mb_substr((string)preg_replace('/[\x00-\x1f]/u', '', $v), 0, $max);
    };
    $country = strtoupper($hdr('HTTP_CF_IPCOUNTRY', 2));
    if (!preg_match('/^[A-Z]{2}$/', $country) || in_array($country, ['XX', 'T1'], true)) $country = '';
    $clean = json_encode(['endpoint' => $ep, 'expirationTime' => $sub['expirationTime'] ?? null, 'keys' => ['p256dh' => $p256, 'auth' => $auth]], JSON_UNESCAPED_SLASHES);
    $pdo->prepare("INSERT INTO push_subscriptions (subscription, endpoint_hash, key_id, device, os, browser, country, region, city, lang, tz, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE subscription = VALUES(subscription), key_id = VALUES(key_id), device = VALUES(device), os = VALUES(os), browser = VALUES(browser),
            country = IF(VALUES(country) = '', country, VALUES(country)), region = IF(VALUES(region) = '', region, VALUES(region)),
            city = IF(VALUES(city) = '', city, VALUES(city)), lang = VALUES(lang), tz = VALUES(tz), updated_at = NOW()")
        ->execute([$clean, hash('sha256', $ep), $keyId, $device, $os, $browser, $country,
            $hdr('HTTP_CF_REGION', 80), $hdr('HTTP_CF_IPCITY', 80),
            mb_substr((string)preg_replace('/[^A-Za-z0-9-]/', '', (string)($data['lang'] ?? '')), 0, 20),
            mb_substr((string)preg_replace('/[^A-Za-z0-9_\/+-]/', '', (string)($data['tz'] ?? '')), 0, 60)]);
    // the browser moved from an older key: its old subscription no longer works, so drop that row
    $old = (string)($data['old'] ?? '');
    if ($old !== '' && $old !== $ep && preg_match('#^https://\S+$#', $old) && strlen($old) <= 1000) {
        $pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint_hash = ? AND (key_id IS NULL OR key_id <> ?)")->execute([hash('sha256', $old), (int)$keyId]); // only rows of an older key
    }
    return ['ok' => true];
}

/* ---------- Segments ---------- */

const EDU_PUSH_FIELDS = ['country' => 'Country', 'region' => 'State / Region', 'city' => 'City', 'device' => 'Device', 'os' => 'Operating system', 'browser' => 'Browser'];

/** Cleans segment rules: [['field' => country|…, 'op' => is|is_not, 'values' => [...]], …]. */
function edu_push_clean_rules($rules): array
{
    $out = [];
    foreach (is_array($rules) ? $rules : [] as $r) {
        $f = (string)($r['field'] ?? '');
        if (!isset(EDU_PUSH_FIELDS[$f])) continue;
        $vals = is_array($r['values'] ?? null) ? $r['values'] : explode(',', (string)($r['values'] ?? ''));
        $vals = array_values(array_unique(array_filter(array_map(fn($v) => mb_substr(trim((string)$v), 0, 80), $vals), fn($v) => $v !== '')));
        if (!$vals) continue;
        if ($f === 'country') $vals = array_map(fn($v) => strcasecmp($v, 'unknown') === 0 ? 'Unknown' : strtoupper($v), $vals);
        if ($f === 'device') $vals = array_map(fn($v) => strcasecmp($v, 'unknown') === 0 ? 'Unknown' : strtolower($v), $vals);
        $out[] = ['field' => $f, 'op' => ($r['op'] ?? '') === 'is_not' ? 'is_not' : 'is', 'values' => array_slice($vals, 0, 100)];
    }
    return $out;
}

/** SQL condition for the rules (all must match). "Unknown" matches subscribers without that detail. */
function edu_push_rules_sql(array $rules, array &$params): string
{
    $parts = [];
    foreach (edu_push_clean_rules($rules) as $r) {
        $ph = [];
        foreach ($r['values'] as $v) { $ph[] = '?'; $params[] = strcasecmp($v, 'unknown') === 0 ? '' : $v; }
        $parts[] = "s.`{$r['field']}` " . ($r['op'] === 'is_not' ? 'NOT ' : '') . 'IN (' . implode(',', $ph) . ')';
    }
    return $parts ? implode(' AND ', $parts) : '1=1';
}

/** How many subscribers match the rules right now. */
function edu_push_estimate(array $rules): int
{
    $pdo = edu_push_db();
    $params = [];
    $st = $pdo->prepare("SELECT COUNT(*) FROM push_subscriptions s JOIN push_keys k ON k.id = s.key_id WHERE " . edu_push_rules_sql($rules, $params));
    $st->execute($params);
    return (int)$st->fetchColumn();
}

function edu_push_segment(int $id): ?array
{
    $st = edu_push_db()->prepare("SELECT * FROM push_segments WHERE id = ?");
    $st->execute([$id]);
    $s = $st->fetch(PDO::FETCH_ASSOC);
    if (!$s) return null;
    $s['rules'] = edu_push_clean_rules(json_decode((string)$s['rules'], true));
    return $s;
}

/** Human summary of the rules: "Country is IN, US · Device is mobile". */
function edu_push_rules_text(array $rules): string
{
    $t = [];
    foreach (edu_push_clean_rules($rules) as $r) $t[] = EDU_PUSH_FIELDS[$r['field']] . ($r['op'] === 'is_not' ? ' is not ' : ' is ') . implode(', ', $r['values']);
    return $t ? implode(' · ', $t) : 'All subscribers';
}

/* ---------- Sending ---------- */

/** Creates a campaign for everyone (or one segment) and queues it. Returns the campaign id. */
function edu_push_queue(string $title, string $body, string $url, string $image = '', ?int $postId = null, ?int $segmentId = null, string $icon = ''): int
{
    $pdo = edu_push_db();
    $rules = [];
    $segName = null;
    if ($segmentId) {
        $seg = edu_push_segment($segmentId);
        if (!$seg) throw new RuntimeException('Segment not found');
        $rules = $seg['rules'];
        $segName = $seg['name'];
    }
    $params = [];
    $where = edu_push_rules_sql($rules, $params);
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO push_campaigns (post_id, title, body, image, url, status, total_subscribers, segment_id, segment_name, icon) VALUES (?, ?, ?, ?, ?, 'pending', 0, ?, ?, ?)")
            ->execute([$postId, mb_substr($title, 0, 150), mb_substr($body, 0, 300), $image !== '' && strlen($image) <= 500 ? $image : null, $url, $segmentId ?: null, $segName, $icon !== '' && strlen($icon) <= 500 ? $icon : null]);
        $cid = (int)$pdo->lastInsertId();
        $ins = $pdo->prepare("INSERT INTO push_queue (campaign_id, subscription_id, status) SELECT ?, s.id, 'pending' FROM push_subscriptions s JOIN push_keys k ON k.id = s.key_id WHERE $where");
        $ins->execute(array_merge([$cid], $params));
        $n = $ins->rowCount();
        $pdo->prepare("UPDATE push_campaigns SET total_subscribers = ?, status = IF(? = 0, 'completed', status) WHERE id = ?")->execute([$n, $n, $cid]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $cid;
}

/** Absolute address on this site. */
function edu_push_abs(string $u): string
{
    return $u === '' ? '' : (preg_match('#^https?://#', $u) ? $u : rtrim(SITE_URL, '/') . '/' . ltrim($u, '/'));
}

/** One WebPush sender per key set (kept for the request). */
function edu_push_sender(array $key): WebPush
{
    static $senders = [];
    require_once ROOT_PATH . '/vendor/autoload.php';
    $id = (int)$key['id'];
    if (!isset($senders[$id])) {
        $subject = trim((string)$key['subject']) ?: (defined('VAPID_SUBJECT') ? VAPID_SUBJECT : rtrim(SITE_URL, '/'));
        $senders[$id] = new WebPush(['VAPID' => ['subject' => $subject, 'publicKey' => $key['public_key'], 'privateKey' => $key['private_key']]], ['TTL' => 86400], 20);
        $senders[$id]->setReuseVAPIDHeaders(true);
    }
    return $senders[$id];
}

/** One sender at a time for the whole site (MySQL lock: freed by itself if a run crashes). */
function edu_push_lock(PDO $pdo, bool $release = false): bool
{
    $name = $pdo->quote('edu_push_send_' . (defined('DB_NAME') ? DB_NAME : 'site'));
    if ($release) { $pdo->query("SELECT RELEASE_LOCK($name)"); return true; }
    return (int)$pdo->query("SELECT GET_LOCK($name, 0)")->fetchColumn() === 1;
}

/**
 * Delivers the next batch of the queue. Notifications go strictly one after another (oldest first) and only one
 * run works at a time, so ten notifications sent together, or many thousands of subscribers, never overload the
 * server: each run sends at most $limit, in groups of 100 parallel requests.
 * $campaignId is accepted for older callers; the queue order decides what is sent.
 * Returns ['campaign' => id|null, 'sent' => n, 'failed' => n, 'left' => n, 'done' => bool, 'busy' => bool].
 */
function edu_push_process(?int $campaignId = null, int $limit = 1000): array
{
    $pdo = edu_push_db();
    if (!edu_push_lock($pdo)) return ['campaign' => null, 'sent' => 0, 'failed' => 0, 'left' => 0, 'done' => false, 'busy' => true];
    try {
        @set_time_limit(120);
        $next = (int)$pdo->query("SELECT id FROM push_campaigns WHERE status IN ('pending','processing') AND created_at >= NOW() - INTERVAL 1 DAY ORDER BY id ASC LIMIT 1")->fetchColumn();
        if (!$next) return ['campaign' => null, 'sent' => 0, 'failed' => 0, 'left' => 0, 'done' => true, 'busy' => false];
        return edu_push_process_campaign($pdo, $next, max(1, min($limit, 2000))) + ['busy' => false];
    } finally {
        edu_push_lock($pdo, true);
    }
}

/** Where a notification stands: delivered / failed / waiting, and how many notifications are ahead of it. */
function edu_push_progress(int $campaignId): array
{
    $pdo = edu_push_db();
    $st = $pdo->prepare("SELECT id, status, sent, failed, total_subscribers, created_at FROM push_campaigns WHERE id = ?");
    $st->execute([$campaignId]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) return ['done' => true, 'sent' => 0, 'failed' => 0, 'total' => 0, 'left' => 0, 'ahead' => 0];
    $left = (int)$pdo->query("SELECT COUNT(*) FROM push_queue WHERE campaign_id = " . (int)$campaignId . " AND status = 'pending'")->fetchColumn();
    $stale = strtotime((string)$c['created_at']) < time() - 86400;
    $ahead = (int)$pdo->query("SELECT COUNT(*) FROM push_campaigns WHERE id < " . (int)$campaignId . " AND status IN ('pending','processing') AND created_at >= NOW() - INTERVAL 1 DAY")->fetchColumn();
    return ['done' => $c['status'] === 'completed' || $left === 0 || $stale, 'sent' => (int)$c['sent'], 'failed' => (int)$c['failed'],
        'total' => (int)$c['total_subscribers'], 'left' => $left, 'ahead' => $ahead];
}

/** Sends up to $limit queued deliveries of one notification (call with the send lock held). */
function edu_push_process_campaign(PDO $pdo, int $campaignId, int $limit): array
{
    $st = $pdo->prepare("SELECT * FROM push_campaigns WHERE id = ?");
    $st->execute([$campaignId]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) return ['campaign' => $campaignId, 'sent' => 0, 'failed' => 0, 'left' => 0, 'done' => true, 'error' => 'Campaign not found'];
    if (!is_file(ROOT_PATH . '/vendor/autoload.php')) return ['campaign' => $campaignId, 'sent' => 0, 'failed' => 0, 'left' => 0, 'done' => true, 'error' => 'The web-push library is missing'];

    $pdo->prepare("UPDATE push_campaigns SET status = 'processing' WHERE id = ? AND status = 'pending'")->execute([$campaignId]);
    $q = $pdo->prepare("SELECT q.id AS queue_id, q.subscription_id, q.attempts, s.subscription, s.key_id FROM push_queue q
        LEFT JOIN push_subscriptions s ON s.id = q.subscription_id WHERE q.campaign_id = ? AND q.status = 'pending' LIMIT " . max(1, $limit));
    $q->execute([$campaignId]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    $keys = [];
    foreach ($pdo->query("SELECT id, public_key, private_key, subject FROM push_keys") as $k) $keys[(int)$k['id']] = $k;

    $payload = json_encode([
        'title' => $c['title'], 'body' => $c['body'], 'image' => edu_push_abs((string)$c['image']), 'url' => edu_push_track_url(edu_push_abs((string)$c['url']), (int)$c['id']),
        'icon' => edu_push_abs((string)($c['icon'] ?: edu_push_default_icon())), 'campaign' => (int)$campaignId,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $sent = 0;
    $failed = 0;
    $mark = fn($status, $err, $id) => $pdo->prepare("UPDATE push_queue SET status = ?, last_error = ?, processed_at = NOW() WHERE id = ?")->execute([$status, $err !== null ? mb_substr($err, 0, 500) : null, $id]);
    $byKey = [];
    foreach ($rows as $r) {
        $sub = json_decode((string)$r['subscription'], true);
        $key = $keys[(int)$r['key_id']] ?? null;
        if (empty($sub['endpoint']) || !$key) { // subscriber removed, or its key was erased
            $mark('expired', empty($sub['endpoint']) ? 'Subscriber removed' : 'No key for this subscriber', $r['queue_id']);
            $failed++;
            continue;
        }
        $byKey[(int)$r['key_id']][] = [$r, $sub];
    }
    foreach ($byKey as $kid => $list) {
        $webPush = edu_push_sender($keys[$kid]);
        $byEndpoint = [];
        foreach ($list as [$r, $sub]) {
            try {
                $webPush->queueNotification(Subscription::create($sub), $payload);
                $byEndpoint[$sub['endpoint']][] = $r;
            } catch (Throwable $e) {
                $mark('failed', $e->getMessage(), $r['queue_id']);
                $failed++;
            }
        }
        foreach ($webPush->flush(100) as $report) {
            foreach ($byEndpoint[$report->getEndpoint()] ?? [] as $r) {
                if ($report->isSuccess()) {
                    $mark('sent', null, $r['queue_id']);
                    $sent++;
                } elseif ($report->isSubscriptionExpired()) {
                    $mark('expired', $report->getReason(), $r['queue_id']);
                    $pdo->prepare("DELETE FROM push_subscriptions WHERE id = ?")->execute([$r['subscription_id']]);
                    $failed++;
                } else {
                    $att = (int)$r['attempts'] + 1;
                    $pdo->prepare("UPDATE push_queue SET attempts = ?, last_error = ?, status = IF(? >= 3, 'failed', 'pending'), processed_at = NOW() WHERE id = ?")
                        ->execute([$att, mb_substr($report->getReason(), 0, 500), $att, $r['queue_id']]);
                    if ($att >= 3) $failed++;
                }
            }
        }
    }
    $pdo->prepare("UPDATE push_campaigns SET sent = sent + ?, failed = failed + ? WHERE id = ?")->execute([$sent, $failed, $campaignId]);
    $left = (int)$pdo->query("SELECT COUNT(*) FROM push_queue WHERE campaign_id = " . (int)$campaignId . " AND status = 'pending'")->fetchColumn();
    if ($left === 0) $pdo->prepare("UPDATE push_campaigns SET status = 'completed' WHERE id = ?")->execute([$campaignId]);
    return ['campaign' => $campaignId, 'sent' => $sent, 'failed' => $failed, 'left' => $left, 'done' => $left === 0];
}

/** Sends one test notification right away to one subscriber (by id or endpoint). Not saved in the history. */
function edu_push_send_test(array $n, ?int $subId = null, string $endpoint = ''): array
{
    $pdo = edu_push_db();
    $sql = "SELECT s.subscription, k.id, k.public_key, k.private_key, k.subject FROM push_subscriptions s JOIN push_keys k ON k.id = s.key_id WHERE ";
    $st = $pdo->prepare($sql . ($subId ? 's.id = ?' : 's.endpoint_hash = ?'));
    $st->execute([$subId ?: hash('sha256', $endpoint)]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ['error' => 'This browser is not subscribed yet.'];
    if (!is_file(ROOT_PATH . '/vendor/autoload.php')) return ['error' => 'The web-push library is missing'];
    $payload = json_encode([
        'title' => mb_substr(trim((string)($n['title'] ?? '')) ?: 'Test notification', 0, 150),
        'body' => mb_substr(trim((string)($n['body'] ?? '')) ?: 'Push notifications are working on ' . edu_site_name() . '.', 0, 300),
        'image' => edu_push_abs(trim((string)($n['image'] ?? ''))), 'url' => edu_push_abs(trim((string)($n['url'] ?? '')) ?: '/'),
        'icon' => edu_push_abs(trim((string)($n['icon'] ?? '')) ?: edu_push_default_icon()), 'campaign' => 0, 'tag' => 'test-' . time(),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    try {
        $report = edu_push_sender($row)->sendOneNotification(Subscription::create(json_decode($row['subscription'], true)), $payload);
    } catch (Throwable $e) {
        return ['error' => $e->getMessage()];
    }
    return $report->isSuccess() ? ['ok' => true] : ['error' => 'Not delivered: ' . $report->getReason()];
}

/* ---------- Keys, export / import ---------- */

/** New key set; erases every old key and the subscribers that used them. Returns the new public key. */
/**
 * Makes a new active key. $clearSubscribers: erase every old key and subscriber; otherwise the old keys stay
 * (inactive) so their subscribers still get notifications, and each browser moves to the new key on its next visit.
 */
function edu_push_reset_keys(bool $clearSubscribers = true): string
{
    require_once ROOT_PATH . '/vendor/autoload.php';
    $pdo = edu_push_db();
    $k = VAPID::createVapidKeys();
    $subject = (string)($pdo->query("SELECT subject FROM push_keys WHERE active = 1 ORDER BY id DESC LIMIT 1")->fetchColumn() ?: (defined('VAPID_SUBJECT') ? VAPID_SUBJECT : ''));
    $pdo->beginTransaction();
    try {
        if ($clearSubscribers) {
            $pdo->exec("DELETE FROM push_subscriptions");
            $pdo->exec("DELETE FROM push_keys");
        } else {
            $pdo->exec("UPDATE push_keys SET active = 0");
        }
        $pdo->prepare("INSERT INTO push_keys (public_key, private_key, subject, active) VALUES (?, ?, ?, 1)")->execute([$k['publicKey'], $k['privateKey'], $subject]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    edu_clear_page_cache(); // pages carry the public key
    return $k['publicKey'];
}

/** Everything needed to move push to another site: all key sets and all subscribers. */
function edu_push_export(): array
{
    $pdo = edu_push_db();
    $keys = $pdo->query("SELECT id, public_key, private_key, subject, active, created_at FROM push_keys ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $pub = array_column($keys, 'public_key', 'id');
    $subs = [];
    foreach ($pdo->query("SELECT subscription, key_id, device, os, browser, country, region, city, lang, tz, created_at FROM push_subscriptions ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $subs[] = ['subscription' => json_decode($s['subscription'], true), 'public_key' => $pub[(int)$s['key_id']] ?? null] + array_diff_key($s, ['subscription' => 1, 'key_id' => 1]);
    }
    return [
        'format' => 'edumint-push', 'version' => 1, 'site' => parse_url(SITE_URL, PHP_URL_HOST), 'exported_at' => date('c'),
        'keys' => array_map(fn($k) => ['public_key' => $k['public_key'], 'private_key' => $k['private_key'], 'subject' => $k['subject'], 'active' => (bool)$k['active'], 'created_at' => $k['created_at']], $keys),
        'subscribers' => $subs,
    ];
}

/**
 * Imports an export file (any number of key sets, from this or another site). New keys are added beside the
 * current one; every subscriber keeps its own key. Returns counts.
 */
function edu_push_import(array $data): array
{
    $pdo = edu_push_db();
    if (!isset($data['keys']) && !isset($data['subscribers'])) throw new RuntimeException('This is not a push export file.');
    $keyIds = [];
    $keysAdded = 0;
    $hasActive = (bool)edu_push_active_key($pdo);
    $fileKeys = [];
    foreach ((array)($data['keys'] ?? []) as $k) {
        $pubK = trim((string)($k['public_key'] ?? $k['publicKey'] ?? ''));
        $priK = trim((string)($k['private_key'] ?? $k['privateKey'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_-]{80,100}$/', $pubK) || !preg_match('/^[A-Za-z0-9_-]{40,50}$/', $priK)) continue;
        $fileKeys[] = $pubK;
        $st = $pdo->prepare("SELECT id FROM push_keys WHERE public_key = ?");
        $st->execute([$pubK]);
        if ($id = (int)$st->fetchColumn()) { $keyIds[$pubK] = $id; continue; }
        $pdo->prepare("INSERT INTO push_keys (public_key, private_key, subject, active) VALUES (?, ?, ?, ?)")
            ->execute([$pubK, $priK, mb_substr((string)($k['subject'] ?? ''), 0, 255), $hasActive ? 0 : 1]);
        $keyIds[$pubK] = (int)$pdo->lastInsertId();
        $hasActive = true;
        $keysAdded++;
    }
    $added = $updated = $skipped = 0;
    $ins = $pdo->prepare("INSERT INTO push_subscriptions (subscription, endpoint_hash, key_id, device, os, browser, country, region, city, lang, tz, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE subscription = VALUES(subscription), key_id = VALUES(key_id), updated_at = NOW()");
    foreach ((array)($data['subscribers'] ?? []) as $s) {
        if (!is_array($s)) { $skipped++; continue; }
        $sub = is_array($s['subscription'] ?? null) ? $s['subscription'] : (is_string($s['subscription'] ?? null) ? json_decode($s['subscription'], true) : null);
        $ep = (string)($sub['endpoint'] ?? '');
        if (!preg_match('#^https://\S+$#', $ep) || strlen($ep) > 1000 || empty($sub['keys']['p256dh']) || empty($sub['keys']['auth'])) { $skipped++; continue; }
        $pk = (string)($s['public_key'] ?? (count($fileKeys) === 1 ? $fileKeys[0] : ''));
        $kid = $keyIds[$pk] ?? null;
        if (!$kid) { $skipped++; continue; } // without its key nobody can send to it
        $t = strtotime((string)($s['created_at'] ?? '')) ?: time();
        $f = fn($n, $max) => mb_substr(trim((string)($s[$n] ?? '')), 0, $max);
        $dev = in_array($s['device'] ?? '', ['mobile', 'desktop'], true) ? $s['device'] : '';
        $country = strtoupper($f('country', 2));
        $ins->execute([json_encode(['endpoint' => $ep, 'expirationTime' => $sub['expirationTime'] ?? null, 'keys' => ['p256dh' => (string)$sub['keys']['p256dh'], 'auth' => (string)$sub['keys']['auth']]], JSON_UNESCAPED_SLASHES),
            hash('sha256', $ep), $kid, $dev, $f('os', 30), $f('browser', 30) ?: edu_push_service_browser($ep), preg_match('/^[A-Z]{2}$/', $country) ? $country : '',
            $f('region', 80), $f('city', 80), $f('lang', 20), $f('tz', 60), date('Y-m-d H:i:s', $t)]);
        $ins->rowCount() === 1 ? $added++ : $updated++;
    }
    return ['keys' => $keysAdded, 'added' => $added, 'updated' => $updated, 'skipped' => $skipped];
}

/* ---------- Analytics ---------- */

/** Subscriber numbers for the Analytics tab. */
function edu_push_stats(int $days = 30): array
{
    $pdo = edu_push_db();
    $group = fn($col) => $pdo->query("SELECT COALESCE(NULLIF($col, ''), 'Unknown') AS l, COUNT(*) AS v FROM push_subscriptions GROUP BY l ORDER BY v DESC")->fetchAll(PDO::FETCH_KEY_PAIR);
    $countries = $pdo->query("SELECT country AS c, SUM(device = 'desktop') AS d, SUM(device = 'mobile') AS m, COUNT(*) AS t FROM push_subscriptions GROUP BY country ORDER BY t DESC")->fetchAll(PDO::FETCH_ASSOC);
    $regions = $pdo->query("SELECT country AS c, region AS r, SUM(device = 'desktop') AS d, SUM(device = 'mobile') AS m, COUNT(*) AS t FROM push_subscriptions WHERE region <> '' GROUP BY country, region ORDER BY t DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
    $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
    $st = $pdo->prepare("SELECT DATE(created_at) d, COUNT(*) n FROM push_subscriptions WHERE created_at >= ? GROUP BY d");
    $st->execute([$from]);
    $perDay = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    $st = $pdo->prepare("SELECT COUNT(*) FROM push_subscriptions WHERE created_at < ?");
    $st->execute([$from]);
    $run = (int)$st->fetchColumn();
    $growth = [];
    for ($i = 0; $i < $days; $i++) {
        $d = date('Y-m-d', strtotime("$from +$i days"));
        $run += (int)($perDay[$d] ?? 0);
        $growth[] = ['d' => $d, 'new' => (int)($perDay[$d] ?? 0), 'total' => $run];
    }
    return [
        'total' => (int)$pdo->query("SELECT COUNT(*) FROM push_subscriptions")->fetchColumn(),
        'device' => $group('device'), 'os' => $group('os'), 'browser' => $group('browser'),
        'platform' => $pdo->query("SELECT CASE WHEN os = 'Android' THEN 'Android' WHEN os = 'iOS' THEN 'iOS (iPhone / iPad)' WHEN os IN ('Windows','macOS','Linux','ChromeOS') OR device = 'desktop' THEN 'Desktop' ELSE 'Other' END AS l, COUNT(*) AS v FROM push_subscriptions GROUP BY l ORDER BY v DESC")->fetchAll(PDO::FETCH_KEY_PAIR),
        'countries' => $countries, 'regions' => $regions, 'growth' => $growth,
    ];
}

/** Known values of a subscriber detail (for the segment builder). */
function edu_push_values(string $field): array
{
    if (!isset(EDU_PUSH_FIELDS[$field])) return [];
    return edu_push_db()->query("SELECT DISTINCT `$field` FROM push_subscriptions WHERE `$field` <> '' ORDER BY `$field` LIMIT 300")->fetchAll(PDO::FETCH_COLUMN);
}

/** The values your subscribers actually have, with how many each — for picking in a segment. Countries get their name. */
function edu_push_value_counts(string $field): array
{
    if (!isset(EDU_PUSH_FIELDS[$field])) return [];
    $label = $field === 'region' ? "CONCAT(region, IF(country <> '', CONCAT(' (', country, ')'), ''))" : "`$field`";
    $rows = edu_push_db()->query("SELECT `$field` AS v, MAX($label) AS l, COUNT(*) AS n FROM push_subscriptions WHERE `$field` <> '' GROUP BY `$field` ORDER BY n DESC, v LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $l = $field === 'country' ? edu_push_country_name((string)$r['v']) : ($field === 'device' ? ucfirst((string)$r['v']) : (string)$r['l']);
        $out[] = ['v' => (string)$r['v'], 'l' => $l, 'n' => (int)$r['n']];
    }
    $unknown = (int)edu_push_db()->query("SELECT COUNT(*) FROM push_subscriptions WHERE `$field` = ''")->fetchColumn();
    if ($unknown) $out[] = ['v' => 'Unknown', 'l' => 'Unknown', 'n' => $unknown];
    return $out;
}

/** Delivery log of one notification: totals, why some failed, and the latest deliveries. */
function edu_push_delivery_log(int $campaignId): array
{
    $pdo = edu_push_db();
    $c = $pdo->prepare("SELECT * FROM push_campaigns WHERE id = ?");
    $c->execute([$campaignId]);
    $camp = $c->fetch(PDO::FETCH_ASSOC);
    if (!$camp) return ['error' => 'Not found'];
    $st = $pdo->prepare("SELECT status, COUNT(*) n, MIN(created_at) first, MAX(processed_at) last FROM push_queue WHERE campaign_id = ? GROUP BY status");
    $st->execute([$campaignId]);
    $by = []; $first = null; $last = null;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $by[$r['status']] = (int)$r['n']; $first = $first ?? $r['first']; if ($r['last'] && (!$last || $r['last'] > $last)) $last = $r['last']; }
    $er = $pdo->prepare("SELECT COALESCE(NULLIF(last_error, ''), 'Unknown error') e, COUNT(*) n FROM push_queue WHERE campaign_id = ? AND status = 'failed' GROUP BY e ORDER BY n DESC LIMIT 10");
    $er->execute([$campaignId]);
    $errors = [];
    foreach ($er->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $e = (string)$r['e'];
        $why = preg_match('/\b(404|410)\b/', $e) ? 'Subscription expired — the reader unsubscribed or cleared the browser (removed automatically)'
            : (preg_match('/\b(401|403)\b/', $e) ? 'Key mismatch — this browser subscribed with another key' : (preg_match('/timed? ?out|timeout/i', $e) ? 'The push service did not answer in time' : mb_strimwidth($e, 0, 140, '…')));
        $errors[] = ['why' => $why, 'n' => (int)$r['n']];
    }
    $rows = $pdo->prepare("SELECT q.status, q.attempts, q.processed_at, s.device, s.os, s.browser, s.country FROM push_queue q LEFT JOIN push_subscriptions s ON s.id = q.subscription_id WHERE q.campaign_id = ? ORDER BY q.id DESC LIMIT 50");
    $rows->execute([$campaignId]);
    $list = [];
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $list[] = ['status' => $r['status'], 'tries' => (int)$r['attempts'], 'at' => $r['processed_at'] ? date('j M, g:i:s a', strtotime($r['processed_at'])) : '',
            'who' => trim(implode(' · ', array_filter([$r['os'] ?: ($r['device'] ? ucfirst($r['device']) : ''), $r['browser'], $r['country'] ? edu_push_country_name($r['country']) : ''])) ) ?: 'Removed subscriber'];
    }
    return ['ok' => true, 'total' => (int)$camp['total_subscribers'], 'sent' => (int)$camp['sent'], 'failed' => (int)$camp['failed'], 'clicks' => (int)$camp['clicks'],
        'waiting' => (int)($by['pending'] ?? 0), 'expired' => in_array($camp['status'], ['pending', 'processing'], true) && strtotime((string)$camp['created_at']) < strtotime('-1 day'), 'url' => (string)$camp['url'], 'segment' => (string)($camp['segment_name'] ?: 'All subscribers'),
        'started' => $first ? date('j M Y, g:i:s a', strtotime($first)) : '', 'finished' => $last && empty($by['pending']) ? date('j M Y, g:i:s a', strtotime($last)) : '',
        'errors' => $errors, 'rows' => $list];
}

/** Is this IP a public internet address? (no private, loopback, link-local or reserved ranges) */
function edu_push_public_ip(string $ip): bool
{
    return (bool)filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

/** Shortens text to about $max characters, ending at a word. */
function edu_push_short(string $s, int $max = 160): string
{
    $t = trim(preg_replace('/\s+/u', ' ', $s));
    if (mb_strlen($t) <= $max) return $t;
    $cut = mb_substr($t, 0, $max);
    $sp = mb_strrpos($cut, ' ');
    return rtrim(mb_substr($cut, 0, $sp !== false && $sp > $max - 30 ? $sp : $max), " ,;:.-–") . '…';
}

/**
 * LaraPush-style "fetch from URL": title, a short text, the featured image and the site icon of any article.
 * This site's own posts come from the database; any other site (WordPress or not) from its page tags.
 * Only public addresses are fetched (checked again after every redirect, and the connection is pinned to that IP).
 */
function edu_push_fetch_meta(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') return ['error' => 'Paste an article link first.'];
    if (!preg_match('#^https?://#i', $raw)) $raw = 'https://' . $raw;
    $p = parse_url($raw);
    if (empty($p['host'])) return ['error' => "That doesn't look like a web address."];
    $bare = fn($h) => preg_replace('/^www\./', '', strtolower((string)$h));
    $own = $bare(parse_url(SITE_URL, PHP_URL_HOST));

    if ($bare($p['host']) === $own) {
        $pdo = edu_push_db();
        $path = trim((string)($p['path'] ?? ''), '/');
        parse_str((string)($p['query'] ?? ''), $qs);
        $id = 0;
        if (!empty($qs['id'])) $id = (int)$qs['id'];
        elseif ($path !== '') {
            $map = @json_decode((string)@file_get_contents(ROOT_PATH . '/.edu-slugmap.json'), true);
            if (is_array($map) && isset($map[$path])) $id = (int)$map[$path];
            if (!$id) { $st = $pdo->prepare("SELECT id FROM posts WHERE slug = ? AND status = 'published' ORDER BY id DESC LIMIT 1"); $st->execute([$path]); $id = (int)$st->fetchColumn(); }
            if (!$id && preg_match('/-(\d+)$/', $path, $m)) $id = (int)$m[1];
        }
        if ($id) {
            $st = $pdo->prepare("SELECT p.id, p.title, p.slug, p.content, m.file_path, pm.meta_value AS descr FROM posts p LEFT JOIN media m ON m.id = p.featured_image_id
                LEFT JOIN post_meta pm ON pm.post_id = p.id AND pm.meta_key = 'description' WHERE p.id = ? AND p.status = 'published'");
            $st->execute([$id]);
            if ($post = $st->fetch(PDO::FETCH_ASSOC)) {
                $text = (string)($post['descr'] ?: html_entity_decode(strip_tags((string)$post['content']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                return ['ok' => true, 'title' => mb_substr((string)$post['title'], 0, 150), 'body' => edu_push_short($text), 'url' => edu_push_abs(postUrl($post['slug'], $post['id'])),
                    'image' => $post['file_path'] ? edu_push_abs('/' . ltrim($post['file_path'], '/')) : '', 'icon' => edu_push_abs(edu_push_default_icon()), 'site' => $own, 'post_id' => (int)$post['id']];
            }
        }
    }
    if (!function_exists('curl_init')) return ['error' => 'This server cannot fetch other websites (cURL is missing).'];

    $url = $raw;
    $html = ''; $final = '';
    for ($hop = 0; $hop < 5; $hop++) {
        $u = parse_url($url);
        $scheme = strtolower((string)($u['scheme'] ?? ''));
        $host = (string)($u['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') return ['error' => 'Only http(s) links can be fetched.'];
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if (!$ips) return ['error' => "Couldn't find that website."];
        foreach ($ips as $ip) if (!edu_push_public_ip($ip)) return ['error' => "This address can't be fetched."];
        $port = (int)($u['port'] ?? ($scheme === 'https' ? 443 : 80));
        $buf = ''; $status = 0; $loc = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RESOLVE => ["$host:$port:{$ips[0]}"], CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 9, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_ENCODING => '', CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml', 'Accept-Language: en-IN,en;q=0.9,hi;q=0.8'],
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36',
            CURLOPT_HEADERFUNCTION => function ($ch, $h) use (&$loc) { if (stripos($h, 'location:') === 0) $loc = trim(substr($h, 9)); return strlen($h); },
            CURLOPT_WRITEFUNCTION => function ($ch, $d) use (&$buf) { $buf .= $d; return (strlen($buf) > 1500000 || stripos($buf, '</head>') !== false) ? 0 : strlen($d); },
        ]);
        curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        if ($status >= 300 && $status < 400 && $loc !== '') { $url = edu_push_resolve_url($loc, $url); continue; }
        if ($errno && $errno !== 23 && $buf === '') return ['error' => "Couldn't load that page (timeout or blocked)."];
        if ($status >= 400) return ['error' => "The site answered $status."];
        $html = $buf; $final = $url;
        break;
    }
    if ($final === '') return ['error' => 'Too many redirects.'];
    if (preg_match('/<meta[^>]+charset=["\']?([\w-]+)/i', $html, $m) && strcasecmp($m[1], 'utf-8') !== 0) $html = (string)@mb_convert_encoding($html, 'UTF-8', $m[1]);

    $attr = function (string $tag, string $name): string {
        return preg_match('/\s' . $name . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $tag, $m) ? (string)($m[1] !== '' ? $m[1] : ($m[2] ?? '') . ($m[3] ?? '')) : '';
    };
    preg_match_all('/<meta\b[^>]*>/i', $html, $metas);
    $meta = function (array $names) use ($metas, $attr): string {
        foreach ($metas[0] as $tag) {
            $k = strtolower($attr($tag, 'property') ?: $attr($tag, 'name'));
            if (in_array($k, $names, true) && trim($c = $attr($tag, 'content')) !== '') return html_entity_decode($c, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return '';
    };
    $title = $meta(['og:title', 'twitter:title']) ?: (preg_match('/<title[^>]*>([^<]*)<\/title>/i', $html, $m) ? html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : '');
    $siteName = $meta(['og:site_name']);
    $title = trim($title);
    if ($siteName !== '' && mb_strlen($title) > mb_strlen($siteName) + 10) $title = trim(preg_replace('/\s*[|\-–—:·]\s*' . preg_quote($siteName, '/') . '\s*$/iu', '', $title));
    if ($title === '') return ['error' => 'No title found on that page.'];
    $image = $meta(['og:image', 'og:image:url', 'og:image:secure_url', 'twitter:image', 'twitter:image:src']);
    $icon = ''; $best = -1;
    preg_match_all('/<link\b[^>]*>/i', $html, $links);
    foreach ($links[0] as $tag) {
        $rel = strtolower($attr($tag, 'rel'));
        if (!preg_match('/(^|\s)(icon|apple-touch-icon(-precomposed)?)(\s|$)/', $rel)) continue;
        $href = html_entity_decode($attr($tag, 'href'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($href === '' || preg_match('/\.svg(\?|$)/i', $href) || str_starts_with($href, 'data:')) continue;
        $size = preg_match('/(\d+)x\d+/', $attr($tag, 'sizes'), $m) ? (int)$m[1] : (str_contains($rel, 'apple') ? 180 : 32);
        if ($size > $best) { $best = $size; $icon = $href; }
    }
    $origin = parse_url($final, PHP_URL_SCHEME) . '://' . parse_url($final, PHP_URL_HOST);
    $canon = $meta(['og:url']);
    return ['ok' => true, 'title' => edu_push_short($title, 150), 'body' => edu_push_short($meta(['og:description', 'twitter:description', 'description'])),
        'url' => $canon !== '' ? edu_push_resolve_url($canon, $final) : $final, 'image' => $image !== '' ? edu_push_resolve_url($image, $final) : '',
        'icon' => $icon !== '' ? edu_push_resolve_url($icon, $final) : $origin . '/favicon.ico', 'site' => $bare(parse_url($final, PHP_URL_HOST)), 'post_id' => null];
}

/** Absolute address of $ref as seen from page $base. */
function edu_push_resolve_url(string $ref, string $base): string
{
    $ref = trim($ref);
    if (preg_match('#^https?://#i', $ref)) return $ref;
    $b = parse_url($base);
    $origin = ($b['scheme'] ?? 'https') . '://' . ($b['host'] ?? '') . (isset($b['port']) ? ':' . $b['port'] : '');
    if (str_starts_with($ref, '//')) return ($b['scheme'] ?? 'https') . ':' . $ref;
    if (str_starts_with($ref, '/')) return $origin . $ref;
    $dir = preg_replace('#/[^/]*$#', '/', (string)($b['path'] ?? '/'));
    return $origin . ($dir ?: '/') . $ref;
}

/** Country name from a two-letter code ("IN" → "India"). */
function edu_push_country_name(string $code): string
{
    if ($code === '') return 'Unknown';
    if (class_exists('Locale')) {
        $n = Locale::getDisplayRegion('-' . $code, 'en');
        if ($n !== '' && $n !== $code) return $n;
    }
    return $code;
}

/** Links on this site get utm_source=push&utm_campaign=ID, so Analytics shows push traffic and counts the clicks. */
function edu_push_track_url(string $url, int $campaignId): string
{
    $host = parse_url($url, PHP_URL_HOST);
    $own = parse_url(SITE_URL, PHP_URL_HOST);
    if ($url === '' || ($host && strcasecmp(preg_replace('/^www\./', '', $host), preg_replace('/^www\./', '', (string)$own)) !== 0) || str_contains($url, 'utm_source=')) return $url;
    [$base, $frag] = array_pad(explode('#', $url, 2), 2, null);
    return $base . (str_contains($base, '?') ? '&' : '?') . 'utm_source=push&utm_medium=notification&utm_campaign=' . $campaignId . ($frag !== null ? '#' . $frag : '');
}
