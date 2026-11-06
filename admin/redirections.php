<?php
// Redirections — send an old address or a post to another address (301 permanent / 302 / 307 temporary), for all
// visitors or only some countries (Cloudflare tells the country). Also: 404 monitor (addresses that were not found)
// and a redirect for every missing page. Rules are written to cache/.redirect-rules.json and checked by
// includes/redirects.php at the very start of each page.
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/stats.php';
$me = admin_guard(['admin']);

const RD_COUNTRIES = ['IN' => 'India', 'US' => 'United States', 'GB' => 'United Kingdom', 'CA' => 'Canada', 'AU' => 'Australia', 'NZ' => 'New Zealand', 'AE' => 'UAE', 'SA' => 'Saudi Arabia', 'QA' => 'Qatar', 'KW' => 'Kuwait', 'OM' => 'Oman', 'BH' => 'Bahrain', 'NP' => 'Nepal', 'BD' => 'Bangladesh', 'PK' => 'Pakistan', 'LK' => 'Sri Lanka', 'BT' => 'Bhutan', 'MV' => 'Maldives', 'SG' => 'Singapore', 'MY' => 'Malaysia', 'ID' => 'Indonesia', 'PH' => 'Philippines', 'VN' => 'Vietnam', 'TH' => 'Thailand', 'JP' => 'Japan', 'KR' => 'South Korea', 'CN' => 'China', 'HK' => 'Hong Kong', 'RU' => 'Russia', 'DE' => 'Germany', 'FR' => 'France', 'IT' => 'Italy', 'ES' => 'Spain', 'NL' => 'Netherlands', 'IE' => 'Ireland', 'SE' => 'Sweden', 'CH' => 'Switzerland', 'TR' => 'Turkey', 'EG' => 'Egypt', 'BR' => 'Brazil', 'MX' => 'Mexico', 'ZA' => 'South Africa', 'NG' => 'Nigeria', 'KE' => 'Kenya'];

function rd_load(): array
{
    $s = edu_settings('redirections');
    if (!isset($s['items'])) {
        // First visit: bring over the old Country Redirection rules and the old SEO "redirect 404" option.
        $items = [];
        foreach ((array)(edu_settings('redirects')['rules'] ?? []) as $r) {
            if (empty($r['country']) || empty($r['url'])) continue;
            $items[] = ['id' => substr(md5(uniqid('', true)), 0, 10), 'match' => 'content', 'source' => '', 'post' => 0, 'to_type' => 'url', 'to_post' => 0, 'to' => $r['url'], 'code' => 302, 'cc' => [$r['country']], 'active' => !empty($r['active']) ? 1 : 0, 'note' => 'Country redirect', 'created' => date('c')];
        }
        $seo = edu_settings('seo');
        $s = ['items' => $items, 'n404' => ['on' => ($seo['redirect_404'] ?? '0') === '1' ? 1 : 0, 'to' => $seo['redirect_404_to'] ?? '/', 'code' => 301]];
        edu_settings_save('redirections', $s);
        rd_write($s);
    }
    return $s;
}

/** Writes the rules file the site reads (post addresses are looked up now, so a moved post stays correct after a save). */
function rd_write(array $s): void
{
    global $pdo;
    $base = rtrim(SITE_URL, '/');
    $rules = [];
    foreach ($s['items'] as $r) {
        $to = $r['to'];
        if (($r['to_type'] ?? 'url') === 'post' && $r['to_post']) {
            $st = $pdo->prepare("SELECT slug FROM posts WHERE id = ?");
            $st->execute([$r['to_post']]);
            $slug = $st->fetchColumn();
            if ($slug === false) continue;
            $to = $base . postUrl($slug, (int)$r['to_post']);
        }
        $rules[] = ['id' => $r['id'], 'match' => $r['match'], 'source' => $r['source'], 'post' => (int)$r['post'], 'to' => $to, 'code' => (int)$r['code'], 'cc' => array_values($r['cc'] ?? []), 'active' => (int)$r['active']];
    }
    $n = $s['n404'] ?? [];
    $out = ['rules' => $rules, '404' => ['on' => (int)($n['on'] ?? 0), 'to' => (string)($n['to'] ?? '/'), 'code' => (int)($n['code'] ?? 301)]];
    $f = ROOT_PATH . '/cache/.redirect-rules.json';
    if (!is_dir(dirname($f))) mkdir(dirname($f), 0755, true);
    file_put_contents($f, json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    @unlink(ROOT_PATH . '/cache/.redirects.json'); // the old country-only file
    edu_clear_page_cache();
}

function rd_json(array $d): void
{
    header('Content-Type: application/json');
    echo json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$s = rd_load();
$ajax = $_GET['ajax'] ?? '';
if ($ajax === 'posts') {
    $q = trim((string)($_GET['q'] ?? ''));
    $st = $pdo->prepare("SELECT id, title, slug, status FROM posts WHERE (? = '' OR title LIKE ? OR id = ?) ORDER BY date DESC LIMIT 20");
    $st->execute([$q, "%$q%", ctype_digit($q) ? (int)$q : 0]);
    rd_json(array_map(fn($p) => ['id' => (int)$p['id'], 'title' => $p['title'], 'url' => postUrl($p['slug'], (int)$p['id']), 'status' => $p['status']], $st->fetchAll(PDO::FETCH_ASSOC)));
}
if ($ajax === 'cf') {
    // Is the site behind Cloudflare's proxy, and does Cloudflare send the visitor's country?
    $host = parse_url(SITE_URL, PHP_URL_HOST);
    $trace = '';
    $ch = curl_init('https://' . $host . '/cdn-cgi/trace');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false]);
    $trace = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $proxied = $code === 200 && str_contains($trace, 'colo=') && str_contains($trace, 'fl=');
    preg_match('/colo=(\w+)/', $trace, $colo);
    $cc = strtoupper((string)($_SERVER['HTTP_CF_IPCOUNTRY'] ?? ''));
    rd_json(['proxied' => $proxied, 'via_cf' => !empty($_SERVER['HTTP_CF_RAY']), 'country' => $cc, 'country_name' => $cc ? edu_country_name($cc) : '', 'flag' => $cc ? edu_country_flag($cc) : '', 'colo' => $colo[1] ?? '', 'ip' => $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';
    $tab = 'rules';
    try {
        if ($act === 'save') {
            $id = preg_replace('/[^a-f0-9]/', '', (string)($_POST['id'] ?? ''));
            $match = in_array($_POST['match'] ?? '', ['url', 'post', 'content'], true) ? $_POST['match'] : 'url';
            $source = '';
            $post = 0;
            if ($match === 'url') {
                $source = trim((string)($_POST['source'] ?? ''));
                if (preg_match('#^https?://#i', $source)) $source = (string)parse_url($source, PHP_URL_PATH) . (($q = parse_url($source, PHP_URL_QUERY)) ? "?$q" : '');
                $source = '/' . ltrim($source, '/');
                if ($source === '/' || $source === '/*') throw new RuntimeException('Enter the old address to redirect (not the homepage). Use “All posts & pages” for whole-site country rules.');
                if (preg_match('#^/(admin|api|assets|uploads|includes)(/|$)#i', $source)) throw new RuntimeException('Admin, API and file folders cannot be redirected.');
            } elseif ($match === 'post') {
                $post = (int)($_POST['post'] ?? 0);
                if (!$post) throw new RuntimeException('Choose the post to redirect.');
            }
            $toType = ($_POST['to_type'] ?? '') === 'post' ? 'post' : 'url';
            $toPost = (int)($_POST['to_post'] ?? 0);
            $to = trim((string)($_POST['to'] ?? ''));
            if ($toType === 'post') { if (!$toPost) throw new RuntimeException('Choose the post to send visitors to.'); $to = ''; }
            else {
                if ($to !== '' && $to[0] === '/') $to = rtrim(SITE_URL, '/') . $to;
                if (!filter_var($to, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $to)) throw new RuntimeException('Enter the new address (a full https:// link or a path starting with /).');
            }
            if ($match === 'post' && $toType === 'post' && $toPost === $post) throw new RuntimeException('A post cannot redirect to itself.');
            $cc = ($_POST['traffic'] ?? 'all') === 'countries' ? array_values(array_intersect(array_map('strtoupper', (array)($_POST['cc'] ?? [])), array_keys(RD_COUNTRIES))) : [];
            if (($_POST['traffic'] ?? 'all') === 'countries' && !$cc) throw new RuntimeException('Choose at least one country, or apply the rule to all visitors.');
            if ($match === 'content' && !$cc) throw new RuntimeException('“All posts & pages” is meant for country rules — choose the countries.');
            $item = ['id' => $id ?: substr(md5(uniqid('', true)), 0, 10), 'match' => $match, 'source' => $source, 'post' => $post, 'to_type' => $toType, 'to_post' => $toPost, 'to' => $to,
                'code' => in_array((int)($_POST['code'] ?? 301), [301, 302, 307], true) ? (int)$_POST['code'] : 301, 'cc' => $cc, 'active' => !empty($_POST['active']) ? 1 : 0,
                'note' => mb_substr(trim((string)($_POST['note'] ?? '')), 0, 120), 'created' => date('c')];
            $found = false;
            foreach ($s['items'] as $i => $r) if ($r['id'] === $item['id']) { $item['created'] = $r['created'] ?? $item['created']; $s['items'][$i] = $item; $found = true; }
            if (!$found) array_unshift($s['items'], $item);
            admin_flash($found ? 'Redirect saved.' : 'Redirect added — it works right away.');
            admin_log('redirect_save', ($found ? 'Edited' : 'Added') . ' redirect: ' . ($match === 'url' ? $source : ($match === 'post' ? "post #$post" : 'all posts & pages')) . ' → ' . ($toType === 'post' ? "post #$toPost" : $to) . ($cc ? ' (' . implode(',', $cc) . ')' : ''));
        } elseif ($act === 'toggle' || $act === 'delete') {
            foreach ($s['items'] as $i => $r) if ($r['id'] === ($_POST['id'] ?? '')) { if ($act === 'delete') unset($s['items'][$i]); else $s['items'][$i]['active'] = empty($r['active']) ? 1 : 0; }
            $s['items'] = array_values($s['items']);
            if (!empty($_POST['ajax'])) { edu_settings_save('redirections', $s); rd_write($s); rd_json(['ok' => true]); }
            admin_flash($act === 'delete' ? 'Redirect deleted.' : 'Redirect updated.');
            admin_log('redirect_save', ucfirst($act) . 'd a redirect');
        } elseif ($act === 'n404') {
            $tab = '404';
            $to = trim((string)($_POST['to'] ?? '/'));
            if ($to === '' ) $to = '/';
            if ($to[0] !== '/' && !filter_var($to, FILTER_VALIDATE_URL)) throw new RuntimeException('Enter a path like / or a full https:// address.');
            $s['n404'] = ['on' => !empty($_POST['on']) ? 1 : 0, 'to' => $to, 'code' => in_array((int)($_POST['code'] ?? 301), [301, 302], true) ? (int)$_POST['code'] : 301];
            admin_flash('404 settings saved.');
            admin_log('redirect_save', '404 redirect ' . ($s['n404']['on'] ? 'on → ' . $to : 'off'));
        } elseif ($act === 'clear404') {
            $tab = 'monitor';
            @unlink(ROOT_PATH . '/cache/.404-log.json');
            admin_flash('404 log cleared.');
        }
        edu_settings_save('redirections', $s);
        rd_write($s);
    } catch (RuntimeException $e) {
        admin_flash($e->getMessage(), 'error');
    }
    header('Location: /admin/redirections.php?tab=' . $tab);
    exit;
}

$hits = json_decode((string)@file_get_contents(ROOT_PATH . '/cache/.redirect-hits.json'), true) ?: [];
$log404 = json_decode((string)@file_get_contents(ROOT_PATH . '/cache/.404-log.json'), true) ?: [];
uasort($log404, fn($a, $b) => $b['t'] <=> $a['t']);
$postTitles = [];
$ids = array_filter(array_merge(array_column($s['items'], 'post'), array_column($s['items'], 'to_post')));
if ($ids) foreach ($pdo->query("SELECT id, title, slug FROM posts WHERE id IN (" . implode(',', array_map('intval', $ids)) . ")") as $p) $postTitles[(int)$p['id']] = $p;
$active = count(array_filter($s['items'], fn($r) => !empty($r['active'])));
$totalHits = array_sum(array_map(fn($h) => (int)$h['n'], $hits));
$tab = in_array($_GET['tab'] ?? '', ['rules', 'monitor', '404'], true) ? $_GET['tab'] : 'rules';
$esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$jsItems = array_map(function ($r) use ($postTitles) {
    $r['post_title'] = $postTitles[$r['post']]['title'] ?? '';
    $r['to_post_title'] = $postTitles[$r['to_post']]['title'] ?? '';
    return $r;
}, $s['items']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Redirections - <?= $esc(edu_site_name()) ?></title>
<?php admin_shell_head(); ?>
<style>
.rd-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px}
.rd-stat{background:#fff;border:1px solid var(--gray-200);border-radius:12px;padding:14px 16px}.rd-stat span{font-size:.78rem;color:var(--gray-500)}.rd-stat b{display:block;font-size:1.35rem;color:var(--gray-900)}
.rd-cf{display:flex;align-items:center;gap:14px;background:#fff;border:1px solid var(--gray-200);border-radius:12px;padding:14px 16px;margin-bottom:16px;flex-wrap:wrap}
.rd-cf .ic{width:40px;height:40px;border-radius:10px;background:#fff7ed;color:#f97316;display:grid;place-items:center;font-size:18px;flex-shrink:0}
.rd-cf b{display:block;color:var(--gray-900)}.rd-cf small{color:var(--gray-500)}
.rd-cf-res{display:flex;gap:8px;flex-wrap:wrap;margin-left:auto;align-items:center}
.rd-chip{display:inline-flex;align-items:center;gap:6px;padding:5px 10px;border-radius:99px;font-size:.78rem;font-weight:600;background:var(--gray-100);color:var(--gray-600)}
.rd-chip.ok{background:#dcfce7;color:#166534}.rd-chip.bad{background:#fee2e2;color:#b91c1c}.rd-chip.warn{background:#fef3c7;color:#92400e}
.rd-tabs{display:flex;gap:4px;border-bottom:1px solid var(--gray-200);margin-bottom:14px}
.rd-tabs a{padding:10px 14px;font-size:.875rem;font-weight:600;color:var(--gray-500);text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-1px}
.rd-tabs a.on{color:var(--primary);border-bottom-color:var(--primary)}.rd-tabs a em{font-style:normal;font-size:.72rem;background:var(--gray-100);border-radius:99px;padding:1px 7px;margin-left:4px;color:var(--gray-600)}
.rd-src{font:13px ui-monospace,Menlo,monospace;color:var(--gray-800);overflow-wrap:anywhere}
.rd-to{font-size:.82rem;color:var(--primary);overflow-wrap:anywhere;text-decoration:none}
.rd-kind{display:inline-block;font-size:.65rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em;padding:2px 6px;border-radius:4px;background:var(--gray-100);color:var(--gray-600);margin-bottom:3px}
.rd-code{font-weight:700;font-size:.78rem;padding:3px 8px;border-radius:6px;background:#eef2ff;color:#4338ca}
.rd-cc{font-size:1.05rem;letter-spacing:2px}
.rd-toggle{position:relative;display:inline-block;width:38px;height:22px}.rd-toggle input{opacity:0;width:0;height:0}
.rd-toggle span{position:absolute;inset:0;background:var(--gray-300,#d1d5db);border-radius:99px;cursor:pointer;transition:.2s}.rd-toggle span::before{content:"";position:absolute;width:16px;height:16px;left:3px;top:3px;background:#fff;border-radius:50%;transition:.2s}
.rd-toggle input:checked+span{background:#10b981}.rd-toggle input:checked+span::before{transform:translateX(16px)}
.rd-seg{display:flex;border:1px solid var(--gray-200);border-radius:10px;overflow:hidden;margin-bottom:10px}
.rd-seg label{flex:1;text-align:center;padding:9px 6px;font-size:.82rem;font-weight:600;color:var(--gray-600);cursor:pointer;border-right:1px solid var(--gray-200);margin:0}
.rd-seg label:last-child{border-right:0}.rd-seg input{display:none}.rd-seg label:has(input:checked){background:var(--primary);color:#fff}
.rd-pick{position:relative}.rd-pick-list{position:absolute;left:0;right:0;top:100%;background:#fff;border:1px solid var(--gray-200);border-radius:10px;box-shadow:0 10px 25px rgba(0,0,0,.1);max-height:240px;overflow-y:auto;z-index:5;display:none}
.rd-pick-list.open{display:block}.rd-pick-list button{display:block;width:100%;text-align:left;border:0;background:#fff;padding:9px 12px;font:inherit;font-size:.85rem;cursor:pointer;border-bottom:1px solid var(--gray-100)}
.rd-pick-list button:hover{background:var(--gray-50,#f9fafb)}.rd-pick-list small{display:block;color:var(--gray-400);font-size:.72rem}
.rd-chosen{display:flex;align-items:center;gap:8px;background:var(--primary-lighter,#f5f3ff);border:1px solid var(--primary-light,#ede9fe);border-radius:9px;padding:8px 10px;font-size:.85rem;margin-top:6px}.rd-chosen span{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.rd-chosen button{border:0;background:none;color:var(--gray-500);cursor:pointer}
.rd-ccs{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:6px;max-height:200px;overflow-y:auto;border:1px solid var(--gray-200);border-radius:10px;padding:8px;margin-top:6px}
.rd-ccs label{display:flex;align-items:center;gap:6px;font-size:.82rem;font-weight:500;margin:0;cursor:pointer;padding:3px 4px;border-radius:6px}.rd-ccs label:hover{background:var(--gray-50,#f9fafb)}
.rd-help{font-size:.78rem;color:var(--gray-500);margin-top:4px}
@media (max-width:900px){.rd-stats{grid-template-columns:repeat(2,1fr)}.rd-hide{display:none}.rd-cf-res{margin-left:0}}
</style>
</head>
<body>
<?php admin_shell_open('Redirections', 'Send old addresses and posts to new ones', '<button type="button" class="btn btn-primary" onclick="RD.open()"><i class="fas fa-plus"></i> <span class="hide-mobile">Add Redirect</span></button>'); ?>
<?= admin_flash() ?>
<div class="rd-stats">
  <div class="rd-stat"><span>Redirects</span><b><?= count($s['items']) ?></b></div>
  <div class="rd-stat"><span>Active</span><b style="color:#16a34a"><?= $active ?></b></div>
  <div class="rd-stat"><span>Times used</span><b style="color:#2563eb"><?= number_format($totalHits) ?></b></div>
  <div class="rd-stat"><span>404 addresses seen</span><b style="color:#d97706"><?= count($log404) ?></b></div>
</div>
<div class="rd-cf">
  <span class="ic"><i class="fab fa-cloudflare"></i></span>
  <div><b>Cloudflare connection</b><small>Country rules need Cloudflare’s proxy (orange cloud) and “IP Geolocation” switched on.</small></div>
  <div class="rd-cf-res" id="cfRes"><button type="button" class="btn btn-secondary btn-sm" id="cfTest"><i class="fas fa-plug"></i> Test connection</button></div>
</div>
<div class="rd-tabs">
  <a href="?tab=rules" class="<?= $tab === 'rules' ? 'on' : '' ?>">Redirects<em><?= count($s['items']) ?></em></a>
  <a href="?tab=monitor" class="<?= $tab === 'monitor' ? 'on' : '' ?>">404 Monitor<em><?= count($log404) ?></em></a>
  <a href="?tab=404" class="<?= $tab === '404' ? 'on' : '' ?>">404 Redirect<?= !empty($s['n404']['on']) ? '<em style="background:#dcfce7;color:#166534">On</em>' : '' ?></a>
</div>

<?php if ($tab === 'rules'): ?>
<div class="table-wrap"><div class="table-responsive"><table>
  <thead><tr><th>From</th><th>To</th><th>Type</th><th class="rd-hide">Visitors</th><th class="rd-hide">Hits</th><th>On</th><th>Actions</th></tr></thead>
  <tbody>
  <?php if (!$s['items']): ?><tr><td colspan="7"><div class="empty-state"><i class="fas fa-route"></i><p>No redirects yet. Click “Add Redirect” to send an old address or a post somewhere else.</p></div></td></tr><?php endif; ?>
  <?php foreach ($s['items'] as $r): $h = $hits[$r['id']] ?? null; ?>
  <tr>
    <td><?php if ($r['match'] === 'post'): ?><span class="rd-kind">Post</span><div class="rd-src" style="font-family:inherit"><?= $esc($postTitles[$r['post']]['title'] ?? 'Deleted post #' . $r['post']) ?></div>
      <?php elseif ($r['match'] === 'content'): ?><span class="rd-kind">All posts &amp; pages</span>
      <?php else: ?><span class="rd-kind">URL</span><div class="rd-src"><?= $esc($r['source']) ?></div><?php endif; ?>
      <?php if (!empty($r['note'])): ?><small style="color:var(--gray-400)"><?= $esc($r['note']) ?></small><?php endif; ?></td>
    <td><?php if (($r['to_type'] ?? 'url') === 'post'): $tp = $postTitles[$r['to_post']] ?? null; ?><span class="rd-kind">Post</span><div><?php if ($tp): ?><a class="rd-to" href="<?= $esc(postUrl($tp['slug'], (int)$r['to_post'])) ?>" target="_blank"><?= $esc($tp['title']) ?></a><?php else: ?><span style="color:#b91c1c">Deleted post</span><?php endif; ?></div>
      <?php else: ?><a class="rd-to" href="<?= $esc($r['to']) ?>" target="_blank" rel="noopener"><?= $esc($r['to']) ?></a><?php endif; ?></td>
    <td><span class="rd-code"><?= (int)$r['code'] ?></span></td>
    <td class="rd-hide"><?php if ($r['cc']): ?><span class="rd-cc" title="<?= $esc(implode(', ', array_map(fn($c) => RD_COUNTRIES[$c] ?? $c, $r['cc']))) ?>"><?= implode('', array_map('edu_country_flag', array_slice($r['cc'], 0, 6))) ?><?= count($r['cc']) > 6 ? ' +' . (count($r['cc']) - 6) : '' ?></span><?php else: ?><span style="color:var(--gray-500);font-size:.82rem">All visitors</span><?php endif; ?></td>
    <td class="rd-hide"><?= $h ? number_format($h['n']) . '<br><small style="color:var(--gray-400)">' . date('j M', $h['t']) . '</small>' : '<span style="color:var(--gray-400)">0</span>' ?></td>
    <td><label class="rd-toggle"><input type="checkbox" data-toggle="<?= $esc($r['id']) ?>" <?= $r['active'] ? 'checked' : '' ?>><span></span></label></td>
    <td><div class="row-actions" style="display:flex;gap:6px"><button type="button" class="btn-action btn-edit" onclick="RD.open('<?= $esc($r['id']) ?>')"><i class="fas fa-edit"></i></button>
      <form method="POST" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $esc($r['id']) ?>"><button class="btn-action btn-delete" data-confirm="Delete this redirect? Visitors will see the old address again." data-confirm-title="Delete redirect" data-confirm-ok="Delete"><i class="fas fa-trash"></i></button></form></div></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table></div></div>

<?php elseif ($tab === 'monitor'): ?>
<div class="table-wrap"><div style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 16px;border-bottom:1px solid var(--gray-100);flex-wrap:wrap"><small style="color:var(--gray-500)">Addresses visitors opened that don’t exist — redirect the ones that matter.</small><?php if ($log404): ?><form method="POST" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="clear404"><button class="btn btn-secondary btn-sm" data-confirm="Clear the 404 log?" data-confirm-title="Clear log" data-confirm-ok="Clear"><i class="fas fa-broom"></i> Clear log</button></form><?php endif; ?></div>
<div class="table-responsive"><table>
  <thead><tr><th>Address</th><th>Hits</th><th class="rd-hide">Last seen</th><th class="rd-hide">Came from</th><th></th></tr></thead>
  <tbody>
  <?php if (!$log404): ?><tr><td colspan="5"><div class="empty-state"><i class="fas fa-check-circle"></i><p>No missing pages recorded.</p></div></td></tr><?php endif; ?>
  <?php foreach (array_slice($log404, 0, 200, true) as $path => $l): ?>
  <tr><td class="rd-src"><?= $esc($path) ?></td><td><?= (int)$l['n'] ?></td><td class="rd-hide" style="white-space:nowrap;font-size:.82rem"><?= date('j M, g:i a', $l['t']) ?></td><td class="rd-hide" style="font-size:.78rem;color:var(--gray-500);overflow-wrap:anywhere"><?= $esc($l['r'] ?: '—') ?></td>
    <td><button type="button" class="btn btn-secondary btn-sm" onclick="RD.open('', <?= $esc(json_encode($path)) ?>)"><i class="fas fa-route"></i> Redirect</button></td></tr>
  <?php endforeach; ?>
  </tbody>
</table></div></div>

<?php else: ?>
<form method="POST" class="table-wrap" style="padding:20px;max-width:640px"><?= csrf_field() ?><input type="hidden" name="action" value="n404">
  <label style="display:flex;gap:10px;align-items:center;font-weight:600;margin-bottom:6px"><span class="rd-toggle"><input type="checkbox" name="on" value="1" <?= !empty($s['n404']['on']) ? 'checked' : '' ?>><span></span></span> Redirect every missing page (404)</label>
  <p class="rd-help" style="margin:0 0 16px">Off = visitors see the site’s own “Page not found” page (recommended for SEO). On = they are sent to the address below.</p>
  <div class="form-group"><label>Send to</label><input class="form-control" name="to" value="<?= $esc($s['n404']['to'] ?? '/') ?>" placeholder="/ or https://…"></div>
  <div class="form-group"><label>Type</label><select class="form-control" name="code"><option value="301"<?= (int)($s['n404']['code'] ?? 301) === 301 ? ' selected' : '' ?>>301 — permanent</option><option value="302"<?= (int)($s['n404']['code'] ?? 301) === 302 ? ' selected' : '' ?>>302 — temporary</option></select></div>
  <button class="btn btn-primary"><i class="fas fa-save"></i> Save</button>
</form>
<?php endif; ?>

<div class="modal-overlay" id="rdModal"><div class="modal" style="max-width:640px">
  <div class="modal-header"><div class="modal-title"><i class="fas fa-route" style="color:var(--primary);margin-right:.5rem"></i> <span id="rdTitle">Add Redirect</span></div><button type="button" class="modal-close" data-close><i class="fas fa-times"></i></button></div>
  <form method="POST" id="rdForm"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id"><input type="hidden" name="post"><input type="hidden" name="to_post">
    <div class="modal-body">
      <div class="form-group"><label>Redirect from</label>
        <div class="rd-seg"><label><input type="radio" name="match" value="url" checked> An address (URL)</label><label><input type="radio" name="match" value="post"> A post</label><label><input type="radio" name="match" value="content"> All posts &amp; pages</label></div>
        <div data-for="url"><input class="form-control" name="source" placeholder="/old-address  (or paste the full old link)"><div class="rd-help">End with * to redirect everything under it, e.g. /old-section/*</div></div>
        <div data-for="post" class="rd-pick"><input class="form-control" data-pick="post" placeholder="Search posts by title or ID…" autocomplete="off"><div class="rd-pick-list"></div><div class="rd-chosen" data-chosen="post" hidden><i class="fas fa-file-lines"></i><span></span><button type="button" title="Change">✕</button></div></div>
        <div data-for="content" class="rd-help">Every post and page (not the homepage or lists) — for sending visitors from chosen countries elsewhere.</div>
      </div>
      <div class="form-group"><label>Redirect to</label>
        <div class="rd-seg"><label><input type="radio" name="to_type" value="url" checked> An address (URL)</label><label><input type="radio" name="to_type" value="post"> A post</label></div>
        <div data-to="url"><input class="form-control" name="to" placeholder="https://new-address or /new-path"></div>
        <div data-to="post" class="rd-pick"><input class="form-control" data-pick="to_post" placeholder="Search posts by title or ID…" autocomplete="off"><div class="rd-pick-list"></div><div class="rd-chosen" data-chosen="to_post" hidden><i class="fas fa-file-lines"></i><span></span><button type="button" title="Change">✕</button></div></div>
      </div>
      <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div class="form-group"><label>Type</label><select class="form-control" name="code"><option value="301">301 — moved permanently</option><option value="302">302 — temporary</option><option value="307">307 — temporary (keeps method)</option></select></div>
        <div class="form-group"><label>Note <span style="font-weight:400;color:var(--gray-400)">(optional)</span></label><input class="form-control" name="note" maxlength="120" placeholder="Why this redirect exists"></div>
      </div>
      <div class="form-group"><label>Which visitors?</label>
        <div class="rd-seg"><label><input type="radio" name="traffic" value="all" checked> All visitors</label><label><input type="radio" name="traffic" value="countries"> Only some countries</label></div>
        <div id="rdCountries" hidden><input class="form-control" id="rdCcQ" placeholder="Find a country…" style="margin-top:4px"><div class="rd-ccs"><?php foreach (RD_COUNTRIES as $c => $n): ?><label data-name="<?= $esc(strtolower($n . ' ' . $c)) ?>"><input type="checkbox" name="cc[]" value="<?= $c ?>"> <?= edu_country_flag($c) ?> <?= $esc($n) ?></label><?php endforeach; ?></div><div class="rd-help">Uses the visitor’s country from Cloudflare — test the connection above.</div></div>
      </div>
      <label style="display:flex;gap:10px;align-items:center;font-weight:600"><span class="rd-toggle"><input type="checkbox" name="active" value="1" checked><span></span></span> Active</label>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-close>Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Redirect</button></div>
  </form>
</div></div>
<?php admin_shell_close(); ?>
<script>
window.RD = (function () {
  var items = <?= json_encode(array_column($jsItems, null, 'id'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>, token = <?= json_encode(csrf_token()) ?>;
  var m = document.getElementById('rdModal'), f = document.getElementById('rdForm');
  document.body.appendChild(m);
  function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : t; return d.innerHTML; }
  function sync() {
    var mt = f.querySelector('[name=match]:checked').value, tt = f.querySelector('[name=to_type]:checked').value, tr = f.querySelector('[name=traffic]:checked').value;
    f.querySelectorAll('[data-for]').forEach(function (e) { e.hidden = e.dataset.for !== mt; });
    f.querySelectorAll('[data-to]').forEach(function (e) { e.hidden = e.dataset.to !== tt; });
    document.getElementById('rdCountries').hidden = tr !== 'countries';
  }
  f.addEventListener('change', function (e) { if (['match', 'to_type', 'traffic'].indexOf(e.target.name) > -1) sync(); });
  function choose(key, id, title) {
    f[key].value = id || '';
    var box = f.querySelector('[data-chosen="' + key + '"]'), inp = f.querySelector('[data-pick="' + key + '"]');
    box.hidden = !id; inp.hidden = !!id; box.querySelector('span').textContent = title || '';
  }
  f.querySelectorAll('[data-pick]').forEach(function (inp) {
    var list = inp.nextElementSibling, key = inp.dataset.pick, t;
    function load() {
      fetch('/admin/redirections.php?ajax=posts&q=' + encodeURIComponent(inp.value.trim()), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (rows) {
        list.innerHTML = rows.length ? rows.map(function (p) { return '<button type="button" data-id="' + p.id + '" data-title="' + esc(p.title) + '">' + esc(p.title) + '<small>#' + p.id + ' · ' + esc(p.url) + (p.status !== 'published' ? ' · ' + p.status : '') + '</small></button>'; }).join('') : '<button type="button" disabled>No posts found</button>';
        list.classList.add('open');
      });
    }
    inp.addEventListener('focus', load);
    inp.addEventListener('input', function () { clearTimeout(t); t = setTimeout(load, 250); });
    list.addEventListener('mousedown', function (e) { var b = e.target.closest('[data-id]'); if (!b) return; e.preventDefault(); choose(key, b.dataset.id, b.dataset.title); list.classList.remove('open'); });
    inp.addEventListener('blur', function () { setTimeout(function () { list.classList.remove('open'); }, 150); });
    f.querySelector('[data-chosen="' + key + '"] button').onclick = function () { choose(key, '', ''); inp.value = ''; inp.focus(); };
  });
  document.getElementById('rdCcQ').addEventListener('input', function () { var q = this.value.toLowerCase().trim(); f.querySelectorAll('.rd-ccs label').forEach(function (l) { l.hidden = q && l.dataset.name.indexOf(q) < 0; }); });
  function close() { m.classList.remove('open'); document.body.style.overflow = ''; }
  m.addEventListener('click', function (e) { if (e.target === m || e.target.closest('[data-close]')) close(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
  document.querySelectorAll('[data-toggle]').forEach(function (c) {
    c.addEventListener('change', function () { var fd = new FormData(); fd.append('_csrf', token); fd.append('action', 'toggle'); fd.append('id', c.dataset.toggle); fd.append('ajax', '1'); fetch('/admin/redirections.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) { if (!d.ok) c.checked = !c.checked; }); });
  });
  // Cloudflare test
  document.getElementById('cfTest').onclick = function () {
    var b = this, box = document.getElementById('cfRes'); b.disabled = true; b.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Testing…';
    fetch('/admin/redirections.php?ajax=cf', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      var chips = '<span class="rd-chip ' + (d.proxied ? 'ok' : 'bad') + '"><i class="fas ' + (d.proxied ? 'fa-check' : 'fa-xmark') + '"></i> Proxy ' + (d.proxied ? 'on' + (d.colo ? ' (' + esc(d.colo) + ')' : '') : 'off') + '</span>';
      chips += '<span class="rd-chip ' + (d.country ? 'ok' : (d.proxied ? 'warn' : 'bad')) + '"><i class="fas ' + (d.country ? 'fa-check' : 'fa-triangle-exclamation') + '"></i> ' + (d.country ? 'Country: ' + d.flag + ' ' + esc(d.country_name) : 'No country — switch on “IP Geolocation” in Cloudflare → Network') + '</span>';
      box.innerHTML = chips + '<button type="button" class="btn btn-secondary btn-sm" id="cfTest2"><i class="fas fa-rotate"></i></button>';
      document.getElementById('cfTest2').onclick = function () { location.reload(); };
    }).catch(function () { b.disabled = false; b.innerHTML = '<i class="fas fa-plug"></i> Test connection'; adminNotice('The test could not run.', { type: 'error' }); });
  };
  return {
    open: function (id, src) {
      var r = id ? items[id] : null;
      f.reset(); f.id.value = r ? r.id : '';
      document.getElementById('rdTitle').textContent = r ? 'Edit Redirect' : 'Add Redirect';
      f.querySelector('[name=match][value="' + (r ? r.match : 'url') + '"]').checked = true;
      f.querySelector('[name=to_type][value="' + (r ? (r.to_type || 'url') : 'url') + '"]').checked = true;
      f.querySelector('[name=traffic][value="' + (r && r.cc.length ? 'countries' : 'all') + '"]').checked = true;
      f.source.value = r ? r.source : (src || ''); f.to.value = r ? r.to : ''; f.code.value = r ? r.code : 301; f.note.value = r ? (r.note || '') : '';
      f.active.checked = r ? !!+r.active : true;
      f.querySelectorAll('[name="cc[]"]').forEach(function (c) { c.checked = !!(r && r.cc.indexOf(c.value) > -1); });
      choose('post', r && r.post ? r.post : '', r ? r.post_title : ''); choose('to_post', r && r.to_post ? r.to_post : '', r ? r.to_post_title : '');
      sync(); m.classList.add('open'); document.body.style.overflow = 'hidden';
    }
  };
})();
</script>
</body>
</html>
