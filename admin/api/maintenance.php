<?php
// Admin-area maintenance mode on / off — the superadmin only (from the admin bar). POST on=1|0, _csrf.
require_once dirname(__DIR__) . '/components/guard.php';
$me = admin_guard(['admin']);
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
if (!edu_is_super((int)$me['id'])) { http_response_code(403); exit('{"error":"Only the superadmin can do this."}'); }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit('{"error":"POST only"}'); }
csrf_check();
$on = ($_POST['on'] ?? '') === '1';
edu_settings_save('general', ['maint_admin' => $on ? '1' : '0']); // not logged (superadmin)
echo json_encode(['ok' => true, 'on' => $on]);
