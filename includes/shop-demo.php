<?php
/**
 * Demo content for the online store, to show the software to someone: 2 video courses (an uploaded demo lesson with
 * 720p + 360p, and public Khan Academy YouTube lessons), 2 notes packs (real PDF notes), 3 demo student accounts,
 * paid demo orders (so the sales report has numbers) and access given by staff.
 * Everything made here is remembered (settings shop.demo) so "Remove demo data" deletes exactly that and nothing else.
 */
require_once __DIR__ . '/shop.php';

const SH_DEMO_PASS = 'Demo@1234';

function sh_demo_info(): array
{
    $d = json_decode((string)es('shop', 'demo', ''), true);
    $empty = ['products' => [], 'users' => [], 'orders' => [], 'staff' => [], 'slides' => [], 'free' => [], 'icons' => [], 'toppers' => [], 'tinfo' => []];
    return is_array($d) ? $d + $empty : $empty;
}
function sh_demo_on(): bool { return (bool)sh_demo_info()['products']; }

/** Adds the demo content (once). Returns a short summary. */
function sh_demo_add(PDO $pdo, int $byUser = 0): string
{
    sh_tables($pdo);
    if (sh_demo_on()) return 'Demo data is already there.';
    $src = __DIR__ . '/demo';
    foreach (['demo-lesson-720.mp4', 'demo-lesson-360.mp4', 'demo-class10-maths-formulas.pdf', 'demo-class12-physics-revision.pdf', 'demo-science-mcq-set.pdf'] as $f)
        if (!is_file("$src/$f")) throw new RuntimeException("Demo file missing: $f");
    $dir = sh_media_dir();
    if (!is_writable($dir)) throw new RuntimeException('The private media folder is not writable: ' . $dir);
    $info = ['products' => [], 'users' => [], 'orders' => []];
    $copy = function (string $file, int $pid) use ($src, $dir) {
        @mkdir("$dir/$pid", 0750, true);
        $rel = $pid . '/demo-' . bin2hex(random_bytes(4)) . '-' . $file;
        if (!@copy("$src/$file", "$dir/$rel")) throw new RuntimeException("Could not copy $file");
        return $rel;
    };
    $prod = function (array $p) use ($pdo, &$info, $src): int {
        $thumb = null; // picture: copied into /uploads/store (public, like any product picture)
        if (!empty($p['thumb']) && is_file("$src/{$p['thumb']}")) {
            @mkdir(ROOT_PATH . '/uploads/store', 0755, true);
            $name = 'demo-' . bin2hex(random_bytes(3)) . '-' . $p['thumb'];
            if (@copy("$src/{$p['thumb']}", ROOT_PATH . '/uploads/store/' . $name)) $thumb = '/uploads/store/' . $name;
        }
        $pdo->prepare("INSERT INTO sh_products (type, title, slug, subtitle, description, thumb, price, mrp, validity_days, status, sort) VALUES (?,?,?,?,?,?,?,?,?, 'published', ?)")
            ->execute([$p['type'], $p['title'], sh_unique_slug($pdo, $p['title']), $p['subtitle'], $p['desc'], $thumb, $p['price'], $p['mrp'], $p['days'], $p['sort']]);
        return $info['products'][] = (int)$pdo->lastInsertId();
    };
    $sec = function (int $pid, string $t, int $sort) use ($pdo): int { $pdo->prepare("INSERT INTO sh_sections (product_id, title, sort) VALUES (?,?,?)")->execute([$pid, $t, $sort]); return (int)$pdo->lastInsertId(); };
    $yt = function (int $pid, ?int $sid, string $title, string $id, string $dur, int $free, int $sort) use ($pdo) {
        $pdo->prepare("INSERT INTO sh_lessons (product_id, section_id, title, video_url, duration, free, sort) VALUES (?,?,?,?,?,?,?)")
            ->execute([$pid, $sid, $title, 'https://www.youtube.com/watch?v=' . $id, $dur, $free, $sort]);
    };
    $pdf = function (int $pid, string $title, string $file, int $pages, int $free, int $sort) use ($pdo, $copy, $src) {
        $pdo->prepare("INSERT INTO sh_files (product_id, title, file, size, pages, free, sort) VALUES (?,?,?,?,?,?,?)")
            ->execute([$pid, $title, $copy($file, $pid), filesize("$src/$file"), $pages, $free, $sort]);
    };

    // 1. Class 10 Maths course: our own uploaded lesson (720p + 360p) + Khan Academy YouTube lessons + formula PDF
    $c1 = $prod(['type' => 'course', 'title' => 'Class 10 Maths — Complete Course (Demo)', 'subtitle' => 'Real Numbers, Polynomials, Quadratic Equations and AP — recorded classes with notes',
        'desc' => "A demo course to show how video courses work.\n\nThe first lesson is uploaded to our own server (try the ⚙ button: speed up to 2x and quality 720p / 360p). The other lessons are public Khan Academy videos on YouTube, added as links.\n\nIncludes the Class 10 formula sheet as a PDF.",
        'price' => 999, 'mrp' => 1999, 'days' => 365, 'sort' => 1, 'thumb' => 'demo-thumb-maths10.jpg']);
    $s1 = $sec($c1, 'Chapter 1 — Real Numbers', 1);
    $pdo->prepare("INSERT INTO sh_lessons (product_id, section_id, title, video_file, size, variants, height, duration, free, notes, sort) VALUES (?,?,?,?,?,?,?,?,1,?,1)")
        ->execute([$c1, $s1, 'Introduction to Real Numbers (uploaded demo lesson)', $copy('demo-lesson-720.mp4', $c1), filesize("$src/demo-lesson-720.mp4"),
            json_encode(['360' => $copy('demo-lesson-360.mp4', $c1)]), 720, '0:42', "This lesson is a video file on our own server. Use ⚙ to change speed (0.5x–2x) and quality."]);
    $yt($c1, $s1, "Euclid's division algorithm visualised", 'qgcgZISJlEc', '9:59', 1, 2);
    $yt($c1, $s1, 'Rational numbers — terminating decimals', 'eT2W2v5dDSA', '9:16', 0, 3);
    $s2 = $sec($c1, 'Chapter 2 — Polynomials', 2);
    $yt($c1, $s2, 'Adding and subtracting polynomials', 'ZGl2ExHwdak', '1:43', 0, 4);
    $yt($c1, $s2, 'Polynomial division — introduction', '3eowXt_LNbg', '5:26', 0, 5);
    $s3 = $sec($c1, 'Chapter 4 & 5 — Quadratic Equations, AP', 3);
    $yt($c1, $s3, 'Quadratic equations — unit summary', 'NtTgKkxbeZg', '22:38', 0, 6);
    $yt($c1, $s3, 'Arithmetic Progression — full chapter', 'Thb37iY18to', '2:09:30', 0, 7);
    $pdf($c1, 'Class 10 Maths — Important Formulas', 'demo-class10-maths-formulas.pdf', 3, 0, 1);

    // 2. Class 12 Physics course (YouTube only) + revision PDF
    $c2 = $prod(['type' => 'course', 'title' => 'Class 12 Physics — Electrostatics (Demo)', 'subtitle' => "Electric charge and Coulomb's law — YouTube lessons with revision notes",
        'desc' => "A demo course made only from YouTube links (public Khan Academy videos), with a quick-revision PDF.", 'price' => 1499, 'mrp' => 2499, 'days' => 0, 'sort' => 2, 'thumb' => 'demo-thumb-physics12.jpg']);
    $s4 = $sec($c2, 'Electric Charges and Fields', 1);
    $yt($c2, $s4, 'Electric charge and electric force', 'lU8qZ0UyZUk', '11:59', 1, 1);
    $yt($c2, $s4, "Coulomb's law", 'UI4TbcMQP8w', '11:17', 0, 2);
    $yt($c2, $s4, "Introduction to charge and Coulomb's law", 'NXMgvrS8Gr8', '14:02', 0, 3);
    $pdf($c2, 'Class 12 Physics — Quick Revision', 'demo-class12-physics-revision.pdf', 2, 1, 1);

    // 3. Paid notes pack, 4. free notes pack
    $n1 = $prod(['type' => 'notes', 'title' => 'Class 10 Maths Formula Notes (Demo)', 'subtitle' => 'Every chapter\'s formulas on 3 pages — perfect for last-minute revision',
        'desc' => "A demo PDF notes pack. Read it on your phone, tablet or computer.", 'price' => 49, 'mrp' => 99, 'days' => 0, 'sort' => 3, 'thumb' => 'demo-thumb-formulas.jpg']);
    $pdf($n1, 'Class 10 Maths — Important Formulas', 'demo-class10-maths-formulas.pdf', 3, 0, 1);
    $n2 = $prod(['type' => 'notes', 'title' => 'Science MCQ Practice Set (Free Demo)', 'subtitle' => '10 important questions with answers',
        'desc' => "A free demo notes pack — anyone with an account can open it.", 'price' => 0, 'mrp' => null, 'days' => 0, 'sort' => 4, 'thumb' => 'demo-thumb-mcq.jpg']);
    $pdf($n2, 'Science MCQ Practice Set', 'demo-science-mcq-set.pdf', 2, 0, 1);

    // demo students (accounts) — the password is the same for all
    $mk = function (string $name, string $mobile, string $email) use ($pdo, $byUser, &$info): int {
        $q = $pdo->prepare("SELECT id FROM sh_users WHERE mobile = ? OR email = ?"); $q->execute([$mobile, $email]);
        if ($q->fetchColumn()) throw new RuntimeException("A demo account ($mobile) already exists — remove it first.");
        $id = sh_register($pdo, $name, $mobile, $email, SH_DEMO_PASS, $byUser ?: null);
        return $info['users'][] = $id;
    };
    $u1 = $mk('Demo Student', '9000000001', 'demo.student1@example.com');
    $u2 = $mk('Riya Sharma (Demo)', '9000000002', 'demo.student2@example.com');
    $u3 = $mk('Aman Verma (Demo)', '9000000003', 'demo.student3@example.com');
    // paid demo orders (dated over the last days, so reports show sales)
    $order = function (int $uid, int $pid, int $daysAgo, string $method) use ($pdo, &$info) {
        $p = sh_product($pdo, $pid);
        $at = date('Y-m-d H:i:s', strtotime("-$daysAgo days 11:30"));
        $pdo->prepare("INSERT INTO sh_orders (user_id, product_id, title, amount, status, rzp_order, rzp_payment, method, created_at, paid_at) VALUES (?,?,?,?, 'paid', ?, ?, ?, ?, ?)")
            ->execute([$uid, $pid, $p['title'], $p['price'], 'order_DEMO' . bin2hex(random_bytes(5)), 'pay_DEMO' . bin2hex(random_bytes(5)), $method, $at, $at]);
        $oid = (int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE sh_orders SET order_no = ? WHERE id = ?")->execute(['DEMO-' . str_pad((string)$oid, 5, '0', STR_PAD_LEFT), $oid]);
        sh_grant($pdo, $uid, $pid, 'purchase', $oid);
        $pdo->prepare("UPDATE sh_access SET granted_at = ? WHERE user_id = ? AND product_id = ?")->execute([$at, $uid, $pid]);
        $info['orders'][] = $oid;
    };
    $order($u1, $c1, 3, 'upi');
    $order($u1, $n1, 1, 'upi');
    $order($u2, $c2, 2, 'card');
    sh_grant($pdo, $u2, $n2, 'free');
    sh_grant($pdo, $u3, $c1, 'admin', null, $byUser ?: null, 'Demo: offline batch student, given by staff');
    // a little progress for the first student
    $l = $pdo->query("SELECT id FROM sh_lessons WHERE product_id = $c1 ORDER BY sort LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($l as $lid) $pdo->prepare("INSERT IGNORE INTO sh_progress (user_id, lesson_id, done) VALUES (?, ?, 1)")->execute([$u1, $lid]);
    sh_demo_reviews($pdo, $info);
    edu_settings_save('shop', ['demo' => json_encode($info)]);
    sh_demo_extras($pdo);
    return '4 demo courses / notes, 3 demo students and 3 paid demo orders added. Demo login: 9000000001 / ' . SH_DEMO_PASS;
}

/** Reviews by the demo students on the demo items they have (also for a demo added before reviews existed). */
function sh_demo_reviews(PDO $pdo, ?array $info = null): int
{
    $info ??= sh_demo_info();
    if (!$info['products'] || !$info['users']) return 0;
    [$c1, $c2, $n1, $n2] = array_pad(array_map('intval', $info['products']), 4, 0);
    [$u1, $u2, $u3] = array_pad(array_map('intval', $info['users']), 3, 0);
    $rv = [
        [$c1, $u1, 5, "Very clear explanation of Real Numbers. The 2x speed and the notes PDF helped me revise quickly before my test."],
        [$c1, $u3, 4, "Good course, the chapter-wise videos are easy to follow. I would like more practice questions."],
        [$n1, $u1, 5, "All formulas on 3 pages — perfect for the last day before the exam."],
        [$c2, $u2, 4, "Coulomb's law finally makes sense. Short and to the point."],
        [$n2, $u2, 5, null],
    ];
    $q = $pdo->prepare("INSERT IGNORE INTO sh_reviews (product_id, user_id, rating, body, created_at) VALUES (?, ?, ?, ?, ?)");
    $n = 0;
    foreach ($rv as $i => [$p, $u, $r, $b]) if ($p && $u && (int)$pdo->query("SELECT COUNT(*) FROM sh_access WHERE user_id = $u AND product_id = $p AND revoked = 0")->fetchColumn()) { $q->execute([$p, $u, $r, $b, date('Y-m-d H:i:s', strtotime('-' . (4 - min(4, $i)) . ' days'))]); $n += $q->rowCount(); }
    return $n;
}

/** Course FAQ / what you get, lecture numbers, a unit, VVI questions, quizzes and lecture notes on the demo course
    (also upgrades a demo added before these existed). Safe to run again. */
function sh_demo_extras(PDO $pdo): string
{
    sh_tables($pdo);
    $d = sh_demo_info();
    if (!$d['products']) return 'No demo data.';
    [$c1, $c2] = array_map('intval', array_slice($d['products'], 0, 2));
    $j = fn($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $pdo->prepare("UPDATE sh_products SET faq = COALESCE(faq, ?), includes = COALESCE(includes, ?) WHERE id = ?")->execute([
        $j([['q' => 'Is this course in Hindi or English?', 'a' => 'Classes are explained in simple Hindi with English terms, so both kinds of students can follow.'],
            ['q' => 'Does it follow the NCERT / CBSE syllabus?', 'a' => 'Yes — chapters follow the NCERT Class 10 Maths book, chapter by chapter.']]),
        $j(['Chapter-wise recorded lectures', 'VVI questions and a quiz under each lecture', 'Ask doubts to the teacher under any lecture', 'Formula notes PDF']), $c1]);
    $pdo->prepare("UPDATE sh_products SET faq = COALESCE(faq, ?), includes = COALESCE(includes, ?) WHERE id = ?")->execute([
        $j([['q' => 'Which board is this for?', 'a' => 'CBSE and state boards that follow NCERT Class 12 Physics.']]), $j(['Short, clear lectures', 'Quick revision PDF']), $c2]);
    // lecture numbers inside each chapter
    foreach ([$c1, $c2] as $pid) {
        $n = [];
        foreach ($pdo->query("SELECT id, section_id FROM sh_lessons WHERE product_id = $pid ORDER BY sort, id")->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $k = (int)$l['section_id']; $n[$k] = ($n[$k] ?? 0) + 1;
            $pdo->prepare("UPDATE sh_lessons SET lecture_no = COALESCE(lecture_no, ?) WHERE id = ?")->execute([$n[$k], $l['id']]);
        }
    }
    $ls = $pdo->query("SELECT id, section_id FROM sh_lessons WHERE product_id = $c1 ORDER BY sort, id LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
    if (count($ls) < 2) return 'Demo course has no lessons.';
    // a unit for the first two lectures of chapter 1
    $has = (int)$pdo->query("SELECT COUNT(*) FROM sh_units WHERE product_id = $c1")->fetchColumn();
    if (!$has) { $pdo->prepare("INSERT INTO sh_units (product_id, section_id, title, sort) VALUES (?, ?, ?, 1)")->execute([$c1, $ls[0]['section_id'], "Euclid's Division Lemma"]); $uid = (int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE sh_lessons SET unit_id = ? WHERE id IN (?, ?)")->execute([$uid, $ls[0]['id'], $ls[1]['id']]); }
    $vvi1 = ['qa' => [
        ['q' => "State Euclid's division lemma.", 'a' => "For any two positive integers a and b, there exist unique whole numbers q and r such that a = bq + r, where 0 ≤ r < b."],
        ['q' => 'Use Euclid\'s algorithm to find the HCF of 135 and 225.', 'a' => "225 = 135 × 1 + 90, 135 = 90 × 1 + 45, 90 = 45 × 2 + 0. So the HCF is 45."],
        ['q' => 'Show that every positive even integer is of the form 2q.', 'a' => "Take b = 2 in a = bq + r: r is 0 or 1. If r = 0, a = 2q is even; so every even integer is 2q."]],
        'links' => [['t' => 'More important questions — Latest Update', 'u' => rtrim(SITE_URL, '/') . '/latest-update']]];
    $quiz1 = ['items' => [
        ['q' => 'In a = bq + r, what is true about r?', 'o' => ['r < 0', '0 ≤ r < b', 'r > b', 'r = b'], 'a' => 1, 'e' => 'The remainder is always at least 0 and smaller than the divisor b.'],
        ['q' => 'HCF of 96 and 404 is…', 'o' => ['2', '4', '8', '12'], 'a' => 1, 'e' => '404 = 96 × 4 + 20, 96 = 20 × 4 + 16, 20 = 16 × 1 + 4, 16 = 4 × 4. HCF = 4.'],
        ['q' => 'If 17 = 5 × 3 + r, then r is…', 'o' => ['1', '2', '3', '5'], 'a' => 1, 'e' => '5 × 3 = 15, so r = 17 − 15 = 2.']], 'links' => []];
    $vvi2 = ['qa' => [['q' => 'What is the HCF of two consecutive integers?', 'a' => '1 — consecutive integers have no common factor other than 1.']], 'links' => []];
    $quiz2 = ['items' => [['q' => 'Euclid\'s algorithm is used to find the…', 'o' => ['LCM', 'HCF', 'Square root', 'Prime factors'], 'a' => 1, 'e' => 'It finds the HCF by repeated division.']], 'links' => []];
    $pdo->prepare("UPDATE sh_lessons SET vvi = COALESCE(vvi, ?), quiz = COALESCE(quiz, ?), doubts_on = 1 WHERE id = ?")->execute([$j($vvi1), $j($quiz1), $ls[0]['id']]);
    $pdo->prepare("UPDATE sh_lessons SET vvi = COALESCE(vvi, ?), quiz = COALESCE(quiz, ?), doubts_on = 1 WHERE id = ?")->execute([$j($vvi2), $j($quiz2), $ls[1]['id']]);
    // the formula notes as the notes PDF of lecture 1
    if (!(int)$pdo->query("SELECT COUNT(*) FROM sh_files WHERE lesson_id = " . (int)$ls[0]['id'])->fetchColumn()) {
        $src = __DIR__ . '/demo/demo-class10-maths-formulas.pdf';
        $rel = $c1 . '/demo-notes-' . bin2hex(random_bytes(4)) . '.pdf';
        if (is_file($src) && @copy($src, sh_media_dir() . '/' . $rel))
            $pdo->prepare("INSERT INTO sh_files (product_id, title, file, size, pages, free, sort, lesson_id) VALUES (?, ?, ?, ?, 3, 0, 1, ?)")->execute([$c1, 'Real Numbers — formula notes', $rel, filesize($src), $ls[0]['id']]);
    }
    return 'Demo lectures now have notes, VVI questions and quizzes.';
}

/** Client showcase (on top of the demo): 10 more video courses across the categories (with teachers, units, batch dates,
    FAQ, VVI + quiz), 5 notes packs, 12 more demo students with orders, progress, reviews and answered doubts, the
    homepage slider, free YouTube courses, category icons and the "students" number. Everything is removed by
    sh_demo_remove(). Content: includes/demo/showcase/ (showcase.json + pictures + PDFs). */
function sh_demo_showcase(PDO $pdo, int $byUser = 0): string
{
    sh_tables($pdo);
    if (!sh_demo_on()) sh_demo_add($pdo, $byUser);
    $info = sh_demo_info();
    if (!empty($info['showcase'])) return 'The showcase is already added.';
    $dir = __DIR__ . '/demo/showcase';
    $J = json_decode((string)@file_get_contents("$dir/showcase.json"), true);
    if (!$J) throw new RuntimeException('Showcase content is missing (includes/demo/showcase/showcase.json).');
    $j = fn($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $cats = $pdo->query("SELECT slug, id FROM sh_categories")->fetchAll(PDO::FETCH_KEY_PAIR);
    $pub = function (string $file, string $sub) use ($dir): string { // a picture into the public uploads folder
        $to = ROOT_PATH . '/uploads/' . $sub; @mkdir($to, 0755, true);
        $name = 'demo-' . bin2hex(random_bytes(3)) . '-' . $file;
        if (!@copy("$dir/$file", "$to/$name")) throw new RuntimeException("Could not copy $file");
        return 'uploads/' . $sub . '/' . $name;
    };
    $pdo->beginTransaction();
    try {
        // teachers: staff accounts with only the Teacher permission and a random password nobody knows
        $T = [];
        $i = 0;
        foreach ($J['teachers'] as $k => $name) {
            $i++;
            $email = "demo.teacher$i@example.com";
            $uname = strtolower(preg_replace('/[^A-Za-z0-9]+/', '.', $name)); // the user form needs a username without spaces
            $q = $pdo->prepare("SELECT id FROM users WHERE email = ? OR username = ?"); $q->execute([$email, $uname]);
            if ($id = (int)$q->fetchColumn()) { $T[$k] = $id; continue; }
            $pdo->prepare("INSERT INTO users (username, email, password_hash, role, status, permissions) VALUES (?, ?, ?, 'author', 'active', ?)")
                ->execute([$uname, $email, password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), $j(['store' => ['teacher' => true]])]);
            $T[$k] = $info['staff'][] = (int)$pdo->lastInsertId();
        }
        // category icons (only where the admin has not set one)
        foreach ($J['icons'] as $slug => $file) {
            if (empty($cats[$slug])) continue;
            $cur = (string)$pdo->query("SELECT icon FROM sh_categories WHERE id = " . (int)$cats[$slug])->fetchColumn();
            if ($cur !== '') continue;
            $rel = $pub($file, 'categories');
            $pdo->prepare("UPDATE sh_categories SET icon = ? WHERE id = ?")->execute([$rel, $cats[$slug]]);
            $info['icons'][(string)$cats[$slug]] = $rel;
        }
        // the first demo items get their category too
        foreach ($info['products'] as $pid) {
            $t = (string)$pdo->query("SELECT title FROM sh_products WHERE id = " . (int)$pid)->fetchColumn();
            $slug = preg_match('/Class\s+(\d{1,2})/i', $t, $m) ? 'class-' . $m[1] : (stripos($t, 'Science') !== false ? 'class-10' : '');
            if ($slug && !empty($cats[$slug])) $pdo->prepare("UPDATE sh_products SET category_id = ? WHERE id = ? AND category_id IS NULL")->execute([$cats[$slug], $pid]);
        }
        $first = $info['products'][0] ?? 0;
        if ($first && !empty($T['rk'])) $pdo->prepare("UPDATE sh_products SET teacher_id = ? WHERE id = ? AND teacher_id IS NULL")->execute([$T['rk'], $first]);
        if (!empty($info['products'][1]) && !empty($T['av'])) $pdo->prepare("UPDATE sh_products SET teacher_id = ? WHERE id = ? AND teacher_id IS NULL")->execute([$T['av'], $info['products'][1]]);
        // video courses
        $keys = []; $firstLesson = [];
        $sort = 10;
        foreach (array_reverse($J['courses'], true) as $ci => $c) { // inserted last-first, so the first course is the newest on the homepage
            $sort = 10 + (int)$ci;
            $pdo->prepare("INSERT INTO sh_products (type, title, slug, subtitle, description, thumb, price, mrp, validity_days, status, sort, category_id, teacher_id, starts_at, faq, includes)
                VALUES ('course',?,?,?,?,?,?,?,?, 'published', ?,?,?,?,?,?)")->execute([$c['title'], sh_unique_slug($pdo, $c['title']), $c['subtitle'], $c['desc'], '/' . $pub($c['thumb'], 'store'),
                $c['price'], $c['mrp'], $c['days'], $sort, $cats[$c['cat']] ?? null, $T[$c['teacher']] ?? null, $c['starts'] ? date('Y-m-d', strtotime('+' . (int)$c['starts'] . ' days')) : null,
                $j($c['faq']), $j($c['includes'])]);
            $pid = (int)$pdo->lastInsertId(); $info['products'][] = $pid; $keys[$c['key']] = $pid;
            $ls = 0; $ss = 0;
            foreach ($c['chapters'] as $ch) {
                $pdo->prepare("INSERT INTO sh_sections (product_id, title, sort) VALUES (?,?,?)")->execute([$pid, mb_substr($ch['t'], 0, 200), ++$ss]);
                $sid = (int)$pdo->lastInsertId(); $no = 0;
                foreach ($ch['l'] as $l) {
                    $pdo->prepare("INSERT INTO sh_lessons (product_id, section_id, title, video_url, duration, free, sort, lecture_no, doubts_on) VALUES (?,?,?,?,?,?,?,?,1)")
                        ->execute([$pid, $sid, mb_substr($l['t'], 0, 200), 'https://www.youtube.com/watch?v=' . $l['id'], $l['d'] ?: null, $ls < 1 ? 1 : 0, ++$ls, ++$no]);
                    if ($ls === 1) $firstLesson[$pid] = (int)$pdo->lastInsertId();
                }
            }
        }
        // VVI + quiz on the first lecture of the trigonometry course
        if (!empty($keys['sc-maths10-trig'])) {
            $pdo->prepare("UPDATE sh_lessons SET vvi = ?, quiz = ? WHERE id = ?")->execute([
                $j(['qa' => [['q' => 'What is sin θ in a right triangle?', 'a' => 'sin θ = opposite side ÷ hypotenuse.'],
                    ['q' => 'Write the value of sin 30°, cos 60° and tan 45°.', 'a' => 'sin 30° = 1/2, cos 60° = 1/2 and tan 45° = 1.'],
                    ['q' => 'Prove that sin²θ + cos²θ = 1.', 'a' => 'In a right triangle, (opposite)² + (adjacent)² = (hypotenuse)². Divide both sides by (hypotenuse)²: sin²θ + cos²θ = 1.']], 'links' => []]),
                $j(['items' => [['q' => 'tan θ is equal to…', 'o' => ['sin θ × cos θ', 'sin θ ÷ cos θ', 'cos θ ÷ sin θ', '1 ÷ sin θ'], 'a' => 1, 'e' => 'tan θ = opposite ÷ adjacent = sin θ ÷ cos θ.'],
                    ['q' => 'The value of sin 90° is…', 'o' => ['0', '1/2', '1', 'not defined'], 'a' => 2, 'e' => 'sin 90° = 1.'],
                    ['q' => 'If sin A = 3/5, then cos A = ?', 'o' => ['4/5', '3/4', '5/4', '5/3'], 'a' => 0, 'e' => 'cos A = √(1 − 9/25) = √(16/25) = 4/5.']], 'links' => []]),
                $firstLesson[$keys['sc-maths10-trig']]]);
        }
        // notes packs
        $media = sh_media_dir();
        foreach (array_reverse($J['notes'], true) as $ni => $n) {
            $sort = 40 + (int)$ni;
            $pdo->prepare("INSERT INTO sh_products (type, title, slug, subtitle, description, thumb, price, mrp, validity_days, status, sort, category_id) VALUES ('notes',?,?,?,?,?,?,?,0,'published',?,?)")
                ->execute([$n['title'], sh_unique_slug($pdo, $n['title']), $n['subtitle'], $n['desc'], '/' . $pub($n['thumb'], 'store'), $n['price'], $n['mrp'], $sort, $cats[$n['cat']] ?? null]);
            $pid = (int)$pdo->lastInsertId(); $info['products'][] = $pid; $keys[$n['key']] = $pid;
            @mkdir("$media/$pid", 0750, true);
            $rel = $pid . '/demo-' . bin2hex(random_bytes(4)) . '-' . $n['pdf'];
            if (!@copy("$dir/{$n['pdf']}", "$media/$rel")) throw new RuntimeException('Could not copy ' . $n['pdf']);
            $pdo->prepare("INSERT INTO sh_files (product_id, title, file, size, pages, free, sort) VALUES (?,?,?,?,?,0,1)")->execute([$pid, preg_replace('/\s*\((Free )?Demo\)$/', '', $n['title']), $rel, filesize("$media/$rel"), $n['pages']]);
        }
        // more demo students, their orders, progress, reviews and doubts
        $names = ['Priya Kumari', 'Rohit Raj', 'Ananya Mishra', 'Sahil Ansari', 'Kajal Kumari', 'Vikash Yadav', 'Sneha Gupta', 'Aditya Singh', 'Pooja Bharti', 'Rahul Paswan', 'Nikita Jha', 'Arjun Prasad'];
        $U = [];
        foreach ($names as $k => $nm) {
            $mob = '90000000' . str_pad((string)($k + 4), 2, '0', STR_PAD_LEFT); $em = 'demo.student' . ($k + 4) . '@example.com';
            $q = $pdo->prepare("SELECT id FROM sh_users WHERE mobile = ? OR email = ?"); $q->execute([$mob, $em]);
            if ($q->fetchColumn()) continue;
            $U[] = $info['users'][] = sh_register($pdo, $nm . ' (Demo)', $mob, $em, SH_DEMO_PASS, $byUser ?: null);
        }
        $buy = function (int $uid, int $pid, int $daysAgo, string $method) use ($pdo, &$info) {
            $p = sh_product($pdo, $pid, false); if (!$p) return;
            $at = date('Y-m-d H:i:s', strtotime("-$daysAgo days " . (9 + $daysAgo % 10) . ':' . str_pad((string)(($daysAgo * 7) % 60), 2, '0', STR_PAD_LEFT)));
            if ((float)$p['price'] <= 0) { sh_grant($pdo, $uid, $pid, 'free'); return; }
            $pdo->prepare("INSERT INTO sh_orders (user_id, product_id, title, amount, status, rzp_order, rzp_payment, method, created_at, paid_at) VALUES (?,?,?,?, 'paid', ?, ?, ?, ?, ?)")
                ->execute([$uid, $pid, $p['title'], $p['price'], 'order_DEMO' . bin2hex(random_bytes(5)), 'pay_DEMO' . bin2hex(random_bytes(5)), $method, $at, $at]);
            $oid = (int)$pdo->lastInsertId();
            $pdo->prepare("UPDATE sh_orders SET order_no = ? WHERE id = ?")->execute(['DEMO-' . str_pad((string)$oid, 5, '0', STR_PAD_LEFT), $oid]);
            sh_grant($pdo, $uid, $pid, 'purchase', $oid);
            $pdo->prepare("UPDATE sh_access SET granted_at = ? WHERE user_id = ? AND product_id = ?")->execute([$at, $uid, $pid]);
            $info['orders'][] = $oid;
        };
        $plan = [ // student index => [course keys]
            0 => ['sc-maths10-trig', 'sc-science10'], 1 => ['sc-science10', 'sn-maths6'], 2 => ['sc-maths10-trig', 'sc-science9'], 3 => ['sc-maths9', 'sn-sci9'],
            4 => ['sc-science9', 'sc-maths9'], 5 => ['sc-maths8', 'sn-maths7'], 6 => ['sc-bio11', 'sn-bio11'], 7 => ['sc-chem12', 'sn-chem12'],
            8 => ['sc-evs5', 'sc-sst7'], 9 => ['sc-maths10-trig', 'sc-bst11'], 10 => ['sc-science10', 'sc-bio11'], 11 => ['sc-sst7', 'sc-maths8']];
        $ways = ['upi', 'upi', 'card', 'upi', 'netbanking', 'upi', 'wallet'];
        $d = 2;
        foreach ($plan as $k => $list) foreach ($list as $key) if (!empty($U[$k]) && !empty($keys[$key])) { $buy($U[$k], $keys[$key], $d, $ways[$d % 7]); $d = ($d + 3) % 41 + 1; }
        // progress: the first lectures done
        foreach ($plan as $k => $list) foreach ($list as $key) if (!empty($U[$k]) && !empty($keys[$key]))
            foreach ($pdo->query("SELECT id FROM sh_lessons WHERE product_id = " . (int)$keys[$key] . " ORDER BY sort LIMIT " . (1 + $k % 4))->fetchAll(PDO::FETCH_COLUMN) as $lid)
                $pdo->prepare("INSERT IGNORE INTO sh_progress (user_id, lesson_id, done) VALUES (?, ?, 1)")->execute([$U[$k], $lid]);
        $rv = [
            ['sc-maths10-trig', 0, 5, 'Trigonometry was my weakest chapter. After these lectures I solved all the NCERT questions myself. The quiz under each lecture is very helpful.'],
            ['sc-maths10-trig', 2, 5, 'Heights and distances explained so simply! Sir draws every figure step by step.'],
            ['sc-maths10-trig', 9, 4, 'Very good crash course. Please add more PYQs for AP.'],
            ['sc-science10', 0, 5, 'Board Booster batch is worth it — short lectures, clear notes and my doubts were answered the same day.'],
            ['sc-science10', 1, 4, 'Metallurgy and microbiology chapters are explained very well. Good for revision.'],
            ['sc-science10', 10, 5, 'I like that I can watch on my phone at 1.5x speed. Ma\'am explains with real-life examples.'],
            ['sc-science9', 2, 5, 'Carbon and biotechnology chapters became easy. Thank you DK Academy!'],
            ['sc-science9', 4, 4, 'Nice foundation course for Class 9. Notes would make it perfect.'],
            ['sc-maths9', 3, 5, 'One lecture per chapter — I revised the whole syllabus in a week before my exam.'],
            ['sc-maths9', 4, 4, 'Quick and to the point. Good for last-minute revision.'],
            ['sc-maths8', 5, 5, 'Number Play chapter is explained with fun puzzles. My son enjoys these classes.'],
            ['sc-maths8', 11, 4, 'Good explanation of every "Figure it out" question.'],
            ['sc-sst7', 8, 5, 'Stories make history easy to remember. Very nice teaching.'],
            ['sc-sst7', 11, 4, 'Maps and examples are clear. Helpful for school tests.'],
            ['sc-evs5', 8, 5, 'Perfect for small kids — simple language and colourful examples.'],
            ['sc-bio11', 6, 5, 'Photosynthesis finally clicked for me. Great for NEET basics.'],
            ['sc-bio11', 10, 4, 'Diagrams are explained step by step. Want more chapters soon!'],
            ['sc-bst11', 9, 4, 'Quick revision of all chapters, very useful before the half-yearly exam.'],
            ['sc-chem12', 7, 5, 'Polymers is a scoring chapter and this masterclass covers every board question.'],
            ['sn-sci9', 3, 5, 'Short summaries — exactly what I needed the night before the test.'],
            ['sn-bio11', 6, 5, null], ['sn-chem12', 7, 4, 'Clear notes. Examples of each polymer type are listed well.'], ['sn-maths7', 5, 5, null], ['sn-maths6', 1, 5, 'Good worksheets for daily practice.'],
        ];
        $q = $pdo->prepare("INSERT IGNORE INTO sh_reviews (product_id, user_id, rating, body, created_at) VALUES (?, ?, ?, ?, ?)");
        foreach ($rv as $n => [$key, $k, $r, $b]) if (!empty($keys[$key]) && !empty($U[$k])) $q->execute([$keys[$key], $U[$k], $r, $b, date('Y-m-d H:i:s', strtotime('-' . (1 + $n % 20) . ' days'))]);
        $dq = [
            ['sc-maths10-trig', 0, 'rk', 'Sir, why is sin 90° = 1? I am confused.', 'When the angle becomes 90°, the opposite side becomes equal to the hypotenuse, so opposite ÷ hypotenuse = 1. Draw the triangle and make the angle bigger step by step — you will see it.'],
            ['sc-maths10-trig', 2, 'rk', 'In heights and distances, how do I know which ratio to use?', 'Look at what you know and what you need. If you know the side next to the angle and need the height, use tan. If the slant line (hypotenuse) is involved, use sin or cos.'],
            ['sc-science10', 0, 'ns', 'Ma\'am, what is the difference between alloying and galvanization?', 'Alloying mixes a metal with other elements to change its properties (like steel). Galvanization coats iron with zinc to stop rusting.'],
            ['sc-science9', 4, 'ns', 'Is charcoal a pure form of carbon?', 'Charcoal is an amorphous (non-crystalline) form of carbon. It is mostly carbon, but not as pure as diamond or graphite.'],
            ['sc-bio11', 6, 'ns', 'Why is chlorophyll green?', 'Chlorophyll absorbs mostly red and blue light and reflects green light — that is why leaves look green.'],
            ['sc-chem12', 7, 'av', 'Is natural rubber an addition polymer?', 'Yes. Natural rubber is a polymer of isoprene (cis-1,4-polyisoprene), made by addition polymerisation.'],
        ];
        foreach ($dq as $n => [$key, $k, $tk, $qq, $aa]) if (!empty($keys[$key]) && !empty($U[$k]) && !empty($firstLesson[$keys[$key]])) {
            $at = date('Y-m-d H:i:s', strtotime('-' . (2 + $n) . ' days'));
            $pdo->prepare("INSERT INTO sh_doubts (lesson_id, product_id, user_id, question, answer, answered_by, status, created_at, answered_at) VALUES (?,?,?,?,?,?, 'answered', ?, ?)")
                ->execute([$firstLesson[$keys[$key]], $keys[$key], $U[$k], $qq, $aa, $T[$tk] ?? null, $at, date('Y-m-d H:i:s', strtotime($at . ' +3 hours'))]);
        }
        // homepage slider
        $si = (int)$pdo->query("SELECT COALESCE(MAX(sort),0) FROM hm_slides")->fetchColumn();
        foreach ($J['slides'] as $sl) {
            $link = str_starts_with($sl['link'], 'COURSE:') ? (($p = sh_product($pdo, (int)($keys[substr($sl['link'], 7)] ?? 0), false)) ? sh_url($p) : '/store/') : $sl['link'];
            $pdo->prepare("INSERT INTO hm_slides (image, mobile_image, link, title, new_tab, sort, active) VALUES (?,?,?,?,0,?,1)")->execute([$pub($sl['image'], 'slides'), $pub($sl['mobile'], 'slides'), $link, $sl['title'], ++$si]);
            $info['slides'][] = (int)$pdo->lastInsertId();
        }
        // free courses (YouTube playlists)
        $fi = 0;
        foreach ($J['free'] as $f) {
            $pdo->prepare("INSERT INTO hm_free (url, list_id, video_id, title, thumb, channel, videos, category_id, sort, active) VALUES (?,?,?,?,?,?,?,?,?,1)")
                ->execute(['https://www.youtube.com/playlist?list=' . $f['list'], $f['list'], $f['video'] ?: null, $f['title'], $f['thumb'], $f['channel'], $f['videos'], $f['cat'] ? ($cats[$f['cat']] ?? null) : null, ++$fi]);
            $info['free'][] = (int)$pdo->lastInsertId();
        }
        // "Our Impact": a showcase number of students (the others are counted by the site); the old setting comes back on removal
        $info['home_prev'] = (string)es('home', 'impact', '');
        $imp = sh_home_cfg()['impact'];
        foreach ($imp as &$it) if (($it['k'] ?? '') === 'students' && trim((string)$it['v']) === '') $it['v'] = '2,500+';
        unset($it);
        edu_settings_save('home', ['impact' => $j($imp)]);
        $info['showcase'] = 1;
        edu_settings_save('shop', ['demo' => json_encode($info)]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    sh_home_refresh();
    try { sh_demo_people($pdo); } catch (Throwable $e) { error_log('demo people: ' . $e->getMessage()); }
    return 'Showcase added: ' . count($J['courses']) . ' courses, ' . count($J['notes']) . ' notes packs, ' . count($U) . ' more students, slider, free courses and category icons.';
}

/** Demo teacher profiles (photo, subject, qualification, experience) and toppers for "Meet our teachers / toppers".
    Runs as part of the showcase; can also be run on its own for a showcase added before these sections. */
function sh_demo_people(PDO $pdo): string
{
    sh_tables($pdo);
    $info = sh_demo_info();
    if (!empty($info['people'])) return 'Teachers and toppers are already added.';
    $dir = __DIR__ . '/demo/showcase';
    $J = json_decode((string)@file_get_contents("$dir/people.json"), true);
    if (!$J) throw new RuntimeException('Demo people are missing (includes/demo/showcase/people.json).');
    $pub = function (string $file, string $sub) use ($dir): string {
        $to = ROOT_PATH . '/uploads/' . $sub; @mkdir($to, 0755, true);
        $name = 'demo-' . bin2hex(random_bytes(3)) . '-' . $file;
        if (!@copy("$dir/$file", "$to/$name")) throw new RuntimeException("Could not copy $file");
        return 'uploads/' . $sub . '/' . $name;
    };
    $SC = json_decode((string)@file_get_contents("$dir/showcase.json"), true) ?: [];
    foreach ($J['teachers'] as $i => $t) {
        $uid = (int)$pdo->query("SELECT id FROM users WHERE email = " . $pdo->quote('demo.teacher' . ($i + 1) . '@example.com'))->fetchColumn();
        if (!$uid) continue;
        $shown = (string)($SC['teachers'][$t['key']] ?? '');
        $pdo->prepare("UPDATE users SET username = ? WHERE id = ? AND username LIKE '% %'")->execute([strtolower(preg_replace('/[^A-Za-z0-9]+/', '.', $shown ?: 'teacher' . ($i + 1))), $uid]); // older demos had spaces
        if (sh_teacher_info($pdo, $uid)) { if ($shown !== '') $pdo->prepare("UPDATE sh_teacher_info SET display_name = COALESCE(display_name, ?) WHERE user_id = ?")->execute([$shown, $uid]); continue; }
        $pdo->prepare("INSERT INTO sh_teacher_info (user_id, display_name, photo, qualification, subject, experience, about, show_home, sort) VALUES (?,?,?,?,?,?,?,1,?)")
            ->execute([$uid, $shown ?: null, $pub($t['file'], 'teachers'), $t['qualification'], $t['subject'], $t['experience'], $t['about'], $i + 1]);
        $info['tinfo'][] = $uid;
    }
    $n = (int)$pdo->query("SELECT COALESCE(MAX(sort),0) FROM hm_toppers")->fetchColumn();
    foreach ($J['toppers'] as $t) {
        $pdo->prepare("INSERT INTO hm_toppers (name, photo, result, class_name, year, sort, active) VALUES (?,?,?,?,?,?,1)")->execute([$t['name'], $pub($t['file'], 'toppers'), $t['result'], $t['class'], $t['year'], ++$n]);
        $info['toppers'][] = (int)$pdo->lastInsertId();
    }
    $info['people'] = 1;
    edu_settings_save('shop', ['demo' => json_encode($info)]);
    sh_home_refresh();
    return count($info['tinfo']) . ' teacher profiles and ' . count($J['toppers']) . ' toppers added.';
}

/** Removes exactly what sh_demo_add made (products with their videos / PDFs, demo accounts, demo orders). */
function sh_demo_remove(PDO $pdo): string
{
    sh_tables($pdo);
    $d = sh_demo_info();
    $pids = array_map('intval', $d['products']); $uids = array_map('intval', $d['users']); $oids = array_map('intval', $d['orders']);
    $in = fn(array $a) => $a ? implode(',', $a) : '0';
    $files = [];
    foreach ($pdo->query("SELECT video_file, variants FROM sh_lessons WHERE product_id IN (" . $in($pids) . ")")->fetchAll(PDO::FETCH_ASSOC) as $l) foreach (array_filter(array_merge([$l['video_file']], array_values(sh_variants($l)))) as $f) $files[] = $f;
    foreach ($pdo->query("SELECT file FROM sh_files WHERE product_id IN (" . $in($pids) . ")")->fetchAll(PDO::FETCH_COLUMN) as $f) $files[] = $f;
    foreach ($pdo->query("SELECT thumb FROM sh_products WHERE id IN (" . $in($pids) . ")")->fetchAll(PDO::FETCH_COLUMN) as $t)
        if ($t && str_starts_with($t, '/uploads/store/demo-')) @unlink(ROOT_PATH . $t); // only the demo pictures
    $lessonIds = $pdo->query("SELECT id FROM sh_lessons WHERE product_id IN (" . $in($pids) . ")")->fetchAll(PDO::FETCH_COLUMN);
    $pdo->exec("DELETE FROM sh_progress WHERE user_id IN (" . $in($uids) . ") OR lesson_id IN (" . $in(array_map('intval', $lessonIds)) . ")");
    $pdo->exec("DELETE FROM sh_access WHERE user_id IN (" . $in($uids) . ") OR product_id IN (" . $in($pids) . ")");
    $pdo->exec("DELETE FROM sh_reviews WHERE user_id IN (" . $in($uids) . ") OR product_id IN (" . $in($pids) . ")");
    $pdo->exec("DELETE FROM sh_orders WHERE id IN (" . $in($oids) . ") OR user_id IN (" . $in($uids) . ") OR product_id IN (" . $in($pids) . ")");
    $pdo->exec("DELETE FROM sh_doubts WHERE product_id IN (" . $in($pids) . ")");
    foreach (['sh_lessons', 'sh_sections', 'sh_units', 'sh_files'] as $t) $pdo->exec("DELETE FROM $t WHERE product_id IN (" . $in($pids) . ")");
    $pdo->exec("DELETE FROM sh_products WHERE id IN (" . $in($pids) . ")");
    $pdo->exec("DELETE FROM sh_users WHERE id IN (" . $in($uids) . ")");
    foreach ($files as $f) { $p = sh_media_path($f); if (is_file($p)) @unlink($p); } // every file of the demo products (also ones uploaded later)
    foreach ($pids as $pid) @rmdir(sh_media_dir() . '/' . $pid);
    // showcase parts: slider, free courses, teacher accounts, category icons, the impact number
    foreach (array_map('intval', $d['slides']) as $sid) {
        $r = $pdo->query("SELECT image, mobile_image FROM hm_slides WHERE id = $sid")->fetch(PDO::FETCH_ASSOC);
        if ($r) { sh_image_drop($r['image']); sh_image_drop($r['mobile_image']); $pdo->exec("DELETE FROM hm_slides WHERE id = $sid"); }
    }
    $pdo->exec("DELETE FROM hm_free WHERE id IN (" . $in(array_map('intval', $d['free'])) . ")");
    foreach ($d['icons'] as $cid => $rel) {
        $q = $pdo->prepare("UPDATE sh_categories SET icon = NULL WHERE id = ? AND icon = ?"); $q->execute([(int)$cid, $rel]);
        if ($q->rowCount()) sh_image_drop($rel);
    }
    foreach (array_map('intval', $d['toppers']) as $tid) { $ph = (string)$pdo->query("SELECT photo FROM hm_toppers WHERE id = $tid")->fetchColumn(); $pdo->exec("DELETE FROM hm_toppers WHERE id = $tid"); sh_image_drop($ph); }
    foreach (array_map('intval', $d['tinfo']) as $tu) { $ph = (string)$pdo->query("SELECT photo FROM sh_teacher_info WHERE user_id = $tu")->fetchColumn(); $pdo->exec("DELETE FROM sh_teacher_info WHERE user_id = $tu"); sh_image_drop($ph); }
    $staff = array_map('intval', $d['staff']);
    if ($staff) {
        $pdo->exec("UPDATE sh_products SET teacher_id = NULL WHERE teacher_id IN (" . $in($staff) . ")");
        $demoT = $pdo->query("SELECT id FROM users WHERE id IN (" . $in($staff) . ") AND email LIKE 'demo.teacher%@example.com'")->fetchAll(PDO::FETCH_COLUMN);
        if ($demoT) { $pdo->exec("DELETE FROM authors WHERE user_id IN (" . $in(array_map('intval', $demoT)) . ")"); $pdo->exec("DELETE FROM sh_teacher_info WHERE user_id IN (" . $in(array_map('intval', $demoT)) . ")"); }
        $pdo->exec("DELETE FROM users WHERE id IN (" . $in($staff) . ") AND email LIKE 'demo.teacher%@example.com'");
    }
    if (!empty($d['showcase'])) edu_settings_save('home', ['impact' => (string)($d['home_prev'] ?? '')]);
    edu_settings_save('shop', ['demo' => '']);
    if (function_exists('sh_home_refresh')) sh_home_refresh();
    return 'Demo data removed (' . count($pids) . ' products, ' . count($uids) . ' accounts, ' . count($oids) . ' orders).';
}
