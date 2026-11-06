<?php
// One course or notes pack: details, what is inside (free previews open), and Buy / Start learning.  /store/item?s=slug
require_once dirname(__DIR__) . '/includes/config.php';
require_once INCLUDES_PATH . '/shop.php';
$pdo = sh_db();
$p = sh_on() || sh_staff() ? sh_product($pdo, (string)($_GET['s'] ?? ''), !sh_staff()) : null; // staff can preview drafts
if (!$p) { http_response_code(404); require ROOT_PATH . '/templates/page_not_found.php'; exit; }
$u = sh_user();
$own = $u && sh_has_access($pdo, (int)$u['id'], (int)$p['id']);
$isCourse = $p['type'] === 'course';
$cur = $isCourse ? sh_curriculum($pdo, (int)$p['id']) : [];
$files = sh_files($pdo, (int)$p['id']);
$nLessons = array_sum(array_map(fn($s) => count($s['lessons']), $cur));
$nSecs = count(array_filter($cur, fn($s) => $s['title'] !== ''));
$learn = '/store/learn?s=' . rawurlencode($p['slug']);
$stTitle = $p['title'];
$stDesc = $p['subtitle'] ?: mb_substr(trim(strip_tags((string)$p['description'])), 0, 160);
$stIndex = true;
require ROOT_PATH . '/components/store-open.php';
?>
<?php if ($p['status'] !== 'published'): ?><div class="st-msg info">Preview for staff — this item is <b><?= sh_e($p['status']) ?></b> and buyers cannot see it.</div><?php endif; ?>
<p class="st-crumb"><a href="/">Home</a> › <a href="/store">Store</a> › <a href="/store?t=<?= sh_e($p['type']) ?>"><?= sh_e(sh_type_label($p['type'])) ?></a></p>
<div class="st-pg">
  <div>
    <h1><?= sh_e($p['title']) ?></h1>
    <?php if ($p['subtitle']): ?><p class="st-lead"><?= sh_e($p['subtitle']) ?></p><?php endif; ?>
    <?php if (trim((string)$p['description']) !== ''): ?>
    <div class="st-box"><div class="st-box-h">About this <?= $isCourse ? 'course' : 'notes pack' ?></div><div class="st-box-b st-desc"><?= nl2br(sh_e($p['description'])) ?></div></div>
    <?php endif; ?>

    <?php if ($isCourse): ?>
    <div class="st-box"><div class="st-box-h">Course content <small><?= $nSecs ? $nSecs . ' chapter' . ($nSecs === 1 ? '' : 's') . ' · ' : '' ?><?= $nLessons ?> video<?= $nLessons === 1 ? '' : 's' ?></small></div>
      <?php if (!$cur): ?><div class="st-box-b st-empty">Videos are being added — they will appear here.</div><?php endif; ?>
      <?php foreach ($cur as $s): ?><div class="st-sec"><?php if ($s['title'] !== ''): ?><div class="st-sec-h"><span><?= sh_e($s['title']) ?></span><small><?= count($s['lessons']) ?> videos</small></div><?php endif; ?>
        <?php foreach ($s['lessons'] as $l): $open = $own || $l['free']; ?>
        <?= $open ? '<a class="st-li" href="' . sh_e($learn . '&l=' . (int)$l['id']) . '">' : '<div class="st-li">' ?><?= sh_icon($open ? 'play' : 'lock') ?><span class="t"><?= sh_e($l['title']) ?></span><?= $l['free'] && !$own ? '<span class="fr">Free preview</span>' : '' ?><?= $l['duration'] ? '<span class="d">' . sh_e($l['duration']) . '</span>' : '' ?><?= $open ? '</a>' : '</div>' ?>
        <?php endforeach; ?></div><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($files): ?>
    <div class="st-box"><div class="st-box-h"><?= $isCourse ? 'PDF notes with this course' : 'PDFs in this pack' ?> <small><?= count($files) ?> file<?= count($files) === 1 ? '' : 's' ?></small></div>
      <?php foreach ($files as $f): $open = $own || $f['free']; ?>
      <?= $open ? '<a class="st-li" href="' . sh_e($learn . '&f=' . (int)$f['id']) . '">' : '<div class="st-li">' ?><?= sh_icon($open ? 'file' : 'lock') ?><span class="t"><?= sh_e($f['title']) ?></span><?= $f['free'] && !$own ? '<span class="fr">Free preview</span>' : '' ?><?= $f['pages'] ? '<span class="d">' . (int)$f['pages'] . ' pages</span>' : '' ?><?= $open ? '</a>' : '</div>' ?>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <aside class="st-buy"><?= sh_thumb_html($p) ?><div class="st-buy-b">
    <?php if ($own): ?>
      <p class="st-own" style="margin:0 0 12px">✓ This is in your library</p>
      <a class="st-btn block" href="<?= sh_e($learn) ?>"><?= sh_icon($isCourse ? 'play' : 'file') ?> <?= $isCourse ? 'Start learning' : 'Open notes' ?></a>
    <?php else: ?>
      <div class="st-price"><?= sh_price_html($p) ?></div>
      <div class="st-msg err" id="stBuyMsg" hidden></div>
      <?php if ($u): ?>
        <button type="button" class="st-btn block" style="margin-top:12px" data-buy="<?= (int)$p['id'] ?>" data-t="<?= sh_e(sh_csrf()) ?>"><?= sh_icon('cart') ?> <?= (float)$p['price'] > 0 ? 'Buy now' : 'Get it free' ?></button>
      <?php else: ?>
        <a class="st-btn block" style="margin-top:12px" href="/account/login?next=<?= rawurlencode(sh_url($p)) ?>"><?= sh_icon('cart') ?> <?= (float)$p['price'] > 0 ? 'Buy now' : 'Get it free' ?></a>
        <p class="st-meta" style="margin:8px 0 0;text-align:center">Log in or create a free account to buy.</p>
      <?php endif; ?>
    <?php endif; ?>
    <ul class="st-incl">
      <?php if ($isCourse): ?><li><?= sh_icon('video') ?> <?= $nLessons ?> recorded video<?= $nLessons === 1 ? '' : 's' ?></li><?php endif; ?>
      <?php if ($files): ?><li><?= sh_icon('file') ?> <?= count($files) ?> PDF file<?= count($files) === 1 ? '' : 's' ?></li><?php endif; ?>
      <li><?= sh_icon((int)$p['validity_days'] ? 'clock' : 'infinity') ?> <?= (int)$p['validity_days'] ? (int)$p['validity_days'] . ' days access' : 'Lifetime access' ?></li>
      <li><?= sh_icon('device') ?> Phone, tablet and computer</li>
      <li><?= sh_icon('shield') ?> Secure payment by Razorpay</li>
    </ul>
  </div></aside>
</div>
<div class="st-mbar"><div class="st-price"><?= $own ? '<span class="st-own">✓ In your library</span>' : sh_price_html($p) ?></div>
  <?php if ($own): ?><a class="st-btn" href="<?= sh_e($learn) ?>"><?= $isCourse ? 'Start learning' : 'Open notes' ?></a><?php else: ?><button type="button" class="st-btn" onclick="var b=document.querySelector('.st-buy [data-buy], .st-buy a.st-btn');b.scrollIntoView({block:'center'});b.click();"><?= (float)$p['price'] > 0 ? 'Buy now' : 'Get it free' ?></button><?php endif; ?></div>
<?php if ($u && !$own): ?><script>window.ST_BUYER = <?= json_encode(['name' => $u['name'], 'email' => $u['email'], 'contact' => $u['mobile']], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>; window.ST_SITE = <?= json_encode(edu_site_name(), JSON_HEX_TAG) ?>;</script><?php endif; ?>
<?php require ROOT_PATH . '/components/store-close.php'; ?>
