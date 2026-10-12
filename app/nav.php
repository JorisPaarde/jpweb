<?php

declare(strict_types=1);

/** @var array<string, mixed> $ctx */
$pageId = (string) ($ctx['page_id'] ?? '');
$meerPages = ['meer', 'bedrijf', 'abonnement', 'wijzigingen', 'account'];
$meerActive = in_array($pageId, $meerPages, true);

$primary = [
    ['id' => 'overzicht', 'href' => '/app/overzicht.php', 'label' => 'Overzicht', 'icon' => 'home'],
    ['id' => 'acties', 'href' => '/app/acties.php', 'label' => 'Acties', 'icon' => 'check'],
    ['id' => 'vragen', 'href' => '/app/vragen.php', 'label' => 'Vragen', 'icon' => 'chat'],
    ['id' => 'concurrenten', 'href' => '/app/concurrenten.php', 'label' => 'Concurrenten', 'icon' => 'flag'],
    ['id' => 'meer', 'href' => '/app/meer.php', 'label' => 'Meer', 'icon' => 'menu'],
];

$secondary = [
    ['id' => 'bedrijf', 'href' => '/app/bedrijf.php', 'label' => 'Bedrijf'],
    ['id' => 'abonnement', 'href' => '/app/abonnement.php', 'label' => 'Abonnement'],
    ['id' => 'wijzigingen', 'href' => '/app/wijzigingen.php', 'label' => 'Wijzigingen'],
    ['id' => 'account', 'href' => '/app/account.php', 'label' => 'Account'],
];

if (!function_exists('app_nav_icon')) {
    function app_nav_icon(string $name): string
    {
        return match ($name) {
            'home' => '<svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 3.2 3.5 10.2v10.1h6.2v-5.6h4.6v5.6h6.2V10.2L12 3.2z"/></svg>',
            'check' => '<svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M9.2 16.6 4.8 12.2l1.4-1.4 3 3 8-8 1.4 1.4-9.4 9.4z"/><path fill="none" stroke="currentColor" stroke-width="1.6" d="M5 5.5h14v14H5z"/></svg>',
            'chat' => '<svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M4 4h16a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H9l-5 4V5a1 1 0 0 1 1-1zm2 3v2h12V7H6zm0 4v2h8v-2H6z"/></svg>',
            'flag' => '<svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M6 3h1.5v18H6V3zm2.5 1 4.2 2.4L17 4v8l-4.3 2.4L8.5 12V4z"/></svg>',
            'menu' => '<svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M4 6.5h16v2H4v-2zm0 4.5h16v2H4v-2zm0 4.5h16v2H4v-2z"/></svg>',
            default => '',
        };
    }
}
?>
<nav class="app-nav" aria-label="Hoofdmenu">
  <div class="app-nav__brand">
    <span class="app-nav__brand-mark">AI</span>
    <span class="app-nav__brand-text">Vindbaarheid</span>
  </div>
  <ul class="app-nav__primary">
    <?php foreach ($primary as $item):
        $active = $item['id'] === $pageId || ($item['id'] === 'meer' && $meerActive);
        ?>
      <li>
        <a
          href="<?= app_h($item['href']) ?>"
          class="app-nav__link<?= $active ? ' is-active' : '' ?>"
          <?= $active ? 'aria-current="page"' : '' ?>
        >
          <span class="app-nav__icon"><?= app_nav_icon($item['icon']) ?></span>
          <span class="app-nav__label"><?= app_h($item['label']) ?></span>
        </a>
      </li>
    <?php endforeach; ?>
  </ul>
  <div class="app-nav__desk-extra">
    <hr class="app-nav__rule" />
    <ul class="app-nav__secondary">
      <?php foreach ($secondary as $item):
          $active = $item['id'] === $pageId;
          ?>
        <li>
          <a
            href="<?= app_h($item['href']) ?>"
            class="app-nav__link app-nav__link--text<?= $active ? ' is-active' : '' ?>"
            <?= $active ? 'aria-current="page"' : '' ?>
          ><?= app_h($item['label']) ?></a>
        </li>
      <?php endforeach; ?>
    </ul>
    <a class="app-nav__logout" href="/logout.php">Uitloggen</a>
  </div>
</nav>
