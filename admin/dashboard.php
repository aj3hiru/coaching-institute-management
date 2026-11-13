<?php
// Dashboard — the coaching institute at a glance: students, admissions, batches, fee collected and due.
// The blog's dashboard (traffic, posts) is now Blog → Blog Dashboard (/admin/blog-dashboard.php).
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/institute.php';
$me = admin_guard(['admin', 'editor', 'author', 'teacher', 'accountant']);
if (!ci_can($me)) {
    if (admin_blog_access()) { header('Location: /admin/blog-dashboard.php'); exit; }
    require_once INCLUDES_PATH . '/shop.php';
    if (sh_perm($me)) { header('Location: ' . (sh_perm($me, 'orders') ? '/admin/shop-orders.php' : (sh_perm($me, 'products') ? '/admin/shop-products.php' : (sh_perm($me, 'customers') ? '/admin/shop-customers.php' : '/admin/shop-doubts.php')))); exit; } // online store staff
    // no institute and no blog access: a plain welcome page (no figures)
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Dashboard</title>';
    admin_shell_head();
    echo '</head><body>';
    admin_shell_open('Dashboard', date('l, F j, Y'));
    echo '<div class="card" style="padding:1.5rem">Welcome, ' . htmlspecialchars($me['username']) . '. Use the menu on the left.</div>';
    admin_shell_close();
    echo '</body></html>';
    exit;
}
ci_tables($pdo);

$one = fn(string $sql, array $a = []) => (function () use ($pdo, $sql, $a) { $s = $pdo->prepare($sql); $s->execute($a); return $s->fetchColumn(); })();
$today = date('Y-m-d');
$month = date('Y-m-01');

// ---- the period (top right): Today / Yesterday / Last 7 days / This month / Last month / Custom — like the store dashboard.
// Every "this period" figure is compared with the period just before it (This month: the same days of last month).
$ranges = ['today' => 'Today', 'yesterday' => 'Yesterday', '7days' => 'Last 7 Days', 'month' => 'This Month', 'prev_month' => 'Last Month'];
$range = (string)($_GET['range'] ?? 'today');
if ($range !== 'custom' && $range !== 'session' && !isset($ranges[$range])) $range = 'today';
// academic sessions (Institute → Sessions) with their dates, newest first: a session shows from its start to today (or its end)
$sessions = $pdo->query("SELECT id, name, start_date, end_date, is_current FROM ci_sessions WHERE start_date IS NOT NULL AND end_date IS NOT NULL ORDER BY start_date DESC")->fetchAll(PDO::FETCH_ASSOC);
$sess = null;
if ($range === 'session') { foreach ($sessions as $x) if ((int)$x['id'] === (int)($_GET['sid'] ?? 0)) $sess = $x; if (!$sess) $range = 'today'; }
$isDate = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d);
$d = fn(string $s, string $mod = '') => date('Y-m-d', strtotime($s . ($mod !== '' ? ' ' . $mod : '')));
switch ($range) {
    case 'today': $from = $to = $today; $pFrom = $pTo = $d($today, '-1 day'); break;
    case 'yesterday': $from = $to = $d($today, '-1 day'); $pFrom = $pTo = $d($today, '-2 day'); break;
    case '7days': $from = $d($today, '-6 day'); $to = $today; $pFrom = $d($today, '-13 day'); $pTo = $d($today, '-7 day'); break;
    case 'prev_month': $from = date('Y-m-01', strtotime('first day of last month')); $to = date('Y-m-t', strtotime('first day of last month'));
        $pFrom = date('Y-m-01', strtotime('first day of -2 month')); $pTo = date('Y-m-t', strtotime('first day of -2 month')); break;
    case 'session': // compared with the same days of the year before (the previous session)
        $from = $sess['start_date']; $to = $sess['start_date'] > $today ? $sess['end_date'] : min($sess['end_date'], $today);
        $pFrom = $d($from, '-1 year'); $pTo = $d($to, '-1 year'); break;
    case 'custom':
        $from = $isDate($_GET['from'] ?? null) ? $_GET['from'] : $month; $to = $isDate($_GET['to'] ?? null) ? $_GET['to'] : $today;
        if ($from > $to) [$from, $to] = [$to, $from];
        $len = (int)round((strtotime($to) - strtotime($from)) / 86400) + 1;
        $pTo = $d($from, '-1 day'); $pFrom = $d($pTo, '-' . ($len - 1) . ' day'); break;
    default: $from = $month; $to = $today; // This Month
        $pFrom = date('Y-m-01', strtotime('first day of last month'));
        $pTo = min(date('Y-m-t', strtotime('first day of last month')), date('Y-m-', strtotime('first day of last month')) . date('d')); // the same days of last month
}
$rangeLabel = $range === 'custom' ? date(substr($from, 0, 4) === substr($to, 0, 4) ? 'd M' : 'd M Y', strtotime($from)) . ' – ' . date('d M Y', strtotime($to)) : ($range === 'session' ? 'Session ' . $sess['name'] : $ranges[$range]);
$span = $from === $to ? date('d M Y', strtotime($from)) : date(substr($from, 0, 4) === substr($to, 0, 4) ? 'd M' : 'd M Y', strtotime($from)) . ' – ' . date('d M Y', strtotime($to)); // the year on both ends when they differ

$period = function (string $a, string $b) use ($one): array {
    return [
        'adm' => (int)$one("SELECT COUNT(*) FROM ci_students WHERE stype <> 'online' AND admission_date BETWEEN ? AND ?", [$a, $b]),
        'fee' => (float)$one("SELECT COALESCE(SUM(amount),0) FROM ci_payments WHERE paid_on BETWEEN ? AND ?", [$a, $b]),
    ];
};
$cur = $period($from, $to);
$prev = $period($pFrom, $pTo);
// fee received in the period by mode (Cash, UPI, Card, Bank transfer, Cheque, Other); a split payment counts each part where it belongs
$modes = array_fill_keys(array_keys(CI_METHODS), 0.0);
$q = $pdo->prepare("SELECT method, parts, amount FROM ci_payments WHERE paid_on BETWEEN ? AND ?"); $q->execute([$from, $to]);
foreach ($q as $r) {
    $parts = $r['method'] === 'Split' ? json_decode((string)$r['parts'], true) : null;
    if (!is_array($parts) || !$parts) $parts = [['m' => $r['method'], 'a' => $r['amount']]];
    foreach ($parts as $x) { $m = isset($modes[$x['m'] ?? '']) ? $x['m'] : 'Other'; $modes[$m] += (float)($x['a'] ?? 0); }
}
$stats = [ // now (not tied to the period)
    'active' => (int)$one("SELECT COUNT(*) FROM ci_students WHERE status = 'active' AND stype <> 'online'"),
    'online' => (int)$one("SELECT COUNT(*) FROM ci_students WHERE status = 'active' AND stype IN ('online','both')"),
    // running now only (not finished, not yet to start): a batch between its start and end date; a course with a running
    // batch or a student still studying it; a class with active students
    'batches' => (int)$one("SELECT COUNT(*) FROM ci_batches b WHERE b.status = 'active' AND (b.start_date IS NULL OR b.start_date <= CURDATE()) AND (b.end_date IS NULL OR b.end_date >= CURDATE())"),
    'courses' => (int)$one("SELECT COUNT(*) FROM ci_courses c WHERE c.status = 'active' AND (EXISTS (SELECT 1 FROM ci_batches b WHERE b.course_id = c.id AND b.status = 'active' AND (b.start_date IS NULL OR b.start_date <= CURDATE()) AND (b.end_date IS NULL OR b.end_date >= CURDATE()))
        OR EXISTS (SELECT 1 FROM ci_enrollments e JOIN ci_students s ON s.id = e.student_id WHERE e.course_id = c.id AND e.status = 'active' AND s.status = 'active'))"),
    'classes' => (int)$one("SELECT COUNT(DISTINCT class_id) FROM ci_students WHERE status = 'active' AND stype <> 'online' AND class_id IS NOT NULL"),
    'due' => (float)$one("SELECT COALESCE(SUM(" . CI_DUE_SQL . "),0) FROM ci_enrollments e"),
    'late' => (int)$one("SELECT COUNT(*) FROM ci_enrollments e WHERE e.status <> 'dropped' AND e.due_date IS NOT NULL AND e.due_date < CURDATE() AND " . CI_DUE_SQL . " > 0.004"),
];
// ---- Institute Overview: Running now (default) / Upcoming / Completed / All / one session — all counted here, picked in the page
$BW = [ // batches in each state
    'running' => "b.status = 'active' AND (b.start_date IS NULL OR b.start_date <= CURDATE()) AND (b.end_date IS NULL OR b.end_date >= CURDATE())",
    'upcoming' => "b.status = 'active' AND b.start_date > CURDATE()",
    'completed' => "(b.status = 'completed' OR (b.status = 'active' AND b.end_date < CURDATE()))",
    'all' => '1 = 1',
];
$OW = [ // online courses (as in Online Courses → Course Status)
    'running' => '(starts_at IS NULL OR starts_at < CURDATE()) AND (ends_at IS NULL OR ends_at >= CURDATE())',
    'upcoming' => 'starts_at >= CURDATE()', 'completed' => 'ends_at < CURDATE()', 'all' => '1 = 1',
];
$onl = function (string $w) use ($one): int { try { return (int)$one("SELECT COUNT(*) FROM sh_products WHERE type = 'course' AND status = 'published' AND $w"); } catch (Throwable) { return 0; } };
$ov = [];
foreach (['running' => 'Running now', 'upcoming' => 'Upcoming', 'completed' => 'Completed', 'all' => 'All'] as $k => $l) {
    $w = $BW[$k];
    $ov[$k] = ['label' => $l,
        'courses' => $k === 'running' ? $stats['courses'] : ($k === 'all' ? (int)$one("SELECT COUNT(*) FROM ci_courses") : (int)$one("SELECT COUNT(DISTINCT b.course_id) FROM ci_batches b WHERE $w")),
        'batches' => (int)$one("SELECT COUNT(*) FROM ci_batches b WHERE $w"),
        'classes' => $k === 'running' ? $stats['classes'] : ($k === 'all' ? (int)$one("SELECT COUNT(*) FROM ci_classes") : (int)$one("SELECT COUNT(DISTINCT s.class_id) FROM ci_enrollments e JOIN ci_batches b ON b.id = e.batch_id JOIN ci_students s ON s.id = e.student_id WHERE $w AND s.class_id IS NOT NULL")),
        'online' => $onl($OW[$k]),
        'links' => ['/admin/ci-courses.php', '/admin/ci-batches.php?status=' . ['running' => 'active', 'upcoming' => 'active', 'completed' => 'completed', 'all' => 'all'][$k], '/admin/ci-classes.php', '/admin/shop-products.php?batch=' . ($k === 'all' ? '' : $k)]];
}
foreach ($pdo->query("SELECT id, name, start_date, end_date, is_current FROM ci_sessions ORDER BY (start_date IS NULL), start_date DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC) as $x) {
    $sid = (int)$x['id']; $inS = "(b.session_id = $sid OR e.session_id = $sid)";
    $ov['s' . $sid] = ['label' => 'Session ' . $x['name'], 'session' => $x['name'], 'current' => (int)$x['is_current'],
        'courses' => (int)$one("SELECT COUNT(DISTINCT c) FROM (SELECT course_id c FROM ci_batches WHERE session_id = $sid UNION SELECT course_id FROM ci_enrollments WHERE session_id = $sid) t"),
        'batches' => (int)$one("SELECT COUNT(*) FROM ci_batches b WHERE b.session_id = $sid"),
        'classes' => (int)$one("SELECT COUNT(DISTINCT s.class_id) FROM ci_enrollments e LEFT JOIN ci_batches b ON b.id = e.batch_id JOIN ci_students s ON s.id = e.student_id WHERE $inS AND s.class_id IS NOT NULL"),
        'online' => $x['start_date'] && $x['end_date'] ? $onl('(starts_at IS NULL OR starts_at <= ' . $pdo->quote($x['end_date']) . ') AND (ends_at IS NULL OR ends_at >= ' . $pdo->quote($x['start_date']) . ')') : $onl('1 = 1'),
        'links' => ['/admin/ci-courses.php', '/admin/ci-batches.php?status=all&session=' . $sid, '/admin/ci-classes.php', '/admin/shop-products.php?batch=']];
}

$recentStudents = $pdo->query("SELECT s.*, (SELECT GROUP_CONCAT(c.name SEPARATOR ', ') FROM ci_enrollments e JOIN ci_courses c ON c.id = e.course_id WHERE e.student_id = s.id AND e.status <> 'dropped') AS courses
    FROM ci_students s WHERE s.stype <> 'online' ORDER BY s.id DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
$recentPays = $pdo->query("SELECT p.*, s.name, s.reg_no, s.photo, u.username, (SELECT " . CI_DUE_SQL . " FROM ci_enrollments e WHERE e.id = p.enrollment_id) AS due_now FROM ci_payments p JOIN ci_students s ON s.id = p.student_id LEFT JOIN users u ON u.id = p.created_by ORDER BY p.id DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
$upcoming = $pdo->query("SELECT e.id, e.due_date, s.id AS sid, s.name, s.mobile, s.photo, c.name AS course_name, " . CI_DUE_SQL . " AS due
    FROM ci_enrollments e JOIN ci_students s ON s.id = e.student_id LEFT JOIN ci_courses c ON c.id = e.course_id
    WHERE e.status <> 'dropped' AND " . CI_DUE_SQL . " > 0.004 ORDER BY (e.due_date IS NULL), e.due_date, due DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
$batches = $pdo->query("SELECT b.id, b.name, b.timing, b.capacity, c.name AS course_name, (SELECT COUNT(*) FROM ci_enrollments e WHERE e.batch_id = b.id AND e.status = 'active') AS n
    FROM ci_batches b LEFT JOIN ci_courses c ON c.id = b.course_id WHERE b.status = 'active' AND (b.start_date IS NULL OR b.start_date <= CURDATE()) AND (b.end_date IS NULL OR b.end_date >= CURDATE()) ORDER BY n DESC, b.name LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
$empty = !$stats['active'] && !$stats['courses'] && !$stats['batches'];

// ---- small pieces ----
$e = 'ci_e';
$TONE = ['green' => ['#1cc88a', '#e8f9f2', '#0f9f6e'], 'purple' => ['#7c3aed', '#f5f3ff', '#7c3aed'], 'red' => ['#e74a5b', '#fdecee', '#c81e32'], 'amber' => ['#f59e0b', '#fef6e7', '#b45309']];
$delta = function (float $now, float $before, bool $goodUp = true): string {
    if ($now == $before) { $dir = 'flat'; $txt = '0%'; }
    elseif ($before == 0.0) { $dir = 'up'; $txt = 'New'; }
    else { $p = (int)round(($now - $before) / $before * 100); $dir = $p > 0 ? 'up' : ($p < 0 ? 'down' : 'flat'); $txt = abs($p) . '%'; }
    $good = $dir === 'flat' || ($dir === 'up') === $goodUp;
    $ar = $dir === 'up' ? '<i class="fas fa-arrow-up"></i>' : ($dir === 'down' ? '<i class="fas fa-arrow-down"></i>' : '');
    return '<span class="db-delta"><span class="db-pill ' . ($good ? 'good' : 'bad') . '">' . $ar . $txt . '</span><span class="db-vs">vs. previous period</span></span>';
};
$card = function (string $tone, string $icon, string $label, string $value, string $foot, string $href) use ($TONE, $e): string {
    [$solid, $tint] = $TONE[$tone];
    return '<a class="db-stat" href="' . $e($href) . '" data-ds-item="' . $e($label) . '"><span class="db-disc" style="background:' . $tint . '"></span>'
        . '<span class="db-ic" style="background:' . $solid . '"><i class="fas ' . $icon . '"></i></span>'
        . '<span class="db-st"><span class="db-l">' . $e($label) . '</span><span class="db-v">' . $value . '</span><span class="db-f">' . $foot . '</span></span></a>';
};
$head = fn(string $icon, string $title, string $link = '', string $linkText = '', string $iconCls = '') => '<div class="db-h"><h2><span class="db-hi ' . $iconCls . '"><i class="fas ' . $icon . '"></i></span>' . $title . '</h2>'
    . ($link !== '' ? '<a class="db-all" data-ds-item="View all link" href="' . ci_e($link) . '">' . ci_e($linkText) . ' <i class="fas fa-chevron-right"></i></a>' : '') . '</div>';
$tile = function (string $tone, string $icon, string $label, string $value, string $href = '') use ($TONE, $e): string {
    [, $tint, $ink] = $TONE[$tone];
    $in = '<span class="db-ti" style="background:' . $tint . ';color:' . $ink . '"><i class="fas ' . $icon . '"></i></span><span><span class="db-tl">' . $e($label) . '</span><span class="db-tv"' . ($href ? ' style="color:' . $ink . '"' : '') . '>' . $value . '</span></span>';
    return $href !== '' ? '<a class="db-tile db-tile-o" data-ds-item="' . $e($label) . '" href="' . $e($href) . '">' . $in . '</a>' : '<div class="db-tile" data-ds-item="' . $e($label) . '">' . $in . '</div>';
};
// money on the chart's side: ₹500, ₹20k, ₹1.5L, ₹2Cr
$short = function (float $v): string { $t = fn($n) => rtrim(rtrim(number_format($n, 1, '.', ''), '0'), '.'); return $v >= 1e7 ? '₹' . $t($v / 1e7) . 'Cr' : ($v >= 1e5 ? '₹' . $t($v / 1e5) . 'L' : ($v >= 1e3 ? '₹' . $t($v / 1e3) . 'k' : '₹' . (int)round($v))); };
$q = fn(array $x) => '/admin/dashboard.php?' . http_build_query($x);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Dashboard - <?= ci_e(edu_site_name()) ?></title>
<?php admin_shell_head(); ci_head(); ?>
<style>
/* Dashboard — same look as the store's admin dashboard: white cards, coloured icon squares, a pale disc in the corner,
   big numbers with "vs. previous period", section cards with an icon title and "View all ›". */
.db .sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
.db{--db-b:#e5e7eb;--db-t:#111827;--db-m:#6b7280;--db-p:#7c3aed}
.db-card{background:#fff;border:1px solid var(--db-b);border-radius:5px!important;box-shadow:0 1px 2px rgba(16,24,40,.04)!important}
.db-bar{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;margin-bottom:20px}
.db-hello b{display:block;font-size:17px;color:var(--db-t)}.db-hello span{font-size:13.5px;color:var(--db-m)}
.db-seg{display:flex;align-items:center;gap:2px;padding:4px;background:#fff;border:1px solid var(--db-b);border-radius:5px!important;max-width:100%;overflow-x:auto;scrollbar-width:none}
.db-seg::-webkit-scrollbar{display:none}
.db-seg a,.db-seg summary{flex:none;display:inline-flex;align-items:center;gap:6px;height:32px;padding:0 12px;border-radius:5px!important;font-size:13.5px;font-weight:500;color:#374151;text-decoration:none;cursor:pointer;white-space:nowrap;list-style:none}
.db-seg summary::-webkit-details-marker{display:none}
.db-seg a:hover,.db-seg summary:hover{background:#f3f4f6}
.db-seg a.on,.db-seg details.on>summary{background:var(--db-p);color:#fff}
.db-seg details{position:static}
.db-cust{position:absolute;z-index:30;top:100%;margin-top:8px;right:0;width:230px;padding:12px;background:#fff;border:1px solid var(--db-b);border-radius:5px!important;box-shadow:0 12px 32px rgba(16,24,40,.12)!important}
.db-segw{display:flex;align-items:center;gap:8px;min-width:0;max-width:100%}
.db-seg{min-width:0}
.db-custw{position:relative!important;flex:none}
.db-custw>summary{display:inline-flex;align-items:center;gap:6px;height:42px;padding:0 14px;background:#fff;border:1px solid var(--db-b);border-radius:5px!important;font-size:13.5px;font-weight:500;color:#374151;cursor:pointer;white-space:nowrap;list-style:none}
.db-custw>summary::-webkit-details-marker{display:none}
.db-custw>summary i{color:var(--db-p)}
.db-custw.on>summary{background:var(--db-p);border-color:var(--db-p);color:#fff}.db-custw.on>summary i{color:#fff}
.db-cust p{margin:0 0 8px;font-size:11px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#9ca3af}
.db-cust label{display:block;margin-bottom:8px;font-size:12px;color:var(--db-m)}
.db-cust input{display:block;width:100%;margin-top:4px;padding:6px 9px;border:1px solid var(--db-b);border-radius:5px!important;font:inherit;font-size:13.5px}
.db-cust button{width:100%;margin-top:4px;padding:8px;border:0;border-radius:5px!important;background:var(--db-p);color:#fff;font-weight:600;cursor:pointer}
.db-stats{display:flex;flex-wrap:wrap;gap:20px;margin-bottom:20px}.db-stats>.db-stat{flex:1 1 200px;min-width:0} /* hidden cards leave no hole: the others grow */
.db-stat{position:relative;display:flex;gap:16px;padding:20px;overflow:hidden;background:#fff;border:1px solid var(--db-b);border-radius:5px!important;box-shadow:0 1px 2px rgba(16,24,40,.04)!important;text-decoration:none;color:inherit;transition:transform .15s,box-shadow .15s}
.db-stat:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(16,24,40,.08)!important}
.db-disc{position:absolute;right:-30px;top:-30px;width:100px;height:100px;border-radius:50%!important;pointer-events:none}
.db-ic{position:relative;flex:none;display:flex;align-items:center;justify-content:center;width:52px;height:52px;border-radius:5px!important;color:#fff;font-size:21px}
.db-st{position:relative;min-width:0}
.db-l{display:block;font-size:14.5px;font-weight:500;color:#374151}
.db-v{display:block;margin-top:4px;font-size:27px;font-weight:700;line-height:1.15;color:var(--db-t)}
.db-f{display:block;margin-top:8px;font-size:13px;color:var(--db-m)}
.db-delta{display:flex;flex-wrap:wrap;align-items:center;gap:4px 8px}
.db-pill{display:inline-flex;align-items:center;gap:4px;padding:2px 6px;border-radius:5px!important;font-size:12px;font-weight:600;white-space:nowrap}
.db-pill i{font-size:9.5px}.db-pill.good{background:#ecfdf5;color:#047857}.db-pill.bad{background:#fef2f2;color:#b91c1c}
.db-vs{font-size:13px;color:var(--db-m);white-space:nowrap}
.db-row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px;margin-bottom:20px} /* Payment Modes | Institute Overview: same width */
.db-row3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:20px;margin-bottom:20px}
.db-sec{padding:24px}
.db .db-sec{overflow:visible} /* the Institute Overview menu may hang below its card */
.db-tag{margin-left:2px;padding:2px 9px;border-radius:5px!important;background:#f3f4f6;color:#4b5563;font-size:12px;font-weight:600;white-space:nowrap}
.db-ovf{position:relative;margin-left:6px;font-size:12px}
.db-ovf>summary{display:inline-flex;align-items:center;gap:8px;height:32px;padding:0 10px;border:1px solid #d1d5db;border-radius:5px!important;background:#fff;color:#374151;font-size:13.5px;font-weight:500;cursor:pointer;list-style:none;white-space:nowrap} /* like the other drop-downs */
.db-ovf>summary i{color:#9ca3af}.db-ovf>summary:hover{border-color:#9ca3af}.db-ovf[open]>summary{border-color:#7c3aed}
.db-ovf>summary::-webkit-details-marker{display:none}.db-ovf>summary i{font-size:9px;transition:transform .15s}.db-ovf[open]>summary i{transform:rotate(180deg)}
.db-ovf.other>summary{color:#7c3aed;border-color:#c4b5fd}
.db-ovf-m{position:absolute;left:0;top:calc(100% + 6px);z-index:40;min-width:200px;max-height:320px;overflow-y:auto;padding:6px;background:#fff;border:1px solid #e5e7eb;border-radius:5px!important;box-shadow:0 12px 32px rgba(16,24,40,.14)!important}
.db-ovf-m .db-ovf-sb{justify-content:space-between;margin-top:4px;border-top:1px solid #f3f4f6!important;border-radius:0!important}
.db-ovf-m .db-ovf-sb::after{content:"";width:6px;height:6px;border:solid #9ca3af;border-width:0 2px 2px 0;transform:rotate(45deg);margin-top:-3px}
.db-ovf-m .db-ovf-sb[aria-expanded=true]::after{transform:rotate(-135deg);margin-top:3px}
.db-ovf-m .db-ovf-sb.on{color:#7c3aed!important;font-weight:600}
.db-ovf-sl{padding-left:10px}.db-ovf-sl[hidden]{display:none}
@media (max-width:640px){.db .db-sec{position:relative}.db-ovf{position:static}.db-ovf-m{left:12px;right:12px;top:54px;width:auto;min-width:0}} /* phones: the menu opens across its card, under the heading */
.db-ovf-m p{margin:6px 0 2px;padding:8px 10px 2px;border-top:1px solid #f3f4f6;font-size:11px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#9ca3af}
.db-ovf-m button{display:flex;align-items:center;width:100%;padding:8px 10px;border:0;border-radius:5px!important;background:none!important;color:#374151!important;font:inherit;font-size:14px;font-weight:400;text-align:left;cursor:pointer;min-height:0;height:auto;box-shadow:none!important}
.db-ovf-m button:hover{background:#f3f4f6!important}.db-ovf-m button.on{background:#f5f3ff!important;color:#7c3aed!important;font-weight:600}
.db-ovf-m button.on::after{content:"";width:10px;height:5px;margin:-3px 2px 0 auto;border:solid #7c3aed;border-width:0 0 2px 2px;transform:rotate(-45deg)}
.db-ovf-m button .db-rng-cur{margin-left:8px}
.db-run{margin-left:2px;padding:2px 8px;border-radius:5px!important;background:#ecfdf5;color:#047857;font-size:11.5px;font-weight:600}
.db-one{margin-bottom:20px}
.db-h{display:flex;align-items:center;justify-content:space-between;gap:12px;min-height:32px;margin-bottom:20px} /* same height in every card, so the tiles of side-by-side cards line up */
.db-h h2{display:flex;align-items:center;gap:12px;margin:0;font-size:17px;font-weight:600;color:var(--db-t)}
.db-hi{display:inline-flex;align-items:center;justify-content:center;font-size:18px;color:#4b5563}
.db-hi.dot{width:32px;height:32px;border-radius:50%!important;background:#1cc88a;color:#fff;font-size:14px}
.db-all{display:inline-flex;align-items:center;gap:7px;font-size:14px;font-weight:500;color:var(--db-p);text-decoration:none;white-space:nowrap}
.db-all i{font-size:10.5px}
.db-tiles{display:flex;flex-wrap:wrap;gap:16px}.db-tiles>.db-tile{flex:1 1 calc(50% - 8px);min-width:0} /* two a row; a single one takes the row */
@media (min-width:1500px){.db-tiles3{grid-template-columns:repeat(3,minmax(0,1fr))}}
.db-tile{display:flex;align-items:center;gap:14px;padding:16px;border:1px solid var(--db-b);border-radius:5px!important;text-decoration:none;color:inherit}
.db-tile-o:hover{background:#f9fafb}
.db-ti{flex:none;display:flex;align-items:center;justify-content:center;width:44px;height:44px;border-radius:5px!important;font-size:18px}
.db-tl{display:block;font-size:13.5px;color:var(--db-m)}
.db-tv{display:block;margin-top:2px;font-size:22px;font-weight:700;line-height:1.2;color:var(--db-t)}
.db-tbl{width:100%;border-collapse:collapse;font-size:14px}
.db-tbl th{padding:0 14px 12px 0;text-align:left;font-size:12px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:#9ca3af;white-space:nowrap}
.db-tbl td{padding:12px 14px 12px 0;border-top:1px solid #f3f4f6;vertical-align:middle;color:#374151}
.db-tbl td:last-child,.db-tbl th:last-child{padding-right:0;text-align:right}
.db-tbl a.db-rc{font-weight:600;color:var(--db-p);text-decoration:none;white-space:nowrap}
.db-tbl .db-amt{font-weight:600;color:var(--db-t);white-space:nowrap}
.db-tbl .db-who{max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.db-tbl .db-who a{color:var(--db-t);font-weight:500;text-decoration:none}
.db-mode{display:inline-block;padding:2px 8px;border-radius:5px!important;font-size:12px;font-weight:600;background:#f3f4f6;color:#374151;white-space:nowrap}
.db-due{display:inline-flex;align-items:center;gap:8px;padding:4px 4px 4px 10px;border:1px solid #fecaca;border-radius:5px!important;background:#fef2f2;color:#b91c1c;font:inherit;font-size:13px;font-weight:700;white-space:nowrap;cursor:pointer}
.db-due.is-ro{padding:4px 10px;cursor:default}
.db-due-c{display:inline-flex;align-items:center;gap:5px;padding:3px 8px;border-radius:4px!important;background:#dc2626;color:#fff;font-size:12px}.db-due-c i{font-size:10px}
.db-due:hover .db-due-c{background:#b91c1c}
.db-full{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border:1px solid #bbf7d0;border-radius:5px!important;background:#f0fdf4;color:#15803d;font-size:13px;font-weight:700;white-space:nowrap}.db-full i{font-size:11px}
.db-mode.cash{background:#ecfdf5;color:#047857}.db-mode.on{background:#f5f3ff;color:#6d28d9}
.db-pr{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border:1px solid var(--db-b);border-radius:5px!important;color:#4b5563}
.db-pr:hover{background:#f9fafb}.db-pr svg{width:15px;height:15px}
.db-scroll{position:relative;overflow-x:auto;border-top:1px solid #f3f4f6;padding-top:16px}
.db-emp{display:flex;flex-direction:column;align-items:center;padding:28px 10px;text-align:center}
.db-emp span{display:flex;align-items:center;justify-content:center;width:64px;height:64px;margin-bottom:12px;border-radius:50%!important;background:#f3f4f6;color:#9ca3af;font-size:24px}
.db-emp b{font-size:15px;color:var(--db-t)}.db-emp small{margin-top:4px;font-size:13.5px;color:var(--db-m)}
/* lists */
.db-list{list-style:none;margin:0;padding:0;border-top:1px solid #f3f4f6}
.db-list li{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 0;border-bottom:1px solid #f3f4f6}
.db-list li:last-child{border-bottom:0;padding-bottom:0}
.db-list .db-p{display:flex;align-items:center;gap:12px;min-width:0}
.db-av{flex:none;display:flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:50%!important;font-size:13px;font-weight:700;overflow:hidden}
.db-av img{width:100%;height:100%;object-fit:cover}
.db-list .db-n{min-width:0}.db-list .db-n a{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:600;color:var(--db-t);text-decoration:none}
.db-list .db-n small{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12.5px;color:var(--db-m)}
.db-list .db-r{flex:none;text-align:right;font-size:13px;color:var(--db-m)}
.db-list .db-r b{display:block;font-size:14px;color:var(--db-t)}
.db-late{color:#c81e32!important}
.db-meter{width:110px;height:6px;margin-top:6px;margin-left:auto;border-radius:3px!important;background:#f3f4f6;overflow:hidden}
.db-meter i{display:block;height:100%;border-radius:3px!important;background:var(--db-p)}
.db-start{padding:20px 24px;margin-bottom:20px}.db-start ol{margin:8px 0 0 18px;padding:0;line-height:1.9}
/* a card hidden from Display options: the other cards of its row take the room */
@media (min-width:1280px){.db-row:has(>.dsp-hide){grid-template-columns:minmax(0,1fr)}.db-row3:has(>.dsp-hide){grid-template-columns:repeat(2,minmax(0,1fr))}.db-row3:has(>.dsp-hide~.dsp-hide){grid-template-columns:minmax(0,1fr)}.db-row3:has(>.dsp-hide)>:last-child{grid-column:auto}}
.db-row:not(:has(>:not(.dsp-hide))),.db-row3:not(:has(>:not(.dsp-hide))){display:none}
@media (max-width:1279px){.db-stats>.db-stat{flex-basis:calc(50% - 10px)}.db-row,.db-row3{grid-template-columns:minmax(0,1fr)}}
@media (min-width:1280px) and (max-width:1499px){.db-row3{grid-template-columns:repeat(2,minmax(0,1fr))}.db-row3>:last-child{grid-column:1/-1}}
@media (max-width:640px){
  .db-stats{gap:12px}.db-stat{flex-direction:column;gap:10px;padding:14px}.db-ic{width:40px;height:40px;font-size:16px;border-radius:5px!important}
  .db-l{font-size:13px}.db-v{font-size:21px}.db-vs{display:none}.db-f{margin-top:6px;font-size:12px}
  .db-sec{padding:16px}.db-h{margin-bottom:14px}.db-h h2{font-size:16px}.db-tiles{gap:10px}.db-tile{flex-direction:column;align-items:flex-start;gap:8px;padding:12px}.db-tv{font-size:19px}
  .db-row,.db-row3{gap:14px;margin-bottom:14px}.db-bar{margin-bottom:14px}.db-seg{width:100%}
}
/* period menu in the header */
.db-rng{position:relative}
.db-rng>summary{border-radius:5px!important;display:inline-flex;align-items:center;gap:8px;height:36px;padding:0 12px;border:1px solid #d1d5db;background:#fff;color:#374151;font-size:14px;font-weight:500;cursor:pointer;list-style:none;white-space:nowrap}
.db-rng>summary::-webkit-details-marker{display:none}
.db-rng>summary>.fa-calendar-days{color:#7c3aed}.db-rng-c{font-size:10px;color:#9ca3af;transition:transform .15s}.db-rng[open] .db-rng-c{transform:rotate(180deg)}
.db-rng-m{position:absolute;right:0;top:calc(100% + 6px);z-index:1000;width:240px;padding:6px;background:#fff;border:1px solid #e5e7eb;border-radius:5px!important;box-shadow:0 12px 32px rgba(16,24,40,.14)!important}
.db-rng-m a{display:flex;align-items:center;justify-content:space-between;padding:8px 10px;border-radius:5px!important;font-size:14px;color:#374151;text-decoration:none}
.db-rng-m a:hover{background:#f3f4f6}.db-rng-m a.on{background:#f5f3ff;color:#7c3aed;font-weight:600}.db-rng-m a i{font-size:12px}
.db-rng-cb{display:flex;align-items:center;justify-content:space-between;width:100%;margin-top:4px;padding:8px 10px;border:0;border-top:1px solid #f3f4f6;border-radius:0!important;background:none;font:inherit;font-size:14px;color:#374151;text-align:left;cursor:pointer}
.db-rng-cb::after{content:"";width:6px;height:6px;border:solid #9ca3af;border-width:0 2px 2px 0;transform:rotate(45deg);margin-top:-3px;transition:transform .15s}
.db-rng-cb[aria-expanded=true]::after{transform:rotate(-135deg);margin-top:3px}
.db-rng-m .db-rng-cb{background:none!important;color:#374151!important;box-shadow:none!important;min-height:0;height:auto;font-weight:400}
.db-rng-m .db-rng-cb:hover,.db-rng-m .db-rng-cb[aria-expanded=true]{background:#f3f4f6!important}
.db-rng-m .db-rng-cb.on{color:#7c3aed!important;font-weight:600}
.db-rng-m form{margin:0;padding:6px 6px 4px}.db-rng-m form[hidden]{display:none}
.db-rng-sl{padding:2px 0 4px 10px}.db-rng-sl[hidden]{display:none}.db-rng-sl a{font-size:13.5px!important}
.db-rng-cur{margin-left:6px;margin-right:auto;padding:1px 7px;border-radius:5px!important;background:#ecfdf5;color:#047857;font-size:11px;font-weight:600}
.db-rng-m{max-height:calc(100vh - 140px);overflow-y:auto}
.db-rng-m p{margin:0 0 8px;font-size:11px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#9ca3af}
.db-rng-m label{display:block;margin-bottom:8px;font-size:12px;color:#6b7280}
.db-rng-m input{display:block;width:100%;box-sizing:border-box;margin-top:4px;padding:6px 9px;border:1px solid #e5e7eb;border-radius:5px!important;font:inherit;font-size:13.5px}
.db-rng-m button{width:100%;padding:8px;border:0;border-radius:5px!important;background:#7c3aed;color:#fff;font-weight:600;cursor:pointer}
.db-rng-m a.on::after{content:"";width:10px;height:5px;margin:-3px 2px 0 0;border:solid #7c3aed;border-width:0 0 2px 2px;transform:rotate(-45deg)}
@media (max-width:640px){.db-rng>summary{width:38px;height:38px;padding:0;justify-content:center;gap:0;font-size:15px}.db-rng-l,.db-rng-c{display:none!important}.db-rng-m{position:fixed;left:12px;right:12px;top:auto;width:auto}}
/* the admin is flat (square, no shadows); the dashboard has the store dashboard's soft cards */
.db .db-tbl th{background:transparent;border:0}
@media (max-width:640px){.db-segw{width:100%}.db-seg{flex:1}.db-custw>summary{height:42px;padding:0 12px}}
</style>
</head>
<body>
<?php
$rng = '<details class="db-rng" data-ds-item="Period filter"><summary aria-label="Period: ' . $e($rangeLabel) . '"><i class="fas fa-calendar-days"></i><span class="db-rng-l">' . $e($rangeLabel) . '</span><i class="fas fa-chevron-down db-rng-c"></i></summary><div class="db-rng-m" role="menu">';
foreach ($ranges as $k => $l) $rng .= '<a role="menuitem" href="' . $e($q(['range' => $k])) . '"' . ($range === $k ? ' class="on" aria-current="true"' : '') . '>' . $e($l) . '</a>'; // (the tick is drawn by CSS: an icon inside would make the header turn the link into an icon-only button on phones)
if ($sessions) { $sOn = $range === 'session';
    $rng .= '<button type="button" class="db-rng-cb db-rng-sb' . ($sOn ? ' on' : '') . '" aria-expanded="' . ($sOn ? 'true' : 'false') . '">' . ($sOn ? 'Session ' . $e($sess['name']) : 'Session') . '</button><div class="db-rng-sl"' . ($sOn ? '' : ' hidden') . '>';
    foreach ($sessions as $x) { $on = $range === 'session' && (int)$sess['id'] === (int)$x['id'];
        $rng .= '<a role="menuitem" href="' . $e($q(['range' => 'session', 'sid' => (int)$x['id']])) . '"' . ($on ? ' class="on" aria-current="true"' : '') . '>' . $e($x['name']) . ($x['is_current'] ? ' <small class="db-rng-cur">Current</small>' : '') . '</a>'; }
    $rng .= '</div>'; }
$rng .= '<button type="button" class="db-rng-cb db-rng-cc' . ($range === 'custom' ? ' on' : '') . '" aria-expanded="' . ($range === 'custom' ? 'true' : 'false') . '">Custom range</button>'
    . '<form method="get" action="/admin/dashboard.php"' . ($range === 'custom' ? '' : ' hidden') . '><input type="hidden" name="range" value="custom">'
    . '<label>From<input type="date" name="from" value="' . $e($from) . '" max="' . $today . '" required></label><label>To<input type="date" name="to" value="' . $e($to) . '" max="' . $today . '" required></label>'
    . '<button type="submit">Apply</button></form></div></details>';
admin_shell_open('Dashboard', $range === 'custom' ? $span : $rangeLabel . ' · ' . $span, '<div class="ci-top-actions" data-ds="Top buttons" data-ds-section data-ds-extra>' . $rng
    . (ci_perm($me, 'add_student') ? '<a class="ci-btn" data-ds-item="Add Student" href="/admin/student-edit.php"><i class="fas fa-user-plus"></i> Add Student</a>' : '')
    . (ci_perm($me, 'fees') ? '<a class="ci-btn ci-ghost" data-ds-item="Collect Fee" href="/admin/ci-dues.php?period=all"><i class="fas fa-indian-rupee-sign"></i> Collect Fee</a>' : '')
    . (ci_perm($me, 'batches') ? '<a class="ci-btn ci-ghost" data-ds-item="New Batch" href="/admin/ci-batches.php?new=1"><i class="fas fa-layer-group"></i> New Batch</a>' : '') . '</div>'); ?>
<div class="ci db">
<?= admin_flash() ?>

<?php if ($empty): ?>
<div class="db-card db-start"><b>Getting started</b><ol>
  <li>Check the academic session and class list in <a href="/admin/ci-sessions.php">Sessions</a> and <a href="/admin/ci-classes.php">Classes</a>.</li>
  <li>Add your <a href="/admin/ci-courses.php?new=1">courses</a> with their fee.</li>
  <li>Create <a href="/admin/ci-batches.php?new=1">batches</a> (timing, teacher, seats).</li>
  <li><a href="/admin/student-edit.php">Add students</a> and take the fee — a receipt is made for every payment.</li>
</ol></div>
<?php endif; ?>

<div class="db-stats" data-ds="Summary" data-ds-section>
  <?= $card('green', 'fa-indian-rupee-sign', 'Fee Collected', ci_money0($cur['fee']), $delta($cur['fee'], $prev['fee']), '/admin/ci-fees.php?' . http_build_query(['period' => 'custom', 'from' => $from, 'to' => $to])) ?>
  <?= $card('purple', 'fa-user-plus', 'New Admissions', number_format($cur['adm']), $delta($cur['adm'], $prev['adm']), '/admin/students.php?' . http_build_query(['status' => 'all', 'from' => $from, 'to' => $to])) ?>
  <?= $card('purple', 'fa-user-graduate', 'Active Students', number_format($stats['active']), $stats['online'] ? number_format($stats['online']) . ' online students' : 'Studying at the institute', '/admin/students.php') ?>
  <?= $card('red', 'fa-hourglass-half', 'Total Due', ci_money0($stats['due']), 'Still to be collected', '/admin/ci-dues.php?period=all') ?>
  <?= $card($stats['late'] ? 'red' : 'green', 'fa-calendar-xmark', 'Due Date Passed', number_format($stats['late']), $stats['late'] ? 'Students to remind' : 'Nobody is late', '/admin/ci-dues.php?period=all&late=1') ?>
</div>

<div class="db-row" data-ds-wrap>
  <section class="db-card db-sec" data-ds="Payment Modes" data-ds-section>
    <?= $head('fa-wallet', 'Payment Modes <small class="db-tag" data-ds-item="Period label">' . $e($rangeLabel) . '</small>', '/admin/ci-fees.php?' . http_build_query(['period' => 'custom', 'from' => $from, 'to' => $to]), 'View all', 'dot') ?>
    <div class="db-tiles">
      <?php $mi = ['Cash' => ['green', 'fa-money-bill-wave'], 'UPI' => ['purple', 'fa-mobile-screen-button'], 'Bank' => ['green', 'fa-building-columns'], 'Cheque' => ['purple', 'fa-money-check'], 'Card' => ['green', 'fa-credit-card'], 'Other' => ['purple', 'fa-wallet']];
      foreach ($mi as $m => [$tn, $ic]) if (in_array($m, ['Cash', 'UPI', 'Bank', 'Cheque'], true) || $modes[$m] > 0) echo $tile($tn, $ic, CI_METHODS[$m], ci_money0($modes[$m])); ?>
    </div>
  </section>
  <section class="db-card db-sec" data-ds="Institute Overview" data-ds-section>
    <?php $ovm = '<details class="db-ovf" data-ds-item="Overview filter"><summary><span class="db-ovf-l">Running now</span><i class="fas fa-chevron-down"></i></summary><div class="db-ovf-m" role="menu">';
    foreach ($ov as $k => $o) { if ($k[0] === 's' && !isset($sHead)) { $sHead = 1; $ovm .= '<button type="button" class="db-ovf-sb" aria-expanded="false">Session</button><div class="db-ovf-sl" hidden>'; }
        $ovm .= '<button type="button" role="menuitemradio" data-ov="' . $e($k) . '">' . $e($o['session'] ?? $o['label']) . (!empty($o['current']) ? ' <small class="db-rng-cur">Current</small>' : '') . '</button>'; }
    $ovm .= (isset($sHead) ? '</div>' : '') . '</div></details>'; ?>
    <?= $head('fa-chart-simple', 'Institute Overview ' . $ovm) ?>
    <div class="db-tiles" id="dbOv" data-ov='<?= $e(json_encode($ov, JSON_UNESCAPED_UNICODE)) ?>'>
      <?= $tile('green', 'fa-book', 'Courses', number_format($ov['running']['courses']), '/admin/ci-courses.php') ?>
      <?= $tile('purple', 'fa-layer-group', 'Batches', number_format($ov['running']['batches']), $ov['running']['links'][1]) ?>
      <?= $tile('purple', 'fa-chalkboard', 'Classes', number_format($ov['running']['classes']), '/admin/ci-classes.php') ?>
      <?= $tile('green', 'fa-photo-film', 'Online Courses', number_format($ov['running']['online']), $ov['running']['links'][3]) ?>
    </div>
  </section>
</div>

<section class="db-card db-sec db-one" data-ds="Recent Payments" data-ds-section>
    <?= $head('fa-file-lines', 'Recent Payments', '/admin/ci-fees.php?period=month', 'View all') ?>
    <?php if (!$recentPays): ?><div class="db-emp" style="border-top:1px solid #f3f4f6"><span><i class="fas fa-file-lines"></i></span><b>No payments yet</b><small>Fee payments will appear here once you take them</small></div>
    <?php else: ?><div class="db-scroll"><table class="db-tbl"><thead><tr><th>Receipt</th><th>Student</th><th>Amount</th><th>Mode</th><th>Date</th><th>Status</th><th><span class="sr-only">Print</span></th></tr></thead><tbody>
      <?php foreach ($recentPays as $p): $mode = ci_pay_mode($p, false); ?><tr>
        <td><a class="db-rc" href="/admin/ci-receipt.php?id=<?= (int)$p['id'] ?>" target="_blank"><?= $e($p['receipt_no']) ?></a></td>
        <td class="db-who"><a href="/admin/student-view.php?id=<?= (int)$p['student_id'] ?>" title="<?= $e($p['name']) ?>"><?= $e($p['name']) ?></a></td>
        <td class="db-amt"><?= ci_money0($p['amount']) ?></td>
        <td><span class="db-mode <?= $p['method'] === 'Cash' ? 'cash' : 'on' ?>"><?= $e($mode) ?></span></td>
        <td style="white-space:nowrap;color:#6b7280"><?= ci_date($p['paid_on']) ?></td>
        <td><?php // the course of this payment now: still due (collect it here) or fully paid
          if ($p['due_now'] === null) echo '<span style="color:#9ca3af">—</span>';
          elseif ((float)$p['due_now'] > 0.004) echo ci_perm($me, 'fees') ? '<button type="button" class="db-due" data-collect-sid="' . (int)$p['student_id'] . '" data-eid="' . (int)$p['enrollment_id'] . '" title="Collect the due">' . ci_money0($p['due_now']) . ' due <span class="db-due-c"><i class="fas fa-indian-rupee-sign"></i> Collect</span></button>' : '<span class="db-due is-ro">' . ci_money0($p['due_now']) . ' due</span>';
          else echo '<span class="db-full"><i class="fas fa-check"></i> Full Paid</span>'; ?></td>
        <td><a class="db-pr" href="/admin/ci-receipt.php?id=<?= (int)$p['id'] ?>" target="_blank" aria-label="Print receipt <?= $e($p['receipt_no']) ?>"><?= ci_svg('print') ?></a></td>
      </tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
</section>

<?php $av = function (array $s, int $k) use ($e): string { $c = [['#f5f3ff', '#7c3aed'], ['#e8f9f2', '#0f9f6e'], ['#fdecee', '#c81e32'], ['#fef6e7', '#b45309']][$k % 4];
    $ini = mb_strtoupper(mb_substr(trim((string)$s['name']), 0, 1));
    return '<span class="db-av" data-ds-item="Photo" style="background:' . $c[0] . ';color:' . $c[1] . '">' . (!empty($s['photo']) ? '<img src="' . $e('/' . ltrim((string)$s['photo'], '/')) . '" alt="" loading="lazy">' : $e($ini)) . '</span>'; }; ?>
<div class="db-row3" data-ds-wrap>
  <section class="db-card db-sec" data-ds="New Admissions" data-ds-section>
    <?= $head('fa-user-plus', 'New Admissions', '/admin/students.php?status=all', 'View all') ?>
    <?php if (!$recentStudents): ?><div class="db-emp" style="border-top:1px solid #f3f4f6"><span><i class="fas fa-user-graduate"></i></span><b>No students yet</b><small>New admissions will appear here</small></div><?php else: ?>
    <ul class="db-list"><?php foreach ($recentStudents as $k => $s): ?><li><div class="db-p"><?= $av($s, $k) ?><div class="db-n"><a href="/admin/student-view.php?id=<?= (int)$s['id'] ?>"><?= $e($s['name']) ?></a><small data-ds-item="Course"><?= $s['courses'] ? $e($s['courses']) : '&nbsp;' ?></small></div></div><span class="db-r" data-ds-item="Admission date"><?= ci_date($s['admission_date']) ?></span></li><?php endforeach; ?></ul><?php endif; ?>
  </section>
  <section class="db-card db-sec" data-ds="Fee to Collect" data-ds-section>
    <?= $head('fa-hand-holding-dollar', 'Fee to Collect', '/admin/ci-dues.php?period=all', 'View all') ?>
    <?php if (!$upcoming): ?><div class="db-emp" style="border-top:1px solid #f3f4f6"><span><i class="fas fa-circle-check"></i></span><b>No dues</b><small>Everyone has paid</small></div><?php else: ?>
    <ul class="db-list"><?php foreach ($upcoming as $k => $u): $late = $u['due_date'] && $u['due_date'] < $today; ?><li><div class="db-p"><?= $av($u, $k + 2) ?><div class="db-n"><a href="/admin/student-view.php?id=<?= (int)$u['sid'] ?>#pay"><?= $e($u['name']) ?></a><small><span data-ds-item="Course"><?= $e($u['course_name']) ?></span><span data-ds-item="Mobile number"><span class="db-sep"> · </span><a href="tel:<?= $e($u['mobile']) ?>" style="display:inline;font-weight:500;color:inherit"><?= $e($u['mobile']) ?></a></span></small></div></div>
      <span class="db-r"><b style="color:#c81e32" data-ds-item="Due amount"><?= ci_money0($u['due']) ?></b><span data-ds-item="Due date"<?= $late ? ' class="db-late"' : '' ?>><?= $u['due_date'] ? ($late ? 'Late · ' : '') . ci_date($u['due_date']) : 'No date' ?></span></span></li><?php endforeach; ?></ul><?php endif; ?>
  </section>
  <section class="db-card db-sec" data-ds="Batches" data-ds-section>
    <?= $head('fa-layer-group', 'Batches', '/admin/ci-batches.php', 'View all') ?>
    <?php if (!$batches): ?><div class="db-emp" style="border-top:1px solid #f3f4f6"><span><i class="fas fa-layer-group"></i></span><b>No running batches</b><small>Create a batch to see seats filling here</small></div><?php else: ?>
    <ul class="db-list"><?php foreach ($batches as $b): ?><li><div class="db-n"><a href="/admin/students.php?batch=<?= (int)$b['id'] ?>"><?= $e($b['name']) ?></a><small data-ds-item="Course and timing"><?= $e(implode(' · ', array_filter([$b['course_name'], $b['timing']]))) ?></small></div>
      <span class="db-r"><b data-ds-item="Students / seats"><?= (int)$b['n'] ?><?= $b['capacity'] ? ' <span style="font-weight:400;color:#6b7280">/ ' . (int)$b['capacity'] . '</span>' : '' ?></b><?= $b['capacity'] ? '<div class="db-meter" data-ds-item="Seats bar"><i style="width:' . min(100, round($b['n'] / max(1, $b['capacity']) * 100)) . '%"></i></div>' : 'students' ?></span></li><?php endforeach; ?></ul><?php endif; ?>
  </section>
</div>

</div>
<script>
(function () { // Institute Overview filter: shows the counts of the choice at once; the choice is remembered in this browser
  var box = document.getElementById('dbOv'), d = document.querySelector('.db-ovf'); if (!box || !d) return;
  var data = JSON.parse(box.getAttribute('data-ov')), tiles = box.querySelectorAll('.db-tile'), keys = ['courses', 'batches', 'classes', 'online'];
  function pick(k, save) {
    if (!data[k]) k = 'running';
    var o = data[k];
    tiles.forEach(function (t, i) { var v = t.querySelector('.db-tv'); if (v) v.textContent = Number(o[keys[i]] || 0).toLocaleString('en-IN'); if (t.tagName === 'A' && o.links[i]) t.setAttribute('href', o.links[i]); });
    d.querySelector('.db-ovf-l').textContent = o.label; d.classList.toggle('other', k !== 'running');
    var sb = d.querySelector('.db-ovf-sb'), sl = d.querySelector('.db-ovf-sl'), isS = k.charAt(0) === 's';
    if (sb) { sb.classList.toggle('on', isS); sb.textContent = isS ? o.label : 'Session'; sl.hidden = !isS; sb.setAttribute('aria-expanded', isS ? 'true' : 'false'); }
    d.querySelectorAll('[data-ov]').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-ov') === k); b.setAttribute('aria-checked', b.getAttribute('data-ov') === k ? 'true' : 'false'); });
    if (save) try { localStorage.setItem('dbOv', k); } catch (e) {}
  }
  var saved = 'running'; try { saved = localStorage.getItem('dbOv') || 'running'; } catch (e) {}
  pick(saved, false);
  d.addEventListener('click', function (e) {
    var sb = e.target.closest('.db-ovf-sb'); if (sb) { var sl = d.querySelector('.db-ovf-sl'); sl.hidden = !sl.hidden; sb.setAttribute('aria-expanded', sl.hidden ? 'false' : 'true'); return; }
    var b = e.target.closest('[data-ov]'); if (!b) return; pick(b.getAttribute('data-ov'), true); d.removeAttribute('open'); });
  document.addEventListener('click', function (e) { if (d.open && !d.contains(e.target)) d.removeAttribute('open'); });
})();
(function () { // the period menu in the header closes on a click outside it or Escape
  var d = document.querySelector('.db-rng'); if (!d) return;
  // Session and Custom range open their own part of the menu (one at a time)
  var parts = [[d.querySelector('.db-rng-sb'), d.querySelector('.db-rng-sl')], [d.querySelector('.db-rng-cc'), d.querySelector('.db-rng-m form')]].filter(function (x) { return x[0] && x[1]; });
  parts.forEach(function (x) {
    x[0].addEventListener('click', function () {
      var open = x[1].hidden;
      parts.forEach(function (y) { y[1].hidden = true; y[0].setAttribute('aria-expanded', 'false'); });
      x[1].hidden = !open; x[0].setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) { var i = x[1].querySelector('input:not([type=hidden]), a'); if (i) i.focus(); }
    });
  });
  document.addEventListener('click', function (e) { if (d.open && !d.contains(e.target)) d.removeAttribute('open'); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && d.open) { d.removeAttribute('open'); d.querySelector('summary').focus(); } });
})();
</script>
<?php require __DIR__ . '/components/ci-modals.php'; // the Collect fee pop-up (same as Fees / Students) ?>
<?php admin_shell_close(); ?>
</body>
</html>
