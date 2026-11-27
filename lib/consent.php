<?php

declare(strict_types=1);

/**
 * Cookiekeuze (Consent Mode v2) en Google Tag Manager voor de PHP-pagina's.
 *
 * Zelfde volgorde als de statische pagina's: eerst de standaardkeuze (alles geweigerd),
 * dan een opgeslagen keuze terugzetten, dan pas GTM. De banner en de knop
 * "Cookie-instellingen" komen uit /consent.js.
 *
 * Alleen voor pagina's zonder persoonsgegevens of tokens in de URL of titel. Ingelogde
 * pagina's krijgen data-no-conversion-events op <html> (zie jpwc_consent_html_attr), zodat
 * consent.js daar alleen consent_update pusht.
 *
 * Bewust NIET op: /app/* (bedrijfsnaam van de klant in de titel), /dashboard/, /admin/*,
 * /ai-scan.php?confirm=… en /ai-scan/wachtwoord/?t=… en /app/confirm-email.php?t=…
 * (token in de URL, die GTM als page_location zou meesturen).
 */

const JPWC_GTM_ID = 'GTM-WQ8RDBNK';
const JPWC_CONSENT_JS = '/consent.js?v=2';

/** Attribuut voor <html> op ingelogde of afgeschermde pagina's: geen conversie-events. */
function jpwc_consent_html_attr(bool $loggedIn): string
{
    return $loggedIn ? ' data-no-conversion-events' : '';
}

/** In de <head>, direct na de stylesheet. */
function jpwc_consent_head(): void
{
    echo <<<'HTML'
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('consent', 'default', {analytics_storage: 'denied', ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied', wait_for_update: 500});
  (function () {
    try {
      var c = JSON.parse(localStorage.getItem('jpwc_consent') || 'null');
      if (c && c.v === 1 && Date.now() - c.ts < 31536000000) {
        var ads = c.ads ? 'granted' : 'denied';
        gtag('consent', 'update', {analytics_storage: c.analytics ? 'granted' : 'denied', ad_storage: ads, ad_user_data: ads, ad_personalization: ads});
      }
    } catch (e) {}
  })();
</script>
<!-- Google Tag Manager -->
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','GTM-WQ8RDBNK');</script>
<!-- End Google Tag Manager -->
<script src="/consent.js?v=2" defer></script>

HTML;
}

/** Direct na <body>. */
function jpwc_consent_noscript(): void
{
    echo '<!-- Google Tag Manager (noscript) -->' . "\n"
        . '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=' . JPWC_GTM_ID
        . '" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>' . "\n"
        . '<!-- End Google Tag Manager (noscript) -->' . "\n";
}

/** In de footer-links, zelfde knop als de statische footer (nap.py). */
function jpwc_consent_footer_button(): string
{
    return '<button type="button" class="footer-cookie-link" data-cookie-settings>Cookie-instellingen</button>';
}
