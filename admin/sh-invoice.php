<?php
// Printable invoice of an online order (bought on the website with Razorpay, or made at the office): A4, same look as the
// fee receipt. Opened from the student profile → Online orders. ?id=ORDER_ID
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/institute.php';
require_once INCLUDES_PATH . '/shop.php';
$me = sh_page_start($pdo, ['orders', 'customers']);
$id = (int)($_GET['id'] ?? 0);
$q = $pdo->prepare("SELECT o.*, u.name AS uname, u.mobile AS umobile, u.email AS uemail, u.student_id, p.type AS ptype, p.mrp, p.validity_days, p.slug,
    s.reg_no, s.name AS sname, s.father_name, s.address, s.city
    FROM sh_orders o LEFT JOIN sh_users u ON u.id = o.user_id LEFT JOIN sh_products p ON p.id = o.product_id LEFT JOIN ci_students s ON s.id = u.student_id WHERE o.id = ?");
$q->execute([$id]);
$o = $q->fetch(PDO::FETCH_ASSOC);
if (!$o) { http_response_code(404); exit('Invoice not found.'); }
$a = $pdo->prepare("SELECT * FROM sh_access WHERE user_id = ? AND product_id = ?"); $a->execute([(int)$o['user_id'], (int)$o['product_id']]);
$acc = $a->fetch(PDO::FETCH_ASSOC) ?: null;
$inst = ci_institute();
$logo = $inst['logo'] ? (str_starts_with($inst['logo'], 'http') ? $inst['logo'] : '/' . ltrim($inst['logo'], '/')) : '';
$amt = (float)$o['amount'];
$mrp = max($amt, (float)($o['mrp'] ?? 0));
$disc = round($mrp - $amt, 2);
$office = str_starts_with((string)$o['rzp_order'], 'manual_');
$paidOn = $o['paid_at'] ?: $o['created_at'];
$no = $o['order_no'] ?: ('ORD-' . str_pad((string)$o['id'], 6, '0', STR_PAD_LEFT));
// access now: what the student can still open
$accTxt = 'Not given';
if ($acc) {
    if (!empty($acc['blocked'])) $accTxt = 'Blocked';
    elseif ($acc['revoked']) $accTxt = 'Taken back';
    elseif (!$acc['expires_at']) $accTxt = 'Lifetime access';
    else $accTxt = (strtotime($acc['expires_at']) > time() ? 'Till ' : 'Ended on ') . date('d M Y', strtotime($acc['expires_at']));
}
$period = (int)($o['validity_days'] ?? 0) > 0 ? (int)$o['validity_days'] . ' days' : 'Lifetime';
$status = ['paid' => 'PAID', 'created' => 'NOT PAID', 'failed' => 'FAILED'][$o['status']] ?? strtoupper((string)$o['status']);
if (!function_exists('ci_words_n')) {
    function ci_words_n(int $n): string
    {
        static $w = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
        static $t = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
        if ($n < 20) return $w[$n];
        if ($n < 100) return trim($t[intdiv($n, 10)] . ' ' . $w[$n % 10]);
        if ($n < 1000) return trim($w[intdiv($n, 100)] . ' Hundred ' . ci_words_n($n % 100));
        foreach ([[10000000, 'Crore'], [100000, 'Lakh'], [1000, 'Thousand']] as [$d, $l]) if ($n >= $d) return trim(ci_words_n(intdiv($n, $d)) . " $l " . ci_words_n($n % $d));
        return '';
    }
}
$words = function (float $v): string { $n = (int)floor($v + 1e-7); $p = (int)round(($v - $n) * 100); return ($n ? ci_words_n($n) : 'Zero') . ' Rupees' . ($p ? ' and ' . ci_words_n($p) . ' Paise' : '') . ' Only'; };
$back = $o['student_id'] ? '/admin/student-view.php?id=' . (int)$o['student_id'] . '#online' : '/admin/shop-orders.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Invoice <?= ci_e($no) ?></title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#e5e7eb;font:13px/1.45 Arial,Helvetica,sans-serif;color:#1f2937}
.bar{display:flex;gap:8px;justify-content:center;align-items:center;padding:12px;background:#fff;border-bottom:1px solid #d1d5db;position:sticky;top:0}
.bar a,.bar button{height:34px;padding:0 14px;border:1px solid #9ca3af;background:#fff;color:#111827;font:600 13px/32px Arial,sans-serif;cursor:pointer;text-decoration:none}
.bar .pr{background:#4a3369;border-color:#4a3369;color:#fff}
.page{background:#fff;margin:18px auto 30px;width:210mm;min-height:297mm;padding:16mm 15mm;border:1px solid #d1d5db;position:relative}
.top{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;padding-bottom:12px;border-bottom:2.5px solid #111}
.org{display:flex;gap:12px;align-items:flex-start}.org img{max-height:62px;max-width:130px}
.org h1{margin:0 0 3px;font-size:21px;color:#111}.org p{margin:0;color:#4b5563;font-size:12px}
.doc{text-align:right}.doc h2{margin:0 0 8px;font-size:22px;letter-spacing:.06em;color:#111}
.doc table{margin-left:auto;border-collapse:collapse;font-size:12px}
.doc td{padding:1.5px 0 1.5px 12px}.doc td:first-child{color:#6b7280;text-align:right}.doc td:last-child{font-weight:700;color:#111;text-align:left}
.st{display:inline-block;margin-top:6px;padding:3px 10px;border:2px solid currentColor;font-weight:800;letter-spacing:.08em;font-size:12px}
.st.paid{color:#15803d}.st.no{color:#b91c1c}
.boxes{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin:16px 0}
.box{border:1px solid #9ca3af}.box h3{margin:0;padding:6px 10px;background:#eceff3;border-bottom:1px solid #9ca3af;font-size:11.5px;letter-spacing:.05em;color:#111}
.box table{width:100%;border-collapse:collapse;font-size:12.5px}.box td{padding:4px 10px;vertical-align:top}.box td:first-child{color:#6b7280;width:38%}.box td:last-child{font-weight:600;color:#111}
table.lines{width:100%;border-collapse:collapse;font-size:13px;border:1px solid #9ca3af}
.lines th{background:#eceff3;text-align:left;padding:7px 10px;border:1px solid #9ca3af;font-size:12px}
.lines td{padding:7px 10px;border:1px solid #d1d5db}.lines .n{width:42px;text-align:center}.lines .a{width:160px;text-align:right;white-space:nowrap}
.lines tr.pay td{background:#f3f4f6;font-weight:700;color:#111;font-size:14px}
.words{margin:12px 0;padding:9px 12px;border:1px dashed #9ca3af;font-size:12.5px}.words b{color:#111}
.sign{display:flex;justify-content:flex-end;margin-top:46px}.sign .s{min-width:200px;text-align:center;border-top:1px solid #111;padding-top:5px;font-size:12px;color:#374151}
.foot{margin-top:28px;padding-top:8px;border-top:1px solid #d1d5db;font-size:11px;color:#6b7280;display:flex;justify-content:space-between;gap:12px}
@media print{body{background:#fff}.bar{display:none}.page{margin:0;border:0;width:auto;min-height:0}@page{size:A4;margin:0}}
</style>
</head>
<body>
<div class="bar"><button class="pr" onclick="window.print()">Print / Save as PDF</button><a href="<?= ci_e($back) ?>">Back to the student</a></div>
<div class="page">
  <div class="top">
    <div class="org"><?php if ($logo): ?><img src="<?= ci_e($logo) ?>" alt=""><?php endif; ?><div><h1><?= ci_e($inst['name']) ?></h1><?php if ($inst['address']): ?><p><?= ci_e($inst['address']) ?></p><?php endif; ?><p><?= ci_e(implode('  |  ', array_filter([$inst['phone'] ?? '', $inst['email'] ?? '']))) ?></p></div></div>
    <div class="doc"><h2>INVOICE</h2><table>
      <tr><td>Invoice No.:</td><td><?= ci_e($no) ?></td></tr>
      <tr><td>Date:</td><td><?= date('d M Y', strtotime($paidOn)) ?></td></tr>
      <?php if ($o['reg_no']): ?><tr><td>Reg. No.:</td><td><?= ci_e($o['reg_no']) ?></td></tr><?php endif; ?>
    </table><span class="st <?= $o['status'] === 'paid' ? 'paid' : 'no' ?>"><?= $status ?></span></div>
  </div>
  <div class="boxes">
    <div class="box"><h3>BILLED TO</h3><table>
      <tr><td>Student</td><td><?= ci_e($o['sname'] ?: ($o['uname'] ?? '—')) ?></td></tr>
      <?php if ($o['father_name']): ?><tr><td>Father's name</td><td><?= ci_e($o['father_name']) ?></td></tr><?php endif; ?>
      <tr><td>Mobile</td><td><?= ci_e($o['umobile'] ?? '') ?></td></tr>
      <?php if ($o['uemail'] && !str_ends_with((string)$o['uemail'], '@noemail.invalid')): ?><tr><td>Email</td><td><?= ci_e($o['uemail']) ?></td></tr><?php endif; ?>
      <?php if ($o['address'] || $o['city']): ?><tr><td>Address</td><td><?= ci_e(trim(($o['address'] ?? '') . ' ' . ($o['city'] ?? ''))) ?></td></tr><?php endif; ?>
    </table></div>
    <div class="box"><h3>ORDER DETAILS</h3><table>
      <tr><td>Item</td><td><?= ci_e($o['title']) ?></td></tr>
      <tr><td>Type</td><td><?= ($o['ptype'] ?? '') === 'notes' ? 'PDF notes' : 'Online video course' ?></td></tr>
      <tr><td>Access period</td><td><?= ci_e($period) ?></td></tr>
      <tr><td>Access now</td><td><?= ci_e($accTxt) ?></td></tr>
      <tr><td>Paid by</td><td><?= $office ? 'At the office' . ($o['method'] ? ' — ' . ci_e(ucfirst((string)$o['method'])) : '') : 'Online (Razorpay)' . ($o['method'] ? ' — ' . ci_e((string)$o['method']) : '') ?></td></tr>
      <?php if ($o['rzp_payment'] && !$office): ?><tr><td>Payment ID</td><td><?= ci_e($o['rzp_payment']) ?></td></tr><?php endif; ?>
    </table></div>
  </div>
  <table class="lines">
    <thead><tr><th class="n">#</th><th>Description</th><th class="a">Amount</th></tr></thead>
    <tbody>
      <tr><td class="n">1</td><td><?= ci_e($o['title']) ?> <span style="color:#6b7280">(<?= ($o['ptype'] ?? '') === 'notes' ? 'PDF notes' : 'video course' ?>, <?= ci_e(strtolower($period)) ?>)</span></td><td class="a"><?= ci_money($mrp) ?></td></tr>
      <?php if ($disc > 0.004): ?><tr><td class="n">2</td><td>Less: discount</td><td class="a">− <?= ci_money($disc) ?></td></tr><?php endif; ?>
      <tr class="pay"><td></td><td><?= $o['status'] === 'paid' ? 'Total paid' : 'Total (not paid)' ?></td><td class="a"><?= ci_money($amt) ?></td></tr>
    </tbody>
  </table>
  <div class="words">Amount in words: <b><?= ci_e($words($amt)) ?></b></div>
  <div class="sign"><div class="s">Authorised Signatory<br><b><?= ci_e($inst['name']) ?></b></div></div>
  <div class="foot"><span>This is a computer-generated invoice. Order placed <?= date('d M Y, h:i A', strtotime($o['created_at'])) ?>.</span><span><?= ci_e($no) ?></span></div>
</div>
</body>
</html>
