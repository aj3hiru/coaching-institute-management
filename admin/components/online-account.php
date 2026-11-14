<?php
// The student's online account inside the student profile (it replaced the separate "Store Customers" profile):
// what they can open (give free / take back / give back / block), their orders, login details, status, password, block / suspend.
// The forms post to shop-customers.php (its actions) with "to" = this profile, so staff come back here.
// Use: $oa = oa_load($pdo, $student);  then oa_side($oa, $me), oa_main($oa, $me) and once oa_modals($oa).

function oa_load(PDO $pdo, array $s): array
{
    $q = $pdo->prepare("SELECT * FROM sh_users WHERE student_id = ? OR mobile = ? ORDER BY student_id = ? DESC, id LIMIT 1");
    $q->execute([(int)$s['id'], (string)$s['mobile'], (int)$s['id']]);
    $u = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    $acc = $ord = [];
    if ($u) {
        $a = $pdo->prepare("SELECT a.*, p.title, p.type, u.username AS by_name FROM sh_access a JOIN sh_products p ON p.id = a.product_id LEFT JOIN users u ON u.id = a.granted_by WHERE a.user_id = ? ORDER BY a.revoked, a.granted_at DESC");
        $a->execute([(int)$u['id']]); $acc = $a->fetchAll(PDO::FETCH_ASSOC);
        $o = $pdo->prepare("SELECT * FROM sh_orders WHERE user_id = ? ORDER BY id DESC LIMIT 500"); $o->execute([(int)$u['id']]); $ord = $o->fetchAll(PDO::FETCH_ASSOC); // the whole order history
    }
    $products = $pdo->query("SELECT id, title, type, price, status FROM sh_products ORDER BY status = 'published' DESC, title")->fetchAll(PDO::FETCH_ASSOC);
    $open = count(array_filter($acc, fn($x) => !$x['revoked'] && empty($x['blocked']) && (!$x['expires_at'] || strtotime($x['expires_at']) > time())));
    $paid = array_filter($ord, fn($o) => $o['status'] === 'paid');
    return ['s' => $s, 'u' => $u, 'acc' => $acc, 'ord' => $ord, 'products' => $products, 'open' => $open,
        'blocked' => count(array_filter($acc, fn($x) => !empty($x['blocked']))), 'spent' => array_sum(array_column($paid, 'amount')), 'paid_n' => count($paid),
        'to' => '/admin/student-view.php?id=' . (int)$s['id']];
}

/** How long the student can open it: Lifetime / till date / ended / taken back / blocked. */
function oa_till(array $x): string
{
    if (!empty($x['blocked'])) return '<span class="ci-badge ci-b-red"><i class="fas fa-ban"></i> Blocked</span>';
    if ($x['revoked']) return '<span class="ci-badge ci-b-red">Taken back</span>';
    if (!$x['expires_at']) return '<span class="ci-badge ci-b-green">Lifetime access</span>';
    $t = strtotime($x['expires_at']);
    if ($t < time()) return '<span class="ci-badge ci-b-amber">Expired ' . date('d M Y', $t) . '</span>';
    $d = (int)ceil(($t - time()) / 86400);
    return date('d M Y', $t) . '<small class="ci-muted" style="display:block">' . $d . ' day' . ($d === 1 ? '' : 's') . ' left</small>';
}

function oa_hidden(array $oa, string $action, array $more = []): string
{
    $h = csrf_field() . '<input type="hidden" name="action" value="' . ci_e($action) . '"><input type="hidden" name="uid" value="' . (int)($oa['u']['id'] ?? 0) . '"><input type="hidden" name="to" value="' . ci_e($oa['to']) . '">';
    foreach ($more as $k => $v) $h .= '<input type="hidden" name="' . ci_e($k) . '" value="' . ci_e((string)$v) . '">';
    return $h;
}

/** Side card: login, status, last login, password, block / suspend. */
function oa_side(array $oa, array $me): void
{
    $u = $oa['u']; $s = $oa['s'];
    echo '<div class="ci-card sv-fold" id="online-account" data-ds="Online account"><div class="ci-card-h" role="button" tabindex="0" aria-expanded="false"><h3>Online account</h3>';
    if ($u) echo $u['status'] === 'active' ? '<span class="ci-badge ci-b-green">Active</span>' : '<span class="ci-badge ci-b-red"><i class="fas fa-ban"></i> Suspended</span>';
    echo '</div><div class="ci-card-b">';
    if (!$u) {
        echo '<p class="ci-muted" style="margin:0 0 10px">No online login yet.</p>';
        echo '<form method="post" action="/admin/shop-customers.php">' . oa_hidden($oa, 'create', ['student_id' => (int)$s['id']]) . '<div class="ci-f"><label>Password</label><input name="pass" minlength="4" autocomplete="new-password" placeholder="empty = date of birth (DDMMYYYY)"></div><button class="ci-btn" type="submit" style="margin-top:8px"><i class="fas fa-user-plus"></i> Make online login</button></form>';
        echo '</div></div>';
        return;
    }
    $mail = str_ends_with((string)$u['email'], '@noemail.invalid') ? '' : (string)$u['email'];
    echo '<dl class="ci-kv ci-kv1"><dt>Login</dt><dd>' . ci_e($u['mobile']) . ($mail ? '<br><small class="ci-muted">' . ci_e($mail) . '</small>' : '') . '</dd>'
        . '<dt>Joined online</dt><dd>' . date('d M Y', strtotime($u['created_at'])) . '</dd>'
        . '<dt>Last login</dt><dd>' . ($u['last_login'] ? date('d M Y, h:i A', strtotime($u['last_login'])) : 'Never') . '</dd>'
        . '<dt>Can open now</dt><dd>' . (int)$oa['open'] . ' course' . ($oa['open'] === 1 ? '' : 's') . ' / notes</dd>'
        . '<dt>Spent online</dt><dd>' . ci_money0($oa['spent']) . ' · ' . $oa['paid_n'] . ' paid order' . ($oa['paid_n'] === 1 ? '' : 's') . '</dd></dl>';
    $badges = ($oa['blocked'] ? '<span class="ci-badge ci-b-amber">Blocked from ' . $oa['blocked'] . ' course' . ($oa['blocked'] === 1 ? '' : 's') . '</span> ' : '') . (!empty($u['block_free']) ? '<span class="ci-badge ci-b-amber">No free courses</span>' : '');
    if ($badges) echo '<p style="margin:8px 0 0">' . $badges . '</p>';
    if (!empty($u['block_note']) && ($u['status'] !== 'active' || $oa['blocked'] || !empty($u['block_free']))) echo '<p class="oa-note"><i class="fas fa-circle-info"></i> ' . ci_e($u['block_note']) . '</p>';
    // login email + status
    echo '<details class="oa-more"><summary>Login email &amp; status</summary><form method="post" action="/admin/shop-customers.php">' . oa_hidden($oa, 'save', ['name' => $s['name'], 'mobile' => $u['mobile']])
        . '<div class="ci-f"><label>Email</label><input name="email" type="email" required value="' . ci_e($u['email']) . '"></div>'
        . '<div class="ci-f" style="margin-top:8px"><label>Status</label><select name="status"><option value="active">Active</option><option value="blocked"' . ($u['status'] === 'blocked' ? ' selected' : '') . '>Suspended</option></select></div>'
        . '<button class="ci-btn ci-sm" type="submit" style="margin-top:8px">Save</button></form></details>';
    // password: a button first, the field after clicking it
    echo '<div class="oa-pw"><button type="button" class="ci-btn ci-ghost ci-sm" data-oa-pw><i class="fas fa-key"></i> Set new password</button>'
        . '<form method="post" action="/admin/shop-customers.php" hidden>' . oa_hidden($oa, 'password')
        . '<div class="ci-f"><label>New password</label><input name="pass" minlength="4" autocomplete="new-password" placeholder="empty = a new one is made"></div>'
        . '<div style="display:flex;gap:6px;margin-top:8px"><button class="ci-btn ci-sm" type="submit">Save password</button><button type="button" class="ci-btn ci-ghost ci-sm" data-oa-pw-x>Cancel</button></div></form></div>';
    echo '<div class="oa-acts"><button type="button" class="ci-btn ci-ghost ci-sm oa-ban" data-block="' . (int)$u['id'] . '"><i class="fas fa-ban"></i> Block / suspend</button></div>';
    echo '</div></div>';
}

/** Main column: online courses & notes (give / take back) and orders. */
function oa_main(array $oa, array $me): void
{
    $u = $oa['u']; $s = $oa['s']; $sell = sh_perm($me, 'orders');
    if (!$oa['acc'] && !$oa['ord']) return; // not in any online course / notes and no orders: nothing to show (Enroll is on the top card)
    $enrollBtn = $sell ? '<button type="button" class="ci-btn ci-sm ci-noprint" data-enroll="Enroll ' . ci_e($s['name']) . '" data-enroll-sid="' . (int)$s['id'] . '" data-enroll-name="' . ci_e($s['name']) . '"><i class="fas fa-plus"></i> Add to Course</button>' : '';
    echo '<div class="ci-sec-h" id="online" data-ds="Online courses &amp; notes"><h3>Online courses &amp; notes <span class="ci-muted">(' . count(array_filter($oa['acc'], fn($x) => $x['source'] !== 'block')) . ')</span></h3>' . $enrollBtn . '</div>';

    echo '<div class="ci-card">';
    if ($u) {
        echo '<div class="ci-card-b ci-noprint"><form method="post" action="/admin/shop-customers.php" class="oa-give">' . oa_hidden($oa, 'grant')
            . '<div class="ci-f oa-give-p"><label>Give a course / notes free</label><select name="product_id" required data-search><option value=""></option>';
        foreach ($oa['products'] as $pp) echo '<option value="' . (int)$pp['id'] . '">' . ci_e($pp['title'] . ' — ' . ((float)$pp['price'] > 0 ? ci_money0($pp['price']) : 'Free') . ($pp['status'] !== 'published' ? ' (' . $pp['status'] . ')' : '')) . '</option>';
        echo '</select></div><div class="ci-f"><label>For how long</label><select name="days"><option value="">Its own access period</option><option value="0">Lifetime</option><option value="30">1 month</option><option value="90">3 months</option><option value="180">6 months</option><option value="365">1 year</option></select></div>'
            . '<div class="ci-f"><label>Note</label><input name="note" maxlength="200" placeholder="e.g. Offline batch student"></div>'
            . '<div class="ci-f"><label>&nbsp;</label><button class="ci-btn" type="submit"><i class="fas fa-key"></i> Give access</button></div></form></div>';
    }
    echo '<div class="ci-card-b ci-p0 ci-table-wrap"><table class="ci-table" data-sheet="off"><thead><tr><th>Item</th><th>How</th><th>Since</th><th>Access</th><th class="ci-noprint">Action</th></tr></thead><tbody>';
    if (!$oa['acc']) echo '<tr><td colspan="5" class="ci-empty">No online course or notes yet.' . ($sell ? ' Use <b>+ Add to Course</b> above.' : '') . '</td></tr>';
    foreach ($oa['acc'] as $x) {
        $how = ['purchase' => 'Bought', 'admin' => 'Given' . ($x['by_name'] ? ' by ' . ci_e($x['by_name']) : ''), 'free' => 'Free', 'block' => 'Never had it'][$x['source']] ?? ci_e($x['source']);
        $act = !empty($x['blocked']) ? ci_ico('ban', null, 'Change the block', 'button', ' data-block="' . (int)$u['id'] . '"')
            : ($x['revoked'] ? ci_post_ico('check', 'Give back', ['action' => 'restore', 'aid' => $x['id'], 'uid' => $u['id'], 'to' => $oa['to']], '', '/admin/shop-customers.php')
            : ci_post_ico('trash', 'Take back access', ['action' => 'revoke', 'aid' => $x['id'], 'uid' => $u['id'], 'to' => $oa['to']], 'Take back access to ' . $x['title'] . '? (A paid purchase is not refunded by this.)', '/admin/shop-customers.php'));
        echo '<tr' . ($x['revoked'] ? ' style="opacity:.6"' : '') . '><td><b>' . ci_e($x['title']) . '</b> <small class="ci-muted">' . ($x['type'] === 'notes' ? 'PDF notes' : 'Video course') . '</small></td><td>' . $how . ($x['note'] ? '<small class="ci-muted" style="display:block">' . ci_e($x['note']) . '</small>' : '') . '</td>'
            . '<td class="ci-nw">' . date('d M Y', strtotime($x['granted_at'])) . '</td><td class="ci-nw">' . oa_till($x) . '</td><td class="ci-noprint"><div class="ci-icos">' . $act . '</div></td></tr>';
    }
    echo '</tbody></table></div></div>';
    // every order (also old ones whose access has ended), each with its invoice
    $paid = array_filter($oa['ord'], fn($o) => $o['status'] === 'paid');
    echo '<div class="ci-sec-h" data-ds="Online orders &amp; invoices" style="margin-top:16px"><h3>Online orders &amp; invoices <span class="ci-muted">(' . count($oa['ord']) . ')</span></h3>'
        . ($paid ? '<span class="ci-muted">' . count($paid) . ' paid · ' . ci_money0(array_sum(array_column($paid, 'amount'))) . '</span>' : '') . '</div>';
    if (!$oa['ord']) { echo '<div class="ci-card"><div class="ci-card-b ci-empty" style="padding:14px 16px">No online orders yet.</div></div>'; return; }
    echo '<div class="ci-card"><div class="ci-card-b ci-p0 ci-table-wrap"><table class="ci-table" data-sheet="off"><thead><tr><th>Order</th><th>Item</th><th class="ci-num">Amount</th><th>Status</th><th>Paid by</th><th>Date</th><th class="ci-noprint">Invoice</th></tr></thead><tbody>';
    if (!$oa['ord']) echo '<tr><td colspan="7" class="ci-empty">No online orders yet.</td></tr>';
    foreach ($oa['ord'] as $o) {
        $office = str_starts_with((string)$o['rzp_order'], 'manual_');
        echo '<tr><td class="ci-nw"><b>' . ci_e($o['order_no'] ?: '#' . $o['id']) . '</b></td><td>' . ci_e($o['title']) . '</td><td class="ci-num">' . ci_money0($o['amount']) . '</td><td>'
            . (['paid' => '<span class="ci-badge ci-b-green">Paid</span>', 'created' => '<span class="ci-badge ci-b-amber">Not paid</span>', 'failed' => '<span class="ci-badge ci-b-red">Failed</span>'][$o['status']] ?? '') . '</td>'
            . '<td>' . ($office ? 'Office' : 'Online') . ($o['method'] ? '<small class="ci-muted" style="display:block">' . ci_e($o['method']) . '</small>' : '') . ($o['rzp_payment'] && !$office ? '<small class="ci-muted" style="display:block">' . ci_e($o['rzp_payment']) . '</small>' : '') . '</td>'
            . '<td class="ci-nw">' . date('d M Y, h:i A', strtotime($o['paid_at'] ?: $o['created_at'])) . '</td>'
            . '<td class="ci-noprint">' . ($o['status'] === 'paid' ? '<a class="ci-ico" href="/admin/sh-invoice.php?id=' . (int)$o['id'] . '" target="_blank" aria-label="Invoice" title="Print invoice">' . ci_svg('print') . '</a>' : '<span class="ci-muted">—</span>') . '</td></tr>';
    }
    echo '</tbody></table></div></div>';
}

/** The Block / suspend popup (and the password button script). Once per page. */
function oa_modals(array $oa): void
{
    if (!$oa['u']) return; ?>
<style>
.oa-note{margin:8px 0 0;font-size:13px;color:#92400e;background:#fffbeb;border:1px solid #fde68a;padding:6px 10px}
.oa-more{margin-top:12px;border-top:1px solid #eef0f3;padding-top:10px}.oa-more summary{cursor:pointer;font-weight:600;font-size:13.5px}.oa-more form{margin-top:10px}
.oa-pw{margin-top:12px;border-top:1px solid #eef0f3;padding-top:12px}.oa-pw form{margin-top:0}
.oa-acts{margin-top:10px}.oa-ban{color:#b91c1c!important;border-color:#fecaca!important}
.oa-give{display:grid;grid-template-columns:minmax(220px,2fr) minmax(150px,1fr) minmax(150px,1fr) auto;gap:12px;align-items:end}
@media (max-width:900px){.oa-give{grid-template-columns:1fr 1fr}.oa-give-p{grid-column:1/-1}}
@media (max-width:560px){.oa-give{grid-template-columns:1fr}}
.bk-items{display:flex;flex-direction:column;gap:6px;max-height:300px;overflow:auto;border:1px solid #eef0f3;padding:8px}
.bk-it{display:flex;gap:10px;align-items:center;padding:8px 10px;border:1px solid #e5e7eb;background:#fff;cursor:pointer}.bk-it.on{border-color:#fca5a5;background:#fef2f2}
.bk-it small{color:#6b7280;font-size:12px;display:block}.bk-it .bk-st{margin-left:auto;font-size:11.5px}
.bk-add{margin-top:8px}.bk-add select{width:100%}
.bk-opt{display:flex;gap:10px;align-items:flex-start;padding:10px 12px;border:1px solid #e5e7eb;margin-top:10px;cursor:pointer}.bk-opt small{display:block;color:#6b7280;font-size:12.5px;font-weight:400}
.bk-susp{border-color:#fecaca;background:#fff7f7}.bk-susp b{color:#b91c1c}
</style>
<div class="ci-modal" id="bkForm" data-mform hidden><div class="ci-modal-box ci-modal-wide" role="dialog" aria-modal="true" aria-labelledby="bkH">
<div class="ci-card-h"><h3 id="bkH">Block</h3><button type="button" class="ci-x" data-close aria-label="Close"><i class="fas fa-times"></i></button></div><div class="ci-card-b">
<form method="post" action="/admin/shop-customers.php"><?= oa_hidden($oa, 'block_save') ?>
  <p class="ci-muted" style="margin:0 0 8px">Tick the courses this student must not open any more. A blocked course can't be bought or joined again by them. Untick to lift a block.</p>
  <div class="bk-items" id="bkItems"><p class="ci-muted" style="margin:6px">Loading…</p></div>
  <div class="bk-add"><select id="bkOther" aria-label="Block from another course"><option value="">+ Block from another course (one they don't have)…</option></select></div>
  <label class="bk-opt"><input type="checkbox" name="block_free" value="1"><span><b>Block from every free course</b><small>Free courses they joined close, and they can't join free courses.</small></span></label>
  <label class="bk-opt bk-susp"><input type="checkbox" name="suspend" value="1"><span><b>Suspend the whole account</b><small>No login and no course at all, and this mobile number and email can't be used to make a new account.</small></span></label>
  <div class="ci-f" style="margin-top:10px"><label>Reason <span class="ci-muted" style="font-weight:400">(only staff see it)</span></label><input name="block_note" maxlength="255" placeholder="e.g. Shared the login with others"></div>
  <div class="ci-mfoot"><button type="button" class="ci-btn ci-ghost" data-close>Cancel</button><button class="ci-btn" type="submit" style="background:#b91c1c;border-color:#b91c1c"><i class="fas fa-ban"></i> Save block</button></div>
</form></div></div></div>
<script>
(function () {
  var pw = document.querySelector('[data-oa-pw]');
  if (pw) { var pf = pw.nextElementSibling; pw.addEventListener('click', function () { pw.hidden = true; pf.hidden = false; pf.querySelector('input[name=pass]').focus(); });
    pf.querySelector('[data-oa-pw-x]').addEventListener('click', function () { pf.hidden = true; pw.hidden = false; pf.querySelector('input[name=pass]').value = ''; }); }
  var m = document.getElementById('bkForm'), f = m.querySelector('form'), box = document.getElementById('bkItems');
  function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : t; return d.innerHTML; }
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-block]'); if (!b) return; e.preventDefault();
    var uid = b.getAttribute('data-block'); f.uid.value = uid; box.innerHTML = '<p class="ci-muted" style="margin:6px">Loading…</p>';
    f.block_free.checked = false; f.suspend.checked = false; f.block_note.value = '';
    window.ciModal.open('#bkForm');
    fetch('/admin/shop-customers.php?blockinfo=' + uid, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      if (d.error) { box.innerHTML = '<p style="color:#b91c1c;margin:6px">' + esc(d.error) + '</p>'; return; }
      document.getElementById('bkH').textContent = 'Block — ' + d.user.name;
      var so = document.getElementById('bkOther'); so.innerHTML = '<option value="">+ Block from another course (one they don\'t have)…</option>' + (d.others || []).map(function (o) { return '<option value="' + o.id + '">' + esc(o.title) + '</option>'; }).join('');
      f.block_free.checked = +d.user.block_free === 1; f.suspend.checked = d.user.status !== 'active'; f.block_note.value = d.user.block_note || '';
      box.innerHTML = d.items.length ? d.items.map(function (it) {
        return '<label class="bk-it' + (it.blocked ? ' on' : '') + '"><input type="checkbox" name="block_access[]" value="' + it.id + '"' + (it.blocked ? ' checked' : '') + '><span><b>' + esc(it.title) + '</b><small>' + esc(it.kind) + ' · ' + esc(it.how) + (it.free && it.how !== 'Free' ? ' · free course' : '') + '</small></span><span class="bk-st ci-badge ' + (it.blocked ? 'ci-b-red">Blocked' : (it.active ? 'ci-b-green">Can open' : 'ci-b-grey">Closed')) + '</span></label>';
      }).join('') : '<p class="ci-muted" style="margin:6px">No online course yet — you can still suspend the account.</p>';
    }).catch(function () { box.innerHTML = '<p style="color:#b91c1c;margin:6px">Could not load the courses.</p>'; });
  });
  box.addEventListener('change', function (e) { var l = e.target.closest('.bk-it'); if (l) l.classList.toggle('on', e.target.checked); });
  document.getElementById('bkOther').addEventListener('change', function () {
    var o = this.options[this.selectedIndex]; if (!this.value) return;
    var p = box.querySelector('p'); if (p && !box.querySelector('.bk-it')) p.remove();
    var l = document.createElement('label'); l.className = 'bk-it on';
    l.innerHTML = '<input type="checkbox" name="block_new[]" value="' + this.value + '" checked><span><b>' + esc(o.textContent) + '</b><small>They don\'t have it — they won\'t be able to buy or join it</small></span><span class="bk-st ci-badge ci-b-red">New block</span>';
    box.appendChild(l); o.remove(); this.value = '';
  });
})();
</script>
<?php }
