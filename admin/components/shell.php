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
    if (!function_exists('edu_role_allows')) require_once dirname(__DIR__, 2) . '/includes/roles.php';
    $group = explode('.', $path)[0];
    if (!edu_role_allows($u['role'], $group)) return false; // a role never reaches outside its part (blog / office)
    if ($u['role'] === 'blog_admin') return $group !== 'users' || in_array($path, ['users.create', 'users.edit', 'users.delete', 'users.suspend'], true); // the whole blog
    $v = $u['permissions'];
    foreach (explode('.', $path) as $k) {
        if (!is_array($v) || !array_key_exists($k, $v)) return false;
        $v = $v[$k];
    }
    return !empty($v);
}

/** May the user use the blog part (posts, blog dashboard)? Admins always; others with Blogs → own or all. */
function admin_blog_access(): bool
{
    return admin_shell_user()['role'] === 'admin' || admin_can('blogs.create') || admin_can('blogs.edit_own') || admin_can('blogs.edit_all');
}

function admin_shell_nav(): array
{
    $root = $_SERVER['DOCUMENT_ROOT'] ?? '';
    $has = fn(string $href) => is_file($root . parse_url($href, PHP_URL_PATH));
    $isAdmin = admin_shell_user()['role'] === 'admin';
    $isEditor = admin_shell_user()['role'] === 'editor';
    $isBlogAdmin = admin_shell_user()['role'] === 'blog_admin';
    // A link is shown when the user may use it and the page exists.
    $link = function (bool $allowed, string $label, string $href, string $icon) use ($has) {
        return ($allowed && $has($href)) ? ['label' => $label, 'href' => $href, 'icon' => $icon] : null;
    };
    $sub = fn(array $items) => array_values(array_filter($items));
    require_once ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/includes/institute.php';
    $cu = ['role' => admin_shell_user()['role'], 'permissions' => admin_shell_user()['permissions'] ?: []];
    $ci = ci_can($cu);
    $cp = fn(string $k) => ci_perm($cu, $k);
    $blogOn = admin_blog_access(); // Users → Blogs: own or all (not "No access")
    $groups = [
        ['label' => 'Main', 'items' => [
            ['label' => 'Dashboard', 'href' => '/admin/dashboard.php', 'icon' => 'fa-home'],
        ]],
        ['label' => 'Institute', 'items' => $ci ? [
            ['label' => 'Students', 'href' => '/admin/students.php', 'icon' => 'fa-user-graduate', 'items' => $sub([
                $link(true, 'All Students', '/admin/students.php', 'fa-list'),
                $link($cp('add_student'), 'Add Student', '/admin/student-edit.php', 'fa-user-plus'),
            ])],
            $link($cp('batches'), 'Batches', '/admin/ci-batches.php', 'fa-layer-group'),
            $link($cp('courses'), 'Courses', '/admin/ci-courses.php', 'fa-book-open'),
            $cp('fees') ? ['label' => 'Fees & Dues', 'href' => '/admin/ci-fees.php', 'icon' => 'fa-indian-rupee-sign', 'items' => $sub([
                $link(true, 'Fee Payments', '/admin/ci-fees.php', 'fa-receipt'),
                $link(true, 'Dues', '/admin/ci-fees.php?tab=dues', 'fa-hourglass-half'),
            ])] : null,
            $link($cp('sessions') || $cp('classes'), 'Sessions & Classes', '/admin/ci-setup.php', 'fa-calendar-alt'),
            $link(true, 'Reports', '/admin/ci-reports.php', 'fa-file-pdf'),
        ] : []],
        ['label' => 'Online Store', 'items' => (function () use ($cu, $link, $ci) {
            require_once ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/includes/shop.php';
            return [
                $link(sh_perm($cu, 'products'), 'Online Courses', '/admin/shop-products.php?type=course', 'fa-photo-film'),
                $link(sh_perm($cu, 'products'), 'Add Lecture', '/admin/shop-lecture.php', 'fa-film'),
                $link(sh_perm($cu, 'products'), 'PDF Notes', '/admin/shop-products.php?type=notes', 'fa-file-pdf'),
                $link(sh_perm($cu, 'products'), 'Categories', '/admin/shop-categories.php', 'fa-shapes'),
                $link(sh_perm($cu, 'teacher') || sh_perm($cu, 'products') || sh_perm($cu, 'customers'), 'Doubts', '/admin/shop-doubts.php', 'fa-circle-question'),
                $link(sh_perm($cu, 'orders'), 'Orders', '/admin/shop-orders.php', 'fa-cart-shopping'),
                $link(sh_perm($cu, 'customers'), 'Customers', '/admin/shop-customers.php', 'fa-users'),
                $link(sh_perm($cu, 'products'), 'Reviews', '/admin/shop-reviews.php', 'fa-star'),
                $link(sh_perm($cu, 'customers'), 'Student Reports', '/admin/shop-reports.php', 'fa-flag'),
                $link(sh_perm($cu, 'orders') && !$ci, 'Reports', '/admin/ci-reports.php?type=store', 'fa-file-pdf'), // one Reports page (Institute → Reports has Online sales too)
                $link(sh_perm($cu, 'settings'), 'Homepage', ($cu['role'] ?? '') === 'admin' ? '/admin/customize.php?panel=homepage' : '/admin/home-page.php', 'fa-house'), // admins: Customize → Homepage
                $link(sh_perm($cu, 'settings'), 'Store Settings', '/admin/shop-settings.php', 'fa-sliders'),
            ];
        })()],
        ['label' => 'Blog', 'collapsible' => true, 'items' => [
            $link($blogOn, 'Blog Dashboard', '/admin/blog-dashboard.php', 'fa-gauge'),
            $blogOn ? ['label' => 'Posts', 'href' => '/admin/blogs-manager.php', 'icon' => 'fa-newspaper', 'items' => $sub([
                ['label' => 'All Posts', 'href' => '/admin/blogs-manager.php', 'icon' => 'fa-list'],
                $link(admin_can('blogs.create'), 'Add Post', '/admin/post-manager.php', 'fa-plus'),
                $link(admin_can('blogs.manage_categories'), 'Categories', '/admin/categories-manager.php', 'fa-folder'),
                $link(admin_can('blogs.manage_tags'), 'Tags', '/admin/tag-manager.php', 'fa-tags'),
                $link(admin_can('blogs.manage_comments'), 'Comments', '/admin/comments-manager.php', 'fa-comments'),
            ])] : null,
            ['label' => 'Pages', 'href' => '/admin/pages-list.php', 'icon' => 'fa-file', 'items' => $sub([
                $link($isAdmin, 'All Pages', '/admin/pages-list.php', 'fa-list'),
                $link($isAdmin, 'Add Page', '/admin/page-editor.php', 'fa-plus'),
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
            $link($isAdmin || admin_can('ads.manage_ads'), 'Ad Inserter', '/admin/ad-inserter.php', 'fa-ad'),
            $link($isAdmin || $isBlogAdmin, 'Code Snippets', '/admin/code-snippets.php', 'fa-code'),
            $link($isBlogAdmin, 'Blog Customize', '/admin/customize.php', 'fa-palette'), // post template, also read, sidebar …
        ]],
        ['label' => 'Site', 'items' => [
            $link($isAdmin, 'Customize', '/admin/customize.php', 'fa-palette'),
            ['label' => 'Settings', 'href' => '#', 'icon' => 'fa-cogs', 'items' => $sub([
                $link($isAdmin, 'General', '/admin/general-settings.php', 'fa-sliders-h'),
                $link($isAdmin, 'Performance', '/admin/performance-settings.php', 'fa-tachometer-alt'),
            ])],
            admin_can('push_notifications.send') ? ['label' => 'Push Notifications', 'href' => '/push_notifications/push-manager.php', 'icon' => 'fa-bell'] : null, // delivery logs are inside the page (History)
            $isAdmin && $has('/admin/contact-messages.php') ? ['label' => 'Contact Messages', 'href' => '/admin/contact-messages.php', 'icon' => 'fa-envelope', 'badge' => admin_shell_unread()] : null,
        ]],
        ['label' => 'Admin', 'items' => [
            $link(admin_can('users.create'), 'Users', '/admin/user-manager.php', 'fa-user'),
            ['label' => 'Tools', 'href' => '#', 'icon' => 'fa-toolbox', 'items' => $sub([
                $link($isAdmin || $isEditor || $isBlogAdmin, 'Bulk Update', '/admin/bulk-update.php', 'fa-clock-rotate-left'),
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
        if ($items) $out[] = ['label' => $g['label'], 'items' => $items, 'collapsible' => !empty($g['collapsible'])];
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
    // square corners + no shadows everywhere, and the themed dropdown for every <select>
    $root = $_SERVER['DOCUMENT_ROOT'] ?? '';
    echo '<link rel="stylesheet" href="/assets/css/admin-flat.css?v=' . (@filemtime($root . '/assets/css/admin-flat.css') ?: 1) . '">' . "\n";
    echo '<link rel="stylesheet" href="/assets/css/admin-wpmenu.css?v=' . (@filemtime($root . '/assets/css/admin-wpmenu.css') ?: 1) . '">' . "\n"; // WordPress-style sidebar menu
    echo '<script src="/assets/js/admin-select.js?v=' . (@filemtime($root . '/assets/js/admin-select.js') ?: 1) . '" defer></script>' . "\n";
    echo '<script src="/assets/js/admin-display.js?v=' . (@filemtime($root . '/assets/js/admin-display.js') ?: 1) . '" defer></script>' . "\n";
    echo '<script src="/assets/js/admin-table.js?v=' . (@filemtime($root . '/assets/js/admin-table.js') ?: 1) . '" defer></script>' . "\n";
    echo '<script src="/assets/js/admin-modal.js?v=' . (@filemtime($root . '/assets/js/admin-modal.js') ?: 1) . '" defer></script>' . "\n";
    echo '<script src="/assets/js/admin-date.js?v=' . (@filemtime($root . '/assets/js/admin-date.js') ?: 1) . '" defer></script>' . "\n";
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
    <?php
    // the site uses the school-style header brand (Customize → Header): show the same brand here
    require_once ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/includes/institute.php';
    $br = ci_brand();
    $school = true; // the site has one header brand: logo + site title + line (Customize → Site Identity)
    if ($school): ?>
    <a class="sb-brand sb-school" href="/admin/dashboard.php" aria-label="Dashboard"><?php if ($br['emblem'] !== ''): ?><img src="<?= htmlspecialchars($br['emblem']) ?>" alt="" class="sb-emblem" width="40" height="46"><?php endif; ?><span class="sb-school-t"><b><?= htmlspecialchars($br['title']) ?></b><?php if ($br['line'] !== ''): ?><small><?= htmlspecialchars($br['line']) ?></small><?php endif; ?></span></a>
    <?php else: ?>
    <a class="sb-brand" title="Dashboard" href="/admin/dashboard.php"><?php if ($logo): ?><img src="<?= htmlspecialchars($logo) ?>" alt="<?= htmlspecialchars($site) ?>" class="sb-logo"><?php else: ?><span class="sb-sitename"><?= htmlspecialchars($site) ?></span><?php endif; ?></a>
    <?php endif; ?>
    <button class="sb-close" aria-label="Close menu" type="button" onclick="admShell.close()"><i class="fas fa-times"></i></button>
  </div>
  <nav class="sb-nav" id="admSbNav">
    <?php foreach (admin_shell_nav() as $g): ?>
    <?php
      $gid = 'sbg_' . strtolower(preg_replace('/[^a-z0-9]+/i', '_', $g['label']));
      $gActive = false;
      foreach ($g['items'] as $gi) { if (!$gi) continue; $gActive = $gActive || admin_shell_is_active($gi['href']); foreach ($gi['items'] ?? [] as $gs) $gActive = $gActive || admin_shell_is_active($gs['href']); }
      $gCol = !empty($g['collapsible']);
    ?>
    <div class="sb-section<?= $gCol ? ' sb-collapsible' . ($gActive ? ' open' : '') : '' ?>" id="<?= $gid ?>">
      <?php if ($gCol): ?><button type="button" class="sb-title sb-title-btn" aria-expanded="<?= $gActive ? 'true' : 'false' ?>" onclick="admShell.group('<?= $gid ?>')"><?= htmlspecialchars($g['label']) ?><i class="fas fa-chevron-down"></i></button><div class="sb-group-body"><?php else: ?>
      <div class="sb-title"><?= htmlspecialchars($g['label']) ?></div><?php endif; ?>
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
      <?php if ($gCol): ?></div><?php endif; ?>
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
      <div class="page-heading"><h1><?= $title ?></h1><?php if ($subtitle !== ''): ?><p><?= $subtitle ?></p><?php endif; ?></div>
    </div>
    <div class="nav-right"><?= $navRight ?><div class="dsp-wrap"><button type="button" class="dsp-btn" id="dspBtn" aria-haspopup="true" aria-expanded="false"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6"/></svg><span class="dsp-lbl">Display options</span><span class="dsp-count" id="dspCount" hidden></span></button><div class="dsp-panel" id="dspPanel" hidden></div></div></div>
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
// Page-header buttons: their words go in a span, so phones can show the icon alone (the word stays as the label)
document.querySelectorAll('.top-nav .nav-right a, .top-nav .nav-right button').forEach(function (b) {
  if (b.id === 'dspBtn' || b.closest('.dsp-panel')) return;
  var words = '';
  [].slice.call(b.childNodes).forEach(function (n) {
    if (n.nodeType === 3 && n.textContent.trim()) { words += n.textContent.trim() + ' '; var s = document.createElement('span'); s.className = 'nr-t'; s.textContent = n.textContent; b.replaceChild(s, n); }
    else if (n.nodeType === 1 && n.tagName === 'SPAN' && !n.className && n.textContent.trim()) { words += n.textContent.trim() + ' '; n.className = 'nr-t'; }
  });
  if (words && !b.getAttribute('aria-label')) b.setAttribute('aria-label', words.trim());
  if (words && b.querySelector('svg, i, img')) b.classList.add('nr-ico');
});
window.admShell = {
  open() { document.getElementById('sidebar').classList.add('open'); document.getElementById('sidebarOverlay').classList.add('active'); },
  close() { document.getElementById('sidebar').classList.remove('open'); document.getElementById('sidebarOverlay').classList.remove('active'); },
  toggle(id) {
    const box = document.querySelector('[data-submenu="' + id + '"]'); if (!box) return;
    const sub = box.querySelector('.sb-submenu'), chev = box.querySelector('.sb-chev');
    const open = !sub.classList.contains('open');
    sub.classList.toggle('open', open); chev.classList.toggle('open', open);
  },
  group(id) {
    const g = document.getElementById(id); if (!g) return;
    const open = !g.classList.contains('open');
    g.classList.toggle('open', open);
    const b = g.querySelector('.sb-title-btn'); if (b) b.setAttribute('aria-expanded', open ? 'true' : 'false');
  },
};
// WordPress-style fly-out: on a computer, a menu whose sub-menu is closed shows it to the right while hovered (or focused).
(function () {
  var mq = window.matchMedia('(min-width:1024px)'), shut = null;
  function place(box) {
    var sub = box.querySelector('.sb-submenu'); if (!sub || sub.classList.contains('open') || !mq.matches) return;
    clearTimeout(shut); [].forEach.call(document.querySelectorAll('[data-submenu].fly'), function (o) { if (o !== box) o.classList.remove('fly'); });
    var r = box.getBoundingClientRect(); box.classList.add('fly');
    sub.style.left = r.right + 'px'; sub.style.top = r.top + 'px';
    var h = sub.offsetHeight, max = window.innerHeight - 8; if (r.top + h > max) sub.style.top = Math.max(8, max - h) + 'px';
  }
  function hide(box) { shut = setTimeout(function () { box.classList.remove('fly'); }, 120); }
  [].forEach.call(document.querySelectorAll('.sidebar.sb [data-submenu]'), function (box) {
    box.addEventListener('mouseenter', function () { place(box); });
    box.addEventListener('mouseleave', function () { hide(box); });
    box.addEventListener('focusin', function () { place(box); });
    box.addEventListener('focusout', function (e) { if (!box.contains(e.relatedTarget)) box.classList.remove('fly'); });
  });
  var nav = document.getElementById('admSbNav'); if (nav) nav.addEventListener('scroll', function () { [].forEach.call(document.querySelectorAll('[data-submenu].fly'), function (o) { o.classList.remove('fly'); }); });
})();
// Menus open only for the page you are on: after a refresh every other menu starts closed (nothing is remembered).
try { Object.keys(localStorage).forEach(function (k) { if (/^nav_/.test(k)) localStorage.removeItem(k); }); } catch (e) {}
(function () { var n = document.getElementById('admSbNav'); if (!n) return; try { n.scrollTop = +sessionStorage.getItem('adm_sb_scroll') || 0; } catch (e) {} n.addEventListener('scroll', function () { try { sessionStorage.setItem('adm_sb_scroll', n.scrollTop); } catch (e) {} }); })();
</script>
    <?php
}

}
