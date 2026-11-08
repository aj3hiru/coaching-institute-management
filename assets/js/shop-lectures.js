/* Online Store → Lectures (admin/shop-lectures.php): the sheet of one course's lectures.
   Select rows (Shift+click selects a range) → Make chapter / Move to chapter / Unit / Free / Remove; edit names, chapter,
   unit and Free right in the rows; drag the ⠿ handle to change the order; Save sends the whole sheet in its order.
   The Notes · Quiz · VVI buttons open the lecture editor in a popup (the sheet is saved first). */
(function () {
  'use strict';
  var C = window.LS; if (!C) return;
  var COLORS = ['#7c3aed', '#0891b2', '#ea580c', '#16a34a', '#db2777', '#2563eb', '#ca8a04', '#dc2626', '#0d9488', '#9333ea'];
  var body = document.getElementById('lsBody'), dirty = false, last = null;
  function $(id) { return document.getElementById(id); }
  function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : t; return d.innerHTML; }
  function post(data) {
    var fd = new FormData(); fd.append('_csrf', C.csrf); fd.append('ajax', '1'); fd.append('pid', C.pid);
    Object.keys(data).forEach(function (k) { if (Array.isArray(data[k])) data[k].forEach(function (v) { fd.append(k + '[]', v); }); else fd.append(k, data[k]); });
    return fetch('/admin/shop-lectures.php?course=' + C.pid, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); })
      .then(function (d) { if (!d.ok) throw new Error(d.error || 'Could not save.'); return d; });
  }
  function fail(e) { alert(e.message || e); }
  function color(sid) { var i = C.secs.findIndex(function (s) { return s.id === sid; }); return i < 0 ? 'transparent' : COLORS[i % COLORS.length]; }
  function secOpts(sel) { return '<option value="0">— No chapter —</option>' + C.secs.map(function (s) { return '<option value="' + s.id + '"' + (s.id === sel ? ' selected' : '') + '>' + esc(s.title) + '</option>'; }).join(''); }
  function unitOpts(sid, sel) { var u = C.units[sid] || []; return '<option value="0">' + (sid ? (u.length ? '— No unit —' : '— no units —') : '—') + '</option>' + u.map(function (x) { return '<option value="' + x.id + '"' + (x.id === sel ? ' selected' : '') + '>' + esc(x.title) + '</option>'; }).join(''); }
  function setDirty(v) { dirty = v; $('lsDirty').hidden = !v; if ($('lsDirty2')) $('lsDirty2').hidden = !v; }
  window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

  // ---- rows ----
  function rowHtml(r) {
    var x = function (hash, icon, name, n) { return '<a data-embed class="' + (n ? 'has' : '') + '" title="' + name + (n ? ' (' + n + ')' : ' — add') + '" href="/admin/shop-lecture.php?id=' + r.id + '&embed=1#' + hash + '"><i class="fas ' + icon + '"></i> ' + (n ? n : '+') + '</a>'; };
    return '<td class="cb"><input type="checkbox" class="pick" aria-label="Select"></td><td class="no"></td>'
      + '<td><div class="vid"><span class="hd" draggable="true" title="Drag to change the order"><i class="fas fa-grip-vertical"></i></span>' + (r.thumb ? '<img src="' + esc(r.thumb) + '" alt="" loading="lazy">' : '<span class="ph"><i class="fas ' + (r.pdf ? 'fa-file-pdf' : 'fa-film') + '"></i></span>')
      + '<div class="tw"><input class="t" maxlength="200" value="' + esc(r.title) + '" aria-label="Lecture name" title="' + esc(r.title) + '"><small>' + esc(r.dur || '') + (r.yt ? (r.dur ? ' · ' : '') + 'YouTube' : '') + '</small></div></div></td>'
      + '<td class="cs"><select class="s" aria-label="Chapter">' + secOpts(r.section_id) + '</select></td>'
      + '<td class="us"><select class="u" aria-label="Unit">' + unitOpts(r.section_id, r.unit_id) + '</select></td>'
      + '<td class="fr"><input type="checkbox" class="f" aria-label="Free"' + (r.free ? ' checked' : '') + '></td>'
      + '<td><div class="ls-x3">' + x('notes', 'fa-file-pdf', 'Notes PDF', r.n) + x('quiz', 'fa-list-check', 'Quiz / MCQ', r.q) + x('vvi', 'fa-circle-question', 'VVI questions', r.v) + '</div></td>'
      + '<td><a class="ci-ico" data-embed href="/admin/shop-lecture.php?id=' + r.id + '&embed=1" aria-label="Edit lecture"><i class="fas fa-pen"></i></a></td>';
  }
  function draw() {
    body.innerHTML = '';
    C.rows.forEach(function (r) { var tr = document.createElement('tr'); tr.dataset.id = r.id; tr.innerHTML = rowHtml(r); body.appendChild(tr); });
    $('lsEmpty').hidden = !!C.rows.length; $('lsSheet').querySelector('.ls-wrap').hidden = !C.rows.length;
    paint();
  }
  function trs() { return [].slice.call(body.children); }
  function paint() { // numbers per chapter, chapter colour, filters, chapter counts, toolbar
    var no = {}, q = $('lsQ').value.trim().toLowerCase(), f = $('lsF').value, cnt = {};
    trs().forEach(function (tr) {
      var s = +tr.querySelector('.s').value; no[s] = (no[s] || 0) + 1; cnt[s] = (cnt[s] || 0) + 1;
      tr.querySelector('.no').textContent = no[s];
      tr.querySelector('td.cb').style.borderLeftColor = color(s); tr.style.setProperty('--lc', color(s));
      tr.hidden = (f !== 'all' && +f !== s) || (q && tr.querySelector('.t').value.toLowerCase().indexOf(q) < 0);
    });
    document.querySelectorAll('.ls-ch').forEach(function (c) { var n = cnt[+c.dataset.id] || 0; c.querySelector('.cnt').textContent = n + ' lecture' + (n === 1 ? '' : 's'); });
    var sel = picked().length; $('lsSel').textContent = sel + ' selected';
    document.querySelectorAll('[data-needsel]').forEach(function (b) { b.disabled = !sel; });
    var us = {}; picked().forEach(function (tr) { us[tr.querySelector('.s').value] = 1; }); var one = Object.keys(us).length === 1 ? +Object.keys(us)[0] : 0;
    $('lsToU').innerHTML = '<option value="">' + (one ? 'Put in unit…' : 'Unit (select one chapter)') + '</option>' + (one ? '<option value="0">— No unit —</option>' + (C.units[one] || []).map(function (u) { return '<option value="' + u.id + '">' + esc(u.title) + '</option>'; }).join('') : '');
    $('lsToU').disabled = !one;
  }
  function picked() { return trs().filter(function (tr) { return tr.querySelector('.pick').checked; }); }
  function refreshSelects() { // after chapters / units change
    trs().forEach(function (tr) { var s = tr.querySelector('.s'), v = +s.value, u = tr.querySelector('.u'), uv = +u.value; if (!C.secs.some(function (x) { return x.id === v; })) v = 0; s.innerHTML = secOpts(v); u.innerHTML = unitOpts(v, uv); });
    $('lsTo').innerHTML = '<option value="">Move to chapter…</option>' + secOpts(-1).replace('<option value="0">', '<option value="0">');
    var fv = $('lsF').value; $('lsF').innerHTML = '<option value="all">All chapters</option><option value="0">No chapter</option>' + C.secs.map(function (s) { return '<option value="' + s.id + '">' + esc(s.title) + '</option>'; }).join(''); $('lsF').value = [].some.call($('lsF').options, function (o) { return o.value === fv; }) ? fv : 'all';
    var nv = $('lsNew'); if (nv) { var keep = nv.value; nv.innerHTML = secOpts(-1); nv.value = [].some.call(nv.options, function (o) { return o.value === keep; }) ? keep : '0'; }
    drawChapters(); paint();
  }

  body.addEventListener('click', function (e) {
    var cb = e.target.closest('.pick'); if (!cb) return;
    var tr = cb.closest('tr');
    if (e.shiftKey && last && last !== tr) { var a = trs().filter(function (t) { return !t.hidden; }), i = a.indexOf(last), j = a.indexOf(tr); if (i > -1 && j > -1) a.slice(Math.min(i, j), Math.max(i, j) + 1).forEach(function (t) { t.querySelector('.pick').checked = cb.checked; t.classList.toggle('on', cb.checked); }); }
    tr.classList.toggle('on', cb.checked); last = tr; paint();
  });
  body.addEventListener('change', function (e) {
    var tr = e.target.closest('tr'); if (!tr) return;
    if (e.target.classList.contains('s')) tr.querySelector('.u').innerHTML = unitOpts(+e.target.value, 0);
    if (!e.target.classList.contains('pick')) { setDirty(true); paint(); }
  });
  body.addEventListener('input', function (e) { if (e.target.classList.contains('t')) setDirty(true); });
  $('lsAll').addEventListener('change', function () { var on = this.checked; trs().forEach(function (t) { if (!t.hidden) { t.querySelector('.pick').checked = on; t.classList.toggle('on', on); } }); paint(); });
  $('lsQ').addEventListener('input', paint); $('lsF').addEventListener('change', paint);

  // drag to reorder (by the handle)
  var dragTr = null;
  body.addEventListener('dragstart', function (e) { var h = e.target.closest('.hd'); if (!h) return; dragTr = h.closest('tr'); dragTr.classList.add('drag'); e.dataTransfer.effectAllowed = 'move'; try { e.dataTransfer.setData('text/plain', dragTr.dataset.id); e.dataTransfer.setDragImage(dragTr, 20, 20); } catch (x) {} });
  body.addEventListener('dragover', function (e) { if (!dragTr) return; e.preventDefault(); var tr = e.target.closest('tr'); trs().forEach(function (t) { t.classList.toggle('over', t === tr && t !== dragTr); }); });
  body.addEventListener('drop', function (e) { if (!dragTr) return; e.preventDefault(); var tr = e.target.closest('tr'); if (tr && tr !== dragTr) { body.insertBefore(dragTr, tr); setDirty(true); } });
  body.addEventListener('dragend', function () { if (dragTr) dragTr.classList.remove('drag'); trs().forEach(function (t) { t.classList.remove('over'); }); dragTr = null; paint(); });

  // ---- toolbar ----
  function setSec(sid) { picked().forEach(function (tr) { tr.querySelector('.s').value = String(sid); tr.querySelector('.u').innerHTML = unitOpts(sid, 0); }); setDirty(true); paint(); }
  $('lsTo').addEventListener('change', function () { if (this.value === '') return; setSec(+this.value); this.value = ''; });
  $('lsToU').addEventListener('change', function () { var v = this.value; if (v === '') return; picked().forEach(function (tr) { tr.querySelector('.u').value = v; }); setDirty(true); this.value = ''; paint(); });
  $('lsFrOn').addEventListener('click', function () { picked().forEach(function (tr) { tr.querySelector('.f').checked = true; }); setDirty(true); });
  $('lsFrOff').addEventListener('click', function () { picked().forEach(function (tr) { tr.querySelector('.f').checked = false; }); setDirty(true); });
  $('lsMk').addEventListener('click', function () {
    var n = picked().length; $('lsMkHint').textContent = n + ' selected lecture' + (n === 1 ? '' : 's') + ' go into this chapter.';
    var f = $('lsMkForm'); f.title.value = ''; window.ciModal.open('#lsMkF'); setTimeout(function () { f.title.focus(); }, 50);
  });
  $('lsMkForm').addEventListener('submit', function (e) {
    e.preventDefault(); var t = this.title.value.trim(); if (!t) return;
    post({ action: 'ch_add', title: t }).then(function (d) { C.secs.push({ id: d.id, title: d.title }); refreshSelects(); setSec(d.id); window.ciModal.close('#lsMkF'); return save(true); }).catch(fail);
  });
  $('lsRm').addEventListener('click', function () {
    var ids = picked().map(function (tr) { return +tr.dataset.id; }); if (!ids.length) return;
    if (!confirm('Remove ' + ids.length + ' lecture' + (ids.length === 1 ? '' : 's') + ' from this course? Their notes, MCQs and VVI go too. Videos of the playlist will not come back by themselves.')) return;
    post({ action: 'remove', ids: ids }).then(function () { C.rows = C.rows.filter(function (r) { return ids.indexOf(r.id) < 0; }); trs().forEach(function (tr) { if (ids.indexOf(+tr.dataset.id) > -1) tr.remove(); }); $('lsEmpty').hidden = !!trs().length; paint(); }).catch(fail);
  });

  // ---- save ----
  function save(quiet) {
    var rows = trs().map(function (tr) { return { id: +tr.dataset.id, title: tr.querySelector('.t').value, section_id: +tr.querySelector('.s').value, unit_id: +tr.querySelector('.u').value, free: tr.querySelector('.f').checked ? 1 : 0 }; });
    var b = $('lsSave'); b.disabled = true;
    return post({ action: 'save', rows: JSON.stringify(rows) }).then(function () { setDirty(false); b.disabled = false; if (!quiet) { b.innerHTML = '<i class="fas fa-check"></i> Saved'; setTimeout(function () { b.innerHTML = '<i class="fas fa-check"></i> Save'; }, 1500); } })
      .catch(function (e) { b.disabled = false; fail(e); throw e; });
  }
  $('lsSave').addEventListener('click', function () { save(false); });
  if ($('lsPublish')) $('lsPublish').addEventListener('click', function () { // Publish box: status + the sheet
    var b = this; b.disabled = true;
    (dirty ? save(true) : Promise.resolve()).then(function () { return post({ action: 'status', status: $('lsStatus').value }); })
      .then(function () { b.innerHTML = '<i class="fas fa-check"></i> Saved'; setTimeout(function () { b.innerHTML = '<i class="fas fa-check"></i> Save'; }, 1500); }).catch(fail).then(function () { b.disabled = false; });
  });
  document.addEventListener('keydown', function (e) { if ((e.ctrlKey || e.metaKey) && e.key === 's') { e.preventDefault(); save(false); } });
  window.lcBeforeOpen = function () { return dirty ? save(true) : null; }; // the popup opens on saved data
  window.onLectureSaved = function () { setDirty(false); location.reload(); };

  // ---- chapters & units ----
  function drawChapters() {
    var box = $('lsChList');
    box.innerHTML = C.secs.length ? '' : '<p class="ci-muted" style="margin:0">No chapter yet. Select videos in the sheet below and press <b>Make chapter</b>.</p>';
    C.secs.forEach(function (s, i) {
      var d = document.createElement('div'); d.className = 'ls-ch'; d.dataset.id = s.id;
      d.innerHTML = '<span class="bar" style="background:' + color(s.id) + '"></span><input class="nm" maxlength="200" value="' + esc(s.title) + '" aria-label="Chapter name"><span class="cnt"></span>'
        + '<div class="ci-icos"><button type="button" class="ci-ico" data-mv="-1" aria-label="Move up"' + (i ? '' : ' disabled') + '><i class="fas fa-arrow-up"></i></button><button type="button" class="ci-ico" data-mv="1" aria-label="Move down"' + (i < C.secs.length - 1 ? '' : ' disabled') + '><i class="fas fa-arrow-down"></i></button><button type="button" class="ci-ico" data-del aria-label="Remove chapter" data-i="trash"><i class="fas fa-trash"></i></button></div>'
        + '<div class="ls-units"><b style="font-weight:600;color:#6b7280">Units:</b>' + (C.units[s.id] || []).map(function (u) { return '<span>' + esc(u.title) + '<button type="button" data-udel="' + u.id + '" aria-label="Remove unit">×</button></span>'; }).join('') + '<button type="button" class="add" data-uadd>+ Unit</button></div>';
      box.appendChild(d);
    });
  }
  $('lsChList').addEventListener('change', function (e) {
    if (!e.target.classList.contains('nm')) return; var c = e.target.closest('.ls-ch'), id = +c.dataset.id, t = e.target.value.trim();
    if (!t) { e.target.value = C.secs.find(function (s) { return s.id === id; }).title; return; }
    post({ action: 'ch_rename', id: id, title: t }).then(function (d) { C.secs.forEach(function (s) { if (s.id === id) s.title = d.title; }); var keep = dirty; refreshSelects(); setDirty(keep); }).catch(fail);
  });
  $('lsChList').addEventListener('click', function (e) {
    var c = e.target.closest('.ls-ch'); if (!c) return; var id = +c.dataset.id, b;
    if ((b = e.target.closest('[data-mv]'))) {
      var dir = +b.dataset.mv, i = C.secs.findIndex(function (s) { return s.id === id; }), j = i + dir; if (j < 0 || j >= C.secs.length) return;
      post({ action: 'ch_move', id: id, dir: dir }).then(function () { var t = C.secs[i]; C.secs[i] = C.secs[j]; C.secs[j] = t; var keep = dirty; refreshSelects(); setDirty(keep); }).catch(fail);
    } else if (e.target.closest('[data-del]')) {
      if (!confirm('Remove this chapter? Its lectures stay in the course, without a chapter.')) return;
      var go = function () { return post({ action: 'ch_del', id: id }).then(function () { C.secs = C.secs.filter(function (s) { return s.id !== id; }); delete C.units[id]; refreshSelects(); }); };
      (dirty ? save(true).then(go) : go()).catch(fail);
    } else if (e.target.closest('[data-uadd]')) {
      var t = prompt('Unit name (inside "' + C.secs.find(function (s) { return s.id === id; }).title + '")'); if (!t || !t.trim()) return;
      post({ action: 'unit_add', section_id: id, title: t.trim() }).then(function (d) { (C.units[id] = C.units[id] || []).push({ id: d.id, title: d.title }); var keep = dirty; refreshSelects(); setDirty(keep); }).catch(fail);
    } else if ((b = e.target.closest('[data-udel]'))) {
      var uid = +b.dataset.udel; if (!confirm('Remove this unit? Its lectures stay in the chapter.')) return;
      var go2 = function () { return post({ action: 'unit_del', id: uid }).then(function () { C.units[id] = (C.units[id] || []).filter(function (u) { return u.id !== uid; }); refreshSelects(); }); };
      (dirty ? save(true).then(go2) : go2()).catch(fail);
    }
  });
  $('lsChAdd').addEventListener('click', function () {
    var i = $('lsChName'), t = i.value.trim(); if (!t) { i.focus(); return; }
    post({ action: 'ch_add', title: t }).then(function (d) { C.secs.push({ id: d.id, title: d.title }); i.value = ''; var keep = dirty; refreshSelects(); setDirty(keep); }).catch(fail);
  });
  $('lsChName').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); $('lsChAdd').click(); } });

  // ---- playlist ----
  var msg = $('lsMsg');
  function say(t, ok) { msg.hidden = false; msg.className = 'ls-msg ' + (ok ? 'ok' : 'err'); msg.innerHTML = t; }
  function busy(b, t) { b.disabled = true; b.dataset.h = b.innerHTML; b.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + t; }
  function done(d) { say('✓ ' + (d.added ? d.added + ' new video' + (d.added === 1 ? '' : 's') + ' added as lectures. Loading the sheet…' : 'No new videos — the sheet is up to date.'), true); if (d.added) { setDirty(false); setTimeout(function () { location.reload(); }, 600); } }
  $('lsLink').addEventListener('click', function () {
    var u = $('lsUrl').value.trim(), b = this; if (!u) { $('lsUrl').focus(); return; }
    busy(b, 'Scanning the playlist…'); say('Reading the playlist from YouTube — big playlists take a minute…', true);
    (dirty ? save(true) : Promise.resolve()).then(function () { return post({ action: 'link', url: u }); })
      .then(function (d) { d.added = d.added || 0; if (!d.added) { setDirty(false); setTimeout(function () { location.reload(); }, 600); } done(d); })
      .catch(function (e) { say('✗ ' + esc(e.message), false); }).then(function () { b.disabled = false; b.innerHTML = b.dataset.h; });
  });
  $('lsUrl').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); $('lsLink').click(); } });
  if ($('lsScan')) $('lsScan').addEventListener('click', function () { var b = this; busy(b, 'Scanning…'); (dirty ? save(true) : Promise.resolve()).then(function () { return post({ action: 'scan' }); }).then(done).catch(function (e) { say('✗ ' + esc(e.message), false); }).then(function () { b.disabled = false; b.innerHTML = b.dataset.h; }); });
  if ($('lsUnlink')) $('lsUnlink').addEventListener('click', function () { if (!confirm('Unlink the playlist? The lectures stay; new videos will not come in any more.')) return; post({ action: 'unlink' }).then(function () { setDirty(false); location.reload(); }).catch(fail); });
  if ($('lsUnskip')) $('lsUnskip').addEventListener('click', function (e) { e.preventDefault(); post({ action: 'unskip' }).then(function () { return post({ action: 'scan' }); }).then(done).catch(fail); });
  if ($('lsNew')) $('lsNew').addEventListener('change', function () { post({ action: 'newvids', section_id: this.value }).catch(fail); });

  draw(); refreshSelects(); setDirty(false);
})();
