/* Add / edit lecture (admin/shop-lecture.php): video / PDF switch, chapter → lecture no. + units, auto name,
   video link fetch (name, picture, length), repeatable rows (VVI, quiz), save then upload the chosen PDFs in pieces. */
(function () {
  'use strict';
  var C = window.SHC || {}, f = document.getElementById('slcForm');
  var course = document.getElementById('slcCourse');
  if (!f || !document.getElementById('slcTitle')) return;
  var $ = function (s) { return f.querySelector(s); }, $$ = function (s) { return [].slice.call(f.querySelectorAll(s)); };

  // --- what is shown -------------------------------------------------------
  function kindV() { var k = f.querySelector('[name=kind]:checked') || f.querySelector('[name=kind]'); return k ? k.value : 'video'; } // lectures are videos (old PDF lectures keep 'pdf')
  function sync() {
    var pdf = kindV() === 'pdf';
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
  // the course can be chosen here: its chapters and units come in without reloading (nothing typed is lost)
  var courseIn = $('[name=course]');
  if (course) course.addEventListener('change', function () {
    courseIn.value = course.value || '';
    if (!course.value) return;
    fetch('/admin/shop-lecture.php?chapters=' + encodeURIComponent(course.value), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      if (d.error) { msg.textContent = d.error; return; }
      sec.innerHTML = ''; d.chapters.forEach(function (c) { var o = document.createElement('option'); o.value = c.id; o.textContent = c.title; o.setAttribute('data-next', c.next); sec.appendChild(o); });
      var nw = document.createElement('option'); nw.value = '-1'; nw.setAttribute('data-next', '1'); nw.textContent = '+ New chapter…'; sec.appendChild(nw);
      sec.value = String(d.pick || (d.chapters.length ? d.chapters[d.chapters.length - 1].id : -1));
      unit.innerHTML = '<option value="0" data-sec="*">— No unit —</option>'; d.units.forEach(function (u) { var o = document.createElement('option'); o.value = u.id; o.setAttribute('data-sec', u.sec); o.textContent = u.title; unit.appendChild(o); });
      var nu = document.createElement('option'); nu.value = '-1'; nu.setAttribute('data-sec', '*'); nu.textContent = '+ New unit…'; unit.appendChild(nu);
      title.dataset.prefix = d.prefix || ''; if (!title.value.trim()) title.dataset.auto = '1';
      if (window.SHC) SHC.pid = d.course;
      try { history.replaceState(null, '', '/admin/shop-lecture.php?course=' + d.course); } catch (e) {}
      onSec(); clearErr(course);
    });
  });

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

  // --- notes PDFs: chosen from PDF Media (upload new ones there), each with its own title; "Add more PDFs" adds more -----
  var notesList = $('#slcNoteList'), notesBtn = $('#slcNotesAdd');
  function notesLabel() { notesBtn.querySelector('span').textContent = notesList.children.length ? 'Add more PDFs' : 'Add notes PDF'; }
  notesBtn.addEventListener('click', function () {
    if (!window.PdfMedia) { msg.textContent = 'PDF Media is still loading — try again in a moment.'; return; }
    PdfMedia.open({ onPick: function (items) {
      items.forEach(function (it) {
        if (notesList.querySelector('[data-mid="' + it.id + '"]')) return; // already added
        var row = document.createElement('div'); row.className = 'slc-nrow'; row.dataset.mid = it.id;
        row.innerHTML = '<span class="slc-nico">PDF</span><input name="note_mtitle[' + it.id + ']" maxlength="200" aria-label="PDF title" title="Title — students see it and the downloaded file gets this name"><small>' + (it.pages ? it.pages + (it.pages === 1 ? ' page · ' : ' pages · ') : '') + 'from PDF Media · new</small>'
          + '<button type="button" class="slc-nrm" style="border:0;background:none;cursor:pointer">Remove</button><input type="hidden" name="note_media[]" value="' + it.id + '">';
        row.querySelector('input').value = it.title;
        row.querySelector('button').addEventListener('click', function () { row.remove(); notesLabel(); });
        notesList.appendChild(row);
      });
      notesLabel();
    } });
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
  // --- bulk add: paste many VVI questions or MCQs at once (the same format as GK Quiz in the post editor) -----------
  var HI = { 'क': 'A', 'ख': 'B', 'ग': 'C', 'घ': 'D', 'ङ': 'E', 'च': 'F' };
  var reQ = /^\s*(?:Q(?:ues(?:tion)?)?\s*\.?\s*|प्रश्न\s*|प्र\.\s*)?(\d{1,4})\s*[.)\-:।]\s*(.+)$/i;
  function mcqParse(text) {
    var qs = [], errs = [], cur = null, mode = '';
    var letter = function (x) { return HI[x] || String(x).toUpperCase(); };
    var reA = /^\s*(?:ans(?:wer)?|correct(?:\s*answer)?|सही\s*उत्तर|उत्तर)\s*[:\-–=.]?\s*(?:[(\[]?\s*([A-Fa-fकखगघङच])\s*[)\]]?(?=$|[\s.)\-:।])\s*[.)\-:।]?\s*)?(.*)$/i;
    var reE = /^\s*(?:explanation|exp|solution|व्याख्या|हल|स्पष्टीकरण)\s*[:\-–.]\s*(.*)$/i;
    var reO = /^\s*(?:\(\s*([A-Fa-fकखगघङच])\s*\)|([A-Fa-fकखगघङच])\s*[.)\-:।])\s*(.+)$/;
    text.replace(/\r/g, '').split('\n').forEach(function (raw, i) {
      var line = raw.trim(), m;
      if (!line) { if (mode === 'exp') mode = 'exp-gap'; return; }
      if (cur && (m = line.match(reA)) && /^(ans|correct|सही|उत्तर)/i.test(line)) {
        var l = m[1] ? letter(m[1]) : '';
        if (!l && m[2]) { var t = m[2].trim().toLowerCase(); var hit = cur.opts.filter(function (o) { return o.t.toLowerCase() === t; })[0]; if (hit) l = hit.l; }
        cur.ans = l; mode = 'ans'; return;
      }
      if (cur && (m = line.match(reE))) { cur.exp = m[1].trim(); mode = 'exp'; return; }
      if ((m = line.match(reQ)) && !(cur && cur.opts.length && cur.opts.length < 2)) { cur = { q: m[2].trim(), opts: [], ans: '', exp: '', line: i + 1 }; qs.push(cur); mode = 'q'; return; }
      if (cur && (m = line.match(reO))) {
        var tt = m[3].trim(), ll = letter(m[1] || m[2]);
        if (/\s*[*✔✓]\s*$/.test(tt) || /^[*✔✓]\s*/.test(tt)) { tt = tt.replace(/^[*✔✓]\s*|\s*[*✔✓]\s*$/g, ''); cur.ans = ll; }
        cur.opts.push({ l: ll, t: tt }); mode = 'opt'; return;
      }
      if (cur && (mode === 'exp' || mode === 'exp-gap')) { cur.exp += (cur.exp ? ' ' : '') + line; mode = 'exp'; return; }
      if (cur && mode === 'q') { cur.q += ' ' + line; return; }
      errs.push(cur ? 'Line ' + (i + 1) + ' not understood: ' + line.slice(0, 60) : 'Line ' + (i + 1) + ': write a question number first, e.g. "1. Question"');
    });
    qs.forEach(function (q, n) {
      if (q.opts.length < 2) errs.push('Question ' + (n + 1) + ' (line ' + q.line + '): needs at least 2 options (A. … B. …)');
      else if (q.opts.length > 4) errs.push('Question ' + (n + 1) + ' (line ' + q.line + '): at most 4 options (A–D)');
      else if (!q.ans) errs.push('Question ' + (n + 1) + ' (line ' + q.line + '): add the right answer, e.g. "Answer: B" or a * after the right option');
      else if (!q.opts.some(function (o) { return o.l === q.ans; })) errs.push('Question ' + (n + 1) + ': answer ' + q.ans + ' is not one of its options');
    });
    if (!qs.length && !errs.length) errs.push('No questions found.');
    return { qs: qs, errs: errs };
  }
  function vviParse(text) {
    var qs = [], errs = [], cur = null, mode = '';
    var reAns = /^\s*(?:ans(?:wer)?|उत्तर|सही\s*उत्तर|हल|solution)\s*[:\-–=.]\s*(.*)$/i;
    text.replace(/\r/g, '').split('\n').forEach(function (raw, i) {
      var line = raw.trim(), m;
      if (!line) return;
      if (cur && (m = line.match(reAns))) { cur.a = m[1].trim(); mode = 'a'; return; }
      if ((m = line.match(reQ))) { cur = { q: m[2].trim(), a: '', line: i + 1 }; qs.push(cur); mode = 'q'; return; }
      if (cur && mode === 'a') { cur.a += '\n' + line; return; }
      if (cur && mode === 'q') { cur.q += ' ' + line; return; }
      errs.push('Line ' + (i + 1) + ': write a question number first, e.g. "1. Question"');
    });
    qs.forEach(function (q, n) { if (!q.a) errs.push('Question ' + (n + 1) + ' (line ' + q.line + '): add the answer, e.g. "Answer: …"'); });
    if (!qs.length && !errs.length) errs.push('No questions found.');
    return { qs: qs, errs: errs };
  }
  function rowEmpty(row) { return ![].some.call(row.querySelectorAll('input:not([type=radio]), textarea'), function (x) { return x.value.trim(); }); }
  function nextRow(box) { // the first empty row, or a new one (the Add button clones the last row)
    var rows = box.querySelectorAll(':scope > .slc-row'), last = rows[rows.length - 1];
    if (last && rowEmpty(last)) return last;
    f.querySelector('[data-add="' + box.id + '"]').click();
    rows = box.querySelectorAll(':scope > .slc-row'); return rows[rows.length - 1];
  }
  var BULK = {
    vvi: { title: 'Bulk add VVI questions', box: '#slcVqa', parse: vviParse, sample: '1. What is a noun?\nAnswer: A noun is the name of a person, place, animal or thing.\n\n2. भारत की राजधानी क्या है?\nउत्तर: नई दिल्ली', help: 'Question with a number, then <code>Answer:</code> (or <code>उत्तर:</code>). An answer can have more lines.',
      fill: function (q) { var row = nextRow($('#slcVqa')); row.querySelector('[name="vq[]"]').value = q.q; row.querySelector('[name="va[]"]').value = q.a; } },
    quiz: { title: 'Bulk add MCQs', box: '#slcQz', parse: mcqParse, sample: '1. भारत की राजधानी क्या है?\nA. मुंबई\nB. नई दिल्ली\nC. कोलकाता\nD. चेन्नई\nAnswer: B\nExplanation: नई दिल्ली 1931 से राजधानी है।', help: 'Also works: <code>Q1.</code> / <code>प्रश्न 1.</code>, options <code>(A)</code> or <code>क.</code> <code>ख.</code>, answer <code>उत्तर: ख</code> / <code>Ans: B</code>, or a <code>*</code> after the right option. Explanation is optional. Up to 4 options (A–D).',
      fill: function (q) {
        var row = nextRow($('#slcQz'));
        row.querySelector('input[name^="qq["]').value = q.q;
        var ops = row.querySelectorAll('input[name^="qo["]'), rad = row.querySelectorAll('input[type=radio]');
        [].forEach.call(ops, function (o, k) { o.value = q.opts[k] ? q.opts[k].t : ''; });
        var ai = q.opts.map(function (o) { return o.l; }).indexOf(q.ans); [].forEach.call(rad, function (r, k) { r.checked = k === ai; });
        var e = row.querySelector('input[name^="qe["]'); if (e) e.value = q.exp || '';
      } },
  };
  f.addEventListener('click', function (e) {
    var b = e.target.closest('[data-bulk]'); if (!b) return;
    var cfg = BULK[b.getAttribute('data-bulk')];
    var m = document.createElement('div'); m.className = 'pm-modal';
    m.innerHTML = '<div class="pm-box slc-bulk" role="dialog" aria-modal="true" style="max-width:820px"><div class="pm-head"><h3>' + cfg.title + '</h3><button type="button" class="ci-btn ci-ghost ci-sm" data-x aria-label="Close"><i class="fas fa-times"></i></button></div>'
      + '<div class="pm-body"><p class="slc-sub" style="margin:0 0 6px"><b>Format</b> — one question after another (paste 100 at once):</p><pre></pre><p class="slc-sub">' + cfg.help + '</p><textarea placeholder="Paste or type the questions here…"></textarea><p class="slc-berr" hidden></p></div>'
      + '<div class="pm-foot"><span class="ci-muted">They are added below the questions already there — you can still edit each one.</span><span style="display:flex;gap:8px"><button type="button" class="ci-btn ci-ghost" data-x>Cancel</button><button type="button" class="ci-btn" data-go><i class="fas fa-plus"></i> Add questions</button></span></div></div>';
    m.querySelector('pre').textContent = cfg.sample;
    document.body.appendChild(m); var ta = m.querySelector('textarea'), er = m.querySelector('.slc-berr'); ta.focus();
    var close = function () { m.remove(); };
    m.addEventListener('click', function (ev) { if (ev.target === m || ev.target.closest('[data-x]')) close(); });
    m.querySelector('[data-go]').addEventListener('click', function () {
      var r = cfg.parse(ta.value || '');
      if (r.errs.length) { er.hidden = false; er.textContent = r.errs.slice(0, 8).join('\n') + (r.errs.length > 8 ? '\n… and ' + (r.errs.length - 8) + ' more' : ''); return; }
      r.qs.forEach(cfg.fill); close();
      msg.style.color = '#15803d'; msg.textContent = r.qs.length + ' question' + (r.qs.length === 1 ? '' : 's') + ' added.';
    });
  });

  // --- featured image: chosen from the Media Library (or uploaded there); else the video's own picture --------------
  var thumbIn = $('#slcThumb'), thumbBox = $('#slcThumbBox'), thumbRm = $('#slcThumbRm');
  function ytId(u) { var m = String(u || '').match(/(?:youtu\.be\/|youtube(?:-nocookie)?\.com\/(?:watch\?(?:.*&)?v=|embed\/|shorts\/|live\/|v\/))([\w-]{11})/); return m ? m[1] : ''; }
  function paintThumb() {
    if (!thumbBox) return;
    var own = thumbIn.value.trim(), yt = ytId(url && url.value);
    thumbBox.innerHTML = own ? '<img class="slc-thumb" alt="" src="' + esc(own) + '">' : (yt ? '<img class="slc-thumb" alt="" src="https://i.ytimg.com/vi/' + yt + '/hqdefault.jpg"><small class="slc-sub" style="display:block;margin-top:4px">From the YouTube video</small>' : '<span class="slc-thumb-empty">No picture yet</span>');
    thumbRm.hidden = !own;
  }
  if (thumbBox) {
    $('#slcThumbPick').addEventListener('click', function () { if (!window.EduMedia) { msg.textContent = 'The Media Library is still loading — try again.'; return; } EduMedia.pick(function (it) { thumbIn.value = it.path; paintThumb(); }); });
    thumbRm.addEventListener('click', function () { thumbIn.value = ''; paintThumb(); });
    if (url) { url.addEventListener('input', paintThumb); url.addEventListener('change', paintThumb); }
    paintThumb();
  }

  // --- Publish: every required part is checked; a missing one is marked, the page jumps to it and says it is required ---
  var errBox = $('#slcErrBox');
  function clearErr(el) {
    if (!el) return; el.classList.remove('slc-err-f');
    var box = el.closest('.ci-f, .meta-panel-body'), n = box && box.querySelector(':scope > .slc-req'); if (n) n.remove();
    var pnl = el.closest('.meta-panel'); if (pnl && !pnl.querySelector('.slc-err-f')) pnl.classList.remove('slc-has-err');
    if (errBox && !f.querySelector('.slc-err-f')) errBox.classList.remove('on');
  }
  function markErr(el, text) {
    el.classList.add('slc-err-f');
    var box = el.closest('.ci-f') || el.parentNode;
    if (!box.querySelector(':scope > .slc-req')) { var n = document.createElement('span'); n.className = 'slc-req'; n.textContent = text; box.appendChild(n); }
    var pnl = el.closest('.meta-panel'); if (pnl) pnl.classList.add('slc-has-err');
  }
  f.addEventListener('input', function (e) { if (e.target.classList && e.target.classList.contains('slc-err-f')) clearErr(e.target); });
  f.addEventListener('change', function (e) { if (e.target.classList && e.target.classList.contains('slc-err-f')) clearErr(e.target); if (e.target === sec) clearErr(secNew.querySelector('input')); });
  if (course) course.addEventListener('change', function () { clearErr(course); });
  f.addEventListener('submit', function (e) {
    var miss = [];
    var need = function (ok, el, name, text) { if (ok) { clearErr(el); return; } miss.push({ el: el, name: name }); markErr(el, text || name + ' is required — fill it in.'); };
    if (course && !course.disabled) need(!!(+courseIn.value || +course.value), course, 'Course', 'Choose the course — this is required.');
    if (sec.value === '-1') need(!!secNew.querySelector('input').value.trim(), secNew.querySelector('input'), 'New chapter name', 'Write the chapter name — this is required.');
    else need(!!sec.value, sec, 'Chapter', 'Choose the chapter — this is required.');
    need(!!title.value.trim(), title, 'Lecture name', 'Write the lecture name — this is required.');
    if (kindV() !== 'pdf') need(/^https?:\/\/\S+$/i.test(url.value.trim()) || !!f.querySelector('.slc-kept'), url, 'Video link', 'Paste the video link (YouTube or any other source) — this is required.');
    if (!miss.length) { if (errBox) errBox.classList.remove('on'); return; }
    e.preventDefault(); e.stopImmediatePropagation();
    if (errBox) { errBox.textContent = 'Fill the required: ' + miss.map(function (m) { return m.name; }).join(', ') + '.'; errBox.classList.add('on'); }
    var first = miss[0].el; first.scrollIntoView({ behavior: 'smooth', block: 'center' }); setTimeout(function () { try { first.focus({ preventScroll: true }); } catch (x) {} }, 350);
  }, true);
  $$('[data-next]').forEach(function (b) { if (b.tagName === 'BUTTON') b.addEventListener('click', function () { $('#slcNext').value = '1'; }); });
  f.addEventListener('submit', function (e) {
    var pdfKind = kindV() === 'pdf';
    if (!pdfKind && !url.value.trim() && !f.querySelector('.slc-kept')) { e.preventDefault(); url.focus(); msg.textContent = 'Paste the video link.'; return; }
    if (sec.value === '-1' && !secNew.querySelector('input').value.trim()) { e.preventDefault(); secNew.querySelector('input').focus(); msg.textContent = 'Write the new chapter name.'; return; }
    if (unit.value === '-1' && !unitNew.querySelector('input').value.trim()) { e.preventDefault(); unitNew.querySelector('input').focus(); msg.textContent = 'Write the new unit name.'; return; }
    var jobs = [];
    var pf = $('#slcPdf'); // videos are links now: only PDFs are uploaded
    if (pdfKind && pf && pf.files[0]) jobs.push({ f: pf.files[0], kind: 'lp', label: 'lecture PDF' });
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
