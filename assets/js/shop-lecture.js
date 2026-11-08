/* Add / edit lecture (admin/shop-lecture.php): video / PDF switch, chapter → lecture no. + units, auto name,
   video link fetch (name, picture, length), repeatable rows (VVI, quiz), save then upload the chosen PDFs in pieces. */
(function () {
  'use strict';
  var C = window.SHC || {}, f = document.getElementById('slcForm');
  var course = document.getElementById('slcCourse');
  if (course) course.addEventListener('change', function () { if (course.value) location.href = '/admin/shop-lecture.php?course=' + course.value; });
  if (!f || !document.getElementById('slcTitle')) return;
  var $ = function (s) { return f.querySelector(s); }, $$ = function (s) { return [].slice.call(f.querySelectorAll(s)); };

  // --- what is shown -------------------------------------------------------
  function sync() {
    var pdf = $('[name=kind]:checked').value === 'pdf';
    $$('.slc-video').forEach(function (e) { e.hidden = pdf; }); $$('.slc-pdf').forEach(function (e) { e.hidden = !pdf; });
  }
  $$('[name=kind]').forEach(function (r) { r.addEventListener('change', sync); }); sync();

  // --- chapter / unit / lecture number / name ------------------------------
  var sec = $('#slcSec'), unit = $('#slcUnit'), no = $('#slcNo'), title = $('#slcTitle'), isNew = !+$('[name=id]').value;
  var secNew = $('#slcSecNew'), unitNew = $('#slcUnitNew');
  function opt(sel) { return sel.options[sel.selectedIndex]; }
  function chapterName() { return sec.value === '-1' ? (secNew.querySelector('input').value.trim() || 'New chapter') : opt(sec).textContent.trim(); }
  function unitName() { return unit.value === '-1' ? unitNew.querySelector('input').value.trim() : (+unit.value ? opt(unit).textContent.trim() : ''); }
  function autoName() {
    if (title.dataset.auto !== '1') return;
    var parts = [title.dataset.prefix, chapterName(), 'Lecture ' + (no.value || 1)].filter(Boolean);
    var u = unitName(); if (u) parts.push(u);
    title.value = parts.join(' – ');
  }
  function onSec() {
    secNew.hidden = sec.value !== '-1';
    [].forEach.call(unit.options, function (o) { var s = o.getAttribute('data-sec'); o.hidden = !(s === '*' || s === sec.value); });
    if (opt(unit).hidden) unit.value = '0';
    if (isNew) no.value = opt(sec).getAttribute('data-next') || 1;
    onUnit();
  }
  function onUnit() { unitNew.hidden = unit.value !== '-1'; autoName(); }
  sec.addEventListener('change', onSec); unit.addEventListener('change', onUnit);
  f.querySelectorAll('[data-newfor]').forEach(function (b) { b.addEventListener('click', function () {
    var sel = document.getElementById(b.getAttribute('data-newfor')); sel.value = '-1'; sel.dispatchEvent(new Event('change', { bubbles: true }));
    var box = sel === sec ? secNew : unitNew; box.hidden = false; box.querySelector('input').focus();
  }); }); no.addEventListener('input', autoName);
  secNew.querySelector('input').addEventListener('input', autoName); unitNew.querySelector('input').addEventListener('input', autoName);
  title.addEventListener('input', function () { title.dataset.auto = '0'; });
  onSec(); if (!title.value) { title.dataset.auto = '1'; autoName(); }

  // --- video link check ----------------------------------------------------
  var url = $('#slcUrl'), probe = $('#slcProbe'), dur = $('#slcDur'), last = '';
  function esc(s) { return String(s || '').replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function check(force) {
    var u = url.value.trim(); if (!u || (u === last && !force)) return; last = u;
    probe.className = 'slc-probe wait'; probe.textContent = 'Checking the link…';
    fetch('/admin/shop-lecture.php?probe=' + encodeURIComponent(u), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      if (!d.ok) { probe.className = 'slc-probe bad'; probe.innerHTML = '<span>✗ ' + esc(d.error || 'The link could not be checked.') + '</span>'; return; }
      var names = { youtube: 'YouTube video', vimeo: 'Vimeo video', drive: 'Google Drive video', file: 'Video file', embed: 'Video page' };
      probe.className = 'slc-probe ok';
      probe.innerHTML = (d.thumb ? '<img src="' + esc(d.thumb) + '" alt="">' : '') + '<div><b>✓ ' + esc(names[d.kind] || 'Video') + ' found — fetched successfully</b><small>' + esc(d.title) + (d.duration ? ' · ' + esc(d.duration) : '') + (d.size ? ' · ' + esc(d.size) : '') + (d.by ? ' · ' + esc(d.by) : '') + '</small>' + (d.note ? '<small style="display:block;color:#92400e">' + esc(d.note) + '</small>' : '') + '</div>';
      if (d.duration) dur.value = d.duration; // the length comes from the video itself
      if (d.title) { var ut = document.createElement('button'); ut.type = 'button'; ut.className = 'ci-btn ci-ghost ci-sm'; ut.style.marginLeft = 'auto'; ut.textContent = 'Use this title';
        ut.addEventListener('click', function () { title.value = d.title.slice(0, 200); title.dataset.auto = '0'; ut.textContent = '✓ Title set'; ut.disabled = true; }); probe.appendChild(ut); }
    }).catch(function () { probe.className = 'slc-probe bad'; probe.textContent = '✗ Could not check the link right now.'; });
  }
  $('#slcFetch').addEventListener('click', function () { check(true); });
  url.addEventListener('paste', function () { setTimeout(function () { check(true); }, 50); });
  url.addEventListener('change', function () { check(false); });

  // --- repeatable rows -----------------------------------------------------
  f.addEventListener('click', function (e) {
    var add = e.target.closest('[data-add]'), rm = e.target.closest('[data-rm]');
    if (add) {
      var box = document.getElementById(add.getAttribute('data-add')), row = box.lastElementChild.cloneNode(true);
      row.querySelectorAll('input:not([type=radio]), textarea').forEach(function (x) { x.value = ''; });
      if (add.hasAttribute('data-renum')) { // quiz rows: give the new MCQ its own index
        var n = box.children.length;
        row.querySelectorAll('[name]').forEach(function (x) { x.name = x.name.replace(/\[\d+\]/, '[' + n + ']'); if (x.type === 'radio') x.checked = x.value === '0'; });
      }
      box.appendChild(row); row.querySelector('input:not([type=radio]), textarea').focus();
    }
    if (rm) { var r = rm.closest('.slc-row'), b = r.parentNode; if (b.children.length > 1) r.remove(); else r.querySelectorAll('input:not([type=radio]), textarea').forEach(function (x) { x.value = ''; }); }
  });

  // --- notes PDFs: pick one or many, each with its own name ----------------
  var notesPick = $('#slcNotes'), notesList = $('#slcNoteList'), newNotes = [];
  $('#slcNotesAdd').addEventListener('click', function () { notesPick.click(); });
  notesPick.addEventListener('change', function () {
    [].forEach.call(notesPick.files, function (file) {
      if (!/\.pdf$/i.test(file.name)) { msg.textContent = file.name + ' is not a PDF.'; return; }
      var row = document.createElement('div'); row.className = 'slc-nrow';
      row.innerHTML = '<span class="slc-nico">PDF</span><input maxlength="200" aria-label="Notes name"><small>' + (file.size / 1048576).toFixed(1) + ' MB · new</small><button type="button" class="slc-nrm" style="border:0;background:none;cursor:pointer">Remove</button>';
      row.querySelector('input').value = file.name.replace(/\.pdf$/i, '').replace(/[_-]+/g, ' ');
      var nin = row.querySelector('input'); nin.addEventListener('focus', function once() { nin.removeEventListener('focus', once); setTimeout(function () { nin.select(); }, 0); });
      var item = { f: file, row: row }; newNotes.push(item);
      row.querySelector('button').addEventListener('click', function () { newNotes.splice(newNotes.indexOf(item), 1); row.remove(); });
      notesList.appendChild(row);
    });
    notesPick.value = '';
  });

  // --- save, then upload ---------------------------------------------------
  var CHUNK = 4 * 1024 * 1024, bar = $('#slcBar'), st = $('#slcSt'), msg = $('#slcMsg'), busy = 0;
  window.addEventListener('beforeunload', function (e) { if (busy) { e.preventDefault(); e.returnValue = ''; } });
  function rid() { return Array.prototype.map.call(crypto.getRandomValues(new Uint8Array(12)), function (b) { return ('0' + b.toString(16)).slice(-2); }).join(''); }
  function wait(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
  function upload(file, o) {
    var id = rid(), total = Math.max(1, Math.ceil(file.size / CHUNK)), i = 0;
    function send(idx, tries) {
      var h = { 'Content-Type': 'application/octet-stream', 'X-CSRF-Token': C.csrf, 'X-Upload': id, 'X-Index': String(idx), 'X-Total': String(total), 'X-Size': String(file.size), 'X-Name': encodeURIComponent(file.name), 'X-Kind': o.kind, 'X-Target': String(o.target) };
      if (o.quality) h['X-Quality'] = o.quality; if (o.duration) h['X-Duration'] = o.duration; if (o.height) h['X-Height'] = String(o.height); if (o.title) h['X-Title'] = encodeURIComponent(o.title);
      return fetch('/admin/shop-upload.php', { method: 'POST', headers: h, body: file.slice(idx * CHUNK, Math.min(file.size, (idx + 1) * CHUNK)), credentials: 'same-origin' })
        .then(function (r) { return r.json().catch(function () { return { error: 'Server error ' + r.status }; }).then(function (d) { d._s = r.status; return d; }); })
        .then(function (d) {
          if (d.ok) return d;
          if (d._s === 409 && typeof d.next === 'number') { i = d.next; return { retry: true }; }
          if (d._s >= 500 && d._s !== 507 && tries < 4) return wait(1500 * (tries + 1)).then(function () { return send(idx, tries + 1); });
          throw new Error(d.error || 'Upload failed');
        }, function () { if (tries < 6) return wait(2000 * (tries + 1)).then(function () { return send(idx, tries + 1); }); throw new Error('Network problem — the upload stopped. Please try again.'); });
    }
    function loop() { if (i >= total) return Promise.resolve(); return send(i, 0).then(function (d) { if (d.retry) return loop(); i++; if (o.progress) o.progress(i / total); return d.done ? d : loop(); }); }
    busy++; return loop().then(function (d) { busy--; return d; }, function (e) { busy--; throw e; });
  }
  function videoInfo(file) {
    return new Promise(function (ok) {
      var v = document.createElement('video'), u = URL.createObjectURL(file), done = false, fin = function (r) { if (done) return; done = true; URL.revokeObjectURL(u); ok(r); };
      v.preload = 'metadata'; v.muted = true;
      v.onloadedmetadata = function () { var s = Math.round(v.duration || 0), d = s > 0 && isFinite(s) ? (s >= 3600 ? Math.floor(s / 3600) + ':' + ('0' + Math.floor(s % 3600 / 60)).slice(-2) + ':' + ('0' + s % 60).slice(-2) : Math.floor(s / 60) + ':' + ('0' + s % 60).slice(-2)) : ''; fin({ duration: d, height: Math.min(v.videoWidth || 0, v.videoHeight || 0) || v.videoHeight || 0 }); };
      v.onerror = function () { fin({}); }; setTimeout(function () { fin({}); }, 8000); v.src = u;
    });
  }
  // --- one step after another: the whole form stays open, but a part works only when the required part before it is filled
  //     chapter → lecture name → video link (or the PDF) → everything else (notes, VVI, quiz, options, save)
  var pdfIn = $('#slcPdf'), keptVideo = !!f.querySelector('.slc-kept');
  function stepsOk() {
    var chapter = sec.value !== '-1' ? !!sec.value : !!secNew.querySelector('input').value.trim();
    var name = chapter && !!title.value.trim();
    var pdf = $('[name=kind]:checked').value === 'pdf';
    var media = name && (pdf ? !!(pdfIn && (pdfIn.files.length || pdfIn.dataset.has)) : (/^https?:\/\/\S+$/i.test(url.value.trim()) || keptVideo));
    return { 2: chapter, 3: name, 4: media, need4: pdf ? 'Choose the lecture PDF first.' : 'Paste the video link first.' };
  }
  function gate() {
    var ok = stepsOk();
    $$('[data-step]').forEach(function (p) {
      var on = !!ok[p.getAttribute('data-step')];
      p.classList.toggle('slc-locked', !on);
      if ('inert' in p) p.inert = !on;
      var n = p.querySelector(':scope > .slc-need'), why = p.getAttribute('data-step') === '4' ? ok.need4 : p.getAttribute('data-need');
      if (!on && !n) { n = document.createElement('span'); n.className = 'slc-need'; p.insertBefore(n, p.firstChild); }
      if (n) { n.textContent = '🔒 ' + why; n.hidden = on; }
    });
  }
  ['input', 'change'].forEach(function (ev) { f.addEventListener(ev, gate); });
  document.addEventListener('admin-select-change', gate);
  gate();
  $$('[data-next]').forEach(function (b) { if (b.tagName === 'BUTTON') b.addEventListener('click', function () { $('#slcNext').value = '1'; }); });
  f.addEventListener('submit', function (e) {
    var pdfKind = $('[name=kind]:checked').value === 'pdf';
    if (!pdfKind && !url.value.trim() && !f.querySelector('.slc-kept')) { e.preventDefault(); url.focus(); msg.textContent = 'Paste the video link.'; return; }
    if (sec.value === '-1' && !secNew.querySelector('input').value.trim()) { e.preventDefault(); secNew.querySelector('input').focus(); msg.textContent = 'Write the new chapter name.'; return; }
    if (unit.value === '-1' && !unitNew.querySelector('input').value.trim()) { e.preventDefault(); unitNew.querySelector('input').focus(); msg.textContent = 'Write the new unit name.'; return; }
    var jobs = [];
    var pf = $('#slcPdf'); // videos are links now: only PDFs are uploaded
    if (pdfKind && pf && pf.files[0]) jobs.push({ f: pf.files[0], kind: 'lp', label: 'lecture PDF' });
    newNotes.forEach(function (n) { jobs.push({ f: n.f, kind: 'ln', label: n.f.name, title: n.row.querySelector('input').value.trim() || n.f.name.replace(/\.pdf$/i, '') }); });
    var bad = jobs.filter(function (j) { return j.kind !== 'v' && !/\.pdf$/i.test(j.f.name); });
    if (bad.length) { e.preventDefault(); msg.textContent = bad[0].f.name + ' is not a PDF.'; return; }
    if (!jobs.length) return; // nothing to upload: a normal save
    e.preventDefault();
    var fd = new FormData(f); fd.append('ajax', '1');
    $$('button').forEach(function (b) { b.disabled = true; }); bar.style.display = 'block'; st.textContent = 'Saving…';
    var main = jobs.filter(function (j) { return j.main; })[0];
    (main ? videoInfo(main.f) : Promise.resolve({})).then(function (vi) {
      if (main) { main.duration = vi.duration; main.height = vi.height; if (vi.duration && !dur.value) fd.set('duration', vi.duration); }
      return fetch(location.pathname + location.search, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }).then(function (d) {
      if (!d.ok) throw new Error(d.error || 'Could not save the lecture.');
      var all = jobs.reduce(function (a, j) { return a + j.f.size; }, 0), sent = 0;
      return jobs.reduce(function (p, j) {
        return p.then(function () {
          return upload(j.f, { kind: j.kind, target: d.id, quality: j.quality, duration: j.duration, height: j.height, title: j.title,
            progress: function (fr) { var now = sent + fr * j.f.size; bar.firstChild.style.width = Math.round(now * 100 / all) + '%'; st.textContent = 'Uploading ' + j.label + '… ' + Math.round(now * 100 / all) + '% — keep this page open'; } })
            .then(function () { sent += j.f.size; });
        });
      }, Promise.resolve()).then(function () { st.textContent = 'Done.'; location.href = d.go; });
    }).catch(function (err) { $$('button').forEach(function (b) { b.disabled = false; }); st.textContent = ''; msg.textContent = err.message || 'Something went wrong.'; msg.style.color = '#b91c1c'; });
  });
})();
