<?php
// Student ID cards (Institute only) — opened from Reports → ID Cards, printed or saved as PDF from the browser.
// Card (54 × 85.6 mm portrait, the size of a bank card): the institute's logo, name, address and phone on top; the student's
// photo, name, admission ID, father's name, mobile and address; a Code 128 barcode of the admission ID that the
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
    FROM ci_students s LEFT JOIN ci_classes c ON c.id = s.class_id WHERE " . implode(' AND ', $w) . " ORDER BY c.sort IS NULL, c.sort, s.reg_no, s.name LIMIT 1000");
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
.idc-h{display:flex;align-items:center;gap:2mm;padding:2.2mm 2.6mm 1.8mm;background:#fff;border-top:1.6mm solid var(--idc)}
.idc-h img{width:10.5mm;height:10.5mm;object-fit:contain;flex:none}
.idc-h div{min-width:0;line-height:1.2}
.idc-h b{display:block;font-family:Georgia,"Times New Roman",serif;font-size:3.7mm;font-weight:700;letter-spacing:.02em;text-transform:uppercase;color:var(--idc);line-height:1.05}
.idc-h small{display:block;font-size:1.6mm;color:#4b5563;margin-top:.5mm}
.idc-t{background:#fcd34d;color:var(--idc);text-align:center;font-size:1.95mm;font-weight:800;letter-spacing:.24em;padding:.9mm 0} /* yellow, so it stands out; no border */
.idc-bc{padding:1.4mm 1.4mm .4mm}
.idc-bc svg{width:100%;height:10mm;display:block} /* full card width: thick bars (~0.42 mm) for any scanner */
.idc-ph{width:19mm;height:24.4mm;margin:1.6mm auto 0;border:.35mm solid var(--idc);padding:.4mm;background:#fff} /* passport 7:9 */
.idc-ph img,.idc-ph svg{width:100%;height:100%;object-fit:cover;object-position:50% 30%;display:block}
.idc-nm{margin:1.2mm 2.6mm 0;text-align:center;font-size:3mm;font-weight:800;color:var(--idc);line-height:1.15;text-transform:uppercase;letter-spacing:.02em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.idc-d{margin:1.4mm 3mm 0;border-collapse:collapse;width:calc(100% - 6mm);font-size:2.05mm;line-height:1.25;table-layout:fixed}
.idc-d th{width:17mm;padding:.35mm 0;text-align:left;font-weight:600;color:#4b5563;vertical-align:top;white-space:nowrap}
.idc-d td{padding:.35mm 0 .35mm 1mm;font-weight:700;color:#111827;vertical-align:top;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.idc-d td::before{content:": ";font-weight:600;color:#4b5563;margin-left:-1mm}
.idc-d td.ad{white-space:normal;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;font-weight:600}
.idc-sp{flex:1}
.idc-f{height:1.6mm;background:var(--idc);border-top:.5mm solid var(--idc2)}
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
      <?php if ($inst['phone']): ?><small>Ph: <?= ci_e($inst['phone']) ?></small><?php endif; ?></div></div>
    <div class="idc-t">STUDENT IDENTITY CARD</div>
    <div class="idc-bc"><?= str_replace('<svg ', '<svg preserveAspectRatio="none" ', ci_barcode_svg((string)$s['reg_no'], 30, 1)) ?></div>
    <div class="idc-ph"><?= ($u = ci_photo_url($s['photo'] ?? '')) ? '<img src="' . ci_e($u) . '" alt="">' : ci_photo_blank() ?></div>
    <div class="idc-nm"><?= ci_e($s['name']) ?></div>
    <table class="idc-d">
      <tr><th>Admission ID</th><td><?= ci_e($s['reg_no']) ?></td></tr>
      <tr><th>Father's Name</th><td><?= ci_e(trim((string)($s['father_name'] ?? '')) ?: '—') ?></td></tr>
      <tr><th>Contact No.</th><td><?= ci_e($s['mobile']) ?></td></tr>
      <?php if ($ad !== ''): ?><tr><th>Address</th><td class="ad"><?= ci_e($ad) ?></td></tr><?php endif; ?>
    </table>
    <div class="idc-sp"></div>
    <div class="idc-f"></div>
  </div>
<?php endforeach; ?>
</div><?php endif; ?>
</body>
</html>
