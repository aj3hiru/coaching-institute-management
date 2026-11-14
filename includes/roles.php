<?php
// Staff roles and what each may reach. One place, used by the admin guard, admin_can(), sh_perm() and ci_perm().
//   admin       everything
//   blog_admin  the whole blog only: posts (all), categories, tags, comments, media, analytics, ads, code snippets,
//               blog parts of Customize (post template, also read, reading progress, sidebar, blog & archives),
//               and creating blog authors / editors. No institute, no online store, no site settings.
//   editor      blog only: every post, categories, tags, comments, media, analytics
//   author      blog only: own posts and own media
//   teacher     online store teaching tools (courses & lectures, doubts) — never the blog
//   accountant  money: fees & dues, orders & sales reports, customers — never the blog
// The permission flags stored per user still decide the details, but a role can never reach outside its part.

const EDU_ROLES = ['admin' => 'Admin', 'blog_admin' => 'Blog Admin', 'editor' => 'Editor', 'author' => 'Author', 'teacher' => 'Teacher', 'accountant' => 'Accountant'];
const EDU_STAFF_ROLES = ['admin', 'blog_admin', 'editor', 'author', 'teacher', 'accountant'];

/** 'all' (admin) · 'blog' (blog admin, editor, author) · 'office' (teacher, accountant) · '' (anything else). */
function edu_role_scope(string $role): string
{
    if ($role === 'admin') return 'all';
    if (in_array($role, ['blog_admin', 'editor', 'author'], true)) return 'blog';
    if (in_array($role, ['teacher', 'accountant'], true)) return 'office';
    return '';
}

/** Permission groups a role may ever hold (the rest are always off). */
function edu_role_allows(string $role, string $group): bool
{
    $scope = edu_role_scope($role);
    if ($scope === 'all') return true;
    if ($group === 'dashboard_access') return $scope !== '';
    if ($scope === 'blog') return in_array($group, ['blogs', 'media', 'analytics', 'ads', 'files', 'authors', 'users'], true);
    if ($scope === 'office') return in_array($group, ['institute', 'store', 'student_scope', 'users'], true); // users: User Manager (staff only), when an admin gives it
    return false;
}

/** Cleans a permission set for a role: groups outside the role are switched off. */
function edu_role_clamp(string $role, array $perms): array
{
    foreach ($perms as $g => $v) {
        if (edu_role_allows($role, (string)$g)) continue;
        $perms[$g] = is_array($v) ? array_fill_keys(array_keys($v), false) : false;
    }
    // users: only a blog admin (or an admin) manages users, and a blog admin only blog people (checked in the Users page)
    if ($role !== 'admin' && $role !== 'blog_admin' && isset($perms['users']) && is_array($perms['users'])) {
        foreach (['change_roles', 'manage_permissions'] as $k) $perms['users'][$k] = false;
    }
    return $perms;
}

/** Roles a user of this role may give to others in Users (empty = may not manage users). A teacher / accountant whom an
 *  admin gave "User Manager" manages teachers and accountants only. */
function edu_roles_assignable(string $role, $perms = null): array
{
    if ($role === 'admin') return array_keys(EDU_ROLES);
    if ($role === 'blog_admin') return ['author', 'editor'];
    if ($role === 'editor') return ['author'];
    if ($role === 'teacher' || $role === 'accountant') {
        $p = is_array($perms) ? $perms : (json_decode((string)$perms, true) ?: []);
        return !empty($p['users']['create']) || !empty($p['users']['edit']) ? ['teacher', 'accountant'] : [];
    }
    return [];
}
