<?php
// Course player: watch a lesson / read a PDF of a course or notes pack the student owns (free previews for everyone).
//   /store/learn?s=slug (&l=lessonId | &f=fileId) (&preview=1 = staff preview)
// Paid lessons are only shown to owners: anyone else is sent to the course page (or to log in), and the media links
// themselves are checked again in /store/media.
require_once dirname(__DIR__) . '/includes/config.php';
require_once INCLUDES_PATH . '/shop.php';
$pdo = sh_db();
$staff = sh_preview(); // staff see everything only with ?preview=1 — otherwise the same locks as students
$p = sh_on() || $staff ? sh_product($pdo, (string)($_GET['s'] ?? ''), !$staff) : null;
if (!$p) { http_response_code(404); require ROOT_PATH . '/templates/page_not_found.php'; exit; }
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
$base = '/store/learn?s=' . rawurlencode($p['slug']) . ($staff ? '&preview=1' : '');
$can = fn($x) => $own || !empty($x['free']);
// previous / next among what this visitor may open
$openList = array_values(array_filter($all, $can));
$idx = $lesson ? array_search((int)$lesson['id'], array_map(fn($l) => (int)$l['id'], $openList), true) : false;
$prev = $idx !== false && $idx > 0 ? $openList[$idx - 1] : null;
$next = $idx !== false && $idx < count($openList) - 1 ? $openList[$idx + 1] : null;
$lockedLeft = count($all) - count($openList) + count(array_filter($files, fn($f) => !$can($f)));

$wm = $u ? ($u['mobile'] . ' · ' . $u['email']) : '';
$stTitle = ($item['title'] ?? $p['title']) . ' — ' . $p['title'];
require ROOT_PATH . '/components/store-open.php';
$ico = fn($n) => sh_icon($n);
?>
<div class="lp-top"><div class="lp-top-in">
  <a class="lp-back" href="<?= sh_e(sh_url($p) . ($staff ? '&preview=1' : '')) ?>" aria-label="Course page"><?= $ico('back') ?></a>
  <div class="lp-tt"><b><?= sh_e($p['title']) ?></b><?php if ($isCourse && $all): ?><span><?= $owner ? $nDone . ' of ' . count($all) . ' done' : ($staff ? 'Staff preview' : 'Free preview') ?></span><?php endif; ?></div>
  <?php if ($isCourse && $all && $owner): ?><div class="lp-ring" style="--p:<?= $pct ?>" title="<?= $pct ?>% done"><span><?= $pct ?>%</span></div><?php endif; ?>
  <?php if ($u): ?><a class="lp-lib" href="/account/">My Library</a><?php endif; ?>
</div></div>
<?php if (isset($_GET['new'])): ?><div class="lp-wrap"><div class="st-msg ok">Payment successful — <b><?= sh_e($p['title']) ?></b> is now in your library. Happy learning!</div></div><?php endif; ?>
<?php if ($staff && !$owner): ?><div class="lp-wrap"><div class="st-msg info">Staff preview — every video and PDF is open for you. <a href="<?= sh_e(sh_url($p)) ?>">See it as a student</a></div></div><?php endif; ?>

<div class="lp-wrap lp-grid">
  <div class="lp-main">
  <?php if ($lesson): ?>
    <?php $isPdfL = ($lesson['kind'] ?? 'video') === 'pdf'; $directUrl = !$lesson['video_file'] && $lesson['video_url'] && sh_is_direct_video((string)$lesson['video_url']); ?>
    <?php if ($isPdfL): ?>
    <div class="st-pdfv lp-pdfl" id="stPdf" data-src="<?= $lesson['pdf_file'] ? sh_e(sh_media_link('p', (int)$lesson['id'], $uid)) : '' ?>" data-wm="<?= sh_e($wm) ?>" oncontextmenu="return false"><?= $lesson['pdf_file'] ? '' : '<div class="st-empty">The PDF of this lecture is being uploaded.</div>' ?></div>
    <span class="lp-sub" id="stPdfInfo" hidden></span>
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
        <?php if ($wm): ?><span class="st-wm" id="stWm"><?= sh_e($wm) ?></span><?php endif; ?>
      <?php elseif ($lesson['video_url']): ?>
        <iframe src="<?= sh_e(sh_embed_url((string)$lesson['video_url'])) ?>" title="<?= sh_e($lesson['title']) ?>" allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture; fullscreen" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>
      <?php else: ?><div class="lp-novid">The video for this lesson is being uploaded.</div><?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="lp-head">
      <div><?php if (!empty($secOf[(int)$lesson['id']])): ?><span class="lp-ch"><?= sh_e($secOf[(int)$lesson['id']]) ?></span><?php endif; ?><h1><?= sh_e($lesson['title']) ?></h1>
        <span class="lp-sub"><?= $lesson['duration'] ? sh_e($lesson['duration']) . ' · ' : '' ?><?= $lesson['free'] && !$owner ? 'Free preview' : 'Lesson ' . (array_search((int)$lesson['id'], array_map(fn($l) => (int)$l['id'], $all), true) + 1) . ' of ' . count($all) ?></span></div>
      <div class="lp-acts">
        <?php if ($owner): ?><button type="button" class="lp-btn ghost<?= isset($done[$lesson['id']]) ? ' is-done' : '' ?>" id="stDone" data-l="<?= (int)$lesson['id'] ?>" data-done="<?= isset($done[$lesson['id']]) ? 1 : 0 ?>" data-t="<?= sh_e(sh_csrf()) ?>"><?= $ico('check') ?> <span><?= isset($done[$lesson['id']]) ? 'Completed' : 'Mark as done' ?></span></button><?php endif; ?>
        <?php if ($prev): ?><a class="lp-btn ghost" href="<?= sh_e($base . '&l=' . (int)$prev['id']) ?>">‹ Previous</a><?php endif; ?>
        <?php if ($next): ?><a class="lp-btn" href="<?= sh_e($base . '&l=' . (int)$next['id']) ?>">Next lesson ›</a><?php elseif (!$own && $lockedLeft): ?><a class="lp-btn" href="<?= sh_e(sh_url($p)) ?>#buy">Unlock all lessons</a><?php endif; ?>
      </div>
    </div>
    <?php
    $lNotes = sh_lesson_notes($pdo, (int)$lesson['id']);
    $lVvi = sh_vvi($lesson); $lQuiz = sh_quiz($lesson);
    $canAsk = ($owner || $staff) && !empty($lesson['doubts_on']);
    $tabs = array_filter([
        'about' => trim((string)$lesson['notes']) !== '' ? ['About', 'book'] : null,
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
    <?php if ($tabs): ?>
    <div class="lt" id="lt">
      <div class="lt-strip" role="tablist"><?php $first = true; foreach ($tabs as $k => [$lab, $ic]): ?><button type="button" role="tab" data-lt="<?= $k ?>" aria-selected="<?= $first ? 'true' : 'false' ?>" class="<?= $first ? 'on' : '' ?>"><?= $ico($ic) ?> <?= $lab ?><?php if ($k === 'notes'): ?><em><?= count($lNotes) ?></em><?php elseif ($k === 'vvi' && $lVvi['qa']): ?><em><?= count($lVvi['qa']) ?></em><?php elseif ($k === 'quiz' && $lQuiz['items']): ?><em><?= count($lQuiz['items']) ?></em><?php endif; ?></button><?php $first = false; endforeach; ?></div>
      <?php $first = true; foreach ($tabs as $k => $_): ?><div class="lt-pane" data-pane="<?= $k ?>"<?= $first ? '' : ' hidden' ?>>
        <?php if ($k === 'about'): ?><div class="lt-about"><?= nl2br(sh_e($lesson['notes'])) ?></div>
        <?php elseif ($k === 'notes'): ?>
          <?php foreach ($lNotes as $nf): ?><div class="lt-note"><button type="button" class="lt-note-h" data-pdf="<?= sh_e(sh_media_link('f', (int)$nf['id'], $uid)) ?>" data-wm="<?= sh_e($wm) ?>"><span class="lt-link-i"><?= $ico('notes') ?></span><span><b><?= sh_e($nf['title']) ?></b><small><?= $nf['pages'] ? (int)$nf['pages'] . ' pages · ' : '' ?>tap to read</small></span><span class="lt-link-go">Read ›</span></button><div class="st-pdfv lt-pdf" hidden></div></div><?php endforeach; ?>
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
  <?php else: ?>
    <div class="lp-head"><div><span class="lp-ch">PDF notes</span><h1><?= sh_e($file['title']) ?></h1><span class="lp-sub" id="stPdfInfo">Loading…</span></div></div>
    <div class="st-pdfv" id="stPdf" data-src="<?= sh_e(sh_media_link('f', (int)$file['id'], $uid)) ?>" data-wm="<?= sh_e($wm) ?>" oncontextmenu="return false"></div>
  <?php endif; ?>
  </div>

  <aside class="lp-side">
    <?php if (!$own && $lockedLeft): ?>
    <div class="lp-unlock"><b>Unlock the full <?= $isCourse ? 'course' : 'notes' ?></b><span><?= $lockedLeft ?> more lesson<?= $lockedLeft === 1 ? '' : 's' ?> / PDF<?= $lockedLeft === 1 ? '' : 's' ?> are locked.</span>
      <a class="lp-btn" href="<?= sh_e(sh_url($p)) ?>#buy"><?= (float)$p['price'] > 0 ? 'Buy for ' . sh_money($p['price']) : 'Enrol for free' ?></a></div>
    <?php endif; ?>
    <div class="lp-list">
      <div class="lp-list-h"><b><?= $isCourse ? 'Course content' : 'PDF notes' ?></b><?php if ($owner && $all): ?><small><?= $nDone ?>/<?= count($all) ?></small><?php endif; ?></div>
      <?php if ($owner && $all): ?><div class="lp-bar"><i style="width:<?= $pct ?>%"></i></div><?php endif; ?>
      <?php foreach ($cur as $s): $hasCur = $lesson && in_array((int)$lesson['id'], array_map(fn($x) => (int)$x['id'], $s['lessons']), true); $sDone = count(array_filter($s['lessons'], fn($x) => isset($done[$x['id']]))); ?>
      <details class="lp-sec"<?= $hasCur || count($cur) === 1 ? ' open' : '' ?>>
        <summary><span class="sp-chev" aria-hidden="true"></span><b><?= sh_e($s['title'] !== '' ? $s['title'] : 'Lessons') ?></b><small><?= $owner ? $sDone . '/' . count($s['lessons']) : count($s['lessons']) ?></small></summary>
        <?php foreach ($s['lessons'] as $l): $ok = $can($l); $on = $lesson && (int)$lesson['id'] === (int)$l['id']; $isDone = isset($done[$l['id']]);
          $cls = 'lp-li' . ($on ? ' on' : '') . ($isDone ? ' done' : '') . ($ok ? '' : ' locked'); ?>
          <a class="<?= $cls ?>" href="<?= sh_e($ok ? $base . '&l=' . (int)$l['id'] : sh_url($p) . '#buy') ?>"<?= $ok ? '' : ' title="Buy the course to open this lesson"' ?>>
            <span class="lp-st"><?= $ico(!$ok ? 'lock' : ($isDone ? 'check' : (($l['kind'] ?? 'video') === 'pdf' ? 'file' : ($on ? 'playsolid' : 'play')))) ?></span>
            <span class="lp-lt"><?= sh_e($l['title']) ?><small><?= $l['duration'] ? sh_e($l['duration']) : '' ?><?= $l['free'] && !$owner ? ($l['duration'] ? ' · ' : '') . '<em>Free</em>' : '' ?></small></span></a>
        <?php endforeach; ?>
      </details>
      <?php endforeach; ?>
      <?php if ($files): ?>
      <details class="lp-sec"<?= $file || !$cur ? ' open' : '' ?>>
        <summary><span class="sp-chev" aria-hidden="true"></span><b><?= $isCourse ? 'PDF notes' : 'Files' ?></b><small><?= count($files) ?></small></summary>
        <?php foreach ($files as $f): $ok = $can($f); $on = $file && (int)$file['id'] === (int)$f['id']; ?>
          <a class="lp-li<?= $on ? ' on' : '' ?><?= $ok ? '' : ' locked' ?>" href="<?= sh_e($ok ? $base . '&f=' . (int)$f['id'] : sh_url($p) . '#buy') ?>">
            <span class="lp-st"><?= $ico($ok ? 'file' : 'lock') ?></span><span class="lp-lt"><?= sh_e($f['title']) ?><small><?= $f['pages'] ? (int)$f['pages'] . ' pages' : 'PDF' ?><?= $f['free'] && !$owner ? ' · <em>Free</em>' : '' ?></small></span></a>
        <?php endforeach; ?>
      </details>
      <?php endif; ?>
    </div>
  </aside>
</div>
<?php if ($file || ($lesson && ($lesson['kind'] ?? '') === 'pdf')): ?><script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js" defer></script><?php endif; ?>
<?php require ROOT_PATH . '/components/store-close.php'; ?>
