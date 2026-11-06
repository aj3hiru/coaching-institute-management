<?php
// Country Redirection is now part of Admin → Redirections (rules can target countries there).
header("Location: /admin/redirections.php", true, 301);
exit;
