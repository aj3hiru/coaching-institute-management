<?php
// Search overlay, menu button and the slide-in mobile menu — shared by the racing and the school-style header (components/header.php).
?>
<?php if (!empty($__school)): ?><style>
/* the same school-style brand at the top of the phone menu — same height, logo and text sizes as the phone header */
.mnav-top.nb-mnav-top-school{height:auto!important;min-height:0!important;padding:5px 8px 4px 0!important;align-items:stretch!important;box-sizing:border-box}
.mnav-top.nb-mnav-top-school .mnav-close{align-self:center;flex:0 0 auto}
.mnav-brand.nb-mnav-school{position:relative;isolation:isolate;display:flex;align-items:center;gap:8px;flex:1 1 auto;min-width:0;width:auto!important;max-width:none!important;height:52px!important;box-sizing:border-box;padding:3px 40px 3px 14px;text-decoration:none;color:inherit}
.nb-mnav-school::before{content:"";position:absolute;inset:0 10px 0 0;z-index:-1;background:rgba(240,174,113,.43);clip-path:polygon(0 0,100% 0,97% 45%,100% 100%,0 100%)}
.nb-mnav-school::after{content:"";position:absolute;bottom:0;right:4px;width:30px;height:55%;z-index:-1;background:#f02801;clip-path:polygon(35% 0,0 100%,20% 100%)}
.nb-mnav-school .nb-emblem{display:block;width:40px!important;height:46px!important;flex:0 0 auto}
.nb-mnav-school .nb-sb-school{display:flex;flex-direction:column;align-items:flex-start;text-align:left;line-height:1.1;min-width:0}
.nb-mnav-school .site-title{font-family:Poppins,Roboto,Arial,sans-serif;font-weight:700;font-size:18px;color:#9e1b32!important;white-space:nowrap}
.nb-mnav-school .nb-brand-line{margin-top:3px;font-family:Roboto,Arial,sans-serif;font-size:11.5px;font-weight:500;letter-spacing:0;text-transform:none;color:#1a1a1a!important;white-space:normal;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
@media (max-width:400px){.mnav-brand.nb-mnav-school{height:48px!important}.nb-mnav-school .nb-emblem{width:36px!important;height:42px!important}.nb-mnav-school .site-title{font-size:16.5px}.nb-mnav-school .nb-brand-line{font-size:10.5px}}
</style><?php endif; ?>
<?php if (th_on('h_search')): ?>
  <div class="edu-hsearch" id="eduHSearch" hidden>
    <form role="search" method="get" action="/search" class="edu-hsearch-form">
      <label for="edu-hsearch-input" class="screen-reader-text">Search for:</label>
      <input id="edu-hsearch-input" type="search" name="q" placeholder="<?= htmlspecialchars((string)th('h_search_placeholder')) ?>" autocomplete="off" required>
      <?php if (th_on('h_voice')): ?><button type="button" class="voice-icon" aria-label="Voice search"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19v3"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><rect x="9" y="2" width="6" height="13" rx="3"/></svg></button><?php endif; ?>
      <button type="submit" class="edu-hsearch-go" aria-label="Search"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg></button>
      <button type="button" class="edu-hsearch-x" id="eduHSearchClose" aria-label="Close search">&times;</button>
    </form>
  </div>
  <?php endif; ?>
      <button type="button" class="nb-burger" id="menuToggle" aria-label="Open menu" aria-expanded="false" aria-controls="nb-drawer"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M3 6h18M3 12h18M3 18h18"/></svg></button>
      <div class="mnav-overlay" id="overlay" role="presentation"></div>
      <aside id="nb-drawer" class="mnav nb-drawer" aria-label="Mobile menu" aria-hidden="true" inert>
        <div class="mnav-top<?= !empty($__school) ? ' nb-mnav-top-school' : '' ?>">
          <a href="/" class="mnav-brand<?= !empty($__school) ? ' nb-mnav-school' : '' ?>"><?= $__logo . (!empty($__school) ? $__brandText : '') ?></a>
          <button type="button" class="mnav-close" id="closeBtn" aria-label="Close menu"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg></button>
        </div>
        <?php
          // Customize → Header → Mobile menu: greeting, Log in and Subscribe can each be hidden
          $__dHello = th_on('h_drawer_hello'); $__dLogin = th_on('h_login') && th_on('h_drawer_login'); $__dSub = $__subOn && th_on('h_drawer_sub');
        ?>
        <?php $__storeOn = is_file(ROOT_PATH . '/account/login.php'); $__store = $__storeOn && th_on('h_drawer_login'); // Customize → Header → Off-Canvas Menu → "Courses & Student Login buttons" ?>
        <?php if ($__dHello || $__store || (!$__storeOn && ($__dLogin || $__dSub))): ?>
        <div class="mnav-hello">
          <?php if ($__dHello): ?>
          <div class="mnav-hello-row">
            <span class="mnav-avatar"><?= $__userIco ?></span>
            <div class="mnav-hello-text"><p class="mnav-hello-title"><?= htmlspecialchars((string)th('h_drawer_title')) ?></p><?php if (trim((string)th('h_drawer_text')) !== ''): ?><p class="mnav-hello-sub"><?= htmlspecialchars((string)th('h_drawer_text')) ?></p><?php endif; ?></div>
          </div>
          <?php endif; ?>
          <?php if ($__store): // online store: course buttons (which set shows is decided in the browser — cached pages stay the same for everyone) ?>
          <div class="mnav-hello-btns"<?= $__dHello ? '' : ' style="margin-top:0"' ?> data-sh="out">
            <a href="/courses/" class="mnav-btn outline"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="m16.2 7.8-2.1 6.4-6.4 2.1 2.1-6.4Z"/></svg> All Courses</a>
            <a href="/account/login" class="mnav-btn solid" data-sh-keep><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="m10 17 5-5-5-5M15 12H3"/></svg> Log in</a>
          </div>
          <div class="mnav-hello-btns"<?= $__dHello ? '' : ' style="margin-top:0"' ?> data-sh="in" hidden>
            <a href="/account/" class="mnav-btn outline"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20V3H6.5A2.5 2.5 0 0 0 4 5.5Z"/><path d="M4 19.5A2.5 2.5 0 0 0 6.5 22H20v-5"/></svg> My Courses</a>
            <a href="/account/logout" class="mnav-btn solid"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5M21 12H9"/></svg> Log out</a>
          </div>
          <?php elseif (!$__storeOn && ($__dLogin || $__dSub)): ?>
          <div class="mnav-hello-btns" style="<?= $__dLogin && $__dSub ? '' : 'grid-template-columns:1fr;' ?><?= $__dHello ? '' : 'margin-top:0' ?>">
            <?php if ($__dLogin): ?><a href="<?= htmlspecialchars($__loginUrl) ?>" class="mnav-btn outline"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="m10 17 5-5-5-5"/><path d="M15 12H3"/></svg> <?= htmlspecialchars((string)th('h_login_text')) ?></a><?php endif; ?>
            <?php if ($__dSub): $__si = edu_sub_icon(); ?><a <?= $__subAttr ?> class="mnav-btn solid<?= $__si ? ' has-ic' : '' ?>"<?= $__si ? ' style="--nb-sb:' . htmlspecialchars($__si[2]) . '"' : '' ?>><?= $__si ? $__si[1] : '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.27 21a2 2 0 0 0 3.46 0"/><path d="M3.26 15.33A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.67C19.41 13.96 18 12.5 18 8A6 6 0 0 0 6 8c0 4.5-1.41 5.96-2.74 7.33"/></svg>' ?> <?= htmlspecialchars((string)th('h_sub_text')) ?></a><?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
        <div class="mnav-gap" aria-hidden="true"></div>
        <?php endif; ?>
        <nav class="mnav-list" aria-label="Menu">
          <ul>
            <?php if ($__store): ?>
            <li data-sh="in" hidden><div class="mnav-row"><a href="/account/profile" class="mnav-link"><svg class="mnav-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg><span>My Profile</span></a></div></li>
            <li data-sh="in" hidden><div class="mnav-row"><a href="/account/orders" class="mnav-link"><svg class="mnav-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 2h14v20l-3-2-2 2-2-2-2 2-2-2-3 2Z"/><path d="M9 7h6M9 11h6M9 15h4"/></svg><span>Orders</span></a></div></li>
            <li><div class="mnav-row"><a href="/courses/" class="mnav-link"><svg class="mnav-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg><span>All Courses</span></a></div></li>
            <li data-sh="in" hidden><div class="mnav-row"><a href="/account/report" class="mnav-link"><svg class="mnav-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 22V4"/><path d="M4 4h13l-2 4 2 4H4"/></svg><span>Report us</span></a></div></li>
            <li><div class="mnav-row"><a href="/notes/" class="mnav-link"><svg class="mnav-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6M8 13h8M8 17h5"/></svg><span>PDF Notes</span></a></div></li>
            <li><div class="mnav-row"><a href="/blog" class="mnav-link"><svg class="mnav-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M13 2 3 14h9l-1 8 10-12h-9l1-8Z"/></svg><span>Blog</span></a></div></li>
            <?php endif; ?>
            <?php foreach ($__menu as $m): ?>
            <li<?= $__active($m['url']) ? ' class="active"' : '' ?>><div class="mnav-row"><a href="<?= htmlspecialchars($m['url']) ?>" class="mnav-link"<?= $__tgt($m) ?>><svg class="mnav-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= ($__ico[$m['icon']] ?? '') ?></svg><span><?= htmlspecialchars($m['label']) ?></span></a><?php if ($m['children']): ?><button type="button" class="mnav-chev" aria-expanded="false" aria-label="Show sub-menu"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></button><?php else: ?><svg class="mnav-arrow" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg><?php endif; ?></div><?php if ($m['children']): ?><ul class="mnav-sub"><?php foreach ($m['children'] as $c): ?><li<?= $__active((string)$c['url']) ? ' class="active"' : '' ?>><a href="<?= htmlspecialchars((string)$c['url']) ?>"<?= $__tgt($c) ?>><?= htmlspecialchars((string)$c['label']) ?></a></li><?php endforeach; ?></ul><?php endif; ?></li>
            <?php endforeach; ?>
          </ul>
        </nav>
        <div class="mnav-foot"<?= th_on('h_dark') ? '' : ' hidden' ?>>
          <a role="button" tabindex="0" class="mnav-dark dark-mode-toggle"><svg class="mnav-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg><span>Dark Mode</span><span class="mnav-switch" aria-hidden="true"></span></a>
        </div>
        <?php if ($__socials): ?>
        <div class="nb-drawer-follow"><span>Follow Us</span><div>
          <?php foreach ($__socials as [$name, $url, $path]): ?><a href="<?= htmlspecialchars($url) ?>" target="_blank" rel="noopener nofollow" aria-label="<?= $name ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="<?= $path ?>"/></svg></a><?php endforeach; ?>
        </div></div>
        <?php endif; ?>
      </aside>
