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
    return is_array($d) ? $d + ['products' => [], 'users' => [], 'orders' => []] : ['products' => [], 'users' => [], 'orders' => []];
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
        'desc' => "A demo PDF notes pack. Buyers read it on the screen (phone or computer) with their own watermark.", 'price' => 49, 'mrp' => 99, 'days' => 0, 'sort' => 3, 'thumb' => 'demo-thumb-formulas.jpg']);
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
    edu_settings_save('shop', ['demo' => json_encode($info)]);
    return '4 demo courses / notes, 3 demo students and 3 paid demo orders added. Demo login: 9000000001 / ' . SH_DEMO_PASS;
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
    $pdo->exec("DELETE FROM sh_orders WHERE id IN (" . $in($oids) . ") OR user_id IN (" . $in($uids) . ") OR product_id IN (" . $in($pids) . ")");
    foreach (['sh_lessons', 'sh_sections', 'sh_files'] as $t) $pdo->exec("DELETE FROM $t WHERE product_id IN (" . $in($pids) . ")");
    $pdo->exec("DELETE FROM sh_products WHERE id IN (" . $in($pids) . ")");
    $pdo->exec("DELETE FROM sh_users WHERE id IN (" . $in($uids) . ")");
    foreach ($files as $f) { $p = sh_media_path($f); if (is_file($p)) @unlink($p); } // every file of the demo products (also ones uploaded later)
    foreach ($pids as $pid) @rmdir(sh_media_dir() . '/' . $pid);
    edu_settings_save('shop', ['demo' => '']);
    return 'Demo data removed (' . count($pids) . ' products, ' . count($uids) . ' accounts, ' . count($oids) . ' orders).';
}
