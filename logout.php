<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

[$config] = ai_scan_boot();

// Alleen een formulier met CSRF-token logt uit; een link of afbeelding van een andere site niet.
if (ai_scan_request_method() === 'POST' && ai_scan_verify_csrf($config, (string) ($_POST['csrf'] ?? ''))) {
    ai_scan_logout_user($config);
    ai_scan_redirect('/login/', 303);
}

ai_scan_redirect('/app/meer.php', 303);
