<?php
// Shared pieces of the archive pages (category, tag, author): page header, post cards (same cards as the homepage)
// and pagination.

/** Header block: small label, title, description and post count. */
function edu_archive_head(string $kicker, string $title, string $desc = '', int $count = 0): string
{
    return '<header class="nb-arch-head">'
        . ($kicker !== '' ? '<span class="nb-arch-kicker">' . htmlspecialchars($kicker) . '</span>' : '')
        . '<h1 class="nb-arch-title">' . htmlspecialchars($title) . '</h1>'
        . ($desc !== '' ? '<p class="nb-arch-desc">' . nl2br(htmlspecialchars($desc)) . '</p>' : '')
        . ($count > 0 ? '<span class="nb-arch-count">' . number_format($count) . ' ' . ($count === 1 ? 'post' : 'posts') . '</span>' : '')
        . '</header>';
}

/** Post cards in the homepage style. $posts rows need id, slug, title, date, content, category_id, banner_image, banner_alt, author_name, author_slug. */
function edu_archive_cards(PDO $pdo, array $posts): string
{
    if (!$posts) return '';
    $catIds = array_values(array_unique(array_filter(array_map(fn($p) => (int)($p['category_id'] ?? 0), $posts))));
    $cats = [];
    if ($catIds) {
        foreach ($pdo->query('SELECT id, name, slug FROM categories WHERE id IN (' . implode(',', $catIds) . ')') as $c) $cats[(int)$c['id']] = $c;
    }
    $fmt = function_exists('es') ? es('general', 'date_format', 'j F Y') : 'j F Y';
    $out = '<div class="nb-grid nb-grid--feat nb-arch-grid">';
    foreach ($posts as $i => $p) {
        $url = postUrl($p['slug'], $p['id']);
        $cat = $cats[(int)($p['category_id'] ?? 0)] ?? null;
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace('<', ' <', (string)($p['content'] ?? ''))), ENT_QUOTES, 'UTF-8')));
        $ex = mb_strlen($text) > 140 ? rtrim(mb_substr($text, 0, 140)) . '…' : $text;
        $img = !empty($p['banner_image'])
            ? '<img src="/' . htmlspecialchars(ltrim($p['banner_image'], '/')) . '" alt="' . htmlspecialchars($p['banner_alt'] ?: $p['title']) . '" width="640" height="360" loading="' . ($i < 3 ? 'eager' : 'lazy') . '" decoding="async">'
            : '<span class="nb-card-ph">' . htmlspecialchars(mb_strtoupper(mb_substr($p['title'], 0, 1))) . '</span>';
        $out .= '<article class="nb-card nb-card--feat">'
            . '<a class="nb-card-img" href="' . $url . '" tabindex="-1" aria-hidden="true">' . $img . '</a>'
            . '<div class="nb-card-body">'
            . ($cat ? '<a class="nb-card-cat" href="/categories/' . htmlspecialchars($cat['slug']) . '"><svg class="nb-card-bolt" viewBox="0 0 24 24" aria-hidden="true"><path d="M13 2 4 14h7l-1 8 9-12h-7l1-8z"/></svg>' . htmlspecialchars($cat['name']) . '</a>' : '')
            . '<h2 class="nb-card-title"><a href="' . $url . '">' . htmlspecialchars($p['title']) . '</a></h2>'
            . '<div class="nb-card-meta">' . (!empty($p['author_name']) ? '<span>By ' . (!empty($p['author_slug']) ? '<a href="/author/' . htmlspecialchars($p['author_slug']) . '">' . htmlspecialchars($p['author_name']) . '</a>' : htmlspecialchars($p['author_name'])) . '</span><span class="sep">·</span>' : '')
            . '<time class="nb-card-date" datetime="' . date('c', strtotime((string)$p['date'])) . '">' . date($fmt, strtotime((string)$p['date'])) . '</time></div>'
            . ($ex !== '' ? '<p class="nb-card-excerpt">' . htmlspecialchars($ex) . '</p>' : '')
            . '</div></article>';
    }
    return $out . '</div>';
}

/** Numbered pagination; $href(n) gives the address of page n. */
function edu_archive_pagination(int $page, int $total, callable $href): string
{
    if ($total <= 1) return '';
    $o = '<nav class="pagination nb-arch-pages" aria-label="Pages">';
    $o .= $page > 1 ? '<a href="' . htmlspecialchars($href($page - 1)) . '" rel="prev">&lsaquo; Prev</a>' : '<span class="disabled">&lsaquo; Prev</span>';
    $start = max(1, $page - 2);
    $end = min($total, $page + 2);
    if ($start > 1) $o .= '<a href="' . htmlspecialchars($href(1)) . '">1</a>' . ($start > 2 ? '<span>…</span>' : '');
    for ($i = $start; $i <= $end; $i++) $o .= $i === $page ? '<span class="current" aria-current="page">' . $i . '</span>' : '<a href="' . htmlspecialchars($href($i)) . '">' . $i . '</a>';
    if ($end < $total) $o .= ($end < $total - 1 ? '<span>…</span>' : '') . '<a href="' . htmlspecialchars($href($total)) . '">' . $total . '</a>';
    $o .= $page < $total ? '<a href="' . htmlspecialchars($href($page + 1)) . '" rel="next">Next &rsaquo;</a>' : '<span class="disabled">Next &rsaquo;</span>';
    return $o . '</nav>';
}

/** The theme's 404 page for a missing category / tag / author. */
function edu_archive_404(): void
{
    http_response_code(404);
    include ROOT_PATH . '/templates/page_not_found.php';
    exit;
}
