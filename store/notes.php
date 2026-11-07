<?php
// PDF Notes store — its own section for notes (like the notes shops of big coaching brands): hero, search, free / paid
// filter, notes cards with pages and a free preview link.  /store/notes  (?q=…&f=free|paid)
require_once dirname(__DIR__) . '/includes/config.php';
require_once INCLUDES_PATH . '/shop.php';
$pdo = sh_db();
if (!sh_on()) { http_response_code(404); require ROOT_PATH . '/templates/page_not_found.php'; exit; }
$qq = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 80));
$fl = in_array($_GET['f'] ?? '', ['free', 'paid'], true) ? $_GET['f'] : '';
$w = "p.status = 'published' AND p.type = 'notes'"; $a = [];
if ($qq !== '') { $w .= " AND (p.title LIKE ? OR p.subtitle LIKE ? OR p.description LIKE ?)"; array_push($a, "%$qq%", "%$qq%", "%$qq%"); }
if ($fl === 'free') $w .= ' AND p.price <= 0'; elseif ($fl === 'paid') $w .= ' AND p.price > 0';
$st = $pdo->prepare("SELECT p.*, (SELECT COUNT(*) FROM sh_files f WHERE f.product_id = p.id) AS files, (SELECT COALESCE(SUM(f.pages),0) FROM sh_files f WHERE f.product_id = p.id) AS pages,
    (SELECT f.id FROM sh_files f WHERE f.product_id = p.id AND f.free = 1 ORDER BY f.sort LIMIT 1) AS free_file,
    (SELECT COUNT(*) FROM sh_access x WHERE x.product_id = p.id AND x.revoked = 0) AS buyers,
    (SELECT ROUND(AVG(r.rating),1) FROM sh_reviews r WHERE r.product_id = p.id AND r.status = 'published') AS rating
    FROM sh_products p WHERE $w ORDER BY p.sort, buyers DESC, p.id DESC");
$st->execute($a);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
$tot = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(price <= 0),0) free FROM sh_products WHERE status = 'published' AND type = 'notes'")->fetch(PDO::FETCH_ASSOC);
$u = sh_user();
$owned = [];
if ($u) { $q = $pdo->prepare("SELECT product_id FROM sh_access WHERE user_id = ? AND revoked = 0 AND (expires_at IS NULL OR expires_at > NOW())"); $q->execute([$u['id']]); $owned = array_flip($q->fetchAll(PDO::FETCH_COLUMN)); }
$stTitle = 'PDF Notes';
$stDesc = 'Handwritten-style and typed PDF notes, formula sheets and practice sets from ' . edu_site_name() . ' — read on any phone or computer.';
$stIndex = true;
require ROOT_PATH . '/components/store-open.php';
$ico = fn($n) => sh_icon($n);
$link = fn(array $x) => '/store/notes?' . http_build_query(array_filter(['q' => $qq, 'f' => $fl] + $x, fn($v) => $v !== '' && $v !== null));
?>
<section class="nt-hero"><div class="sp-wrap">
  <nav class="sp-crumb"><a href="/store/">Store</a><span>›</span><span>PDF Notes</span></nav>
  <div class="nt-hero-in">
    <div><span class="sp-kind"><?= $ico('notes') ?> PDF Notes</span>
      <h1>Notes that make revision easy</h1>
      <p class="sp-sub">Chapter-wise notes, formula sheets and practice sets by our teachers. Read them on your phone, tablet or computer — any time.</p>
      <form class="nt-search" method="get" action="/store/notes" role="search"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
        <input name="q" value="<?= sh_e($qq) ?>" placeholder="Search notes — e.g. Maths formulas, Physics" aria-label="Search notes"><?php if ($fl): ?><input type="hidden" name="f" value="<?= sh_e($fl) ?>"><?php endif; ?><button type="submit">Search</button></form>
    </div>
    <div class="nt-stats"><div><b><?= (int)$tot['n'] ?></b><span>Notes packs</span></div><div><b><?= (int)$tot['free'] ?></b><span>Free to read</span></div><div><b><?= $ico('device') ?></b><span>Any device</span></div></div>
  </div>
</div></section>
<div class="sp-wrap nt-body">
  <div class="nt-bar">
    <div class="nt-chips"><a href="<?= sh_e($link(['f' => ''])) ?>" class="<?= $fl === '' ? 'on' : '' ?>">All notes</a><a href="<?= sh_e($link(['f' => 'free'])) ?>" class="<?= $fl === 'free' ? 'on' : '' ?>">Free</a><a href="<?= sh_e($link(['f' => 'paid'])) ?>" class="<?= $fl === 'paid' ? 'on' : '' ?>">Premium</a></div>
    <span class="nt-count"><?= count($rows) ?> notes<?= $qq !== '' ? ' for "' . sh_e($qq) . '"' : '' ?></span>
  </div>
  <?php if (!$rows): ?><div class="ac-empty"><span class="ac-empty-i"><?= $ico('notes') ?></span><b><?= $qq !== '' ? 'No notes found' : 'New notes are coming soon' ?></b><p><?= $qq !== '' ? 'Try another word, or see all notes.' : 'Our teachers are preparing notes — check back soon.' ?></p><?php if ($qq !== '' || $fl): ?><a class="ac-btn" href="/store/notes">See all notes</a><?php endif; ?></div><?php endif; ?>
  <div class="nt-grid">
  <?php foreach ($rows as $p): $own = isset($owned[$p['id']]); $off = $p['mrp'] !== null && (float)$p['mrp'] > (float)$p['price'] ? (int)round(100 - (float)$p['price'] * 100 / (float)$p['mrp']) : 0; ?>
    <article class="nt-card">
      <a class="nt-cover" href="<?= sh_e(sh_url($p)) ?>"><?php if ($p['thumb']): ?><img src="<?= sh_e($p['thumb']) ?>" alt="" loading="lazy"><?php else: ?><span><?= sh_e($p['title']) ?></span><?php endif; ?>
        <?php if ((float)$p['price'] <= 0): ?><em class="nt-tag free">FREE</em><?php elseif ($off): ?><em class="nt-tag"><?= $off ?>% OFF</em><?php endif; ?></a>
      <div class="nt-cb">
        <h3><a href="<?= sh_e(sh_url($p)) ?>"><?= sh_e($p['title']) ?></a></h3>
        <?php if ($p['subtitle']): ?><p><?= sh_e($p['subtitle']) ?></p><?php endif; ?>
        <div class="nt-meta"><span><?= $ico('file') ?> <?= (int)$p['files'] ?> PDF<?= (int)$p['files'] === 1 ? '' : 's' ?></span><?php if ((int)$p['pages']): ?><span><?= (int)$p['pages'] ?> pages</span><?php endif; ?><?php if ($p['rating']): ?><span class="nt-rt">★ <?= number_format((float)$p['rating'], 1) ?></span><?php endif; ?></div>
        <div class="nt-foot">
          <div class="nt-price"><?= $own ? '<span class="st-own">✓ In your library</span>' : ((float)$p['price'] > 0 ? '<b>' . sh_money($p['price']) . '</b>' . ($off ? '<s>' . sh_money($p['mrp']) . '</s>' : '') : '<b class="g">Free</b>') ?></div>
          <?php if ($own): ?><a class="nt-btn" href="/store/learn?s=<?= rawurlencode($p['slug']) ?>">Read</a>
          <?php elseif ($p['free_file']): ?><a class="nt-btn ghost" href="/store/learn?s=<?= rawurlencode($p['slug']) ?>&f=<?= (int)$p['free_file'] ?>">Free preview</a>
          <?php else: ?><a class="nt-btn" href="<?= sh_e(sh_url($p)) ?>"><?= (float)$p['price'] > 0 ? 'Get notes' : 'Read free' ?></a><?php endif; ?>
        </div>
      </div>
    </article>
  <?php endforeach; ?>
  </div>
  <section class="nt-how"><h2>How notes work</h2>
    <div><span>1</span><b>Choose your notes</b><p>Open any pack to see the PDFs inside and read the free preview.</p></div>
    <div><span>2</span><b>Pay securely</b><p>UPI, cards or net banking through Razorpay. Free notes need only an account.</p></div>
    <div><span>3</span><b>Read anywhere</b><p>Your notes open in <a href="/account/">My Courses</a> on any phone or computer.</p></div>
  </section>
</div>
<?php require ROOT_PATH . '/components/store-close.php'; ?>
