/* Store admin (shop-content.php): Upload-or-link switch, chunked uploads with progress + retry (videos, their smaller
   qualities, PDFs), video length / height read from the file before upload. */
(function () {
  'use strict';
  var C = window.SHC || {};
  var chunk = function () { return C.chunk || 4 * 1024 * 1024; }; // the server takes requests up to 8 MB
  var uploading = 0;
  window.addEventListener('beforeunload', function (e) { if (uploading) { e.preventDefault(); e.returnValue = ''; } });

  function rid() { var a = new Uint8Array(12); (window.crypto || window.msCrypto).getRandomValues(a); return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join(''); }
  function mb(n) { return n >= 1073741824 ? (n / 1073741824).toFixed(2) + ' GB' : (n / 1048576).toFixed(1) + ' MB'; }
  function wait(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

  /** Sends one file in pieces. opts: kind v|f, target, quality, title, duration, height, onProgress(fraction). */
  function upload(file, opts) {
    var CHUNK = chunk(), id = rid(), total = Math.max(1, Math.ceil(file.size / CHUNK)), i = 0;
    uploading++;
    function send(idx, tries) {
      var blob = file.slice(idx * CHUNK, Math.min(file.size, (idx + 1) * CHUNK));
      var h = { 'Content-Type': 'application/octet-stream', 'X-CSRF-Token': C.csrf, 'X-Upload': id, 'X-Index': String(idx), 'X-Total': String(total),
        'X-Size': String(file.size), 'X-Name': encodeURIComponent(file.name), 'X-Kind': opts.kind, 'X-Target': String(opts.target) };
      if (opts.quality) h['X-Quality'] = opts.quality;
      if (opts.title) h['X-Title'] = encodeURIComponent(opts.title);
      if (opts.duration) h['X-Duration'] = opts.duration;
      if (opts.height) h['X-Height'] = String(opts.height);
      return fetch('/admin/shop-upload.php', { method: 'POST', headers: h, body: blob, credentials: 'same-origin' })
        .then(function (r) { return r.json().catch(function () { return { error: 'Server error ' + r.status }; }).then(function (d) { d._s = r.status; return d; }); })
        .then(function (d) {
          if (d.ok) return d;
          if (d._s === 409 && typeof d.next === 'number') { i = d.next; return { retryFrom: d.next }; } // resume where the server is
          if (d._s >= 500 && d._s !== 507 && tries < 4) return wait(1500 * (tries + 1)).then(function () { return send(idx, tries + 1); });
          throw new Error(d.error || 'Upload failed');
        }, function (e) { // network down: wait and try the same piece again
          if (tries < 6) return wait(2000 * (tries + 1)).then(function () { return send(idx, tries + 1); });
          throw new Error('Network problem — the upload stopped. Please try again.');
        });
    }
    function loop() {
      if (i >= total) return Promise.resolve({ ok: true });
      return send(i, 0).then(function (d) {
        if (d.retryFrom !== undefined) return loop();
        i++;
        if (opts.onProgress) opts.onProgress(i / total);
        if (d.done) return d;
        return loop();
      });
    }
    return loop().then(function (d) { uploading--; return d; }, function (e) { uploading--; throw e; });
  }

  /** Length (m:ss) and height of a local video file, read by the browser. */
  function videoInfo(file) {
    return new Promise(function (ok) {
      var v = document.createElement('video'), url = URL.createObjectURL(file), done = false;
      var fin = function (r) { if (done) return; done = true; URL.revokeObjectURL(url); ok(r); };
      v.preload = 'metadata'; v.muted = true;
      v.onloadedmetadata = function () {
        var s = Math.round(v.duration || 0), d = '';
        if (s > 0 && isFinite(s)) d = s >= 3600 ? Math.floor(s / 3600) + ':' + ('0' + Math.floor(s % 3600 / 60)).slice(-2) + ':' + ('0' + s % 60).slice(-2) : Math.floor(s / 60) + ':' + ('0' + s % 60).slice(-2);
        fin({ duration: d, height: Math.min(v.videoWidth || 0, v.videoHeight || 0) || v.videoHeight || 0 }); // 1080p = the short side (also for phone-shot upright videos)
      };
      v.onerror = function () { fin({ duration: '', height: 0 }); };
      setTimeout(function () { fin({ duration: '', height: 0 }); }, 8000);
      v.src = url;
    });
  }

  function ui(form) {
    var bar = form.querySelector('.shc-bar i'), st = form.querySelector('[data-st]'), btns = form.querySelectorAll('button, input, select, textarea');
    return {
      busy: function (on) { form.classList.toggle('busy', on); btns.forEach(function (b) { if (b.type !== 'file' || !on) b.disabled = on; }); if (on) btns.forEach(function (b) { b.disabled = true; }); },
      set: function (f, text) { if (bar) bar.style.width = Math.round(f * 100) + '%'; if (st) st.textContent = text || ''; },
      fail: function (t) { if (st) { st.textContent = t; st.style.color = '#b91c1c'; } }
    };
  }

  // ---- lesson form ----
  var lf = document.getElementById('shcLesson');
  if (lf) {
    var sync = function () {
      var link = lf.querySelector('[name=source]:checked').value === 'link';
      lf.querySelectorAll('.shc-when-up').forEach(function (e) { e.hidden = link; });
      lf.querySelectorAll('.shc-when-link').forEach(function (e) { e.hidden = !link; });
      var u = lf.querySelector('[name=video_url]'); if (u) u.required = link;
    };
    lf.querySelectorAll('[name=source]').forEach(function (r) { r.addEventListener('change', sync); });
    sync();
    lf.addEventListener('submit', function (e) {
      var link = lf.querySelector('[name=source]:checked').value === 'link';
      var main = lf.querySelector('[name=vfile]').files[0];
      var quals = [].filter.call(lf.querySelectorAll('.shc-qf'), function (q) { return q.files[0]; });
      if (link || (!main && !quals.length)) return; // a normal save (link, or no file chosen)
      e.preventDefault();
      if (!lf.querySelector('[name=title]').value.trim()) { lf.querySelector('[name=title]').focus(); return; }
      var u = ui(lf);
      var fd = new FormData(lf); fd.delete('vfile'); fd.append('ajax', '1');
      var info = main ? videoInfo(main) : Promise.resolve({});
      u.busy(true); u.set(0, 'Saving…');
      info.then(function (vi) {
        if (vi.duration && !lf.querySelector('[name=duration]').value) fd.set('duration', vi.duration);
        return fetch(location.pathname + location.search, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
          if (!d.ok) throw new Error(d.error || 'Could not save the video.');
          var jobs = [];
          if (main) jobs.push({ f: main, q: '', label: 'Video', vi: vi });
          quals.forEach(function (q) { jobs.push({ f: q.files[0], q: q.getAttribute('data-q'), label: q.getAttribute('data-q') + 'p' }); });
          var all = jobs.reduce(function (a, j) { return a + j.f.size; }, 0), sent = 0;
          return jobs.reduce(function (p, j) {
            return p.then(function () {
              return upload(j.f, { kind: 'v', target: d.id, quality: j.q, duration: j.vi && j.vi.duration, height: j.vi && j.vi.height,
                onProgress: function (fr) { var now = sent + fr * j.f.size; u.set(now / all, 'Uploading ' + j.label + '… ' + mb(now) + ' of ' + mb(all) + ' (' + Math.round(now * 100 / all) + '%) — keep this page open'); } })
                .then(function () { sent += j.f.size; });
            });
          }, Promise.resolve()).then(function () { u.set(1, 'Done — saving…'); location.href = C.self + '&up=1#list'; });
        });
      }).catch(function (err) { u.busy(false); lf.querySelectorAll('button, input, select, textarea').forEach(function (b) { b.disabled = false; }); u.fail(err.message || 'Upload failed.'); });
    });
    // remove one quality
    lf.querySelectorAll('[data-rmq]').forEach(function (b) {
      b.addEventListener('click', function () {
        if (!confirm('Remove the ' + b.getAttribute('data-rmq') + 'p file?')) return;
        var f = document.createElement('form'); f.method = 'post'; f.style.display = 'none';
        [['_csrf', C.csrf], ['action', 'remove_quality'], ['id', lf.querySelector('[name=id]').value], ['q', b.getAttribute('data-rmq')], ['anchor', 'add']].forEach(function (x) { var i = document.createElement('input'); i.name = x[0]; i.value = x[1]; f.appendChild(i); });
        document.body.appendChild(f); f.submit();
      });
    });
  }

  // ---- PDFs ----
  var pf = document.getElementById('shcPdf');
  if (pf) pf.addEventListener('submit', function (e) {
    e.preventDefault();
    var files = [].slice.call(pf.querySelector('[name=pfile]').files);
    if (!files.length) { pf.querySelector('[name=pfile]').focus(); return; }
    var bad = files.filter(function (f) { return !/\.pdf$/i.test(f.name); });
    var u = ui(pf);
    if (bad.length) { u.fail(bad[0].name + ' is not a PDF.'); return; }
    var title = files.length === 1 ? pf.querySelector('[name=ptitle]').value.trim() : '';
    var all = files.reduce(function (a, f) { return a + f.size; }, 0), sent = 0;
    u.busy(true);
    files.reduce(function (p, f, n) {
      return p.then(function () {
        return upload(f, { kind: 'f', target: C.pid, title: title, onProgress: function (fr) { var now = sent + fr * f.size; u.set(now / all, 'Uploading ' + (files.length > 1 ? (n + 1) + ' of ' + files.length + ' — ' : '') + f.name + ' (' + Math.round(now * 100 / all) + '%)'); } })
          .then(function () { sent += f.size; });
      });
    }, Promise.resolve()).then(function () { u.set(1, 'Done.'); location.href = C.self + '&up=1#pdfs'; })
      .catch(function (err) { u.busy(false); pf.querySelectorAll('button, input').forEach(function (b) { b.disabled = false; }); u.fail(err.message || 'Upload failed.'); });
  });
})();
