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
/* a tick on the right answer, a cross on a wrong one (white icons on the right of the option) */
.edu-quiz .eq-opts li{position:relative}
.edu-quiz .eq-opts li.correct,.edu-quiz .eq-opts li.wrong{padding-right:44px !important}
.edu-quiz .eq-opts li.correct::after,.edu-quiz .eq-opts li.wrong::after{content:"";position:absolute;right:12px;top:50%;width:22px;height:22px;margin-top:-11px;border-radius:50%;background:rgba(255,255,255,.22) center/14px 14px no-repeat}
.edu-quiz .eq-opts li.correct::after{background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23fff' stroke-width='3.2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M20 6 9 17l-5-5'/%3E%3C/svg%3E")}
.edu-quiz .eq-opts li.wrong::after{background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23fff' stroke-width='3.2' stroke-linecap='round'%3E%3Cpath d='M18 6 6 18M6 6l12 12'/%3E%3C/svg%3E")}
/* the empty paragraph between quiz parts (so ads / Also Read can be placed "after paragraph N") takes no space */
.entry-content p.eq-br,p.eq-br{margin:0 !important;padding:0 !important;height:0;line-height:0;font-size:0}
.edu-quiz + p.eq-br + .edu-quiz,.edu-quiz.eq-part{margin-top:0}
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

/** A quiz with many questions is shown as several quiz boxes of 2 questions, each followed by an empty paragraph,
 * so "after paragraph N" ads, Also Read boxes etc. also work in a post that is mostly a quiz. The saved post is not changed. */
function edu_quiz_split(string $html, int $per = 2): string
{
    if (stripos($html, 'edu-quiz') === false || !class_exists('DOMDocument')) return $html;
    $out = ''; $pos = 0;
    while (preg_match('/<div\b[^>]*class=["\'][^"\']*\bedu-quiz\b[^"\']*["\'][^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE, $pos)) {
        $start = $m[0][1];
        // find the matching </div>
        $depth = 0; $i = $start; $end = -1;
        while (preg_match('/<(\/?)div\b[^>]*>/i', $html, $t, PREG_OFFSET_CAPTURE, $i)) {
            $depth += $t[1][0] === '/' ? -1 : 1;
            $i = $t[0][1] + strlen($t[0][0]);
            if ($depth === 0) { $end = $i; break; }
        }
        if ($end < 0) break;
        $out .= substr($html, $pos, $start - $pos);
        $out .= edu_quiz_split_one(substr($html, $start, $end - $start), $per);
        $pos = $end;
    }
    return $out . substr($html, $pos);
}

function edu_quiz_split_one(string $box, int $per): string
{
    $d = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $ok = $d->loadHTML('<?xml encoding="UTF-8"><body>' . $box . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors(); libxml_use_internal_errors($prev);
    if (!$ok) return $box;
    $root = null;
    foreach ($d->getElementsByTagName('div') as $el) { if (preg_match('/\bedu-quiz\b/', (string)$el->getAttribute('class'))) { $root = $el; break; } }
    if (!$root) return $box;
    $title = ''; $cards = [];
    foreach ($root->childNodes as $c) {
        if (!($c instanceof DOMElement)) continue;
        $cls = (string)$c->getAttribute('class');
        if (preg_match('/\beq-card\b/', $cls)) $cards[] = $d->saveHTML($c);
        elseif (preg_match('/\beq-title\b/', $cls)) $title = $d->saveHTML($c);
        else $cards[] = $d->saveHTML($c); // anything else stays in order
    }
    if (count($cards) <= $per) return $box . '<p class="eq-br"></p>';
    $html = '';
    foreach (array_chunk($cards, $per) as $k => $chunk) {
        $html .= '<div class="edu-quiz' . ($k ? ' eq-part' : '') . '">' . ($k === 0 ? $title : '') . implode('', $chunk) . '</div><p class="eq-br"></p>';
    }
    return $html;
}

