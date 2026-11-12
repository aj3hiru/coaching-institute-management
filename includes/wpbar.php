<?php
/**
 * Admin bar in the same structure and look as the WordPress toolbar (#wpadminbar, WordPress' own admin-bar.css and
 * dashicons): left — menu, site name, Customize, Clear Cache, Comments, + New, Edit; right — photo + name, Search.
 * Used by the public pages (admin/api/adminbar.php → filled in by the browser, pages are cached) and by every admin
 * page (admin/components/shell.php). Links are only shown when the signed-in user may open them.
 *
 * $ctx: kind = home|post|page|category|tag|search|admin …, id (post id), slug (page slug)
 */

if (!function_exists('wpbar_html')) {

require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/components/shell.php';

/** CSS + JS the bar needs (admin pages print these in <head>; public pages load them from the bar script). */
function wpbar_assets(): array
{
    $root = $_SERVER['DOCUMENT_ROOT'] ?? '';
    $v = fn(string $p) => $p . '?v=' . (@filemtime($root . $p) ?: 1);
    return [
        'css' => [$v('/assets/css/dashicons.min.css'), $v('/assets/css/wp-admin-bar.min.css'), $v('/assets/css/wpbar.css')],
        'js' => $v('/assets/js/wpbar.js'),
    ];
}

function wpbar_e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/** One <li>: [id, label html, href, class, children (list of groups: [groupId, items, secondary?])] */
function wpbar_li(string $id, string $label, string $href, string $class = '', array $groups = []): string
{
    $pop = $groups ? 'menupop' : '';
    $cls = trim($pop . ' ' . $class);
    $h = '<li role="group" id="wp-admin-bar-' . $id . '"' . ($cls !== '' ? ' class="' . $cls . '"' : '') . '>';
    $h .= '<a class="ab-item" role="menuitem"' . ($groups ? ' aria-expanded="false"' : '') . ' href="' . wpbar_e($href) . '">' . $label . '</a>';
    if ($groups) {
        $h .= '<div class="ab-sub-wrapper">';
        foreach ($groups as $g) {
            [$gid, $items, $secondary] = $g + [2 => false];
            if (!$items) continue;
            $h .= '<ul role="menu" id="wp-admin-bar-' . $gid . '" class="' . ($secondary ? 'ab-sub-secondary ' : '') . 'ab-submenu">';
            foreach ($items as [$iid, $ilabel, $ihref]) {
                $h .= '<li role="group" id="wp-admin-bar-' . $iid . '"><a class="ab-item" role="menuitem" href="' . wpbar_e($ihref) . '">' . wpbar_e($ilabel) . '</a></li>';
            }
            $h .= '</ul>';
        }
        $h .= '</div>';
    }
    return $h . '</li>';
}

/** Grey "mystery person" picture, like WordPress shows when the user has no photo. */
function wpbar_default_avatar(): string
{
    return 'data:image/svg+xml,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" fill="#c3c4c7"/><circle cx="32" cy="25" r="12" fill="#fff"/><path d="M10 64c2-15 11-22 22-22s20 7 22 22z" fill="#fff"/></svg>');
}

/** The signed-in staff member (or null when nobody / not allowed). */
function wpbar_user(): ?array
{
    global $pdo;
    if (empty($_SESSION['user_id']) || !isset($pdo)) return null;
    $st = $pdo->prepare('SELECT id, username, role, status, permissions FROM users WHERE id = ?');
    $st->execute([$_SESSION['user_id']]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u || $u['status'] !== 'active' || !in_array($u['role'], ['admin', 'blog_admin', 'editor', 'author', 'teacher', 'accountant'], true)) return null;
    $a = $pdo->prepare('SELECT id, name, profile_image FROM authors WHERE user_id = ? LIMIT 1');
    $a->execute([$u['id']]);
    $author = $a->fetch(PDO::FETCH_ASSOC) ?: [];
    $u['name'] = trim((string)($author['name'] ?? '')) ?: $u['username'];
    $u['avatar'] = !empty($author['profile_image']) ? '/' . ltrim($author['profile_image'], '/') : '';
    return $u;
}

function wpbar_html(array $ctx = []): string
{
    global $pdo;
    $u = wpbar_user();
    if (!$u) return '';
    $isAdmin = $u['role'] === 'admin';
    $isEditor = $u['role'] === 'editor';
    $inAdmin = ($ctx['kind'] ?? '') === 'admin';
    if (!function_exists('ci_can')) require_once __DIR__ . '/institute.php';
    $ci = ci_can($u);
    if (!function_exists('sh_perm') && is_file(__DIR__ . '/shop.php')) require_once __DIR__ . '/shop.php';
    $shopEdit = function_exists('sh_perm') && sh_perm($u, 'products');
    $root = $_SERVER['DOCUMENT_ROOT'] ?? '';
    $has = fn(string $href) => is_file($root . parse_url($href, PHP_URL_PATH));
    $keep = fn(array $items) => array_values(array_filter($items, fn($i) => !empty($i[3]) && $has($i[2])));
    [, $site] = admin_shell_brand();

    // 1. (no menu button first: the bar starts with the site name)
    $left = '';

    // 2. Site name: on the site → admin pages; in the admin → a plain link to the site (no "Visit Site" menu)
    if ($inAdmin) {
        $left .= wpbar_li('site-name', wpbar_e($site), '/', 'dk-in-admin');
    } else {
        $left .= wpbar_li('site-name', wpbar_e($site), '/admin/dashboard.php'); // a plain link to the Dashboard (no drop-down on hover)
        // 3. Customize
        if ($isAdmin && $has('/admin/customize.php')) $left .= wpbar_li('customize', 'Customize', '/admin/customize.php', 'hide-if-no-customize');
    }

    // 4. Clear Cache (the slot of WordPress' Updates)
    if (admin_can('settings.maintenance_mode') && $has('/admin/cache-manager.php')) {
        $left .= '<li role="group" id="wp-admin-bar-updates" class="dk-cache"><a class="ab-item" role="menuitem" href="/admin/cache-manager.php" data-wpbar-clear data-csrf="'
            . wpbar_e($_SESSION['edu_csrf'] ??= bin2hex(random_bytes(32))) . '" title="Clear all cached pages"><span class="ab-icon" aria-hidden="true"></span><span class="ab-label">Clear Cache</span></a></li>';
    }

    // 4b. Superadmin only: admin-area maintenance mode switch (everyone else sees a maintenance page while it is on)
    if (function_exists('edu_is_super') && edu_is_super((int)$u['id'])) {
        $mOn = edu_maintenance_on();
        $left .= '<li role="group" id="wp-admin-bar-dk-maint" class="dk-maint' . ($mOn ? ' is-on' : '') . '"><a class="ab-item" role="menuitem" href="#" data-wpbar-maint="' . ($mOn ? '0' : '1') . '" data-csrf="'
            . wpbar_e($_SESSION['edu_csrf'] ??= bin2hex(random_bytes(32))) . '" title="' . ($mOn ? 'Admin area is closed for everyone else — click to open it' : 'Close the admin area for everyone else') . '"><span class="ab-icon" aria-hidden="true"></span><span class="ab-label">'
            . ($mOn ? 'Maintenance mode: ON' : 'Maintenance mode on') . '</span></a></li>';
    }

    // 5. Comments waiting for approval
    if (admin_can('blogs.manage_comments') && $has('/admin/comments-manager.php')) {
        $pending = 0;
        try { $pending = (int)$pdo->query("SELECT COUNT(*) FROM comments WHERE status IS NULL OR status NOT IN ('approved','spam','trash')")->fetchColumn(); } catch (Throwable $e) {}
        $left .= wpbar_li('comments', '<span class="ab-icon" aria-hidden="true"></span><span class="ab-label awaiting-mod pending-count count-' . $pending . '" aria-hidden="true">' . $pending
            . '</span><span class="screen-reader-text comments-in-moderation-text">' . $pending . ' Comments in moderation</span>', '/admin/comments-manager.php');
    }

    // 6. + New
    $new = $keep([
        ['new-student', 'Student', '/admin/student-edit.php', $ci],
        ['new-post', 'Post', '/admin/post-manager.php', admin_can('blogs.create')],
        ['new-media', 'Media', '/admin/file-manager.php', admin_can('files.access_file_manager')],
        ['new-page', 'Page', '/admin/page-editor.php', $isAdmin],
        ['new-course', 'Course', '/admin/shop-products.php?new=1&type=course', $shopEdit],
        ['new-notes', 'PDF notes', '/admin/shop-products.php?new=1&type=notes', $shopEdit],
        ['new-user', 'User', '/admin/user-manager.php', admin_can('users.create')],
    ]);
    if ($new) $left .= wpbar_li('new-content', '<span class="ab-icon" aria-hidden="true"></span><span class="ab-label">New</span>', $new[0][2], '', [['new-content-default', $new]]);

    // 7. Edit (the page being viewed)
    $edit = null;
    $kind = (string)($ctx['kind'] ?? '');
    if ($kind === 'post' && ($pid = (int)($ctx['id'] ?? 0)) > 0) {
        $p = $pdo->prepare('SELECT p.id, au.user_id FROM posts p LEFT JOIN authors au ON au.id = p.author_id WHERE p.id = ?');
        $p->execute([$pid]);
        if ($row = $p->fetch(PDO::FETCH_ASSOC)) {
            $own = (int)($row['user_id'] ?? 0) === (int)$u['id'];
            if ($isAdmin || admin_can('blogs.edit_all') || ($own && admin_can('blogs.edit_own'))) $edit = ['Edit Post', '/admin/post-manager.php?id=' . (int)$row['id']];
        }
    } elseif ($kind === 'page' && ($slug = preg_replace('/[^a-z0-9-]/', '', strtolower((string)($ctx['slug'] ?? '')))) !== '' && $isAdmin) {
        $p = $pdo->prepare('SELECT id FROM site_pages WHERE slug = ? LIMIT 1');
        $p->execute([$slug]);
        if ($pg = $p->fetchColumn()) $edit = ['Edit Page', '/admin/page-editor.php?id=' . (int)$pg];
    } elseif ($kind === 'category' && admin_can('blogs.manage_categories')) {
        $edit = ['Edit Category', '/admin/categories-manager.php'];
    } elseif (($cid = (int)($ctx['course'] ?? 0)) > 0) { // a course / PDF notes page: edit it (staff who may edit courses)
        if (!function_exists('sh_perm')) require_once __DIR__ . '/shop.php';
        $cp = $pdo->prepare('SELECT id, type FROM sh_products WHERE id = ?'); $cp->execute([$cid]);
        if (($row = $cp->fetch(PDO::FETCH_ASSOC)) && sh_perm($u, 'products')) {
            $isC = $row['type'] === 'course';
            $edit = [$isC ? 'Edit Course' : 'Edit Notes', '/admin/shop-products.php?edit=' . $cid . '&type=' . ($isC ? 'course' : 'notes'),
                $isC ? [['edit-course-lectures', 'Lectures & chapters', '/admin/shop-lectures.php?course=' . $cid], ['edit-course-details', 'Course details, price & picture', '/admin/shop-products.php?edit=' . $cid . '&type=course']] : []];
        }
    } elseif ($kind === 'home' && $isAdmin) {
        $edit = ['Edit Homepage', '/admin/customize.php'];
    }
    if ($edit) $left .= wpbar_li('edit', wpbar_e($edit[0]), $edit[1], '', !empty($edit[2]) ? [['edit-sub', $edit[2]]] : []);

    // Right side: photo + name (profile menu), Search
    $av = $u['avatar'] !== '' ? $u['avatar'] : wpbar_default_avatar();
    $name = wpbar_e($u['name']);
    $right = '<li role="group" id="wp-admin-bar-my-account" class="menupop with-avatar"><a class="ab-item" role="menuitem" aria-expanded="false" href="/admin/my-profile.php"><img alt="" src="' . wpbar_e($av) . '" class="avatar avatar-28 photo" height="28" width="28" decoding="async">'
        . '<span class="display-name">' . $name . '</span></a>'
        . '<div class="ab-sub-wrapper"><ul role="menu" aria-label="' . $name . '" id="wp-admin-bar-user-actions" class="ab-submenu dk-user-menu">'
        . '<li role="group" id="wp-admin-bar-user-info" class="dk-uhead"><a class="ab-item" role="menuitem" href="/admin/my-profile.php"><img alt="" src="' . wpbar_e($av) . '" class="avatar avatar-64 photo" height="64" width="64" decoding="async">'
        . '<span class="display-name">' . $name . '</span><span class="dk-role">' . wpbar_e(ucfirst($u['role'])) . '</span></a></li>'
        . '<li role="group" id="wp-admin-bar-dk-profile" class="dk-ulink"><a class="ab-item" role="menuitem" href="/admin/my-profile.php"><span class="dk-ui dashicons-before dashicons-admin-users" aria-hidden="true"></span>Edit Profile</a></li>'
        . '<li role="group" id="wp-admin-bar-dk-dash" class="dk-ulink"><a class="ab-item" role="menuitem" href="/admin/dashboard.php"><span class="dk-ui dashicons-before dashicons-dashboard" aria-hidden="true"></span>Dashboard</a></li>'
        . '<li role="group" id="wp-admin-bar-logout" class="dk-ulink dk-out"><a class="ab-item" role="menuitem" href="/admin/api/logout.php"><span class="dk-ui dashicons-before dashicons-exit" aria-hidden="true"></span>Log Out</a></li></ul></div></li>';
    if (!$inAdmin) {
        $right .= '<li role="group" id="wp-admin-bar-search" class="admin-bar-search"><div class="ab-item ab-empty-item" tabindex="-1" role="menuitem"><form action="/search" method="get" id="adminbarsearch">'
            . '<input class="adminbar-input" name="q" id="adminbar-search" type="text" value="" maxlength="150"><label for="adminbar-search" class="screen-reader-text">Search</label>'
            . '<input type="submit" class="adminbar-button" value="Search"></form></div></li>';
    }

    return '<div id="wpadminbar" class="nojq nojs"><a class="screen-reader-shortcut" href="#wp-toolbar" tabindex="1">Skip to toolbar</a>'
        . '<div class="quicklinks" id="wp-toolbar" role="navigation" aria-label="Toolbar">'
        . '<ul role="menu" id="wp-admin-bar-root-default" class="ab-top-menu">' . $left . '</ul>'
        . '<ul role="menu" id="wp-admin-bar-top-secondary" class="ab-top-secondary ab-top-menu">' . $right . '</ul></div></div>';
}

}
