<?php

declare(strict_types=1);

require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/layout.php';

$ctx = app_boot('meer');

app_render_layout($ctx, 'Meer', static function (array $ctx): void {
    ?>
    <h1>Meer</h1>
    <ul class="meer-list">
      <li><a href="/app/bedrijf.php">Bedrijf</a></li>
      <li><a href="/app/abonnement.php">Abonnement</a></li>
      <li><a href="/app/wijzigingen.php">Wijzigingen</a></li>
      <li><a href="/app/account.php">Account</a></li>
      <li><a href="/logout.php">Uitloggen</a></li>
    </ul>
    <?php
});
