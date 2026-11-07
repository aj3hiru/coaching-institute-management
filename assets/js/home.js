/* Homepage: one slider for every [data-hs] section (swipe, mouse drag, dots, arrows, optional autoplay data-auto=ms),
   "View more" for Our Courses (loads 8 more at a time), and the Our Impact numbers that count up when seen. */
(function () {
  'use strict';
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function slider(box) {
    var t = box.querySelector('.hs-track'), dotsBox = box.querySelector('.hs-dots'), prev = box.querySelector('.hs-prev'), next = box.querySelector('.hs-next');
    if (!t) return;
    var pages = 1, timer = 0, paused = false;
    var page = function () { return Math.max(1, t.clientWidth); };
    var cur = function () { return Math.round(t.scrollLeft / page()); };
    var go = function (i, smooth) {
      if (i < 0) i = pages - 1; if (i >= pages) i = 0;
      // snap to the first slide that starts on that page, so a card is never cut at the left edge
      var x = i * page(), best = 0;
      [].forEach.call(t.children, function (s) { var o = s.offsetLeft - t.offsetLeft; if (o <= x + 4) best = o; });
      t.scrollTo({ left: i === pages - 1 ? t.scrollWidth : best, behavior: smooth === false || reduce ? 'auto' : 'smooth' });
    };
    function build() {
      pages = Math.max(1, Math.ceil((t.scrollWidth - 8) / page()));
      box.classList.toggle('hs-one', pages < 2);
      if (!dotsBox) return;
      dotsBox.innerHTML = '';
      if (pages < 2) return;
      for (var i = 0; i < pages; i++) { var b = document.createElement('button'); b.type = 'button'; b.setAttribute('aria-label', 'Go to ' + (i + 1) + ' of ' + pages); b.addEventListener('click', (function (k) { return function () { go(k); restart(); }; })(i)); dotsBox.appendChild(b); }
      paint();
    }
    function paint() {
      var c = t.scrollLeft + page() >= t.scrollWidth - 4 ? pages - 1 : cur();
      if (dotsBox) [].forEach.call(dotsBox.children, function (b, i) { b.classList.toggle('on', i === c); b.setAttribute('aria-current', i === c ? 'true' : 'false'); });
      if (prev) prev.disabled = !box.hasAttribute('data-auto') && t.scrollLeft < 4;
      if (next) next.disabled = !box.hasAttribute('data-auto') && t.scrollLeft + page() >= t.scrollWidth - 4;
    }
    var raf = 0; t.addEventListener('scroll', function () { cancelAnimationFrame(raf); raf = requestAnimationFrame(paint); }, { passive: true });
    if (prev) prev.addEventListener('click', function () { go(cur() - 1); restart(); });
    if (next) next.addEventListener('click', function () { var c = t.scrollLeft + page() >= t.scrollWidth - 4 ? pages - 1 : cur(); go(c + 1); restart(); });
    // mouse drag (touch scrolls by itself); a drag never opens a card
    var down = false, moved = false, sx = 0, sl = 0;
    t.addEventListener('mousedown', function (e) { if (e.button) return; down = true; moved = false; sx = e.pageX; sl = t.scrollLeft; });
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

  // ---- Our Courses: View more (8 at a time, two per slide column) ----
  var more = document.getElementById('hmMore'), track = document.getElementById('hmCourses');
  if (more && track) more.addEventListener('click', function () {
    var o = +more.getAttribute('data-o') || 0, label = more.innerHTML;
    more.disabled = true; more.textContent = 'Loading…';
    fetch('/store/more?o=' + o + '&n=8').then(function (r) { return r.json(); }).then(function (d) {
      var tmp = document.createElement('div'); tmp.innerHTML = d.html || '';
      var cards = [].slice.call(tmp.children), first = null;
      for (var i = 0; i < cards.length; i += 2) {
        var col = document.createElement('div'); col.className = 'hs-s hm-col hm-new';
        col.appendChild(cards[i]); if (cards[i + 1]) col.appendChild(cards[i + 1]);
        track.appendChild(col); if (!first) first = col;
      }
      more.setAttribute('data-o', o + (d.count || 0));
      var box = track.closest('[data-hs]'); if (box && box._hsBuild) box._hsBuild();
      if (first) track.scrollTo({ left: first.offsetLeft - track.offsetLeft, behavior: reduce ? 'auto' : 'smooth' });
      if (!d.more) { more.remove(); var all = document.getElementById('hmAll'); if (all) all.hidden = false; }
      else { more.disabled = false; more.innerHTML = label; }
    }).catch(function () { more.disabled = false; more.innerHTML = label; });
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
