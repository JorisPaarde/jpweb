<?php

declare(strict_types=1);

// /app/ zonder bestand: door naar het overzicht, dat zelf naar de inlogpagina stuurt.
header('Cache-Control: no-store, max-age=0');
header('Location: /app/overzicht.php', true, 302);
exit;
