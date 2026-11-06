<?php
// Ad Inserter (Admin → Ad Inserter): 16 ad blocks, each with its own pages, insertion point and alignment,
// plus global header/footer code. Same model as the new CMS. Scripts follow "Delay ads and tracking scripts".
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/perf.php';

function edu_ads_config(): array
{
    $cfg = edu_settings('ads');
    $blocks = [];
    for ($i = 1; $i <= 16; $i++) {
        $b = $cfg['blocks'][$i - 1] ?? [];
        $blocks[] = [
            'id' => $i, 'code' => (string)($b['code'] ?? ''), 'enabled' => !empty($b['enabled']),
            'pages' => array_values(array_intersect((array)($b['pages'] ?? ['post']), ['post', 'homepage', 'category', 'search', 'tag'])),
            'insertion' => (string)($b['insertion'] ?? 'disabled'), 'alignment' => (string)($b['alignment'] ?? 'default'),
            'paragraph' => max(1, (int)($b['paragraph'] ?? 1)), 'min_height' => max(0, (int)($b['min_height'] ?? 0)),
        ];
    }
    return ['blocks' => $blocks, 'global_header' => (string)($cfg['global_header'] ?? ''), 'global_footer' => (string)($cfg['global_footer'] ?? ''),
            'ads_txt_enabled' => !empty($cfg['ads_txt_enabled']), 'ads_txt' => (string)($cfg['ads_txt'] ?? '')];
}

function edu_ad_page(): string
{
    $s = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
    return match ($s) {
        'post.php' => 'post', 'index.php' => 'homepage', 'search.php' => 'search', 'tag.php', 'author.php' => 'tag',
        default => 'category',
    };
}

function edu_ad_wrap(array $b): string
{
    $cls = ['default' => '', 'left' => 'ai-block-left', 'center' => 'ai-block-center', 'right' => 'ai-block-right', 'float-left' => 'ai-block-float-left', 'float-right' => 'ai-block-float-right'][$b['alignment']] ?? '';
    return '<div class="ai-block' . ($cls ? ' ' . $cls : '') . '"' . ($b['min_height'] ? ' style="min-height:' . $b['min_height'] . 'px"' : '') . '>' . edu_delay_scripts($b['code']) . '</div>';
}

/** Ads for one insertion point on the current page. */
function edu_ads(string $insertion): string
{
    static $cfg = null;
    $cfg ??= edu_ads_config();
    $page = edu_ad_page();
    $out = '';
    foreach ($cfg['blocks'] as $b) {
        if ($b['enabled'] && trim($b['code']) !== '' && $b['insertion'] === $insertion && in_array($page, $b['pages'], true)) $out .= edu_ad_wrap($b);
    }
    return $out;
}

/** Puts "before/after paragraph N" blocks into article HTML (only the article's own top-level paragraphs count). */
function edu_ads_in_content(string $html): string
{
    $cfg = edu_ads_config();
    $page = edu_ad_page();
    $before = [];
    $after = [];
    foreach ($cfg['blocks'] as $b) {
        if (!$b['enabled'] || trim($b['code']) === '' || !in_array($page, $b['pages'], true)) continue;
        if ($b['insertion'] === 'before_paragraph') $before[$b['paragraph']][] = edu_ad_wrap($b);
        if ($b['insertion'] === 'after_paragraph') $after[$b['paragraph']][] = edu_ad_wrap($b);
    }
    if (!$before && !$after) return $html;
    // Find top-level <p>…</p> ranges (skip ones inside lists, tables, quotes and inserted boxes).
    $ranges = [];
    $depth = 0;
    $pStart = -1;
    preg_match_all('/<(\/?)([a-zA-Z][a-zA-Z0-9-]*)\b[^>]*?(\/?)>/', $html, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
    $nestedTags = ['blockquote', 'ul', 'ol', 'table', 'figure', 'aside', 'details', 'pre', 'nav', 'form', 'section'];
    $stack = [];
    foreach ($m as $t) {
        $tag = strtolower($t[2][0]);
        if (in_array($tag, ['br', 'img', 'hr', 'input', 'meta', 'link', 'source', 'wbr', 'col', 'embed'], true) || $t[3][0] === '/') continue;
        if ($t[1][0] !== '/') {
            if ($tag === 'p' && $depth === 0 && $pStart < 0) $pStart = $t[0][1];
            $isNested = in_array($tag, $nestedTags, true) || ($tag === 'div' && preg_match('/class=["\'][^"\']*(nb-|ai-block)/', $t[0][0]));
            $stack[] = $isNested;
            if ($isNested) $depth++;
        } else {
            if ($tag === 'p' && $pStart >= 0 && $depth === 0) {
                $ranges[] = [$pStart, $t[0][1] + strlen($t[0][0])];
                $pStart = -1;
            }
            if ($stack && array_pop($stack)) $depth--;
        }
    }
    // Insert from the end so earlier offsets stay valid.
    for ($i = count($ranges); $i >= 1; $i--) {
        [$s, $e] = $ranges[$i - 1];
        if (!empty($after[$i])) $html = substr_replace($html, implode('', $after[$i]), $e, 0);
        if (!empty($before[$i])) $html = substr_replace($html, implode('', $before[$i]), $s, 0);
    }
    return $html;
}

function edu_ads_head(): string
{
    $g = edu_ads_config()['global_header'];
    $css = '<style>.ai-block{margin:18px 0;max-width:100%;overflow:hidden;clear:both}.ai-block-center{text-align:center}.ai-block-left{text-align:left}.ai-block-right{text-align:right}.ai-block-float-left{float:left;margin:6px 18px 10px 0;clear:none}.ai-block-float-right{float:right;margin:6px 0 10px 18px;clear:none}.ai-block:empty{display:none}</style>';
    return $css . ($g !== '' ? edu_delay_scripts($g) : '');
}

function edu_ads_footer(): string
{
    $g = edu_ads_config()['global_footer'];
    return edu_ads('footer') . ($g !== '' ? edu_delay_scripts($g) : '');
}
