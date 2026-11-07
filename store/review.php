<?php
// A student's review of a course / notes pack they have (POST a=save&p=ID&rating=1..5&body=… | a=delete&p=ID).
// Only students who bought it or were given it (access not taken back) can review; one review each, editable.
// Reviews wait for approval (status 'pending') before they are published.
require_once dirname(__DIR__) . '/includes/config.php';
require_once INCLUDES_PATH . '/shop.php';
$pdo = sh_db();
$pid = (int)($_POST['p'] ?? 0);
$p = $pid ? sh_product($pdo, $pid, false) : null;
$back = $p ? sh_url($p) . '#reviews' : '/store/';
$u = sh_user();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !$p) { header('Location: ' . $back); exit; }
if (!$u) { header('Location: /account/login?next=' . rawurlencode(sh_url($p) . '#reviews')); exit; }
$flash = function (string $m, string $t = 'ok') use ($back) { $_SESSION['sh_flash'] = [$t, $m]; header('Location: ' . $back); exit; };
if (!sh_csrf_ok()) $flash('This page had expired. Please try again.', 'err');
$q = $pdo->prepare("SELECT 1 FROM sh_access WHERE user_id = ? AND product_id = ? AND revoked = 0");
$q->execute([$u['id'], $pid]);
if (!$q->fetchColumn()) $flash('Only students who have this ' . ($p['type'] === 'course' ? 'course' : 'notes pack') . ' can review it.', 'err');
$a = (string)($_POST['a'] ?? '');
if ($a === 'delete') {
    $pdo->prepare("DELETE FROM sh_reviews WHERE product_id = ? AND user_id = ?")->execute([$pid, $u['id']]);
    $flash('Your review was removed.');
}
$rating = (int)($_POST['rating'] ?? 0);
$body = trim(preg_replace("/\r\n?/", "\n", (string)($_POST['body'] ?? '')));
if ($rating < 1 || $rating > 5) $flash('Please choose 1 to 5 stars.', 'err');
if (mb_strlen($body) > 2000) $flash('Please keep the review under 2000 characters.', 'err');
// a new or changed review waits for the admin's approval (Online Store → Reviews) before it shows on the course page
$pdo->prepare("INSERT INTO sh_reviews (product_id, user_id, rating, body, status) VALUES (?, ?, ?, ?, 'pending')
    ON DUPLICATE KEY UPDATE rating = VALUES(rating), body = VALUES(body), status = 'pending', updated_at = NOW()")->execute([$pid, $u['id'], $rating, $body !== '' ? $body : null]);
$flash('Thank you! Your review is saved and will show here after the institute approves it.');
