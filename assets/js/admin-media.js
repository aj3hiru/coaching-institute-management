/* EduMedia.pick(callback, options) — the Media Library popup (pick uploaded images or upload new ones).
   One image (default): callback receives {id, path, alt}. options.multiple = true: tick several images (and upload several
   at once) — callback receives an array of them. options.purpose = 'slide' uploads keep up to 1920 px (slider banners).
   A file chosen from the device shows at once (its own preview) while it is turned into WebP, then the WebP size,
   the original size and the picture size appear on it. Used by General Settings, Customize and other admin pages. */
(function () {
  var box, state = { page: 1, sel: [], cb: null, timer: null, multi: false, purpose: '', fresh: {} };
  function el(id) { return document.getElementById(id); }
  function kb(n) { return n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB'; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function css() {
    if (el('edu-mlb-css')) return;
    var st = document.createElement('style'); st.id = 'edu-mlb-css';
    st.textContent = '#edu-mlb .mlb-item{position:relative}#edu-mlb .mlb-item .mlb-tick{position:absolute;top:5px;right:5px;width:22px;height:22px;border-radius:50%;background:rgba(255,255,255,.9);border:2px solid #cbd5e1;display:grid;place-items:center;font-size:11px;color:transparent}'
      + '#edu-mlb .mlb-item.is-sel .mlb-tick{background:var(--primary,#7c3aed);border-color:var(--primary,#7c3aed);color:#fff}'
      + '#edu-mlb .mlb-badge{position:absolute;left:4px;right:4px;bottom:4px;padding:3px 5px;border-radius:4px;background:rgba(17,24,39,.82);color:#fff;font-size:10.5px;line-height:1.3;text-align:left}'
      + '#edu-mlb .mlb-badge b{color:#86efac}#edu-mlb .mlb-badge.is-busy{background:rgba(124,58,237,.88)}#edu-mlb .mlb-badge.is-bad{background:rgba(185,28,28,.9)}'
      + '#edu-mlb .mlb-item.is-up img{opacity:.75}#edu-mlb .mlb-conv{margin-top:8px;padding:8px 10px;border-radius:5px;background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;font-size:12.5px}';
    document.head.appendChild(st);
  }
  function build() {
    if (box) return;
    css();
    box = document.createElement('div');
    box.className = 'mlb-overlay'; box.hidden = true; box.id = 'edu-mlb';
    box.innerHTML = '<div class="mlb-box"><div class="mlb-head"><h3><i class="fas fa-images"></i> Media Library</h3><button type="button" class="mlb-x" data-close>✕</button></div>' +
      '<div class="mlb-main"><div class="mlb-left"><div class="mlb-tools"><input type="text" class="form-control" id="edu-mlb-q" placeholder="Search images…"><label class="btn btn-secondary" style="margin:0;cursor:pointer"><i class="fas fa-upload"></i> <span id="edu-mlb-upl">Upload</span><input type="file" accept="image/*" id="edu-mlb-up" hidden></label></div>' +
      '<div class="mlb-grid" id="edu-mlb-grid"></div><div class="mlb-pager" id="edu-mlb-pager"></div></div>' +
      '<div class="mlb-right" id="edu-mlb-detail"><div class="mlb-empty"><i class="fas fa-hand-pointer"></i>Select an image to view &amp; edit details</div></div></div>' +
      '<div class="mlb-foot"><span id="edu-mlb-name">No image selected</span><div><button type="button" class="btn btn-secondary" data-close>Cancel</button> <button type="button" class="btn btn-primary" id="edu-mlb-use" disabled><i class="fas fa-check"></i> <span>Use This Image</span></button></div></div></div>';
    document.body.appendChild(box);
    box.addEventListener('click', function (e) { if (e.target === box || e.target.closest('[data-close]')) close(); });
    el('edu-mlb-q').addEventListener('input', function () { clearTimeout(state.timer); state.timer = setTimeout(function () { load(1); }, 300); });
    el('edu-mlb-pager').addEventListener('click', function (e) { var b = e.target.closest('[data-p]'); if (b) load(+b.dataset.p); });
    el('edu-mlb-use').addEventListener('click', function () {
      if (!state.sel.length || !state.cb) return;
      var cb = state.cb, out = state.multi ? state.sel.slice() : state.sel[0];
      close(); cb(out);
    });
    el('edu-mlb-up').addEventListener('change', function () {
      var files = [].slice.call(this.files || []).filter(function (f) { return /^image\//.test(f.type); }); this.value = '';
      if (files.length) uploadAll(files);
    });
  }
  function close() { box.hidden = true; document.body.style.overflow = ''; }
  function foot() {
    var n = state.sel.length, use = el('edu-mlb-use');
    use.disabled = !n;
    use.querySelector('span').textContent = state.multi ? (n > 1 ? 'Use these ' + n + ' images' : 'Use this image') : 'Use This Image';
    el('edu-mlb-name').textContent = !n ? (state.multi ? 'Click images to select several' : 'No image selected') : (n === 1 ? 'Selected: ' + state.sel[0].path.split('/').pop() : n + ' images selected');
  }
  function isSel(it) { return state.sel.some(function (x) { return x.id === it.id; }); }
  function toggle(it, tile, force) {
    var on = force !== undefined ? force : (state.multi ? !isSel(it) : true);
    if (!state.multi) { state.sel = []; el('edu-mlb-grid').querySelectorAll('.mlb-item').forEach(function (x) { x.classList.remove('is-sel'); }); }
    state.sel = state.sel.filter(function (x) { return x.id !== it.id; });
    if (on) state.sel.push(it);
    if (tile) tile.classList.toggle('is-sel', on);
    if (on) detail(it);
    foot();
  }
  function tileFor(it) {
    var c = document.createElement('button'); c.type = 'button'; c.className = 'mlb-item' + (isSel(it) ? ' is-sel' : '');
    c.innerHTML = '<img loading="lazy" alt="" src="' + esc(it.thumb) + '">' + (state.multi ? '<span class="mlb-tick">✓</span>' : '') + (state.fresh[it.id] ? '<span class="mlb-badge">' + state.fresh[it.id] + '</span>' : '');
    c.onclick = function () { toggle(it, c); };
    return c;
  }
  function load(page) {
    state.page = page;
    var g = el('edu-mlb-grid'); g.innerHTML = '<div class="mlb-msg"><i class="fas fa-spinner fa-spin"></i></div>';
    fetch('/admin/api/media-library.php?page=' + page + '&q=' + encodeURIComponent(el('edu-mlb-q').value.trim()), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); }).then(function (d) {
        g.innerHTML = '';
        if (!d.items || !d.items.length) g.innerHTML = '<div class="mlb-msg"><i class="fas fa-image"></i>No images found</div>';
        (d.items || []).forEach(function (it) { g.appendChild(tileFor(it)); });
        el('edu-mlb-pager').innerHTML = d.pages > 1 ? '<button type="button" class="btn-action btn-edit" ' + (page <= 1 ? 'disabled' : '') + ' data-p="' + (page - 1) + '">« Prev</button><span>Page ' + page + ' of ' + d.pages + '</span><button type="button" class="btn-action btn-edit" ' + (page >= d.pages ? 'disabled' : '') + ' data-p="' + (page + 1) + '">Next »</button>' : '';
      });
  }
  // files from the device: each one shows at once (its own preview) and is uploaded → WebP, one after another
  function uploadAll(files) {
    var g = el('edu-mlb-grid'), empty = g.querySelector('.mlb-msg'); if (empty) empty.remove();
    var tiles = files.map(function (f) {
      var c = document.createElement('button'); c.type = 'button'; c.className = 'mlb-item is-up';
      c.innerHTML = '<img alt="" src="' + URL.createObjectURL(f) + '"><span class="mlb-badge is-busy"><i class="fas fa-spinner fa-spin"></i> Converting to WebP…<br>' + esc(kb(f.size)) + '</span>';
      g.insertBefore(c, g.firstChild); return c;
    });
    var i = 0, ok = 0;
    el('edu-mlb-upl').textContent = 'Uploading 0/' + files.length;
    (function next() {
      if (i >= files.length) { el('edu-mlb-upl').textContent = 'Upload'; return; }
      var f = files[i], c = tiles[i], fd = new FormData(); fd.append('file', f); if (state.purpose) fd.append('purpose', state.purpose);
      fetch('/admin/api/media-upload.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
        var b = c.querySelector('.mlb-badge');
        if (d.error) { b.className = 'mlb-badge is-bad'; b.textContent = d.error; return; }
        ok++;
        var info = (d.webp ? '<b>WebP</b> · ' + kb(d.size) + (d.orig_size ? ' (was ' + kb(d.orig_size) + ')' : '') : kb(d.size || f.size)) + (d.w ? '<br>' + d.w + ' × ' + d.h + ' px' : '');
        state.fresh[d.id] = info;
        var it = { id: d.id, path: d.path, thumb: d.thumb || d.path, alt: d.alt || '', info: info };
        c.classList.remove('is-up'); b.className = 'mlb-badge'; b.innerHTML = info;
        if (state.multi) { var t = document.createElement('span'); t.className = 'mlb-tick'; t.textContent = '✓'; c.appendChild(t); }
        c.onclick = function () { toggle(it, c); };
        toggle(it, c, true); // a new upload is selected straight away
      }).catch(function () { var b = c.querySelector('.mlb-badge'); b.className = 'mlb-badge is-bad'; b.textContent = 'Upload failed'; })
        .finally(function () { i++; el('edu-mlb-upl').textContent = i < files.length ? 'Uploading ' + i + '/' + files.length : 'Upload'; next(); });
    })();
  }
  function detail(it) {
    var d = el('edu-mlb-detail');
    d.innerHTML = '<img class="mlb-prev" alt="" src="' + esc(it.path) + '">' + (state.fresh[it.id] ? '<div class="mlb-conv"><i class="fas fa-circle-check"></i> Converted: ' + state.fresh[it.id].replace(/<br>/g, ' · ') + '</div>' : '')
      + '<div class="form-group"><label>Alt Text</label><input class="form-control" id="edu-mlb-alt"></div><span class="mlb-saved" id="edu-mlb-saved"></span><div class="form-group"><label>File URL</label><input class="form-control" readonly value="' + esc(location.origin + it.path) + '"></div>';
    var a = el('edu-mlb-alt'), t; a.value = it.alt || '';
    a.addEventListener('input', function () { clearTimeout(t); t = setTimeout(function () {
      var fd = new FormData(); fd.append('id', it.id); fd.append('alt_text', a.value);
      fetch('/admin/api/media-library.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function () { it.alt = a.value; el('edu-mlb-saved').textContent = 'Saved'; });
    }, 500); });
  }
  window.EduMedia = {
    pick: function (cb, opt) {
      opt = opt || {}; build();
      state.cb = cb; state.sel = []; state.multi = !!opt.multiple; state.purpose = opt.purpose || '';
      var up = el('edu-mlb-up'); if (state.multi) up.setAttribute('multiple', ''); else up.removeAttribute('multiple');
      el('edu-mlb-detail').innerHTML = '<div class="mlb-empty"><i class="fas fa-hand-pointer"></i>' + (state.multi ? 'Click images to select several — or Upload many at once' : 'Select an image to view &amp; edit details') + '</div>';
      foot(); box.hidden = false; document.body.style.overflow = 'hidden'; load(1);
    },
    upload: function (file, cb) {
      var fd = new FormData(); fd.append('file', file);
      fetch('/admin/api/media-upload.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) { if (d.error) alert(d.error); else cb(d); }).catch(function () { alert('Upload failed.'); });
    }
  };
})();
