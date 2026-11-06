<?php
// Printable fee receipt (A5 by default, ?size=80 for an 80 mm thermal printer).
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/institute.php';
$me = ci_page_start($pdo);
$id = (int)($_GET['id'] ?? 0);
$q = $pdo->prepare("SELECT p.*, s.name, s.reg_no, s.father_name, s.mobile, s.address, s.city, c.name AS course_name, b.name AS batch_name, b.timing, se.name AS session_name,
    e.fee, e.discount, e.due_date, e.status AS estatus, u.username
    FROM ci_payments p JOIN ci_students s ON s.id = p.student_id LEFT JOIN ci_enrollments e ON e.id = p.enrollment_id LEFT JOIN ci_courses c ON c.id = e.course_id
    LEFT JOIN ci_batches b ON b.id = e.batch_id LEFT JOIN ci_sessions se ON se.id = e.session_id LEFT JOIN users u ON u.id = p.created_by WHERE p.id = ?");
$q->execute([$id]);
$r = $q->fetch(PDO::FETCH_ASSOC);
if (!$r) { http_response_code(404); exit('Receipt not found.'); }
// totals of this enrollment up to (and including) this receipt
$pq = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM ci_payments WHERE enrollment_id = ? AND (paid_on < ? OR (paid_on = ? AND id <= ?))");
$pq->execute([$r['enrollment_id'], $r['paid_on'], $r['paid_on'], $r['id']]);
$paidTill = (float)$pq->fetchColumn();
$net = (float)$r['fee'] - (float)$r['discount'];
$dueAfter = max(0, $net - $paidTill);
$inst = ci_institute();
$thermal = ($_GET['size'] ?? '') === '80';

/** Number in words (Indian system: Thousand, Lakh, Crore). */
function ci_words_n(int $n): string
{
    static $o = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    static $t = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    if ($n < 20) return $o[$n];
    if ($n < 100) return trim($t[intdiv($n, 10)] . ' ' . $o[$n % 10]);
    if ($n < 1000) return trim($o[intdiv($n, 100)] . ' Hundred ' . ci_words_n($n % 100));
    foreach ([[10000000, 'Crore'], [100000, 'Lakh'], [1000, 'Thousand']] as [$d, $l]) {
        if ($n >= $d) return trim(ci_words_n(intdiv($n, $d)) . " $l " . ci_words_n($n % $d));
    }
    return '';
}

/** Amount in words, e.g. 12500 → "Twelve Thousand Five Hundred Rupees Only". */
function ci_words(float $amt): string
{
    $n = (int)floor($amt + 0.0000001);
    $paise = (int)round(($amt - $n) * 100);
    return ($n ? ci_words_n($n) : 'Zero') . ' Rupees' . ($paise ? ' and ' . ci_words_n($paise) . ' Paise' : '') . ' Only';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Receipt <?= ci_e($r['receipt_no']) ?></title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#f3f4f6;font:14px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;color:#111827}
.bar{display:flex;gap:8px;justify-content:center;padding:14px}
.bar a,.bar button{height:36px;padding:0 14px;border:1px solid #d1d5db;background:#fff;color:#111827;font-family:inherit;font-size:13px;font-weight:600;line-height:34px;cursor:pointer;text-decoration:none;border-radius:0}
.bar button{background:#4a3369;border-color:#4a3369;color:#fff}
.rc{background:#fff;margin:0 auto 30px;padding:28px;width:148mm;border:1px solid #e5e7eb}
.rc.th{width:80mm;padding:10px 8px;font-size:12px}
.hd{display:flex;align-items:center;gap:14px;border-bottom:2px solid #111827;padding-bottom:12px;margin-bottom:12px}
.th .hd{flex-direction:column;text-align:center;gap:4px}
.hd img{max-height:56px;max-width:160px}
.hd h1{margin:0;font-size:20px}
.th .hd h1{font-size:16px}
.hd p{margin:2px 0 0;color:#4b5563;font-size:12px}
.tt{display:flex;justify-content:space-between;align-items:baseline;margin:0 0 10px}
.tt b{font-size:15px;letter-spacing:.06em}
table{width:100%;border-collapse:collapse}
td{padding:5px 0;vertical-align:top}
td:first-child{color:#6b7280;width:38%}
.amt{margin:12px 0;padding:12px;border:1px dashed #9ca3af;text-align:center}
.amt b{display:block;font-size:24px}
.th .amt b{font-size:18px}
.amt small{color:#4b5563}
.sum td{border-top:1px solid #f1f2f4}
.ft{display:flex;justify-content:space-between;align-items:flex-end;margin-top:28px;font-size:12px;color:#4b5563}
.th .ft{margin-top:14px;flex-direction:column;align-items:center;gap:6px}
.sign{text-align:center;border-top:1px solid #9ca3af;padding-top:4px;min-width:140px}
@media print{body{background:#fff}.bar{display:none}.rc{border:0;margin:0;width:auto}@page{size:<?= $thermal ? '80mm auto' : 'A5' ?>;margin:<?= $thermal ? '2mm' : '10mm' ?>}}
</style>
</head>
<body>
<div class="bar"><button onclick="window.print()">Print</button><a href="?id=<?= $id ?>"<?= $thermal ? '' : ' style="border-color:#4a3369"' ?>>A5</a><a href="?id=<?= $id ?>&amp;size=80"<?= $thermal ? ' style="border-color:#4a3369"' : '' ?>>Thermal 80 mm</a><a href="/admin/student-view.php?id=<?= (int)$r['student_id'] ?>">Back</a></div>
<div class="rc<?= $thermal ? ' th' : '' ?>">
  <div class="hd"><?php if ($inst['logo']): ?><img src="<?= ci_e(str_starts_with($inst['logo'], 'http') ? $inst['logo'] : '/' . ltrim($inst['logo'], '/')) ?>" alt=""><?php endif; ?>
    <div><h1><?= ci_e($inst['name']) ?></h1><?php if ($inst['address']): ?><p><?= ci_e($inst['address']) ?></p><?php endif; ?><p><?= ci_e(implode(' · ', array_filter([$inst['phone'], $inst['email']]))) ?></p></div></div>
  <div class="tt"><b>FEE RECEIPT</b><span><?= ci_e($r['receipt_no']) ?> · <?= ci_date($r['paid_on']) ?></span></div>
  <table>
    <tr><td>Student</td><td><b><?= ci_e($r['name']) ?></b> (<?= ci_e($r['reg_no']) ?>)</td></tr>
    <?php if ($r['father_name']): ?><tr><td>Father's name</td><td><?= ci_e($r['father_name']) ?></td></tr><?php endif; ?>
    <tr><td>Mobile</td><td><?= ci_e($r['mobile']) ?></td></tr>
    <tr><td>Course</td><td><?= ci_e($r['course_name'] ?? '—') ?></td></tr>
    <?php if ($r['batch_name']): ?><tr><td>Batch</td><td><?= ci_e(implode(' · ', array_filter([$r['batch_name'], $r['timing']]))) ?></td></tr><?php endif; ?>
    <?php if ($r['session_name']): ?><tr><td>Session</td><td><?= ci_e($r['session_name']) ?></td></tr><?php endif; ?>
    <tr><td>Paid by</td><td><?= ci_e(CI_METHODS[$r['method']] ?? $r['method']) ?><?= $r['note'] ? ' — ' . ci_e($r['note']) : '' ?></td></tr>
  </table>
  <div class="amt"><b><?= ci_money($r['amount']) ?></b><small><?= ci_e(ci_words((float)$r['amount'])) ?></small></div>
  <table class="sum">
    <tr><td>Total course fee</td><td><?= ci_money($r['fee']) ?><?= (float)$r['discount'] > 0 ? ' − ' . ci_money($r['discount']) . ' discount = ' . ci_money($net) : '' ?></td></tr>
    <tr><td>Paid till this receipt</td><td><?= ci_money($paidTill) ?></td></tr>
    <tr><td>Balance due</td><td><b><?= ci_money($dueAfter) ?></b><?= $dueAfter > 0 && $r['due_date'] ? ' · next due ' . ci_date($r['due_date']) : '' ?></td></tr>
  </table>
  <div class="ft"><span>Received by <?= ci_e($r['username'] ?? '') ?> · <?= date('d M Y, h:i A', strtotime($r['created_at'])) ?></span><span class="sign">Authorised signature</span></div>
</div>
</body>
</html>
