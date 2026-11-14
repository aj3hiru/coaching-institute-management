<?php
// Online store → Customers: buyer accounts (linked to institute students by mobile) with filters, what each one can
// open, give / take back access, set a new password, make an account for an institute student, and Block:
// from chosen courses, from every free course, or suspend the whole account (no login, no course, and its mobile /
// email can't make a new account).
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/institute.php';
require_once INCLUDES_PATH . '/shop.php';
$me = sh_page_start($pdo, ['customers']);
sh_tables($pdo);
$id = (int)($_GET['id'] ?? $_POST['uid'] ?? 0);
if (isset($_GET['blockinfo'])) { // the Block popup: the account and every course it has (or had)
    header('Content-Type: application/json; charset=utf-8');
    $q = $pdo->prepare("SELECT id, name, mobile, email, status, block_free, block_note FROM sh_users WHERE id = ?"); $q->execute([(int)$_GET['blockinfo']]); $bu = $q->fetch(PDO::FETCH_ASSOC);
    if (!$bu) exit(json_encode(['error' => 'Account not found']));
    $a = $pdo->prepare("SELECT a.id, a.source, a.revoked, a.blocked, a.expires_at, p.title, p.type, p.price FROM sh_access a JOIN sh_products p ON p.id = a.product_id WHERE a.user_id = ? ORDER BY a.blocked DESC, a.revoked, p.title"); $a->execute([(int)$bu['id']]);
    $others = $pdo->prepare("SELECT id, title, type FROM sh_products WHERE id NOT IN (SELECT product_id FROM sh_access WHERE user_id = ?) ORDER BY status = 'published' DESC, title"); $others->execute([(int)$bu['id']]);
    exit(json_encode(['user' => $bu, 'others' => array_map(fn($o) => ['id' => (int)$o['id'], 'title' => $o['title'] . ($o['type'] === 'notes' ? ' (PDF notes)' : '')], $others->fetchAll(PDO::FETCH_ASSOC)),
        'items' => array_map(fn($x) => ['id' => (int)$x['id'], 'title' => $x['title'], 'kind' => $x['type'] === 'notes' ? 'PDF notes' : 'Video course', 'how' => ['purchase' => 'Bought', 'free' => 'Free', 'admin' => 'Given by staff', 'block' => 'Never had it'][$x['source']] ?? $x['source'],
        'blocked' => (int)$x['blocked'], 'active' => !(int)$x['revoked'] && !(int)$x['blocked'] && (!$x['expires_at'] || strtotime($x['expires_at']) > time()), 'free' => (float)$x['price'] <= 0], $a->fetchAll(PDO::FETCH_ASSOC))], JSON_UNESCAPED_UNICODE));
}

// The customers list and profile now live in Students: an online account is a student ("Online" / "Both") and its
// profile is the student profile (admin/components/online-account.php). This page keeps the actions those forms post to.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($id && ($sid = sh_student_sync($pdo, $id))) { header('Location: /admin/student-view.php?id=' . $sid . '#online'); exit; }
    $go = array_filter(['type' => 'online', 'q' => trim((string)($_GET['q'] ?? ''))]);
    header('Location: /admin/students.php?' . http_build_query($go) . (isset($_GET['new']) ? '' : ''));
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a = (string)($_POST['action'] ?? '');
    $to = (string)($_POST['to'] ?? ''); // the student profile the form was on
    $to = preg_match('#^/admin/student-view\.php\?id=\d+$#', $to) ? $to : '';
    $back = $id ? '/admin/shop-customers.php?id=' . $id : '/admin/shop-customers.php';
    try {
        if ($a === 'create') {
            $sid = (int)($_POST['student_id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? '')); $mobile = trim((string)($_POST['mobile'] ?? '')); $email = trim((string)($_POST['email'] ?? ''));
            if ($sid) { // from an institute student: use their details where the form is empty
                $s = $pdo->prepare("SELECT name, mobile, email, dob FROM ci_students WHERE id = ?"); $s->execute([$sid]);
                if ($r = $s->fetch(PDO::FETCH_ASSOC)) { $sdob = $r['dob']; $name = $name ?: $r['name']; $mobile = $mobile ?: $r['mobile']; $email = $email ?: (string)$r['email']; }
            }
            $pass = (string)($_POST['pass'] ?? '');
            $made = false;
            if ($pass === '') { $pass = ($sid && !empty($sdob) ? ci_default_pass($sdob, '') : '') ?: sh_clean_mobile($mobile) ?: substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(9))), 0, 8); $made = true; } // empty = the student's date of birth (DDMMYYYY), else the mobile number
            if ($email === '' && preg_match('/^[6-9]\d{9}$/', sh_clean_mobile($mobile))) $email = 'm' . sh_clean_mobile($mobile) . '@noemail.invalid'; // mobile-only login
            $new = sh_register($pdo, $name, $mobile, $email, $pass, (int)$me['id']);
            if ($sid) { $pdo->prepare("UPDATE sh_users SET student_id = ? WHERE id = ?")->execute([$sid, $new]); ci_type_sync($pdo, $sid); }
            admin_log('shop_customer', "Created buyer account #$new for $name");
            admin_flash('Online login made. Login: mobile number' . ($made ? ' · password: ' . $pass : ' + the password you set') . '.');
            $back = $sid ? '/admin/student-view.php?id=' . $sid . '#online-account' : '/admin/shop-customers.php?id=' . $new;
        } elseif ($id && $a === 'grant') {
            $pid = (int)($_POST['product_id'] ?? 0);
            $days = ($_POST['days'] ?? '') === '' ? null : max(0, (int)$_POST['days']);
            sh_grant($pdo, $id, $pid, 'admin', null, (int)$me['id'], trim((string)($_POST['note'] ?? '')), $days);
            admin_log('shop_access', "Gave product #$pid to buyer #$id");
            admin_flash('Access given.');
        } elseif ($id && $a === 'revoke') {
            $pdo->prepare("UPDATE sh_access SET revoked = 1 WHERE id = ? AND user_id = ?")->execute([(int)$_POST['aid'], $id]);
            admin_log('shop_access', "Took back access #" . (int)$_POST['aid'] . " from buyer #$id");
            admin_flash('Access taken back.');
        } elseif ($id && $a === 'restore') {
            $pdo->prepare("UPDATE sh_access SET revoked = 0 WHERE id = ? AND user_id = ?")->execute([(int)$_POST['aid'], $id]);
            admin_flash('Access given back.');
        } elseif ($id && $a === 'save') {
            $name = trim((string)($_POST['name'] ?? '')); $mobile = sh_clean_mobile($_POST['mobile'] ?? ''); $email = strtolower(trim((string)($_POST['email'] ?? '')));
            if (mb_strlen($name) < 2) throw new RuntimeException('Enter the name.');
            if (!preg_match('/^[6-9]\d{9}$/', $mobile)) throw new RuntimeException('Enter a valid 10-digit mobile number.');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid email.');
            $q = $pdo->prepare("SELECT COUNT(*) FROM sh_users WHERE (mobile = ? OR email = ?) AND id <> ?"); $q->execute([$mobile, $email, $id]);
            if ((int)$q->fetchColumn()) throw new RuntimeException('Another account already uses this mobile or email.');
            $status = ($_POST['status'] ?? '') === 'blocked' ? 'blocked' : 'active';
            $pdo->prepare("UPDATE sh_users SET name = ?, mobile = ?, email = ?, status = ?, student_id = COALESCE(student_id, ?) WHERE id = ?")->execute([mb_substr($name, 0, 150), $mobile, $email, $status, sh_find_student($pdo, $mobile), $id]);
            if ($status === 'blocked') { foreach (['mobile' => $mobile, 'email' => $email] as $k => $v) $pdo->prepare("INSERT INTO sh_bans (kind, value, user_id, created_by) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE user_id = VALUES(user_id)")->execute([$k, $v, $id, (int)$me['id']]); }
            else $pdo->prepare("DELETE FROM sh_bans WHERE user_id = ?")->execute([$id]);
            admin_flash('Saved.');
        } elseif ($id && $a === 'password') {
            $pass = (string)($_POST['pass'] ?? '');
            $made = false;
            if ($pass === '') { $pass = substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(9))), 0, 8); $made = true; }
            if (strlen($pass) < 4) throw new RuntimeException('The password must have at least 4 characters.');
            $pdo->prepare("UPDATE sh_users SET pass = ?, reset_hash = NULL WHERE id = ?")->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
            admin_log('shop_customer', "Set a new password for buyer #$id");
            admin_flash('New password saved' . ($made ? ': ' . $pass . ' — give it to the student now.' : '.'));
        } elseif ($id && $a === 'block_save') { // Block popup: chosen courses, free courses, whole account
            $bu = $pdo->query("SELECT * FROM sh_users WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
            if (!$bu) throw new RuntimeException('Account not found.');
            $note = mb_substr(trim((string)($_POST['block_note'] ?? '')), 0, 255) ?: null;
            $want = array_map('intval', (array)($_POST['block_access'] ?? []));
            foreach ($pdo->query("SELECT id, blocked FROM sh_access WHERE user_id = $id")->fetchAll(PDO::FETCH_ASSOC) as $x) {
                $on = in_array((int)$x['id'], $want, true);
                if ($on && !(int)$x['blocked']) $pdo->prepare("UPDATE sh_access SET blocked = 1, revoked = 1, note = COALESCE(?, note) WHERE id = ?")->execute([$note ? 'Blocked: ' . $note : null, $x['id']]);
                if (!$on && (int)$x['blocked']) { // block lifted: the course opens again — or, for a course they never had, the row goes
                    $src = (string)$pdo->query("SELECT source FROM sh_access WHERE id = " . (int)$x['id'])->fetchColumn();
                    if ($src === 'block') $pdo->prepare("DELETE FROM sh_access WHERE id = ?")->execute([$x['id']]);
                    else $pdo->prepare("UPDATE sh_access SET blocked = 0, revoked = 0 WHERE id = ?")->execute([$x['id']]);
                }
            }
            foreach (array_unique(array_map('intval', (array)($_POST['block_new'] ?? []))) as $np) // courses they don't have: they can't buy / join them
                if ($np && (int)$pdo->query("SELECT COUNT(*) FROM sh_products WHERE id = $np")->fetchColumn())
                    $pdo->prepare("INSERT INTO sh_access (user_id, product_id, source, granted_by, note, blocked, revoked) VALUES (?, ?, 'block', ?, ?, 1, 1) ON DUPLICATE KEY UPDATE blocked = 1, revoked = 1")->execute([$id, $np, (int)$me['id'], $note ? 'Blocked: ' . $note : 'Blocked']);
            $want = array_merge($want, array_map('intval', (array)($_POST['block_new'] ?? [])));
            $free = !empty($_POST['block_free']) ? 1 : 0;
            if ($free) $pdo->exec("UPDATE sh_access SET blocked = 1, revoked = 1 WHERE user_id = $id AND source = 'free'");
            $susp = !empty($_POST['suspend']);
            $pdo->prepare("UPDATE sh_users SET block_free = ?, block_note = ?, status = ?, blocked_at = IF(? = 1 OR ? = 1 OR ? > 0, COALESCE(blocked_at, NOW()), NULL) WHERE id = ?")
                ->execute([$free, $note, $susp ? 'blocked' : 'active', $susp ? 1 : 0, $free, count($want), $id]);
            if ($susp) { // the mobile number and email can't make a new account
                foreach (['mobile' => $bu['mobile'], 'email' => strtolower((string)$bu['email'])] as $k => $v) if ($v !== '') $pdo->prepare("INSERT INTO sh_bans (kind, value, user_id, reason, created_by) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), reason = VALUES(reason)")->execute([$k, $v, $id, $note, (int)$me['id']]);
            } else $pdo->prepare("DELETE FROM sh_bans WHERE user_id = ?")->execute([$id]);
            admin_log('shop_customer', "Block settings for buyer #$id: " . count($want) . ' course(s)' . ($free ? ', free courses' : '') . ($susp ? ', account suspended' : ''));
            admin_flash($susp ? 'Account suspended — no login, no course, and its mobile / email can\'t make a new account.' : (count($want) || $free ? 'Block saved.' : 'Blocks removed.'));
            $back = (string)($_POST['back'] ?? '') === 'list' ? '/admin/shop-customers.php?' . http_build_query(array_filter(['q' => $_POST['bq'] ?? '', 'show' => $_POST['bshow'] ?? ''])) : '/admin/shop-customers.php?id=' . $id;
        } elseif ($id && $a === 'unlink') {
            $pdo->prepare("UPDATE sh_users SET student_id = NULL WHERE id = ?")->execute([$id]);
            $ns = sh_student_sync($pdo, $id);
            admin_flash('No longer linked to the institute student.');
            if ($ns) $to = '/admin/student-view.php?id=' . $ns;
        }
    } catch (RuntimeException $e) { admin_flash($e->getMessage(), 'error'); }
    if ($to && $a !== 'create') $back = $to . '#online';
    header('Location: ' . $back);
    exit;
}

$products = $pdo->query("SELECT id, title, type, price, validity_days, status FROM sh_products ORDER BY status = 'published' DESC, title")->fetchAll(PDO::FETCH_ASSOC);
$cu = null;
if ($id) {
    $q = $pdo->prepare("SELECT u.*, s.name AS sname, s.reg_no, s.id AS sid FROM sh_users u LEFT JOIN ci_students s ON s.id = u.student_id WHERE u.id = ?");
    try { $q->execute([$id]); $cu = $q->fetch(PDO::FETCH_ASSOC) ?: null; } catch (Throwable) { $q = $pdo->prepare("SELECT * FROM sh_users WHERE id = ?"); $q->execute([$id]); $cu = $q->fetch(PDO::FETCH_ASSOC) ?: null; }
    if (!$cu) { header('Location: /admin/shop-customers.php'); exit; }
    $acc = $pdo->prepare("SELECT a.*, p.title, p.type, u.username AS by_name FROM sh_access a JOIN sh_products p ON p.id = a.product_id LEFT JOIN users u ON u.id = a.granted_by WHERE a.user_id = ? ORDER BY a.revoked, a.granted_at DESC");
    $acc->execute([$id]); $acc = $acc->fetchAll(PDO::FETCH_ASSOC);
    $ord = $pdo->prepare("SELECT * FROM sh_orders WHERE user_id = ? ORDER BY id DESC LIMIT 50"); $ord->execute([$id]); $ord = $ord->fetchAll(PDO::FETCH_ASSOC);
} else {
    $qq = trim((string)($_GET['q'] ?? ''));
    $show = in_array($_GET['show'] ?? '', ['buyers', 'free', 'none', 'linked', 'suspended', 'blocked'], true) ? $_GET['show'] : '';
    $joined = in_array($_GET['joined'] ?? '', ['today', 'week', 'month', 'year'], true) ? $_GET['joined'] : '';
    $sort = in_array($_GET['sort'] ?? '', ['spent', 'login', 'name', 'owns'], true) ? $_GET['sort'] : '';
    $owns = "(SELECT COUNT(*) FROM sh_access a WHERE a.user_id = u.id AND a.revoked = 0 AND a.blocked = 0 AND (a.expires_at IS NULL OR a.expires_at > NOW()))";
    $spentQ = "(SELECT COALESCE(SUM(o.amount),0) FROM sh_orders o WHERE o.user_id = u.id AND o.status = 'paid')";
    $w = []; $args = [];
    if ($qq !== '') { $w[] = '(u.name LIKE ? OR u.mobile LIKE ? OR u.email LIKE ?)'; array_push($args, "%$qq%", "%$qq%", "%$qq%"); }
    if ($show === 'buyers') $w[] = "EXISTS (SELECT 1 FROM sh_orders o WHERE o.user_id = u.id AND o.status = 'paid')";
    if ($show === 'free') $w[] = "EXISTS (SELECT 1 FROM sh_access a WHERE a.user_id = u.id AND a.source = 'free' AND a.revoked = 0)";
    if ($show === 'none') $w[] = "$owns = 0";
    if ($show === 'linked') $w[] = 'u.student_id IS NOT NULL';
    if ($show === 'suspended') $w[] = "u.status <> 'active'";
    if ($show === 'blocked') $w[] = "(u.block_free = 1 OR EXISTS (SELECT 1 FROM sh_access a WHERE a.user_id = u.id AND a.blocked = 1))";
    if ($joined !== '') $w[] = 'u.created_at >= ' . ['today' => 'CURDATE()', 'week' => "DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)", 'month' => "DATE_FORMAT(CURDATE(), '%Y-%m-01')", 'year' => "DATE_FORMAT(CURDATE(), '%Y-01-01')"][$joined];
    $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM sh_users u $where"); $cnt->execute($args); $total = (int)$cnt->fetchColumn();
    $per = 50; $pages = max(1, (int)ceil($total / $per)); $pg = min($pages, max(1, (int)($_GET['page'] ?? 1)));
    $order = ['spent' => 'spent DESC, u.id DESC', 'login' => '(u.last_login IS NULL), u.last_login DESC', 'name' => 'u.name', 'owns' => 'items DESC, u.id DESC'][$sort] ?? 'u.id DESC';
    $st = $pdo->prepare("SELECT u.*, $owns AS items, $spentQ AS spent, (SELECT COUNT(*) FROM sh_access a WHERE a.user_id = u.id AND a.blocked = 1) AS nblocked FROM sh_users u $where ORDER BY $order LIMIT $per OFFSET " . (($pg - 1) * $per));
    $st->execute($args); $list = $st->fetchAll(PDO::FETCH_ASSOC);
    $tot = $pdo->query("SELECT COUNT(*) n, SUM(status <> 'active') susp, SUM(EXISTS (SELECT 1 FROM sh_orders o WHERE o.user_id = sh_users.id AND o.status = 'paid')) buyers,
        SUM(created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) month FROM sh_users")->fetch(PDO::FETCH_ASSOC);
    $totSpent = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM sh_orders WHERE status = 'paid'")->fetchColumn();
    $qsl = fn(array $o = []) => '?' . http_build_query(array_filter(array_merge(['q' => $qq, 'show' => $show, 'joined' => $joined, 'sort' => $sort], $o), fn($v) => $v !== '' && $v !== null && $v !== 1));
    $students = [];
    try { $students = $pdo->query("SELECT s.id, s.name, s.reg_no, s.mobile FROM ci_students s WHERE s.status = 'active' AND NOT EXISTS (SELECT 1 FROM sh_users u WHERE u.student_id = s.id OR u.mobile = s.mobile) ORDER BY s.name LIMIT 2000")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable) {}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Store Customers - <?= ci_e(edu_site_name()) ?></title>
<?php admin_shell_head(); ci_head(); ?>
<style>
.cu-tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;margin:0 0 14px}
.cu-tile{display:flex;gap:10px;align-items:center;padding:12px 14px;border:1px solid #e5e7eb;background:#fff;color:#111827!important;text-decoration:none!important}
.cu-tile:hover{border-color:#c4b5fd}.cu-tile i{width:36px;height:36px;display:grid;place-items:center;background:#f3effa;color:var(--ci-acc,#7c3aed);font-size:15px;flex:0 0 auto}
.cu-tile b{display:block;font-size:18px;line-height:1.1}.cu-tile small{color:#6b7280;font-size:12px}.cu-t-red i{background:#fef2f2;color:#dc2626}
.cu-filters{display:grid;grid-template-columns:minmax(220px,2fr) repeat(3,minmax(150px,1fr)) auto;gap:12px;align-items:end}
.cu-filters .cu-btns span{display:flex;gap:6px}
@media (max-width:900px){.cu-filters{grid-template-columns:1fr 1fr}.cu-filters .cu-search{grid-column:1/-1}}
.cu-who{display:flex;gap:10px;align-items:center;text-decoration:none!important;color:#111827!important}.cu-who small{display:block;color:#6b7280;font-size:12px}
.cu-av{width:34px;height:34px;flex:0 0 auto;display:grid;place-items:center;background:#ede9fe;color:#6d28d9;font-weight:800;border-radius:50%!important}
.cu-off td{opacity:.6}
.cu-pg{display:flex;gap:6px;flex-wrap:wrap;justify-content:center;margin:0 0 16px}.cu-pg a,.cu-pg span{min-width:34px;padding:6px 10px;border:1px solid #e5e7eb;background:#fff;text-align:center;text-decoration:none}.cu-pg .on{background:var(--ci-acc,#7c3aed);color:#fff;border-color:var(--ci-acc,#7c3aed)}
.cv-head{display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;padding:18px;border:1px solid #e5e7eb;background:#fff;margin:0 0 14px}
.cv-av{width:64px;height:64px;flex:0 0 auto;display:grid;place-items:center;background:#ede9fe;color:#6d28d9;font-weight:800;font-size:26px;border-radius:50%!important;overflow:hidden}.cv-av img{width:100%;height:100%;object-fit:cover}
.cv-who{flex:1;min-width:220px}.cv-who h2{margin:0 0 4px;font-size:20px}
.cv-contact{display:flex;gap:14px;flex-wrap:wrap;font-size:13.5px}.cv-contact a{color:#374151;text-decoration:none}.cv-contact i{color:#9ca3af;margin-right:3px}
.cv-badges{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}.cv-badges a{text-decoration:none}
.cv-note{margin:8px 0 0;font-size:13px;color:#92400e;background:#fffbeb;border:1px solid #fde68a;padding:6px 10px}
.cv-acts{display:flex;gap:8px;flex-wrap:wrap}.cv-ban{color:#b91c1c!important;border-color:#fecaca!important}
.cv-tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:10px;margin:0 0 14px}
.cv-tile{display:flex;gap:10px;align-items:center;padding:12px 14px;border:1px solid #e5e7eb;background:#fff}.cv-tile i{width:36px;height:36px;display:grid;place-items:center;background:#f3effa;color:var(--ci-acc,#7c3aed);flex:0 0 auto}
.cv-tile b{display:block;font-size:16px}.cv-tile small{color:#6b7280;font-size:12px}
.cv-grid{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:14px;align-items:start}
@media (max-width:1100px){.cv-grid{grid-template-columns:1fr}}
.cv-side .ci-grid2{grid-template-columns:1fr!important}
.bk-items{display:flex;flex-direction:column;gap:6px;max-height:300px;overflow:auto;border:1px solid #eef0f3;padding:8px}
.bk-it{display:flex;gap:10px;align-items:center;padding:8px 10px;border:1px solid #e5e7eb;background:#fff;cursor:pointer}.bk-it.on{border-color:#fca5a5;background:#fef2f2}
.bk-it small{color:#6b7280;font-size:12px;display:block}.bk-it .bk-st{margin-left:auto;font-size:11.5px}
.bk-add{margin-top:8px}.bk-add select{width:100%}
.bk-opt{display:flex;gap:10px;align-items:flex-start;padding:10px 12px;border:1px solid #e5e7eb;margin-top:10px;cursor:pointer}.bk-opt small{display:block;color:#6b7280;font-size:12.5px;font-weight:400}
.bk-susp{border-color:#fecaca;background:#fff7f7}.bk-susp b{color:#b91c1c}
</style>
</head>
<body>
<?php admin_shell_open($cu ? ci_e($cu['name']) : 'Store Customers', $cu ? 'Buyer account' : 'Online accounts of students and buyers', $cu ? '<a class="ci-btn ci-ghost" href="/admin/shop-customers.php"><i class="fas fa-arrow-left"></i> All customers</a>' : (sh_perm($me, 'orders') ? '<button type="button" class="ci-btn" data-enroll="Enroll a student"><i class="fas fa-cart-plus"></i> Enroll student</button> ' : '') . '<button type="button" class="ci-btn ci-ghost" data-mopen="#mkForm"><i class="fas fa-user-plus"></i> Account only</button>'); ?>
<div class="ci">
<?= admin_flash() ?>
<?php if ($cu): ?>
<?php $cvOpen = count(array_filter($acc, fn($x) => !$x['revoked'] && empty($x['blocked']) && (!$x['expires_at'] || strtotime($x['expires_at']) > time())));
$cvBlocked = count(array_filter($acc, fn($x) => !empty($x['blocked']))); $cvPaid = array_filter($ord, fn($o) => $o['status'] === 'paid'); $cvSpent = array_sum(array_column($cvPaid, 'amount')); ?>
<div class="cv-head">
  <span class="cv-av"><?php if ($av = sh_avatar_url($cu)): ?><img src="<?= ci_e($av) ?>" alt=""><?php else: ?><?= ci_e(mb_strtoupper(mb_substr(trim($cu['name']), 0, 1))) ?><?php endif; ?></span>
  <div class="cv-who"><h2><?= ci_e($cu['name']) ?></h2><div class="cv-contact"><?= ci_tel($cu['mobile']) ?><a href="mailto:<?= ci_e($cu['email']) ?>"><i class="fas fa-envelope"></i> <?= ci_e($cu['email']) ?></a></div>
    <div class="cv-badges"><?= $cu['status'] === 'active' ? '<span class="ci-badge ci-b-green">Active</span>' : '<span class="ci-badge ci-b-red"><i class="fas fa-ban"></i> Suspended</span>' ?><?= $cvBlocked ? '<span class="ci-badge ci-b-amber">Blocked from ' . $cvBlocked . ' course' . ($cvBlocked === 1 ? '' : 's') . '</span>' : '' ?><?= !empty($cu['block_free']) ? '<span class="ci-badge ci-b-amber">No free courses</span>' : '' ?><?= !empty($cu['sid']) ? '<a class="ci-badge ci-b-grey" href="/admin/student-view.php?id=' . (int)$cu['sid'] . '"><i class="fas fa-school"></i> Institute student · ' . ci_e($cu['reg_no']) . '</a>' : '' ?></div>
    <?php if (!empty($cu['block_note']) && ($cu['status'] !== 'active' || $cvBlocked || !empty($cu['block_free']))): ?><p class="cv-note"><i class="fas fa-circle-info"></i> <?= ci_e($cu['block_note']) ?></p><?php endif; ?></div>
  <div class="cv-acts"><?php if (sh_perm($me, 'orders')): ?><button type="button" class="ci-btn" data-enroll="Enroll <?= ci_e($cu['name']) ?>" data-enroll-uid="<?= $id ?>" data-enroll-name="<?= ci_e($cu['name']) ?>"><i class="fas fa-cart-shopping"></i> Enroll</button><?php endif; ?><button type="button" class="ci-btn ci-ghost cv-ban" data-block="<?= $id ?>"><i class="fas fa-ban"></i> Block / suspend</button></div>
</div>
<div class="cv-tiles">
  <div class="cv-tile"><i class="fas fa-book-open"></i><div><b><?= $cvOpen ?></b><small>Can open now</small></div></div>
  <div class="cv-tile"><i class="fas fa-indian-rupee-sign"></i><div><b><?= ci_money0($cvSpent) ?></b><small>Spent · <?= count($cvPaid) ?> paid order<?= count($cvPaid) === 1 ? '' : 's' ?></small></div></div>
  <div class="cv-tile"><i class="fas fa-calendar-plus"></i><div><b><?= date('d M Y', strtotime($cu['created_at'])) ?></b><small>Joined</small></div></div>
  <div class="cv-tile"><i class="fas fa-right-to-bracket"></i><div><b><?= $cu['last_login'] ? date('d M Y', strtotime($cu['last_login'])) : 'Never' ?></b><small>Last login</small></div></div>
</div>
<div class="cv-grid">
  <div class="cv-main">
<div class="ci-card"><div class="ci-card-h"><h3>Courses &amp; notes</h3><?php if (sh_perm($me, 'orders')): ?><button type="button" class="ci-btn ci-sm" data-enroll="Enroll <?= ci_e($cu['name']) ?>" data-enroll-uid="<?= $id ?>" data-enroll-name="<?= ci_e($cu['name']) ?>"><i class="fas fa-cart-plus"></i> Enroll in a course / notes (paid)</button><?php endif; ?></div><div class="ci-card-b">
  <form method="post" class="ci-filters" style="margin:0 0 12px"><?= csrf_field() ?><input type="hidden" name="action" value="grant"><input type="hidden" name="uid" value="<?= $id ?>">
    <div class="ci-f" style="min-width:260px"><label>Give a course / notes free</label><select name="product_id" required data-search><option value=""></option><?php foreach ($products as $pp): ?><option value="<?= (int)$pp['id'] ?>"><?= ci_e($pp['title'] . ' — ' . ((float)$pp['price'] > 0 ? ci_money0($pp['price']) : 'Free') . ($pp['status'] !== 'published' ? ' (' . $pp['status'] . ')' : '')) ?></option><?php endforeach; ?></select></div>
    <div class="ci-f"><label>For how long</label><select name="days"><option value="">Product's own access period</option><option value="0">Lifetime</option><option value="30">1 month</option><option value="90">3 months</option><option value="180">6 months</option><option value="365">1 year</option></select></div>
    <div class="ci-f"><label>Note</label><input name="note" maxlength="200" placeholder="e.g. Offline batch student, paid cash"></div>
    <div class="ci-f"><label>&nbsp;</label><button class="ci-btn" type="submit"><i class="fas fa-key"></i> Give access</button></div>
  </form>
</div><div class="ci-card-b ci-p0 ci-table-wrap"><table class="ci-table"><thead><tr><th>Item</th><th>How</th><th>Since</th><th>Till</th><th>Action</th></tr></thead><tbody>
<?php if (!$acc): ?><tr><td colspan="5" class="ci-empty">Nothing yet.</td></tr><?php endif; ?>
<?php foreach ($acc as $x): $expired = $x['expires_at'] && strtotime($x['expires_at']) < time(); ?>
<tr<?= $x['revoked'] ? ' style="opacity:.55"' : '' ?>><td><b><?= ci_e($x['title']) ?></b> <small class="ci-muted"><?= $x['type'] === 'notes' ? 'PDF notes' : 'Video course' ?></small></td>
<td><?= ['purchase' => 'Bought', 'admin' => 'Given' . ($x['by_name'] ? ' by ' . ci_e($x['by_name']) : ''), 'free' => 'Free', 'block' => 'Never had it'][$x['source']] ?? ci_e($x['source']) ?><?= $x['note'] ? '<small class="ci-muted" style="display:block">' . ci_e($x['note']) . '</small>' : '' ?></td>
<td class="ci-nw"><?= date('d M Y', strtotime($x['granted_at'])) ?></td>
<td class="ci-nw"><?= !empty($x['blocked']) ? '<span class="ci-badge ci-b-red"><i class="fas fa-ban"></i> Blocked</span>' : ($x['revoked'] ? '<span class="ci-badge ci-b-red">Taken back</span>' : ($x['expires_at'] ? ($expired ? '<span class="ci-badge ci-b-amber">Ended ' . date('d M Y', strtotime($x['expires_at'])) . '</span>' : date('d M Y', strtotime($x['expires_at']))) : 'Lifetime') )?></td>
<td><?= !empty($x['blocked']) ? ci_ico('ban', null, 'Change the block', 'button', ' data-block="' . $id . '"') : ($x['revoked'] ? ci_post_ico('check', 'Give back', ['action' => 'restore', 'aid' => $x['id'], 'uid' => $id]) : ci_post_ico('trash', 'Take back access', ['action' => 'revoke', 'aid' => $x['id'], 'uid' => $id], 'Take back access to ' . $x['title'] . '? (A paid purchase is not refunded by this.)') )?></td></tr>
<?php endforeach; ?>
</tbody></table></div></div>

<div class="ci-card"><div class="ci-card-h"><h3>Orders</h3></div><div class="ci-card-b ci-p0 ci-table-wrap"><table class="ci-table"><thead><tr><th>Order</th><th>Item</th><th class="ci-num">Amount</th><th>Status</th><th>Payment id</th><th>Date</th></tr></thead><tbody>
<?php if (!$ord): ?><tr><td colspan="6" class="ci-empty">No orders.</td></tr><?php endif; ?>
<?php foreach ($ord as $o): ?><tr><td><?= ci_e($o['order_no'] ?: '#' . $o['id']) ?></td><td><?= ci_e($o['title']) ?></td><td class="ci-num"><?= ci_money0($o['amount']) ?></td><td><?= ['paid' => '<span class="ci-badge ci-b-green">Paid</span>', 'created' => '<span class="ci-badge ci-b-amber">Not paid</span>', 'failed' => '<span class="ci-badge ci-b-red">Failed</span>'][$o['status']] ?? '' ?></td><td><small><?= ci_e($o['rzp_payment'] ?: '—') ?></small></td><td class="ci-nw"><?= date('d M Y, h:i A', strtotime($o['paid_at'] ?: $o['created_at'])) ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>

  </div>
  <aside class="cv-side">
  <div class="ci-card"><div class="ci-card-h"><h3>Details</h3><?= $cu['status'] === 'active' ? '<span class="ci-badge ci-b-green">Active</span>' : '<span class="ci-badge ci-b-red">Suspended</span>' ?></div><div class="ci-card-b">
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="uid" value="<?= $id ?>">
    <div class="ci-grid2"><div class="ci-f"><label>Name</label><input name="name" required value="<?= ci_e($cu['name']) ?>"></div>
    <div class="ci-f"><label>Mobile</label><input name="mobile" required inputmode="numeric" value="<?= ci_e($cu['mobile']) ?>"></div>
    <div class="ci-f"><label>Email</label><input name="email" type="email" required value="<?= ci_e($cu['email']) ?>"></div>
    <div class="ci-f"><label>Status</label><select name="status"><option value="active">Active</option><option value="blocked" <?= $cu['status'] === 'blocked' ? 'selected' : '' ?>>Suspended</option></select></div></div>
    <p class="ci-muted" style="margin:8px 0">Joined <?= date('d M Y', strtotime($cu['created_at'])) ?><?= $cu['last_login'] ? ' · last login ' . date('d M Y, h:i A', strtotime($cu['last_login'])) : ' · never logged in' ?></p>
    <?php if (!empty($cu['sid'])): ?><p style="margin:0 0 10px">Institute student: <a href="/admin/student-view.php?id=<?= (int)$cu['sid'] ?>"><?= ci_e($cu['sname']) ?> (<?= ci_e($cu['reg_no']) ?>)</a></p><?php endif; ?>
    <button class="ci-btn" type="submit">Save</button></form>
    <?php if (!empty($cu['sid'])): ?><form method="post" style="margin-top:8px" onsubmit="return confirm('Unlink this account from the institute student?')"><?= csrf_field() ?><input type="hidden" name="action" value="unlink"><input type="hidden" name="uid" value="<?= $id ?>"><button class="ci-btn ci-ghost ci-sm" type="submit">Unlink student</button></form><?php endif; ?>
  </div></div>
  <div class="ci-card"><div class="ci-card-h"><h3>Password</h3></div><div class="ci-card-b">
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="password"><input type="hidden" name="uid" value="<?= $id ?>">
    <div class="ci-f"><label>New password</label><input name="pass" minlength="4" autocomplete="new-password" placeholder="leave empty to make one"><span class="ci-hint">The student can also reset it by email from the login page.</span></div>
    <button class="ci-btn ci-ghost" type="submit" style="margin-top:8px" onclick="return confirm('Set a new password for this account?')">Set new password</button></form>
  </div></div>
  </aside>
</div>
<?php else: ?>
<div class="ci-modal" id="mkForm" data-mform<?= isset($_GET['new']) ? ' data-open-now' : '' ?> hidden><div class="ci-modal-box ci-modal-wide" role="dialog" aria-modal="true" aria-labelledby="mkFormH">
<div class="ci-card-h"><h3 id="mkFormH">Make an online account</h3><button type="button" class="ci-x" data-close aria-label="Close"><i class="fas fa-times"></i></button></div><div class="ci-card-b">
<p class="ci-muted" style="margin:0 0 12px">Only the login — no course. To sell or give a course too, use <b>Enroll student</b>.</p>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="create">
<div class="ci-grid2 ci-mgrid">
  <div class="ci-f" style="grid-column:1/-1"><label>Institute student (optional)</label><select name="student_id" data-search><option value="">— Not an institute student —</option><?php foreach ($students as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)($_GET['student'] ?? 0) === (int)$s['id'] ? 'selected' : '' ?>><?= ci_e($s['name'] . ' (' . $s['reg_no'] . ') · ' . $s['mobile']) ?></option><?php endforeach; ?></select><span class="ci-hint">Their name, mobile and email are used if you leave those empty. Students who already have an account are not listed.</span></div>
  <div class="ci-f"><label>Name</label><input name="name" maxlength="150"></div>
  <div class="ci-f"><label>Mobile</label><input name="mobile" inputmode="numeric" maxlength="14"></div>
  <div class="ci-f"><label>Email</label><input name="email" type="email" maxlength="190"><span class="ci-hint">Needed for password reset.</span></div>
  <div class="ci-f"><label>Password</label><input name="pass" minlength="4" autocomplete="new-password" placeholder="leave empty to make one"></div>
</div>
<div class="ci-mfoot"><button type="button" class="ci-btn ci-ghost" data-close>Cancel</button><button class="ci-btn" type="submit"><i class="fas fa-user-plus"></i> Make account</button></div>
</form>
</div></div></div>
<div class="cu-tiles">
  <a class="cu-tile" href="?"><i class="fas fa-users"></i><div><b><?= (int)$tot['n'] ?></b><small>Online accounts</small></div></a>
  <a class="cu-tile" href="?show=buyers"><i class="fas fa-cart-shopping"></i><div><b><?= (int)$tot['buyers'] ?></b><small>Bought something</small></div></a>
  <a class="cu-tile" href="?joined=month"><i class="fas fa-user-plus"></i><div><b><?= (int)$tot['month'] ?></b><small>Joined this month</small></div></a>
  <a class="cu-tile" href="?sort=spent"><i class="fas fa-indian-rupee-sign"></i><div><b><?= ci_money0($totSpent) ?></b><small>Total sales</small></div></a>
  <a class="cu-tile cu-t-red" href="?show=suspended"><i class="fas fa-ban"></i><div><b><?= (int)$tot['susp'] ?></b><small>Suspended</small></div></a>
</div>
<div class="ci-card"><div class="ci-card-b"><form class="cu-filters" method="get">
  <div class="ci-f cu-search"><label>Search</label><input name="q" value="<?= ci_e($qq) ?>" placeholder="Name, mobile or email"></div>
  <div class="ci-f"><label>Show</label><select name="show" onchange="this.form.submit()"><?php foreach (['' => 'All accounts', 'buyers' => 'Bought something', 'free' => 'Joined a free course', 'none' => 'Own nothing yet', 'linked' => 'Institute students', 'blocked' => 'Blocked from a course', 'suspended' => 'Suspended'] as $k => $l): ?><option value="<?= $k ?>" <?= $show === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
  <div class="ci-f"><label>Joined</label><select name="joined" onchange="this.form.submit()"><?php foreach (['' => 'Any time', 'today' => 'Today', 'week' => 'This week', 'month' => 'This month', 'year' => 'This year'] as $k => $l): ?><option value="<?= $k ?>" <?= $joined === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
  <div class="ci-f"><label>Sort by</label><select name="sort" onchange="this.form.submit()"><?php foreach (['' => 'Newest first', 'spent' => 'Most spent', 'owns' => 'Most courses', 'login' => 'Last login', 'name' => 'Name A–Z'] as $k => $l): ?><option value="<?= $k ?>" <?= $sort === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
  <div class="ci-f cu-btns"><label>&nbsp;</label><span><button class="ci-btn" type="submit"><i class="fas fa-magnifying-glass"></i> Search</button><?php if ($qq !== '' || $show !== '' || $joined !== '' || $sort !== ''): ?><a class="ci-btn ci-ghost" href="?">Clear</a><?php endif; ?></span></div>
</form></div></div>
<div class="ci-card"><div class="ci-card-h"><h3><?= $total ?> account<?= $total === 1 ? '' : 's' ?></h3><?php if ($pages > 1): ?><span class="ci-muted">Page <?= $pg ?> of <?= $pages ?></span><?php endif; ?></div>
<div class="ci-card-b ci-p0 ci-table-wrap"><table data-sno class="ci-table cu-table"><thead><tr><th>Student</th><th>Mobile</th><th>Institute</th><th class="ci-num">Courses</th><th class="ci-num">Spent</th><th>Joined</th><th>Last login</th><th>Status</th><th>Action</th></tr></thead><tbody>
<?php if (!$list): ?><tr><td colspan="9" class="ci-empty"><?= $total || $qq !== '' || $show !== '' || $joined !== '' ? 'No account matches — change the filters.' : 'No accounts yet.' ?></td></tr><?php endif; ?>
<?php foreach ($list as $r): $susp = $r['status'] !== 'active'; ?>
<tr class="<?= $susp ? 'cu-off' : '' ?>"><td><a class="cu-who" href="?id=<?= (int)$r['id'] ?>"><span class="cu-av"><?= ci_e(mb_strtoupper(mb_substr(trim($r['name']), 0, 1))) ?></span><span><b><?= ci_e($r['name']) ?></b><small><?= ci_e($r['email']) ?></small></span></a></td>
<td class="ci-nw"><?= ci_tel($r['mobile']) ?></td>
<td><?= $r['student_id'] ? '<a href="/admin/student-view.php?id=' . (int)$r['student_id'] . '"><i class="fas fa-school"></i> Student</a>' : '<span class="ci-muted">—</span>' ?></td>
<td class="ci-num"><?= (int)$r['items'] ?></td><td class="ci-num"><?= ci_money0($r['spent']) ?></td>
<td class="ci-nw"><?= date('d M Y', strtotime($r['created_at'])) ?></td><td class="ci-nw"><?= $r['last_login'] ? date('d M Y', strtotime($r['last_login'])) : '<span class="ci-muted">Never</span>' ?></td>
<td><?= $susp ? '<span class="ci-badge ci-b-red">Suspended</span>' : (((int)$r['nblocked'] || (int)$r['block_free']) ? '<span class="ci-badge ci-b-amber">Blocked: ' . ((int)$r['nblocked'] ? (int)$r['nblocked'] . ' course' . ((int)$r['nblocked'] === 1 ? '' : 's') : '') . ((int)$r['nblocked'] && (int)$r['block_free'] ? ' + ' : '') . ((int)$r['block_free'] ? 'free' : '') . '</span>' : '<span class="ci-badge ci-b-green">Active</span>') ?></td>
<td><div class="ci-icos"><?= ci_ico('eye', '?id=' . (int)$r['id'], 'Open') ?><?= ci_ico('ban', null, 'Block / suspend', 'button', ' data-block="' . (int)$r['id'] . '"') ?></div></td></tr>
<?php endforeach; ?>
</tbody></table></div></div>
<?php if ($pages > 1): ?><nav class="cu-pg"><?php for ($i = 1; $i <= $pages; $i++): ?><?= $i === $pg ? '<span class="on">' . $i . '</span>' : '<a href="' . ci_e($qsl(['page' => $i])) . '">' . $i . '</a>' ?><?php endfor; ?></nav><?php endif; ?>
<?php endif; ?>
</div>
<!-- Block / suspend -->
<div class="ci-modal" id="bkForm" data-mform hidden><div class="ci-modal-box ci-modal-wide" role="dialog" aria-modal="true" aria-labelledby="bkH">
<div class="ci-card-h"><h3 id="bkH">Block</h3><button type="button" class="ci-x" data-close aria-label="Close"><i class="fas fa-times"></i></button></div><div class="ci-card-b">
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="block_save"><input type="hidden" name="uid" value=""><input type="hidden" name="back" value="<?= $cu ? 'view' : 'list' ?>">
<input type="hidden" name="bq" value="<?= ci_e($_GET['q'] ?? '') ?>"><input type="hidden" name="bshow" value="<?= ci_e($_GET['show'] ?? '') ?>">
  <p class="ci-muted" style="margin:0 0 8px">Tick the courses this student must not open any more. A blocked course can't be bought or joined again by them. Untick to lift a block.</p>
  <div class="bk-items" id="bkItems"><p class="ci-muted" style="margin:6px">Loading…</p></div>
  <div class="bk-add"><select id="bkOther" aria-label="Block from another course"><option value="">+ Block from another course (one they don't have)…</option></select></div>
  <label class="bk-opt"><input type="checkbox" name="block_free" value="1"><span><b>Block from every free course</b><small>Free courses they joined close, and they can't join free courses.</small></span></label>
  <label class="bk-opt bk-susp"><input type="checkbox" name="suspend" value="1"><span><b>Suspend the whole account</b><small>No login and no course at all, and this mobile number and email can't be used to make a new account.</small></span></label>
  <div class="ci-f" style="margin-top:10px"><label>Reason <span class="ci-muted" style="font-weight:400">(only staff see it)</span></label><input name="block_note" maxlength="255" placeholder="e.g. Shared the login with others, abusive in doubts"></div>
  <div class="ci-mfoot"><button type="button" class="ci-btn ci-ghost" data-close>Cancel</button><button class="ci-btn" type="submit" style="background:#b91c1c;border-color:#b91c1c"><i class="fas fa-ban"></i> Save block</button></div>
</form></div></div></div>
<script>
(function () {
  var m = document.getElementById('bkForm'), f = m.querySelector('form'), box = document.getElementById('bkItems');
  function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : t; return d.innerHTML; }
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-block]'); if (!b) return; e.preventDefault();
    var uid = b.getAttribute('data-block'); f.uid.value = uid; box.innerHTML = '<p class="ci-muted" style="margin:6px">Loading…</p>';
    f.block_free.checked = false; f.suspend.checked = false; f.block_note.value = '';
    window.ciModal.open('#bkForm');
    fetch('/admin/shop-customers.php?blockinfo=' + uid, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      if (d.error) { box.innerHTML = '<p style="color:#b91c1c;margin:6px">' + esc(d.error) + '</p>'; return; }
      document.getElementById('bkH').textContent = 'Block — ' + d.user.name;
      var so = document.getElementById('bkOther'); so.innerHTML = '<option value="">+ Block from another course (one they don\'t have)…</option>' + (d.others || []).map(function (o) { return '<option value="' + o.id + '">' + esc(o.title) + '</option>'; }).join('');
      f.block_free.checked = +d.user.block_free === 1; f.suspend.checked = d.user.status !== 'active'; f.block_note.value = d.user.block_note || '';
      box.innerHTML = d.items.length ? d.items.map(function (it) {
        return '<label class="bk-it' + (it.blocked ? ' on' : '') + '"><input type="checkbox" name="block_access[]" value="' + it.id + '"' + (it.blocked ? ' checked' : '') + '><span><b>' + esc(it.title) + '</b><small>' + esc(it.kind) + ' · ' + esc(it.how) + (it.free && it.how !== 'Free' ? ' · free course' : '') + '</small></span><span class="bk-st ci-badge ' + (it.blocked ? 'ci-b-red">Blocked' : (it.active ? 'ci-b-green">Can open' : 'ci-b-grey">Closed')) + '</span></label>';
      }).join('') : '<p class="ci-muted" style="margin:6px">This student has no course yet — you can still suspend the account.</p>';
    }).catch(function () { box.innerHTML = '<p style="color:#b91c1c;margin:6px">Could not load the courses.</p>'; });
  });
  box.addEventListener('change', function (e) { var l = e.target.closest('.bk-it'); if (l) l.classList.toggle('on', e.target.checked); });
  document.getElementById('bkOther').addEventListener('change', function () { // a course they don't have: listed ticked, saved as blocked
    var o = this.options[this.selectedIndex]; if (!this.value) return;
    var p = box.querySelector('p'); if (p && !box.querySelector('.bk-it')) p.remove();
    var l = document.createElement('label'); l.className = 'bk-it on';
    l.innerHTML = '<input type="checkbox" name="block_new[]" value="' + this.value + '" checked><span><b>' + esc(o.textContent) + '</b><small>They don\'t have it — they won\'t be able to buy or join it</small></span><span class="bk-st ci-badge ci-b-red">New block</span>';
    box.appendChild(l); o.remove(); this.value = '';
  });
})();
</script>
<?php if (sh_perm($me, 'orders')) require __DIR__ . '/components/sh-enroll.php'; ?>
<?php admin_shell_close(); ?>
</body>
</html>
