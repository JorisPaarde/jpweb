# QA-rapport JPWebcreation-site

Controle uitgevoerd op 12 juli 2026 voor branch `agent/site-verbeteringen` en pull request #1.

## Gecontroleerd en geslaagd

### HTML en inhoudsstructuur

- Alle 9 HTML-pagina's zijn zonder parserfout ingelezen:
  - homepage;
  - 404-pagina;
  - privacyverklaring;
  - 6 projectcases.
- Iedere pagina heeft precies één `h1` als eerste heading.
- Alle afbeeldingen hebben een `alt`-attribuut.
- Iedere projectcase heeft precies één zichtbaar resultaatblok.
- De homepage bevat zes FAQ-items met native `details`- en `summary`-elementen.

### Interne routes en bestanden

- Alle interne links verwijzen naar een bestaand bestand, een bestaande map of een bestaand anker.
- Alle gebruikte afbeeldingen, stylesheets en scripts bestaan lokaal.
- De homepage-CTA's verwijzen naar bestaande secties.
- De CTA's op alle projectpagina's verwijzen correct terug naar contact en projecten.
- De volgende lokale routes zijn via de statische preview met HTTP 200 gecontroleerd:
  - `/`
  - `/404.html`
  - `/privacyverklaring/`
  - alle 6 projectroutes;
  - `/sitemap.xml`;
  - `/robots.txt`.

### Externe projectlinks

De zes gekoppelde klantsites gaven tijdens de controle HTTP 200:

- `https://havekainstallatie.nl/`
- `https://wildfloweroffice.nl/`
- `https://www.mikespianoshow.com/`
- `https://eceens.nl/`
- `https://supperservices.com/`
- `https://archipelbouwadvies.nl/`

Links die in een nieuw tabblad openen gebruiken `rel="noopener"`.

### SEO-basis

- Iedere indexeerbare pagina heeft precies één canonical naar het productiedomein.
- De XML-sitemap bevat exact dezelfde 8 canonieke, indexeerbare pagina's.
- `robots.txt` verwijst naar de productiesitemap.
- De 404-pagina bevat `noindex`.
- De twee zekere oude projectpaden hebben een 301-regel in `.htaccess`.
- Alle 47 URL's uit de bestaande WordPress-sitemaps staan in `URL-MIGRATIE.md`.

### CSS, JavaScript en assets

- Openende en sluitende CSS-haakjes zijn in balans.
- Alle HTML-pagina's gebruiken dezelfde actuele CSS- en JavaScript-cacheversies.
- `script.js` is door de lokale Node-syntaxcontrole gekomen.
- `prefers-reduced-motion` schakelt de hero- en projectcarrouselanimaties uit.
- De portretfoto is een geldige WebP van 800 × 800 pixels en 51.204 bytes.
- `git diff --check` rapporteert geen whitespacefouten.

## Nog niet gecontroleerd

De onderstaande punten kunnen niet betrouwbaar in deze werkomgeving worden bevestigd en blijven open:

- echte visuele browsercontrole op 320, 375, 768, 1024 en 1440 pixels;
- bediening van het mobiele menu in een echte browser;
- focusvolgorde en contrastcontrole met browser- en toegankelijkheidstools;
- browserconsole en netwerkverzoeken tijdens interactie;
- PHP-syntax met de PHP-versie van de hosting;
- daadwerkelijke bezorging van het contactformulier naar `info@jpwebcreation.nl`;
- de hostafhankelijke `X-Robots-Tag` op `test.jpwebcreation.nl`;
- werking van de 301-regels en aangepaste 404 op LiteSpeed/Apache;
- productiecontrole na deployment;
- performance- en layoutmetingen via Lighthouse of gelijkwaardige browsertool.

## Vereiste controles na deployment

1. Open homepage, privacy, 404 en alle cases op test.
2. Controleer desktop en mobiel op de afgesproken breedtes.
3. Verstuur een echte testaanvraag en bevestig ontvangst in de inbox.
4. Controleer foutmeldingen, verplichte velden, honeypot en rate limit.
5. Controleer met `curl -I` dat test `X-Robots-Tag: noindex, nofollow` geeft.
6. Controleer dat productie geen `noindex`-header krijgt.
7. Controleer de twee oude project-URL's op één 301 naar de nieuwe bestemming.
8. Open een onbekend pad en controleer de aangepaste 404-pagina en statuscode.
9. Voer Lighthouse en een toegankelijkheidscontrole uit.

Dit rapport claimt geen geslaagde productie- of mailcontrole zolang die tests niet daadwerkelijk op de hosting zijn uitgevoerd.
