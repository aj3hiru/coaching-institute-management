<?php
// Data for the PDF reports (assets/js/ci-report.js draws them): ?type=student|course|batch|class&id=N
// Returns one generic document: title, number, two info boxes, tables, notes. Students who left or dropped are listed
// with that status and the date, so a course/batch report shows who left in between.
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/institute.php';
require_once INCLUDES_PATH . '/shop.php';
$type = (string)($_GET['type'] ?? '');
// online sales need the store "Orders" permission; every other report needs institute access
// online reports: sales and students need the store "Orders" permission; courses / notes reports need Orders or Products
$onlineTypes = ['store', 'ostudents', 'ocourses', 'ocourse', 'onotes'];
if (in_array($type, $onlineTypes, true)) { $me = admin_guard(['admin', 'editor', 'author', 'teacher', 'accountant']);
    if (!(sh_perm($me, 'orders') || (in_array($type, ['ocourses', 'ocourse', 'onotes'], true) && sh_perm($me, 'products')))) { http_response_code(403); exit('{"error":"Access denied"}'); } ci_tables($pdo); sh_tables($pdo); }
else $me = ci_page_start($pdo);
header('Content-Type: application/json; charset=utf-8');
$id = (int)($_GET['id'] ?? 0);
$out = fn(array $d) => exit(json_encode($d + ['institute' => ci_institute(), 'generated' => date('d M Y, h:i A'), 'viewer' => ['name' => $me['username'], 'role' => $me['role']]], JSON_UNESCAPED_UNICODE));
$fail = function (string $m) { http_response_code(404); exit(json_encode(['error' => $m])); };
$rs = fn($n) => 'Rs. ' . number_format((float)$n, 2);
$rs0 = fn($n) => 'Rs. ' . number_format((float)$n); // whole rupees for wide tables
$d = fn($v) => $v ? date('d M Y', strtotime($v)) : '-';
$st = fn(string $s, ?string $on = null) => ['active' => 'Active', 'completed' => 'Completed', 'left' => 'Left', 'dropped' => 'Dropped', 'inactive' => 'Inactive'][$s] ?? ucfirst($s);
$withDate = fn(string $s, ?string $on) => $st($s) . (in_array($s, ['left', 'dropped', 'completed'], true) && $on ? ' (' . date('d M Y', strtotime($on)) . ')' : '');

/** Students of one course / batch / class as table rows + totals. */
$studentRows = function (string $where, array $args, bool $showBatch) use ($pdo, $rs0, $d, $withDate) {
    $q = $pdo->prepare("SELECT s.reg_no, s.name, s.mobile, s.status AS sstatus, s.left_on, e.status, e.ended_on, e.enrolled_on, e.fee, e.discount, e.fee_type,
        b.name AS batch_name, c.name AS course_name, " . CI_PAID_SQL . " AS paid, " . CI_DUE_SQL . " AS due
        FROM ci_enrollments e JOIN ci_students s ON s.id = e.student_id LEFT JOIN ci_batches b ON b.id = e.batch_id LEFT JOIN ci_courses c ON c.id = e.course_id
        WHERE $where ORDER BY e.status = 'active' DESC, s.name");
    $q->execute($args);
    $rows = [];
    $t = ['n' => 0, 'active' => 0, 'left' => 0, 'completed' => 0, 'fee' => 0, 'paid' => 0, 'due' => 0];
    foreach ($q as $r) {
        $net = $r['status'] === 'dropped' ? 0 : (float)$r['fee'] - (float)$r['discount'];
        $status = $r['sstatus'] === 'left' && $r['status'] !== 'completed' ? $withDate('left', $r['left_on'] ?: $r['ended_on']) : $withDate($r['status'], $r['ended_on']);
        $row = [$r['reg_no'], $r['name'], $r['mobile']];
        if ($showBatch) $row[] = $r['batch_name'] ?: '-';
        array_push($row, $d($r['enrolled_on']), $status, $rs0($net), $rs0($r['paid']), $rs0($r['due']));
        $rows[] = $row;
        $t['n']++;
        if ($r['status'] === 'active' && $r['sstatus'] !== 'left') $t['active']++;
        if ($r['sstatus'] === 'left' || in_array($r['status'], ['left', 'dropped'], true)) $t['left']++;
        if ($r['status'] === 'completed') $t['completed']++;
        $t['fee'] += $net; $t['paid'] += (float)$r['paid']; $t['due'] += (float)$r['due'];
    }
    return [$rows, $t];
};
$studentHead = fn(bool $b) => array_merge(['Reg. No.', 'Student', 'Mobile'], $b ? ['Batch'] : [], ['Joined', 'Status', 'Fee', 'Paid', 'Due']);
$moneyCols = fn(bool $b) => $b ? [6, 7, 8] : [5, 6, 7]; // Fee, Paid, Due
$summary = fn(array $t) => [['Students (all time)', (string)$t['n']], ['Studying now', (string)$t['active']], ['Completed', (string)$t['completed']], ['Left / dropped in between', (string)$t['left']],
    ['Total fee (after discounts)', $rs($t['fee'])], ['Fee collected', $rs($t['paid'])], ['Fee still due', $rs($t['due'])]];

if (in_array($type, ['ostudents', 'ocourses', 'ocourse', 'onotes'], true)) require __DIR__ . '/components/online-reports.php';

if ($type === 'student') {
    $q = $pdo->prepare("SELECT s.*, c.name AS class_name FROM ci_students s LEFT JOIN ci_classes c ON c.id = s.class_id WHERE s.id = ?");
    $q->execute([$id]);
    $s = $q->fetch(PDO::FETCH_ASSOC) ?: $fail('Student not found');
    $enr = ci_enrollments($pdo, $id);
    $tot = ci_student_totals($pdo, $id);
    $cRows = array_map(fn($e) => [$e['course_name'] ?? '-', $e['batch_name'] ?: '-', $e['session_name'] ?: '-', ($e['fee_type'] ?? 'full') === 'installment' ? 'Installment' : 'Final fee',
        $rs0($e['fee']), (float)$e['discount'] > 0 ? $rs0($e['discount']) : '-', $rs0($e['status'] === 'dropped' ? 0 : (float)$e['fee'] - (float)$e['discount']), $rs0($e['paid']), $rs0($e['due']), $withDate($e['status'], $e['ended_on'] ?? null)], $enr);
    $pq = $pdo->prepare("SELECT p.*, c.name AS course_name, u.username FROM ci_payments p LEFT JOIN ci_enrollments e ON e.id = p.enrollment_id LEFT JOIN ci_courses c ON c.id = e.course_id LEFT JOIN users u ON u.id = p.created_by WHERE p.student_id = ? ORDER BY p.paid_on, p.id");
    $pq->execute([$id]);
    $pays = $pq->fetchAll(PDO::FETCH_ASSOC);
    $hq = $pdo->prepare("SELECT h.changed_on, a.name f, b.name t, h.note FROM ci_class_history h LEFT JOIN ci_classes a ON a.id = h.from_class LEFT JOIN ci_classes b ON b.id = h.to_class WHERE h.student_id = ? ORDER BY h.changed_on, h.id");
    $hq->execute([$id]);
    $hist = $hq->fetchAll(PDO::FETCH_ASSOC);
    $notes = [$s['name'] . ' has ' . count($enr) . ' course(s): total fee ' . $rs($tot['net']) . ', paid ' . $rs($tot['paid']) . ' in ' . count($pays) . ' payment(s), due ' . $rs($tot['due']) . '.'];
    foreach ($enr as $e) if ((float)$e['due'] > 0.004 && $e['due_date']) $notes[] = ($e['course_name'] ?? 'Course') . ': ' . $rs($e['due']) . ' due' . ($e['due_date'] < date('Y-m-d') ? ' - due date ' . $d($e['due_date']) . ' has passed.' : ' by ' . $d($e['due_date']) . '.');
    if ($s['status'] === 'left') $notes[] = 'The student left' . ($s['left_on'] ? ' on ' . $d($s['left_on']) : '') . '.';
    // online: the account, every course / notes ever given (also ended or taken back) and every order
    $ou = null; $oAcc = $oOrd = [];
    try {
        $uq = $pdo->prepare("SELECT * FROM sh_users WHERE student_id = ? OR mobile = ? ORDER BY student_id = ? DESC, id LIMIT 1"); $uq->execute([$id, $s['mobile'], $id]);
        if ($ou = $uq->fetch(PDO::FETCH_ASSOC) ?: null) {
            $aq = $pdo->prepare("SELECT a.*, p.title, p.type FROM sh_access a JOIN sh_products p ON p.id = a.product_id WHERE a.user_id = ? AND a.source <> 'block' ORDER BY a.granted_at"); $aq->execute([(int)$ou['id']]); $oAcc = $aq->fetchAll(PDO::FETCH_ASSOC);
            $oq = $pdo->prepare("SELECT * FROM sh_orders WHERE user_id = ? ORDER BY COALESCE(paid_at, created_at), id"); $oq->execute([(int)$ou['id']]); $oOrd = $oq->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable) {}
    $oPaid = array_filter($oOrd, fn($o) => $o['status'] === 'paid');
    $oPaidSum = array_sum(array_column($oPaid, 'amount'));
    $accState = function (array $a) use ($d): string {
        if (!empty($a['blocked'])) return 'Blocked';
        if ($a['revoked']) return 'Taken back';
        if (!$a['expires_at']) return 'Lifetime';
        return (strtotime($a['expires_at']) > time() ? 'Till ' : 'Ended ') . $d(substr($a['expires_at'], 0, 10));
    };
    // attendance (institute students): this month in a box, and every month since admission
    $attBox = null; $attSec = null;
    if (($s['stype'] ?? '') !== 'online') {
        $mFirst = substr((string)($s['admission_date'] ?: $s['created_at']), 0, 7) ?: date('Y-m');
        $fq = $pdo->prepare("SELECT MIN(att_date) FROM ci_attendance WHERE student_id = ?"); $fq->execute([$id]); if ($fa = $fq->fetchColumn()) $mFirst = min($mFirst, substr($fa, 0, 7));
        $mRows = []; $AT = ['present' => 0, 'absent' => 0, 'leave' => 0, 'holiday' => 0];
        for ($t = strtotime($mFirst . '-01'); date('Y-m', $t) <= date('Y-m'); $t = strtotime('+1 month', $t)) {
            $c = ci_att_student($pdo, $id, date('Y-m-01', $t), min(date('Y-m-d'), date('Y-m-t', $t)))['count'];
            foreach ($AT as $k => $v) $AT[$k] += $c[$k];
            if ($c['days'] + $c['holiday']) $mRows[] = [date('F Y', $t), (string)$c['days'], (string)$c['present'], (string)$c['absent'], (string)$c['leave'], (string)$c['holiday'], $c['pct'] === null ? '-' : $c['pct'] . '%'];
        }
        $cm = ci_att_student($pdo, $id, date('Y-m-01'), date('Y-m-d'))['count'];
        $attBox = ['title' => 'Attendance · ' . date('F Y'), 'right_text' => 'This month till ' . $d(date('Y-m-d')), 'head' => ['Class days', 'Present', 'Absent', 'Leave', 'Holidays', 'Attendance'],
            'body' => [[(string)$cm['days'], $cm['present'] . ' day(s)', $cm['absent'] . ' day(s)', $cm['leave'] . ' day(s)', $cm['holiday'] . ' day(s)', $cm['pct'] === null ? '-' : $cm['pct'] . '%']], 'right' => []];
        $allD = $AT['present'] + $AT['absent'] + $AT['leave'];
        $attSec = ['title' => 'Attendance', 'right_text' => 'Month by month', 'head' => ['Month', 'Class days', 'Present', 'Absent', 'Leave', 'Holidays', 'Attendance'], 'body' => array_reverse($mRows),
            'foot' => $mRows ? [['Total', (string)$allD, (string)$AT['present'], (string)$AT['absent'], (string)$AT['leave'], (string)$AT['holiday'], $allD ? round($AT['present'] / $allD * 100) . '%' : '-']] : null,
            'right' => [1, 2, 3, 4, 5, 6], 'empty' => 'No attendance marked yet.'];
        if ($allD) $notes[] = 'Attendance: present ' . $AT['present'] . ' of ' . $allD . ' class day(s) (' . round($AT['present'] / $allD * 100) . '%), ' . $AT['absent'] . ' absent, ' . $AT['leave'] . ' leave, ' . $AT['holiday'] . ' holiday(s).';
    }
    if ($ou) $notes[] = 'Online: ' . count($oAcc) . ' course(s) / notes given, ' . count(array_filter($oAcc, fn($a) => !$a['revoked'] && empty($a['blocked']) && (!$a['expires_at'] || strtotime($a['expires_at']) > time()))) . ' open now; ' . count($oPaid) . ' paid order(s) for ' . $rs($oPaidSum) . '.';
    $out(['title' => 'STUDENT REPORT', 'subtitle' => 'Student record and fee statement', 'no' => 'SR-' . $s['reg_no'], 'file' => 'student-report_' . $s['reg_no'],
        'boxes' => [
            ['head' => 'STUDENT', 'rows' => [['Name', $s['name']], ['Reg. No.', $s['reg_no']], ["Father's name", $s['father_name'] ?: '-'], ['Mobile', $s['mobile'] . ($s['alt_mobile'] ? ', ' . $s['alt_mobile'] : '')], ['Address', trim(implode(', ', array_filter([$s['address'], $s['city'], $s['state'], $s['pincode']]))) ?: '-']]],
            ['head' => 'DETAILS', 'rows' => [['Student type', CI_STYPES[$s['stype'] ?? 'institute'] ?? 'Institute'], ['Class', $s['class_name'] ?: '-'], ['Admission', $d($s['admission_date'])], ['Status', $withDate($s['status'], $s['left_on'])], ['Email', $s['email'] ?: '-']]],
        ],
        'sections' => array_values(array_filter([
            $attBox,
            $attSec,
            ['title' => 'Summary of Fee', 'head' => ['Description', 'Amount'], 'body' => array_values(array_filter([['Institute fee (after discounts)', $rs($tot['net'])], ['Institute fee paid', $rs($tot['paid'])], ['Institute fee due', $rs($tot['due'])],
                $ou ? ['Online orders paid', $rs($oPaidSum)] : null])), 'foot' => [['Total paid (institute + online)', $rs($tot['paid'] + $oPaidSum)]], 'right' => [1], 'w' => [1 => 140]],
            ['title' => 'Institute Courses', 'right_text' => count($enr) . ' course(s)', 'head' => ['Course', 'Batch', 'Session', 'Plan', 'Course fee', 'Discount', 'Fee', 'Paid', 'Due', 'Status'], 'body' => $cRows, 'right' => [4, 5, 6, 7, 8], 'small' => true],
            ['title' => 'Institute Fee Payments', 'right_text' => count($pays) . ' receipt(s)', 'head' => ['No.', 'Date', 'Receipt', 'Course', 'Paid by', 'Collected by', 'Amount'],
                'body' => array_map(fn($p, $i) => [$i + 1, $d($p['paid_on']), $p['receipt_no'], $p['course_name'] ?? '-', ci_pay_mode($p), $p['username'] ?: '-', $rs($p['amount'])], $pays, array_keys($pays)),
                'foot' => $pays ? [['', '', '', '', '', 'Total', $rs($tot['paid'])]] : null, 'right' => [6], 'empty' => 'No payments yet.'],
            $ou ? ['title' => 'Online Account', 'head' => ['Login', 'Joined online', 'Last login', 'Account'], 'body' => [[$ou['mobile'] . (str_ends_with((string)$ou['email'], '@noemail.invalid') ? '' : ' / ' . $ou['email']), $d(substr((string)$ou['created_at'], 0, 10)), $ou['last_login'] ? $d(substr((string)$ou['last_login'], 0, 10)) : 'Never', $ou['status'] === 'active' ? 'Active' : 'Suspended']]] : null,
            $ou ? ['title' => 'Online Courses & Notes', 'right_text' => count($oAcc) . ' item(s)', 'head' => ['No.', 'Item', 'Type', 'How', 'Since', 'Access'],
                'body' => array_map(fn($a, $i) => [$i + 1, $a['title'], $a['type'] === 'notes' ? 'PDF notes' : 'Video course', ['purchase' => 'Bought', 'admin' => 'Given by staff', 'free' => 'Free'][$a['source']] ?? $a['source'], $d(substr((string)$a['granted_at'], 0, 10)), $accState($a)], $oAcc, array_keys($oAcc)),
                'small' => true, 'empty' => 'No online course or notes.'] : null,
            $ou ? ['title' => 'Online Orders', 'right_text' => count($oOrd) . ' order(s)', 'head' => ['No.', 'Date', 'Order', 'Item', 'Paid by', 'Status', 'Amount'],
                'body' => array_map(fn($o, $i) => [$i + 1, $d(substr((string)($o['paid_at'] ?: $o['created_at']), 0, 10)), $o['order_no'] ?: '#' . $o['id'], $o['title'], (str_starts_with((string)$o['rzp_order'], 'manual_') ? 'Office' : 'Online') . ($o['method'] ? ' (' . $o['method'] . ')' : ''), ['paid' => 'Paid', 'created' => 'Not paid', 'failed' => 'Failed'][$o['status']] ?? $o['status'], $rs($o['amount'])], $oOrd, array_keys($oOrd)),
                'foot' => $oPaid ? [['', '', '', '', '', 'Total paid', $rs($oPaidSum)]] : null, 'right' => [6], 'small' => true, 'empty' => 'No online orders.'] : null,
            $hist ? ['title' => 'Class History', 'head' => ['Date', 'From class', 'To class', 'Note'], 'body' => array_map(fn($h) => [$d($h['changed_on']), $h['f'] ?: '-', $h['t'] ?: '-', $h['note'] ?: '-'], $hist)] : null,
        ])),
        'notes' => $notes]);
}

if ($type === 'course' || $type === 'batch') {
    if ($type === 'course') {
        $q = $pdo->prepare("SELECT c.*, k.name AS class_name FROM ci_courses c LEFT JOIN ci_classes k ON k.id = c.class_id WHERE c.id = ?");
        $q->execute([$id]);
        $c = $q->fetch(PDO::FETCH_ASSOC) ?: $fail('Course not found');
        [$rows, $t] = $studentRows('e.course_id = ?', [$id], true);
        $bq = $pdo->prepare("SELECT b.name, b.timing, b.days, b.teacher, b.status, b.start_date, b.end_date, se.name AS session_name,
            (SELECT COUNT(*) FROM ci_enrollments e WHERE e.batch_id = b.id AND e.status = 'active') act, (SELECT COUNT(*) FROM ci_enrollments e WHERE e.batch_id = b.id) alln
            FROM ci_batches b LEFT JOIN ci_sessions se ON se.id = b.session_id WHERE b.course_id = ? ORDER BY b.status = 'active' DESC, b.name");
        $bq->execute([$id]);
        $bat = $bq->fetchAll(PDO::FETCH_ASSOC);
        $out(['title' => 'COURSE REPORT', 'subtitle' => 'Course, batches, students and fee', 'no' => 'CR-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT), 'file' => 'course-report_' . $c['name'],
            'boxes' => [
                ['head' => 'COURSE', 'rows' => [['Course', $c['name']], ['Class', $c['class_name'] ?: '-'], ['Course fee', $rs($c['fee'])], ['Duration', $c['duration'] ?: '-'], ['Status', $st($c['status'])]]],
                ['head' => 'FIGURES', 'rows' => [['Batches', (string)count($bat)], ['Students', (string)$t['n']], ['Studying now', (string)$t['active']], ['Left in between', (string)$t['left']], ['Fee due', $rs($t['due'])]]],
            ],
            'sections' => [
                ['title' => 'Summary of Figures', 'head' => ['Description', 'Value'], 'body' => $summary($t), 'right' => [1], 'w' => [1 => 140]],
                ['title' => 'Batches', 'right_text' => count($bat) . ' batch(es)', 'head' => ['Batch', 'Timing', 'Session', 'Teacher', 'Students now', 'All time', 'Status'],
                    'body' => array_map(fn($b) => [$b['name'], trim(($b['timing'] ?? '') . ($b['days'] ? ' ' . $b['days'] : '')) ?: '-', $b['session_name'] ?: '-', $b['teacher'] ?: '-', (string)$b['act'], (string)$b['alln'], $st($b['status'])], $bat), 'right' => [4, 5], 'empty' => 'No batches.'],
                ['title' => 'Students', 'right_text' => $t['n'] . ' student(s)', 'head' => $studentHead(true), 'body' => $rows, 'right' => $moneyCols(true), 'small' => true,
                    'foot' => $rows ? [['', 'Total', '', '', '', '', $rs0($t['fee']), $rs0($t['paid']), $rs0($t['due'])]] : null, 'empty' => 'No students yet.'],
            ],
            'notes' => [$c['name'] . ': ' . $t['n'] . ' student(s) in ' . count($bat) . ' batch(es); ' . $t['active'] . ' studying now, ' . $t['completed'] . ' completed, ' . $t['left'] . ' left or dropped in between.',
                'Fee: ' . $rs($t['fee']) . ' in total, ' . $rs($t['paid']) . ' collected, ' . $rs($t['due']) . ' still due.']]);
    }
    $q = $pdo->prepare("SELECT b.*, c.name AS course_name, c.fee AS course_fee, se.name AS session_name FROM ci_batches b LEFT JOIN ci_courses c ON c.id = b.course_id LEFT JOIN ci_sessions se ON se.id = b.session_id WHERE b.id = ?");
    $q->execute([$id]);
    $b = $q->fetch(PDO::FETCH_ASSOC) ?: $fail('Batch not found');
    [$rows, $t] = $studentRows('e.batch_id = ?', [$id], false);
    $out(['title' => 'BATCH REPORT', 'subtitle' => 'Batch students and fee', 'no' => 'BR-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT), 'file' => 'batch-report_' . $b['name'],
        'boxes' => [
            ['head' => 'BATCH', 'rows' => [['Batch', $b['name']], ['Course', $b['course_name'] ?: '-'], ['Timing', trim(($b['timing'] ?? '') . ($b['days'] ? ' ' . $b['days'] : '')) ?: '-'], ['Teacher', $b['teacher'] ?: '-'], ['Status', $st($b['status'])]]],
            ['head' => 'DETAILS', 'rows' => [['Session', $b['session_name'] ?: '-'], ['Runs', $d($b['start_date']) . ' to ' . $d($b['end_date'])], ['Seats', $b['capacity'] ? (string)$b['capacity'] : 'No limit'], ['Course fee', $rs($b['course_fee'])], ['Studying now', (string)$t['active']]]],
        ],
        'sections' => [
            ['title' => 'Summary of Figures', 'head' => ['Description', 'Value'], 'body' => $summary($t), 'right' => [1], 'w' => [1 => 140]],
            ['title' => 'Students', 'right_text' => $t['n'] . ' student(s)', 'head' => $studentHead(false), 'body' => $rows, 'right' => $moneyCols(false), 'small' => true,
                'foot' => $rows ? [['', 'Total', '', '', '', $rs0($t['fee']), $rs0($t['paid']), $rs0($t['due'])]] : null, 'empty' => 'No students yet.'],
        ],
        'notes' => [$b['name'] . ' (' . ($b['course_name'] ?: 'course') . '): ' . $t['n'] . ' student(s); ' . $t['active'] . ' studying now, ' . $t['completed'] . ' completed, ' . $t['left'] . ' left or dropped in between.',
            'Fee: ' . $rs($t['fee']) . ' in total, ' . $rs($t['paid']) . ' collected, ' . $rs($t['due']) . ' still due.']]);
}

if ($type === 'class') {
    $q = $pdo->prepare("SELECT * FROM ci_classes WHERE id = ?");
    $q->execute([$id]);
    $k = $q->fetch(PDO::FETCH_ASSOC) ?: $fail('Class not found');
    $sq = $pdo->prepare("SELECT s.*, (SELECT GROUP_CONCAT(c.name SEPARATOR ', ') FROM ci_enrollments e JOIN ci_courses c ON c.id = e.course_id WHERE e.student_id = s.id AND e.status = 'active') AS courses,
        (SELECT COALESCE(SUM(CASE WHEN e.status = 'dropped' THEN 0 ELSE e.fee - e.discount END),0) FROM ci_enrollments e WHERE e.student_id = s.id) AS net,
        (SELECT COALESCE(SUM(p.amount),0) FROM ci_payments p WHERE p.student_id = s.id) AS paid,
        (SELECT COALESCE(SUM(" . CI_DUE_SQL . "),0) FROM ci_enrollments e WHERE e.student_id = s.id) AS due
        FROM ci_students s WHERE s.class_id = ? ORDER BY s.status = 'active' DESC, s.name");
    $sq->execute([$id]);
    $rows = [];
    $t = ['n' => 0, 'active' => 0, 'left' => 0, 'fee' => 0, 'paid' => 0, 'due' => 0];
    foreach ($sq as $r) {
        $rows[] = [$r['reg_no'], $r['name'], $r['mobile'], $r['courses'] ?: '-', $withDate($r['status'], $r['left_on']), $rs($r['net']), $rs($r['paid']), $rs($r['due'])];
        $t['n']++; $t['active'] += $r['status'] === 'active'; $t['left'] += $r['status'] === 'left';
        $t['fee'] += (float)$r['net']; $t['paid'] += (float)$r['paid']; $t['due'] += (float)$r['due'];
    }
    $cq = $pdo->prepare("SELECT name, fee, status FROM ci_courses WHERE class_id = ? ORDER BY name");
    $cq->execute([$id]);
    $courses = $cq->fetchAll(PDO::FETCH_ASSOC);
    $out(['title' => 'CLASS REPORT', 'subtitle' => 'Students of the class and their fee', 'no' => 'CL-' . preg_replace('/[^A-Za-z0-9]/', '', $k['name']), 'file' => 'class-report_' . $k['name'],
        'boxes' => [
            ['head' => 'CLASS', 'rows' => [['Class', $k['name']], ['Courses', (string)count($courses)], ['Students', (string)$t['n']], ['Studying now', (string)$t['active']], ['Left', (string)$t['left']]]],
            ['head' => 'FEE', 'rows' => [['Total fee', $rs($t['fee'])], ['Collected', $rs($t['paid'])], ['Due', $rs($t['due'])], ['Session', $pdo->query("SELECT name FROM ci_sessions WHERE is_current = 1 LIMIT 1")->fetchColumn() ?: '-']]],
        ],
        'sections' => [
            ['title' => 'Courses of this Class', 'head' => ['Course', 'Course fee', 'Status'], 'body' => array_map(fn($c) => [$c['name'], $rs($c['fee']), $st($c['status'])], $courses), 'right' => [1], 'empty' => 'No courses linked to this class.'],
            ['title' => 'Students', 'right_text' => $t['n'] . ' student(s)', 'head' => ['Reg. No.', 'Student', 'Mobile', 'Courses', 'Status', 'Fee', 'Paid', 'Due'], 'body' => $rows, 'right' => [5, 6, 7], 'small' => true,
                'foot' => $rows ? [['', 'Total', '', '', '', $rs($t['fee']), $rs($t['paid']), $rs($t['due'])]] : null, 'empty' => 'No students in this class.'],
        ],
        'notes' => ['Class ' . $k['name'] . ': ' . $t['n'] . ' student(s), ' . $t['active'] . ' studying now, ' . $t['left'] . ' left.', 'Fee: ' . $rs($t['fee']) . ' in total, ' . $rs($t['paid']) . ' collected, ' . $rs($t['due']) . ' still due.']]);
}
// ---- online store sales for a period: ?type=store&period=today|yesterday|week|month|lastmonth|year|custom&from=&to=&product=
if ($type === 'store') {
    $today = date('Y-m-d');
    $period = (string)($_GET['period'] ?? 'month');
    $okDate = fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && checkdate((int)substr($v, 5, 2), (int)substr($v, 8, 2), (int)substr($v, 0, 4)) ? $v : null;
    switch ($period) {
        case 'today': $from = $to = $today; $pl = 'Today'; break;
        case 'yesterday': $from = $to = date('Y-m-d', strtotime('-1 day')); $pl = 'Yesterday'; break;
        case 'week': $from = date('Y-m-d', strtotime('monday this week')); $to = $today; $pl = 'This week'; break;
        case 'lastmonth': $from = date('Y-m-01', strtotime('first day of last month')); $to = date('Y-m-t', strtotime('first day of last month')); $pl = 'Last month'; break;
        case 'year': $from = date('Y-01-01'); $to = $today; $pl = 'This year'; break;
        case 'all': $from = '2000-01-01'; $to = $today; $pl = 'All time'; break;
        case 'custom': $from = $okDate($_GET['from'] ?? '') ?? $today; $to = $okDate($_GET['to'] ?? '') ?? $from; if ($from > $to) [$from, $to] = [$to, $from]; $pl = 'Chosen dates'; break;
        default: $period = 'month'; $from = date('Y-m-01'); $to = $today; $pl = 'This month';
    }
    $range = $period === 'all' ? 'All time' : ($from === $to ? $d($from) : $d($from) . ' to ' . $d($to));
    $prodId = (int)($_GET['product'] ?? 0);
    $prodName = 'All courses & notes';
    $w = "o.status = 'paid' AND DATE(o.paid_at) BETWEEN ? AND ?"; $a = [$from, $to];
    if ($prodId) { $w .= ' AND o.product_id = ?'; $a[] = $prodId; $pn = $pdo->prepare("SELECT title FROM sh_products WHERE id = ?"); $pn->execute([$prodId]); $prodName = (string)($pn->fetchColumn() ?: '#' . $prodId); }
    $q = $pdo->prepare("SELECT o.*, u.name, u.mobile, p.type FROM sh_orders o LEFT JOIN sh_users u ON u.id = o.user_id LEFT JOIN sh_products p ON p.id = o.product_id WHERE $w ORDER BY o.paid_at, o.id");
    $q->execute($a);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    $total = array_sum(array_column($rows, 'amount'));
    $byP = []; $byM = []; $buyers = [];
    foreach ($rows as $o) {
        $k = $o['title']; $byP[$k] = [($byP[$k][0] ?? 0) + 1, ($byP[$k][1] ?? 0) + (float)$o['amount'], $o['type'] === 'notes' ? 'PDF notes' : 'Video course'];
        $m = ucfirst((string)($o['method'] ?: 'online')); $byM[$m] = [($byM[$m][0] ?? 0) + 1, ($byM[$m][1] ?? 0) + (float)$o['amount']];
        $buyers[$o['user_id']] = true;
    }
    uasort($byP, fn($x, $y) => $y[1] <=> $x[1]); uasort($byM, fn($x, $y) => $y[1] <=> $x[1]);
    $na = $pdo->prepare("SELECT COUNT(*) FROM sh_users WHERE DATE(created_at) BETWEEN ? AND ?"); $na->execute([$from, $to]); $newAcc = (int)$na->fetchColumn();
    $gq = $pdo->prepare("SELECT a.granted_at, a.note, a.source, u.name, u.mobile, p.title, s.username FROM sh_access a JOIN sh_users u ON u.id = a.user_id JOIN sh_products p ON p.id = a.product_id
        LEFT JOIN users s ON s.id = a.granted_by WHERE a.source IN ('admin', 'free') AND a.revoked = 0 AND DATE(a.granted_at) BETWEEN ? AND ?" . ($prodId ? ' AND a.product_id = ' . $prodId : '') . " ORDER BY a.granted_at");
    $gq->execute([$from, $to]); $given = $gq->fetchAll(PDO::FETCH_ASSOC);
    $sections = [
        ['title' => 'Sales by course / notes', 'head' => ['Item', 'Type', 'Orders', 'Amount'], 'body' => array_map(fn($k, $v) => [$k, $v[2], (string)$v[0], $rs($v[1])], array_keys($byP), $byP),
            'foot' => $byP ? [['Total', '', (string)count($rows), $rs($total)]] : null, 'right' => [2, 3], 'empty' => 'No sales in this period.'],
        ['title' => 'Paid orders', 'right_text' => count($rows) . ' order(s)', 'head' => ['No.', 'Date', 'Order', 'Buyer', 'Mobile', 'Item', 'Payment id', 'Amount'],
            'body' => array_map(fn($o, $i) => [$i + 1, $d($o['paid_at']), $o['order_no'] ?: '#' . $o['id'], $o['name'] ?? '-', $o['mobile'] ?? '-', $o['title'], $o['rzp_payment'] ?: '-', $rs0($o['amount'])], $rows, array_keys($rows)),
            'foot' => $rows ? [['', '', '', '', '', '', 'Total', $rs0($total)]] : null, 'right' => [7], 'small' => true, 'empty' => 'No paid orders in this period.'],
    ];
    if (count($byM) > 1 || ($byM && !isset($byM['Online']))) $sections[] = ['title' => 'By payment method', 'head' => ['Method', 'Orders', 'Amount'], 'body' => array_map(fn($k, $v) => [$k, (string)$v[0], $rs($v[1])], array_keys($byM), $byM), 'right' => [1, 2]];
    $sections[] = ['title' => 'Given free / by staff', 'right_text' => count($given) . ' item(s)', 'head' => ['No.', 'Date', 'Buyer', 'Mobile', 'Item', 'How', 'Note'],
        'body' => array_map(fn($g, $i) => [$i + 1, $d($g['granted_at']), $g['name'], $g['mobile'], $g['title'], $g['source'] === 'free' ? 'Free item' : 'Given by ' . ($g['username'] ?: 'staff'), $g['note'] ?: '-'], $given, array_keys($given)),
        'small' => true, 'empty' => 'Nothing given free in this period.'];
    $out(['title' => 'ONLINE SALES REPORT', 'subtitle' => $pl . ' · ' . $range, 'no' => 'OS-' . ($period === 'all' ? 'ALL' : str_replace('-', '', $from) . ($to !== $from ? '-' . str_replace('-', '', $to) : '')),
        'file' => 'online-sales_' . ($period === 'all' ? 'all-time' : $from . ($to !== $from ? '_to_' . $to : '')), 'landscape' => true,
        'boxes' => [['head' => 'REPORT FOR', 'rows' => [['Period', $pl], ['Dates', $range], ['Item', $prodName]]],
                    ['head' => 'SALES', 'rows' => [['Total sales', $rs($total)], ['Paid orders', (string)count($rows)], ['Buyers', (string)count($buyers)], ['New accounts', (string)$newAcc], ['Given free / by staff', (string)count($given)]]]],
        'sections' => $sections,
        'notes' => [$rows ? $rs($total) . ' from ' . count($buyers) . ' buyer(s) in ' . count($rows) . ' paid order(s), ' . $range . '.' : 'No online sales ' . ($period === 'all' ? 'yet' : ($from === $to ? 'on ' : 'from ') . $range) . '.', 'Amounts are what buyers paid through Razorpay (Razorpay\'s own fee is not taken off).']]);
}
// ---- fee collection for a period: ?type=fees&period=today|yesterday|week|month|lastmonth|year|custom&from=&to=&course=&method=
if ($type === 'fees') {
    if (!ci_perm($me, 'fees')) { http_response_code(403); exit(json_encode(['error' => 'You do not have access to fee reports.'])); }
    $today = date('Y-m-d');
    $period = (string)($_GET['period'] ?? 'today');
    $okDate = fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && checkdate((int)substr($v, 5, 2), (int)substr($v, 8, 2), (int)substr($v, 0, 4)) ? $v : null;
    switch ($period) {
        case 'yesterday': $from = $to = date('Y-m-d', strtotime('-1 day')); $pl = 'Yesterday'; break;
        case 'week': $from = date('Y-m-d', strtotime('monday this week')); $to = $today; $pl = 'This week'; break;
        case 'month': $from = date('Y-m-01'); $to = $today; $pl = 'This month'; break;
        case 'lastmonth': $from = date('Y-m-01', strtotime('first day of last month')); $to = date('Y-m-t', strtotime('first day of last month')); $pl = 'Last month'; break;
        case 'year': $from = date('Y-01-01'); $to = $today; $pl = 'This year'; break;
        case 'custom':
            $from = $okDate($_GET['from'] ?? '') ?? $today; $to = $okDate($_GET['to'] ?? '') ?? $from;
            if ($from > $to) [$from, $to] = [$to, $from];
            $pl = 'Chosen dates'; break;
        default: $period = 'today'; $from = $to = $today; $pl = 'Today';
    }
    $range = $from === $to ? $d($from) : $d($from) . ' to ' . $d($to);
    $w = ['p.paid_on BETWEEN ? AND ?']; $a = [$from, $to];
    $courseId = (int)($_GET['course'] ?? 0);
    $courseName = 'All courses';
    if ($courseId) {
        $w[] = 'e.course_id = ?'; $a[] = $courseId;
        $cn = $pdo->prepare("SELECT name FROM ci_courses WHERE id = ?"); $cn->execute([$courseId]); $courseName = (string)($cn->fetchColumn() ?: 'Course #' . $courseId);
    }
    $q = $pdo->prepare("SELECT p.*, s.name, s.reg_no, c.name AS course_name, b.name AS batch_name, u.username FROM ci_payments p
        JOIN ci_students s ON s.id = p.student_id LEFT JOIN ci_enrollments e ON e.id = p.enrollment_id LEFT JOIN ci_courses c ON c.id = e.course_id
        LEFT JOIN ci_batches b ON b.id = e.batch_id LEFT JOIN users u ON u.id = p.created_by WHERE " . implode(' AND ', $w) . " ORDER BY p.paid_on, p.id");
    $q->execute($a);
    $method = trim((string)($_GET['method'] ?? ''));
    $rows = []; $byMode = []; $byStaff = []; $byCourse = []; $total = 0; $students = [];
    foreach ($q as $p) {
        $split = ci_pay_split($p);
        if ($method !== '' && !isset($split[$method])) continue;
        $amt = $method !== '' ? (float)$split[$method] : (float)$p['amount']; // with a mode chosen, count only that part of a split payment
        $rows[] = [$p, $amt];
        foreach ($split as $m => $v) if ($method === '' || $m === $method) $byMode[$m] = ($byMode[$m] ?? 0) + $v;
        $who = $p['username'] ?: '-';
        $byStaff[$who] = [($byStaff[$who][0] ?? 0) + 1, ($byStaff[$who][1] ?? 0) + $amt];
        $cn = $p['course_name'] ?: '-';
        $byCourse[$cn] = [($byCourse[$cn][0] ?? 0) + 1, ($byCourse[$cn][1] ?? 0) + $amt];
        $total += $amt; $students[$p['student_id']] = true;
    }
    arsort($byMode); uasort($byStaff, fn($x, $y) => $y[1] <=> $x[1]); uasort($byCourse, fn($x, $y) => $y[1] <=> $x[1]);
    $sumTable = fn(string $title, string $col, array $list) => ['title' => $title, 'head' => [$col, 'Payments', 'Amount'],
        'body' => array_map(fn($k, $v) => [$k, (string)$v[0], $rs($v[1])], array_keys($list), $list), 'foot' => $list ? [['Total', (string)count($rows), $rs($total)]] : null, 'right' => [1, 2], 'empty' => 'Nothing.'];
    $modeRows = []; foreach ($byMode as $m => $v) $modeRows[$m] = [count(array_filter($rows, fn($r) => isset(ci_pay_split($r[0])[$m]))), $v];
    $sections = [
        $sumTable('By payment mode', 'Mode', $modeRows),
        ['title' => 'Payments', 'right_text' => count($rows) . ' payment(s)', 'head' => ['No.', 'Date', 'Receipt', 'Student', 'Reg. no.', 'Course / batch', 'Mode', 'Collected by', 'Amount'],
            'body' => array_map(fn($r, $i) => [$i + 1, $d($r[0]['paid_on']), $r[0]['receipt_no'], $r[0]['name'], $r[0]['reg_no'],
                ($r[0]['course_name'] ?: '-') . ($r[0]['batch_name'] ? ' / ' . $r[0]['batch_name'] : ''), ci_pay_mode($r[0]), $r[0]['username'] ?: '-', $rs0($r[1])], $rows, array_keys($rows)),
            'foot' => $rows ? [['', '', '', '', '', '', '', 'Total', $rs0($total)]] : null, 'right' => [8], 'small' => true, 'empty' => 'No fee was collected in this period.'],
        $sumTable('By staff (collected by)', 'Collected by', $byStaff),
    ];
    if (!$courseId && count($byCourse) > 1) $sections[] = $sumTable('By course', 'Course', $byCourse);
    $out(['title' => 'FEE COLLECTION REPORT', 'subtitle' => $pl . ' · ' . $range, 'no' => 'FEE-' . str_replace('-', '', $from) . ($to !== $from ? '-' . str_replace('-', '', $to) : ''),
        'file' => 'fee-report_' . $from . ($to !== $from ? '_to_' . $to : ''), 'landscape' => true,
        'boxes' => [['head' => 'REPORT FOR', 'rows' => [['Period', $pl], ['Dates', $range], ['Course', $courseName], ['Payment mode', $method !== '' ? $method : 'All modes']]],
                    ['head' => 'COLLECTION', 'rows' => [['Total collected', $rs($total)], ['Payments', (string)count($rows)], ['Students who paid', (string)count($students)]]]],
        'sections' => $sections,
        'notes' => [$rows ? $rs($total) . ' collected from ' . count($students) . ' student(s) in ' . count($rows) . ' payment(s), ' . $range . '.' : 'No fee was collected ' . ($from === $to ? 'on ' : 'from ') . $range . '.']]);
}
// ---- all courses / all batches / all classes (one line each), ?status=active|completed|inactive|all
$stF = in_array($_GET['status'] ?? '', ['active', 'completed', 'inactive', 'all'], true) ? $_GET['status'] : 'all';
$money = "COALESCE(SUM(CASE WHEN e.status = 'dropped' THEN 0 ELSE e.fee - e.discount END),0) AS fee, COALESCE(SUM(" . CI_PAID_SQL . "),0) AS paid, COALESCE(SUM(" . CI_DUE_SQL . "),0) AS due";
$sum3 = fn(array $rows) => [array_sum(array_column($rows, 'fee')), array_sum(array_column($rows, 'paid')), array_sum(array_column($rows, 'due'))];
$stLabel = ['active' => 'Active', 'completed' => 'Completed', 'inactive' => 'Inactive', 'all' => 'All'][$stF];
if ($type === 'courses') {
    $rows = $pdo->query("SELECT c.id, c.name, c.fee AS cfee, c.status, k.name AS class_name, (SELECT COUNT(*) FROM ci_batches b WHERE b.course_id = c.id) AS batches,
        (SELECT COUNT(*) FROM ci_enrollments e WHERE e.course_id = c.id) AS alln, (SELECT COUNT(*) FROM ci_enrollments e WHERE e.course_id = c.id AND e.status = 'active') AS act,
        (SELECT COUNT(*) FROM ci_enrollments e WHERE e.course_id = c.id AND e.status IN ('left','dropped')) AS lft
        FROM ci_courses c LEFT JOIN ci_classes k ON k.id = c.class_id" . ($stF !== 'all' ? " WHERE c.status = " . $pdo->quote($stF) : '') . " ORDER BY k.sort IS NULL, k.sort, c.name")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $m = $pdo->query("SELECT $money FROM ci_enrollments e WHERE e.course_id = " . (int)$r['id'])->fetch(PDO::FETCH_ASSOC); $r += $m; }
    unset($r);
    [$tf, $tp, $td] = $sum3($rows);
    $out(['title' => 'COURSES REPORT', 'subtitle' => 'All courses', 'no' => 'CS-' . date('Ymd'), 'file' => 'courses-report_' . strtolower($stLabel), 'landscape' => true,
        'boxes' => [['head' => 'REPORT FOR', 'rows' => [['Courses', 'All courses'], ['Status', $stLabel], ['Number of courses', (string)count($rows)]]],
                    ['head' => 'FIGURES', 'rows' => [['Students (all time)', (string)array_sum(array_column($rows, 'alln'))], ['Studying now', (string)array_sum(array_column($rows, 'act'))], ['Fee collected', $rs($tp)], ['Fee due', $rs($td)]]]],
        'sections' => [['title' => 'Courses', 'right_text' => count($rows) . ' course(s)', 'head' => ['No.', 'Course', 'Class', 'Course fee', 'Batches', 'Students', 'Studying now', 'Left', 'Total fee', 'Collected', 'Due', 'Status'],
            'body' => array_map(fn($r, $i) => [$i + 1, $r['name'], $r['class_name'] ?: '-', $rs0($r['cfee']), (string)$r['batches'], (string)$r['alln'], (string)$r['act'], (string)$r['lft'], $rs0($r['fee']), $rs0($r['paid']), $rs0($r['due']), $st($r['status'])], $rows, array_keys($rows)),
            'foot' => $rows ? [['', 'Total', '', '', (string)array_sum(array_column($rows, 'batches')), (string)array_sum(array_column($rows, 'alln')), (string)array_sum(array_column($rows, 'act')), (string)array_sum(array_column($rows, 'lft')), $rs0($tf), $rs0($tp), $rs0($td), '']] : null,
            'right' => [3, 4, 5, 6, 7, 8, 9, 10], 'small' => true, 'empty' => 'No courses.']],
        'notes' => [count($rows) . ' course(s) (' . strtolower($stLabel) . '): fee ' . $rs($tf) . ', collected ' . $rs($tp) . ', still due ' . $rs($td) . '.']]);
}
if ($type === 'batches') {
    $rows = $pdo->query("SELECT b.id, b.name, b.timing, b.days, b.teacher, b.status, b.capacity, c.name AS course_name, se.name AS session_name,
        (SELECT COUNT(*) FROM ci_enrollments e WHERE e.batch_id = b.id) AS alln, (SELECT COUNT(*) FROM ci_enrollments e WHERE e.batch_id = b.id AND e.status = 'active') AS act
        FROM ci_batches b LEFT JOIN ci_courses c ON c.id = b.course_id LEFT JOIN ci_sessions se ON se.id = b.session_id" . ($stF !== 'all' ? " WHERE b.status = " . $pdo->quote($stF) : '') . " ORDER BY c.name, b.name")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $m = $pdo->query("SELECT $money FROM ci_enrollments e WHERE e.batch_id = " . (int)$r['id'])->fetch(PDO::FETCH_ASSOC); $r += $m; }
    unset($r);
    [$tf, $tp, $td] = $sum3($rows);
    $out(['title' => 'BATCHES REPORT', 'subtitle' => 'All batches', 'no' => 'BS-' . date('Ymd'), 'file' => 'batches-report_' . strtolower($stLabel), 'landscape' => true,
        'boxes' => [['head' => 'REPORT FOR', 'rows' => [['Batches', 'All batches'], ['Status', $stLabel], ['Number of batches', (string)count($rows)]]],
                    ['head' => 'FIGURES', 'rows' => [['Students (all time)', (string)array_sum(array_column($rows, 'alln'))], ['Studying now', (string)array_sum(array_column($rows, 'act'))], ['Fee collected', $rs($tp)], ['Fee due', $rs($td)]]]],
        'sections' => [['title' => 'Batches', 'right_text' => count($rows) . ' batch(es)', 'head' => ['No.', 'Batch', 'Course', 'Timing', 'Session', 'Teacher', 'Students now', 'All time', 'Total fee', 'Collected', 'Due', 'Status'],
            'body' => array_map(fn($r, $i) => [$i + 1, $r['name'], $r['course_name'] ?: '-', trim(($r['timing'] ?? '') . ($r['days'] ? ' ' . $r['days'] : '')) ?: '-', $r['session_name'] ?: '-', $r['teacher'] ?: '-',
                (string)$r['act'] . ($r['capacity'] ? ' / ' . (int)$r['capacity'] : ''), (string)$r['alln'], $rs0($r['fee']), $rs0($r['paid']), $rs0($r['due']), $st($r['status'])], $rows, array_keys($rows)),
            'foot' => $rows ? [['', 'Total', '', '', '', '', (string)array_sum(array_column($rows, 'act')), (string)array_sum(array_column($rows, 'alln')), $rs0($tf), $rs0($tp), $rs0($td), '']] : null,
            'right' => [6, 7, 8, 9, 10], 'small' => true, 'empty' => 'No batches.']],
        'notes' => [count($rows) . ' batch(es) (' . strtolower($stLabel) . '): fee ' . $rs($tf) . ', collected ' . $rs($tp) . ', still due ' . $rs($td) . '.']]);
}
if ($type === 'classes') {
    $rows = $pdo->query("SELECT k.id, k.name, (SELECT COUNT(*) FROM ci_courses c WHERE c.class_id = k.id) AS courses, (SELECT COUNT(*) FROM ci_students s WHERE s.class_id = k.id) AS alln,
        (SELECT COUNT(*) FROM ci_students s WHERE s.class_id = k.id AND s.status = 'active') AS act, (SELECT COUNT(*) FROM ci_students s WHERE s.class_id = k.id AND s.status = 'left') AS lft
        FROM ci_classes k ORDER BY k.sort, k.name")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $m = $pdo->query("SELECT $money FROM ci_enrollments e JOIN ci_students s ON s.id = e.student_id WHERE s.class_id = " . (int)$r['id'])->fetch(PDO::FETCH_ASSOC); $r += $m; }
    unset($r);
    [$tf, $tp, $td] = $sum3($rows);
    $out(['title' => 'CLASSES REPORT', 'subtitle' => 'All classes', 'no' => 'CLS-' . date('Ymd'), 'file' => 'classes-report',
        'boxes' => [['head' => 'REPORT FOR', 'rows' => [['Classes', 'All classes'], ['Number of classes', (string)count($rows)], ['Students', (string)array_sum(array_column($rows, 'alln'))]]],
                    ['head' => 'FIGURES', 'rows' => [['Studying now', (string)array_sum(array_column($rows, 'act'))], ['Left', (string)array_sum(array_column($rows, 'lft'))], ['Fee collected', $rs($tp)], ['Fee due', $rs($td)]]]],
        'sections' => [['title' => 'Classes', 'right_text' => count($rows) . ' class(es)', 'head' => ['No.', 'Class', 'Courses', 'Students', 'Studying now', 'Left', 'Total fee', 'Collected', 'Due'],
            'body' => array_map(fn($r, $i) => [$i + 1, $r['name'], (string)$r['courses'], (string)$r['alln'], (string)$r['act'], (string)$r['lft'], $rs0($r['fee']), $rs0($r['paid']), $rs0($r['due'])], $rows, array_keys($rows)),
            'foot' => $rows ? [['', 'Total', (string)array_sum(array_column($rows, 'courses')), (string)array_sum(array_column($rows, 'alln')), (string)array_sum(array_column($rows, 'act')), (string)array_sum(array_column($rows, 'lft')), $rs0($tf), $rs0($tp), $rs0($td)]] : null,
            'right' => [2, 3, 4, 5, 6, 7, 8], 'empty' => 'No classes.']],
        'notes' => ['All classes together: fee ' . $rs($tf) . ', collected ' . $rs($tp) . ', still due ' . $rs($td) . '.']]);
}
if ($type === 'attendance') { // ?period=today|week|month|lastmonth|custom (&from=&to=) &class= &course= &batch=
    $today = date('Y-m-d'); $per = (string)($_GET['period'] ?? 'month');
    [$a, $b, $plabel] = match ($per) {
        'today' => [$today, $today, 'Today'],
        'yesterday' => [date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day')), 'Yesterday'],
        'week' => [date('Y-m-d', strtotime('monday this week')), $today, 'This week'],
        'lastmonth' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('first day of last month')), date('F Y', strtotime('first day of last month'))],
        'custom' => [ci_clean_date($_GET['from'] ?? '') ?: date('Y-m-01'), ci_clean_date($_GET['to'] ?? '') ?: $today, 'Chosen dates'],
        default => [date('Y-m-01'), $today, date('F Y')],
    };
    if ($a > $b) [$a, $b] = [$b, $a];
    if ($b > $today) $b = $today;
    $cls = (int)($_GET['class'] ?? 0); $crs = (int)($_GET['course'] ?? 0); $bat = (int)($_GET['batch'] ?? 0);
    ci_att_autoclose($pdo, $a, $b); // past classes: the ones not marked are absent
    $hols = ci_holidays_between($pdo, $a, $b);
    // who is in the report: students of the institute's batches / courses in that scope, plus anyone with attendance marked in it
    $w = ["s.stype <> 'online'"]; $args = [];
    if ($cls) { $w[] = 's.class_id = ?'; $args[] = $cls; }
    if ($crs) { $w[] = 'e.course_id = ?'; $args[] = $crs; }
    if ($bat) { $w[] = 'e.batch_id = ?'; $args[] = $bat; }
    $q = $pdo->prepare("SELECT s.id, s.name, s.reg_no, cl.name AS class_name, COALESCE(e.batch_id, 0) AS bid, e.course_id AS cid, b.name AS batch_name
        FROM ci_enrollments e JOIN ci_students s ON s.id = e.student_id LEFT JOIN ci_batches b ON b.id = e.batch_id LEFT JOIN ci_classes cl ON cl.id = s.class_id
        WHERE " . implode(' AND ', $w) . " AND ((e.status = 'active' AND s.status = 'active' AND (e.enrolled_on IS NULL OR e.enrolled_on <= ?))
            OR EXISTS (SELECT 1 FROM ci_attendance x WHERE x.student_id = s.id AND x.batch_id = COALESCE(e.batch_id, 0) AND x.att_date BETWEEN ? AND ?))
        ORDER BY cl.sort IS NULL, cl.sort, s.reg_no, s.name");
    $q->execute(array_merge($args, [$b, $a, $b]));
    $list = []; foreach ($q as $r) $list[$r['id'] . '-' . $r['bid']] ??= $r;
    $marks = []; $days = [];
    if ($list) {
        $ids = implode(',', array_unique(array_map(fn($r) => (int)$r['id'], $list)));
        $mq = $pdo->prepare("SELECT student_id, batch_id, att_date, status FROM ci_attendance WHERE student_id IN ($ids) AND att_date BETWEEN ? AND ?");
        $mq->execute([$a, $b]);
        foreach ($mq as $m) { $k = $m['student_id'] . '-' . $m['batch_id']; if (!isset($list[$k])) continue; $marks[$k][$m['att_date']] = $m['status']; if ($m['status'] !== 'holiday') $days[$m['att_date']] = true; }
        if ($hols) foreach ($list as $k => $r) for ($t = strtotime($a); $t <= strtotime($b); $t += 86400) { // holidays where nothing is marked
            $dt = date('Y-m-d', $t); if (!isset($marks[$k][$dt]) && ci_holiday_on($hols, $dt, (int)$r['bid'], (int)$r['cid'])) $marks[$k][$dt] = 'holiday'; }
    }
    ksort($days);
    $pct = fn(int $p, int $n) => $n ? round($p / $n * 100) . '%' : '-';
    $rows = []; $T = ['present' => 0, 'absent' => 0, 'leave' => 0, 'holiday' => 0];
    foreach ($list as $k => $r) {
        $c = array_count_values($marks[$k] ?? []); $p = $c['present'] ?? 0; $ab = $c['absent'] ?? 0; $lv = $c['leave'] ?? 0; $hd = $c['holiday'] ?? 0;
        $T['present'] += $p; $T['absent'] += $ab; $T['leave'] += $lv; $T['holiday'] += $hd;
        $rows[] = [$r['reg_no'], $r['name'], $r['class_name'] ?: '-', $r['batch_name'] ?: '-', (string)$p, (string)$ab, (string)$lv, (string)$hd, (string)($p + $ab + $lv), $pct($p, $p + $ab + $lv)];
    }
    $nDays = count($days); $allMarks = $T['present'] + $T['absent'] + $T['leave'];
    $label = [];
    if ($cls) $label[] = ['Class', (string)$pdo->query("SELECT name FROM ci_classes WHERE id = $cls")->fetchColumn()];
    if ($crs) $label[] = ['Course', (string)$pdo->query("SELECT name FROM ci_courses WHERE id = $crs")->fetchColumn()];
    if ($bat) $label[] = ['Batch', (string)$pdo->query("SELECT name FROM ci_batches WHERE id = $bat")->fetchColumn()];
    $sections = [['title' => 'Students', 'right_text' => count($rows) . ' student(s)', 'head' => ['Adm. ID', 'Student', 'Class', 'Batch', 'Present', 'Absent', 'Leave', 'Holiday', 'Class days', 'Attendance'],
        'body' => $rows, 'right' => [4, 5, 6, 7, 8, 9], 'empty' => 'No students for this choice.']];
    if ($days && count($days) <= 62) { // day by day
        $dr = [];
        foreach (array_keys($days) as $dt) { $c = ['present' => 0, 'absent' => 0, 'leave' => 0, 'holiday' => 0]; foreach ($marks as $mm) if (isset($mm[$dt])) $c[$mm[$dt]]++;
            $dr[] = [date('d M Y, D', strtotime($dt)), (string)$c['present'], (string)$c['absent'], (string)$c['leave'], (string)$c['holiday'], (string)(count($list) - array_sum($c)), $pct($c['present'], $c['present'] + $c['absent'] + $c['leave'])]; }
        $sections[] = ['title' => 'Day by day', 'right_text' => $nDays . ' day(s)', 'head' => ['Date', 'Present', 'Absent', 'Leave', 'Holiday', 'Not marked', 'Attendance'], 'body' => $dr, 'right' => [1, 2, 3, 4, 5, 6]];
    }
    $span = (strtotime($b) - strtotime($a)) / 86400 + 1;
    if ($list && $span <= 31) { // the register: every day of the period, P / A / L
        $dl = []; for ($t = strtotime($a); $t <= strtotime($b); $t += 86400) $dl[] = date('Y-m-d', $t);
        $reg = [];
        foreach ($list as $k => $r) { $m = $marks[$k] ?? []; $line = [$r['reg_no'], $r['name']];
            foreach ($dl as $dt) $line[] = isset($m[$dt]) ? strtoupper($m[$dt][0]) : (date('w', strtotime($dt)) === '0' ? 'S' : '-');
            $c = array_count_values($m); $line[] = (string)($c['present'] ?? 0); $line[] = $pct($c['present'] ?? 0, ($c['present'] ?? 0) + ($c['absent'] ?? 0) + ($c['leave'] ?? 0)); $reg[] = $line; }
        $sections[] = ['title' => 'Register', 'right_text' => 'P present · A absent · L leave · H holiday · S Sunday', 'head' => array_merge(['Adm. ID', 'Student'], array_map(fn($dt) => date('j', strtotime($dt)), $dl), ['P', '%']),
            'body' => $reg, 'small' => true, 'right' => [], 'empty' => ''];
    }
    $out(['title' => 'ATTENDANCE REPORT', 'subtitle' => $plabel . ' · ' . $d($a) . ($a !== $b ? ' to ' . $d($b) : ''), 'no' => 'AT-' . date('Ymd', strtotime($b)) . '-' . count($rows),
        'file' => 'attendance-report_' . $a . '_' . $b, 'landscape' => true,
        'boxes' => [['head' => 'REPORT FOR', 'rows' => array_merge([['Period', $d($a) . ($a !== $b ? ' - ' . $d($b) : '')]], $label ?: [['Students', 'All institute batches']])],
            ['head' => 'FIGURES', 'rows' => [['Students', (string)count($rows)], ['Days with attendance', (string)$nDays], ['Present marks', (string)$T['present']], ['Absent / leave / holiday', $T['absent'] . ' / ' . $T['leave'] . ' / ' . $T['holiday']], ['Average attendance', $pct($T['present'], $allMarks)]]]],
        'sections' => $sections,
        'notes' => [count($rows) . ' student(s), ' . $nDays . ' day(s) with attendance between ' . $d($a) . ' and ' . $d($b) . '. Attendance % = present days out of the class days marked for the student (holidays are not counted). After a class ends, students of that batch who were not marked are absent.']]);
}
$fail('Unknown report');
