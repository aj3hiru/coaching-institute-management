/* Site analytics (Admin → Analytics): one page view per page, plus the time the page was actually in view. No cookies. */
(function () {
  var d = document, w = window, l = location, s = d.currentScript;
  if (w.__ea || navigator.webdriver || w.top !== w || /(^|[?&])czp=/.test(l.search) || /(^|;\s*)edu_ab=/.test(d.cookie)) return;
  w.__ea = 1;
  var end = "/api/ea.php", pid = (s && s.getAttribute("data-p")) || "";
  function send(o) {
    var b = new URLSearchParams(o);
    if (navigator.sendBeacon && navigator.sendBeacon(end, b)) return;
    try { fetch(end, { method: "POST", body: b, keepalive: true }); } catch (e) {}
  }
  send({ n: "pv", u: l.href, r: d.referrer || "", w: String(w.innerWidth || 0), p: pid });
  // time in view: counted while the tab is visible, sent when it is hidden or closed
  var on = d.visibilityState === "visible" ? Date.now() : 0, acc = 0;
  function flush() {
    if (on) { acc += Date.now() - on; on = 0; }
    if (acc >= 1000) { send({ n: "en", u: l.href, d: String(acc) }); acc = 0; }
  }
  d.addEventListener("visibilitychange", function () { d.visibilityState === "visible" ? (on = Date.now()) : flush(); });
  w.addEventListener("pagehide", flush);
  // a long read keeps the visit "current" (Realtime) and saves time in case the tab is killed
  setInterval(function () { if (on && Date.now() - on > 60000) { flush(); on = Date.now(); } }, 30000);
})();
