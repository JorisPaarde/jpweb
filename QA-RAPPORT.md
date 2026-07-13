# QA-rapport JPWebcreation-site

Oorspronkelijke brede controle uitgevoerd op 12 juli 2026. Dit rapport is op
13 juli 2026 bijgewerkt voor de huidige repositoryinhoud. Het beschrijft
lokale, broncode- en eerder uitgevoerde testcontroles. Het claimt geen actuele
productiedeploy, Search Console-actie of succesvolle e-mailbezorging.

## Gecontroleerd en geslaagd

### Pagina's en inhoud

- De repository bevat tien HTML-pagina's: homepage, AI-pagina, 404,
  privacyverklaring en zes projectcases.
- Iedere HTML-pagina bevat precies één `h1`.
- De homepage bevat acht FAQ-items met native `details` en `summary`.
- De homepage toont vier primaire editorial projectrijen en twee compacte
  secundaire projectlinks; het oude project-cardgrid is niet meer aanwezig.
- De AI-pagina bevat de drie afgesproken ingangen: AI-tools op maat,
  AI-workshops voor teams en begeleiding bij AI-assisted development.
- De AI-referenties noemen alleen de bevestigde ervaring: gemeente Leiden,
  gemeente Haarlem, een bouwbedrijf en begeleiding van AI-assisted
  codingtrajecten.
- Archipel Bouwadvies en Wildfloweroffice volgen dezelfde caseopbouw en bevatten
  ieder een niet-zichtbare `[VRAAG: ...]`-notitie waar een sterker, specifiek
  klantresultaat nog bevestiging nodig heeft.

### Afbeeldingen en alternatieve teksten

- Alle `img`-elementen hebben een `alt`-attribuut.
- De zes bewegende herhalingen in de herocarrousel hebben bewust een lege alt:
  dezelfde projecten worden verderop op de pagina al inhoudelijk beschreven.
- De vier zichtbare projectbeelden op de homepage en alle beelden op Mike's
  Pianoshow, Haveka Installatie en Supper Services hebben unieke Nederlandse
  altteksten van maximaal 100 tekens in het afgesproken patroon.
- De portretfoto heeft een korte persoonsbeschrijving als alttekst.
- De social preview is een geldige JPEG van 1200 x 630 pixels.

### Routes, sitemap en redirects

- `sitemap.xml` bevat negen canonieke, indexeerbare routes: homepage, `/ai/`,
  privacyverklaring en zes projectcases.
- Alle sitemaproutes hebben een bijbehorend lokaal bestand en iedere
  indexeerbare pagina heeft een zelfverwijzende productiecanonical.
- `robots.txt` verwijst naar de productiesitemap; de 404-pagina bevat
  `noindex`.
- De 49 legacyroutes in `.github/scripts/check-redirects.sh` hebben ieder een
  expliciete één-hopbestemming. De onderliggende Apache/LiteSpeed-regels staan
  in `.htaccess` en de volledige kaart staat in `URL-MIGRATIE.md`.
- De deployworkflow controleert de publieke routes, de aangepaste 404, de
  contact-state-endpoint, de omgevingsspecifieke robotsheader en alle 49
  redirects.

### Metadata en structured data

- Homepage en `/ai/` gebruiken een volledige Open Graph- en Twitter-set met
  `summary_large_image` en de gedeelde 1200 x 630-afbeelding.
- De homepage bevat `ProfessionalService`- en `FAQPage`-JSON-LD.
- De acht vragen en antwoorden in `FAQPage` zijn inhoudelijk gelijk aan de
  zichtbare FAQ. De prijsvermelding gebruikt `vanaf`: websites vanaf 3.500 euro
  en online tools/maatwerk vanaf 7.500 euro, plus vaste offerte en uurbasis.
- De JSON-LD gebruikt alleen de vastgelegde bedrijfsgegevens: adres, KvK,
  telefoon en e-mail.

### Technische broncontrole

- Alle tien HTML-pagina's gebruiken CSS-cacheversie `v=43` en
  JavaScript-cacheversie `v=8`.
- `styles.css` bevat bij de laatste telling 324 openende en 324 sluitende
  accolades.
- `git diff --check` rapporteert geen whitespacefouten.
- Probleemitems, processtappen, projectrijen, reviews, diensten en de nieuwe
  AI-aanbodblokken gebruiken hetzelfde revealpatroon waar dat inhoudelijk past.
- `prefers-reduced-motion` schakelt reveal- en carrouselanimaties uit.
- De formulierhandler behoudt geldige invoer na een fout eenmalig in een
  kortlevende serversessie. Honeypot en privacytoestemming worden niet
  teruggezet en veldfouten zijn met `aria-describedby` gekoppeld.

## Open controles vóór productie

Deze punten zijn niet door broninspectie alleen te bewijzen:

1. Controleer homepage, `/ai/`, alle cases, privacy en 404 in een echte browser
   op 320, 375, 768, 1024 en 1440 pixels.
2. Controleer specifiek de lange teksten in `Voorbeelden uit de praktijk`, de
   hero, de AI-aanbodblokken, de casekoppen en de social-previewcompositie.
3. Bedien navigatie, mobiele menu, FAQ, links en formulier uitsluitend met
   toetsenbord; controleer daarna met een schermlezer de labels, foutmeldingen,
   focusvolgorde en landmarks.
4. Controleer console en netwerkpaneel op JavaScriptfouten, mislukte assets en
   layout shifts.
5. Verstuur op de doelomgeving eerst een ongeldige en daarna één geldige
   formulierinzending; bevestig de succesweergave en daadwerkelijke ontvangst
   op `info@jpwebcreation.nl`.
6. Voer Lighthouse of een gelijkwaardige mobiele performance- en
   toegankelijkheidscontrole uit en leg alleen regressies met hoge impact als
   nieuwe taak vast.
7. Voer na de handmatige productiedeploy de productierooktest uit: alle huidige
   routes `200`, onbekend pad `404`, productie zonder `X-Robots-Tag: noindex`,
   en iedere oude URL exact één `301` naar een eindbestemming met `200`.
8. Valideer de live homepage in Google Rich Results Test. Accepteer pas wanneer
   `FAQPage` en `ProfessionalService` nul fouten tonen en de FAQ-tekst nog exact
   overeenkomt met de zichtbare tekst.

## Search Console: geblokkeerd buiten de repository

De sitemap is in de repository bijgewerkt, maar kon vanuit deze omgeving niet
worden ingediend omdat geen geautoriseerde Google Search Console-koppeling
beschikbaar was. Daardoor zijn ook de volgende acties nog niet uitgevoerd:

- sitemap `https://jpwebcreation.nl/sitemap.xml` indienen en status `Succesvol`
  controleren;
- herindexering aanvragen voor homepage, `/ai/` en de zes projectpagina's;
- verouderde resultaten voor oude `/stad/`, `/blog/`, `/general/`,
  `/uncategorized/` en de oude contact- en projectslugs laten verwijderen;
- in het rapport Pagina-indexering bevestigen dat nul ingediende sitemap-URL's
  de status 404 hebben.

Voer deze acties pas na de handmatige productiedeploy uit. Noteer uitvoerdatum,
gebruikte property en Search Console-status in dit rapport; schrijf geen
successtatus op basis van alleen de repositoryconfiguratie.
