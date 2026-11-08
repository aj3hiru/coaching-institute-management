<?php
// functions.php - Helper Functions

function generateSlug($text) {
    $slug = strtolower($text);
    $slug = preg_replace('/\s+/', '-', $slug);
    $slug = preg_replace('/[^a-z0-9\-]/', '', $slug);
    $slug = preg_replace('/-+/', '-', $slug);
    $slug = trim($slug, '-');
    return $slug;
}

function postUrl($slug, $id) {
    $slug = trim((string)$slug, '-/ ');
    $id = (int)$id;
    // a title with no English letters gives an empty slug: such posts live at /post-ID
    if ($slug === '' || $slug === 'post') return '/post-' . $id;
    // posts written since clean addresses were switched on live at /slug; older posts keep /slug-ID
    if ($id >= edu_slug_only_from()) return '/' . $slug;
    return "/{$slug}-{$id}";
}

/** First post ID with a clean /slug address (General → slug_only_from); PHP_INT_MAX = all posts use /slug-ID. */
function edu_slug_only_from(): int
{
    static $v = null;
    if ($v !== null) return $v;
    require_once __DIR__ . '/settings.php';
    $c = (int)es('general', 'slug_only_from', 0);
    return $v = $c > 0 ? $c : PHP_INT_MAX;
}

/** Addresses that are not free for a post at /slug (site sections, files, pages, an older post's /slug-ID). */
function edu_slug_reserved(PDO $pdo, string $slug): bool
{
    static $words = ['admin', 'admin-login', 'api', 'assets', 'author', 'cache', 'categories', 'category', 'components', 'cron', 'doc', 'documents', 'feed',
        'includes', 'latest-updates', 'news-sitemap', 'page', 'pages', 'post', 'posts', 'push-notifications', 'rss', 'search', 'sitemap', 'sitemap-index', 'state-jobs',
        'states', 'stories', 'story', 'tag', 'tags', 'templates', 'upload', 'uploads', 'vendor', 'web-stories', 'wp-admin', 'wp-login'];
    if (in_array($slug, $words, true)) return true;
    $root = dirname(__DIR__);
    if (is_file("$root/$slug.php") || is_dir("$root/$slug")) return true;
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM site_pages WHERE slug = ?");
        $st->execute([$slug]);
        if ($st->fetchColumn()) return true;
        // "ssc-cgl-2026" is also the old address of post 2026 if that post's slug is "ssc-cgl"
        if (preg_match('/^(.+)-(\d+)$/', $slug, $m)) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE id = ? AND id < ? AND (slug = ? OR (? = 'post' AND slug IN ('', 'post')))");
            $st->execute([(int)$m[2], edu_slug_only_from(), $m[1], $m[1]]);
            if ($st->fetchColumn()) return true;
        }
    } catch (Throwable $e) {}
    return false;
}

/** A free /slug for a clean-address post: "slug", then "slug-2", "slug-3" … like WordPress. */
function edu_unique_post_slug(PDO $pdo, string $slug, int $excludeId = 0): string
{
    $base = mb_substr(trim(preg_replace('/-+/', '-', $slug), '-'), 0, 150);
    if ($base === '' || $base === 'post') return $base;
    // only clean-address posts share the /slug space (older posts live at /slug-ID)
    $from = edu_slug_only_from() === PHP_INT_MAX ? 0 : edu_slug_only_from();
    $st = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE slug = ? AND id <> ? AND id >= ?");
    for ($i = 1; $i < 500; $i++) {
        $try = $i === 1 ? $base : $base . '-' . $i;
        if (edu_slug_reserved($pdo, $try)) continue;
        $st->execute([$try, $excludeId, $from]);
        if (!$st->fetchColumn()) return $try;
    }
    return $base . '-' . substr(md5(uniqid('', true)), 0, 6);
}

/** Clean addresses that end in a number ("ssc-cgl-2026") look like old /slug-ID addresses: this list tells post.php. */
function edu_slugmap_file(): string
{
    return dirname(__DIR__) . '/.edu-slugmap.json';
}
function edu_slugmap_write(PDO $pdo): void
{
    $map = [];
    try {
        $st = $pdo->prepare("SELECT id, slug FROM posts WHERE id >= ? AND slug REGEXP '-[0-9]+$'");
        $st->execute([edu_slug_only_from() === PHP_INT_MAX ? PHP_INT_MAX : edu_slug_only_from()]);
        foreach ($st as $r) $map[$r['slug']] = (int)$r['id'];
        $tmp = edu_slugmap_file() . '.tmp';
        if (file_put_contents($tmp, json_encode($map)) !== false) rename($tmp, edu_slugmap_file());
    } catch (Throwable $e) {
        error_log('slugmap: ' . $e->getMessage());
    }
}

function tagUrl($slug, $id) {
    return "/tag/{$slug}-{$id}";
}

function storyUrl($slug, $id) {
    return "/story/{$slug}-{$id}";
}

function formatViews($views) {
    if ($views >= 1000000) {
        return round($views / 1000000, 1) . 'M';
    } elseif ($views >= 1000) {
        return round($views / 1000, 1) . 'K';
    }
    return $views;
}

// Fetch comments (with pagination and total)
function getCommentTree($pdo, $post_id, $offset = 0, $limit = 5) {
    $stmt = $pdo->prepare("SELECT c.id, c.post_id, c.parent_id, c.name, c.content, c.date, p.name AS parent_name, COUNT(*) OVER() AS total_count FROM comments c LEFT JOIN comments p ON c.parent_id = p.id WHERE c.post_id = ? AND c.status = 'approved' ORDER BY c.date ASC LIMIT ?, ?");
    $stmt->bindValue(1, $post_id, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset, PDO::PARAM_INT);
    $stmt->bindValue(3, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$comments) return [[], 0];
    $total = (int)$comments[0]['total_count'];
    $tree = [];
    $map = [];
    foreach ($comments as $c) {
        $c['children'] = [];
        unset($c['total_count']); 
        $map[$c['id']] = $c;
        if ($c['parent_id'] && isset($map[$c['parent_id']])) {
            $map[$c['parent_id']]['children'][] = &$map[$c['id']];
        } else {
            $tree[] = &$map[$c['id']];
        }
    }
    return [$tree, $total];
}

function renderComments($comments, $depth=0) {
    foreach ($comments as $comment) {
        $replyClass = $depth > 0 ? 'blog-comment-item blog-comment-reply' : 'blog-comment-item';
        $date = date('M j, Y \a\t g:i a', strtotime($comment['date']));
        echo "<div class='$replyClass' data-comment-id='{$comment['id']}'>";
        echo "<div class='blog-comment-header'>";
        echo "<span class='blog-comment-author'>".htmlspecialchars($comment['name'])."</span>";
        echo "<span class='blog-comment-date'>$date</span>";
        echo "</div>";
        if ($comment['parent_id'] && $comment['parent_name']) {
            echo "<div style='color:#6c757d;font-size:0.75rem;margin-bottom:5px;'>Replying to <strong>".htmlspecialchars($comment['parent_name'])."</strong></div>";
        }
        echo "<div class='blog-comment-content'>".nl2br(htmlspecialchars($comment['content']))."</div>";
        echo "<div class='blog-comment-actions'>";
       echo "<button class='blog-comment-reply-btn' data-id='{$comment['id']}' data-name='".htmlspecialchars($comment['name'], ENT_QUOTES)."'>Reply</button>"; 
        echo "</div>";
        echo "</div>";
        if (!empty($comment['children'])) {
            renderComments($comment['children'], $depth+1);
        }
    }
}

// TOC Generation
function generateTOC($c, $heading = ''){
    if(!$c||!is_string($c))return['toc'=>'','content'=>$c];
    if (trim((string)$heading) === '') $heading = (function_exists('th') ? trim((string)th('p_toc_heading')) : '') ?: 'Table of Contents'; // Customize → Single Post → Table of contents
    $i=[];$l=2;$t='<div class="toc"><h2 class="em-wt"><span class="em-wt-l">'.htmlspecialchars($heading).'</span></h2><ol>';
    $c=preg_replace_callback('/<h([2-6])>(.*?)<\/h\1>/i',function($a)use(&$t,&$i,&$l){
        $lev=(int)$a[1];$hPlain=trim(strip_tags($a[2]));$hHTML=trim($a[2]);if(!$hPlain)return $a[0];
        // Hindi and other scripts keep their letters (a Latin-only id made Hindi headings unreachable)
        $id=trim(preg_replace('/[^\p{L}\p{M}\p{N}]+/u','-',$hPlain),'-');if($id==='')$id='section';
        $i[$id]=($i[$id]??0)+1;$uid=$i[$id]>1?"$id-{$i[$id]}":$id;
        while($lev<$l){$t.='</li></ol>'; $l--;}
        while($lev>$l){$t.='<ol>'; $l++;}
        if($lev==$l && $l>2)$t.='</li>';
        $t.="<li><a href='#$uid'>".htmlspecialchars($hPlain)."</a>";
        return "<h$lev id='$uid'>$hHTML</h$lev>";
    },$c);
    if(!$i)return['toc'=>'','content'=>$c]; // no headings → no empty contents box
    while($l>2){$t.='</li></ol>'; $l--;}
    $t.='</li></ol></div>';
    return['toc'=>$t,'content'=>$c];
}
?>