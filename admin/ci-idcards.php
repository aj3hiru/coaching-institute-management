<?php
// Student ID cards (Institute only) — opened from Reports → ID Cards, printed or saved as PDF from the browser.
// Card (85.6 × 54 mm, the size of a bank card): the institute's logo, name, address, phone and email on top; the student's
// photo, name, class, roll no., admission ID, mobile, address and batch; a Code 128 barcode of the admission ID that the
// attendance page's barcode scanner reads. 10 cards on an A4 page.
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
.sheet{width:210mm;margin:16px auto;padding:10mm;background:#fff;display:grid;grid-template-columns:repeat(2,85.6mm);justify-content:center;gap:5mm 8mm;align-content:start}
.empty{max-width:600px;margin:40px auto;padding:20px;background:#fff;border:1px solid #e5e7eb;text-align:center;color:#6b7280}
.idc{width:85.6mm;height:54mm;border:.3mm solid #c9ced6;border-radius:3mm;overflow:hidden;display:flex;flex-direction:column;background:#fff;break-inside:avoid;page-break-inside:avoid}
.idc-h{display:flex;align-items:center;gap:2mm;padding:1.6mm 2.6mm;background:var(--idc);color:#fff;min-height:11.5mm}
.idc-h img{width:8.5mm;height:8.5mm;object-fit:contain;background:#fff;border-radius:50%;padding:.5mm;flex:none}
.idc-h div{min-width:0;line-height:1.15}
.idc-h b{display:block;font-size:3.3mm;font-weight:800;letter-spacing:.02em;text-transform:uppercase;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.idc-h small{display:block;font-size:1.85mm;opacity:.92;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.idc-t{background:var(--idc2);color:#fff;text-align:center;font-size:1.9mm;font-weight:700;letter-spacing:.18em;padding:.4mm 0}
.idc-b{flex:1;display:flex;gap:2.6mm;padding:1.8mm 2.6mm 0;min-height:0}
.idc-ph{flex:none;width:16.5mm;height:20mm;border:.3mm solid #c9ced6;border-radius:1mm;overflow:hidden;background:#e5e7eb}
.idc-ph img,.idc-ph svg{width:100%;height:100%;object-fit:cover;display:block}
.idc-d{min-width:0;flex:1;font-size:2.15mm;line-height:1.32}
.idc-d .nm{font-size:3mm;font-weight:800;color:#111827;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:.4mm}
.idc-d dl{display:grid;grid-template-columns:auto 1fr;gap:0 1.5mm;margin:0}
.idc-d dt{color:#6b7280}.idc-d dd{margin:0;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.idc-d dd.ad{white-space:normal;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;font-weight:500}
.idc-f{display:flex;align-items:flex-end;justify-content:space-between;gap:2mm;padding:.8mm 2.6mm 1.4mm}
.idc-bc{display:flex;flex-direction:column;align-items:flex-start}
.idc-bc svg{height:7.5mm;width:auto;display:block}
.idc-bc span{font-size:1.9mm;letter-spacing:.2em;font-family:ui-monospace,Menlo,Consolas,monospace;margin-top:.2mm}
.idc-s{text-align:right;font-size:1.8mm;color:#374151;line-height:1.3}
.idc-s i{display:block;width:15mm;border-top:.25mm solid #6b7280;margin:3.5mm 0 .3mm auto}
@page{size:A4 portrait;margin:8mm 10mm} /* 5 rows of 54 mm cards fit the 281 mm height */
@media print{
  body{background:#fff}.bar{display:none}
  .sheet{width:auto;margin:0;padding:0;gap:2.4mm 8mm}
}
@media screen and (max-width:820px){.sheet{width:auto;grid-template-columns:85.6mm;padding:6mm}}
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
      <small><?= ci_e(implode(' · ', array_filter([$inst['phone'] ? 'Ph: ' . $inst['phone'] : '', $inst['email']]))) ?></small></div></div>
    <div class="idc-t">STUDENT IDENTITY CARD</div>
    <div class="idc-b">
      <div class="idc-ph"><?= ($u = ci_photo_url($s['photo'] ?? '')) ? '<img src="' . ci_e($u) . '" alt="">' : '<svg viewBox="0 0 64 64" aria-hidden="true"><rect width="64" height="64" fill="#c9ced6"/><circle cx="32" cy="25" r="12" fill="#fff"/><path d="M8 64c1.6-13 11.6-21 24-21s22.4 8 24 21z" fill="#fff"/></svg>' ?></div>
      <div class="idc-d"><div class="nm"><?= ci_e($s['name']) ?></div><dl>
        <dt>Class</dt><dd><?= ci_e($s['class_name'] ?: '—') ?></dd>
        <dt>Roll No.</dt><dd><?= ci_e($s['roll_no'] ?: '—') ?></dd>
        <dt>Admission ID</dt><dd><?= ci_e($s['reg_no']) ?></dd>
        <dt>Mobile</dt><dd><?= ci_e($s['mobile']) ?></dd>
        <?php if ($s['batches']): ?><dt>Batch</dt><dd><?= ci_e($s['batches']) ?></dd><?php endif; ?>
        <?php if ($ad !== ''): ?><dt>Address</dt><dd class="ad"><?= ci_e($ad) ?></dd><?php endif; ?>
      </dl></div>
    </div>
    <div class="idc-f"><div class="idc-bc"><?= ci_barcode_svg((string)$s['reg_no'], 40, 1.4) ?><span><?= ci_e($s['reg_no']) ?></span></div>
      <div class="idc-s"><?= $session !== '' ? 'Session ' . ci_e($session) : '' ?><i></i>Authorised sign</div></div>
  </div>
<?php endforeach; ?>
</div><?php endif; ?>
</body>
</html>
