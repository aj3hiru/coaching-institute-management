<?php
// "Enroll / New order" popup: a student paid at the office (cash, UPI, card …) or gets a course free.
// Step 1 — who: an online account, an institute student, or a new student. Step 2 — what: course / notes, amount,
// payment mode, date, access period, note. Posts to shop-orders.php (action=manual) and comes back to this page.
// Open with <button data-enroll> ; preset the student with data-enroll-uid="N" or data-enroll-sid="N" (+ data-enroll-name).
// Needs $pdo. Include once per page.
require_once INCLUDES_PATH . '/shop.php';
$__ep = $pdo->query("SELECT id, title, type, price, validity_days FROM sh_products WHERE status <> 'draft' ORDER BY type = 'course' DESC, title")->fetchAll(PDO::FETCH_ASSOC);
$__eback = $_SERVER['REQUEST_URI'] ?? '/admin/shop-orders.php';
?>
<div class="ci-modal" id="shEnroll" data-mform hidden><div class="ci-modal-box ci-modal-wide" role="dialog" aria-modal="true" aria-labelledby="shEnrollH">
<div class="ci-card-h"><h3 id="shEnrollH" data-mtitle-target>New order</h3><button type="button" class="ci-x" data-close aria-label="Close"><i class="fas fa-times"></i></button></div><div class="ci-card-b">
<form method="post" action="/admin/shop-orders.php" id="shEnrollF"><?= csrf_field() ?><input type="hidden" name="action" value="manual"><input type="hidden" name="back" value="<?= ci_e($__eback) ?>">
  <input type="hidden" name="who" value="acc"><input type="hidden" name="uid" value=""><input type="hidden" name="sid" value="">
  <div class="she-step" id="sheWho">
    <div class="she-h"><span>1</span> Student</div>
    <div class="she-tabs" role="tablist">
      <button type="button" data-who="acc" class="on">Online account</button><button type="button" data-who="stu">Institute student</button><button type="button" data-who="new">New student</button>
    </div>
    <div class="she-pane" data-pane="acc stu"><input class="she-q" type="search" placeholder="Search name, mobile, email or admission ID" autocomplete="off"><div class="she-res" aria-live="polite"></div></div>
    <div class="she-pane ci-grid2 ci-mgrid" data-pane="new" hidden>
      <div class="ci-f"><label>Full name <span class="ci-req">*</span></label><input name="n_name" maxlength="150"></div>
      <div class="ci-f"><label>Mobile <span class="ci-req">*</span></label><input name="n_mobile" inputmode="numeric" maxlength="14" placeholder="10 digits"></div>
      <div class="ci-f"><label>Email <span class="ci-muted" style="font-weight:400">(optional)</span></label><input name="n_email" type="email" maxlength="190"></div>
      <div class="ci-f"><label>Password <span class="ci-muted" style="font-weight:400">(optional)</span></label><input name="n_pass" minlength="4" autocomplete="new-password" placeholder="empty = a 6-digit one is made"></div>
    </div>
  </div>
  <div class="she-chosen" id="sheChosen" hidden><span class="she-av"><i class="fas fa-user"></i></span><div><b></b><small></small></div><button type="button" class="ci-btn ci-ghost ci-sm" id="sheChange">Change</button></div>
  <div class="she-step">
    <div class="she-h"><span>2</span> Course / notes and payment</div>
    <div class="ci-grid2 ci-mgrid">
      <div class="ci-f" style="grid-column:1/-1"><label>Course / notes <span class="ci-req">*</span></label><select name="product_id" id="sheProd" data-search required><option value="">Choose…</option>
        <optgroup label="Video courses"><?php foreach ($__ep as $x) if ($x['type'] === 'course'): ?><option value="<?= (int)$x['id'] ?>" data-price="<?= (float)$x['price'] ?>"><?= ci_e($x['title'] . ' — ' . ($x['price'] > 0 ? '₹' . number_format((float)$x['price']) : 'Free')) ?></option><?php endif; ?></optgroup>
        <optgroup label="PDF notes"><?php foreach ($__ep as $x) if ($x['type'] === 'notes'): ?><option value="<?= (int)$x['id'] ?>" data-price="<?= (float)$x['price'] ?>"><?= ci_e($x['title'] . ' — ' . ($x['price'] > 0 ? '₹' . number_format((float)$x['price']) : 'Free')) ?></option><?php endif; ?></optgroup>
      </select></div>
      <div class="ci-f"><label>Payment mode <span class="ci-req">*</span></label><select name="mode" id="sheMode"><?php foreach (SH_PAY_MODES as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></div>
      <div class="ci-f" id="sheAmtF"><label>Amount received (₹)</label><input name="amount" id="sheAmt" type="number" min="0" step="0.01"></div>
      <div class="ci-f"><label>Paid on</label><input type="date" name="paid_on" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>"></div>
      <div class="ci-f"><label>Access for</label><select name="days"><option value="">Course's own period</option><option value="0">Lifetime</option><option value="30">1 month</option><option value="90">3 months</option><option value="180">6 months</option><option value="365">1 year</option></select></div>
      <div class="ci-f" style="grid-column:1/-1"><label>Note</label><input name="note" maxlength="200" placeholder="e.g. UPI ref no., receipt no."></div>
    </div>
    <div class="she-sum" id="sheSum"></div>
  </div>
  <div class="ci-mfoot"><button type="button" class="ci-btn ci-ghost" data-close>Cancel</button><button class="ci-btn" type="submit"><i class="fas fa-check"></i> Save &amp; give access</button></div>
</form></div></div></div>
<script>
(function () {
  var m = document.getElementById('shEnroll'), f = document.getElementById('shEnrollF'), who = 'acc', t = 0, seq = 0;
  var esc = function (s) { return String(s || '').replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
  var q = f.querySelector('.she-q'), res = f.querySelector('.she-res'), chosen = document.getElementById('sheChosen'), whoBox = document.getElementById('sheWho');
  var prod = document.getElementById('sheProd'), mode = document.getElementById('sheMode'), amt = document.getElementById('sheAmt'), sum = document.getElementById('sheSum');
  function setWho(w) {
    who = w; f.who.value = w;
    [].forEach.call(f.querySelectorAll('[data-who]'), function (b) { b.classList.toggle('on', b.getAttribute('data-who') === w); });
    [].forEach.call(f.querySelectorAll('.she-pane'), function (p) { p.hidden = p.getAttribute('data-pane').split(' ').indexOf(w) < 0; });
    f.querySelector('[name=n_name]').required = f.querySelector('[name=n_mobile]').required = w === 'new';
    if (w !== 'new') { q.placeholder = w === 'acc' ? 'Search name, mobile or email of the online account' : 'Search name, mobile, admission ID or father\'s name'; search(); }
  }
  function pickPerson(kind, id, name, sub) {
    f.uid.value = kind === 'acc' ? id : ''; f.sid.value = kind === 'stu' ? id : ''; f.who.value = kind;
    chosen.querySelector('b').textContent = name; chosen.querySelector('small').textContent = sub || '';
    chosen.hidden = false; whoBox.hidden = true;
  }
  function search() {
    var my = ++seq; res.innerHTML = '<p class="she-msg">Searching…</p>';
    fetch('/admin/shop-api.php?a=' + (who === 'acc' ? 'users' : 'students') + '&q=' + encodeURIComponent(q.value.trim()), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      if (my !== seq) return;
      if (!d.list || !d.list.length) { res.innerHTML = '<p class="she-msg">Nobody found. ' + (who === 'acc' ? 'Try Institute student, or add a New student.' : 'Try another name, or add a New student.') + '</p>'; return; }
      res.innerHTML = d.list.map(function (x) {
        var sub = who === 'acc' ? x.mobile + (x.email ? ' · ' + x.email : '') : x.reg + ' · ' + x.mobile + (x.class ? ' · Class ' + x.class : '') + (x.uid ? ' · has an online account' : '');
        return '<button type="button" class="she-row" data-id="' + x.id + '" data-name="' + esc(x.name) + '" data-sub="' + esc(sub) + '"><b>' + esc(x.name) + '</b><small>' + esc(sub) + '</small></button>';
      }).join('');
    }).catch(function () { res.innerHTML = '<p class="she-msg">Could not search. Please try again.</p>'; });
  }
  function paint() {
    var o = prod.options[prod.selectedIndex], price = o && o.dataset.price !== undefined ? +o.dataset.price : null, free = mode.value === 'free';
    document.getElementById('sheAmtF').hidden = free;
    if (!prod.value) { sum.innerHTML = ''; return; }
    var a = free ? 0 : (amt.value === '' ? price : +amt.value);
    sum.innerHTML = free ? '<span>Given <b>free</b> — no payment is recorded.</span>' : '<span>Price <b>₹' + (price || 0).toLocaleString('en-IN') + '</b></span>' + (a < price ? '<span>Discount <b>₹' + (price - a).toLocaleString('en-IN') + '</b></span>' : '') + '<span>Received <b class="ci-g">₹' + (a || 0).toLocaleString('en-IN') + '</b> by ' + esc(mode.options[mode.selectedIndex].text) + '</span>';
  }
  f.addEventListener('click', function (e) {
    var w = e.target.closest('[data-who]'); if (w) { setWho(w.getAttribute('data-who')); return; }
    var r = e.target.closest('.she-row'); if (r) { pickPerson(who, r.dataset.id, r.dataset.name, r.dataset.sub); return; }
  });
  document.getElementById('sheChange').addEventListener('click', function () { chosen.hidden = true; whoBox.hidden = false; f.uid.value = ''; f.sid.value = ''; setWho(who === 'new' ? 'acc' : who); });
  q.addEventListener('input', function () { clearTimeout(t); t = setTimeout(search, 250); });
  prod.addEventListener('change', function () { var o = prod.options[prod.selectedIndex]; amt.value = o && o.dataset.price !== undefined ? o.dataset.price : ''; paint(); });
  mode.addEventListener('change', paint); amt.addEventListener('input', paint);
  f.addEventListener('submit', function (e) {
    var w = f.who.value;
    if ((w === 'acc' && !f.uid.value) || (w === 'stu' && !f.sid.value)) { e.preventDefault(); alert('Choose the student first (search and press their name), or add a New student.'); }
  });
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-enroll]'); if (!b) return;
    e.preventDefault();
    f.reset(); f.paid_on.value = f.paid_on.defaultValue; chosen.hidden = true; whoBox.hidden = false; f.uid.value = ''; f.sid.value = '';
    document.getElementById('shEnrollH').textContent = b.getAttribute('data-enroll') || 'New order';
    if (b.dataset.enrollUid) pickPerson('acc', b.dataset.enrollUid, b.dataset.enrollName || 'Student', 'Online account');
    else if (b.dataset.enrollSid) pickPerson('stu', b.dataset.enrollSid, b.dataset.enrollName || 'Student', 'Institute student');
    else setWho('acc');
    [].forEach.call(f.querySelectorAll('select'), function (s) { s.dispatchEvent(new Event('change', { bubbles: true })); });
    if (b.dataset.enrollPid) { prod.value = b.dataset.enrollPid; prod.dispatchEvent(new Event('change', { bubbles: true })); }
    paint(); ciModal.open('#shEnroll');
  });
})();
</script>
