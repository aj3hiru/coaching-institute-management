<?php
// Printable fee receipt: A4 invoice layout (default) or ?size=80 for an 80 mm thermal printer. Only fee receipts offer thermal.
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/institute.php';
$me = ci_page_start($pdo);
$id = (int)($_GET['id'] ?? 0);
$q = $pdo->prepare("SELECT p.*, s.name, s.reg_no, s.father_name, s.mobile, s.address, s.city, c.name AS course_name, b.name AS batch_name, b.timing, se.name AS session_name,
    e.fee, e.discount, e.fee_type, e.due_date, e.status AS estatus, u.username
    FROM ci_payments p JOIN ci_students s ON s.id = p.student_id LEFT JOIN ci_enrollments e ON e.id = p.enrollment_id LEFT JOIN ci_courses c ON c.id = e.course_id
    LEFT JOIN ci_batches b ON b.id = e.batch_id LEFT JOIN ci_sessions se ON se.id = e.session_id LEFT JOIN users u ON u.id = p.created_by WHERE p.id = ?");
$q->execute([$id]);
$r = $q->fetch(PDO::FETCH_ASSOC);
if (!$r) { http_response_code(404); exit('Receipt not found.'); }
// totals of this enrollment up to (and including) this receipt
$pq = $pdo->prepare("SELECT COALESCE(SUM(amount),0), COUNT(*) FROM ci_payments WHERE enrollment_id = ? AND (paid_on < ? OR (paid_on = ? AND id <= ?))");
$pq->execute([$r['enrollment_id'], $r['paid_on'], $r['paid_on'], $r['id']]);
[$paidTill, $instNo] = $pq->fetch(PDO::FETCH_NUM);
$paidTill = (float)$paidTill;
$isInst = ($r['fee_type'] ?? 'full') === 'installment';
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
<?php $paidBefore = max(0, $paidTill - (float)$r['amount']); $logo = $inst['logo'] ? (str_starts_with($inst['logo'], 'http') ? $inst['logo'] : '/' . ltrim($inst['logo'], '/')) : ''; ?>
<style>
*{box-sizing:border-box}
body{margin:0;background:#e5e7eb;font:13px/1.45 Arial,Helvetica,sans-serif;color:#1f2937}
.bar{display:flex;gap:8px;justify-content:center;align-items:center;padding:12px;background:#fff;border-bottom:1px solid #d1d5db;position:sticky;top:0}
.bar a,.bar button{height:34px;padding:0 14px;border:1px solid #9ca3af;background:#fff;color:#111827;font:600 13px/32px Arial,sans-serif;cursor:pointer;text-decoration:none}
.bar .on{background:#111827;color:#fff;border-color:#111827}
.bar .pr{background:#4a3369;border-color:#4a3369;color:#fff}
.page{background:#fff;margin:18px auto 30px;width:210mm;min-height:297mm;padding:16mm 15mm;border:1px solid #d1d5db}
.top{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;padding-bottom:12px;border-bottom:2.5px solid #111}
.org{display:flex;gap:12px;align-items:flex-start}
.org img{max-height:62px;max-width:130px}
.org h1{margin:0 0 3px;font-size:21px;color:#111}
.org p{margin:0;color:#4b5563;font-size:12px}
.doc{text-align:right}
.doc h2{margin:0 0 8px;font-size:22px;letter-spacing:.06em;color:#111}
.doc table{margin-left:auto;border-collapse:collapse;font-size:12px}
.doc td{padding:1.5px 0 1.5px 12px}.doc td:first-child{color:#6b7280;text-align:right}.doc td:last-child{font-weight:700;color:#111;text-align:left}
.boxes{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin:16px 0}
.box{border:1px solid #9ca3af}
.box h3{margin:0;padding:6px 10px;background:#eceff3;border-bottom:1px solid #9ca3af;font-size:11.5px;letter-spacing:.05em;color:#111}
.box table{width:100%;border-collapse:collapse;font-size:12.5px}
.box td{padding:4px 10px;vertical-align:top}.box td:first-child{color:#6b7280;width:38%}.box td:last-child{font-weight:600;color:#111}
table.lines{width:100%;border-collapse:collapse;font-size:13px;border:1px solid #9ca3af}
.lines th{background:#eceff3;text-align:left;padding:7px 10px;border:1px solid #9ca3af;font-size:12px}
.lines td{padding:7px 10px;border:1px solid #d1d5db}
.lines .n{width:42px;text-align:center}.lines .a{width:160px;text-align:right;white-space:nowrap}
.lines tr.sub td{background:#fafafa}
.lines tr.pay td{background:#f3f4f6;font-weight:700;color:#111;font-size:14px}
.lines tr.bal td{font-weight:700;color:#111}
.words{margin:12px 0;padding:9px 12px;border:1px dashed #9ca3af;font-size:12.5px}
.words b{color:#111}
.meta{display:flex;justify-content:space-between;gap:20px;margin-top:6px;font-size:12px;color:#4b5563}
.sign{display:flex;justify-content:space-between;align-items:flex-end;margin-top:46px}
.sign .s{min-width:200px;text-align:center;border-top:1px solid #111;padding-top:5px;font-size:12px;color:#374151}
.foot{margin-top:28px;padding-top:8px;border-top:1px solid #d1d5db;font-size:11px;color:#6b7280;display:flex;justify-content:space-between}
/* thermal 80 mm */
.th{width:80mm;min-height:0;padding:4mm 3.5mm;font-size:11.5px;margin:18px auto}
.th .c{text-align:center}.th h1{margin:0;font-size:15px}.th p{margin:1px 0;font-size:10.5px;color:#111}
.th hr{border:0;border-top:1px dashed #111;margin:6px 0}
.th table{width:100%;border-collapse:collapse}.th td{padding:1.5px 0;vertical-align:top}.th td.r{text-align:right;white-space:nowrap}
.th .big td{font-size:14px;font-weight:700;padding-top:4px}
@media print{body{background:#fff}.bar{display:none}.page{margin:0;border:0;width:auto;min-height:0}@page{size:<?= $thermal ? '80mm auto' : 'A4' ?>;margin:<?= $thermal ? '2mm' : '0' ?>}}
</style>
</head>
<body>
<div class="bar"><button class="pr" onclick="window.print()">Print</button><a href="?id=<?= $id ?>" class="<?= $thermal ? '' : 'on' ?>">A4</a><a href="?id=<?= $id ?>&amp;size=80" class="<?= $thermal ? 'on' : '' ?>">Thermal 80 mm</a><a href="/admin/student-view.php?id=<?= (int)$r['student_id'] ?>#payments">Back</a></div>
<?php if (!$thermal): ?>
<div class="page">
  <div class="top">
    <div class="org"><?php if ($logo): ?><img src="<?= ci_e($logo) ?>" alt=""><?php endif; ?><div><h1><?= ci_e($inst['name']) ?></h1><?php if ($inst['address']): ?><p><?= ci_e($inst['address']) ?></p><?php endif; ?><p><?= ci_e(implode('  |  ', array_filter([$inst['phone'] ? 'Phone: ' . $inst['phone'] : '', $inst['email'] ? 'Email: ' . $inst['email'] : '']))) ?></p></div></div>
    <div class="doc"><h2>FEE RECEIPT</h2><table>
      <tr><td>Receipt No.:</td><td><?= ci_e($r['receipt_no']) ?></td></tr>
      <tr><td>Date:</td><td><?= ci_date($r['paid_on']) ?></td></tr>
      <?php if ($r['session_name']): ?><tr><td>Session:</td><td><?= ci_e($r['session_name']) ?></td></tr><?php endif; ?>
      <tr><td>Reg. No.:</td><td><?= ci_e($r['reg_no']) ?></td></tr>
    </table></div>
  </div>
  <div class="boxes">
    <div class="box"><h3>RECEIVED FROM</h3><table>
      <tr><td>Student</td><td><?= ci_e($r['name']) ?></td></tr>
      <?php if ($r['father_name']): ?><tr><td>Father's name</td><td><?= ci_e($r['father_name']) ?></td></tr><?php endif; ?>
      <tr><td>Mobile</td><td><?= ci_e($r['mobile']) ?></td></tr>
      <?php if ($r['address']): ?><tr><td>Address</td><td><?= ci_e($r['address']) ?></td></tr><?php endif; ?>
    </table></div>
    <div class="box"><h3>COURSE DETAILS</h3><table>
      <tr><td>Course</td><td><?= ci_e($r['course_name'] ?? '—') ?></td></tr>
      <?php if ($r['batch_name']): ?><tr><td>Batch</td><td><?= ci_e(implode(' · ', array_filter([$r['batch_name'], $r['timing']]))) ?></td></tr><?php endif; ?>
      <tr><td>Fee plan</td><td><?= $isInst ? 'Installment ' . (int)$instNo : 'Final fee (one time)' ?></td></tr>
      <tr><td>Payment mode</td><td><?= ci_e(ci_pay_mode($r)) ?></td></tr>
    </table></div>
  </div>
  <table class="lines">
    <thead><tr><th class="n">#</th><th>Description</th><th class="a">Amount</th></tr></thead>
    <tbody>
      <tr><td class="n">1</td><td>Course fee — <?= ci_e($r['course_name'] ?? '') ?></td><td class="a"><?= ci_money($r['fee']) ?></td></tr>
      <?php if ((float)$r['discount'] > 0): ?><tr><td class="n">2</td><td>Less: discount</td><td class="a">− <?= ci_money($r['discount']) ?></td></tr><?php endif; ?>
      <tr class="sub"><td></td><td><b>Fee payable</b></td><td class="a"><b><?= ci_money($net) ?></b></td></tr>
      <tr><td></td><td>Paid earlier</td><td class="a"><?= ci_money($paidBefore) ?></td></tr>
      <tr class="pay"><td></td><td>Amount received with this receipt<?= $r['note'] ? ' <span style="font-weight:400;color:#4b5563">(' . ci_e($r['note']) . ')</span>' : '' ?></td><td class="a"><?= ci_money($r['amount']) ?></td></tr>
      <tr class="bal"><td></td><td>Balance due<?= $dueAfter > 0 && $r['due_date'] ? ' <span style="font-weight:400;color:#4b5563">— next due ' . ci_date($r['due_date']) . '</span>' : '' ?></td><td class="a"><?= ci_money($dueAfter) ?></td></tr>
    </tbody>
  </table>
  <div class="words">Amount received in words: <b><?= ci_e(ci_words((float)$r['amount'])) ?></b></div>
  <div class="meta"><span></span><span>Entered on <?= date('d M Y, h:i A', strtotime($r['created_at'])) ?></span></div>
  <div class="sign"><div class="s">Student / Guardian</div><div class="s">Authorised Signatory<br><b><?= ci_e($inst['name']) ?></b></div></div>
  <div class="foot"><span>This is a computer-generated receipt.</span><span><?= ci_e($r['receipt_no']) ?></span></div>
</div>
<?php else: ?>
<div class="page th">
  <div class="c"><h1><?= ci_e($inst['name']) ?></h1><?php if ($inst['address']): ?><p><?= ci_e($inst['address']) ?></p><?php endif; ?><?php if ($inst['phone']): ?><p>Ph: <?= ci_e($inst['phone']) ?></p><?php endif; ?></div>
  <hr><div class="c"><b>FEE RECEIPT</b></div><hr>
  <table>
    <tr><td>Receipt</td><td class="r"><?= ci_e($r['receipt_no']) ?></td></tr>
    <tr><td>Date</td><td class="r"><?= ci_date($r['paid_on']) ?></td></tr>
    <tr><td>Student</td><td class="r"><?= ci_e($r['name']) ?></td></tr>
    <tr><td>Reg. No.</td><td class="r"><?= ci_e($r['reg_no']) ?></td></tr>
    <tr><td>Course</td><td class="r"><?= ci_e($r['course_name'] ?? '—') ?></td></tr>
    <?php if ($r['batch_name']): ?><tr><td>Batch</td><td class="r"><?= ci_e($r['batch_name']) ?></td></tr><?php endif; ?>
    <tr><td>Plan</td><td class="r"><?= $isInst ? 'Installment ' . (int)$instNo : 'Final fee' ?></td></tr>
  </table>
  <hr>
  <table>
    <tr><td>Course fee</td><td class="r"><?= ci_money($r['fee']) ?></td></tr>
    <?php if ((float)$r['discount'] > 0): ?><tr><td>Discount</td><td class="r">−<?= ci_money($r['discount']) ?></td></tr><?php endif; ?>
    <tr><td>Fee payable</td><td class="r"><?= ci_money($net) ?></td></tr>
    <tr><td>Paid earlier</td><td class="r"><?= ci_money($paidBefore) ?></td></tr>
    <tr class="big"><td>PAID NOW</td><td class="r"><?= ci_money($r['amount']) ?></td></tr>
    <tr><td>Mode</td><td class="r"><?= ci_e(ci_pay_mode($r)) ?></td></tr>
    <tr><td><b>Balance due</b></td><td class="r"><b><?= ci_money($dueAfter) ?></b></td></tr>
  </table>
  <hr><p><?= ci_e(ci_words((float)$r['amount'])) ?></p><hr>
  <p class="c"><?= date('d M Y h:i A', strtotime($r['created_at'])) ?></p>
  <p class="c">Thank you</p>
</div>
<?php endif; ?>
</body>
</html>
