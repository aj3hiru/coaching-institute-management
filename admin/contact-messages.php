<?php
// Contact Messages — an inbox for everything sent from the Contact Us form: list on the left, the open message on
// the right, search, bulk actions, and replying straight from here (by email, through the server like WordPress).
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/mailer.php';
$me = admin_guard(['admin']) /* site pages / messages: not part of the blog roles */;
edu_contact_table($pdo);
// Replies sent from here are kept with the message (added columns; old rows simply have none).
try {
    if (!$pdo->query("SHOW COLUMNS FROM contact_messages LIKE 'reply'")->fetch()) {
        $pdo->exec("ALTER TABLE contact_messages ADD COLUMN reply TEXT NULL, ADD COLUMN replied_at DATETIME NULL");
    }
} catch (Throwable $e) {
}

function cm_counts(PDO $pdo): array
{
    $c = ['all' => 0, 'new' => 0, 'read' => 0, 'replied' => 0];
    foreach ($pdo->query("SELECT status, COUNT(*) c FROM contact_messages GROUP BY status") as $r) { $c[$r['status']] = (int)$r['c']; $c['all'] += (int)$r['c']; }
    return $c;
}

function cm_json(array $d): void
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
    if ($act === 'status' && in_array($_POST['status'] ?? '', ['new', 'read', 'replied'], true)) {
        $pdo->prepare("UPDATE contact_messages SET status = ? WHERE id IN ($in)")->execute([$_POST['status']]);
        cm_json(['ok' => true, 'counts' => cm_counts($pdo)]);
    }
    if ($act === 'delete') {
        $n = $pdo->exec("DELETE FROM contact_messages WHERE id IN ($in)");
        admin_log('contact_delete', 'Deleted ' . $n . ' contact message(s)');
        cm_json(['ok' => true, 'deleted' => $n, 'counts' => cm_counts($pdo)]);
    }
    if ($act === 'reply') {
        $id = $ids[0] ?? 0;
        $st = $pdo->prepare("SELECT * FROM contact_messages WHERE id = ?");
        $st->execute([$id]);
        $m = $st->fetch(PDO::FETCH_ASSOC);
        $body = trim((string)($_POST['body'] ?? ''));
        if (!$m) cm_json(['ok' => false, 'error' => 'This message no longer exists.']);
        if ($body === '') cm_json(['ok' => false, 'error' => 'Please write your reply first.']);
        $subject = 'Re: ' . ($m['subject'] ?: 'Your message to ' . edu_site_name());
        $quote = nl2br(htmlspecialchars($m['message']));
        $html = '<div style="font-family:Arial,sans-serif;font-size:15px;line-height:1.6;color:#111">' . nl2br(htmlspecialchars($body))
            . '<br><br>— ' . htmlspecialchars(edu_site_name()) . '<br><br><div style="border-left:3px solid #ddd;padding-left:12px;color:#666;font-size:13px">On '
            . date('j M Y, g:i a', strtotime($m['created_at'])) . ', ' . htmlspecialchars($m['name']) . ' wrote:<br>' . $quote . '</div></div>';
        $from = (string)es('general', 'contact_email', es('general', 'admin_email', ''));
        if (!edu_send_mail($m['email'], $subject, $html, $body, $from, edu_site_name())) cm_json(['ok' => false, 'error' => 'The email could not be sent. Please try again in a minute.']);
        $pdo->prepare("UPDATE contact_messages SET status = 'replied', reply = ?, replied_at = NOW() WHERE id = ?")->execute([$body, $id]);
        admin_log('contact_reply', 'Replied to contact message #' . $id . ' (' . $m['email'] . ')');
        cm_json(['ok' => true, 'at' => date('j M Y, g:i a'), 'counts' => cm_counts($pdo)]);
    }
    cm_json(['ok' => false, 'error' => 'Unknown action.']);
}

$status = in_array($_GET['status'] ?? '', ['new', 'read', 'replied'], true) ? $_GET['status'] : 'all';
$q = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$per = 25;
$counts = cm_counts($pdo);
$week = (int)$pdo->query("SELECT COUNT(*) FROM contact_messages WHERE created_at >= NOW() - INTERVAL 7 DAY")->fetchColumn();
$where = [];
$args = [];
if ($status !== 'all') { $where[] = 'status = ?'; $args[] = $status; }
if ($q !== '') { $where[] = '(name LIKE ? OR email LIKE ? OR subject LIKE ? OR message LIKE ?)'; array_push($args, "%$q%", "%$q%", "%$q%", "%$q%"); }
$w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$st = $pdo->prepare("SELECT COUNT(*) FROM contact_messages $w");
$st->execute($args);
$total = (int)$st->fetchColumn();
$pages = max(1, (int)ceil($total / $per));
$page = min($page, $pages);
$st = $pdo->prepare("SELECT * FROM contact_messages $w ORDER BY created_at DESC, id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
$st->execute($args);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
$ago = function (string $d): string {
    $t = strtotime($d);
    $s = time() - $t;
    if ($s < 60) return 'just now';
    if ($s < 3600) return floor($s / 60) . ' min ago';
    if ($s < 86400) return floor($s / 3600) . ' h ago';
    if ($s < 7 * 86400) return floor($s / 86400) . ' d ago';
    return date('j M', $t) . (date('Y', $t) !== date('Y') ? ' ' . date('Y', $t) : '');
};
$device = function (?string $ua): string {
    $ua = (string)$ua;
    if ($ua === '') return '';
    $os = preg_match('/Android/i', $ua) ? 'Android' : (preg_match('/iPhone|iPad|iOS/i', $ua) ? 'iOS' : (preg_match('/Windows/i', $ua) ? 'Windows' : (preg_match('/Mac OS/i', $ua) ? 'macOS' : (preg_match('/Linux/i', $ua) ? 'Linux' : ''))));
    $br = preg_match('/Edg\//', $ua) ? 'Edge' : (preg_match('/OPR\/|Opera/', $ua) ? 'Opera' : (preg_match('/Chrome\//', $ua) ? 'Chrome' : (preg_match('/Firefox\//', $ua) ? 'Firefox' : (preg_match('/Safari\//', $ua) ? 'Safari' : ''))));
    return trim($br . ($br && $os ? ' on ' : '') . $os);
};
$data = [];
foreach ($rows as $m) {
    $data[] = ['id' => (int)$m['id'], 'name' => $m['name'], 'email' => $m['email'], 'subject' => (string)$m['subject'], 'message' => $m['message'], 'status' => $m['status'],
        'date' => date('D, j M Y \a\t g:i a', strtotime($m['created_at'])), 'ip' => (string)$m['ip'], 'device' => $device($m['user_agent'] ?? ''),
        'reply' => (string)($m['reply'] ?? ''), 'replied_at' => !empty($m['replied_at']) ? date('j M Y, g:i a', strtotime($m['replied_at'])) : ''];
}
$link = function (array $over) use ($status, $q, $page) {
    $p = array_filter(array_merge(['status' => $status === 'all' ? '' : $status, 'q' => $q, 'page' => $page > 1 ? $page : ''], $over), fn($v) => $v !== '' && $v !== null);
    return '/admin/contact-messages.php' . ($p ? '?' . http_build_query($p) : '');
};
$smtp = edu_mail_ready();
$esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Contact Messages - <?= $esc(edu_site_name()) ?></title>
<?php admin_shell_head(); ?>
<style>
.ib-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:0 0 16px}
.ib-stat{background:#fff;border:1px solid var(--gray-200);border-radius:12px;padding:14px 16px;display:flex;align-items:center;gap:12px}
.ib-stat i{width:38px;height:38px;border-radius:10px;display:grid;place-items:center;font-size:15px;flex-shrink:0}
.ib-stat b{display:block;font-size:1.25rem;line-height:1.2;color:var(--gray-900)}.ib-stat span{font-size:.78rem;color:var(--gray-500)}
.ib{display:grid;grid-template-columns:minmax(300px,400px) 1fr;background:#fff;border:1px solid var(--gray-200);border-radius:14px;overflow:hidden;min-height:560px}
.ib-left{border-right:1px solid var(--gray-200);display:flex;flex-direction:column;min-width:0}
.ib-tools{padding:12px;border-bottom:1px solid var(--gray-100);display:flex;flex-direction:column;gap:10px}
.ib-search{position:relative}.ib-search i{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--gray-400);font-size:13px}
.ib-search input{width:100%;padding:8px 10px 8px 32px;border:1px solid var(--gray-200);border-radius:9px;font:inherit;font-size:.875rem;background:var(--gray-50,#f9fafb)}
.ib-search input:focus{outline:0;border-color:var(--primary);background:#fff}
.ib-tabs{display:flex;gap:4px;overflow-x:auto;scrollbar-width:none}.ib-tabs::-webkit-scrollbar{display:none}
.ib-tabs a{padding:5px 10px;border-radius:99px;font-size:.78rem;font-weight:600;color:var(--gray-600);text-decoration:none;white-space:nowrap;background:var(--gray-100)}
.ib-tabs a.active{background:var(--primary);color:#fff}.ib-tabs a em{font-style:normal;opacity:.75;margin-left:3px}
.ib-bulk{display:flex;align-items:center;gap:6px;font-size:.8rem;color:var(--gray-600)}
.ib-bulk label{display:flex;align-items:center;gap:6px;cursor:pointer;margin-right:auto}
.ib-bulk button{border:1px solid var(--gray-200);background:#fff;border-radius:7px;padding:4px 8px;font-size:.75rem;cursor:pointer;color:var(--gray-700);font-family:inherit}
.ib-bulk button:hover{background:var(--gray-50,#f9fafb)}.ib-bulk button.danger{color:var(--danger)}.ib-bulk button:disabled{opacity:.45;cursor:default}
.ib-list{flex:1;overflow-y:auto;max-height:640px}
.ib-item{display:flex;gap:10px;padding:12px 12px 12px 10px;border-bottom:1px solid var(--gray-100);cursor:pointer;position:relative;border-left:3px solid transparent}
.ib-item:hover{background:var(--gray-50,#f9fafb)}.ib-item.active{background:var(--primary-lighter,#f5f3ff);border-left-color:var(--primary)}
.ib-item input{margin-top:10px;flex-shrink:0}
.ib-av{width:36px;height:36px;border-radius:50%;display:grid;place-items:center;font-weight:700;color:#fff;font-size:.9rem;flex-shrink:0}
.ib-main{min-width:0;flex:1}
.ib-top{display:flex;align-items:baseline;gap:8px}.ib-top strong{font-size:.875rem;color:var(--gray-800);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:600;flex:1;min-width:0}
.ib-top time{font-size:.72rem;color:var(--gray-400);white-space:nowrap}
.ib-sub{font-size:.8125rem;color:var(--gray-700);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ib-snip{font-size:.78rem;color:var(--gray-500);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ib-item.unread strong,.ib-item.unread .ib-sub{font-weight:700;color:var(--gray-900)}
.ib-item.unread::after{content:"";position:absolute;right:12px;bottom:16px;width:8px;height:8px;border-radius:50%;background:var(--primary)}
.ib-tag{display:inline-block;font-size:.65rem;font-weight:700;padding:1px 6px;border-radius:99px;background:#dcfce7;color:#166534;margin-left:4px;vertical-align:middle}
.ib-pager{display:flex;align-items:center;justify-content:space-between;padding:10px 12px;border-top:1px solid var(--gray-100);font-size:.78rem;color:var(--gray-500)}
.ib-pager a{padding:4px 9px;border:1px solid var(--gray-200);border-radius:7px;color:var(--gray-700);text-decoration:none}.ib-pager a.off{pointer-events:none;opacity:.4}
.ib-right{min-width:0;display:flex;flex-direction:column}
.ib-empty{flex:1;display:grid;place-items:center;text-align:center;color:var(--gray-400);padding:40px}.ib-empty i{font-size:2.6rem;margin-bottom:10px;display:block}
.ib-read{display:none;flex-direction:column;flex:1;min-height:0}.ib-read.show{display:flex}
.ib-rhead{padding:18px 22px;border-bottom:1px solid var(--gray-100);display:flex;gap:14px;align-items:flex-start}
.ib-rhead .ib-av{width:46px;height:46px;font-size:1.1rem}
.ib-rhead h2{margin:0 0 4px;font-size:1.15rem;color:var(--gray-900);line-height:1.35;overflow-wrap:anywhere}
.ib-from{font-size:.85rem;color:var(--gray-600);overflow-wrap:anywhere}.ib-from a{color:var(--primary)}
.ib-when{font-size:.78rem;color:var(--gray-400);margin-top:2px}
.ib-acts{display:flex;gap:6px;margin-left:auto;flex-shrink:0}
.ib-acts button{width:36px;height:36px;border-radius:9px;border:1px solid var(--gray-200);background:#fff;color:var(--gray-600);cursor:pointer}
.ib-acts button:hover{background:var(--gray-50,#f9fafb);color:var(--gray-900)}.ib-acts button.danger:hover{color:var(--danger);border-color:#fecaca;background:#fef2f2}
.ib-back{display:none;border:0;background:0 0;color:var(--primary);font-weight:600;padding:12px 22px 0;text-align:left;cursor:pointer;font-family:inherit}
.ib-body{padding:20px 22px;overflow-y:auto;flex:1}
.ib-text{white-space:pre-wrap;overflow-wrap:anywhere;font-size:.95rem;line-height:1.7;color:var(--gray-800)}
.ib-meta{display:flex;flex-wrap:wrap;gap:8px;margin-top:18px}.ib-meta span{font-size:.75rem;background:var(--gray-100);color:var(--gray-600);padding:3px 9px;border-radius:99px}
.ib-sent{margin-top:20px;border:1px solid #bbf7d0;background:#f0fdf4;border-radius:12px;padding:12px 14px}
.ib-sent b{font-size:.8rem;color:#166534;display:block;margin-bottom:4px}.ib-sent div{white-space:pre-wrap;font-size:.875rem;color:var(--gray-700)}
.ib-reply{border-top:1px solid var(--gray-100);padding:14px 22px 18px;background:var(--gray-50,#f9fafb)}
.ib-reply textarea{width:100%;min-height:110px;border:1px solid var(--gray-200);border-radius:10px;padding:10px 12px;font:inherit;font-size:.9rem;resize:vertical;background:#fff;box-sizing:border-box}
.ib-reply textarea:focus{outline:0;border-color:var(--primary)}
.ib-rfoot{display:flex;align-items:center;gap:10px;margin-top:10px;flex-wrap:wrap}.ib-rfoot small{color:var(--gray-500);font-size:.78rem;margin-right:auto}
@media (max-width:1100px){.ib-stats{grid-template-columns:repeat(2,1fr)}}
@media (max-width:820px){.ib{grid-template-columns:1fr}.ib-left{border-right:0}.ib-right{display:none}.ib.reading .ib-left{display:none}.ib.reading .ib-right{display:flex}.ib-back{display:block}.ib-list{max-height:none}.ib-rhead{padding:14px 16px}.ib-body{padding:16px}.ib-reply{padding:12px 16px}}
</style>
</head>
<body>
<?php admin_shell_open('Contact Messages', 'Messages sent from the Contact Us form'); ?>
<?= admin_flash() ?>
<div class="ib-stats">
  <div class="ib-stat"><i class="fas fa-inbox" style="background:#ede9fe;color:#7c3aed"></i><div><b data-count="all"><?= $counts['all'] ?></b><span>Total messages</span></div></div>
  <div class="ib-stat"><i class="fas fa-envelope" style="background:#fee2e2;color:#dc2626"></i><div><b data-count="new"><?= $counts['new'] ?></b><span>Unread</span></div></div>
  <div class="ib-stat"><i class="fas fa-reply" style="background:#dcfce7;color:#16a34a"></i><div><b data-count="replied"><?= $counts['replied'] ?></b><span>Replied</span></div></div>
  <div class="ib-stat"><i class="fas fa-calendar-week" style="background:#e0f2fe;color:#0284c7"></i><div><b><?= $week ?></b><span>Last 7 days</span></div></div>
</div>
<?php if (!$smtp): ?><div class="alert alert-info" style="margin-bottom:16px"><i class="fas fa-circle-info"></i> <span>To reply from here (and get a copy of new messages by email), add your SMTP details in <a href="/admin/general-settings.php#email">Settings → General → Email</a>. Until then "Send Reply" opens your email app.</span></div><?php endif; ?>

<div class="ib" id="ib">
  <div class="ib-left">
    <div class="ib-tools">
      <form class="ib-search" method="GET"><?php if ($status !== 'all'): ?><input type="hidden" name="status" value="<?= $status ?>"><?php endif; ?><i class="fas fa-search"></i><input type="search" name="q" value="<?= $esc($q) ?>" placeholder="Search name, email or text…"></form>
      <div class="ib-tabs"><?php foreach (['all' => 'All', 'new' => 'Unread', 'read' => 'Read', 'replied' => 'Replied'] as $k => $l): ?><a href="<?= $esc($link(['status' => $k === 'all' ? '' : $k, 'page' => ''])) ?>" class="<?= $status === $k ? 'active' : '' ?>"><?= $l ?><em data-count="<?= $k ?>"><?= $counts[$k] ?></em></a><?php endforeach; ?></div>
      <?php if ($rows): ?><div class="ib-bulk"><label><input type="checkbox" id="ibAll"> <span id="ibSelInfo">Select all</span></label>
        <button type="button" data-bulk="read" disabled title="Mark as read"><i class="fas fa-envelope-open"></i></button>
        <button type="button" data-bulk="new" disabled title="Mark as unread"><i class="fas fa-envelope"></i></button>
        <button type="button" data-bulk="delete" class="danger" disabled title="Delete"><i class="fas fa-trash"></i></button></div><?php endif; ?>
    </div>
    <div class="ib-list" id="ibList">
      <?php if (!$rows): ?><div class="ib-empty"><div><i class="fas fa-inbox"></i><?= $q !== '' ? 'No messages match "' . $esc($q) . '".' : ($status === 'new' ? 'No unread messages — all caught up.' : 'No messages yet.') ?></div></div><?php endif; ?>
      <?php foreach ($rows as $i => $m): $hue = crc32(strtolower($m['email'])) % 360; ?>
      <div class="ib-item<?= $m['status'] === 'new' ? ' unread' : '' ?>" data-i="<?= $i ?>" data-id="<?= (int)$m['id'] ?>">
        <input type="checkbox" class="ib-check" aria-label="Select">
        <span class="ib-av" style="background:hsl(<?= $hue ?>,55%,52%)"><?= $esc(mb_strtoupper(mb_substr($m['name'], 0, 1))) ?></span>
        <div class="ib-main">
          <div class="ib-top"><strong><?= $esc($m['name']) ?></strong><time title="<?= $esc($m['created_at']) ?>"><?= $ago($m['created_at']) ?></time></div>
          <div class="ib-sub"><?= $esc($m['subject'] ?: '(no subject)') ?><?= $m['status'] === 'replied' ? '<span class="ib-tag">Replied</span>' : '' ?></div>
          <div class="ib-snip"><?= $esc(mb_substr(preg_replace('/\s+/', ' ', $m['message']), 0, 120)) ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php if ($total > $per): ?><div class="ib-pager"><a class="<?= $page <= 1 ? 'off' : '' ?>" href="<?= $esc($link(['page' => $page - 1 > 1 ? $page - 1 : ''])) ?>"><i class="fas fa-chevron-left"></i></a><span><?= ($page - 1) * $per + 1 ?>–<?= min($total, $page * $per) ?> of <?= $total ?></span><a class="<?= $page >= $pages ? 'off' : '' ?>" href="<?= $esc($link(['page' => $page + 1])) ?>"><i class="fas fa-chevron-right"></i></a></div><?php endif; ?>
  </div>
  <div class="ib-right">
    <div class="ib-empty" id="ibNone"><div><i class="fas fa-envelope-open-text"></i>Select a message to read it</div></div>
    <div class="ib-read" id="ibRead">
      <button type="button" class="ib-back" id="ibBack"><i class="fas fa-arrow-left"></i> All messages</button>
      <div class="ib-rhead"><span class="ib-av" id="ibAv"></span><div style="min-width:0"><h2 id="ibSubject"></h2><div class="ib-from" id="ibFrom"></div><div class="ib-when" id="ibWhen"></div></div>
        <div class="ib-acts"><button type="button" id="ibUnread" title="Mark as unread"><i class="fas fa-envelope"></i></button><button type="button" id="ibDelete" class="danger" title="Delete"><i class="fas fa-trash"></i></button></div></div>
      <div class="ib-body"><div class="ib-text" id="ibText"></div><div class="ib-meta" id="ibMeta"></div><div id="ibSent"></div></div>
      <div class="ib-reply">
        <textarea id="ibReplyText" placeholder="Write your reply…"></textarea>
        <div class="ib-rfoot"><small id="ibReplyNote"><?= $smtp ? 'Sent from your site email, to the sender.' : 'Opens your email app with the reply.' ?></small><button type="button" class="btn btn-primary" id="ibSend"><i class="fas fa-paper-plane"></i> Send Reply</button></div>
      </div>
    </div>
  </div>
</div>
<?php admin_shell_close(); ?>
<script>
(function () {
  var msgs = <?= json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>;
  var token = <?= json_encode(csrf_token(), JSON_HEX_TAG) ?>, smtp = <?= $smtp ? 'true' : 'false' ?>, cur = null;
  var $ = function (id) { return document.getElementById(id); };
  function post(data) {
    var fd = new FormData(); fd.append('_csrf', token);
    Object.keys(data).forEach(function (k) { [].concat(data[k]).forEach(function (v) { fd.append(Array.isArray(data[k]) ? k + '[]' : k, v); }); });
    return fetch('/admin/contact-messages.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }
  function setCounts(c) {
    if (!c) return;
    document.querySelectorAll('[data-count]').forEach(function (e) { e.textContent = c[e.dataset.count]; });
    var n = c['new'];
    var link = document.querySelector('.sb-link[href="/admin/contact-messages.php"]'); if (!link) return;
    var b = link.querySelector('.sb-badge');
    if (!n) { if (b) b.remove(); return; }
    if (!b) { b = document.createElement('span'); b.className = 'sb-badge'; link.appendChild(b); }
    b.textContent = n > 99 ? '99+' : n;
  }
  function item(i) { return document.querySelector('.ib-item[data-i="' + i + '"]'); }
  function mark(i, st) {
    var m = msgs[i]; m.status = st;
    var el = item(i); el.classList.toggle('unread', st === 'new');
    var sub = el.querySelector('.ib-sub'), tag = sub.querySelector('.ib-tag');
    if (st === 'replied' && !tag) sub.insertAdjacentHTML('beforeend', '<span class="ib-tag">Replied</span>');
  }
  function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
  function open(i) {
    var m = msgs[i]; cur = i;
    document.querySelectorAll('.ib-item.active').forEach(function (e) { e.classList.remove('active'); });
    item(i).classList.add('active');
    var av = item(i).querySelector('.ib-av');
    $('ibAv').style.background = av.style.background; $('ibAv').textContent = av.textContent;
    $('ibSubject').textContent = m.subject || '(no subject)';
    $('ibFrom').innerHTML = '<b>' + esc(m.name) + '</b> &lt;<a href="mailto:' + esc(m.email) + '">' + esc(m.email) + '</a>&gt;';
    $('ibWhen').textContent = m.date;
    $('ibText').textContent = m.message;
    $('ibMeta').innerHTML = (m.ip ? '<span><i class="fas fa-location-dot"></i> IP ' + esc(m.ip) + '</span>' : '') + (m.device ? '<span><i class="fas fa-display"></i> ' + esc(m.device) + '</span>' : '');
    $('ibSent').innerHTML = m.reply ? '<div class="ib-sent"><b><i class="fas fa-check"></i> You replied' + (m.replied_at ? ' · ' + esc(m.replied_at) : '') + '</b><div>' + esc(m.reply) + '</div></div>' : '';
    $('ibReplyText').value = '';
    $('ibNone').style.display = 'none'; $('ibRead').classList.add('show'); $('ib').classList.add('reading');
    if (m.status === 'new') { mark(i, 'read'); post({ action: 'status', status: 'read', id: m.id }).then(function (d) { if (d.ok) setCounts(d.counts); }); }
    try { var u = new URL(location.href); u.searchParams.set('open', m.id); history.replaceState(null, '', u); } catch (e) {}
    if (innerWidth <= 820) scrollTo(0, 0);
  }
  function close() { $('ib').classList.remove('reading'); try { var u = new URL(location.href); u.searchParams.delete('open'); history.replaceState(null, '', u); } catch (e) {} }
  document.querySelectorAll('.ib-item').forEach(function (el) {
    el.addEventListener('click', function (e) { if (e.target.classList.contains('ib-check')) return; open(+el.dataset.i); });
  });
  $('ibBack').onclick = close;
  $('ibUnread').onclick = function () { if (cur === null) return; var m = msgs[cur]; mark(cur, 'new'); post({ action: 'status', status: 'new', id: m.id }).then(function (d) { if (d.ok) setCounts(d.counts); }); adminNotice('Marked as unread.', { type: 'success', autoClose: 1500 }); };
  function removeItems(ids) { ids.forEach(function (id) { var el = document.querySelector('.ib-item[data-id="' + id + '"]'); if (el) el.remove(); }); if (!document.querySelector('.ib-item')) location.reload(); }
  $('ibDelete').onclick = function () {
    if (cur === null) return; var m = msgs[cur];
    adminConfirm('This message from ' + m.name + ' will be deleted permanently.', { title: 'Delete message?', confirmText: 'Delete' }).then(function (ok) {
      if (!ok) return;
      post({ action: 'delete', id: m.id }).then(function (d) { if (!d.ok) return; setCounts(d.counts); removeItems([m.id]); cur = null; $('ibRead').classList.remove('show'); $('ibNone').style.display = ''; close(); });
    });
  };
  $('ibSend').onclick = function () {
    if (cur === null) return; var m = msgs[cur], body = $('ibReplyText').value.trim();
    if (!smtp) {
      location.href = 'mailto:' + encodeURIComponent(m.email) + '?subject=' + encodeURIComponent('Re: ' + (m.subject || 'your message')) + '&body=' + encodeURIComponent((body ? body + '\n\n' : '') + '> ' + m.message.replace(/\n/g, '\n> '));
      mark(cur, 'replied'); post({ action: 'status', status: 'replied', id: m.id }).then(function (d) { if (d.ok) setCounts(d.counts); });
      return;
    }
    if (!body) { $('ibReplyText').focus(); return; }
    var btn = this; btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending…';
    post({ action: 'reply', id: m.id, body: body }).then(function (d) {
      btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send Reply';
      if (!d.ok) { adminNotice(d.error || 'Could not send.', { type: 'error', autoClose: 0 }); return; }
      m.reply = body; m.replied_at = d.at; mark(cur, 'replied'); setCounts(d.counts); open(cur);
      adminNotice('Your reply was sent to ' + m.email + '.', { type: 'success' });
    }).catch(function () { btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send Reply'; adminNotice('Could not send — check your connection.', { type: 'error' }); });
  };
  // Bulk selection
  var all = $('ibAll');
  function selected() { return [].map.call(document.querySelectorAll('.ib-check:checked'), function (c) { return +c.closest('.ib-item').dataset.id; }); }
  function sync() {
    var n = selected().length, total = document.querySelectorAll('.ib-check').length;
    document.querySelectorAll('[data-bulk]').forEach(function (b) { b.disabled = !n; });
    if (all) { all.checked = n && n === total; all.indeterminate = n > 0 && n < total; $('ibSelInfo').textContent = n ? n + ' selected' : 'Select all'; }
  }
  document.addEventListener('change', function (e) { if (e.target.classList.contains('ib-check')) sync(); });
  if (all) all.addEventListener('change', function () { document.querySelectorAll('.ib-check').forEach(function (c) { c.checked = all.checked; }); sync(); });
  document.querySelectorAll('[data-bulk]').forEach(function (b) {
    b.onclick = function () {
      var ids = selected(), act = b.dataset.bulk; if (!ids.length) return;
      var go = function () {
        post(act === 'delete' ? { action: 'delete', ids: ids } : { action: 'status', status: act, ids: ids }).then(function (d) {
          if (!d.ok) return; setCounts(d.counts);
          if (act === 'delete') { removeItems(ids); if (cur !== null && ids.indexOf(msgs[cur].id) > -1) { cur = null; $('ibRead').classList.remove('show'); $('ibNone').style.display = ''; } }
          else msgs.forEach(function (m, i) { if (ids.indexOf(m.id) > -1) mark(i, act); });
          document.querySelectorAll('.ib-check').forEach(function (c) { c.checked = false; }); sync();
        });
      };
      if (act === 'delete') adminConfirm(ids.length + ' message(s) will be deleted permanently.', { title: 'Delete messages?', confirmText: 'Delete' }).then(function (ok) { if (ok) go(); });
      else go();
    };
  });
  // Open the message from the address (?open=ID), or the first one on wide screens
  var want = +(new URLSearchParams(location.search).get('open') || 0), idx = msgs.findIndex(function (m) { return m.id === want; });
  if (idx > -1) open(idx);
})();
</script>
</body>
</html>
