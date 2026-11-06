<?php
require_once __DIR__ . '/security.php';
// Site-wide helpers loaded by config.php: the site title (editable in Customize → Site Identity, else the
// constant from config.php) and the shortcodes that work in posts, pages, the footer, the top bar and SEO texts.

// Remember the database handle of config.php (pages may later run inside a function scope).
if (isset($pdo) && $pdo instanceof PDO && empty($GLOBALS['__edu_pdo'])) $GLOBALS['__edu_pdo'] = $pdo;

// Stay signed in until Log out (the server deletes idle sessions after 24 minutes)
require_once __DIR__ . '/remember.php';
if (isset($pdo) && $pdo instanceof PDO && !empty($_COOKIE[EDU_RM_COOKIE])) edu_rm_restore($pdo);

if (!function_exists('edu_text_lang')) {
    /** 'hi' when a text is mostly Hindi (Devanagari letters), else 'en' — used for lang, og:locale, schema and the news sitemap. */
    function edu_text_lang(string $text): string
    {
        $t = html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8');
        $hi = preg_match_all('/\p{Devanagari}/u', $t);
        $en = preg_match_all('/[A-Za-z]/', $t);
        return $hi > 0 && $hi >= ($hi + $en) * 0.3 ? 'hi' : 'en';
    }
}
if (!function_exists('edu_site_name')) {
    /** The site title: Customize → Site Identity → Site Title, else SITE_NAME from config.php. */
    function edu_site_name(): string
    {
        static $name = null;
        // the Customizer preview shows an unsaved title, so it is not kept for the request there
        if ($name !== null && !isset($_GET['czp'])) return $name;
        require_once __DIR__ . '/settings.php';
        $saved = trim((string)es('general', 'site_title', ''));
        return $name = ($saved !== '' ? $saved : (defined('SITE_NAME') ? SITE_NAME : 'Site'));
    }

    /**
     * Header logo size, WordPress-style: one width, the height follows the image's own shape.
     * Before "Logo width" was saved once, it starts at the size the logo had with the old width + height limits.
     * Returns [width, height or 0 when the image size is unknown].
     */
    function edu_logo_size(): array
    {
        require_once __DIR__ . '/settings.php';
        $logo = trim((string)es('general', 'logo', ''));
        $ratio = 0.0;
        if ($logo !== '' && !preg_match('#^(https?:)?//#i', $logo)) {
            $root = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__);
            $file = $root . '/' . ltrim(parse_url($logo, PHP_URL_PATH) ?: '', '/');
            if (is_file($file) && ($info = @getimagesize($file)) && $info[0] > 0 && $info[1] > 0) $ratio = $info[0] / $info[1];
        }
        $w = (int)es('general', 'logo_w', 0);
        if ($w <= 0) {
            $oldW = (int)es('general', 'logo_width', 150) ?: 150;
            $oldH = (int)es('general', 'logo_height', 48) ?: 48;
            $w = $ratio > 0 ? (int)round(min($oldW, $oldH * $ratio)) : $oldW;
        }
        $w = max(20, min(600, $w));
        return [$w, $ratio > 0 ? (int)round($w / $ratio) : 0];
    }

    /** The shortcodes shown in Customize → Shortcodes. */
    function edu_shortcode_help(): array
    {
        return [
            ['[site_title]', 'Site title (Site Identity)'],
            ['[site_url]', 'Site address, e.g. https://example.com (also [site-url])'],
            ['[site_link]', 'Site title linked to the homepage'],
            ['[site_tagline]', 'Tagline (Site Identity)'],
            ['[site_domain]', 'Domain only, e.g. example.com'],
            ['[contact_email]', 'Admin / contact email'],
            ['[year]', 'Current year'],
            ['[date]', "Today's date"],
            ['[contact_form]', 'Contact form (name, email, subject, message) — submissions go to Contact Messages'],
        ];
    }

    /**
     * Replaces the value shortcodes ([site_title], [year] …). The older {year} / {site} placeholders still work.
     * $plain = text without HTML (titles, meta descriptions). [contact_form] is left for edu_shortcodes_html().
     */
    function edu_shortcodes(?string $s, bool $plain = false): string
    {
        $s = (string)$s;
        if ($s === '' || (!str_contains($s, '[') && !str_contains($s, '{'))) return $s;
        require_once __DIR__ . '/settings.php';
        $name = edu_site_name();
        $url = rtrim(defined('SITE_URL') ? SITE_URL : '', '/');
        $email = (string)es('general', 'contact_email', defined('CONTACT_EMAIL') ? CONTACT_EMAIL : '');
        $h = fn($v) => $plain ? $v : htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $map = [
            'site_title' => $h($name), 'site_name' => $h($name), 'site_url' => $h($url), 'home_url' => $h($url),
            'site_link' => $plain ? $name : '<a href="' . htmlspecialchars($url . '/') . '">' . htmlspecialchars($name) . '</a>',
            'site_tagline' => $h((string)es('general', 'tagline', '')), 'site_domain' => $h((string)parse_url($url, PHP_URL_HOST)),
            'contact_email' => $h($email), 'admin_email' => $h($email),
            'year' => date('Y'), 'date' => date('F j, Y'),
        ];
        $s = preg_replace_callback('/\[([a-z_-]+)\s*\/?\]/i', function ($m) use ($map) {
            $k = str_replace('-', '_', strtolower($m[1]));
            return $map[$k] ?? $m[0];
        }, $s);
        return strtr($s, ['{year}' => $map['year'], '{site}' => $map['site_title']]);
    }

    /** HTML with every shortcode, including the [contact_form] (once per page). */
    function edu_shortcodes_html(?string $html): string
    {
        $html = edu_shortcodes($html);
        if (!str_contains($html, '[contact_form]')) return $html;
        if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
        $tok = $_SESSION['cTkn'] ??= bin2hex(random_bytes(32));
        $form = '<div class="edu-cform"><form id="contactForm" class="contact-form" aria-label="Contact form"><input type="hidden" name="cTkn" value="' . $tok . '">'
            . '<div class="cf-row"><div class="cf-g"><label for="cf-name">Your Name</label><input type="text" id="cf-name" name="name" required maxlength="120" autocomplete="name"></div>'
            . '<div class="cf-g"><label for="cf-email">Your Email</label><input type="email" id="cf-email" name="email" required maxlength="254" autocomplete="email"></div></div>'
            . '<div class="cf-g"><label for="cf-subject">Subject</label><input type="text" id="cf-subject" name="subject" required maxlength="200"></div>'
            . '<div class="cf-g"><label for="cf-message">Message</label><textarea id="cf-message" name="message" rows="6" required></textarea></div>'
            . '<button type="submit" class="cf-btn">Send Message</button><div id="formResponse" class="cf-resp" role="status" aria-live="polite"></div></form></div>'
            . '<script>document.getElementById("contactForm").addEventListener("submit",async function(e){e.preventDefault();var t=e.target,r=document.getElementById("formResponse"),b=t.querySelector(".cf-btn"),o=b.innerHTML;r.className="cf-resp";r.textContent="";b.disabled=true;b.innerHTML="Sending…";try{var x=await fetch("/api/2c4a5d7f8h.php",{method:"POST",body:new FormData(t)}),m=await x.text();r.className="cf-resp "+(x.ok?"ok":"err");r.textContent=m.replace(/<[^>]*>/g,"");if(x.ok)t.reset()}catch(err){r.className="cf-resp err";r.textContent="Connection failed. Please try again."}finally{b.disabled=false;b.innerHTML=o}});</script>';
        $html = preg_replace('#<p>\s*\[contact_form\]\s*</p>|\[contact_form\]#', $form, $html, 1);
        return str_replace('[contact_form]', '', $html);
    }
}
