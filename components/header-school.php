<?php
// School-style header (Customize → Site Identity → Header Brand → "School style"):
//   a dark top bar (phone · WhatsApp | a short text · Login button) and a white main bar with the round emblem, the name
//   and the address on a tinted panel (slanted, notched edge + red stripe), the menu on the right (items that do not
//   fit move into "More"), search, and the menu button + slide-in drawer on small screens.
// Included by components/header.php, which has already set $__menu, $__logo, $__bt, $__bl, $__loginUrl, $__active …
$__scPhone = trim((string)th('id_school_phone')) ?: trim((string)es('general', 'phone', ''));
$__scWa = trim((string)th('id_school_wa')) ?: $__scPhone;
$__digits = fn($v) => preg_replace('/\D+/', '', (string)$v);
$__waNum = $__digits($__scWa);
if (strlen($__waNum) === 10) $__waNum = '91' . $__waNum;
$__show = function (string $v) use ($__digits) { $d = $__digits($v); if (strlen($d) === 12 && str_starts_with($d, '91')) $d = substr($d, 2); return $d ?: $v; };
$__barText = trim((string)th('id_school_bar_text'));
$__loginText = trim((string)th('id_school_login_text')) ?: 'Student Login';
$__svgPhone = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.8 19.8 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.18 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.1 9.9a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg>';
$__svgWa = '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="currentColor" d="M17.47 14.38c-.3-.15-1.76-.87-2.03-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.94 1.16-.17.2-.35.22-.64.07-.3-.15-1.26-.46-2.39-1.47-.88-.79-1.48-1.76-1.65-2.06-.17-.3-.02-.46.13-.6.13-.14.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.03-.52-.07-.15-.67-1.61-.92-2.2-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.79.37-.27.3-1.04 1.02-1.04 2.48s1.07 2.88 1.21 3.07c.15.2 2.1 3.2 5.08 4.49.71.31 1.26.49 1.7.63.71.22 1.36.19 1.87.12.57-.09 1.76-.72 2-1.41.25-.7.25-1.29.18-1.41-.08-.13-.28-.2-.57-.35M12.05 21.8h-.01a9.87 9.87 0 0 1-5.03-1.38l-.36-.21-3.74.98 1-3.65-.24-.37a9.86 9.86 0 0 1-1.51-5.26c0-5.45 4.44-9.88 9.89-9.88 2.64 0 5.12 1.03 6.99 2.9a9.83 9.83 0 0 1 2.89 7c0 5.45-4.44 9.88-9.88 9.88m8.41-18.3A11.8 11.8 0 0 0 12.05 0C5.5 0 .16 5.34.16 11.89c0 2.1.55 4.14 1.59 5.95L.06 24l6.3-1.65a11.9 11.9 0 0 0 5.68 1.45h.01c6.55 0 11.89-5.34 11.89-11.89a11.8 11.8 0 0 0-3.48-8.41Z"/></svg>';
$__svgUser = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>';
$__svgHome = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M12 2.6 1.8 11.2a1 1 0 0 0 1.3 1.5L4 12v8.5A1.5 1.5 0 0 0 5.5 22H10v-6h4v6h4.5a1.5 1.5 0 0 0 1.5-1.5V12l.9.7a1 1 0 0 0 1.3-1.5z"/></svg>';
$__svgChev = '<svg class="nb-sc-chev" viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>';
?>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@700&family=Roboto:wght@500;600;700&display=swap">
<style>
.nb-h-school{--sc-navy:#0e2c53;--sc-yellow:#ffc000;--sc-red:#f02801;--sc-panel:rgba(240,174,113,.43);--sc-title:#9e1b32;--sc-pad:60px;
  position:relative;z-index:500;background:#fff;border-bottom:1px solid #eceef1;font-family:var(--font-body,Roboto),system-ui,-apple-system,"Segoe UI",Arial,sans-serif}
.nb-h-school *{box-sizing:border-box}
.nb-h-school{overflow-x:clip}
.nb-sc-in{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:0 var(--sc-pad);height:100%}
/* top bar */
.nb-sc-bar{background:var(--sc-navy);height:40px;color:#fff}
.nb-sc-links{list-style:none;margin:0;padding:0;display:flex;align-items:center}
.nb-sc-links li{display:inline-flex;align-items:center;gap:10px;position:relative;font-size:16px;font-weight:500;color:#fff;line-height:1}
.nb-sc-links li+li{margin-left:20px;padding-left:20px}
.nb-sc-links li+li:after{content:"";position:absolute;left:0;top:50%;transform:translateY(-50%);height:14px;width:1px;background:rgba(255,255,255,.3)}
.nb-sc-links li.nb-sc-btnli:after{display:none}
.nb-sc-links svg{color:var(--sc-yellow);flex:0 0 auto}
.nb-sc-links a{color:#fff;text-decoration:none}
.nb-sc-links a:hover{color:var(--sc-yellow)}
.nb-sc-login{display:inline-flex;align-items:center;gap:8px;height:30px;padding:0 28px;background:var(--sc-yellow);color:#161921!important;font-size:14px;font-weight:700;border-radius:4px;text-decoration:none}
.nb-sc-login svg{color:#161921!important}
.nb-sc-login:hover{background:#fff;color:#161921!important}
/* main bar */
.nb-sc-main{position:relative;height:90px;background:#fff}
.nb-sc-brand{position:relative;align-self:stretch;display:flex;align-items:center;gap:10px;padding-right:12px;text-decoration:none!important;color:inherit;flex:0 0 auto;z-index:1}
.nb-sc-brand::before{content:"";position:absolute;top:0;bottom:0;left:calc(-1 * var(--sc-pad));right:-52px;z-index:-1;background:var(--sc-panel);clip-path:polygon(0 0,100% 0,97% 45%,100% 100%,0 100%)}
.nb-sc-brand::after{content:"";position:absolute;bottom:0;right:-57px;width:40px;height:55%;z-index:-1;background:var(--sc-red);clip-path:polygon(50% 0,0 100%,25% 100%)}
.nb-sc-brand .nb-emblem{display:block;width:52px;height:60px;flex:0 0 auto}
.nb-sc-name{display:flex;flex-direction:column;line-height:1.1;min-width:0}
.nb-sc-name b{font-family:Poppins,Roboto,Arial,sans-serif;font-weight:700;font-size:26px;color:var(--sc-title);white-space:nowrap;letter-spacing:-.005em}
.nb-sc-name span{margin-top:4px;font-size:17px;font-weight:500;color:#1a1a1a;white-space:nowrap}
/* menu */
.nb-sc-nav{flex:1 1 auto;min-width:0;display:flex;justify-content:flex-end;height:100%}
.nb-sc-nav>ul{list-style:none;margin:0;padding:0;display:flex;align-items:stretch;height:100%;flex-wrap:nowrap}
.nb-sc-nav>ul>li{position:relative;margin:0 13px;display:flex;align-items:center}
.nb-sc-nav>ul>li:last-child{margin-right:0}
.nb-sc-nav a{display:flex;align-items:center;gap:4px;height:100%;font-size:16px;font-weight:600;color:var(--sc-navy);text-transform:uppercase;text-decoration:none;white-space:nowrap}
.nb-sc-nav a:hover,.nb-sc-nav li.is-on>a{color:var(--sc-red)}
.nb-sc-nav .nb-sc-chev{margin-top:1px}
.nb-sc-sub{list-style:none;margin:0;padding:10px 0;position:absolute;top:100%;left:-14px;min-width:210px;background:#fff;border-top:3px solid var(--sc-red);box-shadow:0 4px 15px rgba(1,15,28,.08);visibility:hidden;opacity:0;transform:translateY(8px);transition:.2s;z-index:600}
.nb-sc-nav li:hover>.nb-sc-sub,.nb-sc-nav li:focus-within>.nb-sc-sub{visibility:visible;opacity:1;transform:none}
.nb-sc-sub a{height:auto;padding:8px 18px;font-size:15px;font-weight:500;text-transform:none;color:#161921}
.nb-sc-sub a:hover{color:var(--sc-red)}
.nb-sc-more.is-empty{display:none!important}
.nb-sc-more>.nb-sc-sub,.nb-sc-nav>ul>li:nth-last-child(2)>.nb-sc-sub{left:auto;right:-14px}
.nb-sc-act{display:flex;align-items:center;gap:6px;flex:0 0 auto}
.nb-sc-act .nb-search-icon{display:inline-flex;align-items:center;justify-content:center;width:38px;height:38px;color:var(--sc-navy)}
.nb-sc-act .nb-search-icon svg{width:20px;height:20px}
.nb-sc-act .nb-burger{display:none;color:var(--sc-navy)}
@media (max-width:1499px){.nb-sc-nav>ul>li{margin:0 9px}.nb-sc-nav a{font-size:15px}}
@media (max-width:1399px){.nb-h-school{--sc-pad:40px}}
@media (max-width:1199px){
  .nb-sc-nav{display:none}
  .nb-sc-act .nb-burger{display:inline-flex}
}
@media (max-width:991px){
  .nb-h-school{--sc-pad:15px}
  .nb-sc-bar .nb-sc-in{justify-content:center}
  .nb-sc-hide-md{display:none!important}
  .nb-sc-links li{font-size:14px}
  .nb-sc-main{height:76px}
  .nb-sc-act .nb-search-icon{display:none}
  .nb-sc-brand .nb-emblem{width:44px;height:51px}
  .nb-sc-name b{font-size:21px}
  .nb-sc-name span{font-size:13px}
}
@media (max-width:575px){
  .nb-sc-bar{height:36px}
  .nb-sc-hide-sm{display:none!important}
  .nb-sc-main{height:66px}
  .nb-sc-brand{gap:7px;padding-right:4px}
  .nb-sc-brand::before{right:-34px}
  .nb-sc-brand::after{right:-42px;width:30px;clip-path:polygon(35% 0,0 100%,20% 100%)}
  .nb-sc-brand .nb-emblem{width:36px;height:42px}
  .nb-sc-name b{font-size:16.5px}
  .nb-sc-name span{font-size:10.5px;margin-top:3px}
  .nb-sc-act .nb-search-icon{width:34px}
}
@media (max-width:370px){.nb-sc-name b{font-size:15px}.nb-sc-name span{font-size:9.5px}}
</style>
<header class="nb-header nb-h-racing nb-h-school" id="site-navigation">
  <div class="nb-sc-bar"><div class="nb-sc-in">
    <ul class="nb-sc-links">
      <?php if ($__scPhone !== ''): ?><li><?= $__svgPhone ?><a href="tel:<?= htmlspecialchars('+' . ltrim(strlen($__digits($__scPhone)) === 10 ? '91' . $__digits($__scPhone) : $__digits($__scPhone), '+')) ?>"><?= htmlspecialchars($__show($__scPhone)) ?></a></li><?php endif; ?>
      <?php if ($__waNum !== ''): ?><li class="nb-sc-hide-md"><?= $__svgWa ?><a href="https://wa.me/<?= htmlspecialchars($__waNum) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($__show($__scWa)) ?></a></li><?php endif; ?>
    </ul>
    <ul class="nb-sc-links">
      <?php if ($__barText !== ''): ?><li class="nb-sc-hide-sm"><?= htmlspecialchars($__barText) ?></li><?php endif; ?>
      <?php if (th_on('h_login')): ?><li class="nb-sc-btnli nb-sc-hide-md"><a class="nb-sc-login" href="<?= htmlspecialchars($__loginUrl) ?>"><?= $__svgUser ?><?= htmlspecialchars($__loginText) ?></a></li><?php endif; ?>
    </ul>
  </div></div>
  <div class="nb-sc-main"><div class="nb-sc-in">
    <a href="/" class="nb-sc-brand" rel="home" title="<?= htmlspecialchars($__bt) ?> Home" aria-label="<?= htmlspecialchars($__bt) ?> — home">
      <?= $__logo ?>
      <span class="nb-sc-name"><b><?= htmlspecialchars($__bt) ?></b><?php if ($__bl !== ''): ?><span><?= htmlspecialchars($__bl) ?></span><?php endif; ?></span>
    </a>
    <nav class="nb-sc-nav" aria-label="Main navigation"><ul id="nbScMenu">
      <?php foreach ($__menu as $m): $home = $m['url'] === '/'; ?>
      <li class="nb-sc-item<?= $__active($m['url']) ? ' is-on' : '' ?>"><a href="<?= htmlspecialchars($m['url']) ?>"<?= $__tgt($m) ?><?= $home ? ' aria-label="' . htmlspecialchars($m['label']) . '" title="' . htmlspecialchars($m['label']) . '"' : '' ?>><?= $home ? $__svgHome : htmlspecialchars($m['label']) . ($m['children'] ? $__svgChev : '') ?></a>
        <?php if ($m['children']): ?><ul class="nb-sc-sub"><?php foreach ($m['children'] as $c): ?><li><a href="<?= htmlspecialchars((string)$c['url']) ?>"<?= $__tgt($c) ?>><?= htmlspecialchars((string)$c['label']) ?></a></li><?php endforeach; ?></ul><?php endif; ?></li>
      <?php endforeach; ?>
      <li class="nb-sc-more is-empty"><a href="#" role="button" aria-haspopup="true">More<?= $__svgChev ?></a><ul class="nb-sc-sub" id="nbScMore"></ul></li>
    </ul></nav>
    <div class="nb-sc-act nb-hr-act">
      <?php if (th_on('h_search')): ?><a href="/search" role="button" aria-label="Search" class="nb-search-icon" id="searchToggle" aria-expanded="false" aria-controls="eduHSearch"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg></a><?php endif; ?>
<?php include __DIR__ . '/header-drawer.php'; ?>
    </div>
  </div></div>
</header>
<script>
(function () {
  // menu items that do not fit on one line go into "More" (re-checked on resize)
  var ul = document.getElementById('nbScMenu'), more = ul && ul.querySelector('.nb-sc-more'), box = document.getElementById('nbScMore');
  if (!ul || !more) return;
  var items = [].slice.call(ul.querySelectorAll(':scope > .nb-sc-item'));
  function fit() {
    items.forEach(function (li) { li.classList.remove('nb-sc-in-more'); ul.insertBefore(li, more); });
    box.innerHTML = '';
    more.classList.add('is-empty');
    var nav = ul.parentElement;
    if (!nav.offsetWidth) return; // menu hidden (small screen): the drawer is used
    var moved = [];
    while (ul.scrollWidth > nav.clientWidth + 1 && items.length - moved.length > 1) {
      var li = items[items.length - 1 - moved.length];
      moved.unshift(li); li.remove();
      more.classList.remove('is-empty');
    }
    moved.forEach(function (li) {
      var a = li.querySelector('a'), n = document.createElement('li'), c = a.cloneNode(true);
      c.querySelectorAll('svg').forEach(function (s) { s.remove(); });
      n.appendChild(c); box.appendChild(n);
      li.querySelectorAll('.nb-sc-sub a').forEach(function (s) { var k = document.createElement('li'), x = s.cloneNode(true); x.style.paddingLeft = '30px'; k.appendChild(x); box.appendChild(k); });
    });
  }
  more.querySelector('a').addEventListener('click', function (e) { e.preventDefault(); });
  var t; window.addEventListener('resize', function () { clearTimeout(t); t = setTimeout(fit, 120); });
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(fit);
  fit();
})();
</script>
