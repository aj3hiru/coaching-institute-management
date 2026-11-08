/* Course notes PDFs (File Manager → Course notes) and the picker popup of Add lecture → Add notes PDF, which also offers
   the PDFs of File Manager (copied into Course notes when added).
   PdfMedia.mount(el, { uploadButton })          the library page: upload, rename (title), open, delete
   PdfMedia.open({ onPick: function (items) {} }) the picker: choose one or more PDFs (or upload new ones), then "Add"
   Every PDF has a title: students see it and the downloaded file is named after it. */
(function () {
  'use strict';
  var CHUNK = 4 * 1024 * 1024;
  var csrf = function () { return (window.PM_CFG && PM_CFG.csrf) || (window.SHC && SHC.csrf) || ''; };
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function mb(n) { return n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB'; }
  function rid() { return Array.prototype.map.call(crypto.getRandomValues(new Uint8Array(12)), function (b) { return ('0' + b.toString(16)).slice(-2); }).join(''); }
  function wait(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
  function post(fields) {
    var fd = new FormData(); fd.append('_csrf', csrf()); fd.append('ajax', '1');
    Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
    return fetch('/admin/shop-pdfs.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }
  function list(q) { return fetch('/admin/shop-pdfs.php?json=1' + (q ? '&q=' + encodeURIComponent(q) : ''), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) { return d.items || []; }); }
  /** chunked upload of one PDF into the library → { id, title, pages, size } */
  function upload(file, title, progress) {
    var id = rid(), total = Math.max(1, Math.ceil(file.size / CHUNK)), i = 0;
    function send(idx, tries) {
      var h = { 'Content-Type': 'application/octet-stream', 'X-CSRF-Token': csrf(), 'X-Upload': id, 'X-Index': String(idx), 'X-Total': String(total), 'X-Size': String(file.size),
        'X-Name': encodeURIComponent(file.name), 'X-Kind': 'pm', 'X-Target': '0', 'X-Title': encodeURIComponent(title || '') };
      return fetch('/admin/shop-upload.php', { method: 'POST', headers: h, body: file.slice(idx * CHUNK, Math.min(file.size, (idx + 1) * CHUNK)), credentials: 'same-origin' })
        .then(function (r) { return r.json().catch(function () { return { error: 'Server error ' + r.status }; }).then(function (d) { d._s = r.status; return d; }); })
        .then(function (d) {
          if (d.ok) return d;
          if (d._s === 409 && typeof d.next === 'number') { i = d.next; return { retry: true }; }
          if (d._s >= 500 && d._s !== 507 && tries < 4) return wait(1500 * (tries + 1)).then(function () { return send(idx, tries + 1); });
          throw new Error(d.error || 'Upload failed');
        }, function () { if (tries < 6) return wait(2000 * (tries + 1)).then(function () { return send(idx, tries + 1); }); throw new Error('Network problem — the upload stopped.'); });
    }
    function loop() { if (i >= total) return Promise.resolve({}); return send(i, 0).then(function (d) { if (d.retry) return loop(); i++; if (progress) progress(i / total); return d.done ? d : loop(); }); }
    return loop();
  }

  /** the library inside `root`; opts.pick = true for the picker (cards can be selected) */
  function Library(root, opts) {
    opts = opts || {};
    var items = [], sel = {}, q = '';
    root.classList.add('pm-root');
    root.innerHTML = '<div class="pm-bar"><input type="search" placeholder="Search PDFs by title…" aria-label="Search PDFs"><span class="pm-count"></span>'
      + (opts.ownUpload ? '<button type="button" class="ci-btn ci-sm pm-upbtn"><i class="fas fa-upload"></i> Upload PDF</button>' : '') + '</div>'
      + '<div class="pm-up"></div><div class="pm-grid"></div><input type="file" accept="application/pdf,.pdf" multiple hidden>';
    var grid = root.querySelector('.pm-grid'), ups = root.querySelector('.pm-up'), fin = root.querySelector('input[type=file]'), count = root.querySelector('.pm-count');
    var search = root.querySelector('input[type=search]'), t0;
    search.addEventListener('input', function () { clearTimeout(t0); t0 = setTimeout(function () { q = search.value.trim(); load(); }, 250); });
    var ub = root.querySelector('.pm-upbtn'); if (ub) ub.addEventListener('click', function () { fin.click(); });
    if (opts.uploadButton) opts.uploadButton.addEventListener('click', function () { fin.click(); });
    fin.addEventListener('change', function () { [].forEach.call(fin.files, add); fin.value = ''; });
    function add(file) {
      var job = document.createElement('div'); job.className = 'pm-job';
      if (!/\.pdf$/i.test(file.name)) { job.className += ' err'; job.textContent = file.name + ' is not a PDF.'; ups.appendChild(job); return; }
      job.innerHTML = '<i class="fas fa-file-pdf" style="color:#dc2626"></i><span>' + esc(file.name) + '</span><i class="bar"><b></b></i><span class="pc">0%</span>'; ups.appendChild(job);
      upload(file, file.name.replace(/\.pdf$/i, ''), function (f) { job.querySelector('.bar b').style.width = Math.round(f * 100) + '%'; job.querySelector('.pc').textContent = Math.round(f * 100) + '%'; })
        .then(function (d) {
          job.remove();
          if (opts.pick) sel[d.id] = true;
          return load().then(function () { var t = grid.querySelector('[data-id="' + d.id + '"] .pm-t'); if (t) { t.focus(); t.select(); } }); // give it a title now
        })
        .catch(function (e) { job.className = 'pm-job err'; job.innerHTML = '<span>' + esc(file.name) + ' — ' + esc(e.message) + '</span>'; });
    }
    function card(it) {
      var c = document.createElement('div'); c.className = 'pm-card' + (sel[it.id] ? ' sel' : ''); c.dataset.id = it.id;
      c.innerHTML = (opts.pick ? '<span class="pm-chk"><i class="fas fa-check"></i></span>' : '')
        + '<span class="pm-ico" title="' + (opts.pick ? 'Choose' : 'Open') + '"><i class="fas fa-file-pdf"></i>' + (it.pages ? '<small>' + it.pages + (it.pages === 1 ? ' page' : ' pages') + '</small>' : '') + '</span>'
        + '<input class="pm-t" maxlength="200" value="' + esc(it.title) + '" aria-label="PDF title" title="Title — students see it and the downloaded file gets this name">'
        + '<div class="pm-meta"><span>' + mb(it.size) + '</span><span>·</span><span>' + esc(it.date) + '</span>' + (it.used ? '<span class="pm-used">· in ' + it.used + ' lecture' + (it.used === 1 ? '' : 's') + '</span>' : '') + '<span class="pm-saved" hidden>✓ saved</span></div>'
        + '<div class="pm-acts"><a href="' + esc(it.view) + '" target="_blank" rel="noopener">Open</a>' + (!opts.pick ? '<button type="button" class="ci-btn ci-ghost ci-sm pm-del"' + (it.used ? ' disabled title="Used in a lecture"' : '') + '><i class="fas fa-trash"></i> Delete</button>' : '') + '</div>';
      var t = c.querySelector('.pm-t');
      t.addEventListener('change', function () {
        var v = t.value.trim(); if (!v) { t.value = it.title; return; }
        post({ action: 'rename', id: it.id, title: v }).then(function (d) { if (d.ok) { it.title = d.title; var s = c.querySelector('.pm-saved'); s.hidden = false; setTimeout(function () { s.hidden = true; }, 1500); } else alert(d.error || 'Could not save the title.'); });
      });
      if (opts.pick) c.querySelector('.pm-ico').addEventListener('click', function () { toggle(it.id, c); });
      if (opts.pick) c.querySelector('.pm-chk').addEventListener('click', function () { toggle(it.id, c); });
      else c.querySelector('.pm-ico').addEventListener('click', function () { window.open(it.view, '_blank'); });
      var del = c.querySelector('.pm-del');
      if (del) del.addEventListener('click', function () { if (!confirm('Delete "' + it.title + '"?')) return; post({ action: 'delete', id: it.id }).then(function (d) { if (d.ok) load(); else alert(d.error || 'Could not delete.'); }); });
      return c;
    }
    function toggle(id, c) { sel[id] = !sel[id]; if (!sel[id]) delete sel[id]; c.classList.toggle('sel', !!sel[id]); if (opts.onSelect) opts.onSelect(chosen()); }
    function chosen() { return items.filter(function (it) { return sel[it.id]; }).map(function (it) { var t = grid.querySelector('[data-id="' + it.id + '"] .pm-t'); return { id: it.id, title: (t && t.value.trim()) || it.title, pages: it.pages, size: it.size }; }); }
    function load() {
      // titles being typed are kept when the list is drawn again (e.g. another upload finished)
      return list(q).then(function (d) {
        var keep = {}, focus = document.activeElement && document.activeElement.classList.contains('pm-t') && grid.contains(document.activeElement) ? document.activeElement.closest('.pm-card').dataset.id : null;
        [].forEach.call(grid.querySelectorAll('.pm-card'), function (c) { keep[c.dataset.id] = c.querySelector('.pm-t').value; });
        d.forEach(function (it) { if (keep[it.id] !== undefined && keep[it.id] !== it.title) it._typed = keep[it.id]; });
        items = d; grid.innerHTML = '';
        if (!d.length) grid.innerHTML = '<div class="pm-empty" style="grid-column:1/-1">' + (q ? 'No PDF with this title.' : 'No PDFs yet — press <b>Upload PDF</b> to add the first one from your device.') + '</div>';
        d.forEach(function (it) { var c = card(it); if (it._typed !== undefined) { c.querySelector('.pm-t').value = it._typed; delete it._typed; } grid.appendChild(c); });
        if (focus) { var ft = grid.querySelector('[data-id="' + focus + '"] .pm-t'); if (ft) { ft.focus(); var L = ft.value.length; try { ft.setSelectionRange(L, L); } catch (e) {} } }
        count.textContent = d.length + ' PDF' + (d.length === 1 ? '' : 's');
        if (opts.onSelect) opts.onSelect(chosen());
      });
    }
    load();
    return { chosen: chosen, reload: load };
  }

  window.PdfMedia = {
    upload: upload,
    mount: function (el, opts) { return Library(el, opts); },
    open: function (opts) {
      opts = opts || {};
      var m = document.createElement('div'); m.className = 'pm-modal';
      m.innerHTML = '<div class="pm-box" role="dialog" aria-modal="true" aria-label="Choose PDFs"><div class="pm-head"><h3><i class="fas fa-file-pdf" style="color:#dc2626"></i> Choose notes PDFs</h3><button type="button" class="ci-btn ci-ghost ci-sm pm-x" aria-label="Close"><i class="fas fa-times"></i></button></div>'
        + '<div class="pm-tabs"><button type="button" class="on" data-t="lib"><i class="fas fa-lock"></i> Course notes</button><button type="button" data-t="site"><i class="fas fa-folder-open"></i> File Manager PDFs</button></div>'
        + '<div class="pm-body"><div class="pm-pane" data-p="lib"></div><div class="pm-pane" data-p="site" hidden><div class="pm-bar"><input type="search" placeholder="Search File Manager PDFs…"><span class="pm-count"></span></div><p class="ci-muted" style="margin:0 0 10px;font-size:12.5px">A PDF chosen here is copied into Course notes, so only students of the course can open it.</p><div class="pm-grid"></div></div></div>'
        + '<div class="pm-foot"><span class="ci-muted pm-n">Choose one or more PDFs — or upload new ones from your device.</span><span style="display:flex;gap:8px"><button type="button" class="ci-btn ci-ghost pm-cancel">Cancel</button><button type="button" class="ci-btn pm-add" disabled><i class="fas fa-plus"></i> Add to lecture</button></span></div></div>';
      document.body.appendChild(m); document.documentElement.style.overflow = 'hidden';
      var addB = m.querySelector('.pm-add'), nTxt = m.querySelector('.pm-n'), libC = [], siteSel = {}, siteItems = [];
      function paint() {
        var n = libC.length + Object.keys(siteSel).length; addB.disabled = !n;
        addB.innerHTML = '<i class="fas fa-plus"></i> Add ' + (n ? n + ' ' : '') + 'to lecture';
        nTxt.textContent = n ? n + ' chosen · the title you see is the name students get' : 'Choose one or more PDFs — or upload new ones from your device.';
      }
      var lib = Library(m.querySelector('[data-p="lib"]'), { pick: true, ownUpload: true, onSelect: function (c) { libC = c; paint(); } });
      // File Manager PDFs (public files): chosen ones are copied into Course notes when added
      var sp = m.querySelector('[data-p="site"]'), sg = sp.querySelector('.pm-grid'), sq = sp.querySelector('input[type=search]'), st0, loaded = false;
      function siteLoad() {
        sg.innerHTML = '<div class="pm-empty" style="grid-column:1/-1">Loading…</div>';
        fetch('/admin/shop-pdfs.php?site=1' + (sq.value.trim() ? '&q=' + encodeURIComponent(sq.value.trim()) : ''), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
          siteItems = d.items || []; sp.querySelector('.pm-count').textContent = siteItems.length + ' PDF' + (siteItems.length === 1 ? '' : 's');
          sg.innerHTML = siteItems.length ? '' : '<div class="pm-empty" style="grid-column:1/-1">No PDF in File Manager' + (sq.value.trim() ? ' with this name' : ' yet') + '.</div>';
          siteItems.forEach(function (it) {
            var c = document.createElement('div'); c.className = 'pm-card' + (siteSel[it.id] ? ' sel' : ''); c.dataset.id = it.id;
            c.innerHTML = '<span class="pm-chk"><i class="fas fa-check"></i></span><span class="pm-ico" title="Choose"><i class="fas fa-file-pdf"></i></span><input class="pm-t" maxlength="200" aria-label="Title for the course" value="' + esc(siteSel[it.id] || it.title) + '"><div class="pm-meta"><span>' + mb(it.size) + '</span><span>·</span><span>' + esc(it.date) + '</span></div><div class="pm-acts"><a href="' + esc(it.view) + '" target="_blank" rel="noopener">Open</a></div>';
            var tog = function () { if (siteSel[it.id]) delete siteSel[it.id]; else siteSel[it.id] = c.querySelector('.pm-t').value.trim() || it.title; c.classList.toggle('sel', !!siteSel[it.id]); paint(); };
            c.querySelector('.pm-ico').addEventListener('click', tog); c.querySelector('.pm-chk').addEventListener('click', tog);
            c.querySelector('.pm-t').addEventListener('input', function () { if (siteSel[it.id]) siteSel[it.id] = this.value.trim() || it.title; });
            sg.appendChild(c);
          });
        });
      }
      sq.addEventListener('input', function () { clearTimeout(st0); st0 = setTimeout(siteLoad, 250); });
      m.querySelector('.pm-tabs').addEventListener('click', function (e) {
        var b = e.target.closest('[data-t]'); if (!b) return;
        m.querySelectorAll('.pm-tabs button').forEach(function (x) { x.classList.toggle('on', x === b); });
        m.querySelectorAll('.pm-pane').forEach(function (p) { p.hidden = p.dataset.p !== b.dataset.t; });
        if (b.dataset.t === 'site' && !loaded) { loaded = true; siteLoad(); }
      });
      function close() { m.remove(); document.documentElement.style.overflow = ''; document.removeEventListener('keydown', key); }
      function key(e) { if (e.key === 'Escape') close(); }
      document.addEventListener('keydown', key);
      m.addEventListener('click', function (e) { if (e.target === m) close(); });
      m.querySelector('.pm-x').addEventListener('click', close); m.querySelector('.pm-cancel').addEventListener('click', close);
      addB.addEventListener('click', function () {
        var chosen = lib.chosen(), ids = Object.keys(siteSel); if (!chosen.length && !ids.length) return;
        addB.disabled = true; addB.textContent = 'Adding…';
        Promise.all(ids.map(function (id) { return post({ action: 'import', media_id: id, title: siteSel[id] }); })).then(function (rs) {
          var bad = rs.filter(function (r) { return !r.ok; });
          if (bad.length) alert(bad[0].error || 'A PDF could not be added.');
          rs.filter(function (r) { return r.ok; }).forEach(function (r) { chosen.push({ id: r.id, title: r.title, pages: r.pages, size: r.size }); });
          close(); if (chosen.length && opts.onPick) opts.onPick(chosen);
        });
      });
    },
  };
})();
