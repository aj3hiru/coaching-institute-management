<?php
// Theme options set in Admin → Customize. edu_theme() returns every option (saved values over the defaults below).
// While an admin is previewing in the Customizer (?czp=1), the unsaved draft from their session is used instead.
require_once __DIR__ . '/settings.php';

function edu_theme_defaults(): array
{
    return [
        // Colors
        'c_accent' => '#0c6878', 'c_accent_hover' => '#095666', 'c_body_bg' => '#ffffff', 'c_text' => '#373737',
        'c_heading' => '#111827', 'c_link' => '#1e73be', 'c_link_hover' => '#d50808', 'c_meta' => '#6b7280',
        'c_header_bg' => '#ffffff', 'c_header_text' => '#1d2327', 'c_strip_bg' => '#ffffff', 'c_strip_text' => '#1d2327',
        'c_strip_hover' => '', 'c_drawer_bg' => '#ffffff', 'c_drawer_text' => '#353543',
        'c_button_bg' => '', 'c_button_text' => '#ffffff', 'c_button_hover' => '',
        'c_footer_bg' => '#0c6878', 'c_footer_text' => '#ffffff', 'c_footer_link' => '#ffffff', 'c_footer_accent' => '#ffb700',
        'c_card_bg' => '#ffffff', 'c_card_border' => '#e5e7eb', 'c_toc_bg' => '', 'c_sidebar_title_bg' => '',
        // Typography
        'font_body' => 'system', 'font_heading' => 'system', 'font_size' => 16, 'line_height' => 1.7, 'heading_weight' => 700,
        'h1_size' => 30, 'h2_size' => 24, 'h3_size' => 20, 'font_display' => 'swap',
        // Header
        'h_sticky' => '0', 'h_search' => '1', 'h_voice' => '1', 'h_bell' => '1', 'h_socials' => '1', 'h_dark' => '1',
        'h_topbar' => '0', 'h_topbar_text' => '', 'h_width' => 1200, 'box_radius' => 5, 'h_search_placeholder' => 'e.g. SSC, UPSC, Railway...',
        'h_login' => '1', 'h_login_text' => 'Log in', 'h_login_url' => '', 'h_sub' => '1', 'h_sub_text' => 'Subscribe', 'h_sub_url' => '', 'h_sub_icon' => '',
        'h_drawer_title' => 'Hello, Reader', 'h_drawer_text' => 'Join our courses and grow your future',
        'h_drawer_hello' => '1', 'h_drawer_login' => '1', 'h_drawer_sub' => '1',
        // Menus
        'menu' => [
            ['label' => 'Home', 'url' => '/'], ['label' => 'Latest Posts', 'url' => '/latest-posts'],
            ['label' => 'About Us', 'url' => '/about-us'], ['label' => 'Contact Us', 'url' => '/contact-us'],
        ],
        'menu_align' => 'center', 'menu_style' => 'underline', 'menu_dark_icon' => '1',
        // Footer
        'f_totop' => '1', 'f_cats' => '1', 'f_cats_heading' => 'Job Categories', 'f_cats_count' => 6,
        'f_links_heading' => 'Quick Links',
        'f_links' => [
            ['label' => 'About Us', 'url' => '/about-us'], ['label' => 'Contact Us', 'url' => '/contact-us'],
            ['label' => 'Privacy Policy', 'url' => '/privacy-policy'], ['label' => 'Terms', 'url' => '/terms-of-service'], ['label' => 'Disclaimer', 'url' => '/disclaimer'],
            ['label' => 'Editorial Policy', 'url' => '/editorial-policy'], ['label' => 'Cookies Policy', 'url' => '/cookie-policy'],
        ],
        'f_follow' => '1', 'f_follow_heading' => 'Follow Us', 'f_follow_title' => 'Follow Us On Social Media',
        'f_follow_sub' => 'Get Latest Job Updates On Social Media', 'f_follow_btn' => '', 'f_follow_btn_url' => '',
        'f_copyright' => '© {year} {site}. All rights reserved.',
        // Sidebar (post pages)
        'sb_post' => '1', 'sb_heading' => 'More from {category}', 'sb_count' => 5, 'sb_source' => 'category', 'sb_sticky' => '0', 'nf_title' => 'Page not found', 'nf_text' => 'The page you are looking for may have moved or no longer exists. Search the site, or jump to one of these.', 'nf_post_title' => 'This post is not available', 'nf_post_text' => 'The post you opened may have been removed or its address has changed. Search for it, or pick up something new below.', 'nf_courses' => '1', 'nf_cats' => '1', 'nf_posts' => '1', 'sb_search' => '1', 'sb_search_heading' => 'Search', 'sb_cats' => '1', 'sb_cats_heading' => 'Categories', 'sb_cats_count' => 10, 'p_toc_heading' => 'Table of Contents',
        // Post template
        'p_breadcrumb' => '1', 'p_cat_badge' => '1', 'p_author_date' => '1', 'p_date_shown' => 'updated', 'p_reading_time' => '1',
        'p_pref_source' => '1', 'p_gnews' => '1', 'p_gnews_url' => 'https://news.google.com/home', 'p_featured' => '1', 'p_caption' => '0', 'p_summary' => '1', 'p_summary_heading' => 'Summary', 'p_summary_pos' => 'before_content',
        'p_toc' => '1', 'p_toc_collapsed' => '0', 'p_keypoints' => '1', 'p_keypoints_heading' => 'Key Points',
        'p_keypoints_style' => 'dot', 'p_keypoints_after' => 1, 'p_faqs' => '1', 'p_tags' => '1', 'p_share' => '1',
        'p_author_box' => '1', 'p_comments' => '1', 'p_related' => '0', 'p_related_heading' => 'Related Posts', 'p_related_count' => 3,
        'p_join' => '0', 'p_join_whatsapp' => '', 'p_join_telegram' => '',
        // Also Read
        'ar_enabled' => '1', 'ar_label' => 'Also Read', 'ar_after' => 3, 'ar_count' => 1, 'ar_source' => 'category',
        // Reading progress
        'rp_bar' => '1', 'rp_button' => '0',
        // Homepage & archives
        'home_heading' => 'Latest Posts', 'home_posts' => 7, 'home_lead' => '1', 'home_grid_more' => '0',
        'home_tables' => '1', 'home_hero' => '1', 'home_faqs' => '1', 'arch_columns' => 3,
        // Cookie consent
        'cc_enabled' => '0', 'cc_title' => 'We value your privacy', 'cc_message' => 'We use cookies to show ads and to understand how the site is used. You can accept all, reject non-essential cookies, or choose what to allow.',
        'cc_accept' => 'Accept all', 'cc_reject' => 'Reject', 'cc_customize' => 'Customize', 'cc_save' => 'Save choices',
        'cc_privacy_text' => 'Privacy Policy', 'cc_privacy_url' => '/privacy-policy', 'cc_position' => 'bottom',
        'cc_sticky' => '1', 'cc_reask' => 'never', 'cc_days' => 180, 'cc_footer_link' => '1', 'cc_region' => 'all',
        // Additional CSS
        'custom_css' => '',
        // ---- Customizer v2 (same structure as the new CMS) ----
        // Menus: 'menu' = Primary Menu (mobile menu; items may have children), the strip has its own list
        'strip_source' => 'custom', 'strip_items' => [
            ['label' => 'Home', 'url' => '/'], ['label' => 'Latest Posts', 'url' => '/latest-posts'],
            ['label' => 'About Us', 'url' => '/about-us'], ['label' => 'Contact Us', 'url' => '/contact-us'],
        ],
        // Footer link columns (each: heading + own links, or all categories automatically)
        'f_columns' => [
            ['title' => 'Job Categories', 'auto' => 'categories', 'count' => 6, 'links' => []],
            ['title' => 'Quick Links', 'auto' => '', 'count' => 6, 'links' => [
                ['label' => 'Blog', 'url' => '/blog'], ['label' => 'About Us', 'url' => '/about-us'], ['label' => 'Contact Us', 'url' => '/contact-us'],
                ['label' => 'Privacy Policy', 'url' => '/privacy-policy'], ['label' => 'Terms', 'url' => '/terms-of-service'], ['label' => 'Disclaimer', 'url' => '/disclaimer'],
                ['label' => 'Editorial Policy', 'url' => '/editorial-policy'], ['label' => 'Cookies Policy', 'url' => '/cookie-policy'],
            ]],
        ],
        'f_logo' => '', 'f_desc' => '', 'f_play' => '0', 'f_play_url' => '',
        // Site Identity extras
        'id_hide_title' => '1', 'id_hide_tagline' => '1', 'id_retina_logo' => '',
        // header brand: 'logo' (logo + optional title/tagline) or 'school' (round emblem + big name + a line such as the address)
        'id_brand_style' => 'school', 'id_emblem_h' => 52, 'id_brand_bg' => '1', 'id_brand_emblem' => '', 'id_header_title' => '', 'id_header_line' => '',
        'id_school_phone' => '', 'id_school_wa' => '', 'id_school_bar_text' => '', 'id_school_login_text' => 'Student Login',
        // Colors: global palette + every area (empty = theme default)
        'global_colors' => [
            ['slug' => 'base-3', 'name' => 'White', 'color' => '#ffffff'], ['slug' => 'contrast', 'name' => 'Contrast', 'color' => '#222222'],
            ['slug' => 'contrast-2', 'name' => 'Contrast 2', 'color' => '#373737'], ['slug' => 'accent-2', 'name' => 'Blue', 'color' => '#1e73be'],
            ['slug' => 'accent', 'name' => 'Accent', 'color' => '#0c6878'], ['slug' => 'accent-3', 'name' => 'Red', 'color' => '#d50808'],
            ['slug' => 'base', 'name' => 'Base', 'color' => '#e1e1e1'],
        ],
        'colors' => [],
        // Typography: Font Manager + Typography Manager
        'fonts' => [], 'typography' => [],
        // Also Read boxes and the reading progress button
        'ar_groups' => [['id' => 'ar1', 'after' => 3, 'count' => 1, 'source' => 'category', 'post_ids' => [], 'style' => 'card', 'label' => 'Also Read']],
        'rp_label' => 'In this article', 'rp_desktop' => '0',
    ];
}

/** Color areas of the Customizer (same as the CMS / GeneratePress). Each field becomes --t-<group>-<field>. */
const EDU_COLOR_GROUPS = [
    'body' => ['Body', ['background' => 'Background', 'text' => 'Text', 'link' => 'Link', 'link_hover' => 'Link Hover']],
    'top_bar' => ['Top Bar', ['background' => 'Background', 'text' => 'Text', 'link' => 'Link', 'link_hover' => 'Link Hover']],
    'header' => ['Header', ['racing_bg' => 'Background', 'racing_text' => 'Icons & Text', 'site_title' => 'Site Title', 'tagline' => 'Tagline']],
    'menu_strip' => ['Menu Strip (below header)', ['background' => 'Background', 'text' => 'Text', 'text_hover' => 'Text Hover / Current']],
    'off_canvas' => ['Mobile Menu (Off Canvas)', ['background' => 'Background', 'text' => 'Text', 'background_hover' => 'Item Background Hover', 'sub_bg' => 'Sub-Menu Background']],
    'buttons' => ['Buttons', ['background' => 'Background', 'background_hover' => 'Background Hover', 'text' => 'Text', 'text_hover' => 'Text Hover']],
    'content' => ['Content', [
        'background' => 'Background', 'text' => 'Text', 'link' => 'Link', 'link_hover' => 'Link Hover', 'title' => 'Content Title',
        'archive_title' => 'Archive Content Title', 'archive_title_hover' => 'Archive Content Title Hover', 'meta_text' => 'Entry Meta Text',
        'meta_link' => 'Entry Meta Links', 'meta_link_hover' => 'Entry Meta Links Hover', 'h1' => 'Heading 1 (H1) Color', 'h2' => 'Heading 2 (H2) Color',
        'h3' => 'Heading 3 (H3) Color', 'h4' => 'Heading 4 (H4) Color', 'h5' => 'Heading 5 (H5) Color', 'h6' => 'Heading 6 (H6) Color',
    ]],
    'cards' => ['Cards & Boxes', ['background' => 'Card Background', 'border' => 'Card Border', 'toc_bg' => 'Table of Contents Header']],
    'forms' => ['Forms', ['field_bg' => 'Form Background', 'field_bg_focus' => 'Form Background Focus', 'field_text' => 'Form Text', 'field_text_focus' => 'Form Text Focus', 'field_border' => 'Form Border', 'field_border_focus' => 'Form Border Focus']],
    'sidebar' => ['Sidebar Widgets', ['background' => 'Background', 'text' => 'Text', 'link' => 'Link', 'link_hover' => 'Link Hover', 'title' => 'Widget Title', 'title_bg' => 'Widget Title Background']],
    'footer_widgets' => ['Footer Widgets', ['background' => 'Background', 'text' => 'Text', 'link' => 'Link', 'link_hover' => 'Link Hover', 'title' => 'Widget Title', 'accent' => 'Heading Underline']],
    'footer_bar' => ['Footer Bar', ['background' => 'Background', 'text' => 'Text', 'link' => 'Link', 'link_hover' => 'Link Hover']],
    'search_modal' => ['Search Modal', ['field_bg' => 'Field Background', 'field_text' => 'Field Text', 'overlay_bg' => 'Overlay Background']],
];

/** Typography targets and where they apply on the site. */
const EDU_TYPO_TARGETS = [
    'body' => ['Body', 'body'],
    'headings' => ['All Headings (H1–H6, titles)', 'h1,h2,h3,h4,h5,h6,.entry-title,.nb-card-title'],
    'site_title' => ['Site Title', '.site-title'],
    'tagline' => ['Site Tagline', '.site-tagline'],
    'primary_menu' => ['Mobile Menu Items', '.mnav-link'],
    'sub_menu' => ['Mobile Sub-Menu Items', '.mnav-sub a'],
    'menu_strip' => ['Menu Strip Items', '.inb-scroll-menu a'],
    'buttons' => ['Buttons', '.nb-btn, .nb-hr-sub, .post-join-btn, .blog-comment-submit-btn, .wp-block-button__link, .cf-btn, .cf-submit'],
    'post_title' => ['Single Content Title (H1)', '.single-post h1.entry-title'],
    'archive_title' => ['Archive Content Title', '.nb-card-title, .nb-arch-title'],
    'h1' => ['Heading 1 (H1)', '.entry-content h1'],
    'h2' => ['Heading 2 (H2)', '.entry-content h2'],
    'h3' => ['Heading 3 (H3)', '.entry-content h3'],
    'h4' => ['Heading 4 (H4)', '.entry-content h4'],
    'h5' => ['Heading 5 (H5)', '.entry-content h5'],
    'h6' => ['Heading 6 (H6)', '.entry-content h6'],
    'meta' => ['Entry Meta', '.post-meta-row, .nb-card-meta, .nb-head-kicker, .trending-item-date'],
    'widget_title' => ['Widget Titles', '.trending-box-header h2, .widget-title'],
    'footer' => ['Footer', '.ftx-footer'],
    'custom' => ['Custom selector', ''],
];

function edu_theme_is_preview(): bool
{
    return isset($_GET['czp']) && in_array($_SESSION['role'] ?? '', ['admin', 'blog_admin'], true);
}

function edu_theme(): array
{
    static $t = null;
    if ($t !== null) return $t;
    $saved = edu_settings('theme');
    $t = edu_theme_migrate(array_merge(edu_theme_defaults(), $saved), $saved);
    return $t;
}

/**
 * Older saved settings → the new structures, so the site looks the same until the new controls are used:
 * strip links, footer columns, colors, typography and Also Read are built from the old single options.
 */
function edu_theme_migrate(array $t, array $saved): array
{
    if (!array_key_exists('strip_items', $saved) && isset($saved['menu'])) $t['strip_items'] = $saved['menu'];
    if (!array_key_exists('f_columns', $saved)) {
        $cols = [];
        if ((string)$t['f_cats'] === '1') $cols[] = ['title' => (string)$t['f_cats_heading'], 'auto' => 'categories', 'count' => (int)$t['f_cats_count'], 'links' => []];
        if (!empty($t['f_links'])) $cols[] = ['title' => (string)$t['f_links_heading'], 'auto' => '', 'count' => 6, 'links' => (array)$t['f_links']];
        $t['f_columns'] = $cols;
    }
    if (!array_key_exists('global_colors', $saved) && preg_match('/^#[0-9a-f]{3,8}$/i', (string)$t['c_accent'])) {
        foreach ($t['global_colors'] as &$g) if ($g['slug'] === 'accent') $g['color'] = $t['c_accent'];
        unset($g);
    }
    if (!array_key_exists('colors', $saved)) {
        // an old colour still at its default is left empty, so the area follows the global colours
        $oldDefaults = edu_theme_defaults();
        $c = fn($k) => preg_match('/^#[0-9a-f]{3,8}$/i', (string)($t[$k] ?? '')) && strcasecmp((string)$t[$k], (string)($oldDefaults[$k] ?? '')) !== 0 ? $t[$k] : '';
        $t['colors'] = [
            'body' => ['background' => $c('c_body_bg'), 'text' => $c('c_text')],
            'header' => ['racing_bg' => $c('c_header_bg'), 'racing_text' => $c('c_header_text')],
            'menu_strip' => ['background' => $c('c_strip_bg'), 'text' => $c('c_strip_text'), 'text_hover' => $c('c_strip_hover')],
            'off_canvas' => ['background' => $c('c_drawer_bg'), 'text' => $c('c_drawer_text')],
            'buttons' => ['background' => $c('c_button_bg'), 'text' => $c('c_button_text'), 'background_hover' => $c('c_button_hover') ?: $c('c_accent_hover')],
            'content' => ['text' => $c('c_text'), 'title' => $c('c_heading'), 'link' => $c('c_link'), 'link_hover' => $c('c_link_hover'), 'meta_text' => $c('c_meta')],
            'cards' => ['background' => $c('c_card_bg'), 'border' => $c('c_card_border'), 'toc_bg' => $c('c_toc_bg')],
            'sidebar' => ['title_bg' => $c('c_sidebar_title_bg')],
            'footer_widgets' => ['background' => $c('c_footer_bg'), 'text' => $c('c_footer_text'), 'link' => $c('c_footer_link'), 'link_hover' => $c('c_footer_accent'), 'accent' => $c('c_footer_accent')],
            'footer_bar' => ['link_hover' => $c('c_footer_accent')],
        ];
        $t['colors'] = edu_theme_link_globals($t['colors'], (array)$t['global_colors']);
    }
    if (!array_key_exists('typography', $saved)) {
        $fonts = [];
        $addFont = function ($f) use (&$fonts) {
            if (in_array($f, ['system', 'Georgia', ''], true) || isset($fonts[$f])) return;
            $fonts[$f] = ['family' => $f, 'google' => true, 'variants' => ['400', '500', '600', '700'], 'fallback' => $f === 'Merriweather' ? 'serif' : 'sans-serif'];
        };
        $addFont((string)$t['font_body']);
        $addFont((string)$t['font_heading']);
        $fam = fn($f) => $f === 'system' ? '' : (string)$f;
        $r = fn($id, $target, $extra) => array_merge(edu_typo_rule($target), ['id' => $id], $extra);
        $t['typography'] = [
            $r('t-body', 'body', ['family' => $fam($t['font_body']), 'size' => ['d' => (string)$t['font_size'], 't' => '', 'm' => ''], 'lineHeight' => ['d' => (string)$t['line_height'], 't' => '', 'm' => '']]),
            $r('t-head', 'headings', ['family' => $fam($t['font_heading']), 'weight' => (string)$t['heading_weight']]),
            $r('t-h1', 'post_title', ['size' => ['d' => (string)$t['h1_size'], 't' => '', 'm' => '']]),
            $r('t-h2', 'h2', ['size' => ['d' => (string)$t['h2_size'], 't' => '', 'm' => '']]),
            $r('t-h3', 'h3', ['size' => ['d' => (string)$t['h3_size'], 't' => '', 'm' => '']]),
        ];
        if (!array_key_exists('fonts', $saved)) $t['fonts'] = array_values($fonts);
    }
    if (!array_key_exists('ar_groups', $saved) && (isset($saved['ar_after']) || isset($saved['ar_count']) || isset($saved['ar_source']) || isset($saved['ar_label']))) {
        $src = in_array($t['ar_source'], ['category', 'latest', 'random'], true) ? $t['ar_source'] : 'category';
        $t['ar_groups'] = [['id' => 'ar1', 'after' => (int)$t['ar_after'], 'count' => (int)$t['ar_count'], 'source' => $src, 'post_ids' => [], 'style' => 'card', 'label' => (string)$t['ar_label']]];
    }
    return $t;
}

/** An empty Typography Manager rule. */
function edu_typo_rule(string $target = 'body'): array
{
    $e = ['d' => '', 't' => '', 'm' => ''];
    return ['id' => substr(bin2hex(random_bytes(4)), 0, 7), 'target' => $target, 'selector' => '', 'family' => '', 'weight' => '', 'transform' => '',
        'style' => '', 'decoration' => '', 'size' => $e, 'sizeUnit' => 'px', 'lineHeight' => $e, 'letterSpacing' => $e, 'marginBottom' => $e];
}

/** The Subscribe button's social icon (Customize → Header): [label, svg, colour] — or null for none / unknown. */
function edu_sub_icon(?string $key = null): ?array
{
    $k = $key ?? (string)th('h_sub_icon', '');
    $f = fn($d) => '<svg class="nb-sub-ic" viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true"><path d="' . $d . '"/></svg>';
    $all = [
        'whatsapp' => ['WhatsApp', $f('M17.47 14.38c-.3-.15-1.76-.87-2.03-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.94 1.16-.17.2-.35.22-.64.08-.3-.15-1.26-.46-2.39-1.48-.88-.79-1.48-1.76-1.65-2.06-.17-.3-.02-.46.13-.6.13-.14.3-.35.45-.52.15-.18.2-.3.3-.5.1-.2.05-.37-.03-.52-.07-.15-.67-1.61-.92-2.2-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.8.37-.27.3-1.04 1.02-1.04 2.48s1.07 2.88 1.21 3.07c.15.2 2.1 3.2 5.08 4.49.71.3 1.26.49 1.7.63.71.22 1.36.19 1.87.12.57-.09 1.76-.72 2-1.41.25-.7.25-1.29.18-1.41-.08-.13-.28-.2-.57-.35m-5.42 7.4h-.01a9.87 9.87 0 0 1-5.03-1.38l-.36-.21-3.74.98 1-3.65-.24-.37a9.86 9.86 0 0 1-1.51-5.26c0-5.45 4.44-9.88 9.89-9.88 2.64 0 5.12 1.03 6.99 2.9a9.83 9.83 0 0 1 2.89 6.99c0 5.45-4.44 9.88-9.88 9.88m8.41-18.3A11.82 11.82 0 0 0 12.05 0C5.5 0 .16 5.34.16 11.89c0 2.1.55 4.14 1.59 5.95L.06 24l6.3-1.65a11.88 11.88 0 0 0 5.69 1.45h.01c6.55 0 11.89-5.34 11.89-11.89 0-3.18-1.24-6.17-3.48-8.41Z'), '#1fb855'],
        'facebook' => ['Facebook', $f('M24 12.07C24 5.45 18.63.07 12 .07S0 5.45 0 12.07c0 5.99 4.39 10.95 10.13 11.85v-8.38H7.08v-3.47h3.05V9.43c0-3.01 1.79-4.67 4.53-4.67 1.31 0 2.69.24 2.69.24v2.95h-1.51c-1.49 0-1.96.93-1.96 1.87v2.25h3.33l-.53 3.47h-2.8v8.38C19.61 23.03 24 18.06 24 12.07Z'), '#1877f2'],
        'youtube' => ['YouTube', $f('M23.5 6.19a3.02 3.02 0 0 0-2.12-2.14C19.5 3.55 12 3.55 12 3.55s-7.5 0-9.38.5A3.02 3.02 0 0 0 .5 6.19C0 8.07 0 12 0 12s0 3.93.5 5.81a3.02 3.02 0 0 0 2.12 2.14c1.88.5 9.38.5 9.38.5s7.5 0 9.38-.5a3.02 3.02 0 0 0 2.12-2.14C24 15.93 24 12 24 12s0-3.93-.5-5.81ZM9.55 15.57V8.43L15.82 12l-6.27 3.57Z'), '#ff0000'],
        'instagram' => ['Instagram', $f('M12 2.16c3.2 0 3.58.01 4.85.07 3.25.15 4.77 1.69 4.92 4.92.06 1.27.07 1.65.07 4.85s-.01 3.58-.07 4.85c-.15 3.23-1.66 4.77-4.92 4.92-1.27.06-1.65.07-4.85.07s-3.58-.01-4.85-.07c-3.26-.15-4.77-1.7-4.92-4.92C2.17 15.58 2.16 15.2 2.16 12s.01-3.58.07-4.85C2.38 3.92 3.9 2.38 7.15 2.23 8.42 2.17 8.8 2.16 12 2.16ZM12 0C8.74 0 8.33.01 7.05.07 2.7.27.27 2.69.07 7.05.01 8.33 0 8.74 0 12s.01 3.67.07 4.95c.2 4.36 2.62 6.78 6.98 6.98 1.28.06 1.69.07 4.95.07s3.67-.01 4.95-.07c4.35-.2 6.78-2.62 6.98-6.98.06-1.28.07-1.69.07-4.95s-.01-3.67-.07-4.95C23.73 2.7 21.31.27 16.95.07 15.67.01 15.26 0 12 0Zm0 5.84a6.16 6.16 0 1 0 0 12.32 6.16 6.16 0 0 0 0-12.32ZM12 16a4 4 0 1 1 0-8 4 4 0 0 1 0 8Zm6.4-11.85a1.44 1.44 0 1 0 0 2.88 1.44 1.44 0 0 0 0-2.88Z'), 'linear-gradient(45deg,#f58529,#dd2a7b 55%,#8134af)'],
        'x' => ['X (Twitter)', $f('M18.9 1.15h3.68l-8.04 9.19L24 22.85h-7.41l-5.8-7.58-6.64 7.58H.47l8.6-9.83L0 1.15h7.59l5.25 6.93ZM17.61 20.64h2.04L6.49 3.24H4.3Z'), '#000000'],
        'telegram' => ['Telegram', $f('M11.94 0A12 12 0 1 0 12 24 12 12 0 0 0 11.94 0Zm4.97 7.22c.1 0 .32.02.46.14.12.1.15.23.17.33.02.09.04.3.02.47-.18 1.9-.96 6.5-1.36 8.63-.17.9-.5 1.2-.82 1.23-.7.06-1.23-.46-1.9-.9-1.06-.7-1.65-1.13-2.68-1.8-1.19-.78-.42-1.21.26-1.91.18-.18 3.25-2.98 3.3-3.23.01-.03.02-.15-.05-.21-.07-.06-.17-.04-.25-.02-.1.02-1.79 1.14-5.06 3.35-.48.33-.91.49-1.3.48-.43-.01-1.25-.24-1.87-.44-.75-.25-1.35-.37-1.3-.79.03-.22.33-.44.9-.66 3.5-1.52 5.83-2.53 7-3.02 3.33-1.38 4.02-1.62 4.48-1.63Z'), '#229ed9'],
        'linkedin' => ['LinkedIn', $f('M20.45 20.45h-3.56v-5.57c0-1.33-.02-3.04-1.85-3.04-1.85 0-2.14 1.45-2.14 2.94v5.67H9.35V9h3.41v1.56h.05c.48-.9 1.64-1.85 3.37-1.85 3.6 0 4.27 2.37 4.27 5.46v6.28ZM5.34 7.43a2.06 2.06 0 1 1 0-4.12 2.06 2.06 0 0 1 0 4.12ZM7.12 20.45H3.56V9h3.56v11.45ZM22.23 0H1.77C.79 0 0 .77 0 1.73v20.54C0 23.23.79 24 1.77 24h20.45c.98 0 1.78-.77 1.78-1.73V1.73C24 .77 23.2 0 22.22 0Z'), '#0a66c2'],
    ];
    return $all[$k] ?? null;
}
function th(string $key, $default = null)
{
    $t = edu_theme();
    return $t[$key] ?? $default;
}

function th_on(string $key): bool
{
    return (string)th($key, '0') === '1';
}

/** Text with {year}, {site}, {category} and the shortcodes ([site_title], [year] …) filled in. */
function th_text(string $key, array $vars = []): string
{
    $vars += ['year' => date('Y'), 'site' => edu_site_name()];
    $s = (string)th($key, '');
    foreach ($vars as $k => $v) $s = str_replace('{' . $k . '}', (string)$v, $s);
    return function_exists('edu_shortcodes') ? edu_shortcodes($s, true) : $s;
}

/** Cleans everything the Customizer sends (lists, colors, fonts, rules) before it is saved or previewed. */
function edu_theme_sanitize(array $theme): array
{
    $str = fn($v, $max) => mb_substr(trim((string)$v), 0, $max);
    $link = function ($i, bool $kids) use (&$link, $str) {
        $o = ['label' => $str($i['label'] ?? '', 80), 'url' => $str($i['url'] ?? '', 300)];
        if (preg_match('/^\s*(javascript|data|vbscript)\s*:/i', $o['url'])) $o['url'] = ''; // links only (dropped below)
        if (!empty($i['newTab'])) $o['newTab'] = true;
        if ($kids && !empty($i['children']) && is_array($i['children'])) {
            $o['children'] = array_values(array_filter(array_map(fn($c) => $link($c, false), array_slice($i['children'], 0, 30)), fn($c) => $c['label'] !== '' && $c['url'] !== ''));
        }
        return $o;
    };
    $links = fn($list, bool $kids) => array_values(array_filter(array_map(fn($i) => $link((array)$i, $kids), array_slice((array)$list, 0, 60)), fn($i) => $i['label'] !== '' && $i['url'] !== ''));
    foreach (['menu' => true, 'strip_items' => true, 'f_links' => false] as $k => $kids) if (isset($theme[$k])) $theme[$k] = $links($theme[$k], $kids);
    if (isset($theme['strip_source'])) $theme['strip_source'] = $theme['strip_source'] === 'categories' ? 'categories' : 'custom';
    if (isset($theme['f_columns'])) {
        $theme['f_columns'] = array_values(array_map(fn($c) => [
            'title' => $str($c['title'] ?? '', 80), 'auto' => ($c['auto'] ?? '') === 'categories' ? 'categories' : '',
            'count' => max(1, min(20, (int)($c['count'] ?? 6))), 'links' => $links($c['links'] ?? [], false),
        ], array_slice(array_filter((array)$theme['f_columns'], 'is_array'), 0, 4)));
    }
    if (isset($theme['global_colors'])) {
        $out = [];
        foreach (array_slice(array_filter((array)$theme['global_colors'], 'is_array'), 0, 24) as $g) {
            $slug = substr(preg_replace('/[^a-z0-9-]/', '', strtolower((string)($g['slug'] ?? ''))), 0, 40);
            $col = edu_theme_color_value((string)($g['color'] ?? ''));
            if ($slug !== '' && $col !== '' && !str_starts_with($col, 'var(') && !isset($out[$slug])) $out[$slug] = ['slug' => $slug, 'name' => $str($g['name'] ?? $slug, 40), 'color' => $col];
        }
        $theme['global_colors'] = array_values($out);
    }
    if (isset($theme['colors'])) {
        $out = [];
        foreach (EDU_COLOR_GROUPS as $g => [, $fields]) {
            foreach ($fields as $f => $_) {
                $v = edu_theme_color_value((string)($theme['colors'][$g][$f] ?? ''));
                if ($v !== '') $out[$g][$f] = $v;
            }
        }
        $theme['colors'] = $out;
    }
    if (isset($theme['fonts'])) {
        $out = [];
        foreach (array_slice(array_filter((array)$theme['fonts'], 'is_array'), 0, 12) as $f) {
            $fam = trim(preg_replace('/[^A-Za-z0-9 \-]/', '', (string)($f['family'] ?? '')));
            if ($fam === '') continue;
            $vars = array_values(array_intersect((array)($f['variants'] ?? []), ['100', '200', '300', '400', '500', '600', '700', '800', '900', '400italic', '700italic']));
            $out[] = ['family' => mb_substr($fam, 0, 60), 'google' => !empty($f['google']), 'variants' => $vars ?: ['400', '700'],
                'fallback' => in_array($f['fallback'] ?? '', ['sans-serif', 'serif', 'monospace', 'cursive', 'system-ui'], true) ? $f['fallback'] : 'sans-serif'];
        }
        $theme['fonts'] = $out;
    }
    if (isset($theme['typography'])) {
        $out = [];
        $resp = fn($v) => array_map(fn($x) => preg_match('/^-?\d*\.?\d+(px|em|rem|%)?$/', trim((string)$x)) ? trim((string)$x) : '', ['d' => $v['d'] ?? '', 't' => $v['t'] ?? '', 'm' => $v['m'] ?? '']);
        $pick = fn($v, $ok) => in_array((string)$v, $ok, true) ? (string)$v : '';
        foreach (array_slice(array_filter((array)$theme['typography'], 'is_array'), 0, 60) as $r) {
            $target = isset(EDU_TYPO_TARGETS[$r['target'] ?? '']) ? $r['target'] : 'body';
            $out[] = [
                'id' => substr(preg_replace('/[^a-z0-9-]/i', '', (string)($r['id'] ?? '')), 0, 20) ?: substr(bin2hex(random_bytes(4)), 0, 7),
                'target' => $target, 'selector' => $target === 'custom' ? mb_substr(trim(str_replace(['/*', '*/'], '', preg_replace('/[^A-Za-z0-9\s.#\-_>+~*:()\[\]="\',^$|]/', '', (string)($r['selector'] ?? '')))), 0, 200) : '',
                'family' => mb_substr(trim(preg_replace('/[^A-Za-z0-9 \-]/', '', (string)($r['family'] ?? ''))), 0, 60),
                'weight' => $pick($r['weight'] ?? '', ['100', '200', '300', '400', '500', '600', '700', '800', '900', 'normal', 'bold']),
                'transform' => $pick($r['transform'] ?? '', ['none', 'uppercase', 'lowercase', 'capitalize']),
                'style' => $pick($r['style'] ?? '', ['normal', 'italic']), 'decoration' => $pick($r['decoration'] ?? '', ['none', 'underline', 'line-through']),
                'size' => $resp($r['size'] ?? []), 'sizeUnit' => in_array($r['sizeUnit'] ?? '', ['px', 'em', 'rem'], true) ? $r['sizeUnit'] : 'px',
                'lineHeight' => $resp($r['lineHeight'] ?? []), 'letterSpacing' => $resp($r['letterSpacing'] ?? []), 'marginBottom' => $resp($r['marginBottom'] ?? []),
            ];
        }
        $theme['typography'] = $out;
    }
    if (isset($theme['ar_groups'])) {
        $out = [];
        foreach (array_slice(array_filter((array)$theme['ar_groups'], 'is_array'), 0, 10) as $g) {
            $ids = array_values(array_unique(array_filter(array_map('intval', (array)($g['post_ids'] ?? [])))));
            $out[] = [
                'id' => substr(preg_replace('/[^a-z0-9]/i', '', (string)($g['id'] ?? '')), 0, 20) ?: 'ar' . substr(bin2hex(random_bytes(3)), 0, 6),
                'after' => max(1, min(40, (int)($g['after'] ?? 3))), 'count' => max(1, min(12, (int)($g['count'] ?? 1))),
                'source' => in_array($g['source'] ?? '', ['category', 'latest', 'random', 'manual'], true) ? $g['source'] : 'category',
                'post_ids' => array_slice($ids, 0, 20), 'style' => in_array($g['style'] ?? '', ['card', 'accent', 'minimal'], true) ? $g['style'] : 'card',
                'label' => $str($g['label'] ?? 'Also Read', 60),
            ];
        }
        $theme['ar_groups'] = $out;
    }
    // Google Play badge: on/off + an optional web link (no link = badge only)
    if (isset($theme['f_play'])) $theme['f_play'] = $theme['f_play'] === '1' || $theme['f_play'] === true ? '1' : '0';
    if (isset($theme['f_play_url'])) { $u = $str($theme['f_play_url'], 500); $theme['f_play_url'] = preg_match('#^https?://\S+$#i', $u) ? $u : ''; }
    return $theme;
}

/** A safe CSS color: #hex, rgb()/rgba()/hsl(), transparent, or a global color written as var(--gc-slug). Else ''. */
/**
 * Area colours that are exact copies of a global colour become references to it (var(--gc-…)), like WordPress:
 * changing that global colour then changes every area that uses it. Looks the same until a global colour changes.
 */
function edu_theme_link_globals(array $colors, array $globals): array
{
    $map = [];
    foreach ($globals as $g) {
        $hex = strtolower(trim((string)($g['color'] ?? '')));
        $slug = preg_replace('/[^a-z0-9-]/', '', (string)($g['slug'] ?? ''));
        if ($slug !== '' && preg_match('/^#[0-9a-f]{3,8}$/', $hex) && !isset($map[$hex])) $map[$hex] = 'var(--gc-' . $slug . ')';
    }
    foreach ($colors as $group => $fields) {
        if (!is_array($fields)) continue;
        foreach ($fields as $field => $v) {
            $k = strtolower(trim((string)$v));
            if (isset($map[$k])) $colors[$group][$field] = $map[$k];
        }
    }
    return $colors;
}

function edu_theme_color_value(string $v): string
{
    $v = trim($v);
    if ($v === '') return '';
    if (preg_match('/^#[0-9a-f]{3,8}$/i', $v) || preg_match('/^(rgb|rgba|hsl|hsla)\([0-9.,%\s\/deg]+\)$/i', $v) || strtolower($v) === 'transparent') return $v;
    if (preg_match('/^var\(--gc-[a-z0-9-]+\)$/', $v)) return $v;
    return '';
}

/** A color of the Customizer (group + field), '' when not set. */
function edu_theme_color(string $group, string $field): string
{
    return edu_theme_color_value((string)(edu_theme()['colors'][$group][$field] ?? ''));
}

/** "Mukta" → "Mukta", sans-serif (with the fallback from the Font Manager). */
function edu_theme_family(string $family): string
{
    $f = trim(preg_replace('/[^A-Za-z0-9 \-]/', '', $family));
    if ($f === '') return '';
    $fallback = 'sans-serif';
    foreach ((array)th('fonts', []) as $font) if (($font['family'] ?? '') === $f) $fallback = $font['fallback'] ?? 'sans-serif';
    $sys = ['Arial' => 1, 'Helvetica' => 1, 'Georgia' => 1, 'Times New Roman' => 1, 'Verdana' => 1, 'Tahoma' => 1, 'Trebuchet MS' => 1];
    if ($f === 'Georgia' || $f === 'Times New Roman') $fallback = 'serif';
    return (str_contains($f, ' ') || !isset($sys[$f]) ? '"' . $f . '"' : $f) . ', ' . $fallback;
}

/** Google Fonts stylesheet for every Google font in the Font Manager, or ''. */
function edu_google_fonts_href(): string
{
    $q = [];
    foreach ((array)th('fonts', []) as $f) {
        if (empty($f['google']) || trim((string)($f['family'] ?? '')) === '') continue;
        $w = [];
        $it = [];
        foreach ((array)($f['variants'] ?? ['400', '700']) as $v) {
            $n = preg_replace('/\D/', '', (string)$v);
            if ($n === '') continue;
            str_contains((string)$v, 'italic') ? $it[$n] = 1 : $w[$n] = 1;
        }
        if (!$w && !$it) $w = ['400' => 1, '700' => 1];
        ksort($w);
        ksort($it);
        $axis = $it ? 'ital,wght@' . implode(';', array_merge(array_map(fn($x) => '0,' . $x, array_keys($w)), array_map(fn($x) => '1,' . $x, array_keys($it)))) : 'wght@' . implode(';', array_keys($w));
        $q[] = 'family=' . str_replace('%20', '+', rawurlencode(trim($f['family']))) . ':' . $axis;
    }
    if (!$q) return '';
    $d = in_array(th('font_display'), ['auto', 'block', 'swap', 'fallback', 'optional'], true) ? th('font_display') : 'swap';
    return 'https://fonts.googleapis.com/css2?' . implode('&', $q) . '&display=' . ($d === 'auto' ? 'swap' : $d);
}

/** CSS of the Typography Manager (desktop, then tablet ≤1024px and mobile ≤767px). */
function edu_theme_typo_css(): string
{
    $css = '';
    $num = fn($v) => preg_match('/^-?[\d.]+$/', (string)$v);
    foreach (['d' => ['', ''], 't' => ['@media (max-width:1024px){', '}'], 'm' => ['@media (max-width:767px){', '}']] as $bp => [$open, $close]) {
        $block = '';
        foreach ((array)th('typography', []) as $r) {
            $target = (string)($r['target'] ?? '');
            $sel = $target === 'custom' ? trim(str_replace(['/*', '*/'], '', preg_replace('/[^A-Za-z0-9\s.#\-_>+~*:()\[\]="\',^$|]/', '', (string)($r['selector'] ?? '')))) : (EDU_TYPO_TARGETS[$target][1] ?? '');
            if ($sel === '') continue;
            $d = [];
            if ($bp === 'd') {
                if (($fam = edu_theme_family((string)($r['family'] ?? ''))) !== '') $d[] = 'font-family:' . $fam;
                foreach (['weight' => 'font-weight', 'transform' => 'text-transform', 'style' => 'font-style', 'decoration' => 'text-decoration'] as $k => $prop) {
                    if (($r[$k] ?? '') !== '') $d[] = $prop . ':' . $r[$k];
                }
            }
            $size = (string)($r['size'][$bp] ?? '');
            if ($size !== '') $d[] = 'font-size:' . $size . ($num($size) ? ($r['sizeUnit'] ?? 'px') : '');
            // the body line height is for the article text (as before), not every element of the page
            if (($lh = (string)($r['lineHeight'][$bp] ?? '')) !== '' && $target !== 'body') $d[] = 'line-height:' . $lh;
            if (($ls = (string)($r['letterSpacing'][$bp] ?? '')) !== '') $d[] = 'letter-spacing:' . $ls . ($num($ls) ? 'em' : '');
            $mb = (string)($r['marginBottom'][$bp] ?? '');
            if ($mb !== '' && $target !== 'body') $d[] = 'margin-bottom:' . $mb . ($num($mb) ? 'em' : '');
            if ($d) $block .= $sel . '{' . implode(';', $d) . '}';
            // Body: the article text follows the body size / line height; "Paragraph Bottom Margin" is for paragraphs
            if ($target === 'body') {
                $c = [];
                if ($size !== '') $c[] = 'font-size:' . $size . ($num($size) ? ($r['sizeUnit'] ?? 'px') : '');
                if ($lh !== '') $c[] = 'line-height:' . $lh;
                if ($c) $block .= '.entry-content,.content.entry-content{' . implode(';', $c) . '}';
                if ($mb !== '') $block .= '.entry-content p{margin-bottom:' . $mb . ($num($mb) ? 'em' : '') . '}';
            }
        }
        if ($block !== '') $css .= $open . $block . $close;
    }
    return $css;
}

/** <link> for Google Fonts (only when the Font Manager has some) + the theme <style>. Printed in <head>. */
function edu_theme_head(): string
{
    $t = edu_theme();
    $out = '';
    if (($href = edu_google_fonts_href()) !== '') {
        $out .= '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link rel="stylesheet" href="' . htmlspecialchars($href) . '">';
    }
    $col = fn($g, $f, $d = '') => edu_theme_color($g, $f) ?: $d;
    $vars = [];
    foreach ((array)$t['global_colors'] as $g) {
        $v = edu_theme_color_value((string)($g['color'] ?? ''));
        $slug = preg_replace('/[^a-z0-9-]/', '', (string)($g['slug'] ?? ''));
        if ($slug !== '' && $v !== '') $vars[] = '--gc-' . $slug . ':' . $v;
    }
    foreach (EDU_COLOR_GROUPS as $g => [, $fields]) {
        foreach ($fields as $f => $_) {
            if (($v = edu_theme_color($g, $f)) !== '') $vars[] = '--t-' . str_replace('_', '-', $g) . '-' . str_replace('_', '-', $f) . ':' . $v;
        }
    }
    // variables the theme's own CSS uses, filled from the colors above (empty = theme default)
    $accentHover = $col('buttons', 'background_hover', 'color-mix(in srgb,var(--gc-accent,#0c6878) 80%,#000)');
    $vars[] = '--color-primary:var(--gc-accent,#0c6878);--color-primary-hover:' . $accentHover;
    $vars[] = '--text-primary:' . $col('content', 'title', '#111827');
    $vars[] = '--t-content-meta-text:' . $col('content', 'meta_text', '#6b7280');
    $vars[] = '--t-footer-widgets-link-hover:' . $col('footer_widgets', 'link_hover', $col('footer_widgets', 'accent', '#ffb700'));
    $vars[] = '--edu-strip-bg:' . $col('menu_strip', 'background', '#ffffff') . ';--edu-strip-text:' . $col('menu_strip', 'text', '#1d2327') . ';--edu-strip-hover:' . $col('menu_strip', 'text_hover', 'var(--gc-accent)');
    $vars[] = '--edu-card-bg:' . $col('cards', 'background', '#ffffff') . ';--edu-card-border:' . $col('cards', 'border', '#e5e7eb') . ';--edu-toc-bg:' . $col('cards', 'toc_bg', 'var(--gc-accent)');
    $vars[] = '--nb-container:' . max(960, min(1600, (int)$t['h_width'])) . 'px';
    $vars[] = '--edu-radius:' . max(0, min(30, (int)($t['box_radius'] ?? 5))) . 'px';
    // fonts: the body / headings rules also drive the theme's font variables
    $sys = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif';
    $bodyFam = $headFam = '';
    foreach ((array)$t['typography'] as $r) {
        if (($r['target'] ?? '') === 'body' && $bodyFam === '') $bodyFam = edu_theme_family((string)($r['family'] ?? ''));
        if (($r['target'] ?? '') === 'headings' && $headFam === '') $headFam = edu_theme_family((string)($r['family'] ?? ''));
    }
    $vars[] = '--edu-font-body:' . ($bodyFam ?: $sys) . ';--edu-font-heading:' . ($headFam ?: 'var(--edu-font-body)');
    // older variables still used by the theme's CSS (desktop values of the matching rules)
    $rv = function (string $target, string $prop, string $def) use ($t) {
        foreach ((array)$t['typography'] as $r) {
            if (($r['target'] ?? '') !== $target) continue;
            $v = $prop === 'weight' ? (string)($r['weight'] ?? '') : (string)($r[$prop]['d'] ?? '');
            if ($v === '') continue;
            return $prop === 'size' && preg_match('/^[\d.]+$/', $v) ? $v . ($r['sizeUnit'] ?? 'px') : $v;
        }
        return $def;
    };
    $vars[] = '--edu-font-size:' . $rv('body', 'size', '16px') . ';--edu-line-height:' . $rv('body', 'lineHeight', '1.7') . ';--edu-heading-weight:' . $rv('headings', 'weight', '700')
        . ';--edu-h1:' . $rv('post_title', 'size', '30px') . ';--edu-h2:' . $rv('h2', 'size', '24px') . ';--edu-h3:' . $rv('h3', 'size', '20px');

    $css = ':root{' . implode(';', $vars) . '}'
        . '[hidden]{display:none!important}'
        . 'html body{background:var(--t-body-background,#fff);color:var(--t-body-text,#373737);font-family:var(--edu-font-body);font-size:var(--edu-font-size)}'
        . 'h1,h2,h3,h4,h5,h6,.entry-title,.nb-card-title{font-family:var(--edu-font-heading)}'
        . '.entry-content,.content.entry-content{font-size:var(--edu-font-size);line-height:var(--edu-line-height)}'
        . '.entry-content h2,.entry-content h3,.entry-content h4{font-weight:var(--edu-heading-weight)}'
        // each heading level follows its own colour (Customize → Colors → Content), else the Content Title colour as before
        . '.entry-content h2{color:var(--t-content-h2,var(--t-content-title))}.entry-content h3{color:var(--t-content-h3,var(--t-content-title))}.entry-content h4{color:var(--t-content-h4,var(--t-content-title))}'
        . '.single-post .entry-content h1{color:var(--t-content-h1,var(--text-primary))}'
        . '.single-post h1.entry-title{font-size:var(--edu-h1)}.entry-content h2{font-size:var(--edu-h2)}.entry-content h3{font-size:var(--edu-h3)}'
        . '#site-navigation.nb-h-racing .nb-hr-nav{background:var(--edu-strip-bg)}#site-navigation.nb-h-racing .inb-scroll-menu a{color:var(--edu-strip-text)}'
        . '#site-navigation.nb-h-racing .inb-scroll-menu a:hover,#site-navigation.nb-h-racing .inb-scroll-menu a.current{color:var(--edu-strip-hover)}'
        . '.nb-h-racing .inb-scroll-menu a::after{background:var(--edu-strip-hover)}'
        . '.nb-hr-act .nb-bell,.nb-hr-act .nb-search-icon,.nb-burger,.nb-hr-soc a{color:var(--t-header-racing-text)}'
        . '.mnav{--mnav-bg:var(--t-off-canvas-background,#fff);--mnav-text:var(--t-off-canvas-text,#353543);--mnav-accent:var(--gc-accent)}'
        . '.mnav-link:hover,.mnav-sub a:hover{background:var(--t-off-canvas-background-hover,transparent)}.mnav-sub{background:var(--t-off-canvas-sub-bg,var(--mnav-soft))}'
        . '.nb-card,.nb-card--feat,.post-card,.nb-head-card,.author-bio,.blog-comment-form-container,.blog-comments-section{background:var(--edu-card-bg)}'
        . '.nb-card--feat,.post-card{border-color:var(--edu-card-border)}'
        . '.toc h2,.toc>h2{background:var(--edu-toc-bg)!important}'
        . '.site-title{color:var(--t-header-site-title,inherit)}.site-tagline{color:var(--t-header-tagline,inherit)}'
        . '.nb-progress span{background:var(--gc-accent)}'
        . '.ftx-logo{display:block;max-width:200px;max-height:64px;width:auto;height:auto;margin:0 0 12px}'
        . '.mnav-chev svg{transition:transform .2s}.mnav-chev.open svg{transform:rotate(180deg)}'
        . edu_theme_typo_css();
    if (th_on('h_sticky')) $css .= '#site-navigation.nb-h-racing{position:sticky;top:0}body:has(#wpadminbar) #site-navigation.nb-h-racing{top: var(--wpab-top,32px)}';
    if (th_on('sb_sticky')) $css .= '@media (min-width:981px){.post-sidebar .trending-box{position:sticky;top:90px}}';
    if (!th_on('rp_bar')) $css .= '.nb-progress{display:none}';
    $cols = max(2, min(4, (int)$t['arch_columns']));
    $css .= '@media (min-width:901px){.post-grid{grid-template-columns:repeat(' . $cols . ',minmax(0,1fr))}}';
    if ($t['menu_style'] === 'pills') $css .= '.nb-h-racing .inb-scroll-menu a{padding:6px 14px!important;margin:8px 0;border-radius:999px;border:1px solid rgba(0,0,0,.08)}.nb-h-racing .inb-scroll-menu a::after{display:none}.nb-h-racing .inb-scroll-menu a:hover,.nb-h-racing .inb-scroll-menu a.current{background:var(--edu-strip-hover);color:#fff!important}';
    if ($t['menu_style'] === 'plain') $css .= '.nb-h-racing .inb-scroll-menu a::after{display:none}';
    if ($t['menu_align'] === 'left') $css .= '@media (min-width:981px){.nb-h-racing .inb-scroll-menu-inner{justify-content:flex-start}}';
    else $css .= '@media (min-width:981px){.nb-h-racing .inb-scroll-menu-inner{max-width:var(--nb-container,1200px);width:100%;min-width:0;overflow-x:auto;scrollbar-width:none;justify-content:flex-start}.nb-h-racing .inb-scroll-menu-inner::-webkit-scrollbar{display:none}.nb-h-racing .inb-scroll-menu-inner>:first-child{margin-left:auto}.nb-h-racing .inb-scroll-menu-inner>:last-child{margin-right:auto}}'; // inside the container: centred while it fits; longer → starts at the first link and scrolls inside the container (nothing cut off)
    $custom = trim((string)$t['custom_css']);
    if ($custom !== '') $css .= str_ireplace('</style', '<\/style', $custom);
    // Customize → Container → Corner radius: no box, button, card, drop-down or FAQ on the site is rounder than this
    // (circles such as avatars and round icons stay round). Done in the browser, so every page and every style follows.
    $rad = max(0, min(30, (int)($t['box_radius'] ?? 5)));
    $out .= '<script>(function(R){function f(root){[].forEach.call((root||document).querySelectorAll("*"),function(e){if(e.closest&&e.closest("svg"))return;var c=getComputedStyle(e),r=c.borderTopLeftRadius,p=parseFloat(r);if(!r||r.indexOf("%")>-1||!(p>R))return;var w=e.offsetWidth,h=e.offsetHeight;if(w&&h?(Math.abs(w-h)<=2&&p>=w/2-1):(c.width!=="auto"&&c.width===c.height))return;e.style.setProperty("border-radius",R+"px","important")})}function run(){f(document)}if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",run);else run();addEventListener("load",run);document.addEventListener("DOMContentLoaded",function(){new MutationObserver(function(ms){ms.forEach(function(m){[].forEach.call(m.addedNodes,function(n){if(n.nodeType===1){f(n.parentNode||n)}})})}).observe(document.body,{childList:true,subtree:true})})})(' . $rad . ');</script>';
    return $out . '<style id="edu-theme">' . $css . '</style>';
}
