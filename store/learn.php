<?php
// Watch a lesson / read a PDF of a course or notes pack the buyer owns (free previews for everyone).
//   /store/learn?s=slug  (&l=lessonId | &f=fileId)
require_once dirname(__DIR__) . '/includes/config.php';
require_once INCLUDES_PATH . '/shop.php';
$pdo = sh_db();
$p = sh_on() || sh_staff() ? sh_product($pdo, (string)($_GET['s'] ?? ''), !sh_staff()) : null; // staff can preview drafts
if (!$p) { http_response_code(404); require ROOT_PATH . '/templates/page_not_found.php'; exit; }
$u = sh_user();
$uid = $u ? (int)$u['id'] : 0;
$owner = sh_has_access($pdo, $uid, (int)$p['id']);
$staff = sh_staff();
$own = $owner || $staff; // staff see everything (preview)
$cur = $p['type'] === 'course' ? sh_curriculum($pdo, (int)$p['id']) : [];
$files = sh_files($pdo, (int)$p['id']);
$all = [];
foreach ($cur as $s) foreach ($s['lessons'] as $l) $all[] = $l;
$lid = (int)($_GET['l'] ?? 0);
$fid = (int)($_GET['f'] ?? 0);
// what to show: the asked lesson / file, else the first lesson (course) or first file (notes)
$lesson = null; $file = null;
if ($fid) foreach ($files as $f) if ((int)$f['id'] === $fid) $file = $f;
if (!$file && $lid) foreach ($all as $l) if ((int)$l['id'] === $lid) $lesson = $l;
if (!$file && !$lesson) { if ($all) $lesson = $all[0]; elseif ($files) $file = $files[0]; }
$item = $lesson ?: $file;
$allowed = $item && ($own || !empty($item['free']));
if (!$own && !$allowed) {
    if (!$u) { header('Location: /account/login?next=' . rawurlencode($_SERVER['REQUEST_URI'])); exit; }
    header('Location: ' . sh_url($p)); exit; // not bought: back to the page with the Buy button
}
$done = [];
if ($uid) { $q = $pdo->prepare("SELECT lesson_id FROM sh_progress WHERE user_id = ? AND done = 1"); $q->execute([$uid]); $done = array_flip($q->fetchAll(PDO::FETCH_COLUMN)); }
$nDone = count(array_filter($all, fn($l) => isset($done[$l['id']])));
$base = '/store/learn?s=' . rawurlencode($p['slug']);
$idx = $lesson ? array_search($lesson['id'], array_column($all, 'id')) : false;
$prev = $idx !== false && $idx > 0 ? $all[$idx - 1] : null;
$next = $idx !== false && $idx < count($all) - 1 ? $all[$idx + 1] : null;
$can = fn($x) => $own || !empty($x['free']);

/** Video link → embed address (YouTube / Vimeo / Bunny / any iframe link). */
function sh_embed(string $url): string
{
    if (preg_match('~(?:youtu\.be/|youtube\.com/(?:watch\?v=|embed/|shorts/|live/))([\w-]{11})~', $url, $m)) return 'https://www.youtube-nocookie.com/embed/' . $m[1] . '?rel=0&modestbranding=1';
    if (preg_match('~vimeo\.com/(?:video/)?(\d+)~', $url, $m)) return 'https://player.vimeo.com/video/' . $m[1];
    return $url;
}
$wm = $u ? ($u['mobile'] . ' · ' . $u['email']) : '';
$stTitle = ($item['title'] ?? $p['title']) . ' — ' . $p['title'];
require ROOT_PATH . '/components/store-open.php';
?>
<p class="st-crumb"><a href="/store">Store</a> › <a href="<?= sh_e(sh_url($p)) ?>"><?= sh_e($p['title']) ?></a><?php if ($u): ?> › <a href="/account/">My Library</a><?php endif; ?></p>
<?php if (isset($_GET['new'])): ?><div class="st-msg ok">Payment successful — <b><?= sh_e($p['title']) ?></b> is now in your library. Enjoy learning!</div><?php endif; ?>
<?php if ($staff && !$owner): ?><div class="st-msg info">Staff preview — you see every video and PDF.</div><?php endif; ?>
<?php if (!$own): ?><div class="st-msg info">This is a free preview. <a href="<?= sh_e(sh_url($p)) ?>">Buy the full <?= $p['type'] === 'course' ? 'course' : 'notes' ?></a> to open everything.</div><?php endif; ?>
<div class="st-learn">
  <div>
  <?php if ($lesson): ?>
    <div class="st-player" id="stPlayer" oncontextmenu="return false">
      <?php if ($lesson['video_file']): ?>
        <?php
        // the original plus any smaller qualities, best first — the ⚙ menu switches between them
        $srcs = [['q' => 'orig', 'h' => (int)($lesson['height'] ?? 0), 'label' => ($lesson['height'] ? (int)$lesson['height'] . 'p' : 'Original'), 'src' => sh_media_link('v', (int)$lesson['id'], $uid)]];
        foreach (sh_variants($lesson) as $qq => $_) if (!$lesson['height'] || (int)$qq < (int)$lesson['height']) $srcs[] = ['q' => (string)$qq, 'h' => (int)$qq, 'label' => $qq . 'p', 'src' => sh_media_link('v', (int)$lesson['id'], $uid, 14400, (string)$qq)];
        ?>
        <video id="stVideo" controls playsinline preload="metadata" controlsList="nodownload" disablePictureInPicture data-srcs="<?= sh_e(json_encode($srcs, JSON_UNESCAPED_SLASHES)) ?>" src="<?= sh_e($srcs[0]['src']) ?>"></video>
        <div class="st-gear"><button type="button" class="st-gear-b" aria-label="Settings: speed and quality" aria-haspopup="true" aria-expanded="false"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68 1.65 1.65 0 0 0 10 3.17V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"/></svg><span class="st-gear-l" id="stGearL">1x</span></button>
          <div class="st-menu" hidden><div class="st-menu-h">Speed</div><div class="st-menu-r" data-speed><?php foreach (['0.5', '0.75', '1', '1.25', '1.5', '1.75', '2'] as $sp): ?><button type="button" data-v="<?= $sp ?>"<?= $sp === '1' ? ' class="on"' : '' ?>><?= $sp === '1' ? 'Normal' : $sp . 'x' ?></button><?php endforeach; ?></div>
          <div class="st-menu-h">Quality</div><div class="st-menu-r" data-quality><button type="button" data-v="auto" class="on">Auto</button><?php foreach ($srcs as $sx): ?><button type="button" data-v="<?= sh_e($sx['q']) ?>"><?= sh_e($sx['label']) ?></button><?php endforeach; ?></div></div></div>
      <?php elseif ($lesson['video_url']): ?>
        <iframe src="<?= sh_e(sh_embed((string)$lesson['video_url'])) ?>" allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture; fullscreen" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>
      <?php else: ?><div style="color:#fff;display:grid;place-items:center;height:100%">The video for this lesson is being uploaded.</div><?php endif; ?>
      <?php if ($wm && $lesson['video_file']): ?><span class="st-wm" id="stWm"><?= sh_e($wm) ?></span><?php endif; ?>
    </div>
    <div class="st-lt"><h1><?= sh_e($lesson['title']) ?></h1>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <?php if ($owner): ?><button type="button" class="st-btn ghost" id="stDone" data-l="<?= (int)$lesson['id'] ?>" data-done="<?= isset($done[$lesson['id']]) ? 1 : 0 ?>" data-t="<?= sh_e(sh_csrf()) ?>"><?= sh_icon('check') ?> <span><?= isset($done[$lesson['id']]) ? 'Completed' : 'Mark as done' ?></span></button><?php endif; ?>
        <?php if ($prev && $can($prev)): ?><a class="st-btn ghost" href="<?= sh_e($base . '&l=' . (int)$prev['id']) ?>">‹ Previous</a><?php endif; ?>
        <?php if ($next && $can($next)): ?><a class="st-btn" href="<?= sh_e($base . '&l=' . (int)$next['id']) ?>">Next ›</a><?php endif; ?>
      </div></div>
    <?php if (trim((string)$lesson['notes']) !== ''): ?><div class="st-box"><div class="st-box-b st-desc"><?= nl2br(sh_e($lesson['notes'])) ?></div></div><?php endif; ?>
  <?php elseif ($file): ?>
    <div class="st-lt"><h1><?= sh_e($file['title']) ?></h1><span class="st-meta" id="stPdfInfo">Loading…</span></div>
    <div class="st-pdfv" id="stPdf" data-src="<?= sh_e(sh_media_link('f', (int)$file['id'], $uid)) ?>" data-wm="<?= sh_e($wm) ?>" oncontextmenu="return false"></div>
  <?php else: ?>
    <div class="st-empty">Nothing has been added here yet. Please check back soon.</div>
  <?php endif; ?>
  </div>

  <aside class="st-side">
    <?php if ($all): ?>
    <div class="st-box-h">Course content <small><?= $nDone ?> / <?= count($all) ?> done</small></div>
    <div class="st-prog"><i style="width:<?= count($all) ? round($nDone * 100 / count($all)) : 0 ?>%"></i></div>
    <?php foreach ($cur as $s): ?><div class="st-sec"><?php if ($s['title'] !== ''): ?><div class="st-sec-h"><span><?= sh_e($s['title']) ?></span></div><?php endif; ?>
      <?php foreach ($s['lessons'] as $l): $ok = $can($l); $on = $lesson && (int)$lesson['id'] === (int)$l['id']; $cls = 'st-li' . ($on ? ' on' : '') . (isset($done[$l['id']]) ? ' done' : ''); ?>
      <?= $ok ? '<a class="' . $cls . '" href="' . sh_e($base . '&l=' . (int)$l['id']) . '">' : '<div class="' . $cls . '">' ?><?= sh_icon(!$ok ? 'lock' : (isset($done[$l['id']]) ? 'check' : 'play')) ?><span class="t"><?= sh_e($l['title']) ?></span><?= $l['duration'] ? '<span class="d">' . sh_e($l['duration']) . '</span>' : '' ?><?= $ok ? '</a>' : '</div>' ?>
      <?php endforeach; ?></div><?php endforeach; ?>
    <?php endif; ?>
    <?php if ($files): ?>
    <div class="st-box-h" style="border-top:1px solid var(--sb)">PDF notes <small><?= count($files) ?></small></div>
    <?php foreach ($files as $f): $ok = $can($f); $on = $file && (int)$file['id'] === (int)$f['id']; ?>
      <?= $ok ? '<a class="st-li' . ($on ? ' on' : '') . '" href="' . sh_e($base . '&f=' . (int)$f['id']) . '">' : '<div class="st-li">' ?><?= sh_icon($ok ? 'file' : 'lock') ?><span class="t"><?= sh_e($f['title']) ?></span><?= $ok ? '</a>' : '</div>' ?>
    <?php endforeach; ?>
    <?php endif; ?>
  </aside>
</div>
<?php if ($file): ?><script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js" defer></script><?php endif; ?>
<?php require ROOT_PATH . '/components/store-close.php'; ?>
