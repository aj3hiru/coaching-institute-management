<?php
// Push Notifications page API: posts, sending, test push, segments, subscribers, history, settings,
// key reset and export / import. Keys, settings and deleting are for admins only.
ini_set('display_errors', '0'); // JSON only; problems go to the error log
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/components/guard.php';
require_once INCLUDES_PATH . '/push.php';
admin_guard(['admin', 'editor', 'author']);
if (!admin_can('push_notifications.send')) { http_response_code(403); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['error' => 'You are not allowed to send notifications.']); exit; }

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$isAdmin = admin_shell_user()['role'] === 'admin';
$pushAdv = $isAdmin || admin_can('push_notifications.advanced'); // Users → Push notifications → Advanced (keys, import / export, settings)
function push_out(array $d, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function push_admin_only(bool $isAdmin): void
{
    if (!$isAdmin) push_out(['error' => 'Only administrators can do this.'], 403);
}

try {
    if ($action === 'posts') {
        $q = trim((string)($_GET['q'] ?? ''));
        $sql = "SELECT p.id, p.title, p.slug, p.date, m.file_path, m.responsive_set FROM posts p LEFT JOIN media m ON m.id = p.featured_image_id WHERE p.status = 'published'";
        $params = [];
        if ($q !== '') { $sql .= " AND p.title LIKE ?"; $params[] = '%' . $q . '%'; }
        $st = $pdo->prepare($sql . " ORDER BY p.date DESC LIMIT 30");
        $st->execute($params);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $set = json_decode($r['responsive_set'] ?? '', true) ?: [];
            $out[] = ['id' => (int)$r['id'], 'title' => $r['title'], 'url' => postUrl($r['slug'], $r['id']), 'date' => date('j M Y, g:i a', strtotime($r['date'])),
                'image' => $r['file_path'] ? '/' . ltrim($r['file_path'], '/') : '', 'thumb' => $r['file_path'] ? '/' . ltrim($set['sm'] ?? $r['file_path'], '/') : ''];
        }
        push_out(['posts' => $out]);
    }
    if ($action === 'values') {
        push_out(['values' => edu_push_values((string)($_GET['field'] ?? '')), 'counts' => edu_push_value_counts((string)($_GET['field'] ?? ''))]);
    }
    if ($action === 'fetch') {
        $r = edu_push_fetch_meta((string)($_GET['url'] ?? ''));
        push_out($r, empty($r['ok']) ? 400 : 200);
    }
    if ($action === 'log') {
        push_out(edu_push_delivery_log((int)($_GET['id'] ?? 0)));
    }
    if ($action === 'export') {
        push_admin_only($pushAdv);
        $data = edu_push_export();
        admin_log('push_export', 'Exported push keys and ' . count($data['subscribers']) . ' subscribers');
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="push-' . preg_replace('/[^a-z0-9.-]/i', '', (string)$data['site']) . '-' . date('Y-m-d') . '.json"');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') push_out(['error' => 'Method not allowed'], 405);
    csrf_check();

    switch ($action) {
        case 'send':
            $title = trim((string)($_POST['title'] ?? ''));
            $url = trim((string)($_POST['url'] ?? ''));
            if ($title === '' || $url === '') push_out(['error' => 'Title and link are required.'], 400);
            if (strlen($url) > 500) push_out(['error' => 'The link is too long (max 500 characters).'], 400);
            if (!edu_push_ready()) push_out(['error' => 'Push keys are not set up on the server.'], 500);
            $seg = (int)($_POST['segment_id'] ?? 0) ?: null;
            $cid = edu_push_queue($title, trim((string)($_POST['body'] ?? '')), $url, trim((string)($_POST['image'] ?? '')),
                ($_POST['post_id'] ?? '') !== '' ? (int)$_POST['post_id'] : null, $seg, trim((string)($_POST['icon'] ?? '')));
            $total = (int)$pdo->query("SELECT total_subscribers FROM push_campaigns WHERE id = " . $cid)->fetchColumn();
            admin_log('push_send', 'Sent push notification: ' . mb_strimwidth($title, 0, 60, '…') . " (#$cid, $total subscribers)");
            push_out(['ok' => true, 'campaign' => $cid, 'total' => $total]);
        case 'process':
            // helps the queue along (only one sender runs at a time), then reports on this notification
            $run = edu_push_process(null, 1000);
            push_out(edu_push_progress((int)($_POST['campaign'] ?? 0)) + ['busy' => !empty($run['busy']), 'error' => $run['error'] ?? null]);
        case 'test':
            $r = edu_push_send_test($_POST, (int)($_POST['sub_id'] ?? 0) ?: null, (string)($_POST['endpoint'] ?? ''));
            push_out($r, empty($r['ok']) ? 400 : 200);
        case 'estimate':
            push_out(['count' => edu_push_estimate(json_decode((string)($_POST['rules'] ?? '[]'), true) ?: [])]);
        case 'segment_save':
            $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 120);
            $rules = edu_push_clean_rules(json_decode((string)($_POST['rules'] ?? '[]'), true));
            if ($name === '') push_out(['error' => 'Please give the segment a name.'], 400);
            if (!$rules) push_out(['error' => 'Add at least one condition with a value.'], 400);
            $id = (int)($_POST['id'] ?? 0);
            if ($id) {
                $pdo->prepare("UPDATE push_segments SET name = ?, rules = ? WHERE id = ?")->execute([$name, json_encode($rules, JSON_UNESCAPED_UNICODE), $id]);
            } else {
                $pdo->prepare("INSERT INTO push_segments (name, rules) VALUES (?, ?)")->execute([$name, json_encode($rules, JSON_UNESCAPED_UNICODE)]);
                $id = (int)$pdo->lastInsertId();
            }
            admin_log('push_segment', "Saved push segment: $name (#$id)");
            push_out(['ok' => true, 'id' => $id, 'count' => edu_push_estimate($rules), 'text' => edu_push_rules_text($rules)]);
        case 'segment_delete':
            $pdo->prepare("DELETE FROM push_segments WHERE id = ?")->execute([(int)($_POST['id'] ?? 0)]);
            push_out(['ok' => true]);
        case 'delete_sub':
            push_admin_only($isAdmin);
            $pdo->prepare("DELETE FROM push_subscriptions WHERE id = ?")->execute([(int)($_POST['id'] ?? 0)]);
            push_out(['ok' => true]);
        case 'delete_campaign':
            push_admin_only($isAdmin);
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("DELETE FROM push_queue WHERE campaign_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM push_campaigns WHERE id = ?")->execute([$id]);
            admin_log('push_log_delete', "Deleted push notification #$id from the history");
            push_out(['ok' => true]);
        case 'clear_history':
            push_admin_only($isAdmin);
            // notifications still being delivered stay
            $ids = $pdo->query("SELECT id FROM push_campaigns WHERE NOT (status IN ('pending','processing') AND created_at >= NOW() - INTERVAL 1 DAY)")->fetchAll(PDO::FETCH_COLUMN);
            foreach (array_chunk($ids, 500) as $chunk) {
                $in = implode(',', array_map('intval', $chunk));
                $pdo->exec("DELETE FROM push_queue WHERE campaign_id IN ($in)");
                $pdo->exec("DELETE FROM push_campaigns WHERE id IN ($in)");
            }
            admin_log('push_history_clear', 'Cleared the push history (' . count($ids) . ' notifications)');
            push_out(['ok' => true, 'removed' => count($ids)]);
        case 'settings':
            push_admin_only($pushAdv);
            $icon = trim((string)($_POST['icon'] ?? ''));
            if ($icon !== '' && !preg_match('#^(/|https?://)\S+$#', $icon)) push_out(['error' => 'The icon must be an image address.'], 400);
            edu_settings_save('push', [
                'auto_send' => ($_POST['auto_send'] ?? '') === '1' ? '1' : '0',
                'collect' => ($_POST['collect'] ?? '1') === '0' ? '0' : '1',
                'icon' => mb_substr($icon, 0, 255),
            ]);
            if (isset($_POST['bell'])) edu_settings_save('theme', ['h_bell' => $_POST['bell'] === '1' ? '1' : '0']);
            admin_log('push_settings', 'Updated push notification settings');
            push_out(['ok' => true]);
        case 'reset_keys':
            push_admin_only($pushAdv);
            if (($_POST['confirm'] ?? '') !== 'RESET') push_out(['error' => 'Type RESET to confirm.'], 400);
            $n = (int)$pdo->query("SELECT COUNT(*) FROM push_subscriptions")->fetchColumn();
            $clear = ($_POST['clear'] ?? '') === '1';
            $pub = edu_push_reset_keys($clear);
            admin_log('push_keys_reset', $clear ? "Reset push keys; erased old keys and $n subscribers" : "Reset push key; kept the old keys and $n subscribers");
            push_out(['ok' => true, 'public_key' => $pub, 'removed' => $clear ? $n : 0, 'kept' => $clear ? 0 : $n]);
        case 'import':
            push_admin_only($pushAdv);
            $f = $_FILES['file'] ?? null;
            if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) push_out(['error' => 'Please choose an export file.'], 400);
            if ($f['size'] > 50 * 1024 * 1024) push_out(['error' => 'The file is too large (max 50 MB).'], 400);
            $data = json_decode((string)file_get_contents($f['tmp_name']), true);
            if (!is_array($data)) push_out(['error' => 'This file is not valid JSON.'], 400);
            $r = edu_push_import($data);
            admin_log('push_import', "Imported push: {$r['keys']} key sets, {$r['added']} new and {$r['updated']} updated subscribers, {$r['skipped']} skipped");
            push_out(['ok' => true] + $r);
    }
    push_out(['error' => 'Unknown action'], 400);
} catch (Throwable $e) {
    error_log('push api: ' . $e->getMessage());
    push_out(['error' => $e->getMessage()], 500);
}
