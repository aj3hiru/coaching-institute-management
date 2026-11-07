<?php
// Homepage "Meet our teachers" and "Meet our toppers" — three designs each (chosen in Online Store → Homepage).
// $t rows come from sh_home_teachers(); $p rows from hm_toppers. Each function returns one <section>.

const HM_TEACHER_STYLES = ['1' => 'Faculty cards (with section label)', '2' => 'Photo overlay', '3' => 'Profile rows with passport photo (with section label)'];
const HM_TOPPER_STYLES = ['4' => 'Passport cards (the first design)', '1' => 'Score cards (with section label)', '2' => 'Hall of fame posters (with section label)', '3' => 'Moving strip'];

function hm_e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function hm_initial(string $n): string { return hm_e(mb_strtoupper(mb_substr(trim(preg_replace('/\(.*?\)/', '', $n)), 0, 1))); }
function hm_clean_name(string $n): string { return trim($n); } // demo names keep their "(Demo)" mark
/** "97.4% · Class 10 Board" → ['97.4%', 'Class 10 Board']; no number → ['', whole text]. */
function hm_score(string $r): array
{
    if (preg_match('/^\s*(\d{1,3}(?:\.\d+)?\s*%)\s*(?:[·\-–—|,:]\s*)?(.*)$/u', $r, $m)) return [str_replace(' ', '', $m[1]), trim($m[2])];
    if (preg_match('/(rank\s*\d+|\d+(?:st|nd|rd|th)\s*rank|AIR\s*\d+)/i', $r, $m)) return [$m[1], trim(str_replace($m[1], '', $r), " ·-–—|,")];
    return ['', $r];
}
function hm_photo(string $src, string $name, string $cls = ''): string
{
    return $src !== '' ? '<img class="' . $cls . '" src="' . hm_e($src) . '" alt="' . hm_e($name) . '" loading="lazy">' : '<span class="' . $cls . ' hm-ph-none">' . hm_initial($name) . '</span>';
}
function hm_head(string $title, string $label = '', string $sub = ''): string
{
    return '<div class="hm-h' . ($label !== '' ? ' hm-h-l' : '') . '"><div>' . ($label !== '' ? '<span class="hm-label">' . hm_e($label) . '</span>' : '') . '<h2>' . hm_e($title) . '</h2>' . ($sub !== '' ? '<p class="hm-hsub">' . hm_e($sub) . '</p>' : '') . '</div></div>';
}
function hm_slider(string $cls, string $slides, int $auto = 0): string
{
    return '<div class="' . $cls . '" data-hs' . ($auto ? ' data-auto="' . $auto . '"' : '') . '><div class="hs-track">' . $slides . '</div>'
        . '<button type="button" class="hs-prev" aria-label="Previous">‹</button><button type="button" class="hs-next" aria-label="Next">›</button><div class="hs-dots"></div></div>';
}
function hm_ico(string $n): string { return function_exists('sh_icon') ? sh_icon($n) : ''; }

function hm_teachers_html(array $t, string $style, string $title = 'Meet our teachers'): string
{
    if (!$t) return '';
    $s = '';
    if ($style === '2') {
        foreach ($t as $x) $s .= '<div class="hs-s"><div class="tB-card">' . hm_photo($x['photo'], $x['name'], 'tB-ph')
            . '<div class="tB-in"><b>' . hm_e($x['name']) . '</b>' . ($x['subject'] !== '' ? '<span>' . hm_e($x['subject']) . '</span>' : '')
            . '<div class="tB-more">' . ($x['qualification'] !== '' ? '<small>' . hm_ico('notes') . hm_e($x['qualification']) . '</small>' : '') . ($x['experience'] !== '' ? '<small>' . hm_ico('calendar') . hm_e($x['experience']) . '</small>' : '') . '</div></div></div></div>';
        return '<section class="hm-sec hm-tch-s tB"><div class="sp-wrap">' . hm_head($title, '', 'Experienced teachers who know how to make every chapter simple.') . hm_slider('hm-tch', $s) . '</div></section>';
    }
    if ($style === '3') {
        foreach ($t as $x) {
            $yrs = preg_match('/(\d+)\s*\+?\s*(years?|yrs?)/i', $x['experience'], $m) ? $m[1] : '';
            $s .= '<div class="hs-s"><div class="tC-card"><span class="tC-ring">' . hm_photo($x['photo'], $x['name'], 'tC-ph') . '</span>'
                . '<div class="tC-b"><b>' . hm_e($x['name']) . '</b>' . ($x['subject'] !== '' ? '<span class="tC-sub">' . hm_e($x['subject']) . '</span>' : '')
                . ($x['qualification'] !== '' ? '<small>' . hm_e($x['qualification']) . '</small>' : '') . ($x['about'] !== '' ? '<p>' . hm_e($x['about']) . '</p>' : '')
                . ($yrs !== '' ? '<span class="tC-exp">' . hm_ico('calendar') . '<b>' . hm_e($yrs) . '+</b> years experience</span>' : ($x['experience'] !== '' ? '<span class="tC-exp">' . hm_ico('calendar') . hm_e($x['experience']) . '</span>' : '')) . '</div></div></div>';
        }
        return '<section class="hm-sec hm-tch-s tC"><div class="sp-wrap">' . hm_head($title, 'Our faculty') . hm_slider('hm-tch', $s) . '</div></section>';
    }
    foreach ($t as $x) $s .= '<div class="hs-s"><div class="tA-card"><span class="tA-phw">' . hm_photo($x['photo'], $x['name'], 'tA-ph') . '</span>'
        . '<div class="tA-b"><b>' . hm_e($x['name']) . '</b>' . ($x['subject'] !== '' ? '<span class="tA-sub">' . hm_e($x['subject']) . '</span>' : '')
        . '<ul>' . ($x['qualification'] !== '' ? '<li>' . hm_ico('notes') . hm_e($x['qualification']) . '</li>' : '') . ($x['experience'] !== '' ? '<li>' . hm_ico('calendar') . hm_e($x['experience']) . '</li>' : '') . '</ul></div></div></div>';
    return '<section class="hm-sec hm-tch-s tA"><div class="sp-wrap">' . hm_head($title, 'Our faculty') . hm_slider('hm-tch', $s) . '</div></section>';
}

function hm_toppers_html(array $p, string $style, string $title = 'Meet our toppers'): string
{
    if (!$p) return '';
    $src = fn($x) => $x['photo'] ? (function_exists('sh_upload_url') ? sh_upload_url($x['photo']) : '/' . ltrim($x['photo'], '/')) : '';
    $s = '';
    if ($style === '2') {
        foreach ($p as $k => $x) { [$sc, $rest] = hm_score((string)$x['result']); $n = hm_clean_name($x['name']);
            $s .= '<div class="hs-s"><div class="pB-card"><span class="pB-phw">' . hm_photo($src($x), $n, 'pB-ph') . '<span class="pB-badge">' . hm_ico('check') . '</span>' . ($x['year'] ? '<em>' . hm_e($x['year']) . '</em>' : '') . '</span>'
                . '<div class="pB-rib">' . ($sc !== '' ? '<b>' . hm_e($sc) . '</b>' : '') . '<span>' . hm_e($rest !== '' ? $rest : (string)$x['class_name']) . '</span></div>'
                . '<div class="pB-b"><b>' . hm_e($n) . '</b>' . ($x['class_name'] ? '<small>' . hm_e($x['class_name']) . '</small>' : '') . '</div></div></div>'; }
        return '<section class="hm-sec hm-top-s pB"><div class="sp-wrap">' . hm_head($title, 'Hall of fame', 'Our students who made us proud.') . hm_slider('hm-top', $s, 4500) . '</div></section>';
    }
    if ($style === '4') { // the first design: passport photo with the year, name, result box, class
        foreach ($p as $x) { $n = hm_clean_name($x['name']);
            $s .= '<div class="hs-s"><div class="hm-pc hm-pc-top"><span class="hm-pc-ph">' . ($src($x) !== '' ? '<img src="' . hm_e($src($x)) . '" alt="' . hm_e($n) . '" loading="lazy">' : '<b>' . hm_initial($n) . '</b>') . ($x['year'] ? '<em>' . hm_e($x['year']) . '</em>' : '') . '</span>'
                . '<b class="hm-pc-n">' . hm_e($n) . '</b>' . ($x['result'] ? '<span class="hm-pc-marks">Marks: <b>' . hm_e($x['result']) . '</b></span>' : '') . ($x['class_name'] ? '<small class="hm-pc-cls">' . hm_e($x['class_name']) . ($x['year'] ? ' · ' . hm_e($x['year']) : '') . '</small>' : '') . '</div></div>'; }
        return '<section class="hm-sec hm-top-s p4"><div class="sp-wrap">' . hm_head($title) . hm_slider('hm-top', $s, 4500) . '</div></section>';
    }
    if ($style === '3') {
        $chip = function ($x) use ($src) { [$sc, $rest] = hm_score((string)$x['result']); $n = hm_clean_name($x['name']);
            return '<div class="pC-chip">' . hm_photo($src($x), $n, 'pC-ph') . '<div><b>' . hm_e($n) . '</b><small>' . hm_e(trim(($x['class_name'] ?: '') . ($x['year'] ? ' · ' . $x['year'] : ''), ' ·')) . '</small></div>' . ($sc !== '' ? '<span class="pC-sc">' . hm_e($sc) . '</span>' : '<span class="pC-sc pC-txt">' . hm_e($rest) . '</span>') . '</div>'; };
        $half = (int)ceil(count($p) / 2); $r1 = array_slice($p, 0, $half); $r2 = array_slice($p, $half) ?: $r1;
        $row = function (array $rows, string $dir) use ($chip) { $h = implode('', array_map($chip, $rows)); return '<div class="pC-row ' . $dir . '"><div class="pC-move">' . $h . $h . $h . '</div></div>'; };
        return '<section class="hm-sec hm-top-s pC"><div class="sp-wrap">' . hm_head($title, '', 'Students who scored big with us.') . '</div><div class="pC-wall" aria-label="Our toppers">' . $row($r1, 'l') . $row($r2, 'r') . '</div></section>';
    }
    foreach ($p as $x) { [$sc, $rest] = hm_score((string)$x['result']); $n = hm_clean_name($x['name']);
        $s .= '<div class="hs-s"><div class="pA-card"><span class="pA-ring">' . hm_photo($src($x), $n, 'pA-ph') . '</span>'
            . ($sc !== '' ? '<b class="pA-sc">' . hm_e($sc) . '</b>' : '') . '<b class="pA-n">' . hm_e($n) . '</b>'
            . '<small class="pA-r">' . hm_e($rest !== '' ? $rest : '') . '</small><span class="pA-y">' . hm_e(trim(($x['class_name'] ?: '') . ($x['year'] ? ' · ' . $x['year'] : ''), ' ·')) . '</span></div></div>'; }
    return '<section class="hm-sec hm-top-s pA"><div class="sp-wrap">' . hm_head($title, 'Our pride') . hm_slider('hm-top', $s, 4500) . '</div></section>';
}
