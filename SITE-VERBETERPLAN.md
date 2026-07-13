# Open verbeterplan JPWebcreation-site

Bijgewerkt op 13 juli 2026. Afgeronde bouwtaken zijn uit de oude backlog
verwijderd om te voorkomen dat een volgende agent bestaande onderdelen opnieuw
ontwerpt. Dit document bevat alleen controles en verbeteringen die nog niet
aantoonbaar zijn afgerond.

## Vaststaande keuzes — niet opnieuw uitvinden

- De site is statische HTML/CSS/JavaScript met een PHP-contacthandler en draait
  op Apache/LiteSpeed.
- De homepage gebruikt vier brede editorial projectrijen en twee compacte links;
  zet dit niet terug naar een kaartgrid.
- De drie dienstblokken op de homepage zijn website, praktische AI en technisch
  beheer. Online tools blijven daarnaast onderdeel van de bredere positionering.
- Contact blijft een direct gesprek met Joris; bouw geen intake-tool zonder een
  nieuwe expliciete opdracht.
- De bestaande werking van de herocarrousel is een vaststaande ontwerpkeuze.
- Er wordt voorlopig geen bezoekersanalytics of cookiebanner toegevoegd.
- Verzin geen klantresultaten, percentages, quotes, prijzen, doorlooptijden of
  garanties. Gebruik `[VRAAG: ...]` wanneer noodzakelijke informatie ontbreekt.
- Productie wordt uitsluitend handmatig gestart via de GitHub Actions-workflow.
  Een testdeploy of repositorywijziging is geen bewijs van productielivegang.

## Reeds geïmplementeerd — alleen regressies herstellen

- homepagepositionering, klantgerichte bewijsstrook en directe CTA's;
- persoonlijk Joris-blok, drie Google-reviewpassages en social proof in de
  Mike's Pianoshow-case;
- probleem, aanpak, oplossing, resultaat en contact-CTA op alle zes cases;
- responsive tekstcorrectie in `Voorbeelden uit de praktijk` en uniforme
  revealanimaties waar passend;
- focusbasis, `prefers-reduced-motion` en veilige formulierwaarderetentie;
- acht FAQ-items, vanafprijzen, `FAQPage` en `ProfessionalService` JSON-LD;
- `/ai/`, homepage-AI-blok, navigatie-item en sitemapvermelding;
- Open Graph/Twitter metadata en 1200 x 630 social preview op homepage en `/ai/`;
- 49 één-hop legacyredirects plus een automatische redirect-rooktest;
- tien ongepubliceerde contentoutlines in `CONTENT-HERSTART.md`.

## P0 — R01 Browser- en responsive acceptatietest

**Bestanden/secties:** alle HTML-pagina's, met nadruk op `index.html` hero,
`.pain-points`, `#aanpak`, `#werk`, `#diensten`, FAQ en contact; `ai/index.html`;
alle `projecten/*/index.html`; bij een noodzakelijke correctie `styles.css` of
`script.js`.

**Doel:** aantonen dat de huidige inhoud en animaties op kleine schermen,
desktop en met toetsenbord bruikbaar blijven.

**Concrete uitvoering:**

1. Start de lokale of gedeployde testsite en controleer exact 320, 375, 768,
   1024 en 1440 pixels breed.
2. Leg per breedte screenshots vast van homepage, AI-pagina en minimaal de
   langste case. Controleer daarnaast alle overige pagina's op horizontale
   overflow.
3. Controleer in `Voorbeelden uit de praktijk` tekstbreedte, woordafbreking,
   beeld-tekstvolgorde en screenshotuitsnede. Controleer in `Aanpak` dat dezelfde
   revealrichting en timing worden gebruikt als bij vergelijkbare herhaalde
   content, zonder koppen of bediening onnodig te verbergen.
4. Doorloop header, mobiel menu, CTA's, cases, FAQ en formulier met Tab,
   Shift+Tab, Enter en spatie. Test daarna `prefers-reduced-motion: reduce`.
5. Pas alleen het kleinst mogelijke CSS- of JS-detail aan wanneer een concreet
   probleem reproduceerbaar is. Verander geen copy, sectievolgorde,
   carrouselgedrag of visuele richting op basis van smaak alleen.
6. Verhoog na een CSS- of JS-wijziging de betreffende cacheversie in alle tien
   HTML-bestanden volgens `AGENTS.md`.

**Beperkingen:** geen wijziging aan de werking van de herocarrousel; geen nieuw
componentmodel; geen wijzigingen aan klantclaims.

**Acceptatiecriteria:** nul horizontale overflow; `Voorbeelden uit de praktijk`
blijft leesbaar op 320 pixels; alle primaire acties zijn zichtbaar en
toetsenbordbedienbaar; focus wordt nergens afgesneden; reduced motion toont alle
inhoud direct; console bevat geen fouten.

## P0 — R02 Formulier en toegankelijkheid op de server controleren

**Bestanden/secties:** `index.html` sectie `#contact`, `contact.php`,
`script.js`; wijzig alleen na een reproduceerbare fout.

**Doel:** bewijzen dat de technisch geïmplementeerde formulierstroom ook in een
echte browser en via de doelserver werkt.

**Concrete uitvoering:**

1. Verstuur op test één ongeldige inzending met fouten in ieder verplicht veld.
   Controleer algemene melding, focus op de eerste fout, veldgebonden meldingen,
   behouden naam/bedrijf/e-mail/telefoon/bericht en lege privacy/honeypot.
2. Lees de foutstroom met een schermlezer en controleer dat ieder foutbericht
   via `aria-describedby` bij het juiste veld wordt aangekondigd.
3. Verstuur daarna één geldige, duidelijk herkenbare testinzending. Controleer
   de successtatus, leeg formulier en ontvangst op
   `info@jpwebcreation.nl`.
4. Controleer dat formulierwaarden niet in URL, console, analytics,
   `localStorage` of andere clientopslag terechtkomen.

**Beperkingen:** veroorzaak geen reeks echte mails; verlaag rate limiting of
servervalidatie niet om een test te laten slagen; plaats geen secrets in de
repository.

**Acceptatiecriteria:** ongeldige invoer is per veld begrijpelijk en blijft
eenmalig behouden; toestemming blijft uit; een geldige inzending wordt eenmaal
ontvangen; de bezoeker ziet een duidelijke bevestiging; geen persoonsgegevens
lekken naar clientopslag of logs.

## P0 — R03 Handmatige productiedeploy en technische livecontrole

**Bestanden/secties:** `.github/workflows/deploy.yml`, `.htaccess`,
`.github/scripts/check-redirects.sh`, `sitemap.xml`, `robots.txt`; normaal geen
codewijziging nodig.

**Doel:** de goedgekeurde commit handmatig publiceren en aantonen dat routes,
redirects en indexatiesignalen op productie kloppen.

**Concrete uitvoering:**

1. Noteer de exact goedgekeurde `main`-commit na een groene testworkflow.
2. Start handmatig `Deploy site` met `workflow_dispatch` en het
   `production`-environment. Productie mag niet automatisch aan een push worden
   gekoppeld.
3. Controleer de workflow-uitvoer: negen sitemaproutes plus robots geven `200`,
   een onbekend pad geeft `404`, en productie geeft nergens een
   `X-Robots-Tag` met `noindex`.
4. Laat `.github/scripts/check-redirects.sh https://jpwebcreation.nl` alle 49
   oude routes controleren. Onderzoek iedere mislukking; versoepel de test niet
   zonder te bewijzen dat de redirectkaart fout is.
5. Open homepage, AI-pagina en alle cases handmatig en controleer canonical,
   social image, interne links en contactroutes.
6. Valideer de live homepage in Google Rich Results Test. Vergelijk bij iedere
   FAQ-wijziging zichtbare tekst en JSON-LD letter voor letter.

**Beperkingen:** claim geen productiestatus vóór een geslaagde handmatige run;
maak geen redirectketen; verwijder staging-`noindex` niet.

**Acceptatiecriteria:** productieworkflow groen; alle actuele routes `200`;
onbekend pad `404`; 49 legacy-URL's elk exact één `301` en daarna `200`;
productie niet `noindex`; Rich Results Test toont nul fouten.

## P0 — R04 Google Search Console opschonen

**Externe omgeving:** geautoriseerde property voor `jpwebcreation.nl` in Google
Search Console. **Status:** geblokkeerd in de huidige werkomgeving door het
ontbreken van een Search Console-koppeling.

**Doel:** Google alleen de actuele canonieke routes laten verwerken en oude
WordPressresultaten sneller uit de zoekresultaten laten verdwijnen.

**Concrete uitvoering na R03:**

1. Open de correcte domein- of URL-prefixproperty en leg vast welke property is
   gebruikt.
2. Dien exact `https://jpwebcreation.nl/sitemap.xml` in. Wacht op status
   `Succesvol`; noteer datum en gemeld aantal ontdekte URL's.
3. Vraag URL-inspectie/indexering aan voor `/`, `/ai/` en alle zes routes onder
   `/projecten/` uit `sitemap.xml`.
4. Dien tijdelijke verwijderingsverzoeken in voor oude resultaatgroepen
   `/stad/`, `/blog/`, `/general/` en `/uncategorized/`, plus de oude contact- en
   twee oude projectslugs uit `URL-MIGRATIE.md`. Gebruik alleen URL of prefix
   wanneer de Search Console-interface dat expliciet ondersteunt.
5. Controleer Pagina-indexering nadat Google de sitemap heeft verwerkt. Filter
   op ingediende sitemap en onderzoek iedere 404 afzonderlijk.

**Beperkingen:** verwijder geen actuele URL; gebruik geen generieke homepage als
vervangende canonical in Search Console; rapporteer `ingediend` niet als
`verwerkt` of `geaccepteerd`.

**Acceptatiecriteria:** sitemapstatus `Succesvol`; nul ingediende sitemap-URL's
met 404; acht herindexeringsverzoeken vastgelegd; verwijderingsverzoeken voor
alle gedocumenteerde dode URL-groepen zichtbaar met datum en status.

## P1 — R05 Nog onbevestigde caseresultaten vervangen

**Bestanden/secties:**
`projecten/archipel-bouwadvies/index.html` en
`projecten/wildfloweroffice/index.html`, direct onder het resultaatblok bij de
HTML-comments met `[VRAAG: ...]`.

**Doel:** de nu correcte maar algemene resultaattekst sterker maken met echte,
door de klant of Joris bevestigde informatie.

**Concrete uitvoering:**

1. Stel exact de vraag uit iedere `[VRAAG: ...]`-comment aan Joris of de klant.
2. Leg bron, datum en exacte formulering buiten de publieke HTML vast.
3. Vervang maximaal één algemene resultaatszin per case door een concrete,
   begrijpelijke observatie. Gebruik een cijfer alleen als periode,
   meetmethode en goedkeuring bekend zijn.
4. Verwijder de betreffende comment pas nadat de nieuwe zin inhoudelijk is
   goedgekeurd. Blijft bevestiging uit, publiceer dan niets nieuws en laat de
   bestaande tekst staan.

**Beperkingen:** haal geen persoonlijke informatie of profielfoto's van Google,
LinkedIn of Facebook zonder aantoonbare toestemming; parafraseer een review niet
alsof het een gemeten projectresultaat is.

**Acceptatiecriteria:** iedere nieuwe claim heeft een herleidbare bron en
goedkeuring; geen `[VRAAG]` wordt stilzwijgend ingevuld; probleem, aanpak,
oplossing en resultaat blijven duidelijk van elkaar onderscheiden.

## P1 — R06 Altteksten op de drie overige cases uniform maken

**Bestanden/secties:** alle `img`-elementen in
`projecten/ec-eens/index.html`, `projecten/archipel-bouwadvies/index.html` en
`projecten/wildfloweroffice/index.html`.

**Doel:** de overige case-afbeeldingen op dezelfde specifieke standaard brengen
als Mike's Pianoshow, Haveka Installatie en Supper Services.

**Concrete uitvoering:** bekijk ieder bronbeeld en schrijf een unieke
Nederlandse alt van maximaal 100 tekens in het patroon `Screenshot van
[oplossing] voor [klant], [wat zichtbaar is]`. Beschrijf alleen het belangrijkste
zichtbare scherm of onderdeel. Gebruik geen woorden als `mooie`, `moderne` of
`professionele` en herhaal geen omringende alinea.

**Beperkingen:** laat alleen decoratieve, inhoudelijk volledig gedupliceerde
beelden leeg; wijzig `src`, afmetingen, volgorde of uitsnede niet.

**Acceptatiecriteria:** negen unieke alts, elk maximaal 100 tekens en volgens
het patroon; nul betekenisvolle afbeeldingen met lege alt; klant- en
productnamen correct gespeld.

## P1 — R07 Afbeeldingsladen meten en optimaliseren

**Bestanden/secties:** hero en projectbeelden in `index.html`, alle
`projecten/*/index.html`, bronbestanden onder `assets/projects/`.

**Doel:** minder afbeeldingsbytes laden zonder lege carrouselpanelen, gewijzigde
uitsneden of extra layout shift.

**Concrete uitvoering:**

1. Noteer voor ieder gebruikt beeld pixelafmetingen, bestandsgrootte en positie
   boven of onder de vouw.
2. Meet met het browsernetwerkpaneel welke hero-afbeelding LCP is. Geef alleen
   die afbeelding `fetchpriority="high"`; voeg dit niet op basis van DOM-volgorde
   alleen toe.
3. Test of latere carrouselbeelden ruim vóór hun eerste weergave geladen zijn.
   Gebruik `loading="lazy"` daar alleen als drie volledige omwentelingen op een
   trage mobiele verbinding geen leeg paneel tonen.
4. Genereer alleen bij aantoonbare besparing een kleinere WebP-variant en voeg
   `srcset` en een bij de werkelijke layout passende `sizes` toe.
5. Vergelijk vóór en na met dezelfde mobiele Lighthouse-instellingen en leg
   bytes, LCP en CLS vast.

**Beperkingen:** verander de carouselinterval, compositie of beeldvolgorde niet;
accepteer geen lagere leesbaarheid van screenshots om een bestand kleiner te
maken.

**Acceptatiecriteria:** maximaal één afbeelding met hoge laadprioriteit; geen
leeg carrouselpaneel; vaste breedte/hoogte en aspectratio blijven behouden; CLS
verslechtert niet; totale afbeeldingsbytes of LCP verbetert meetbaar.

## P1 — R08 Contentherstart uitvoeren als afzonderlijke publicatiefase

**Bestand/bron:** `CONTENT-HERSTART.md`; toekomstige pagina's onder een nog door
Joris te kiezen kennisroute. De outlines zijn plannen en staan niet in de
sitemap.

**Doel:** nuttige kenniscontent publiceren die aansluit op offerteprocessen,
WooCommerce-maatwerk, AI-workshops en AI-assisted development.

**Concrete uitvoering:**

1. Laat Joris eerst één outline en de definitieve URL-structuur kiezen.
2. Schrijf één artikel per wijziging met exact de doelgroep, H2-structuur, CTA,
   bronregels en claimgrenzen uit de gekozen outline.
3. Controleer actuele technische, privacy- of AI-regelclaims uitsluitend bij
   primaire bronnen en noteer publicatie- en raadpleegdatum in de conceptreview.
4. Voeg pas na inhoudelijke goedkeuring canonical, metadata, interne links en de
   nieuwe URL aan `sitemap.xml` toe.
5. Herzie dan pas relevante `/blog/`- of `/general/`-redirects wanneer het nieuwe
   artikel aantoonbaar dezelfde zoekintentie bedient; houd iedere redirect op
   maximaal één hop en werk `URL-MIGRATIE.md` plus de rooktest tegelijk bij.

**Beperkingen:** geen generieke artikelen over waarom een website belangrijk is;
geen zoekvolume-, ranking- of resultaatclaims zonder bron; geen tien artikelen
in één oncontroleerbare wijziging.

**Acceptatiecriteria per artikel:** titel korter dan 60 tekens; unieke primaire
zoekterm; alle opgegeven H2's aanwezig; CTA sluit aan op de dienst; claims zijn
herleidbaar; pagina is mobiel en toegankelijk gecontroleerd; sitemap, interne
link en eventuele redirect zijn zonder keten bijgewerkt.

## P2 — R09 Cookieloze beoordeling van leadkwaliteit vastleggen

**Bestand:** maak na expliciete keuze van Joris een kort procesdocument; wijzig
geen sitecode.

**Doel:** na livegang beoordelen of de nieuwe positionering betere aanvragen
oplevert zonder bezoekersanalytics.

**Concrete uitvoering:** definieer vóór productie welke handmatig beschikbare
gegevens per aanvraag worden genoteerd, bijvoorbeeld datum, contactroute,
gevraagde dienst, passende/niet-passende aanvraag en vervolgafspraak. Leg vast
wie dit bijhoudt en plan een evaluatie na 30 en 90 dagen. Noteer geen volledige
berichtinhoud en bewaar niet meer persoonsgegevens dan nodig.

**Beperkingen:** geen trackingpixel, analytics-script, cookiebanner of extern CRM
zonder aparte opdracht en privacybeoordeling.

**Acceptatiecriteria:** eigenaar, velden, bewaartermijn en evaluatiemomenten zijn
expliciet; de methode kan passende en niet-passende aanvragen onderscheiden;
geen website- of trackingwijziging is nodig.
