/* Online store: Buy (Razorpay Checkout), lesson "Mark as done", PDF reader (pdf.js),
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
        if (d.demo) { demoCheckout(d, tok); return; }
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

  // ---- Demo checkout (Store Settings → Payment mode → Demo): a practice payment window, no real money ----
  function demoCheckout(d, tok) {
    var rs = '₹' + (d.amount / 100).toLocaleString('en-IN', { maximumFractionDigits: 2 }), b = window.ST_BUYER || {};
    var w = document.createElement('div'); w.className = 'dpay'; w.setAttribute('role', 'dialog'); w.setAttribute('aria-modal', 'true'); w.setAttribute('aria-label', 'Demo payment');
    var e = function (t) { return String(t || '').replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
    w.innerHTML = '<div class="dpay-box"><div class="dpay-top"><div><small>' + e(window.ST_SITE || document.title) + '</small><b>' + e(d.title) + '</b></div><button type="button" class="dpay-x" aria-label="Close">×</button></div>'
      + '<div class="dpay-demo">DEMO PAYMENT · test mode — no real money is taken</div>'
      + '<div class="dpay-amt"><span>Amount to pay</span><b>' + rs + '</b></div>'
      + (b.contact || b.email ? '<div class="dpay-who">' + e(b.name) + ' · ' + e(b.contact || b.email) + '</div>' : '')
      + '<div class="dpay-ways">' + [['upi', 'UPI', 'Google Pay, PhonePe, Paytm'], ['card', 'Card', 'Debit / credit card'], ['netbanking', 'Net banking', 'All Indian banks'], ['wallet', 'Wallet', 'Paytm, Mobikwik']].map(function (x, i) {
        return '<label class="dpay-way"><input type="radio" name="dpw" value="' + x[0] + '"' + (i ? '' : ' checked') + '><span><b>' + x[1] + '</b><small>' + x[2] + '</small></span></label>'; }).join('') + '</div>'
      + '<button type="button" class="dpay-pay">Pay ' + rs + '</button><button type="button" class="dpay-fail">Show a failed payment</button><p class="dpay-m" role="status"></p></div>';
    document.body.appendChild(w); document.documentElement.style.overflow = 'hidden';
    var m = w.querySelector('.dpay-m'), pay = w.querySelector('.dpay-pay'), done = false;
    var close = function () { w.remove(); document.documentElement.style.overflow = ''; busy(false); };
    w.querySelector('.dpay-x').addEventListener('click', function () { if (done) return; close(); post('/store/checkout', { a: 'cancel', order: d.order, why: 'Closed demo checkout', _t: tok }); });
    pay.addEventListener('click', function () {
      done = true; pay.disabled = true; pay.innerHTML = '<span class="dpay-spin"></span> Processing…'; m.textContent = '';
      setTimeout(function () {
        post('/store/checkout', { a: 'demo_pay', order: d.order, via: (w.querySelector('input[name=dpw]:checked') || {}).value || 'upi', _t: tok }).then(function (v) {
          if (!v.ok) { done = false; pay.disabled = false; pay.textContent = 'Pay ' + rs; m.textContent = v.error || 'Something went wrong.'; return; }
          w.querySelector('.dpay-box').innerHTML = '<div class="dpay-ok"><span class="dpay-tick"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5 10 17 19 7"/></svg></span><b>Payment successful</b><small>' + rs + ' · demo payment</small><p>Opening your course…</p></div>';
          setTimeout(function () { location.href = v.go; }, 1300);
        }).catch(function () { done = false; pay.disabled = false; pay.textContent = 'Pay ' + rs; m.textContent = 'Network problem. Please try again.'; });
      }, 900);
    });
    w.querySelector('.dpay-fail').addEventListener('click', function () {
      post('/store/checkout', { a: 'demo_fail', order: d.order, _t: tok }).then(function () { close(); say('The payment failed (demo). You can try again.'); });
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

  // ---- PDF reader (pages drawn on the screen); window.stPdf(box, src, wm, info) for more (wm is not used: no watermark) ----
  function loadPdfJs() {
    if (window.pdfjsLib) return Promise.resolve();
    if (!loadPdfJs.p) loadPdfJs.p = new Promise(function (ok, bad) { var s = document.createElement('script'); s.src = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js'; s.onload = ok; s.onerror = function () { loadPdfJs.p = null; bad(); }; document.head.appendChild(s); });
    return loadPdfJs.p;
  }
  window.stPdf = function (box, src, wmText, info) {
    var tries = 0;
    var start = function () {
      if (!window.pdfjsLib) { if (++tries < 100) setTimeout(start, 150); else if (info) info.textContent = 'The reader could not load. Check your internet.'; return; }
      window.pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
      wmText = ''; // no watermark
      window.pdfjsLib.getDocument({ url: src }).promise.then(function (doc) {
        if (info) { info.textContent = doc.numPages + ' page' + (doc.numPages === 1 ? '' : 's'); info.hidden = false; }
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
      }).catch(function () { if (info) info.textContent = ''; box.innerHTML = '<div class="st-empty">This PDF could not be opened. Please reload the page.</div>'; });
    };
    loadPdfJs().then(start, function () { box.innerHTML = '<div class="st-empty">The reader could not load. Check your internet.</div>'; });
  };
  var box = document.getElementById('stPdf');
  if (box && box.getAttribute('data-src')) window.stPdf(box, box.getAttribute('data-src'), box.getAttribute('data-wm'), document.getElementById('stPdfInfo'));

  // ---- show / hide password ----
  document.querySelectorAll('.st-pw button').forEach(function (b) {
    b.addEventListener('click', function () { var i = b.parentNode.querySelector('input'); var show = i.type === 'password'; i.type = show ? 'text' : 'password'; if (b.hasAttribute('data-eye')) { b.classList.toggle('on', show); b.setAttribute('aria-label', show ? 'Hide password' : 'Show password'); } else b.textContent = show ? 'Hide' : 'Show'; b.setAttribute('aria-pressed', show ? 'true' : 'false'); });
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
  if (!bar) return;
  // the bar starts hidden (class is-off in the page), so it never shows for a moment and slides away on load
  if (!btn || !('IntersectionObserver' in window)) { bar.classList.remove('is-off'); return; }
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
/* reviews slider: dots follow the scroll, dots jump, mouse drag on desktop (touch scrolls natively) */
(function () {
  var box = document.querySelector('.sp-rvs'); if (!box) return;
  var t = box.querySelector('.sp-rvs-track'), dots = box.querySelectorAll('.sp-rvs-dots button'), slides = t.children;
  function cur() { var x = t.scrollLeft, best = 0, d = 1e9; for (var i = 0; i < slides.length; i++) { var dd = Math.abs(slides[i].offsetLeft - t.offsetLeft - x); if (dd < d) { d = dd; best = i; } } if (t.scrollLeft + t.clientWidth >= t.scrollWidth - 4) best = slides.length - 1; return best; }
  function paint() { var c = cur(); dots.forEach(function (b, i) { b.classList.toggle('is-active', i === c); }); }
  // hide dots that can never be reached alone (when the last cards all fit on screen)
  function fit() { var max = t.scrollWidth - t.clientWidth; dots.forEach(function (b, i) { b.hidden = i > 0 && i < slides.length - 1 && slides[i].offsetLeft - t.offsetLeft > max + 4; }); var box2 = box.querySelector('.sp-rvs-dots'); if (box2) box2.hidden = max <= 4; paint(); }
  var raf = 0; t.addEventListener('scroll', function () { cancelAnimationFrame(raf); raf = requestAnimationFrame(paint); }, { passive: true });
  window.addEventListener('resize', fit); fit();
  dots.forEach(function (b, i) { b.addEventListener('click', function () { t.scrollTo({ left: slides[i].offsetLeft - t.offsetLeft, behavior: 'smooth' }); }); });
  var down = false, moved = false, sx = 0, sl = 0;
  t.addEventListener('mousedown', function (e) { down = true; moved = false; sx = e.pageX; sl = t.scrollLeft; });
  window.addEventListener('mousemove', function (e) { if (!down) return; var dx = e.pageX - sx; if (Math.abs(dx) > 5) { moved = true; t.classList.add('is-dragging'); t.scrollLeft = sl - dx; } });
  window.addEventListener('mouseup', function () { if (!down) return; down = false; if (moved) { t.classList.remove('is-dragging'); var c = cur(); t.scrollTo({ left: slides[c].offsetLeft - t.offsetLeft, behavior: 'smooth' }); } });
  t.addEventListener('dragstart', function (e) { e.preventDefault(); });
})();
/* FAQ: smooth open / close (the answer box grows and shrinks) */
(function () {
  document.querySelectorAll('.sp-faq details').forEach(function (d) {
    var sum = d.querySelector('summary'), box = d.querySelector('.sp-faq-b'); if (!sum || !box) return;
    sum.addEventListener('click', function (e) {
      e.preventDefault();
      if (d.dataset.busy) return; d.dataset.busy = '1';
      var opening = !d.open;
      if (opening) { d.open = true; d.classList.add('is-open'); var h = box.scrollHeight; box.style.height = '0px'; box.offsetHeight; box.style.height = h + 'px'; }
      else { d.classList.remove('is-open'); box.style.height = box.scrollHeight + 'px'; box.offsetHeight; box.style.height = '0px'; }
      var end = function (ev) { if (ev.target !== box) return; box.removeEventListener('transitionend', end); if (!opening) d.open = false; box.style.height = ''; delete d.dataset.busy; };
      box.addEventListener('transitionend', end);
      setTimeout(function () { if (d.dataset.busy) end({ target: box }); }, 450); // safety if no transition runs
    });
  });
})();
/* carousels ([data-car]): dots follow the scroll, dots jump, mouse drag on desktop */
(function () {
  document.querySelectorAll('[data-car]').forEach(function (box) {
    var t = box.querySelector('.st-car-track'), dots = box.querySelectorAll('.st-car-dots button'), slides = t.children;
    function cur() { var x = t.scrollLeft, best = 0, d = 1e9; for (var i = 0; i < slides.length; i++) { var dd = Math.abs(slides[i].offsetLeft - t.offsetLeft - x); if (dd < d) { d = dd; best = i; } } if (t.scrollLeft + t.clientWidth >= t.scrollWidth - 4) best = slides.length - 1; return best; }
    function paint() { var c = cur(); dots.forEach(function (b, i) { b.classList.toggle('is-active', i === c); }); }
    function fit() { var max = t.scrollWidth - t.clientWidth, w = box.querySelector('.st-car-dots'); if (w) w.hidden = max <= 4; dots.forEach(function (b, i) { b.hidden = i > 0 && i < slides.length - 1 && slides[i].offsetLeft - t.offsetLeft > max + 4; }); paint(); }
    var raf = 0; t.addEventListener('scroll', function () { cancelAnimationFrame(raf); raf = requestAnimationFrame(paint); }, { passive: true });
    window.addEventListener('resize', fit); fit();
    dots.forEach(function (b, i) { b.addEventListener('click', function () { t.scrollTo({ left: slides[i].offsetLeft - t.offsetLeft, behavior: 'smooth' }); }); });
    var down = false, moved = false, sx = 0, sl = 0;
    t.addEventListener('mousedown', function (e) { down = true; moved = false; sx = e.pageX; sl = t.scrollLeft; });
    window.addEventListener('mousemove', function (e) { if (!down) return; var dx = e.pageX - sx; if (Math.abs(dx) > 5) { moved = true; t.classList.add('is-dragging'); t.scrollLeft = sl - dx; } });
    window.addEventListener('mouseup', function () { if (!down) return; down = false; if (moved) { t.classList.remove('is-dragging'); var c = cur(); t.scrollTo({ left: slides[c].offsetLeft - t.offsetLeft, behavior: 'smooth' }); } });
    t.addEventListener('click', function (e) { if (moved) { e.preventDefault(); e.stopPropagation(); moved = false; } }, true);
    t.addEventListener('dragstart', function (e) { e.preventDefault(); });
  });
})();

/* lecture tabs: About · PDF Notes · VVI Questions · Quiz · Doubts & Problems */
(function () {
  'use strict';
  var lt = document.getElementById('lt'); if (!lt) return;
  var btns = lt.querySelectorAll('.lt-strip button'), panes = lt.querySelectorAll('.lt-pane');
  function show(k) {
    btns.forEach(function (b) { var on = b.getAttribute('data-lt') === k; b.classList.toggle('on', on); b.setAttribute('aria-selected', on ? 'true' : 'false'); if (on) { var st = b.parentNode, x = b.offsetLeft - st.offsetLeft; if (x < st.scrollLeft || x + b.offsetWidth + 150 > st.scrollLeft + st.clientWidth) st.scrollTo({ left: Math.max(0, x - 40), behavior: 'smooth' }); } });
    // keep the box as tall as before while switching, so the page below does not jump up (then it fits the new tab)
    var h = lt.offsetHeight; lt.style.minHeight = h + 'px';
    panes.forEach(function (p) { p.hidden = p.getAttribute('data-pane') !== k; });
    clearTimeout(lt._mh); lt._mh = setTimeout(function () { lt.style.minHeight = ''; }, 260);
    if (k === 'doubts') loadDoubts();
    if (k === 'discuss') loadDisc();
  }
  btns.forEach(function (b) { b.addEventListener('click', function () { show(b.getAttribute('data-lt')); }); });
  // notes: read inside the tab
  lt.querySelectorAll('.lt-note-h').forEach(function (h) {
    h.addEventListener('click', function () {
      var box = h.closest('.lt-note').querySelector('.lt-pdf'), open = box.hidden; box.hidden = !open; h.classList.toggle('open', open);
      if (open && !box.dataset.done) { box.dataset.done = '1'; window.stPdf(box, h.getAttribute('data-pdf'), h.getAttribute('data-wm'), null); }
    });
  });
  // quiz
  var qz = lt.querySelector('.lt-quiz');
  if (qz) {
    var key = []; try { key = JSON.parse(qz.getAttribute('data-quiz')); } catch (e) {}
    var chk = qz.querySelector('[data-check]'), retry = qz.querySelector('[data-retry]'), score = qz.querySelector('.lt-score');
    chk.addEventListener('click', function () {
      var right = 0, answered = 0;
      qz.querySelectorAll('.lt-q').forEach(function (q) {
        var n = +q.getAttribute('data-n'), pick = q.querySelector('input:checked'), k = key[n] || [0, ''];
        q.querySelectorAll('.lt-opt').forEach(function (o, i) { o.classList.toggle('right', i === k[0]); o.classList.toggle('wrong', !!pick && +pick.value === i && i !== k[0]); o.querySelector('input').disabled = true; });
        if (pick) { answered++; if (+pick.value === k[0]) right++; }
        var ex = q.querySelector('.lt-exp'); if (k[1]) { ex.textContent = k[1]; ex.hidden = false; }
      });
      score.textContent = 'You got ' + right + ' of ' + key.length + ' right' + (answered < key.length ? ' (' + (key.length - answered) + ' not answered)' : '') + (right === key.length ? ' — excellent!' : '');
      chk.hidden = true; retry.hidden = false;
    });
    retry.addEventListener('click', function () {
      qz.querySelectorAll('.lt-opt').forEach(function (o) { o.classList.remove('right', 'wrong'); var i = o.querySelector('input'); i.disabled = false; i.checked = false; });
      qz.querySelectorAll('.lt-exp').forEach(function (e) { e.hidden = true; }); score.textContent = ''; chk.hidden = false; retry.hidden = true;
    });
  }
  // doubts
  var db = document.getElementById('ltDoubts'), loaded = false;
  function esc(s) { return String(s || '').replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function badge(k, n) { var b = lt.querySelector('.lt-n[data-n=' + k + ']'); if (!b) return; b.setAttribute('data-c', n); b.textContent = n > 99 ? '99+' : n; b.hidden = !n; }
  function render(list) {
    var box = db.querySelector('.lt-dlist'); badge('doubts', list.length);
    if (!list.length) { box.innerHTML = '<p class="lt-dl-empty">No doubts yet.</p>'; return; }
    box.innerHTML = list.map(function (d) {
      return '<div class="lt-d' + (d.mine ? ' mine' : '') + '"><div class="lt-d-q"><span class="sp-av sp-av' + (d.id % 3) + '">' + esc(d.who.charAt(0)) + '</span><div><b>' + esc(d.who) + (d.mine ? ' <em>you</em>' : '') + '</b><small>' + esc(d.at) + '</small><p>' + esc(d.q).replace(/\n/g, '<br>') + '</p></div></div>'
        + (d.a ? '<div class="lt-d-a"><b>Teacher' + (d.by ? ' · ' + esc(d.by) : '') + '</b><p>' + esc(d.a).replace(/\n/g, '<br>') + '</p></div>' : '<div class="lt-d-w">Waiting for the teacher\'s answer</div>') + '</div>';
    }).join('');
  }
  function loadDoubts() {
    if (!db || loaded) return; loaded = true;
    fetch('/store/doubt?l=' + db.getAttribute('data-l'), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) { render(d.list || []); }).catch(function () { loaded = false; });
  }
  if (db) {
    var form = db.querySelector('.lt-ask'), ta = form.querySelector('textarea'), m = form.querySelector('.lt-ask-m'), b = form.querySelector('button');
    form.addEventListener('submit', function () {
      var q = ta.value.trim(); if (q.length < 5) { m.textContent = 'Please write your doubt.'; ta.focus(); return; }
      b.disabled = true; m.textContent = 'Sending…';
      var fd = new FormData(); fd.append('l', db.getAttribute('data-l')); fd.append('q', q); fd.append('_t', db.getAttribute('data-t'));
      fetch('/store/doubt', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
        b.disabled = false; if (d.error) { m.textContent = d.error; return; }
        ta.value = ''; m.textContent = 'Sent.'; render(d.list || []);
      }).catch(function () { b.disabled = false; m.textContent = 'Could not send. Please try again.'; });
    });
    if (lt.querySelector('.lt-strip button.on[data-lt=doubts]')) loadDoubts();
  }
  // discussion: everyone in the course reads and writes (newest first). Only 30 come at a time: the next 30 load when the
  // visitor scrolls near the end of the list (never all at once). A new message / a removed one changes just that one item.
  var dc = document.getElementById('ltDisc'), dLoaded = false;
  function dItem(d) {
    return '<div class="lt-d lt-dm' + (d.mine ? ' mine' : '') + (d.teacher ? ' tchr' : '') + '" data-id="' + d.id + '"><div class="lt-d-q"><span class="sp-av sp-av' + (d.id % 3) + '">' + esc(d.who.charAt(0)) + '</span><div><b>' + (d.mine ? 'You' : esc(d.who))
      + (d.teacher ? ' <em class="lt-tb">Teacher</em>' : '') + '</b><small>' + esc(d.at) + '</small><p>' + esc(d.m).replace(/\n/g, '<br>') + '</p></div>'
      + (d.can_del ? '<button type="button" class="lt-del" data-del="' + d.id + '" aria-label="Remove">✕</button>' : '') + '</div></div>';
  }
  if (dc) {
    var dBox = dc.querySelector('.lt-dlist'), dBusy = false, dMore = true, dLast = 0, dCount = 0, dIo = null;
    var dBadge = lt.querySelector('.lt-n[data-n=discuss]'); dCount = dBadge ? parseInt(dBadge.getAttribute('data-c'), 10) || 0 : 0;
    var setCount = function (n) { dCount = Math.max(0, n); badge('discuss', dCount); };
    var sentinel = document.createElement('div'); sentinel.className = 'lt-dmore'; sentinel.innerHTML = '<span class="lt-dspin"></span>';
    var empty = function () { if (!dBox.querySelector('.lt-dm')) { var e = dBox.querySelector('.lt-dl-empty'); if (!e) { e = document.createElement('p'); e.className = 'lt-dl-empty'; dBox.insertBefore(e, dBox.firstChild); } e.textContent = 'No messages yet.'; } else { var x = dBox.querySelector('.lt-dl-empty'); if (x) x.remove(); } };
    var page = function () {
      if (dBusy || !dMore) return; dBusy = true; sentinel.hidden = false;
      fetch('/store/discuss?l=' + dc.getAttribute('data-l') + (dLast ? '&before=' + dLast : ''), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
        if (d.error) { dBox.innerHTML = '<p class="lt-dl-empty">' + esc(d.error) + '</p>'; dMore = false; return; }
        if (!dLast) dBox.innerHTML = '';
        var list = d.list || [], tmp = document.createElement('div'); tmp.innerHTML = list.map(dItem).join('');
        while (tmp.firstChild) dBox.appendChild(tmp.firstChild);
        if (list.length) dLast = list[list.length - 1].id;
        dMore = !!d.more; dBox.appendChild(sentinel); sentinel.hidden = !dMore; empty();
      }).catch(function () { dLoaded = false; }).then(function () {
        dBusy = false;
        // the end is already on screen (short list / tall screen): load the next page too
        if (dMore && sentinel.getBoundingClientRect().top < innerHeight + 300 && !lt.querySelector('[data-pane=discuss]').hidden) page();
      });
    };
    window._ltDiscPage = page;
    if ('IntersectionObserver' in window) { dIo = new IntersectionObserver(function (es) { if (es[0].isIntersecting) page(); }, { rootMargin: '0px 0px 400px 0px' }); dIo.observe(sentinel); }
    else addEventListener('scroll', function () { if (sentinel.getBoundingClientRect().top < innerHeight + 400) page(); }, { passive: true });
  }
  function loadDisc() { if (!dc || dLoaded) return; dLoaded = true; window._ltDiscPage(); }
  if (dc) {
    var df = dc.querySelector('.lt-ask'), dta = df.querySelector('textarea'), dm = df.querySelector('.lt-ask-m'), dbtn = df.querySelector('button');
    var post = function (fd) {
      fd.append('l', dc.getAttribute('data-l')); fd.append('_t', dc.getAttribute('data-t'));
      return fetch('/store/discuss', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) { if (d.error) { dm.textContent = d.error; throw 0; } return d; });
    };
    df.addEventListener('submit', function () {
      var m = dta.value.trim(); if (m.length < 2) { dm.textContent = 'Please write your message.'; dta.focus(); return; }
      dbtn.disabled = true; dm.textContent = 'Posting…';
      var fd = new FormData(); fd.append('m', m);
      post(fd).then(function (d) {
        dta.value = ''; dm.textContent = '';
        if (d.item) { var t = document.createElement('div'); t.innerHTML = dItem(d.item); var n = t.firstChild; n.classList.add('lt-new'); dBox.insertBefore(n, dBox.firstChild); setCount(dCount + 1); empty(); }
      }).catch(function (e) { if (e !== 0) dm.textContent = 'Could not post. Please try again.'; }).then(function () { dbtn.disabled = false; });
    });
    dc.addEventListener('click', function (e) {
      var b = e.target.closest('[data-del]'); if (!b) return;
      var go = function () { var fd = new FormData(); fd.append('del', b.getAttribute('data-del')); post(fd).then(function (d) { var row = b.closest('.lt-dm'); if (row) row.remove(); if (d.removed) setCount(dCount - 1); empty(); }).catch(function () {}); };
      if (window.EduDialog) EduDialog.confirm('This message will be removed for everyone.', { title: 'Remove this message?', danger: true }).then(function (y) { if (y) go(); }); else if (confirm('Remove this message?')) go();
    });
    if (lt.querySelector('.lt-strip button.on[data-lt=discuss]')) loadDisc();
  }
})();
