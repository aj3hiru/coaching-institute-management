<?php
// Job cards (like naukridrishti.in): logo, post name, department, location, salary, total posts, qualification,
// "x days ago" and the last date. Details come from the post editor's "Job card" box; anything empty falls back
// (post title, category, featured image, state / All India) or the line is left out.

const EDU_JOB_META = ['job_card', 'job_post_name', 'job_org', 'job_logo', 'job_location', 'job_salary', 'job_vacancies', 'job_qualification'];

/** Qualification group used by the filter chips. */
function edu_job_qual_group(string $q): string
{
    $q = strtolower($q);
    return match (true) {
        $q === '' => '',
        (bool)preg_match('/ph\.?d|post ?grad|\bpg\b|m\.a\b|m\.sc|m\.com|mba|mca|m\.tech|m\.e\b|m\.ed|llm/', $q) => 'pg',
        (bool)preg_match('/grad|b\.a\b|b\.sc|b\.com|b\.tech|b\.e\b|bca|bba|b\.ed|llb|pharma|mbbs|nursing|gnm|anm|degree|bachelor/', $q) => 'graduation',
        (bool)preg_match('/diploma|polytechnic/', $q) => 'diploma',
        (bool)preg_match('/\biti\b/', $q) => 'iti',
        (bool)preg_match('/12|inter|hsc|senior secondary/', $q) => '12th',
        (bool)preg_match('/10|matric|high school/', $q) => '10th',
        (bool)preg_match('/\b[5-9](th)?\b/', $q) => 'below10',
        default => 'other',
    };
}

/** Card data for a list of posts (each needs id, title, slug, date; category / state / image are looked up). */
function edu_job_cards_data(PDO $pdo, array $posts): array
{
    if (!$posts) return [];
    $ids = array_values(array_unique(array_map(fn($p) => (int)$p['id'], $posts)));
    $in = implode(',', array_fill(0, count($ids), '?'));
    $meta = [];
    $st = $pdo->prepare("SELECT post_id, meta_key, meta_value FROM post_meta WHERE post_id IN ($in) AND meta_key IN ('" . implode("','", EDU_JOB_META) . "')");
    $st->execute($ids);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $meta[(int)$r['post_id']][$r['meta_key']] = (string)$r['meta_value'];
    $st = $pdo->prepare("SELECT p.id, p.last_date, c.name AS cat_name, s.state_name, m.file_path, m.responsive_set
        FROM posts p LEFT JOIN categories c ON c.id = p.category_id LEFT JOIN states s ON s.id = p.state_id LEFT JOIN media m ON m.id = p.featured_image_id
        WHERE p.id IN ($in)");
    $st->execute($ids);
    $extra = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $extra[(int)$r['id']] = $r;
    $out = [];
    foreach ($posts as $p) {
        $id = (int)$p['id']; $m = $meta[$id] ?? []; $x = $extra[$id] ?? [];
        $img = '';
        if (!empty($m['job_logo'])) $img = $m['job_logo'];
        elseif (!empty($x['file_path'])) { $set = json_decode((string)($x['responsive_set'] ?? ''), true) ?: []; $img = (string)($set['sm'] ?? $x['file_path']); }
        if ($img !== '' && !preg_match('#^(https?:)?//#', $img)) $img = '/' . ltrim($img, '/');
        $out[] = [
            'url' => postUrl((string)$p['slug'], $id),
            'title' => trim((string)($m['job_post_name'] ?? '')) ?: (string)$p['title'],
            'org' => trim((string)($m['job_org'] ?? '')) ?: (string)($x['cat_name'] ?? ''),
            'logo' => $img,
            'location' => trim((string)($m['job_location'] ?? '')) ?: ((string)($x['state_name'] ?? '') ?: 'All India'),
            'salary' => trim((string)($m['job_salary'] ?? '')),
            'vacancies' => trim((string)($m['job_vacancies'] ?? '')),
            'qualification' => trim((string)($m['job_qualification'] ?? '')),
            'date' => (string)$p['date'],
            'last_date' => (string)($x['last_date'] ?? ($p['last_date'] ?? '')),
            'full_title' => (string)$p['title'],
            'is_job' => ($m['job_card'] ?? '') === '1',
            'cat' => (string)($x['cat_name'] ?? ''),
            'thumb' => !empty($x['file_path']) ? '/' . ltrim((string)((json_decode((string)($x['responsive_set'] ?? ''), true) ?: [])['sm'] ?? $x['file_path']), '/') : '',
        ];
    }
    return $out;
}

/** "3 days ago", "1 months ago" (the same wording as the reference cards). */
function edu_job_ago(string $date): string
{
    $s = max(0, time() - strtotime($date));
    if ($s < 3600) return max(1, (int)floor($s / 60)) . ' minutes ago';
    if ($s < 86400) return (int)floor($s / 3600) . ' hours ago';
    if ($s < 30 * 86400) { $d = (int)floor($s / 86400); return $d . ($d === 1 ? ' day ago' : ' days ago'); }
    if ($s < 365 * 86400) { $mo = (int)floor($s / (30 * 86400)); return $mo . ($mo === 1 ? ' month ago' : ' months ago'); }
    $y = (int)floor($s / (365 * 86400));
    return $y . ($y === 1 ? ' year ago' : ' years ago');
}

/** A post without a job card: thumbnail, title, category, age. Same frame as the job cards. */
function edu_update_card_html(array $c): string
{
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $img = $c['thumb'] !== '' ? '<img src="' . $e($c['thumb']) . '" alt="" width="96" height="54" loading="lazy" decoding="async">' : '<span class="jc-ini" aria-hidden="true">' . $e(mb_strtoupper(mb_substr($c['full_title'], 0, 1))) . '</span>';
    return '<article class="jc jc-u" data-q="">'
        . '<a class="jc-link" href="' . $e($c['url']) . '" aria-label="' . $e($c['full_title']) . '"></a>'
        . '<div class="jc-top"><span class="jc-thumb">' . $img . '</span><span class="jc-head"><h3 class="jc-title jc-title2">' . $e($c['full_title']) . '</h3>' . ($c['cat'] !== '' ? '<span class="jc-org">' . $e($c['cat']) . '</span>' : '') . '</span></div>'
        . '<div class="jc-foot"><span class="jc-ago">' . $e(edu_job_ago($c['date'])) . '</span><span class="jc-more">Read more</span></div>'
        . '</article>';
}

/** One job card. */
function edu_job_card_html(array $c): string
{
    if (empty($c['is_job'])) return edu_update_card_html($c);
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $ico = [
        'loc' => '<path d="M12 2a7 7 0 0 0-7 7c0 5.2 7 13 7 13s7-7.8 7-13a7 7 0 0 0-7-7zm0 9.5A2.5 2.5 0 1 1 12 6.5a2.5 2.5 0 0 1 0 5z"/>',
        'pay' => '<path d="M6 3h12v2h-4.1a5 5 0 0 1 1.3 2H18v2h-2.8a5 5 0 0 1-4.9 4H9.4l6.3 8h-2.6L7 13.2V11h3.3a3 3 0 0 0 2.8-2H6V7h7.1a3 3 0 0 0-2.8-2H6z"/>',
        'seat' => '<path d="M16 11a3 3 0 1 0-3-3 3 3 0 0 0 3 3zm-8 0a3 3 0 1 0-3-3 3 3 0 0 0 3 3zm0 2c-2.3 0-7 1.2-7 3.5V19h14v-2.5C15 14.2 10.3 13 8 13zm8 0c-.3 0-.6 0-1 .1a4.2 4.2 0 0 1 2 3.4V19h6v-2.5c0-2.3-4.7-3.5-7-3.5z"/>',
        'cap' => '<path d="M12 3 1 9l11 6 9-4.9V17h2V9zM5 13.2V17c0 1.7 3.1 3 7 3s7-1.3 7-3v-3.8l-7 3.8z"/>',
    ];
    $row = fn($k, $t) => $t !== '' ? '<span class="jc-i"><svg viewBox="0 0 24 24" aria-hidden="true">' . $ico[$k] . '</svg><span>' . $e($t) . '</span></span>' : '';
    $logo = $c['logo'] !== '' ? '<img src="' . $e($c['logo']) . '" alt="" width="48" height="48" loading="lazy" decoding="async">' : '<span class="jc-ini" aria-hidden="true">' . $e(mb_strtoupper(mb_substr($c['org'] ?: $c['title'], 0, 1))) . '</span>';
    $pay = preg_match('/^\d/', $c['salary']) ? '₹' . $c['salary'] : $c['salary'];
    $info = $row('loc', $c['location']) . $row('pay', $pay) . $row('seat', $c['vacancies'] !== '' && is_numeric(str_replace(',', '', $c['vacancies'])) ? number_format((int)str_replace(',', '', $c['vacancies'])) : $c['vacancies']) . $row('cap', $c['qualification']);
    $last = $c['last_date'] !== '' && strtotime($c['last_date']) ? '<span class="jc-last">Last Date : <b>' . $e(date('j F Y', strtotime($c['last_date']))) . '</b></span>' : '<span class="jc-more">View details</span>';
    return '<article class="jc" data-q="' . $e(edu_job_qual_group($c['qualification'])) . '">'
        . '<a class="jc-link" href="' . $e($c['url']) . '" aria-label="' . $e($c['full_title']) . '"></a>'
        . '<button type="button" class="jc-share" data-share="' . $e($c['url']) . '" data-title="' . $e($c['full_title']) . '" aria-label="Share"><svg viewBox="0 -960 960 960" aria-hidden="true"><path d="M680-80q-50 0-85-35t-35-85q0-6 3-28L282-392q-16 15-37 23.5t-45 8.5q-50 0-85-35t-35-85q0-50 35-85t85-35q24 0 45 8.5t37 23.5l281-164q-2-7-2.5-13.5T560-760q0-50 35-85t85-35q50 0 85 35t35 85q0 50-35 85t-85 35q-24 0-45-8.5T598-672L317-508q2 7 2.5 13.5t.5 14.5q0 8-.5 14.5T317-452l281 164q16-15 37-23.5t45-8.5q50 0 85 35t35 85q0 50-35 85t-85 35Zm0-80q17 0 28.5-11.5T720-200q0-17-11.5-28.5T680-240q-17 0-28.5 11.5T640-200q0 17 11.5 28.5T680-160ZM200-440q17 0 28.5-11.5T240-480q0-17-11.5-28.5T200-520q-17 0-28.5 11.5T160-480q0 17 11.5 28.5T200-440Zm480-280q17 0 28.5-11.5T720-760q0-17-11.5-28.5T680-800q-17 0-28.5 11.5T640-760q0 17 11.5 28.5T680-720Z"/></svg></button>'
        . '<div class="jc-top"><span class="jc-logo">' . $logo . '</span><span class="jc-head"><h3 class="jc-title">' . $e($c['title']) . '</h3>' . ($c['org'] !== '' ? '<span class="jc-org">' . $e($c['org']) . '</span>' : '') . '</span></div>'
        . ($info !== '' ? '<div class="jc-info">' . $info . '</div>' : '')
        . '<div class="jc-foot"><span class="jc-ago">' . $e(edu_job_ago($c['date'])) . '</span>' . $last . '</div>'
        . '</article>';
}

/** The card grid (+ qualification chips when the list has more than one kind) and, once per page, its CSS and script. */
function edu_job_cards(PDO $pdo, array $posts, bool $chips = true): string
{
    static $assets = false;
    $cards = edu_job_cards_data($pdo, $posts);
    if (!$cards) return '<p class="no-posts-message">No jobs here yet.</p>';
    $groups = array_values(array_unique(array_filter(array_map(fn($c) => !empty($c['is_job']) ? edu_job_qual_group($c['qualification']) : '', $cards))));
    $labels = ['below10' => '5th–9th', '10th' => '10th', '12th' => '12th', 'iti' => 'ITI', 'diploma' => 'Diploma', 'graduation' => 'Graduation', 'pg' => 'PG', 'other' => 'Other'];
    $html = '';
    if (!$assets) { $assets = true; $html .= edu_job_cards_assets(); }
    if ($chips && count($groups) > 1) {
        $html .= '<div class="jc-chips" role="toolbar" aria-label="Filter by qualification"><button type="button" class="on" data-f="">All</button>';
        foreach (array_keys($labels) as $g) if (in_array($g, $groups, true)) $html .= '<button type="button" data-f="' . $g . '">' . $labels[$g] . '</button>';
        $html .= '</div>';
    }
    $html .= '<div class="jc-grid">' . implode('', array_map('edu_job_card_html', $cards)) . '</div>';
    return $html;
}

function edu_job_cards_assets(): string
{
    return <<<'HTML'
<style>
.jc-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin:6px 0 18px}
.jc{position:relative;min-width:0;display:flex;flex-direction:column;padding:12px 14px;border:1px solid #dadce0;border-radius:8px;background:#fff;font-family:Roboto,system-ui,-apple-system,"Segoe UI",sans-serif;transition:box-shadow .2s,border-color .2s}
.jc:hover{border-color:#c4c7cc;box-shadow:0 4px 14px rgba(16,24,40,.08)}
.jc-link{position:absolute;inset:0;z-index:1;border-radius:8px}
.jc-link:focus-visible{outline:2px solid var(--gc-accent,#0c6878);outline-offset:2px}
.jc-share{position:absolute;top:10px;right:10px;z-index:2;width:30px;height:30px;padding:0;border:0;border-radius:50%;background:none;color:#202124;cursor:pointer;display:grid;place-items:center}
.jc-share:hover{background:#f1f3f4}.jc-share svg{width:20px;height:20px;fill:currentColor}
.jc-top{display:flex;gap:12px;align-items:center;padding-right:30px}
.jc-logo{flex-shrink:0;width:50px;height:50px;border:1px solid #e8eaed;border-radius:6px;background:#fff;display:grid;place-items:center;overflow:hidden}
.jc-logo img{width:100%;height:100%;object-fit:contain}
.jc-ini{font-size:20px;font-weight:700;color:var(--gc-accent,#0c6878)}
.jc-head{min-width:0;flex:1}
.jc-thumb{flex-shrink:0;width:96px;height:54px;border-radius:6px;overflow:hidden;background:#f1f3f4;display:grid;place-items:center}.jc-thumb img{width:100%;height:100%;object-fit:cover}
.jc .jc-title2{white-space:normal;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;font-size:15.5px;line-height:1.35}
.jc-u .jc-top{padding-right:0}
.jc .jc-title{margin:0;font-size:17px;font-weight:700;line-height:1.3;color:#202124;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.jc-org{display:block;margin-top:3px;font-size:15px;color:#202124;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.jc-info{display:grid;grid-template-columns:1fr 1fr;gap:6px 14px;margin:10px 0 0}
.jc-i{display:flex;align-items:center;gap:7px;min-width:0;font-size:13.5px;color:#3c4043}
.jc-i svg{flex-shrink:0;width:15px;height:15px;fill:#80868b}
.jc-i span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.jc-foot{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:auto;padding-top:9px;border-top:1px dashed #dadce0;font-size:14px;color:#3c4043}
.jc-info+.jc-foot{margin-top:10px}.jc-top+.jc-foot{margin-top:12px}
.jc-last,.jc-ago{white-space:nowrap}.jc-last b{color:#c5221f;font-weight:700}
.jc-more{color:var(--gc-accent,#0c6878);font-weight:600}
.jc-chips{display:flex;gap:8px;overflow-x:auto;margin:4px 0 14px;padding-bottom:2px;scrollbar-width:none}
.jc-chips::-webkit-scrollbar{display:none}
.jc-chips button{flex-shrink:0;padding:6px 14px;border:1px solid #dadce0;border-radius:99px;background:#fff;color:#3c4043;font:500 14px/1.2 Roboto,system-ui,sans-serif;cursor:pointer}
.jc-chips button.on{background:var(--gc-accent,#0c6878);border-color:var(--gc-accent,#0c6878);color:#fff}
@media (max-width:1024px){.jc-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:640px){.jc-foot{font-size:13px;gap:6px}.jc-grid{grid-template-columns:minmax(0,1fr);gap:12px}.jc .jc-title{font-size:16px}.jc-org{font-size:14px}}
[data-theme="dark"] .jc{background:#151b23;border-color:#263040}[data-theme="dark"] .jc .jc-title,[data-theme="dark"] .jc-org,[data-theme="dark"] .jc-share{color:#e5e7eb}[data-theme="dark"] .jc-i,[data-theme="dark"] .jc-foot{color:#c4c7cc}[data-theme="dark"] .jc-foot{border-color:#263040}[data-theme="dark"] .jc-logo{border-color:#263040}[data-theme="dark"] .jc-chips button{background:#151b23;border-color:#263040;color:#d1d5db}
</style>
<script>
document.addEventListener('click', function (e) {
  var s = e.target.closest && e.target.closest('.jc-share');
  if (s) {
    e.preventDefault();
    var url = new URL(s.getAttribute('data-share'), location.href).href, t = s.getAttribute('data-title') || document.title;
    if (navigator.share) { navigator.share({ title: t, url: url }).catch(function () {}); return; }
    var done = function () { var n = document.createElement('div'); n.textContent = 'Link copied'; n.setAttribute('style', 'position:fixed;left:50%;bottom:24px;transform:translateX(-50%);z-index:99999;padding:10px 18px;border-radius:99px;background:#202124;color:#fff;font:600 14px system-ui,sans-serif'); document.body.appendChild(n); setTimeout(function () { n.remove(); }, 1800); };
    if (navigator.clipboard) navigator.clipboard.writeText(url).then(done, function () { prompt('Copy this link', url); }); else prompt('Copy this link', url);
    return;
  }
  var c = e.target.closest && e.target.closest('.jc-chips button');
  if (c) {
    var bar = c.parentNode, f = c.getAttribute('data-f'), grid = bar.nextElementSibling;
    bar.querySelectorAll('button').forEach(function (b) { b.classList.toggle('on', b === c); });
    if (grid) grid.querySelectorAll('.jc').forEach(function (card) { card.style.display = !f || card.getAttribute('data-q') === f ? '' : 'none'; });
  }
});
</script>
HTML;
}

/**
 * Homepage slider: job-card posts only, 2 cards stacked per slide, the next slide peeks in (~15%),
 * with the same bar indicator as the "Also Read" slider. Empty string when no post has a job card.
 */
function edu_job_slider(PDO $pdo, int $limit = 12): string
{
    $posts = $pdo->query("SELECT p.id, p.title, p.slug, p.date FROM posts p JOIN post_meta jm ON jm.post_id = p.id AND jm.meta_key = 'job_card' AND jm.meta_value = '1' WHERE p.status = 'published' ORDER BY p.date DESC LIMIT " . max(1, $limit))->fetchAll(PDO::FETCH_ASSOC);
    $cards = edu_job_cards_data($pdo, $posts);
    if (!$cards) return '';
    $slides = '';
    foreach (array_chunk($cards, 2) as $pair) $slides .= '<div class="jss-slide">' . implode('', array_map('edu_job_card_html', $pair)) . '</div>';
    $dots = implode('', array_map(fn($i) => '<button type="button" aria-label="Slide ' . ($i + 1) . '"' . ($i === 0 ? ' class="is-active"' : '') . '></button>', range(0, (int)ceil(count($cards) / 2) - 1)));
    return edu_job_cards_assets() . <<<HTML
<section class="jss" aria-labelledby="jss-h">
  <style>
  .jss{margin:30px 0 8px}
  .jss-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin:0 0 12px}
  .jss .jss-h2{margin:0;font-size:22px;font-weight:800;color:var(--text-primary,#111827)}
  .jss-all{font-weight:600;font-size:14.5px;color:var(--gc-accent,#0c6878) !important;white-space:nowrap}
  .jss-track{display:flex;gap:14px;overflow-x:auto;scroll-snap-type:x mandatory;scroll-behavior:smooth;overscroll-behavior-x:contain;-webkit-overflow-scrolling:touch;scrollbar-width:none;padding:2px 2px 4px;cursor:grab}
  .jss-track::-webkit-scrollbar{display:none}
  .jss-track.is-dragging{cursor:grabbing;scroll-snap-type:none;scroll-behavior:auto;user-select:none}
  .jss-slide{flex:0 0 calc((100% - 28px) / 3.18);min-width:0;display:flex;flex-direction:column;gap:14px;scroll-snap-align:start}
  .jss-slide .jc{flex:1}
  .jss-dots{display:flex;justify-content:center;align-items:center;gap:6px;margin-top:14px}
  .jss-dots button{width:14px;height:4px;padding:0;border:0;border-radius:999px;background:#d1d5db;cursor:pointer;transition:width .25s ease,background .25s ease}
  .jss-dots button.is-active{width:30px;background:var(--gc-accent,#0c6878)}
  @media (max-width:1024px){.jss-slide{flex-basis:calc((100% - 14px) / 2.2)}}
  @media (max-width:640px){.jss-slide{flex-basis:85%;gap:12px}.jss-track{gap:12px}.jss .jss-h2{font-size:20px}}
  </style>
  <div class="jss-head"><h2 class="jss-h2" id="jss-h">Latest Jobs</h2><a class="jss-all" href="/latest-updates">View all ›</a></div>
  <div class="jss-track" tabindex="0" aria-label="Latest jobs — swipe for more">{$slides}</div>
  <div class="jss-dots">{$dots}</div>
  <script>
  (function () {
    var box = document.currentScript.parentNode, t = box.querySelector('.jss-track'), dots = box.querySelectorAll('.jss-dots button'), slides = t.children;
    function cur() { var x = t.scrollLeft, best = 0, d = 1e9; for (var i = 0; i < slides.length; i++) { var dd = Math.abs(slides[i].offsetLeft - t.offsetLeft - x); if (dd < d) { d = dd; best = i; } } if (t.scrollLeft + t.clientWidth >= t.scrollWidth - 4) best = slides.length - 1; return best; }
    function paint() { var c = cur(); dots.forEach(function (b, i) { b.classList.toggle('is-active', i === c); }); }
    var raf = 0; t.addEventListener('scroll', function () { cancelAnimationFrame(raf); raf = requestAnimationFrame(paint); }, { passive: true });
    dots.forEach(function (b, i) { b.addEventListener('click', function () { t.scrollTo({ left: slides[i].offsetLeft - t.offsetLeft, behavior: 'smooth' }); }); });
    // mouse drag on desktop (touch scrolls natively); a drag never opens a card
    var down = false, moved = false, sx = 0, sl = 0;
    t.addEventListener('mousedown', function (e) { down = true; moved = false; sx = e.pageX; sl = t.scrollLeft; });
    window.addEventListener('mousemove', function (e) { if (!down) return; var dx = e.pageX - sx; if (Math.abs(dx) > 5) { moved = true; t.classList.add('is-dragging'); t.scrollLeft = sl - dx; } });
    window.addEventListener('mouseup', function () { if (!down) return; down = false; if (moved) { t.classList.remove('is-dragging'); var c = cur(); t.scrollTo({ left: slides[c].offsetLeft - t.offsetLeft, behavior: 'smooth' }); } });
    t.addEventListener('click', function (e) { if (moved) { e.preventDefault(); e.stopPropagation(); moved = false; } }, true);
    t.addEventListener('dragstart', function (e) { e.preventDefault(); });
  })();
  </script>
</section>
HTML;
}

/**
 * Google "JobPosting" structured data for a post whose job card is on (Google for Jobs).
 * Built from the job card: title, organisation + logo, location (state, India), salary (when it has a number),
 * seats, qualification, last date → validThrough. Returns '' when the post has no job card.
 */
function edu_job_posting_jsonld(PDO $pdo, array $post, array $pmeta): string
{
    if (($pmeta['job_card'] ?? '') !== '1') return '';
    $abs = fn($u) => $u === '' ? '' : (preg_match('#^https?://#', $u) ? $u : rtrim(SITE_URL, '/') . '/' . ltrim($u, '/'));
    $title = trim((string)($pmeta['job_post_name'] ?? '')) ?: (string)$post['title'];
    $org = trim((string)($pmeta['job_org'] ?? '')) ?: (string)($post['cat_name'] ?? '') ?: edu_site_name();
    $logo = (string)($pmeta['job_logo'] ?? '');
    if ($logo === '' && !empty($post['banner_path'])) $logo = (string)$post['banner_path'];
    $state = '';
    if (!empty($post['state_id'])) { $st = $pdo->prepare("SELECT state_name FROM states WHERE id = ?"); $st->execute([(int)$post['state_id']]); $state = (string)$st->fetchColumn(); }
    $loc = trim((string)($pmeta['job_location'] ?? ''));
    if ($state === '' && $loc !== '' && !preg_match('/all india|india$/i', $loc)) $state = $loc;
    $html = preg_replace('#<(script|style|iframe|noscript)\b[^>]*>.*?</\1>#is', '', (string)$post['content']);
    $desc = trim(mb_substr(strip_tags((string)$html, '<p><br><ul><ol><li><strong><b><em><h2><h3><h4><table><thead><tbody><tr><th><td>'), 0, 20000));
    if ($desc === '') $desc = (string)($pmeta['description'] ?? $title);
    $job = [
        '@context' => 'https://schema.org', '@type' => 'JobPosting',
        'title' => mb_substr($title, 0, 150), 'description' => $desc,
        'identifier' => ['@type' => 'PropertyValue', 'name' => $org, 'value' => (string)$post['id']],
        'datePosted' => date('c', strtotime((string)$post['date'])),
        'employmentType' => 'FULL_TIME', 'directApply' => false,
        'hiringOrganization' => array_filter(['@type' => 'Organization', 'name' => $org, 'logo' => $logo !== '' ? $abs($logo) : null]),
        'jobLocation' => ['@type' => 'Place', 'address' => array_filter(['@type' => 'PostalAddress', 'addressRegion' => $state ?: null, 'addressCountry' => 'IN'])],
        'url' => rtrim(SITE_URL, '/') . postUrl((string)$post['slug'], (int)$post['id']),
    ];
    if ($state === '') $job['applicantLocationRequirements'] = ['@type' => 'Country', 'name' => 'India'];
    if (!empty($post['last_date']) && strtotime((string)$post['last_date'])) $job['validThrough'] = date('Y-m-d', strtotime((string)$post['last_date'])) . 'T23:59:59+05:30';
    $seats = preg_replace('/\D/', '', (string)($pmeta['job_vacancies'] ?? ''));
    if ($seats !== '' && (int)$seats > 0) $job['totalJobOpenings'] = (int)$seats;
    $pay = (string)($pmeta['job_salary'] ?? '');
    if (preg_match('/(\d[\d,]*(?:\.\d+)?)/', $pay, $m)) {
        $amount = (float)str_replace(',', '', $m[1]);
        $unit = preg_match('/year|annum|annual|p\.?a\b|yearly/i', $pay) ? 'YEAR' : (preg_match('/day|daily/i', $pay) ? 'DAY' : (preg_match('/hour/i', $pay) ? 'HOUR' : 'MONTH'));
        if ($amount > 0) $job['baseSalary'] = ['@type' => 'MonetaryAmount', 'currency' => 'INR', 'value' => ['@type' => 'QuantitativeValue', 'value' => $amount, 'unitText' => $unit]];
    }
    $q = trim((string)($pmeta['job_qualification'] ?? ''));
    if ($q !== '') {
        $cat = match (edu_job_qual_group($q)) { '10th', '12th' => 'high school', 'iti', 'diploma' => 'associate degree', 'graduation' => 'bachelor degree', 'pg' => 'postgraduate degree', default => '' };
        $job['educationRequirements'] = array_filter(['@type' => 'EducationalOccupationalCredential', 'credentialCategory' => $cat ?: null, 'name' => $q]);
        $job['qualifications'] = $q;
    }
    return '<script type="application/ld+json" class="edu-jobposting">' . json_encode($job, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . '</script>';
}
