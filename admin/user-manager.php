<?php
// Users & Authors — WordPress-style: one form creates the login account and the author profile shown on posts
// (photo, bio, qualifications, social links… — the E-E-A-T details search engines look for).
require_once __DIR__ . '/components/guard.php';
$me = admin_guard(['admin', 'editor', 'teacher', 'accountant']);
$isAdmin = $me['role'] === 'admin';
if (!$isAdmin && !admin_can('users.create') && !admin_can('users.edit')) { http_response_code(403); exit('Access Denied'); }
$myPerms = json_decode((string)($me['permissions'] ?? ''), true) ?: [];
$canGive = edu_roles_assignable($me['role'], $myPerms); // admin: every role · blog admin: author, editor · editor: author · User Manager (teacher / accountant): teachers, accountants
// a teacher / accountant with "User Manager": manages staff, and can give only the permissions they hold themselves
$officeMgr = in_array($me['role'], ['teacher', 'accountant'], true) && (bool)$canGive;
if (!$canGive) { http_response_code(403); exit('Access Denied'); }

// ─── Permissions: the same groups as before, with defaults per role ───────────────────────────────────────────
const UM_PERMS = [
    'dashboard_access' => 'Dashboard Access',
    'blogs' => ['create' => 'Create', 'edit_own' => 'Edit Own', 'edit_all' => 'Edit All', 'delete_own' => 'Delete Own', 'delete_all' => 'Delete All', 'publish' => 'Publish', 'unpublish' => 'Unpublish', 'schedule' => 'Schedule', 'feature' => 'Feature', 'manage_categories' => 'Categories', 'manage_tags' => 'Tags', 'manage_comments' => 'Comments', 'view_drafts' => 'View Drafts', 'manage_seo' => 'Manage SEO'],
    'media' => ['upload' => 'Upload', 'delete' => 'Delete', 'manage_all' => 'Manage All'],
    'push_notifications' => ['send' => 'Send', 'schedule' => 'Schedule', 'manage_templates' => 'Templates', 'advanced' => 'Advanced'],
    'users' => ['create' => 'Create', 'edit' => 'Edit', 'delete' => 'Delete', 'suspend' => 'Suspend', 'change_roles' => 'Change Roles', 'manage_permissions' => 'Manage Permissions'],
    'authors' => ['create' => 'Create', 'edit' => 'Edit', 'delete' => 'Delete', 'approve' => 'Approve', 'feature' => 'Feature'],
    'analytics' => ['view_basic' => 'Basic Analytics', 'view_advanced' => 'Advanced Analytics'],
    'ads' => ['manage_ads' => 'Manage Ads', 'view_revenue' => 'View Revenue'],
    'settings' => ['general' => 'General', 'seo' => 'SEO', 'smtp' => 'SMTP', 'api_keys' => 'API Keys', 'maintenance_mode' => 'Cache & Maintenance'],
    'pages' => ['create' => 'Create', 'edit' => 'Edit', 'delete' => 'Delete'],
    'files' => ['access_file_manager' => 'File Manager'],
    'security' => ['view_logs' => 'View Logs', 'manage_blacklist' => 'Blacklist', 'manage_recaptcha' => 'reCAPTCHA'],
    'institute' => ['add_student' => 'Add student', 'edit_student' => 'Edit student', 'batches' => 'Manage batches', 'courses' => 'Manage courses', 'fees' => 'Manage fee & dues', 'sessions' => 'Manage sessions', 'classes' => 'Manage classes', 'attendance' => 'Attendance (mark, edit, holidays)', 'att_scan' => 'Scan screen only (ID card scanning)'],
    'store' => ['products' => 'Courses & notes (add, edit, upload)', 'orders' => 'Orders & sales reports', 'customers' => 'Customers', 'settings' => 'Store settings (Razorpay keys)', 'teacher' => 'Teacher'],
];
const UM_ICONS = ['dashboard_access' => 'fa-tachometer-alt', 'blogs' => 'fa-blog', 'media' => 'fa-photo-video', 'push_notifications' => 'fa-bell', 'users' => 'fa-users', 'authors' => 'fa-feather-alt', 'analytics' => 'fa-chart-bar', 'ads' => 'fa-ad', 'settings' => 'fa-cog', 'pages' => 'fa-file-alt', 'files' => 'fa-folder', 'security' => 'fa-shield-alt'];

function um_role_perms(string $role): array
{
    $p = [];
    foreach (UM_PERMS as $k => $v) $p[$k] = is_array($v) ? array_fill_keys(array_keys($v), $role === 'admin') : $role === 'admin';
    if ($role === 'admin') return $p;
    $p['dashboard_access'] = true;
    if ($role === 'blog_admin' || $role === 'editor') { // the whole blog; a blog admin also ads, authors and blog users
        foreach ($p['blogs'] as $k => $_) $p['blogs'][$k] = true;
        $p['media'] = ['upload' => true, 'delete' => true, 'manage_all' => true];
        $p['analytics'] = ['view_basic' => true, 'view_advanced' => true];
        $p['files']['access_file_manager'] = true;
        if ($role === 'blog_admin') {
            $p['ads'] = ['manage_ads' => true, 'view_revenue' => true];
            foreach ($p['authors'] as $k => $_) $p['authors'][$k] = true;
            foreach (['create', 'edit', 'delete', 'suspend'] as $k) $p['users'][$k] = true;
        }
    } elseif ($role === 'teacher') {
        $p['store']['teacher'] = true;
    } elseif ($role === 'accountant') {
        $p['institute']['fees'] = true;
        $p['store']['orders'] = $p['store']['customers'] = true;
    } else { // author: own posts
        foreach (['create', 'edit_own', 'delete_own', 'view_drafts'] as $k) $p['blogs'][$k] = true;
        $p['media']['upload'] = true;
        $p['analytics']['view_basic'] = true;
    }
    return edu_role_clamp($role, $p);
}

/**
 * Permissions from the form's simple "Advance Access" panel, turned into the stored permission keys:
 *   Blog: none / own (add + edit own posts, own media, own analytics) / all (every post, media, page, SEO, all analytics); + Ads
 *   Push notifications: send; advanced (keys, import / export)
 *   Institute: add student, edit student, batches, courses, fee & dues, sessions, classes
 *   Online store: courses & notes, orders & sales reports, customers, store settings
 * Everything else (users, settings, security …) stays with admins. The dashboard is always available.
 */
function um_perms_from_post(): array
{
    $out = [];
    foreach (UM_PERMS as $k => $v) $out[$k] = is_array($v) ? array_fill_keys(array_keys($v), false) : false;
    $out['dashboard_access'] = true;
    $blog = in_array($_POST['p_blog'] ?? '', ['own', 'all'], true) ? $_POST['p_blog'] : 'none';
    if ($blog !== 'none') {
        foreach (['create', 'edit_own', 'delete_own', 'view_drafts', 'publish', 'unpublish', 'schedule'] as $k) $out['blogs'][$k] = true;
        $out['media']['upload'] = $out['media']['delete'] = true;
        $out['files']['access_file_manager'] = true;
        $out['analytics']['view_basic'] = true;
    }
    if ($blog === 'all') {
        foreach ($out['blogs'] as $k => $_) $out['blogs'][$k] = true;
        $out['media']['manage_all'] = true;
        $out['analytics']['view_advanced'] = true;
        $out['pages'] = ['create' => true, 'edit' => true, 'delete' => true];
    }
    if (!empty($_POST['p_ads'])) $out['ads'] = ['manage_ads' => true, 'view_revenue' => true];
    if (!empty($_POST['p_push']) || !empty($_POST['p_push_adv'])) { $out['push_notifications']['send'] = $out['push_notifications']['schedule'] = true; }
    if (!empty($_POST['p_push_adv'])) { $out['push_notifications']['manage_templates'] = $out['push_notifications']['advanced'] = true; }
    foreach (array_keys(UM_PERMS['institute']) as $k) $out['institute'][$k] = !empty($_POST['p_inst'][$k]);
    foreach (array_keys(UM_PERMS['store']) as $k) $out['store'][$k] = !empty($_POST['p_store'][$k]);
    // which students: both / only institute / only online
    $out['student_scope'] = in_array($_POST['p_scope'] ?? '', ['institute', 'online'], true) ? $_POST['p_scope'] : '';
    // User Manager: add / edit / suspend staff (teachers, accountants); delete is separate
    $um = ($_POST['p_users'] ?? '') === 'manage';
    $out['users'] = ['create' => $um, 'edit' => $um, 'suspend' => $um, 'delete' => $um && !empty($_POST['p_users_del']), 'change_roles' => false, 'manage_permissions' => false];
    return $out;
}

/**
 * What a User Manager (teacher / accountant) may save for another staff member: a permission they do not hold
 * themselves keeps its old value (they can neither give nor take it), the student scope is theirs when they have one,
 * and User Manager itself is never given by them.
 */
function um_cap(array $want, array $mine, array $old): array
{
    foreach (['institute', 'store'] as $g) foreach ((array)($want[$g] ?? []) as $k => $v)
        if (empty($mine[$g][$k])) $want[$g][$k] = !empty($old[$g][$k]);
    $ms = (string)($mine['student_scope'] ?? '');
    if ($ms !== '') $want['student_scope'] = $ms;
    $want['users'] = isset($old['users']) && is_array($old['users']) ? $old['users'] : array_fill_keys(['create', 'edit', 'suspend', 'delete', 'change_roles', 'manage_permissions'], false);
    foreach (['blogs', 'media', 'push_notifications', 'authors', 'analytics', 'ads', 'settings', 'pages', 'files', 'security'] as $g) $want[$g] = $old[$g] ?? ($want[$g] ?? []);
    return $want;
}

function um_slug(string $s): string
{
    return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-');
}

function um_unique_slug(PDO $pdo, string $slug, int $exceptId): string
{
    $base = $slug !== '' ? $slug : 'author';
    for ($i = 1; ; $i++) {
        $try = $i === 1 ? $base : $base . '-' . $i;
        $st = $pdo->prepare("SELECT COUNT(*) FROM authors WHERE slug = ? AND id <> ?");
        $st->execute([$try, $exceptId]);
        if (!$st->fetchColumn()) return $try;
    }
}

$roleOptions = $isAdmin
    ? ['author' => 'Author', 'editor' => 'Editor', 'blog_admin' => 'Blog Admin', 'teacher' => 'Teacher', 'accountant' => 'Accountant', 'admin' => 'Admin']
    : array_intersect_key(EDU_ROLES, array_flip($canGive));

// ─── Save / delete ────────────────────────────────────────────────────────────────────────────────────────────
// the superadmin account is not shown to — and cannot be changed by — anyone else
$umSuper = function_exists('edu_is_super') && edu_super_column($pdo) && edu_is_super();
$umHide = $umSuper || !function_exists('edu_super_column') || !edu_super_column($pdo) ? '' : 'COALESCE(u.is_super, 0) = 0';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';
    $__tid = (int)($_POST['id'] ?? 0);
    if ($__tid && !$umSuper && function_exists('edu_is_super') && edu_is_super($__tid)) { header('Location: /admin/user-manager.php'); exit; }
    $back = '/admin/user-manager.php' . (($_POST['view'] ?? '') === 'authors' ? '?view=authors' : '');
    try {
        if ($act === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $username = trim((string)($_POST['username'] ?? ''));
            $email = filter_var(trim((string)($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
            $pass = (string)($_POST['password'] ?? '');
            $role = (string)($_POST['role'] ?? '');
            if (!array_key_exists($role, $roleOptions)) { // a role you may not give is refused, never swapped for another
                if ($isAdmin || ($_POST['id'] ?? '') === (string)$me['id']) $role = $isAdmin ? 'author' : $me['role'];
                else throw new RuntimeException('You may not give the role "' . (EDU_ROLES[$role] ?? $role) . '".');
            }
            $status = in_array($_POST['status'] ?? '', ['active', 'pending', 'suspended'], true) ? $_POST['status'] : 'active';
            $old = null;
            if ($id) {
                $st = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                $st->execute([$id]);
                $old = $st->fetch(PDO::FETCH_ASSOC);
                if (!$old) throw new RuntimeException('This user no longer exists.');
                if (!$isAdmin && !in_array($old['role'], $canGive, true) && $id !== (int)$me['id']) throw new RuntimeException('You can only change ' . implode(' and ', array_map(fn($r) => strtolower(EDU_ROLES[$r]) . 's', $canGive)) . '.');
            }
            if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) throw new RuntimeException('Username: 3–50 letters, numbers, dot, dash or underscore.');
            if (!$email) throw new RuntimeException('Please enter a valid email address.');
            if (!$id && strlen($pass) < 8) throw new RuntimeException('The password must have at least 8 characters.');
            if ($id && $pass !== '' && strlen($pass) < 8) throw new RuntimeException('The new password must have at least 8 characters.');
            $self = $id === (int)$me['id'];
            if (!$isAdmin && $old && !$self && $status !== $old['status'] && !admin_can('users.suspend') && $me['role'] !== 'blog_admin' && $me['role'] !== 'editor') $status = $old['status'];
            if (!$isAdmin && !$id && !admin_can('users.create') && !in_array($me['role'], ['blog_admin', 'editor'], true)) throw new RuntimeException('You may edit staff but not add new ones.');
            if ($self) { $role = $me['role']; $status = 'active'; } // nobody can lock themselves out
            $dupe = $pdo->prepare("SELECT username, email FROM users WHERE (username = ? OR email = ?) AND id <> ?");
            $dupe->execute([$username, $email, $id]);
            if ($d = $dupe->fetch(PDO::FETCH_ASSOC)) throw new RuntimeException(strcasecmp($d['username'], $username) === 0 ? 'This username is already taken.' : 'Another user already uses this email.');
            // Admins set permissions freely; an editor's users always get the author defaults; your own stay as they are.
            // a role never holds permissions outside its part (blog / teacher & accountant); a Blog Admin always has the whole blog
            if ($self && $old) $perms = $old['permissions'];
            elseif ($isAdmin && !in_array($role, ['admin', 'blog_admin'], true)) $perms = json_encode(edu_role_clamp($role, um_perms_from_post()));
            elseif ($officeMgr) { // a User Manager: within their own permissions and their own students
                $oldP = $old ? (json_decode((string)$old['permissions'], true) ?: []) : [];
                $ms = (string)($myPerms['student_scope'] ?? '');
                if ($ms !== '' && $old && (string)($oldP['student_scope'] ?? '') !== $ms) throw new RuntimeException('This user works with other students than you — ask an admin.');
                $perms = json_encode(edu_role_clamp($role, um_cap(um_perms_from_post(), $myPerms, $oldP ?: um_role_perms($role))));
            }
            else $perms = json_encode(um_role_perms($role));

            // Author profile (every user gets one, like WordPress — it is what shows on their posts)
            $t = fn($k, $max = 255) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
            $url = fn($k) => ($v = trim((string)($_POST[$k] ?? ''))) !== '' && filter_var($v, FILTER_VALIDATE_URL) ? $v : null;
            $display = $t('name', 100) ?: $username;

            $pdo->beginTransaction();
            if ($id) {
                $sql = "UPDATE users SET username = ?, email = ?, role = ?, status = ?, permissions = ?" . ($pass !== '' ? ", password_hash = ?" : '') . " WHERE id = ?";
                $args = [$username, $email, $role, $status, $perms];
                if ($pass !== '') $args[] = password_hash($pass, PASSWORD_BCRYPT);
                $args[] = $id;
                $pdo->prepare($sql)->execute($args);
                $uid = $id;
            } else {
                $pdo->prepare("INSERT INTO users (username, email, password_hash, role, status, permissions) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$username, $email, password_hash($pass, PASSWORD_BCRYPT), $role, $status, $perms]);
                $uid = (int)$pdo->lastInsertId();
            }
            $st = $pdo->prepare("SELECT id FROM authors WHERE user_id = ? ORDER BY id LIMIT 1");
            $st->execute([$uid]);
            $aid = (int)$st->fetchColumn();
            $slug = um_unique_slug($pdo, um_slug($t('slug', 150) ?: $display), $aid);
            $aEmail = $t('author_email', 150) ?: $email;
            $chk = $pdo->prepare("SELECT COUNT(*) FROM authors WHERE email = ? AND id <> ?");
            $chk->execute([$aEmail, $aid]);
            if ($chk->fetchColumn()) $aEmail = null; // that public email is already on another profile — leave it empty
            $vals = [
                'full_name' => $t('full_name', 150) ?: $display, 'name' => $display, 'slug' => $slug, 'bio' => $t('bio', 5000),
                'profile_image' => ltrim($t('profile_image'), '/') ?: null, 'email' => $aEmail, 'mobile_number' => $t('mobile_number', 20),
                'address' => $t('address', 1000), 'qualifications' => $t('qualifications', 1000), 'designation' => $t('designation', 150),
                'experience' => $t('experience', 100), 'certifications' => $t('certifications', 1000), 'languages_known' => $t('languages_known'),
                'instagram' => $url('instagram'), 'threads' => $url('threads'), 'linkedin' => $url('linkedin'), 'facebook' => $url('facebook'), 'twitter' => $url('twitter'),
                'is_featured' => !empty($_POST['is_featured']) ? 1 : 0, 'status' => $status,
            ];
            if ($aid) {
                $pdo->prepare("UPDATE authors SET " . implode(', ', array_map(fn($k) => "$k = ?", array_keys($vals))) . " WHERE id = ?")->execute([...array_values($vals), $aid]);
            } else {
                $vals['user_id'] = $uid;
                $pdo->prepare("INSERT INTO authors (" . implode(', ', array_keys($vals)) . ") VALUES (" . rtrim(str_repeat('?, ', count($vals)), ', ') . ")")->execute(array_values($vals));
            }
            $pdo->commit();
            // teacher profile (Online Store → Teacher): subject and the "Meet our teachers" card on the homepage
            $tp = json_decode((string)$perms, true) ?: [];
            if (!empty($tp['store']['teacher']) && is_file(INCLUDES_PATH . '/shop.php')) {
                require_once INCLUDES_PATH . '/shop.php'; sh_tables($pdo);
                $pdo->prepare("INSERT INTO sh_teacher_info (user_id, subject, about, show_home, sort) VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE subject = VALUES(subject), about = VALUES(about), show_home = VALUES(show_home), sort = VALUES(sort)")
                    ->execute([$uid, $t('t_subject', 255) ?: null, $t('t_about', 500) ?: null, !empty($_POST['t_show']) ? 1 : 0, (int)($_POST['t_sort'] ?? 0)]);
                if (function_exists('sh_home_refresh')) sh_home_refresh();
            }
            if ($self) $_SESSION['username'] = $username;
            edu_clear_page_cache();
            admin_log($id ? 'user_edit' : 'user_create', ($id ? 'Edited' : 'Created') . " user: $username ($role, $status)");
            admin_flash($id ? "User \"$username\" saved." : "User \"$username\" created" . ($status === 'active' ? ' and can sign in now.' : " ($status)."));
        } elseif ($act === 'role' && $isAdmin) {
            $id = (int)($_POST['id'] ?? 0);
            $role = array_key_exists($_POST['role'] ?? '', EDU_ROLES) ? $_POST['role'] : null;
            if (!$role || $id === (int)$me['id']) throw new RuntimeException('You cannot change your own role.');
            $pdo->prepare("UPDATE users SET role = ?, permissions = ? WHERE id = ?")->execute([$role, json_encode(um_role_perms($role)), $id]);
            admin_log('user_role', "Changed user #$id role to $role");
            admin_flash('Role changed. Permissions were reset to the ' . $role . ' defaults.');
        } elseif ($act === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $to = (int)($_POST['reassign'] ?? 0);
            if ($id === (int)$me['id']) throw new RuntimeException('You cannot delete your own account.');
            $st = $pdo->prepare("SELECT u.username, u.role, (SELECT GROUP_CONCAT(id) FROM authors WHERE user_id = u.id) aids FROM users u WHERE u.id = ?");
            $st->execute([$id]);
            $u = $st->fetch(PDO::FETCH_ASSOC);
            if (!$u) throw new RuntimeException('This user no longer exists.');
            if (!$isAdmin && (!in_array($u['role'], $canGive, true) || !admin_can('users.delete'))) throw new RuntimeException('You may not delete this user.');
            $aids = array_filter(array_map('intval', explode(',', (string)$u['aids'])));
            $pdo->beginTransaction();
            $moved = 0;
            if ($aids) {
                $in = implode(',', $aids);
                $n = (int)$pdo->query("SELECT COUNT(*) FROM posts WHERE author_id IN ($in)")->fetchColumn();
                if ($n) {
                    $target = $pdo->prepare("SELECT id FROM authors WHERE user_id = ? ORDER BY id LIMIT 1");
                    $target->execute([$to]);
                    $tid = (int)$target->fetchColumn();
                    if (!$tid || $to === $id) throw new RuntimeException('This user has ' . $n . ' post(s). Choose who gets them.');
                    $moved = $pdo->exec("UPDATE posts SET author_id = $tid WHERE author_id IN ($in)");
                }
            }
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]); // the author profile goes with it
            $pdo->commit();
            edu_clear_page_cache();
            admin_log('user_delete', "Deleted user: {$u['username']}" . ($moved ? " ($moved posts moved to user #$to)" : ''));
            admin_flash("User \"{$u['username']}\" deleted" . ($moved ? " — $moved post(s) moved." : '.'));
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        admin_flash($e instanceof RuntimeException ? $e->getMessage() : 'Could not save: ' . $e->getMessage(), 'error');
    }
    header('Location: ' . $back);
    exit;
}

// ─── Data ──────────────────────────────────────────────────────────────────────────────────────────────────────
$view = ($_GET['view'] ?? '') === 'authors' ? 'authors' : 'users';
$q = trim((string)($_GET['search'] ?? ''));
$fRole = array_key_exists($_GET['role'] ?? '', EDU_ROLES) ? $_GET['role'] : '';
$fStatus = in_array($_GET['status'] ?? '', ['active', 'pending', 'suspended'], true) ? $_GET['status'] : '';
$where = [];
$args = [];
if (!$isAdmin) $where[] = "(u.role IN ('" . implode("','", $canGive) . "') OR u.id = " . (int)$me['id'] . ")"; // blog admin: authors + editors · editor: authors
if ($q !== '') { $where[] = "(u.username LIKE ? OR u.email LIKE ? OR a.name LIKE ? OR a.full_name LIKE ?)"; array_push($args, "%$q%", "%$q%", "%$q%", "%$q%"); }
if ($fRole) { $where[] = "u.role = ?"; $args[] = $fRole; }
if ($fStatus) { $where[] = "u.status = ?"; $args[] = $fStatus; }
$st = $pdo->prepare("SELECT u.id, u.username, u.email, u.role, u.status, u.permissions, u.created_at, a.id author_id, a.name, a.full_name, a.slug, a.bio, a.profile_image,
        a.email author_email, a.mobile_number, a.address, a.qualifications, a.designation, a.experience, a.certifications, a.languages_known,
        a.instagram, a.threads, a.linkedin, a.facebook, a.twitter, a.is_featured, a.status author_status,
        (SELECT COUNT(*) FROM posts p WHERE p.author_id = a.id) posts
    FROM users u LEFT JOIN authors a ON a.id = (SELECT MIN(id) FROM authors WHERE user_id = u.id)
    " . (($where = array_merge($where, $umHide !== '' ? [$umHide] : [])) ? 'WHERE ' . implode(' AND ', $where) : '') . " ORDER BY FIELD(u.role, 'admin', 'blog_admin', 'editor', 'author', 'teacher', 'accountant'), u.username");
$st->execute($args);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
$all = $pdo->query("SELECT u.id, u.username, u.role, u.status, (SELECT COUNT(*) FROM authors WHERE user_id = u.id) has_author FROM users u" . ($umHide !== '' ? " WHERE $umHide" : '') . " ORDER BY u.username")->fetchAll(PDO::FETCH_ASSOC);
$cnt = ['users' => count($all), 'admins' => 0, 'active' => 0, 'pending' => 0, 'authors' => 0];
foreach ($all as $u) { $cnt['admins'] += $u['role'] === 'admin'; $cnt['active'] += $u['status'] === 'active'; $cnt['pending'] += $u['status'] === 'pending'; $cnt['authors'] += $u['has_author'] > 0; }
$img = fn($p) => $p ? '/' . ltrim($p, '/') : '';
$esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$jsRows = [];
$teach = [];
try { foreach ($pdo->query("SELECT * FROM sh_teacher_info") as $tr) $teach[(int)$tr['user_id']] = $tr; } catch (Throwable) {}
foreach ($rows as $r) {
    $r['perms'] = json_decode((string)$r['permissions'], true) ?: um_role_perms($r['role']);
    unset($r['permissions']);
    $r['self'] = (int)$r['id'] === (int)$me['id'];
    $ti = $teach[(int)$r['id']] ?? [];
    $r['t_subject'] = (string)($ti['subject'] ?? ''); $r['t_about'] = (string)($ti['about'] ?? ''); $r['t_show'] = $ti ? (int)$ti['show_home'] : 1; $r['t_sort'] = (int)($ti['sort'] ?? 0);
    $jsRows[$r['id']] = $r;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Users &amp; Authors - <?= $esc(edu_site_name()) ?></title>
<?php admin_shell_head(); ?>
<script src="/assets/js/admin-media.js?v=<?= @filemtime(ROOT_PATH . '/assets/js/admin-media.js') ?>" defer></script>
<style>
.um-img{width:38px;height:38px;border-radius:50%;object-fit:cover;flex-shrink:0}
.u-identity{display:flex;align-items:center;gap:.75rem;min-width:0}
.u-avatar{width:38px;height:38px;border-radius:50%;background:var(--primary-light,#ede9fe);color:var(--primary);display:grid;place-items:center;font-weight:700;flex-shrink:0}
.u-name{font-weight:600;color:var(--gray-900);font-size:.9375rem}.u-email{font-size:.8125rem;color:var(--gray-500);overflow-wrap:anywhere}
.um-role{padding:.35rem .5rem;border:1px solid var(--gray-200);border-radius:8px;font-size:.8125rem;background:#fff;font-family:inherit}
.um-you{display:inline-block;margin-left:6px;padding:1px 7px;border-radius:99px;background:var(--gray-100);color:var(--gray-600);font-size:.68rem;font-weight:700;vertical-align:middle}
.um-feat{color:#f59e0b;margin-left:4px}
.um-photo{display:flex;align-items:center;gap:14px}
.um-photo-btns{display:flex;gap:8px;flex-wrap:wrap}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:0 1rem}
@media (max-width:640px){.form-row{grid-template-columns:1fr}.hide-mobile{display:none}}
.pwd-field{position:relative;display:block}.pwd-field .form-control{padding-right:2.5rem}.pwd-field-toggle{position:absolute;right:.5rem;top:50%;transform:translateY(-50%);border:0;background:0 0;color:var(--gray-500);cursor:pointer;padding:.35rem}
.um-hint{font-size:.78rem;color:var(--gray-500);margin-top:4px;overflow-wrap:anywhere}
.view-tab-count{color:var(--gray-400);font-weight:400;font-size:.8125rem}
.perm-item.perm-off{opacity:.45;cursor:not-allowed}
#umPermBox .perm-item{flex-wrap:wrap}
#umPermBox .perm-item small{flex-basis:100%;padding-left:24px;margin-top:2px;color:var(--gray-500);font-size:.74rem;line-height:1.35;font-weight:400}
.um-del-note{background:var(--warning-light,#fef3c7);color:#92400e;border-radius:10px;padding:.75rem 1rem;font-size:.875rem;margin-bottom:1rem}
</style>
</head>
<body>
<?php admin_shell_open('Users &amp; Authors', 'Sign-in accounts and the author profiles shown on posts', '<button type="button" class="btn btn-primary" onclick="UM.open(0)"><i class="fas fa-user-plus"></i> <span class="hide-mobile">Add User</span></button>'); ?>
<?= admin_flash() ?>
<div class="summary-line">
  <span><b><?= $cnt['users'] ?></b> Users</span><?php if ($isAdmin): ?><span class="summary-sep">·</span><span><b><?= $cnt['admins'] ?></b> Admins</span><?php endif; ?>
  <span class="summary-sep">·</span><span><b><?= $cnt['authors'] ?></b> Author Profiles</span>
  <span class="summary-sep">·</span><span><b><?= $cnt['active'] ?></b> Active</span>
  <span class="summary-sep">·</span><span><b><?= $cnt['pending'] ?></b> Pending</span>
</div>
<div class="view-tabs">
  <a href="/admin/user-manager.php" class="view-tab<?= $view === 'users' ? ' active' : '' ?>">Users <span class="view-tab-count">(<?= $cnt['users'] ?>)</span></a>
  <a href="/admin/user-manager.php?view=authors" class="view-tab<?= $view === 'authors' ? ' active' : '' ?>">Authors <span class="view-tab-count">(<?= $cnt['authors'] ?>)</span></a>
</div>
<div class="filter-section"><form method="GET"><?php if ($view === 'authors'): ?><input type="hidden" name="view" value="authors"><?php endif; ?>
  <div class="filter-row">
    <div class="filter-group"><label>Search</label><input type="text" name="search" class="filter-control" placeholder="Name, username or email…" value="<?= $esc($q) ?>"></div>
    <?php if ($isAdmin): ?><div class="filter-group"><label>Role</label><select name="role" class="filter-control"><option value="">All Roles</option><?php foreach (EDU_ROLES as $k => $l): ?><option value="<?= $k ?>"<?= $fRole === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div><?php endif; ?>
    <div class="filter-group"><label>Status</label><select name="status" class="filter-control"><option value="">All Status</option><?php foreach (['active' => 'Active', 'pending' => 'Pending', 'suspended' => 'Suspended'] as $k => $l): ?><option value="<?= $k ?>"<?= $fStatus === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <div class="filter-group" style="justify-content:flex-end"><label>&nbsp;</label><div style="display:flex;gap:.5rem"><button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Filter</button><a href="/admin/user-manager.php<?= $view === 'authors' ? '?view=authors' : '' ?>" class="btn btn-secondary" title="Clear filters"><i class="fas fa-times"></i></a></div></div>
  </div>
</form></div>

<div class="table-wrap"><div class="table-responsive"><table>
<?php if ($view === 'users'): ?>
  <thead><tr><th>User</th><th>Role</th><th>Status</th><th class="hide-mobile">Posts</th><th class="hide-mobile">Joined</th><th>Actions</th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="6"><div class="empty-state"><i class="fas fa-users"></i><p>No users found</p></div></td></tr><?php endif; ?>
  <?php foreach ($rows as $r): $self = (int)$r['id'] === (int)$me['id']; ?>
  <tr>
    <td><div class="u-identity"><?php if ($r['profile_image']): ?><img class="um-img" src="<?= $esc($img($r['profile_image'])) ?>" alt=""><?php else: ?><div class="u-avatar"><?= $esc(mb_strtoupper(mb_substr($r['name'] ?: $r['username'], 0, 1))) ?></div><?php endif; ?>
      <div style="min-width:0"><div class="u-name"><?= $esc($r['name'] ?: $r['username']) ?><?= $self ? '<span class="um-you">You</span>' : '' ?></div><div class="u-email">@<?= $esc($r['username']) ?> · <?= $esc($r['email']) ?></div></div></div></td>
    <td><?php if ($isAdmin && !$self): ?><form method="POST" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="role"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
      <select name="role" class="um-role" aria-label="Role" onchange="var s=this;adminConfirm('Their permissions are reset to the defaults of the new role.',{title:'Change role?',confirmText:'Change role',danger:false}).then(function(ok){if(ok)s.form.submit();else s.value='<?= $r['role'] ?>';})"><?php foreach (EDU_ROLES as $k => $l): ?><option value="<?= $k ?>"<?= $r['role'] === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></form>
      <?php else: ?><span class="badge badge-default"><?= $esc(EDU_ROLES[$r['role']] ?? $r['role']) ?></span><?php endif; ?></td>
    <td><span class="badge <?= $r['status'] === 'active' ? 'badge-active' : ($r['status'] === 'suspended' ? 'badge-inactive' : 'badge-pending') ?>"><?= ucfirst($r['status']) ?></span></td>
    <td class="hide-mobile"><?= (int)$r['posts'] ?></td>
    <td class="hide-mobile" style="color:var(--gray-500);font-size:.8125rem;white-space:nowrap"><?= $r['created_at'] ? date('d M Y', strtotime($r['created_at'])) : '—' ?></td>
    <td><div class="row-actions">
      <?php if ($self && !$isAdmin): ?><a class="btn-action btn-edit" href="/admin/my-profile.php"><i class="fas fa-user-pen"></i> <span>My Profile</span></a>
      <?php else: ?><button type="button" class="btn-action btn-edit" onclick="UM.open(<?= (int)$r['id'] ?>)"><i class="fas fa-edit"></i> <span>Edit</span></button><?php endif; ?>
      <?php if (!$self && ($isAdmin || admin_can('users.delete'))): ?><button type="button" class="btn-action btn-delete" title="Delete" onclick="UM.del(<?= (int)$r['id'] ?>)"><i class="fas fa-trash"></i></button><?php endif; ?>
    </div></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
<?php else: ?>
  <thead><tr><th>Author</th><th class="hide-mobile">Profile page</th><th>Posts</th><th class="hide-mobile">Status</th><th>Actions</th></tr></thead>
  <tbody>
  <?php $shown = 0; foreach ($rows as $r): if (!$r['author_id']) continue; $shown++; ?>
  <tr>
    <td><div class="u-identity"><?php if ($r['profile_image']): ?><img class="um-img" src="<?= $esc($img($r['profile_image'])) ?>" alt=""><?php else: ?><div class="u-avatar"><?= $esc(mb_strtoupper(mb_substr($r['name'] ?: $r['username'], 0, 1))) ?></div><?php endif; ?>
      <div style="min-width:0"><div class="u-name"><?= $esc($r['name']) ?><?= $r['is_featured'] ? '<i class="fas fa-star um-feat" title="Featured author"></i>' : '' ?></div><div class="u-email"><?= $esc($r['designation'] ?: ucfirst($r['role'])) ?></div></div></div></td>
    <td class="hide-mobile"><a href="/author/<?= $esc($r['slug']) ?>" target="_blank" rel="noopener" style="color:var(--primary);font-size:.85rem">/author/<?= $esc($r['slug']) ?> <i class="fas fa-arrow-up-right-from-square" style="font-size:.7rem"></i></a></td>
    <td><?= (int)$r['posts'] ?></td>
    <td class="hide-mobile"><span class="badge <?= $r['author_status'] === 'active' ? 'badge-active' : 'badge-inactive' ?>"><?= ucfirst((string)$r['author_status']) ?></span></td>
    <td><div class="row-actions"><?php if ((int)$r['id'] === (int)$me['id'] && !$isAdmin): ?><a class="btn-action btn-edit" href="/admin/my-profile.php"><i class="fas fa-user-pen"></i> <span>My Profile</span></a><?php else: ?><button type="button" class="btn-action btn-edit" onclick="UM.open(<?= (int)$r['id'] ?>)"><i class="fas fa-edit"></i> <span>Edit</span></button><?php endif; ?></div></td>
  </tr>
  <?php endforeach; if (!$shown): ?><tr><td colspan="5"><div class="empty-state"><i class="fas fa-feather-alt"></i><p>No author profiles found</p></div></td></tr><?php endif; ?>
  </tbody>
<?php endif; ?>
</table></div></div>

<!-- Add / Edit user (account + author profile in one form) -->
<div class="modal-overlay" id="umModal">
  <div class="modal modal-lg">
    <div class="modal-header"><div class="modal-title"><i class="fas fa-user-plus" style="color:var(--primary);margin-right:.5rem"></i> <span id="umTitle">Add New User</span></div><button type="button" class="modal-close" onclick="UM.close()"><i class="fas fa-times"></i></button></div>
    <form method="POST" id="umForm" autocomplete="off">
      <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="0"><input type="hidden" name="view" value="<?= $view ?>">
      <div class="modal-body">
        <div class="form-section-title"><i class="fas fa-key"></i> Account</div>
        <div class="form-grid form-grid-2">
          <div class="form-group"><label>Username <span class="req">*</span></label><input type="text" name="username" class="form-control" required pattern="[A-Za-z0-9_.\-]{3,50}" title="3–50 letters, numbers, dot, dash or underscore" placeholder="e.g. rahul.sharma"></div>
          <div class="form-group"><label>Email <span class="req">*</span></label><input type="email" name="email" class="form-control" required placeholder="name@example.com"></div>
          <div class="form-group"><label id="umPassLabel">Password <span class="req">*</span></label><span class="pwd-field"><input type="password" name="password" class="form-control" minlength="8" autocomplete="new-password" placeholder="At least 8 characters"><button type="button" class="pwd-field-toggle" onclick="UM.eye(this)" aria-label="Show password"><i class="fas fa-eye"></i></button></span></div>
          <div class="form-group"><label>Role</label><select name="role" class="form-control" onchange="UM.applyRole(this.value)"><?php foreach ($roleOptions as $k => $l): ?><option value="<?= $k ?>"><?= $esc($l) ?></option><?php endforeach; ?></select></div>
          <div class="form-group"><label>Status</label><select name="status" class="form-control"><option value="active">Active</option><option value="pending">Pending</option><option value="suspended">Suspended</option></select><div class="um-hint" id="umSelfHint" hidden>You cannot change your own role or status.</div></div>
        </div>

        <div class="form-section-title" style="margin-top:1rem"><i class="fas fa-id-card"></i> Author Profile <span style="font-size:.72rem;font-weight:400;color:var(--gray-500)">— shown on posts and on the author page</span></div>
        <div class="form-group"><div class="um-photo"><span id="umPhoto"></span><div><div class="um-photo-btns"><button type="button" class="btn btn-secondary btn-sm" onclick="UM.pick()"><i class="fas fa-image"></i> Choose photo</button><button type="button" class="btn btn-secondary btn-sm" id="umPhotoRm" onclick="UM.photo('')"><i class="fas fa-trash"></i> Remove</button></div><div class="um-hint">A square photo works best (at least 200×200).</div></div></div><input type="hidden" name="profile_image"></div>
        <div class="form-row">
          <div class="form-group"><label>Display Name</label><input type="text" name="name" class="form-control" maxlength="100" placeholder="Shown on posts — defaults to the username"></div>
          <div class="form-group"><label>Full Name</label><input type="text" name="full_name" class="form-control" maxlength="150" placeholder="e.g. Rahul Kumar Sharma"></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label>Profile Address</label><input type="text" name="slug" class="form-control" maxlength="150" placeholder="Made from the display name"><div class="um-hint"><?= $esc(rtrim(SITE_URL, '/')) ?>/author/<b id="umSlugPrev">…</b></div></div>
          <div class="form-group"><label>Designation</label><input type="text" name="designation" class="form-control" maxlength="150" placeholder="e.g. Senior Education Writer"></div>
        </div>
        <div class="form-group"><label>Bio</label><textarea name="bio" class="form-control" rows="4" placeholder="A few lines about the author's experience and expertise — shown in the author box under each post"></textarea></div>
        <div class="form-row">
          <div class="form-group"><label>Qualifications</label><input type="text" name="qualifications" class="form-control" placeholder="e.g. M.A. Political Science, B.Ed"></div>
          <div class="form-group"><label>Experience</label><input type="text" name="experience" class="form-control" maxlength="100" placeholder="e.g. 6 years in education journalism"></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label>Languages Known</label><input type="text" name="languages_known" class="form-control" placeholder="Hindi, English"></div>
          <div class="form-group"><label>Certifications</label><input type="text" name="certifications" class="form-control" placeholder="Any notable certifications"></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label>Public Email <span style="font-weight:400;color:var(--gray-500)">(optional)</span></label><input type="email" name="author_email" class="form-control" placeholder="Defaults to the account email"></div>
          <div class="form-group"><label>Mobile <span style="font-weight:400;color:var(--gray-500)">(optional)</span></label><input type="text" name="mobile_number" class="form-control" maxlength="20" placeholder="+91 XXXXX XXXXX"></div>
        </div>
        <div class="form-group"><label>Address <span style="font-weight:400;color:var(--gray-500)">(optional)</span></label><input type="text" name="address" class="form-control" placeholder="City, State"></div>
        <div class="form-section-title" style="margin-top:1rem"><i class="fas fa-share-nodes"></i> Social Profiles <span style="font-size:.72rem;font-weight:400;color:var(--gray-500)">(optional)</span></div>
        <div class="form-grid social-profile-grid">
          <div class="form-group"><label><i class="fab fa-facebook" style="color:#1877f2;margin-right:.35rem"></i> Facebook</label><input type="url" name="facebook" class="form-control" placeholder="https://facebook.com/username"></div>
          <div class="form-group"><label><i class="fab fa-x-twitter" style="margin-right:.35rem"></i> X / Twitter</label><input type="url" name="twitter" class="form-control" placeholder="https://x.com/username"></div>
          <div class="form-group"><label><i class="fab fa-instagram" style="color:#e1306c;margin-right:.35rem"></i> Instagram</label><input type="url" name="instagram" class="form-control" placeholder="https://instagram.com/username"></div>
          <div class="form-group"><label><i class="fab fa-linkedin" style="color:#0a66c2;margin-right:.35rem"></i> LinkedIn</label><input type="url" name="linkedin" class="form-control" placeholder="https://linkedin.com/in/username"></div>
          <div class="form-group"><label><i class="fab fa-threads" style="margin-right:.35rem"></i> Threads</label><input type="url" name="threads" class="form-control" placeholder="https://threads.net/@username"></div>
        </div>
        <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;margin:.25rem 0 1rem"><input type="checkbox" name="is_featured" value="1"> <span><i class="fas fa-star" style="color:#f59e0b;margin-right:4px"></i>Featured author</span></label>

        <?php if ($isAdmin || $officeMgr): ?>
        <div id="umPermBox">
        <div class="form-section-title"><i class="fas fa-shield-alt"></i> Permissions</div>
        <p class="um-hint" id="umScopeNote" style="margin:0 0 .6rem"></p>
        <div class="adv-toggle" role="button" tabindex="0" onclick="this.classList.toggle('open');this.nextElementSibling.classList.toggle('open')"><span><i class="fas fa-shield-alt"></i> Advance Access</span><i class="fas fa-chevron-down chev"></i></div>
        <div class="adv-panel"><div class="perm-panel">
          <div class="perm-group" data-scope="blog"><div class="perm-group-title"><i class="fas fa-blog"></i> Blogs</div><div class="perm-grid">
            <label class="perm-item"><input type="radio" name="p_blog" value="none" data-simple="blog"> No access</label>
            <label class="perm-item"><input type="radio" name="p_blog" value="own" data-simple="blog"> Add / edit own posts <small>(own media &amp; own analytics)</small></label>
            <label class="perm-item"><input type="radio" name="p_blog" value="all" data-simple="blog"> All posts <small>(all media, pages, SEO &amp; site analytics)</small></label>
            <label class="perm-item"><input type="checkbox" name="p_ads" value="1" data-simple="ads"> Ads (Ad Inserter)</label>
          </div></div>
          <div class="perm-group" data-scope="all"><div class="perm-group-title"><i class="fas fa-bell"></i> Push Notifications</div><div class="perm-grid">
            <label class="perm-item"><input type="checkbox" name="p_push" value="1" data-simple="push"> Send push</label>
            <label class="perm-item"><input type="checkbox" name="p_push_adv" value="1" data-simple="push_adv"> Advanced <small>(change keys, import / export)</small></label>
          </div></div>
          <?php $mine = fn(string $g, string $k) => $isAdmin || !empty($myPerms[$g][$k]); $myScope = (string)($myPerms['student_scope'] ?? ''); ?>
          <div class="perm-group" data-scope="office"><div class="perm-group-title"><i class="fas fa-people-group"></i> Students they work with</div><div class="perm-grid">
            <?php foreach (CI_SCOPES as $k => $l): $off = !$isAdmin && $myScope !== '' && $k !== $myScope; ?><label class="perm-item<?= $off ? ' perm-off' : '' ?>"><input type="radio" name="p_scope" value="<?= $k ?>" data-simple="scope"<?= $off ? ' disabled' : '' ?>> <?= $esc($l) ?><?= $k === 'institute' ? ' <small>Institute + "Both" students</small>' : ($k === 'online' ? ' <small>Online + "Both" students</small>' : ' <small>Every student</small>') ?></label><?php endforeach; ?>
          </div></div>
          <div class="perm-group" data-scope="office"><div class="perm-group-title"><i class="fas fa-user-graduate"></i> Institute Management</div><div class="perm-grid">
            <?php foreach (UM_PERMS['institute'] as $k => $l): if ($k === 'attendance' || $k === 'att_scan') continue; $off = !$mine('institute', $k); ?><label class="perm-item<?= $off ? ' perm-off' : '' ?>"<?= $off ? ' title="You do not have this permission yourself"' : '' ?>><input type="checkbox" name="p_inst[<?= $k ?>]" value="1" data-simple="inst.<?= $k ?>"<?= $off ? ' disabled' : '' ?>> <?= $esc($l) ?></label><?php endforeach; ?>
          </div></div>
          <div class="perm-group" data-scope="office"><div class="perm-group-title"><i class="fas fa-clipboard-user"></i> Attendance</div><div class="perm-grid">
            <?php foreach (['attendance' => 'Full attendance <small>Mark P / A / L, check-out all, scan screen, holidays</small>', 'att_scan' => 'Scan screen only <small>For the gate — only scans ID cards, nothing else</small>'] as $k => $l): $off = !$mine('institute', $k); ?><label class="perm-item<?= $off ? ' perm-off' : '' ?>"<?= $off ? ' title="You do not have this permission yourself"' : '' ?>><input type="checkbox" name="p_inst[<?= $k ?>]" value="1" data-simple="inst.<?= $k ?>"<?= $off ? ' disabled' : '' ?>> <?= $l ?></label><?php endforeach; ?>
          </div></div>
          <div class="perm-group" data-scope="office"><div class="perm-group-title"><i class="fas fa-store"></i> Online Store</div><div class="perm-grid" id="umStorePerms">
            <?php foreach (UM_PERMS['store'] as $k => $l): $off = !$mine('store', $k); ?><label class="perm-item<?= $off ? ' perm-off' : '' ?>"<?= $off ? ' title="You do not have this permission yourself"' : '' ?>><input type="checkbox" name="p_store[<?= $k ?>]" value="1" data-simple="store.<?= $k ?>"<?= $off ? ' disabled' : '' ?>> <?= $esc($l) ?></label><?php endforeach; ?>
          </div></div>
          <?php if ($isAdmin): ?><div class="perm-group" data-scope="office"><div class="perm-group-title"><i class="fas fa-users-gear"></i> User Manager</div><div class="perm-grid">
            <label class="perm-item"><input type="radio" name="p_users" value="none" data-simple="users"> No access</label>
            <label class="perm-item"><input type="radio" name="p_users" value="manage" data-simple="users"> Manage staff <small>Add / edit / suspend teachers &amp; accountants — gives only their own permissions</small></label>
            <label class="perm-item"><input type="checkbox" name="p_users_del" value="1" data-simple="users_del"> Can also delete staff <small>Only with "Manage staff"</small></label>
          </div></div><?php endif; ?>
        </div></div>
        <div class="um-teach" id="umTeach" hidden>
          <div class="form-section-title"><i class="fas fa-chalkboard-user"></i> Teacher profile <span style="font-size:.72rem;font-weight:400;color:var(--gray-500)">— the card in "Meet our teachers" on the homepage and "Taught by" on courses</span></div>
          <p class="um-hint" style="margin:0 0 .6rem">The photo, display name, qualifications and experience come from the <b>Author Profile</b> above — use a passport-size photo.</p>
          <div class="form-row">
            <div class="form-group"><label>Subject(s) taught</label><input type="text" name="t_subject" class="form-control" maxlength="255" placeholder="e.g. Mathematics, Physics"></div>
            <div class="form-group"><label>Order on the homepage</label><input type="number" name="t_sort" class="form-control" value="0"></div>
          </div>
          <div class="form-group"><label>One line about the teacher <span style="font-weight:400;color:var(--gray-500)">(optional)</span></label><input type="text" name="t_about" class="form-control" maxlength="500" placeholder="e.g. Makes Maths easy with simple tricks"></div>
          <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;margin:.25rem 0 .5rem"><input type="checkbox" name="t_show" value="1" checked> Show in "Meet our teachers" on the homepage</label>
        </div>
        </div>
        <?php endif; ?>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="UM.close()">Cancel</button><button type="submit" class="btn btn-primary" id="umSave"><i class="fas fa-save"></i> Create User</button></div>
    </form>
  </div>
</div>

<!-- Delete user -->
<div class="modal-overlay" id="umDel">
  <div class="modal" style="max-width:480px">
    <div class="modal-header"><div class="modal-title"><i class="fas fa-trash" style="color:var(--danger);margin-right:.5rem"></i> Delete User</div><button type="button" class="modal-close" onclick="document.getElementById('umDel').classList.remove('open')"><i class="fas fa-times"></i></button></div>
    <form method="POST"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id"><input type="hidden" name="view" value="<?= $view ?>">
      <div class="modal-body">
        <p style="margin:0 0 1rem">Delete <b id="umDelName"></b>? Their sign-in account and author profile are removed.</p>
        <div id="umDelPosts" hidden><div class="um-del-note"><i class="fas fa-triangle-exclamation"></i> This user has <b id="umDelCount"></b> post(s). Posts are never deleted — choose who they move to:</div>
          <div class="form-group"><label>Move posts to</label><select name="reassign" class="form-control"><?php foreach ($all as $u): if (!$u['has_author']) continue; ?><option value="<?= (int)$u['id'] ?>"><?= $esc($u['username']) ?> (<?= $u['role'] ?>)</option><?php endforeach; ?></select></div></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="document.getElementById('umDel').classList.remove('open')">Cancel</button><button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Delete User</button></div>
    </form>
  </div>
</div>

<script>
window.UM = (function () {
  var rows = <?= json_encode($jsRows, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
  var roleDefaults = <?= json_encode(array_combine(array_keys(EDU_ROLES), array_map('um_role_perms', array_keys(EDU_ROLES))), JSON_HEX_TAG) ?>;
  // which permission boxes belong to which role: blog roles → Blogs; teacher / accountant → Institute + Online Store; admin → everything
  var ROLE_SCOPE = <?= json_encode(array_combine(array_keys(EDU_ROLES), array_map('edu_role_scope', array_keys(EDU_ROLES))), JSON_HEX_TAG) ?>;
  var m = document.getElementById('umModal'), f = document.getElementById('umForm'), permBox = document.getElementById('umPermBox');
  document.body.appendChild(m); document.body.appendChild(document.getElementById('umDel'));
  function setPerms(p) {
    p = p || {};
    var g = function (a, b) { return !!(p[a] && p[a][b]); };
    var blog = g('blogs', 'edit_all') ? 'all' : (g('blogs', 'create') || g('blogs', 'edit_own') ? 'own' : 'none');
    f.querySelectorAll('[data-simple]').forEach(function (c) {
      var k = c.dataset.simple, on;
      if (k === 'blog') on = c.value === blog;
      else if (k === 'ads') on = g('ads', 'manage_ads');
      else if (k === 'push') on = g('push_notifications', 'send');
      else if (k === 'push_adv') on = g('push_notifications', 'advanced');
      else if (k.indexOf('inst.') === 0) on = g('institute', k.slice(5));
      else if (k === 'scope') on = c.value === (p.student_scope || '');
      else if (k === 'users') on = c.value === (g('users', 'create') || g('users', 'edit') ? 'manage' : 'none');
      else if (k === 'users_del') on = g('users', 'delete');
      else if (k.indexOf('store.') === 0) on = g('store', k.slice(6)) || (k !== 'store.settings' && g('institute', 'shop')); // the older single store permission
      c.checked = !!on;
    });
    f.querySelectorAll('[data-simple]').forEach(function (c) { c.closest('.perm-item').classList.toggle('checked', c.checked); });
  }
  f.addEventListener('change', function (e) { if (e.target.matches('[data-simple]')) { f.querySelectorAll('[data-simple]').forEach(function (c) { c.closest('.perm-item').classList.toggle('checked', c.checked); }); teachBox(); } });
  function scopeBox(role) { // show only the permission groups the role can hold
    var sc = ROLE_SCOPE[role] || 'blog', box = document.getElementById('umPermBox');
    if (!box) return;
    box.querySelectorAll('[data-scope]').forEach(function (g) { g.hidden = !(sc === 'all' || g.dataset.scope === sc); });
    var note = document.getElementById('umScopeNote');
    if (note) note.textContent = sc === 'all' ? 'Admins can do everything.' : role === 'blog_admin' ? 'Blog Admin: the whole blog — all posts, categories, tags, comments, media, analytics, ads, code snippets, blog Customize, and blog authors / editors. Nothing outside the blog.' : sc === 'blog' ? 'Blog only — nothing outside the blog (institute, store, settings).' : 'Institute and online store only — no blog.';
    var adv = box.querySelector('.adv-panel'), tog = box.querySelector('.adv-toggle');
    if (role === 'admin' || role === 'blog_admin') { adv.hidden = true; tog.hidden = true; } else { adv.hidden = false; tog.hidden = false; }
  }
  function teachBox() { var b = document.getElementById('umTeach'), c = f.querySelector('[name="p_store[teacher]"]'); if (b) b.hidden = !(c && c.checked); }
  function slugify(s) { return (s || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, ''); }
  function slugPrev() { document.getElementById('umSlugPrev').textContent = slugify(f.slug.value) || slugify(f.name.value) || slugify(f.username.value) || '…'; }
  ['slug', 'name', 'username'].forEach(function (n) { f[n].addEventListener('input', slugPrev); });
  function photo(p) {
    f.profile_image.value = p || '';
    var box = document.getElementById('umPhoto');
    box.innerHTML = p ? '<img src="/' + String(p).replace(/^\/+/, '') + '" alt="" style="width:72px;height:72px;border-radius:50%;object-fit:cover;display:block">' : '<span class="u-avatar" style="width:72px;height:72px;font-size:26px">' + ((f.name.value || f.username.value || '?').charAt(0).toUpperCase().replace(/[<>&"]/g, '')) + '</span>';
    document.getElementById('umPhotoRm').hidden = !p;
  }
  return {
    open: function (id) {
      var r = id ? rows[id] : null;
      f.reset(); f.id.value = id || 0;
      document.getElementById('umTitle').textContent = r ? 'Edit User — ' + r.username : 'Add New User';
      document.getElementById('umSave').innerHTML = r ? '<i class="fas fa-save"></i> Save Changes' : '<i class="fas fa-save"></i> Create User';
      document.getElementById('umPassLabel').innerHTML = r ? 'New Password' : 'Password <span class="req">*</span>';
      f.password.required = !r; f.password.placeholder = r ? 'Leave blank to keep the current password' : 'At least 8 characters';
      if (r) {
        ['username', 'email', 'role', 'status', 'name', 'full_name', 'slug', 'designation', 'bio', 'qualifications', 'experience', 'languages_known', 'certifications', 'mobile_number', 'address', 'facebook', 'twitter', 'instagram', 'linkedin', 'threads'].forEach(function (k) { if (f[k]) f[k].value = r[k] == null ? '' : r[k]; });
        f.author_email.value = r.author_email || '';
        if (f.t_subject) { f.t_subject.value = r.t_subject || ''; f.t_about.value = r.t_about || ''; f.t_sort.value = r.t_sort || 0; f.t_show.checked = r.t_show != 0; }
        f.is_featured.checked = r.is_featured == 1;
        if (!f.role.querySelector('option[value="' + r.role + '"]')) f.role.value = f.role.options[0].value;
        setPerms(r.perms);
      } else { f.role.value = f.role.querySelector('option[value="author"]') ? 'author' : f.role.options[0].value; f.status.value = 'active'; setPerms(roleDefaults[f.role.value] || roleDefaults.author); }
      var self = !!(r && r.self);
      f.role.disabled = self; f.status.disabled = self; document.getElementById('umSelfHint').hidden = !self;
      if (permBox) permBox.hidden = self;
      photo(r ? r.profile_image : ''); slugPrev(); teachBox(); scopeBox(f.role.value);
      m.classList.add('open'); document.body.style.overflow = 'hidden';
      setTimeout(function () { (r ? f.name : f.username).focus(); }, 50);
    },
    close: function () { m.classList.remove('open'); document.body.style.overflow = ''; },
    applyRole: function (role) { setPerms(roleDefaults[role] || roleDefaults.author); scopeBox(role); },
    eye: function (b) { var i = b.previousElementSibling; i.type = i.type === 'password' ? 'text' : 'password'; b.firstElementChild.className = 'fas ' + (i.type === 'password' ? 'fa-eye' : 'fa-eye-slash'); },
    pick: function () { EduMedia.pick(function (it) { photo(it.path); }); },
    photo: photo,
    del: function (id) {
      var r = rows[id], d = document.getElementById('umDel'), sel = d.querySelector('[name=reassign]');
      d.querySelector('[name=id]').value = id;
      document.getElementById('umDelName').textContent = r.username;
      document.getElementById('umDelCount').textContent = r.posts;
      document.getElementById('umDelPosts').hidden = !(+r.posts);
      Array.prototype.forEach.call(sel.options, function (o) { o.hidden = o.disabled = +o.value === +id; });
      var first = Array.prototype.find.call(sel.options, function (o) { return !o.disabled; }); if (first) sel.value = first.value;
      d.classList.add('open');
    }
  };
})();
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { UM.close(); document.getElementById('umDel').classList.remove('open'); } });
document.getElementById('umModal').addEventListener('click', function (e) { if (e.target === this) UM.close(); });
document.getElementById('umDel').addEventListener('click', function (e) { if (e.target === this) this.classList.remove('open'); });
// Disabled fields are not sent — enable them just before submitting (the server ignores them for your own account anyway).
document.getElementById('umForm').addEventListener('submit', function () { this.role.disabled = false; this.status.disabled = false; });
</script>
<?php admin_shell_close(); ?>
</body>
</html>
