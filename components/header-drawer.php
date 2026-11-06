<?php
// Search overlay, menu button and the slide-in mobile menu — shared by the racing and the school-style header (components/header.php).
?>
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
        <div class="mnav-top">
          <a href="/" class="mnav-brand"><?= $__logo ?></a>
          <button type="button" class="mnav-close" id="closeBtn" aria-label="Close menu"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg></button>
        </div>
        <?php
          // Customize → Header → Mobile menu: greeting, Log in and Subscribe can each be hidden
          $__dHello = th_on('h_drawer_hello'); $__dLogin = th_on('h_login') && th_on('h_drawer_login'); $__dSub = $__subOn && th_on('h_drawer_sub');
        ?>
        <?php if ($__dHello || $__dLogin || $__dSub): ?>
        <div class="mnav-hello">
          <?php if ($__dHello): ?>
          <div class="mnav-hello-row">
            <span class="mnav-avatar"><?= $__userIco ?></span>
            <div class="mnav-hello-text"><p class="mnav-hello-title"><?= htmlspecialchars((string)th('h_drawer_title')) ?></p><?php if (trim((string)th('h_drawer_text')) !== ''): ?><p class="mnav-hello-sub"><?= htmlspecialchars((string)th('h_drawer_text')) ?></p><?php endif; ?></div>
          </div>
          <?php endif; ?>
          <?php if ($__dLogin || $__dSub): ?>
          <div class="mnav-hello-btns" style="<?= $__dLogin && $__dSub ? '' : 'grid-template-columns:1fr;' ?><?= $__dHello ? '' : 'margin-top:0' ?>">
            <?php if ($__dLogin): ?><a href="<?= htmlspecialchars($__loginUrl) ?>" class="mnav-btn outline"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="m10 17 5-5-5-5"/><path d="M15 12H3"/></svg> <?= htmlspecialchars((string)th('h_login_text')) ?></a><?php endif; ?>
            <?php if ($__dSub): ?><a <?= $__subAttr ?> class="mnav-btn solid"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.27 21a2 2 0 0 0 3.46 0"/><path d="M3.26 15.33A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.67C19.41 13.96 18 12.5 18 8A6 6 0 0 0 6 8c0 4.5-1.41 5.96-2.74 7.33"/></svg> <?= htmlspecialchars((string)th('h_sub_text')) ?></a><?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
        <div class="mnav-gap" aria-hidden="true"></div>
        <?php endif; ?>
        <nav class="mnav-list" aria-label="Menu">
          <ul>
            <?php foreach ($__menu as $m): ?>
            <li<?= $__active($m['url']) ? ' class="active"' : '' ?>><div class="mnav-row"><a href="<?= htmlspecialchars($m['url']) ?>" class="mnav-link"<?= $__tgt($m) ?>><svg class="mnav-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $__ico[$m['icon']] ?></svg><span><?= htmlspecialchars($m['label']) ?></span></a><?php if ($m['children']): ?><button type="button" class="mnav-chev" aria-expanded="false" aria-label="Show sub-menu"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></button><?php else: ?><svg class="mnav-arrow" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg><?php endif; ?></div><?php if ($m['children']): ?><ul class="mnav-sub"><?php foreach ($m['children'] as $c): ?><li<?= $__active((string)$c['url']) ? ' class="active"' : '' ?>><a href="<?= htmlspecialchars((string)$c['url']) ?>"<?= $__tgt($c) ?>><?= htmlspecialchars((string)$c['label']) ?></a></li><?php endforeach; ?></ul><?php endif; ?></li>
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
