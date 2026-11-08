<?php require_once INCLUDES_PATH . '/settings.php'; require_once INCLUDES_PATH . '/theme.php';
// shortcodes ([site_title], [year] …) work in SEO titles and descriptions too
if (isset($seo) && is_array($seo) && function_exists('edu_shortcodes')) foreach (['title', 'description', 'og_title', 'og_description'] as $__k) if (isset($seo[$__k]) && is_string($seo[$__k])) $seo[$__k] = edu_shortcodes($seo[$__k], true); ?>
<?php require_once INCLUDES_PATH . '/seo.php'; if (edu_seo_advanced()): require_once INCLUDES_PATH . '/seo_head.php'; echo edu_seo_head_advanced($seo, (string)$seo_canonical, $seo_ctx ?? []); else: ?>
<!-- SEO Meta Tags -->
<meta name="description" content="<?= htmlspecialchars($seo['description'], ENT_QUOTES, 'UTF-8') ?>">
<link rel="canonical" href="<?= htmlspecialchars($seo_canonical) ?>">

<!-- Open Graph Tags (for social sharing) -->
<meta property="og:type" content="<?= htmlspecialchars($seo['type'], ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:title" content="<?= htmlspecialchars($seo['og_title'] ?? $seo['title'], ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:description" content="<?= htmlspecialchars($seo['og_description'] ?? $seo['description'], ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:url" content="<?= htmlspecialchars($seo_canonical) ?>">
<meta property="og:image" content="<?= htmlspecialchars($seo['image'], ENT_QUOTES, 'UTF-8') ?>">

<!-- Twitter Card Tags -->
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= htmlspecialchars($seo['og_title'] ?? $seo['title'], ENT_QUOTES, 'UTF-8') ?>">
<meta name="twitter:description" content="<?= htmlspecialchars($seo['og_description'] ?? $seo['description'], ENT_QUOTES, 'UTF-8') ?>">
<meta name="twitter:image" content="<?= htmlspecialchars($seo['image'], ENT_QUOTES, 'UTF-8') ?>">

<!-- Robots Meta -->
<meta name="robots" content="<?= htmlspecialchars($seo['robots'], ENT_QUOTES, 'UTF-8') ?>">
	
<?php endif; ?>
<?php $__tc = THEME_COLOR; if (function_exists('edu_theme')) foreach ((array)(edu_theme()['global_colors'] ?? []) as $__g) if (($__g['slug'] ?? '') === 'accent' && preg_match('/^#[0-9a-f]{3,8}$/i', (string)($__g['color'] ?? ''))) $__tc = $__g['color']; ?>
<meta name="theme-color" content="<?= htmlspecialchars($__tc, ENT_QUOTES, 'UTF-8') ?>">
	
<?php require_once INCLUDES_PATH . '/seo.php'; echo edu_seo_head_tags(); $__fav = es('general', 'favicon'); ?>
<link rel="icon" href="<?= $__fav ? htmlspecialchars($__fav) : '/assets/icons/favicon.ico' ?>"<?= $__fav ? '' : ' type="image/x-icon"' ?>>
<?php if (is_file(ROOT_PATH . '/apple-touch-icon.png')): ?><link rel="apple-touch-icon" href="/apple-touch-icon.png"><?php endif; ?>
<link rel="alternate" type="application/rss+xml" title="<?= edu_site_name() ?> &raquo; Feed" href="<?= rtrim(SITE_URL, '/') ?>/feed/" />

<script id="dm">!function(){let e="1"===localStorage.dm,a=!1,t=!1,r=()=>new Promise((e,t)=>{let r=document.createElement("script");r.src="/assets/js/darkreader.min.js",r.onload=()=>{a=!0,e()},r.onerror=t,document.head.appendChild(r)}),n=()=>DarkReader.enable({brightness:100,contrast:100,sepia:10}),l=()=>DarkReader.disable();async function o(o){if(o.preventDefault(),t)return;t=!0;let d=document.querySelectorAll(".dark-mode-toggle");d.forEach(e=>e.classList.add("loading"));try{a||await r(),e=!e,localStorage.dm=e?"1":"0",e?n():l()}finally{setTimeout(()=>{d.forEach(e=>e.classList.remove("loading")),t=!1},600)}}e&&(a?n():r().then(n)),document.addEventListener("DOMContentLoaded",()=>{document.querySelectorAll(".dark-mode-toggle").forEach(e=>e.onclick=o)})}();</script>



<style id="critical">
:root {

  /* ─── FONTS ─────────────────────────────────── */
  --font-ui:        "Inter", "Segoe UI", Arial, sans-serif;
  --font-body:      "Roboto", sans-serif;
  --font-heading:   "Inter", sans-serif;
  --font-mono:      "Fira Code", "Courier New", monospace; /* for code blocks */

  --text-xs:    12px;
  --text-sm:    13px;
  --text-base:  15px;
  --text-md:    17px;
  --text-lg:    20px;
  --text-xl:    24px;
  --text-2xl:   30px;
  --text-3xl:   38px;

  --weight-normal:   400;
  --weight-medium:   500;
  --weight-semibold: 600;
  --weight-bold:     700;

  --leading-tight:  1.3;
  --leading-base:   1.6;
  --leading-loose:  1.8;

  --letter-tight:  -0.02em;
  --letter-normal:  0;
  --letter-wide:    0.04em;


  /* ─── BRAND COLORS ───────────────────────────── */
  --color-primary:        var(--gc-accent,#0c6878);
  --color-primary-hover:  color-mix(in srgb, var(--gc-accent,#0c6878) 80%, #000);
  --color-primary-active: color-mix(in srgb, var(--gc-accent,#0c6878) 65%, #000);
  --color-primary-light:  color-mix(in srgb, var(--gc-accent,#0c6878) 13%, #fff);
  --color-primary-soft:   #f0f8f9;

  --color-accent:         #3aa0b0;
  --color-accent-hover:   #1a8a9c;

  --color-secondary:      #06404c;


  /* ─── TEXT COLORS ────────────────────────────── */
  --text-primary:   #111827;
  --text-body:      #1f2937;
  --text-secondary: #4b5563;
  --text-muted:     #6b7280;
  --text-faint:     #9ca3af;
  --text-white:     #ffffff;
  --text-link:      var(--gc-accent,#0c6878);
  --text-link-hover:#074b59;


  /* ─── BACKGROUNDS ────────────────────────────── */
  --bg-page:        #f6f8fa;
  --bg-card:        #ffffff;
  --bg-card-hover:  #fcfbff;
  --bg-header:      rgba(255, 255, 255, 0.92);
  --bg-sidebar:     #ffffff;
  --bg-subtle:      #eef6f7;
  --bg-overlay:     rgba(15, 23, 42, 0.55);
  --bg-code:        #0f2a30;  /* inline code / code block bg */
  --bg-tag:         #e0f2f4;


  /* ─── BORDERS ────────────────────────────────── */
  --border-light:   #e3eef0;
  --border-default: #c9dfe3;
  --border-input:   #b5d6dc;
  --border-focus:   var(--gc-accent,#0c6878);
  --border-width:   1px;
  --border-radius-xs:   4px;
  --border-radius-sm:   8px;
  --border-radius-md:   14px;
  --border-radius-lg:   20px;
  --border-radius-full: 999px;


  /* ─── SHADOWS ────────────────────────────────── */
  --shadow-xs: 0 1px 4px rgba(12, 104, 120, 0.06);
  --shadow-sm: 0 2px 8px rgba(12, 104, 120, 0.08);
  --shadow-md: 0 8px 24px rgba(12, 104, 120, 0.12);
  --shadow-lg: 0 16px 40px rgba(12, 104, 120, 0.18);


  /* ─── SPACING ────────────────────────────────── */
  --space-1:  4px;
  --space-2:  8px;
  --space-3:  12px;
  --space-4:  16px;
  --space-5:  20px;
  --space-6:  24px;
  --space-8:  32px;
  --space-10: 40px;
  --space-12: 48px;
  --space-16: 64px;


  /* ─── LAYOUT ─────────────────────────────────── */
  --container-sm:     760px;   /* article / reading width */
  --container-md:     1000px;
  --container-lg:     1200px;
  --container-xl:     1400px;

  --header-height:    72px;
  --sidebar-width:    300px;
  --content-gap:      32px;    /* gap between main + sidebar */


  /* ─── GRADIENTS ──────────────────────────────── */
  /* all from the Accent colour (Customize → Colors), so they change with it */
  --gradient-primary: linear-gradient(135deg, var(--gc-accent,#0c6878) 0%, color-mix(in srgb, var(--gc-accent,#0c6878) 70%, #3bd9f0) 100%);
  --gradient-soft:    linear-gradient(135deg, color-mix(in srgb, var(--gc-accent,#0c6878) 6%, #fff) 0%, color-mix(in srgb, var(--gc-accent,#0c6878) 13%, #fff) 100%);
  --gradient-dark:    linear-gradient(135deg, color-mix(in srgb, var(--gc-accent,#0c6878) 55%, #000) 0%, var(--color-primary-hover,#095666) 100%);
  --gradient-hero:    linear-gradient(160deg, color-mix(in srgb, var(--gc-accent,#0c6878) 6%, #fff) 0%, color-mix(in srgb, var(--gc-accent,#0c6878) 13%, #fff) 60%, #f6f8fa 100%);


  /* ─── TRANSITIONS ────────────────────────────── */
  --ease-fast:   0.15s ease;
  --ease-normal: 0.25s ease;
  --ease-slow:   0.4s ease;


  /* ─── Z-INDEX ────────────────────────────────── */
  --z-base:    1;
  --z-card:    10;
  --z-overlay: 999;
  --z-header:  1000;
  --z-sidebar: 1001;
  --z-modal:   1100;
  --z-toast:   1200;
  --z-search:  2000;


  /* ─── COMPONENTS ─────────────────────────────── */

  /* Buttons */
  --btn-height-sm:  34px;
  --btn-height-md:  42px;
  --btn-height-lg:  50px;
  --btn-padding-sm: 0 14px;
  --btn-padding-md: 0 20px;
  --btn-padding-lg: 0 28px;

  /* Cards */
  --card-padding:   24px;
  --card-gap:       28px;
  --card-radius:    var(--border-radius-md);
  --card-border:    var(--border-light);
  --card-shadow:    var(--shadow-sm);

  /* Inputs */
  --input-height:   44px;
  --input-padding:  0 14px;
  --input-radius:   var(--border-radius-sm);
  --input-bg:       #ffffff;

  /* Badge / Tags */
  --tag-padding:    3px 10px;
  --tag-radius:     var(--border-radius-full);
  --tag-font-size:  var(--text-xs);

  /* Reading */
  --article-width:  680px;   /* max width for article content */
  --article-gap:    var(--space-6);


  /* ─── SCROLLBAR ──────────────────────────────── */
  --scrollbar-width:       6px;
  --scrollbar-track:       #eef6f7;
  --scrollbar-thumb:       #c4b5fd;
  --scrollbar-thumb-hover: #9f67e8;


  /* ─── SELECTION ──────────────────────────────── */
  --selection-bg:   #c4b5fd;
  --selection-text: #0f2a30;

}

*,
*::before,
*::after {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
}

* {
  -webkit-tap-highlight-color: transparent;
}

html {
  scroll-behavior: smooth;
}

body {
  font-family: var(--font-body);
  font-size: var(--text-base);
  line-height: var(--leading-base);
  color: var(--text-body);
  background: var(--bg-page);
  -webkit-font-smoothing: antialiased;
  -moz-osx-font-smoothing: grayscale;
}

a {
  text-decoration: none;
  color: var(--text-link);
  transition: color var(--ease-fast);
}

a:hover {
  color: var(--text-link-hover);
}

h1, h2, h3, h4, h5, h6 {
  font-family: var(--font-heading);
  color: var(--text-primary);
  line-height: var(--leading-tight);
  letter-spacing: var(--letter-tight);
  font-weight: var(--weight-bold);
}

img {
  max-width: 100%;
  display: block;
}

::selection {
  background: var(--selection-bg);
  color: var(--selection-text);
}

/* Scrollbar */
::-webkit-scrollbar {
  width: var(--scrollbar-width);
}
::-webkit-scrollbar-track {
  background: var(--scrollbar-track);
}
::-webkit-scrollbar-thumb {
  background: var(--scrollbar-thumb);
  border-radius: var(--border-radius-full);
}
::-webkit-scrollbar-thumb:hover {
  background: var(--scrollbar-thumb-hover);
}



/* ── Global Container ──────────────────────────── */
.container {
  max-width: var(--container-lg);
  margin: 0 auto;
  padding: 0 var(--space-4);
}


/* ── Search Overlay ────────────────────────────── */
.search-overlay {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: var(--bg-card);
  z-index: var(--z-search);
  display: flex;
  align-items: center;
  justify-content: center;
  opacity: 0;
  visibility: hidden;
  transform: translateY(-10px);
  transition:
    opacity var(--ease-normal),
    visibility var(--ease-normal),
    transform var(--ease-normal);
}

header.search-active .search-overlay {
  opacity: 1;
  visibility: visible;
  transform: translateY(0);
}

.search-container {
  width: 100%;
  max-width: var(--container-xl);
  padding: 0 var(--space-4);
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.input-wrapper {
  position: relative;
  width: 90%;
  display: flex;
  align-items: center;
}

.input-icon {
  position: absolute;
  left: var(--space-2);
  pointer-events: none;
  color: var(--text-muted);
}

.search-overlay input {
  width: 100%;
  height: var(--input-height);
  border: var(--border-width) dotted var(--border-input);
  padding: 0 var(--space-8) 0 var(--space-8);
  outline: none;
  border-radius: var(--border-radius-sm);
  background: transparent;
  color: var(--color-primary);
  font-size: var(--text-sm);
  font-family: var(--font-body);
}

.search-overlay input::placeholder {
  color: var(--text-faint);
}

.search-overlay input:focus {
  border-color: var(--border-focus);
}

.close-search {
  background: none;
  border: none;
  cursor: pointer;
  padding: var(--space-1);
  font-size: var(--text-lg);
  margin-left: var(--space-2);
  color: var(--text-muted);
}


/* ── Voice Icon ────────────────────────────────── */
.voice-icon {
  border: none;
  background: none;
  position: absolute;
  right: var(--space-2);
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
}

.voice-icon::before {
  content: "";
  position: absolute;
  inset: -8px;
  border-radius: 50%;
  background: rgba(12, 104, 120, 0.25);
  opacity: 0;
}

.voice-icon.listening::before {
  animation: listen 1s ease-out infinite;
  opacity: 1;
}

.voice-icon.listening svg {
  animation: micPulse 1s ease-in-out infinite;
}

@keyframes listen {
  0%   { transform: scale(0.7); opacity: 0.6; }
  70%  { transform: scale(1.12); opacity: 0.25; }
  100% { opacity: 0.15; }
}

@keyframes micPulse {
  0%, 100% { transform: scale(1); }
  50%       { transform: scale(1.12); }
}


/* ── Page Title (main H1) ──────────────────────── */
.page-title {
  font-size: var(--text-xl);
  font-weight: var(--weight-bold);
  color: var(--text-primary);
  padding: var(--space-2) 0;
}


/* ── Global Breadcrumbs ───────────────────────────────── */
.breadcrumbs {
  font-family: var(--font-ui);
  font-size: var(--text-sm);
  margin: var(--space-2) var(--space-4) 0;
}

.breadcrumbs ol {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  list-style: none;
  padding: 0;
  margin: 0;
  color: var(--text-muted);
}

.breadcrumbs li {
  display: flex;
  align-items: center;
  margin-left: 0;
}

.breadcrumbs li:not(:last-child)::after {
  content: '/';
  margin: 0 var(--space-1);
  color: var(--text-faint);
}

.breadcrumbs a {
  color: var(--text-link);
  transition: color var(--ease-fast);
}

.breadcrumbs a:hover {
  color: var(--text-link-hover);
  text-decoration: underline;
}

.breadcrumbs li[aria-current="page"] {
  color: var(--text-secondary);
  font-weight: var(--weight-semibold);
}




/* Quick Links Container (Home and Categories) */
.ql-container {
  position: relative;
  margin-bottom: var(--space-5);
}

.ql-mask {
  max-height: 120px;
  overflow: hidden;
  transition: max-height 0.5s ease-in-out;
  position: relative;
}

.ql-mask.expanded {
  max-height: 2000px;
}

.ql-mask::after {
  content: "";
  position: absolute;
  bottom: 0;
  left: 0;
  width: 100%;
  height: 60px;
  background: linear-gradient(to bottom, transparent, var(--bg-page));
  pointer-events: none;
  transition: opacity var(--ease-normal);
}

.ql-mask.expanded::after {
  opacity: 0;
}

.ql-table {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: var(--space-1);
  list-style: none;
  padding: 0;
  margin: 0 0 var(--space-5) 0;
}

.ql-table li {
  border: var(--border-width) dotted var(--border-default);
  padding: var(--space-2) var(--space-1);
  margin: 0;
  background: var(--bg-subtle);
  text-align: center;
}

.ql-table a {
  color: var(--text-body);
  font-size: var(--text-sm);
  font-weight: var(--weight-medium);
  display: block;
  width: 100%;
}

.ql-table a:hover {
  background: var(--color-primary-soft);
  color: var(--color-primary);
  text-decoration: underline;
}

.ql-toggle-btn {
  display: block;
  margin: var(--space-2) auto 0;
  background: var(--bg-card);
  border: var(--border-width) dotted var(--color-primary);
  color: var(--color-primary);
  padding: var(--space-2) var(--space-5);
  border-radius: var(--border-radius-full);
  font-size: var(--text-xs);
  cursor: pointer;
  transition: background var(--ease-fast), color var(--ease-fast);
}

.ql-toggle-btn:hover {
  background: var(--color-primary);
  color: var(--text-white);
}

@media (max-width: 768px) {
  .ql-table {
    grid-template-columns: repeat(2, 1fr);
  }
}




/* ── Global Post Grid ─────────────────────────────────── */
.post-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
  gap: var(--card-gap);
  margin: 0;
}

/* ── Post Card ─────────────────────────────────── */
.post-card {
  background: var(--bg-card);
  overflow: hidden;
  box-shadow: var(--shadow-sm);
  cursor: pointer;
  border-radius: var(--border-radius-sm);
  color: inherit;
  will-change: transform;
  transition: box-shadow var(--ease-normal), transform var(--ease-normal);
}

.post-card:hover {
  box-shadow: var(--shadow-md);
  transform: translateY(-2px);
}

.post-card-link {
  text-decoration: none;
  color: inherit;
  display: block;
}

/* ── Card Banner ───────────────────────────────── */
.post-banner {
  width: 100%;
  height: 200px;
  overflow: hidden;
  background: var(--bg-subtle);
  position: relative;
}

.post-banner img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  transition: transform var(--ease-slow);
  will-change: transform;
}

.post-card:hover .post-banner img {
  transform: scale(1.05);
}

/* ── Banner Placeholder (no image) ────────────── */
.post-banner-placeholder {
  width: 100%;
  height: 100%;
  background: var(--gradient-primary);
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--text-white);
  font-size: 48px;
  font-weight: var(--weight-bold);
}

/* ── Card Content ──────────────────────────────── */
.post-card-content {
  padding: var(--card-padding);
}

.post-card-title {
  font-size: var(--text-lg);
  font-weight: var(--weight-bold);
  margin: 0 0 var(--space-3) 0;
  color: var(--text-primary);
  line-height: var(--leading-tight);
}

.post-card-author {
  font-family: var(--font-ui);
  font-size: var(--text-sm);
  color: var(--color-primary);
  font-weight: var(--weight-semibold);
  margin: 0;
}

/* ── Responsive ────────────────────────────────── */
@media (max-width: 768px) {
  .post-grid {
    grid-template-columns: 1fr;
    gap: var(--space-5);
  }
}


/* ── Author Bio (post page: bottom / author page: top) ── */
.author-bio {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  gap: var(--space-3);
  padding: var(--card-padding);
  background: var(--bg-card);
  border: var(--border-width) solid var(--border-light);
  border-radius: var(--card-radius);
  box-shadow: var(--card-shadow);
}

.author-bio p {
  text-align: justify;
  color: var(--text-secondary);
  line-height: var(--leading-loose);
}

.author-follow-links {
  display:flex;
  gap:14px;
  flex-wrap:wrap;
  margin-top:8px;
}

/* ── Promo Top Bar ─────────────────────────────── */
.promo-top-bar {
  position: sticky;
  top: 0;
  z-index: var(--z-overlay);
  background-color: #111827;
  color: var(--text-white);
  padding: var(--space-1);
  box-shadow: var(--shadow-sm);
  display: flex;
  justify-content: center;
  align-items: center;
  gap: var(--space-1);
  transition: transform var(--ease-normal);
  overflow: hidden;
}

.ptb-message {
  font-size: var(--text-xs);
  font-weight: var(--weight-medium);
  margin: 0;
  letter-spacing: 0.5px;
  min-width: 230px;
  text-align: center;
  display: flex;
  justify-content: center;
}


.ptb-btn {
  background-color: var(--color-primary);
  color: var(--text-white);
  text-decoration: none;
  padding: 2px var(--space-3);
  border-radius: var(--border-radius-full);
  font-size: var(--text-xs);
  font-weight: var(--weight-bold);
  white-space: nowrap;
  transition: background-color var(--ease-fast);
}

.ptb-btn:hover {
  background-color: var(--color-primary-hover);
}

.ptb-close {
  background: transparent;
  border: none;
  color: rgba(255, 255, 255, 0.6);
  font-size: var(--text-sm);
  cursor: pointer;
  padding: 0 var(--space-1);
  margin-left: var(--space-1);
  transition: color var(--ease-fast);
}

.ptb-close:hover {
  color: var(--text-white);
}

.anim-txt {
  display: inline-block;
  transition: transform 0.5s cubic-bezier(0.25, 1, 0.5, 1), opacity 0.5s ease;
  will-change: transform, opacity;
}

.txt-out {
  transform: translateY(-150%) scale(0.95);
  opacity: 0;
}

.txt-in {
  transform: translateY(150%) scale(0.95);
  opacity: 0;
  transition: none;
}

/* No post class show */
.no-post {
	margin: 80px auto;
	text-align: center;
	color: #686868;
	font-style: italic
}


/* PAGE SPECIFIC UI CRITICAL */

/* ── Category Section Header ───────────────────── */
.category-section-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: var(--space-8);
  padding-bottom: 0.2rem;
  border-bottom: var(--border-width) dotted var(--border-dark);
}

.category-section-title {
  font-size: var(--text-xl);
  color: var(--text-primary);
  margin: 0;
}

.view-all-link {
  color: var(--color-primary);
  font-weight: var(--weight-semibold);
  font-size: var(--text-base);
  transition: color var(--ease-fast);
}

.view-all-link:hover {
  color: var(--color-primary-hover);
}

.no-posts-message {
  color: var(--text-secondary);
  text-shadow: 0 0 1px rgba(0, 0, 0, 0.1);
  font-style: italic;
  padding: var(--space-8) 0;
}

</style>
<style id="theme-critical">:root{--hd-bg: var(--t-primary-nav-nav-bg,var(--t-header-background,var(--gc-accent,#0c6878)));--hd-text: var(--t-primary-nav-nav-text,#ffffff);--hd-hover: var(--t-menu-strip-text-hover,#d50808);--hd-scroll-bg: var(--t-menu-strip-background,#e1e1e1);--hd-scroll-text: var(--t-menu-strip-text,#1d1d1d);--text-body: var(--t-content-text,var(--t-body-text,#373737));--text-primary: var(--t-content-title,#111827);--text-link: var(--t-body-link,var(--gc-accent,#0c6878));--text-link-hover: var(--t-body-link-hover,#d50808);--bg-page: var(--t-body-background,#ffffff);--bg-card: var(--t-content-background,#ffffff);--color-primary: var(--gc-accent,#0c6878);--color-primary-hover: var(--t-buttons-background-hover,var(--color-primary-hover,#095666));--ed-mob-nav: var(--t-off-canvas-background,var(--gc-accent,#0c6878));--ed-mob-nav-active: var(--t-off-canvas-background-hover,#0f7485);--ed-mob-nav-deep: var(--t-off-canvas-sub-bg,var(--color-primary-hover,#095666));--ed-mob-nav-text: var(--t-off-canvas-text,#f7ffff)}html body{background: var(--t-body-background,#fff);color: var(--t-body-text,#373737);display: block;min-height: 100%}html body a,html body a:link,html body a:visited,html body a:hover,html body a:focus,html body a:focus-visible,html body a:active,html body .entry-content a,html body .content a,html body a *,html body .faq a,html body .single-post .breadcrumbs a:hover,html body .single-post .breadcrumbs a:focus-visible{text-decoration: none !important;text-decoration-line: none !important}html body .entry-content a,html body .content a{color: var(--t-content-link,#1e73be)}html body .entry-content a:hover,html body .content a:hover{color: var(--t-content-link-hover,#d50808)}.nb-top-bar{background: var(--t-top-bar-background,#636363);color: var(--t-top-bar-text,#fff);font-size: 13px;padding: 6px 25px;text-align: center}.nb-top-bar a{color: var(--t-top-bar-link,#fff)}.nb-top-bar a:hover{color: var(--t-top-bar-link-hover,#fff);opacity: .85}#site-navigation{background: var(--hd-bg)}#site-navigation.nb-not-sticky{position: relative}.navigation-branding .site-logo a{gap: 10px}.nb-site-branding{display: flex;flex-direction: column;line-height: 1.2}.site-title{color: var(--t-header-site-title,#fff);font-size: 22px;font-weight: 700}.site-tagline{color: var(--t-header-tagline,#e1e1e1);font-size: 12px;font-weight: 500}.is-logo-image{max-height: var(--hd-h);width: auto;object-fit: contain}.main-navigation .main-nav ul li a,.main-navigation .menu-bar-item>a{color: var(--t-primary-nav-nav-text,#fff)}.main-navigation .main-nav>ul>li>a:hover{color: var(--t-primary-nav-nav-text-hover,#fff);background: var(--t-primary-nav-nav-bg-hover,transparent)}.main-navigation .main-nav>ul>li.current-menu-item>a{color: var(--t-primary-nav-nav-text-current,#fff);background: var(--t-primary-nav-nav-bg-current,transparent)}.nb-search-icon{padding: 0 16px;font-size: 18px}.nb-search-icon .gp-icon svg{width: 18px;height: 18px}#push-notify-btn[hidden]{display: none !important}.main-navigation .main-nav>ul>li{position: relative}.main-navigation .main-nav .sub-menu{display: none}.nb-caret{display: inline-block;width: 6px;height: 6px;margin-left: 6px;border-right: 2px solid currentColor;border-bottom: 2px solid currentColor;transform: rotate(45deg) translateY(-3px)}@media (min-width: 981px){.main-navigation .main-nav>ul>li:hover>.sub-menu,.main-navigation .main-nav>ul>li:focus-within>.sub-menu{display: block;position: absolute;top: 100%;left: 0;min-width: 210px;z-index: 600;background: var(--t-primary-nav-sub-bg,var(--hd-bg));box-shadow: 0 6px 16px rgba(0,0,0,.14);padding: 4px 0}.main-navigation .main-nav .sub-menu li{display: block}.main-navigation .main-nav .sub-menu a{line-height: 1.4 !important;padding: 10px 16px !important;color: var(--t-primary-nav-sub-text,#fff) !important;font-size: 14px}.main-navigation .main-nav .sub-menu a:hover{color: var(--t-primary-nav-sub-text-hover,#fff) !important;background: var(--t-primary-nav-sub-bg-hover,rgba(255,255,255,.08))}}@media (max-width: 980px){.slideout-navigation .sub-menu,.slideout-navigation .nb-caret{display: none !important}}.inb-scroll-menu-inner{max-width: var(--nb-container,1200px)}.grid-container{max-width: var(--nb-container,1200px)}.gp-modal__overlay{background: var(--t-search-modal-overlay-bg,rgba(0,0,0,.2))}.search-modal-form{background: var(--t-search-modal-field-bg,#fff);color: var(--t-search-modal-field-text,#111827)}.nb-btn,.post-join-btn,.blog-comment-submit-btn,.cf-submit,.wp-block-button__link{background: var(--t-buttons-background,var(--gc-accent,#0c6878));color: var(--t-buttons-text,#fff)}.nb-btn:hover,.post-join-btn:hover,.blog-comment-submit-btn:hover,.cf-submit:hover,.wp-block-button__link:hover{background: var(--t-buttons-background-hover,var(--color-primary-hover,#095666));color: var(--t-buttons-text-hover,#fff)}.nb-site input[type="text"],.nb-site input[type="email"],.nb-site input[type="url"],.nb-site input[type="search"]:not(.search-field):not(#topbar-search-input):not(#mobile-search-input),.nb-site textarea,.nb-site select{background-color: var(--t-forms-field-bg,#fff);color: var(--t-forms-field-text,#111827);border-color: var(--t-forms-field-border,#dcdfe4)}.nb-site input[type="text"]:focus,.nb-site input[type="email"]:focus,.nb-site input[type="url"]:focus,.nb-site textarea:focus,.nb-site select:focus{background-color: var(--t-forms-field-bg-focus,var(--t-forms-field-bg,#fff));color: var(--t-forms-field-text-focus,var(--t-forms-field-text,#111827));border-color: var(--t-forms-field-border-focus,var(--gc-accent,#0c6878))}.entry-content h1{color: var(--t-content-h1,var(--text-primary))}.entry-content h2{color: var(--t-content-h2,var(--text-primary))}.entry-content h3{color: var(--t-content-h3,var(--text-primary))}.entry-content h4{color: var(--t-content-h4,var(--text-primary))}.entry-content h5{color: var(--t-content-h5,var(--text-primary))}.entry-content h6{color: var(--t-content-h6,var(--text-primary))}.single-post h1.entry-title{color: var(--t-content-title,#111827)}.post-meta-row,.post-meta-date,.nb-card-meta{color: var(--t-content-meta-text,#595959)}.post-meta-by a,.nb-card-meta a{color: var(--t-content-meta-link,#222)}.post-meta-by a:hover,.nb-card-meta a:hover{color: var(--t-content-meta-link-hover,var(--gc-accent,#0c6878))}.post-sidebar .trending-box{background: var(--t-sidebar-background,#fff);color: var(--t-sidebar-text,#373737)}.post-sidebar .trending-box-header{background: var(--t-sidebar-title-bg,var(--gc-accent,#0c6878)) !important}.post-sidebar .trending-box-header h2,.post-sidebar .trending-box-header svg{color: var(--t-sidebar-title,#fff) !important;fill: currentColor}.post-sidebar .trending-item-title a{color: var(--t-sidebar-link,#111827)}.post-sidebar .trending-item-title a:hover{color: var(--t-sidebar-link-hover,var(--gc-accent,#0c6878))}.ftx-footer{--ftx-bg: var(--t-footer-widgets-background,var(--gc-accent,#0c6878));--ftx-accent: var(--t-footer-widgets-accent,#ffb700);color: var(--t-footer-widgets-text,#fff)}.ftx-footer .ftx-heading{color: var(--t-footer-widgets-title,#fff);font-weight: 600}.ftx-footer .ftx-link{color: var(--t-footer-widgets-link,#fff)}.ftx-footer .ftx-link:hover{color: var(--t-footer-widgets-link-hover,#ffb700)}.ftx-footer .ftx-desc{color: var(--t-footer-widgets-text,#fff)}.ftx-footer .ftx-desc p{margin: 0 0 .6em}.ftx-site-name{color: #fff;font-size: 24px;font-weight: 700;margin-bottom: 10px}.ftx-cta-card{text-align: center;padding: 22px 14px 18px}.ftx-cta-card .ftx-cta-text{align-items: center;margin-bottom: 14px}.ftx-cta-card .ftx-cta-title,.ftx-cta-card .ftx-cta-subtitle{text-align: center}.ftx-cta-card .ftx-social-row{justify-content: center;column-gap: 12px}.ftx-cta-card .ftx-cta-btn{margin-top: 12px}.ftx-social-btn{color: #fff !important}.ftx-social-btn .ftx-ico{font-size: 1.35em}.ftx-copy{background: var(--t-footer-bar-background,transparent);color: var(--t-footer-bar-text,#fff)}.ftx-copy a{color: var(--t-footer-bar-link,#fff)}.ftx-copy a:hover{color: var(--t-footer-bar-link-hover,#ffb700)}.mobile-drawer-follow{padding: 14px 12px;border-top: 1px solid rgba(255,255,255,.16)}.mobile-drawer-follow-title{color: #fff;font-weight: 700;font-size: 14px;margin-bottom: 8px}.mobile-drawer-socials{display: flex;flex-wrap: wrap;gap: 14px}.mobile-drawer-socials a{color: #fff}.mobile-drawer-social-icon{width: 20px;height: 20px}html.nb-in-customizer #wpadminbar,html.nb-in-customizer #ab-boot{display: none !important}html.nb-in-customizer body{padding-top: 0 !important;margin-top: 0 !important}html.nb-in-customizer body:has(#wpadminbar){margin-top: 0 !important;padding-top: 0 !important}html.nb-in-customizer body:has(#wpadminbar) #site-navigation,html.nb-in-customizer body:has(#wpadminbar) #site-navigation.nb-h-racing.is-sticky{top: 0 !important}html.nb-in-customizer body:has(#wpadminbar) .mnav,html.nb-in-customizer body:has(#wpadminbar) .mnav-overlay{top: 0 !important}html.nb-in-customizer #wpadminbar,html.nb-in-customizer #ab-boot{display: none !important}body:has(#wpadminbar) .nb-header.is-sticky,body:has(#wpadminbar) #site-navigation:not(.nb-not-sticky):not(.nb-h-racing){top: var(--wpab-top,32px)}body:has(#wpadminbar) .mnav{top: var(--wpab-top,32px)}body:has(#wpadminbar) .mnav-overlay{top: var(--wpab-top,32px)}html.nb-in-customizer body:has(#wpadminbar) .nb-header{top: 0}.nb-bell-wrap{position: relative;display: inline-flex}.nb-bell{position: relative;display: inline-flex;align-items: center;justify-content: center;gap: 6px;height: 38px;min-width: 38px;padding: 0 6px;border: 0;background: transparent;color: inherit;cursor: pointer}.nb-bell svg{width: 22px;height: 22px;transform-origin: top center;animation: nbRing 2.4s ease-in-out 3}.nb-bell.is-denied svg{animation: none;opacity: .8}.nb-bell-dot{position: absolute;top: 6px;right: 6px;width: 9px;height: 9px;border-radius: 50%;background: #e53935;border: 2px solid var(--hd-bg,var(--gc-accent,#0c6878))}.nb-h-racing .nb-bell-dot{border-color: #fff}.nb-bell-label{font-size: 13px;font-weight: 600}@keyframes nbRing{0%,60%,100%{transform: rotate(0)}10%,30%{transform: rotate(14deg)}20%,40%{transform: rotate(-14deg)}}.nb-push-toast{position: fixed;left: 50%;bottom: 24px;z-index: 10001;transform: translateX(-50%);padding: 11px 20px;border-radius: 999px;background: var(--gc-accent,#0c6878);color: #fff;font-weight: 700;font-size: 14px;box-shadow: 0 8px 24px rgba(0,0,0,.2);animation: nbToast .3s ease}@keyframes nbToast{from{opacity: 0;transform: translate(-50%,12px)}}.main-navigation .menu-bar-item .nb-bell{color: var(--hd-text,#fff);height: var(--hd-h)}.nb-hc-auth{display: flex;align-items: center;gap: 14px;margin-left: 14px}.nb-hc-login{display: inline-flex;align-items: center;gap: 6px;color: var(--hd-text,#fff) !important;font-size: 14px;font-weight: 600}.nb-hc-login svg{width: 18px;height: 18px}.nb-hc-sub{display: inline-flex;align-items: center;height: 34px;padding: 0 16px;border-radius: 6px;background: #fff;color: var(--hd-bg,var(--gc-accent,#0c6878)) !important;font-size: 14px;font-weight: 700;transition: transform .18s,box-shadow .18s;white-space: nowrap}.nb-hc-sub:hover{transform: translateY(-1px);box-shadow: 0 6px 16px rgba(0,0,0,.2)}.nb-hc-burger{display: none !important}.nb-hc-burger .menu-toggle{display: flex;align-items: center;color: var(--hd-text,#fff);background: none;border: 0;padding: 0 14px;height: var(--hd-h);cursor: pointer}.main-navigation .menu-toggle:not(.nb-hc-burger .menu-toggle){display: none !important}@media (max-width: 980px){.nb-hc-burger{display: flex !important}.nb-hc-login{display: none !important}.nb-hc-auth{margin-left: 4px}.nb-hc-sub{height: 30px;padding: 0 12px;font-size: 13px}.main-navigation .menu-bar-items{margin-left: auto}}@media (max-width: 560px){.nb-hc-sub{padding: 0 10px;font-size: 12.5px}}.slideout-navigation,.slideout-overlay{display: none !important}.nb-h-racing{position: relative;z-index: 500;background: var(--t-header-racing-bg,#fff);border-bottom: 1px solid #e6e8ec;color: var(--t-header-racing-text,#1d2327)}.nb-h-racing.is-sticky{position: sticky;top: 0}.nb-h-racing.is_stuck{box-shadow: 0 2px 10px rgba(0,0,0,.06)}.nb-h-racing *{box-sizing: border-box}.nb-hr-top{max-width: var(--nb-container,1200px);margin: 0 auto;display: grid;grid-template-columns: 1fr auto 1fr;align-items: center;gap: 16px;padding: 14px 24px}.nb-hr-soc{display: flex;gap: 18px;align-items: center}.nb-hr-soc a{color: #26303a;display: inline-flex;transition: color .15s}.nb-hr-soc a:hover{color: var(--gc-accent,#0c6878)}.nb-hr-soc-ico{width: 19px;height: 19px}.nb-hr-brand{justify-self: center;text-align: center}.nb-hr-brand .nb-brand-link{display: flex;align-items: center;gap: 10px}.nb-h-racing .is-logo-image{max-height: var(--hd-h,50px);width: auto}.nb-h-racing .site-title{color: var(--t-header-site-title,var(--gc-accent,#0c6878));font-size: 30px;font-weight: 800;letter-spacing: .08em}.nb-h-racing .site-tagline{color: var(--t-header-tagline,#454f5e);letter-spacing: .3em;font-size: 12px;text-transform: uppercase}.nb-h-racing .nb-site-branding{align-items: center}.nb-hr-act{justify-self: end;display: flex;align-items: center;gap: 12px}.nb-hr-act .nb-bell,.nb-hr-act .nb-search-icon{color: #1d2327;height: 38px;display: inline-flex;align-items: center;padding: 0 4px}.nb-hr-act .nb-search-icon svg{width: 18px;height: 18px;fill: currentColor}.nb-hr-login{display: inline-flex;align-items: center;gap: 8px;padding: 8px 4px;color: #1d2327 !important;font-size: 15px;font-weight: 600}.nb-hr-login:hover{color: var(--gc-accent,#0c6878) !important}.nb-hr-login svg{width: 18px;height: 18px}.nb-hr-div{width: 1px;height: 26px;background: #e6e8ec}.nb-hr-sub{display: inline-flex;align-items: center;padding: 11px 22px;border-radius: 6px;background: var(--t-buttons-background,var(--gc-accent,#0c6878));color: var(--t-buttons-text,#fff) !important;font-size: 15px;font-weight: 700;transition: background .18s,box-shadow .18s,transform .18s;white-space: nowrap}.nb-hr-sub:hover{background: var(--t-buttons-background-hover,var(--color-primary-hover,#095666));color: var(--t-buttons-text-hover,var(--t-buttons-text,#fff)) !important;box-shadow: 0 6px 16px rgba(0,0,0,.18);transform: translateY(-1px)}.nb-burger{display: none;align-items: center;justify-content: center;padding: 6px;margin-left: 2px;border: 0;background: none;color: #1d2327;cursor: pointer}.nb-hr-nav{border-top: 1px solid #e6e8ec}.nb-h-racing .inb-scroll-menu{background: transparent}.nb-h-racing .inb-scroll-menu-inner{justify-content: center;gap: 30px;padding: 0 24px}.nb-h-racing .inb-scroll-menu a{position: relative;padding: 14px 2px;color: #1d2327;font-size: 15px;font-weight: 600}.nb-h-racing .inb-scroll-menu a::after{content: "";position: absolute;left: 0;right: 0;bottom: 8px;height: 2px;background: var(--gc-accent,#0c6878);transform: scaleX(0);transition: transform .18s}.nb-h-racing .inb-scroll-menu a:hover{color: var(--gc-accent,#0c6878)}.nb-h-racing .inb-scroll-menu a:hover::after{transform: scaleX(1)}.nb-h-racing .inb-scroll-menu a.current:not([href="/"])::after{transform: scaleX(1)}@media (max-width: 980px){.nb-hr-top{grid-template-columns: auto 1fr auto;gap: 10px;padding: 10px 14px}.nb-hr-soc,.nb-hr-login,.nb-hr-div{display: none !important}.nb-hr-brand{justify-self: start}.nb-h-racing .site-title{font-size: 22px;letter-spacing: .06em}.nb-h-racing .site-tagline{font-size: 10px;letter-spacing: .2em}.nb-h-racing .is-logo-image{max-height: 40px}.nb-hr-act{gap: 6px}.nb-hr-sub{padding: 9px 14px;font-size: 14px}.nb-burger{display: inline-flex}.nb-h-racing .inb-scroll-menu-inner{justify-content: flex-start;gap: 22px;padding: 0 14px}.nb-h-racing .inb-scroll-menu a{padding: 11px 2px;font-size: 14px}}@media (max-width: 400px){.nb-hr-sub{padding: 8px 10px;font-size: 13px}.nb-hr-act .nb-search-icon{display: none}}.nb-strip--center .inb-scroll-menu-inner{justify-content: center}.nb-strip--pills .inb-scroll-menu-inner{padding: 8px;gap: 8px}.nb-strip--pills a{padding: 4px 14px !important;border-radius: 999px;background: rgba(255,255,255,.7);border: 1px solid rgba(0,0,0,.08)}.nb-strip--pills a:hover{background: var(--gc-accent,#0c6878);color: #fff !important}.nb-strip--underline a{position: relative;padding-bottom: 4px !important}.nb-strip--underline a::after{content: "";position: absolute;left: 6px;right: 6px;bottom: 0;height: 2px;background: currentColor;transform: scaleX(0);transition: transform .18s}.nb-strip--underline a:hover::after{transform: scaleX(1)}@media (max-width: 980px){.nb-strip--center .inb-scroll-menu-inner{justify-content: flex-start}}.nb-drawer{--mnav-accent: var(--gc-accent,#0c6878);z-index: 100002}.mnav-overlay{z-index: 100001}.nb-drawer .mnav-brand img{max-height: 36px;width: auto}.nb-drawer-name{font-size: 18px;font-weight: 800;color: var(--gc-accent,#0c6878)}.nb-drawer .mnav-dark.is-on .mnav-switch{background: var(--gc-accent,#0c6878)}.nb-drawer-follow{margin-top: auto;padding: 16px 18px 22px;border-top: 1px solid var(--mnav-line,#eaeaf2);background: var(--mnav-soft,#f8f9fe)}.nb-drawer-follow>span{display: block;font-size: 13px;color: var(--mnav-muted,#8b8ba3);margin-bottom: 10px}.nb-drawer-follow>div{display: flex;flex-wrap: wrap;gap: 10px}.nb-drawer-follow a{width: 40px;height: 40px;border-radius: 50%;display: inline-flex;align-items: center;justify-content: center;background: var(--gc-accent,#0c6878);color: #fff !important}.nb-drawer-ico{width: 18px;height: 18px}.nb-drawer .mnav-foot + .nb-drawer-follow{margin-top: 0}@media (min-width: 981px){.nb-drawer,.nb-drawer + .mnav-overlay{display: none}}#site-navigation.nb-h-racing{background: var(--t-header-racing-bg,#fff);color: var(--t-header-racing-text,#1d2327);font-size: 15px;position: relative}#site-navigation.nb-h-racing.is-sticky{position: sticky;top: 0}body:has(#wpadminbar) #site-navigation.nb-h-racing.is-sticky{top: var(--wpab-top,32px)}#site-navigation.nb-h-racing .site-title{color: var(--t-header-site-title,var(--gc-accent,#0c6878))}#site-navigation.nb-h-racing .inb-scroll-menu{background: transparent}#site-navigation.nb-h-racing .inb-scroll-menu a{color: var(--t-header-racing-text,#1d2327)}#site-navigation.nb-h-racing .inb-scroll-menu a:hover{color: var(--gc-accent,#0c6878)}.nb-hc-auth a{flex-direction: row !important;white-space: nowrap}.main-navigation .inside-navigation{flex-wrap: nowrap}.main-navigation .main-nav ul{flex-wrap: nowrap}.main-navigation .main-nav ul li a{white-space: nowrap}@media (max-width: 1280px) and (min-width: 561px){.topbar-search{width: 190px;max-width: 190px}}@media (max-width: 1140px){#site-navigation:not(.nb-h-racing) .main-nav{display: none}.nb-hc-burger{display: flex !important}.navigation-branding{margin-right: auto}}@media (max-width: 1140px) and (min-width: 981px){.topbar-search{width: 220px;max-width: 220px}}.main-navigation .menu-bar-item>a.nb-hc-login{display: inline-flex;align-items: center;gap: 6px;padding: 0;line-height: 1.2;height: auto;color: var(--hd-text,#fff)}.main-navigation .menu-bar-item>a.nb-hc-sub{display: inline-flex;align-items: center;height: 34px;padding: 0 16px;line-height: 1.2;color: var(--hd-bg,var(--gc-accent,#0c6878))}@media (max-width: 980px){.main-navigation .menu-bar-item>a.nb-hc-sub{height: 30px;padding: 0 12px}}@media (max-width: 560px){.main-navigation .menu-bar-item>a.nb-hc-sub{padding: 0 10px}}.main-navigation .menu-bar-item>a.nb-search-icon{display: inline-flex;align-items: center;padding: 0 12px}.navigation-branding{flex-shrink: 0}@media (max-width: 560px){.navigation-branding .is-logo-image{max-width: 150px;height: auto;max-height: calc(var(--hd-h) - 12px)}.main-navigation .menu-bar-items{gap: 0}}.nb-hr-sub{padding: 6px 15px;border-radius: 5px;font-size: 14px;font-weight: 600;line-height: 1.3}@media (max-width: 980px){.nb-hr-sub{padding: 5px 11px;font-size: 13px}}html.nb-push-sub .nb-bell-wrap{display: none !important}.nb-allow-guide{position: fixed;inset: 0;z-index: 2147483000;background: rgba(15,23,42,.45);animation: nb-allow-in .15s ease-out}.nb-allow-card{position: absolute;top: 10px;left: 10px;width: min(380px,calc(100vw - 20px));padding: 8px;box-sizing: border-box;border-radius: 14px;background: #fff;box-shadow: 0 16px 40px rgba(0,0,0,.3)}.nb-allow-card img{display: block;width: 100%;height: auto;border-radius: 8px}.nb-allow-close{position: absolute;z-index: 2;top: 4px;right: 4px;width: 32px;height: 32px;border: 0;border-radius: 50%;background: #f1f5f9;color: #334155;font-size: 20px;line-height: 1;cursor: pointer}@keyframes nb-allow-in{from{opacity: 0}to{opacity: 1}}@media (min-width: 768px){.nb-allow-card{left: 110px}}#site-navigation a:not([href]),.mnav a:not([href]),.nb-strip a:not([href]),.dark-mode-toggle{cursor: pointer}.mnav-overlay{position: fixed;inset: 0;z-index: 999;background: rgba(0,0,0,.45);opacity: 0;visibility: hidden;transition: opacity .24s,visibility .24s}.mnav-overlay.open{opacity: 1;visibility: visible}.mnav{--mnav-accent: var(--color-primary,var(--gc-accent,#0c6878));--mnav-text: #353543;--mnav-muted: #8b8ba3;--mnav-line: #eaeaf2;--mnav-soft: #f8f9fe;--mnav-bg: #fff;position: fixed;top: 0;bottom: 0;left: 0;z-index: 1001;width: min(340px,85vw);display: flex;flex-direction: column;background: var(--mnav-bg);color: var(--mnav-text);box-shadow: 6px 0 24px rgba(0,0,0,.18);transform: translateX(-105%);transition: transform .24s cubic-bezier(.23,1,.32,1);font-family: var(--font-ui,system-ui,sans-serif)}.mnav.open{transform: translateX(0)}body:has(#wpadminbar) .mnav{top: 0}.mnav-top{height: 56px;flex-shrink: 0;display: flex;align-items: center;gap: .5rem;padding: 0 .5rem 0 1rem;border-bottom: 1px solid var(--mnav-line)}.mnav-brand{flex: 1;min-width: 0;display: flex;align-items: center;text-decoration: none}.mnav-brand img{max-height: 36px;max-width: 180px;width: auto;object-fit: contain}.mnav-brand img.nb-logo-img{width: auto !important;height: auto !important}.mnav-brand span{font-size: 17px;font-weight: 700;color: var(--mnav-accent);overflow: hidden;text-overflow: ellipsis;white-space: nowrap}.mnav-close{width: 40px;height: 40px;border-radius: 50%;border: 0;background: none;color: #666;font-size: 20px;display: grid;place-items: center;cursor: pointer}.mnav-close:hover{background: var(--mnav-soft)}.mnav-hello{flex-shrink: 0;background: var(--mnav-soft);padding: 1rem}.mnav-hello-row{display: flex;align-items: center;gap: .75rem}.mnav-avatar{width: 44px;height: 44px;border-radius: 50%;flex-shrink: 0;display: grid;place-items: center;background: var(--color-primary-light,#e0f2f4);color: var(--mnav-accent);font-size: 18px}.mnav-hello-text{min-width: 0;flex: 1}.mnav-hello-title{margin: 0;font-size: 16px;font-weight: 600;color: var(--mnav-text)}.mnav-hello-sub{margin: 0;font-size: 12px;color: var(--mnav-muted);overflow: hidden;text-overflow: ellipsis;white-space: nowrap}.mnav-hello-btns{margin-top: .875rem;display: grid;grid-template-columns: 1fr 1fr;gap: .5rem}.mnav-btn{height: 40px;display: flex;align-items: center;justify-content: center;gap: .4rem;border-radius: 4px;font-size: 15px;font-weight: 500;text-decoration: none;border: 1px solid var(--mnav-accent)}.mnav-btn.outline{background: var(--mnav-bg);color: var(--mnav-accent)}.mnav-btn.solid{background: var(--mnav-accent);color: #fff}.mnav-gap{height: 8px;flex-shrink: 0;background: var(--mnav-line)}.mnav-list{flex: 1;min-height: 0;overflow-y: auto;overscroll-behavior: contain}.mnav-list ul{list-style: none;margin: 0;padding: 0}.mnav-list>ul>li{position: relative;border-bottom: 1px solid var(--mnav-line)}.mnav-list>ul>li.active::before{content: "";position: absolute;left: 0;top: 0;height: 50px;width: 4px;border-radius: 0 3px 3px 0;background: var(--mnav-accent)}.mnav-row{display: flex;align-items: center;min-height: 50px}.mnav-link{flex: 1;min-width: 0;display: flex;align-items: center;gap: .875rem;padding: 0 1rem;min-height: 50px;font-size: 15px;color: var(--mnav-text);text-decoration: none}.mnav-icon{width: 20px;text-align: center;font-size: 17px;color: #6b6b7b}li.active>.mnav-row .mnav-link{color: var(--mnav-accent);font-weight: 600}li.active>.mnav-row .mnav-icon{color: var(--mnav-accent)}.mnav-arrow{padding: 0 1rem;font-size: 12px;color: #9a9aa8}.mnav-chev{width: 50px;height: 50px;border: 0;background: none;color: #9a9aa8;font-size: 13px;cursor: pointer}.mnav-chev i{transition: transform .2s}.mnav-chev.open i{transform: rotate(180deg)}.mnav-sub{max-height: 0;overflow: hidden;transition: max-height .25s ease;background: var(--t-off-canvas-sub-bg,var(--mnav-soft))}.mnav-sub.open{max-height: 1200px}.mnav-sub li a{display: flex;align-items: center;justify-content: space-between;gap: .5rem;padding: .7rem 1rem .7rem 3.1rem;font-size: 14px;color: var(--mnav-text);text-decoration: none;border-top: 1px solid var(--mnav-line)}.mnav-sub li.active a{color: var(--mnav-accent);font-weight: 600}.mnav-count{font-size: 11.5px;color: var(--mnav-muted);background: var(--mnav-bg);border: 1px solid var(--mnav-line);border-radius: 999px;padding: 0 8px}.mnav-foot{flex-shrink: 0;border-top: 1px solid var(--mnav-line);background: var(--mnav-soft)}.mnav-dark{width: 100%;display: flex;align-items: center;gap: .875rem;padding: 0 1rem;min-height: 52px;border: 0;background: none;font-size: 15px;color: var(--mnav-text);cursor: pointer;font-family: inherit}.mnav-switch{margin-left: auto;width: 40px;height: 22px;border-radius: 22px;background: #d1d5db;position: relative;transition: background .2s}.mnav-switch::after{content: "";position: absolute;top: 3px;left: 3px;width: 16px;height: 16px;border-radius: 50%;background: #fff;transition: transform .2s}.mnav-switch.on{background: var(--mnav-accent)}.mnav-switch.on::after{transform: translateX(18px)}[data-theme="dark"] .mnav{--mnav-text: #e5e7eb;--mnav-muted: #9ca3af;--mnav-line: #2d2d3a;--mnav-soft: #1a1a24;--mnav-bg: #111118}[data-theme="dark"] .mnav-icon,[data-theme="dark"] .mnav-close{color: #a1a1aa}
.nb-h-racing{position:relative}
.nb-h-racing .search-overlay{z-index:1002}
.search-overlay .search-go{border:0;border-radius:10px;padding:0 18px;height:44px;background:var(--gc-accent,#0c6878);color:#fff;font-weight:700;cursor:pointer;flex-shrink:0}
.nb-strip-dark{display:inline-flex;align-items:center}
.inb-scroll-menu{overflow-x:auto;white-space:nowrap;scrollbar-width:none;-webkit-overflow-scrolling:touch}.inb-scroll-menu::-webkit-scrollbar{display:none}.inb-scroll-menu-inner{display:flex;margin:0 auto;width:max-content;min-width:100%;box-sizing:border-box}@media (max-width:980px){.inb-scroll-menu-inner{max-width:none}}.inb-scroll-menu a{display:inline-flex;align-items:center;flex:0 0 auto}
.mnav-icon{flex-shrink:0}.mnav-arrow{padding:0;margin-right:1rem;box-sizing:content-box}.mnav-list a,.mnav-dark{text-decoration:none}
html.mnav-lock{overflow:hidden}
.nb-hr-act{position:relative}.edu-hsearch{position:absolute;right:0;top:100%;margin-top:10px;z-index:600;width:340px;max-width:calc(100vw - 32px);padding:8px;border:1px solid #e5e7eb;border-radius:12px;background:var(--t-search-modal-overlay-bg,#fff);box-shadow:0 12px 32px rgba(15,23,42,.14)}.edu-hsearch-form{display:flex;align-items:center;height:42px;border:1px solid #dcdfe4;border-radius:999px;background:var(--t-search-modal-field-bg,#fff);overflow:hidden}.edu-hsearch-form:focus-within{border-color:var(--gc-accent,#0c6878)}.edu-hsearch-form input{flex:1;min-width:0;height:100%;padding:0 6px 0 16px;border:0;outline:0;background:transparent;font:inherit;font-size:15px;color:var(--t-search-modal-field-text,#111827);-webkit-appearance:none;appearance:none}.edu-hsearch-form input::-webkit-search-cancel-button{display:none}.edu-hsearch-form button{position:relative;flex:0 0 38px;width:38px;height:100%;padding:0;border:0;background:none;color:#4b5563;cursor:pointer;display:flex;align-items:center;justify-content:center}.edu-hsearch-form .edu-hsearch-go{color:var(--gc-accent,#0c6878)}.edu-hsearch-x{display:none!important;font-size:22px;line-height:1}.edu-hsearch .voice-icon::before{content:"";position:absolute;inset:6px;border-radius:50%;background:color-mix(in srgb,var(--gc-accent,#0c6878) 25%,transparent);opacity:0}.edu-hsearch .voice-icon.listening::before{animation:listen 1s ease-out infinite;opacity:1}.edu-hsearch .voice-icon.listening svg{animation:micPulse 1s ease-in-out infinite;color:var(--gc-accent,#0c6878)}@media (max-width:980px){.nb-hr-act{position:static}.nb-hr-top{position:relative}.edu-hsearch{left:0;right:0;top:100%;margin-top:0;width:auto;max-width:none;padding:10px 14px;border:0;border-top:1px solid #e6e8ec;border-bottom:1px solid #e6e8ec;border-radius:0;box-shadow:0 8px 16px rgba(15,23,42,.08)}.edu-hsearch-x{display:flex!important}}.nb-hr-login{display:inline-flex;align-items:center;gap:6px;color:var(--t-header-racing-text,#1d2327)!important;font-size:15px;font-weight:600;white-space:nowrap}.nb-hr-login svg{width:18px;height:18px}.nb-hr-sub{cursor:pointer}.mnav-avatar svg{width:20px;height:20px}.mnav-btn svg{flex-shrink:0}
.screen-reader-text{position:absolute!important;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
.gp-modal{position:fixed;inset:0;z-index:100010;visibility:hidden;opacity:0;transition:opacity .3s ease,visibility .3s}.gp-modal--open{visibility:visible;opacity:1}.gp-modal__overlay{display:flex;justify-content:center;align-items:flex-start;height:100%;padding-top:25vh;background:rgba(0,0,0,.2);backdrop-filter:blur(3px);-webkit-backdrop-filter:blur(3px)}.gp-modal__container{max-width:100%;padding:0 10px;transform:scale(.9);transition:transform .3s ease}.gp-modal--open .gp-modal__container{transform:scale(1)}.search-modal-form{width:500px;max-width:100%;background:#fff;color:#111827;box-shadow:0 10px 40px rgba(0,0,0,.18)}.search-modal-fields{display:flex}.search-modal-fields .search-field{flex:1;min-width:0;height:60px;padding:10px 15px;border:0;outline:0;background:transparent;color:currentColor;font:inherit;font-size:17px;-webkit-appearance:none;appearance:none}.search-modal-fields .search-field::-webkit-search-cancel-button{display:none}.search-modal-fields button{position:relative;width:60px;border:0;background:none;color:currentColor;cursor:pointer;display:flex;align-items:center;justify-content:center;padding:0}.search-modal-fields .voice-icon::before{content:"";position:absolute;inset:12px;border-radius:50%;background:color-mix(in srgb,var(--gc-accent,#0c6878) 25%,transparent);opacity:0}.search-modal-fields .voice-icon.listening::before{animation:listen 1s ease-out infinite;opacity:1}.search-modal-fields .voice-icon.listening svg{animation:micPulse 1s ease-in-out infinite;color:var(--gc-accent,#0c6878)}
.post-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:22px}.post-card{padding:14px;border:1px solid #e5e7eb;border-radius:8px;box-shadow:none;background:#fff;transition:box-shadow .2s,transform .2s}.post-card:hover{box-shadow:0 6px 18px rgba(15,23,42,.08);transform:none}.post-card .post-banner{aspect-ratio:16/9;border-radius:5px;overflow:hidden;background:#eef0f2}.post-card .post-banner img{width:100%;height:100%;object-fit:cover}.post-card-content{padding:14px 2px 2px}.post-card-title{font-size:18px;line-height:1.35;font-weight:700;color:#111827;margin:0 0 8px}.post-card:hover .post-card-title{color:var(--gc-accent,#0c6878)}.post-card-author{font-size:13px;color:#6b7280;font-weight:500}.post-banner-placeholder{background:linear-gradient(135deg,var(--gc-accent,#0c6878),color-mix(in srgb,var(--gc-accent,#0c6878) 70%,#3bd9f0));color:#fff}@media (max-width:900px){.post-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}}@media (max-width:600px){.post-grid{grid-template-columns:1fr;gap:18px}.post-card-title{font-size:20px}}main>.breadcrumbs{max-width:var(--container-lg,1200px);margin-left:auto;margin-right:auto;padding-left:16px;padding-right:16px;box-sizing:border-box}
.nb-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:24px}.nb-card{display:flex;flex-direction:column;overflow:hidden;border-radius:4px;background:var(--t-content-background,#fff);box-shadow:0 1px 6px rgba(0,0,0,.1);transition:box-shadow .2s ease,transform .2s ease}.nb-card:hover{box-shadow:0 6px 18px rgba(0,0,0,.14)}.nb-card-img{display:block;aspect-ratio:16 / 9;overflow:hidden;background:#eef0f2}.nb-card-img img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .35s ease}.nb-card:hover .nb-card-img img{transform:scale(1.03)}.nb-card-body{padding:12px 12px 16px;display:flex;flex-direction:column;gap:6px}.nb-card-meta{display:flex;align-items:center;flex-wrap:wrap;gap:6px;font-size:13px;line-height:1.4}.nb-card-meta .sep{color:#c0c4c9}.nb-card-meta svg:not(.nb-verified){width:12px;height:12px;fill:currentColor}.nb-card-date{display:inline-flex;align-items:center;gap:5px}.nb-card-title{margin:0;font-size:21px;line-height:1.3;font-weight:500;color:var(--t-content-archive-title,#111827)}.nb-card-title a{color:inherit}.nb-card-title a:hover{color:var(--t-content-archive-title-hover,var(--gc-accent,#0c6878))}.nb-card-excerpt{margin:0;font-size:15px;line-height:1.55;color:#4b5563}@media (max-width:640px){.nb-grid{grid-template-columns:1fr;gap:18px}.nb-card-title{font-size:19px}}.nb-grid--feat{grid-template-columns:repeat(3,minmax(0,1fr));gap:22px}.nb-grid--feat>.nb-grid-ad{grid-column:1 / -1}.nb-card--feat{padding:14px;border:1px solid #e5e7eb;border-radius:8px;box-shadow:none}.nb-card--feat:hover{box-shadow:0 6px 18px rgba(15,23,42,.08)}.nb-card--feat .nb-card-img{border-radius:5px}.nb-card--feat .nb-card-body{flex:1;padding:14px 2px 2px;gap:8px}.nb-card-cat{display:inline-flex;align-items:center;gap:5px;align-self:flex-start;font-size:13px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--gc-accent,#0c6878)}.nb-card-cat:hover{opacity:.85}.nb-card-bolt{width:13px;height:13px;fill:currentColor;flex-shrink:0}.nb-card--feat .nb-card-title{font-size:18px;line-height:1.35;font-weight:700}.nb-card--feat .nb-card-meta{font-size:13px;color:#6b7280}.nb-card--feat .nb-card-excerpt{font-size:15px;line-height:1.6;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}.nb-card-more{align-self:flex-start;margin-top:auto;display:inline-flex;align-items:center;padding:9px 18px;border-radius:4px;font-size:14px;font-weight:600;line-height:1.2;color:var(--t-buttons-text,#fff);background:var(--t-buttons-background,var(--gc-accent,#0c6878));transition:background .15s ease}.nb-card-more:hover{color:var(--t-buttons-text-hover,#fff);background:var(--t-buttons-background-hover,var(--color-primary-hover,#095666))}.nb-card--lead{grid-column:1 / -1;flex-direction:row;align-items:center;gap:26px;padding:18px}.nb-card--lead .nb-card-img{flex:0 0 52%}.nb-card--lead .nb-card-body{padding:0;gap:10px}.nb-card--lead .nb-card-title{font-size:26px;line-height:1.3}.nb-card--lead .nb-card-excerpt{font-size:16px;-webkit-line-clamp:4}.nb-card--lead .nb-card-more{margin-top:6px}.nb-card-ph{display:grid;place-items:center;width:100%;height:100%;font-size:48px;font-weight:800;color:#fff;background:linear-gradient(135deg,var(--gc-accent,#0c6878),color-mix(in srgb,var(--gc-accent,#0c6878) 70%,#3bd9f0))}.nb-card-title{font-family:inherit}.home-posts{margin:28px 0 36px;container-type:inline-size}@container (max-width:920px){.nb-grid--feat{gap:16px}.nb-card--feat{padding:12px}.nb-card--feat .nb-card-body{padding-top:12px;gap:7px}.nb-card--feat .nb-card-title{font-size:16.5px}.nb-card--feat .nb-card-excerpt{font-size:14px}.nb-card--feat .nb-card-cat{font-size:12px}.nb-card--feat .nb-card-more{padding:8px 14px;font-size:13.5px}.nb-card--lead{padding:16px;gap:22px}.nb-card--lead .nb-card-title{font-size:22px}.nb-card--lead .nb-card-excerpt{font-size:15px}}@container (max-width:700px){.nb-grid--feat{grid-template-columns:repeat(2,minmax(0,1fr))}}@container (max-width:640px){.nb-card--lead{flex-direction:column;align-items:stretch;gap:0;padding:14px}.nb-card--lead .nb-card-img{flex:none}.nb-card--lead .nb-card-body{padding:14px 2px 2px}}@container (max-width:540px){.nb-grid--feat{grid-template-columns:minmax(0,1fr);gap:18px}.nb-card--feat .nb-card-title,.nb-card--lead .nb-card-title{font-size:20px}}.home-sec-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:0 0 16px;padding-bottom:10px;border-bottom:2px solid #e5e7eb}.home-sec-title{margin:0;font-size:22px;font-weight:800;color:#111827;position:relative}.home-sec-title::after{content:"";position:absolute;left:0;bottom:-12px;width:60px;height:2px;background:var(--gc-accent,#0c6878)}.home-sec-more{font-size:14px;font-weight:700;color:var(--gc-accent,#0c6878)}
.nb-burger svg{width:26px;height:26px}.nb-hr-act .nb-search-icon svg{width:20px;height:20px;fill:none}.nb-hr-act .nb-search-icon{padding:0 6px}.nb-hr-soc svg{width:19px;height:19px}.nb-drawer-follow svg{width:18px;height:18px}.search-overlay .input-wrapper input{padding-left:44px}
@media (max-width:400px){.nb-hr-act .nb-search-icon{display:inline-flex}}
.nb-hr-brand svg{display:block;height:auto;width:150px}@media (max-width:980px){.nb-hr-brand svg{width:120px}}
.mnav-brand svg{height:36px;width:auto}
/* post header: "CATEGORY | · 1 min read" */
.nb-head-kicker{display:flex;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:12px}.nb-head-kicker .nb-head-cat{margin-bottom:0}.nb-head-divider{color:#9ca3af;font-size:15px;line-height:1}.nb-head-kicker .nb-head-read{font-size:14px;font-weight:600;color:#6b7280}
/* archive header (category / tag) */
.nb-arch-head{margin:6px 0 22px;padding:0 0 16px;border-bottom:1px solid #e5e7eb}.nb-arch-kicker{display:inline-block;font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--gc-accent,#0c6878);margin:0 0 6px}.nb-arch-title{margin:0;font-size:30px;line-height:1.25;font-weight:700;color:var(--t-content-title,#111827)}.nb-arch-desc{margin:8px 0 0;color:#4b5563;font-size:15.5px;line-height:1.6;max-width:820px}.nb-arch-count{display:inline-block;margin-top:10px;font-size:13px;font-weight:600;color:#6b7280;background:#f3f4f6;border-radius:99px;padding:3px 10px}.nb-arch-grid{margin:0 0 26px;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:22px}@media (max-width:980px){.nb-arch-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}}@media (max-width:600px){.nb-arch-grid{grid-template-columns:minmax(0,1fr);gap:16px}}.nb-arch-empty{padding:30px 0;color:#6b7280}.nb-arch-pages{margin:6px 0 34px}@media (max-width:767px){.nb-arch-title{font-size:24px}.nb-arch-head{margin-bottom:16px}}
/* author box (post) — like naukridrishti */
.nb-ab{width:815px;max-width:100%;margin:0 0 28px}.nb-ab-h{font-size:24px;line-height:1.25;font-weight:700;color:#1f2937;margin:0 0 14px}.nb-ab-card{border:1px solid #dcdcdc;border-radius:5px;padding:20px;background:var(--t-content-background,#fff)}.nb-ab-top{display:flex;gap:18px;align-items:center}.nb-ab-img{flex-shrink:0}.nb-ab-img img{width:110px;height:110px;object-fit:cover;border-radius:5px;display:block;background:#f3f4f6}.nb-ab-who{flex:1;min-width:0}.nb-ab-name{display:flex;align-items:center;gap:8px;margin:0;font-size:21px;font-weight:700;text-transform:uppercase;color:#374151;line-height:1.2}.nb-ab-name a{color:inherit;text-decoration:none}.nb-ab-role{margin:4px 0 0;font-size:16px;color:#374151}.nb-ab-row{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:10px;flex-wrap:wrap}.nb-ab-btn{display:inline-flex;align-items:center;justify-content:center;padding:8px 20px;border-radius:99px;background:#2563eb;color:#fff !important;font-size:15px;font-weight:500;text-decoration:none;white-space:nowrap}.nb-ab-btn:hover{background:#1d4ed8}.nb-ab-bio{margin:16px 0 0;color:#374151;font-size:16px;line-height:1.6;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
.single-post .nb-ab .nb-ab-name{margin:0;font-size:21px;line-height:1.2}.single-post .nb-ab .nb-ab-h{margin:0 0 14px;font-size:24px}.single-post .nb-ab p{margin-bottom:0}
.nb-ap-tick{display:inline-flex;flex-shrink:0;line-height:0}.nb-ap-tick svg{display:block}@media (max-width:767px){.nb-ap-name .nb-ap-tick svg{width:24px;height:24px}}@media (max-width:600px){.nb-ab-name .nb-ap-tick svg{width:18px;height:18px}}
.nb-ap-badge{display:inline-grid;place-items:center;width:24px;height:24px;border-radius:50%;background:#334155;color:#fbbf24;flex-shrink:0}.nb-ab-name .nb-ap-badge{width:22px;height:22px}
.nb-ap-soc{display:flex;gap:8px;flex-wrap:wrap}.nb-ap-soc a{display:inline-grid;place-items:center;width:30px;height:30px;background:#eef0f3;color:#111827;border-radius:3px;text-decoration:none}.nb-ap-soc a:hover{background:#e2e8f0}
@media (max-width:600px){.single-post .nb-ab .nb-ab-name{font-size:18px}.single-post .nb-ab .nb-ab-h{font-size:21px}.nb-ab-card{padding:16px}.nb-ab-top{align-items:flex-start;gap:14px}.nb-ab-img img{width:84px;height:84px}.nb-ab-row{flex-wrap:nowrap;gap:8px;margin-top:8px}.nb-ab-row .nb-ap-soc{gap:5px;flex-wrap:nowrap;min-width:0}.nb-ab-row .nb-ap-soc a{width:26px;height:26px}.nb-ab-row .nb-ap-soc svg{width:14px;height:14px}.nb-ab-btn{padding:5px 12px;font-size:12.5px;flex-shrink:0}.nb-ab-name{font-size:18px}.nb-ab-role{font-size:14.5px}.nb-ab-h{font-size:21px}.nb-ab-bio{font-size:15px}}
/* author profile page — like naukridrishti */
.nb-ap{margin:8px 0 28px}.nb-ap-top{display:flex;align-items:center;gap:14px;padding:4px 0 10px}.nb-ap-back{display:inline-grid;place-items:center;width:38px;height:38px;border-radius:50%;color:#111827}.nb-ap-back:hover{background:#f3f4f6}.nb-ap-top b{display:block;font-size:17px;text-transform:uppercase;color:#111827;line-height:1.2}.nb-ap-top small{font-size:14px;color:#4b5563}.nb-ap-cover{height:220px;border-radius:6px;background:linear-gradient(135deg,var(--gc-accent,#0c6878),color-mix(in srgb,var(--gc-accent,#0c6878) 70%,#3bd9f0) 55%,color-mix(in srgb,var(--gc-accent,#0c6878) 10%,#fff))}.nb-ap-main{padding:0 6px}.nb-ap-avatar{width:150px;height:150px;border-radius:50%;object-fit:cover;border:4px solid #fff;margin:-80px 0 0 10px;display:block;background:#f3f4f6;box-shadow:0 2px 10px rgba(0,0,0,.12)}.nb-ap-name{display:flex;align-items:center;gap:12px;margin:16px 0 4px;font-size:34px;line-height:1.15;font-weight:800;text-transform:uppercase;color:#111827}.nb-ap-name .nb-ap-badge{width:32px;height:32px}.nb-ap-role{margin:0 0 10px;font-size:20px;color:#374151}.nb-ap-bio{margin:14px 0 0;font-size:16.5px;line-height:1.65;color:#374151}.nb-ap-facts{list-style:none;margin:16px 0 0;padding:0;display:flex;flex-wrap:wrap;gap:8px 22px}.nb-ap-facts li{display:flex;align-items:center;gap:7px;font-size:15.5px;color:#374151}.nb-ap-facts svg{color:#4b5563;flex-shrink:0}.nb-ap-facts a{color:#111827}.nb-ap-cards{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin-top:18px}.nb-ap-cards--one{grid-template-columns:1fr}.nb-ap-card{border:1px solid #dcdcdc;border-radius:10px;padding:12px 16px}.nb-ap-card h2{margin:0 0 4px;font-size:17px;font-weight:700;color:#1f2937}.nb-ap-card p{margin:0;font-size:15.5px;line-height:1.55;color:#374151}.nb-ap-h{font-size:22px;font-weight:700;margin:6px 0 16px;padding-top:18px;border-top:1px solid #e5e7eb;color:#111827}
@media (max-width:767px){.nb-ap-cover{height:130px}.nb-ap-avatar{width:110px;height:110px;margin-top:-56px}.nb-ap-name{font-size:25px;gap:10px}.nb-ap-name .nb-ap-badge{width:26px;height:26px}.nb-ap-role{font-size:17px}.nb-ap-cards{grid-template-columns:1fr}.nb-ap-facts{flex-direction:column;gap:8px}}
/* front-end admin bar = WordPress-style toolbar (signed-in staff only, filled by the browser — pages are cached; see includes/wpbar.php).
   Until it is drawn the space is kept by a dark strip, so the page does not jump. */
html.ab-on body{margin-top:32px}html.ab-on body:not(:has(#wpadminbar))::before{content:"";position:fixed;top:0;left:0;right:0;height:32px;background:#4a3369;z-index:99999}
@media screen and (max-width:782px){html.ab-on body{margin-top:46px}html.ab-on body:not(:has(#wpadminbar))::before{height:46px}}
@media screen and (max-width:600px){html.ab-on body:not(:has(#wpadminbar))::before{position:absolute}}
body:has(#wpadminbar) .nb-hr-login,body:has(#wpadminbar) .nb-hr-div,body:has(#wpadminbar) .nb-hc-login{display:none !important}html.nb-in-customizer body:has(#wpadminbar){margin-top:0 !important}html.nb-in-customizer body::before{display:none !important}
</style>
<script id="ab-boot">(function(){try{if(document.cookie.indexOf("edu_ab=1")<0||window.self!==window.top)return}catch(e){return}
var C=<?= json_encode(array_filter(['kind' => (string)($seo_ctx['kind'] ?? ''), 'id' => (($seo_ctx['kind'] ?? '') === 'post' && isset($id)) ? (int)$id : null, 'slug' => (($seo_ctx['kind'] ?? '') === 'page' && isset($page['slug'])) ? (string)$page['slug'] : null], fn($v) => $v !== null && $v !== '')) ?>;
var R=document.documentElement,K="edu_ab_w",key=JSON.stringify(C),cached=null,fresh=false;R.classList.add("ab-on");
try{cached=JSON.parse(sessionStorage.getItem(K)||"null")}catch(x){}
function body(f){if(document.body)f();else{var t=setInterval(function(){if(document.body){clearInterval(t);f()}},15)}}
// WordPress' admin-bar.css + dashicons + the toolbar script, loaded once; the bar is drawn when its CSS is ready
function assets(a){return Promise.all((a.css||[]).map(function(h){var l=document.querySelector('link[data-wpbar="'+h+'"]');if(l)return l._p;
l=document.createElement("link");l.rel="stylesheet";l.href=h;l.setAttribute("data-wpbar",h);l._p=new Promise(function(ok){l.onload=l.onerror=ok});document.head.appendChild(l);return l._p})).then(function(){
if(a.js&&!document.querySelector('script[data-wpbar]')){var s=document.createElement("script");s.src=a.js;s.defer=true;s.setAttribute("data-wpbar","1");document.head.appendChild(s)}})}
function render(d,stale){assets(d.assets||{}).then(function(){body(function(){var w=document.createElement("div");w.innerHTML=d.html;var b=w.firstChild;
if(stale){var ed=b.querySelector("#wp-admin-bar-edit");ed&&ed.remove()}
var old=document.getElementById("wpadminbar");old?old.parentNode.replaceChild(b,old):document.body.insertBefore(b,document.body.firstChild)})})}
// shown at once from the previous page (without its Edit link); fresh data replaces it a moment later
if(cached&&cached.d&&cached.d.in)render(cached.d,cached.k!==key);
fetch("/admin/api/adminbar.php?"+new URLSearchParams(C),{credentials:"same-origin",cache:"no-store"}).then(function(r){return r.json()}).then(function(d){
fresh=true;
if(!d||!d.in){document.cookie="edu_ab=;path=/;max-age=0";try{sessionStorage.removeItem(K)}catch(x){}R.classList.remove("ab-on");body(function(){var o=document.getElementById("wpadminbar");o&&o.remove()});return}
var same=cached&&cached.k===key&&JSON.stringify(cached.d)===JSON.stringify(d);
try{sessionStorage.setItem(K,JSON.stringify({k:key,d:d}))}catch(x){}
// nothing changed: keep the bar already drawn (redrawing it would close a menu under the mouse)
if(!same||!document.getElementById("wpadminbar"))render(d,false)}).catch(function(){})})();</script>
<?= edu_theme_head() ?>
<?php require_once INCLUDES_PATH . '/perf.php'; echo edu_snippet('head'); require_once INCLUDES_PATH . '/ads.php'; echo edu_ads_head(); ?>
<?php require INCLUDES_PATH . '/consent-head.php'; ?>


<link rel="stylesheet" href="/assets/css/app.min.css?v=22052026" media="print" onload="this.media='all'">

<noscript><link rel="stylesheet" href="/assets/css/app.min.css?v=22052026"></noscript>
<link rel="stylesheet" href="/assets/css/footer.css?v=<?= @filemtime(ROOT_PATH . '/assets/css/footer.css') ?>" media="print" onload="this.media='all'">

<script id="app" src="/assets/js/app.bundle.min.js?v=1210202602" defer></script>
<?php // Analytics (Admin → Analytics): page views and time on page, no cookies
$__eaPid = (int)($__eaPostId ?? 0); ?>
<script src="/assets/js/ea.js?v=<?= @filemtime(ROOT_PATH . '/assets/js/ea.js') ?>" defer<?= $__eaPid ? ' data-p="' . $__eaPid . '"' : '' ?>></script>
<?php
// Push: the browser's own "Allow notifications" prompt (Push Notifications → Settings → Collect subscribers)
require_once INCLUDES_PATH . '/push.php';
$__pk = null;
try { if (edu_push_collecting()) $__pk = edu_push_active_key(); } catch (Throwable $e) { $__pk = null; }
if ($__pk): ?>
<script>window.EDU_PUSH=<?= json_encode(['on' => 1, 'key' => $__pk['public_key']], JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="/assets/js/push-client.js?v=<?= @filemtime(ROOT_PATH . '/assets/js/push-client.js') ?>" defer></script>
<?php endif; ?>


<?php if (!edu_seo_advanced()): // Advanced SEO prints one combined @graph instead ?>
<!-- Organization Schema -->
<?php
$companySameAs = array_values(array_filter([
    SOCIAL_INSTAGRAM,
    SOCIAL_THREADS,
    SOCIAL_LINKEDIN,
    SOCIAL_FACEBOOK,
    SOCIAL_TWITTER,
    SOCIAL_WHATSAPP,
    SOCIAL_TELEGRAM,
    SOCIAL_ARATT,
]));

$org_schema = [
    '@context'      => 'https://schema.org',
    '@type'         => edu_seo_advanced() && in_array(seo_opt('org_type'), ['Organization', 'EducationalOrganization', 'NewsMediaOrganization'], true) ? seo_opt('org_type') : 'Organization',
    '@id'      => SITE_URL . '#organization',
    'name'          => edu_site_name(),
    'url'           => SITE_URL,
    'logo'          => SITE_LOGO,
    'foundingDate'  => FOUNDING_YEAR,
    'description'   => SEO_DEFAULT_DESCRIPTION,
    'address' => [
        '@type'           => 'PostalAddress',
        'addressLocality' => 'Begun, Chittorgarh',
        'addressRegion'   => 'Rajasthan',
        'postalCode'      => '312023',
        'addressCountry'  => 'IN',
    ],
    'contactPoint' => [
        '@type'             => 'ContactPoint',
        'contactType'       => 'Customer Support',
        'availableLanguage' => ['Hindi', 'English'],
        'email'             => CONTACT_EMAIL,
        'telephone'         => PHONE_NUMBER,
        'url'               => SITE_URL . '/contact-us',
    ],
    'sameAs' => $companySameAs,
];
?>
<script type="application/ld+json">
<?= json_encode($org_schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?>
</script>



<!-- Website Schema -->
<?php
$website_schema = [
    '@context'   => 'https://schema.org',
    '@type'      => 'WebSite',
    '@id'        => SITE_URL . '#website',
    'url'        => SITE_URL,
    'name'       => edu_site_name(),
    'inLanguage' => 'en-IN',
    'publisher'  => [
        '@id' => SITE_URL . '#organization',
    ],
];
?>
<script type="application/ld+json">
<?= json_encode($website_schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?>
</script>
<?php endif; ?>