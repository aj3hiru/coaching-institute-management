<?php
// Instant indexing (like Rank Math's Instant Indexing): tells Google (Indexing API) and Bing/Yandex/Seznam (IndexNow)
// about new, updated or deleted addresses. Submissions wait in a small queue that the every-minute cron sends.
require_once __DIR__ . '/seo.php';

function edu_ix_table(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS seo_index_log (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, url VARCHAR(500) NOT NULL, action ENUM('update','delete') NOT NULL DEFAULT 'update',
        engine VARCHAR(20) NOT NULL, status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending', response VARCHAR(500) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, sent_at DATETIME NULL, KEY idx_status (status), KEY idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** The IndexNow key — made once and published as /assets/indexnow/{key}.txt (search engines check it via keyLocation). */
function edu_ix_key(): string
{
    $key = (string)seo_opt('ix_indexnow_key');
    if (!preg_match('/^[a-f0-9]{32}$/', $key)) {
        $key = bin2hex(random_bytes(16));
        edu_settings_save('seo', ['ix_indexnow_key' => $key]);
    }
    $d = ROOT_PATH . '/assets/indexnow';
    if (!is_dir($d)) @mkdir($d, 0755, true);
    if (!is_file("$d/$key.txt")) @file_put_contents("$d/$key.txt", $key);
    return $key;
}

/** Where the key file is published (kept out of the site's top folder). */
function edu_ix_key_url(string $key): string
{
    return rtrim(SITE_URL, '/') . "/assets/indexnow/$key.txt";
}

function edu_ix_google_creds(): ?array
{
    $j = json_decode((string)seo_opt('ix_google_json'), true);
    return is_array($j) && !empty($j['client_email']) && !empty($j['private_key']) ? $j : null;
}

/** Queue addresses for the engines that are switched on. $action: update | delete. Returns how many were queued. */
function edu_ix_queue(PDO $pdo, array $urls, string $action = 'update', bool $force = false): int
{
    if (!$force && seo_opt('ix_auto') !== '1') return 0;
    if (seo_opt('noindex_site') === '1') return 0;
    edu_ix_table($pdo);
    $engines = [];
    if (edu_ix_google_creds()) $engines[] = 'google';
    if (seo_opt('ix_indexnow') === '1') $engines[] = 'indexnow';
    $n = 0;
    $ins = $pdo->prepare("INSERT INTO seo_index_log (url, action, engine) VALUES (?, ?, ?)");
    foreach (array_unique(array_filter(array_map('trim', $urls))) as $u) {
        if (!preg_match('#^https?://#', $u)) $u = rtrim(SITE_URL, '/') . '/' . ltrim($u, '/');
        foreach ($engines as $e) { $ins->execute([mb_substr($u, 0, 500), $action === 'delete' ? 'delete' : 'update', $e]); $n++; }
    }
    return $n;
}

function edu_ix_http(string $url, string $method, array $headers, string $body = ''): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 6]);
    if ($body !== '') curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return [$code, $res === false ? $err : (string)$res];
}

/** OAuth token for the Google Indexing API from the service-account key (cached ~55 minutes). */
function edu_ix_google_token(array $c): ?string
{
    $cache = edu_private_cache_file('google_token.json');
    $t = json_decode((string)@file_get_contents($cache), true);
    if (!empty($t['token']) && ($t['exp'] ?? 0) > time() + 60 && ($t['email'] ?? '') === $c['client_email']) return $t['token'];
    $b64 = fn($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    $now = time();
    $jwt = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . $b64(json_encode(['iss' => $c['client_email'], 'scope' => 'https://www.googleapis.com/auth/indexing', 'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600]));
    if (!openssl_sign($jwt, $sig, $c['private_key'], 'sha256WithRSAEncryption')) return null;
    [$code, $res] = edu_ix_http('https://oauth2.googleapis.com/token', 'POST', ['Content-Type: application/x-www-form-urlencoded'], http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt . '.' . $b64($sig)]));
    $d = json_decode($res, true);
    if ($code !== 200 || empty($d['access_token'])) return null;
    @file_put_contents($cache, json_encode(['token' => $d['access_token'], 'exp' => $now + (int)($d['expires_in'] ?? 3600) - 300, 'email' => $c['client_email']]));
    return $d['access_token'];
}

function edu_private_cache_file(string $name): string
{
    $dir = dirname(ROOT_PATH) . '/edumint24-private/cache';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return is_dir($dir) ? "$dir/$name" : sys_get_temp_dir() . '/edu-' . $name;
}

/** Sends what is waiting (called by the every-minute cron and by "Submit now"). Returns a short summary. */
function edu_ix_process(PDO $pdo, int $limit = 50): string
{
    edu_ix_table($pdo);
    $rows = $pdo->query("SELECT * FROM seo_index_log WHERE status = 'pending' ORDER BY id LIMIT " . (int)$limit)->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return '';
    $upd = $pdo->prepare("UPDATE seo_index_log SET status = ?, response = ?, sent_at = NOW() WHERE id = ?");
    $sent = $failed = 0;
    // IndexNow takes many addresses at once (per host)
    $now = array_filter($rows, fn($r) => $r['engine'] === 'indexnow');
    if ($now) {
        $key = edu_ix_key();
        $host = parse_url(SITE_URL, PHP_URL_HOST);
        $list = array_values(array_unique(array_map(fn($r) => $r['url'], $now)));
        [$code, $res] = edu_ix_http('https://api.indexnow.org/indexnow', 'POST', ['Content-Type: application/json; charset=utf-8'], json_encode(['host' => $host, 'key' => $key, 'keyLocation' => edu_ix_key_url($key), 'urlList' => $list]));
        $ok = $code >= 200 && $code < 300;
        $msg = $ok ? "HTTP $code — accepted" : "HTTP $code " . mb_substr(trim(strip_tags($res)), 0, 200);
        foreach ($now as $r) { $upd->execute([$ok ? 'sent' : 'failed', $msg, $r['id']]); $ok ? $sent++ : $failed++; }
    }
    $g = array_filter($rows, fn($r) => $r['engine'] === 'google');
    if ($g) {
        $c = edu_ix_google_creds();
        $token = $c ? edu_ix_google_token($c) : null;
        foreach ($g as $r) {
            if (!$token) { $upd->execute(['failed', 'Google key missing or not accepted — check the service account JSON', $r['id']]); $failed++; continue; }
            [$code, $res] = edu_ix_http('https://indexing.googleapis.com/v3/urlNotifications:publish', 'POST', ['Content-Type: application/json', 'Authorization: Bearer ' . $token], json_encode(['url' => $r['url'], 'type' => $r['action'] === 'delete' ? 'URL_DELETED' : 'URL_UPDATED']));
            $d = json_decode($res, true);
            $ok = $code === 200;
            $upd->execute([$ok ? 'sent' : 'failed', $ok ? 'HTTP 200 — Google received it' : "HTTP $code " . mb_substr((string)($d['error']['message'] ?? $res), 0, 300), $r['id']]);
            $ok ? $sent++ : $failed++;
        }
    }
    return "indexing: $sent sent" . ($failed ? ", $failed failed" : '');
}
