# Uitvoerbaar verbeterplan JPWebcreation-site

Dit document vertaalt de inhoudelijke beoordeling van de nieuwe JPWebcreation-site naar afzonderlijke taken. Een coding agent moet deze taken één voor één kunnen uitvoeren en controleren.

## Doel

De site moet niet alleen laten zien wat JPWebcreation kan, maar meer geschikte potentiële klanten overtuigen om contact op te nemen.

De gewenste positionering blijft:

> JPWebcreation bouwt niet alleen websites. Joris begrijpt hoe een organisatie werkt en vertaalt knelpunten naar websites, webshops en online tools die klanten helpen en handwerk verminderen.

De site richt zich primair op ondernemers en organisaties die:

- een professionele website nodig hebben die duidelijk uitlegt en aanvragen ondersteunt;
- vastlopen in onvolledige aanvragen, handmatige verwerking of losstaande systemen;
- een webshop, configurator, offertemodule, planningapp of andere online tool nodig hebben;
- één technisch en inhoudelijk aanspreekpunt zoeken voor bouw, beheer en doorontwikkeling.

## Uitvoeringsregels voor agents

- Voer taken in de aangegeven volgorde uit, tenzij een taak expliciet onafhankelijk is.
- Lees voor iedere wijziging eerst `AGENTS.md`.
- Verzin geen resultaten, percentages, klantquotes, prijzen, doorlooptijden of garanties.
- Gebruik alleen klantnamen, quotes en cijfers die door Joris zijn bevestigd of aantoonbaar uit een bestaande openbare bron komen.
- Behoud de praktische, nuchtere toon en schrijf op de site in de ik-vorm wanneer Joris zelf spreekt.
- Houd de kernboodschap gericht op het probleem en resultaat van de klant, niet op gebruikte techniek.
- Maak geen externe accounts, formulieren, analytics-configuraties of betaalde diensten aan zonder toestemming.
- Push, deploy en wijzig DNS of serverinstellingen alleen wanneer dat afzonderlijk is gevraagd.
- Houd iedere taak als afzonderlijke logische wijziging controleerbaar.
- Als `styles.css` wordt gewijzigd, verhoog dan de CSS-versie in alle HTML-bestanden volgens `AGENTS.md`.
- Controleer na iedere wijziging desktop en mobiel via een lokale statische server.

## Statuslegenda

- `[ ]` Niet gestart
- `[~]` In uitvoering
- `[x]` Afgerond en gecontroleerd
- `[!]` Geblokkeerd door ontbrekende informatie of toestemming

## Overzicht en volgorde

| ID | Prioriteit | Taak | Afhankelijk van |
| --- | --- | --- | --- |
| T01 | P0 | Ontbrekende inhoud en keuzes verzamelen | — |
| T02 | P0 | Hero en primaire CTA aanscherpen | T01 gedeeltelijk |
| T03 | P0 | Persoonlijk Joris-blok toevoegen | T01 |
| T04 | P0 | Reviews en bewijs overtuigender maken | T01 |
| T05 | P0 | CTA onder iedere projectcase toevoegen | — |
| T06 | P1 | Projectcases resultaatgerichter maken | T01 |
| T07 | P1 | Diensten terugbrengen tot duidelijke ingangen | T01 gedeeltelijk |
| T08 | P0 | Contactsectie en contactformulier verbeteren | T01 |
| T09 | P1 | Veelgestelde vragen toevoegen | T01 |
| T10 | P0 | Bestaande URL’s inventariseren en migratieplan maken | — |
| T11 | P0 | Redirects en technische SEO-basis implementeren | T10 |
| T12 | P1 | Meting van aanvragen en CTA’s inrichten | T01, T08 |
| T13 | P0 | Inhoudelijke, functionele en responsive QA uitvoeren | T02–T12 |
| T14 | P0 | Livegang en controle na livegang | T10–T13, aparte toestemming |
| T15 | P1 | Homepage-projecten als editorial showcase presenteren | T06 |
| T16 | P1 | Automatische carrousels pauzeerbaar maken | — |
| T17 | P0 | Zichtbare focusstijl voor alle interacties toevoegen | — |
| T18 | P1 | Laden van hero- en projectafbeeldingen optimaliseren | T15 |
| T19 | P1 | JavaScript-cacheversie sitebreed gelijkmaken | — |
| T20 | P0 | Automatische rooktest na deployment toevoegen | T11 |
| T21 | P1 | Formulierinvoer bij validatiefouten veilig behouden | T08 |

---

## T01 — Ontbrekende inhoud en keuzes verzamelen

**Status:** [x]
**Prioriteit:** P0
**Doel:** alle informatie verzamelen die niet veilig door een agent kan worden aangenomen.

### Benodigde keuzes van Joris

- [x] Bevestig de belangrijkste gewenste klantgroep: een combinatie van reguliere websiteklanten en organisaties met proces- of maatwerkvragen.
- [x] Bevestig welke drie hoofddiensten actief verkocht moeten worden: websites, online tools en technisch beheer/doorontwikkeling.
- [x] Bepaal de positie van AI: AI-tools worden binnen online tools aangeboden om bedrijfsprocessen te stroomlijnen, zonder onbewezen resultaatclaims.
- [x] Lever of selecteer een geschikte portretfoto van Joris: gebruik de bestaande foto van de huidige website na visuele controle.
- [x] Bevestig welke reactietermijn bij contact mag worden beloofd: binnen één werkdag.
- [x] Kies de voorkeursroute voor leads: formulier op eigen hosting, telefoon en e-mail.
- [x] Kies hoe het formulier technisch wordt verzonden: een eigen PHP-handler stuurt naar `info@jpwebcreation.nl`; bezorging moet na deployment live worden getest.
- [x] Bevestig de prijspresentatie: geen bedragen op de site; eerst de vraag bespreken. Projecten via een vaste offerte, beheer en losse werkzaamheden op uurbasis.
- [x] Bevestig de werkwijze na het eerste gesprek: vrijblijvende kennismaking en bij grotere maatwerkvragen eventueel een betaalde discovery. Er wordt geen algemene doorlooptijd genoemd; de planning komt in de offerte.
- [x] Selecteer drie sterke bestaande Google-reviews en controleer de exacte tekst en attributie via de huidige Trustindex-weergave.
- [x] Verzamel per hoofdcase minimaal één bevestigd resultaat of concreet effect. Voor alle zes cases is een concreet effect bevestigd en toegevoegd.

### Gewenste resultaatinformatie per case

| Case | Te bevestigen effect of resultaat |
| --- | --- |
| Mike's Pianoshow | ontwikkeling van boekingsaanvragen/verkoop, groei van aantal boekingen of artiesten, tijdwinst door de planningapp |
| Haveka Installatie | completere aanvragen, minder aanvullende communicatie, snellere offertevoorbereiding |
| Supper Services | minder handmatige orderstappen, betrouwbaardere verwerking, tijdwinst of minder fouten |
| EC EEns | eenvoudiger beheer, meer contenttypen beheersbaar, duidelijkere bezoekersroutes |
| Archipel Bouwadvies | meer of relevantere aanvragen, betere vindbaarheid of duidelijkere intake |
| Wildfloweroffice | beter begrip van het aanbod, beheerbaarheid of relevante aanvragen |

### Acceptatiecriteria

- [x] Alle gekozen teksten, quotes en claims hebben een herleidbare bron of expliciete goedkeuring.
- [x] Niet-bevestigde cijfers en claims zijn niet gepubliceerd.
- [x] De benodigde inhoudelijke keuzes zijn vastgelegd voordat afhankelijke taken zijn uitgevoerd.

---

## T02 — Hero en primaire CTA aanscherpen

**Status:** [~]
**Prioriteit:** P0
**Bestand:** `index.html`

### Doel

Binnen enkele seconden duidelijk maken:

1. voor wie JPWebcreation werkt;
2. welk probleem Joris oplost;
3. dat zowel een gewone website als maatwerk mogelijk is;
4. wat de bezoeker nu kan doen.

### Huidige inhoud

- Eyebrow: `Voor ondernemers die online minder handwerk willen`
- H1: `Websites die voor je werken`
- Hoofdtekst:

  > Loop je vast met aanvragen, bestellingen, beheer of systemen die niet samenwerken? Ik kijk hoe je organisatie werkt en bouw een website of online tool die klanten helpt en jou tijd, fouten en losse handelingen bespaart.

- Primaire CTA: `Vertel waar het knelt`
- Secundaire CTA: `Bekijk wat ik heb gebouwd`
- De bevestigde reactietermijn staat bij het contactformulier en wordt niet als
  extra regel in de hero herhaald.

### Werkzaamheden

- [x] Vervang de hero-inhoud zonder de kernpositionering af te zwakken.
- [x] Laat de primaire CTA naar een werkend contactformulier of contactgedeelte verwijzen.
- [x] Laat de secundaire CTA naar de cases verwijzen.
- [x] Controleer dat de hero niet suggereert dat alleen complex maatwerk mogelijk is.
- [ ] Controleer regellengte en leesbaarheid op mobiel.

### Acceptatiecriteria

- [ ] Een nieuwe bezoeker kan zonder te scrollen benoemen wat JPWebcreation doet en waarom dat nuttig is.
- [ ] De CTA beschrijft een laagdrempelige volgende stap.
- [ ] Er staat geen onbevestigde reactietermijn of garantie.
- [ ] De hero werkt visueel op 320, 375, 768, 1024 en 1440 pixels breed.

---

## T03 — Persoonlijk Joris-blok toevoegen

**Status:** [x]
**Prioriteit:** P0
**Bestanden:** `index.html`, `styles.css`, nieuw afbeeldingsbestand onder `assets/`

### Doel

Vertrouwen opbouwen door vroeg genoeg te laten zien dat klanten rechtstreeks met Joris samenwerken en dat hij zowel meedenkt als bouwt.

### Huidige positie

Het blok staat na de belangrijkste cases en vóór de reviews en diensten.

### Inhoudelijke richting

- Kop: `Eén partner die blijft meedenken`
- Vermeld:
  - Joris is het vaste aanspreekpunt;
  - hij onderzoekt eerst waar het proces knelt;
  - hij kan zowel een heldere website als maatwerk bouwen;
  - hij blijft beschikbaar voor beheer en doorontwikkeling.
- Voeg een echte, recente portretfoto toe met een beschrijvende alt-tekst.
- Voeg een CTA toe naar contact.

### Werkzaamheden

- [x] Gebruik alleen een door Joris goedgekeurde portretfoto.
- [x] Schrijf een korte tekst van circa 80–130 woorden.
- [x] Vermijd een lange technische opsomming van frameworks en plugins.
- [x] Verbind persoonlijke samenwerking expliciet aan het voordeel voor de klant.
- [x] Voeg een duidelijke contact-CTA toe.

### Acceptatiecriteria

- [x] Het blok voelt persoonlijk, maar niet als een volledige autobiografie.
- [x] De tekst maakt duidelijk waarom rechtstreeks contact met Joris waardevol is.
- [x] De bestaande afbeelding is 800 × 800 pixels, 51 KB en heeft vaste afmetingen in de HTML.
- [x] Het blok gebruikt de bestaande responsive gridstructuur en een beschrijvende alt-tekst.

---

## T04 — Reviews en bewijs overtuigender maken

**Status:** [x]
**Prioriteit:** P0
**Bestanden:** `index.html`, mogelijk `styles.css`

### Doel

Zelfclaims vervangen of ondersteunen met geloofwaardig bewijs van klanten.

### Werkzaamheden

- [x] Vervang de zes zeer korte quotes door drie sterkere bestaande reviews met meer context.
- [x] Toon per review de bevestigde naam, relevante passage en bron. Bedrijf of rol wordt niet toegevoegd wanneer die niet zeker is.
- [x] Geef voorrang aan reviews die concrete effecten of een aantoonbare werkwijze noemen.
- [x] Voeg geen gemiddelde Google-score of reviewaantal toe zolang die niet actueel is gecontroleerd.
- [x] Presenteer de bestaande procesclaims als `Zo werkt JPWebcreation`, niet als klantbewijs.

### Acceptatiecriteria

- [x] Iedere quote is exact of met een zichtbare weglating als verkorte quote weergegeven.
- [x] Iedere quote heeft de naam waaronder hij in de bestaande Google-review wordt getoond.
- [x] De geselecteerde reviews ondersteunen een zakelijk resultaat, technische kwaliteit of bereikbaarheid.
- [x] Er zijn geen niet-bevestigde bedrijfsnamen, functies of resultaten toegevoegd.

---

## T05 — CTA onder iedere projectcase toevoegen

**Status:** [x]
**Prioriteit:** P0
**Bestanden:** alle bestanden onder `projecten/*/index.html`, mogelijk `styles.css`

### Doel

Voorkomen dat een geïnteresseerde bezoeker na een case alleen naar de klantwebsite of terug naar het portfolio kan gaan.

### Werkzaamheden

- [x] Voeg onder iedere case een apart conversieblok toe.
- [x] Schrijf de kop en tekst passend bij het probleem van die case.
- [x] Laat de primaire CTA verwijzen naar `../../#contact` of naar de latere definitieve contactpagina.
- [x] Houd `Bekijk live site` als secundaire actie.
- [x] Zorg dat de CTA niet suggereert dat iedere klant exact dezelfde oplossing nodig heeft.

### Voorgestelde CTA-richtingen

| Case | CTA-kop |
| --- | --- |
| Mike's Pianoshow | `Groeit je bedrijf sneller dan je planning meekan?` |
| Haveka Installatie | `Kost een aanvraag nu te veel heen-en-weer contact?` |
| Supper Services | `Moet je webshop beter aansluiten op je operatie?` |
| EC EEns | `Moet veel informatie beheersbaar én vindbaar blijven?` |
| Archipel Bouwadvies | `Moeten bezoekers sneller begrijpen welke hulp ze nodig hebben?` |
| Wildfloweroffice | `Is je aanbod goed, maar je online verhaal nog niet helder?` |

Gebruik als primaire knop bijvoorbeeld `Bespreek jouw situatie` of `Vertel waar het knelt`.

### Acceptatiecriteria

- [x] Iedere projectpagina eindigt met een duidelijke route naar JPWebcreation.
- [x] De primaire CTA blijft op het JPWebcreation-domein.
- [x] De externe klantwebsite is visueel secundair aan contact opnemen.
- [x] Alle links werken vanaf iedere mapdiepte.

---

## T06 — Projectcases resultaatgerichter maken

**Status:** [x]
**Prioriteit:** P1
**Afhankelijk van:** T01
**Bestanden:** alle bestanden onder `projecten/*/index.html`

### Doel

Per case niet alleen vertellen wat is gebouwd, maar aantonen welk bedrijfsprobleem is opgelost en wat daardoor veranderde.

### Gewenste vaste case-opbouw

1. Situatie of probleem
2. Waarom de standaardaanpak tekortschiet
3. Gekozen oplossing
4. Concreet resultaat of operationeel effect
5. Relevante klantquote
6. CTA naar JPWebcreation

### Werkzaamheden

- [x] Controleer iedere case op een duidelijke probleem-resultaatrelatie.
- [x] Maak lange featurelijsten ondergeschikt aan het resultaat.
- [x] Voeg per case een zichtbaar blok `Resultaat` toe.
- [x] Gebruik cijfers alleen na bevestiging in T01; er zijn geen nieuwe cijfers toegevoegd.
- [x] Gebruik zonder cijfers alleen concrete, door Joris bevestigde effecten.
- [x] Voeg bij Mike's Pianoshow een relevante, bestaande klantreview als onderbouwing toe; verzin geen quotes voor andere cases.
- [x] Behoud technische termen alleen wanneer ze de complexiteit of deskundigheid begrijpelijk maken.

### Acceptatiecriteria

- [x] Iedere case beantwoordt duidelijk: wat was het probleem, wat deed Joris en wat leverde dat op?
- [x] Iedere gepubliceerde resultaatclaim is bevestigd of volgt direct uit de beschreven en bevestigde projectinrichting.
- [x] De belangrijkste waarde is scanbaar zonder alle alinea's te lezen.
- [x] Geen twee cases gebruiken vrijwel dezelfde algemene resultaattekst.

---

## T07 — Diensten terugbrengen tot duidelijke ingangen

**Status:** [x]
**Prioriteit:** P1
**Bestanden:** `index.html`, mogelijk `styles.css`

### Doel

Bezoekers snel laten herkennen waarvoor ze JPWebcreation concreet kunnen inschakelen, zonder zes gelijkwaardige en deels overlappende diensten te hoeven vergelijken.

### Aanbevolen drie hoofdingangen

1. **Website die duidelijk uitlegt en aanvragen ondersteunt**
   Voor een nieuwe of bestaande bedrijfswebsite, inhoud, structuur, vindbaarheid en conversie.

2. **Webshop of online tool die handwerk vermindert**
   Voor bestellen, offertes, configuratie, planning, klantomgevingen en koppelingen.

3. **Technisch beheer en doorontwikkeling**
   Voor onderhoud, hosting, foutopsporing, optimalisatie en uitbreiding na livegang.

### Werkzaamheden

- [x] Vervang of hergroepeer de huidige zes diensten.
- [x] Maak bij iedere hoofddienst duidelijk voor welk probleem deze bedoeld is.
- [x] Benoem expliciet dat een gewone, professionele website ook mogelijk is als maatwerk niet nodig is.
- [x] Verwerk webshops, koppelingen en AI als mogelijkheden binnen websites en online tools.
- [x] Positioneer AI als onderdeel van online tools voor het stroomlijnen van bedrijfsprocessen, zonder onbewezen cases of resultaten te suggereren.
- [x] Laat de diensten logisch aansluiten op de bestaande cases en het contactgedeelte.

### Acceptatiecriteria

- [x] Een bezoeker kan kiezen uit drie duidelijk onderscheiden hoofddiensten.
- [x] De sectie straalt geen onnodige complexiteit uit naar klanten die alleen een goede website nodig hebben.
- [x] De maatwerkcapaciteit blijft duidelijk zichtbaar.
- [x] De drie diensten overlappen inhoudelijk zo min mogelijk.

---

## T08 — Contactsectie en contactformulier verbeteren

**Status:** [~]
**Prioriteit:** P0
**Afhankelijk van:** T01
**Bestanden:** minimaal `index.html`, `styles.css`, mogelijk `script.js` en een goedgekeurde server- of formulierintegratie

### Doel

Contact opnemen laagdrempelig maken voor bezoekers die nog geen uitgewerkt plan hebben.

De contacttekst, rechtstreekse contactroutes, het eigen formulier en een aparte
succesweergave zijn geïmplementeerd. De statische site staat nog niet op
productie; daadwerkelijke e-mailbezorging moet daarom na de handmatige
productiedeploy opnieuw worden getest.

### Aanbevolen inhoud

- Kop: `Wat moet jouw website beter voor je regelen?`
- Inleiding:

  > Vertel kort waar je tegenaan loopt. Je hoeft de oplossing nog niet te weten; ik denk mee over wat praktisch en haalbaar is.

- Formuliervelden:
  - naam;
  - bedrijf, optioneel;
  - e-mailadres;
  - telefoonnummer, optioneel;
  - vraag: `Waar loop je tegenaan?`;
  - privacytoestemming indien juridisch nodig.

### Werkzaamheden

- [x] Kies eerst een goedgekeurde verzendmethode voor de statische site.
- [x] Voeg een kort formulier toe zonder onnodige verplichte velden.
- [x] Voeg duidelijke fout-, verzend- en succesmeldingen toe.
- [x] Bescherm het formulier met een honeypot, servervalidatie en een korte rate limit.
- [x] Link naar de privacyverklaring.
- [x] Behoud telefoon en e-mail als alternatieve contactroutes.
- [x] Voeg de bevestigde reactietermijn van één werkdag toe.
- [x] Log geen formulierinhoud in analytics.

### Acceptatiecriteria

- [ ] Een formulierinzending komt aantoonbaar op de juiste plek aan.
- [ ] De bezoeker krijgt na verzenden een duidelijke bevestiging.
- [ ] Het formulier werkt met toetsenbord en schermlezer en heeft correcte labels.
- [ ] Validatiefouten wissen geen reeds ingevulde inhoud.
- [ ] E-mailadressen, sleutels en secrets staan niet in client-side JavaScript of in de repository.
- [ ] De primaire CTA's op homepage en cases leiden naar deze contactmogelijkheid.

---

## T09 — Veelgestelde vragen toevoegen

**Status:** [x]
**Prioriteit:** P1
**Afhankelijk van:** T01
**Bestanden:** `index.html`, mogelijk `styles.css`

### Doel

Belangrijke twijfels wegnemen voordat een potentiële klant contact opneemt.

### Onderwerpen

- [x] Kun je ook een bestaande website verbeteren?
- [x] Bouw je alleen maatwerk of ook een reguliere bedrijfswebsite?
- [x] Werk je alleen met WordPress?
- [x] Kan ik de website zelf beheren?
- [x] Kun je hosting, onderhoud en doorontwikkeling verzorgen?
- [x] Hoe verloopt een traject na het eerste gesprek?
- [x] Hoe wordt de prijs voor een website of online tool bepaald?
- [x] Hoe lang duurt een gemiddeld traject?

Alle FAQ-antwoorden zijn gebaseerd op bestaande projectinformatie of expliciete keuzes van Joris. Er worden geen prijsbedragen of algemene doorlooptijden genoemd.

Publiceer alleen antwoorden die door Joris zijn bevestigd. Als een vaste prijs of doorlooptijd niet passend is, leg dan helder uit waarvan deze afhangt en wat de bezoeker na een eerste gesprek ontvangt.

### Acceptatiecriteria

- [x] De FAQ bevat alleen vragen die koopbezwaren wegnemen.
- [x] De antwoorden zijn kort, concreet en controleerbaar.
- [x] De uitklapfunctionaliteit gebruikt native `details` en `summary` en werkt zonder eigen JavaScript.
- [x] Er is geen afwijkende FAQ-structured data toegevoegd.

---

## T10 — Bestaande URL's inventariseren en migratieplan maken

**Status:** [~]
**Prioriteit:** P0 vóór livegang
**Resultaat:** een gecontroleerde URL-mapping, bijvoorbeeld `URL-MIGRATIE.md` of `url-migratie.csv`

### Doel

Voorkomen dat de sterkere nieuwe site bij livegang organisch verkeer, backlinks en bestaande bezoekers verliest.

### Werkzaamheden

- [~] Exporteer alle bestaande URL's uit:
  - [x] de huidige XML-sitemaps;
  - Google Search Console, indien beschikbaar;
  - analytics of serverlogs, indien beschikbaar;
  - een crawl van de huidige website;
  - bekende backlinks, indien beschikbaar.
- [ ] Noteer voor iedere oude URL:
  - huidige statuscode;
  - paginatype;
  - organisch verkeer of vertoningen indien bekend;
  - backlinks indien bekend;
  - nieuwe bestemming;
  - actie: behouden, herschrijven, samenvoegen, 301 of bewust verwijderen.
- [ ] Redirect niet automatisch alle oude artikelen naar de homepage. Kies de inhoudelijk meest relevante bestemming.
- [ ] Beoordeel vergelijkbare oude SEO-artikelen op samenvoegen tot enkele kwalitatieve landingspagina's.
- [ ] Behoud waardevolle lokale en dienstgerichte zoekintenties met echte, nuttige inhoud.

### Minimaal bekende slugwijzigingen om te controleren

| Oude URL | Nieuwe URL |
| --- | --- |
| `/projecten/website-mikes-pianoshow/` | `/projecten/mikes-pianoshow/` |
| `/projecten/website-wildfloweroffice/` | `/projecten/wildfloweroffice/` |
| `/projecten/archipel-bouwadvies/` | blijft gelijk |
| `/contact-voor-hulp-met-jouw-website/` | bepaal relevante contactbestemming |
| `/blog/` en bestaande artikelen | per URL beoordelen |

### Acceptatiecriteria

- [ ] Iedere indexeerbare oude URL heeft een expliciete beslissing.
- [ ] De belangrijkste huidige landingspagina's verdwijnen niet zonder gelijkwaardige inhoud of redirect.
- [ ] De mapping is door Joris gecontroleerd voordat redirects worden geïmplementeerd.
- [ ] De oude sitemap en crawl zijn als bron vastgelegd.

---

## T11 — Redirects en technische SEO-basis implementeren

**Status:** [~]
**Prioriteit:** P0 vóór livegang
**Afhankelijk van:** T10

### Doel

De nieuwe statische site indexeerbaar maken zonder bestaande SEO-signalen onnodig te verliezen.

### Werkzaamheden

- [~] Implementeer de goedgekeurde 301-mapping in de configuratie die op de LiteSpeed/Apache-server actief is. De twee onbetwiste projectredirects zijn toegevoegd; overige URL's wachten op data en goedkeuring.
- [ ] Voorkom redirectketens en loops.
- [x] Voeg per indexeerbare pagina een correcte canonical naar het productiedomein toe.
- [x] Controleer unieke page titles en meta descriptions.
- [x] Voeg Open Graph- en social metadata toe op homepage, privacy en alle projectcases, met uitsluitend bestaande titels, beschrijvingen en projectafbeeldingen.
- [x] Maak een XML-sitemap met alleen canonieke, indexeerbare productie-URL's.
- [x] Maak een passende `robots.txt` die naar de sitemap verwijst.
- [~] Zorg dat de stagingomgeving `test.jpwebcreation.nl` niet in zoekmachines wordt geïndexeerd, bij voorkeur via een omgevingsspecifieke `X-Robots-Tag: noindex, nofollow` of gelijkwaardige serverinstelling. De hostafhankelijke header staat in `.htaccess`, maar moet na deployment op de server worden gecontroleerd.
- [ ] Blokkeer productie niet per ongeluk wanneer de stagingblokkade wordt ingesteld.
- [x] Maak een bruikbare 404-pagina met routes naar homepage, cases en contact.
- [x] Voeg `ProfessionalService` structured data toe met uitsluitend de zichtbare en verifieerbare naam, URL, e-mail, telefoon en adresgegevens.

### Acceptatiecriteria

- [ ] Oude URL's geven rechtstreeks één 301 naar de juiste nieuwe URL.
- [ ] Productiepagina's geven 200 en hebben een zelfverwijzende canonical.
- [ ] Staging geeft aantoonbaar een `noindex`-signaal en productie niet.
- [ ] `sitemap.xml` en `robots.txt` geven 200 en bevatten productie-URL's.
- [ ] Geen interne link verwijst naar een oude of omgeleide URL.
- [ ] Er zijn geen onbedoelde 404's in een volledige crawl.

---

## T12 — Meting van aanvragen en CTA's inrichten

**Status:** [x] — bewust afgesloten zonder bezoekersanalytics
**Prioriteit:** P1
**Afhankelijk van:** T01 en T08

### Doel

Kunnen vaststellen of de nieuwe site daadwerkelijk meer en betere aanvragen oplevert.

Joris heeft gekozen voorlopig geen bezoekersanalytics te gebruiken. Er is daarom bewust geen trackingcode, conversie-event of cookiebanner toegevoegd. Inkomende formulieren, e-mails en telefoongesprekken kunnen alleen handmatig als lead worden bijgehouden.

### Alleen bij een latere, expliciet goedgekeurde analyticsfase

- [ ] Succesvolle formulierinzending.
- [ ] Klik op telefoonnummer.
- [ ] Klik op e-mailadres.
- [ ] Klik op primaire hero-CTA.
- [ ] Klik op CTA onder een case.
- [ ] Eventueel klik naar een afspraakplanner of WhatsApp als die wordt toegevoegd.

### Werkzaamheden

- [ ] Leg vóór livegang een nulmeting vast van huidig verkeer en huidige aanvragen, voor zover beschikbaar.
- [x] Vraag toestemming voordat analytics wordt toegevoegd; keuze: voorlopig geen bezoekersanalytics.
- [ ] Definieer duidelijke eventnamen en documenteer deze.
- [ ] Markeer een formulier pas als conversie na bevestigde succesvolle verzending.
- [x] Laad geen niet-noodzakelijke tracking en behoud de bestaande cookieloze situatie.
- [ ] Maak onderscheid tussen totaal aantal leads en geschikte leads.

### Acceptatiecriteria

- [x] Er zijn nu geen afgesproken analyticsconversies om te testen.
- [x] Dubbele analytics-events zijn niet van toepassing zolang geen tracking is toegevoegd.
- [x] Persoonsgegevens en berichtinhoud worden niet naar analytics gestuurd, omdat geen analytics is toegevoegd.
- [ ] Leg vóór productie vast hoe Joris zonder bezoekersanalytics handmatig de
  aantallen en geschiktheid van aanvragen na 30 en 90 dagen beoordeelt.

---

## T13 — Inhoudelijke, functionele en responsive QA uitvoeren

**Status:** [~]
**Prioriteit:** P0
**Afhankelijk van:** alle te publiceren wijzigingen

### Inhoudelijke controle

- [x] Controleer spelling, interpunctie en Nederlandse diakritische tekens, zoals `coöperatie` en `oriëntatie`.
- [x] Gebruik `Mike's Pianoshow`, `JPWebcreation` en andere namen overal consequent.
- [ ] Controleer consequente ik-vorm en aanspreekvorm.
- [ ] Verwijder dubbele, algemene of niet-onderbouwde claims.
- [ ] Controleer dat iedere sectie een nieuwe functie in de klantreis heeft.

### Functionele controle

- [x] Start de lokale preview volgens `AGENTS.md` en controleer alle statische publieksroutes.
- [x] Test alle navigatie-, telefoon-, mail-, case-, externe en privacy-links op bestemming en bestaand bestand/anker.
- [ ] Test het mobiele menu met muis, toetsenbord en touch.
- [ ] Test het contactformulier volledig, inclusief foutscenario's.
- [ ] Controleer console en netwerkverzoeken op fouten.
- [x] Controleer dat externe links veilig worden geopend en tijdens de audit HTTP 200 geven.

### Responsive en toegankelijkheid

- [ ] Controleer minimaal 320, 375, 768, 1024 en 1440 pixels breed.
- [ ] Controleer focusstijlen, contrast, headingvolgorde en landmarkstructuur.
- [x] Controleer de headingvolgorde en aanwezigheid van alt-attributen; visuele kwaliteit en schermlezertest blijven open.
- [x] Controleer dat carrouselanimaties met `prefers-reduced-motion` worden uitgeschakeld.
- [x] Controleer dat belangrijke inhoud als gewone HTML aanwezig blijft zonder animatie of JavaScript.
- [x] Gebruik één revealpatroon voor herhaalde probleemitems, processtappen,
  projectrijen, reviews en diensten; laat koppen, FAQ-bediening en het
  contactformulier direct zichtbaar.
- [x] Schakel revealtransities volledig uit bij
  `prefers-reduced-motion: reduce`.
- [ ] Controleer de nieuwe editorial projectrijen afzonderlijk op 320, 375, 768,
  1024 en 1440 pixels: beeldvolgorde, uitsnede, regellengte, tussenruimte,
  scheidingslijnen en de twee compacte secundaire projectlinks.

### Performance

- [x] Controleer de nieuwe portretfoto op bestandstype, afmetingen en bestandsgrootte.
- [ ] Controleer layout shifts, laadtijd en mobiele leesbaarheid.
- [ ] Voer een Lighthouse- of gelijkwaardige controle uit en los regressies met hoge impact op.

### Acceptatiecriteria

- [ ] Geen bekende kapotte links, consolefouten of formulierfouten.
- [x] Er zijn geen kapotte interne links of externe projectlinks gevonden; console en formulierwerking blijven open.
- [x] Geen onbevestigde inhoud is aan de verbeterbranch toegevoegd.
- [ ] Alle P0-taken zijn aantoonbaar afgerond.
- [ ] De site is bruikbaar met toetsenbord en op kleine mobiele schermen.
- [x] Resterende browser-, PHP-, server- en productietests zijn expliciet gedocumenteerd in `QA-RAPPORT.md`.

---

## T14 — Livegang en controle na livegang

**Status:** [!]
**Prioriteit:** P0
**Afhankelijk van:** T10–T13 en expliciete toestemming van Joris

### Voor livegang

- [ ] Maak of bevestig een herstelmogelijkheid van de huidige productieomgeving.
- [ ] Controleer dat de te deployen commit exact de goedgekeurde versie is.
- [ ] Controleer redirectregels, formulieren, sitemap, canonicals en productie-robots.
- [x] Bevestig dat geen bezoekersanalytics of trackingcookies worden gebruikt; de privacyverklaring vermeldt dit.
- [ ] Maak een korte lijst met kritieke URL's voor directe controle.

### Na livegang

- [ ] Controleer homepage, alle cases, privacy en contact op productie.
- [ ] Test een echte formulierinzending.
- [ ] Test telefoon- en e-maillinks op mobiel.
- [ ] Controleer alle belangrijke oude URL's op één correcte 301.
- [ ] Controleer canonical, robots en sitemap op het productiedomein.
- [ ] Dien de nieuwe sitemap in bij Google Search Console.
- [ ] Controleer de eerste dagen 404's, serverfouten en formulierbezorging.
- [ ] Vergelijk na 30 en 90 dagen verkeer, conversies en leadkwaliteit met de nulmeting.

### Acceptatiecriteria

- [ ] Alle kritieke productiechecks zijn geslaagd.
- [ ] Er zijn geen onbedoelde indexatieblokkades.
- [ ] Oude belangrijke URL's behouden een geldige route.
- [ ] Leads kunnen via minimaal twee werkende routes contact opnemen.
- [ ] Problemen en vervolgoptimalisaties zijn als nieuwe, afzonderlijke taken vastgelegd.

---

## T15 — Homepage-projecten als editorial showcase presenteren

**Status:** [~]
**Prioriteit:** P1
**Bestanden:** `index.html`, `styles.css`

### Doel

De projecten niet als een generieke kaartmuur tonen, maar als een rustig,
redactioneel bewijs van hoe JPWebcreation bedrijfsproblemen oplost.

### Vastgelegde ontwerpkeuze

- Vier primaire cases staan als brede rijen onder elkaar: Mike's Pianoshow,
  Haveka Installatie, Supper Services en EC EEns.
- Oneven rijen tonen beeld links en tekst rechts; even rijen draaien dit op
  desktop visueel om.
- De DOM-volgorde blijft bij iedere case afbeelding en daarna tekst. Op mobiel
  verschijnt iedere case daardoor consequent als afbeelding gevolgd door tekst.
- Projectonderdelen staan als gewone inline tekst met puntscheiding, niet als
  pill badges.
- De rijen hebben geen buitenrand, kaartachtergrond of omhoog springende
  hoveranimatie. Witruimte en horizontale lijnen maken de structuur.
- Archipel Bouwadvies en Wildfloweroffice staan onderaan als compacte
  secundaire links onder `Ook gebouwd`.

### Werkzaamheden

- [x] Vervang `.work-feature`, `.work-grid` en `.work-card` door
  `.work-list`, `.work-item` en `.work-more`.
- [x] Behoud alle zes bestaande case-URL's op de homepage.
- [x] Voeg per primaire case een nummer, resultaatgerichte bovenregel, korte
  probleem-oplossingstekst, projectonderdelen en `Bekijk case` toe.
- [x] Laat screenshots maximaal circa 2,5% vergroten bij hover en verplaats
  alleen de CTA-pijl; voeg geen kaarthover of zware extra schaduw toe.
- [x] Verhoog de CSS-cacheversie in alle HTML-bestanden.
- [x] Corrigeer de mobiele projectkop: forceer één kolom met voldoende
  selectorspecificiteit, verlaag de kopgrootte onder 560 pixels en voeg een
  gecontroleerd afbreekpunt toe aan `bedrijfsproblemen`.
- [ ] Voer de specifieke responsive en visuele controles uit die bij T13 staan.
- [ ] Controleer na testdeployment dat alle zes cases vanuit de homepage openen.

### Acceptatiecriteria

- [ ] De desktopsectie leest als opeenvolgende cases en niet als een card grid.
- [ ] De mobiele volgorde is voor alle vier primaire cases afbeelding, tekst en
  CTA, zonder CSS-gridpositie uit de desktopvariant.
- [ ] De twee secundaire projecten blijven zichtbaar en toetsenbordbedienbaar.
- [ ] Geen screenshot wordt uitgerekt of toont een ongewenste uitsnede op de
  afgesproken controlebreedtes.

---

## T16 — Automatische carrousels pauzeerbaar maken

**Status:** [ ]
**Prioriteit:** P1
**Bestanden:** `index.html`, alle projectpagina's met een carrousel,
`styles.css`, `script.js`

### Probleem

De hero op de homepage en de projecthero's draaien continu. De bestaande
`prefers-reduced-motion`-regel stopt animatie voor bezoekers die die
voorkeur hebben ingesteld, maar andere bezoekers hebben nog geen zichtbare
manier om de beweging zelf te pauzeren.

### Uitvoering

- Voeg per automatisch draaiende carrousel één echte `button` toe met een
  zichtbare tekst of duidelijk icoon plus toegankelijke naam
  `Animatie pauzeren`.
- Laat de knop met `aria-pressed` of een even duidelijke status aangeven of
  de carrousel draait.
- Voeg één herbruikbare CSS-klasse toe die de relevante
  `animation-play-state` op `paused` zet; maak geen losse implementatie per
  project.
- Pauzeer ook zolang een carrousel of de pauzeknop toetsenbordfocus heeft en
  zolang een aanwijzer erboven staat. Start alleen automatisch opnieuw wanneer
  de bezoeker niet zelf op pauze heeft gedrukt.
- Initialiseer de animatie direct als gepauzeerd wanneer
  `prefers-reduced-motion: reduce` actief is.
- Controleer dat de knop zonder JavaScript geen content of links verbergt.

### Acceptatiecriteria

- [ ] De bediening werkt met muis, touch, Enter en spatie.
- [ ] De gekozen pauzestatus blijft behouden tijdens dezelfde paginaweergave.
- [ ] Er ontstaat geen layout shift wanneer knoptekst of status verandert.
- [ ] Homepage en alle zes projectpagina's gebruiken hetzelfde gedrag.

---

## T17 — Zichtbare focusstijl voor alle interacties toevoegen

**Status:** [ ]
**Prioriteit:** P0 vóór productie
**Bestand:** `styles.css`

### Probleem

Formuliervelden hebben een expliciete focusstijl, maar navigatielinks, knoppen,
projectrijen, FAQ-samenvattingen, secundaire projectlinks en footerlinks
vertrouwen grotendeels op browserdefaults. Dat is visueel inconsistent en kan
op de groene en lichte achtergronden onvoldoende zichtbaar zijn.

### Uitvoering

- Definieer één sitebrede `:focus-visible`-stijl met minimaal 3 px outline en
  minimaal 3 px outline-offset.
- Gebruik op lichte achtergronden `var(--green-dark)` en op donkergroene
  achtergronden `var(--amber)` of een andere bestaande kleur met aantoonbaar
  voldoende contrast.
- Pas de stijl expliciet toe op `a`, `button`, `summary`, invoervelden en
  de volledige klikbare `.work-item`- en `.work-more-list a`-elementen.
- Verwijder nergens `outline` zonder in dezelfde regel een gelijkwaardig
  zichtbaar alternatief te bieden.
- Controleer focusvolgorde vanaf de header tot en met de footer zonder muis.

### Acceptatiecriteria

- [ ] Elk interactief element is tijdens toetsenbordnavigatie direct herkenbaar.
- [ ] Focus wordt niet afgesneden door `overflow: hidden` van screenshots,
  kaarten of containers.
- [ ] De stijl is zichtbaar op `var(--paper)`, `var(--white)`,
  `var(--mist)`, `var(--green)` en `var(--green-dark)`.

---

## T18 — Laden van hero- en projectafbeeldingen optimaliseren

**Status:** [ ]
**Prioriteit:** P1
**Bestanden:** `index.html`, projectpagina's, bestanden onder
`assets/projects/`

### Probleem

De homepagehero bevat zes grote carrouselafbeeldingen boven de vouw. Als meerdere
afbeeldingen tegelijk hoge laadprioriteit krijgen, concurreren ze met tekst,
CSS en de eerste zichtbare afbeelding. De platte screenshots hebben bovendien
vaste bronbestanden zonder `srcset` voor kleinere schermen.

### Uitvoering

- Meet eerst per hero- en projectafbeelding de huidige pixelafmetingen en
  bestandsgrootte; noteer die in de taakdiff of QA-uitvoer.
- Geef alleen de afbeelding die bij de eerste weergave daadwerkelijk zichtbaar
  moet zijn `fetchpriority="high"`. Verwijder hoge prioriteit bij alle andere
  panelen.
- Bepaal met browsernetwerkcontrole of niet-eerste panelen veilig
  `loading="lazy"` kunnen gebruiken zonder lege panelen tijdens de eerste
  omwenteling. Pas lazy loading alleen toe als die controle slaagt.
- Genereer waar dat aantoonbaar bytes bespaart minimaal een kleinere WebP-variant
  voor mobiele schermen en gebruik `srcset` plus een correcte `sizes`-waarde.
- Behoud de bestaande JPG- of PNG-fallback wanneer die nog nodig is.
- Wijzig geen uitsnede of compositie zonder de projectsectie en alle
  projecthero's opnieuw visueel te controleren.

### Acceptatiecriteria

- [ ] Slechts één heroafbeelding heeft expliciet hoge laadprioriteit.
- [ ] De eerste zichtbare carouselweergave bevat geen leeg of later inspringend
  paneel.
- [ ] Afbeeldingen behouden hun aspectratio en er ontstaat geen extra layout
  shift.
- [ ] Lighthouse of een gelijkwaardige meting toont geen nieuwe
  afbeeldingswaarschuwing met hoge impact.

---

## T19 — JavaScript-cacheversie sitebreed gelijkmaken

**Status:** [ ]
**Prioriteit:** P1
**Bestanden:** alle HTML-bestanden

### Probleem

Alle pagina's verwijzen naar hetzelfde `script.js`, maar gebruiken momenteel
verschillende queryversies (`v=3`, `v=4` en `v=5`). Daardoor kan een
bezoeker afhankelijk van de route verschillende gecachte versies van hetzelfde
bestand gebruiken.

### Uitvoering

- Kies één nieuw geheel versienummer dat hoger is dan de hoogste huidige
  versie.
- Vervang in ieder HTML-bestand uitsluitend de querywaarde van
  `script.js?v=`; verander geen relatieve padniveaus.
- Controleer met
  `rg -n 'script\\.js\\?v=' --glob '*.html'` dat alle regels hetzelfde
  versienummer tonen.
- Open homepage, 404, privacy en minimaal één projectpagina en controleer menu,
  revealgedrag en formulierstatus op JavaScript-fouten.

### Acceptatiecriteria

- [ ] Er komt exact één JavaScript-versienummer voor in alle HTML-bestanden.
- [ ] Alle relatieve scriptpaden verwijzen nog naar het bestaande
  `script.js`.
- [ ] De wijziging bevat geen functionele JavaScript-aanpassing.

---

## T20 — Automatische rooktest na deployment toevoegen

**Status:** [ ]
**Prioriteit:** P0 vóór productie
**Bestand:** `.github/workflows/deploy.yml`

### Probleem

De workflow stopt na `rsync`. Een geslaagde bestandsoverdracht bewijst niet dat
de webserver de juiste routes, statuscodes en stagingheaders teruggeeft.

### Uitvoering

- Voeg na de testdeploy een aparte stap `Verify test deployment` toe.
- Laat deze stap met `curl --fail --show-error --location` minimaal
  `/`, `/privacyverklaring/`, alle zes projectroutes,
  `/sitemap.xml` en `/robots.txt` ophalen.
- Controleer afzonderlijk met headers dat test
  `X-Robots-Tag: noindex, nofollow` bevat.
- Vraag een bewust niet-bestaand pad op en controleer zowel HTTP-status 404 als
  aanwezigheid van een unieke tekst uit `404.html`.
- Voeg na de handmatige productiedeploy een overeenkomstige stap toe die
  controleert dat productie géén `X-Robots-Tag: noindex` bevat.
- Verstuur vanuit deze rooktest geen contactformulier en volg geen externe
  klantlinks.

### Acceptatiecriteria

- [ ] De workflow faalt wanneer een kritieke route geen verwachte status geeft.
- [ ] Test faalt wanneer de noindexheader ontbreekt.
- [ ] Productie faalt wanneer een noindexheader aanwezig is.
- [ ] De uitvoer noemt de falende route zonder secrets of serverpaden te tonen.

---

## T21 — Formulierinvoer bij validatiefouten veilig behouden

**Status:** [ ]
**Prioriteit:** P1
**Bestanden:** `index.html`, `contact.php`, mogelijk `script.js`

### Probleem

`contact.php` gebruikt na een ongeldige inzending een 303-redirect naar
`/?contact=invalid`. De bezoeker ziet daarna alleen een algemene melding en
moet alle velden opnieuw invullen.

### Uitvoering

- Kies vóór de implementatie één server-side methode voor kortstondig
  teruggeven van naam, bedrijf, e-mail, telefoon en bericht na een
  validatiefout.
- Zet formulierinhoud niet in queryparameters, logs, analytics of
  `localStorage`.
- Geef per ongeldig verplicht veld een concrete foutmelding die met
  `aria-describedby` aan dat veld is gekoppeld.
- Plaats focus na terugkeer op de eerste ongeldige invoer; laat de algemene
  statusmelding daarnaast kort samenvatten wat de bezoeker moet herstellen.
- Bewaar nooit de honeypotwaarde en vink privacytoestemming niet automatisch
  opnieuw aan.
- Wis tijdelijke server-side invoer direct na eenmaal uitlezen en stel een
  korte maximale bewaartijd in.
- Behoud de bestaande servervalidatie, hostcontrole, honeypot en rate limit.

### Acceptatiecriteria

- [ ] Geldige eerder ingevulde waarden blijven na een validatiefout zichtbaar.
- [ ] Geen formulierinhoud verschijnt in de URL of browseropslag.
- [ ] Fouten zijn met toetsenbord en schermlezer per veld te begrijpen.
- [ ] Een succesvolle inzending toont nog steeds de bestaande succesweergave en
  wist het formulier.

---

## Definitie van volledig afgerond

Dit verbeterplan is volledig uitgevoerd wanneer:

- [ ] de positionering direct duidelijk is voor de gewenste klantgroep;
- [ ] cases aantoonbare problemen, oplossingen en bevestigde resultaten tonen;
- [ ] Joris zichtbaar en geloofwaardig als rechtstreeks aanspreekpunt wordt gepresenteerd;
- [ ] iedere belangrijke pagina een logische CTA naar JPWebcreation bevat;
- [ ] contact opnemen eenvoudig en technisch betrouwbaar werkt;
- [ ] diensten overzichtelijk en niet onnodig breed worden gepresenteerd;
- [ ] koopbezwaren via heldere FAQ-antwoorden worden weggenomen;
- [ ] bestaande waardevolle URL's en SEO-signalen zorgvuldig zijn gemigreerd;
- [ ] leadkwaliteit volgens de gekozen cookieloze, handmatige methode kan worden
  beoordeeld; bezoekersanalytics wordt alleen na aparte toestemming toegevoegd;
- [ ] de site inhoudelijk, technisch, responsive en toegankelijk is gecontroleerd;
- [ ] livegang en monitoring aantoonbaar succesvol zijn afgerond.
