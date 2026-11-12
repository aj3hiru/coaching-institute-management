<?php
// Site settings saved from the admin (General Settings, Customize, SEO, Code Snippets, Performance …).
// Each group is one JSON row in app_config ("edu_general", "edu_theme", …), read once per request.
// Anything not saved yet falls back to the defaults given by the caller (usually the constants in config.php).

// Remember the database handle of whoever loaded this file (pages may run inside a function scope).
if (isset($pdo) && $pdo instanceof PDO) $GLOBALS['__edu_pdo'] = $pdo;

if (!function_exists('edu_db')) {
    function edu_db(): ?PDO
    {
        return $GLOBALS['__edu_pdo'] ?? ($GLOBALS['pdo'] ?? null);
    }
}

if (!function_exists('edu_settings')) {
    function edu_settings(string $group): array
    {
        $cache = &$GLOBALS['__edu_settings_cache'];
        if (!is_array($cache)) $cache = [];
        if (array_key_exists($group, $cache)) return $cache[$group];
        $cache[$group] = [];
        $pdo = edu_db();
        if (!$pdo) return [];
        try {
            $st = $pdo->prepare("SELECT config_value FROM app_config WHERE config_key = ? LIMIT 1");
            $st->execute(['edu_' . $group]);
            $raw = $st->fetchColumn();
            $cache[$group] = $raw ? (json_decode($raw, true) ?: []) : [];
            // Customizer preview (?czp=1): an admin sees their unsaved changes on top.
            if (isset($_GET['czp']) && in_array($_SESSION['role'] ?? '', ['admin', 'blog_admin'], true) && !empty($_SESSION['edu_draft'][$group]) && is_array($_SESSION['edu_draft'][$group])) {
                $cache[$group] = array_merge($cache[$group], $_SESSION['edu_draft'][$group]);
            }
        } catch (Throwable $e) {
            $cache[$group] = [];
        }
        return $cache[$group];
    }

    /** One setting: es('general', 'tagline', 'default'). Empty strings count as "not set". */
    function es(string $group, string $key, $default = '')
    {
        $all = edu_settings($group);
        return (isset($all[$key]) && $all[$key] !== '') ? $all[$key] : $default;
    }

    /** Saves a whole group (merged over what is stored) and clears the page cache so visitors see it at once. */
    /** The site logo always lives at /logo.webp. Any picture chosen as the logo (JPG, PNG, GIF, WebP — from the media
        library, a path or a link) is turned into WebP and written there, replacing the previous logo; an empty logo
        removes the file. Returns what is stored: '/logo.webp?v=TIME' (the time makes browsers fetch the new one), '' when
        removed, the chosen path when it cannot be turned into WebP (e.g. an SVG), or the current logo when the picture
        cannot be read at all. */
    function edu_logo_store(string $val, string $old = ''): string
    {
        $val = trim($val); $dest = ROOT_PATH . '/logo.webp';
        if ($val === '') { if (is_file($dest)) @unlink($dest); return ''; }
        if (preg_match('#^/logo\.webp(\?|$)#', $val)) return $val; // already the logo
        $path = (string)parse_url($val, PHP_URL_PATH); $host = (string)parse_url($val, PHP_URL_HOST);
        $own = $host === '' || strcasecmp(preg_replace('/^www\./', '', $host), preg_replace('/^www\./', '', (string)parse_url(SITE_URL, PHP_URL_HOST))) === 0;
        $data = '';
        if ($own && $path !== '' && !str_contains($path, '..')) { $f = ROOT_PATH . '/' . ltrim(rawurldecode($path), '/'); if (is_file($f) && filesize($f) <= 8 * 1048576) $data = (string)file_get_contents($f); }
        elseif (preg_match('#^https?://#i', $val) && function_exists('curl_init')) {
            $ch = curl_init($val); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_TIMEOUT => 10, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS]);
            $data = (string)curl_exec($ch); curl_close($ch); if (strlen($data) > 8 * 1048576) $data = '';
        }
        if ($data === '') return $old; // the picture could not be read (wrong path, missing file): the current logo stays
        $isWebp = substr($data, 0, 4) === 'RIFF' && substr($data, 8, 4) === 'WEBP';
        $out = '';
        if (function_exists('imagecreatefromstring') && function_exists('imagewebp') && ($im = @imagecreatefromstring($data))) {
            $w = imagesx($im); $h = imagesy($im); $max = 1200;
            if ($w > $max) { $nh = max(1, (int)round($h * $max / $w)); $n = imagecreatetruecolor($max, $nh); imagealphablending($n, false); imagesavealpha($n, true);
                imagefill($n, 0, 0, imagecolorallocatealpha($n, 0, 0, 0, 127)); imagecopyresampled($n, $im, 0, 0, 0, 0, $max, $nh, $w, $h); imagedestroy($im); $im = $n; }
            if (!imageistruecolor($im)) imagepalettetotruecolor($im);
            imagealphablending($im, false); imagesavealpha($im, true); // keep a see-through background
            ob_start(); imagewebp($im, null, 90); $out = (string)ob_get_clean(); imagedestroy($im);
        } elseif ($isWebp) $out = $data; // no image library: a WebP logo is used as it is
        if ($out === '') { if (is_file($dest)) @unlink($dest); return $val; } // e.g. an SVG: kept at its own address (the old /logo.webp goes)
        $tmp = $dest . '.tmp' . bin2hex(random_bytes(3));
        if (@file_put_contents($tmp, $out) === false || !@rename($tmp, $dest)) { @unlink($tmp); return $val; }
        @chmod($dest, 0644);
        return '/logo.webp?v=' . time();
    }

    function edu_settings_save(string $group, array $values): void
    {
        if ($group === 'general' && array_key_exists('logo', $values)) { // the logo always goes to /logo.webp
            $old = (string)(edu_settings('general')['logo'] ?? '');
            $new = trim((string)$values['logo']);
            $values['logo'] = $new === $old && preg_match('#^/logo\.webp(\?|$)#', $new) ? $new : edu_logo_store($new, $old);
        }
        $pdo = edu_db();
        $merged = array_merge(edu_settings($group), $values);
        $json = json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $key = 'edu_' . $group;
        $exists = $pdo->prepare("SELECT COUNT(*) FROM app_config WHERE config_key = ?");
        $exists->execute([$key]);
        if ($exists->fetchColumn()) {
            $pdo->prepare("UPDATE app_config SET config_value = ? WHERE config_key = ?")->execute([$json, $key]);
        } else {
            $pdo->prepare("INSERT INTO app_config (config_key, config_value) VALUES (?, ?)")->execute([$key, $json]);
        }
        // Later reads (and saves) in this same request see the new values.
        $GLOBALS['__edu_settings_cache'][$group] = $merged;
        edu_clear_page_cache();
    }

    function edu_clear_page_cache(): int
    {
        $n = 0;
        foreach (glob(ROOT_PATH . '/cache/*.html') ?: [] as $f) {
            if (@unlink($f)) $n++;
        }
        return $n;
    }

    /** Social links: saved in General Settings, else the constants in config.php. */
    function edu_social(): array
    {
        $map = [
            'facebook' => 'SOCIAL_FACEBOOK', 'twitter' => 'SOCIAL_TWITTER', 'instagram' => 'SOCIAL_INSTAGRAM',
            'whatsapp' => 'SOCIAL_WHATSAPP', 'telegram' => 'SOCIAL_TELEGRAM', 'linkedin' => 'SOCIAL_LINKEDIN',
            'threads' => 'SOCIAL_THREADS', 'youtube' => '',
        ];
        $out = [];
        foreach ($map as $k => $const) {
            $out[$k] = (string)es('general', 'social_' . $k, ($const && defined($const)) ? constant($const) : '');
        }
        return $out;
    }
}
