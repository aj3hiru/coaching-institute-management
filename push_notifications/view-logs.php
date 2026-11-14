<?php
// Delivery logs now live inside the Push page (History → click a notification); old links land there.
require_once $_SERVER['DOCUMENT_ROOT'] . '/admin/components/guard.php';
admin_guard(['admin', 'editor', 'author', 'teacher', 'accountant']);
header('Location: /push_notifications/push-manager.php?tab=history', true, 301);
exit;
