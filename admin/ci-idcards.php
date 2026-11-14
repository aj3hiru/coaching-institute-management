<?php
// Student ID cards (Institute only) — opened from Reports → ID Cards, printed or saved as PDF from the browser.
// Card (54 × 85.6 mm portrait, the size of a bank card): the institute's logo, name, address, phone and email on top; the student's
// photo, name, class, roll no., admission ID, mobile, address and batch; a Code 128 barcode of the admission ID that the
// attendance page's barcode scanner reads. 9 cards on an A4 page.
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/institute.php';
$me = ci_page_start($pdo);
$f = ['class' => (int)($_GET['class'] ?? 0), 'course' => (int)($_GET['course'] ?? 0), 'batch' => (int)($_GET['batch'] ?? 0), 'student' => (int)($_GET['student'] ?? 0)];
$w = ["s.stype <> 'online'"]; $a = [];
if ($f['student']) { $w[] = 's.id = ?'; $a[] = $f['student']; }
else $w[] = "s.status = 'active'";
if ($f['class']) { $w[] = 's.class_id = ?'; $a[] = $f['class']; }
if ($f['course'] || $f['batch']) {
    $sub = "EXISTS (SELECT 1 FROM ci_enrollments e WHERE e.student_id = s.id AND e.status = 'active'" . ($f['course'] ? ' AND e.course_id = ?' : '') . ($f['batch'] ? ' AND e.batch_id = ?' : '') . ')';
    $w[] = $sub; if ($f['course']) $a[] = $f['course']; if ($f['batch']) $a[] = $f['batch'];
}
$q = $pdo->prepare("SELECT s.*, c.name AS class_name,
        (SELECT GROUP_CONCAT(DISTINCT b.name ORDER BY b.name SEPARATOR ', ') FROM ci_enrollments e JOIN ci_batches b ON b.id = e.batch_id WHERE e.student_id = s.id AND e.status = 'active' AND b.status = 'active') AS batches
    FROM ci_students s LEFT JOIN ci_classes c ON c.id = s.class_id WHERE " . implode(' AND ', $w) . " ORDER BY c.sort IS NULL, c.sort, CAST(s.roll_no AS UNSIGNED), s.name LIMIT 1000");
$q->execute($a);
$list = $q->fetchAll(PDO::FETCH_ASSOC);
$inst = ci_institute();
$logo = $inst['logo'] ? (preg_match('~^https?://~', $inst['logo']) ? $inst['logo'] : '/' . ltrim($inst['logo'], '/')) : '';
$sid = ci_current_session($pdo);
$session = $sid ? (string)$pdo->query("SELECT name FROM ci_sessions WHERE id = " . (int)$sid)->fetchColumn() : '';
$names = [];
if ($f['class']) $names[] = 'Class ' . (string)$pdo->query("SELECT name FROM ci_classes WHERE id = " . $f['class'])->fetchColumn();
if ($f['course']) $names[] = (string)$pdo->query("SELECT name FROM ci_courses WHERE id = " . $f['course'])->fetchColumn();
if ($f['batch']) $names[] = 'Batch ' . (string)$pdo->query("SELECT name FROM ci_batches WHERE id = " . $f['batch'])->fetchColumn();
$title = $f['student'] && $list ? $list[0]['name'] : ($names ? implode(' · ', $names) : 'All active students');
$addr = fn(array $s) => implode(', ', array_filter([trim((string)$s['address']), trim((string)$s['city']), trim((string)$s['state']), trim((string)$s['pincode'])]));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>ID cards — <?= ci_e($title) ?></title>
<style>
:root{--idc:#4a3369;--idc2:#646c3e}
*{box-sizing:border-box}
body{margin:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;color:#111827;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.bar{position:sticky;top:0;z-index:5;display:flex;flex-wrap:wrap;align-items:center;gap:10px;padding:12px 16px;background:#fff;border-bottom:1px solid #e5e7eb}
.bar b{font-size:15px}.bar span{color:#6b7280;font-size:13px}
.bar .sp{flex:1}
.bar a,.bar button{display:inline-flex;align-items:center;gap:6px;height:36px;padding:0 14px;border:1px solid #d1d5db;background:#fff;color:#374151;font:inherit;font-size:14px;font-weight:600;text-decoration:none;cursor:pointer}
.bar button.pr{background:var(--idc);border-color:var(--idc);color:#fff}
.sheet{width:210mm;margin:16px auto;padding:10mm;background:#fff;display:grid;grid-template-columns:repeat(3,54mm);justify-content:center;gap:6mm 8mm;align-content:start}
.empty{max-width:600px;margin:40px auto;padding:20px;background:#fff;border:1px solid #e5e7eb;text-align:center;color:#6b7280}
/* portrait card, 54 × 85.6 mm (bank-card size) */
.idc{position:relative;width:54mm;height:85.6mm;border:.3mm solid #c9ced6;border-radius:3mm;overflow:hidden;display:flex;flex-direction:column;background:#fff;break-inside:avoid;page-break-inside:avoid}
.idc-h{display:flex;align-items:center;gap:2mm;padding:2.4mm 2.6mm 2.2mm;background:var(--idc);color:#fff}
.idc-h img{width:11mm;height:11mm;object-fit:contain;background:#fff;border-radius:50%;padding:.6mm;flex:none}
.idc-h div{min-width:0;line-height:1.2}
.idc-h b{display:block;font-size:3.6mm;font-weight:800;letter-spacing:.03em;text-transform:uppercase;line-height:1.05}
.idc-h small{display:block;font-size:1.7mm;opacity:.9;margin-top:.5mm}
.idc-t{background:var(--idc2);color:#fff;text-align:center;font-size:1.9mm;font-weight:700;letter-spacing:.2em;padding:.7mm 0}
.idc-b{display:flex;gap:2.4mm;padding:2.6mm 2.6mm 0}
.idc-ph{flex:none;width:19mm;height:24mm;border:.5mm solid var(--idc);border-radius:1.4mm;overflow:hidden;background:#e5e7eb}
.idc-ph img,.idc-ph svg{width:100%;height:100%;object-fit:cover;display:block}
.idc-k{min-width:0;flex:1;display:flex;flex-direction:column;gap:1.1mm}
.idc-k i,.idc-x i{display:block;font-style:normal;font-size:1.55mm;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--idc)}
.idc-k b,.idc-x b{display:block;font-size:2.35mm;font-weight:700;color:#111827;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.idc-k .nm b{font-size:2.8mm;font-weight:800;white-space:normal;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
.idc-x{display:grid;grid-template-columns:1fr 1fr;gap:1.1mm 2mm;padding:2mm 2.6mm 0;flex:1;align-content:start}
.idc-x .w{grid-column:1/-1}.idc-x .w b{white-space:normal;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;font-weight:600}
.idc-s{display:flex;justify-content:space-between;align-items:flex-end;padding:0 2.6mm 1mm;font-size:1.6mm;color:#374151}
.idc-s span:last-child{text-align:center}.idc-s em{display:block;width:15mm;border-top:.25mm solid #6b7280;margin-bottom:.3mm}
.idc-bc{border-top:.6mm solid var(--idc);padding:1.2mm 2.6mm 1.4mm;text-align:center;background:#fff}
.idc-bc svg{height:8.5mm;width:auto;max-width:100%;display:block;margin:0 auto}
.idc-bc span{display:block;font-size:2.1mm;font-weight:700;letter-spacing:.25em;font-family:ui-monospace,Menlo,Consolas,monospace;margin-top:.3mm}
@page{size:A4 portrait;margin:8mm 10mm} /* 3 × 3 portrait cards on a page */
@media print{
  body{background:#fff}.bar{display:none}
  .sheet{width:auto;margin:0;padding:0;gap:4mm 8mm}
}
@media screen and (max-width:820px){.sheet{width:auto;grid-template-columns:54mm;padding:6mm}}
</style>
</head>
<body>
<div class="bar"><b>ID cards</b><span><?= ci_e($title) ?> · <?= count($list) ?> card<?= count($list) === 1 ? '' : 's' ?></span><span class="sp"></span>
  <a href="/admin/ci-reports.php?type=idcards">← Reports</a><?php if ($list): ?><button type="button" class="pr" onclick="window.print()">Print / Save as PDF</button><?php endif; ?></div>
<?php if (!$list): ?><div class="empty">No students for this choice. ID cards are made for active institute students.</div>
<?php else: ?><div class="sheet">
<?php foreach ($list as $s): $ad = $addr($s); ?>
  <div class="idc">
    <div class="idc-h"><?php if ($logo): ?><img src="<?= ci_e($logo) ?>" alt=""><?php endif; ?><div><b><?= ci_e($inst['name']) ?></b>
      <?php if ($inst['address']): ?><small><?= ci_e($inst['address']) ?></small><?php endif; ?>
      <small><?= ci_e(implode(' · ', array_filter([$inst['phone'], $inst['email']]))) ?></small></div></div>
    <div class="idc-t">STUDENT IDENTITY CARD</div>
    <div class="idc-b">
      <div class="idc-ph"><?= ($u = ci_photo_url($s['photo'] ?? '')) ? '<img src="' . ci_e($u) . '" alt="">' : '<svg viewBox="0 0 64 64" aria-hidden="true"><rect width="64" height="64" fill="#c9ced6"/><circle cx="32" cy="25" r="12" fill="#fff"/><path d="M8 64c1.6-13 11.6-21 24-21s22.4 8 24 21z" fill="#fff"/></svg>' ?></div>
      <div class="idc-k">
        <div class="nm"><i>Student name</i><b><?= ci_e($s['name']) ?></b></div>
        <div><i>Admission ID</i><b><?= ci_e($s['reg_no']) ?></b></div>
        <div><i>Class · Roll</i><b><?= ci_e(($s['class_name'] ?: '—') . ' · ' . ($s['roll_no'] ?: '—')) ?></b></div>
      </div>
    </div>
    <div class="idc-x">
      <div><i>Mobile</i><b><?= ci_e($s['mobile']) ?></b></div>
      <div><i>Session</i><b><?= ci_e($session !== '' ? $session : '—') ?></b></div>
      <?php if ($s['batches']): ?><div class="w"><i>Batch</i><b><?= ci_e($s['batches']) ?></b></div><?php endif; ?>
      <?php if ($ad !== ''): ?><div class="w"><i>Address</i><b><?= ci_e($ad) ?></b></div><?php endif; ?>
    </div>
    <div class="idc-s"><span></span><span><em></em>Authorised sign</span></div>
    <div class="idc-bc"><?= ci_barcode_svg((string)$s['reg_no'], 40, 1.4) ?><span><?= ci_e($s['reg_no']) ?></span></div>
  </div>
<?php endforeach; ?>
</div><?php endif; ?>
</body>
</html>
