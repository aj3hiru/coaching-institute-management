<?php
// The lecture editor (notes PDF, quiz / MCQ, VVI …) in a popup: any <a data-embed href="/admin/shop-lecture.php?…&embed=1">
// opens it. When the lecture is saved the popup closes and the page reloads (or calls window.onLectureSaved).
?>
<style>
.lc-emb{position:fixed;inset:0;z-index:100050;background:rgba(17,24,39,.55);display:flex;align-items:center;justify-content:center;padding:18px}
.lc-emb-box{width:min(1300px,100%);height:min(94vh,1000px);background:#fff;display:flex;flex-direction:column;box-shadow:0 24px 60px rgba(15,23,42,.35)}
.lc-emb-h{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border-bottom:1px solid #e5e7eb}.lc-emb-h b{font-size:16px}
.lc-emb iframe{flex:1;width:100%;border:0}
</style>
<!-- the lecture editor (notes PDF, quiz / MCQ, VVI …) in a popup on this page -->
<div class="lc-emb" id="lcEmb" hidden><div class="lc-emb-box" role="dialog" aria-modal="true" aria-label="Lecture"><div class="lc-emb-h"><b id="lcEmbT">Lecture</b><button type="button" class="ci-btn ci-ghost ci-sm" id="lcEmbX"><i class="fas fa-times"></i> Close</button></div><iframe id="lcEmbF" title="Lecture editor"></iframe></div></div>
<script>
(function () {
  var box = document.getElementById('lcEmb'), fr = document.getElementById('lcEmbF'), t = document.getElementById('lcEmbT');
  function close() { box.hidden = true; fr.src = 'about:blank'; document.documentElement.style.overflow = ''; }
  document.addEventListener('click', function (e) {
    var a = e.target.closest('a[data-embed]'); if (!a || e.ctrlKey || e.metaKey) return; e.preventDefault();
    if (window.ciModal) try { window.ciModal.close('#lcQuick'); } catch (x) {}
    t.textContent = /#quiz/.test(a.href) ? 'Quiz / MCQ' : (/#notes/.test(a.href) ? 'Notes PDF' : (/#vvi/.test(a.href) ? 'VVI questions' : (/course=/.test(a.href) ? 'Add lecture' : 'Edit lecture')));
    var go = function () { fr.src = a.getAttribute('href'); box.hidden = false; document.documentElement.style.overflow = 'hidden'; };
    if (window.lcBeforeOpen) Promise.resolve(window.lcBeforeOpen()).then(go); else go(); // e.g. the Lectures sheet saves first
  });
  document.getElementById('lcEmbX').addEventListener('click', close);
  box.addEventListener('click', function (e) { if (e.target === box) close(); });
  window.addEventListener('message', function (e) { if (e.origin === location.origin && e.data && e.data.lectureSaved) { close(); if (window.onLectureSaved) window.onLectureSaved(e.data.lectureSaved); else { location.hash = 'lectures'; location.reload(); } } });
})();
</script>
