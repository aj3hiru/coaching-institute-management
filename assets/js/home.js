/* Homepage: one slider for every [data-hs] section (swipe, mouse drag, dots, arrows, optional autoplay data-auto=ms),
   "View more" for Our Courses (loads 8 more at a time), and the Our Impact numbers that count up when seen. */
(function () {
  'use strict';
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function slider(box) {
    var t = box.querySelector('.hs-track'), dotsBox = box.querySelector('.hs-dots'), prev = box.querySelector('.hs-prev'), next = box.querySelector('.hs-next');
    if (!t) return;
    var pages = 1, timer = 0, paused = false, stops = [0];
    var vw = function () { return Math.max(1, t.clientWidth); };
    // stops = where each "next" lands: the first slide that is not fully in view starts the next view
    // (works for 4-a-row cards, 1 card with a peek of the next, and full-width slides with a gap)
    function measure() {
      var w = vw(), max = Math.max(0, t.scrollWidth - w), offs = [].map.call(t.children, function (s) { return s.offsetLeft - t.offsetLeft; });
      stops = [0];
      for (var k = 0; k < offs.length; k++) {
        var last = stops[stops.length - 1], right = offs[k] + t.children[k].offsetWidth;
        if (offs[k] > last + 2 && right > last + w + 2) stops.push(Math.min(offs[k], max));
        if (stops[stops.length - 1] >= max - 2) break;
      }
      if (max > 2 && stops[stops.length - 1] < max - 2) stops.push(max);
      for (var q = stops.length - 1; q > 0; q--) if (stops[q] - stops[q - 1] < 3) stops.splice(q, 1);
    }
    var cur = function () { var x = t.scrollLeft, b = 0; stops.forEach(function (o, i) { if (Math.abs(o - x) < Math.abs(stops[b] - x)) b = i; }); return b; };
    // a soft, eased move (slow start, slow stop) instead of the browser's quick smooth scroll; snapping is off while it runs
    var anim = 0;
    var ease = function (k) { return k < 0.5 ? 4 * k * k * k : 1 - Math.pow(-2 * k + 2, 3) / 2; };
    var go = function (i, smooth) {
      if (i < 0) i = pages - 1; if (i >= pages) i = 0;
      var to = stops[i] || 0, from = t.scrollLeft, dist = to - from;
      cancelAnimationFrame(anim);
      if (smooth === false || reduce || Math.abs(dist) < 2) { t.classList.remove('hs-anim'); t.scrollLeft = to; return; }
      var dur = Math.min(1100, 650 + Math.abs(dist) / vw() * 120), t0 = 0;
      t.classList.add('hs-anim');
      (function step(ts) {
        if (!t0) t0 = ts;
        var k = Math.min(1, (ts - t0) / dur);
        t.scrollLeft = from + dist * ease(k);
        if (k < 1) anim = requestAnimationFrame(step); else t.classList.remove('hs-anim');
      })(performance.now());
    };
    function build() {
      measure(); pages = stops.length;
      box.classList.toggle('hs-one', pages < 2);
      if (!dotsBox) return;
      dotsBox.innerHTML = '';
      if (pages < 2) return;
      for (var i = 0; i < pages; i++) { var b = document.createElement('button'); b.type = 'button'; b.setAttribute('aria-label', 'Go to ' + (i + 1) + ' of ' + pages); b.addEventListener('click', (function (k) { return function () { go(k); restart(); }; })(i)); dotsBox.appendChild(b); }
      paint();
    }
    function paint() {
      var c = cur();
      if (dotsBox) [].forEach.call(dotsBox.children, function (b, i) { b.classList.toggle('on', i === c); b.setAttribute('aria-current', i === c ? 'true' : 'false'); });
      if (prev) prev.disabled = !box.hasAttribute('data-auto') && t.scrollLeft < 4;
      if (next) next.disabled = !box.hasAttribute('data-auto') && t.scrollLeft + vw() >= t.scrollWidth - 4;
    }
    var raf = 0; t.addEventListener('scroll', function () { cancelAnimationFrame(raf); raf = requestAnimationFrame(paint); }, { passive: true });
    if (prev) prev.addEventListener('click', function () { go(cur() - 1); restart(); });
    if (next) next.addEventListener('click', function () { go(cur() + 1); restart(); });
    // mouse drag (touch scrolls by itself); a drag never opens a card
    var down = false, moved = false, sx = 0, sl = 0;
    var stopAnim = function () { cancelAnimationFrame(anim); t.classList.remove('hs-anim'); };
    t.addEventListener('touchstart', stopAnim, { passive: true });
    t.addEventListener('mousedown', function (e) { if (e.button) return; stopAnim(); down = true; moved = false; sx = e.pageX; sl = t.scrollLeft; });
    window.addEventListener('mousemove', function (e) { if (!down) return; var dx = e.pageX - sx; if (Math.abs(dx) > 5) { moved = true; t.classList.add('drag'); t.scrollLeft = sl - dx; } });
    window.addEventListener('mouseup', function () { if (!down) return; down = false; if (moved) { t.classList.remove('drag'); go(cur()); } });
    t.addEventListener('click', function (e) { if (moved) { e.preventDefault(); e.stopPropagation(); moved = false; } }, true);
    t.addEventListener('dragstart', function (e) { e.preventDefault(); });
    // autoplay: only while on screen, not while the visitor is touching / hovering / the tab is hidden
    var auto = +box.getAttribute('data-auto') || 0, seen = true;
    function restart() { clearInterval(timer); if (!auto || reduce || pages < 2) return; timer = setInterval(function () { if (!paused && seen && !document.hidden) go(cur() + 1); }, auto); }
    if (auto) {
      box.addEventListener('mouseenter', function () { paused = true; }); box.addEventListener('mouseleave', function () { paused = false; });
      box.addEventListener('touchstart', function () { paused = true; }, { passive: true }); box.addEventListener('touchend', function () { setTimeout(function () { paused = false; }, 4000); });
      box.addEventListener('focusin', function () { paused = true; }); box.addEventListener('focusout', function () { paused = false; });
      if ('IntersectionObserver' in window) new IntersectionObserver(function (es) { seen = es[0].isIntersecting; }).observe(box);
    }
    var rt = 0; window.addEventListener('resize', function () { clearTimeout(rt); rt = setTimeout(function () { build(); go(cur(), false); }, 150); });
    box._hsBuild = build;
    build(); restart();
    // images can change the width after load
    [].forEach.call(t.querySelectorAll('img'), function (im) { if (!im.complete) im.addEventListener('load', function () { build(); }, { once: true }); });
  }
  [].forEach.call(document.querySelectorAll('[data-hs]'), slider);
  // top slider: as tall as the slide in view (slides of different sizes)
  [].forEach.call(document.querySelectorAll('.hm-hs'), function (box) {
    var t = box.querySelector('.hs-track'); if (!t) return;
    var fit = function () { var w = t.clientWidth || 1, k = Math.round(t.scrollLeft / w), s = t.children[k]; if (s && s.offsetHeight) t.style.height = s.offsetHeight + 'px'; };
    var tm; t.addEventListener('scroll', function () { clearTimeout(tm); tm = setTimeout(fit, 80); }, { passive: true });
    addEventListener('resize', fit); addEventListener('load', fit);
    [].forEach.call(t.querySelectorAll('img'), function (im) { if (!im.complete) im.addEventListener('load', fit); });
    fit();
  });

  // ---- Our Courses: a grid (2 a row on phones, 4 shown first; 4 a row on computers, 8 shown first).
  //      View more shows the next ones right below — first those already on the page, then 8 more from the server.
  //      Category pages use the same with [data-more-grid] buttons (their own type / category / filter).
  function moreList(more, grid, allBtn) {
    if (!more || !grid) return;
    var hiddenCards = function () { return [].slice.call(grid.querySelectorAll('.hc')).filter(function (c) { return c.offsetParent === null; }); };
    var done = more.getAttribute('data-more') === '0';
    var slider = document.getElementById(more.getAttribute('data-slider') || ''); // Our Courses: a slider until View more is pressed
    var sync = function () { if (slider) return; var left = hiddenCards().length; if (done && !left) { more.remove(); if (allBtn) allBtn.hidden = false; } };
    sync(); // e.g. a computer shows all 8 at once and the server has no more
    window.addEventListener('resize', function () { if (document.body.contains(more)) sync(); });
    more.addEventListener('click', function () {
      if (slider) { // the slider's cards move into the grid, the slider goes; then more come from the server below
        [].forEach.call(slider.querySelectorAll('.hs-s > *'), function (c) { c.classList.add('shown'); grid.appendChild(c); });
        slider.remove(); slider = null; grid.hidden = false;
        if (done) { sync(); return; }
      }
      var hid = hiddenCards();
      if (hid.length) { hid.slice(0, 8).forEach(function (c) { c.classList.add('shown', 'hm-new'); }); sync(); return; }
      var o = +more.getAttribute('data-o') || 0, label = more.innerHTML, q = '';
      ['w', 't', 'c'].forEach(function (k) { var v = more.getAttribute('data-' + k); if (v && v !== '0') q += '&' + k + '=' + encodeURIComponent(v); });
      more.disabled = true; more.textContent = 'Loading…';
      fetch('/store/more?o=' + o + '&n=8' + q).then(function (r) { return r.json(); }).then(function (d) {
        var tmp = document.createElement('div'); tmp.innerHTML = d.html || '';
        [].slice.call(tmp.children).forEach(function (c) { c.classList.add('shown', 'hm-new'); grid.appendChild(c); });
        more.setAttribute('data-o', o + (d.count || 0));
        more.disabled = false; more.innerHTML = label;
        if (!d.more) done = true;
        sync();
      }).catch(function () { more.disabled = false; more.innerHTML = label; });
    });
  }
  moreList(document.getElementById('hmMore'), document.getElementById('hmCourses'), document.getElementById('hmAll'));
  [].forEach.call(document.querySelectorAll('[data-more-grid]'), function (b) { moreList(b, document.getElementById(b.getAttribute('data-more-grid')), document.getElementById(b.getAttribute('data-all') || '')); });

  // ---- Categories: "All" shows every category (the rest are hidden by the count set in Customize) ----
  var ca = document.querySelector('.hm-cats-all'), cg = document.getElementById('hmCats');
  if (ca && cg) ca.addEventListener('click', function () { var on = cg.classList.toggle('all'); ca.setAttribute('aria-expanded', on ? 'true' : 'false'); ca.textContent = on ? ca.getAttribute('data-less') : ca.getAttribute('data-more'); });

  // ---- splash: ripple where a .hm-splash button is pressed ----
  document.addEventListener('pointerdown', function (e) {
    var b = e.target.closest && e.target.closest('.hm-splash'); if (!b || reduce) return;
    var r = b.getBoundingClientRect(), d = Math.max(r.width, r.height), s = document.createElement('span');
    s.className = 'hm-ripple'; s.style.width = s.style.height = d + 'px'; s.style.left = (e.clientX - r.left - d / 2) + 'px'; s.style.top = (e.clientY - r.top - d / 2) + 'px';
    b.appendChild(s); setTimeout(function () { s.remove(); }, 650);
  });

  // ---- Our Impact: numbers count up once, when they come on screen ----
  var nums = document.querySelectorAll('.hm-imp [data-count]');
  if (nums.length && 'IntersectionObserver' in window && !reduce) {
    var io = new IntersectionObserver(function (es) {
      es.forEach(function (e) {
        if (!e.isIntersecting) return; io.unobserve(e.target);
        var el = e.target, txt = el.getAttribute('data-count'), m = txt.match(/^([^\d]*)([\d,]+)(.*)$/);
        if (!m) return;
        var end = +m[2].replace(/,/g, ''), t0 = 0, dur = 1400;
        if (!end) return;
        var step = function (ts) { if (!t0) t0 = ts; var k = Math.min(1, (ts - t0) / dur), v = Math.round(end * (1 - Math.pow(1 - k, 3)));
          el.textContent = m[1] + v.toLocaleString('en-IN') + m[3]; if (k < 1) requestAnimationFrame(step); else el.textContent = txt; };
        requestAnimationFrame(step);
      });
    }, { threshold: 0.4 });
    nums.forEach(function (n) { io.observe(n); });
  }
})();
