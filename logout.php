<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

[$config] = ai_scan_boot();
ai_scan_logout_user($config);
ai_scan_redirect('/login/', 303);
