<?php
// Online (store) reports for admin/ci-report.php — included there, uses its helpers ($pdo, $out, $fail, $rs, $rs0, $d).
//   ?type=ostudents  online student accounts: who joined, who bought, what they own and spent
//   ?type=ocourses   every online course / PDF notes pack: chapters, video lectures, PDF lessons, notes PDFs, MCQs, students, sales
//   ?type=ocourse&id=N  one course / notes pack: every lecture (video / PDF, MCQs, notes), its files and its students with progress
//   ?type=onotes     PDF notes: packs listed, PDFs and pages, how many sold / given free in a period
$today = date('Y-m-d');
/** Period from ?period= (today|yesterday|week|month|lastmonth|year|all|custom): [from, to, label, range text, key]. */
$oPeriod = function (string $def) use ($today, $d): array {
    $p = (string)($_GET['period'] ?? $def);
    $ok = fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && checkdate((int)substr($v, 5, 2), (int)substr($v, 8, 2), (int)substr($v, 0, 4)) ? $v : null;
    switch ($p) {
        case 'today': $f = $t = $today; $l = 'Today'; break;
        case 'yesterday': $f = $t = date('Y-m-d', strtotime('-1 day')); $l = 'Yesterday'; break;
        case 'week': $f = date('Y-m-d', strtotime('monday this week')); $t = $today; $l = 'This week'; break;
        case 'month': $f = date('Y-m-01'); $t = $today; $l = 'This month'; break;
        case 'lastmonth': $f = date('Y-m-01', strtotime('first day of last month')); $t = date('Y-m-t', strtotime('first day of last month')); $l = 'Last month'; break;
        case 'year': $f = date('Y-01-01'); $t = $today; $l = 'This year'; break;
        case 'custom': $f = $ok($_GET['from'] ?? '') ?? $today; $t = $ok($_GET['to'] ?? '') ?? $f; if ($f > $t) [$f, $t] = [$t, $f]; $l = 'Chosen dates'; break;
        default: $p = 'all'; $f = '2000-01-01'; $t = $today; $l = 'All time';
    }
    return [$f, $t, $l, $p === 'all' ? 'All time' : ($f === $t ? $d($f) : $d($f) . ' to ' . $d($t)), $p];
};
$typeName = fn(string $t) => $t === 'notes' ? 'PDF notes' : 'Video course';
$priceTxt = fn(array $p) => (float)$p['price'] <= 0 ? 'Free' . ((float)$p['mrp'] > 0 ? ' (was ' . 'Rs. ' . number_format((float)$p['mrp']) . ')' : '') : 'Rs. ' . number_format((float)$p['price']);
$cats = $pdo->query("SELECT id, name FROM sh_categories")->fetchAll(PDO::FETCH_KEY_PAIR);

/** Content numbers of every product (or one): chapters, video / PDF lessons, notes PDFs, pages, MCQs, VVI, free previews, YouTube / uploaded. */
$content = function (?int $only = null) use ($pdo): array {
    $c = [];
    $w = $only ? " WHERE product_id = $only" : '';
    foreach ($pdo->query("SELECT product_id, COUNT(*) n FROM sh_sections$w GROUP BY product_id") as $r) $c[(int)$r['product_id']]['chapters'] = (int)$r['n'];
    foreach ($pdo->query("SELECT id, product_id, kind, video_url, video_file, free, quiz, vvi, pdf_pages FROM sh_lessons$w") as $l) {
        $k = (int)$l['product_id']; $c[$k] ??= [];
        $isPdf = ($l['kind'] ?? 'video') === 'pdf';
        $c[$k][$isPdf ? 'pdfl' : 'video'] = ($c[$k][$isPdf ? 'pdfl' : 'video'] ?? 0) + 1;
        if ($isPdf) $c[$k]['pages'] = ($c[$k]['pages'] ?? 0) + (int)$l['pdf_pages'];
        elseif ($l['video_file']) $c[$k]['upload'] = ($c[$k]['upload'] ?? 0) + 1;
        elseif ($l['video_url'] && sh_yt_id((string)$l['video_url'])) $c[$k]['yt'] = ($c[$k]['yt'] ?? 0) + 1;
        if ((int)$l['free']) $c[$k]['freel'] = ($c[$k]['freel'] ?? 0) + 1;
        $c[$k]['mcq'] = ($c[$k]['mcq'] ?? 0) + count(sh_quiz($l)['items']);
        $c[$k]['vvi'] = ($c[$k]['vvi'] ?? 0) + count(sh_vvi($l)['qa']);
    }
    foreach ($pdo->query("SELECT product_id, COUNT(*) n, COALESCE(SUM(pages),0) pg, SUM(free) fr FROM sh_files$w GROUP BY product_id") as $r) {
        $k = (int)$r['product_id']; $c[$k]['files'] = (int)$r['n']; $c[$k]['pages'] = ($c[$k]['pages'] ?? 0) + (int)$r['pg']; $c[$k]['freef'] = (int)$r['fr'];
    }
    return $c;
};
/** Sales numbers per product between two dates: paid orders, revenue; and (all time) students with access, free / staff given, rating. */
$sales = function (string $from, string $to, ?int $only = null) use ($pdo): array {
    $s = [];
    $w = $only ? " AND product_id = $only" : '';
    $q = $pdo->prepare("SELECT product_id, COUNT(*) n, COALESCE(SUM(amount),0) amt FROM sh_orders WHERE status = 'paid' AND DATE(paid_at) BETWEEN ? AND ?$w GROUP BY product_id"); $q->execute([$from, $to]);
    foreach ($q as $r) $s[(int)$r['product_id']] = ['sold' => (int)$r['n'], 'amt' => (float)$r['amt']];
    foreach ($pdo->query("SELECT product_id, COUNT(*) n, SUM(source = 'free') fr, SUM(source = 'admin') st FROM sh_access WHERE revoked = 0 AND (expires_at IS NULL OR expires_at > NOW())$w GROUP BY product_id") as $r)
        $s[(int)$r['product_id']] = ($s[(int)$r['product_id']] ?? []) + ['students' => (int)$r['n'], 'free' => (int)$r['fr'], 'staff' => (int)$r['st']];
    foreach ($pdo->query("SELECT product_id, AVG(rating) a, COUNT(*) n FROM sh_reviews WHERE 1$w GROUP BY product_id") as $r) $s[(int)$r['product_id']] = ($s[(int)$r['product_id']] ?? []) + ['rating' => round((float)$r['a'], 1), 'reviews' => (int)$r['n']];
    return $s;
};
$n = fn(array $a, string $k) => (int)($a[$k] ?? 0);

// ---- online student accounts
if ($type === 'ostudents') {
    [$from, $to, $pl, $range, $pk] = $oPeriod('all');
    $show = in_array($_GET['show'] ?? '', ['buyers', 'free', 'none', 'linked'], true) ? $_GET['show'] : 'all';
    $q = $pdo->prepare("SELECT u.id, u.name, u.mobile, u.email, u.created_at, u.last_login, u.student_id, u.status, st.reg_no, st.stype,
        (SELECT COUNT(*) FROM sh_access a WHERE a.user_id = u.id AND a.revoked = 0 AND (a.expires_at IS NULL OR a.expires_at > NOW())) AS owned,
        (SELECT COUNT(*) FROM sh_access a WHERE a.user_id = u.id AND a.revoked = 0 AND a.source = 'free') AS freec,
        (SELECT COUNT(*) FROM sh_orders o WHERE o.user_id = u.id AND o.status = 'paid') AS orders,
        (SELECT COALESCE(SUM(o.amount),0) FROM sh_orders o WHERE o.user_id = u.id AND o.status = 'paid') AS spent
        FROM sh_users u LEFT JOIN ci_students st ON st.id = u.student_id WHERE DATE(u.created_at) BETWEEN ? AND ? ORDER BY u.created_at DESC");
    $q->execute([$from, $to]);
    $all = $q->fetchAll(PDO::FETCH_ASSOC);
    $rows = array_values(array_filter($all, fn($u) => match ($show) { 'buyers' => (int)$u['orders'] > 0, 'free' => (int)$u['freec'] > 0, 'none' => !(int)$u['owned'], 'linked' => in_array($u['stype'] ?? '', ['both', 'institute'], true), default => true }));
    $buyers = count(array_filter($all, fn($u) => (int)$u['orders'] > 0));
    $spent = array_sum(array_column($rows, 'spent'));
    $byMonth = [];
    foreach ($pdo->query("SELECT DATE_FORMAT(created_at, '%Y-%m') m, COUNT(*) n FROM sh_users WHERE created_at >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 11 MONTH) GROUP BY m ORDER BY m") as $r) $byMonth[] = [date('M Y', strtotime($r['m'] . '-01')), (string)$r['n']];
    $showTxt = ['all' => 'All accounts', 'buyers' => 'Bought something', 'free' => 'Joined a free course', 'none' => 'Own nothing yet', 'linked' => 'Also institute students (Both)'][$show];
    $out(['title' => 'ONLINE STUDENTS REPORT', 'subtitle' => $pl . ' · ' . $showTxt, 'no' => 'OST-' . date('Ymd'), 'file' => 'online-students_' . ($pk === 'all' ? 'all-time' : $from . '_to_' . $to), 'landscape' => true,
        'boxes' => [['head' => 'REPORT FOR', 'rows' => [['Joined', $pl], ['Dates', $range], ['Showing', $showTxt]]],
                    ['head' => 'ACCOUNTS', 'rows' => [['Accounts', (string)count($all)], ['Bought something', (string)$buyers], ['Own nothing yet', (string)count(array_filter($all, fn($u) => !(int)$u['owned']))],
                        ['Online only', (string)count(array_filter($all, fn($u) => ($u['stype'] ?? '') === 'online'))], ['Both (institute + online)', (string)count(array_filter($all, fn($u) => in_array($u['stype'] ?? '', ['both', 'institute'], true)))], ['Total spent (listed)', $rs($spent)]]]],
        'sections' => [
            ['title' => 'Online students', 'right_text' => count($rows) . ' account(s)', 'head' => ['No.', 'Name', 'Mobile', 'Email', 'Joined', 'Last login', 'Owns', 'Free', 'Paid orders', 'Spent', 'Reg. no. · Type'],
                'body' => array_map(fn($u, $i) => [$i + 1, $u['name'], $u['mobile'], $u['email'] ?: '-', $d($u['created_at']), $u['last_login'] ? $d($u['last_login']) : '-', (string)(int)$u['owned'], (string)(int)$u['freec'], (string)(int)$u['orders'], $rs0($u['spent']), ($u['reg_no'] ? $u['reg_no'] . ' · ' : '') . (CI_STYPES[$u['stype'] ?? ''] ?? '-')], $rows, array_keys($rows)),
                'foot' => $rows ? [['', 'Total', '', '', '', '', (string)array_sum(array_column($rows, 'owned')), (string)array_sum(array_column($rows, 'freec')), (string)array_sum(array_column($rows, 'orders')), $rs0($spent), '']] : null,
                'right' => [6, 7, 8, 9], 'small' => true, 'empty' => 'No online accounts here.'],
            ['title' => 'New accounts by month (last 12 months)', 'head' => ['Month', 'New accounts'], 'body' => $byMonth, 'right' => [1], 'empty' => 'No new accounts in the last 12 months.'],
        ],
        'notes' => ['"Owns" counts courses and notes the student can open now (bought, free or given by staff). "Type": Online = online courses / notes only; Both = also in an institute batch (linked by mobile number).']]);
}

// ---- every online course / notes pack
if ($type === 'ocourses' || $type === 'onotes') {
    $isNotes = $type === 'onotes';
    [$from, $to, $pl, $range, $pk] = $oPeriod('all');
    $kind = $isNotes ? 'notes' : (in_array($_GET['kind'] ?? '', ['course', 'notes'], true) ? $_GET['kind'] : '');
    $price = in_array($_GET['price'] ?? '', ['paid', 'free'], true) ? $_GET['price'] : '';
    $status = ($_GET['status'] ?? '') === 'all' ? 'all' : 'published';
    $cat = (int)($_GET['category'] ?? 0);
    $w = ['1']; $a = [];
    if ($kind) { $w[] = 'type = ?'; $a[] = $kind; }
    if ($price === 'paid') $w[] = 'price > 0'; elseif ($price === 'free') $w[] = 'price <= 0';
    if ($status !== 'all') $w[] = "status = 'published'";
    if ($cat) { $w[] = 'category_id = ?'; $a[] = $cat; }
    $q = $pdo->prepare("SELECT * FROM sh_products WHERE " . implode(' AND ', $w) . " ORDER BY type, title"); $q->execute($a);
    $items = $q->fetchAll(PDO::FETCH_ASSOC);
    $C = $content(); $S = $sales($from, $to);
    $tot = ['video' => 0, 'pdfl' => 0, 'files' => 0, 'pages' => 0, 'mcq' => 0, 'vvi' => 0, 'students' => 0, 'sold' => 0, 'amt' => 0.0, 'free' => 0, 'chapters' => 0];
    $body = []; $byCat = [];
    foreach ($items as $i => $p) {
        $c = $C[(int)$p['id']] ?? []; $s = $S[(int)$p['id']] ?? [];
        foreach (['video', 'pdfl', 'files', 'pages', 'mcq', 'vvi', 'chapters'] as $k) $tot[$k] += $n($c, $k);
        $tot['students'] += $n($s, 'students'); $tot['sold'] += $n($s, 'sold'); $tot['amt'] += (float)($s['amt'] ?? 0); $tot['free'] += $n($s, 'free');
        $cn = $cats[(int)$p['category_id']] ?? '-';
        $byCat[$cn] = [($byCat[$cn][0] ?? 0) + 1, ($byCat[$cn][1] ?? 0) + $n($s, 'sold'), ($byCat[$cn][2] ?? 0) + (float)($s['amt'] ?? 0), ($byCat[$cn][3] ?? 0) + $n($s, 'students')];
        $up = $p['starts_at'] && $p['starts_at'] >= $today;
        $body[] = $isNotes
            ? [$i + 1, $p['title'], $cn, $priceTxt($p), (string)$n($c, 'files'), (string)$n($c, 'pages'), (string)$n($c, 'freef'), (string)$n($s, 'sold'), $rs0($s['amt'] ?? 0), (string)$n($s, 'free'), (string)$n($s, 'students')]
            : [$i + 1, $p['title'] . ($up ? ' (upcoming)' : '') . ($p['status'] !== 'published' ? ' [' . $p['status'] . ']' : ''), $typeName($p['type']), $cn, $priceTxt($p), (string)$n($c, 'chapters'), (string)$n($c, 'video'), (string)$n($c, 'pdfl'), (string)$n($c, 'files'), (string)$n($c, 'mcq'), (string)$n($s, 'students'), (string)$n($s, 'sold'), $rs0($s['amt'] ?? 0)];
    }
    $paidN = count(array_filter($items, fn($p) => (float)$p['price'] > 0));
    $filt = implode(' · ', array_filter([$kind ? $typeName($kind) : 'Courses & notes', $price === 'paid' ? 'Paid' : ($price === 'free' ? 'Free' : ''), $cat ? ($cats[$cat] ?? '') : '', $status === 'all' ? 'incl. drafts' : 'Published']));
    $sections = [$isNotes
        ? ['title' => 'PDF notes packs', 'right_text' => count($items) . ' pack(s)', 'head' => ['No.', 'Notes pack', 'Category', 'Price', 'PDFs', 'Pages', 'Free PDFs', 'Sold', 'Amount', 'Given free', 'Readers now'],
            'body' => $body, 'foot' => $items ? [['', 'Total', '', '', (string)$tot['files'], (string)$tot['pages'], '', (string)$tot['sold'], $rs0($tot['amt']), (string)$tot['free'], (string)$tot['students']]] : null, 'right' => [4, 5, 6, 7, 8, 9, 10], 'small' => true, 'empty' => 'No PDF notes here.']
        : ['title' => 'Online courses & notes', 'right_text' => count($items) . ' item(s)', 'head' => ['No.', 'Course / notes', 'Type', 'Category', 'Price', 'Chapters', 'Video lectures', 'PDF lessons', 'Notes PDFs', 'MCQs', 'Students', 'Sold', 'Amount'],
            'body' => $body, 'foot' => $items ? [['', 'Total', '', '', '', (string)$tot['chapters'], (string)$tot['video'], (string)$tot['pdfl'], (string)$tot['files'], (string)$tot['mcq'], (string)$tot['students'], (string)$tot['sold'], $rs0($tot['amt'])]] : null, 'right' => [5, 6, 7, 8, 9, 10, 11, 12], 'small' => true, 'empty' => 'No courses here.']];
    ksort($byCat);
    $sections[] = ['title' => 'By category', 'head' => ['Category', 'Items', 'Sold', 'Amount', 'Students / readers'], 'body' => array_map(fn($k, $v) => [$k, (string)$v[0], (string)$v[1], $rs($v[2]), (string)$v[3]], array_keys($byCat), $byCat), 'right' => [1, 2, 3, 4]];
    $out(['title' => $isNotes ? 'PDF NOTES REPORT' : 'ONLINE COURSES REPORT', 'subtitle' => $filt . ' · sales ' . $range, 'no' => ($isNotes ? 'ON-' : 'OC-') . date('Ymd'), 'file' => ($isNotes ? 'pdf-notes' : 'online-courses') . '_' . date('Y-m-d'), 'landscape' => true,
        'boxes' => $isNotes
            ? [['head' => 'NOTES LISTED', 'rows' => [['Packs', (string)count($items)], ['Paid packs', (string)$paidN], ['Free packs', (string)(count($items) - $paidN)], ['PDF files', (string)$tot['files']], ['Pages', (string)$tot['pages']]]],
               ['head' => 'SALES · ' . strtoupper($pl), 'rows' => [['Dates', $range], ['Packs sold', (string)$tot['sold']], ['Amount', $rs($tot['amt'])], ['Given free (all time)', (string)$tot['free']], ['Readers now', (string)$tot['students']]]]]
            : [['head' => 'CATALOGUE', 'rows' => [['Items', (string)count($items)], ['Video courses', (string)count(array_filter($items, fn($p) => $p['type'] === 'course'))], ['PDF notes packs', (string)count(array_filter($items, fn($p) => $p['type'] === 'notes'))],
                   ['Paid / free', $paidN . ' / ' . (count($items) - $paidN)], ['Upcoming batches', (string)count(array_filter($items, fn($p) => $p['starts_at'] && $p['starts_at'] >= $today))]]],
               ['head' => 'CONTENT & SALES', 'rows' => [['Video lectures', (string)$tot['video']], ['PDF lessons / notes PDFs', $tot['pdfl'] . ' / ' . $tot['files']], ['MCQs (quiz questions)', (string)$tot['mcq']], ['Students now', (string)$tot['students']], ['Sold · ' . $pl, $tot['sold'] . ' · ' . $rs($tot['amt'])]]]],
        'sections' => $sections,
        'notes' => ['"Students" / "Readers now" = accounts that can open the item today (bought, free or given by staff). "Sold" and "Amount" are paid orders in the chosen period (' . $range . ').']]);
}

// ---- one online course / notes pack in full
if ($type === 'ocourse') {
    $q = $pdo->prepare("SELECT * FROM sh_products WHERE id = ?"); $q->execute([$id]);
    $p = $q->fetch(PDO::FETCH_ASSOC) ?: $fail('Course not found');
    $pid = (int)$p['id'];
    $c = $content($pid)[$pid] ?? []; $s = $sales('2000-01-01', $today, $pid)[$pid] ?? [];
    $secs = $pdo->query("SELECT id, title FROM sh_sections WHERE product_id = $pid")->fetchAll(PDO::FETCH_KEY_PAIR);
    $notesBy = [];
    foreach ($pdo->query("SELECT lesson_id, COUNT(*) n FROM sh_files WHERE product_id = $pid AND lesson_id IS NOT NULL GROUP BY lesson_id") as $r) $notesBy[(int)$r['lesson_id']] = (int)$r['n'];
    $lessons = $pdo->query("SELECT l.*, s.sort AS ssort FROM sh_lessons l LEFT JOIN sh_sections s ON s.id = l.section_id WHERE l.product_id = $pid ORDER BY (s.sort IS NULL), s.sort, s.id, l.sort, l.id")->fetchAll(PDO::FETCH_ASSOC);
    $lBody = [];
    foreach ($lessons as $i => $l) {
        $isPdf = ($l['kind'] ?? 'video') === 'pdf';
        $src = $isPdf ? 'PDF' . ((int)$l['pdf_pages'] ? ' · ' . (int)$l['pdf_pages'] . ' pages' : '') : ($l['video_file'] ? 'Uploaded video' : ($l['video_url'] ? (sh_yt_id((string)$l['video_url']) ? 'YouTube' : 'Video link') : 'No video yet'));
        $lBody[] = [$i + 1, $secs[(int)$l['section_id']] ?? '-', ($l['lecture_no'] ? 'L' . (int)$l['lecture_no'] . ' · ' : '') . $l['title'], $src, $l['duration'] ?: '-', (int)$l['free'] ? 'Free' : '-',
            (string)($notesBy[(int)$l['id']] ?? 0), (string)count(sh_quiz($l)['items']), (string)count(sh_vvi($l)['qa'])];
    }
    $files = $pdo->query("SELECT f.title, f.pages, f.free, l.title AS ltitle FROM sh_files f LEFT JOIN sh_lessons l ON l.id = f.lesson_id WHERE f.product_id = $pid ORDER BY (f.lesson_id IS NOT NULL), f.sort, f.id")->fetchAll(PDO::FETCH_ASSOC);
    $totalL = count(array_filter($lessons, fn($l) => ($l['kind'] ?? 'video') !== 'pdf'));
    $allL = array_map('intval', array_column($lessons, 'id'));
    $st = $pdo->prepare("SELECT a.user_id, a.source, a.granted_at, a.expires_at, a.revoked, u.name, u.mobile,
        (SELECT COUNT(*) FROM sh_progress g WHERE g.user_id = a.user_id AND g.done = 1 AND g.lesson_id IN (" . ($allL ? implode(',', $allL) : '0') . ")) AS done
        FROM sh_access a JOIN sh_users u ON u.id = a.user_id WHERE a.product_id = ? ORDER BY a.granted_at DESC"); $st->execute([$pid]);
    $studs = $st->fetchAll(PDO::FETCH_ASSOC);
    $how = fn($r) => (int)$r['revoked'] ? 'Removed' : (['purchase' => 'Bought', 'free' => 'Free', 'admin' => 'Given by staff'][$r['source']] ?? ucfirst($r['source']));
    $isCourse = $p['type'] === 'course';
    $teacher = sh_teacher_name($pdo, (int)($p['teacher_id'] ?? 0)) ?: '-';
    $sections = [];
    if ($isCourse) $sections[] = ['title' => 'Lectures', 'right_text' => count($lessons) . ' lesson(s)', 'head' => ['No.', 'Chapter', 'Lecture', 'Video / PDF', 'Length', 'Preview', 'Notes PDFs', 'MCQs', 'VVI'],
        'body' => $lBody, 'foot' => $lessons ? [['', '', 'Total', '', '', (string)$n($c, 'freel'), (string)array_sum($notesBy), (string)$n($c, 'mcq'), (string)$n($c, 'vvi')]] : null, 'right' => [6, 7, 8], 'small' => true, 'empty' => 'No lectures yet.'];
    $sections[] = ['title' => $isCourse ? 'PDF files (course and lecture notes)' : 'PDF files', 'right_text' => count($files) . ' file(s)', 'head' => ['No.', 'PDF', 'Pages', 'Free', 'Lecture'],
        'body' => array_map(fn($f, $i) => [$i + 1, $f['title'], $f['pages'] ? (string)(int)$f['pages'] : '-', (int)$f['free'] ? 'Free' : '-', $f['ltitle'] ?: ($isCourse ? 'Whole course' : '-')], $files, array_keys($files)), 'right' => [2], 'small' => true, 'empty' => 'No PDF files.'];
    $sections[] = ['title' => 'Students', 'right_text' => count($studs) . ' student(s)', 'head' => array_merge(['No.', 'Student', 'Mobile', 'How', 'Since', 'Access till'], $isCourse ? ['Lectures done'] : []),
        'body' => array_map(fn($r, $i) => array_merge([$i + 1, $r['name'], $r['mobile'], $how($r), $d($r['granted_at']), $r['expires_at'] ? $d($r['expires_at']) : 'Lifetime'], $isCourse ? [(int)$r['done'] . ' / ' . $totalL . ($totalL ? ' (' . round((int)$r['done'] * 100 / $totalL) . '%)' : '')] : []), $studs, array_keys($studs)),
        'small' => true, 'empty' => 'No students yet.'];
    $out(['title' => $isCourse ? 'ONLINE COURSE REPORT' : 'PDF NOTES REPORT', 'subtitle' => $p['title'], 'no' => 'OC-' . $pid, 'file' => 'online-' . ($isCourse ? 'course' : 'notes') . '_' . $p['slug'], 'landscape' => true,
        'boxes' => [['head' => strtoupper($typeName($p['type'])), 'rows' => [['Title', $p['title']], ['Category', $cats[(int)$p['category_id']] ?? '-'], ['Teacher', $teacher], ['Price', $priceTxt($p)],
                        ['Access', (int)$p['validity_days'] ? (int)$p['validity_days'] . ' days' : 'Lifetime'], ['Status', ucfirst($p['status']) . ($p['starts_at'] ? ' · batch ' . ($p['starts_at'] >= $today ? 'starts ' : 'started ') . $d($p['starts_at']) : '')]]],
                    ['head' => 'CONTENT & SALES', 'rows' => array_merge($isCourse ? [['Chapters', (string)$n($c, 'chapters')], ['Video lectures', $n($c, 'video') . ($n($c, 'yt') ? ' (' . $n($c, 'yt') . ' YouTube' . ($n($c, 'upload') ? ', ' . $n($c, 'upload') . ' uploaded' : '') . ')' : '')], ['PDF lessons', (string)$n($c, 'pdfl')], ['MCQs / VVI questions', $n($c, 'mcq') . ' / ' . $n($c, 'vvi')]] : [],
                        [['PDF files / pages', $n($c, 'files') . ' / ' . $n($c, 'pages')], ['Students now', (string)$n($s, 'students')], ['Paid orders (all time)', $n($s, 'sold') . ' · ' . $rs($s['amt'] ?? 0)], ['Free / given by staff', $n($s, 'free') . ' / ' . $n($s, 'staff')], ['Rating', isset($s['rating']) ? $s['rating'] . ' / 5 (' . $s['reviews'] . ' review(s))' : '-']])]],
        'sections' => $sections,
        'notes' => [$isCourse ? '"Lectures done" counts video and PDF lessons the student marked done.' : 'Readers can open the PDFs on any device.']]);
}
