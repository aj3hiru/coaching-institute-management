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
$range = (string)($_GET['range'] ?? 'month');
$isDate = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d);
$d = fn(string $s, string $mod = '') => date('Y-m-d', strtotime($s . ($mod !== '' ? ' ' . $mod : '')));
switch ($range) {
    case 'today': $from = $to = $today; $pFrom = $pTo = $d($today, '-1 day'); break;
    case 'yesterday': $from = $to = $d($today, '-1 day'); $pFrom = $pTo = $d($today, '-2 day'); break;
    case '7days': $from = $d($today, '-6 day'); $to = $today; $pFrom = $d($today, '-13 day'); $pTo = $d($today, '-7 day'); break;
    case 'prev_month': $from = date('Y-m-01', strtotime('first day of last month')); $to = date('Y-m-t', strtotime('first day of last month'));
        $pFrom = date('Y-m-01', strtotime('first day of -2 month')); $pTo = date('Y-m-t', strtotime('first day of -2 month')); break;
    case 'custom':
        $from = $isDate($_GET['from'] ?? null) ? $_GET['from'] : $month; $to = $isDate($_GET['to'] ?? null) ? $_GET['to'] : $today;
        if ($from > $to) [$from, $to] = [$to, $from];
        $len = (int)round((strtotime($to) - strtotime($from)) / 86400) + 1;
        $pTo = $d($from, '-1 day'); $pFrom = $d($pTo, '-' . ($len - 1) . ' day'); break;
    default: $range = 'month'; $from = $month; $to = $today;
        $pFrom = date('Y-m-01', strtotime('first day of last month'));
        $pTo = min(date('Y-m-t', strtotime('first day of last month')), date('Y-m-', strtotime('first day of last month')) . date('d')); // the same days of last month
}
$rangeLabel = $range === 'custom' ? date('d M', strtotime($from)) . ' – ' . date('d M Y', strtotime($to)) : $ranges[$range];
$span = $from === $to ? date('d M Y', strtotime($from)) : date('d M', strtotime($from)) . ' – ' . date('d M Y', strtotime($to));

$period = function (string $a, string $b) use ($one): array {
    return [
        'adm' => (int)$one("SELECT COUNT(*) FROM ci_students WHERE stype <> 'online' AND admission_date BETWEEN ? AND ?", [$a, $b]),
        'fee' => (float)$one("SELECT COALESCE(SUM(amount),0) FROM ci_payments WHERE paid_on BETWEEN ? AND ?", [$a, $b]),
        'rcp' => (int)$one("SELECT COUNT(*) FROM ci_payments WHERE paid_on BETWEEN ? AND ?", [$a, $b]),
        'charged' => (float)$one("SELECT COALESCE(SUM(GREATEST(fee - discount, 0)),0) FROM ci_enrollments WHERE status <> 'dropped' AND enrolled_on BETWEEN ? AND ?", [$a, $b]),
    ];
};
$cur = $period($from, $to);
$prev = $period($pFrom, $pTo);
// cash and online (UPI, card, bank …) received in the period; a split payment counts each part where it belongs
$cash = 0.0; $online = 0.0;
$q = $pdo->prepare("SELECT method, parts, amount FROM ci_payments WHERE paid_on BETWEEN ? AND ?"); $q->execute([$from, $to]);
foreach ($q as $r) {
    $parts = $r['method'] === 'Split' ? json_decode((string)$r['parts'], true) : null;
    if (is_array($parts) && $parts) { foreach ($parts as $x) { if (($x['m'] ?? '') === 'Cash') $cash += (float)($x['a'] ?? 0); else $online += (float)($x['a'] ?? 0); } }
    elseif ($r['method'] === 'Cash') $cash += (float)$r['amount']; else $online += (float)$r['amount'];
}
$stats = [ // now (not tied to the period)
    'active' => (int)$one("SELECT COUNT(*) FROM ci_students WHERE status = 'active' AND stype <> 'online'"),
    'online' => (int)$one("SELECT COUNT(*) FROM ci_students WHERE status = 'active' AND stype IN ('online','both')"),
    'batches' => (int)$one("SELECT COUNT(*) FROM ci_batches WHERE status = 'active'"),
    'courses' => (int)$one("SELECT COUNT(*) FROM ci_courses WHERE status = 'active'"),
    'classes' => (int)$one("SELECT COUNT(*) FROM ci_classes"),
    'due' => (float)$one("SELECT COALESCE(SUM(" . CI_DUE_SQL . "),0) FROM ci_enrollments e"),
    'late' => (int)$one("SELECT COUNT(*) FROM ci_enrollments e WHERE e.status <> 'dropped' AND e.due_date IS NOT NULL AND e.due_date < CURDATE() AND " . CI_DUE_SQL . " > 0.004"),
];
$store = 0;
try { $store = (int)$one("SELECT COUNT(*) FROM sh_products WHERE status = 'published'"); } catch (Throwable) {}

// the fee chart: one point a day (at least the last 7 days), or one a month for a long custom range
$cFrom = (strtotime($to) - strtotime($from)) / 86400 < 6 ? $d($to, '-6 day') : $from;
$days = (int)round((strtotime($to) - strtotime($cFrom)) / 86400) + 1;
$byMonth = $days > 62;
$q = $pdo->prepare("SELECT " . ($byMonth ? "DATE_FORMAT(paid_on, '%Y-%m')" : 'paid_on') . " k, SUM(amount) v FROM ci_payments WHERE paid_on BETWEEN ? AND ? GROUP BY k");
$q->execute([$cFrom, $to]); $got = $q->fetchAll(PDO::FETCH_KEY_PAIR);
$series = [];
if ($byMonth) { for ($m = strtotime(date('Y-m-01', strtotime($cFrom))); $m <= strtotime($to); $m = strtotime('+1 month', $m)) $series[] = [date('M', $m), date('M Y', $m), (float)($got[date('Y-m', $m)] ?? 0)]; }
else { for ($i = 0; $i < $days; $i++) { $k = $d($cFrom, "+$i day"); $series[] = [$days > 10 ? date('j', strtotime($k)) : date('D', strtotime($k)), date('D, d M Y', strtotime($k)), (float)($got[$k] ?? 0)]; } }

$recentStudents = $pdo->query("SELECT s.*, (SELECT GROUP_CONCAT(c.name SEPARATOR ', ') FROM ci_enrollments e JOIN ci_courses c ON c.id = e.course_id WHERE e.student_id = s.id AND e.status <> 'dropped') AS courses
    FROM ci_students s WHERE s.stype <> 'online' ORDER BY s.id DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
$recentPays = $pdo->query("SELECT p.*, s.name, s.reg_no, s.photo, u.username FROM ci_payments p JOIN ci_students s ON s.id = p.student_id LEFT JOIN users u ON u.id = p.created_by ORDER BY p.id DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
$upcoming = $pdo->query("SELECT e.id, e.due_date, s.id AS sid, s.name, s.mobile, s.photo, c.name AS course_name, " . CI_DUE_SQL . " AS due
    FROM ci_enrollments e JOIN ci_students s ON s.id = e.student_id LEFT JOIN ci_courses c ON c.id = e.course_id
    WHERE e.status <> 'dropped' AND " . CI_DUE_SQL . " > 0.004 ORDER BY (e.due_date IS NULL), e.due_date, due DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
$batches = $pdo->query("SELECT b.id, b.name, b.timing, b.capacity, c.name AS course_name, (SELECT COUNT(*) FROM ci_enrollments e WHERE e.batch_id = b.id AND e.status = 'active') AS n
    FROM ci_batches b LEFT JOIN ci_courses c ON c.id = b.course_id WHERE b.status = 'active' ORDER BY n DESC, b.name LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
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
    . ($link !== '' ? '<a class="db-all" href="' . ci_e($link) . '">' . ci_e($linkText) . ' <i class="fas fa-chevron-right"></i></a>' : '') . '</div>';
$tile = function (string $tone, string $icon, string $label, string $value, string $href = '') use ($TONE, $e): string {
    [, $tint, $ink] = $TONE[$tone];
    $in = '<span class="db-ti" style="background:' . $tint . ';color:' . $ink . '"><i class="fas ' . $icon . '"></i></span><span><span class="db-tl">' . $e($label) . '</span><span class="db-tv"' . ($href ? ' style="color:' . $ink . '"' : '') . '>' . $value . '</span></span>';
    return $href !== '' ? '<a class="db-tile db-tile-o" href="' . $e($href) . '">' . $in . '</a>' : '<div class="db-tile">' . $in . '</div>';
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
.db-card{background:#fff;border:1px solid var(--db-b);border-radius:12px!important;box-shadow:0 1px 2px rgba(16,24,40,.04)!important}
.db-bar{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;margin-bottom:20px}
.db-hello b{display:block;font-size:17px;color:var(--db-t)}.db-hello span{font-size:13.5px;color:var(--db-m)}
.db-seg{display:flex;align-items:center;gap:2px;padding:4px;background:#fff;border:1px solid var(--db-b);border-radius:10px!important;max-width:100%;overflow-x:auto;scrollbar-width:none}
.db-seg::-webkit-scrollbar{display:none}
.db-seg a,.db-seg summary{flex:none;display:inline-flex;align-items:center;gap:6px;height:32px;padding:0 12px;border-radius:7px!important;font-size:13.5px;font-weight:500;color:#374151;text-decoration:none;cursor:pointer;white-space:nowrap;list-style:none}
.db-seg summary::-webkit-details-marker{display:none}
.db-seg a:hover,.db-seg summary:hover{background:#f3f4f6}
.db-seg a.on,.db-seg details.on>summary{background:var(--db-p);color:#fff}
.db-seg details{position:static}
.db-cust{position:absolute;z-index:30;top:100%;margin-top:8px;right:0;width:230px;padding:12px;background:#fff;border:1px solid var(--db-b);border-radius:10px!important;box-shadow:0 12px 32px rgba(16,24,40,.12)!important}
.db-segw{display:flex;align-items:center;gap:8px;min-width:0;max-width:100%}
.db-seg{min-width:0}
.db-custw{position:relative!important;flex:none}
.db-custw>summary{display:inline-flex;align-items:center;gap:6px;height:42px;padding:0 14px;background:#fff;border:1px solid var(--db-b);border-radius:10px!important;font-size:13.5px;font-weight:500;color:#374151;cursor:pointer;white-space:nowrap;list-style:none}
.db-custw>summary::-webkit-details-marker{display:none}
.db-custw>summary i{color:var(--db-p)}
.db-custw.on>summary{background:var(--db-p);border-color:var(--db-p);color:#fff}.db-custw.on>summary i{color:#fff}
.db-cust p{margin:0 0 8px;font-size:11px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#9ca3af}
.db-cust label{display:block;margin-bottom:8px;font-size:12px;color:var(--db-m)}
.db-cust input{display:block;width:100%;margin-top:4px;padding:6px 9px;border:1px solid var(--db-b);border-radius:7px!important;font:inherit;font-size:13.5px}
.db-cust button{width:100%;margin-top:4px;padding:8px;border:0;border-radius:7px!important;background:var(--db-p);color:#fff;font-weight:600;cursor:pointer}
.db-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:20px;margin-bottom:20px}
.db-stat{position:relative;display:flex;gap:16px;padding:20px;overflow:hidden;background:#fff;border:1px solid var(--db-b);border-radius:12px!important;box-shadow:0 1px 2px rgba(16,24,40,.04)!important;text-decoration:none;color:inherit;transition:transform .15s,box-shadow .15s}
.db-stat:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(16,24,40,.08)!important}
.db-disc{position:absolute;right:-30px;top:-30px;width:100px;height:100px;border-radius:50%!important;pointer-events:none}
.db-ic{position:relative;flex:none;display:flex;align-items:center;justify-content:center;width:52px;height:52px;border-radius:12px!important;color:#fff;font-size:21px}
.db-st{position:relative;min-width:0}
.db-l{display:block;font-size:14.5px;font-weight:500;color:#374151}
.db-v{display:block;margin-top:4px;font-size:27px;font-weight:700;line-height:1.15;color:var(--db-t)}
.db-f{display:block;margin-top:8px;font-size:13px;color:var(--db-m)}
.db-delta{display:flex;flex-wrap:wrap;align-items:center;gap:4px 8px}
.db-pill{display:inline-flex;align-items:center;gap:4px;padding:2px 6px;border-radius:5px!important;font-size:12px;font-weight:600;white-space:nowrap}
.db-pill i{font-size:9.5px}.db-pill.good{background:#ecfdf5;color:#047857}.db-pill.bad{background:#fef2f2;color:#b91c1c}
.db-vs{font-size:13px;color:var(--db-m);white-space:nowrap}
.db-row{display:grid;grid-template-columns:minmax(0,3fr) minmax(0,2fr);gap:20px;margin-bottom:20px}
.db-row3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:20px;margin-bottom:20px}
.db-sec{padding:24px}
.db-h{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:20px}
.db-h h2{display:flex;align-items:center;gap:12px;margin:0;font-size:17px;font-weight:600;color:var(--db-t)}
.db-hi{display:inline-flex;align-items:center;justify-content:center;font-size:18px;color:#4b5563}
.db-hi.dot{width:32px;height:32px;border-radius:50%!important;background:#1cc88a;color:#fff;font-size:14px}
.db-all{display:inline-flex;align-items:center;gap:7px;font-size:14px;font-weight:500;color:var(--db-p);text-decoration:none;white-space:nowrap}
.db-all i{font-size:10.5px}
.db-tiles{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
.db-tile{display:flex;align-items:center;gap:14px;padding:16px;border:1px solid var(--db-b);border-radius:10px!important;text-decoration:none;color:inherit}
.db-tile-o:hover{background:#f9fafb}
.db-ti{flex:none;display:flex;align-items:center;justify-content:center;width:44px;height:44px;border-radius:10px!important;font-size:18px}
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
.db-mode{display:inline-block;padding:2px 8px;border-radius:999px!important;font-size:12px;font-weight:600;background:#f3f4f6;color:#374151;white-space:nowrap}
.db-mode.cash{background:#ecfdf5;color:#047857}.db-mode.on{background:#f5f3ff;color:#6d28d9}
.db-pr{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border:1px solid var(--db-b);border-radius:7px!important;color:#4b5563}
.db-pr:hover{background:#f9fafb}.db-pr svg{width:15px;height:15px}
.db-scroll{position:relative;overflow-x:auto;border-top:1px solid #f3f4f6;padding-top:16px}
.db-emp{display:flex;flex-direction:column;align-items:center;padding:28px 10px;text-align:center}
.db-emp span{display:flex;align-items:center;justify-content:center;width:64px;height:64px;margin-bottom:12px;border-radius:50%!important;background:#f3f4f6;color:#9ca3af;font-size:24px}
.db-emp b{font-size:15px;color:var(--db-t)}.db-emp small{margin-top:4px;font-size:13.5px;color:var(--db-m)}
/* chart */
.db-ch{position:relative;display:flex;flex-direction:column;height:250px}
@media (min-width:1280px){.db-chsec{display:flex;flex-direction:column}.db-chsec .db-ch{flex:1;min-height:250px;height:auto}}
.db-ch-in{display:flex;flex:1;min-height:0}
.db-ya{display:flex;flex-direction:column;justify-content:space-between;width:48px;flex:none;padding-right:8px;text-align:right;font-size:11px;color:#9ca3af}
.db-ya span{line-height:1;transform:translateY(-50%)}.db-ya span:first-child{transform:none}.db-ya span:last-child{transform:none}
.db-plot{position:relative;flex:1;min-width:0;border-left:1px solid var(--db-b);border-bottom:1px solid var(--db-b);outline:none}
.db-plot:focus-visible{box-shadow:0 0 0 2px #c4b5fd!important}
.db-grid{position:absolute;left:0;right:0;border-top:1px dashed #f3f4f6}
.db-plot svg{position:absolute;inset:0;width:100%;height:100%;overflow:visible}
.db-hit{position:absolute;inset:0;display:grid}
.db-xh{position:absolute;top:0;bottom:0;width:1px;background:#d1d5db;pointer-events:none}
.db-dot{position:absolute;width:10px;height:10px;margin:-5px 0 0 -5px;border:2px solid #fff;border-radius:50%!important;background:var(--db-p);box-shadow:0 0 0 1px var(--db-p)!important;pointer-events:none}
.db-tip{position:absolute;z-index:5;transform:translateX(-50%);padding:6px 10px;background:#fff;border:1px solid var(--db-b);border-radius:8px!important;box-shadow:0 8px 20px rgba(0,0,0,.08)!important;white-space:nowrap;pointer-events:none}
.db-tip small{display:block;font-size:11px;color:var(--db-m)}.db-tip b{font-size:13px;color:var(--db-t)}
.db-xa{display:grid;margin-left:48px;padding-top:8px;text-align:center;font-size:11.5px;color:#9ca3af}
.db-xa span{overflow:hidden;white-space:nowrap}.db-xa span.on{font-weight:600;color:#374151}
.db-chs{display:flex;flex-wrap:wrap;gap:6px 18px;margin:-8px 0 16px;font-size:13px;color:var(--db-m)}.db-chs b{color:var(--db-t)}
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
@media (max-width:1279px){.db-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.db-row,.db-row3{grid-template-columns:minmax(0,1fr)}}
@media (min-width:1280px) and (max-width:1499px){.db-row3{grid-template-columns:repeat(2,minmax(0,1fr))}.db-row3>:last-child{grid-column:1/-1}}
@media (max-width:640px){
  .db-stats{gap:12px}.db-stat{flex-direction:column;gap:10px;padding:14px}.db-ic{width:40px;height:40px;font-size:16px;border-radius:10px!important}
  .db-l{font-size:13px}.db-v{font-size:21px}.db-vs{display:none}.db-f{margin-top:6px;font-size:12px}
  .db-sec{padding:16px}.db-h{margin-bottom:14px}.db-h h2{font-size:16px}.db-tiles{gap:10px}.db-tile{flex-direction:column;align-items:flex-start;gap:8px;padding:12px}.db-tv{font-size:19px}
  .db-row,.db-row3{gap:14px;margin-bottom:14px}.db-bar{margin-bottom:14px}.db-seg{width:100%}
}
/* the admin is flat (square, no shadows); the dashboard has the store dashboard's soft cards */
.db .db-tbl th{background:transparent;border:0}
@media (max-width:640px){.db-segw{width:100%}.db-seg{flex:1}.db-custw>summary{height:42px;padding:0 12px}}
</style>
</head>
<body>
<?php admin_shell_open('Dashboard', date('l, F j, Y'), '<div class="ci-top-actions">'
    . (ci_perm($me, 'add_student') ? '<a class="ci-btn" href="/admin/student-edit.php"><i class="fas fa-user-plus"></i> Add Student</a>' : '')
    . (ci_perm($me, 'fees') ? '<a class="ci-btn ci-ghost" href="/admin/ci-dues.php?period=all"><i class="fas fa-indian-rupee-sign"></i> Collect Fee</a>' : '')
    . (ci_perm($me, 'batches') ? '<a class="ci-btn ci-ghost" href="/admin/ci-batches.php?new=1"><i class="fas fa-layer-group"></i> New Batch</a>' : '') . '</div>'); ?>
<div class="ci db">
<?= admin_flash() ?>

<div class="db-bar" data-ds="Period">
  <div class="db-hello"><b>Welcome back, <?= $e($me['username']) ?></b><span>Showing <?= $range === 'custom' ? $e($span) : $e($rangeLabel) . ' · ' . $e($span) ?></span></div>
  <div class="db-segw"><nav class="db-seg" aria-label="Period">
    <?php foreach ($ranges as $k => $l): ?><a href="<?= $e($q(['range' => $k])) ?>"<?= $range === $k ? ' class="on" aria-current="true"' : '' ?>><?= $e($l) ?></a><?php endforeach; ?>
  </nav>
    <details class="db-custw<?= $range === 'custom' ? ' on' : '' ?>"><summary><i class="fas fa-calendar-days"></i> <?= $range === 'custom' ? $e($rangeLabel) : 'Custom' ?></summary>
      <form class="db-cust" method="get" action="/admin/dashboard.php"><input type="hidden" name="range" value="custom"><p>Custom range</p>
        <label>From<input type="date" name="from" value="<?= $e($from) ?>" max="<?= $today ?>" required></label>
        <label>To<input type="date" name="to" value="<?= $e($to) ?>" max="<?= $today ?>" required></label>
        <button type="submit">Apply</button></form></details>
  </div>
</div>

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
  <?= $card('green', 'fa-receipt', 'Receipts', number_format($cur['rcp']), $delta($cur['rcp'], $prev['rcp']), '/admin/ci-fees.php?' . http_build_query(['period' => 'custom', 'from' => $from, 'to' => $to])) ?>
  <?= $card('purple', 'fa-file-invoice', 'New Fee Charged', ci_money0($cur['charged']), $delta($cur['charged'], $prev['charged']), '/admin/ci-dues.php?period=all') ?>
  <?= $card('purple', 'fa-user-graduate', 'Active Students', number_format($stats['active']), $stats['online'] ? number_format($stats['online']) . ' online students' : 'Studying at the institute', '/admin/students.php') ?>
  <?= $card('green', 'fa-layer-group', 'Active Batches', number_format($stats['batches']), number_format($stats['courses']) . ' active course' . ($stats['courses'] === 1 ? '' : 's'), '/admin/ci-batches.php') ?>
  <?= $card('red', 'fa-hourglass-half', 'Total Due', ci_money0($stats['due']), 'Still to be collected', '/admin/ci-dues.php?period=all') ?>
  <?= $card($stats['late'] ? 'red' : 'green', 'fa-calendar-xmark', 'Due Date Passed', number_format($stats['late']), $stats['late'] ? 'Students to remind' : 'Nobody is late', '/admin/ci-dues.php?period=all&late=1') ?>
</div>

<div class="db-row" data-ds-wrap>
  <section class="db-card db-sec" data-ds="Fees">
    <?= $head('fa-indian-rupee-sign', 'Fees (' . $e($rangeLabel) . ')', '/admin/ci-fees.php?' . http_build_query(['period' => 'custom', 'from' => $from, 'to' => $to]), 'View all', 'dot') ?>
    <div class="db-tiles">
      <?= $tile('green', 'fa-coins', 'Collected', ci_money0($cur['fee'])) ?>
      <?= $tile('purple', 'fa-file-invoice', 'New fee charged', ci_money0($cur['charged'])) ?>
      <?= $tile('green', 'fa-money-bill-wave', 'Cash', ci_money0($cash)) ?>
      <?= $tile('purple', 'fa-mobile-screen-button', 'UPI / Online', ci_money0($online)) ?>
    </div>
  </section>
  <section class="db-card db-sec" data-ds="Institute Overview">
    <?= $head('fa-chart-simple', 'Institute Overview') ?>
    <div class="db-tiles">
      <?= $tile('green', 'fa-book', 'Courses', number_format($stats['courses']), '/admin/ci-courses.php') ?>
      <?= $tile('purple', 'fa-layer-group', 'Batches', number_format($stats['batches']), '/admin/ci-batches.php') ?>
      <?= $tile('purple', 'fa-chalkboard', 'Classes', number_format($stats['classes']), '/admin/ci-classes.php') ?>
      <?= $tile('green', 'fa-photo-film', 'Online Courses', number_format($store), '/admin/shop-products.php') ?>
    </div>
  </section>
</div>

<div class="db-row" data-ds-wrap>
  <section class="db-card db-sec" data-ds="Recent Payments">
    <?= $head('fa-file-lines', 'Recent Payments', '/admin/ci-fees.php?period=month', 'View all') ?>
    <?php if (!$recentPays): ?><div class="db-emp" style="border-top:1px solid #f3f4f6"><span><i class="fas fa-file-lines"></i></span><b>No payments yet</b><small>Fee payments will appear here once you take them</small></div>
    <?php else: ?><div class="db-scroll"><table class="db-tbl"><thead><tr><th>Receipt</th><th>Student</th><th>Amount</th><th>Mode</th><th>Date</th><th><span class="sr-only">Print</span></th></tr></thead><tbody>
      <?php foreach ($recentPays as $p): $mode = ci_pay_mode($p, false); ?><tr>
        <td><a class="db-rc" href="/admin/ci-receipt.php?id=<?= (int)$p['id'] ?>" target="_blank"><?= $e($p['receipt_no']) ?></a></td>
        <td class="db-who"><a href="/admin/student-view.php?id=<?= (int)$p['student_id'] ?>" title="<?= $e($p['name']) ?>"><?= $e($p['name']) ?></a></td>
        <td class="db-amt"><?= ci_money0($p['amount']) ?></td>
        <td><span class="db-mode <?= $p['method'] === 'Cash' ? 'cash' : 'on' ?>"><?= $e($mode) ?></span></td>
        <td style="white-space:nowrap;color:#6b7280"><?= ci_date($p['paid_on']) ?></td>
        <td><a class="db-pr" href="/admin/ci-receipt.php?id=<?= (int)$p['id'] ?>" target="_blank" aria-label="Print receipt <?= $e($p['receipt_no']) ?>"><?= ci_svg('print') ?></a></td>
      </tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
  </section>
  <section class="db-card db-sec db-chsec" data-ds="Fee Collection">
    <?= $head('fa-chart-line', 'Fee Collection') ?>
    <?php $tot = array_sum(array_column($series, 2)); $max = max(array_column($series, 2) ?: [0]);
    $exp = $max > 0 ? pow(10, floor(log10($max))) : 1; $nn = $max > 0 ? $max / $exp : 0; $top = $max > 0 ? ($nn <= 1 ? 1 : ($nn <= 2 ? 2 : ($nn <= 5 ? 5 : 10))) * $exp : 4;
    $n = count($series); $x = fn($i) => ($i + 0.5) / $n * 100; $y = fn($v) => 100 - $v / $top * 100;
    $pts = []; foreach ($series as $i => $s) $pts[] = [round($x($i), 3), round($y($s[2]), 3)];
    $path = 'M' . $pts[0][0] . ',' . $pts[0][1];
    if ($n === 2) $path .= ' L' . $pts[1][0] . ',' . $pts[1][1];
    else for ($i = 0; $i < $n - 1; $i++) { $p0 = $pts[$i - 1] ?? $pts[$i]; $p1 = $pts[$i]; $p2 = $pts[$i + 1]; $p3 = $pts[$i + 2] ?? $p2; // through every point (Catmull-Rom), never above / below a real value
        $cy = fn($v) => max(0, min(100, $v)); // control points kept inside the chart, so the curve never dips below ₹0 or above the top
        $path .= sprintf(' C%.3f,%.3f %.3f,%.3f %.3f,%.3f', $p1[0] + ($p2[0] - $p0[0]) / 6, $cy($p1[1] + ($p2[1] - $p0[1]) / 6), $p2[0] - ($p3[0] - $p1[0]) / 6, $cy($p2[1] - ($p3[1] - $p1[1]) / 6), $p2[0], $p2[1]); }
    $area = 'M' . $pts[0][0] . ',100 L' . $pts[0][0] . ',' . $pts[0][1] . preg_replace('/^M[^ ]+/', '', $path) . ' L' . $pts[$n - 1][0] . ',100 Z';
    $every = $byMonth ? ($n > 8 ? (int)ceil($n / 6) : 1) : ($n > 16 ? (int)ceil($n / 8) : 1); ?>
    <div class="db-chs"><span>Total <b><?= ci_money0($tot) ?></b></span><span><?= $byMonth ? 'By month' : 'By day' ?> · <?= $e(date('d M', strtotime($cFrom))) ?> – <?= $e(date('d M Y', strtotime($to))) ?></span></div>
    <div class="db-ch" id="dbChart" data-pts='<?= $e(json_encode(array_map(fn($s, $p) => [$s[1], ci_money0($s[2]), $p[0], $p[1]], $series, $pts), JSON_UNESCAPED_UNICODE)) ?>'>
      <div class="db-ch-in">
        <div class="db-ya" aria-hidden="true"><?php foreach ([$top, $top * .75, $top / 2, $top / 4, 0] as $t): ?><span><?= $tot > 0 ? $short($t) : '' ?></span><?php endforeach; ?></div>
        <div class="db-plot" tabindex="0" role="img" aria-label="Fee collected, <?= $e($rangeLabel) ?>. Use the left and right arrow keys to read each <?= $byMonth ? 'month' : 'day' ?>.">
          <?php if ($tot > 0): ?>
          <?php foreach ([25, 50, 75] as $g): ?><div class="db-grid" style="top:<?= $g ?>%"></div><?php endforeach; ?>
          <svg viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true"><defs><linearGradient id="dbFill" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#7c3aed" stop-opacity=".18"/><stop offset="100%" stop-color="#7c3aed" stop-opacity="0"/></linearGradient></defs>
            <path d="<?= $area ?>" fill="url(#dbFill)"/><path d="<?= $path ?>" fill="none" stroke="#7c3aed" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/></svg>
          <div class="db-hit" style="grid-template-columns:repeat(<?= $n ?>,1fr)"><?php for ($i = 0; $i < $n; $i++): ?><div data-i="<?= $i ?>"></div><?php endfor; ?></div>
          <?php else: ?><div class="db-emp" style="position:absolute;inset:0;justify-content:center;padding:0"><span style="width:48px;height:48px;font-size:18px"><i class="fas fa-chart-simple"></i></span><b style="font-size:14px">No fee collected yet</b><small style="font-size:12px">The chart fills in as you take fees</small></div><?php endif; ?>
        </div>
      </div>
      <div class="db-xa" aria-hidden="true" style="grid-template-columns:repeat(<?= $n ?>,1fr)"><?php foreach ($series as $i => $s): ?><span><?= ($i % $every === 0 || $i === $n - 1) ? $e($s[0]) : '' ?></span><?php endforeach; ?></div>
      <table class="sr-only"><caption>Fee collected</caption><tbody><?php foreach ($series as $s): ?><tr><td><?= $e($s[1]) ?></td><td><?= ci_money0($s[2]) ?></td></tr><?php endforeach; ?></tbody></table>
    </div>
  </section>
</div>

<?php $av = function (array $s, int $k) use ($e): string { $c = [['#f5f3ff', '#7c3aed'], ['#e8f9f2', '#0f9f6e'], ['#fdecee', '#c81e32'], ['#fef6e7', '#b45309']][$k % 4];
    $ini = mb_strtoupper(mb_substr(trim((string)$s['name']), 0, 1));
    return '<span class="db-av" style="background:' . $c[0] . ';color:' . $c[1] . '">' . (!empty($s['photo']) ? '<img src="' . $e('/' . ltrim((string)$s['photo'], '/')) . '" alt="" loading="lazy">' : $e($ini)) . '</span>'; }; ?>
<div class="db-row3" data-ds-wrap>
  <section class="db-card db-sec" data-ds="New Admissions">
    <?= $head('fa-user-plus', 'New Admissions', '/admin/students.php?status=all', 'View all') ?>
    <?php if (!$recentStudents): ?><div class="db-emp" style="border-top:1px solid #f3f4f6"><span><i class="fas fa-user-graduate"></i></span><b>No students yet</b><small>New admissions will appear here</small></div><?php else: ?>
    <ul class="db-list"><?php foreach ($recentStudents as $k => $s): ?><li><div class="db-p"><?= $av($s, $k) ?><div class="db-n"><a href="/admin/student-view.php?id=<?= (int)$s['id'] ?>"><?= $e($s['name']) ?></a><small><?= $s['courses'] ? $e($s['courses']) : '&nbsp;' ?></small></div></div><span class="db-r"><?= ci_date($s['admission_date']) ?></span></li><?php endforeach; ?></ul><?php endif; ?>
  </section>
  <section class="db-card db-sec" data-ds="Fee to Collect">
    <?= $head('fa-hand-holding-dollar', 'Fee to Collect', '/admin/ci-dues.php?period=all', 'View all') ?>
    <?php if (!$upcoming): ?><div class="db-emp" style="border-top:1px solid #f3f4f6"><span><i class="fas fa-circle-check"></i></span><b>No dues</b><small>Everyone has paid</small></div><?php else: ?>
    <ul class="db-list"><?php foreach ($upcoming as $k => $u): $late = $u['due_date'] && $u['due_date'] < $today; ?><li><div class="db-p"><?= $av($u, $k + 2) ?><div class="db-n"><a href="/admin/student-view.php?id=<?= (int)$u['sid'] ?>#pay"><?= $e($u['name']) ?></a><small><?= $e($u['course_name']) ?> · <a href="tel:<?= $e($u['mobile']) ?>" style="display:inline;font-weight:500;color:inherit"><?= $e($u['mobile']) ?></a></small></div></div>
      <span class="db-r"><b style="color:#c81e32"><?= ci_money0($u['due']) ?></b><span<?= $late ? ' class="db-late"' : '' ?>><?= $u['due_date'] ? ($late ? 'Late · ' : '') . ci_date($u['due_date']) : 'No date' ?></span></span></li><?php endforeach; ?></ul><?php endif; ?>
  </section>
  <section class="db-card db-sec" data-ds="Batches">
    <?= $head('fa-layer-group', 'Batches', '/admin/ci-batches.php', 'View all') ?>
    <?php if (!$batches): ?><div class="db-emp" style="border-top:1px solid #f3f4f6"><span><i class="fas fa-layer-group"></i></span><b>No active batches</b><small>Create a batch to see seats filling here</small></div><?php else: ?>
    <ul class="db-list"><?php foreach ($batches as $b): ?><li><div class="db-n"><a href="/admin/students.php?batch=<?= (int)$b['id'] ?>"><?= $e($b['name']) ?></a><small><?= $e(implode(' · ', array_filter([$b['course_name'], $b['timing']]))) ?></small></div>
      <span class="db-r"><b><?= (int)$b['n'] ?><?= $b['capacity'] ? ' <span style="font-weight:400;color:#6b7280">/ ' . (int)$b['capacity'] . '</span>' : '' ?></b><?= $b['capacity'] ? '<div class="db-meter"><i style="width:' . min(100, round($b['n'] / max(1, $b['capacity']) * 100)) . '%"></i></div>' : 'students' ?></span></li><?php endforeach; ?></ul><?php endif; ?>
  </section>
</div>

</div>
<script>
(function () { // chart: a line, dot and the amount under the finger / mouse (arrow keys too)
  var ch = document.getElementById('dbChart'); if (!ch) return; var plot = ch.querySelector('.db-plot'), hit = ch.querySelector('.db-hit'); if (!hit) return;
  var pts = JSON.parse(ch.getAttribute('data-pts')), xs = ch.querySelectorAll('.db-xa span'), cur = null, line, dot, tip;
  function show(i) {
    if (i === null) { [line, dot, tip].forEach(function (el) { if (el) el.remove(); }); line = dot = tip = null; if (cur !== null && xs[cur]) xs[cur].classList.remove('on'); cur = null; return; }
    if (!line) { line = document.createElement('div'); line.className = 'db-xh'; dot = document.createElement('div'); dot.className = 'db-dot'; tip = document.createElement('div'); tip.className = 'db-tip'; plot.appendChild(line); plot.appendChild(dot); plot.appendChild(tip); }
    if (cur !== null && xs[cur]) xs[cur].classList.remove('on'); cur = i; if (xs[i]) xs[i].classList.add('on');
    var p = pts[i]; line.style.left = p[2] + '%'; dot.style.left = p[2] + '%'; dot.style.top = p[3] + '%';
    tip.innerHTML = '<small></small><b></b>'; tip.firstChild.textContent = p[0]; tip.lastChild.textContent = p[1];
    tip.style.left = 'clamp(56px,' + p[2] + '%,calc(100% - 56px))'; tip.style.top = 'calc(' + p[3] + '% - 54px)';
  }
  hit.addEventListener('mouseover', function (e) { var c = e.target.closest('[data-i]'); if (c) show(+c.getAttribute('data-i')); });
  hit.addEventListener('mouseleave', function () { show(null); });
  hit.addEventListener('touchstart', function (e) { var c = e.target.closest('[data-i]'); if (c) show(+c.getAttribute('data-i')); }, { passive: true });
  plot.addEventListener('keydown', function (e) {
    if (e.key === 'ArrowRight') { e.preventDefault(); show(cur === null ? 0 : Math.min(pts.length - 1, cur + 1)); }
    else if (e.key === 'ArrowLeft') { e.preventDefault(); show(cur === null ? pts.length - 1 : Math.max(0, cur - 1)); }
    else if (e.key === 'Escape') show(null);
  });
  plot.addEventListener('blur', function () { show(null); });
  // the custom range box closes on a click outside it
  document.addEventListener('click', function (e) { var d = document.querySelector('.db-custw[open]'); if (d && !d.contains(e.target)) d.removeAttribute('open'); });
})();
</script>
<?php admin_shell_close(); ?>
</body>
</html>
