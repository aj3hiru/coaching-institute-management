<?php
// Customize — the theme editor with live preview (same layout and controls as the new CMS Customizer).
// Changes go to a draft in the admin's session (the preview shows it via ?czp=1); "Publish" saves them for everyone.
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/theme.php';
require_once INCLUDES_PATH . '/shop.php';
$czMe = admin_guard(['admin', 'blog_admin']);
$czBlog = $czMe['role'] === 'blog_admin'; // Blog Admin: only Sidebar, Post Template, Also Read, Reading Progress, Blog & Archives
const CZ_BLOG_PANELS = ['sidebar', 'post', 'alsoread', 'progress', 'home'];
const CZ_BLOG_KEYS = '/^(sb_|p_|ar_|rp_|home_|arch_)/';

$identityKeys = ['site_title', 'tagline', 'logo', 'logo_w', 'header_logo', 'favicon'];

// Lookups for the Menus "Add Items" panel and the Also Read post picker
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (isset($_GET['src']) || isset($_GET['posts_brief']) || isset($_GET['titles']))) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $q = trim((string)($_GET['q'] ?? $_GET['posts_brief'] ?? ''));
    $like = '%' . $q . '%';
    try {
        if (isset($_GET['titles'])) {
            $ids = array_slice(array_values(array_filter(array_map('intval', explode(',', (string)$_GET['titles'])))), 0, 50);
            $out = [];
            if ($ids) foreach ($pdo->query("SELECT id, title FROM posts WHERE id IN (" . implode(',', $ids) . ")") as $r) $out[(int)$r['id']] = $r['title'];
            echo json_encode((object)$out, JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (isset($_GET['posts_brief'])) {
            $st = $pdo->prepare("SELECT id, title FROM posts WHERE status = 'published'" . ($q !== '' ? " AND title LIKE ?" : '') . " ORDER BY date DESC LIMIT 20");
            $st->execute($q !== '' ? [$like] : []);
            echo json_encode(array_map(fn($r) => ['id' => (int)$r['id'], 'title' => $r['title']], $st->fetchAll(PDO::FETCH_ASSOC)), JSON_UNESCAPED_UNICODE);
            exit;
        }
        $out = [];
        $src = (string)$_GET['src'];
        if ($src === 'pages') {
            if ($q === '' || stripos('home front page', $q) !== false) $out[] = ['label' => 'Home', 'url' => '/', 'kind' => 'Front Page'];
            $st = $pdo->prepare("SELECT title, slug FROM site_pages WHERE status = 'published'" . ($q !== '' ? " AND title LIKE ?" : '') . " ORDER BY title LIMIT 40");
            $st->execute($q !== '' ? [$like] : []);
            foreach ($st as $r) $out[] = ['label' => $r['title'], 'url' => '/' . $r['slug'], 'kind' => 'Page'];
            foreach ([['Job Categories', '/categories'], ['State Wise Jobs', '/state-jobs'], ['Latest Updates', '/latest-updates']] as [$l, $u]) {
                if ($q === '' || stripos($l, $q) !== false) $out[] = ['label' => $l, 'url' => $u, 'kind' => 'Archive'];
            }
        } elseif ($src === 'posts') {
            $st = $pdo->prepare("SELECT id, title, slug FROM posts WHERE status = 'published'" . ($q !== '' ? " AND title LIKE ?" : '') . " ORDER BY date DESC LIMIT 30");
            $st->execute($q !== '' ? [$like] : []);
            foreach ($st as $r) $out[] = ['label' => $r['title'], 'url' => postUrl($r['slug'], $r['id']), 'kind' => 'Post'];
        } elseif ($src === 'categories') {
            $st = $pdo->prepare("SELECT name, slug FROM categories" . ($q !== '' ? " WHERE name LIKE ?" : '') . " ORDER BY name LIMIT 60");
            $st->execute($q !== '' ? [$like] : []);
            foreach ($st as $r) $out[] = ['label' => $r['name'], 'url' => '/categories/' . $r['slug'], 'kind' => 'Category'];
        } elseif ($src === 'tags') {
            $st = $pdo->prepare("SELECT id, name, slug FROM tags" . ($q !== '' ? " WHERE name LIKE ?" : '') . " ORDER BY name LIMIT 60");
            $st->execute($q !== '' ? [$like] : []);
            foreach ($st as $r) $out[] = ['label' => $r['name'], 'url' => tagUrl($r['slug'], $r['id']), 'kind' => 'Tag'];
        }
        echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(500);
        echo '[]';
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    csrf_check();
    $action = $_POST['action'] ?? '';
    $data = json_decode((string)($_POST['data'] ?? '{}'), true) ?: [];
    $defaults = edu_theme_defaults();
    $theme = edu_theme_sanitize(array_intersect_key((array)($data['theme'] ?? []), $defaults));
    $general = array_intersect_key((array)($data['general'] ?? []), array_flip($identityKeys));
    foreach (['site_title' => 100, 'tagline' => 200] as $gk => $max) if (isset($general[$gk])) $general[$gk] = mb_substr(trim((string)$general[$gk]), 0, $max);
    if (isset($general['logo_w'])) $general['logo_w'] = max(20, min(600, (int)$general['logo_w']));

    $home = isset($data['home']) && is_array($data['home']) ? sh_home_sanitize($data['home']) : null; // Homepage panel
    if ($czBlog) { // keep everything outside the blog exactly as it is
        if (!in_array($action, ['draft', 'publish'], true)) { echo json_encode(['ok' => false, 'message' => 'Only admins can do this.']); exit; }
        $live = edu_theme();
        foreach ($theme as $k => $v) if (!preg_match(CZ_BLOG_KEYS, $k)) $theme[$k] = $live[$k] ?? $defaults[$k];
        $general = []; $home = null;
    }
    if ($action === 'draft') {
        $_SESSION['edu_draft'] = ['theme' => $theme, 'general' => $general] + ($home ? ['home' => $home] : []);
        echo json_encode(['ok' => true]);
        exit;
    }
    if ($action === 'publish') {
        $old = edu_settings('theme');
        $_SESSION['edu_theme_backup'] = ['at' => date('c'), 'theme' => $old];
        edu_settings_save('theme', $theme);
        if ($general) edu_settings_save('general', $general);
        if ($home) { edu_settings_save('home', $home); sh_home_refresh(); }
        unset($_SESSION['edu_draft']);
        admin_log('theme_publish', 'Published Customizer changes');
        echo json_encode(['ok' => true]);
        exit;
    }
    if ($action === 'reset') {
        $old = edu_settings('theme');
        edu_settings_save('theme_backup', ['at' => date('c'), 'theme' => $old]);
        $pdo->prepare("UPDATE app_config SET config_value = '{}' WHERE config_key = 'edu_theme'")->execute();
        edu_clear_page_cache();
        unset($_SESSION['edu_draft']);
        admin_log('theme_reset', 'Reset the Customizer design to default');
        echo json_encode(['ok' => true]);
        exit;
    }
    if ($action === 'undo_reset') {
        $b = edu_settings('theme_backup');
        if (empty($b['theme'])) { echo json_encode(['ok' => false, 'message' => 'Nothing to restore.']); exit; }
        $pdo->prepare("UPDATE app_config SET config_value = ? WHERE config_key = 'edu_theme'")->execute([json_encode($b['theme'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        edu_clear_page_cache();
        admin_log('theme_reset_undo', 'Restored the design from before the reset');
        echo json_encode(['ok' => true]);
        exit;
    }
    echo json_encode(['ok' => false]);
    exit;
}

unset($_SESSION['edu_draft']);
$theme = edu_theme();
$general = array_intersect_key(edu_settings('general'), array_flip($identityKeys)) + ['site_title' => '', 'tagline' => '', 'logo' => '', 'header_logo' => '1', 'favicon' => ''];
$general['logo_w'] = edu_logo_size()[0]; // until saved once: the size the logo has today
$backup = edu_settings('theme_backup');
$hc = sh_home_cfg();
require_once INCLUDES_PATH . '/institute.php'; $hbrand = ci_institute()['brand']['title'] ?? '' ?: edu_site_name();
$homeState = ['sections' => array_map(fn($id) => ['id' => $id, 'on' => $hc['on'][$id] ? 1 : 0, 'title' => $id === 'why' && $hc['titles'][$id] === '' ? 'Why choose ' . $hbrand : $hc['titles'][$id]], $hc['order']), // the why heading is a real text, not built in
    'why' => array_map(fn($w) => ['t' => (string)$w['t'], 'i' => (string)($w['i'] ?? '')], $hc['why']), 'impact' => array_map(fn($it) => $it + ['i' => ''], $hc['impact']), 'why_text' => $hc['why_text'],
    'cats_vis' => (object)$hc['cats_vis'], 'cats_cols_d' => $hc['cats_cols_d'], 'cats_cols_m' => $hc['cats_cols_m'],
    'courses_filter' => $hc['courses_filter'], 'batches_filter' => $hc['batches_filter'], 'notes_sort' => $hc['notes_sort'],
    'cats_d' => $hc['cats_d'], 'cats_m' => $hc['cats_m'], 'posts_count' => $hc['posts_count']];
$latest = $pdo->query("SELECT id, slug FROM posts WHERE status = 'published' ORDER BY date DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$latestUrl = $latest ? postUrl($latest['slug'], $latest['id']) : '/';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Customize - <?= htmlspecialchars(edu_site_name()) ?></title>
<?php admin_shell_head(); ?>
<link rel="stylesheet" href="/assets/css/customize.css?v=<?= @filemtime(ROOT_PATH . '/assets/css/customize.css') ?>">
<script src="/assets/js/admin-media.js?v=<?= @filemtime(ROOT_PATH . '/assets/js/admin-media.js') ?>" defer></script>
<style>
body{margin:0;overflow:hidden}
.cz-list-item{display:grid;grid-template-columns:1fr 1fr auto;gap:6px;margin-bottom:6px}
.cz-list-item input{min-width:0}
.cz-list-item .cz-li-btns{display:flex;gap:2px}
.cz-list-item button{border:1px solid #dcdcde;background:#fff;border-radius:4px;width:26px;height:30px;cursor:pointer;color:#50575e}
.cz-list-add{margin-top:4px}
.cz-media-box{border:1px dashed #c3c4c7;border-radius:6px;min-height:70px;display:flex;align-items:center;justify-content:center;background:#f6f7f7;overflow:hidden;margin-bottom:6px;color:#8c8f94;font-size:12px}
.cz-media-box img{max-width:100%;max-height:110px;display:block}
.cz-codearea{font:12px/1.5 ui-monospace,Menlo,monospace;min-height:220px}
/* device switches, icon picker, compact slide rows, tables (Homepage panel) */
.hm-dev{display:inline-flex;gap:4px}
.hm-dev-o{position:relative;display:inline-flex;align-items:center;gap:3px;height:26px;padding:0 5px;border:1px solid #dcdcde;border-radius:4px;background:#fff;color:#a7aaad;cursor:pointer}
.hm-dev-o svg{width:14px;height:14px}.hm-dev-o input{margin:0;width:13px;height:13px}
.hm-dev-o.on{color:#1d2327;border-color:#c3c4c7}.hm-dev-o.on svg{color:var(--cz-accent,#2271b1)}
.hm-sl{display:grid;grid-template-columns:96px minmax(0,1fr);gap:10px;padding:8px;border:1px solid #dcdcde;border-radius:4px;background:#fff}
.hm-sl.is-off{opacity:.6}
.hm-sl-img{position:relative;align-self:start;border-radius:3px;overflow:hidden;background:#f0f0f1}.hm-sl-img img{display:block;width:100%;aspect-ratio:16/9;object-fit:cover}
.hm-sl-acts{display:flex;flex-wrap:wrap;gap:2px;margin-top:4px}.hm-sl-acts .hm-ic{width:22px;height:22px;font-size:10.5px}
.hm-sl-img{overflow:visible}.hm-sl-img img{border-radius:3px}
.hm-sl-b{display:flex;flex-direction:column;gap:7px;min-width:0}
.hm-sl-b .hm-link{height:30px}
.hm-sl-o{display:flex;gap:12px;font-size:12.5px}
.hm-sl-dev{display:flex;align-items:center;gap:8px}.hm-sl-dev > span{font-size:11px;font-weight:600;color:#646970;text-transform:uppercase;letter-spacing:.04em}
.hm-ctab{border:1px solid #dcdcde;border-radius:4px;background:#fff;overflow:hidden}
.hm-ctab-h,.hm-ctab-r{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:6px 8px}
.hm-ctab-h{background:#f6f7f7;font-size:11px;font-weight:600;color:#646970;text-transform:uppercase;letter-spacing:.04em;border-bottom:1px solid #dcdcde}
.hm-ctab-dh{display:inline-flex;gap:4px}.hm-ctab-dh svg{width:14px;height:14px;margin:0 9px 0 6px;color:#646970}
.hm-ctab-r + .hm-ctab-r{border-top:1px solid #f0f0f1}.hm-ctab-r.is-off .hm-ctab-n{color:#a7aaad;text-decoration:line-through}
.hm-ctab-n{font-size:13px;font-weight:500;color:#1d2327;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.hm-per{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.hm-per label{display:flex;align-items:center;gap:6px;padding:6px 8px;border:1px solid #dcdcde;border-radius:4px;background:#fff;font-size:12.5px;color:#50575e}
.hm-per svg{width:16px;height:16px;color:var(--cz-accent,#2271b1);flex:0 0 auto}.hm-per span{flex:1}
.hm-per input{width:48px!important;height:28px!important;min-height:0!important;padding:0 6px!important;text-align:center}
.hm-rows{border:1px solid #dcdcde;border-radius:6px;background:#fff;overflow:visible}
.hm-rows-h{padding:6px 8px;background:#f6f7f7;border-bottom:1px solid #dcdcde;border-radius:6px 6px 0 0;font-size:10.5px;font-weight:600;color:#646970;text-transform:uppercase;letter-spacing:.05em}
.hm-rowc{padding:6px 8px;align-items:center}.hm-rowc + .hm-rowc{border-top:1px solid #f0f0f1}
.hm-imp-c{display:grid;grid-template-columns:36px minmax(0,1fr) 64px 28px;gap:6px}
.hm-why-c{display:grid;grid-template-columns:36px minmax(0,1fr) 56px 28px;gap:6px}
.hm-rowc input[type=text]{width:100%;min-width:0;height:32px!important;min-height:0!important;padding:0 8px!important;border:1px solid #e0e0e0!important;border-radius:4px!important;background:#fff;font-size:13px;box-sizing:border-box}
.hm-rowc input[type=text]:focus{border-color:var(--cz-accent,#2271b1)!important;box-shadow:none!important}
.hm-rowo{display:inline-flex;gap:0}.hm-rowo .hm-ic,.hm-rowa .hm-ic{width:26px;height:26px}
.hm-icw{position:relative}
.hm-icb{width:34px;height:32px;display:grid;place-items:center;border:1px solid #dcdcde;border-radius:4px;background:#f6f7f7;color:#1d2327;cursor:pointer;padding:0}
.hm-icb:hover{border-color:var(--cz-accent,#2271b1)}.hm-icb svg{width:17px;height:17px}.hm-icb-auto{font-size:9.5px;font-weight:700;color:#8c8f94;text-transform:uppercase}
.hm-icp{position:absolute;left:0;top:36px;z-index:50;width:250px;padding:10px;background:#fff;border:1px solid #c3c4c7;border-radius:6px;box-shadow:0 8px 24px rgba(0,0,0,.15)}
.hm-icp-h{font-size:10.5px;font-weight:700;color:#646970;text-transform:uppercase;letter-spacing:.05em;margin:0 0 6px}
.hm-icp-g{display:grid;grid-template-columns:repeat(8,1fr);gap:3px;margin-bottom:10px}
.hm-icp-o{aspect-ratio:1;display:grid;place-items:center;border:1px solid transparent;border-radius:4px;background:#f6f7f7;cursor:pointer;padding:0;color:#1d2327}
.hm-icp-o:hover{border-color:var(--cz-accent,#2271b1)}.hm-icp-o.on{background:var(--cz-accent,#2271b1);color:#fff}.hm-icp-o svg{width:15px;height:15px}
.hm-icp textarea{width:100%;box-sizing:border-box;font:11px/1.4 ui-monospace,Menlo,monospace}
.hm-icp-f{display:flex;justify-content:space-between;align-items:center;margin-top:8px}.hm-icp-auto{border:0;background:none;color:#b32d2e;font-size:12px;cursor:pointer;padding:0}
/* Homepage panel — WordPress-like: plain rows, ghost icons, one screen per section */
.hm-lead{margin:0 0 10px;font-size:12.5px;color:#646970}.hm-lead i{font-size:11px;color:#8c8f94}
.hm-list{list-style:none;margin:0 -14px;padding:0;border-top:1px solid #dcdcde}
.hm-row{display:flex;align-items:center;gap:8px;min-height:46px;padding:0 12px 0 6px;background:#fff;border-bottom:1px solid #dcdcde;cursor:pointer;user-select:none;transition:background .12s}
.hm-row:hover{background:#f6f7f7}.hm-row:hover .hm-nm{color:var(--cz-accent,#2271b1)}
.hm-row.dragging{opacity:.5;background:#f0f6fc}
.hm-grip{width:22px;display:grid;place-items:center;color:#c3c4c7;cursor:grab;font-size:12px}.hm-row:hover .hm-grip{color:#8c8f94}
.hm-ico{width:26px;height:26px;border-radius:4px;display:grid;place-items:center;background:#f0f0f1;color:#50575e;font-size:12px;flex:0 0 auto}
.hm-nm{flex:1;min-width:0;font-size:13.5px;font-weight:500;color:#1d2327;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.hm-nm em{margin-left:8px;font-style:normal;font-size:11px;font-weight:500;color:#8c8f94;background:#f0f0f1;padding:1px 6px;border-radius:3px}
.hm-row.is-off .hm-nm{color:#8c8f94}.hm-row.is-off .hm-ico{opacity:.5}
.hm-go{font-size:11px;color:#a7aaad}
.hm-acts{display:flex;gap:2px;flex:0 0 auto}
.hm-ic{width:28px;height:28px;display:grid;place-items:center;border:0;border-radius:4px;background:transparent;color:#646970;cursor:pointer;font-size:12.5px}
.hm-ic:hover{background:#f0f0f1;color:#1d2327}.hm-ic.danger:hover{background:#fcf0f1;color:#b32d2e}.hm-ic.is-off{color:#b32d2e}
.hm-ic.is-dis{opacity:.3;pointer-events:none}
.hm-grp{display:flex;flex-direction:column;gap:10px;padding:16px 0;border-bottom:1px solid #f0f0f1}
.hm-grp:first-child{padding-top:4px}.hm-grp:last-child{border-bottom:0}
.hm-grp > h4{margin:0;font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:#646970}
.hm-desc{margin:0;font-size:12px;line-height:1.5;color:#646970}
.hm-src{padding-top:14px}.hm-src a{white-space:nowrap}
.hm-instant{margin:0;font-size:11.5px;color:#8c8f94}.hm-instant i{color:#dba617;margin-right:3px}
/* slides */
.hm-slide{border:1px solid #dcdcde;border-radius:4px;background:#fff;overflow:hidden}
.hm-slide.is-off .hm-slide-img img{opacity:.45}
.hm-slide-img{position:relative;background:#f0f0f1}.hm-slide-img img{display:block;width:100%;aspect-ratio:16/5;object-fit:cover}
.hm-slide-n{position:absolute;left:8px;top:8px;min-width:20px;height:20px;padding:0 5px;border-radius:3px;background:rgba(0,0,0,.6);color:#fff;font-size:11px;font-weight:600;display:grid;place-items:center}
.hm-slide-ov{position:absolute;right:6px;top:6px;display:flex;gap:2px;padding:2px;border-radius:5px;background:rgba(255,255,255,.94);box-shadow:0 1px 3px rgba(0,0,0,.15);opacity:0;transition:opacity .15s}
.hm-slide:hover .hm-slide-ov,.hm-slide:focus-within .hm-slide-ov{opacity:1}
.hm-pill{display:inline-flex;align-items:center;gap:5px;height:28px;padding:0 9px;border:0;border-radius:4px;background:transparent;color:#1d2327;font:500 12px/1 inherit;cursor:pointer}.hm-pill:hover{background:#f0f0f1}
.hm-slide-b{display:flex;flex-direction:column;gap:8px;padding:10px}
.hm-link{display:flex;align-items:center;gap:8px;height:34px;padding:0 10px;border:1px solid #c3c4c7;border-radius:4px;background:#fff}
.hm-link:focus-within{border-color:var(--cz-accent,#2271b1);box-shadow:0 0 0 1px var(--cz-accent,#2271b1)}
.hm-link i{font-size:11.5px;color:#8c8f94}.hm-link input{flex:1;min-width:0;border:0!important;outline:0;box-shadow:none!important;padding:0!important;height:auto!important;font-size:13px;background:transparent}
.hm-slide-o{display:flex;align-items:center;gap:14px;flex-wrap:wrap;font-size:12.5px}
.hm-phone{margin-left:auto;display:inline-flex;align-items:center;gap:4px;color:#646970}.hm-phone .hm-ic{width:22px;height:22px;font-size:11px}
.hm-a{border:0;background:none;padding:0;font:inherit;font-size:12.5px;color:var(--cz-accent,#2271b1);cursor:pointer;text-decoration:underline;text-underline-offset:2px}
.hm-chk{display:inline-flex;align-items:center;gap:6px;font-size:12.5px;color:#1d2327;cursor:pointer}
.hm-add{display:flex;align-items:center;justify-content:center;gap:6px;height:38px;border:1px dashed #a7aaad;border-radius:4px;background:#fff;color:#2c3338;font:500 13px/1 inherit;cursor:pointer}
.hm-add:hover{border-color:var(--cz-accent,#2271b1);color:var(--cz-accent,#2271b1);background:#f6f7f7}
/* list items (free courses, toppers) */
.hm-item{display:flex;align-items:center;gap:10px;padding:8px;border:1px solid #dcdcde;border-radius:4px;background:#fff}
.hm-item.is-off{opacity:.6}
.hm-item-i{width:64px;height:36px;flex:0 0 auto;border-radius:3px;overflow:hidden;background:#f0f0f1;display:grid;place-items:center;color:#a7aaad}
.hm-item.tall .hm-item-i{width:36px;height:46px}
.hm-item-i img{width:100%;height:100%;object-fit:cover;display:block}
.hm-item-t{flex:1;min-width:0}.hm-item-t b{display:block;font-size:13px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.hm-item-t small{display:block;font-size:11.5px;color:#646970;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.hm-form{display:flex;flex-direction:column;gap:10px;padding:12px;border:1px solid #c3c4c7;border-radius:4px;background:#f6f7f7}
.hm-form input[type=text],.hm-form input[type=url],.hm-form input[type=number],.hm-form select{width:100%;box-sizing:border-box}
.hm-two{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.hm-photo{display:flex;gap:12px;align-items:center}.hm-photo > div{display:flex;flex-direction:column;gap:4px}
.hm-photo-i{width:54px;height:70px;border-radius:4px;overflow:hidden;background:#fff;border:1px solid #dcdcde;display:grid;place-items:center;color:#a7aaad}.hm-photo-i img{width:100%;height:100%;object-fit:cover}
.hm-pair{display:grid;grid-template-columns:1fr 84px auto;gap:6px;align-items:center}
.hm-pair-1{grid-template-columns:1fr auto auto}
/* Site Identity */
.id-grp{display:flex;flex-direction:column;gap:10px;padding:0 0 16px;border-bottom:1px solid #f0f0f1}
.id-grp:last-of-type{border-bottom:0}
.id-grp > h4{margin:0;font-size:14px;font-weight:600;color:#1d2327}
.id-pv{padding:14px 10px;border:1px solid #dcdcde;border-radius:4px;background:#fff;overflow:hidden}
.id-pv-in{position:relative;isolation:isolate;display:inline-flex;align-items:center;gap:8px;max-width:100%;padding:4px 44px 4px 12px;box-sizing:border-box}
.id-pv-in::before{content:"";position:absolute;inset:0 8px 0 0;z-index:-1;background:rgba(240,174,113,.43);clip-path:polygon(0 0,100% 0,96% 45%,100% 100%,0 100%)}
.id-pv-in::after{content:"";position:absolute;bottom:0;right:4px;width:30px;height:55%;z-index:-1;background:#f02801;clip-path:polygon(50% 0,0 100%,25% 100%)}
.id-pv img[hidden]{display:none}
.id-pv img{display:block;width:auto;max-width:80px;object-fit:contain;flex:0 0 auto}
.id-pv-t{display:flex;flex-direction:column;min-width:0;line-height:1.15}
.id-pv-t b{font:700 17px/1.15 Poppins,Roboto,Arial,sans-serif;color:#9e1b32;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.id-pv-t small{margin-top:2px;font:500 12px/1.3 Roboto,Arial,sans-serif;color:#1a1a1a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.id-pv.is-plain .id-pv-in{padding-right:12px}.id-pv.is-plain .id-pv-in::before,.id-pv.is-plain .id-pv-in::after{display:none}
.id-dflt{display:flex;flex-direction:column;align-items:center;gap:4px;padding:8px}.id-dflt img{height:54px;width:auto}.id-dflt small{font-size:11.5px;color:#646970}
/* ===== Homepage screens, WordPress Customizer look: grey panel, slim white widgets, 30px controls ===== */
#cz-body.hm-bg{background:#fff;gap:0}
.hm-bg .hm-grp{gap:8px;padding:14px 0;border-bottom:1px solid #dcdcde}
.hm-bg .hm-grp:first-child{padding-top:2px}.hm-bg .hm-grp:last-child{border-bottom:0}
.hm-bg .hm-grp > h4{font-size:14px;font-weight:600;letter-spacing:0;text-transform:none;color:#1d2327;line-height:1.4}
.hm-bg .hm-desc,.hm-bg .cz-hint{font-size:12px;font-style:italic;color:#646970;line-height:1.5}
.hm-bg .cz-field{gap:4px}.hm-bg .cz-label{font-size:13px;font-weight:600;color:#1d2327}
.hm-bg .cz-field input:not([type=checkbox]):not([type=radio]):not([type=range]):not([type=color]),.hm-bg .cz-field select{height:30px;min-height:30px;padding:0 8px;border:1px solid #8c8f94;border-radius:4px;background:#fff;font-size:13px;line-height:28px;box-shadow:none;box-sizing:border-box;width:100%}
.hm-bg .cz-field textarea{min-height:64px;padding:5px 8px;border:1px solid #8c8f94;border-radius:4px;font-size:13px;line-height:1.5;box-shadow:none}
.hm-bg .cz-field input:focus,.hm-bg .cz-field select:focus,.hm-bg .cz-field textarea:focus{border-color:#2271b1;box-shadow:0 0 0 1px #2271b1;outline:0}
.hm-bg .cz-toggle-text{font-size:13px;font-weight:600}
.hm-bg .cz-seg button{padding:6px;font-size:12.5px}
.hm-bg .hm-lead{font-style:italic;font-size:12px}
.hm-bg .hm-row{min-height:42px}.hm-bg .hm-nm{font-size:13.5px;font-weight:600}
/* WordPress buttons */
.hm-btn{display:inline-flex;align-items:center;gap:5px;height:30px;padding:0 10px;border:1px solid #2271b1;border-radius:3px;background:#f6f7f7;color:#2271b1;font:400 13px/1 inherit;cursor:pointer;white-space:nowrap}
.hm-btn:hover{background:#f0f0f1;border-color:#0a4b78;color:#0a4b78}.hm-btn i{font-size:11px}
.hm-btn-p{background:#2271b1;color:#fff}.hm-btn-p:hover{background:#135e96;color:#fff}
.hm-bg .hm-add{align-self:flex-start;height:30px;border:1px solid #2271b1;border-style:solid;background:#f6f7f7;color:#2271b1;font-weight:400}
.hm-bg .hm-add:hover{background:#f0f0f1;color:#0a4b78;border-color:#0a4b78}
/* widgets */
.hm-wl{display:flex;flex-direction:column;gap:6px}
.hm-w{background:#fff;border:1px solid #dcdcde;border-radius:2px}
.hm-w:hover{border-color:#a7aaad}.hm-w.open{border-color:#8c8f94;box-shadow:0 1px 2px rgba(0,0,0,.06)}
.hm-w.is-off .hm-w-lead,.hm-w.is-off .hm-w-t{opacity:.55}
.hm-w-bar{display:flex;align-items:center;gap:9px;min-height:42px;padding:5px 4px 5px 8px;cursor:pointer;user-select:none;box-sizing:border-box}
.hm-w-bar:focus-visible{outline:2px solid #2271b1;outline-offset:-2px}
.hm-w.open .hm-w-bar{border-bottom:1px solid #f0f0f1}
.hm-w-t{flex:1;min-width:0;display:flex;flex-direction:column;gap:1px}
.hm-w-t b{font-size:13px;font-weight:600;color:#1d2327;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.hm-w-t small{font-size:11.5px;color:#646970;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.hm-w-x{display:flex;align-items:center}.hm-w-x .hm-ic{width:24px;height:24px;font-size:10.5px;color:#8c8f94}
.hm-w-x .hm-ic:hover{color:#1d2327}
.hm-w-arr{width:22px;text-align:center;color:#787c82;font-size:13px;transition:transform .15s}.hm-w.open .hm-w-arr{transform:rotate(180deg)}
.hm-w-lead{display:flex;flex:0 0 auto}
.hm-w-th{display:block;width:52px;height:29px;flex:0 0 auto;border-radius:2px;overflow:hidden;background:#f0f0f1}.hm-w-th img{width:100%;height:100%;object-fit:cover;display:block}
.hm-w-ic{width:28px;height:28px;flex:0 0 auto;display:grid!important;place-items:center;border-radius:4px;background:#f0f6fc;color:#2271b1}
.hm-w-ic svg{width:15px;height:15px}.hm-w-ic.auto{background:#f6f7f7;color:#a7aaad}
.hm-w-in{display:flex;flex-direction:column;gap:12px;padding:12px 10px 10px}
.hm-w-f{display:flex;align-items:center;justify-content:space-between;padding-top:10px;border-top:1px solid #f0f0f1}
.hm-w-del{border:0;background:none;padding:0;color:#b32d2e;font-size:13px;cursor:pointer;text-decoration:underline;text-underline-offset:2px}.hm-w-del:hover{color:#8a2424}
.hm-w-pic{display:flex;flex-direction:column;align-items:flex-start;gap:8px}
.hm-w-pic img{display:block;width:100%;aspect-ratio:16/5;object-fit:cover;border-radius:2px;background:#f0f0f1}
.hm-w-chks{display:flex;flex-direction:column;gap:7px}
.hm-bg .hm-chk{font-size:13px}.hm-bg .hm-chk input{margin:0}
.hm-icrow{display:flex;align-items:center;gap:10px}.hm-icrow .cz-hint{flex:1}
.hm-bg .hm-icb{width:40px;height:34px;background:#fff;border-color:#8c8f94}
/* device buttons: one joined group, blue = shown, slashed grey = hidden */
.hm-bg .hm-dev{display:inline-flex;gap:0;width:max-content;border:1px solid #8c8f94;border-radius:4px;overflow:hidden;background:#fff}
.hm-bg .hm-dev-o{position:relative;width:34px;height:28px;padding:0;display:grid;place-items:center;border:0;border-radius:0;background:#fff;color:#a7aaad;cursor:pointer}
.hm-bg .hm-dev-o + .hm-dev-o{border-left:1px solid #dcdcde}
.hm-bg .hm-dev-o svg{width:15px;height:15px}
.hm-bg .hm-dev-o.on{background:#f0f6fc;color:#2271b1}
.hm-bg .hm-dev-o:not(.on)::after{content:"";position:absolute;left:50%;top:50%;width:20px;height:1.5px;background:#a7aaad;transform:translate(-50%,-50%) rotate(-45deg)}
.hm-bg .hm-dev-o:hover{color:#135e96}
/* categories */
.hm-clist{background:#fff;border:1px solid #dcdcde;border-radius:2px}
.hm-crow{display:flex;align-items:center;justify-content:space-between;gap:8px;min-height:40px;padding:5px 6px 5px 12px}
.hm-crow + .hm-crow{border-top:1px solid #f0f0f1}
.hm-crow-n{font-size:13px;color:#1d2327;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.hm-crow.is-off .hm-crow-n{color:#a7aaad;text-decoration:line-through}
.hm-crow .hm-dev-o{width:30px;height:26px}
.hm-bg .hm-per{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.hm-bg .hm-per label{display:flex;align-items:center;justify-content:space-between;gap:6px;padding:4px 4px 4px 10px;border:1px solid #dcdcde;border-radius:2px;background:#fff;font-size:13px;color:#1d2327}
.hm-per-l{display:flex;align-items:center;gap:6px}.hm-bg .hm-per svg{width:15px;height:15px;color:#50575e}
.hm-bg .hm-per input{width:50px!important;height:28px!important;border:1px solid #8c8f94!important;border-radius:4px!important;text-align:center;font-size:13px}
/* item rows (free courses, toppers, teachers) */
.hm-bg .hm-item{gap:9px;padding:6px 4px 6px 8px;border-radius:2px;min-height:42px;box-sizing:border-box}
.hm-bg .hm-item:hover{border-color:#a7aaad}
.hm-bg .hm-item-i{width:52px;height:29px;border-radius:2px}
.hm-bg .hm-item.tall .hm-item-i{width:30px;height:38px}
.hm-bg .hm-item-t b{font-size:13px}.hm-bg .hm-item-t small{font-size:11.5px}
.hm-bg .hm-item .hm-ic{width:26px;height:26px;font-size:11.5px}
.hm-bg .hm-form{background:#fff;border-color:#8c8f94;border-radius:2px;margin-top:-7px}
.hm-bg .hm-instant{font-size:11.5px;font-style:italic}
.hm-bg .hm-src{font-style:italic;font-size:12px;padding-top:12px}

.hm-pair input{min-width:0;height:32px;box-sizing:border-box}
.cz-btn-row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
</style>
</head>
<body>
<?php $czAcc = (function () { foreach ((array)(edu_theme()['global_colors'] ?? []) as $g) if (($g['slug'] ?? '') === 'accent' && preg_match('/^#[0-9a-f]{3,8}$/i', (string)$g['color'])) return $g['color']; return '#0c6878'; })(); ?>
<div class="cz-root" id="cz-root" style="--cz-accent: <?= htmlspecialchars($czAcc) ?>; --cz-accent-dark: color-mix(in srgb, <?= htmlspecialchars($czAcc) ?> 82%, #000)">
  <aside class="cz-panel">
    <div class="cz-top">
      <a class="cz-close" title="Close" href="/admin/dashboard.php"><i class="fas fa-xmark"></i></a>
      <span class="cz-status" id="cz-status">All changes published</span>
      <button type="button" class="cz-publish" id="cz-publish" disabled>Published</button>
    </div>
    <div class="cz-head">
      <button type="button" class="cz-back" id="cz-back" hidden aria-label="Back"><i class="fas fa-chevron-left"></i></button>
      <div><small id="cz-head-small">You are customizing</small><h1 id="cz-head-title"><?= htmlspecialchars(edu_site_name()) ?></h1></div>
    </div>
    <div class="cz-body" id="cz-body"></div>
    <div class="cz-footer">
      <button type="button" class="cz-hide" id="cz-hide"><i class="fas fa-circle-chevron-left"></i> Hide Controls</button>
      <span class="cz-devices" id="cz-devices">
        <button type="button" class="active" data-dev="d" title="Desktop"><i class="fas fa-desktop"></i><span>Desktop</span></button>
        <button type="button" data-dev="t" title="Tablet"><i class="fas fa-tablet-screen-button"></i><span>Tablet</span></button>
        <button type="button" data-dev="m" title="Mobile"><i class="fas fa-mobile-screen-button"></i><span>Mobile</span></button>
      </span>
    </div>
  </aside>
  <main class="cz-preview">
    <div class="cz-frame-wrap cz-dev-d" id="cz-wrap">
      <div class="cz-loading" id="cz-loading"><span class="cz-spinner"></span> Loading preview…</div>
      <span class="cz-refreshing" id="cz-refreshing" hidden><span class="cz-spinner"></span></span>
      <iframe title="Site preview" id="cz-frame" class="is-active" src="/?czp=1"></iframe>
    </div>
  </main>
</div>
<button type="button" class="cz-show" id="cz-show" hidden><i class="fas fa-circle-chevron-right"></i> Show Controls</button>

<script>
const CSRF = <?= json_encode(csrf_token()) ?>;
const DEFAULTS = <?= json_encode(edu_theme_defaults(), JSON_UNESCAPED_UNICODE) ?>;
const state = { theme: <?= json_encode($theme, JSON_UNESCAPED_UNICODE) ?>, general: <?= json_encode($general, JSON_UNESCAPED_UNICODE) ?>, home: <?= json_encode($homeState, JSON_UNESCAPED_UNICODE) ?> };
const HOME_SECTIONS = <?= json_encode(SH_HOME_SECTIONS, JSON_UNESCAPED_UNICODE) ?>;
const PICK_ICONS = <?= json_encode(array_combine(SH_PICK_ICONS, array_map('sh_icon', SH_PICK_ICONS)), JSON_UNESCAPED_SLASHES) ?>;
const HOME_BRAND = <?php require_once INCLUDES_PATH . '/institute.php'; $hb = ci_institute()['brand']['title'] ?? ''; echo json_encode($hb !== '' ? $hb : edu_site_name(), JSON_UNESCAPED_UNICODE); ?>; // same name as the homepage heading
const START_PANEL = <?= json_encode((string)($_GET['panel'] ?? '')) ?>;
const CZ_ONLY = <?= json_encode($czBlog ? CZ_BLOG_PANELS : null) ?>; // Blog Admin: blog panels only
const HAS_BACKUP = <?= !empty($backup['theme']) ? 'true' : 'false' ?>;
const BACKUP_AT = <?= json_encode($backup['at'] ?? '') ?>;
const FONTS = [['system', 'System font (fastest)'], ['Inter', 'Inter'], ['Roboto', 'Roboto'], ['Poppins', 'Poppins'], ['Noto Sans', 'Noto Sans (Hindi too)'], ['Mukta', 'Mukta (Hindi)'], ['Hind', 'Hind (Hindi)'], ['Open Sans', 'Open Sans'], ['Lato', 'Lato'], ['Merriweather', 'Merriweather (serif)'], ['Georgia', 'Georgia (serif)']];
const SITE_NAME_DEFAULT = <?= json_encode(SITE_NAME) ?>;
const ID_ADDRESS = <?= json_encode(trim((string)es('general', 'address', ''))) ?>;
const COLOR_GROUPS = <?= json_encode(EDU_COLOR_GROUPS, JSON_UNESCAPED_UNICODE) ?>;
const TYPO_TARGETS = <?= json_encode(array_map(fn($x) => $x[0], EDU_TYPO_TARGETS), JSON_UNESCAPED_UNICODE) ?>;
const SHORTCODES = <?= json_encode(edu_shortcode_help(), JSON_UNESCAPED_UNICODE) ?>;

// --- panel definitions: [type, key, label, extra] ; keys starting with "g." edit General Settings (identity) ---
const PANELS = [
  { id: 'homepage', icon: 'fa-house', label: 'Homepage', sub: 'Sections, slider, free courses, toppers, numbers', render: renderHomepage },
  { id: 'identity', icon: 'fa-id-card', label: 'Site Identity', sub: 'Logo, site title and the line under it', render: renderIdentity },
  { id: 'colors', icon: 'fa-palette', label: 'Colors', sub: 'Global colors and every area of the site', render: renderColors },
  { id: 'typography', icon: 'fa-font', label: 'Typography', sub: 'Fonts, sizes, weights', render: renderTypography },
  { id: 'h_primary', icon: 'fa-window-maximize', label: 'Primary Header', sub: '', design: ['header'], fields: [['toggle', 'h_sticky', 'Sticky header'], ['toggle', 'h_bell', 'Notification bell'], ['toggle', 'h_socials', 'Social icons on the left'], ['toggle', 'h_dark', 'Dark mode switch']] },
  { id: 'h_strip', icon: 'fa-grip-lines', label: 'Primary Menu', sub: '', design: ['menu_strip'], render: renderStripOpts },
  { id: 'h_search', icon: 'fa-magnifying-glass', label: 'Search', sub: '', design: ['search_modal'], fields: [['toggle', 'h_search', 'Show search'], ['toggle', 'h_voice', 'Voice search'], ['text', 'h_search_placeholder', 'Placeholder']] },
  { id: 'h_account', icon: 'fa-user', label: 'Account (Log in)', sub: '', fields: [['toggle', 'h_login', 'Show “Log in”'], ['text', 'h_login_text', 'Log in text'], ['text', 'h_login_url', 'Log in link', { hint: 'Empty = the admin login page.' }]] },
  { id: 'h_button', icon: 'fa-bell', label: 'Button (Subscribe)', sub: '', fields: [['toggle', 'h_sub', 'Show “Subscribe”'], ['text', 'h_sub_text', 'Subscribe text'], ['select', 'h_sub_icon', 'Icon before the text', { options: [['', 'None (normal button)'], ['whatsapp', 'WhatsApp'], ['youtube', 'YouTube'], ['facebook', 'Facebook'], ['instagram', 'Instagram'], ['x', 'X (Twitter)'], ['telegram', 'Telegram'], ['linkedin', 'LinkedIn']], hint: 'With an icon, the button takes that app’s colour (WhatsApp green, YouTube red …). Tip: put your WhatsApp / YouTube link in “Subscribe link”.' }], ['text', 'h_sub_url', 'Subscribe link', { hint: 'Empty = turns on job alerts (push notifications).' }]] },
  { id: 'h_offcanvas', icon: 'fa-bars', label: 'Off-Canvas Menu', sub: '', design: ['off_canvas'], fields: [['toggle', 'h_drawer_hello', 'Show the greeting (“Hello, Reader”)'], ['text', 'h_drawer_title', 'Greeting (mobile menu)'], ['text', 'h_drawer_text', 'Text under the greeting'], ['toggle', 'h_drawer_login', 'Show “Log in” in the mobile menu', { hint: 'Also needs “Show Log in” above.' }], ['toggle', 'h_drawer_sub', 'Show “Subscribe” in the mobile menu', { hint: 'Also needs “Show Subscribe” above.' }]] },
  { id: 'h_above', icon: 'fa-window-minimize', label: 'Above Header (Top bar)', sub: '', design: ['top_bar'], fields: [['toggle', 'h_topbar', 'Show top bar (above the header)'], ['textarea', 'h_topbar_text', 'Top bar text / HTML']] },
  { id: 'container', icon: 'fa-arrows-left-right', label: 'Container', sub: 'Width of the site, corner radius', fields: [
    ['range', 'h_width', 'Container width', { min: 960, max: 1600, step: 10, unit: 'px' }],
    ['range', 'box_radius', 'Corner radius (boxes, buttons, drop-downs)', { min: 0, max: 30, step: 1, unit: 'px' }],
  ]},
  { id: 'menus', icon: 'fa-bars', label: 'Menus', sub: 'Primary menu and the menu strip', render: renderMenus },
  { id: 'f_copy', icon: 'fa-copyright', label: 'Copyright', sub: '', design: ['footer_bar'], fields: [['text', 'f_copyright', 'Copyright text', { hint: 'Shortcodes: [year] [site_title] [site_link]' }]] },
  { id: 'nf', icon: 'fa-triangle-exclamation', label: '404 Page', sub: '', fields: [
    ['text', 'nf_title', 'Heading'], ['textarea', 'nf_text', 'Text'],
    ['text', 'nf_post_title', 'Heading (missing post)'], ['textarea', 'nf_post_text', 'Text (missing post)'],
    ['toggle', 'nf_courses', 'Latest courses'], ['toggle', 'nf_cats', 'Popular categories'], ['toggle', 'nf_posts', 'Latest posts'],
  ] },
  { id: 'f_scroll', icon: 'fa-arrow-up', label: 'Scroll To Top', sub: '', fields: [['toggle', 'f_totop', 'Enable scroll to top']] },
  { id: 'buttons', icon: 'fa-square', label: 'Buttons', sub: '', design: ['buttons'], render: renderButtons },
  { id: 'footer', icon: 'fa-shoe-prints', label: 'Footer Columns', design: ['footer_widgets'], sub: 'Columns, follow box, copyright', render: renderFooter },
  { id: 'sidebar', icon: 'fa-table-columns', design: ['sidebar'], label: 'Sidebar', sub: 'Where it shows and what it lists', fields: [
    ['toggle', 'sb_post', 'Posts (articles)'],
    ['toggle', 'sb_search', 'Search box'],
    ['text', 'sb_search_heading', 'Search heading'],
    ['text', 'sb_heading', 'Posts heading', { hint: '{category} = the post’s category.' }],
    ['range', 'sb_count', 'Number of posts', { min: 3, max: 10 }],
    ['seg', 'sb_source', 'Posts', { options: [['category', 'Same category'], ['latest', 'Latest']] }],
    ['toggle', 'sb_cats', 'Categories'],
    ['text', 'sb_cats_heading', 'Categories heading'],
    ['range', 'sb_cats_count', 'How many categories', { min: 3, max: 30 }],
    ['toggle', 'sb_sticky', 'Sticky while scrolling'],
  ]},
  { id: 'post', icon: 'fa-file-lines', label: 'Single Post', sub: '', design: ['cards'], render: renderPost, defs: [
    ['Top of the post', [['toggle', 'p_breadcrumb', 'Breadcrumb'], ['toggle', 'p_cat_badge', 'Category label (⚡ CATEGORY)'], ['toggle', 'p_author_date', 'Author & date row'], ['seg', 'p_date_shown', 'Date shown', { options: [['updated', 'Last updated'], ['published', 'Published']] }], ['toggle', 'p_reading_time', 'Reading time'], ['toggle', 'p_pref_source', '“Add as a preferred source on Google”'], ['toggle', 'p_gnews', 'Google News icon (own box)'], ['text', 'p_gnews_url', 'Google News link', { hint: 'Your publication page on news.google.com.' }]]],
    ['Featured image', [['toggle', 'p_featured', 'Featured image'], ['toggle', 'p_caption', 'Caption under the image (alt text)']]],
    ['Summary & Key Points', [['toggle', 'p_summary', 'Summary box'], ['text', 'p_summary_heading', 'Summary heading'], ['seg', 'p_summary_pos', 'Summary position', { options: [['before_featured', 'Before image'], ['after_featured', 'After image'], ['before_content', 'Before content']] }], ['toggle', 'p_keypoints', 'Key Points box'], ['text', 'p_keypoints_heading', 'Key Points heading'], ['seg', 'p_keypoints_style', 'Bullet style', { options: [['dot', 'Dot'], ['check', 'Check'], ['number', 'Number']] }], ['range', 'p_keypoints_after', 'Show after paragraph (0 = above the article)', { min: 0, max: 6 }]]],
    ['Table of contents', [['toggle', 'p_toc', 'Table of contents'], ['text', 'p_toc_heading', 'Heading'], ['toggle', 'p_toc_collapsed', 'Start collapsed']]],
    ['End of the post', [['toggle', 'p_faqs', 'FAQs'], ['toggle', 'p_tags', 'Tags'], ['toggle', 'p_share', 'Share buttons'], ['toggle', 'p_author_box', 'Author box'], ['toggle', 'p_comments', 'Comments']]],
    ['Related posts', [['toggle', 'p_related', 'Related posts grid'], ['text', 'p_related_heading', 'Related heading'], ['range', 'p_related_count', 'How many', { min: 2, max: 9 }]]],
    ['Join boxes', [['toggle', 'p_join', 'Join WhatsApp / Telegram boxes'], ['text', 'p_join_whatsapp', 'WhatsApp link'], ['text', 'p_join_telegram', 'Telegram link']]],
  ]},
  { id: 'alsoread', icon: 'fa-newspaper', label: 'Also Read', sub: 'Related-post boxes inside articles', render: renderAlsoRead },
  { id: 'progress', icon: 'fa-circle-notch', label: 'Reading Progress', sub: 'Floating progress button on articles', fields: [
    ['toggle', 'rp_button', 'Reading progress button', { hint: 'A floating pill that fills as the reader scrolls, shows the section and minutes left, and lists the H2 headings to jump to.' }],
    ['text', 'rp_label', 'Title of the sections list'],
    ['toggle', 'rp_desktop', 'Also show on desktop'],
    ['toggle', 'rp_bar', 'Thin progress bar at the top of articles'],
    ['hint', 'Switch the preview to Mobile (bottom of this panel) to see the button. The sections list shows the article’s H2 headings.'],
  ]},
  { id: 'home', icon: 'fa-table-cells-large', label: 'Blog / Archive', sub: 'Latest Update page, post grids and lists', sections: [
    ['Latest Update page (blog)', [['toggle', 'home_hero', 'Search box & category buttons'], ['text', 'home_heading', 'Heading above the posts (optional)'], ['range', 'home_posts', 'Posts on the homepage', { min: 0, max: 30 }], ['toggle', 'home_lead', 'First post as a large card'], ['toggle', 'home_grid_more', '“Read more” button on the 3-per-row cards'], ['toggle', 'home_tables', 'Job tables (Recent Updates, Admit Card, Answer Key, Result)'], ['toggle', 'home_faqs', 'FAQs']]],
    ['Archives (categories, tags, authors, search)', [['range', 'arch_columns', 'Columns', { min: 2, max: 4 }]]],
  ]},
  { id: 'consent', icon: 'fa-cookie-bite', label: 'Cookie Consent', sub: 'Privacy popup for Google & AdSense', sections: [
    ['Popup', [['toggle', 'cc_enabled', 'Show the cookie consent popup'], ['seg', 'cc_region', 'Show to', { options: [['all', 'Everyone'], ['eea', 'EEA, UK & CH only']] }], ['seg', 'cc_position', 'Position', { options: [['bottom', 'Bottom bar'], ['left', 'Bottom left'], ['center', 'Center']] }], ['hint', 'Google Consent Mode v2 is set up automatically: ads and analytics wait for the visitor’s choice.']]],
    ['Texts', [['text', 'cc_title', 'Title'], ['textarea', 'cc_message', 'Message'], ['text', 'cc_accept', '“Accept” button'], ['text', 'cc_reject', '“Reject” button'], ['text', 'cc_customize', '“Customize” button'], ['text', 'cc_save', '“Save” button (in Customize)'], ['text', 'cc_privacy_text', 'Privacy link text'], ['text', 'cc_privacy_url', 'Privacy policy URL']]],
    ['Behaviour', [['toggle', 'cc_sticky', 'Sticky cookie button (to change the choice later)'], ['select', 'cc_reask', 'Show the popup again', { options: [['never', 'Never (remember the choice)'], ['daily', 'Once a day if rejected'], ['visit', 'Every visit if rejected']] }], ['range', 'cc_days', 'Remember the choice for', { min: 30, max: 395, unit: 'days' }], ['toggle', 'cc_footer_link', '“Cookie settings” link in the footer']]],
  ]},
  { id: 'shortcodes', icon: 'fa-code', label: 'Shortcodes', sub: 'Codes you can use anywhere', render: renderShortcodes },
  { id: 'css', icon: 'fa-code', label: 'Additional CSS', sub: 'Your own CSS rules', fields: [
    ['code', 'custom_css', 'CSS'],
  ]},
];

const $ = id => document.getElementById(id);
let dirty = false, panel = null, draftTimer = null, subOpen = null;
// the list on the first screen, ordered and grouped like the WordPress (GeneratePress / Astra) Customizer
const CZ_NAV = ['identity', { id: 'global', label: 'Global', items: ['typography', 'colors', 'container', 'buttons'] },
  { id: 'header', label: 'Header', items: ['h_primary', 'h_strip', 'h_search', 'h_account', 'h_button', 'h_offcanvas', 'h_above'] },
  { id: 'posttypes', label: 'Post Types', items: ['home', 'post', 'alsoread', 'progress'] }, 'homepage', 'sidebar',
  { id: 'footer', label: 'Footer', items: ['footer', 'f_copy'] }, { id: 'general', label: 'General', items: ['f_scroll', 'nf', 'consent', 'shortcodes'] },
  'menus', 'css'];
const CZ_STORE = <?= json_encode(is_file(ROOT_PATH . '/account/login.php')) ?>;
if (CZ_STORE) { const oc = PANELS.find(x => x.id === 'h_offcanvas'); if (oc) oc.fields = oc.fields.filter(f => f[1] !== 'h_drawer_sub').map(f => f[1] === 'h_drawer_login' ? ['toggle', 'h_drawer_login', 'Courses & Student Login buttons'] : f); }
const CZ_DEP = { sb_search: 'sb_post', sb_cats: 'sb_post', sb_search_heading: 'sb_search', sb_cats_heading: 'sb_cats', sb_cats_count: 'sb_cats', h_voice: 'h_search', h_search_placeholder: 'h_search', h_login_text: 'h_login', h_login_url: 'h_login', h_sub_text: 'h_sub', h_sub_icon: 'h_sub', h_sub_url: 'h_sub',
  h_drawer_title: 'h_drawer_hello', h_drawer_text: 'h_drawer_hello', h_drawer_sub: 'h_sub', h_topbar_text: 'h_topbar', sb_heading: 'sb_post', sb_count: 'sb_post', sb_source: 'sb_post', sb_sticky: 'sb_post',
  rp_label: 'rp_button', rp_desktop: 'rp_button', f_play_url: 'f_play' };
if (!CZ_STORE) CZ_DEP.h_drawer_login = 'h_login';
['cc_region', 'cc_position', 'cc_title', 'cc_message', 'cc_accept', 'cc_reject', 'cc_customize', 'cc_save', 'cc_privacy_text', 'cc_privacy_url', 'cc_sticky', 'cc_reask', 'cc_days', 'cc_footer_link'].forEach(k => CZ_DEP[k] = 'cc_enabled');
const CZ_PARENTS = new Set(Object.values(CZ_DEP));
CZ_NAV.forEach(n => { if (typeof n === 'object') n.items.forEach(id => { const p = PANELS.find(x => x.id === id); if (p && !p._group) p._group = n; }); });
let czFrom, czTab = 'general', eyeOpen = null, typoOpen = null; // where a panel was opened from (group or the first screen), General / Design tab
const czAllowed = p => !!p && (!CZ_ONLY || CZ_ONLY.includes(p.id));
function exitPanel() { const g = czFrom !== undefined ? czFrom : (panel && panel._group); if (g && g.items.some(id => czAllowed(PANELS.find(x => x.id === id)))) showGroup(g); else showHome(); }
function czRow(label, onClick) { const r = html('<button type="button" class="cz-subrow"><span></span><span class="cz-subrow-r"><i class="fas fa-chevron-right" aria-hidden="true"></i></span></button>'); r.firstChild.textContent = label; r.onclick = onClick; return r; }
function showGroup(g) {
  panel = null; subOpen = null; document.querySelectorAll('.cz-additems').forEach(x => x.remove());
  $('cz-back').hidden = false; $('cz-back').onclick = showHome; $('cz-head-small').textContent = 'Customizing'; $('cz-head-title').textContent = g.label;
  const b = $('cz-body'); b.innerHTML = ''; b.classList.remove('hm-bg');
  const list = html('<div class="cz-subs cz-subs-top"></div>');
  g.items.map(id => PANELS.find(x => x.id === id)).filter(czAllowed).forEach(p => list.appendChild(czRow(p.id === 'identity' ? 'Site Title & Logo' : p.label, () => { czFrom = g; showPanel(p); })));
  b.appendChild(list);
}
const getV = k => k.startsWith('g.') ? state.general[k.slice(2)] : state.theme[k];
function setV(k, v) {
  if (k.startsWith('g.')) state.general[k.slice(2)] = v; else state.theme[k] = v;
  touch();
}
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
function post(action, extra) {
  const fd = new FormData(); fd.append('_csrf', CSRF); fd.append('action', action); fd.append('data', JSON.stringify(state));
  return fetch('/admin/customize.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(r => r.json());
}
function pushDraft() { $('cz-refreshing').hidden = false; post('draft').then(reloadFrame); }
let framePath = '/';
function reloadFrame() {
  $('cz-loading').hidden = false; $('cz-refreshing').hidden = false;
  const f = $('cz-frame'); const y = (() => { try { return f.contentWindow.scrollY; } catch (e) { return 0; } })();
  f.onload = () => { $('cz-loading').hidden = true; $('cz-refreshing').hidden = true; try { f.contentWindow.scrollTo(0, y); } catch (e) {} };
  f.src = framePath + (framePath.includes('?') ? '&' : '?') + 'czp=1&t=' + Date.now();
}
$('cz-frame').onload = () => { $('cz-loading').hidden = true; };

// --- field renderers ---
function field(f) {
  const [type, key, label, ex = {}] = f;
  const w = document.createElement('div');
  if (type === 'hint') { w.className = 'cz-hint'; w.textContent = key; return w; }
  if (type === 'heading') { const h = document.createElement('h3'); h.className = 'cz-heading'; h.textContent = key; return h; }
  for (let k = key; CZ_DEP[k]; k = CZ_DEP[k]) if (String(getV(CZ_DEP[k])) !== '1') return document.createComment(key); // a main switch above it is off
  const v = getV(key);
  if (type === 'toggle') {
    w.innerHTML = '<label class="cz-toggle"><span class="cz-toggle-text">' + esc(label) + (ex.hint ? '<small>' + esc(ex.hint) + '</small>' : '') + '</span><input type="checkbox"' + (String(v) === '1' ? ' checked' : '') + '><span class="cz-switch" aria-hidden="true"></span></label>';
    w.querySelector('input').onchange = e => { setV(key, e.target.checked ? '1' : '0'); if (CZ_PARENTS.has(key)) rerender(); };
  } else if (type === 'text' || type === 'textarea' || type === 'code') {
    w.innerHTML = '<label class="cz-field"><span class="cz-label">' + esc(label) + '</span>' + (type === 'text' ? '<input type="text">' : '<textarea rows="' + (type === 'code' ? 14 : 4) + '"' + (type === 'code' ? ' class="cz-codearea" spellcheck="false"' : '') + '></textarea>') + (ex.hint ? '<small class="cz-hint">' + esc(ex.hint) + '</small>' : '') + '</label>';
    const i = w.querySelector('input,textarea'); i.value = v ?? ''; if (ex.placeholder) i.placeholder = ex.placeholder; i.oninput = e => setV(key, e.target.value);
  } else if (type === 'range') {
    w.innerHTML = '<div class="cz-field"><span class="cz-label">' + esc(label) + '</span><div class="cz-range"><input type="range" min="' + ex.min + '" max="' + ex.max + '" step="' + (ex.step || 1) + '"><span class="cz-num"><input type="number" min="' + ex.min + '" max="' + ex.max + '" step="' + (ex.step || 1) + '">' + (ex.unit ? '<em>' + ex.unit + '</em>' : '') + '</span></div></div>';
    const [r, n] = w.querySelectorAll('input'); r.value = n.value = v;
    r.oninput = () => { n.value = r.value; setV(key, Number(r.value)); };
    n.oninput = () => { r.value = n.value; setV(key, Number(n.value)); };
  } else if (type === 'select') {
    w.innerHTML = '<label class="cz-field"><span class="cz-label">' + esc(label) + '</span><select>' + ex.options.map(o => '<option value="' + esc(o[0]) + '">' + esc(o[1]) + '</option>').join('') + '</select></label>';
    const s = w.querySelector('select'); s.value = v; s.onchange = () => setV(key, s.value);
  } else if (type === 'seg') {
    w.innerHTML = '<div class="cz-field"><span class="cz-label">' + esc(label) + '</span><div class="cz-seg">' + ex.options.map(o => '<button type="button" data-v="' + esc(o[0]) + '"' + (o[0] === v ? ' class="active"' : '') + '>' + esc(o[1]) + '</button>').join('') + '</div></div>';
    w.querySelectorAll('button').forEach(b => b.onclick = () => { w.querySelectorAll('button').forEach(x => x.classList.toggle('active', x === b)); setV(key, b.dataset.v); });
  } else if (type === 'color') {
    w.innerHTML = '<div class="cz-color"><span class="cz-color-label">' + esc(label) + (DEFAULTS[key] === '' ? '<small>' + (v ? 'Custom' : 'Follows the accent color') + '</small>' : '') + '</span><button type="button" class="cz-swatch" title="' + esc(v || 'Accent') + '"></button></div>';
    const sw = w.querySelector('.cz-swatch'); sw.style.background = v || getV('c_accent');
    sw.onclick = () => {
      let p = w.querySelector('.cz-picker'); if (p) { p.remove(); return; }
      p = document.createElement('div'); p.className = 'cz-picker';
      p.innerHTML = '<div class="cz-picker-title">Custom</div><div class="cz-picker-row"><input type="color"><input type="text" placeholder="#hex"></div><div class="cz-picker-row"><button type="button" class="cz-link-btn"><i class="fas fa-rotate-left"></i> Default</button><button type="button" class="cz-btn-primary cz-btn-sm">Done</button></div>';
      const [ci, ti] = p.querySelectorAll('input'); ci.value = /^#[0-9a-f]{6}$/i.test(getV(key)) ? getV(key) : '#000000'; ti.value = getV(key);
      const apply = val => { sw.style.background = val; sw.title = val; setV(key, val); };
      ci.oninput = () => { ti.value = ci.value; apply(ci.value); };
      ti.oninput = () => { if (/^#[0-9a-f]{3,8}$/i.test(ti.value.trim())) { ci.value = ti.value.trim().length === 7 ? ti.value.trim() : ci.value; apply(ti.value.trim()); } };
      p.querySelector('.cz-link-btn').onclick = () => { const d = DEFAULTS[key]; ci.value = d || getV('c_accent'); ti.value = d; apply(d); sw.style.background = d || getV('c_accent'); };
      p.querySelector('.cz-btn-primary').onclick = () => p.remove();
      w.querySelector('.cz-color').appendChild(p);
    };
  } else if (type === 'media') {
    w.innerHTML = '<div class="cz-field"><span class="cz-label">' + esc(label) + '</span><div class="cz-media-box"></div><div class="cz-btn-row"><button type="button" class="cz-btn-outline" data-a="pick">Select image</button><button type="button" class="cz-link-btn" data-a="clear">Remove</button></div>' + (ex.hint ? '<small class="cz-hint">' + esc(ex.hint) + '</small>' : '') + '</div>';
    const box = w.querySelector('.cz-media-box');
    const show = () => { const cur = getV(key); box.innerHTML = cur ? '<img src="' + esc(cur) + '" alt="">' : 'No image selected'; };
    show();
    w.querySelector('[data-a=pick]').onclick = () => EduMedia.pick(it => { setV(key, it.path); show(); });
    w.querySelector('[data-a=clear]').onclick = () => { setV(key, ''); show(); };
  } else if (type === 'list') {
    w.className = 'cz-field';
    const render = () => {
      const items = (getV(key) || []).slice();
      w.innerHTML = '<span class="cz-label">' + esc(label) + '</span>' + items.map((it, i) => '<div class="cz-list-item" data-i="' + i + '"><input type="text" placeholder="Label" data-f="label" value="' + esc(it.label) + '"><input type="text" placeholder="/url or https://" data-f="url" value="' + esc(it.url) + '"><span class="cz-li-btns"><button type="button" data-m="up" title="Move up">↑</button><button type="button" data-m="del" title="Remove">✕</button></span></div>').join('') + '<button type="button" class="cz-btn-outline cz-list-add">+ Add link</button>';
      w.querySelectorAll('.cz-list-item input').forEach(inp => inp.oninput = () => { const i = +inp.closest('.cz-list-item').dataset.i; items[i][inp.dataset.f] = inp.value; setV(key, items); });
      w.querySelectorAll('[data-m]').forEach(b => b.onclick = () => {
        const i = +b.closest('.cz-list-item').dataset.i;
        if (b.dataset.m === 'del') items.splice(i, 1); else if (i > 0) [items[i - 1], items[i]] = [items[i], items[i - 1]];
        setV(key, items); render();
      });
      w.querySelector('.cz-list-add').onclick = () => { items.push({ label: '', url: '' }); state.theme[key] = items; render(); };
    };
    render();
  }
  return w;
}
function section(title, fields, open) {
  const s = document.createElement('div'); s.className = 'cz-section' + (open ? ' open' : '');
  s.innerHTML = '<button type="button" class="cz-section-head"><span>' + esc(title) + '</span><i class="fas fa-chevron-' + (open ? 'up' : 'down') + '"></i></button><div class="cz-section-body"' + (open ? '' : ' hidden') + '></div>';
  const body = s.querySelector('.cz-section-body'); fields.forEach(f => body.appendChild(field(f)));
  s.querySelector('.cz-section-head').onclick = () => { const o = body.hidden; body.hidden = !o; s.classList.toggle('open', o); s.querySelector('.cz-section-head i').className = 'fas fa-chevron-' + (o ? 'up' : 'down'); };
  return s;
}
// ===== Customizer v2 panels (same controls as the CMS): menus, footer columns, colors, typography, Also Read, shortcodes =====
const html = s => { const t = document.createElement('template'); t.innerHTML = String(s).trim(); return t.content.firstElementChild; };
function touch() {
  dirty = true; $('cz-status').textContent = 'Unsaved changes'; $('cz-publish').disabled = false; $('cz-publish').textContent = 'Publish';
  clearTimeout(draftTimer); draftTimer = setTimeout(pushDraft, 450);
}
function rerender() { if (panel) { const y = $('cz-body').scrollTop; showPanel(panel, true); $('cz-body').scrollTop = y; } }
function hintEl(t) { return html('<p class="cz-hint">' + esc(t) + '</p>'); }
function circleBtn(icon, title, onClick, danger) {
  const b = html('<button type="button" class="cz-circle' + (danger ? ' danger' : '') + '" title="' + esc(title) + '" aria-label="' + esc(title) + '"><i class="fas ' + icon + '"></i></button>');
  b.onclick = onClick; return b;
}
// Like WordPress: a section is a row with a chevron; clicking it opens the section on its own screen
// ("Customizing ▸ Panel" + back arrow). showPanel() swaps the panel for the open section after rendering.
let subCapture = null, subPrevBack = null;
function sectionEl(title, build, open, badge) {
  if (subOpen === title) { const body = html('<div class="cz-sub-body"></div>'); build(body); if (!body.children.length) body.appendChild(hintEl('Switch this on first in the section above.')); subCapture = body; return document.createComment('section'); }
  const r = html('<button type="button" class="cz-subrow"><span></span><span class="cz-subrow-r">' + (badge ? '<em class="cz-badge">' + esc(badge) + '</em>' : '') + '<i class="fas fa-chevron-right" aria-hidden="true"></i></span></button>');
  r.firstChild.textContent = title;
  r.onclick = () => { subOpen = title; showPanel(panel, true); $('cz-body').scrollTop = 0; };
  return r;
}
// controls bound to a getter / setter (for values inside lists)
function cText(label, get, set, o = {}) {
  const w = html('<label class="cz-field"><span class="cz-label">' + esc(label) + '</span>' + (o.multi ? '<textarea rows="4"></textarea>' : '<input type="text">') + (o.hint ? '<small class="cz-hint">' + esc(o.hint) + '</small>' : '') + '</label>');
  const i = w.querySelector('input,textarea'); i.value = get() ?? ''; if (o.placeholder) i.placeholder = o.placeholder;
  i.oninput = () => { set(i.value); touch(); }; return w;
}
function cToggle(label, get, set, hint) {
  const w = html('<label class="cz-toggle"><span class="cz-toggle-text">' + esc(label) + (hint ? '<small>' + esc(hint) + '</small>' : '') + '</span><input type="checkbox"><span class="cz-switch" aria-hidden="true"></span></label>');
  const i = w.querySelector('input'); i.checked = !!get(); i.onchange = () => { set(i.checked); touch(); }; return w;
}
function cRange(label, get, set, min, max, step = 1, unit = '') {
  const w = html('<div class="cz-field"><span class="cz-label">' + esc(label) + '</span><div class="cz-range"><input type="range" min="' + min + '" max="' + max + '" step="' + step + '"><span class="cz-num"><input type="number" min="' + min + '" max="' + max + '" step="' + step + '">' + (unit ? '<em>' + unit + '</em>' : '') + '</span></div></div>');
  const [r, n] = w.querySelectorAll('input'); r.value = n.value = get();
  const go = v => { v = Math.max(min, Math.min(max, Number(v) || min)); set(v); touch(); };
  r.oninput = () => { n.value = r.value; go(r.value); }; n.onchange = () => { r.value = n.value; go(n.value); }; return w;
}
function cSelect(label, get, set, options, hint) {
  const w = html('<label class="cz-field"><span class="cz-label">' + esc(label) + '</span><select>' + options.map(o => '<option value="' + esc(o[0]) + '">' + esc(o[1]) + '</option>').join('') + '</select>' + (hint ? '<small class="cz-hint">' + esc(hint) + '</small>' : '') + '</label>');
  const s = w.querySelector('select'); s.value = get(); s.onchange = () => { set(s.value); touch(); }; return w;
}
function cSeg(label, get, set, options, after) {
  const w = html('<div class="cz-field">' + (label ? '<span class="cz-label">' + esc(label) + '</span>' : '') + '<div class="cz-seg">' + options.map(o => '<button type="button" data-v="' + esc(o[0]) + '"' + (o[0] === get() ? ' class="active"' : '') + '>' + (o[2] ? '<i class="fas ' + o[2] + '"></i> ' : '') + esc(o[1]) + '</button>').join('') + '</div></div>');
  w.querySelectorAll('button').forEach(b => b.onclick = () => { w.querySelectorAll('button').forEach(x => x.classList.toggle('active', x === b)); set(b.dataset.v); touch(); if (after) after(); });
  return w;
}

/* ---------- Site Identity: the one header brand — logo, site title, line under it ---------- */
function renderIdentity(wrap) { // the same values as Settings → General (site title, tagline, logo), like WordPress
  const T = state.theme, G = state.general;
  const pv = html('<div class="id-pv" aria-hidden="true"><span class="id-pv-in"><img alt=""><span class="id-pv-t"><b></b><small></small></span></span></div>');
  const paint = () => {
    const im = pv.querySelector('img'); im.hidden = !G.logo; if (G.logo) im.src = G.logo; else im.removeAttribute('src');
    pv.querySelector('img').style.height = Math.max(36, Math.min(80, +T.id_emblem_h || 52)) * .85 + 'px';
    pv.querySelector('b').textContent = G.site_title || SITE_NAME_DEFAULT;
    pv.querySelector('small').textContent = G.tagline || ID_ADDRESS || '';
    pv.classList.toggle('is-plain', String(T.id_brand_bg) === '0');
  };
  const g1 = html('<div class="id-grp"></div>'); g1.appendChild(pv); wrap.appendChild(g1);
  const g2 = html('<div class="id-grp"></div>');
  const lg = field(['media', 'g.logo', 'Logo']), sz = field(['range', 'id_emblem_h', 'Logo size', { min: 36, max: 80, unit: 'px' }]);
  const bg = field(['toggle', 'id_brand_bg', 'Background design behind the logo and title']);
  g2.append(lg, sz, bg); wrap.appendChild(g2);
  const g3 = html('<div class="id-grp"></div>');
  const tt = html('<label class="cz-field"><span class="cz-label">Site Title</span><input type="text" maxlength="100"></label>');
  const ti = tt.querySelector('input'); ti.value = G.site_title || SITE_NAME_DEFAULT; ti.placeholder = SITE_NAME_DEFAULT;
  ti.oninput = () => { G.site_title = ti.value; touch(); paint(); };
  const ln = html('<label class="cz-field"><span class="cz-label">Tagline</span><input type="text" maxlength="200"></label>');
  const li = ln.querySelector('input'); li.value = G.tagline || ''; li.placeholder = ID_ADDRESS || '';
  li.oninput = () => { G.tagline = li.value; touch(); paint(); };
  g3.append(tt, ln); wrap.appendChild(g3);
  const g4 = html('<div class="id-grp"></div>'); g4.appendChild(field(['media', 'g.favicon', 'Site Icon'])); wrap.appendChild(g4);
  [lg, sz, bg].forEach(el => { el.addEventListener('input', () => setTimeout(paint)); el.addEventListener('change', () => setTimeout(paint)); });
  new MutationObserver(paint).observe(lg, { subtree: true, childList: true });
  paint();
}

/* ---------- Colors ---------- */
const COLOR_RX = /^(#[0-9a-f]{3,8}|(rgb|rgba|hsl|hsla)\([0-9.,%\s\/deg]+\)|transparent)$/i;
function toHex(v) { return /^#[0-9a-f]{6}$/i.test(v) ? v : /^#[0-9a-f]{3}$/i.test(v) ? '#' + v.slice(1).split('').map(c => c + c).join('') : '#ffffff'; }
function colorCtl(label, get, set) {
  const w = html('<div class="cz-color"><span class="cz-color-label"></span><button type="button" class="cz-swatch"></button></div>');
  const lab = w.querySelector('.cz-color-label'), sw = w.querySelector('.cz-swatch');
  const pal = () => state.theme.global_colors || [];
  const shown = () => { const v = get() || '', m = /^var\(--gc-([\w-]+)\)$/.exec(v), g = m && pal().find(p => p.slug === m[1]); return [m ? (g ? g.color : '') : v, g ? g.name : '']; };
  w._paint = () => { const [c, name] = shown(), v = get() || ''; sw.style.background = c || ''; sw.classList.toggle('is-empty', !v); sw.title = v || 'Default'; lab.innerHTML = esc(label) + (name ? '<small>' + esc(name) + '</small>' : ''); };
  w._paint();
  sw.onclick = () => {
    let p = w.querySelector('.cz-picker'); if (p) { p.remove(); return; }
    document.querySelectorAll('.cz-picker').forEach(x => x.remove());
    p = html('<div class="cz-picker"><div class="cz-picker-title">Global colors</div><div class="cz-picker-palette"></div><div class="cz-picker-title">Custom</div><div class="cz-picker-row"><input type="color"><input type="text" placeholder="#hex or rgba()"></div><div class="cz-picker-row"><button type="button" class="cz-link-btn"><i class="fas fa-rotate-left"></i> Default</button><button type="button" class="cz-btn-primary cz-btn-sm">Done</button></div></div>');
    const box = p.querySelector('.cz-picker-palette'), [ci, ti] = p.querySelectorAll('input');
    pal().forEach(g => {
      const b = html('<button type="button" class="cz-pal" title="' + esc(g.name) + '"></button>'); b.style.background = g.color;
      if (get() === 'var(--gc-' + g.slug + ')') b.classList.add('active');
      b.onclick = () => { set('var(--gc-' + g.slug + ')'); touch(); w._paint(); box.querySelectorAll('.cz-pal').forEach(x => x.classList.toggle('active', x === b)); ti.value = get(); ci.value = toHex(g.color); };
      box.appendChild(b);
    });
    ci.value = toHex(shown()[0]); ti.value = get() || '';
    ci.oninput = () => { ti.value = ci.value; set(ci.value); touch(); w._paint(); box.querySelectorAll('.cz-pal').forEach(x => x.classList.remove('active')); };
    ti.oninput = () => { const v = ti.value.trim(); if (v === '' || COLOR_RX.test(v)) { set(v); touch(); w._paint(); if (/^#[0-9a-f]{6}$/i.test(v)) ci.value = v; } };
    p.querySelector('.cz-link-btn').onclick = () => { set(''); touch(); ti.value = ''; w._paint(); box.querySelectorAll('.cz-pal').forEach(x => x.classList.remove('active')); };
    p.querySelector('.cz-btn-primary').onclick = () => p.remove();
    w.appendChild(p);
  };
  return w;
}
document.addEventListener('mousedown', e => { document.querySelectorAll('.cz-picker').forEach(p => { if (!p.parentNode.contains(e.target)) p.remove(); }); });
let gpalEditing = null;
function renderColors(wrap) {
  const T = state.theme;
  if (!T.colors || Array.isArray(T.colors)) T.colors = {};
  Object.keys(T.colors).forEach(g => { if (Array.isArray(T.colors[g])) T.colors[g] = {}; });
  if (!Array.isArray(T.global_colors)) T.global_colors = [];
  const block = html('<div class="cz-gpalbox"><span class="cz-block-actions"></span><div class="cz-gpal"></div><div></div></div>');
  const repaintAll = () => wrap.querySelectorAll('.cz-color').forEach(x => x._paint && x._paint());
  const draw = () => {
    const box = block.querySelector('.cz-gpal'), ed = block.children[2]; box.innerHTML = ''; ed.innerHTML = '';
    T.global_colors.forEach((g, i) => {
      const b = html('<button type="button" class="cz-gpal-item' + (gpalEditing === i ? ' active' : '') + '" title="' + esc(g.name) + ' — click to edit"></button>');
      b.style.background = g.color; b.onclick = () => { gpalEditing = gpalEditing === i ? null : i; draw(); }; box.appendChild(b);
    });
    const g = gpalEditing !== null ? T.global_colors[gpalEditing] : null;
    if (g) {
      const row = html('<div class="cz-gpal-edit"><input type="color"><input class="cz-gpal-hex"><input class="cz-gpal-name" placeholder="Name"></div>');
      const [c, h, n] = row.querySelectorAll('input'); c.value = toHex(g.color); h.value = g.color; n.value = g.name;
      const swatch = () => { box.children[gpalEditing].style.background = g.color; repaintAll(); };
      c.oninput = () => { g.color = c.value; h.value = c.value; touch(); swatch(); };
      h.oninput = () => { const v = h.value.trim(); if (COLOR_RX.test(v)) { g.color = v; if (/^#[0-9a-f]{6}$/i.test(v)) c.value = v; touch(); swatch(); } };
      n.oninput = () => { g.name = n.value; touch(); };
      if (gpalEditing > 6) row.appendChild(circleBtn('fa-trash', 'Delete color', () => { T.global_colors.splice(gpalEditing, 1); gpalEditing = null; touch(); draw(); repaintAll(); }, true));
      ed.appendChild(row);
      if (g.slug === 'accent') ed.appendChild(hintEl('Accent = links, buttons, badges and highlights across the site.'));
    }
  };
  block.querySelector('.cz-block-actions').appendChild(circleBtn('fa-plus', 'Add color', () => { T.global_colors.push({ slug: 'custom-' + Date.now().toString(36), name: 'Custom', color: '#888888' }); gpalEditing = T.global_colors.length - 1; touch(); draw(); }));
  draw();
  // Global Palette: ready-made palettes, then the swatches (click one to edit it, + adds one)
  const pal = html('<div class="cz-wpgroup"><h3 class="cz-wp-h">Global Palette</h3><div class="cz-presets"></div></div>');
  PALETTES.forEach(([name, cols]) => {
    const cur = Object.entries(cols).every(([sl, c]) => ((T.global_colors.find(g => g.slug === sl) || {}).color || '').toLowerCase() === c);
    const b = html('<button type="button" class="cz-preset' + (cur ? ' active' : '') + '"><span class="cz-preset-bars"></span><b></b></button>');
    ['accent', 'accent-2', 'contrast', 'contrast-2'].forEach(sl => { const i = document.createElement('i'); i.style.background = cols[sl]; b.firstChild.appendChild(i); });
    b.querySelector('b').textContent = name;
    b.onclick = () => { Object.entries(cols).forEach(([sl, c]) => { const g = T.global_colors.find(x => x.slug === sl); if (g) g.color = c; }); gpalEditing = null; touch(); rerender(); };
    pal.querySelector('.cz-presets').appendChild(b);
  });
  pal.appendChild(block); wrap.appendChild(pal);
  const set = (g, f) => v => { if (!T.colors[g] || Array.isArray(T.colors[g])) T.colors[g] = {}; if (v) T.colors[g][f] = v; else delete T.colors[g][f]; };
  const get = (g, f) => () => (T.colors[g] || {})[f] || '';
  const acc = T.global_colors.find(x => x.slug === 'accent');
  const th = html('<div class="cz-wpgroup"><h3 class="cz-wp-h">Theme Color</h3></div>');
  if (acc) th.appendChild(colorCtl('Accent', () => acc.color, v => { if (v && !/^var\(/.test(v)) { acc.color = v; draw(); } }));
  [['body', 'link', 'Links'], ['body', 'link_hover', 'Links hover'], ['content', 'title', 'Heading (H1–H6)'], ['body', 'text', 'Body Text'], ['cards', 'border', 'Borders']].forEach(([g, f, l]) => th.appendChild(colorCtl(l, get(g, f), set(g, f))));
  wrap.appendChild(th);
  const sf = html('<div class="cz-wpgroup"><h3 class="cz-wp-h">Surface Color</h3></div>');
  [['body', 'background', 'Site Background'], ['content', 'background', 'Content Background']].forEach(([g, f, l]) => sf.appendChild(colorCtl(l, get(g, f), set(g, f))));
  wrap.appendChild(sf);
  // the rest of the content colours (each part's own colours are in its Design tab)
  [['content', 'Content'], ['cards', 'Cards & Boxes'], ['forms', 'Forms']].forEach(([g, label]) => {
    wrap.appendChild(sectionEl(label, body => Object.entries(COLOR_GROUPS[g][1]).forEach(([f, fl]) => body.appendChild(colorCtl(fl, get(g, f), set(g, f))))));
  });
}

/* ---------- Typography ---------- */
const SYSTEM_FONTS = ['Arial', 'Helvetica', 'Georgia', 'Times New Roman', 'Verdana', 'Tahoma', 'Trebuchet MS'];
const GOOGLE_FONTS = ['Noto Sans Devanagari', 'Noto Serif Devanagari', 'Hind', 'Mukta', 'Poppins', 'Inter', 'Roboto', 'Open Sans', 'Lato', 'Montserrat', 'Source Sans 3', 'Nunito', 'Nunito Sans',
  'Raleway', 'Rubik', 'Work Sans', 'PT Sans', 'Merriweather', 'Playfair Display', 'Lora', 'Oswald', 'Barlow', 'DM Sans', 'Manrope', 'Figtree', 'Lexend', 'Lexend Deca',
  'Libre Baskerville', 'Baloo 2', 'Tiro Devanagari Hindi', 'Khand', 'Rajdhani', 'Yatra One', 'Roboto Slab', 'Roboto Condensed', 'Ubuntu', 'Mulish', 'Quicksand', 'Josefin Sans',
  'Archivo', 'IBM Plex Sans', 'Plus Jakarta Sans', 'Outfit', 'Sora', 'Space Grotesk', 'Kanit', 'Prompt', 'Cairo', 'Tajawal', 'Noto Sans', 'Noto Serif', 'Source Serif 4'];
const VARIANTS = ['100', '200', '300', '400', '500', '600', '700', '800', '900', '400italic', '700italic'];
const WEIGHTS = [['', 'Default'], ...['100', '200', '300', '400', '500', '600', '700', '800', '900'].map(w => [w, w]), ['normal', 'Normal'], ['bold', 'Bold']];
const TYPO_GROUPS = [['Base', ['body', 'headings']], ['Header', ['site_title', 'tagline']], ['Navigation', ['primary_menu', 'sub_menu', 'menu_strip']],
  ['Content', ['buttons', 'post_title', 'archive_title', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'meta']], ['Widgets', ['widget_title']], ['Footer', ['footer']], ['Custom', ['custom']]];
let device = 'd', openFont = null, openRule = null;
function setDevice(d) {
  device = d;
  document.querySelectorAll('#cz-devices button').forEach(x => x.classList.toggle('active', x.dataset.dev === d));
  $('cz-wrap').className = 'cz-frame-wrap cz-dev-' + d;
  if (panel && panel.id === 'typography') rerender();
}
function newRule(target) { const e = () => ({ d: '', t: '', m: '' }); return { id: Math.random().toString(36).slice(2, 9), target, selector: '', family: '', weight: '', transform: '', style: '', decoration: '', size: e(), sizeUnit: 'px', lineHeight: e(), letterSpacing: e(), marginBottom: e() }; }
function respCtl(label, obj, unit, slider) {
  const v = obj[device] || '', fb = device === 'm' ? (obj.t || obj.d || '') : device === 't' ? (obj.d || '') : '';
  const w = html('<div class="cz-field"><span class="cz-label cz-label-row">' + esc(label) + '<span class="cz-devices cz-devices-sm">' + [['d', 'fa-desktop', 'Desktop'], ['t', 'fa-tablet-screen-button', 'Tablet'], ['m', 'fa-mobile-screen-button', 'Mobile']].map(([d, ic, t]) => '<button type="button" data-d="' + d + '" title="' + t + '"' + (device === d ? ' class="active"' : '') + '><i class="fas ' + ic + '"></i></button>').join('') + '</span></span><div class="cz-range"><input type="range" min="' + slider[0] + '" max="' + slider[1] + '" step="' + slider[2] + '"><span class="cz-num"><input type="text" inputmode="decimal">' + (unit ? '<em>' + unit + '</em>' : '') + '</span><button type="button" class="cz-reset" title="Reset"' + (v ? '' : ' hidden') + '><i class="fas fa-rotate-left"></i></button></div></div>');
  w.querySelectorAll('[data-d]').forEach(b => b.onclick = () => setDevice(b.dataset.d));
  const [r, n] = w.querySelectorAll('.cz-range input'), reset = w.querySelector('.cz-reset');
  r.value = v || fb || slider[0]; n.value = v; n.placeholder = fb;
  const put = val => { obj[device] = val; reset.hidden = !val; touch(); };
  r.oninput = () => { n.value = r.value; put(r.value); };
  n.oninput = () => { const x = n.value.trim(); if (x === '' || /^-?\d*\.?\d+$/.test(x)) { if (x !== '') r.value = x; put(x); } };
  reset.onclick = () => { n.value = ''; r.value = fb || slider[0]; put(''); };
  return w;
}
function renderTypoManager(wrap) {
  const T = state.theme;
  if (!Array.isArray(T.fonts)) T.fonts = [];
  if (!Array.isArray(T.typography)) T.typography = [];
  // Font Manager
  const fb = html('<div class="cz-wpbox"></div>');
  T.fonts.forEach((f, i) => {
    const it = html('<div class="cz-item' + (openFont === i ? ' open' : '') + '"><div class="cz-item-row"><span class="cz-item-title"></span></div></div>');
    const ttl = it.querySelector('.cz-item-title'); ttl.textContent = f.family || 'New font'; if (f.family) ttl.style.fontFamily = '"' + f.family + '", ' + (f.fallback || 'sans-serif');
    const row = it.querySelector('.cz-item-row');
    row.appendChild(circleBtn(openFont === i ? 'fa-chevron-up' : 'fa-chevron-down', 'Edit font', () => { openFont = openFont === i ? null : i; rerender(); }));
    row.appendChild(circleBtn('fa-trash-can', 'Delete font', () => { T.fonts.splice(i, 1); openFont = null; touch(); rerender(); }, true));
    if (openFont === i) {
      const card = html('<div class="cz-item-card"></div>');
      const cb = html('<div class="cz-field cz-combobox"><span class="cz-label">Font family name</span><input placeholder="Search Google Fonts or type a name"><div class="cz-combo-list" hidden></div></div>');
      const inp = cb.querySelector('input'), list = cb.querySelector('.cz-combo-list'); inp.value = f.family || '';
      const showList = () => {
        const q = inp.value.toLowerCase(), m = GOOGLE_FONTS.filter(x => !q || x.toLowerCase().includes(q)).slice(0, 12);
        list.innerHTML = ''; m.forEach(x => { const b = html('<button type="button"></button>'); b.textContent = x; b.onmousedown = e => e.preventDefault(); b.onclick = () => { inp.value = x; f.family = x; f.google = true; list.hidden = true; touch(); ttl.textContent = x; }; list.appendChild(b); });
        list.hidden = !m.length;
      };
      inp.onfocus = showList; inp.oninput = () => { f.family = inp.value; touch(); ttl.textContent = inp.value || 'New font'; showList(); }; inp.onblur = () => setTimeout(() => list.hidden = true, 150);
      card.appendChild(cb);
      card.appendChild(cToggle('Use Google Fonts API', () => f.google, v => { f.google = v; rerender(); }, 'Off = a font already installed on the device.'));
      if (f.google) {
        const vw = html('<div class="cz-field"><span class="cz-label">Variants</span><div class="cz-checks"></div></div>');
        VARIANTS.forEach(v => { const l = html('<label><input type="checkbox"> ' + v + '</label>'), c = l.querySelector('input'); c.checked = (f.variants || []).includes(v); c.onchange = () => { f.variants = c.checked ? [...(f.variants || []), v] : (f.variants || []).filter(x => x !== v); touch(); }; vw.querySelector('.cz-checks').appendChild(l); });
        card.appendChild(vw);
      }
      card.appendChild(cSelect('Category (fallback)', () => f.fallback || 'sans-serif', v => f.fallback = v, [['sans-serif', 'Sans-serif'], ['serif', 'Serif'], ['monospace', 'Monospace'], ['cursive', 'Handwriting'], ['system-ui', 'System UI']]));
      const close = html('<button type="button" class="cz-btn-outline cz-btn-sm">Close</button>'); close.onclick = () => { openFont = null; rerender(); }; card.appendChild(close);
      it.appendChild(card);
    }
    fb.appendChild(it);
  });
  const addF = html('<button type="button" class="cz-btn-primary">Add Font</button>');
  addF.onclick = () => { T.fonts.push({ family: '', google: true, variants: ['400', '700'], fallback: 'sans-serif' }); openFont = T.fonts.length - 1; touch(); rerender(); };
  fb.appendChild(addF);
  fb.appendChild(cSelect('Google font-display', () => T.font_display || 'swap', v => T.font_display = v, [['auto', 'Auto'], ['block', 'Block'], ['swap', 'Swap'], ['fallback', 'Fallback'], ['optional', 'Optional']]));
  wrap.appendChild(sectionEl('Font Manager', b => b.appendChild(fb), false, String(T.fonts.length || '')));
  // Typography Manager
  const tb = html('<div class="cz-wpbox"></div>');
  const fams = [['', 'Default'], ...T.fonts.filter(f => f.family).map(f => [f.family, f.family]), ...SYSTEM_FONTS.map(f => [f, f])];
  TYPO_GROUPS.forEach(([gl, targets]) => {
    const rules = T.typography.filter(r => targets.includes(r.target)); if (!rules.length) return;
    const grp = html('<div class="cz-typo-group"><h4>' + esc(gl) + '</h4></div>');
    rules.forEach(r => {
      const it = html('<div class="cz-item' + (openRule === r.id ? ' open' : '') + '"><div class="cz-item-row"><span class="cz-item-title"></span></div></div>');
      it.querySelector('.cz-item-title').textContent = [r.target === 'custom' ? (r.selector || 'Custom') : TYPO_TARGETS[r.target], r.family, r.size && r.size.d ? r.size.d + r.sizeUnit : ''].filter(Boolean).join(' / ');
      const row = it.querySelector('.cz-item-row');
      row.appendChild(circleBtn(openRule === r.id ? 'fa-chevron-up' : 'fa-chevron-down', 'Edit', () => { openRule = openRule === r.id ? null : r.id; rerender(); }));
      row.appendChild(circleBtn('fa-trash-can', 'Delete', () => { T.typography = T.typography.filter(x => x.id !== r.id); touch(); rerender(); }, true));
      if (openRule === r.id) {
        const card = html('<div class="cz-item-card"></div>');
        card.appendChild(cSelect('Target Element', () => r.target, v => { r.target = v; rerender(); }, Object.entries(TYPO_TARGETS)));
        if (r.target === 'custom') card.appendChild(cText('CSS selector', () => r.selector, v => r.selector = v, { placeholder: '.entry-content p' }));
        card.appendChild(cSelect('Font Family', () => r.family || '', v => r.family = v, fams));
        if (!T.fonts.length) card.appendChild(hintEl('Add fonts in the Font Manager above to use them here.'));
        const g2 = html('<div class="cz-grid2"></div>');
        g2.appendChild(cSelect('Font Weight', () => r.weight || '', v => r.weight = v, WEIGHTS));
        g2.appendChild(cSelect('Text Transform', () => r.transform || '', v => r.transform = v, [['', 'Default'], ['none', 'None'], ['uppercase', 'Uppercase'], ['lowercase', 'Lowercase'], ['capitalize', 'Capitalize']]));
        g2.appendChild(cSelect('Font Style', () => r.style || '', v => r.style = v, [['', 'Default'], ['normal', 'Normal'], ['italic', 'Italic']]));
        g2.appendChild(cSelect('Text Decoration', () => r.decoration || '', v => r.decoration = v, [['', 'Default'], ['none', 'None'], ['underline', 'Underline'], ['line-through', 'Line-through']]));
        card.appendChild(g2);
        ['size', 'lineHeight', 'letterSpacing', 'marginBottom'].forEach(k => { if (!r[k] || typeof r[k] !== 'object' || Array.isArray(r[k])) r[k] = { d: '', t: '', m: '' }; });
        card.appendChild(respCtl('Font size', r.size, r.sizeUnit || 'px', [8, 80, 1]));
        const units = html('<div class="cz-unit-row">' + ['px', 'em', 'rem'].map(u => '<button type="button" data-u="' + u + '"' + ((r.sizeUnit || 'px') === u ? ' class="active"' : '') + '>' + u + '</button>').join('') + '</div>');
        units.querySelectorAll('button').forEach(b => b.onclick = () => { r.sizeUnit = b.dataset.u; touch(); rerender(); });
        card.appendChild(units);
        card.appendChild(respCtl(r.target === 'body' ? 'Line Height (article text)' : 'Line Height', r.lineHeight, '', [0.8, 3, 0.05]));
        card.appendChild(respCtl('Letter Spacing', r.letterSpacing, 'em', [-0.1, 0.5, 0.01]));
        card.appendChild(respCtl(r.target === 'body' ? 'Paragraph Bottom Margin' : 'Bottom Margin', r.marginBottom, 'em', [0, 4, 0.1]));
        const close = html('<button type="button" class="cz-btn-outline cz-btn-sm">Close</button>'); close.onclick = () => { openRule = null; rerender(); }; card.appendChild(close);
        it.appendChild(card);
      }
      grp.appendChild(it);
    });
    tb.appendChild(grp);
  });
  const addR = html('<button type="button" class="cz-btn-primary">Add Typography</button>');
  addR.onclick = () => {
    const used = new Set(T.typography.map(r => r.target)), next = Object.keys(TYPO_TARGETS).find(k => !used.has(k) && k !== 'custom') || 'custom', r = newRule(next);
    T.typography.push(r); openRule = r.id; touch(); rerender();
  };
  tb.appendChild(addR);
  wrap.appendChild(sectionEl('All typography rules', b => b.appendChild(tb), false, String(T.typography.length || '')));
}

/* ===================== Astra-style panels ===================== */
// Design tab: the colours of that part of the site (same values as before, now next to their part like Astra)
function czDesign(wrap, groups) {
  const T = state.theme;
  if (!T.colors || Array.isArray(T.colors)) T.colors = {};
  groups.forEach(g => {
    const def = COLOR_GROUPS[g]; if (!def) return;
    const box = html('<div class="cz-wpgroup"><h3 class="cz-wp-h"></h3></div>'); box.firstChild.textContent = groups.length > 1 ? def[0] : 'Colors';
    Object.entries(def[1]).forEach(([f, fl]) => box.appendChild(colorCtl(fl, () => (T.colors[g] || {})[f] || '', v => {
      if (!T.colors[g] || Array.isArray(T.colors[g])) T.colors[g] = {};
      if (v) T.colors[g][f] = v; else delete T.colors[g][f];
    })));
    wrap.appendChild(box);
  });
}
function czBoxRow(label, onClick) { const b = html('<button type="button" class="cz-boxrow"><span></span><i class="fas fa-chevron-right" aria-hidden="true"></i></button>'); b.firstChild.textContent = label; b.onclick = onClick; return b; }
function czOpenMenu(view) { const mp = PANELS.find(x => x.id === 'menus'); czFrom = null; showPanel(mp); menuView = view; showPanel(mp, true); $('cz-body').scrollTop = 0; }

// Header ▸ Primary Menu (the menu strip under the logo)
function renderStripOpts(wrap) {
  const T = state.theme;
  wrap.appendChild(czBoxRow('Configure Menu from Here.', () => czOpenMenu('strip')));
  wrap.appendChild(cSeg('Links', () => T.strip_source || 'custom', v => T.strip_source = v, [['custom', 'My links'], ['categories', 'All categories']]));
  wrap.appendChild(cSeg('Style', () => T.menu_style, v => T.menu_style = v, [['plain', 'Plain'], ['pills', 'Pills'], ['underline', 'Underline']]));
  wrap.appendChild(cSeg('Alignment (desktop)', () => T.menu_align, v => T.menu_align = v, [['left', 'Left'], ['center', 'Center']]));
  wrap.appendChild(cToggle('Dark mode icon at the end', () => T.menu_dark_icon === '1', v => T.menu_dark_icon = v ? '1' : '0'));
}
// Global ▸ Buttons
function renderButtons(wrap) { wrap.appendChild(typoRow('buttons', 'Font')); }

// Post Types ▸ Single Post: the parts of an article as one list — 👁 shows / hides, ⌄ opens its options (like Astra's Structure)
const POST_EYE = [['p_breadcrumb'], ['p_cat_badge'], ['p_author_date', ['p_date_shown', 'p_reading_time']], ['p_featured', ['p_caption']],
  ['p_summary', ['p_summary_heading', 'p_summary_pos']], ['p_keypoints', ['p_keypoints_heading', 'p_keypoints_style', 'p_keypoints_after']],
  ['p_toc', ['p_toc_heading', 'p_toc_collapsed']], ['p_faqs'], ['p_tags'], ['p_share'], ['p_author_box'], ['p_comments'], ['p_related', ['p_related_heading', 'p_related_count']],
  ['p_join', ['p_join_whatsapp', 'p_join_telegram']], ['p_pref_source'], ['p_gnews', ['p_gnews_url']]];
const EYE_NAMES = { p_cat_badge: 'Category label', p_author_date: 'Author & date', p_summary: 'Summary', p_keypoints: 'Key Points', p_related: 'Related posts', p_join: 'Join WhatsApp / Telegram', p_pref_source: 'Preferred source (Google)', p_gnews: 'Google News' };
function eyeList(keys, defs) {
  const box = html('<div class="cz-eyes"></div>');
  keys.forEach(([k, subs]) => {
    const d = defs[k]; if (!d) return;
    const on = String(getV(k)) === '1', open = eyeOpen === k;
    const r = html('<div class="cz-eye' + (on ? '' : ' is-off') + (open ? ' open' : '') + '"><div class="cz-eye-bar"><span class="cz-eye-t"></span>' + (subs ? '<button type="button" class="cz-eye-more" aria-label="Options" aria-expanded="' + open + '"><i class="fas fa-chevron-down"></i></button>' : '') + '<button type="button" class="cz-eye-btn" aria-pressed="' + on + '" title="' + (on ? 'Hide' : 'Show') + '"><i class="fas ' + (on ? 'fa-eye' : 'fa-eye-slash') + '"></i></button></div></div>');
    r.querySelector('.cz-eye-t').textContent = EYE_NAMES[k] || d[2];
    r.querySelector('.cz-eye-btn').onclick = () => { setV(k, on ? '0' : '1'); rerender(); };
    if (subs) { const t = () => { eyeOpen = open ? null : k; rerender(); }; r.querySelector('.cz-eye-more').onclick = t; r.querySelector('.cz-eye-t').onclick = t; r.querySelector('.cz-eye-t').classList.add('has-more'); }
    if (open && subs) { const b = html('<div class="cz-eye-in"></div>'); subs.forEach(sk => { if (defs[sk]) b.appendChild(field(defs[sk])); }); r.appendChild(b); }
    box.appendChild(r);
  });
  return box;
}
function renderPost(wrap) {
  const defs = {}; (panel.defs || []).forEach(sec => sec[1].forEach(f => { defs[f[1]] = f; }));
  const g = html('<div class="cz-wpgroup"><h3 class="cz-wp-h">Structure</h3></div>'); g.appendChild(eyeList(POST_EYE, defs)); wrap.appendChild(g);
}

// Global ▸ Typography: Astra font rows (family · size / weight ⌄) that open their settings
const TYPO_ROWS = [['Base Font', [['body', 'Body Font'], ['headings', 'Headings Font']]],
  ['Heading Font', [['h1', 'H1 Font'], ['h2', 'H2 Font'], ['h3', 'H3 Font'], ['h4', 'H4 Font'], ['h5', 'H5 Font'], ['h6', 'H6 Font']]],
  ['Site', [['site_title', 'Site Title'], ['tagline', 'Tagline'], ['post_title', 'Post Title'], ['archive_title', 'Archive Title'], ['meta', 'Entry Meta']]],
  ['Menus', [['menu_strip', 'Primary Menu'], ['primary_menu', 'Off-Canvas Menu'], ['sub_menu', 'Off-Canvas Sub-Menu']]],
  ['Widgets & Footer', [['widget_title', 'Widget Titles'], ['footer', 'Footer']]]];
function ensureFont(fam, weight) {
  const T = state.theme; if (!Array.isArray(T.fonts)) T.fonts = [];
  if (!fam || SYSTEM_FONTS.includes(fam)) return;
  let f = T.fonts.find(x => x.family === fam);
  if (!f && GOOGLE_FONTS.includes(fam)) { f = { family: fam, google: true, variants: ['400', '500', '600', '700'], fallback: 'sans-serif' }; T.fonts.push(f); }
  if (f && f.google && /^\d00$/.test(weight || '') && !(f.variants || []).includes(weight)) f.variants = [...(f.variants || []), weight];
}
function typoRow(target, label) {
  const T = state.theme; if (!Array.isArray(T.typography)) T.typography = [];
  const r0 = T.typography.find(r => r.target === target), open = typoOpen === target;
  const size = r0 && r0.size && r0.size.d ? r0.size.d + (r0.sizeUnit || 'px') : '';
  const w = html('<div class="cz-font' + (open ? ' open' : '') + '"><span class="cz-label"></span><button type="button" class="cz-font-btn" aria-expanded="' + open + '"><span class="cz-font-fam"></span><span class="cz-font-sum"></span><i class="fas fa-chevron-down" aria-hidden="true"></i></button></div>');
  w.querySelector('.cz-label').textContent = label;
  w.querySelector('.cz-font-fam').textContent = (r0 && r0.family) || 'Inherit';
  w.querySelector('.cz-font-sum').innerHTML = esc(size || 'inherit') + ' <em>/</em> ' + esc((r0 && r0.weight) || 'inherit');
  w.querySelector('.cz-font-btn').onclick = () => { typoOpen = open ? null : target; rerender(); };
  if (open) {
    let r = r0; if (!r) { r = newRule(target); T.typography.push(r); }
    ['size', 'lineHeight', 'letterSpacing', 'marginBottom'].forEach(k => { if (!r[k] || typeof r[k] !== 'object' || Array.isArray(r[k])) r[k] = { d: '', t: '', m: '' }; });
    const card = html('<div class="cz-font-card"></div>');
    const own = (T.fonts || []).filter(f => f.family).map(f => f.family);
    const fams = [['', 'Inherit'], ...[...new Set([...own, ...GOOGLE_FONTS])].filter(f => !SYSTEM_FONTS.includes(f)).map(f => [f, f]), ...SYSTEM_FONTS.map(f => [f, f + ' (system)'])];
    card.appendChild(cSelect('Font Family', () => r.family || '', v => { r.family = v; ensureFont(v, r.weight); w.querySelector('.cz-font-fam').textContent = v || 'Inherit'; }, fams));
    card.appendChild(cSelect('Font Weight', () => r.weight || '', v => { r.weight = v; ensureFont(r.family, v); }, WEIGHTS));
    card.appendChild(respCtl('Font Size', r.size, r.sizeUnit || 'px', [8, 80, 1]));
    const units = html('<div class="cz-unit-row">' + ['px', 'em', 'rem'].map(u => '<button type="button" data-u="' + u + '"' + ((r.sizeUnit || 'px') === u ? ' class="active"' : '') + '>' + u + '</button>').join('') + '</div>');
    units.querySelectorAll('button').forEach(b => b.onclick = () => { r.sizeUnit = b.dataset.u; touch(); rerender(); });
    card.appendChild(units);
    card.appendChild(respCtl('Line Height', r.lineHeight, '', [0.8, 3, 0.05]));
    card.appendChild(respCtl('Letter Spacing', r.letterSpacing, 'em', [-0.1, 0.5, 0.01]));
    card.appendChild(cSeg('Text Transform', () => r.transform || '', v => r.transform = v, [['', '—'], ['capitalize', 'Aa'], ['uppercase', 'AA'], ['lowercase', 'aa']]));
    if (target === 'body') card.appendChild(respCtl('Paragraph Margin Bottom', r.marginBottom, 'em', [0, 4, 0.1]));
    const rs = html('<button type="button" class="cz-link-btn cz-font-reset"><i class="fas fa-rotate-left"></i> Reset to default</button>');
    rs.onclick = () => { T.typography = T.typography.filter(x => x !== r); typoOpen = null; touch(); rerender(); };
    card.appendChild(rs);
    w.appendChild(card);
  }
  return w;
}
function renderTypography(wrap) {
  const T = state.theme; if (!Array.isArray(T.fonts)) T.fonts = []; if (!Array.isArray(T.typography)) T.typography = [];
  TYPO_ROWS.forEach(([h, rows]) => { const g = html('<div class="cz-wpgroup"><h3 class="cz-wp-h"></h3></div>'); g.firstChild.textContent = h; rows.forEach(([t, l]) => g.appendChild(typoRow(t, l))); wrap.appendChild(g); });
  renderTypoManager(wrap); // Font Manager + every rule incl. custom selectors (rows that open their own screen)
}

// Global ▸ Colors: Global Palette (presets + swatches), Theme Color, Surface Color
const PALETTES = [
  ['Teal', { accent: '#0c6878', 'accent-2': '#1e73be', 'accent-3': '#d50808', contrast: '#222222', 'contrast-2': '#373737' }],
  ['Royal', { accent: '#046bd2', 'accent-2': '#045cb4', 'accent-3': '#e11d48', contrast: '#1e293b', 'contrast-2': '#334155' }],
  ['Maroon', { accent: '#9e1b32', 'accent-2': '#c2410c', 'accent-3': '#d50808', contrast: '#1f1f1f', 'contrast-2': '#3b3b3b' }],
  ['Forest', { accent: '#2f6b3a', 'accent-2': '#5c7c2e', 'accent-3': '#c2410c', contrast: '#1c2420', 'contrast-2': '#36423b' }]];

/* ---------- Menus ---------- */
let menuView = null, miOpen = null, addingFor = null;
function kindOf(url) {
  if (/^https?:\/\//.test(url)) return 'Custom Link';
  if (url === '/') return 'Front Page';
  if (url.startsWith('/categories/')) return 'Category';
  if (url === '/categories' || url === '/state-jobs' || url === '/latest-updates') return 'Archive';
  if (url.startsWith('/tag/')) return 'Tag';
  return 'Link';
}
// The items list: drag to reorder, expand to edit, move under / out of the item above (nested = sub-menus allowed)
function menuEditor(container, getItems, setItems, nested, key) {
  const items = getItems();
  const box = html('<div class="cz-menu-items"></div>');
  if (!items.length) box.appendChild(hintEl('Time to add some links! Click “Add Items” to start putting pages, categories, and custom links in your menu.'));
  let drag = null;
  const save = v => { setItems(v); touch(); rerender(); };
  const row = (it, id, onChange, onRemove, actions) => {
    const open = miOpen === key + ':' + id;
    const r = html('<div class="cz-mi' + (open ? ' open' : '') + '"><button type="button" class="cz-mi-head"><i class="fas fa-grip-vertical cz-mi-grip" aria-hidden="true"></i><span class="cz-mi-title"></span><span class="cz-mi-kind"></span><i class="fas fa-caret-' + (open ? 'up' : 'down') + '"></i></button></div>');
    r.querySelector('.cz-mi-title').textContent = it.label || '(no label)'; r.querySelector('.cz-mi-kind').textContent = kindOf(it.url || '');
    r.querySelector('.cz-mi-head').onclick = () => { miOpen = open ? null : key + ':' + id; rerender(); };
    if (open) {
      const b = html('<div class="cz-mi-body"></div>');
      b.appendChild(cText('Navigation Label', () => it.label, v => { it.label = v; onChange(); r.querySelector('.cz-mi-title').textContent = v || '(no label)'; }));
      b.appendChild(cText('URL', () => it.url, v => { it.url = v; onChange(); r.querySelector('.cz-mi-kind').textContent = kindOf(v); }));
      const nt = html('<label class="cz-check"><input type="checkbox"> Open link in a new tab</label>'), ntc = nt.querySelector('input');
      ntc.checked = !!it.newTab; ntc.onchange = () => { if (ntc.checked) it.newTab = true; else delete it.newTab; onChange(); touch(); };
      b.appendChild(nt);
      const mv = html('<div class="cz-mi-move"><span>Move</span></div>'); actions.forEach(([t, fn, dis]) => { const x = html('<button type="button"></button>'); x.textContent = t; x.disabled = !!dis; x.onclick = fn; mv.appendChild(x); }); b.appendChild(mv);
      const rm = html('<button type="button" class="cz-mi-remove">Remove</button>'); rm.onclick = onRemove; b.appendChild(rm);
      r.appendChild(b);
    }
    return r;
  };
  items.forEach((it, i) => {
    const wrapI = html('<div class="cz-mi-wrap" draggable="true"></div>');
    wrapI.ondragstart = e => { drag = i; e.dataTransfer.effectAllowed = 'move'; };
    wrapI.ondragover = e => { e.preventDefault(); wrapI.classList.add('drag-over'); };
    wrapI.ondragleave = () => wrapI.classList.remove('drag-over');
    wrapI.ondrop = e => { e.preventDefault(); wrapI.classList.remove('drag-over'); if (drag === null || drag === i) return; const n = items.slice(), [m] = n.splice(drag, 1); n.splice(i, 0, m); drag = null; save(n); };
    const acts = [['Up one', () => { const n = items.slice(); [n[i - 1], n[i]] = [n[i], n[i - 1]]; save(n); }, i === 0], ['Down one', () => { const n = items.slice(); [n[i + 1], n[i]] = [n[i], n[i + 1]]; save(n); }, i === items.length - 1]];
    if (nested && i > 0) acts.push(['Under ' + (items[i - 1].label || 'previous'), () => { const n = items.slice(), [m] = n.splice(i, 1), p = n[i - 1]; p.children = [...(p.children || []), { label: m.label, url: m.url, ...(m.newTab ? { newTab: true } : {}) }, ...(m.children || [])]; save(n); }]);
    wrapI.appendChild(row(it, String(i), () => setItems(items), () => save(items.filter((_, j) => j !== i)), acts));
    (it.children || []).forEach((c, j) => {
      const ch = html('<div class="cz-mi-child"></div>');
      ch.appendChild(row(c, i + '-' + j, () => setItems(items), () => { it.children.splice(j, 1); save(items); },
        [['Out from under ' + (it.label || 'parent'), () => { const n = items.slice(), [m] = it.children.splice(j, 1); n.splice(i + 1, 0, m); save(n); }]]));
      wrapI.appendChild(ch);
    });
    box.appendChild(wrapI);
  });
  container.appendChild(box);
  const foot = html('<div class="cz-menu-foot"><button type="button" class="cz-btn-outline cz-add-items' + (addingFor === key ? ' active' : '') + '"><i class="fas ' + (addingFor === key ? 'fa-xmark' : 'fa-plus') + '"></i> Add Items</button><span class="cz-hint">Drag items to reorder.</span></div>');
  foot.querySelector('button').onclick = () => { addingFor = addingFor === key ? null : key; rerender(); };
  container.appendChild(foot);
  if (addingFor === key) addItemsPanel(it => { const n = getItems().slice(); n.push(it); setItems(n); touch(); rerender(); });
}
let aiSection = 'pages', aiQuery = '';
function addItemsPanel(onAdd) {
  document.querySelectorAll('.cz-additems').forEach(x => x.remove());
  const p = html('<div class="cz-additems" role="dialog" aria-label="Add menu items"><div class="cz-additems-head"><span>Add Menu Items</span><button type="button" aria-label="Close"><i class="fas fa-xmark"></i></button></div><label class="cz-additems-search"><span>Search Menu Items</span><input placeholder="Search…"></label></div>');
  p.querySelector('.cz-additems-head button').onclick = () => { addingFor = null; p.remove(); rerender(); };
  const q = p.querySelector('input'); q.value = aiQuery;
  const secs = [['custom', 'Custom Links'], ['pages', 'Pages'], ['posts', 'Posts'], ['categories', 'Categories'], ['tags', 'Tags']];
  const draw = () => {
    p.querySelectorAll('.cz-ai-sec').forEach(x => x.remove());
    secs.forEach(([id, label]) => {
      const s = html('<div class="cz-ai-sec' + (aiSection === id ? ' open' : '') + '"><button type="button" class="cz-ai-head">' + esc(label) + '<i class="fas fa-caret-' + (aiSection === id ? 'up' : 'down') + '"></i></button></div>');
      s.querySelector('.cz-ai-head').onclick = () => { aiSection = aiSection === id ? null : id; draw(); };
      if (aiSection === id) {
        if (id === 'custom') {
          const c = html('<div class="cz-ai-custom"><label>URL<input value="https://"></label><label>Link Text<input></label><button type="button" class="cz-btn-outline" disabled><i class="fas fa-plus"></i> Add to Menu</button></div>');
          const [u, t] = c.querySelectorAll('input'), b = c.querySelector('button');
          const ok = () => b.disabled = !t.value.trim() || !u.value.trim() || u.value.trim() === 'https://';
          u.oninput = ok; t.oninput = ok;
          b.onclick = () => { onAdd({ label: t.value.trim(), url: u.value.trim() }); };
          s.appendChild(c);
        } else {
          const list = html('<div class="cz-ai-list"><p class="cz-hint">Loading…</p></div>'); s.appendChild(list);
          fetch('/admin/customize.php?src=' + id + '&q=' + encodeURIComponent(q.value.trim()), { credentials: 'same-origin' }).then(r => r.json()).then(rows => {
            list.innerHTML = '';
            if (!rows.length) list.innerHTML = '<p class="cz-hint">No results.</p>';
            rows.forEach(it => {
              const b = html('<button type="button"><i class="fas fa-plus"></i><span></span><em></em></button>');
              b.querySelector('span').textContent = it.label; b.querySelector('em').textContent = it.kind;
              b.onclick = () => { onAdd({ label: it.label, url: it.url }); };
              list.appendChild(b);
            });
          }).catch(() => { list.innerHTML = '<p class="cz-hint">Could not load. Please try again.</p>'; });
        }
      }
      p.appendChild(s);
    });
  };
  let t; q.oninput = () => { aiQuery = q.value; clearTimeout(t); t = setTimeout(draw, 250); };
  draw();
  document.getElementById('cz-root').appendChild(p);
}
function renderMenus(wrap) {
  const T = state.theme;
  if (!Array.isArray(T.menu)) T.menu = [];
  if (!Array.isArray(T.strip_items)) T.strip_items = [];
  const NAMES = { primary: 'Primary Menu', strip: 'Menu Strip' };
  if (!menuView) {
    $('cz-back').onclick = exitPanel;
    const list = html('<div class="cz-subs"></div>');
    [['primary', T.menu.length + ' items'], ['strip', T.strip_source === 'categories' ? 'All categories' : T.strip_items.length + ' items']].forEach(([id, n]) => {
      const r = html('<button type="button" class="cz-subrow"><span></span><span class="cz-subrow-r"><em class="cz-badge"></em><i class="fas fa-chevron-right" aria-hidden="true"></i></span></button>');
      r.firstChild.textContent = NAMES[id]; r.querySelector('em').textContent = n;
      r.onclick = () => { menuView = id; miOpen = null; addingFor = null; showPanel(panel, true); $('cz-body').scrollTop = 0; };
      list.appendChild(r);
    });
    wrap.appendChild(html('<h3 class="cz-wp-h">Menus</h3>')); wrap.appendChild(list);
    wrap.appendChild(html('<h3 class="cz-wp-h">Menu Locations</h3>'));
    const loc = html('<div class="cz-wpbox cz-locs"></div>');
    const l1 = html('<div class="cz-loc"><span class="cz-label">Header row (below the logo)</span></div>');
    l1.appendChild(cSelect('', () => T.strip_source || 'custom', v => { T.strip_source = v; rerender(); }, [['custom', 'Menu Strip'], ['categories', 'All categories (automatic)']]));
    const e1 = html('<button type="button" class="cz-link-btn">Edit Menu</button>'); e1.onclick = () => { menuView = 'strip'; showPanel(panel, true); }; l1.appendChild(e1);
    const l2 = html('<div class="cz-loc"><span class="cz-label">Mobile slide-out menu</span><select disabled><option>Primary Menu</option></select></div>');
    const e2 = html('<button type="button" class="cz-link-btn">Edit Menu</button>'); e2.onclick = () => { menuView = 'primary'; showPanel(panel, true); }; l2.appendChild(e2);
    loc.append(l1, l2); wrap.appendChild(loc);
    return;
  }
  // one menu on its own screen (Customizing ▸ Menus)
  $('cz-head-small').textContent = 'Customizing ▸ Menus'; $('cz-head-title').textContent = NAMES[menuView];
  const back = () => { menuView = null; addingFor = null; miOpen = null; document.querySelectorAll('.cz-additems').forEach(x => x.remove()); showPanel(panel, true); $('cz-body').scrollTop = 0; };
  $('cz-back').onclick = back;
  if (menuView === 'strip' && T.strip_source === 'categories') {
    wrap.appendChild(hintEl('This location lists every category automatically.'));
    const sw = html('<button type="button" class="cz-btn-outline">Use my own links instead</button>'); sw.onclick = () => { T.strip_source = 'custom'; touch(); rerender(); }; wrap.appendChild(sw);
  } else {
    menuEditor(wrap, () => menuView === 'strip' ? T.strip_items : T.menu, v => { if (menuView === 'strip') T.strip_items = v; else T.menu = v; }, true, menuView); // nested: a link with links under it = a drop-down
  }
  if (menuView === 'strip') {
    wrap.appendChild(html('<h3 class="cz-wp-h">Menu Options</h3>'));
    const blk = html('<div class="cz-wpbox"></div>');
    blk.appendChild(cSeg('Links', () => T.strip_source || 'custom', v => T.strip_source = v, [['custom', 'My links'], ['categories', 'All categories']], rerender));
    blk.appendChild(cSeg('Style', () => T.menu_style, v => T.menu_style = v, [['plain', 'Plain'], ['pills', 'Pills'], ['underline', 'Underline']]));
    blk.appendChild(cSeg('Alignment (desktop)', () => T.menu_align, v => T.menu_align = v, [['left', 'Left'], ['center', 'Center']]));
    blk.appendChild(cToggle('Dark mode icon at the end', () => T.menu_dark_icon === '1', v => T.menu_dark_icon = v ? '1' : '0'));
    wrap.appendChild(blk);
  }
}

/* ---------- Footer ---------- */
function renderFooter(wrap) {
  const T = state.theme;
  if (!Array.isArray(T.f_columns)) T.f_columns = [];
  wrap.appendChild(sectionEl('About', b => {
    b.appendChild(field(['media', 'f_logo', 'Footer logo', { hint: 'Empty = the site title as text.' }]));
    b.appendChild(field(['textarea', 'f_desc', 'Description', { hint: 'HTML and shortcodes allowed. Empty = the footer text from General Settings.' }]));
    b.appendChild(field(['toggle', 'f_play', 'Show “Get it on Google Play” badge', { hint: 'Shown under the description.' }]));
    b.appendChild(field(['text', 'f_play_url', 'Google Play link', { placeholder: 'https://play.google.com/store/apps/details?id=…', hint: 'Optional. Empty = the badge shows without a link.' }]));
  }, true));
  wrap.appendChild(sectionEl('Link columns', b => {
    T.f_columns.forEach((col, i) => {
      const cb = html('<div class="cz-colbox"><div class="cz-item-row"><input class="cz-inline-input" placeholder="Column heading"></div></div>');
      const ti = cb.querySelector('input'); ti.value = col.title || ''; ti.oninput = () => { col.title = ti.value; touch(); };
      cb.querySelector('.cz-item-row').appendChild(circleBtn('fa-trash-can', 'Remove column', () => { T.f_columns.splice(i, 1); touch(); rerender(); }, true));
      cb.appendChild(cToggle('List the most-read categories automatically', () => col.auto === 'categories', v => { col.auto = v ? 'categories' : ''; if (!Array.isArray(col.links)) col.links = []; rerender(); }));
      if (col.auto === 'categories') cb.appendChild(cRange('How many categories', () => col.count || 6, v => col.count = v, 1, 20));
      else { if (!Array.isArray(col.links)) col.links = []; menuEditor(cb, () => col.links, v => col.links = v, false, 'fcol' + i); }
      b.appendChild(cb);
    });
    if (T.f_columns.length < 4) {
      const add = html('<button type="button" class="cz-btn-outline"><i class="fas fa-plus"></i> Add column</button>');
      add.onclick = () => { T.f_columns.push({ title: 'Links', auto: '', count: 6, links: [] }); touch(); rerender(); };
      b.appendChild(add);
    }
  }, true, String(T.f_columns.length)));
  wrap.appendChild(sectionEl('Follow Us box', b => {
    [['text', 'f_follow_heading', 'Heading'], ['toggle', 'f_follow', 'Show the box'], ['text', 'f_follow_title', 'Box title'], ['text', 'f_follow_sub', 'Box subtitle'], ['text', 'f_follow_btn', 'Button text (optional)'], ['text', 'f_follow_btn_url', 'Button link']].forEach(f => b.appendChild(field(f)));
    b.appendChild(html('<p class="cz-hint">The social icons come from <a href="/admin/general-settings.php#social" target="_blank">General Settings → Social</a> and are also used in the header and the mobile menu.</p>'));
  }, true));
}

/* ---------- Also Read ---------- */
const AR_STYLES = [['card', 'Embed card', 'Image, title, excerpt. Several posts = swipe slider with a position bar.'], ['accent', 'Highlight bar', 'Colored box with the post titles listed.'], ['minimal', 'Minimal line', 'Simple list of titles between two lines.']];
let arOpen = undefined, arTitles = {};
function renderAlsoRead(wrap) {
  const T = state.theme;
  if (!Array.isArray(T.ar_groups)) T.ar_groups = [];
  if (arOpen === undefined) arOpen = T.ar_groups[0] ? T.ar_groups[0].id : null;
  wrap.appendChild(cToggle('Show “Also Read” boxes inside articles', () => T.ar_enabled === '1', v => T.ar_enabled = v ? '1' : '0'));
  wrap.appendChild(hintEl('Each box appears after the paragraph you choose and holds all of its posts in one container. A paragraph number larger than the article is skipped.'));
  const need = [...new Set(T.ar_groups.flatMap(g => g.post_ids || []))].filter(id => !arTitles[id]);
  if (need.length) fetch('/admin/customize.php?titles=' + need.join(','), { credentials: 'same-origin' }).then(r => r.json()).then(t => { Object.assign(arTitles, t); need.forEach(id => arTitles[id] = arTitles[id] || 'Post #' + id); rerender(); });
  T.ar_groups.forEach((g, i) => {
    const it = html('<div class="cz-item cz-ar' + (arOpen === g.id ? ' open' : '') + '"><div class="cz-item-row"><span class="cz-item-title"></span></div></div>');
    const st = AR_STYLES.find(s => s[0] === g.style);
    it.querySelector('.cz-item-title').textContent = 'Box ' + (i + 1) + ': after paragraph ' + g.after + ' · ' + (g.source === 'manual' ? (g.post_ids || []).length : g.count) + ' post' + ((g.source === 'manual' ? (g.post_ids || []).length : g.count) === 1 ? '' : 's') + ' · ' + (st ? st[1] : '');
    const row = it.querySelector('.cz-item-row');
    row.appendChild(circleBtn(arOpen === g.id ? 'fa-chevron-up' : 'fa-chevron-down', 'Edit', () => { arOpen = arOpen === g.id ? null : g.id; rerender(); }));
    row.appendChild(circleBtn('fa-trash-can', 'Delete box', () => { T.ar_groups = T.ar_groups.filter(x => x.id !== g.id); touch(); rerender(); }, true));
    if (arOpen === g.id) {
      const card = html('<div class="cz-item-card"></div>');
      card.appendChild(cRange('Show after paragraph', () => g.after, v => g.after = v, 1, 40));
      card.appendChild(cText('Label', () => g.label, v => g.label = v));
      card.appendChild(cSeg('Which posts', () => g.source, v => g.source = v, [['category', 'Same category'], ['latest', 'Latest'], ['random', 'Random'], ['manual', 'Pick posts']], rerender));
      if (g.source === 'manual') card.appendChild(postPicker(g));
      else card.appendChild(cRange('Number of posts', () => g.count, v => g.count = v, 1, 12));
      card.appendChild(html('<span class="cz-label">Design</span>'));
      const opts = html('<div class="cz-also"></div>');
      AR_STYLES.forEach(([v, l, d]) => {
        const b = html('<button type="button" class="cz-also-opt' + (g.style === v ? ' active' : '') + '"><span class="cz-also-demo cz-also-demo--' + v + '" aria-hidden="true"><i></i><b></b><u></u></span><strong>' + esc(l) + '</strong><small>' + esc(d) + '</small></button>');
        b.onclick = () => { g.style = v; touch(); rerender(); }; opts.appendChild(b);
      });
      card.appendChild(opts);
      it.appendChild(card);
    }
    wrap.appendChild(it);
  });
  const add = html('<button type="button" class="cz-btn-primary"><i class="fas fa-plus"></i> Add “Also Read” box</button>');
  add.onclick = () => {
    const last = T.ar_groups[T.ar_groups.length - 1], g = { id: 'ar' + Date.now().toString(36), after: Math.min(40, (last ? last.after : 0) + 4), count: 3, source: 'category', post_ids: [], style: 'card', label: 'Also Read' };
    T.ar_groups.push(g); arOpen = g.id; touch(); rerender();
  };
  wrap.appendChild(add);
}
function postPicker(g) {
  if (!Array.isArray(g.post_ids)) g.post_ids = [];
  const w = html('<div class="cz-field"><span class="cz-label">Posts (' + g.post_ids.length + ')</span><div class="cz-picked"></div><input placeholder="Search posts to add…"><div class="cz-ai-list cz-ai-list--compact"></div></div>');
  const picked = w.querySelector('.cz-picked');
  g.post_ids.forEach((id, i) => {
    const p = html('<div class="cz-picked-item"><span></span><button type="button" title="Move up"' + (i === 0 ? ' disabled' : '') + '><i class="fas fa-arrow-up"></i></button><button type="button" title="Remove"><i class="fas fa-xmark"></i></button></div>');
    p.querySelector('span').textContent = arTitles[id] || 'Post #' + id;
    const [up, rm] = p.querySelectorAll('button');
    up.onclick = () => { if (i > 0) { [g.post_ids[i - 1], g.post_ids[i]] = [g.post_ids[i], g.post_ids[i - 1]]; touch(); rerender(); } };
    rm.onclick = () => { g.post_ids.splice(i, 1); g.count = Math.max(1, g.post_ids.length); touch(); rerender(); };
    picked.appendChild(p);
  });
  const q = w.querySelector('input'), hits = w.querySelector('.cz-ai-list');
  const search = () => fetch('/admin/customize.php?posts_brief=' + encodeURIComponent(q.value.trim()), { credentials: 'same-origin' }).then(r => r.json()).then(rows => {
    hits.innerHTML = '';
    rows.filter(h => !g.post_ids.includes(h.id)).slice(0, 8).forEach(h => {
      const b = html('<button type="button"><i class="fas fa-plus"></i><span></span></button>'); b.querySelector('span').textContent = h.title;
      b.onclick = () => { arTitles[h.id] = h.title; g.post_ids.push(h.id); g.count = Math.max(1, g.post_ids.length); touch(); rerender(); };
      hits.appendChild(b);
    });
  });
  let t; q.oninput = () => { clearTimeout(t); t = setTimeout(search, 250); };
  search();
  return w;
}

/* ---------- Shortcodes ---------- */
function renderShortcodes(wrap) {
  const box = html('<div class="cz-codes"></div>');
  box.appendChild(hintEl('Use these anywhere: posts, pages, the footer, the top bar, SEO titles and descriptions. Click to copy.'));
  SHORTCODES.forEach(([code, desc]) => {
    const b = html('<button type="button" class="cz-code"><code></code><span></span></button>');
    b.querySelector('code').textContent = code; b.querySelector('span').textContent = desc;
    b.onclick = () => {
      const done = () => { b.querySelector('span').textContent = 'Copied!'; setTimeout(() => b.querySelector('span').textContent = desc, 1200); };
      if (navigator.clipboard) navigator.clipboard.writeText(code).then(done, done); else { const ta = document.createElement('textarea'); ta.value = code; document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); } catch (e) {} ta.remove(); done(); }
    };
    box.appendChild(b);
  });
  wrap.appendChild(box);
}

/* ---------- Homepage (WordPress-style: a list of sections → each section opens on its own screen) ---------- */
// Order / headings / show-hide and the section options are part of the draft (Publish saves them).
// Slides, free courses and toppers hold pictures, so they are saved as soon as they change.
let HMDB = null, hmOpen = null, hmEdit = null, hmBusy = false, hmLinkT = {};
const HM_ICON = { slider: 'fa-images', courses: 'fa-photo-film', categories: 'fa-shapes', batches: 'fa-calendar-days', notes: 'fa-file-pdf', posts: 'fa-newspaper', free: 'fa-circle-play', impact: 'fa-chart-simple', why: 'fa-circle-check', teachers: 'fa-chalkboard-user', toppers: 'fa-trophy' };
const HM_AUTO = {
  courses: ['Published courses fill in by themselves.', '/admin/shop-products.php?type=course', 'Manage courses'],
  categories: ['Made from your classes, each with an icon.', '/admin/shop-categories.php', 'Manage categories'],
  batches: ['Set “Batch starts on” in a course to make it an upcoming batch.', '/admin/shop-products.php?type=course', 'Manage courses'],
  notes: ['Published PDF notes fill in by themselves.', '/admin/shop-products.php?type=notes', 'Manage PDF notes'],
  posts: ['Your newest published blog posts.', '/admin/post-manager.php?view=all', 'Manage posts'],
  teachers: ['Users with the Teacher permission and “Show on the homepage” in their teacher profile.', '/admin/user-manager.php', 'Manage teachers'],
};
const hmName = x => x.id === 'why' ? 'Why choose us' : (x.title || HOME_SECTIONS[x.id]); // the "Why choose" section keeps its name; its heading is an ordinary text
function hmLoad(then) { fetch('/admin/home-page.php?json=1', { credentials: 'same-origin' }).then(r => r.json()).then(d => { HMDB = d; if (then) then(); }).catch(() => { HMDB = { slides: [], free: [], toppers: [], cats: [] }; if (then) then(); }); }
// save to the database; quiet = keep the panel as it is (e.g. while typing a link), only refresh the preview
function hmSend(fields, opt = {}) {
  if (hmBusy && !opt.quiet) return; if (!opt.quiet) hmBusy = true;
  const fd = new FormData(); fd.append('_csrf', CSRF); fd.append('ajax', '1');
  Object.entries(fields).forEach(([k, v]) => fd.append(k, v));
  $('cz-refreshing').hidden = false;
  return fetch('/admin/home-page.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(r => r.json()).then(r => {
    hmBusy = false;
    if (!r.ok) { $('cz-refreshing').hidden = true; alert(r.message || 'Could not save. Please try again.'); return; }
    if (opt.done) opt.done();
    hmLoad(() => { if (!opt.quiet) rerender(); reloadFrame(); });
  }).catch(() => { hmBusy = false; $('cz-refreshing').hidden = true; alert('Could not save. Please check the internet and try again.'); });
}
function hmScroll(id) { try { const el = $('cz-frame').contentDocument.querySelector('[data-hm="' + id + '"]'); if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch (e) {} }
function hmGhost(icon, title, onClick, cls) { const b = html('<button type="button" class="hm-ic' + (cls ? ' ' + cls : '') + '" title="' + esc(title) + '" aria-label="' + esc(title) + '"><i class="fas ' + icon + '"></i></button>'); b.onclick = e => { e.stopPropagation(); onClick(); }; return b; }
function hmGroup(title, hint) { return html('<div class="hm-grp">' + (title ? '<h4>' + esc(title) + '</h4>' : '') + (hint ? '<p class="hm-desc">' + hint + '</p>' : '') + '</div>'); }
function hmInstant(t) { return html('<p class="hm-instant"><i class="fas fa-bolt"></i> ' + esc(t) + '</p>'); }
const slideFields = r => ({ action: 'slide_save', id: r.id, link: r.link || '', title: r.title || '', sort: r.sort, ...(+r.new_tab ? { new_tab: 1 } : {}), ...(+r.active ? { active: 1 } : {}) });

function renderHomepage(wrap) {
  const H = state.home; $('cz-body').classList.add('hm-bg');
  if (!HMDB) hmLoad(rerender);
  const back = $('cz-back');
  if (!hmOpen) {
    $('cz-head-small').textContent = 'Customizing'; $('cz-head-title').textContent = 'Homepage'; back.onclick = exitPanel;
    wrap.appendChild(html('<p class="hm-lead">Drag <i class="fas fa-grip-vertical"></i> to change the order. Click a section to edit it.</p>'));
    const list = html('<ul class="hm-list"></ul>');
    H.sections.forEach((x, i) => {
      const li = html('<li class="hm-row' + (x.on ? '' : ' is-off') + '" data-i="' + i + '"><span class="hm-grip" title="Drag to move"><i class="fas fa-grip-vertical"></i></span><span class="hm-ico"><i class="fas ' + (HM_ICON[x.id] || 'fa-square') + '"></i></span><span class="hm-nm"></span><span class="hm-acts"></span><i class="fas fa-chevron-right hm-go"></i></li>');
      li.querySelector('.hm-nm').textContent = hmName(x);
      if (!x.on) li.querySelector('.hm-nm').appendChild(html('<em>Hidden</em>'));
      li.querySelector('.hm-acts').appendChild(hmGhost(x.on ? 'fa-eye' : 'fa-eye-slash', x.on ? 'Hide on the homepage' : 'Show on the homepage', () => { x.on = x.on ? 0 : 1; touch(); rerender(); }, x.on ? '' : 'is-off'));
      li.onclick = () => { hmOpen = x.id; hmEdit = null; rerender(); $('cz-body').scrollTop = 0; setTimeout(() => hmScroll(x.id), 60); };
      // drag by the grip
      const grip = li.querySelector('.hm-grip');
      grip.onmousedown = () => { li.draggable = true; }; grip.onclick = e => e.stopPropagation();
      li.ondragstart = e => { li.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', i); };
      li.ondragend = () => {
        li.draggable = false; li.classList.remove('dragging');
        const order = [...list.children].map(n => H.sections[+n.dataset.i]);
        if (order.some((s, k) => s !== H.sections[k])) { H.sections = order; state.home.sections = order; touch(); }
        rerender();
      };
      list.appendChild(li);
    });
    list.ondragover = e => {
      e.preventDefault(); const d = list.querySelector('.dragging'); if (!d) return;
      const after = [...list.querySelectorAll('.hm-row:not(.dragging)')].find(n => e.clientY < n.getBoundingClientRect().top + n.offsetHeight / 2);
      if (after) list.insertBefore(d, after); else list.appendChild(d);
    };
    wrap.appendChild(list);
    return;
  }
  // ---- one section on its own screen ----
  const x = H.sections.find(s => s.id === hmOpen);
  $('cz-head-small').textContent = 'Customizing ▸ Homepage'; $('cz-head-title').textContent = hmName(x);
  back.onclick = () => { hmOpen = null; hmEdit = null; rerender(); $('cz-body').scrollTop = 0; };
  const g0 = hmGroup('');
  g0.appendChild(cToggle('Show on the homepage', () => !!x.on, v => { x.on = v ? 1 : 0; }));
  if (x.id !== 'slider') g0.appendChild(cText('Section heading', () => x.title, v => { x.title = v; $('cz-head-title').textContent = hmName(x); }, { placeholder: x.id === 'why' ? 'Why choose ' + HOME_BRAND : HOME_SECTIONS[x.id] }));
  wrap.appendChild(g0);
  const opt = (label, key, options) => cSeg(label, () => H[key], v => H[key] = v, options);
  if (x.id === 'courses' || x.id === 'batches') {
    const g = hmGroup('Which courses'); g.appendChild(opt('', x.id + '_filter', [['all', 'All latest'], ['launched', 'Launched'], ['upcoming', 'Upcoming']]));
    g.appendChild(html('<p class="hm-desc">Launched = already started (or no start date). Upcoming = batch starts today or later, soonest first.</p>')); wrap.appendChild(g);
  }
  if (x.id === 'categories') hmCategories(wrap, H);
  if (x.id === 'teachers') hmTeachers(wrap);
  if (x.id === 'notes') { const g = hmGroup('Which PDF notes first'); g.appendChild(opt('', 'notes_sort', [['latest', 'Latest'], ['popular', 'Most popular']])); g.appendChild(html('<p class="hm-desc">Most popular = most students have it.</p>')); wrap.appendChild(g); }
  if (x.id === 'posts') { const g = hmGroup('Posts'); g.appendChild(cRange('How many latest posts', () => +H.posts_count || 10, v => H.posts_count = v, 1, 20)); wrap.appendChild(g); }
  if (x.id === 'slider') hmSlides(wrap);
  if (x.id === 'free') hmFree(wrap);
  if (x.id === 'toppers') hmToppers(wrap);
  if (x.id === 'impact') hmImpact(wrap, H);
  if (x.id === 'why') hmWhy(wrap, H);
  if (HM_AUTO[x.id]) { const [t, u, l] = HM_AUTO[x.id]; wrap.appendChild(html('<p class="hm-desc hm-src">' + esc(t) + ' <a href="' + u + '" target="_blank">' + esc(l) + ' <i class="fas fa-arrow-up-right-from-square"></i></a></p>')); }
}

/* ---- shared bits: device switches (phone / tablet / computer) and the icon picker ---- */
const DEV_SVG = {
  m: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="7" y="2" width="10" height="20" rx="2"/><path d="M11 18h2"/></svg>',
  t: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="2" width="16" height="20" rx="2"/><path d="M11 18h2"/></svg>',
  d: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="3" width="20" height="13" rx="2"/><path d="M8 21h8M12 16v5"/></svg>'
};
const DEV_NAME = { m: 'Phone', t: 'Tablet', d: 'Desktop' };
// flags = "111" (phone, tablet, computer); onChange gets the new flags
function hmDev(flags, onChange) { // icon buttons: blue = shown, grey with a slash = hidden
  const w = html('<div class="hm-dev" role="group" aria-label="Show on"></div>');
  ['m', 't', 'd'].forEach((k, i) => {
    const on = (flags || '111')[i] !== '0';
    const b = html('<button type="button" class="hm-dev-o' + (on ? ' on' : '') + '" aria-pressed="' + on + '" title="' + DEV_NAME[k] + ': ' + (on ? 'shown' : 'hidden') + '">' + DEV_SVG[k] + '</button>');
    b.onclick = e => { e.stopPropagation(); const f = (flags || '111').split(''); f[i] = f[i] === '0' ? '1' : '0'; flags = f.join(''); const now = f[i] === '1'; b.classList.toggle('on', now); b.setAttribute('aria-pressed', now); b.title = DEV_NAME[k] + ': ' + (now ? 'shown' : 'hidden'); onChange(flags); };
    w.appendChild(b);
  });
  return w;
}
/* WordPress-style widget: a slim title bar that opens to its settings (one open at a time) */
function hmWidget(key, o) {
  const open = hmEdit === key;
  const w = html('<div class="hm-w' + (open ? ' open' : '') + (o.off ? ' is-off' : '') + '"><div class="hm-w-bar" role="button" tabindex="0" aria-expanded="' + open + '"><span class="hm-w-lead"></span><span class="hm-w-t"><b></b><small></small></span><span class="hm-w-x"></span><i class="fas fa-caret-down hm-w-arr" aria-hidden="true"></i></div></div>');
  const lead = w.querySelector('.hm-w-lead');
  if (!o.lead) lead.remove(); else if (typeof o.lead === 'string') lead.innerHTML = o.lead; else lead.appendChild(o.lead);
  w.querySelector('b').textContent = o.title || '';
  const sm = w.querySelector('small'); if (o.sub) sm.textContent = o.sub; else sm.remove();
  (o.extra || []).forEach(e => w.querySelector('.hm-w-x').appendChild(e));
  const bar = w.querySelector('.hm-w-bar'), tog = () => { hmEdit = open ? null : key; rerender(); };
  bar.onclick = tog; bar.onkeydown = e => { if (e.target === bar && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); tog(); } };
  if (open) {
    const body = html('<div class="hm-w-in"></div>'); o.body(body, w);
    const f = html('<div class="hm-w-f"><span></span><button type="button" class="hm-btn hm-w-done">Done</button></div>');
    if (o.remove) { const d = html('<button type="button" class="hm-w-del">' + esc(o.removeLabel || 'Remove') + '</button>'); d.onclick = o.remove; f.firstChild.appendChild(d); }
    f.querySelector('.hm-w-done').onclick = () => { hmEdit = null; rerender(); };
    body.appendChild(f); w.appendChild(body);
  }
  return w;
}
function hmAddBtn(label, onClick) { const b = html('<button type="button" class="hm-btn hm-add"><i class="fas fa-plus"></i> ' + esc(label) + '</button>'); b.onclick = onClick; return b; }
// icon picker: the site's icons, or paste an SVG; '' = automatic
function hmIconBtn(get, set) {
  const b = html('<button type="button" class="hm-icb" title="Choose icon"></button>');
  const paint = () => { const v = get() || ''; b.innerHTML = v === '' ? '<span class="hm-icb-auto">Auto</span>' : (v.startsWith('<') ? v : (PICK_ICONS[v] || '')); b.classList.toggle('is-auto', v === ''); };
  paint();
  b.onclick = e => {
    e.stopPropagation();
    document.querySelectorAll('.hm-icp').forEach(x => x.remove());
    const p = html('<div class="hm-icp"><div class="hm-icp-h">Choose an icon</div><div class="hm-icp-g"></div><div class="hm-icp-h">Or paste your own SVG</div><textarea rows="3" placeholder="&lt;svg viewBox=&quot;0 0 24 24&quot;&gt;…&lt;/svg&gt;"></textarea><div class="hm-icp-f"><button type="button" class="hm-icp-auto">Automatic</button><button type="button" class="cz-btn-primary hm-icp-ok">Use SVG</button></div></div>');
    const g = p.querySelector('.hm-icp-g');
    Object.entries(PICK_ICONS).forEach(([k, svg]) => { const o = html('<button type="button" class="hm-icp-o' + (get() === k ? ' on' : '') + '" title="' + esc(k) + '">' + svg + '</button>'); o.onclick = () => { set(k); touch(); paint(); p.remove(); }; g.appendChild(o); });
    const ta = p.querySelector('textarea'); if ((get() || '').startsWith('<')) ta.value = get();
    p.querySelector('.hm-icp-ok').onclick = () => { const v = ta.value.trim(); if (!/^<svg[\s>]/i.test(v)) { alert('Paste an SVG that starts with <svg'); return; } set(v); touch(); paint(); p.remove(); };
    p.querySelector('.hm-icp-auto').onclick = () => { set(''); touch(); paint(); p.remove(); };
    p.onclick = ev => ev.stopPropagation();
    b.parentNode.appendChild(p);
    setTimeout(() => document.addEventListener('click', function off() { p.remove(); document.removeEventListener('click', off); }), 0);
  };
  return b;
}

/* ---- Top slider: one compact row per slide — picture | link · show / new tab · phone / tablet / desktop ---- */
function hmSlides(wrap) {
  const g = hmGroup('Slides'); wrap.appendChild(g);
  if (!HMDB) { g.appendChild(hintEl('Loading…')); return; }
  const rows = HMDB.slides;
  if (!rows.length) g.appendChild(html('<p class="hm-desc">No slides yet — the homepage shows ready-made picture banners until you add one.</p>'));
  const order = (id, dir) => { const ids = rows.map(r => r.id), k = ids.indexOf(id), t = k + dir; if (t < 0 || t >= ids.length) return; [ids[k], ids[t]] = [ids[t], ids[k]]; hmSend({ action: 'slide_order', ids: ids.join(',') }); };
  const save = r => hmSend({ ...slideFields(r), show_m: +r.show_m ? 1 : 0, show_t: +r.show_t ? 1 : 0, show_d: +r.show_d ? 1 : 0 }, { quiet: true });
  const list = html('<div class="hm-wl"></div>'); g.appendChild(list);
  rows.forEach((r, k) => {
    ['show_m', 'show_t', 'show_d'].forEach(c => { if (r[c] === undefined || r[c] === null) r[c] = 1; });
    const th = html('<span class="hm-w-th"><img alt=""></span>'); th.querySelector('img').src = r.image_url;
    const where = ['m', 't', 'd'].filter(d => +r['show_' + d]).map(d => DEV_NAME[d]);
    const sub = (r.link ? r.link.replace(/^https?:\/\/(www\.)?/, '') : 'No link') + (where.length < 3 ? ' · ' + (where.join(', ') || 'no device') + ' only' : '');
    list.appendChild(hmWidget('s' + r.id, {
      lead: th, title: 'Slide ' + (k + 1) + (+r.active ? '' : ' — hidden'), sub, off: !+r.active,
      extra: [hmGhost('fa-arrow-up', 'Move up', () => order(r.id, -1), k === 0 ? 'is-dis' : ''), hmGhost('fa-arrow-down', 'Move down', () => order(r.id, 1), k === rows.length - 1 ? 'is-dis' : '')],
      removeLabel: 'Delete slide', remove: () => { if (confirm('Delete this slide?')) { hmEdit = null; hmSend({ action: 'slide_delete', id: r.id }); } },
      body: (b, w) => {
        const pic = html('<div class="hm-w-pic"><img alt=""><button type="button" class="hm-btn">Change image</button></div>');
        pic.querySelector('img').src = r.image_url; pic.querySelector('button').onclick = () => EduMedia.pick(it => hmSend({ ...slideFields(r), image_path: it.path }));
        const lk = html('<label class="cz-field"><span class="cz-label">Link</span><input type="text" placeholder="https://…  or  /courses/"><small class="cz-hint">Where the slide goes when clicked. Empty = no link.</small></label>');
        const li = lk.querySelector('input'); li.value = r.link || '';
        li.oninput = () => { r.link = li.value; w.querySelector('.hm-w-t small').textContent = li.value || 'No link'; clearTimeout(hmLinkT[r.id]); hmLinkT[r.id] = setTimeout(() => save(r), 800); };
        const chk = (label, key) => { const l = hmCheck(label, +r[key]); l.querySelector('input').onchange = e => { r[key] = e.target.checked ? 1 : 0; save(r); w.classList.toggle('is-off', !+r.active); }; return l; };
        const ck = html('<div class="hm-w-chks"></div>'); ck.append(chk('Show this slide', 'active'), chk('Open the link in a new tab', 'new_tab'));
        const dv = html('<div class="cz-field"><span class="cz-label">Show on</span></div>');
        dv.appendChild(hmDev(String(+r.show_m ? 1 : 0) + (+r.show_t ? 1 : 0) + (+r.show_d ? 1 : 0), f => { r.show_m = +f[0]; r.show_t = +f[1]; r.show_d = +f[2]; save(r); }));
        b.append(pic, lk, ck, dv);
      }
    }));
  });
  g.appendChild(hmAddBtn('Add slide', () => EduMedia.pick(it => hmSend({ action: 'slide_save', id: 0, image_path: it.path, link: '', title: '', sort: rows.length + 1, active: 1, show_m: 1, show_t: 1, show_d: 1 }))));
  g.appendChild(html('<p class="hm-desc">Best size 1600 × 500 px. Slides save as soon as you change them.</p>'));
}

/* ---- Categories: every class with phone / tablet / desktop, and how many in one row ---- */
function hmCategories(wrap, H) {
  if (!H.cats_vis || Array.isArray(H.cats_vis)) H.cats_vis = {};
  const g = hmGroup('Classes', 'Click a device icon to show or hide that class on phones, tablets or computers.'); wrap.appendChild(g);
  if (!HMDB) { g.appendChild(hintEl('Loading…')); return; }
  const list = (HMDB.cats || []);
  if (!list.length) { g.appendChild(html('<p class="hm-desc">No categories yet.</p>')); return; }
  const tb = html('<div class="hm-clist"></div>');
  list.forEach(c => {
    const f = H.cats_vis[c.id] || '111';
    const r = html('<div class="hm-crow' + (f === '000' ? ' is-off' : '') + '"><span class="hm-crow-n"></span></div>');
    r.querySelector('.hm-crow-n').textContent = c.name;
    r.appendChild(hmDev(f, nf => { if (nf === '111') delete H.cats_vis[c.id]; else H.cats_vis[c.id] = nf; r.classList.toggle('is-off', nf === '000'); touch(); }));
    tb.appendChild(r);
  });
  g.appendChild(tb);
  const g2 = hmGroup('Classes in one row'); wrap.appendChild(g2);
  const per = html('<div class="hm-per"><label><span class="hm-per-l">' + DEV_SVG.d + 'Desktop</span><input type="number" min="2" max="10"></label><label><span class="hm-per-l">' + DEV_SVG.m + 'Mobile</span><input type="number" min="2" max="5"></label></div>');
  const [dI, mI] = per.querySelectorAll('input'); dI.value = H.cats_cols_d || 8; mI.value = H.cats_cols_m || 3;
  dI.oninput = () => { H.cats_cols_d = Math.max(2, Math.min(10, +dI.value || 8)); touch(); };
  mI.oninput = () => { H.cats_cols_m = Math.max(2, Math.min(5, +mI.value || 3)); touch(); };
  g2.appendChild(per);
}

/* ---- Meet our teachers: every teacher, show / hide and order (saved at once) ---- */
function hmTeachers(wrap) {
  const g = hmGroup('Teachers', 'The eye shows or hides a teacher on the homepage; the arrows change the order.'); wrap.appendChild(g);
  if (!HMDB) { g.appendChild(hintEl('Loading…')); return; }
  g.appendChild(hmInstant('Changes save at once.'));
  const rows = HMDB.teachers || [];
  if (!rows.length) g.appendChild(html('<p class="hm-desc">No teachers yet — give a user the Teacher permission in Users.</p>'));
  const order = (id, dir) => { const ids = rows.map(r => r.id), k = ids.indexOf(id), t = k + dir; if (t < 0 || t >= ids.length) return; [ids[k], ids[t]] = [ids[t], ids[k]]; hmSend({ action: 'teacher_order', ids: ids.join(',') }); };
  rows.forEach((r, k) => {
    const it = hmItem(r.photo, r.name, (r.subject || 'Teacher') + (r.show ? '' : ' · hidden'), !r.show, true);
    it.querySelector('.hm-acts').append(
      hmGhost(r.show ? 'fa-eye' : 'fa-eye-slash', r.show ? 'Hide on the homepage' : 'Show on the homepage', () => hmSend({ action: 'teacher_vis', id: r.id, show: r.show ? 0 : 1 }), r.show ? '' : 'is-off'),
      hmGhost('fa-arrow-up', 'Move up', () => order(r.id, -1), k === 0 ? 'is-dis' : ''), hmGhost('fa-arrow-down', 'Move down', () => order(r.id, 1), k === rows.length - 1 ? 'is-dis' : ''));
    g.appendChild(it);
  });
}

/* ---- Free courses ---- */
function hmCatSelect(cur) { return html('<label class="cz-field"><span class="cz-label">Category</span><select><option value="0">— No category —</option>' + (HMDB.cats || []).map(c => '<option value="' + c.id + '"' + (+cur === c.id ? ' selected' : '') + '>' + esc(c.name) + '</option>').join('') + '</select></label>'); }
function hmTchSelect(cur) { return html('<label class="cz-field"><span class="cz-label">Teacher (shown on the card)</span><select><option value="0">— No teacher —</option>' + (HMDB.teachers || []).map(t => '<option value="' + t.id + '"' + (+cur === t.id ? ' selected' : '') + '>' + esc(t.name) + '</option>').join('') + '</select></label>'); }
function hmInput(label, type, val, attrs) { return html('<label class="cz-field"><span class="cz-label">' + esc(label) + '</span><input type="' + type + '" ' + (attrs || '') + ' value="' + esc(val ?? '') + '"></label>'); }
function hmCheck(label, on) { return html('<label class="hm-chk"><input type="checkbox"' + (on ? ' checked' : '') + '> ' + esc(label) + '</label>'); }
function hmItem(img, title, sub, off, tall) {
  const it = html('<div class="hm-item' + (off ? ' is-off' : '') + (tall ? ' tall' : '') + '"><span class="hm-item-i"></span><div class="hm-item-t"><b></b><small></small></div><span class="hm-acts"></span></div>');
  if (img) { const im = html('<img alt="">'); im.src = img; im.onerror = () => im.remove(); it.querySelector('.hm-item-i').appendChild(im); } else it.querySelector('.hm-item-i').innerHTML = '<i class="fas fa-user-graduate"></i>';
  it.querySelector('b').textContent = title; it.querySelector('small').textContent = sub; return it;
}
function hmFree(wrap) { // free content = courses ticked "Add to Free Courses" (Online Courses → course → sidebar)
  const g = hmGroup('Free courses'); wrap.appendChild(g);
  g.appendChild(hintEl('This section shows the courses ticked "Add to Free Courses" in their course page (ticked by itself when the sale price is 0). Add the YouTube playlist of a course on the Lectures page.'));
  g.appendChild(html('<div class="cz-btn-row"><a class="cz-btn-outline" href="/admin/shop-products.php?type=course&amp;price=free" target="_blank">Open free courses</a><a class="cz-btn-outline" href="/admin/shop-products.php?new=1&amp;type=course" target="_blank">Add a course</a></div>'));
}

/* ---- Toppers ---- */
function hmToppers(wrap) {
  const g = hmGroup('Students'); wrap.appendChild(g);
  if (!HMDB) { g.appendChild(hintEl('Loading…')); return; }
  
  const rows = HMDB.toppers;
  const order = (id, dir) => { const ids = rows.map(r => r.id), k = ids.indexOf(id), t = k + dir; if (t < 0 || t >= ids.length) return; [ids[k], ids[t]] = [ids[t], ids[k]]; hmSend({ action: 'top_order', ids: ids.join(',') }); };
  rows.forEach((r, k) => {
    const it = hmItem(r.photo_url, r.name, [r.result ? 'Marks: ' + r.result : '', r.class_name, r.year].filter(Boolean).join(' · ') + (+r.active ? '' : ' · hidden'), !+r.active, true);
    it.querySelector('.hm-acts').append(hmGhost('fa-arrow-up', 'Move up', () => order(r.id, -1), k === 0 ? 'is-dis' : ''), hmGhost('fa-pen', 'Edit', () => { hmEdit = hmEdit === 't' + r.id ? null : 't' + r.id; rerender(); }),
      hmGhost('fa-trash-can', 'Remove', () => { if (confirm('Remove this topper?')) hmSend({ action: 'top_delete', id: r.id }); }, 'danger'));
    g.appendChild(it);
    if (hmEdit === 't' + r.id) g.appendChild(hmTopForm(r));
  });
  if (hmEdit === 't0') g.appendChild(hmTopForm(null));
  else { g.appendChild(hmAddBtn('Add topper', () => { hmEdit = 't0'; rerender(); })); }
}
function hmTopForm(r) {
  const f = html('<div class="hm-form"></div>');
  let photo = r ? (r.photo || '') : '', photoUrl = r ? r.photo_url : '';
  const pw = html('<div class="hm-photo"><span class="hm-photo-i"></span><div><button type="button" class="cz-btn-outline"><i class="fas fa-image"></i> Choose photo</button><small class="hm-desc">Passport size, e.g. 300 × 400 px.</small></div></div>');
  const paint = () => { pw.querySelector('.hm-photo-i').innerHTML = photoUrl ? '<img alt="" src="' + esc(photoUrl) + '">' : '<i class="fas fa-user-graduate"></i>'; };
  paint(); pw.querySelector('button').onclick = () => EduMedia.pick(it => { photo = it.path; photoUrl = it.path; paint(); });
  const n = hmInput('Student’s name', 'text', r ? r.name : '', 'maxlength="120"'), m = hmInput('Marks', 'text', r ? r.result : '', 'maxlength="160" placeholder="e.g. 454 or 487/500 or 96.4%"');
  const two = html('<div class="hm-two"></div>'); const c = hmInput('Class / exam', 'text', r ? r.class_name : '', 'maxlength="80" placeholder="Class 10"'), y = hmInput('Year', 'text', r ? r.year : '', 'maxlength="10" placeholder="2026"'); two.append(c, y);
  const a = hmCheck('Show on the homepage', !r || +r.active);
  const row = html('<div class="cz-btn-row"><button type="button" class="cz-btn-primary">' + (r ? 'Save' : 'Add topper') + '</button><button type="button" class="cz-btn-outline">Cancel</button></div>');
  row.children[0].onclick = () => {
    const name = n.querySelector('input').value.trim(); if (!name) { alert('Please type the student’s name.'); return; }
    const v = { action: 'top_save', id: r ? r.id : 0, name, result: m.querySelector('input').value, class_name: c.querySelector('input').value, year: y.querySelector('input').value, sort: r ? r.sort : (HMDB.toppers.length + 1) };
    if (photo && (!r || photo !== r.photo)) v.photo_path = photo; if (a.querySelector('input').checked) v.active = 1;
    hmSend(v, { done: () => { hmEdit = null; } });
  };
  row.children[1].onclick = () => { hmEdit = null; rerender(); };
  f.append(pw, n, m, two, a, row);
  return f;
}

/* ---- Our Impact / Why choose us: rows of icon · text · (order) · remove ---- */
function hmIcoLead(v, fallback) { return '<span class="hm-w-ic' + (v ? '' : ' auto') + '" title="' + (v ? 'Icon' : 'Automatic icon') + '">' + (v ? (v.startsWith('<') ? v : (PICK_ICONS[v] || '')) : (PICK_ICONS[fallback] || '')) + '</span>'; }
function hmIconField(get, set, w, fallback) {
  const f = html('<div class="cz-field"><span class="cz-label">Icon</span><div class="hm-icrow"><span class="hm-icw"></span><small class="cz-hint">Pick one, or paste your own SVG. “Auto” = chosen for you.</small></div></div>');
  f.querySelector('.hm-icw').appendChild(hmIconBtn(get, v => { set(v); w.querySelector('.hm-w-lead').innerHTML = hmIcoLead(v, fallback); }));
  return f;
}
function hmImpact(wrap, H) {
  const g = hmGroup('Numbers'); wrap.appendChild(g);
  const list = html('<div class="hm-wl"></div>'); g.appendChild(list);
  H.impact.forEach((it, i) => {
    const auto = it.k && it.k !== 'years';
    list.appendChild(hmWidget('i' + i, {
      lead: hmIcoLead(it.i, 'trend'), title: it.l || '(no label)', sub: it.v || (auto ? 'Counted automatically' : ''),
      remove: () => { H.impact.splice(i, 1); hmEdit = null; touch(); rerender(); },
      body: (b, w) => {
        b.append(hmIconField(() => it.i || '', v => { it.i = v; touch(); }, w, 'trend'),
          cText('Label', () => it.l, v => { it.l = v; w.querySelector('.hm-w-t b').textContent = v || '(no label)'; }),
          cText('Number', () => it.v, v => { it.v = v; }, { placeholder: auto ? 'Counted by the site' : 'e.g. 10+', hint: auto ? 'Empty = the site counts it for you.' : '' }));
      }
    }));
  });
  if (H.impact.length < 8) g.appendChild(hmAddBtn('Add number', () => { H.impact.push({ k: '', l: '', v: '', i: '' }); hmEdit = 'i' + (H.impact.length - 1); touch(); rerender(); }));
}
function hmWhy(wrap, H) {
  const g0 = hmGroup(''); g0.appendChild(cText('Text under the heading', () => H.why_text, v => H.why_text = v, { multi: true, placeholder: 'Optional' })); wrap.appendChild(g0);
  const g = hmGroup('Points'); wrap.appendChild(g);
  const list = html('<div class="hm-wl"></div>'); g.appendChild(list);
  const move = (i, d) => { const j = i + d; if (j < 0 || j >= H.why.length) return; [H.why[i], H.why[j]] = [H.why[j], H.why[i]]; if (hmEdit === 'w' + i) hmEdit = 'w' + j; touch(); rerender(); };
  H.why.forEach((w0, i) => {
    list.appendChild(hmWidget('w' + i, {
      lead: hmIcoLead(w0.i, 'check'), title: w0.t || '(empty point)',
      extra: [hmGhost('fa-arrow-up', 'Move up', () => move(i, -1), i === 0 ? 'is-dis' : ''), hmGhost('fa-arrow-down', 'Move down', () => move(i, 1), i === H.why.length - 1 ? 'is-dis' : '')],
      remove: () => { H.why.splice(i, 1); hmEdit = null; touch(); rerender(); },
      body: (b, w) => {
        b.append(hmIconField(() => w0.i || '', v => { w0.i = v; touch(); }, w, 'check'),
          cText('Point', () => w0.t, v => { w0.t = v; w.querySelector('.hm-w-t b').textContent = v || '(empty point)'; }, { placeholder: 'e.g. Weekly tests' }));
      }
    }));
  });
  if (H.why.length < 12) g.appendChild(hmAddBtn('Add point', () => { H.why.push({ t: '', i: '' }); hmEdit = 'w' + (H.why.length - 1); touch(); rerender(); }));
}

function showHome() {
  panel = null; addingFor = null; document.querySelectorAll('.cz-additems').forEach(x => x.remove()); $('cz-back').hidden = true; $('cz-head-small').textContent = 'You are customizing'; $('cz-head-title').textContent = <?= json_encode(edu_site_name()) ?>;
  const b = $('cz-body'); b.innerHTML = ''; b.classList.remove('hm-bg');
  const nav = document.createElement('nav'); nav.className = 'cz-nav';
  const navItems = [];
  CZ_NAV.forEach(n => {
    if (typeof n === 'string') { const p = PANELS.find(x => x.id === n); if (czAllowed(p)) navItems.push({ label: p.label, icon: p.icon, sub: p.sub, go: () => { czFrom = null; showPanel(p); } }); }
    else if (n.items.some(id => czAllowed(PANELS.find(x => x.id === id)))) navItems.push({ label: n.label, icon: 'fa-table-columns', sub: '', go: () => showGroup(n) });
  });
  navItems.forEach(p => {
    const btn = document.createElement('button'); btn.type = 'button';
    btn.innerHTML = '<span class="cz-nav-ico"><i class="fas ' + p.icon + '"></i></span><span class="cz-nav-text"><strong>' + esc(p.label) + '</strong><small>' + esc(p.sub) + '</small></span><i class="fas fa-chevron-right"></i>';
    btn.onclick = p.go; nav.appendChild(btn);
  });
  b.appendChild(nav);
  const r = document.createElement('div'); r.className = 'cz-dreset';
  r.innerHTML = '<strong>Reset design</strong><small>All Customizer settings back to the default theme. Posts, pages, media, push and SEO are not touched.</small><button type="button" class="cz-dreset-btn"><i class="fas fa-rotate-left"></i> Reset to default</button>' + (HAS_BACKUP ? '<button type="button" class="cz-dreset-undo">Undo the last reset' + (BACKUP_AT ? ' (' + new Date(BACKUP_AT).toLocaleString() + ')' : '') + '</button>' : '');
  r.querySelector('.cz-dreset-btn').onclick = () => {
    if (!confirm('Reset every Customizer setting to the default design?\n\nPosts, pages, media, push and SEO are not touched. You can undo this afterwards.')) return;
    post('reset').then(() => location.reload());
  };
  const u = r.querySelector('.cz-dreset-undo');
  if (u) u.onclick = () => { if (confirm('Restore the design from before the last reset?')) post('undo_reset').then(() => location.reload()); };
  if (!CZ_ONLY) b.appendChild(r); // reset is for admins only
}
function showPanel(p, keep) {
  if (!keep) { subOpen = null; subPrevBack = null; eyeOpen = null; typoOpen = null; menuView = null; miOpen = null; addingFor = null; arOpen = undefined; openFont = null; openRule = null; gpalEditing = null; hmOpen = null; hmEdit = null; $('cz-back').onclick = exitPanel; }
  if (!addingFor) document.querySelectorAll('.cz-additems').forEach(x => x.remove());
  if (!keep) czTab = 'general';
  const grp = czFrom !== undefined ? czFrom : p._group;
  panel = p; $('cz-back').hidden = false; $('cz-head-small').textContent = grp ? 'Customizing ▸ ' + grp.label : 'Customizing'; $('cz-head-title').textContent = p.id === 'identity' && grp ? 'Site Title & Logo' : p.label;
  const b = $('cz-body'); b.innerHTML = ''; b.classList.remove('hm-bg');
  const wrap = document.createElement('div'); wrap.className = 'cz-panel-fields';
  subCapture = null;
  if (p.design) { // Astra: General / Design tabs
    const tb = html('<div class="cz-tabs" role="tablist"><button type="button" role="tab" data-t="general">General</button><button type="button" role="tab" data-t="design">Design</button></div>');
    tb.querySelectorAll('button').forEach(x => { const on = x.dataset.t === czTab; x.classList.toggle('active', on); x.setAttribute('aria-selected', on); x.onclick = () => { czTab = x.dataset.t; subOpen = null; showPanel(p, true); }; });
    wrap.appendChild(tb);
  }
  const czDesignTab = p.design && czTab === 'design';
  if (czDesignTab) czDesign(wrap, p.design);
  if (p.fields && !czDesignTab) p.fields.forEach(f => wrap.appendChild(field(f)));
  if (p.sections && !czDesignTab) { // like WordPress: a panel lists its sections; each one opens on its own screen
    if (p.sections.length === 1) p.sections[0][1].forEach(f => wrap.appendChild(field(f)));
    else p.sections.forEach(sc => wrap.appendChild(sectionEl(sc[0], b => sc[1].forEach(f => b.appendChild(field(f))))));
  }
  if (p.render && !czDesignTab) p.render(wrap);
  if (subOpen !== null && subCapture) { // one section on its own screen
    wrap.innerHTML = ''; wrap.appendChild(subCapture); subCapture = null;
    $('cz-head-small').textContent = 'Customizing ▸ ' + ($('cz-head-title').dataset.trail || p.label); $('cz-head-title').textContent = subOpen;
    const cur = $('cz-back').onclick; if (!cur || !cur._sub) subPrevBack = cur;
    const back = () => { subOpen = null; showPanel(p, true); $('cz-body').scrollTop = 0; }; back._sub = true; $('cz-back').onclick = back;
  } else {
    if (subOpen !== null) subOpen = null;
    if ($('cz-back').onclick && $('cz-back').onclick._sub) { $('cz-back').onclick = subPrevBack || exitPanel; subPrevBack = null; }
  }
  $('cz-head-title').dataset.trail = '';
  b.appendChild(wrap);
  if (keep) return;
  if (p.id === 'post' || p.id === 'alsoread' || p.id === 'progress' || p.id === 'sidebar' || p.id === 'typography') goTo(<?= json_encode($latestUrl) ?>);
  else if (p.id === 'home') goTo('/latest-update');
  else if (p.id === 'homepage') goTo('/');
  else if (p.id === 'nf') goTo('/page-that-does-not-exist');
}
function goTo(path) { if (path === framePath) return; framePath = path; reloadFrame(); }
$('cz-back').onclick = showHome;
// Like WordPress: links clicked inside the preview open in the preview (with the unsaved changes); outside links do nothing
function czFrameLinks() {
  try {
    const f = $('cz-frame'), d = f.contentDocument, loc = f.contentWindow.location;
    if (loc.origin === location.origin) { const u = new URL(loc.href); u.searchParams.delete('czp'); u.searchParams.delete('t'); framePath = u.pathname + (u.search || ''); }
    d.addEventListener('click', e => {
      const a = e.target.closest && e.target.closest('a[href]'); if (!a || e.defaultPrevented) return;
      const href = a.getAttribute('href') || ''; if (href.startsWith('#') || href.startsWith('javascript:')) return;
      e.preventDefault();
      let u; try { u = new URL(a.href, loc.href); } catch (er) { return; }
      if (u.origin !== location.origin || /^\/admin(\/|-login)/.test(u.pathname)) return; // the preview stays on the site
      u.searchParams.delete('czp'); u.searchParams.delete('t'); framePath = ''; goTo(u.pathname + u.search);
    }, true);
  } catch (e) {}
}
$('cz-frame').addEventListener('load', czFrameLinks);
$('cz-publish').onclick = () => {
  $('cz-publish').disabled = true; $('cz-publish').textContent = 'Publishing…';
  post('publish').then(r => { if (r.ok) { dirty = false; $('cz-status').textContent = 'All changes published'; $('cz-publish').textContent = 'Published'; } else { $('cz-publish').disabled = false; $('cz-publish').textContent = 'Publish'; alert('Could not publish. Please try again.'); } });
};
$('cz-hide').onclick = () => { $('cz-root').classList.add('cz-collapsed'); $('cz-show').hidden = false; };
$('cz-show').onclick = () => { $('cz-root').classList.remove('cz-collapsed'); $('cz-show').hidden = true; };
document.querySelectorAll('#cz-devices button').forEach(b => b.onclick = () => setDevice(b.dataset.dev));
window.addEventListener('beforeunload', e => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
showHome();
if (START_PANEL) { const sp = PANELS.find(x => x.id === START_PANEL && (!CZ_ONLY || CZ_ONLY.includes(x.id))); if (sp) showPanel(sp); }
</script>
</body>
</html>
