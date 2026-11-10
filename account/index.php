<?php
// My Courses: everything the student bought or was given (courses and notes, with progress).
//   /account/   (old links ?tab=orders / ?tab=profile go to their own pages)
require_once dirname(__DIR__) . '/includes/config.php';
require_once INCLUDES_PATH . '/shop.php';
if (in_array($_GET['tab'] ?? '', ['orders', 'profile'], true)) { header('Location: /account/' . $_GET['tab'] . (isset($_GET['saved']) ? '?saved=1' : '')); exit; }
$pdo = sh_db();
$u = sh_need_login();
$q = $pdo->prepare("SELECT p.*, a.expires_at, a.source,
    (SELECT COUNT(*) FROM sh_lessons l WHERE l.product_id = p.id) AS lessons,
    (SELECT COUNT(*) FROM sh_lessons l JOIN sh_progress g ON g.lesson_id = l.id AND g.user_id = a.user_id WHERE l.product_id = p.id) AS done,
    (SELECT COUNT(*) FROM sh_files f WHERE f.product_id = p.id) AS files
    FROM sh_access a JOIN sh_products p ON p.id = a.product_id
    WHERE a.user_id = ? AND a.revoked = 0 AND (a.expires_at IS NULL OR a.expires_at > NOW()) ORDER BY a.granted_at DESC");
$q->execute([$u['id']]);
$mine = $q->fetchAll(PDO::FETCH_ASSOC);
$courses = array_values(array_filter($mine, fn($p) => $p['type'] === 'course'));
$notes = array_values(array_filter($mine, fn($p) => $p['type'] === 'notes'));
$stTitle = 'My Courses';
require ROOT_PATH . '/components/store-open.php';
$ico = fn($n) => sh_icon($n);
$card = function (array $p) use ($ico) {
    $pct = (int)$p['lessons'] ? (int)round((int)$p['done'] * 100 / (int)$p['lessons']) : 0;
    $isC = $p['type'] === 'course';
    return '<a class="ac-cc" href="' . sh_e(sh_learn_url($p['slug'])) . '">' . preg_replace('#<span class="st-type">.*?</span>#', '', sh_thumb_html($p)) // no "Video Course" tag — the heading already says it
        . '<div class="ac-cc-b"><h3>' . sh_e($p['title']) . '</h3>'
        . ($isC && (int)$p['lessons'] ? '<div class="ac-pg"><i style="width:' . $pct . '%"></i></div><span class="ac-pt">' . $pct . '% complete · ' . (int)$p['done'] . '/' . (int)$p['lessons'] . ' videos</span>' : '<span class="ac-pt">' . (int)$p['files'] . ' PDF' . ((int)$p['files'] === 1 ? '' : 's') . '</span>')
        . '<span class="ac-exp">' . $ico($p['expires_at'] ? 'calendar' : 'infinity') . ($p['expires_at'] ? 'Access till ' . date('d M Y', strtotime($p['expires_at'])) : 'Lifetime access') . '</span>'
        . '<span class="ac-go">' . ($isC ? ($pct ? 'Continue' : 'Start learning') : 'Open notes') . ' ›</span></div></a>';
};
?>
<div class="ac">
  <?= sh_account_nav($u, '') ?>
  <div class="ac-main">
    <?php if (isset($_GET['welcome'])): ?><div class="st-msg ok">Welcome, <?= sh_e($u['name']) ?>! Your account is ready.</div><?php endif; ?>
    <div class="ac-h ac-h-sr"><h1>My Courses</h1></div>
    <?php if (!$mine): ?>
      <div class="ac-empty"><span class="ac-empty-i"><?= $ico('book') ?></span><b>You haven't joined any course yet</b><p>Start learning today — recorded courses and PDF notes from our teachers are under Courses in the menu.</p></div>
    <?php else: ?>
      <?php if ($courses): ?><h2 class="ac-sub">Video courses <small><?= count($courses) ?></small></h2><div class="ac-grid"><?php foreach ($courses as $p) echo $card($p); ?></div><?php endif; ?>
      <?php if ($notes): ?><h2 class="ac-sub">PDF notes <small><?= count($notes) ?></small></h2><div class="ac-grid"><?php foreach ($notes as $p) echo $card($p); ?></div><?php endif; ?>
    <?php endif; ?>

  </div>
</div>
<?php require ROOT_PATH . '/components/store-close.php'; ?>
