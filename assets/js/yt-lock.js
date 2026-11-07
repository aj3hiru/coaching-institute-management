/* Locked YouTube player (Plyr): our own controls, YouTube's title / logo / share / "Watch on YouTube" / suggestions are
   cut off and cannot be clicked, no right-click. Every [data-yt="VIDEO_ID"] box on the page becomes one player.
   window.YtLock.load(box, id, play) switches the video (free-course playlists). */
(function () {
  'use strict';
  var I18N = { restart: 'Restart', rewind: 'Back {seektime}s', play: 'Play', pause: 'Pause', fastForward: 'Forward {seektime}s', seek: 'Seek', played: 'Played',
    currentTime: 'Current time', duration: 'Duration', volume: 'Volume', mute: 'Mute', unmute: 'Unmute', enterFullscreen: 'Full screen', exitFullscreen: 'Exit full screen',
    settings: 'Settings', speed: 'Speed', normal: 'Normal', menuBack: 'Back' };
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
    p.on('ended', function () { box.classList.add('is-ended'); try { p.stop(); } catch (e) {} box.dispatchEvent(new CustomEvent('yt-ended')); }); // no YouTube end screen
    p.on('playing', function () { box.classList.remove('is-ended'); });
    return p;
  }
  function init(box) { if (box._p || !window.Plyr) return; var id = box.getAttribute('data-yt'); if (/^[\w-]{11}$/.test(id || '')) make(box, id); }
  function load(box, id, play) {
    if (!/^[\w-]{11}$/.test(id)) return;
    if (box._p) { try { box._p.destroy(); } catch (e) {} box._p = null; }
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
