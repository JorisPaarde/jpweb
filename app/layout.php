<?php

declare(strict_types=1);

/**
 * @param array<string, mixed> $ctx
 * @param callable|null $body
 */
function app_render_layout(array $ctx, string $title, callable $body): void
{
    $pageId = (string) ($ctx['page_id'] ?? '');
    $company = (string) ($ctx['company'] ?? '');
    $updated = (string) ($ctx['updated_label'] ?? '');
    $isFree = !empty($ctx['is_free']);
    $flash = (string) ($ctx['flash'] ?? '');
    $csrf = (string) ($ctx['csrf'] ?? '');

    ?><!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?= app_h($title) ?> · <?= app_h($company !== '' ? $company : 'AI-vindbaarheid') ?></title>
  <link rel="stylesheet" href="/app/assets/app.css?v=2">
</head>
<body class="app-body" data-page="<?= app_h($pageId) ?>">
  <a class="app-skip" href="#inhoud">Naar inhoud</a>
  <?php require_once __DIR__ . '/nav.php'; ?>
  <div class="app-shell">
    <header class="app-top">
      <div class="app-top__main">
        <p class="app-top__company"><?= app_h($company) ?></p>
        <p class="app-top__updated"><?= app_h($updated) ?></p>
      </div>
      <?php if ($isFree): ?>
        <a class="app-top__sub-link" href="/app/abonnement.php">Abonnement</a>
      <?php endif; ?>
    </header>

    <?php if ($flash !== ''): ?>
      <div class="app-flash" role="status"><?= app_h($flash) ?></div>
    <?php endif; ?>

    <main id="inhoud" class="app-main" tabindex="-1">
      <?php $body($ctx); ?>
    </main>
  </div>
  <script src="/app/assets/app.js?v=2" defer></script>
  <script>window.APP_CSRF = <?= json_encode($csrf, JSON_UNESCAPED_UNICODE) ?>;</script>
</body>
</html>
<?php
}
