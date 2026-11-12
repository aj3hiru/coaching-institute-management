<?php
// Footer in the new CMS design: brand + about · Job Categories / Quick Links · Follow Us card, then the copyright bar.
require_once INCLUDES_PATH . '/theme.php';
$__fdb = function_exists('edu_db') ? edu_db() : ($pdo ?? null);
// Link columns (Customize → Footer): each has its own links, or lists the most-read categories automatically
$__fcols = [];
foreach ((array)th('f_columns', []) as $col) {
    $items = [];
    if (($col['auto'] ?? '') === 'categories') {
        if ($__fdb) {
            try {
                foreach ($__fdb->query("SELECT name, slug FROM categories ORDER BY views DESC, name ASC LIMIT " . max(1, min(20, (int)($col['count'] ?? 6)))) as $c) $items[] = [$c['name'], '/categories/' . $c['slug'], false];
            } catch (Throwable $e) { $items = []; }
        }
    } else {
        foreach ((array)($col['links'] ?? []) as $l) $items[] = [(string)$l['label'], (string)$l['url'], !empty($l['newTab'])];
    }
    if ($items) $__fcols[] = [(string)($col['title'] ?? ''), $items];
}
// Links to this site open in the same tab; other websites in a new tab
$__ext = function (string $u): bool {
    $h = parse_url($u, PHP_URL_HOST);
    if (!$h) return false;
    $own = preg_replace('/^www\./', '', (string)parse_url(SITE_URL, PHP_URL_HOST));
    return strcasecmp(preg_replace('/^www\./', '', $h), $own) !== 0;
};
$__tgt = fn(string $u, bool $nofollow = false) => $__ext($u) ? ' target="_blank" rel="noopener' . ($nofollow ? ' nofollow' : '') . '"' : '';
$__chev = '<svg class="ftx-ico ftx-ico-sm" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M8.6 5.6a1 1 0 0 1 1.4 0l5.7 5.7a1 1 0 0 1 0 1.4L10 18.4a1 1 0 1 1-1.4-1.4l5-5-5-5a1 1 0 0 1 0-1.4Z"/></svg>';
require_once INCLUDES_PATH . '/settings.php';
$__so = edu_social();
$__fsoc = array_filter([
    ['Facebook', $__so['facebook'], 'M9.101 23.691v-7.98H6.627v-3.667h2.474v-1.58c0-4.085 1.848-5.978 5.858-5.978.401 0 .955.042 1.468.103a8.68 8.68 0 0 1 1.141.195v3.325a8.623 8.623 0 0 0-.653-.036 26.805 26.805 0 0 0-.733-.009c-.707 0-1.259.096-1.675.309a1.686 1.686 0 0 0-.679.622c-.258.42-.374.995-.374 1.752v1.297h3.919l-.386 2.103-.287 1.564h-3.246v8.245C19.396 23.238 24 18.179 24 12.044c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.628 3.874 10.35 9.101 11.647Z'],
    ['X', $__so['twitter'], 'M18.901 1.153h3.68l-8.04 9.19L24 22.846h-7.406l-5.8-7.584-6.638 7.584H.474l8.6-9.83L0 1.154h7.594l5.243 6.932ZM17.61 20.644h2.039L6.486 3.24H4.298Z'],
    ['WhatsApp', $__so['whatsapp'], 'M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38c1.45.79 3.08 1.21 4.74 1.21 5.46 0 9.91-4.45 9.91-9.91C21.95 6.45 17.5 2 12.04 2Zm5.8 14.03c-.25.69-1.440 1.32-2 1.41-.51.08-1.16.11-1.87-.12-.43-.14-.98-.32-1.69-.62-2.98-1.29-4.93-4.29-5.08-4.49-.15-.2-1.21-1.61-1.21-3.07 0-1.46.77-2.18 1.04-2.48.27-.3.6-.37.79-.37h.57c.18.01.43-.07.67.51.25.6.84 2.06.92 2.21.07.15.12.32.02.52-.1.2-.15.32-.3.5-.15.17-.31.39-.45.52-.15.15-.3.31-.13.61.17.3.77 1.27 1.65 2.06 1.14 1.01 2.09 1.33 2.39 1.48.3.15.47.12.64-.07.17-.2.74-.87.94-1.16.2-.3.4-.25.67-.15.27.1 1.73.82 2.03.97.3.15.5.22.57.35.07.12.07.72-.17 1.41Z'],
    ['Telegram', $__so['telegram'], 'M11.944 0A12 12 0 1 0 24 12 12 12 0 0 0 11.944 0Zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635Z'],
    ['Instagram', $__so['instagram'], 'M12 2.16c3.2 0 3.58.01 4.85.07 1.17.05 1.8.25 2.23.41.56.22.96.48 1.38.9.42.42.68.82.9 1.38.16.42.36 1.06.41 2.23.06 1.27.07 1.65.07 4.85s-.01 3.58-.07 4.85c-.05 1.17-.25 1.8-.41 2.23-.22.56-.48.96-.9 1.38-.42.42-.82.68-1.38.9-.42.16-1.06.36-2.23.41-1.27.06-1.65.07-4.85.07s-3.58-.01-4.85-.07c-1.17-.05-1.8-.25-2.230-.41a3.72 3.72 0 0 1-1.38-.9 3.72 3.72 0 0 1-.9-1.38c-.16-.42-.36-1.06-.41-2.23C2.17 15.58 2.16 15.2 2.16 12s.01-3.58.07-4.85c.05-1.17.25-1.8.41-2.23.22-.56.48-.96.9-1.38.42-.42.82-.68 1.38-.9.42-.16 1.06-.36 2.23-.41C8.42 2.17 8.8 2.16 12 2.16ZM12 0C8.74 0 8.33.01 7.05.07 5.78.13 4.9.33 4.14.63c-.79.31-1.46.72-2.13 1.38A5.88 5.88 0 0 0 .63 4.14C.33 4.9.13 5.78.07 7.05.01 8.33 0 8.74 0 12s.01 3.67.07 4.95c.06 1.27.26 2.15.56 2.91.31.79.72 1.46 1.38 2.13a5.88 5.88 0 0 0 2.13 1.38c.76.3 1.64.5 2.91.56C8.33 23.99 8.74 24 12 24s3.67-.01 4.95-.07c1.27-.06 2.15-.26 2.91-.56a5.88 5.88 0 0 0 2.13-1.38 5.88 5.88 0 0 0 1.38-2.13c.3-.76.5-1.64.56-2.91.06-1.28.07-1.69.07-4.95s-.01-3.67-.07-4.95c-.06-1.27-.26-2.15-.56-2.91a5.88 5.88 0 0 0-1.38-2.13A5.88 5.88 0 0 0 19.86.63C19.1.33 18.22.13 16.95.07 15.67.01 15.26 0 12 0Zm0 5.84a6.16 6.16 0 1 0 0 12.32 6.16 6.16 0 0 0 0-12.32ZM12 16a4 4 0 1 1 0-8 4 4 0 0 1 0 8Zm6.4-11.85a1.44 1.44 0 1 0 0 2.88 1.44 1.44 0 0 0 0-2.88Z'],
    ['LinkedIn', $__so['linkedin'], 'M20.45 20.45h-3.56v-5.57c0-1.33-.02-3.04-1.85-3.04-1.85 0-2.14 1.45-2.14 2.94v5.67H9.35V9h3.41v1.56h.05c.48-.9 1.64-1.85 3.37-1.85 3.6 0 4.27 2.37 4.27 5.46v6.28ZM5.34 7.43a2.06 2.06 0 1 1 0-4.13 2.06 2.06 0 0 1 0 4.13ZM7.12 20.45H3.56V9h3.56v11.45ZM22.22 0H1.77C.79 0 0 .77 0 1.73v20.54C0 23.23.79 24 1.77 24h20.45c.98 0 1.78-.77 1.78-1.73V1.73C24 .77 23.2 0 22.22 0Z'],
    ['YouTube', $__so['youtube'], 'M23.5 6.19a3.02 3.02 0 0 0-2.12-2.14C19.5 3.55 12 3.55 12 3.55s-7.5 0-9.38.5A3.02 3.02 0 0 0 .5 6.19 31.6 31.6 0 0 0 0 12a31.6 31.6 0 0 0 .5 5.81 3.02 3.02 0 0 0 2.12 2.14c1.88.5 9.38.5 9.38.5s7.5 0 9.38-.5a3.02 3.02 0 0 0 2.12-2.14A31.6 31.6 0 0 0 24 12a31.6 31.6 0 0 0-.5-5.81ZM9.55 15.57V8.43L15.82 12l-6.27 3.57Z'],
], fn($s) => $s[1] !== '');
$__fsoc[] = ['RSS Feed', '/feed/', 'M6.503 20.752c0 1.794-1.456 3.248-3.251 3.248-1.796 0-3.252-1.454-3.252-3.248 0-1.794 1.456-3.248 3.252-3.248 1.795.001 3.251 1.454 3.251 3.248zm-6.503-12.572v4.811c6.05.062 10.96 4.966 11.022 11.009h4.817c-.062-8.71-7.118-15.758-15.839-15.82zm0-3.368c10.58.046 19.152 8.594 19.183 19.188h4.817c-.03-13.231-10.755-23.954-24-24v4.812z'];
?>
<footer class="ftx-footer" role="contentinfo">
  <div class="ftx-wrap">
    <div class="ftx-grid<?= count($__fcols) >= 4 ? ' ftx-grid--many' : (count($__fcols) === 3 ? ' ftx-grid--three' : '') ?>">
      <div class="ftx-brand">
        <div class="ftx-brand-row">
          <?php if (trim((string)th('f_logo')) !== ''): ?><img class="ftx-logo" src="<?= htmlspecialchars((string)th('f_logo')) ?>" alt="<?= htmlspecialchars(edu_site_name()) ?>" loading="lazy" decoding="async"><?php else: ?><span class="ftx-site-name"><?= htmlspecialchars(edu_site_name()) ?></span><?php endif; ?>
          <?php if (th_on('f_play')): $__play = (string)th('f_play_url'); $__badge = '<img src="/assets/img/google-play-badge.svg" width="135" height="40" alt="Get it on Google Play" loading="lazy" decoding="async">'; ?>
          <span class="ftx-sep" aria-hidden="true"></span>
          <?= preg_match('#^https?://#i', $__play) ? '<a class="ftx-play" href="' . htmlspecialchars($__play) . '" target="_blank" rel="noopener">' . $__badge . '</a>' : '<span class="ftx-play">' . $__badge . '</span>' ?>
          <?php endif; ?>
        </div>
        <?php if (trim((string)th('f_desc')) !== ''): ?><div class="ftx-desc"><?= edu_shortcodes((string)th('f_desc')) ?></div><?php else: ?><p class="ftx-desc"><?= htmlspecialchars(es('general', 'footer_tagline', SITE_FOOTER_TAGLINE), ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
      </div>
      <div class="ftx-columns" style="--fc:<?= max(1, min(4, count($__fcols))) ?>">
        <?php foreach ($__fcols as $i => [$heading, $items]): ?>
        <nav class="ftx-col" aria-label="<?= htmlspecialchars($heading !== '' ? $heading : 'Footer links ' . ($i + 1)) ?>">
          <?php if ($heading !== ''): ?><h2 class="ftx-heading"><?= htmlspecialchars($heading) ?></h2><?php endif; ?>
          <?php foreach ($items as [$l, $u, $nt]): ?><a class="ftx-link" href="<?= htmlspecialchars($u) ?>"<?= $nt ? ' target="_blank" rel="noopener"' : '' ?>><?= $__chev ?><span><?= htmlspecialchars($l) ?></span></a><?php endforeach; ?>
        </nav>
        <?php endforeach; ?>
      </div>
      <?php if (th_on('f_follow')): ?>
      <div class="ftx-follow">
        <h2 class="ftx-heading"><?= htmlspecialchars((string)th('f_follow_heading')) ?></h2>
        <div class="ftx-cta-card">
          <div class="ftx-cta-text"><div class="ftx-cta-title"><?= htmlspecialchars((string)th('f_follow_title')) ?></div><div class="ftx-cta-subtitle"><?= htmlspecialchars((string)th('f_follow_sub')) ?></div></div>
          <div class="ftx-social-row">
            <?php foreach ($__fsoc as [$n, $u, $d]): ?><a class="ftx-social-btn" href="<?= htmlspecialchars($u) ?>"<?= $__tgt($u, true) ?> aria-label="<?= $n ?>"><svg class="ftx-ico" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="<?= $d ?>"/></svg></a><?php endforeach; ?>
          </div>
          <?php if (trim((string)th('f_follow_btn')) !== ''): ?><a class="ftx-cta-btn" href="<?= htmlspecialchars((string)th('f_follow_btn_url', '#')) ?>"<?= $__tgt((string)th('f_follow_btn_url', '#')) ?>><?= htmlspecialchars((string)th('f_follow_btn')) ?></a><?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
    <div class="ftx-copy"><span class="ftx-copy-t"><?= edu_shortcodes(htmlspecialchars(strtr((string)th('f_copyright'), ['{year}' => date('Y'), '{site}' => edu_site_name()]))) ?></span>
      <?php // Customize → Footer → Copyright: "Developed by <name> ↗" in the same row (only when switched on)
      $__dn = trim((string)th('f_dev_name')); $__du = trim((string)th('f_dev_url'));
      if (th_on('f_dev') && $__dn !== ''): $__dl = trim((string)th('f_dev_label')); $__ok = (bool)preg_match('#^https?://#i', $__du); ?>
      <span class="ftx-dev"><?= $__dl !== '' ? htmlspecialchars($__dl) . ' ' : '' ?><?php if ($__ok): ?><a class="ftx-dev-a" href="<?= htmlspecialchars($__du) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($__dn) ?><svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17 17 7M8 7h9v9"/></svg><span class="screen-reader-text"> (opens in a new tab)</span></a><?php else: ?><b><?= htmlspecialchars($__dn) ?></b><?php endif; ?></span>
      <?php endif; ?>
    </div>
    <?php if (th_on('cc_enabled') && th_on('cc_footer_link')): ?><p class="ftx-cookie"><button type="button" data-edu-consent>Cookie settings</button></p><?php endif; ?>
  </div>
</footer>
<?php // phones: an app-like bar fixed at the bottom — Home, Explore (all courses), Orders, Courses (my courses), Profile (photo / initials when logged in)
// Customize → Footer → Mobile Bottom Bar: on / off, each button and its name
$__bp = strtok($_SERVER['REQUEST_URI'] ?? '/', '?'); $__bon = fn(string $re) => preg_match($re, $__bp) ? ' on' : '';
$__bt = fn(string $k, string $d) => htmlspecialchars(trim((string)th('bnav_' . $k . '_t')) !== '' ? (string)th('bnav_' . $k . '_t') : $d);
$__bsvg = ['home' => '<path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>', 'explore' => '<circle cx="12" cy="12" r="9"/><path d="m15.5 8.5-2 5-5 2 2-5z"/>',
    'orders' => '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>', 'courses' => '<path d="M2 4h7a3 3 0 0 1 3 3v14a2 2 0 0 0-2-2H2z"/><path d="M22 4h-7a3 3 0 0 0-3 3v14a2 2 0 0 1 2-2h8z"/>',
    'profile' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>'];
$__bi = fn(string $k) => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $__bsvg[$k] . '</svg>';
$__bitems = array_filter(['home', 'explore', 'orders', 'courses', 'profile'], fn($k) => th_on('bnav_' . $k));
if (th_on('bnav') && $__bitems): ?>
<nav class="dk-bnav" aria-label="Quick links" style="grid-template-columns:repeat(<?= count($__bitems) ?>,1fr)">
  <?php foreach ($__bitems as $__k): if ($__k === 'home'): ?><a href="/"<?= $__bp === '/' ? ' class="on" aria-current="page"' : '' ?>><?= $__bi('home') ?><span><?= $__bt('home', 'Home') ?></span></a>
  <?php elseif ($__k === 'explore'): ?><a href="/courses/" class="<?= trim($__bon('#^/(courses|notes|store|category|free-courses|all-categories)(/|$)#')) ?>"><?= $__bi('explore') ?><span><?= $__bt('explore', 'Explore') ?></span></a>
  <?php elseif ($__k === 'orders'): ?><a href="/account/orders" data-bn-auth class="<?= trim($__bon('#^/account/orders#')) ?>"><?= $__bi('orders') ?><span><?= $__bt('orders', 'Orders') ?></span></a>
  <?php elseif ($__k === 'courses'): ?><a href="/account/" data-bn-auth class="<?= trim($__bon('#^/(account/?$|account/index|learn/)#')) ?>"><?= $__bi('courses') ?><span><?= $__bt('courses', 'Courses') ?></span></a>
  <?php else: ?><a href="/account/profile" data-bn-me class="<?= trim($__bon('#^/account/(profile|login|register)#')) ?>"><span class="dk-bn-me"><?= $__bi('profile') ?></span><span class="dk-bn-l" data-def="<?= $__bt('profile', 'Profile') ?>"><?= $__bt('profile', 'Profile') ?></span></a>
  <?php endif; endforeach; ?>
</nav>
<?php endif; ?>
<script>(function(){var c=document.cookie,g=function(k){var m=c.match(new RegExp('(?:^|; )'+k+'=([^;]*)'));try{return m?decodeURIComponent(m[1]):''}catch(e){return ''}};
var inn=/(?:^|; )sh_in=1/.test(c),me=document.querySelector('.dk-bnav [data-bn-me]');if(!me)return;
if(!inn){me.href='/account/login';me.querySelector('.dk-bn-l').textContent='Login';document.querySelectorAll('.dk-bnav [data-bn-auth]').forEach(function(a){a.href='/account/login?next='+encodeURIComponent(a.getAttribute('href'))});return}
var n=g('sh_n'),l=g('sh_l'),av=g('sh_av'),b=me.querySelector('.dk-bn-me');me.querySelector('.dk-bn-l').textContent=n||me.querySelector('.dk-bn-l').getAttribute('data-def');
if(av){var i=new Image();i.alt='';i.src=av;i.onerror=function(){b.textContent=((n.charAt(0)||'')+(l||'')).toUpperCase();b.classList.add('ini')};b.innerHTML='';b.appendChild(i);b.classList.add('pic')}
else if(n){b.textContent=(n.charAt(0)+(l||'')).toUpperCase();b.classList.add('ini')}})();</script>
<?php if (th_on('f_totop') && empty($GLOBALS['__edu_rp_pill'])): // Customize → Layout → Footer → Back to top button; // articles have the reading progress button instead ?>
<button type="button" class="edu-totop" id="eduTop" aria-label="Back to top" hidden><svg viewBox="0 0 36 36" aria-hidden="true"><circle cx="18" cy="18" r="16" fill="none" stroke="rgba(255,255,255,.35)" stroke-width="3"/><circle id="eduTopRing" cx="18" cy="18" r="16" fill="none" stroke="#fff" stroke-width="3" stroke-dasharray="100.5" stroke-dashoffset="100.5" transform="rotate(-90 18 18)"/><path d="M18 24V12m-5 5 5-5 5 5" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
<style>.edu-totop{position:fixed;right:14px;bottom:16px;z-index:9990;width:46px;height:46px;border-radius:50%;border:0;background:var(--gc-accent,#0c6878);box-shadow:0 6px 18px rgba(0,0,0,.25);padding:4px;cursor:pointer}.edu-totop svg{width:100%;height:100%}@media (min-width:981px){.edu-totop{display:none!important}}</style>
<script>(function(){var b=document.getElementById("eduTop"),r=document.getElementById("eduTopRing");function f(){var h=document.documentElement.scrollHeight-innerHeight,p=h>0?scrollY/h:0;b.hidden=scrollY<300;r.setAttribute("stroke-dashoffset",(100.5*(1-p)).toFixed(1))}addEventListener("scroll",f,{passive:true});b.onclick=function(){scrollTo({top:0,behavior:"smooth"})};f()})();</script>
<?php endif; ?>
<?php if (th_on('cc_enabled')): ?>
<div class="edu-cc edu-cc--<?= in_array(th('cc_position'), ['bottom', 'left', 'center'], true) ? th('cc_position') : 'bottom' ?>" id="eduCc" role="dialog" aria-live="polite" aria-label="Cookie consent" hidden>
  <div class="edu-cc-box">
    <div class="edu-cc-text"><h2><?= htmlspecialchars((string)th('cc_title')) ?></h2><p><?= htmlspecialchars((string)th('cc_message')) ?> <a href="<?= htmlspecialchars((string)th('cc_privacy_url')) ?>"><?= htmlspecialchars((string)th('cc_privacy_text')) ?></a></p></div>
    <div class="edu-cc-btns"><button type="button" data-cc="custom"><?= htmlspecialchars((string)th('cc_customize')) ?></button><button type="button" data-cc="no"><?= htmlspecialchars((string)th('cc_reject')) ?></button><button type="button" class="edu-cc-yes" data-cc="yes"><?= htmlspecialchars((string)th('cc_accept')) ?></button></div>
    <div class="edu-cc-opts">
      <label class="edu-cc-opt"><span><b>Necessary</b><small>Needed for the site to work. Always on.</small></span><input type="checkbox" checked disabled></label>
      <label class="edu-cc-opt"><span><b>Analytics</b><small>Helps us understand which pages are read.</small></span><input type="checkbox" id="eduCcAn"></label>
      <label class="edu-cc-opt"><span><b>Advertising</b><small>Lets ads be shown and measured.</small></span><input type="checkbox" id="eduCcAds"></label>
      <label class="edu-cc-opt"><span><b>Personalized ads</b><small>Ads based on your interests.</small></span><input type="checkbox" id="eduCcPers"></label>
      <div class="edu-cc-btns"><button type="button" class="edu-cc-yes" data-cc="save"><?= htmlspecialchars((string)th('cc_save')) ?></button></div>
    </div>
  </div>
</div>
<?php if (th_on('cc_sticky')): ?><button type="button" class="edu-cc-fab" id="eduCcFab" aria-label="Cookie settings" data-edu-consent hidden><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M21.6 11.2a3.5 3.5 0 0 1-4.8-3.3 3.5 3.5 0 0 1-4.6-4.6A9.5 9.5 0 1 0 21.6 11.2ZM8 9.5A1.5 1.5 0 1 1 8 12.5 1.5 1.5 0 0 1 8 9.5Zm1 6a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3Zm6.5 1a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3Z"/></svg></button><?php endif; ?>
<script>(function(){
if(!window.__eduConsentApplies)return;
var box=document.getElementById("eduCc"),fab=document.getElementById("eduCcFab"),days=<?= max(30, min(395, (int)th('cc_days', 180))) ?>,reask=<?= json_encode((string)th('cc_reask'), JSON_HEX_TAG) ?>;
var c=null;try{c=JSON.parse(localStorage.getItem("edu_consent")||"null")}catch(e){}
var g=function(v){return v?"granted":"denied"};
function show(){box.hidden=false;if(fab)fab.hidden=true;var a=document.getElementById("eduCcAn"),d=document.getElementById("eduCcAds"),p=document.getElementById("eduCcPers");if(c){a.checked=!!c.an;d.checked=!!c.ads;p.checked=!!c.pers}}
function save(an,ads,pers){c={an:an,ads:ads,pers:pers,at:Date.now(),exp:Date.now()+days*864e5};try{localStorage.setItem("edu_consent",JSON.stringify(c));sessionStorage.setItem("edu_consent_seen","1")}catch(e){}
gtag("consent","update",{ad_storage:g(ads),ad_user_data:g(ads),ad_personalization:g(pers),analytics_storage:g(an)});box.hidden=true;box.classList.remove("is-custom");if(fab&&!(an&&ads&&pers))fab.hidden=false}
box.addEventListener("click",function(e){var b=e.target.closest("[data-cc]");if(!b)return;var k=b.dataset.cc;
if(k==="yes")save(true,true,true);else if(k==="no")save(false,false,false);else if(k==="custom")box.classList.toggle("is-custom");
else if(k==="save")save(document.getElementById("eduCcAn").checked,document.getElementById("eduCcAds").checked,document.getElementById("eduCcPers").checked)});
document.addEventListener("click",function(e){if(e.target.closest("[data-edu-consent]")){e.preventDefault();show()}});
var rejected=c&&!(c.an&&c.ads&&c.pers),again=false;
if(c&&c.exp>Date.now()&&rejected){if(reask==="visit")again=!sessionStorage.getItem("edu_consent_seen");else if(reask==="daily")again=Date.now()-c.at>864e5}
if(!c||c.exp<=Date.now()||again)show();else if(fab&&rejected)fab.hidden=false;
})();</script>
<?php endif; ?>
<?php if (function_exists('edu_snippet')) { if (function_exists('edu_ads_footer')) echo edu_ads_footer(); echo edu_snippet('footer'); echo edu_delay_loader(); } ?>
