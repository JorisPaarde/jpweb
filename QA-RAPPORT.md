# QA-rapport JPWebcreation-site

Oorspronkelijke brede controle uitgevoerd op 12 juli 2026. Rapport bijgewerkt op
13 juli 2026 voor `main` en de lokale toegankelijkheids-, formulier- en
deploymentverbeteringen. De statische site staat op dat moment nog niet op
productie; `https://jpwebcreation.nl/` toont nog de bestaande WordPress-site.

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
- De vernieuwde homepage bevat vier primaire editorial projectrijen en twee
  compacte secundaire projectlinks.
- De oude homepage-selectors `.work-feature`, `.work-grid` en
  `.work-card` komen niet meer voor in `index.html` of `styles.css`.

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
- Alle 9 HTML-pagina's gebruiken CSS-cacheversie `v=41` en
  JavaScript-cacheversie `v=8`.
- `script.js` is door de lokale Node-syntaxcontrole gekomen.
- `prefers-reduced-motion` schakelt de hero- en projectcarrouselanimaties uit.
- Een sitebrede `:focus-visible`-basis voor links, knoppen, `summary` en
  formuliervelden is toegevoegd; donkere secties krijgen een contrasterende
  amberkleurige variant.
- Ongeldige formulierinvoer wordt maximaal tien minuten in een beveiligde
  server-side sessie bewaard, eenmaal uitgelezen en daarna direct gewist. De
  honeypot en privacytoestemming worden niet teruggezet en veldfouten zijn via
  `aria-describedby` gekoppeld.
- De deployworkflow bevat na test en productie een HTTP-rooktest voor alle
  publieksroutes, de aangepaste 404, de contact-state-endpoint en de juiste
  robotsheader per omgeving. De YAML is lokaal succesvol geparseerd.
- De portretfoto is een geldige WebP van 800 × 800 pixels en 51.204 bytes.
- `git diff --check` rapporteert geen whitespacefouten.
- Openende en sluitende CSS-haakjes zijn opnieuw geteld: 270 paren.
- Een lokale HTML- en linkaudit controleerde opnieuw 9 pagina's, precies één
  `h1` per pagina, alt-attributen, bestaande interne doelen, CSS `v=41` en
  JavaScript `v=8` en
  de verwachte verdeling van 4 primaire plus 2 secundaire projecten.
- De projectkop valt onder 900 pixels aantoonbaar terug naar één kolom met een
  selector die dezelfde specificiteit heeft als de desktopregel. Op maximaal
  560 pixels gebruikt de kop een kleinere lettergrootte en het lange woord
  `bedrijfsproblemen` heeft een gecontroleerd afbreekpunt.
- Probleemitems, processtappen, primaire projectrijen, reviews en diensten
  gebruiken hetzelfde revealpatroon. Bij `prefers-reduced-motion: reduce`
  worden deze items direct zichtbaar zonder transitie.
- Via een lokale statische server gaven homepage, 404, privacy, alle zes
  projectroutes, sitemap en robots opnieuw HTTP 200.
- GitHub Actions-run `29277423697` voor commit `bd70138` is geslaagd. De nieuwe
  stap controleerde op test alle tien kritieke routes, de staging-noindexheader,
  de aangepaste 404 en de PHP-endpoint.
- Een aanvullende ongeldige POST op test gaf de verwachte 303-redirect. Naam,
  bedrijf, e-mail, telefoon en bericht kwamen eenmaal terug; vier veldfouten
  waren aanwezig; privacy en honeypot werden niet bewaard; een tweede uitlezing
  was leeg.

## Nog niet gecontroleerd

De onderstaande punten kunnen niet betrouwbaar in deze werkomgeving worden bevestigd en blijven open:

- echte visuele browsercontrole op 320, 375, 768, 1024 en 1440 pixels;
- visuele controle van de nieuwe projectrijen: afwisselende desktopvolgorde,
  screenshotuitsnede, tekstbreedte, tussenruimte, mobiele beeld-tekstvolgorde en
  de compacte `Ook gebouwd`-links;
- bediening van het mobiele menu in een echte browser;
- zichtbaarheid, focusvolgorde en contrast van de nieuwe focusstijl met
  browser- en toegankelijkheidstools;
- browserconsole en netwerkverzoeken tijdens interactie;
- daadwerkelijke bezorging van het contactformulier naar `info@jpwebcreation.nl`;
- werking van de 301-regels en aangepaste 404 op LiteSpeed/Apache;
- productiecontrole na deployment;
- performance- en layoutmetingen via Lighthouse of gelijkwaardige browsertool.

## Vereiste controles na deployment

1. Controleer desktop en mobiel op de afgesproken breedtes, met extra aandacht
   voor de editorial projectrijen en de twee secundaire projectlinks.
2. Doorloop de site met alleen Tab, Enter en spatie.
3. Controleer in de browser bij een ongeldige testaanvraag de zichtbare
   veldfouten, focus en niet-aangevinkte toestemming; de serverwaarden en het
   eenmalig wissen zijn al geverifieerd.
4. Verstuur daarna een geldige testaanvraag en bevestig ontvangst in de inbox.
5. Controleer honeypot en rate limit zonder echte spam of herhaalde mail te
   veroorzaken.
6. Controleer de twee oude project-URL's op één 301 naar de nieuwe bestemming.
7. Voer Lighthouse en een toegankelijkheidscontrole uit.

Dit rapport claimt geen geslaagde productie- of mailcontrole zolang die tests niet daadwerkelijk op de hosting zijn uitgevoerd.
