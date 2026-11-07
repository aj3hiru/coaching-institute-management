<?php
// One course or notes pack — landing page: hero, buy card, what's inside (chapters accordion, free previews), FAQ.
//   /store/item?s=slug   (&preview=1 = staff preview, also for drafts)
require_once dirname(__DIR__) . '/includes/config.php';
require_once INCLUDES_PATH . '/shop.php';
$pdo = sh_db();
$pv = sh_preview();
$p = sh_on() || $pv ? sh_product($pdo, (string)($_GET['s'] ?? ''), !$pv) : null;
if (!$p) { http_response_code(404); require ROOT_PATH . '/templates/page_not_found.php'; exit; }
$u = sh_user();
$own = $u && sh_has_access($pdo, (int)$u['id'], (int)$p['id']);
$isCourse = $p['type'] === 'course';
$cur = $isCourse ? sh_curriculum($pdo, (int)$p['id']) : [];
$files = sh_files($pdo, (int)$p['id']);
$all = []; foreach ($cur as $s) foreach ($s['lessons'] as $l) $all[] = $l;
$nLessons = count($all);
$nSecs = count(array_filter($cur, fn($s) => $s['title'] !== ''));
$total = array_sum(array_map(fn($l) => sh_dur_sec($l['duration']), $all));
$freeL = array_values(array_filter($all, fn($l) => $l['free']));
$freeF = array_values(array_filter($files, fn($f) => $f['free']));
$learn = '/store/learn?s=' . rawurlencode($p['slug']) . ($pv ? '&preview=1' : '');
$buyers = (int)$pdo->query("SELECT COUNT(*) FROM sh_access WHERE product_id = " . (int)$p['id'] . " AND revoked = 0")->fetchColumn();
$updated = $p['updated_at'] ?: $p['created_at'];
$priceTxt = (float)$p['price'] > 0 ? sh_money($p['price']) : 'Free';
$off = $p['mrp'] !== null && (float)$p['mrp'] > (float)$p['price'] ? (int)round(100 - (float)$p['price'] * 100 / (float)$p['mrp']) : 0;
$vd = (int)$p['validity_days'];
$validity = $vd ? ($vd % 365 === 0 ? ($vd / 365) . ' year' . ($vd / 365 > 1 ? 's' : '') : $vd . ' days') . ' access' : 'Lifetime access';
$desc = trim((string)$p['description']);
$preview = $freeL ? $learn . '&l=' . (int)$freeL[0]['id'] : ($freeF ? $learn . '&f=' . (int)$freeF[0]['id'] : '');
$stTitle = $p['title'];
$stDesc = $p['subtitle'] ?: mb_substr(trim(strip_tags($desc)), 0, 160);
$stIndex = $p['status'] === 'published';
require ROOT_PATH . '/components/store-open.php';
$ico = fn($n) => sh_icon($n);
?>
<?php if ($pv): ?><div class="sp-pv"><?= $ico('eye') ?> Staff preview — every video and PDF is open for you<?= $p['status'] !== 'published' ? ' · this item is <b>' . sh_e($p['status']) . '</b>, students cannot see it' : '' ?>. <a href="<?= sh_e(sh_url($p)) ?>">See it as a student</a></div><?php endif; ?>
<section class="sp-hero"><div class="sp-wrap"><div class="sp-hero-in">
  <nav class="sp-crumb"><a href="/store/">Store</a><span>›</span><a href="/store/?t=<?= sh_e($p['type']) ?>"><?= $isCourse ? 'Video Courses' : 'PDF Notes' ?></a></nav>
  <span class="sp-kind"><?= $ico($isCourse ? 'video' : 'file') ?> <?= $isCourse ? 'Video course' : 'PDF notes' ?></span>
  <h1><?= sh_e($p['title']) ?></h1>
  <?php if ($p['subtitle']): ?><p class="sp-sub"><?= sh_e($p['subtitle']) ?></p><?php endif; ?>
  <ul class="sp-facts">
    <?php if ($isCourse): ?><li><?= $ico('play') ?> <?= $nLessons ?> video<?= $nLessons === 1 ? '' : 's' ?></li><?php if ($total): ?><li><?= $ico('clock') ?> <?= sh_dur_text($total) ?></li><?php endif; ?><?php if ($nSecs): ?><li><?= $ico('layers') ?> <?= $nSecs ?> chapter<?= $nSecs === 1 ? '' : 's' ?></li><?php endif; ?><?php endif; ?>
    <?php if ($files): ?><li><?= $ico('file') ?> <?= count($files) ?> PDF<?= count($files) === 1 ? '' : 's' ?></li><?php endif; ?>
    <li><?= $ico($vd ? 'calendar' : 'infinity') ?> <?= $validity ?></li>
  </ul>
  <p class="sp-meta"><?= $buyers ? '<b>' . $buyers . '</b> student' . ($buyers === 1 ? '' : 's') . ' enrolled · ' : '' ?>Updated <?= date('M Y', strtotime($updated)) ?></p>
</div></div></section>

<div class="sp-wrap sp-body">
  <aside class="sp-card" id="buy">
    <<?= $preview ? 'a href="' . sh_e($preview) . '"' : 'div' ?> class="sp-media">
      <?php if ($p['thumb']): ?><img src="<?= sh_e($p['thumb']) ?>" alt=""><?php else: ?><span class="sp-ph"><?= sh_e($p['title']) ?></span><?php endif; ?>
      <?php if ($preview): ?><span class="sp-play"><span class="sp-pbtn"><?= $ico('playsolid') ?></span><b><?= $freeL ? 'Watch free preview' : 'Read free preview' ?></b></span><?php endif; ?>
    </<?= $preview ? 'a' : 'div' ?>>
    <div class="sp-cb">
      <?php if ($own): ?>
        <div class="sp-owned"><?= $ico('check') ?> You have this <?= $isCourse ? 'course' : 'notes pack' ?></div>
        <a class="sp-btn" href="<?= sh_e($learn) ?>"><?= $isCourse ? 'Continue learning' : 'Open notes' ?></a>
      <?php else: ?>
        <div class="sp-price"><b><?= $priceTxt ?></b><?php if ($off): ?><s><?= sh_money($p['mrp']) ?></s><span class="sp-off"><?= $off ?>% off</span><?php endif; ?></div>
        <div class="st-msg err" id="stBuyMsg" hidden></div>
        <?php if ($u): ?>
          <button type="button" class="sp-btn" data-buy="<?= (int)$p['id'] ?>" data-t="<?= sh_e(sh_csrf()) ?>"><?= (float)$p['price'] > 0 ? 'Buy now' : 'Enrol for free' ?></button>
        <?php else: ?>
          <a class="sp-btn" href="/account/login?next=<?= rawurlencode(sh_url($p)) ?>"><?= (float)$p['price'] > 0 ? 'Buy now' : 'Enrol for free' ?></a>
        <?php endif; ?>
        <?php if ($preview): ?><a class="sp-btn sp-ghost" href="<?= sh_e($preview) ?>">Try free preview</a><?php endif; ?>
        <?php if (!$u): ?><p class="sp-note">You will log in or create a free account first.</p><?php endif; ?>
      <?php endif; ?>
      <div class="sp-inc"><b>This <?= $isCourse ? 'course' : 'pack' ?> includes</b>
        <ul>
          <?php if ($isCourse): ?><li><?= $ico('video') ?> <?= $nLessons ?> recorded video<?= $nLessons === 1 ? '' : 's' ?><?= $total ? ' · ' . sh_dur_text($total) : '' ?></li><?php endif; ?>
          <?php if ($files): ?><li><?= $ico('file') ?> <?= count($files) ?> PDF note<?= count($files) === 1 ? '' : 's' ?></li><?php endif; ?>
          <li><?= $ico($vd ? 'calendar' : 'infinity') ?> <?= $validity ?></li>
          <li><?= $ico('device') ?> Phone, tablet and computer</li>
          <?php if ($isCourse): ?><li><?= $ico('gauge') ?> Speed up to 2x and quality choice</li><?php endif; ?>
        </ul>
      </div>
      <p class="sp-secure"><?= $ico('shield') ?> Secure payment by Razorpay — UPI, cards, net banking</p>
    </div>
  </aside>

  <div class="sp-main">
    <?php if ($desc !== ''): ?>
    <section class="sp-sec"><h2>About this <?= $isCourse ? 'course' : 'notes pack' ?></h2>
      <div class="sp-desc" id="spDesc"><?= nl2br(sh_e($desc)) ?></div>
      <button type="button" class="sp-link" data-more="spDesc" hidden>Show more</button>
    </section>
    <?php endif; ?>

    <?php if ($isCourse): ?>
    <section class="sp-sec" id="content"><div class="sp-h2"><h2>Course content</h2><?php if (count($cur) > 1): ?><button type="button" class="sp-link" data-expand>Expand all</button><?php endif; ?></div>
      <p class="sp-cmeta"><?= $nSecs ? $nSecs . ' chapter' . ($nSecs === 1 ? '' : 's') . ' · ' : '' ?><?= $nLessons ?> video<?= $nLessons === 1 ? '' : 's' ?><?= $total ? ' · ' . sh_dur_text($total) . ' total length' : '' ?></p>
      <?php if (!$cur): ?><div class="sp-empty">Videos are being added — they will appear here soon.</div><?php endif; ?>
      <div class="sp-acc">
      <?php foreach ($cur as $i => $s): $sd = array_sum(array_map(fn($l) => sh_dur_sec($l['duration']), $s['lessons'])); ?>
        <details class="sp-ch"<?= $i === 0 ? ' open' : '' ?>>
          <summary><span class="sp-chev" aria-hidden="true"></span><b><?= sh_e($s['title'] !== '' ? $s['title'] : 'Lessons') ?></b><small><?= count($s['lessons']) ?> video<?= count($s['lessons']) === 1 ? '' : 's' ?><?= $sd ? ' · ' . sh_dur_text($sd) : '' ?></small></summary>
          <ul>
          <?php foreach ($s['lessons'] as $l): $open = $own || $l['free'] || $pv; ?>
            <li class="<?= $open ? 'is-open' : 'is-locked' ?>"><?= $ico($open ? 'play' : 'lock') ?>
              <?php if ($open): ?><a href="<?= sh_e($learn . '&l=' . (int)$l['id']) ?>"><?= sh_e($l['title']) ?></a><?php else: ?><span><?= sh_e($l['title']) ?></span><?php endif; ?>
              <?php if ($l['free'] && !$own): ?><a class="sp-prev" href="<?= sh_e($learn . '&l=' . (int)$l['id']) ?>">Preview</a><?php endif; ?>
              <em><?= sh_e($l['duration'] ?: '') ?></em></li>
          <?php endforeach; ?>
          </ul>
        </details>
      <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($files): ?>
    <section class="sp-sec"><h2><?= $isCourse ? 'Notes included' : 'PDFs in this pack' ?></h2>
      <div class="sp-acc"><div class="sp-ch"><ul>
      <?php foreach ($files as $f): $open = $own || $f['free'] || $pv; ?>
        <li class="<?= $open ? 'is-open' : 'is-locked' ?>"><?= $ico($open ? 'file' : 'lock') ?>
          <?php if ($open): ?><a href="<?= sh_e($learn . '&f=' . (int)$f['id']) ?>"><?= sh_e($f['title']) ?></a><?php else: ?><span><?= sh_e($f['title']) ?></span><?php endif; ?>
          <?php if ($f['free'] && !$own): ?><a class="sp-prev" href="<?= sh_e($learn . '&f=' . (int)$f['id']) ?>">Preview</a><?php endif; ?>
          <em><?= $f['pages'] ? (int)$f['pages'] . ' pages' : '' ?></em></li>
      <?php endforeach; ?>
      </ul></div></div>
    </section>
    <?php endif; ?>

    <section class="sp-sec"><h2>Frequently asked questions</h2>
      <div class="sp-faq">
        <details><summary>How do I start after paying?</summary><p>Right after the payment the <?= $isCourse ? 'course' : 'notes pack' ?> opens in <b>My Library</b>. Log in any time with your mobile number or email to continue where you stopped.</p></details>
        <details><summary>Can I study on my phone?</summary><p>Yes — videos and PDFs open in the browser on any phone, tablet or computer. No app is needed.</p></details>
        <details><summary>How long can I use it?</summary><p><?= $vd ? 'You get ' . sh_e($validity) . ' from the day you buy.' : 'For as long as you like — the access never ends.' ?></p></details>
        <?php if ($isCourse): ?><details><summary>Can I change the video speed and quality?</summary><p>Yes. Use the ⚙ button on the video: speed from 0.5x to 2x, and a lower quality for slow internet.</p></details><?php endif; ?>
        <details><summary>I'm a student of the institute — do I pay again?</summary><p>Ask the office — our staff can add courses to your account. Sign up with the same mobile number you gave the institute.</p></details>
      </div>
    </section>
  </div>
</div>

<div class="st-mbar"><div class="sp-price"><?= $own ? '<span class="sp-owned">' . $ico('check') . ' In your library</span>' : '<b>' . $priceTxt . '</b>' . ($off ? '<s>' . sh_money($p['mrp']) . '</s>' : '') ?></div>
  <?php if ($own): ?><a class="sp-btn" href="<?= sh_e($learn) ?>"><?= $isCourse ? 'Continue' : 'Open notes' ?></a><?php else: ?><button type="button" class="sp-btn" onclick="var b=document.querySelector('.sp-card [data-buy], .sp-card a.sp-btn');b.scrollIntoView({block:'center'});b.click();"><?= (float)$p['price'] > 0 ? 'Buy now' : 'Enrol free' ?></button><?php endif; ?></div>
<?php if ($u && !$own): ?><script>window.ST_BUYER = <?= json_encode(['name' => $u['name'], 'email' => $u['email'], 'contact' => $u['mobile']], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>; window.ST_SITE = <?= json_encode(edu_site_name(), JSON_HEX_TAG) ?>;</script><?php endif; ?>
<?php require ROOT_PATH . '/components/store-close.php'; ?>
