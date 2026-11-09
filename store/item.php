<?php
// One course or notes pack — landing page: hero (with rating), section strip (About · Content · Included · Reviews),
// buy card, chapter accordion, what's included, student reviews (only students who have it can write one), FAQ.
//   /courses/slug · /notes/slug   (?preview=1 = staff preview, also for drafts) — old /store/item?s=slug redirects
require_once dirname(__DIR__) . '/includes/config.php';
require_once INCLUDES_PATH . '/shop.php';
$pdo = sh_db();
$pv = sh_preview();
$p = sh_on() || $pv ? sh_product($pdo, (string)($_GET['s'] ?? ''), !$pv) : null;
if (!$p) { http_response_code(404); require ROOT_PATH . '/templates/page_not_found.php'; exit; }
$want = sh_url($p) . (isset($_GET['preview']) ? '?preview=1' : '');
sh_redirect_clean($want); // old /store/item?s=…
$path = strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?');
if (sh_rw_live() && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && $path !== sh_url($p) && preg_match('#^/(courses|notes)/#', (string)$path)) { header('Location: ' . $want, true, 301); exit; } // a notes pack opened under /courses/ (or the other way)
$seo_canonical = rtrim(SITE_URL, '/') . sh_url($p); // its own address (also in the /courses/?s=… form)
$pid = (int)$p['id'];
$u = sh_user();
$own = $u && sh_has_access($pdo, (int)$u['id'], $pid);
$isCourse = $p['type'] === 'course';
if ($isCourse) sh_yt_autosync($pdo, $pid); // chapters linked to a YouTube playlist pick up new videos
$cur = $isCourse ? sh_curriculum($pdo, $pid) : [];
$files = sh_files($pdo, $pid);
$all = []; foreach ($cur as $s) foreach ($s['lessons'] as $l) $all[] = $l;
$nLessons = count($all);
$nSecs = count(array_filter($cur, fn($s) => $s['title'] !== ''));
$total = array_sum(array_map(fn($l) => sh_dur_sec($l['duration']), $all));
$freeL = array_values(array_filter($all, fn($l) => $l['free']));
$freeF = array_values(array_filter($files, fn($f) => $f['free']));
$lu = fn(string $q = '') => sh_learn_url($p['slug'], $q, $pv); // study page links
$buyers = (int)$pdo->query("SELECT COUNT(*) FROM sh_access WHERE product_id = $pid AND revoked = 0")->fetchColumn();
$updated = $p['updated_at'] ?: $p['created_at'];
$isFree = (float)$p['price'] <= 0;
$priceTxt = $isFree ? '<span class="st-free100">100% FREE</span>' : sh_money($p['price']); // free: struck original price + 100% FREE
$off = $p['mrp'] !== null && (float)$p['mrp'] > (float)$p['price'] ? (int)round(100 - (float)$p['price'] * 100 / (float)$p['mrp']) : 0;
$joinTxt = $isFree ? ($p['type'] === 'notes' ? 'Get free notes' : 'Join batch') : 'Buy now';
$vd = (int)$p['validity_days'];
$validity = $vd ? ($vd % 365 === 0 ? ($vd / 365) . ' year' . ($vd / 365 > 1 ? 's' : '') : $vd . ' days') . ' access' : 'Lifetime access';
$desc = trim((string)$p['description']);
$preview = $freeL ? $lu('l=' . (int)$freeL[0]['id']) : ($freeF ? $lu('f=' . (int)$freeF[0]['id']) : '');
// reviews
$rt = sh_rating($pdo, $pid);
$rq = $pdo->prepare("SELECT r.*, u.name FROM sh_reviews r JOIN sh_users u ON u.id = r.user_id WHERE r.product_id = ? AND r.status = 'published' ORDER BY (r.body IS NOT NULL) DESC, r.created_at DESC LIMIT 200");
$rq->execute([$pid]);
$reviews = $rq->fetchAll(PDO::FETCH_ASSOC);
$mine = null;
if ($u) { $q = $pdo->prepare("SELECT * FROM sh_reviews WHERE product_id = ? AND user_id = ?"); $q->execute([$pid, $u['id']]); $mine = $q->fetch(PDO::FETCH_ASSOC) ?: null; }
$canReview = $u && (bool)$pdo->query("SELECT 1 FROM sh_access WHERE user_id = " . (int)$u['id'] . " AND product_id = $pid AND revoked = 0")->fetchColumn();
$flash = $_SESSION['sh_flash'] ?? null; unset($_SESSION['sh_flash']);
$teacher = sh_teacher_name($pdo, (int)($p['teacher_id'] ?? 0));
$className = '';
$className = sh_category_name($pdo, (int)($p['category_id'] ?? 0));
[$batchText, $batchUp] = sh_batch($p);
$myFaq = array_values(array_filter(sh_json_list($p['faq'] ?? ''), fn($f) => !empty($f['q']) && !empty($f['a'])));
$myInc = array_values(array_filter(array_map('strval', sh_json_list($p['includes'] ?? '')), fn($v) => trim($v) !== ''));
$short = function (string $n) { $p = preg_split('/\s+/', trim(preg_replace('/\(.*?\)/', '', $n))); return trim(($p[0] ?? '') . (isset($p[1]) ? ' ' . mb_substr($p[1], 0, 1) . '.' : '')); };
$stTitle = $p['title'];
$stDesc = $p['subtitle'] ?: mb_substr(trim(strip_tags($desc)), 0, 160);
// the picture shared on WhatsApp / Facebook / Google: the course's own thumbnail, else its intro video's picture
$__shareImg = (string)($p['thumb'] ?? '');
if ($__shareImg === '' && ($__iy = sh_yt_id((string)($p['intro_url'] ?? '')))) $__shareImg = 'https://i.ytimg.com/vi/' . $__iy . '/hqdefault.jpg';
if ($__shareImg !== '') $stImage = str_starts_with($__shareImg, '/') ? rtrim(SITE_URL, '/') . $__shareImg : $__shareImg;
$stIndex = $p['status'] === 'published';
require ROOT_PATH . '/components/store-open.php';
$ico = fn($n) => sh_icon($n);
$tabs = array_filter(['about' => $desc !== '' ? 'About' : '', 'content' => $isCourse || $files ? 'Content' : '', 'included' => 'Included', 'reviews' => 'Reviews' . ($rt['count'] ? ' (' . $rt['count'] . ')' : '')]);
?>
<?php if ($pv): ?><div class="sp-pv"><?= $ico('eye') ?> Staff preview — every video and PDF is open for you<?= $p['status'] !== 'published' ? ' · this item is <b>' . sh_e($p['status']) . '</b>, students cannot see it' : '' ?>. <a href="<?= sh_e(sh_url($p)) ?>">See it as a student</a></div><?php endif; ?>
<section class="sp-hero"><div class="sp-wrap"><div class="sp-hero-in">
  <nav class="sp-crumb"><a href="/">Home</a><span>›</span><a href="<?= $isCourse ? '/courses/' : '/notes/' ?>"><?= $isCourse ? 'Courses' : 'PDF Notes' ?></a><?php if (!empty($className)): ?><span>›</span><a href="<?= sh_e(sh_cat_url((string)$pdo->query("SELECT slug FROM sh_categories WHERE id = " . (int)$p['category_id'])->fetchColumn())) ?>"><?= sh_e($className) ?></a><?php endif; ?></nav>
  <span class="sp-kind"><?= $ico($isCourse ? 'video' : 'file') ?> <?= $isCourse ? 'Video course' : 'PDF notes' ?><?= $className !== '' ? ' · ' . sh_e($className) : '' ?></span>
  <h1><?= sh_e($p['title']) ?></h1>
  <?php if ($p['subtitle']): ?><p class="sp-sub"><?= sh_e($p['subtitle']) ?></p><?php endif; ?>
  <?php if ($rt['count']): ?><a class="sp-rate" href="#reviews"><b><?= number_format($rt['avg'], 1) ?></b><?= sh_stars($rt['avg']) ?><span><?= $rt['count'] ?> rating<?= $rt['count'] === 1 ? '' : 's' ?></span></a><?php endif; ?>
  <ul class="sp-facts">
    <?php if ($isCourse): ?><li><?= $ico('play') ?> <?= $nLessons ?> video<?= $nLessons === 1 ? '' : 's' ?></li><?php if ($total): ?><li><?= $ico('clock') ?> <?= sh_dur_text($total) ?></li><?php endif; ?><?php if ($nSecs): ?><li><?= $ico('layers') ?> <?= $nSecs ?> chapter<?= $nSecs === 1 ? '' : 's' ?></li><?php endif; ?><?php endif; ?>
    <?php if ($files): ?><li><?= $ico('file') ?> <?= count($files) ?> PDF<?= count($files) === 1 ? '' : 's' ?></li><?php endif; ?>
    <li><?= $ico($vd ? 'calendar' : 'infinity') ?> <?= $validity ?></li>
  </ul>
  <?php if ($batchText !== ''): ?><p class="sp-batch<?= $batchUp ? ' up' : '' ?>"><?= $ico('calendar') ?> <?= $batchUp ? '<b>Upcoming batch</b> · ' : '' ?><?= sh_e($batchText) ?></p><?php endif; ?>
  <?php if ($teacher !== ''): ?><p class="sp-teach"><?= $ico('user') ?> Taught by <b><?= sh_e($teacher) ?></b></p><?php endif; ?>
  <p class="sp-meta"><?= $buyers ? '<b>' . $buyers . '</b> student' . ($buyers === 1 ? '' : 's') . ' enrolled · ' : '' ?>Updated <?= date('M Y', strtotime($updated)) ?></p>
</div></div></section>

<nav class="sp-tabs" id="spTabs" aria-label="Sections"><div class="sp-wrap"><div class="sp-tabs-in">
  <?php $first = true; foreach ($tabs as $k => $l): ?><a href="#<?= $k ?>" data-tab="<?= $k ?>" class="<?= $first ? 'on' : '' ?>"><?= sh_e($l) ?></a><?php $first = false; endforeach; ?>
</div></div></nav>

<div class="sp-wrap sp-body">
  <aside class="sp-card" id="buy">
    <?php $introYt = $isCourse && !empty($p['intro_url']) ? sh_yt_id((string)$p['intro_url']) : null; $cardImg = $p['thumb'] ?: ($introYt ? 'https://i.ytimg.com/vi/' . $introYt . '/hqdefault.jpg' : ''); ?>
    <?php if ($introYt): // the intro video plays right in the card ?>
    <div class="sp-media sp-intro-box"><?= sh_yt_lock_html($introYt, 'Intro: ' . $p['title'], '', (string)($p['thumb'] ?? '')) ?></div>
    <?php else: ?>
    <<?= $preview ? 'a href="' . sh_e($preview) . '"' : 'div' ?> class="sp-media">
      <?php if ($cardImg): ?><img src="<?= sh_e($cardImg) ?>" alt=""><?php else: ?><span class="sp-ph"><?= sh_e($p['title']) ?></span><?php endif; ?>
      <?php if ($preview): ?><span class="sp-play"><span class="sp-pbtn"><?= $ico('playsolid') ?></span><b><?= $freeL ? 'Watch free preview' : 'Read free preview' ?></b></span><?php endif; ?>
    </<?= $preview ? 'a' : 'div' ?>>
    <?php endif; ?>
    <div class="sp-cb">
      <?php if ($own): ?>
        <div class="sp-owned"><?= $ico('check') ?> You have this <?= $isCourse ? 'course' : 'notes pack' ?></div>
        <a class="sp-btn" href="<?= sh_e($lu()) ?>"><?= $isCourse ? 'Continue learning' : 'Open notes' ?></a>
        <?php if (!$mine): ?><a class="sp-btn sp-ghost" href="#reviews" data-write>Rate this <?= $isCourse ? 'course' : 'pack' ?></a><?php endif; ?>
      <?php else: ?>
        <div class="sp-price"><?php if ($off): ?><s><?= sh_money($p['mrp']) ?></s><?php endif; ?><?= $isFree ? $priceTxt : '<b>' . $priceTxt . '</b>' ?><?php if ($off && !$isFree): ?><span class="sp-off"><?= $off ?>% off</span><?php endif; ?></div>
        <div class="st-msg err" id="stBuyMsg" hidden></div>
        <?php if ($u): ?>
          <button type="button" class="sp-btn" data-buy="<?= $pid ?>" data-t="<?= sh_e(sh_csrf()) ?>"><?= $joinTxt ?></button>
        <?php else: ?>
          <a class="sp-btn" href="/account/login?next=<?= rawurlencode(sh_url($p)) ?>"><?= $joinTxt ?></a>
        <?php endif; ?>
        <?php if ($preview): ?><a class="sp-btn sp-ghost" href="<?= sh_e($preview) ?>">Try free preview</a><?php endif; ?>
        <?php if (!$u): ?><p class="sp-note">You will log in or create a free account first.</p><?php endif; ?>
      <?php endif; ?>
      <?php if (!$isFree): ?><p class="sp-secure"><?= $ico('shield') ?> <?= sh_pay_demo() ? 'Demo payment — UPI, cards, net banking (no real money)' : 'Secure payment by Razorpay — UPI, cards, net banking' ?></p><?php endif; ?>
    </div>
  </aside>

  <div class="sp-main">
    <?php if ($flash): ?><div class="st-msg <?= $flash[0] === 'ok' ? 'ok' : 'err' ?>" style="margin-top:20px"><?= sh_e($flash[1]) ?></div><?php endif; ?>
    <?php if ($desc !== ''): ?>
    <section class="sp-sec" id="about"><h2>About this <?= $isCourse ? 'course' : 'notes pack' ?></h2>
      <div class="sp-desc"><?= nl2br(sh_e($desc)) ?></div>
    </section>
    <?php endif; ?>

    <?php if ($isCourse || $files): ?>
    <section class="sp-sec" id="content">
      <div class="sp-h2"><h2><?= $isCourse ? 'Course content' : 'What is inside' ?></h2></div>
      <?php if ($isCourse): ?><p class="sp-cmeta"><?= $nSecs ? $nSecs . ' chapter' . ($nSecs === 1 ? '' : 's') . ' · ' : '' ?><?= $nLessons ?> video<?= $nLessons === 1 ? '' : 's' ?><?= $total ? ' · ' . sh_dur_text($total) . ' total length' : '' ?><?= $files ? ' · ' . count($files) . ' PDF' . (count($files) === 1 ? '' : 's') : '' ?></p><?php endif; ?>
      <?php if ($isCourse && !$cur): ?><div class="sp-empty">Videos are being added — they will appear here soon.</div><?php endif; ?>
      <?php $cNum = 0; $multi = count($cur) > 1; ?>
      <div class="lp-list lp-fr sp-lessons">
        <div class="lp-list-h"><b><?= $isCourse ? 'Lessons' : 'PDF files' ?></b><span><?= $isCourse ? $nLessons . ' ' . ($nLessons === 1 ? 'lesson' : 'lessons') : count($files) . ' PDF' . (count($files) === 1 ? '' : 's') ?></span></div>
        <div class="lp-fr-l">
        <?php foreach ($cur as $s): ?>
          <?php if ($multi && $s['lessons']): ?><div class="lp-ch-h"><?= sh_e($s['title'] !== '' ? $s['title'] : 'Lessons') ?><small><?= count($s['lessons']) ?></small></div><?php endif; ?>
          <?php $lastUnit = null; foreach ($s['lessons'] as $l): $open = $own || $l['free'] || $pv; $cNum++; $isPdf = ($l['kind'] ?? 'video') === 'pdf'; $th = sh_lesson_thumb($l, $p); ?>
            <?php if (($l['unit_title'] ?? '') !== '' && $l['unit_title'] !== $lastUnit): $lastUnit = $l['unit_title']; ?><div class="lp-unit-h"><?= sh_e($l['unit_title']) ?></div><?php endif; ?>
            <a href="<?= $open ? sh_e($lu('l=' . (int)$l['id'])) : '#buy' ?>" class="lp-li<?= $open ? '' : ' locked' ?>"<?= $open ? '' : ' title="Buy the course to open this lesson"' ?>>
              <span class="lp-n"><?= $cNum ?></span>
              <span class="lp-th<?= $isPdf || $th === '' ? ' ph' : '' ?>"><?php if (!$isPdf && $th !== ''): ?><img src="<?= sh_e($th) ?>" alt="" loading="lazy"><?php else: ?><?= $ico($isPdf ? 'pdf' : 'play') ?><?php endif; ?><?php if (!$open): ?><i class="lp-tag lock"><?= $ico('lock') ?></i><?php endif; ?></span>
              <span class="lp-lt"><span class="lp-t2"><?= sh_e($l['title']) ?></span><small><?= $isPdf ? 'PDF' : ($l['duration'] ? sh_e($l['duration']) : 'Video') ?><?= $l['free'] && !$own ? sh_free_badge() : '' ?></small></span></a>
          <?php endforeach; ?>
        <?php endforeach; ?>
        <?php if ($files): ?>
          <?php if ($cur): ?><div class="lp-ch-h"><?= $isCourse ? 'PDF notes' : 'Files' ?><small><?= count($files) ?></small></div><?php endif; ?>
          <?php foreach ($files as $k => $f): $open = $own || $f['free'] || $pv; ?>
            <a href="<?= $open ? sh_e($lu('f=' . (int)$f['id'])) : '#buy' ?>" class="lp-li<?= $open ? '' : ' locked' ?>"<?= $open ? '' : ' title="Buy to open this PDF"' ?>>
              <span class="lp-n"><?= $k + 1 ?></span>
              <span class="lp-th ph"><?= $ico('pdf') ?><?php if (!$open): ?><i class="lp-tag lock"><?= $ico('lock') ?></i><?php endif; ?></span>
              <span class="lp-lt"><span class="lp-t2"><?= sh_e($f['title']) ?></span><small><?= $f['pages'] ? (int)$f['pages'] . ' pages' : 'PDF' ?><?= $f['free'] && !$own ? sh_free_badge() : '' ?></small></span></a>
          <?php endforeach; ?>
        <?php endif; ?>
        </div>
      </div>
    </section>
    <?php endif; ?>

    <section class="sp-sec" id="included"><h2>What's included</h2>
      <?php if ($myInc): ?><ul class="sp-own-inc"><?php foreach ($myInc as $v): ?><li><?= $ico('check') ?> <?= sh_e($v) ?></li><?php endforeach; ?></ul><?php endif; ?>
      <div class="sp-inc-g">
        <?php if ($isCourse): ?><div><?= $ico('video') ?><b><?= $nLessons ?> recorded video<?= $nLessons === 1 ? '' : 's' ?></b><span><?= $total ? sh_dur_text($total) . ' of classes' : 'Watch any time' ?></span></div><?php endif; ?>
        <?php if ($files): ?><div><?= $ico('file') ?><b><?= count($files) ?> PDF note<?= count($files) === 1 ? '' : 's' ?></b><span>Read on your phone or computer</span></div><?php endif; ?>
        <div><?= $ico($vd ? 'calendar' : 'infinity') ?><b><?= $validity ?></b><span><?= $vd ? 'From the day you buy' : 'Study for as long as you like' ?></span></div>
      </div>
    </section>

    <section class="sp-sec" id="reviews"><h2>Student reviews</h2>
      <div class="sp-rv-top">
        <div class="sp-rv-avg"><b><?= $rt['count'] ? number_format($rt['avg'], 1) : '–' ?></b><?= sh_stars($rt['avg']) ?><span><?= $rt['count'] ? $rt['count'] . ' rating' . ($rt['count'] === 1 ? '' : 's') : 'No ratings yet' ?></span></div>
        <div class="sp-rv-bars"><?php foreach ($rt['dist'] as $st => $n): $w = $rt['count'] ? round($n * 100 / $rt['count']) : 0; ?>
          <div><span><?= $st ?> ★</span><i><em style="width:<?= $w ?>%"></em></i><small><?= $w ?>%</small></div><?php endforeach; ?></div>
      </div>

      <?php if ($canReview): ?>
      <form class="sp-rv-form<?= $mine ? ' has' : '' ?>" method="post" action="/store/review" id="spRvForm"><?= sh_csrf_field() ?><input type="hidden" name="a" value="save"><input type="hidden" name="p" value="<?= $pid ?>">
        <b><?= $mine ? 'Your review' : 'Rate this ' . ($isCourse ? 'course' : 'notes pack') ?></b>
        <div class="sp-pick" role="radiogroup" aria-label="Your rating"><?php for ($i = 5; $i >= 1; $i--): ?><input type="radio" name="rating" id="rv<?= $i ?>" value="<?= $i ?>" <?= (int)($mine['rating'] ?? 0) === $i ? 'checked' : '' ?> required><label for="rv<?= $i ?>" title="<?= $i ?> star<?= $i === 1 ? '' : 's' ?>">★</label><?php endfor; ?></div>
        <textarea name="body" rows="3" maxlength="2000" placeholder="What did you like? How did it help you? (optional)"><?= sh_e($mine['body'] ?? '') ?></textarea>
        <div class="sp-rv-act"><button class="sp-btn" type="submit"><?= $mine ? 'Update review' : 'Post review' ?></button>
          <?php if ($mine): ?><button class="sp-link" type="submit" name="a" value="delete" formnovalidate onclick="return confirm('Remove your review?')">Remove</button><?php endif; ?></div>
        <?php if ($mine && $mine['status'] === 'pending'): ?><p class="sp-note sp-wait" style="text-align:left">⏳ Waiting for approval — your review shows here after the institute approves it.</p>
        <?php elseif ($mine && $mine['status'] !== 'published'): ?><p class="sp-note" style="text-align:left">Your review is hidden by the institute.</p><?php endif; ?>
      </form>
      <?php elseif ($u): ?><p class="sp-rv-note"><span class="sp-pen"><?= $ico('pen') ?></span><span>Reviews are written by <b>enrolled students</b>. Join this <?= $isCourse ? 'course' : 'notes pack' ?> to share your experience.</span></p>
      <?php else: ?><p class="sp-rv-note"><span class="sp-pen"><?= $ico('pen') ?></span><span>Enrolled in this <?= $isCourse ? 'course' : 'notes pack' ?>? <a href="/account/login?next=<?= rawurlencode(sh_url($p) . '#reviews') ?>">Log in</a> to write your review.</span></p><?php endif; ?>

      <?php if (!$reviews): ?><div class="sp-empty">No reviews yet<?= $canReview && !$mine ? ' — be the first to rate it.' : '.' ?></div><?php endif; ?>
      <?php if ($reviews): ?>
      <div class="sp-rvs">
        <div class="sp-rvs-track" tabindex="0" aria-label="Student reviews — swipe for more">
        <?php foreach ($reviews as $i => $r): $nm = $short($r['name']) ?: 'Student'; ?>
          <article class="sp-rvc">
            <div class="sp-rvc-who"><span class="sp-av sp-av<?= $i % 3 ?>"><?= sh_e(mb_strtoupper(mb_substr($nm, 0, 1))) ?></span>
              <div class="sp-rvc-nm"><b><?= sh_e($nm) ?></b><?= sh_stars((float)$r['rating'], 'sp-stars sm') ?></div>
              <small><?= date('d M Y', strtotime($r['updated_at'] ?: $r['created_at'])) ?></small></div>
            <p class="sp-rvc-q"><?= $r['body'] ? nl2br(sh_e($r['body'])) : '<span class="sp-rvc-only">Rated ' . (int)$r['rating'] . ' out of 5</span>' ?></p>
          </article>
        <?php endforeach; ?>
        </div>
        <?php if (count($reviews) > 1): ?><div class="sp-rvs-dots"><?php foreach ($reviews as $i => $_): ?><button type="button" aria-label="Review <?= $i + 1 ?>"<?= $i === 0 ? ' class="is-active"' : '' ?>></button><?php endforeach; ?></div><?php endif; ?>
      </div>
      <?php endif; ?>
    </section>

    <section class="sp-sec" id="faq"><h2>Frequently asked questions</h2>
      <div class="sp-faq">
        <?php foreach ($myFaq as $f): ?><details><summary><?= sh_e($f['q']) ?></summary><div class="sp-faq-b"><p><?= nl2br(sh_e($f['a'])) ?></p></div></details><?php endforeach; ?>
        <details><summary>How do I start after paying?</summary><div class="sp-faq-b"><p>Right after the payment the <?= $isCourse ? 'course' : 'notes pack' ?> opens in <b>My Library</b>. Log in any time with your mobile number or email to continue where you stopped.</p></div></details>
        <details><summary>Can I study on my phone?</summary><div class="sp-faq-b"><p>Yes — videos and PDFs open in the browser on any phone, tablet or computer. No app is needed.</p></div></details>
        <details><summary>How long can I use it?</summary><div class="sp-faq-b"><p><?= $vd ? 'You get ' . sh_e($validity) . ' from the day you buy.' : 'For as long as you like — the access never ends.' ?></p></div></details>
        <?php if ($isCourse): ?><details><summary>Can I change the video speed and quality?</summary><div class="sp-faq-b"><p>Yes. Use the ⚙ button on the video: speed from 0.5x to 2x, and a lower quality for slow internet.</p></div></details><?php endif; ?>
        <details><summary>I'm a student of the institute — do I pay again?</summary><div class="sp-faq-b"><p>Ask the office — our staff can add courses to your account. Sign up with the same mobile number you gave the institute.</p></div></details>
      </div>
    </section>
  </div>
</div>

<div class="st-mbar"><div class="sp-price"><?= $own ? '<span class="sp-owned">' . $ico('check') . ' In your library</span>' : ($off ? '<s>' . sh_money($p['mrp']) . '</s>' : '') . ($isFree ? $priceTxt : '<b>' . $priceTxt . '</b>') ?></div>
  <?php if ($own): ?><a class="sp-btn" href="<?= sh_e($lu()) ?>"><?= $isCourse ? 'Continue' : 'Open notes' ?></a><?php else: ?><button type="button" class="sp-btn" onclick="var b=document.querySelector('.sp-card [data-buy], .sp-card a.sp-btn');b.scrollIntoView({block:'center'});b.click();"><?= $joinTxt ?></button><?php endif; ?></div>
<?php if ($u && !$own): ?><script>window.ST_BUYER = <?= json_encode(['name' => $u['name'], 'email' => $u['email'], 'contact' => $u['mobile']], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>; window.ST_SITE = <?= json_encode(edu_site_name(), JSON_HEX_TAG) ?>;</script><?php endif; ?>
<?php require ROOT_PATH . '/components/store-close.php'; ?>
