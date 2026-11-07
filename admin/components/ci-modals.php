<?php
// Popups used on list pages (All Students, Dues): Collect fee and Add to course / batch — like the shop's due collect.
// Include after the page content; needs $pdo, $me. Buttons: data-collect-sid="N" and data-addc-sid="N".
// Both forms post to student-view.php with "back" = this page, so the list reloads with a message.
if (!function_exists('ci_lists')) require_once INCLUDES_PATH . '/institute.php';
$__L = ci_lists($pdo);
$__back = $_SERVER['REQUEST_URI'] ?? '/admin/students.php';
$__canPay = ci_perm($me, 'fees');
$__canAdd = ci_perm($me, 'edit_student') || ci_perm($me, 'add_student');
?>
<?php if ($__canPay): ?>
<div class="ci-modal" id="ci-lc" hidden><div class="ci-modal-box" role="dialog" aria-modal="true" aria-labelledby="ci-lc-h">
  <div class="ci-card-h"><h3 id="ci-lc-h">Collect fee</h3><button type="button" class="ci-x" data-close aria-label="Close"><i class="fas fa-times"></i></button></div>
  <form method="post" action="/admin/student-view.php" class="ci-card-b" id="ci-lc-f"><?= csrf_field() ?><input type="hidden" name="action" value="pay"><input type="hidden" name="id" value=""><input type="hidden" name="back" value="<?= ci_e($__back) ?>">
    <p class="ci-muted" id="ci-lc-who" style="margin:0 0 12px"></p>
    <div class="ci-f"><label>For</label><select name="enrollment_id" id="ci-lc-for" required></select></div>
    <div class="ci-chips" id="ci-lc-chips"></div>
    <div class="ci-grid2" style="gap:12px;margin-top:12px">
      <div class="ci-f ci-nofloat" style="grid-column:1/-1"><?= ci_pays_html('pm[]', 'pa[]', [], 'Amount received') ?></div>
      <div class="ci-f"><label>Date</label><input type="date" name="paid_on" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>"></div>
      <div class="ci-f"><label>Next due date</label><input type="date" name="next_due"></div>
    </div>
    <div class="ci-f" style="margin-top:12px"><label>Note</label><input name="note" maxlength="250" placeholder="e.g. UPI ref no."></div>
    <div style="display:flex;gap:8px;margin-top:14px"><button class="ci-btn" type="submit"><?= ci_svg('check') ?> Save &amp; get receipt</button><button type="button" class="ci-btn ci-ghost" data-close>Cancel</button></div>
  </form>
</div></div>
<?php endif; ?>
<?php if ($__canAdd): ?>
<div class="ci-modal" id="ci-la" hidden><div class="ci-modal-box ci-modal-wide" role="dialog" aria-modal="true" aria-labelledby="ci-la-h">
  <div class="ci-card-h"><h3 id="ci-la-h">Add to a course / batch</h3><button type="button" class="ci-x" data-close aria-label="Close"><i class="fas fa-times"></i></button></div>
  <form method="post" action="/admin/student-view.php" class="ci-card-b ci-float" id="ci-la-f"><?= csrf_field() ?><input type="hidden" name="action" value="enroll"><input type="hidden" name="id" value=""><input type="hidden" name="back" value="<?= ci_e($__back) ?>">
    <p class="ci-muted" id="ci-la-who" style="margin:0 0 14px"></p>
    <div class="ci-grid2" style="gap:16px 12px">
      <div class="ci-f"><label>Course</label><select name="course_id" id="la-course" data-search required><option value=""></option></select></div>
      <div class="ci-f"><label>Batch</label><select name="batch_id" id="la-batch" data-search><option value="">Choose the course first</option></select></div>
      <div class="ci-f"><label>Fee plan</label><select name="fee_type" id="la-type"><option value="full">One-time fee</option><option value="installment">Installment</option></select></div>
      <div class="ci-f"><label>Fee for this student (₹)</label><input type="number" name="final" id="la-final" min="0" step="0.01"></div>
      <div class="ci-f ci-nofloat" style="grid-column:1/-1"><?= ci_pays_html('epm[]', 'epa[]', [], 'Paying now') ?></div>
      <div class="ci-f" id="la-due-f" hidden><label>Next due date</label><input type="date" name="due"></div>
    </div>
    <div class="ci-row-sum" id="la-sum" style="margin-top:12px"></div>
    <div style="display:flex;gap:8px;margin-top:16px"><button class="ci-btn" type="submit"><?= ci_svg('check') ?> Save</button><button type="button" class="ci-btn ci-ghost" data-close>Cancel</button></div>
  </form>
</div></div>
<?php endif; ?>
<div class="ci-toast" id="ci-toast" hidden role="status"></div>
<script>
(function () {
  var inr = function (n) { return '₹' + (Math.round(n * 100) / 100).toLocaleString('en-IN'); };
  var C = <?= json_encode(array_values(array_map(fn($c, $cid) => ['id' => $cid, 'n' => $c['name'], 'f' => (float)$c['fee'], 'k' => (int)$c['class_id']], array_filter($__L['courses'], fn($c) => $c['status'] === 'active'), array_keys(array_filter($__L['courses'], fn($c) => $c['status'] === 'active')))), JSON_UNESCAPED_UNICODE) ?>;
  var B = <?= json_encode(array_values(array_map(fn($b, $bid) => ['id' => $bid, 'c' => (int)$b['course_id'], 'n' => $b['name'] . ($b['timing'] ? ' · ' . $b['timing'] : '') . ($b['session_name'] ? ' (' . $b['session_name'] . ')' : ''), 'a' => $b['status'] === 'active'], $__L['batches'], array_keys($__L['batches']))), JSON_UNESCAPED_UNICODE) ?>;
  function openM(m) { m.hidden = false; (window.ciLock ? ciLock(true) : null); }
  function closeM(m) { m.hidden = true; (window.ciLock ? ciLock(false) : null); }
  document.querySelectorAll('#ci-lc, #ci-la').forEach(function (m) { m.addEventListener('click', function (e) { if (e.target === m || e.target.closest('[data-close]')) closeM(m); }); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !document.querySelector('.dk-pop')) document.querySelectorAll('#ci-lc, #ci-la').forEach(function (m) { if (!m.hidden) closeM(m); }); });
  function student(id) { return fetch('/admin/ci-api.php?a=student&id=' + id, { credentials: 'same-origin' }).then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); }); }
  function onePay(f, v) { f.querySelectorAll('.ci-pay-row').forEach(function (r, k) { if (k) r.remove(); }); var a = f.querySelector('.ci-pay-a'); if (a) { a.value = v; f.querySelector('[data-pays]').dispatchEvent(new Event('input', { bubbles: true })); } }
  window.ciToast = function (msg, ok) { var t = document.getElementById('ci-toast'); t.textContent = msg; t.className = 'ci-toast' + (ok === false ? ' is-err' : ''); t.hidden = false; clearTimeout(t._h); t._h = setTimeout(function () { t.hidden = true; }, 3500); };

  // ---- Collect fee
  var lc = document.getElementById('ci-lc');
  if (lc) {
    var lf = document.getElementById('ci-lc-f'), sel = document.getElementById('ci-lc-for'), chips = document.getElementById('ci-lc-chips'), pay = [];
    function fill() {
      var p = pay[sel.selectedIndex]; if (!p) return;
      chips.innerHTML = '<button type="button" class="ci-chip" data-v="' + p.due + '">Full due · ' + inr(p.due) + '</button>';
      onePay(lf, p.inst ? '' : p.due);
      lf.querySelector('[name=note]').value = p.inst ? 'Installment ' + p.n : '';
    }
    sel.addEventListener('change', fill);
    chips.addEventListener('click', function (e) { var b = e.target.closest('.ci-chip'); if (b) onePay(lf, b.dataset.v); });
    document.addEventListener('click', function (e) {
      var b = e.target.closest && e.target.closest('[data-collect-sid]'); if (!b) return;
      e.preventDefault();
      student(b.dataset.collectSid).then(function (d) {
        if (!d.payable.length) { ciToast(d.student.name + ' has no fee due.'); return; }
        pay = d.payable; lf.querySelector('[name=id]').value = d.student.id;
        document.getElementById('ci-lc-h').textContent = 'Collect fee — ' + d.student.name;
        document.getElementById('ci-lc-who').textContent = d.student.reg + (d.student.class ? ' · Class ' + d.student.class : '');
        sel.innerHTML = ''; pay.forEach(function (p) { var o = document.createElement('option'); o.value = p.id; o.textContent = p.label; sel.appendChild(o); });
        if (b.dataset.eid) sel.value = b.dataset.eid;
        fill(); openM(lc);
        setTimeout(function () { var a = lf.querySelector('.ci-pay-a'); a.focus(); a.select(); }, 40);
      }).catch(function (err) { ciToast('Could not open: ' + err.message, false); });
    });
    lf.addEventListener('submit', function (e) { var t = 0; lf.querySelectorAll('.ci-pay-a').forEach(function (i) { t += parseFloat(i.value) || 0; }); var p = pay[sel.selectedIndex]; if (!t) { e.preventDefault(); ciToast('Enter the amount received.', false); } else if (p && t > p.due + 0.004) { e.preventDefault(); ciToast('That is more than the due (' + inr(p.due) + ').', false); } });
  }

  // ---- Add to course / batch
  var la = document.getElementById('ci-la');
  if (la) {
    var af = document.getElementById('ci-la-f'), course = document.getElementById('la-course'), batch = document.getElementById('la-batch'), type = document.getElementById('la-type'), fin = document.getElementById('la-final'), sum = document.getElementById('la-sum');
    var fee = function () { var o = course.options[course.selectedIndex]; return o && o.value ? +o.dataset.fee : null; };
    function paid() { var t = 0; af.querySelectorAll('.ci-pay-a').forEach(function (i) { t += parseFloat(i.value) || 0; }); return t; }
    function show() {
      var l = fee(), v = parseFloat(fin.value);
      document.getElementById('la-due-f').hidden = type.value !== 'installment';
      if (l === null || isNaN(v)) { sum.innerHTML = ''; return; }
      var p = paid(), d = Math.max(0, v - p);
      sum.innerHTML = '<span>Course fee <b>' + inr(l) + '</b></span>' + (v < l ? '<span>Discount <b>' + inr(l - v) + '</b></span>' : '') + '<span>Fee <b>' + inr(v) + '</b></span><span>Paid now <b class="ci-g">' + inr(p) + '</b></span><span>Due <b class="' + (d > 0 ? 'ci-r' : 'ci-g') + '">' + inr(d) + '</b></span>';
    }
    function courses(classId, className) {
      course.innerHTML = '<option value=""></option>';
      var mine = C.filter(function (c) { return classId && c.k === classId; }), rest = C.filter(function (c) { return !(classId && c.k === classId); });
      var add = function (parent, c) { var o = document.createElement('option'); o.value = c.id; o.dataset.fee = c.f; o.textContent = c.n + ' — ' + inr(c.f); parent.appendChild(o); };
      if (mine.length) { var g1 = document.createElement('optgroup'), g2 = document.createElement('optgroup'); g1.label = 'Class ' + className + ' courses'; g2.label = 'Other courses'; mine.forEach(function (c) { add(g1, c); }); rest.forEach(function (c) { add(g2, c); }); course.appendChild(g1); if (rest.length) course.appendChild(g2); }
      else rest.forEach(function (c) { add(course, c); });
    }
    course.addEventListener('change', function () {
      var c = +course.value, n = 0;
      batch.innerHTML = '<option value="">' + (c ? 'Choose batch…' : 'Choose the course first') + '</option>';
      B.forEach(function (b) { if (b.c === c && b.a) { var o = document.createElement('option'); o.value = b.id; o.textContent = b.n; batch.appendChild(o); n++; } });
      if (c && !n) batch.options[0].textContent = 'No batch for this course';
      var l = fee(); fin.value = l === null ? '' : l; onePay(af, type.value === 'full' && l !== null ? l : ''); show();
    });
    type.addEventListener('change', function () { onePay(af, type.value === 'full' ? fin.value : ''); show(); });
    fin.addEventListener('input', function () { if (type.value === 'full') onePay(af, fin.value); show(); });
    af.addEventListener('input', function (e) { if (e.target.closest('[data-pays]')) show(); });
    af.addEventListener('ci-pays', show);
    af.addEventListener('submit', function (e) { if (!course.value) { e.preventDefault(); ciToast('Choose a course.', false); } });
    document.addEventListener('click', function (e) {
      var b = e.target.closest && e.target.closest('[data-addc-sid]'); if (!b) return;
      e.preventDefault();
      student(b.dataset.addcSid).then(function (d) {
        af.querySelector('[name=id]').value = d.student.id;
        document.getElementById('ci-la-h').textContent = 'Add ' + d.student.name + ' to a course / batch';
        document.getElementById('ci-la-who').textContent = d.student.reg + (d.student.class ? ' · Class ' + d.student.class + ' — its courses come first' : '');
        courses(d.student.class_id, d.student.class); type.value = 'full'; fin.value = ''; batch.innerHTML = '<option value="">Choose the course first</option>'; onePay(af, ''); show();
        openM(la);
      }).catch(function (err) { ciToast('Could not open: ' + err.message, false); });
    });
  }
})();
</script>
