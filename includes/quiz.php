<?php
// GK / MCQ quizzes in posts (post editor → "GK Quiz"). Markup saved in the post:
//   <div class="edu-quiz"><div class="eq-title">…</div>
//     <div class="eq-card" data-ans="B"><div class="eq-q"><span class="eq-n">1.</span> Question</div>
//       <ol class="eq-opts"><li data-opt="A">…</li><li data-opt="B">…</li>…</ol>
//       <div class="eq-exp">Explanation (optional)</div></div> …</div>
// On the post page a reader taps an option: it turns green (right) or red (wrong, the right one turns green) and the
// question locks — the same as cgssb.in. The post keeps its normal NewsArticle schema (no separate quiz schema).

/** Styles and script for quizzes on the post page (added once, only when the post has a quiz) — same look and behaviour
 * as cgssb.in: plain cards, tap an option → it turns green (right) or red (wrong, and the right one turns green), then the
 * question is locked. No score, no "show answers". An explanation, if the author wrote one, appears after answering. */
function edu_quiz_assets(string $lang = 'en'): string
{
    return <<<'HTML'
<style id="edu-quiz-css">
.edu-quiz{font-family:Arial,sans-serif;font-weight:normal;color:#333;line-height:1.5;max-width:800px;margin:20px auto}
.edu-quiz .eq-title{font-size:18px;font-weight:700;margin:0 0 12px;color:var(--t-content-title,#111827)}
.edu-quiz .eq-card{border:1px solid #e0e0e0;border-radius:6px;padding:16px;margin-bottom:20px;background-color:#fff}
.edu-quiz .eq-card *{font-weight:normal}
.edu-quiz .eq-q{font-size:16px;margin-bottom:12px}
.edu-quiz .eq-opts,.entry-content .edu-quiz .eq-opts{list-style:none;display:flex;flex-direction:column;gap:8px;margin:0 !important;padding:0 !important}
.entry-content .edu-quiz .eq-opts li,.edu-quiz .eq-opts li{margin:0 !important;border:1px solid #dcdcdc;border-radius:4px;padding:10px 14px;cursor:pointer;background-color:#fafafa;user-select:none;transition:none;font-size:14px}
.edu-quiz .eq-opts li::marker{content:none}
.edu-quiz .eq-opts li::before{content:attr(data-opt) ". "}
.edu-quiz .eq-opts li:hover{background-color:#fafafa}
.edu-quiz .eq-opts li.correct{background-color:#28a745 !important;color:#fff !important;border-color:#28a745 !important}
.edu-quiz .eq-opts li.wrong{background-color:#dc3545 !important;color:#fff !important;border-color:#dc3545 !important}
.edu-quiz .eq-card.locked .eq-opts li{cursor:default;pointer-events:none}
.edu-quiz .eq-exp{display:none;margin-top:12px;font-size:15px}
.edu-quiz .eq-card.locked .eq-exp{display:block}
.edu-quiz .eq-exp::before{content:"Explanation:";margin-right:.35em;font-weight:700;color:#111827}
[data-theme="dark"] .edu-quiz .eq-exp::before{color:#f3f4f6}
[data-theme="dark"] .edu-quiz{color:#e5e7eb}
[data-theme="dark"] .edu-quiz .eq-card{background-color:#151b23;border-color:#263040}
[data-theme="dark"] .edu-quiz .eq-opts li{background-color:#1b2330;border-color:#334155}
</style>
<script id="edu-quiz-js">
document.querySelectorAll(".edu-quiz").forEach(function (qz) {
  qz.addEventListener("click", function (e) {
    var option = e.target.closest(".eq-opts li"); if (!option) return;
    var card = option.closest(".eq-card"); if (!card || card.classList.contains("locked")) return;
    card.classList.add("locked");
    var correctOpt = (card.getAttribute("data-ans") || "").toUpperCase();
    if (option.getAttribute("data-opt").toUpperCase() === correctOpt) option.classList.add("correct");
    else {
      option.classList.add("wrong");
      var correctEl = card.querySelector('.eq-opts li[data-opt="' + correctOpt + '"]'); if (correctEl) correctEl.classList.add("correct");
    }
  });
});
</script>
HTML;
}
