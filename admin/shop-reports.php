<?php
// Online store → Student reports ("Report us" messages): read, open the attachment, reply note, mark seen / solved.
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/institute.php';
require_once INCLUDES_PATH . '/shop.php';
$me = sh_page_start($pdo, ['customers']);
if (isset($_GET['file'])) { // the attachment of one report (staff only)
    $q = $pdo->prepare("SELECT file, file_name FROM sh_reports WHERE id = ?"); $q->execute([(int)$_GET['file']]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    $path = $r && $r['file'] ? sh_media_path($r['file']) : '';
    if (!$path || !is_file($path)) { http_response_code(404); exit('File not found'); }
    session_write_close();
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    sh_send_file($path, ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'][$ext] ?? 'application/octet-stream', (string)$r['file_name']);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0); $a = (string)($_POST['action'] ?? '');
    if (in_array($a, ['seen', 'closed', 'new'], true)) { $pdo->prepare("UPDATE sh_reports SET status = ? WHERE id = ?")->execute([$a, $id]); admin_flash('Report marked ' . ['seen' => 'seen', 'closed' => 'solved', 'new' => 'new'][$a] . '.'); }
    if ($a === 'note') { $pdo->prepare("UPDATE sh_reports SET note = ?, status = IF(status = 'new', 'seen', status) WHERE id = ?")->execute([mb_substr(trim((string)($_POST['note'] ?? '')), 0, 500) ?: null, $id]); admin_flash('Reply saved — the student sees it on their Report us page.'); }
    if ($a === 'delete') {
        $f = $pdo->query("SELECT file FROM sh_reports WHERE id = $id")->fetchColumn();
        $pdo->prepare("DELETE FROM sh_reports WHERE id = ?")->execute([$id]);
        if ($f) { $p = sh_media_path($f); if (is_file($p)) @unlink($p); }
        admin_flash('Report deleted.');
    }
    admin_log('shop_report', ucfirst($a) . " student report #$id");
    header('Location: /admin/shop-reports.php' . (isset($_GET['status']) ? '?status=' . urlencode((string)$_GET['status']) : '')); exit;
}
$topics = ['payment' => 'Payment or order', 'course' => 'Course or video', 'notes' => 'PDF notes', 'account' => 'My account / login', 'other' => 'Something else'];
$status = in_array($_GET['status'] ?? '', ['new', 'seen', 'closed', 'all'], true) ? $_GET['status'] : 'open';
$w = $status === 'open' ? "r.status <> 'closed'" : ($status === 'all' ? '1' : "r.status = " . $pdo->quote($status));
$rows = $pdo->query("SELECT r.*, u.mobile, u.email FROM sh_reports r LEFT JOIN sh_users u ON u.id = r.user_id WHERE $w ORDER BY r.status = 'new' DESC, r.id DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
$cnt = $pdo->query("SELECT status, COUNT(*) FROM sh_reports GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Student Reports - <?= ci_e(edu_site_name()) ?></title>
<?php admin_shell_head(); ci_head(); ?>
<style>.srp-msg{white-space:pre-line;color:#1f2937;max-width:560px;line-height:1.55}.srp-att{display:inline-flex;align-items:center;gap:6px;margin-top:8px;font-weight:600}.srp-note{display:flex;gap:6px;margin-top:8px}.srp-note input{flex:1;height:34px;border:1px solid #d1d5db;padding:0 8px;min-width:160px}</style>
</head>
<body>
<?php admin_shell_open('Student Reports', 'Messages students sent from "Report us"'); ?>
<div class="ci">
<?= admin_flash() ?>
<div class="ci-stats">
  <div class="ci-stat"><i class="fas fa-flag ci-i-red"></i><div><b><?= (int)($cnt['new'] ?? 0) ?></b><small>New</small></div></div>
  <div class="ci-stat"><i class="fas fa-eye ci-i-amber"></i><div><b><?= (int)($cnt['seen'] ?? 0) ?></b><small>Seen</small></div></div>
  <div class="ci-stat"><i class="fas fa-check ci-i-green"></i><div><b><?= (int)($cnt['closed'] ?? 0) ?></b><small>Solved</small></div></div>
</div>
<form class="ci-filters" method="get"><div class="ci-f"><label>Show</label><select name="status" onchange="this.form.submit()"><?php foreach (['open' => 'Open (new + seen)', 'new' => 'New', 'seen' => 'Seen', 'closed' => 'Solved', 'all' => 'All'] as $k => $l): ?><option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div></form>
<div class="ci-card"><div class="ci-card-b ci-p0 ci-table-wrap"><table class="ci-table">
<thead><tr><th>Student</th><th>About</th><th>Message</th><th>Date</th><th>Status</th><th>Action</th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="6" class="ci-empty">No reports here.</td></tr><?php endif; ?>
<?php foreach ($rows as $r): ?>
<tr><td><a href="/admin/shop-customers.php?id=<?= (int)$r['user_id'] ?>"><b><?= ci_e($r['name']) ?></b></a><small class="ci-muted" style="display:block"><?= ci_e($r['mobile'] ?? '') ?></small><small class="ci-muted" style="display:block"><?= ci_e($r['email'] ?? '') ?></small></td>
<td class="ci-nw"><?= ci_e($topics[$r['topic']] ?? $r['topic']) ?></td>
<td><div class="srp-msg"><?= ci_e($r['message']) ?></div>
  <?php if ($r['file']): ?><a class="srp-att" href="?file=<?= (int)$r['id'] ?>" target="_blank"><i class="fas fa-paperclip"></i> <?= ci_e($r['file_name'] ?: 'Attachment') ?> (<?= round($r['size'] / 1048576, 1) ?> MB)</a><?php endif; ?>
  <form method="post" class="srp-note"><?= csrf_field() ?><input type="hidden" name="action" value="note"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input name="note" maxlength="500" placeholder="Reply the student will see" value="<?= ci_e($r['note'] ?? '') ?>"><button class="ci-btn ci-sm" type="submit">Save reply</button></form></td>
<td class="ci-nw"><?= date('d M Y, h:i A', strtotime($r['created_at'])) ?></td>
<td><?= ['new' => '<span class="ci-badge ci-b-red">New</span>', 'seen' => '<span class="ci-badge ci-b-amber">Seen</span>', 'closed' => '<span class="ci-badge ci-b-green">Solved</span>'][$r['status']] ?? '' ?></td>
<td><div class="ci-icos"><?= $r['status'] !== 'closed' ? ci_post_ico('check', 'Mark solved', ['action' => 'closed', 'id' => $r['id']]) : ci_post_ico('flag', 'Open again', ['action' => 'new', 'id' => $r['id']]) ?><?= $r['status'] === 'new' ? ci_post_ico('eye', 'Mark seen', ['action' => 'seen', 'id' => $r['id']]) : '' ?><?= ci_post_ico('trash', 'Delete report', ['action' => 'delete', 'id' => $r['id']], 'Delete this report and its attachment?') ?></div></td></tr>
<?php endforeach; ?>
</tbody></table></div></div>
</div>
<?php admin_shell_close(); ?>
</body>
</html>
