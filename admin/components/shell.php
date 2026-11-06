<?php
/**
 * Shared admin shell — the same layout as the new CMS admin: admin bar on top, sidebar with
 * Main / Site / Admin / System groups, page header, then the page's own content.
 *
 *   <head> … <?php admin_shell_head(); ?> … </head>
 *   <body> <?php admin_shell_open('Page title', 'Subtitle'); ?>  …page content…  <?php admin_shell_close(); ?> </body>
 *
 * Every admin page uses this, so the sidebar is the same everywhere and the current page is highlighted.
 */

if (!function_exists('admin_shell_open')) {

function admin_shell_user(): array
{
    static $u = null;
    if ($u !== null) return $u;
    global $pdo;
    $u = ['username' => $_SESSION['username'] ?? 'admin', 'role' => $_SESSION['role'] ?? 'author', 'permissions' => []];
    if (!empty($_SESSION['user_id']) && isset($pdo)) {
        try {
            $st = $pdo->prepare('SELECT username, role, permissions FROM users WHERE id = ?');
            $st->execute([$_SESSION['user_id']]);
            if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $u = ['username' => $row['username'], 'role' => $row['role'], 'permissions' => json_decode($row['permissions'] ?? '{}', true) ?: []];
            }
        } catch (Throwable $e) {
        }
    }
    return $u;
}

/** Permission check: admins see everything, others need the flag (e.g. 'blogs.manage_tags'). */
function admin_can(string $path): bool
{
    $u = admin_shell_user();
    if ($u['role'] === 'admin') return true;
    $v = $u['permissions'];
    foreach (explode('.', $path) as $k) {
        if (!is_array($v) || !array_key_exists($k, $v)) return false;
        $v = $v[$k];
    }
    return !empty($v);
}

function admin_shell_nav(): array
{
    $root = $_SERVER['DOCUMENT_ROOT'] ?? '';
    $has = fn(string $href) => is_file($root . parse_url($href, PHP_URL_PATH));
    $isAdmin = admin_shell_user()['role'] === 'admin';
    $isEditor = admin_shell_user()['role'] === 'editor';
    // A link is shown when the user may use it and the page exists.
    $link = function (bool $allowed, string $label, string $href, string $icon) use ($has) {
        return ($allowed && $has($href)) ? ['label' => $label, 'href' => $href, 'icon' => $icon] : null;
    };
    $sub = fn(array $items) => array_values(array_filter($items));
    require_once ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/includes/institute.php';
    $ci = ci_can(['role' => admin_shell_user()['role'], 'permissions' => json_encode(admin_shell_user()['permissions'] ?: new stdClass())]);
    $groups = [
        ['label' => 'Main', 'items' => [
            ['label' => 'Dashboard', 'href' => '/admin/dashboard.php', 'icon' => 'fa-home'],
        ]],
        ['label' => 'Institute', 'items' => $ci ? [
            ['label' => 'Students', 'href' => '/admin/students.php', 'icon' => 'fa-user-graduate', 'open' => true, 'items' => $sub([
                $link(true, 'All Students', '/admin/students.php', 'fa-list'),
                $link(true, 'Add Student', '/admin/student-edit.php', 'fa-user-plus'),
            ])],
            $link(true, 'Batches', '/admin/ci-batches.php', 'fa-layer-group'),
            $link(true, 'Courses', '/admin/ci-courses.php', 'fa-book-open'),
            ['label' => 'Fees & Dues', 'href' => '/admin/ci-fees.php', 'icon' => 'fa-indian-rupee-sign', 'items' => $sub([
                $link(true, 'Fee Payments', '/admin/ci-fees.php', 'fa-receipt'),
                $link(true, 'Dues', '/admin/ci-fees.php?tab=dues', 'fa-hourglass-half'),
            ])],
            $link(true, 'Sessions & Classes', '/admin/ci-setup.php', 'fa-calendar-alt'),
        ] : []],
        ['label' => 'Blog', 'items' => [
            $link(true, 'Blog Dashboard', '/admin/blog-dashboard.php', 'fa-gauge'),
            ['label' => 'Posts', 'href' => '/admin/blogs-manager.php', 'icon' => 'fa-newspaper', 'items' => $sub([
                ['label' => 'All Posts', 'href' => '/admin/blogs-manager.php', 'icon' => 'fa-list'],
                $link(admin_can('blogs.create'), 'Add Post', '/admin/post-manager.php', 'fa-plus'),
                $link(admin_can('blogs.manage_categories'), 'Categories', '/admin/categories-manager.php', 'fa-folder'),
                $link(admin_can('blogs.manage_tags'), 'Tags', '/admin/tag-manager.php', 'fa-tags'),
                $link(admin_can('blogs.manage_comments'), 'Comments', '/admin/comments-manager.php', 'fa-comments'),
            ])],
            ['label' => 'Pages', 'href' => '/admin/pages-list.php', 'icon' => 'fa-file', 'items' => $sub([
                $link($isAdmin || $isEditor, 'All Pages', '/admin/pages-list.php', 'fa-list'),
                $link($isAdmin || $isEditor, 'Add Page', '/admin/page-editor.php', 'fa-plus'),
            ])],
            $link(admin_can('files.access_file_manager'), 'File Manager', '/admin/file-manager.php', 'fa-images'),
            $link(admin_can('analytics.view_basic'), 'Analytics', '/admin/analytics.php', 'fa-chart-line'),
            $isAdmin ? ['label' => 'SEO', 'href' => '/admin/seo.php', 'icon' => 'fa-search', 'items' => [
                ['label' => 'Dashboard', 'href' => '/admin/seo.php', 'icon' => 'fa-gauge'],
                ['label' => 'General Settings', 'href' => '/admin/seo.php?view=general', 'icon' => 'fa-sliders-h'],
                ['label' => 'Titles & Meta', 'href' => '/admin/seo.php?view=titles', 'icon' => 'fa-heading'],
                ['label' => 'Sitemap Settings', 'href' => '/admin/seo.php?view=sitemap', 'icon' => 'fa-sitemap'],
                ['label' => 'Instant Indexing', 'href' => '/admin/seo.php?view=indexing', 'icon' => 'fa-bolt'],
                ['label' => 'Redirections', 'href' => '/admin/redirections.php', 'icon' => 'fa-route'],
                ['label' => 'SEO Analyzer', 'href' => '/admin/seo.php?view=analyzer', 'icon' => 'fa-magnifying-glass-chart'],
                ['label' => 'Status & Tools', 'href' => '/admin/seo.php?view=status', 'icon' => 'fa-toolbox'],
            ]] : null,
            $link($isAdmin, 'Ad Inserter', '/admin/ad-inserter.php', 'fa-ad'),
            $link($isAdmin, 'Code Snippets', '/admin/code-snippets.php', 'fa-code'),
        ]],
        ['label' => 'Site', 'items' => [
            $link($isAdmin, 'Customize', '/admin/customize.php', 'fa-palette'),
            ['label' => 'Settings', 'href' => '#', 'icon' => 'fa-cogs', 'items' => $sub([
                $link($isAdmin, 'General', '/admin/general-settings.php', 'fa-sliders-h'),
                $link($isAdmin, 'Performance', '/admin/performance-settings.php', 'fa-tachometer-alt'),
            ])],
            admin_can('push_notifications.send') ? ['label' => 'Push Notifications', 'href' => '/push_notifications/push-manager.php', 'icon' => 'fa-bell'] : null, // delivery logs are inside the page (History)
            ($isAdmin || $isEditor) && $has('/admin/contact-messages.php') ? ['label' => 'Contact Messages', 'href' => '/admin/contact-messages.php', 'icon' => 'fa-envelope', 'badge' => admin_shell_unread()] : null,
        ]],
        ['label' => 'Admin', 'items' => [
            $link(admin_can('users.create'), 'Users', '/admin/user-manager.php', 'fa-user'),
            ['label' => 'Tools', 'href' => '#', 'icon' => 'fa-toolbox', 'items' => $sub([
                $link($isAdmin || $isEditor, 'Bulk Update', '/admin/bulk-update.php', 'fa-clock-rotate-left'),
                $link($isAdmin, 'Import & Export', '/admin/import-export.php', 'fa-exchange-alt'),
                $link($isAdmin, 'Backup & Restore', '/admin/backup-restore.php', 'fa-database'),
                $link(admin_can('settings.maintenance_mode'), 'Cache Manager', '/admin/cache-manager.php', 'fa-bolt'),
                $link($isAdmin, 'Cron Manager', '/admin/cron-manager.php', 'fa-clock'),
            ])],
            $link(admin_can('security.view_logs'), 'Activity Logs', '/admin/activity-logs.php', 'fa-history'),
        ]],
        ['label' => 'System', 'items' => [
            $link(true, 'My Profile', '/admin/my-profile.php', 'fa-user-circle'),
            ['label' => 'Logout', 'href' => '/admin/api/logout.php', 'icon' => 'fa-sign-out-alt'],
        ]],
    ];
    // Drop empty entries, empty submenus and empty groups.
    $out = [];
    foreach ($groups as $g) {
        $items = [];
        foreach ($g['items'] as $it) {
            if (!$it) continue;
            if (isset($it['items']) && !$it['items']) continue;
            $items[] = $it;
        }
        if ($items) $out[] = ['label' => $g['label'], 'items' => $items];
    }
    return $out;
}

/** Number of contact messages nobody has opened yet (shown as a small count next to "Contact Messages"). */
function admin_shell_unread(): int
{
    global $pdo;
    try { return isset($pdo) ? (int)$pdo->query("SELECT COUNT(*) FROM contact_messages WHERE status = 'new'")->fetchColumn() : 0; } catch (Throwable $e) { return 0; }
}

/** Sidebar / mobile header brand: the logo set in Settings → General, otherwise the site name as text. */
function admin_shell_brand(): array
{
    if (!function_exists('es') && defined('INCLUDES_PATH') && is_file(INCLUDES_PATH . '/settings.php')) require_once INCLUDES_PATH . '/settings.php';
    $logo = function_exists('es') ? trim((string)es('general', 'logo', '')) : '';
    $name = function_exists('es') ? trim((string)es('general', 'site_name', '')) : '';
    return [$logo, $name !== '' ? $name : (defined('SITE_NAME') ? edu_site_name() : 'Admin')];
}

function admin_shell_is_active(string $href): bool
{
    if ($href === '#') return false;
    $cur = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $path = parse_url($href, PHP_URL_PATH);
    if ($cur !== $path) return false;
    // Links that differ only by ?view=… (SEO screens) are active for their own view only.
    parse_str((string)parse_url($href, PHP_URL_QUERY), $want);
    $view = $_GET['view'] ?? '';
    if ($path === '/admin/seo.php') return ($want['view'] ?? '') === ($view === 'dashboard' ? '' : $view);
    foreach ($want as $k => $v) if ((string)($_GET[$k] ?? '') !== (string)$v) return false;
    return true;
}

function admin_shell_head(): void
{
    $v = @filemtime(($_SERVER['DOCUMENT_ROOT'] ?? '') . '/assets/css/admin-ui.css') ?: 1;
    $fav = function_exists('es') ? (string)es('general', 'favicon', '') : '';
    echo '<link rel="icon" href="' . htmlspecialchars($fav ?: '/assets/icons/favicon.ico') . '">' . "\n";
    echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">' . "\n";
    echo '<link rel="stylesheet" href="/assets/css/admin-ui.css?v=' . $v . '">' . "\n";
    // WordPress-style toolbar (includes/wpbar.php)
    require_once ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/includes/wpbar.php';
    $wpb = wpbar_assets();
    foreach ($wpb['css'] as $c) echo '<link rel="stylesheet" href="' . htmlspecialchars($c) . '">' . "\n";
    echo '<script src="' . htmlspecialchars($wpb['js']) . '" defer></script>' . "\n";
    echo '<script src="/assets/js/admin-ui.js?v=' . (@filemtime(($_SERVER['DOCUMENT_ROOT'] ?? '') . '/assets/js/admin-ui.js') ?: 1) . '" defer></script>' . "\n";
}

function admin_shell_icon(string $name): string
{
    static $icons = [
        'home' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
        'posts' => '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>',
        'chev' => '<svg class="ab-arrow" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 9l6 6 6-6"/></svg>',
        'tools' => '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-2 2 2 2 0 01-2-2v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 01-2-2 2 2 0 012-2h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 010-2.83 2 2 0 012.83 0l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 012-2 2 2 0 012 2v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 0 2 2 0 010 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 012 2 2 2 0 01-2 2h-.09a1.65 1.65 0 00-1.51 1z"/></svg>',
        'analytics' => '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>',
        'cache' => '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/></svg>',
        'view' => '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>',
        'logout' => '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>',
    ];
    return $icons[$name] ?? '';
}

function admin_shell_bar(): void
{
    // the same WordPress-style toolbar as on the site's pages (includes/wpbar.php)
    require_once ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/includes/wpbar.php';
    echo wpbar_html(['kind' => 'admin']);
}

function admin_shell_sidebar(): void
{
    [$logo, $site] = admin_shell_brand();
    ?>
<div class="sidebar-overlay" id="sidebarOverlay" onclick="admShell.close()"></div>
<aside class="sidebar sb" id="sidebar">
  <div class="sb-head">
    <a class="sb-brand" title="Dashboard" href="/admin/dashboard.php"><?php if ($logo): ?><img src="<?= htmlspecialchars($logo) ?>" alt="<?= htmlspecialchars($site) ?>" class="sb-logo"><?php else: ?><span class="sb-sitename"><?= htmlspecialchars($site) ?></span><?php endif; ?></a>
    <button class="sb-close" aria-label="Close menu" type="button" onclick="admShell.close()"><i class="fas fa-times"></i></button>
  </div>
  <nav class="sb-nav" id="admSbNav">
    <?php foreach (admin_shell_nav() as $g): ?>
    <div class="sb-section">
      <div class="sb-title"><?= htmlspecialchars($g['label']) ?></div>
      <?php foreach ($g['items'] as $it):
        if (!empty($it['items'])):
          $id = 'nav_' . strtolower(preg_replace('/[^a-z0-9]+/i', '_', $it['label']));
          $childActive = false;
          foreach ($it['items'] as $s) $childActive = $childActive || admin_shell_is_active($s['href']);
          $open = $childActive || !empty($it['open']);
      ?>
      <div data-submenu="<?= $id ?>" data-force="<?= $childActive ? '1' : '0' ?>">
        <div class="sb-row">
          <?php if ($it['href'] === '#'): ?>
          <button type="button" class="sb-link sb-flex sb-button" onclick="admShell.toggle('<?= $id ?>')"><span class="sb-icon"><i class="fas <?= $it['icon'] ?>"></i></span><?= htmlspecialchars($it['label']) ?></button>
          <?php else: ?>
          <a class="sb-link sb-flex" href="<?= $it['href'] ?>"><span class="sb-icon"><i class="fas <?= $it['icon'] ?>"></i></span><?= htmlspecialchars($it['label']) ?></a>
          <?php endif; ?>
          <button type="button" class="sb-chev<?= $open ? ' open' : '' ?>" onclick="admShell.toggle('<?= $id ?>')" aria-label="Toggle <?= htmlspecialchars($it['label']) ?>"><i class="fas fa-chevron-down"></i></button>
        </div>
        <div class="sb-submenu<?= $open ? ' open' : '' ?>">
          <?php foreach ($it['items'] as $s): ?>
          <a class="sb-link sb-sub<?= admin_shell_is_active($s['href']) ? ' active' : '' ?>" href="<?= $s['href'] ?>"><span class="sb-icon"><i class="fas <?= $s['icon'] ?>"></i></span><?= htmlspecialchars($s['label']) ?></a>
          <?php endforeach; ?>
        </div>
      </div>
      <?php else: ?>
      <a class="sb-link<?= admin_shell_is_active($it['href']) ? ' active' : '' ?>" href="<?= $it['href'] ?>"><span class="sb-icon"><i class="fas <?= $it['icon'] ?>"></i></span><?= htmlspecialchars($it['label']) ?><?php if (!empty($it['badge'])): ?><span class="sb-badge" title="<?= (int)$it['badge'] ?> unread"><?= $it['badge'] > 99 ? '99+' : (int)$it['badge'] ?></span><?php endif; ?></a>
      <?php endif; endforeach; ?>
    </div>
    <?php endforeach; ?>
  </nav>
</aside>
    <?php
}

/** Opens the page: admin bar, sidebar, header with title/subtitle (HTML, already escaped by the caller) and optional right-side HTML. */
function admin_shell_open(string $title, string $subtitle = '', string $navRight = ''): void
{
    [$logo, $site] = admin_shell_brand();
    admin_shell_bar();
    // signed in: the public pages show the admin bar (see admin/api/adminbar.php)
    echo '<script>document.cookie="edu_ab=1;path=/;max-age=2592000;samesite=lax"</script>';
    echo '<div class="admin-container">';
    admin_shell_sidebar();
    ?>
<div class="main-content">
  <header class="top-nav">
    <div class="nav-left">
      <button class="menu-toggle" aria-label="Toggle sidebar" type="button" onclick="admShell.open()"><i class="fas fa-bars"></i></button>
      <span class="sitename-mob"><?php if ($logo): ?><img src="<?= htmlspecialchars($logo) ?>" alt="" class="sb-logo sb-logo-mob"><?php else: ?><span class="sb-sitename"><?= htmlspecialchars($site) ?></span><?php endif; ?></span>
      <div class="page-heading"><h1><?= $title ?></h1><?php if ($subtitle !== ''): ?><p><?= $subtitle ?></p><?php endif; ?></div>
    </div>
    <div class="nav-right"><?= $navRight ?></div>
  </header>
  <div class="content-wrapper">
    <?php
}

function admin_shell_close(): void
{
    ?>
  </div>
</div>
</div>
<script>
window.admShell = {
  open() { document.getElementById('sidebar').classList.add('open'); document.getElementById('sidebarOverlay').classList.add('active'); },
  close() { document.getElementById('sidebar').classList.remove('open'); document.getElementById('sidebarOverlay').classList.remove('active'); },
  toggle(id) {
    const box = document.querySelector('[data-submenu="' + id + '"]'); if (!box) return;
    const sub = box.querySelector('.sb-submenu'), chev = box.querySelector('.sb-chev');
    const open = !sub.classList.contains('open');
    sub.classList.toggle('open', open); chev.classList.toggle('open', open);
    try { localStorage.setItem(id, open ? '1' : '0'); } catch (e) {}
  },
};
// Restore saved open/closed submenus (a submenu containing the current page always stays open) and the sidebar scroll.
document.querySelectorAll('[data-submenu]').forEach(function (box) {
  if (box.dataset.force === '1') return;
  try { var v = localStorage.getItem(box.dataset.submenu); if (v !== null) { var o = v === '1'; box.querySelector('.sb-submenu').classList.toggle('open', o); box.querySelector('.sb-chev').classList.toggle('open', o); } } catch (e) {}
});
(function () { var n = document.getElementById('admSbNav'); if (!n) return; try { n.scrollTop = +sessionStorage.getItem('adm_sb_scroll') || 0; } catch (e) {} n.addEventListener('scroll', function () { try { sessionStorage.setItem('adm_sb_scroll', n.scrollTop); } catch (e) {} }); })();
</script>
    <?php
}

}
