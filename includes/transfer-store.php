<?php
// Import & Export for the institute and the online store (admin/import-export.php):
//  * Courses & PDF notes as a ZIP (manifest.json + media/): every field, category, chapters, units, lectures, PDFs and
//    notes files, thumbnails — and, if chosen, the uploaded lecture videos. Imports on this site or another copy of it.
//  * Students, orders and fee payments as CSV files (open in Excel / Google Sheets), with filters; students can be imported
//    back from such a CSV (or any sheet with Name + Mobile columns).
require_once __DIR__ . '/shop.php';
require_once __DIR__ . '/institute.php';
require_once __DIR__ . '/tools.php';

const EDU_TXS_FIELDS = ['type', 'title', 'slug', 'subtitle', 'description', 'price', 'mrp', 'validity_days', 'status', 'sort', 'faq', 'includes',
    'starts_at', 'ends_at', 'intro_url', 'yt_list', 'yt_section', 'yt_skip', 'free_list', 'created_at'];
const EDU_TXS_LESSON = ['title', 'video_url', 'size', 'height', 'duration', 'notes', 'free', 'sort', 'lecture_no', 'kind', 'pdf_pages', 'vvi', 'quiz', 'doubts_on'];

/** Products picked by the export filters. */
function edu_txs_pick(PDO $pdo, array $f): array
{
    $w = ['1']; $a = [];
    if (in_array($f['type'] ?? '', ['course', 'notes'], true)) { $w[] = 'type = ?'; $a[] = $f['type']; }
    if (in_array($f['status'] ?? '', ['published', 'draft'], true)) { $w[] = 'status = ?'; $a[] = $f['status']; }
    if (($f['price'] ?? '') === 'paid') $w[] = 'price > 0';
    if (($f['price'] ?? '') === 'free') $w[] = 'price <= 0';
    if ((int)($f['category'] ?? 0)) { $w[] = 'category_id = ?'; $a[] = (int)$f['category']; }
    $ids = array_filter(array_map('intval', (array)($f['ids'] ?? [])));
    if ($ids) $w[] = 'id IN (' . implode(',', $ids) . ')';
    $q = $pdo->prepare("SELECT * FROM sh_products WHERE " . implode(' AND ', $w) . " ORDER BY type, sort, id");
    $q->execute($a);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

/** Export ZIP of courses / notes. Returns [zip path, count, files]. */
function edu_txs_export(PDO $pdo, array $f, bool $videos): array
{
    sh_tables($pdo);
    $items = edu_txs_pick($pdo, $f);
    if (!$items) throw new RuntimeException('No course or notes match these filters.');
    $zipFile = edu_private_dir('exports') . '/courses-' . date('Y-m-d-His') . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Could not create the ZIP.');
    $added = [];
    $addPub = function (?string $url) use ($zip, &$added): string { // /uploads/… picture → media/uploads/…
        $url = (string)$url;
        if (!str_starts_with($url, '/uploads/')) return $url;
        $abs = ROOT_PATH . $url;
        if (!is_file($abs)) return $url;
        $in = 'media' . $url;
        if (!isset($added[$in])) { $zip->addFile($abs, $in); $added[$in] = 1; }
        return 'zip:' . $in;
    };
    $addPriv = function (?string $rel) use ($zip, &$added): string { // private store file → media/store/…
        $rel = ltrim((string)$rel, '/');
        if ($rel === '') return '';
        $abs = sh_media_path($rel);
        if (!is_file($abs)) return '';
        $in = 'media/store/' . $rel;
        if (!isset($added[$in])) { $zip->addFile($abs, $in); $added[$in] = 1; }
        return 'zip:' . $in;
    };
    $classes = $pdo->query("SELECT id, name FROM ci_classes")->fetchAll(PDO::FETCH_KEY_PAIR);
    $out = [];
    foreach ($items as $p) {
        $pid = (int)$p['id'];
        $it = array_intersect_key($p, array_flip(EDU_TXS_FIELDS));
        $it['thumb'] = $addPub($p['thumb']);
        $it['class'] = $classes[(int)$p['class_id']] ?? '';
        $c = $p['category_id'] ? $pdo->query("SELECT name, slug, icon, description FROM sh_categories WHERE id = " . (int)$p['category_id'])->fetch(PDO::FETCH_ASSOC) : null;
        $it['category'] = $c ?: null;
        $it['sections'] = array_map(fn($s) => ['k' => (int)$s['id'], 'title' => $s['title'], 'sort' => (int)$s['sort'], 'yt_list' => $s['yt_list']],
            $pdo->query("SELECT * FROM sh_sections WHERE product_id = $pid ORDER BY sort, id")->fetchAll(PDO::FETCH_ASSOC));
        $it['units'] = array_map(fn($u) => ['k' => (int)$u['id'], 'section' => (int)$u['section_id'], 'title' => $u['title'], 'sort' => (int)$u['sort']],
            $pdo->query("SELECT * FROM sh_units WHERE product_id = $pid ORDER BY sort, id")->fetchAll(PDO::FETCH_ASSOC));
        $it['lessons'] = [];
        foreach ($pdo->query("SELECT * FROM sh_lessons WHERE product_id = $pid ORDER BY sort, id")->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $x = array_intersect_key($l, array_flip(EDU_TXS_LESSON)) + ['k' => (int)$l['id'], 'section' => (int)$l['section_id'], 'unit' => (int)$l['unit_id']];
            $x['pdf_file'] = $addPriv($l['pdf_file']);
            $x['thumb'] = str_starts_with((string)$l['thumb'], '/uploads/') ? $addPub($l['thumb']) : (string)$l['thumb'];
            $x['video_file'] = $videos ? $addPriv($l['video_file']) : '';
            $x['variants'] = [];
            if ($videos) foreach ((array)json_decode((string)$l['variants'], true) as $h => $rel) if ($z = $addPriv((string)$rel)) $x['variants'][$h] = $z;
            if (!$videos && $l['video_file']) $x['video_left_out'] = 1;
            $it['lessons'][] = $x;
        }
        $it['files'] = array_values(array_filter(array_map(fn($fl) => ($z = $addPriv($fl['file'])) ? ['title' => $fl['title'], 'file' => $z, 'size' => (int)$fl['size'], 'pages' => (int)$fl['pages'], 'free' => (int)$fl['free'], 'sort' => (int)$fl['sort'], 'lesson' => (int)$fl['lesson_id']] : null,
            $pdo->query("SELECT * FROM sh_files WHERE product_id = $pid ORDER BY sort, id")->fetchAll(PDO::FETCH_ASSOC))));
        $out[] = $it;
    }
    $zip->addFromString('manifest.json', json_encode(['format' => 'dk-store', 'version' => 1, 'site' => SITE_URL, 'created' => date('c'), 'videos' => $videos, 'items' => $out],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $zip->close();
    return [$zipFile, count($out), count($added)];
}

/** Reads a courses ZIP. */
function edu_txs_read(string $file): array
{
    $zip = new ZipArchive();
    if ($zip->open($file) !== true) throw new RuntimeException('This file is not a ZIP.');
    $m = json_decode((string)$zip->getFromName('manifest.json'), true);
    $zip->close();
    if (!is_array($m) || ($m['format'] ?? '') !== 'dk-store' || !is_array($m['items'] ?? null)) throw new RuntimeException('This is not a Courses & Notes export (manifest.json is missing).');
    return $m;
}

/** What the ZIP holds and which items are already here (same address). */
function edu_txs_scan(PDO $pdo, array $m): array
{
    $q = $pdo->prepare("SELECT id, title FROM sh_products WHERE slug = ?");
    $items = [];
    foreach ($m['items'] as $i => $it) {
        $q->execute([(string)$it['slug']]);
        $ex = $q->fetch(PDO::FETCH_ASSOC);
        $items[] = ['i' => $i, 'title' => (string)$it['title'], 'type' => $it['type'] === 'notes' ? 'PDF notes' : 'Course', 'lessons' => count($it['lessons'] ?? []), 'exists' => $ex ? (string)$ex['title'] : ''];
    }
    // the same shape as the posts / pages check (edu_tx_scan), so one Import box handles both
    $conf = [];
    foreach ($items as $x) if ($x['exists'] !== '') $conf[] = ['i' => $x['i'], 'title' => $x['title'], 'path' => '/courses/' . $m['items'][$x['i']]['slug'], 'existing' => $x['exists']];
    return ['type' => 'store', 'count' => count($items), 'conflicts' => $conf, 'authors' => [], 'videos' => !empty($m['videos']),
        'source' => ['url' => (string)($m['site'] ?? ''), 'date' => (string)($m['created'] ?? ''), 'app' => 'edumint'],
        'titles' => array_map(fn($x) => $x['title'] . ' (' . $x['type'] . ', ' . $x['lessons'] . ' lectures)', array_slice($items, 0, 8))];
}

/** One item in: skip / replace (same course, its chapters & lectures replaced) / keep_both (a new course with a new address). */
function edu_txs_import_item(PDO $pdo, ZipArchive $zip, array $it, array $opt): array
{
    $title = (string)$it['title'];
    $q = $pdo->prepare("SELECT id FROM sh_products WHERE slug = ?"); $q->execute([(string)$it['slug']]);
    $exId = (int)$q->fetchColumn();
    $dec = $opt['decision'] ?? 'keep_both';
    if ($exId && $dec === 'skip') return ['outcome' => 'skipped', 'title' => $title, 'url' => ''];
    // category (by address; made when missing) and class (by name)
    $cat = null;
    if (!empty($it['category']['slug'])) {
        $c = $pdo->prepare("SELECT id FROM sh_categories WHERE slug = ?"); $c->execute([$it['category']['slug']]);
        $cat = (int)$c->fetchColumn() ?: null;
        if (!$cat) { $pdo->prepare("INSERT INTO sh_categories (name, slug, icon, description) VALUES (?, ?, ?, ?)")->execute([$it['category']['name'], $it['category']['slug'], $it['category']['icon'] ?? null, $it['category']['description'] ?? null]); $cat = (int)$pdo->lastInsertId(); }
    }
    $cls = null;
    if (!empty($it['class'])) { $c = $pdo->prepare("SELECT id FROM ci_classes WHERE name = ?"); $c->execute([$it['class']]); $cls = (int)$c->fetchColumn() ?: null; }
    $pubFile = function (string $v) use ($zip): string { // zip:media/uploads/… → /uploads/… (kept when the same file is already there)
        if (!str_starts_with($v, 'zip:media/uploads/')) return $v;
        $in = substr($v, 4); $url = substr($in, 5); $abs = ROOT_PATH . $url;
        $data = $zip->getFromName($in);
        if ($data === false) return '';
        if (is_file($abs) && md5_file($abs) === md5($data)) return $url;
        if (is_file($abs)) { $url = preg_replace('/(\.\w+)$/', '-' . substr(md5($data), 0, 6) . '$1', $url); $abs = ROOT_PATH . $url; }
        if (!is_dir(dirname($abs))) @mkdir(dirname($abs), 0755, true);
        file_put_contents($abs, $data);
        return $url;
    };
    $pdo->beginTransaction();
    try {
        $f = array_intersect_key($it, array_flip(EDU_TXS_FIELDS));
        unset($f['created_at']);
        if (($opt['status'] ?? 'file') !== 'file') $f['status'] = $opt['status'];
        $f['thumb'] = $pubFile((string)($it['thumb'] ?? ''));
        $f['category_id'] = $cat; $f['class_id'] = $cls;
        foreach (['starts_at', 'ends_at', 'intro_url', 'yt_list', 'faq', 'includes', 'subtitle', 'description'] as $k) if (array_key_exists($k, $f) && $f[$k] === '') $f[$k] = null;
        if ($exId && $dec === 'replace') {
            $pid = $exId;
            unset($f['slug']);
            $pdo->prepare("UPDATE sh_products SET " . implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($f))) . " WHERE id = ?")->execute([...array_values($f), $pid]);
            foreach (['sh_files', 'sh_lessons', 'sh_units', 'sh_sections'] as $t) $pdo->prepare("DELETE FROM $t WHERE product_id = ?")->execute([$pid]);
            $outcome = 'replaced';
        } else {
            $base = (string)$it['slug']; $slug = $base; $n = 2;
            $chk = $pdo->prepare("SELECT COUNT(*) FROM sh_products WHERE slug = ?");
            while (true) { $chk->execute([$slug]); if (!(int)$chk->fetchColumn()) break; $slug = $base . '-' . $n++; }
            $f['slug'] = $slug;
            $pdo->prepare("INSERT INTO sh_products (" . implode(', ', array_map(fn($k) => "`$k`", array_keys($f))) . ") VALUES (" . implode(',', array_fill(0, count($f), '?')) . ")")->execute(array_values($f));
            $pid = (int)$pdo->lastInsertId();
            $outcome = 'imported';
        }
        $privFile = function (string $v) use ($zip, $pid): ?string { // zip:media/store/… → <pid>/name in the private store folder
            if (!str_starts_with($v, 'zip:media/store/')) return null;
            $in = substr($v, 4);
            $st = $zip->getStream($in);
            if (!$st) return null;
            $rel = $pid . '/' . preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($in, PATHINFO_FILENAME)) . '-' . bin2hex(random_bytes(3)) . '.' . strtolower(pathinfo($in, PATHINFO_EXTENSION));
            $abs = sh_media_path($rel);
            if (!is_dir(dirname($abs))) @mkdir(dirname($abs), 0750, true);
            $o = fopen($abs, 'wb'); stream_copy_to_stream($st, $o); fclose($o); fclose($st);
            return $rel;
        };
        $secMap = $unitMap = $lesMap = [];
        foreach ((array)($it['sections'] ?? []) as $s) { $pdo->prepare("INSERT INTO sh_sections (product_id, title, sort, yt_list) VALUES (?, ?, ?, ?)")->execute([$pid, $s['title'], (int)$s['sort'], $s['yt_list'] ?: null]); $secMap[(int)$s['k']] = (int)$pdo->lastInsertId(); }
        foreach ((array)($it['units'] ?? []) as $u) { $pdo->prepare("INSERT INTO sh_units (product_id, section_id, title, sort) VALUES (?, ?, ?, ?)")->execute([$pid, $secMap[(int)$u['section']] ?? null, $u['title'], (int)$u['sort']]); $unitMap[(int)$u['k']] = (int)$pdo->lastInsertId(); }
        $videosOut = 0;
        foreach ((array)($it['lessons'] ?? []) as $l) {
            $row = array_intersect_key($l, array_flip(EDU_TXS_LESSON));
            $row['product_id'] = $pid; $row['section_id'] = $secMap[(int)$l['section']] ?? null; $row['unit_id'] = $unitMap[(int)$l['unit']] ?? null;
            $row['video_file'] = $privFile((string)($l['video_file'] ?? ''));
            $row['pdf_file'] = $privFile((string)($l['pdf_file'] ?? ''));
            $row['thumb'] = ($t = (string)($l['thumb'] ?? '')) !== '' ? $pubFile($t) : null;
            $va = [];
            foreach ((array)($l['variants'] ?? []) as $h => $z) if ($r = $privFile((string)$z)) $va[$h] = $r;
            $row['variants'] = $va ? json_encode($va) : null;
            if (!empty($l['video_left_out'])) $videosOut++;
            $pdo->prepare("INSERT INTO sh_lessons (" . implode(', ', array_map(fn($k) => "`$k`", array_keys($row))) . ") VALUES (" . implode(',', array_fill(0, count($row), '?')) . ")")->execute(array_values($row));
            $lesMap[(int)$l['k']] = (int)$pdo->lastInsertId();
        }
        foreach ((array)($it['files'] ?? []) as $fl) {
            if (!($rel = $privFile((string)$fl['file']))) continue;
            $pdo->prepare("INSERT INTO sh_files (product_id, title, file, size, pages, free, sort, lesson_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$pid, $fl['title'], $rel, (int)$fl['size'], (int)$fl['pages'], (int)$fl['free'], (int)$fl['sort'], $lesMap[(int)$fl['lesson']] ?? null]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    $p = sh_product($pdo, $pid, false);
    return ['outcome' => $outcome, 'title' => $title, 'url' => $p ? sh_url($p) : '', 'note' => $videosOut ? $videosOut . ' uploaded video(s) were not in the file — add them again' : ''];
}

/* ---------- CSV (Excel) ---------- */

function edu_csv_send(string $name, array $head, iterable $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    $o = fopen('php://output', 'w');
    fwrite($o, "\xEF\xBB\xBF"); // Excel reads Hindi / ₹ correctly
    fputcsv($o, $head);
    foreach ($rows as $r) fputcsv($o, $r);
    fclose($o);
}

/** Students CSV with the filters of the Students list. */
function edu_csv_students(PDO $pdo, array $f): array
{
    $w = ['1']; $a = [];
    if (isset(CI_STYPES[$f['type'] ?? ''])) { $w[] = 's.stype = ?'; $a[] = $f['type']; }
    if ((int)($f['class'] ?? 0)) { $w[] = 's.class_id = ?'; $a[] = (int)$f['class']; }
    if (in_array($f['status'] ?? '', ['active', 'left', 'completed'], true)) { $w[] = 's.status = ?'; $a[] = $f['status']; }
    if ($d = ci_clean_date($f['from'] ?? '')) { $w[] = 's.admission_date >= ?'; $a[] = $d; }
    if ($d = ci_clean_date($f['to'] ?? '')) { $w[] = 's.admission_date <= ?'; $a[] = $d; }
    $shop = (bool)$pdo->query("SHOW TABLES LIKE 'sh_access'")->fetch();
    $q = $pdo->prepare("SELECT s.*, c.name AS class_name,
        (SELECT GROUP_CONCAT(CONCAT(co.name, IFNULL(CONCAT(' / ', b.name), '')) SEPARATOR '; ') FROM ci_enrollments e LEFT JOIN ci_courses co ON co.id = e.course_id LEFT JOIN ci_batches b ON b.id = e.batch_id WHERE e.student_id = s.id AND e.status <> 'dropped') AS courses,
        (SELECT COALESCE(SUM(CASE WHEN e.status = 'dropped' THEN 0 ELSE e.fee - e.discount END),0) FROM ci_enrollments e WHERE e.student_id = s.id) AS net,
        (SELECT COALESCE(SUM(p.amount),0) FROM ci_payments p WHERE p.student_id = s.id) AS paid"
        . ($shop ? ", (SELECT GROUP_CONCAT(pr.title SEPARATOR '; ') FROM sh_users u JOIN sh_access ac ON ac.user_id = u.id AND ac.revoked = 0 AND ac.blocked = 0 JOIN sh_products pr ON pr.id = ac.product_id WHERE u.student_id = s.id) AS online" : ", '' AS online")
        . " FROM ci_students s LEFT JOIN ci_classes c ON c.id = s.class_id WHERE " . implode(' AND ', $w) . " ORDER BY s.reg_no");
    $q->execute($a);
    $rows = [];
    foreach ($q as $r) $rows[] = [$r['reg_no'], $r['name'], CI_STYPES[$r['stype']] ?? $r['stype'], $r['father_name'], $r['mother_name'], $r['dob'], $r['gender'], $r['mobile'], $r['alt_mobile'], $r['email'],
        $r['address'], $r['city'], $r['state'], $r['pincode'], $r['class_name'], $r['school'], ucfirst($r['status']), $r['admission_date'], $r['courses'], $r['online'],
        round((float)$r['net'], 2), round((float)$r['paid'], 2), round(max(0, (float)$r['net'] - (float)$r['paid']), 2), $r['notes']];
    return $rows;
}
const EDU_CSV_STUDENT_HEAD = ['Reg. no.', 'Name', 'Type', "Father's name", "Mother's name", 'Date of birth', 'Gender', 'Mobile', 'Alternate mobile', 'Email', 'Address', 'City', 'State', 'PIN code', 'Class', 'School', 'Status', 'Admission date', 'Institute courses / batches', 'Online courses / notes', 'Fee', 'Paid', 'Due', 'Notes'];

/** Students from a CSV: needs Name + Mobile; a student with the same name + mobile is skipped. Returns [added, skipped, errors]. */
function edu_csv_import_students(PDO $pdo, string $file, int $by): array
{
    ci_tables($pdo);
    $h = fopen($file, 'rb');
    if (!$h) throw new RuntimeException('Could not read the file.');
    $first = (string)fgets($h);
    $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);
    $sep = substr_count($first, ';') > substr_count($first, ',') ? ';' : (substr_count($first, "\t") > substr_count($first, ',') ? "\t" : ',');
    $head = array_map(fn($x) => strtolower(trim(preg_replace('/[^a-z ]/i', '', (string)$x))), str_getcsv($first, $sep));
    $col = function (array $names) use ($head) { foreach ($names as $n) { $i = array_search($n, $head, true); if ($i !== false) return $i; } return null; };
    $c = ['name' => $col(['name', 'student name', 'full name']), 'mobile' => $col(['mobile', 'mobile number', 'phone']), 'type' => $col(['type', 'student type']),
        'father' => $col(['fathers name', 'father name', 'father']), 'mother' => $col(['mothers name', 'mother name', 'mother']), 'dob' => $col(['date of birth', 'dob']),
        'gender' => $col(['gender']), 'alt' => $col(['alternate mobile', 'alt mobile']), 'email' => $col(['email']), 'address' => $col(['address']), 'city' => $col(['city']),
        'state' => $col(['state']), 'pin' => $col(['pin code', 'pincode', 'pin']), 'class' => $col(['class']), 'school' => $col(['school']), 'status' => $col(['status']),
        'adm' => $col(['admission date', 'admitted']), 'notes' => $col(['notes'])];
    if ($c['name'] === null || $c['mobile'] === null) { fclose($h); throw new RuntimeException('The sheet needs a "Name" and a "Mobile" column (first row = column names).'); }
    $classes = array_change_key_case(array_flip($pdo->query("SELECT id, name FROM ci_classes")->fetchAll(PDO::FETCH_KEY_PAIR)), CASE_LOWER);
    $types = array_change_key_case(array_flip(CI_STYPES), CASE_LOWER);
    $dup = $pdo->prepare("SELECT COUNT(*) FROM ci_students WHERE mobile = ? AND LOWER(name) = LOWER(?)");
    $added = $skipped = 0; $errors = []; $line = 1;
    $v = fn(array $r, ?int $i, int $max = 150) => $i === null ? '' : mb_substr(trim((string)($r[$i] ?? '')), 0, $max);
    while (($r = fgetcsv($h, 0, $sep)) !== false) {
        $line++;
        if (!array_filter($r, fn($x) => trim((string)$x) !== '')) continue;
        $name = $v($r, $c['name']);
        $mob = preg_replace('/\D+/', '', $v($r, $c['mobile'], 20));
        if (strlen($mob) === 12 && str_starts_with($mob, '91')) $mob = substr($mob, 2);
        if ($name === '' || !preg_match('/^[6-9]\d{9}$/', $mob)) { $errors[] = "Row $line: name or 10-digit mobile missing"; continue; }
        $dup->execute([$mob, $name]);
        if ((int)$dup->fetchColumn()) { $skipped++; continue; }
        $type = $types[strtolower($v($r, $c['type']))] ?? 'institute';
        $st = strtolower($v($r, $c['status']));
        $date = fn($x) => ($t = strtotime($x)) ? date('Y-m-d', $t) : null;
        try {
            $pdo->prepare("INSERT INTO ci_students (reg_no, name, father_name, mother_name, dob, gender, mobile, alt_mobile, email, address, city, state, pincode, class_id, school, status, stype, admission_date, notes, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
                ci_next_no($pdo, 'ci_students', 'reg_no', CI_REG_PREFIX, 6), $name, $v($r, $c['father']) ?: null, $v($r, $c['mother']) ?: null, $date($v($r, $c['dob'])),
                in_array(ucfirst(strtolower($v($r, $c['gender']))), ['Male', 'Female', 'Other'], true) ? ucfirst(strtolower($v($r, $c['gender']))) : null, $mob,
                preg_replace('/\D+/', '', $v($r, $c['alt'], 20)) ?: null, filter_var($v($r, $c['email']), FILTER_VALIDATE_EMAIL) ?: null, $v($r, $c['address'], 500) ?: null,
                $v($r, $c['city'], 100) ?: null, $v($r, $c['state'], 100) ?: null, preg_match('/^\d{6}$/', $v($r, $c['pin'], 10)) ? $v($r, $c['pin'], 10) : null,
                $classes[strtolower($v($r, $c['class']))] ?? null, $v($r, $c['school']) ?: null, in_array($st, ['active', 'left', 'completed'], true) ? $st : 'active', $type,
                $date($v($r, $c['adm'])) ?: date('Y-m-d'), $v($r, $c['notes'], 2000) ?: null, $by]);
            $added++;
        } catch (Throwable $e) { $errors[] = "Row $line: " . $e->getMessage(); }
    }
    fclose($h);
    return [$added, $skipped, $errors];
}

/** Orders CSV (date range, status, product). */
function edu_csv_orders(PDO $pdo, array $f): array
{
    $w = ["DATE(COALESCE(o.paid_at, o.created_at)) BETWEEN ? AND ?"];
    $a = [ci_clean_date($f['from'] ?? '') ?: '2000-01-01', ci_clean_date($f['to'] ?? '') ?: date('Y-m-d')];
    if (in_array($f['status'] ?? '', ['paid', 'created', 'failed'], true)) { $w[] = 'o.status = ?'; $a[] = $f['status']; }
    if ((int)($f['product'] ?? 0)) { $w[] = 'o.product_id = ?'; $a[] = (int)$f['product']; }
    $q = $pdo->prepare("SELECT o.*, u.name, u.mobile, u.email, s.reg_no FROM sh_orders o LEFT JOIN sh_users u ON u.id = o.user_id LEFT JOIN ci_students s ON s.id = u.student_id WHERE " . implode(' AND ', $w) . " ORDER BY o.id");
    $q->execute($a);
    $rows = [];
    foreach ($q as $o) $rows[] = [$o['order_no'] ?: '#' . $o['id'], $o['paid_at'] ?: $o['created_at'], $o['name'], $o['mobile'], str_ends_with((string)$o['email'], '@noemail.invalid') ? '' : $o['email'], $o['reg_no'], $o['title'], round((float)$o['amount'], 2),
        ['paid' => 'Paid', 'created' => 'Not paid', 'failed' => 'Failed'][$o['status']] ?? $o['status'], str_starts_with((string)$o['rzp_order'], 'manual_') ? 'Office' : 'Online', $o['method'], $o['rzp_payment']];
    return $rows;
}
const EDU_CSV_ORDER_HEAD = ['Order', 'Date', 'Student', 'Mobile', 'Email', 'Reg. no.', 'Item', 'Amount', 'Status', 'Where', 'Method', 'Payment id'];

/** Fee payments (receipts) CSV for a date range. */
function edu_csv_fees(PDO $pdo, array $f): array
{
    $q = $pdo->prepare("SELECT p.*, s.reg_no, s.name, s.mobile, c.name AS course, b.name AS batch, u.username FROM ci_payments p JOIN ci_students s ON s.id = p.student_id
        LEFT JOIN ci_enrollments e ON e.id = p.enrollment_id LEFT JOIN ci_courses c ON c.id = e.course_id LEFT JOIN ci_batches b ON b.id = e.batch_id LEFT JOIN users u ON u.id = p.created_by
        WHERE p.paid_on BETWEEN ? AND ? ORDER BY p.paid_on, p.id");
    $q->execute([ci_clean_date($f['from'] ?? '') ?: '2000-01-01', ci_clean_date($f['to'] ?? '') ?: date('Y-m-d')]);
    $rows = [];
    foreach ($q as $p) $rows[] = [$p['receipt_no'], $p['paid_on'], $p['reg_no'], $p['name'], $p['mobile'], $p['course'], $p['batch'], round((float)$p['amount'], 2), ci_pay_mode($p), $p['note'], $p['username']];
    return $rows;
}
const EDU_CSV_FEE_HEAD = ['Receipt', 'Date', 'Reg. no.', 'Student', 'Mobile', 'Course', 'Batch', 'Amount', 'Paid by', 'Note', 'Collected by'];
