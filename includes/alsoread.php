<?php
// "Also Read" boxes inside articles and the reading progress button (Customize → Also Read / Reading Progress),
// same designs as the new CMS.

/** Posts for one Also Read box: picked by hand, same category (topped up with the latest), latest or random. */
function edu_also_read_posts(PDO $pdo, array $g, int $categoryId, array $exclude): array
{
    $take = max(1, min(12, (int)($g['count'] ?? 1)));
    $ex = array_values(array_unique(array_map('intval', $exclude))) ?: [0];
    $sel = "SELECT p.id, p.title, p.slug, LEFT(p.content, 3000) AS content, m.file_path AS banner_path, m.alt_text AS banner_alt,
        (SELECT meta_value FROM post_meta WHERE post_id = p.id AND meta_key = 'summary' LIMIT 1) AS summary,
        (SELECT meta_value FROM post_meta WHERE post_id = p.id AND meta_key = 'description' LIMIT 1) AS description
        FROM posts p LEFT JOIN media m ON m.id = p.featured_image_id WHERE p.status = 'published' AND p.id NOT IN (" . implode(',', $ex) . ")";
    $source = (string)($g['source'] ?? 'category');
    if ($source === 'manual') {
        $ids = array_values(array_diff(array_map('intval', (array)($g['post_ids'] ?? [])), $ex));
        if (!$ids) return [];
        $rows = $pdo->query($sel . " AND p.id IN (" . implode(',', $ids) . ")")->fetchAll(PDO::FETCH_ASSOC);
        $byId = array_column($rows, null, 'id');
        return array_values(array_filter(array_map(fn($i) => $byId[$i] ?? null, $ids)));
    }
    if ($source === 'category' && $categoryId > 0) {
        $st = $pdo->prepare($sel . " AND p.category_id = ? ORDER BY p.date DESC LIMIT $take");
        $st->execute([$categoryId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) < $take) { // not enough in this category: top up with the latest posts
            $more = edu_also_read_posts($pdo, ['source' => 'latest', 'count' => $take - count($rows)], $categoryId, array_merge($ex, array_column($rows, 'id')));
            $rows = array_merge($rows, $more);
        }
        return $rows;
    }
    return $pdo->query($sel . ' ORDER BY ' . ($source === 'random' ? 'RAND()' : 'p.date DESC') . " LIMIT $take")->fetchAll(PDO::FETCH_ASSOC);
}

/** One Also Read box: "card" (image, title, excerpt; several posts = swipe slider), "accent" (colored list) or "minimal". */
function edu_also_read_html(array $posts, string $style, string $label): string
{
    if (!$posts) return '';
    $lbl = htmlspecialchars($label !== '' ? $label : 'Also Read');
    $url = fn($p) => htmlspecialchars(postUrl($p['slug'], $p['id']));
    if ($style === 'accent' || $style === 'minimal') {
        $li = '';
        foreach ($posts as $p) {
            $li .= '<li><a href="' . $url($p) . '">' . htmlspecialchars($p['title']) . '</a>' . ($style === 'minimal' ? '<span class="nb-also-arrow" aria-hidden="true">→</span>' : '') . '</li>';
        }
        return '<aside class="nb-also nb-also--' . $style . '" aria-label="' . $lbl . '"><div class="nb-also-label">' . $lbl . '</div><ul>' . $li . '</ul></aside>';
    }
    $cards = '';
    foreach ($posts as $p) {
        $text = trim((string)($p['summary'] ?: $p['description'] ?: ''));
        if ($text === '') $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace('<', ' <', (string)$p['content'])), ENT_QUOTES, 'UTF-8')));
        $ex = mb_strlen($text) > 170 ? preg_replace('/\s+\S*$/u', '', mb_substr($text, 0, 170)) . ' …' : $text;
        $title = htmlspecialchars($p['title']);
        $cards .= '<a href="' . $url($p) . '" class="nb-also-slide" draggable="false">'
            . (!empty($p['banner_path']) ? '<span class="nb-also-img"><img src="/' . htmlspecialchars(ltrim($p['banner_path'], '/')) . '" alt="' . htmlspecialchars($p['banner_alt'] ?: $p['title']) . '" width="640" height="360" loading="lazy" decoding="async"></span>' : '')
            . '<span class="nb-also-title">' . $title . '</span>'
            . ($ex !== '' ? '<span class="nb-also-excerpt">' . htmlspecialchars($ex) . ' <span class="nb-also-more">Continue reading</span></span>' : '') . '</a>';
    }
    $many = count($posts) > 1;
    $dots = '';
    if ($many) foreach ($posts as $i => $_) $dots .= '<button type="button" aria-label="' . ($i + 1) . '"' . ($i === 0 ? ' class="is-active"' : '') . '></button>';
    return '<figure class="nb-also nb-also--card' . ($many ? ' nb-also--slider' : '') . '" aria-label="' . $lbl . '"><div class="nb-also-label">' . $lbl . '</div><div class="nb-also-track">' . $cards . '</div>'
        . ($many ? '<div class="nb-also-dots">' . $dots . '</div>' : '') . '</figure>';
}

/** Swipe / drag for Also Read sliders and the dash under the cards (runs once per page). */
function edu_also_read_script(): string
{
    return '<script>(function(){function init(){document.querySelectorAll(".nb-also--slider").forEach(function(box){if(box.dataset.ready)return;box.dataset.ready="1";var t=box.querySelector(".nb-also-track"),dots=box.querySelectorAll(".nb-also-dots button"),n=t?t.children.length:0;if(!t||!n)return;var tick=false;function step(){return n>1?t.children[1].offsetLeft-t.children[0].offsetLeft:t.clientWidth}function idx(){var w=t.scrollWidth-t.clientWidth;if(t.scrollLeft>=w-2)return n-1;return Math.min(n-1,Math.round(t.scrollLeft/step()))}function up(){tick=false;var i=idx();dots.forEach(function(d,k){d.classList.toggle("is-active",k===i)})}t.addEventListener("scroll",function(){if(!tick){tick=true;requestAnimationFrame(up)}},{passive:true});function go(i){i=Math.max(0,Math.min(n-1,i));t.scrollTo({left:i*step(),behavior:"smooth"})}dots.forEach(function(d,k){d.addEventListener("click",function(){go(k)})});t.addEventListener("dragstart",function(e){e.preventDefault()});var down=false,sx=0,sl=0,moved=false;t.addEventListener("pointerdown",function(e){if(e.pointerType!=="mouse")return;down=true;moved=false;sx=e.clientX;sl=t.scrollLeft;t.classList.add("is-dragging")});addEventListener("pointermove",function(e){if(!down)return;var dx=e.clientX-sx;if(Math.abs(dx)>5)moved=true;t.scrollLeft=sl-dx});addEventListener("pointerup",function(e){if(!down)return;down=false;t.classList.remove("is-dragging");var dx=e.clientX-sx,cur=Math.round(sl/step());go(Math.abs(dx)>40?cur+(dx<0?1:-1):cur)});addEventListener("click",function(e){if(moved&&t.contains(e.target)){e.preventDefault();e.stopImmediatePropagation();moved=false}},true)})}if(document.readyState==="complete")setTimeout(init,0);else addEventListener("load",init)})();</script>';
}

/**
 * Reading progress button (like the CMS): a ring that fills while reading, "section n/total" + minutes left,
 * and a list of the article's H2 headings to jump to. Mobile only unless "also on desktop" is on.
 */
function edu_reading_progress_html(int $minutes): string
{
    $cfg = json_encode(['label' => (string)th('rp_label', 'In this article') ?: 'In this article', 'top' => 'Back to top', 'minutes' => max(1, $minutes), 'desktop' => th_on('rp_desktop')], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
    $css = '.nb-rp{position:fixed;left:50%;bottom:14px;z-index:9998;transform:translateX(-50%);display:flex;align-items:center;gap:9px;max-width:calc(100vw - 24px);padding:7px 15px 7px 8px;border:0;border-radius:999px;background:rgba(20,24,34,.93);color:#fff;box-shadow:0 6px 22px rgba(0,0,0,.28);font:600 13px/1 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;cursor:pointer}'
        . '.nb-rp[hidden]{display:none!important}.nb-rp-tx{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.nb-rp-tx b{color:#ffd479;font-weight:700}'
        . '.nb-rp-sheet{position:fixed;left:50%;bottom:64px;z-index:9999;width:min(420px,calc(100vw - 24px));max-height:52vh;overflow-y:auto;padding:8px;border-radius:14px;background:#fff;color:#1f2430;box-shadow:0 12px 40px rgba(0,0,0,.3);opacity:0;visibility:hidden;transform:translateX(-50%) translateY(20px);transition:opacity .22s ease,transform .22s ease,visibility 0s .22s;-webkit-overflow-scrolling:touch}'
        . '.nb-rp-sheet.open{opacity:1;visibility:visible;transform:translateX(-50%) translateY(0);transition:opacity .22s ease,transform .22s ease}'
        . '.nb-rp-sheet h4{margin:6px 10px 8px;font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:#8a90a0}'
        . '.nb-rp-sheet a{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:9px;color:#1f2430!important;font-size:14.5px;line-height:1.3;text-decoration:none}'
        . '.nb-rp-sheet a:active,.nb-rp-sheet a:hover{background:#f1f3f8}'
        . '.nb-rp-sheet a .n{flex:0 0 22px;height:22px;border-radius:50%;background:#eef1f7;color:#5a6072;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center}'
        . '.nb-rp-sheet a.done .n{background:var(--gc-accent,#0d9488);color:#fff}.nb-rp-sheet a.cur{background:#fff7db;font-weight:700}.nb-rp-sheet a.top{color:#b45309!important;font-weight:700}'
        . '@media (min-width:981px){.nb-rp:not(.nb-rp--all),.nb-rp-sheet:not(.nb-rp--all){display:none!important}}';
    $js = '(function(){var C=' . $cfg . ';var sc=document.querySelector(".single-post .entry-content")||document.querySelector(".entry-content");if(!sc)return;'
        . 'var hs=[].slice.call(sc.querySelectorAll("h2")).filter(function(h){return !h.closest(".nb-also,.nb-keypoints,.nb-summary,.toc,#toc")});hs.forEach(function(h,i){if(!h.id)h.id="sec-"+(i+1)});'
        . 'var root=document.getElementById("nb-rp-root");if(!root)return;'
        . 'root.innerHTML=\'<button type="button" id="nb-rp" class="nb-rp\'+(C.desktop?\' nb-rp--all\':\'\')+\'" aria-expanded="false"><svg width="26" height="26" viewBox="0 0 36 36" aria-hidden="true"><circle cx="18" cy="18" r="15" fill="none" stroke="rgba(255,255,255,.22)" stroke-width="4"/><circle class="nb-rp-ring" cx="18" cy="18" r="15" fill="none" stroke="#ffd479" stroke-width="4" stroke-linecap="round" stroke-dasharray="94.2" stroke-dashoffset="94.2" transform="rotate(-90 18 18)"/></svg><span class="nb-rp-tx"></span></button><div id="nb-rp-sheet" class="nb-rp-sheet\'+(C.desktop?\' nb-rp--all\':\'\')+\'"></div>\';'
        . 'var pod=document.getElementById("nb-rp"),sh=document.getElementById("nb-rp-sheet");pod.setAttribute("aria-label",C.label);'
        . 'var esc=function(s){var d=document.createElement("div");d.textContent=s;return d.innerHTML};'
        . 'var h="<h4>"+esc(C.label)+"</h4>";hs.forEach(function(e,i){h+=\'<a href="#\'+e.id+\'" data-i="\'+i+\'"><span class="n">\'+(i+1)+"</span>"+esc(e.textContent||"")+"</a>"});'
        . 'h+=\'<a href="#" class="top"><span class="n">↑</span>\'+esc(C.top)+\'</a>\';sh.innerHTML=h;'
        . 'var ring=pod.querySelector(".nb-rp-ring"),tx=pod.querySelector(".nb-rp-tx"),R=2*Math.PI*15,open=false,cur=-2,tick=false,links=sh.querySelectorAll("a[data-i]"),total=C.minutes*60;'
        . 'function tg(v){open=v==null?!open:v;sh.classList.toggle("open",open);pod.setAttribute("aria-expanded",open)}'
        . 'pod.addEventListener("click",function(){if(!hs.length){scrollTo({top:0,behavior:"smooth"});return}tg()});'
        . 'sh.addEventListener("click",function(e){var a=e.target.closest("a");if(!a)return;e.preventDefault();tg(false);if(a.classList.contains("top")){scrollTo({top:0,behavior:"smooth"});return}var el=document.getElementById(a.getAttribute("href").slice(1));el&&el.scrollIntoView({behavior:"smooth",block:"start"})});'
        . 'document.addEventListener("click",function(e){open&&!sh.contains(e.target)&&!pod.contains(e.target)&&tg(false)});'
        . 'function fmt(s){s=Math.max(0,Math.round(s));return s<60?"≈ "+s+" s":"≈ "+Math.round(s/60)+" min"}'
        . 'function up(){tick=false;var t=sc.getBoundingClientRect().top+pageYOffset,hh=sc.offsetHeight||1,p=Math.min(1,Math.max(0,(pageYOffset+innerHeight*.5-t)/hh));'
        . 'ring.setAttribute("stroke-dashoffset",(R*(1-p)).toFixed(1));var ci=-1;for(var i=0;i<hs.length;i++)if(hs[i].getBoundingClientRect().top<=innerHeight*.5)ci=i;'
        . 'if(ci!==cur){cur=ci;for(var k=0;k<links.length;k++){links[k].classList.toggle("done",k<=ci);links[k].classList.toggle("cur",k===ci)}}'
        . 'tx.innerHTML=(ci>=0&&hs.length?esc((ci+1)+"/"+hs.length)+"&nbsp; ":"")+"<b>"+fmt(total*(1-p))+"</b>"}'
        . 'function on(){if(!tick){tick=true;requestAnimationFrame(up)}}addEventListener("scroll",on,{passive:true});addEventListener("resize",on,{passive:true});up()})();';
    return '<style>' . $css . '</style><div id="nb-rp-root"></div><script>' . $js . '</script>';
}
