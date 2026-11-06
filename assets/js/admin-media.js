/* EduMedia.pick(callback) — the Media Library popup (pick an uploaded image or upload a new one).
   callback receives {id, path, alt}. Used by General Settings, Customize and other admin pages. */
(function () {
  var box, state = { page: 1, sel: null, cb: null, timer: null };
  function el(id) { return document.getElementById(id); }
  function build() {
    if (box) return;
    box = document.createElement('div');
    box.className = 'mlb-overlay'; box.hidden = true; box.id = 'edu-mlb';
    box.innerHTML = '<div class="mlb-box"><div class="mlb-head"><h3><i class="fas fa-images"></i> Media Library</h3><button type="button" class="mlb-x" data-close>✕</button></div>' +
      '<div class="mlb-main"><div class="mlb-left"><div class="mlb-tools"><input type="text" class="form-control" id="edu-mlb-q" placeholder="Search images…"><label class="btn btn-secondary" style="margin:0;cursor:pointer"><i class="fas fa-upload"></i> Upload<input type="file" accept="image/*" id="edu-mlb-up" hidden></label></div>' +
      '<div class="mlb-grid" id="edu-mlb-grid"></div><div class="mlb-pager" id="edu-mlb-pager"></div></div>' +
      '<div class="mlb-right" id="edu-mlb-detail"><div class="mlb-empty"><i class="fas fa-hand-pointer"></i>Select an image to view &amp; edit details</div></div></div>' +
      '<div class="mlb-foot"><span id="edu-mlb-name">No image selected</span><div><button type="button" class="btn btn-secondary" data-close>Cancel</button> <button type="button" class="btn btn-primary" id="edu-mlb-use" disabled><i class="fas fa-check"></i> Use This Image</button></div></div></div>';
    document.body.appendChild(box);
    box.addEventListener('click', function (e) { if (e.target === box || e.target.closest('[data-close]')) close(); });
    el('edu-mlb-q').addEventListener('input', function () { clearTimeout(state.timer); state.timer = setTimeout(function () { load(1); }, 300); });
    el('edu-mlb-pager').addEventListener('click', function (e) { var b = e.target.closest('[data-p]'); if (b) load(+b.dataset.p); });
    el('edu-mlb-use').addEventListener('click', function () { if (state.sel && state.cb) state.cb(state.sel); close(); });
    el('edu-mlb-up').addEventListener('change', function () {
      var f = this.files[0]; this.value = ''; if (!f) return;
      var fd = new FormData(); fd.append('file', f);
      el('edu-mlb-name').textContent = 'Uploading…';
      fetch('/admin/api/media-upload.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
        if (d.error) { alert(d.error); el('edu-mlb-name').textContent = 'No image selected'; return; }
        state.sel = { id: d.id, path: d.path, thumb: d.path, alt: '' }; load(1, true);
      }).catch(function () { alert('Upload failed.'); });
    });
  }
  function close() { box.hidden = true; document.body.style.overflow = ''; }
  function load(page, selectFirst) {
    state.page = page;
    var g = el('edu-mlb-grid'); g.innerHTML = '<div class="mlb-msg"><i class="fas fa-spinner fa-spin"></i></div>';
    fetch('/admin/api/media-library.php?page=' + page + '&q=' + encodeURIComponent(el('edu-mlb-q').value.trim()), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); }).then(function (d) {
        g.innerHTML = '';
        if (!d.items || !d.items.length) g.innerHTML = '<div class="mlb-msg"><i class="fas fa-image"></i>No images found</div>';
        (d.items || []).forEach(function (it) {
          var c = document.createElement('button'); c.type = 'button'; c.className = 'mlb-item';
          c.innerHTML = '<img loading="lazy" alt="" src="' + it.thumb + '">';
          c.onclick = function () { g.querySelectorAll('.mlb-item').forEach(function (x) { x.classList.toggle('is-sel', x === c); }); pick(it); };
          g.appendChild(c);
          if (selectFirst && state.sel && state.sel.id === it.id) { c.classList.add('is-sel'); pick(it); }
        });
        el('edu-mlb-pager').innerHTML = d.pages > 1 ? '<button type="button" class="btn-action btn-edit" ' + (page <= 1 ? 'disabled' : '') + ' data-p="' + (page - 1) + '">« Prev</button><span>Page ' + page + ' of ' + d.pages + '</span><button type="button" class="btn-action btn-edit" ' + (page >= d.pages ? 'disabled' : '') + ' data-p="' + (page + 1) + '">Next »</button>' : '';
      });
  }
  function pick(it) {
    state.sel = it; el('edu-mlb-use').disabled = false;
    el('edu-mlb-name').textContent = 'Selected: ' + it.path.split('/').pop();
    var d = el('edu-mlb-detail');
    d.innerHTML = '<img class="mlb-prev" alt="" src="' + it.path + '"><div class="form-group"><label>Alt Text</label><input class="form-control" id="edu-mlb-alt"></div><span class="mlb-saved" id="edu-mlb-saved"></span><div class="form-group"><label>File URL</label><input class="form-control" readonly value="' + location.origin + it.path + '"></div>';
    var a = el('edu-mlb-alt'), t; a.value = it.alt || '';
    a.addEventListener('input', function () { clearTimeout(t); t = setTimeout(function () {
      var fd = new FormData(); fd.append('id', it.id); fd.append('alt_text', a.value);
      fetch('/admin/api/media-library.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function () { it.alt = a.value; el('edu-mlb-saved').textContent = 'Saved'; });
    }, 500); });
  }
  window.EduMedia = {
    pick: function (cb) { build(); state.cb = cb; state.sel = null; el('edu-mlb-use').disabled = true; el('edu-mlb-name').textContent = 'No image selected'; box.hidden = false; document.body.style.overflow = 'hidden'; load(1); },
    upload: function (file, cb) {
      var fd = new FormData(); fd.append('file', file);
      fetch('/admin/api/media-upload.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) { if (d.error) alert(d.error); else cb(d); }).catch(function () { alert('Upload failed.'); });
    }
  };
})();
