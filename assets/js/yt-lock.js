/* Locked YouTube player (Plyr): our own controls, YouTube's title / logo / share / "Watch on YouTube" / suggestions are
   cut off and cannot be clicked, no right-click. Every [data-yt="VIDEO_ID"] box on the page becomes one player.
   window.YtLock.load(box, id, play) switches the video (free-course playlists). */
(function () {
  'use strict';
  var I18N = { restart: 'Restart', rewind: 'Back {seektime}s', play: 'Play', pause: 'Pause', fastForward: 'Forward {seektime}s', seek: 'Seek', played: 'Played',
    currentTime: 'Current time', duration: 'Duration', volume: 'Volume', mute: 'Mute', unmute: 'Unmute', enterFullscreen: 'Full screen', exitFullscreen: 'Exit full screen',
    settings: 'Settings', speed: 'Speed', normal: 'Normal', menuBack: 'Back' };
  /* ---- Quality. YouTube ignores "set quality" from websites, but it picks the quality from the player's size.
     So for a chosen quality the frame is laid out at that video size (e.g. 1280 × 720 for 720p) and scaled to fit our box:
     YouTube streams that quality, the picture still fills the box. "Auto" = YouTube decides (normal size).
     YouTube does not go below 360p in an embed, so the menu offers 360p and up — only levels this video has. ---- */
  var QMAP = { hd2160: ['2160p', 3840, 2160, '4K'], hd1440: ['1440p', 2560, 1440, 'HD'], hd1080: ['1080p', 1920, 1080, 'HD'], hd720: ['720p', 1280, 720, 'HD'], large: ['480p', 854, 480, ''], medium: ['360p', 640, 360, ''] };
  var QORDER = ['hd2160', 'hd1440', 'hd1080', 'hd720', 'large', 'medium'];
  var store = { get: function () { try { return localStorage.getItem('ytq') || 'auto'; } catch (e) { return 'auto'; } }, set: function (v) { try { localStorage.setItem('ytq', v); } catch (e) {} } };
  function frame(box) { return box.querySelector('iframe'); }
  function sizeFrame(box) {
    var f = frame(box), q = box._q || 'auto'; if (!f) return;
    var props = ['width', 'height', 'top', 'left', 'transform', 'transform-origin', 'max-width'];
    if (q === 'auto' || !QMAP[q]) { props.forEach(function (k) { f.style.removeProperty(k); }); return; }
    // YouTube picks the quality from the frame's width: the frame is laid out as wide as the chosen video (1280 for 720p …).
    // When it loads a video again (used for stepping down) it also counts a sharp phone screen's extra pixels, so 480p / 360p
    // are divided by the screen's pixel ratio.
    var dpr = Math.max(1, window.devicePixelRatio || 1), w = QMAP[q][1], h = QMAP[q][2];
    if (q === 'medium' || q === 'large') { w = Math.round(w / dpr); h = Math.round(h / dpr); }
    var bw = box.clientWidth || 1, sc = bw / w;
    var set = function (k, v) { f.style.setProperty(k, v, 'important'); };
    set('max-width', 'none'); set('width', w + 'px'); set('height', (2 * h) + 'px'); set('left', '0'); set('top', (-(h * sc) / 2) + 'px');
    set('transform-origin', '0 0'); set('transform', 'scale(' + sc + ')');
  }
  // After a change. Going UP: a small seek is enough (YouTube fetches the next piece bigger).
  // Going DOWN: YouTube keeps playing what it already loaded, so the video is loaded again at the same second
  // (same speed, same play / pause state) — then it starts in the new size's quality.
  var rank = function (q) { var i = QORDER.indexOf(q); return i === -1 ? 99 : i; };
  function reloadAt(box) {
    var p = box._p, e = p.embed, t = e.getCurrentTime(), rate = p.speed, wasPaused = !p.playing;
    box._reloading = true;
    e.loadVideoById({ videoId: box.getAttribute('data-yt'), startSeconds: t });
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
    // on sharp screens (most phones) YouTube never goes below 480p, so 360p is not offered there
    if ((window.devicePixelRatio || 1) >= 1.5 && lv.indexOf('large') !== -1) lv = lv.filter(function (k) { return k !== 'medium'; });
    return lv;
  }
  function paintQ(box) {
    var b = box.querySelector('.ytq-btn'); if (!b) return;
    var lv = levels(box), e = box._p && box._p.embed, now = e && e.getPlaybackQuality ? e.getPlaybackQuality() : '';
    if (box._q !== 'auto' && lv.length && lv.indexOf(box._q) === -1) { box._q = 'auto'; sizeFrame(box); } // this video does not have it
    b.parentNode.hidden = lv.length < 2;
    var cur = box._q === 'auto' ? now : box._q, info = QMAP[cur];
    b.querySelector('.ytq-l').textContent = box._q === 'auto' ? 'Auto' : (info ? info[0] : 'Auto');
    var tag = b.querySelector('.ytq-hd'); tag.textContent = info ? info[3] : ''; tag.hidden = !(info && info[3]);
    var m = box.querySelector('.ytq-menu');
    m.innerHTML = '<div class="ytq-h">Quality</div>' + ['auto'].concat(lv).map(function (k) {
      var lab = k === 'auto' ? 'Auto' + (box._q === 'auto' && QMAP[now] ? ' <small>(' + QMAP[now][0] + ')</small>' : '') : QMAP[k][0] + (QMAP[k][3] ? ' <sup>' + QMAP[k][3] + '</sup>' : '');
      return '<button type="button" role="menuitemradio" aria-checked="' + (box._q === k) + '" data-q="' + k + '"' + (box._q === k ? ' class="on"' : '') + '>' + lab + '</button>';
    }).join('');
  }
  function addQ(box) {
    var bar = box.querySelector('.plyr__controls'); if (!bar || box.querySelector('.ytq')) return;
    var w = document.createElement('div'); w.className = 'ytq'; w.hidden = true;
    w.innerHTML = '<button type="button" class="plyr__control ytq-btn" aria-haspopup="true" aria-expanded="false" aria-label="Video quality"><span class="ytq-l">Auto</span><sup class="ytq-hd" hidden></sup></button><div class="ytq-menu" role="menu" hidden></div>';
    var gear = bar.querySelector('.plyr__menu') || bar.querySelector('[data-plyr="fullscreen"]');
    bar.insertBefore(w, gear);
    var btn = w.querySelector('.ytq-btn'), menu = w.querySelector('.ytq-menu');
    var close = function () { menu.hidden = true; btn.setAttribute('aria-expanded', 'false'); };
    btn.addEventListener('click', function (e) { e.stopPropagation(); paintQ(box); menu.hidden = !menu.hidden; btn.setAttribute('aria-expanded', menu.hidden ? 'false' : 'true'); });
    menu.addEventListener('click', function (e) { e.stopPropagation(); var b = e.target.closest('[data-q]'); if (b) { setQ(box, b.getAttribute('data-q')); close(); } });
    document.addEventListener('click', function (e) { if (!w.contains(e.target)) close(); });
    box._p.on('controlshidden', close);
  }

  var YT_BTN = '<svg viewBox="0 0 68 48" aria-hidden="true"><path class="ytb-bg" d="M66.52 7.74c-.78-2.93-2.49-5.41-5.42-6.19C55.79.13 34 0 34 0S12.21.13 6.9 1.55C3.97 2.33 2.27 4.81 1.48 7.74.06 13.05 0 24 0 24s.06 10.95 1.48 16.26c.78 2.93 2.49 5.41 5.42 6.19C12.21 47.87 34 48 34 48s21.79-.13 27.1-1.55c2.93-.78 4.64-3.26 5.42-6.19C67.94 34.95 68 24 68 24s-.06-10.95-1.48-16.26z"/><path d="M45 24 27 14v20" fill="#fff"/></svg>';
  var ICON_PLAY = '<svg viewBox="0 0 36 36" aria-hidden="true"><path d="M12 26 18.5 22 18.5 14 12 10z M18.5 22 25 18 25 18 18.5 14z" fill="#fff"/></svg>';
  var ICON_PAUSE = '<svg viewBox="0 0 36 36" aria-hidden="true"><path d="M12 26h4V10h-4zm9-16v16h4V10z" fill="#fff"/></svg>';
  function dress(box) {
    var big = box.querySelector('.plyr__control--overlaid');
    if (big && !big._yt) { big._yt = true; big.innerHTML = YT_BTN + '<span class="plyr__sr-only">Play</span>'; big.classList.add('ytb'); }
    if (!box.querySelector('.ytb-bezel')) { var bz = document.createElement('div'); bz.className = 'ytb-bezel'; bz.setAttribute('aria-hidden', 'true'); (box.querySelector('.plyr') || box).appendChild(bz); }
    if (!box.querySelector('.ytb-spin')) { var sp = document.createElement('div'); sp.className = 'ytb-spin'; sp.setAttribute('aria-hidden', 'true'); (box.querySelector('.plyr') || box).appendChild(sp); }
    menuRoom(box);
  }
  // the play / pause "bezel" in the middle, like YouTube: the new state's icon grows and fades out
  function bezel(box, playing) {
    var bz = box.querySelector('.ytb-bezel'); if (!bz) return;
    bz.innerHTML = playing ? ICON_PLAY : ICON_PAUSE;
    bz.classList.remove('go'); void bz.offsetWidth; bz.classList.add('go');
  }
  // menus never taller than the video (phones): they scroll inside
  function menuRoom(box) { box.style.setProperty('--yt-menu-h', Math.max(96, (box.clientHeight || 200) - 58) + 'px'); }

  function make(box, id) {
    var el = document.createElement('div');
    el.className = 'plyr__video-embed';
    el.setAttribute('data-plyr-provider', 'youtube'); el.setAttribute('data-plyr-embed-id', id);
    box.innerHTML = ''; box.appendChild(el);
    var p = new Plyr(el, {
      controls: ['play-large', 'rewind', 'play', 'fast-forward', 'progress', 'current-time', 'duration', 'mute', 'volume', 'settings', 'fullscreen'],
      settings: ['speed'], speed: { selected: 1, options: [0.5, 0.75, 1, 1.25, 1.5, 1.75, 2] }, seekTime: 10, ratio: '16:9',
      iconUrl: '/assets/plyr/plyr.svg', blankVideo: '', loadSprite: true, i18n: I18N,
      keyboard: { focused: true, global: false }, tooltips: { controls: true, seek: true }, invertTime: false,
      fullscreen: { enabled: true, fallback: true, iosNative: false }, // full screen on our box, so the lock stays
      youtube: { noCookie: true, rel: 0, showinfo: 0, iv_load_policy: 3, modestbranding: 1, playsinline: 1, disablekb: 1, cc_load_policy: 0, fs: 0 }
    });
    box._p = p; box.setAttribute('data-yt', id);
    // Tap on the picture = play / pause on phones too. Plyr does this itself only with a mouse (on touch screens a tap
    // just shows its controls), so this runs exactly when Plyr's own click does not — never both.
    if (!box._tap) box._tap = true, box.addEventListener('click', function (e) { // added once per box (a new lesson reuses it)
      var p = box._p; if (!p || !(p.touch && p.config.hideControls) || !p.ready) return;
      var t = e.target;
      if (!t.closest || !t.closest('.plyr__video-wrapper, .plyr__poster') || t.closest('.plyr__controls, .plyr__control, .plyr__menu')) return;
      if (p.ended) { p.currentTime = 0; p.play(); } else p.togglePlay();
    });
    if (box._q === undefined) box._q = store.get();
    box.classList.remove('yt-started', 'yt-wait');
    p.on('ready', function () { addQ(box); dress(box); sizeFrame(box); });
    // our cover stays until YouTube really plays, so YouTube's own start screen / button never shows through
    p.on('play', function () { if (!box.classList.contains('yt-started')) box.classList.add('yt-wait'); else if (!box._reloading) bezel(box, true); });
    p.on('pause', function () { if (box.classList.contains('yt-started') && !box._reloading && !p.ended) bezel(box, false); });
    p.on('playing', function () { box.classList.remove('yt-wait'); box.classList.add('yt-started'); });
    p.on('playing', function () { if (!box._qSeen) { box._qSeen = true; if (box._q !== 'auto') nudge(box, 5); } paintQ(box); });
    p.on('qualitychange', function () { paintQ(box); });
    p.on('enterfullscreen', function () { setTimeout(function () { sizeFrame(box); }, 60); });
    p.on('exitfullscreen', function () { setTimeout(function () { sizeFrame(box); }, 60); });
    if (window.ResizeObserver && !box._ro) { box._ro = new ResizeObserver(function () { sizeFrame(box); menuRoom(box); }); box._ro.observe(box); }
    p.on('ended', function () { box.classList.add('is-ended'); box.classList.remove('yt-started', 'yt-wait'); try { p.stop(); } catch (e) {} box.dispatchEvent(new CustomEvent('yt-ended')); }); // no YouTube end screen
    p.on('playing', function () { box.classList.remove('is-ended'); });
    return p;
  }
  function init(box) { if (box._p || !window.Plyr) return; var id = box.getAttribute('data-yt'); if (/^[\w-]{11}$/.test(id || '')) make(box, id); }
  function load(box, id, play) {
    if (!/^[\w-]{11}$/.test(id)) return;
    if (box._p) { try { box._p.destroy(); } catch (e) {} box._p = null; }
    box._qSeen = false;
    var p = make(box, id);
    if (play) p.once('ready', function () { try { p.play(); } catch (e) {} });
  }
  // no right-click, no dragging, no text selection inside a player
  document.addEventListener('contextmenu', function (e) { if (e.target.closest && e.target.closest('.yt-lock')) e.preventDefault(); });
  document.addEventListener('dragstart', function (e) { if (e.target.closest && e.target.closest('.yt-lock')) e.preventDefault(); });
  function start() { [].forEach.call(document.querySelectorAll('.yt-lock[data-yt]'), init); }
  window.YtLock = { init: init, load: load };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
