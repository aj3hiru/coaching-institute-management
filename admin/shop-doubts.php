<?php
// Online store → Doubts: questions students asked under lectures. Teachers see the doubts of their own courses;
// admins / store staff see all. Answer (the student sees it under the lecture), hide or delete.
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/institute.php';
require_once INCLUDES_PATH . '/shop.php';
$me = sh_page_start($pdo, ['teacher', 'products', 'customers']);
$all = $me['role'] === 'admin' || sh_perm($me, 'products') || sh_perm($me, 'customers'); // otherwise: a teacher, own courses only
$scope = $all ? '1' : 'p.teacher_id = ' . (int)$me['id'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0); $a = (string)($_POST['action'] ?? '');
    $ok = (int)$pdo->query("SELECT COUNT(*) FROM sh_doubts d JOIN sh_products p ON p.id = d.product_id WHERE d.id = $id AND $scope")->fetchColumn();
    if (!$ok) { admin_flash('Not allowed.', 'error'); header('Location: /admin/shop-doubts.php'); exit; }
    if ($a === 'answer') {
        $ans = trim(preg_replace("/\r\n?/", "\n", (string)($_POST['answer'] ?? '')));
        if ($ans === '') { $pdo->prepare("UPDATE sh_doubts SET answer = NULL, answered_by = NULL, answered_at = NULL, status = 'open' WHERE id = ?")->execute([$id]); admin_flash('Answer removed.'); }
        else { $pdo->prepare("UPDATE sh_doubts SET answer = ?, answered_by = ?, answered_at = NOW(), status = 'answered' WHERE id = ?")->execute([mb_substr($ans, 0, 5000), $me['id'], $id]); admin_flash('Answer saved — the student sees it under the lecture.'); }
    } elseif ($a === 'hide' || $a === 'show') {
        $pdo->prepare("UPDATE sh_doubts SET status = IF(? = 'hide', 'hidden', IF(answer IS NULL, 'open', 'answered')) WHERE id = ?")->execute([$a, $id]);
        admin_flash($a === 'hide' ? 'Doubt hidden.' : 'Doubt shown again.');
    } elseif ($a === 'delete') { $pdo->prepare("DELETE FROM sh_doubts WHERE id = ?")->execute([$id]); admin_flash('Doubt deleted.'); }
    admin_log('shop_doubt', ucfirst($a) . " doubt #$id");
    header('Location: /admin/shop-doubts.php?' . http_build_query(array_intersect_key($_GET, array_flip(['status', 'course'])))); exit;
}
$status = in_array($_GET['status'] ?? '', ['open', 'answered', 'hidden', 'all'], true) ? $_GET['status'] : 'open';
$course = (int)($_GET['course'] ?? 0);
$w = [$scope]; if ($status !== 'all') $w[] = "d.status = " . $pdo->quote($status); if ($course) $w[] = 'd.product_id = ' . $course;
$rows = $pdo->query("SELECT d.*, u.name, u.mobile, l.title AS lecture, l.lecture_no, p.title AS course, p.slug, s.title AS chapter FROM sh_doubts d JOIN sh_users u ON u.id = d.user_id JOIN sh_lessons l ON l.id = d.lesson_id
    JOIN sh_products p ON p.id = d.product_id LEFT JOIN sh_sections s ON s.id = l.section_id WHERE " . implode(' AND ', $w) . " ORDER BY d.status = 'open' DESC, d.id DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
$cnt = $pdo->query("SELECT d.status, COUNT(*) FROM sh_doubts d JOIN sh_products p ON p.id = d.product_id WHERE $scope GROUP BY d.status")->fetchAll(PDO::FETCH_KEY_PAIR);
$courses = $pdo->query("SELECT p.id, p.title FROM sh_products p WHERE p.type = 'course' AND $scope ORDER BY p.title")->fetchAll(PDO::FETCH_KEY_PAIR);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Doubts - <?= ci_e(edu_site_name()) ?></title>
<?php admin_shell_head(); ci_head(); ?>
<style>.sd-q{white-space:pre-line;color:#111827;max-width:560px;line-height:1.55}.sd-ctx{font-size:12.5px;color:#6b7280;margin-bottom:4px}.sd-ans{display:flex;flex-direction:column;gap:6px;margin-top:8px;max-width:560px}.sd-ans textarea{min-height:70px}
.sd-a{margin-top:8px;max-width:560px;padding:8px 12px;background:#f0fdf4;border-left:3px solid #22c55e}.sd-a b{font-size:12px;color:#15803d}.sd-a p{margin:2px 0 0;line-height:1.55;color:#1f2937}
.sd-edit{margin-top:6px}.sd-edit summary{cursor:pointer;font-size:12.5px;font-weight:600;color:var(--primary,#4a3369);list-style:none}.sd-edit summary::-webkit-details-marker{display:none}
.sd-edit[open] summary{margin-bottom:4px}</style>
</head>
<body>
<?php admin_shell_open('Doubts', $all ? 'Questions students asked under lectures' : 'Questions from students of your courses'); ?>
<div class="ci">
<?= admin_flash() ?>
<div class="ci-stats">
  <div class="ci-stat"><i class="fas fa-circle-question ci-i-red"></i><div><b><?= (int)($cnt['open'] ?? 0) ?></b><small>Waiting for an answer</small></div></div>
  <div class="ci-stat"><i class="fas fa-check ci-i-green"></i><div><b><?= (int)($cnt['answered'] ?? 0) ?></b><small>Answered</small></div></div>
</div>
<form class="ci-filters" method="get">
  <div class="ci-f"><label>Show</label><select name="status" onchange="this.form.submit()"><?php foreach (['open' => 'Waiting for an answer', 'answered' => 'Answered', 'hidden' => 'Hidden', 'all' => 'All'] as $k => $l): ?><option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
  <div class="ci-f"><label>Course</label><select name="course" onchange="this.form.submit()"><option value="0">All courses</option><?php foreach ($courses as $k => $l): ?><option value="<?= (int)$k ?>" <?= $course === (int)$k ? 'selected' : '' ?>><?= ci_e($l) ?></option><?php endforeach; ?></select></div>
</form>
<div class="ci-card"><div class="ci-card-b ci-p0 ci-table-wrap"><table class="ci-table">
<thead><tr><th>Student</th><th>Doubt</th><th>Date</th><th>Status</th><th>Action</th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="5" class="ci-empty">No doubts here.</td></tr><?php endif; ?>
<?php foreach ($rows as $r): ?>
<tr><td><b><?= ci_e($r['name']) ?></b><small class="ci-muted" style="display:block"><?= ci_e($r['mobile']) ?></small></td>
<td><div class="sd-ctx"><?= ci_e($r['course']) ?> › <?= ci_e($r['chapter'] ?? '') ?> › <a href="<?= ci_e(sh_learn_url($r['slug'], 'l=' . (int)$r['lesson_id'], true)) ?>" target="_blank"><?= ci_e($r['lecture']) ?></a></div>
  <div class="sd-q"><?= ci_e($r['question']) ?></div>
  <?php if ($r['answer']): ?><div class="sd-a"><b>Answer</b><p><?= nl2br(ci_e($r['answer'])) ?></p></div>
  <details class="sd-edit"><summary>Edit answer</summary>  <form method="post" class="sd-ans"><?= csrf_field() ?><input type="hidden" name="action" value="answer"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><textarea name="answer" maxlength="5000" placeholder="Write the answer — the student sees it under the lecture"><?= ci_e($r['answer'] ?? '') ?></textarea><div><button class="ci-btn ci-sm" type="submit">Update answer</button></div></form></details>
  <?php else: ?>  <form method="post" class="sd-ans"><?= csrf_field() ?><input type="hidden" name="action" value="answer"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><textarea name="answer" maxlength="5000" placeholder="Write the answer — the student sees it under the lecture"><?= ci_e($r['answer'] ?? '') ?></textarea><div><button class="ci-btn ci-sm" type="submit">Send answer</button></div></form><?php endif; ?></td>
<td class="ci-nw"><?= date('d M Y, h:i A', strtotime($r['created_at'])) ?></td>
<td><?= ['open' => '<span class="ci-badge ci-b-red">Waiting</span>', 'answered' => '<span class="ci-badge ci-b-green">Answered</span>', 'hidden' => '<span class="ci-badge ci-b-grey">Hidden</span>'][$r['status']] ?? '' ?></td>
<td><div class="ci-icos"><?= $r['status'] === 'hidden' ? ci_post_ico('check', 'Show again', ['action' => 'show', 'id' => $r['id']]) : ci_post_ico('eye', 'Hide', ['action' => 'hide', 'id' => $r['id']]) ?><?= ci_post_ico('trash', 'Delete', ['action' => 'delete', 'id' => $r['id']], 'Delete this doubt?') ?></div></td></tr>
<?php endforeach; ?>
</tbody></table></div></div>
</div>
<?php admin_shell_close(); ?>
</body>
</html>
