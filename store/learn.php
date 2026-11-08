<?php
// Course player: watch a lesson / read a PDF of a course or notes pack the student owns (free previews for everyone).
//   /learn/slug (?l=lessonId | ?f=fileId) (&preview=1 = staff preview)   (old: /store/learn?s=slug …)
// Paid lessons are only shown to owners: anyone else is sent to the course page (or to log in), and the media links
// themselves are checked again in /store/media.
require_once dirname(__DIR__) . '/includes/config.php';
require_once INCLUDES_PATH . '/shop.php';
$pdo = sh_db();
if (($_GET['s'] ?? '') === 'rw-probe') { header('Cache-Control: no-store'); if (!defined('SH_VIA_404') && sh_rw_live()) exit('rw-ok'); http_response_code(404); exit; } // are the clean-address rules live?
$staff = sh_preview(); // staff see everything only with ?preview=1 — otherwise the same locks as students
$p = sh_on() || $staff ? sh_product($pdo, (string)($_GET['s'] ?? ''), !$staff) : null;
if (!$p) { http_response_code(404); require ROOT_PATH . '/templates/page_not_found.php'; exit; }
sh_redirect_clean(sh_learn_url($p['slug'], http_build_query(array_intersect_key($_GET, array_flip(['l', 'f', 'new']))), isset($_GET['preview']))); // old /store/learn?s=…
$u = sh_user();
$uid = $u ? (int)$u['id'] : 0;
$owner = sh_has_access($pdo, $uid, (int)$p['id']);
$own = $owner || $staff;
$isCourse = $p['type'] === 'course';
$cur = $isCourse ? sh_curriculum($pdo, (int)$p['id']) : [];
$files = sh_files($pdo, (int)$p['id']);
$all = []; $secOf = [];
foreach ($cur as $s) foreach ($s['lessons'] as $l) { $all[] = $l; $secOf[(int)$l['id']] = $s['title']; }
$lid = (int)($_GET['l'] ?? 0);
$fid = (int)($_GET['f'] ?? 0);
$lesson = null; $file = null;
if ($fid) foreach ($files as $f) if ((int)$f['id'] === $fid) $file = $f;
if (!$file && $lid) foreach ($all as $l) if ((int)$l['id'] === $lid) $lesson = $l;
if (!$file && !$lesson) { // nothing asked (or not found): the first lesson / PDF this visitor may open
    foreach ($all as $l) if ($own || $l['free']) { $lesson = $l; break; }
    if (!$lesson) foreach ($files as $f) if ($own || $f['free']) { $file = $f; break; }
}
$item = $lesson ?: $file;
if (!$item || (!$own && empty($item['free']))) { // a paid lesson for someone who has not bought it
    if (!$u && $item) { header('Location: /account/login?next=' . rawurlencode($_SERVER['REQUEST_URI'])); exit; }
    header('Location: ' . sh_url($p) . '#content'); exit;
}
$done = [];
if ($uid) { $q = $pdo->prepare("SELECT lesson_id FROM sh_progress WHERE user_id = ? AND done = 1"); $q->execute([$uid]); $done = array_flip($q->fetchAll(PDO::FETCH_COLUMN)); }
$nDone = count(array_filter($all, fn($l) => isset($done[$l['id']])));
$pct = $all ? (int)round($nDone * 100 / count($all)) : 0;
$lu = fn(string $q = '') => sh_learn_url($p['slug'], $q, $staff);
$can = fn($x) => $own || !empty($x['free']);
// previous / next among what this visitor may open
$openList = array_values(array_filter($all, $can));
$idx = $lesson ? array_search((int)$lesson['id'], array_map(fn($l) => (int)$l['id'], $openList), true) : false;
$prev = $idx !== false && $idx > 0 ? $openList[$idx - 1] : null;
$next = $idx !== false && $idx < count($openList) - 1 ? $openList[$idx + 1] : null;
$lockedLeft = count($all) - count($openList) + count(array_filter($files, fn($f) => !$can($f)));

$wm = ''; // no watermark on videos or PDFs
$stTitle = ($item['title'] ?? $p['title']) . ' — ' . $p['title'];
require ROOT_PATH . '/components/store-open.php';
$ico = fn($n) => sh_icon($n);
?>
<?php /* (no course bar above the video — progress shows on the lesson list) */ ?>
<?php if (isset($_GET['new'])): ?><div class="lp-wrap"><div class="st-msg ok">Payment successful — <b><?= sh_e($p['title']) ?></b> is now in your library. Happy learning!</div></div><?php endif; ?>
<?php if ($staff && !$owner): ?><div class="lp-wrap"><div class="st-msg info">Staff preview — every video and PDF is open for you. <a href="<?= sh_e(sh_url($p)) ?>">See it as a student</a></div></div><?php endif; ?>

<div class="lp-wrap lp-grid">
  <div class="lp-main">
  <?php if ($lesson): ?>
    <?php $isPdfL = ($lesson['kind'] ?? 'video') === 'pdf'; $directUrl = !$lesson['video_file'] && $lesson['video_url'] && sh_is_direct_video((string)$lesson['video_url']); ?>
    <?php if ($isPdfL): $pdfUrl = $lesson['pdf_file'] ? sh_media_link('p', (int)$lesson['id'], $uid) : ''; ?>
    <?php if ($pdfUrl): ?><div class="lp-pdfbar"><span><?= $ico('file') ?> <b>PDF lecture</b><span class="lp-sub" id="stPdfInfo" hidden></span></span><span class="lp-pdfbar-b"><a class="lp-pdfbtn ghost" href="<?= sh_e($pdfUrl) ?>" target="_blank" rel="noopener"><?= $ico('eye') ?> Open full screen</a><a class="lp-pdfbtn" href="<?= sh_e($pdfUrl) ?>&amp;dl=1" download><?= $ico('download') ?> Download PDF</a></span></div><?php endif; ?>
    <div class="st-pdfv lp-pdfl" id="stPdf" data-src="<?= sh_e($pdfUrl) ?>"><?= $lesson['pdf_file'] ? '' : '<div class="st-empty">The PDF of this lecture is being uploaded.</div>' ?></div>
    <?php if (!$pdfUrl): ?><span class="lp-sub" id="stPdfInfo" hidden></span><?php endif; ?>
    <?php else: ?>
    <div class="st-player" id="stPlayer" oncontextmenu="return false">
      <?php if ($lesson['video_file'] || $directUrl): ?>
        <?php
        $srcs = [['q' => 'orig', 'h' => (int)($lesson['height'] ?? 0), 'label' => ($lesson['height'] ? (int)$lesson['height'] . 'p' : 'Original'), 'src' => $directUrl ? (string)$lesson['video_url'] : sh_media_link('v', (int)$lesson['id'], $uid)]];
        foreach (sh_variants($lesson) as $qq => $_) if (!$lesson['height'] || (int)$qq < (int)$lesson['height']) $srcs[] = ['q' => (string)$qq, 'h' => (int)$qq, 'label' => $qq . 'p', 'src' => sh_media_link('v', (int)$lesson['id'], $uid, 14400, (string)$qq)];
        ?>
        <video id="stVideo" controls playsinline preload="metadata" controlsList="nodownload" disablePictureInPicture data-srcs="<?= sh_e(json_encode($srcs, JSON_UNESCAPED_SLASHES)) ?>" src="<?= sh_e($srcs[0]['src']) ?>"></video>
        <div class="st-gear"><button type="button" class="st-gear-b" aria-label="Settings: speed and quality" aria-haspopup="true" aria-expanded="false"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68 1.65 1.65 0 0 0 10 3.17V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"/></svg><span class="st-gear-l" id="stGearL">1x</span></button>
          <div class="st-menu" hidden><div class="st-menu-h">Speed</div><div class="st-menu-r" data-speed><?php foreach (['0.5', '0.75', '1', '1.25', '1.5', '1.75', '2'] as $sp): ?><button type="button" data-v="<?= $sp ?>"<?= $sp === '1' ? ' class="on"' : '' ?>><?= $sp === '1' ? 'Normal' : $sp . 'x' ?></button><?php endforeach; ?></div>
          <div class="st-menu-h">Quality</div><div class="st-menu-r" data-quality><button type="button" data-v="auto" class="on">Auto</button><?php foreach ($srcs as $sx): ?><button type="button" data-v="<?= sh_e($sx['q']) ?>"><?= sh_e($sx['label']) ?></button><?php endforeach; ?></div></div></div>
      <?php elseif ($lesson['video_url'] && ($ytL = sh_yt_id((string)$lesson['video_url']))): ?>
        <?= sh_yt_lock_html($ytL, (string)$lesson['title']) ?>
      <?php elseif ($lesson['video_url']): ?>
        <iframe src="<?= sh_e(sh_embed_url((string)$lesson['video_url'])) ?>" title="<?= sh_e($lesson['title']) ?>" allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture; fullscreen" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>
      <?php else: ?><div class="lp-novid">The video for this lesson is being uploaded.</div><?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="lp-head">
      <div><?php if (!empty($secOf[(int)$lesson['id']])): ?><span class="lp-ch"><?= sh_e($secOf[(int)$lesson['id']]) ?></span><?php endif; ?><h1><?= sh_e($lesson['title']) ?></h1>
        <?php // what this lesson has: icons + numbers only (nothing shown for what it does not have)
        $hN = count(sh_lesson_notes($pdo, (int)$lesson['id'])); $hV = sh_vvi($lesson); $hQ = sh_quiz($lesson);
        $hV = count($hV['qa']); $hQ = count($hQ['items']); // questions only (same numbers as the tabs)
        $chip = fn($n, $icon, $label, $cls) => $n ? '<span class="lp-cnt ' . $cls . '" title="' . $n . ' ' . $label . '" aria-label="' . $n . ' ' . $label . '">' . $icon . '<b>' . $n . '</b></span>' : ''; ?>
        <div class="lp-subrow"><span class="lp-sub"><?= $lesson['duration'] ? sh_e($lesson['duration']) . ' · ' : '' ?><?= $lesson['free'] && !$owner ? 'Free preview' : 'Lesson ' . (array_search((int)$lesson['id'], array_map(fn($l) => (int)$l['id'], $all), true) + 1) . ' of ' . count($all) ?></span><?php
          $chips = $chip($hN, $ico('pdf'), 'PDF notes', 'c-pdf') . $chip($hV, $ico('flag'), 'VVI questions', 'c-vvi') . $chip($hQ, $ico('quiz'), 'MCQ questions', 'c-mcq');
          if ($chips !== ''): ?><span class="lp-cnts"><?= $chips ?></span><?php endif; ?></div></div>
      <div class="lp-acts">
        <?php if ($owner): ?><button type="button" class="lp-btn ghost<?= isset($done[$lesson['id']]) ? ' is-done' : '' ?>" id="stDone" data-l="<?= (int)$lesson['id'] ?>" data-done="<?= isset($done[$lesson['id']]) ? 1 : 0 ?>" data-t="<?= sh_e(sh_csrf()) ?>"><?= $ico('check') ?> <span><?= isset($done[$lesson['id']]) ? 'Completed' : 'Mark as done' ?></span></button><?php endif; ?>
        <?php if ($prev): ?><a class="lp-btn ghost" href="<?= sh_e($lu('l=' . (int)$prev['id'])) ?>">‹ Previous</a><?php endif; ?>
        <?php if ($next): ?><a class="lp-btn" href="<?= sh_e($lu('l=' . (int)$next['id'])) ?>">Next lesson ›</a><?php elseif (!$own && $lockedLeft): ?><a class="lp-btn" href="<?= sh_e(sh_url($p)) ?>#buy">Unlock all lessons</a><?php endif; ?>
      </div>
    </div>
    <?php
    $lNotes = sh_lesson_notes($pdo, (int)$lesson['id']);
    $lVvi = sh_vvi($lesson); $lQuiz = sh_quiz($lesson);
    $canAsk = ($owner || $staff) && !empty($lesson['doubts_on']);
    $tabs = array_filter([
        'notes' => $lNotes ? ['PDF Notes', 'notes'] : null,
        'vvi' => ($lVvi['qa'] || $lVvi['links']) ? ['VVI Questions', 'flag'] : null,
        'quiz' => ($lQuiz['items'] || $lQuiz['links']) ? ['Quiz', 'check'] : null,
        'doubts' => $canAsk ? ['Doubts & Problems', 'pen'] : null,
    ]);
    $linkBox = function (array $links, string $kind) use ($ico) {
        $h = '';
        foreach ($links as [$t, $u]) $h .= '<a class="lt-link" href="' . sh_e($u) . '" target="_blank" rel="noopener"><span class="lt-link-i">' . $ico($kind === 'quiz' ? 'check' : 'file') . '</span><span><b>' . sh_e($t) . '</b><small>' . sh_e(parse_url($u, PHP_URL_HOST) ?: '') . '</small></span><span class="lt-link-go">Open ›</span></a>';
        return $h;
    };
    ?>
    <?php ob_start(); // PDF notes / VVI / quiz / doubts are printed below the lesson list (see .lp-more) ?>
    <?php if ($tabs): ?>
    <div class="lt" id="lt">
      <div class="lt-strip" role="tablist"><?php $first = true; foreach ($tabs as $k => [$lab, $ic]): ?><button type="button" role="tab" data-lt="<?= $k ?>" aria-selected="<?= $first ? 'true' : 'false' ?>" class="<?= $first ? 'on' : '' ?>"><?= $ico($ic) ?> <?= $lab ?><?php if ($k === 'notes'): ?><em><?= count($lNotes) ?></em><?php elseif ($k === 'vvi' && $lVvi['qa']): ?><em><?= count($lVvi['qa']) ?></em><?php elseif ($k === 'quiz' && $lQuiz['items']): ?><em><?= count($lQuiz['items']) ?></em><?php endif; ?></button><?php $first = false; endforeach; ?></div>
      <?php $first = true; foreach ($tabs as $k => $_): ?><div class="lt-pane" data-pane="<?= $k ?>"<?= $first ? '' : ' hidden' ?>>
        <?php if ($k === 'about'): ?><div class="lt-about"><?= nl2br(sh_e($lesson['notes'])) ?></div>
        <?php elseif ($k === 'notes'): ?>
          <?php foreach ($lNotes as $nf): $nUrl = sh_media_link('f', (int)$nf['id'], $uid); ?><div class="lt-note lt-nc">
            <div class="lt-nc-top"><span class="lt-nc-i"><?= $ico('pdf') ?></span><span class="lt-nc-t"><b><?= sh_e($nf['title']) ?></b><small><?= $nf['pages'] ? (int)$nf['pages'] . ' pages · ' : '' ?>PDF<?= !empty($nf['size']) ? ' · ' . ((int)$nf['size'] >= 1048576 ? round((int)$nf['size'] / 1048576, 1) . ' MB' : max(1, (int)round((int)$nf['size'] / 1024)) . ' KB') : '' ?></small></span></div>
            <div class="lt-nc-b"><button type="button" class="lt-note-h lt-nc-v" data-pdf="<?= sh_e($nUrl) ?>"><?= $ico('eye') ?><span class="lt-nc-o">View</span><span class="lt-nc-c">Close</span></button><a class="lt-dl lt-nc-d" href="<?= sh_e($nUrl) ?>&amp;dl=1" download title="Download <?= sh_e($nf['title']) ?>"><?= $ico('download') ?><span>Download</span></a></div>
            <div class="st-pdfv lt-pdf" hidden></div></div><?php endforeach; ?>
        <?php elseif ($k === 'vvi'): ?>
          <?php if ($lVvi['qa']): ?><div class="sp-faq lt-faq"><?php foreach ($lVvi['qa'] as $n => [$qq, $aa]): ?><details><summary><span class="lt-qn">Q<?= $n + 1 ?>.</span> <?= sh_e($qq) ?></summary><div class="sp-faq-b"><p><?= $aa !== '' ? nl2br(sh_e($aa)) : '<em>Answer will be added soon.</em>' ?></p></div></details><?php endforeach; ?></div><?php endif; ?>
          <?php if ($lVvi['links']): ?><div class="lt-links"><?= $linkBox($lVvi['links'], 'vvi') ?></div><?php endif; ?>
        <?php elseif ($k === 'quiz'): ?>
          <?php if ($lQuiz['items']): ?><div class="lt-quiz" data-quiz='<?= sh_e(json_encode(array_map(fn($x) => [$x[2], $x[3]], $lQuiz['items']), JSON_UNESCAPED_UNICODE | JSON_HEX_APOS)) ?>'>
            <?php foreach ($lQuiz['items'] as $n => [$qq, $oo, $_a, $_e]): ?><div class="lt-q" data-n="<?= $n ?>"><b><span class="lt-qn">Q<?= $n + 1 ?>.</span> <?= sh_e($qq) ?></b>
              <div class="lt-opts"><?php foreach ($oo as $oi => $ov): ?><label class="lt-opt"><input type="radio" name="lq<?= (int)$lesson['id'] ?>_<?= $n ?>" value="<?= $oi ?>"><span class="lt-ol"><?= chr(65 + $oi) ?></span><span><?= sh_e($ov) ?></span></label><?php endforeach; ?></div>
              <p class="lt-exp" hidden></p></div><?php endforeach; ?>
            <div class="lt-qact"><button type="button" class="lp-btn" data-check>Check answers</button><button type="button" class="lp-btn ghost" data-retry hidden>Try again</button><span class="lt-score" aria-live="polite"></span></div>
          </div><?php endif; ?>
          <?php if ($lQuiz['links']): ?><div class="lt-links"><?= $linkBox($lQuiz['links'], 'quiz') ?></div><?php endif; ?>
        <?php elseif ($k === 'doubts'): ?>
          <div class="lt-doubts" id="ltDoubts" data-l="<?= (int)$lesson['id'] ?>" data-t="<?= sh_e(sh_csrf()) ?>">
            <form class="lt-ask" onsubmit="return false"><textarea maxlength="2000" rows="3" placeholder="Stuck somewhere? Write your doubt or problem about this lecture — the teacher will answer."></textarea>
              <div class="lt-ask-b"><span class="lt-ask-m"></span><button type="submit" class="lp-btn"><?= $ico('pen') ?> Ask your doubt</button></div></form>
            <div class="lt-dlist"><p class="lt-dl-empty">Loading…</p></div>
          </div>
        <?php endif; ?>
      </div><?php $first = false; endforeach; ?>
    </div>
    <?php endif; ?>
    <?php $moreHtml = ob_get_clean(); ?>
  <?php else: ?>
    <?php $fUrl = sh_media_link('f', (int)$file['id'], $uid); ?>
    <div class="lp-head"><div><span class="lp-ch">PDF notes</span><h1><?= sh_e($file['title']) ?></h1><span class="lp-sub" id="stPdfInfo">Loading…</span></div>
      <div class="lp-pdfbar-b"><a class="lp-pdfbtn ghost" href="<?= sh_e($fUrl) ?>" target="_blank" rel="noopener"><?= $ico('eye') ?> Open full screen</a><a class="lp-pdfbtn" href="<?= sh_e($fUrl) ?>&amp;dl=1" download><?= $ico('download') ?> Download PDF</a></div></div>
    <div class="st-pdfv" id="stPdf" data-src="<?= sh_e($fUrl) ?>"></div>
  <?php endif; ?>
  </div>

  <aside class="lp-side">
    <?php if (!$own && $lockedLeft): ?>
    <div class="lp-unlock"><b>Unlock the full <?= $isCourse ? 'course' : 'notes' ?></b><span><?= $lockedLeft ?> more lesson<?= $lockedLeft === 1 ? '' : 's' ?> / PDF<?= $lockedLeft === 1 ? '' : 's' ?> are locked.</span>
      <a class="lp-btn" href="<?= sh_e(sh_url($p)) ?>#buy"><?= (float)$p['price'] > 0 ? 'Buy for ' . sh_money($p['price']) : 'Enrol for free' ?></a></div>
    <?php endif; ?>
    <?php
    // lesson list like the free courses: number · picture · title (YouTube's picture, a PDF tile, or the course picture)
    $thumbOf = fn(array $l) => sh_lesson_thumb($l, $p);
    $nVid = count($all); $num = 0; $multi = count($cur) > 1;
    ?>
    <div class="lp-list lp-fr">
      <div class="lp-list-h"><b><?= $isCourse ? 'Lessons' : 'PDF notes' ?></b><span><?= $isCourse ? ($owner && $all ? $nDone . ' / ' . $nVid . ' done' : $nVid . ' ' . ($nVid === 1 ? 'lesson' : 'lessons')) : count($files) . ' PDF' . (count($files) === 1 ? '' : 's') ?></span></div>
      <?php if ($owner && $all): ?><div class="lp-bar"><i style="width:<?= $pct ?>%"></i></div><?php endif; ?>
      <div class="lp-fr-l" id="lpList">
      <?php foreach ($cur as $s): ?>
        <?php if ($multi && $s['lessons']): ?><div class="lp-ch-h"><?= sh_e($s['title'] !== '' ? $s['title'] : 'Lessons') ?><small><?= count($s['lessons']) ?></small></div><?php endif; ?>
        <?php foreach ($s['lessons'] as $l): $num++; $ok = $can($l); $on = $lesson && (int)$lesson['id'] === (int)$l['id']; $isDone = isset($done[$l['id']]); $th = $thumbOf($l); $isPdf = ($l['kind'] ?? 'video') === 'pdf'; ?>
          <a class="lp-li<?= $on ? ' on' : '' ?><?= $isDone ? ' done' : '' ?><?= $ok ? '' : ' locked' ?>" href="<?= sh_e($ok ? $lu('l=' . (int)$l['id']) : sh_url($p) . '#buy') ?>"<?= $ok ? '' : ' title="Buy the course to open this lesson"' ?><?= $on ? ' aria-current="true"' : '' ?>>
            <span class="lp-n"><?= $num ?></span>
            <span class="lp-th<?= $isPdf || $th === '' ? ' ph' : '' ?>"><?php if (!$isPdf && $th !== ''): ?><img src="<?= sh_e($th) ?>" alt="" loading="lazy"><?php else: ?><?= $ico($isPdf ? 'pdf' : 'play') ?><?php endif; ?>
              <?php if (!$ok): ?><i class="lp-tag lock"><?= $ico('lock') ?></i><?php elseif ($on): ?><i class="lp-tag now">Playing</i><?php elseif ($isDone): ?><i class="lp-tag ok"><?= $ico('check') ?></i><?php endif; ?></span>
            <span class="lp-lt"><span class="lp-t2"><?= sh_e($l['title']) ?></span><small><?= $isPdf ? 'PDF' : ($l['duration'] ? sh_e($l['duration']) : 'Video') ?><?= $l['free'] && !$owner ? sh_free_badge() : '' ?></small></span></a>
        <?php endforeach; ?>
      <?php endforeach; ?>
      <?php if ($files): ?>
        <?php if ($cur): ?><div class="lp-ch-h"><?= $isCourse ? 'PDF notes' : 'Files' ?><small><?= count($files) ?></small></div><?php endif; ?>
        <?php foreach ($files as $k => $f): $ok = $can($f); $on = $file && (int)$file['id'] === (int)$f['id']; ?>
          <a class="lp-li<?= $on ? ' on' : '' ?><?= $ok ? '' : ' locked' ?>" href="<?= sh_e($ok ? $lu('f=' . (int)$f['id']) : sh_url($p) . '#buy') ?>"<?= $on ? ' aria-current="true"' : '' ?>>
            <span class="lp-n"><?= $k + 1 ?></span>
            <span class="lp-th ph"><?= $ico('pdf') ?><?php if (!$ok): ?><i class="lp-tag lock"><?= $ico('lock') ?></i><?php elseif ($on): ?><i class="lp-tag now">Open</i><?php endif; ?></span>
            <span class="lp-lt"><span class="lp-t2"><?= sh_e($f['title']) ?></span><small><?= $f['pages'] ? (int)$f['pages'] . ' pages' : 'PDF' ?><?= $f['free'] && !$owner ? sh_free_badge() : '' ?></small></span></a>
        <?php endforeach; ?>
      <?php endif; ?>
      </div>
    </div>
    <script>(function () { var l = document.getElementById('lpList'), c = l && l.querySelector('.lp-li.on'); if (c) l.scrollTop = c.offsetTop - l.offsetTop - 60; })();</script>
    <?php if (!empty($moreHtml) && trim($moreHtml) !== ''): ?><section class="lp-more" aria-label="About this lesson"><?= $moreHtml ?></section><?php endif; // right under the lesson list ?>
    <?php if ($owner && $isCourse && $all):
      // the student's progress card: course picture + name, classes done, % complete, continue with the next class not done yet
      $left = count($all) - $nDone;
      $nextUp = null; foreach ($all as $l) if (!isset($done[$l['id']]) && (!$lesson || (int)$l['id'] !== (int)$lesson['id'])) { $nextUp = $l; break; }
      $R = 26; $C = round(2 * M_PI * $R, 2); $off = round($C * (1 - $pct / 100), 2); ?>
    <section class="lp-prog" aria-label="Your progress in this course">
      <a class="lp-prog-img" href="<?= sh_e(sh_url($p)) ?>"><?= sh_thumb_html($p) ?></a>
      <div class="lp-prog-b">
        <small class="lp-prog-k">Your progress</small>
        <b class="lp-prog-t"><?= sh_e($p['title']) ?></b>
        <div class="lp-prog-row">
          <span class="lp-prog-ring" role="img" aria-label="<?= $pct ?>% complete"><svg viewBox="0 0 64 64" aria-hidden="true"><circle cx="32" cy="32" r="<?= $R ?>" class="bg"/><circle cx="32" cy="32" r="<?= $R ?>" class="fg" stroke-dasharray="<?= $C ?>" stroke-dashoffset="<?= $off ?>"/></svg><em><?= $pct ?><small>%</small></em></span>
          <div class="lp-prog-s"><span><b><?= $nDone ?></b> / <?= count($all) ?> classes done</span><small><?= $left ? $left . ' class' . ($left === 1 ? '' : 'es') . ' left' : 'All classes done' ?></small>
            <span class="lp-prog-bar"><i style="width:<?= $pct ?>%"></i></span></div>
        </div>
        <?php if ($left && $nextUp): ?><a class="lp-btn lp-prog-go" href="<?= sh_e($lu('l=' . (int)$nextUp['id'])) ?>"><?= $ico('playsolid') ?><span>Continue: <?= sh_e($nextUp['title']) ?></span></a>
        <?php elseif (!$left): ?><span class="lp-prog-done"><?= $ico('check') ?> Course completed</span><?php endif; ?>
      </div>
    </section>
    <?php endif; ?>
  </aside>
</div>
<?php if ($file || ($lesson && ($lesson['kind'] ?? '') === 'pdf')): ?><script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js" defer></script><?php endif; ?>
<?php require ROOT_PATH . '/components/store-close.php'; ?>
