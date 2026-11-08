<?php
// Online store → saves what is inside one product (the Lectures section of the course edit page posts here, and a
// visit to this address opens that section): chapters, lessons, many YouTube videos at once, a chapter linked to a YouTube playlist
// (its videos become lectures; videos added to the playlist later are fetched by themselves, every 6 hours or with
// "Sync now"), and PDF files.
require_once __DIR__ . '/components/guard.php';
require_once INCLUDES_PATH . '/institute.php';
require_once INCLUDES_PATH . '/shop.php';
$me = sh_page_start($pdo, ['products']);
sh_tables($pdo);
$pid = (int)($_GET['id'] ?? $_POST['pid'] ?? 0);
$p = $pid ? sh_product($pdo, $pid, false) : null;
if (!$p) { header('Location: /admin/shop-products.php'); exit; }
$self = '/admin/shop-products.php?edit=' . $pid . '&type=' . $p['type']; // lectures are managed on the course edit page
$dur = fn($v) => preg_match('/^\d{1,3}:\d{2}(:\d{2})?$/', trim((string)$v)) ? trim((string)$v) : null;

/** Deletes the uploaded files of a lesson (main video + its qualities). */
function shc_drop_media(PDO $pdo, int $id): void
{
    $l = $pdo->query("SELECT video_file, variants, pdf_file FROM sh_lessons WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
    if (!$l) return;
    foreach (array_filter(array_merge([$l['video_file'], $l['pdf_file']], array_values(sh_variants($l)))) as $f) { $f = sh_media_path($f); if (is_file($f)) @unlink($f); }
}
/** Swaps the order of a row with its neighbour (sh_sections / sh_lessons / sh_files) within the same product (and section). */
function shc_move(PDO $pdo, string $table, int $id, int $pid, int $dir, string $extra = ''): void
{
    $rows = $pdo->query("SELECT id FROM $table WHERE product_id = $pid $extra ORDER BY sort, id")->fetchAll(PDO::FETCH_COLUMN);
    $i = array_search($id, array_map('intval', $rows), true);
    if ($i === false) return;
    $j = $i + $dir;
    if ($j < 0 || $j >= count($rows)) return;
    [$rows[$i], $rows[$j]] = [$rows[$j], $rows[$i]];
    $st = $pdo->prepare("UPDATE $table SET sort = ? WHERE id = ?");
    foreach ($rows as $n => $rid) $st->execute([$n + 1, $rid]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $ajax = !empty($_POST['ajax']);
    try {
        switch ($a) {
            case 'add_section':
                $t = trim((string)($_POST['title'] ?? ''));
                if ($t === '') throw new RuntimeException('Enter the chapter name.');
                $sort = (int)$pdo->query("SELECT COALESCE(MAX(sort),0) + 1 FROM sh_sections WHERE product_id = $pid")->fetchColumn();
                $pdo->prepare("INSERT INTO sh_sections (product_id, title, sort) VALUES (?, ?, ?)")->execute([$pid, mb_substr($t, 0, 200), $sort]);
                admin_flash('Chapter added.');
                break;
            case 'rename_section':
                $t = trim((string)($_POST['title'] ?? ''));
                if ($t === '') throw new RuntimeException('Enter the chapter name.');
                $pdo->prepare("UPDATE sh_sections SET title = ? WHERE id = ? AND product_id = ?")->execute([mb_substr($t, 0, 200), $id, $pid]);
                admin_flash('Chapter renamed.');
                break;
            case 'delete_section':
                $pdo->prepare("UPDATE sh_lessons SET section_id = NULL WHERE section_id = ? AND product_id = ?")->execute([$id, $pid]);
                $pdo->prepare("DELETE FROM sh_sections WHERE id = ? AND product_id = ?")->execute([$id, $pid]);
                admin_flash('Chapter removed. Its videos are kept (now without a chapter).');
                break;
            case 'move_section':
                shc_move($pdo, 'sh_sections', $id, $pid, (int)($_POST['dir'] ?? 1) < 0 ? -1 : 1);
                break;
            case 'save_lesson':
                $t = trim((string)($_POST['title'] ?? ''));
                if ($t === '') throw new RuntimeException('Enter the video title.');
                $sec = (int)($_POST['section_id'] ?? 0);
                if ($sec && !(int)$pdo->query("SELECT COUNT(*) FROM sh_sections WHERE id = $sec AND product_id = $pid")->fetchColumn()) $sec = 0;
                $src = (string)($_POST['source'] ?? 'upload');
                $url = trim((string)($_POST['video_url'] ?? ''));
                if ($src === 'link') {
                    if ($url === '' || !preg_match('#^https://#i', $url)) throw new RuntimeException('Paste the YouTube (or other video) link — it must start with https://');
                    if (preg_match('~youtu\.?be~i', $url) && !sh_yt_id($url)) throw new RuntimeException('This YouTube link has no video in it. Open the video and copy its link.');
                } else $url = '';
                $d = $dur($_POST['duration'] ?? '');
                if ($src === 'link' && !$d && ($yt = sh_yt_id($url))) $d = sh_yt_duration($yt) ?: null;
                $free = !empty($_POST['free']) ? 1 : 0;
                $notes = trim((string)($_POST['notes'] ?? '')) ?: null;
                if ($id) {
                    if (!(int)$pdo->query("SELECT COUNT(*) FROM sh_lessons WHERE id = $id AND product_id = $pid")->fetchColumn()) throw new RuntimeException('Video not found.');
                    if ($src === 'link') { // switching to a link: the uploaded files are no longer needed
                        shc_drop_media($pdo, $id);
                        $pdo->prepare("UPDATE sh_lessons SET title=?, section_id=?, video_url=?, video_file=NULL, variants=NULL, height=NULL, size=0, duration=?, free=?, notes=? WHERE id=?")->execute([mb_substr($t, 0, 200), $sec ?: null, $url, $d, $free, $notes, $id]);
                    } else {
                        $pdo->prepare("UPDATE sh_lessons SET title=?, section_id=?, duration=COALESCE(?, duration), free=?, notes=? WHERE id=?")->execute([mb_substr($t, 0, 200), $sec ?: null, $d, $free, $notes, $id]);
                    }
                } else {
                    $sort = (int)$pdo->query("SELECT COALESCE(MAX(sort),0) + 1 FROM sh_lessons WHERE product_id = $pid")->fetchColumn();
                    $pdo->prepare("INSERT INTO sh_lessons (product_id, section_id, title, video_url, duration, free, notes, sort) VALUES (?,?,?,?,?,?,?,?)")
                        ->execute([$pid, $sec ?: null, mb_substr($t, 0, 200), $url ?: null, $d, $free, $notes, $sort]);
                    $id = (int)$pdo->lastInsertId();
                }
                if ($ajax) { header('Content-Type: application/json'); exit(json_encode(['ok' => true, 'id' => $id])); }
                admin_flash($src === 'link' ? 'Video saved (YouTube / link).' : 'Video saved.');
                break;
            case 'link_playlist': // a chapter that follows a YouTube playlist
                [$sec, $n] = sh_link_playlist($pdo, $pid, (string)($_POST['url'] ?? ''), (int)($_POST['section_id'] ?? 0), trim((string)($_POST['title'] ?? '')), (int)($_POST['free_first'] ?? 0));
                admin_log('shop_content', "Linked a YouTube playlist to chapter #$sec of store product #$pid ($n added)");
                admin_flash('Playlist linked. ' . $n . ' video' . ($n === 1 ? '' : 's') . ' added as lectures. New videos you add to this playlist on YouTube come in by themselves (checked every 6 hours, or press Sync now).');
                $_POST['anchor'] = 'list';
                break;
            case 'sync_section':
                @set_time_limit(300);
                $n = sh_yt_sync_section($pdo, $id);
                admin_flash($n < 0 ? 'Could not read the playlist right now — check that it is still Public or Unlisted.' : ($n ? $n . ' new video' . ($n === 1 ? '' : 's') . ' added from the playlist.' : 'Up to date — no new videos in the playlist.'), $n < 0 ? 'error' : 'success');
                break;
            case 'unlink_playlist':
                $pdo->prepare("UPDATE sh_sections SET yt_list = NULL, yt_synced = NULL WHERE id = ? AND product_id = ?")->execute([$id, $pid]);
                admin_flash('Playlist unlinked. The lectures already added stay in the chapter.');
                break;
            case 'bulk_youtube':
                $sec = (int)($_POST['section_id'] ?? 0);
                if ($sec && !(int)$pdo->query("SELECT COUNT(*) FROM sh_sections WHERE id = $sec AND product_id = $pid")->fetchColumn()) $sec = 0;
                $lines = preg_split('/\R/', (string)($_POST['links'] ?? ''));
                $items = []; $bad = 0;
                foreach ($lines as $ln) {
                    $ln = trim($ln); if ($ln === '') continue;
                    $title = ''; $link = $ln;
                    if (str_contains($ln, '|')) { [$title, $link] = array_map('trim', explode('|', $ln, 2)); if (!preg_match('#https?://#', $link) && preg_match('#https?://#', $title)) [$title, $link] = [$link, $title]; }
                    $vid = sh_yt_id($link); $list = sh_yt_list($link);
                    if ($list && (!$vid || !empty($_POST['whole_list']))) { // a playlist: every video in it
                        $pl = sh_yt_playlist($list);
                        if (!$pl) { $bad++; continue; }
                        foreach ($pl as [$v, $tt]) $items[$v] ??= [$v, $tt];
                    } elseif ($vid) $items[$vid] ??= [$vid, $title];
                    else $bad++;
                }
                if (!$items) throw new RuntimeException('No YouTube videos found. Paste video links (one per line) or a playlist link. The playlist must be Public or Unlisted.');
                if (count($items) > 300) throw new RuntimeException('Please add at most 300 videos at a time.');
                $have = array_flip(array_filter(array_map(fn($u) => sh_yt_id((string)$u), $pdo->query("SELECT video_url FROM sh_lessons WHERE product_id = $pid AND video_url IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN))));
                $sort = (int)$pdo->query("SELECT COALESCE(MAX(sort),0) FROM sh_lessons WHERE product_id = $pid")->fetchColumn();
                $ins = $pdo->prepare("INSERT INTO sh_lessons (product_id, section_id, title, video_url, duration, free, sort) VALUES (?,?,?,?,?,?,?)");
                $added = 0; $skipped = 0; $freeFirst = max(0, (int)($_POST['free_first'] ?? 0)); @set_time_limit(300);
                foreach (array_values($items) as $n => [$v, $tt]) {
                    if (isset($have[$v])) { $skipped++; continue; }
                    if ($tt === '') $tt = sh_yt_title($v) ?: 'Video ' . ($sort + 1);
                    $ins->execute([$pid, $sec ?: null, mb_substr($tt, 0, 200), 'https://www.youtube.com/watch?v=' . $v, count($items) <= 40 ? (sh_yt_duration($v) ?: null) : null, $added < $freeFirst ? 1 : 0, ++$sort]);
                    $added++;
                }
                admin_log('shop_content', "Added $added YouTube videos to store product #$pid");
                admin_flash($added . ' YouTube video' . ($added === 1 ? '' : 's') . ' added.' . ($skipped ? " $skipped already in this course were skipped." : '') . ($bad ? " $bad line(s) were not YouTube links / playlists." : ''), $added ? 'success' : 'error');
                break;
            case 'delete_lesson':
                if (!(int)$pdo->query("SELECT COUNT(*) FROM sh_lessons WHERE id = $id AND product_id = $pid")->fetchColumn()) break;
                shc_drop_media($pdo, $id);
                foreach ($pdo->query("SELECT file FROM sh_files WHERE lesson_id = $id")->fetchAll(PDO::FETCH_COLUMN) as $nf) sh_media_unlink($nf);
                $pdo->exec("DELETE FROM sh_files WHERE lesson_id = $id"); $pdo->exec("DELETE FROM sh_doubts WHERE lesson_id = $id");
                $pdo->prepare("DELETE FROM sh_lessons WHERE id = ? AND product_id = ?")->execute([$id, $pid]);
                $pdo->prepare("DELETE FROM sh_progress WHERE lesson_id = ?")->execute([$id]);
                admin_flash('Video deleted.');
                break;
            case 'remove_quality': // drop one smaller quality of a lesson video
                $l = $pdo->query("SELECT variants FROM sh_lessons WHERE id = $id AND product_id = $pid")->fetch(PDO::FETCH_ASSOC);
                $vv = $l ? sh_variants($l) : []; $qq = (string)($_POST['q'] ?? '');
                if (isset($vv[$qq])) { $f = sh_media_path($vv[$qq]); if (is_file($f)) @unlink($f); unset($vv[$qq]); $pdo->prepare("UPDATE sh_lessons SET variants = ? WHERE id = ?")->execute([$vv ? json_encode($vv) : null, $id]); }
                admin_flash($qq . 'p quality removed.');
                admin_flash('Uploaded video removed.');
                break;
            case 'move_lesson':
                $sec = $pdo->query("SELECT section_id FROM sh_lessons WHERE id = $id AND product_id = $pid")->fetchColumn();
                shc_move($pdo, 'sh_lessons', $id, $pid, (int)($_POST['dir'] ?? 1) < 0 ? -1 : 1, $sec ? "AND section_id = " . (int)$sec : "AND section_id IS NULL");
                break;
            case 'toggle_free':
                $t = ($_POST['what'] ?? '') === 'file' ? 'sh_files' : 'sh_lessons';
                $pdo->prepare("UPDATE $t SET free = 1 - free WHERE id = ? AND product_id = ?")->execute([$id, $pid]);
                break;
            case 'rename_file':
                $t = trim((string)($_POST['title'] ?? ''));
                if ($t === '') throw new RuntimeException('Enter the PDF title.');
                $pdo->prepare("UPDATE sh_files SET title = ? WHERE id = ? AND product_id = ?")->execute([mb_substr($t, 0, 200), $id, $pid]);
                admin_flash('PDF renamed.');
                break;
            case 'delete_file':
                $old = $pdo->query("SELECT file FROM sh_files WHERE id = $id AND product_id = $pid")->fetchColumn();
                $pdo->prepare("DELETE FROM sh_files WHERE id = ? AND product_id = ?")->execute([$id, $pid]);
                if ($old) sh_media_unlink($old);
                admin_flash('PDF deleted.');
                break;
            case 'move_file':
                shc_move($pdo, 'sh_files', $id, $pid, (int)($_POST['dir'] ?? 1) < 0 ? -1 : 1);
                break;
        }
    } catch (RuntimeException $e) {
        if ($ajax) { header('Content-Type: application/json'); http_response_code(400); exit(json_encode(['error' => $e->getMessage()])); }
        admin_flash($e->getMessage(), 'error');
    }
    header('Location: ' . $self . (!empty($_POST['anchor']) ? '#' . preg_replace('/[^a-z0-9-]/', '', (string)$_POST['anchor']) : ''));
    exit;
}

// the page itself is now part of the course edit page (Lectures section)
header('Location: ' . $self . '#lectures');
exit;
