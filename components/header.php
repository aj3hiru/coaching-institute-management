<?php
// Racing-style header (same design as the new CMS): social icons · logo · actions on top, menu strip below,
// slide-in drawer on phones. Bell = push notifications, search = the search overlay (with voice search).
require_once INCLUDES_PATH . '/theme.php';
require_once INCLUDES_PATH . '/push.php';
// Push → Settings → "Collect subscribers" off: no bell and no push Subscribe button
try { $__pushOn = edu_push_collecting(); } catch (Throwable $e) { $__pushOn = false; }
// Menu from Customize → Menus; icons picked by the link's address.
$__iconFor = function (string $url): string {
    $map = ['/categories' => 'fa-table-cells-large', '/state-jobs' => 'fa-map-location-dot', '/latest-updates' => 'fa-bolt', '/latest-jobs' => 'fa-briefcase',
            '/about' => 'fa-circle-info', '/contact' => 'fa-envelope'];
    if ($url === '/') return 'fa-house';
    foreach ($map as $k => $v) if (strpos($url, $k) === 0) return $v;
    return 'fa-link';
};
// Primary Menu (mobile menu, items may have a sub-menu) and the Menu Strip (own links or all categories)
$__menu = array_map(fn($m) => ['label' => (string)$m['label'], 'url' => (string)$m['url'], 'newTab' => !empty($m['newTab']), 'icon' => $__iconFor((string)$m['url']), 'children' => (array)($m['children'] ?? [])], (array)th('menu', []));
$__strip = [];
if (th('strip_source') === 'categories') {
    try {
        $__sdb = function_exists('edu_db') ? edu_db() : null;
        if ($__sdb) foreach ($__sdb->query("SELECT name, slug FROM categories ORDER BY views DESC, name ASC LIMIT 60") as $c) $__strip[] = ['label' => $c['name'], 'url' => '/categories/' . $c['slug'], 'newTab' => false];
    } catch (Throwable $e) { $__strip = []; }
} else {
    $__strip = array_map(fn($m) => ['label' => (string)$m['label'], 'url' => (string)$m['url'], 'newTab' => !empty($m['newTab'])], (array)th('strip_items', []));
}
$__tgt = fn($m) => !empty($m['newTab']) ? ' target="_blank" rel="noopener"' : '';
$__ico = [
    'fa-house' => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/><path d="M10 21v-6h4v6"/>',
    'fa-table-cells-large' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
    'fa-map-location-dot' => '<path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>',
    'fa-bolt' => '<path d="M13 2 4 14h7l-1 8 9-12h-7z"/>',
    'fa-briefcase' => '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"/><path d="M3 13h18"/>',
    'fa-circle-info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
    'fa-envelope' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
    'fa-link' => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
];
$__path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$__active = function (string $url) use ($__path): bool {
    return $url === '/' ? $__path === '/' : strpos($__path, $url) === 0;
};
require_once INCLUDES_PATH . '/settings.php';
$__so = edu_social();
$__socials = array_filter([
    ['Facebook', $__so['facebook'], 'M9.101 23.691v-7.98H6.627v-3.667h2.474v-1.58c0-4.085 1.848-5.978 5.858-5.978.401 0 .955.042 1.468.103a8.68 8.68 0 0 1 1.141.195v3.325a8.623 8.623 0 0 0-.653-.036 26.805 26.805 0 0 0-.733-.009c-.707 0-1.259.096-1.675.309a1.686 1.686 0 0 0-.679.622c-.258.42-.374.995-.374 1.752v1.297h3.919l-.386 2.103-.287 1.564h-3.246v8.245C19.396 23.238 24 18.179 24 12.044c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.628 3.874 10.35 9.101 11.647Z'],
    ['X', $__so['twitter'], 'M18.901 1.153h3.68l-8.04 9.19L24 22.846h-7.406l-5.8-7.584-6.638 7.584H.474l8.6-9.83L0 1.154h7.594l5.243 6.932ZM17.61 20.644h2.039L6.486 3.24H4.298Z'],
    ['WhatsApp', $__so['whatsapp'], 'M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413Z'],
    ['Telegram', $__so['telegram'], 'M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z'],
    ['Instagram', $__so['instagram'], 'M12 2.16c3.2 0 3.58.01 4.85.07 1.17.05 1.8.25 2.23.41.56.22.96.48 1.38.9.42.42.68.82.9 1.38.16.42.36 1.06.41 2.23.06 1.27.07 1.65.07 4.85s-.01 3.58-.07 4.85c-.05 1.17-.25 1.8-.41 2.23-.22.56-.48.96-.9 1.38-.42.42-.82.68-1.38.9-.42.16-1.06.36-2.23.41-1.27.06-1.65.07-4.85.07s-3.58-.01-4.85-.07c-1.17-.05-1.8-.25-2.23-.41a3.72 3.72 0 0 1-1.38-.9 3.72 3.72 0 0 1-.9-1.38c-.16-.42-.36-1.06-.41-2.23C2.17 15.58 2.16 15.2 2.16 12s.01-3.58.07-4.85c.05-1.17.25-1.8.41-2.23.22-.56.48-.96.9-1.38.42-.42.82-.68 1.38-.9.42-.16 1.06-.36 2.23-.41C8.42 2.17 8.8 2.16 12 2.16ZM12 0C8.74 0 8.33.01 7.05.07 5.78.13 4.9.33 4.14.63c-.79.31-1.46.72-2.13 1.38A5.88 5.88 0 0 0 .63 4.14C.33 4.9.13 5.78.07 7.05.01 8.33 0 8.74 0 12s.01 3.67.07 4.95c.06 1.27.26 2.15.56 2.91.31.79.72 1.46 1.38 2.13a5.88 5.88 0 0 0 2.13 1.38c.76.3 1.64.5 2.91.56C8.33 23.99 8.74 24 12 24s3.67-.01 4.95-.07c1.27-.06 2.15-.26 2.91-.56a5.88 5.88 0 0 0 2.13-1.38 5.88 5.88 0 0 0 1.38-2.13c.3-.76.5-1.64.56-2.91.06-1.28.07-1.69.07-4.95s-.01-3.67-.07-4.95c-.06-1.27-.26-2.15-.56-2.91a5.88 5.88 0 0 0-1.38-2.13A5.88 5.88 0 0 0 19.86.63C19.1.33 18.22.13 16.95.07 15.67.01 15.26 0 12 0Zm0 5.84a6.16 6.16 0 1 0 0 12.32 6.16 6.16 0 0 0 0-12.32ZM12 16a4 4 0 1 1 0-8 4 4 0 0 1 0 8Zm6.4-11.85a1.44 1.44 0 1 0 0 2.88 1.44 1.44 0 0 0 0-2.88Z'],
    ['YouTube', $__so['youtube'], 'M23.5 6.19a3.02 3.02 0 0 0-2.12-2.14C19.5 3.55 12 3.55 12 3.55s-7.5 0-9.38.5A3.02 3.02 0 0 0 .5 6.19 31.6 31.6 0 0 0 0 12a31.6 31.6 0 0 0 .5 5.81 3.02 3.02 0 0 0 2.12 2.14c1.88.5 9.38.5 9.38.5s7.5 0 9.38-.5a3.02 3.02 0 0 0 2.12-2.14A31.6 31.6 0 0 0 24 12a31.6 31.6 0 0 0-.5-5.81ZM9.55 15.57V8.43L15.82 12l-6.27 3.57Z'],
    ['LinkedIn', $__so['linkedin'], 'M20.45 20.45h-3.56v-5.57c0-1.33-.02-3.04-1.85-3.04-1.85 0-2.14 1.45-2.14 2.94v5.67H9.35V9h3.41v1.56h.05c.48-.9 1.64-1.85 3.37-1.85 3.6 0 4.27 2.37 4.27 5.46v6.28ZM5.34 7.43a2.06 2.06 0 1 1 0-4.13 2.06 2.06 0 0 1 0 4.13ZM7.12 20.45H3.56V9h3.56v11.45ZM22.22 0H1.77C.79 0 0 .77 0 1.73v20.54C0 23.23.79 24 1.77 24h20.45c.98 0 1.78-.77 1.78-1.73V1.73C24 .77 23.2 0 22.22 0Z'],
], fn($s) => $s[1] !== '');
$__rss = 'M6.503 20.752c0 1.794-1.456 3.248-3.251 3.248-1.796 0-3.252-1.454-3.252-3.248 0-1.794 1.456-3.248 3.252-3.248 1.795.001 3.251 1.454 3.251 3.248zm-6.503-12.572v4.811c6.05.062 10.96 4.966 11.022 11.009h4.817c-.062-8.71-7.118-15.758-15.839-15.82zm0-3.368c10.58.046 19.152 8.594 19.183 19.188h4.817c-.03-13.231-10.755-23.954-24-24v4.812z';
$__logoSvg = '<svg width="120" height="45" viewBox="0 0 400 150" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="' . htmlspecialchars(edu_site_name()) . '"><defs><linearGradient id="grad1" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" style="stop-color:#F6AD55;stop-opacity:1"/><stop offset="100%" style="stop-color:#48BB78;stop-opacity:1"/></linearGradient></defs><rect x="20" y="50" width="300" height="80" fill="#1A365D"/><text x="35" y="110" font-family="Arial,sans-serif" font-size="50" font-weight="900" fill="white" letter-spacing="-1">EDUMINT</text><rect x="260" y="20" width="80" height="80" fill="url(#grad1)"/><text x="275" y="78" font-family="Arial,sans-serif" font-size="45" font-weight="bold" fill="white">24</text></svg>';
$__logoImg = es('general', 'logo');
[$__lw, $__lh] = edu_logo_size(); // one width, height follows the image (WordPress-style)
$__logo = ($__logoImg && es('general', 'header_logo', '1') === '1')
    ? '<img src="' . htmlspecialchars($__logoImg) . '"' . (trim((string)th('id_retina_logo')) !== '' ? ' srcset="' . htmlspecialchars($__logoImg) . ' 1x, ' . htmlspecialchars((string)th('id_retina_logo')) . ' 2x"' : '') . ' alt="' . htmlspecialchars(edu_site_name()) . '" class="nb-logo-img" style="width:' . $__lw . 'px;height:auto;max-width:100%" width="' . $__lw . '"' . ($__lh ? ' height="' . $__lh . '"' : '') . '>'
    : $__logoSvg;
// Site title / tagline next to the logo (Customize → Site Identity; hidden by default)
$__tagline = trim((string)es('general', 'tagline', ''));
$__brandText = (!th_on('id_hide_title') || (!th_on('id_hide_tagline') && $__tagline !== ''))
    ? '<span class="nb-site-branding">' . (!th_on('id_hide_title') ? '<span class="site-title">' . htmlspecialchars(edu_site_name()) . '</span>' : '')
        . (!th_on('id_hide_tagline') && $__tagline !== '' ? '<span class="site-tagline">' . htmlspecialchars($__tagline) . '</span>' : '') . '</span>'
    : '';
// School-style brand (Customize → Site Identity → Header Brand): round emblem, the name in big letters, the address under it
$__school = (string)th('id_brand_style', 'logo') === 'school';
if ($__school) {
    $__emb = trim((string)th('id_brand_emblem')) ?: '/assets/images/dk-academy-emblem.svg';
    $__bt = trim((string)th('id_header_title')) ?: edu_site_name();
    $__bl = trim((string)th('id_header_line')) ?: trim((string)es('general', 'address', ''));
    $__logo = '<img src="' . htmlspecialchars($__emb) . '" alt="" class="nb-emblem" width="52" height="60">';
    $__brandText = '<span class="nb-site-branding nb-sb-school"><span class="site-title">' . htmlspecialchars($__bt) . '</span>'
        . ($__bl !== '' ? '<span class="site-tagline nb-brand-line">' . htmlspecialchars($__bl) . '</span>' : '') . '</span>';
}
?>
<?php if (th_on('h_topbar') && trim((string)th('h_topbar_text')) !== ''): ?><div class="nb-top-bar"><?= edu_shortcodes((string)th('h_topbar_text')) ?></div><?php endif; ?>
<?php
        $__loginUrl = trim((string)th('h_login_url')) ?: '/admin-login';
        $__subUrl = trim((string)th('h_sub_url'));
        $__subAttr = $__subUrl !== '' ? 'href="' . htmlspecialchars($__subUrl) . '"' : 'href="#" role="button" data-edu-subscribe';
        $__subOn = th_on('h_sub') && ($__subUrl !== '' || $__pushOn);
        $__userIco = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>';
?>
<?php if ($__school): include __DIR__ . '/header-school.php'; else: ?>
<header class="nb-header nb-h-racing" id="site-navigation">
  <div class="nb-hr-top">
    <nav class="nb-hr-soc" aria-label="Social networks"<?= th_on('h_socials') ? '' : ' style="visibility:hidden"' ?>>
      <?php foreach ($__socials as [$name, $url, $path]): ?>
      <a href="<?= htmlspecialchars($url) ?>" target="_blank" rel="noopener nofollow" aria-label="<?= $name ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="<?= $path ?>"/></svg></a>
      <?php endforeach; ?>
      <a href="/feed/" target="_blank" rel="noopener" aria-label="RSS Feed"><svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="<?= $__rss ?>"/></svg></a>
    </nav>
    <div class="nb-hr-brand">
      <a href="/" title="<?= htmlspecialchars(edu_site_name()) ?> Home" rel="home" aria-label="Go to <?= htmlspecialchars(edu_site_name()) ?> homepage" class="nb-brand-link<?= $__school ? ' nb-brand-school' : '' ?>"><?= $__logo . $__brandText ?></a>
    </div>
    <div class="nb-hr-act">
      <?php if (th_on('h_bell') && $__pushOn): ?><span class="nb-bell-wrap nb-hr-bell">
        <button type="button" id="push-notify-btn" class="nb-bell" aria-label="Get notifications" title="Get notifications" style="display:none"
          onclick="window.eduPush&&eduPush.ask()">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.268 21a2 2 0 0 0 3.464 0"/><path d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326"/></svg>
          <span class="nb-bell-dot" aria-hidden="true"></span>
        </button>
      </span><?php endif; ?>
      <?php if (th_on('h_search')): ?><a href="/search" role="button" aria-label="Search" class="nb-search-icon" id="searchToggle" aria-expanded="false" aria-controls="eduHSearch"><span class="gp-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg></span></a><?php endif; ?>
      <?php if (th_on('h_login')): ?><a class="nb-hr-login" href="<?= htmlspecialchars($__loginUrl) ?>"><?= $__userIco ?><span><?= htmlspecialchars((string)th('h_login_text')) ?></span></a><?php endif; ?>
      <?php if (th_on('h_login') && $__subOn): ?><span class="nb-hr-div" aria-hidden="true"></span><?php endif; ?>
      <?php if ($__subOn): ?><a class="nb-hr-sub" <?= $__subAttr ?>><?= htmlspecialchars((string)th('h_sub_text')) ?></a><?php endif; ?>
<?php include __DIR__ . '/header-drawer.php'; ?>
    </div>
  </div>

  <nav class="nb-hr-nav" aria-label="Main navigation">
    <div class="inb-scroll-menu nb-strip nb-strip--plain nb-strip--center">
      <div class="inb-scroll-menu-inner">
        <?php foreach ($__strip as $m): ?><a href="<?= htmlspecialchars($m['url']) ?>"<?= $__active($m['url']) ? ' class="current" aria-current="page"' : '' ?><?= $__tgt($m) ?>><?= htmlspecialchars($m['label']) ?></a><?php endforeach; ?>
        <?php if (th_on('h_dark') && th_on('menu_dark_icon')): ?><a href="#" class="dark-mode-toggle nb-strip-dark" aria-label="Dark mode"><svg class="nb-moon" viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg></a><?php endif; ?>
      </div>
    </div>
  </nav>

  
</header>
<?php endif; ?>
<script id="msb-sp-c">(function(){
var d=document.getElementById("nb-drawer"),o=document.getElementById("overlay"),b=document.getElementById("menuToggle"),c=document.getElementById("closeBtn");
function setOpen(v){d.classList.toggle("open",v);o.classList.toggle("open",v);d.setAttribute("aria-hidden",v?"false":"true");v?d.removeAttribute("inert"):d.setAttribute("inert","");b.setAttribute("aria-expanded",v?"true":"false");document.documentElement.classList.toggle("mnav-lock",v);}
b&&b.addEventListener("click",function(){setOpen(true)});[c,o].forEach(function(e){e&&e.addEventListener("click",function(){setOpen(false)})});
document.addEventListener("keydown",function(e){if(e.key==="Escape")setOpen(false)});
d&&d.querySelectorAll(".mnav-chev").forEach(function(x){x.addEventListener("click",function(){var u=x.closest("li").querySelector(".mnav-sub"),v=!x.classList.contains("open");x.classList.toggle("open",v);u&&u.classList.toggle("open",v);x.setAttribute("aria-expanded",v?"true":"false")})});
var hs=document.getElementById("eduHSearch"),hst=document.getElementById("searchToggle"),hin=document.getElementById("edu-hsearch-input");
function srch(o){if(!hs)return;hs.hidden=!o;hst&&hst.setAttribute("aria-expanded",o?"true":"false");if(o)setTimeout(function(){hin&&hin.focus()},30)}
hst&&hst.addEventListener("click",function(v){v.preventDefault();srch(hs.hidden)});
var hx=document.getElementById("eduHSearchClose");hx&&hx.addEventListener("click",function(){srch(false)});
document.addEventListener("keydown",function(e){if(e.key==="Escape")srch(false)});
document.addEventListener("click",function(e){if(hs&&!hs.hidden&&!e.target.closest("#eduHSearch")&&!e.target.closest("#searchToggle"))srch(false)});
document.querySelectorAll("[data-edu-subscribe]").forEach(function(a){a.addEventListener("click",function(e){e.preventDefault();setOpen(false);if(!window.eduPush){alert("Notifications are not supported in this browser.");return}if(eduPush.subscribed()){alert("You are already subscribed to job alerts.");return}eduPush.ask()})});
var S=window.SpeechRecognition||window.webkitSpeechRecognition;document.querySelectorAll(".voice-icon").forEach(function(vb){var f=vb.closest("form"),i=f&&f.querySelector("input[type=search]");if(!S||!i){vb.style.display="none";return}var r=new S,p=i.placeholder;r.lang="en-IN";vb.onclick=function(){i.placeholder="Listening...";vb.classList.add("listening");try{r.start()}catch(e){}};r.onresult=function(e){i.value=e.results[0][0].transcript;i.placeholder=p;vb.classList.remove("listening");f.submit()};r.onend=function(){vb.classList.remove("listening");i.placeholder=p}});
})();</script>

<!-- Site Navigation Schema -->
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "ItemList",
  "@id": "<?= htmlspecialchars(SITE_URL) ?>#site-navigation",
  "name": "Main Navigation Menu",
  "itemListElement": [
    {
      "@type": "SiteNavigationElement",
      "position": 1,
      "name": "Home",
      "url": "<?= htmlspecialchars(SITE_URL) ?>"
    },
    {
      "@type": "SiteNavigationElement",
      "position": 2,
      "name": "Categories",
      "url": "<?= htmlspecialchars(SITE_URL) ?>/categories"
    },
    {
      "@type": "SiteNavigationElement",
      "position": 3,
      "name": "About Us",
      "url": "<?= htmlspecialchars(SITE_URL) ?>/about-us"
    },
    {
      "@type": "SiteNavigationElement",
      "position": 4,
      "name": "Contact Us",
      "url": "<?= htmlspecialchars(SITE_URL) ?>/contact-us"
    }
  ]
}
</script>
<?php if (function_exists('edu_snippet')) echo edu_snippet('body'); ?>
