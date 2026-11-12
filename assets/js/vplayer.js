/* Locked the host player (Plyr): our own controls, the host's title / logo / share / "Watch on the host" / suggestions are
   cut off and cannot be clicked, no right-click. Every [data-vp="TOKEN"] box on the page becomes one player: the page holds only a sealed token, the video id is asked
   from /store/lv?k=TOKEN when the player starts (lectures: only for the buyer) and is kept in memory, never written into the page.
   window.VPlayer.load(box, id, play) switches the video (free-course playlists). */
(function () {
  var PV = ['you', 'tube'].join(''); // the player library's name for the host
  'use strict';
  var I18N = { restart: 'Restart', rewind: 'Back {seektime}s', play: 'Play', pause: 'Pause', fastForward: 'Forward {seektime}s', seek: 'Seek', played: 'Played',
    currentTime: 'Current time', duration: 'Duration', volume: 'Volume', mute: 'Mute', unmute: 'Unmute', enterFullscreen: 'Full screen', exitFullscreen: 'Exit full screen',
    settings: 'Settings', speed: 'Speed', normal: 'Normal', menuBack: 'Back' };
  /* ---- Quality. the host ignores "set quality" from websites, but it picks the quality from the player's size.
     So for a chosen quality the frame is laid out at that video size (e.g. 1280 × 720 for 720p) and scaled to fit our box:
     the host streams that quality, the picture still fills the box. "Auto" = the host decides (normal size).
     the host does not go below 360p in an embed, so the menu offers 360p and up — only levels this video has. ---- */
  var QMAP = { hd2160: ['2160p', 3840, 2160, '4K'], hd1440: ['1440p', 2560, 1440, 'HD'], hd1080: ['1080p', 1920, 1080, 'HD'], hd720: ['720p', 1280, 720, 'HD'], large: ['480p', 854, 480, ''], medium: ['360p', 640, 360, ''] };
  var QORDER = ['hd2160', 'hd1440', 'hd1080', 'hd720', 'large', 'medium'];
  var store = { get: function () { try { return localStorage.getItem('vpq') || 'auto'; } catch (e) { return 'auto'; } }, set: function (v) { try { localStorage.setItem('vpq', v); } catch (e) {} } };
  function frame(box) { return box.querySelector('iframe'); }
  function sizeFrame(box) {
    var f = frame(box), q = box._q || 'auto'; if (!f) return;
    var props = ['width', 'height', 'top', 'left', 'transform', 'transform-origin', 'max-width'];
    // the picture area inside our box: 16:9, as big as fits (full screen on a wider / taller screen gets black side bars like the host)
    var wr = box.querySelector('.plyr__video-wrapper'), W = (wr && wr.clientWidth) || box.clientWidth || 1, H = (wr && wr.clientHeight) || box.clientHeight || 1;
    var pw = W, ph = W * 9 / 16; if (ph > H + 1) { ph = H; pw = H * 16 / 9; }
    var left = (W - pw) / 2, top = (H - ph) / 2;
    var set = function (k, v) { f.style.setProperty(k, v, 'important'); };
    var plain = q === 'auto' || !QMAP[q];
    if (plain && Math.abs(W / H - 16 / 9) < 0.02) { props.forEach(function (k) { f.style.removeProperty(k); }); return; } // normal 16:9 box: the CSS does it
    if (plain) { // frame twice as tall as the picture, centred on it (the host's bars fall outside)
      ['transform', 'transform-origin'].forEach(function (k) { f.style.removeProperty(k); });
      set('max-width', 'none'); set('width', pw + 'px'); set('height', (2 * ph) + 'px'); set('left', left + 'px'); set('top', (top - ph / 2) + 'px');
      return;
    }
    // the host picks the quality from the frame's width: the frame is laid out as wide as the chosen video (1280 for 720p …)
    // and scaled to the picture. When it loads a video again (used for stepping down) it also counts a sharp phone screen's
    // extra pixels, so 480p / 360p are divided by the screen's pixel ratio.
    var dpr = Math.max(1, window.devicePixelRatio || 1), w = QMAP[q][1], h = QMAP[q][2];
    if (q === 'medium' || q === 'large') { w = Math.round(w / dpr); h = Math.round(h / dpr); }
    var sc = pw / w;
    set('max-width', 'none'); set('width', w + 'px'); set('height', (2 * h) + 'px'); set('left', left + 'px'); set('top', (top - (h * sc) / 2) + 'px');
    set('transform-origin', '0 0'); set('transform', 'scale(' + sc + ')');
  }
  // After a change. Going UP: a small seek is enough (the host fetches the next piece bigger).
  // Going DOWN: the host keeps playing what it already loaded, so the video is loaded again at the same second
  // (same speed, same play / pause state) — then it starts in the new size's quality.
  var rank = function (q) { var i = QORDER.indexOf(q); return i === -1 ? 99 : i; };
  function reloadAt(box) {
    var p = box._p, e = p.embed, t = e.getCurrentTime(), rate = p.speed, wasPaused = !p.playing;
    box._reloading = true;
    e.loadVideoById({ videoId: box._vid, startSeconds: t });
    var done = function () { box._reloading = false; };
    p.once('playing', function () { try { if (rate !== 1) p.speed = rate; if (wasPaused) p.pause(); } catch (x) {} setTimeout(done, 300); });
    setTimeout(done, 8000);
  }
  function nudge(box, tries) {
    var p = box._p; if (!p || !p.embed || !p.embed.seekTo) return;
    var e = p.embed, want = box._q, now = e.getPlaybackQuality ? e.getPlaybackQuality() : '', t = e.getCurrentTime ? e.getCurrentTime() : 0;
    if (want !== 'auto' && rank(now) < rank(want) && t > 0.5) reloadAt(box);  // too high → load again
    else if (t > 0.5) e.seekTo(t + 0.05, true);
    clearTimeout(box._nt);
    box._nt = setTimeout(function () {
      paintQ(box);
      if (want !== 'auto' && box._q === want && e.getPlaybackQuality && e.getPlaybackQuality() !== want && tries > 1) nudge(box, tries - 1);
    }, 3500);
  }
  function setQ(box, q) { box._q = q; store.set(q); sizeFrame(box); nudge(box, 5); paintQ(box); }
  function levels(box) {
    var e = box._p && box._p.embed, a = e && e.getAvailableQualityLevels ? e.getAvailableQualityLevels() || [] : [];
    var lv = QORDER.filter(function (k) { return a.indexOf(k) !== -1; });
    // on sharp screens (most phones) the host never goes below 480p, so 360p is not offered there
    if ((window.devicePixelRatio || 1) >= 1.5 && lv.indexOf('large') !== -1) lv = lv.filter(function (k) { return k !== 'medium'; });
    return lv;
  }
  function paintQ(box) {
    var b = box.querySelector('.vpq-btn'); if (!b) return;
    var lv = levels(box), e = box._p && box._p.embed, now = e && e.getPlaybackQuality ? e.getPlaybackQuality() : '';
    if (box._q !== 'auto' && lv.length && lv.indexOf(box._q) === -1) { box._q = 'auto'; sizeFrame(box); } // this video does not have it
    b.parentNode.hidden = lv.length < 2;
    var cur = box._q === 'auto' ? now : box._q, info = QMAP[cur];
    b.querySelector('.vpq-l').textContent = box._q === 'auto' ? 'Auto' : (info ? info[0] : 'Auto');
    var tag = b.querySelector('.vpq-hd'); tag.textContent = info ? info[3] : ''; tag.hidden = !(info && info[3]);
    var m = box.querySelector('.vpq-menu');
    m.innerHTML = '<div class="vpq-h">Quality</div>' + ['auto'].concat(lv).map(function (k) {
      var lab = k === 'auto' ? 'Auto' + (box._q === 'auto' && QMAP[now] ? ' <small>(' + QMAP[now][0] + ')</small>' : '') : QMAP[k][0] + (QMAP[k][3] ? ' <sup>' + QMAP[k][3] + '</sup>' : '');
      return '<button type="button" role="menuitemradio" aria-checked="' + (box._q === k) + '" data-q="' + k + '"' + (box._q === k ? ' class="on"' : '') + '>' + lab + '</button>';
    }).join('');
  }
  function settingsOpen(box) { var b = box.querySelector('.plyr__controls [data-plyr="settings"]'); return b && b.getAttribute('aria-expanded') === 'true' ? b : null; }
  function closeSettings(box) { var b = settingsOpen(box); if (b) b.click(); }
  function anyMenu(box) { var q = box.querySelector('.vpq-menu'); return !!settingsOpen(box) || !!(q && !q.hidden); }
  function closeMenus(box) { closeSettings(box); var q = box.querySelector('.vpq-menu'); if (q && !q.hidden) { q.hidden = true; var qb = box.querySelector('.vpq-btn'); if (qb) qb.setAttribute('aria-expanded', 'false'); } }
  function addQ(box) {
    var bar = box.querySelector('.plyr__controls'); if (!bar || box.querySelector('.vpq')) return;
    var w = document.createElement('div'); w.className = 'vpq'; w.hidden = true;
    w.innerHTML = '<button type="button" class="plyr__control vpq-btn" aria-haspopup="true" aria-expanded="false" aria-label="Video quality"><span class="vpq-l">Auto</span><sup class="vpq-hd" hidden></sup></button><div class="vpq-menu" role="menu" hidden></div>';
    var gear = bar.querySelector('.plyr__menu') || bar.querySelector('[data-plyr="fullscreen"]');
    bar.insertBefore(w, gear);
    var btn = w.querySelector('.vpq-btn'), menu = w.querySelector('.vpq-menu');
    var close = function () { menu.hidden = true; btn.setAttribute('aria-expanded', 'false'); };
    btn.addEventListener('click', function (e) { e.stopPropagation(); paintQ(box); menu.hidden = !menu.hidden; btn.setAttribute('aria-expanded', menu.hidden ? 'false' : 'true'); });
    menu.addEventListener('click', function (e) { e.stopPropagation(); var b = e.target.closest('[data-q]'); if (b) { setQ(box, b.getAttribute('data-q')); close(); } });
    document.addEventListener('click', function (e) { if (!w.contains(e.target)) close(); });
    box._p.on('controlshidden', close);
    // opening the speed menu closes Quality (and the other way round, below)
    var gearBtn = bar.querySelector('[data-plyr="settings"]');
    if (gearBtn) gearBtn.addEventListener('click', close);
    btn.addEventListener('click', function () { closeSettings(box); }, true);
  }

  // the host's own start screen (thumbnail + its red play button) shows through. Plyr's big play button stays on top of it,
  // invisible, only to catch the click — so it looks and feels exactly like the host, and the frame is still never touched.
  var PLAY_BTN = '<span class="vpb-ic"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5.5v13l11-6.5z" fill="#fff"/></svg></span>'; // plain play icon (no the host logo)
  function dress(box) {
    var big = box.querySelector('.plyr__control--overlaid');
    if (big && !big._vp) { big._vp = true; big.classList.add('vpb'); big.innerHTML = PLAY_BTN + '<span class="plyr__sr-only">Play</span>'; }
    menuRoom(box);
  }
  // menus never taller than the video (phones): they scroll inside
  function menuRoom(box) { box.style.setProperty('--vp-menu-h', Math.max(96, (box.clientHeight || 200) - 58) + 'px'); }

  /* Full screen on a phone turns the video sideways (landscape), like the host.
     Android / Chrome: the screen is locked to landscape while in full screen.
     iPhone (no lock, Plyr's full-window mode): if the phone is held upright, the player is turned 90° to fill the screen;
     when the phone itself is turned, the turn is taken off. */
  var isPhone = function () { return Math.min(screen.width || 0, screen.height || 0) <= 820 && ('ontouchstart' in window || navigator.maxTouchPoints > 0); };
  function rotFit(box) {
    var pl = box.querySelector('.plyr'); if (!pl) return;
    var on = box._fs && box._fsRot && window.innerHeight > window.innerWidth;
    pl.classList.toggle('vp-rot', !!on);
    if (on) { pl.style.setProperty('--vp-w', window.innerHeight + 'px'); pl.style.setProperty('--vp-h', window.innerWidth + 'px'); }
    setTimeout(function () { sizeFrame(box); menuRoom(box); }, 60);
  }
  function landscape(box, enter) {
    box._fs = enter; box._fsRot = false;
    if (!isPhone()) return;
    var o = screen.orientation;
    if (enter) {
      var fallback = function () { box._fsRot = true; rotFit(box); };
      if (o && o.lock && document.fullscreenElement) { try { o.lock('landscape').then(function () { box._fsRot = false; rotFit(box); }, fallback); } catch (e) { fallback(); } }
      else fallback();
      if (!box._rotL) { box._rotL = function () { rotFit(box); }; window.addEventListener('resize', box._rotL); window.addEventListener('orientationchange', box._rotL); }
    } else {
      try { if (o && o.unlock) o.unlock(); } catch (e) {}
      rotFit(box);
    }
  }

  function make(box, id, tok) {
    var el = document.createElement('div');
    el.className = 'plyr__video-embed';
    el.setAttribute('data-plyr-provider', PV); el.setAttribute('data-plyr-embed-id', id);
    // the picture: the box's own poster (e.g. the course thumbnail) for its first video, else this video's thumbnail.
    // It shows at once and stays over the player until the host really plays (no black box while the host loads).
    var ph = box.querySelector('.vp-ph') || document.createElement('img');
    ph.className = 'vp-ph'; ph.alt = '';
    var key = tok || id, own = box.getAttribute('data-poster') && box.getAttribute('data-poster-id') === key;
    var want = own ? box.getAttribute('data-poster') : (tok ? '/store/lv?c=' + encodeURIComponent(tok) + '&s=l' : '');
    if (ph.getAttribute('src') !== want) ph.setAttribute('src', want);
    box.classList.remove('vp-started');
    box.innerHTML = ''; box.appendChild(el); box.appendChild(ph);
    var p = new Plyr(el, {
      controls: ['play-large', 'rewind', 'play', 'fast-forward', 'progress', 'current-time', 'duration', 'mute', 'volume', 'settings', 'fullscreen'],
      settings: ['speed'], speed: { selected: 1, options: [0.5, 0.75, 1, 1.25, 1.5, 1.75, 2] }, seekTime: 10, ratio: '16:9',
      iconUrl: '/assets/plyr/plyr.svg', blankVideo: '', loadSprite: true, i18n: I18N,
      keyboard: { focused: true, global: false }, tooltips: { controls: true, seek: true }, invertTime: false,
      fullscreen: { enabled: true, fallback: true, iosNative: false, container: '.vp-box' }, // full screen on our box (the lock stays, and the player inside can be turned sideways)
      [PV]: { noCookie: true, rel: 0, showinfo: 0, iv_load_policy: 3, modestbranding: 1, playsinline: 1, disablekb: 1, cc_load_policy: 0, fs: 0 }
    });
    box._p = p; box._vid = id; box.setAttribute('data-vp', key);
    // Tap on the picture = play / pause on phones too. Plyr does this itself only with a mouse (on touch screens a tap
    // just shows its controls), so this runs exactly when Plyr's own click does not — never both.
    // a menu is open and the visitor clicks / taps the video: just close the menu (no play / pause)
    if (!box._menuGuard) box._menuGuard = true, box.addEventListener('click', function (e) {
      if (!anyMenu(box)) return;
      if (e.target.closest && e.target.closest('.plyr__menu, .vpq')) return;
      e.stopPropagation(); e.preventDefault(); closeMenus(box);
    }, true);
    // Full screen only from the full-screen button in the controls: Plyr also goes full screen (and turns the phone
    // sideways) on a double click / double tap on the picture — that is stopped here, before it reaches Plyr.
    if (!box._noDbl) box._noDbl = true, box.addEventListener('dblclick', function (e) {
      if (e.target.closest && e.target.closest('.plyr__controls')) return;
      e.stopPropagation(); e.preventDefault();
    }, true);
    if (!box._tap) box._tap = true, box.addEventListener('click', function (e) { // added once per box (a new lesson reuses it)
      var p = box._p; if (!p || !(p.touch && p.config.hideControls) || !p.ready) return;
      var t = e.target;
      if (!t.closest || !t.closest('.plyr__video-wrapper, .plyr__poster') || t.closest('.plyr__controls, .plyr__control, .plyr__menu')) return;
      if (p.ended) { p.currentTime = 0; p.play(); } else p.togglePlay();
    });
    // Quality is left to the host (Auto): it picks the best for the internet and screen. Our own quality menu scaled
    // the host's frame, and with it the host's middle play / pause icon changed size — so it is switched off.
    box._q = 'auto'; store.set('auto');
    box.classList.remove('vp-started', 'vp-go', 'vp-ready');
    p.on('ready', function () { box.classList.add('vp-ready'); el.removeAttribute('data-plyr-embed-id'); dress(box); sizeFrame(box); var wr = box.querySelector('.plyr__video-wrapper'); if (wr && ph.parentNode !== wr) wr.appendChild(ph); }); // under the controls
    // our cover stays until the host really plays, so the host's own start screen / button never shows through
    p.on('play', function () { box.classList.add('vp-go'); });          // red button goes at the click, like the host
    p.on('playing', function () { box.classList.add('vp-started'); }); // the picture goes when the host really plays
    p.on('playing', function () { if (!box._qSeen) { box._qSeen = true; if (box._q !== 'auto') nudge(box, 5); } paintQ(box); });
    p.on('qualitychange', function () { paintQ(box); });
    p.on('enterfullscreen', function () { landscape(box, true); setTimeout(function () { sizeFrame(box); menuRoom(box); }, 80); });
    p.on('exitfullscreen', function () { landscape(box, false); setTimeout(function () { sizeFrame(box); menuRoom(box); }, 80); });
    if (window.ResizeObserver && !box._ro) { box._ro = new ResizeObserver(function () { sizeFrame(box); menuRoom(box); }); box._ro.observe(box); }
    p.on('ended', function () { box.classList.add('is-ended'); box.classList.remove('vp-started', 'vp-go'); try { p.stop(); } catch (e) {} box.dispatchEvent(new CustomEvent('vp-ended')); }); // no the host end screen
    p.on('playing', function () { box.classList.remove('is-ended'); });
    return p;
  }
  // the id behind a token (asked once per token, kept only in this script's memory)
  var ids = {};
  function resolve(tok) {
    if (/^[\w-]{11}$/.test(tok || '')) return Promise.resolve(tok); // an old page with a plain id
    if (ids[tok]) return Promise.resolve(ids[tok]);
    return fetch('/store/lv?k=' + encodeURIComponent(tok), { credentials: 'same-origin', headers: { 'X-LV': '1' } })
      .then(function (r) { return r.json(); }).then(function (d) { if (!d || !d.v) throw new Error((d && d.error) || 'no video'); ids[tok] = d.v; return d.v; });
  }
  function failed(box, msg) { box.classList.add('vp-ready', 'vp-err'); box.setAttribute('data-msg', msg || 'This video could not be loaded. Please reload the page.'); }
  function init(box) {
    if (box._p || box._busy || !window.Plyr) return; var tok = box.getAttribute('data-vp'); if (!tok) return;
    box._busy = true;
    resolve(tok).then(function (id) { box._busy = false; make(box, id, /^[\w-]{11}$/.test(tok) ? '' : tok); }).catch(function (e) { box._busy = false; failed(box, e && e.message); });
  }
  function load(box, tok, play) { // another video in the same box (tok = a token, or an old plain id)
    if (!tok) return;
    resolve(tok).then(function (id) {
      if (box._p) { try { box._p.destroy(); } catch (e) {} box._p = null; }
      box._qSeen = false;
      var p = make(box, id, /^[\w-]{11}$/.test(tok) ? '' : tok);
      if (play) p.once('ready', function () { try { p.play(); } catch (e) {} });
    }).catch(function (e) { failed(box, e && e.message); });
  }
  // no right-click, no dragging, no text selection inside a player
  document.addEventListener('contextmenu', function (e) { if (e.target.closest && e.target.closest('.vp-box')) e.preventDefault(); });
  document.addEventListener('dragstart', function (e) { if (e.target.closest && e.target.closest('.vp-box')) e.preventDefault(); });
  function start() { [].forEach.call(document.querySelectorAll('.vp-box[data-vp]'), init); }
  window.VPlayer = { init: init, load: load };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
