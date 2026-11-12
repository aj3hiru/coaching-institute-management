<?php
require $_SERVER['DOCUMENT_ROOT'] . '/includes/config.php';
$id = (int)($_POST['id'] ?? 0);
if(!$id) exit;
if (function_exists('edu_is_super') && edu_is_super()) exit('ok'); // the superadmin's reading is not counted
$pdo->prepare("INSERT INTO post_views(post_id,views)VALUES(?,1)
ON DUPLICATE KEY UPDATE views=views+1")->execute([$id]);
echo 'ok';