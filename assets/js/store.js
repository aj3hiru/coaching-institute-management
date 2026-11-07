/* Online store: Buy (Razorpay Checkout), lesson "Mark as done", moving watermark on videos, PDF reader (pdf.js),
   show/hide password. */
(function () {
  'use strict';
  function post(url, data) {
    var fd = new FormData();
    Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) {
      return r.json().catch(function () { return { error: 'Unexpected reply from the server (' + r.status + ').' }; });
    });
  }
  function loadScript(src) {
    return new Promise(function (ok, bad) {
      if (window.Razorpay) return ok();
      var s = document.createElement('script'); s.src = src; s.onload = ok; s.onerror = function () { s.remove(); bad(new Error('Could not load the payment window. Check your internet and try again.')); };
      document.head.appendChild(s);
    });
  }

  // ---- Buy ----
  var buy = document.querySelector('[data-buy]');
  if (buy) {
    var msg = document.getElementById('stBuyMsg'), label = buy.innerHTML;
    var say = function (t, kind) { msg.textContent = t; msg.className = 'st-msg ' + (kind || 'err'); msg.hidden = !t; };
    var busy = function (on, t) { buy.disabled = on; if (on) buy.textContent = t || 'Please wait…'; else buy.innerHTML = label; };
    buy.addEventListener('click', function () {
      say(''); busy(true);
      var tok = buy.getAttribute('data-t');
      post('/store/checkout', { a: 'create', p: buy.getAttribute('data-buy'), _t: tok }).then(function (d) {
        if (d.login) { location.href = '/account/login?next=' + encodeURIComponent(location.pathname + location.search); return; }
        if (d.error) { busy(false); say(d.error); return; }
        if (d.owned || d.free) { location.href = d.go; return; }
        return loadScript('https://checkout.razorpay.com/v1/checkout.js').then(function () {
          var b = window.ST_BUYER || {}, paid = false;
          var rz = new window.Razorpay({
            key: d.key, order_id: d.rzp_order, amount: d.amount, currency: 'INR', name: window.ST_SITE || document.title, description: d.title,
            prefill: { name: b.name || '', email: b.email || '', contact: b.contact || '' },
            theme: { color: (getComputedStyle(document.querySelector('.st')).getPropertyValue('--sa') || '').trim() || '#0c6878' },
            handler: function (r) {
              paid = true; busy(true, 'Confirming payment…');
              post('/store/checkout', { a: 'verify', order: d.order, razorpay_order_id: r.razorpay_order_id, razorpay_payment_id: r.razorpay_payment_id, razorpay_signature: r.razorpay_signature, _t: tok })
                .then(function (v) { if (v.ok) { location.href = v.go; } else { busy(false); say(v.error || 'The payment could not be confirmed.'); } })
                .catch(function () { busy(false); say('Network problem while confirming. If money was taken, the course will appear in your library shortly.'); });
            },
            modal: { ondismiss: function () { if (paid) return; busy(false); post('/store/checkout', { a: 'cancel', order: d.order, why: 'Closed by buyer', _t: tok }); } }
          });
          rz.on('payment.failed', function (r) { say((r && r.error && r.error.description ? r.error.description + ' ' : 'The payment failed. ') + 'You can try again.'); });
          rz.open();
        });
      }).catch(function (e) { busy(false); say(e && e.message ? e.message : 'Could not start the payment. Please try again.'); });
    });
  }

  // ---- Mark as done ----
  var doneBtn = document.getElementById('stDone');
  if (doneBtn) doneBtn.addEventListener('click', function () {
    var on = doneBtn.getAttribute('data-done') !== '1';
    doneBtn.disabled = true;
    post('/store/progress', { l: doneBtn.getAttribute('data-l'), done: on ? '1' : '0', _t: doneBtn.getAttribute('data-t') }).then(function (d) {
      doneBtn.disabled = false;
      if (!d.ok) return;
      doneBtn.setAttribute('data-done', on ? '1' : '0');
      doneBtn.querySelector('span').textContent = on ? 'Completed' : 'Mark as done';
      doneBtn.classList.toggle('is-done', on);
      var li = document.querySelector('.lp-li.on'); if (li) li.classList.toggle('done', on);
    });
  });
  var vid = document.querySelector('.st-player video');
  if (vid && doneBtn) vid.addEventListener('ended', function () { if (doneBtn.getAttribute('data-done') !== '1') doneBtn.click(); }); // a finished video counts as done

  // ---- moving watermark (buyer's mobile + email) ----
  var wm = document.getElementById('stWm'), pl = document.getElementById('stPlayer');
  if (wm && pl && vid) {
    var move = function () { var room = Math.max(4, 92 - wm.offsetWidth * 100 / Math.max(1, pl.offsetWidth)); wm.style.left = (3 + Math.random() * room) + '%'; wm.style.top = (6 + Math.random() * 80) + '%'; };
    move(); setInterval(move, 9000);
    // full screen of the video alone would hide the watermark: use full screen of the player box instead
    vid.addEventListener('dblclick', function (e) { e.preventDefault(); });
    var fs = function () {
      var f = document.fullscreenElement || document.webkitFullscreenElement;
      if (f === vid && pl.requestFullscreen) { (document.exitFullscreen || document.webkitExitFullscreen).call(document).then(function () { pl.requestFullscreen().catch(function () {}); }).catch(function () {}); }
    };
    document.addEventListener('fullscreenchange', fs);
  }

  // ---- PDF reader (pages drawn on the screen with the buyer's watermark) ----
  var box = document.getElementById('stPdf');
  if (box) {
    var info = document.getElementById('stPdfInfo'), tries = 0;
    var start = function () {
      if (!window.pdfjsLib) { if (++tries < 100) setTimeout(start, 150); else info.textContent = 'The reader could not load. Check your internet.'; return; }
      window.pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
      window.pdfjsLib.getDocument({ url: box.getAttribute('data-src') }).promise.then(function (doc) {
        info.textContent = doc.numPages + ' page' + (doc.numPages === 1 ? '' : 's');
        var wmText = box.getAttribute('data-wm');
        var draw = function (c) {
          doc.getPage(+c.getAttribute('data-p')).then(function (pg) {
            var w = box.clientWidth || 800, vp1 = pg.getViewport({ scale: 1 }), dpr = Math.min(window.devicePixelRatio || 1, 2);
            var vp = pg.getViewport({ scale: (w / vp1.width) * dpr });
            c.width = vp.width; c.height = vp.height; c.style.aspectRatio = '';
            var ctx = c.getContext('2d');
            pg.render({ canvasContext: ctx, viewport: vp }).promise.then(function () {
              if (!wmText) return;
              ctx.save(); ctx.globalAlpha = 0.12; ctx.fillStyle = '#000'; ctx.font = 'bold ' + Math.round(16 * dpr) + 'px Arial';
              ctx.translate(c.width / 2, c.height / 2); ctx.rotate(-Math.PI / 6);
              var tw = ctx.measureText(wmText).width;
              for (var y = -c.height; y < c.height; y += 170 * dpr) ctx.fillText(wmText, -tw / 2, y);
              ctx.restore();
            });
          });
        };
        var io = 'IntersectionObserver' in window ? new IntersectionObserver(function (es) { es.forEach(function (e) { if (e.isIntersecting) { io.unobserve(e.target); draw(e.target); } }); }, { rootMargin: '800px' }) : null;
        for (var i = 1; i <= doc.numPages; i++) {
          var c = document.createElement('canvas'); c.setAttribute('data-p', i); c.className = 'st-pdfp';
          box.appendChild(c);
          if (io) io.observe(c); else draw(c);
        }
      }).catch(function () { info.textContent = ''; box.innerHTML = '<div class="st-empty">This PDF could not be opened. Please reload the page.</div>'; });
    };
    start();
  }

  // ---- show / hide password ----
  document.querySelectorAll('.st-pw button').forEach(function (b) {
    b.addEventListener('click', function () { var i = b.parentNode.querySelector('input'); var show = i.type === 'password'; i.type = show ? 'text' : 'password'; b.textContent = show ? 'Hide' : 'Show'; b.setAttribute('aria-pressed', show ? 'true' : 'false'); });
  });
})();
/* Lesson player ⚙ menu: speed 0.5x–2x and quality (Auto picks by internet speed and player size; drops a step when the
   video keeps stopping to load). Switching quality keeps the time and play state. */
(function () {
  'use strict';
  var v = document.getElementById('stVideo'), g = document.querySelector('.st-gear');
  if (!v || !g) return;
  var btn = g.querySelector('.st-gear-b'), menu = g.querySelector('.st-menu'), lab = document.getElementById('stGearL');
  var srcs = []; try { srcs = JSON.parse(v.getAttribute('data-srcs') || '[]'); } catch (e) {}
  var qBox = menu.querySelector('[data-quality]'), sBox = menu.querySelector('[data-speed]');
  if (srcs.length < 2) { qBox.previousElementSibling.hidden = true; qBox.hidden = true; } // only one file: no quality choice
  var mode = 'auto', cur = srcs[0] ? srcs[0].q : 'orig', speed = 1;
  function store(k, val) { try { localStorage.setItem(k, val); } catch (e) {} }
  function read(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
  function mark(box, val) { box.querySelectorAll('button').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-v') === String(val)); }); }
  function label() { var s = srcs.filter(function (x) { return x.q === cur; })[0]; lab.textContent = (speed !== 1 ? speed + 'x' : '1x') + (srcs.length > 1 && s ? ' · ' + (mode === 'auto' ? 'Auto ' : '') + s.label : ''); }
  function setSpeed(x) { speed = x; v.playbackRate = x; mark(sBox, String(x)); store('st_speed', String(x)); label(); }
  function pickAuto() {
    var c = navigator.connection || {}, mbps = c.downlink || 10, h = Math.round((v.clientHeight || 360) * Math.min(window.devicePixelRatio || 1, 2));
    if (c.saveData) mbps = Math.min(mbps, 1);
    var want = mbps < 1.2 ? 360 : mbps < 2.5 ? 480 : mbps < 5 ? 720 : 9999;
    want = Math.min(want, Math.max(360, h));
    var list = srcs.slice().sort(function (a, b) { return (a.h || 9999) - (b.h || 9999); });
    var ok = list.filter(function (x) { return (x.h || 9999) <= want; });
    return (ok.length ? ok[ok.length - 1] : list[0]).q;
  }
  function useQ(q) {
    var s = srcs.filter(function (x) { return x.q === q; })[0];
    if (!s || q === cur && v.currentSrc) { cur = q; label(); return; }
    var t = v.currentTime, playing = !v.paused && !v.ended;
    cur = q; v.src = s.src; v.load();
    v.addEventListener('loadedmetadata', function once() { v.removeEventListener('loadedmetadata', once); try { v.currentTime = t; } catch (e) {} v.playbackRate = speed; if (playing) v.play().catch(function () {}); }, { once: true });
    label();
  }
  // menu
  btn.addEventListener('click', function (e) { e.stopPropagation(); menu.hidden = !menu.hidden; btn.setAttribute('aria-expanded', menu.hidden ? 'false' : 'true'); });
  document.addEventListener('click', function (e) { if (!menu.hidden && !g.contains(e.target)) { menu.hidden = true; btn.setAttribute('aria-expanded', 'false'); } });
  function closeMenu() { menu.hidden = true; btn.setAttribute('aria-expanded', 'false'); }
  sBox.addEventListener('click', function (e) { var b = e.target.closest('button'); if (b) { setSpeed(parseFloat(b.getAttribute('data-v'))); closeMenu(); } });
  qBox.addEventListener('click', function (e) {
    var b = e.target.closest('button'); if (!b) return;
    mode = b.getAttribute('data-v') === 'auto' ? 'auto' : 'fixed'; mark(qBox, b.getAttribute('data-v'));
    store('st_quality', b.getAttribute('data-v'));
    useQ(mode === 'auto' ? pickAuto() : b.getAttribute('data-v'));
    closeMenu();
  });
  v.addEventListener('ratechange', function () { if (Math.abs(v.playbackRate - speed) > 0.01) { speed = v.playbackRate; mark(sBox, String(speed)); label(); } }); // the browser's own speed menu
  // Auto: step down when the video keeps waiting for data
  var stalls = [];
  v.addEventListener('waiting', function () {
    if (mode !== 'auto' || srcs.length < 2 || v.currentTime < 1) return;
    var now = Date.now(); stalls = stalls.filter(function (t) { return now - t < 30000; }); stalls.push(now);
    if (stalls.length < 3) return;
    stalls = [];
    var list = srcs.slice().sort(function (a, b) { return (a.h || 9999) - (b.h || 9999); });
    var i = list.map(function (x) { return x.q; }).indexOf(cur);
    if (i > 0) useQ(list[i - 1].q);
  });
  // start with the saved choices
  var sp = parseFloat(read('st_speed') || '1'); if ([0.5, 0.75, 1, 1.25, 1.5, 1.75, 2].indexOf(sp) < 0) sp = 1;
  var sq = read('st_quality');
  if (sq && sq !== 'auto' && srcs.some(function (x) { return x.q === sq; })) { mode = 'fixed'; mark(qBox, sq); useQ(sq); }
  else if (srcs.length > 1) { var a = pickAuto(); if (a !== cur) useQ(a); }
  setSpeed(sp);
  v.addEventListener('loadedmetadata', function () { v.playbackRate = speed; });
  // keyboard: < and > change speed (like YouTube)
  document.addEventListener('keydown', function (e) {
    if (/INPUT|TEXTAREA|SELECT/.test((e.target || {}).tagName || '')) return;
    var steps = [0.5, 0.75, 1, 1.25, 1.5, 1.75, 2], i = steps.indexOf(speed);
    if (e.key === '>' && i < steps.length - 1) setSpeed(steps[i + 1]);
    if (e.key === '<' && i > 0) setSpeed(steps[i - 1]);
  });
})();
/* product page: "Show more" for a long description, "Expand all" chapters */
(function () {
  document.querySelectorAll('[data-more]').forEach(function (b) {
    var box = document.getElementById(b.getAttribute('data-more')); if (!box) return;
    if (box.scrollHeight > 190) { box.classList.add('is-clamped'); b.hidden = false; }
    b.addEventListener('click', function () { var c = box.classList.toggle('is-clamped'); b.textContent = c ? 'Show more' : 'Show less'; });
  });
})();
/* phones: the bottom Buy bar hides while the buy card's own button is on screen */
(function () {
  var bar = document.querySelector('.st-mbar'), btn = document.querySelector('.sp-card .sp-btn');
  if (!bar || !btn || !('IntersectionObserver' in window)) return;
  new IntersectionObserver(function (es) { bar.classList.toggle('is-off', es[0].isIntersecting); }).observe(btn);
})();

/* product page: section strip (smooth scroll + the tab of the section on screen), smooth chapter accordion,
   all reviews, "Rate this course" */
(function () {
  'use strict';
  var tabs = document.getElementById('spTabs');
  var offset = function () { return (tabs ? tabs.offsetHeight : 0) + 12; };
  function go(id) { var el = document.getElementById(id); if (!el) return; window.scrollTo({ top: el.getBoundingClientRect().top + window.pageYOffset - offset(), behavior: 'smooth' }); }
  if (tabs) {
    var links = [].slice.call(tabs.querySelectorAll('a[data-tab]'));
    var mark = function (id) { links.forEach(function (a) { var on = a.getAttribute('data-tab') === id; a.classList.toggle('on', on); if (on && a.scrollIntoView && tabs.scrollWidth > tabs.clientWidth) a.parentNode.scrollTo({ left: a.offsetLeft - 20, behavior: 'smooth' }); }); };
    links.forEach(function (a) { a.addEventListener('click', function (e) { e.preventDefault(); var id = a.getAttribute('data-tab'); mark(id); go(id); history.replaceState(null, '', '#' + id); }); });
    var secs = links.map(function (a) { return document.getElementById(a.getAttribute('data-tab')); }).filter(Boolean);
    var onScroll = function () {
      var y = offset() + 40, cur = secs[0] && secs[0].id;
      secs.forEach(function (s) { if (s.getBoundingClientRect().top <= y) cur = s.id; });
      if ((window.innerHeight + window.pageYOffset) >= document.body.scrollHeight - 4 && secs.length) cur = secs[secs.length - 1].id;
      mark(cur);
      tabs.classList.toggle('is-stuck', tabs.getBoundingClientRect().top <= 1);
    };
    window.addEventListener('scroll', onScroll, { passive: true }); onScroll();
    document.querySelectorAll('a[href="#reviews"], a[href="#content"]').forEach(function (a) { if (!a.hasAttribute('data-tab')) a.addEventListener('click', function (e) { e.preventDefault(); go(a.getAttribute('href').slice(1)); }); });
    if (location.hash && document.getElementById(location.hash.slice(1))) setTimeout(function () { go(location.hash.slice(1)); }, 150);
  }
  // smooth accordion
  function setOpen(ch, open, quick) {
    var body = ch.querySelector('.sp-ch-b'), h = ch.querySelector('.sp-ch-h');
    if (!body) return;
    h.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (quick) { ch.classList.toggle('open', open); body.style.height = open ? 'auto' : '0px'; return; }
    var from = body.offsetHeight; ch.classList.toggle('open', open);
    body.style.height = 'auto'; var to = open ? body.scrollHeight : 0;
    body.style.height = from + 'px'; body.offsetHeight; // reflow
    body.style.height = to + 'px';
    var end = function (e) { if (e.target !== body) return; body.removeEventListener('transitionend', end); if (ch.classList.contains('open')) body.style.height = 'auto'; };
    body.addEventListener('transitionend', end);
  }
  var chs = [].slice.call(document.querySelectorAll('.sp-acc .sp-ch'));
  chs.forEach(function (ch) {
    setOpen(ch, ch.classList.contains('open'), true);
    var h = ch.querySelector('.sp-ch-h'); if (h) h.addEventListener('click', function () { setOpen(ch, !ch.classList.contains('open')); syncEx(); });
  });
  var ex = document.querySelector('[data-expand]');
  function syncEx() { if (ex) ex.textContent = chs.every(function (c) { return c.classList.contains('open'); }) ? 'Collapse all' : 'Expand all'; }
  if (ex) { ex.addEventListener('click', function () { var openAll = chs.some(function (c) { return !c.classList.contains('open'); }); chs.forEach(function (c) { setOpen(c, openAll); }); syncEx(); }); syncEx(); }
  // reviews
  var all = document.querySelector('[data-allrv]');
  if (all) all.addEventListener('click', function () { document.querySelectorAll('.sp-rv.is-more').forEach(function (r) { r.classList.remove('is-more'); }); all.remove(); });
  var w = document.querySelector('[data-write]');
  if (w) w.addEventListener('click', function (e) { e.preventDefault(); go('reviews'); var f = document.getElementById('spRvForm'); if (f) setTimeout(function () { f.classList.add('flash'); var r = f.querySelector('input[type=radio]'); if (r) r.focus(); }, 500); });
})();
