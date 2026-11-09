<?php
// Performance settings were removed (2026-11-09): cache times and script loading use the built-in defaults in includes/perf.php.
require_once __DIR__ . '/components/guard.php';
admin_guard(['admin']);
header('Location: /admin/cache-manager.php', true, 302);
exit;
