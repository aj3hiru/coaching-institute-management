<?php
// Comments — a compact WordPress-style list: approve / unapprove, reply as the site, delete (one or many),
// filter by status or post and search. Replies from here show on the post with the "Admin" badge.
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/mailer.php';
$me = admin_guard(['admin', 'editor', 'author', 'teacher', 'accountant']);
if (!admin_can('blogs.manage_comments')) { http_response_code(403); exit('Access Denied'); }
$adminEmail = defined('CONTACT_EMAIL') ? CONTACT_EMAIL : ((function_exists('es') ? (es('general', 'contact_email', '') ?: es('general', 'admin_email', '')) : '') ?: 'contact@' . preg_replace('/^www\./', '', (string)parse_url(SITE_URL, PHP_URL_HOST))); // Settings → General → contact email
$adminName = edu_site_name() . ' Support';

function cmt_json(array $d): void
{
    header('Content-Type: application/json');
    echo json_encode($d);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';
    $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? [$_POST['id'] ?? 0]))));
    $in = $ids ? implode(',', $ids) : '0';
    if (in_array($act, ['approved', 'pending'], true)) {
        $pdo->exec("UPDATE comments SET status = '$act' WHERE id IN ($in)");
        edu_clear_page_cache();
        admin_log('comment_status', ucfirst($act === 'approved' ? 'approved' : 'unapproved') . ' ' . count($ids) . ' comment(s)');
        cmt_json(['ok' => true]);
    }
    if ($act === 'delete') {
        $n = (int)$pdo->query("SELECT COUNT(*) FROM comments WHERE id IN ($in)")->fetchColumn();
        $pdo->exec("DELETE FROM comments WHERE id IN ($in)"); // replies go with them
        edu_clear_page_cache();
        admin_log('comment_delete', "Deleted $n comment(s)");
        cmt_json(['ok' => true]);
    }
    if ($act === 'reply') {
        $pid = $ids[0] ?? 0;
        $text = trim((string)($_POST['content'] ?? ''));
        if ($text === '') cmt_json(['ok' => false, 'error' => 'Please write your reply first.']);
        $st = $pdo->prepare("SELECT c.*, p.title, p.slug FROM comments c JOIN posts p ON p.id = c.post_id WHERE c.id = ?");
        $st->execute([$pid]);
        $parent = $st->fetch(PDO::FETCH_ASSOC);
        if (!$parent) cmt_json(['ok' => false, 'error' => 'That comment no longer exists.']);
        $pdo->prepare("INSERT INTO comments (name, email, content, post_id, parent_id, date, evf, status) VALUES (?, ?, ?, ?, ?, NOW(), 1, 'approved')")
            ->execute([$adminName, $adminEmail, $text, $parent['post_id'], $pid]);
        // Replying to a pending comment approves it (as in WordPress).
        if ($parent['status'] === 'pending') $pdo->prepare("UPDATE comments SET status = 'approved' WHERE id = ?")->execute([$pid]);
        edu_clear_page_cache();
        admin_log('comment_reply', "Replied to comment #$pid on post #{$parent['post_id']}");
        // Tell the commenter (only when their email was verified and email is set up)
        if ((int)$parent['evf'] === 1 && $parent['email'] !== $adminEmail && edu_mail_ready()) {
            $url = rtrim(SITE_URL, '/') . '/' . ltrim(postUrl($parent['slug'], (int)$parent['post_id']), '/') . '#comments';
            $html = '<div style="font-family:Arial,sans-serif;font-size:15px;line-height:1.6;color:#111">Hi ' . htmlspecialchars($parent['name']) . ',<br><br>'
                . htmlspecialchars(edu_site_name()) . ' replied to your comment on <b>' . htmlspecialchars($parent['title']) . '</b>:<br><br>'
                . '<div style="border-left:3px solid #7c3aed;padding-left:12px">' . nl2br(htmlspecialchars($text)) . '</div><br>'
                . '<a href="' . htmlspecialchars($url) . '" style="background:#7c3aed;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none">View the discussion</a></div>';
            edu_send_mail($parent['email'], 'New reply to your comment — ' . edu_site_name(), $html);
        }
        cmt_json(['ok' => true, 'name' => $adminName]);
    }
    cmt_json(['ok' => false, 'error' => 'Unknown action.']);
}

$status = in_array($_GET['status'] ?? '', ['pending', 'approved'], true) ? $_GET['status'] : 'all';
$q = trim((string)($_GET['q'] ?? ''));
$post = (int)($_GET['post'] ?? 0);
$page = max(1, (int)($_GET['page'] ?? 1));
$per = 20;
$counts = ['all' => 0, 'pending' => 0, 'approved' => 0];
foreach ($pdo->query("SELECT status, COUNT(*) c FROM comments GROUP BY status") as $r) { $counts[$r['status']] = (int)$r['c']; $counts['all'] += (int)$r['c']; }
$where = [];
$args = [];
if ($status !== 'all') { $where[] = 'c.status = ?'; $args[] = $status; }
if ($q !== '') { $where[] = '(c.name LIKE ? OR c.email LIKE ? OR c.content LIKE ?)'; array_push($args, "%$q%", "%$q%", "%$q%"); }
if ($post) { $where[] = 'c.post_id = ?'; $args[] = $post; }
$w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$st = $pdo->prepare("SELECT COUNT(*) FROM comments c $w");
$st->execute($args);
$total = (int)$st->fetchColumn();
$pages = max(1, (int)ceil($total / $per));
$page = min($page, $pages);
$st = $pdo->prepare("SELECT c.*, p.title post_title, p.slug post_slug, par.name parent_name,
        (SELECT COUNT(*) FROM comments x WHERE x.post_id = c.post_id) post_comments
    FROM comments c JOIN posts p ON p.id = c.post_id LEFT JOIN comments par ON par.id = c.parent_id
    $w ORDER BY c.date DESC, c.id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
$st->execute($args);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
$postTitle = '';
if ($post) { $pt = $pdo->prepare("SELECT title FROM posts WHERE id = ?"); $pt->execute([$post]); $postTitle = (string)$pt->fetchColumn(); }
$esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$link = fn(array $o) => '/admin/comments-manager.php' . (($p = http_build_query(array_filter(array_merge(['status' => $status === 'all' ? '' : $status, 'q' => $q, 'post' => $post ?: '', 'page' => $page > 1 ? $page : ''], $o), fn($x) => $x !== '' && $x !== null))) ? "?$p" : '');
$ago = function (string $d): string {
    $s = time() - strtotime($d);
    if ($s < 60) return 'just now';
    if ($s < 3600) return floor($s / 60) . ' min ago';
    if ($s < 86400) return floor($s / 3600) . ' h ago';
    if ($s < 7 * 86400) return floor($s / 86400) . ' d ago';
    return date('j M Y', strtotime($d));
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Comments - <?= $esc(edu_site_name()) ?></title>
<?php admin_shell_head(); ?>
<style>
.cmx-bar{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:12px}
.cmx-tabs{display:inline-flex;background:#fff;border:1px solid var(--gray-200);border-radius:10px;padding:4px;gap:2px}
.cmx-tabs a{padding:6px 12px;border-radius:7px;font-size:.8125rem;font-weight:600;color:var(--gray-600);text-decoration:none;display:inline-flex;align-items:center;gap:6px}
.cmx-tabs a em{font-style:normal;font-size:.7rem;background:var(--gray-100);color:var(--gray-600);border-radius:99px;padding:1px 7px}
.cmx-tabs a.active{background:var(--primary);color:#fff}.cmx-tabs a.active em{background:rgba(255,255,255,.25);color:#fff}
.cmx-tabs a.pend em{background:#fef3c7;color:#92400e}
.cmx-search{display:flex;gap:8px;margin-left:auto}.cmx-search input{width:240px}
.cmx-bulk{display:flex;align-items:center;gap:8px;padding:10px 14px;border-bottom:1px solid var(--gray-100);font-size:.8125rem;color:var(--gray-600);flex-wrap:wrap}
.cmx-bulk select{width:auto;height:34px;padding:0 .6rem;font-size:.8125rem}
.cmx-bulk .btn{height:34px}
.cmx-postf{display:inline-flex;align-items:center;gap:6px;background:var(--primary-lighter,#f5f3ff);color:var(--primary);padding:4px 10px;border-radius:99px;font-size:.78rem;font-weight:600;text-decoration:none;margin-left:auto}
.cmx table td{vertical-align:top;padding-top:12px;padding-bottom:12px}
.cmx-who{display:flex;gap:10px;min-width:180px;max-width:240px}
.cmx-av{width:34px;height:34px;border-radius:50%;display:grid;place-items:center;color:#fff;font-weight:700;font-size:.85rem;flex-shrink:0}
.cmx-who b{display:block;font-size:.85rem;color:var(--gray-900);overflow-wrap:anywhere}.cmx-who small{display:block;font-size:.75rem;color:var(--gray-500);overflow-wrap:anywhere}
.cmx-badge{display:inline-block;font-size:.6rem;font-weight:800;background:var(--primary);color:#fff;border-radius:4px;padding:1px 5px;margin-left:4px;vertical-align:middle}
.cmx-text{font-size:.875rem;color:var(--gray-800);line-height:1.55;white-space:pre-wrap;overflow-wrap:anywhere;display:-webkit-box;-webkit-line-clamp:4;-webkit-box-orient:vertical;overflow:hidden}
.cmx-text.full{display:block}
.cmx-reto{font-size:.75rem;color:var(--gray-500);margin-bottom:3px}
.cmx-acts{display:flex;gap:2px;flex-wrap:wrap;margin-top:6px;font-size:.78rem}
.cmx-acts button,.cmx-acts a{border:0;background:none;padding:2px 6px;border-radius:5px;cursor:pointer;font:inherit;font-weight:600;text-decoration:none;color:var(--primary)}
.cmx-acts button:hover,.cmx-acts a:hover{background:var(--gray-100)}
.cmx-acts .ok{color:#16a34a}.cmx-acts .warn{color:#d97706}.cmx-acts .del{color:var(--danger)}
tr.is-pending td{background:#fffbeb}
tr.is-pending td:first-child{box-shadow:inset 3px 0 0 #f59e0b}
.cmx-post a{font-size:.8125rem;font-weight:600;color:var(--gray-800);text-decoration:none;display:block;max-width:220px;overflow-wrap:anywhere}
.cmx-post a:hover{color:var(--primary)}.cmx-post small{font-size:.72rem;color:var(--gray-500)}
.cmx-when{font-size:.78rem;color:var(--gray-600);white-space:nowrap}.cmx-when small{display:block;color:var(--gray-400)}
.cmx-reply td{background:var(--gray-50,#f9fafb)!important}
.cmx-reply textarea{width:100%;min-height:80px;border:1px solid var(--gray-200);border-radius:8px;padding:8px 10px;font:inherit;font-size:.875rem;box-sizing:border-box}
.cmx-reply .row{display:flex;gap:8px;justify-content:flex-end;margin-top:8px}
.cmx-pages{display:flex;justify-content:center;gap:6px;padding:14px;flex-wrap:wrap}
.cmx-pages a{min-width:34px;height:34px;display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--gray-200);border-radius:8px;color:var(--gray-700);text-decoration:none;font-size:.85rem;background:#fff}
.cmx-pages a.cur{background:var(--primary);color:#fff;border-color:var(--primary)}
@media (max-width:900px){.cmx-hide{display:none}.cmx-search{margin-left:0;width:100%}.cmx-search input{flex:1;width:auto}.cmx-who{min-width:0}}
@media (max-width:640px){.cmx table thead{display:none}.cmx table tr{display:block;border-bottom:1px solid var(--gray-100)}.cmx table td{display:block;border:0!important;padding:6px 14px}.cmx table td:first-child{float:right}}
</style>
</head>
<body>
<?php admin_shell_open('Comments', 'Moderate and reply to comments'); ?>
<?= admin_flash() ?>
<div class="cmx-bar">
  <div class="cmx-tabs"><?php foreach (['all' => 'All', 'pending' => 'Pending', 'approved' => 'Approved'] as $k => $l): ?><a href="<?= $esc($link(['status' => $k === 'all' ? '' : $k, 'page' => ''])) ?>" class="<?= $status === $k ? 'active' : '' ?><?= $k === 'pending' && $counts['pending'] ? ' pend' : '' ?>"><?= $l ?> <em><?= $counts[$k] ?></em></a><?php endforeach; ?></div>
  <form class="cmx-search" method="GET"><?php if ($status !== 'all'): ?><input type="hidden" name="status" value="<?= $status ?>"><?php endif; ?><?php if ($post): ?><input type="hidden" name="post" value="<?= $post ?>"><?php endif; ?><input type="search" name="q" class="form-control" value="<?= $esc($q) ?>" placeholder="Search comments…"><button class="btn btn-primary"><i class="fas fa-search"></i></button></form>
</div>
<div class="table-wrap cmx">
  <div class="cmx-bulk">
    <label style="display:flex;align-items:center;gap:6px;cursor:pointer"><input type="checkbox" id="cmxAll"> <span id="cmxSel">Select all</span></label>
    <select id="cmxAct" class="form-control"><option value="">Bulk actions</option><option value="approved">Approve</option><option value="pending">Unapprove</option><option value="delete">Delete</option></select>
    <button type="button" class="btn btn-secondary btn-sm" id="cmxApply" disabled>Apply</button>
    <?php if ($post): ?><a class="cmx-postf" href="<?= $esc($link(['post' => '', 'page' => ''])) ?>" title="Show comments on all posts">Post: <?= $esc(mb_strimwidth($postTitle, 0, 50, '…')) ?> <i class="fas fa-xmark"></i></a><?php endif; ?>
  </div>
  <div class="table-responsive"><table>
    <thead><tr><th style="width:36px"></th><th>Author</th><th>Comment</th><th class="cmx-hide">In response to</th><th class="cmx-hide">Submitted</th></tr></thead>
    <tbody id="cmxBody">
    <?php if (!$rows): ?><tr><td colspan="5"><div class="empty-state"><i class="fas fa-comments"></i><p><?= $q !== '' ? 'No comments match your search.' : ($status === 'pending' ? 'No comments waiting for approval.' : 'No comments yet.') ?></p></div></td></tr><?php endif; ?>
    <?php foreach ($rows as $c): $admin = $c['email'] === $adminEmail; $hue = crc32(strtolower($c['email'])) % 360; $url = postUrl($c['post_slug'], (int)$c['post_id']); ?>
    <tr data-id="<?= (int)$c['id'] ?>" class="<?= $c['status'] === 'pending' ? 'is-pending' : '' ?>">
      <td><input type="checkbox" class="cmx-check" aria-label="Select"></td>
      <td><div class="cmx-who"><span class="cmx-av" style="background:<?= $admin ? 'var(--primary)' : "hsl($hue,50%,50%)" ?>"><?= $esc(mb_strtoupper(mb_substr($c['name'], 0, 1))) ?></span><div style="min-width:0"><b><?= $esc($c['name']) ?><?= $admin ? '<span class="cmx-badge">ADMIN</span>' : '' ?></b><small><?= $esc($c['email']) ?></small><?php if ((int)$c['evf'] === 1 && !$admin): ?><small style="color:#16a34a"><i class="fas fa-circle-check"></i> email verified</small><?php endif; ?></div></div></td>
      <td>
        <?php if ($c['parent_name']): ?><div class="cmx-reto"><i class="fas fa-reply"></i> In reply to <b><?= $esc($c['parent_name']) ?></b></div><?php endif; ?>
        <div class="cmx-text"><?= $esc($c['content']) ?></div>
        <div class="cmx-acts">
          <?php if ($c['status'] === 'pending'): ?><button type="button" class="ok" data-do="approved"><i class="fas fa-check"></i> Approve</button><?php else: ?><button type="button" class="warn" data-do="pending"><i class="fas fa-rotate-left"></i> Unapprove</button><?php endif; ?>
          <button type="button" data-do="reply"><i class="fas fa-reply"></i> Reply</button>
          <a href="<?= $esc($url) ?>#comment-<?= (int)$c['id'] ?>" target="_blank" rel="noopener"><i class="fas fa-eye"></i> View</a>
          <button type="button" class="del" data-do="delete"><i class="fas fa-trash"></i> Delete</button>
        </div>
      </td>
      <td class="cmx-hide cmx-post"><a href="<?= $esc($link(['post' => (int)$c['post_id'], 'page' => ''])) ?>" title="Show only this post's comments"><?= $esc($c['post_title']) ?></a><small><i class="fas fa-comments"></i> <?= (int)$c['post_comments'] ?> comment<?= (int)$c['post_comments'] === 1 ? '' : 's' ?></small></td>
      <td class="cmx-hide"><div class="cmx-when" title="<?= $esc($c['date']) ?>"><?= date('j M Y', strtotime($c['date'])) ?><small><?= date('g:i a', strtotime($c['date'])) ?><?= time() - strtotime($c['date']) < 7 * 86400 ? ' · ' . $ago($c['date']) : '' ?></small></div></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($pages > 1): ?><div class="cmx-pages"><?php for ($n = 1; $n <= $pages; $n++): ?><a class="<?= $n === $page ? 'cur' : '' ?>" href="<?= $esc($link(['page' => $n > 1 ? $n : ''])) ?>"><?= $n ?></a><?php endfor; ?></div><?php endif; ?>
</div>
<?php admin_shell_close(); ?>
<script>
(function () {
  var token = <?= json_encode(csrf_token(), JSON_HEX_TAG) ?>;
  function post(d) {
    var fd = new FormData(); fd.append('_csrf', token);
    Object.keys(d).forEach(function (k) { [].concat(d[k]).forEach(function (v) { fd.append(Array.isArray(d[k]) ? k + '[]' : k, v); }); });
    return fetch('/admin/comments-manager.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }
  function act(ids, what) {
    var go = function () { return post({ action: what, ids: ids }).then(function (d) { if (d.ok) location.reload(); else adminNotice(d.error || 'Could not save.', { type: 'error' }); }); };
    if (what === 'delete') return adminConfirm(ids.length > 1 ? ids.length + ' comments (and their replies) will be deleted permanently.' : 'This comment and its replies will be deleted permanently.', { title: 'Delete comment' + (ids.length > 1 ? 's' : '') + '?', confirmText: 'Delete' }).then(function (ok) { if (ok) go(); });
    go();
  }
  document.getElementById('cmxBody').addEventListener('click', function (e) {
    var b = e.target.closest('[data-do]'); if (!b) { var t = e.target.closest('.cmx-text'); if (t) t.classList.toggle('full'); return; }
    var tr = b.closest('tr'), id = +tr.dataset.id, what = b.dataset.do;
    if (what !== 'reply') return act([id], what);
    var open = tr.nextElementSibling && tr.nextElementSibling.classList.contains('cmx-reply');
    document.querySelectorAll('.cmx-reply').forEach(function (r) { r.remove(); });
    if (open) return;
    var r = document.createElement('tr'); r.className = 'cmx-reply';
    r.innerHTML = '<td></td><td colspan="4"><textarea placeholder="Write your reply — it is posted as <?= $esc($adminName) ?> with the Admin badge"></textarea><div class="row"><button type="button" class="btn btn-secondary btn-sm" data-cancel>Cancel</button><button type="button" class="btn btn-primary btn-sm" data-send><i class="fas fa-paper-plane"></i> Post Reply</button></div></td>';
    tr.after(r); var ta = r.querySelector('textarea'); ta.focus();
    r.querySelector('[data-cancel]').onclick = function () { r.remove(); };
    r.querySelector('[data-send]').onclick = function () {
      var btn = this; if (!ta.value.trim()) return ta.focus();
      btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Posting…';
      post({ action: 'reply', id: id, content: ta.value }).then(function (d) {
        if (!d.ok) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane"></i> Post Reply'; return adminNotice(d.error || 'Could not post.', { type: 'error' }); }
        location.reload();
      });
    };
  });
  var all = document.getElementById('cmxAll'), apply = document.getElementById('cmxApply'), sel = document.getElementById('cmxAct');
  function ids() { return [].map.call(document.querySelectorAll('.cmx-check:checked'), function (c) { return +c.closest('tr').dataset.id; }); }
  function sync() { var n = ids().length, t = document.querySelectorAll('.cmx-check').length; apply.disabled = !n || !sel.value; all.checked = n && n === t; all.indeterminate = n > 0 && n < t; document.getElementById('cmxSel').textContent = n ? n + ' selected' : 'Select all'; }
  document.addEventListener('change', function (e) { if (e.target.classList.contains('cmx-check')) sync(); });
  all.addEventListener('change', function () { document.querySelectorAll('.cmx-check').forEach(function (c) { c.checked = all.checked; }); sync(); });
  sel.addEventListener('change', sync);
  apply.addEventListener('click', function () { var i = ids(); if (i.length && sel.value) act(i, sel.value); });
})();
</script>
</body>
</html>
