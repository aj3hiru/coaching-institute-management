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
            if (isset($_GET['czp']) && ($_SESSION['role'] ?? '') === 'admin' && !empty($_SESSION['edu_draft'][$group]) && is_array($_SESSION['edu_draft'][$group])) {
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
    function edu_settings_save(string $group, array $values): void
    {
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
