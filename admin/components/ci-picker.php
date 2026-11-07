<?php
// "Add student to this batch": first asks Existing or New student. Existing → a finder (search name / mobile / email / reg. no.,
// filter by class, course, batch) whose Add button opens the "Add to a course / batch" popup with this course + batch chosen.
// New → the Add Student page with the batch chosen. Buttons: data-addto-batch="ID" data-addto-course="ID" data-addto-name="…".
// Include after components/ci-modals.php (needs $pdo).
if (!function_exists('ci_lists')) require_once INCLUDES_PATH . '/institute.php';
$__P = ci_lists($pdo);
?>
<div class="ci-modal" id="ci-addto" data-mform hidden><div class="ci-modal-box" role="dialog" aria-modal="true" aria-labelledby="ci-addto-h">
  <div class="ci-card-h"><h3 id="ci-addto-h">Add a student</h3><button type="button" class="ci-x" data-close aria-label="Close"><i class="fas fa-times"></i></button></div>
  <div class="ci-card-b">
    <p class="ci-muted" id="ci-addto-to" style="margin:0 0 14px"></p>
    <div class="ci-choice">
      <button type="button" class="ci-choice-b" id="ci-addto-old"><span class="ci-choice-i"><i class="fas fa-user-check"></i></span><b>Existing student</b><small>Find a student already in the institute and add them</small></button>
      <a class="ci-choice-b" id="ci-addto-new" href="/admin/student-edit.php"><span class="ci-choice-i"><i class="fas fa-user-plus"></i></span><b>New student</b><small>Fill the admission form for a new student</small></a>
    </div>
  </div>
</div></div>

<div class="ci-modal" id="ci-pick" data-mform hidden><div class="ci-modal-box ci-modal-wide ci-pick-box" role="dialog" aria-modal="true" aria-labelledby="ci-pick-h">
  <div class="ci-card-h"><h3 id="ci-pick-h">Find a student</h3><button type="button" class="ci-x" data-close aria-label="Close"><i class="fas fa-times"></i></button></div>
  <div class="ci-card-b">
    <p class="ci-muted" id="ci-pick-to" style="margin:0 0 12px"></p>
    <form class="ci-pick-f" onsubmit="return false">
      <div class="ci-f ci-pick-q"><label>Search</label><input name="q" placeholder="Name, mobile, email, reg. no. or father's name" autocomplete="off"></div>
      <div class="ci-f"><label>Class</label><select name="class"><option value="0">All classes</option><?php foreach ($__P['classes'] as $k => $n): ?><option value="<?= (int)$k ?>"><?= ci_e($n) ?></option><?php endforeach; ?></select></div>
      <div class="ci-f"><label>Course</label><select name="course"><option value="0">All courses</option><?php foreach ($__P['courses'] as $k => $c): ?><option value="<?= (int)$k ?>"><?= ci_e($c['name']) ?></option><?php endforeach; ?></select></div>
      <div class="ci-f"><label>Batch</label><select name="batch"><option value="0">All batches</option><?php foreach ($__P['batches'] as $k => $b): ?><option value="<?= (int)$k ?>"><?= ci_e($b['name'] . ($b['session_name'] ? ' (' . $b['session_name'] . ')' : '')) ?></option><?php endforeach; ?></select></div>
    </form>
    <div class="ci-pick-list" id="ci-pick-list" aria-live="polite"></div>
  </div>
</div></div>
<script>
(function () {
  var target = { batch: 0, course: 0, name: '' };
  var list = document.getElementById('ci-pick-list'), f = document.querySelector('#ci-pick .ci-pick-f'), t = 0, seq = 0;
  var esc = function (s) { return String(s || '').replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-addto-batch]'); if (!b) return;
    e.preventDefault();
    target = { batch: +b.dataset.addtoBatch, course: +b.dataset.addtoCourse, name: b.dataset.addtoName || '' };
    document.getElementById('ci-addto-h').textContent = 'Add a student to ' + target.name;
    document.getElementById('ci-addto-to').textContent = 'Is the student already in the institute, or new?';
    document.getElementById('ci-addto-new').href = '/admin/student-edit.php?batch=' + target.batch;
    ciModal.open('#ci-addto');
  });
  document.getElementById('ci-addto-old').addEventListener('click', function () {
    ciModal.close('#ci-addto');
    document.getElementById('ci-pick-h').textContent = 'Find a student to add to ' + target.name;
    document.getElementById('ci-pick-to').textContent = 'Search or filter, then press Add — the fee form opens next.';
    f.reset(); [].forEach.call(f.querySelectorAll('select'), function (s) { s.dispatchEvent(new Event('change', { bubbles: false })); });
    ciModal.open('#ci-pick'); load();
  });
  function load() {
    var my = ++seq, p = new URLSearchParams(new FormData(f)); p.set('a', 'search'); p.set('in', target.batch);
    list.innerHTML = '<p class="ci-muted ci-pick-msg">Searching…</p>';
    fetch('/admin/ci-api.php?' + p.toString(), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      if (my !== seq) return;
      if (!d.list || !d.list.length) { list.innerHTML = '<p class="ci-muted ci-pick-msg">No student found. Try another name or number — or add a <a href="/admin/student-edit.php?batch=' + target.batch + '">new student</a>.</p>'; return; }
      list.innerHTML = '<table class="ci-table" data-sheet="off"><thead><tr><th>Student</th><th>Mobile / email</th><th>Class</th><th>Batches now</th><th></th></tr></thead><tbody>' + d.list.map(function (s) {
        return '<tr><td><b>' + esc(s.name) + '</b><small class="ci-muted" style="display:block">' + esc(s.reg) + (s.father ? ' · ' + esc(s.father) : '') + '</small></td><td>' + esc(s.mobile) + (s.email ? '<small class="ci-muted" style="display:block">' + esc(s.email) + '</small>' : '') + '</td><td>' + esc(s.class || '—') + '</td><td class="ci-muted">' + (s.batches.length ? s.batches.map(esc).join('<br>') : '—') + '</td><td>'
          + (s.already ? '<span class="ci-badge ci-b-green">In this batch</span>' : '<button type="button" class="ci-btn ci-sm" data-addc-sid="' + s.id + '" data-addc-course="' + target.course + '" data-addc-batch="' + target.batch + '">Add</button>') + '</td></tr>';
      }).join('') + '</tbody></table>' + (d.more ? '<p class="ci-muted ci-pick-msg">Showing the first 60 — type more to narrow down.</p>' : '');
    }).catch(function () { list.innerHTML = '<p class="ci-muted ci-pick-msg">Could not search. Please try again.</p>'; });
  }
  f.addEventListener('input', function () { clearTimeout(t); t = setTimeout(load, 250); });
  f.addEventListener('change', function () { clearTimeout(t); t = setTimeout(load, 50); });
  list.addEventListener('click', function (e) { if (e.target.closest('[data-addc-sid]')) ciModal.close('#ci-pick'); });
})();
</script>
