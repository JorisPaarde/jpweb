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

---

## T01 — Ontbrekende inhoud en keuzes verzamelen

**Status:** [ ]  
**Prioriteit:** P0  
**Doel:** alle informatie verzamelen die niet veilig door een agent kan worden aangenomen.

### Benodigde keuzes van Joris

- [ ] Bevestig de belangrijkste gewenste klantgroep:
  - organisaties met proces- of maatwerkvragen;
  - reguliere mkb-websites;
  - of een duidelijke combinatie van beide.
- [ ] Bevestig welke drie hoofddiensten actief verkocht moeten worden.
- [ ] Beslis of AI zichtbaar als zelfstandige dienst blijft, alleen als mogelijkheid wordt genoemd of voorlopig wordt verwijderd.
- [ ] Lever of selecteer een geschikte portretfoto van Joris.
- [ ] Bevestig welke reactietermijn bij contact mag worden beloofd, bijvoorbeeld één werkdag.
- [ ] Kies de voorkeursroute voor leads: formulier, telefoon, e-mail en eventueel WhatsApp of afspraakplanner.
- [ ] Kies hoe het formulier technisch wordt verzonden. De huidige site is statisch; implementeer geen externe formulierenservice zonder goedkeuring.
- [ ] Bevestig of een globale prijsindicatie of minimumbudget mag worden genoemd.
- [ ] Bevestig realistische doorlooptijden en de werkwijze na het eerste gesprek.
- [ ] Selecteer drie sterke, onafhankelijke klantreviews en controleer de exacte tekst en attributie.
- [ ] Verzamel per hoofdcase minimaal één bevestigd resultaat of concreet effect.

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

- [ ] Alle gekozen teksten, quotes en claims hebben een herleidbare bron of expliciete goedkeuring.
- [ ] Niet-bevestigde cijfers en claims staan gemarkeerd als geblokkeerd en worden niet gepubliceerd.
- [ ] De keuzes zijn vastgelegd voordat afhankelijke taken worden uitgevoerd.

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

### Voorgestelde inhoud

Gebruik dit als uitgangspunt en pas het alleen aan als T01 tot andere keuzes leidt:

- Eyebrow: `Websites en online tools voor ondernemers die willen doorgroeien`
- H1: `Een website die klanten helpt én werk uit handen neemt`
- Hoofdtekst:

  > Loop je vast met aanvragen, bestellingen, beheer of systemen die niet samenwerken? Ik kijk hoe je organisatie werkt en bouw een website of online tool die klanten helpt en jou tijd, fouten en losse handelingen bespaart.

- Primaire CTA: `Vertel waar het knelt`
- Secundaire CTA: `Bekijk wat ik heb gebouwd`
- Microcopy bij de primaire CTA, alleen na bevestiging in T01:

  > Vrijblijvend kennismaken · rechtstreeks contact met Joris · reactie binnen één werkdag

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

**Status:** [ ]  
**Prioriteit:** P0  
**Bestanden:** `index.html`, `styles.css`, nieuw afbeeldingsbestand onder `assets/`

### Doel

Vertrouwen opbouwen door vroeg genoeg te laten zien dat klanten rechtstreeks met Joris samenwerken en dat hij zowel meedenkt als bouwt.

### Aanbevolen positie

Plaats het blok na de belangrijkste cases en vóór de reviews of diensten.

### Inhoudelijke richting

- Kop: `Je werkt rechtstreeks met degene die meedenkt én bouwt`
- Vermeld:
  - Joris is het vaste aanspreekpunt;
  - hij onderzoekt eerst waar het proces knelt;
  - hij kan zowel een heldere WordPress-site als maatwerk bouwen;
  - hij blijft beschikbaar voor beheer en doorontwikkeling.
- Voeg een echte, recente portretfoto toe met een beschrijvende alt-tekst.
- Voeg een CTA toe naar contact.

### Werkzaamheden

- [ ] Gebruik alleen een door Joris goedgekeurde portretfoto.
- [ ] Schrijf een korte tekst van circa 80–130 woorden.
- [ ] Vermijd een lange technische opsomming van frameworks en plugins.
- [ ] Verbind persoonlijke samenwerking expliciet aan het voordeel voor de klant.
- [ ] Voeg een duidelijke contact-CTA toe.

### Acceptatiecriteria

- [ ] Het blok voelt persoonlijk, maar niet als een volledige autobiografie.
- [ ] De tekst maakt duidelijk waarom rechtstreeks contact met Joris waardevol is.
- [ ] De afbeelding is geoptimaliseerd en veroorzaakt geen grote layout shift.
- [ ] Het blok is volledig responsive en toegankelijk.

---

## T04 — Reviews en bewijs overtuigender maken

**Status:** [ ]  
**Prioriteit:** P0  
**Bestanden:** `index.html`, mogelijk `styles.css`

### Doel

Zelfclaims vervangen of ondersteunen met geloofwaardig bewijs van klanten.

### Werkzaamheden

- [ ] Vervang de zes zeer korte quotes bij voorkeur door drie sterkere reviews met meer context.
- [ ] Toon per review indien bevestigd:
  - volledige naam;
  - bedrijf of rol;
  - relevante passage over resultaat, samenwerking of technische kwaliteit;
  - bron, bijvoorbeeld Google-review.
- [ ] Geef voorrang aan reviews die concrete effecten noemen, zoals meer aanvragen, meer sales, minder werk of goed beheer.
- [ ] Overweeg een directe koppeling tussen review en relevante case.
- [ ] Voeg alleen een gemiddelde Google-score of aantal reviews toe als deze actueel en gecontroleerd zijn.
- [ ] Hernoem de huidige `proof-strip` niet tot klantbewijs zolang deze alleen eigen werkwijzeclaims bevat. Presenteer hem dan als `Zo werk ik` of vergelijkbaar.

### Acceptatiecriteria

- [ ] Iedere quote is exact of zorgvuldig als geautoriseerde verkorte quote weergegeven.
- [ ] Iedere quote heeft een herkenbare en correcte afzender.
- [ ] Minimaal twee reviews ondersteunen een concreet zakelijk resultaat of aantoonbare werkwijze.
- [ ] Er wordt geen review gebruikt die bij bezoekers onnodig twijfel kan oproepen wanneer een sterkere onafhankelijke review beschikbaar is.

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

**Status:** [ ]  
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

- [ ] Controleer iedere intro op een duidelijke probleem-resultaatrelatie.
- [ ] Maak lange featurelijsten ondergeschikt aan het resultaat.
- [ ] Voeg per case een zichtbaar blok `Resultaat` of `Wat dit veranderde` toe.
- [ ] Gebruik cijfers alleen na bevestiging in T01.
- [ ] Gebruik zonder cijfers alleen concrete, verifieerbare effecten.
- [ ] Voeg waar mogelijk één relevante klantquote toe.
- [ ] Behoud technische termen alleen wanneer ze de complexiteit of deskundigheid begrijpelijk maken.

### Acceptatiecriteria

- [ ] Iedere case beantwoordt duidelijk: wat was het probleem, wat deed Joris en wat leverde dat op?
- [ ] Iedere gepubliceerde resultaatclaim is bevestigd.
- [ ] De belangrijkste waarde is scanbaar zonder alle alinea's te lezen.
- [ ] Geen twee cases gebruiken vrijwel dezelfde algemene resultaattekst.

---

## T07 — Diensten terugbrengen tot duidelijke ingangen

**Status:** [ ]  
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

- [ ] Vervang of hergroepeer de huidige zes diensten.
- [ ] Maak bij iedere hoofddienst duidelijk voor welk probleem deze bedoeld is.
- [ ] Benoem expliciet dat een gewone, professionele website ook mogelijk is als maatwerk niet nodig is.
- [ ] Verplaats koppelingen, WooCommerce, AI en vergelijkbare mogelijkheden naar ondersteunende voorbeelden.
- [ ] Toon AI alleen als zelfstandige dienst na een bewuste keuze in T01 en met voldoende bewijs.
- [ ] Voeg per hoofddienst een passende route naar cases of contact toe.

### Acceptatiecriteria

- [ ] Een bezoeker kan binnen tien seconden kiezen welke hoofddienst bij zijn vraag past.
- [ ] De sectie straalt geen onnodige complexiteit uit naar klanten die alleen een goede website nodig hebben.
- [ ] De maatwerkcapaciteit blijft duidelijk zichtbaar.
- [ ] De drie diensten overlappen inhoudelijk zo min mogelijk.

---

## T08 — Contactsectie en contactformulier verbeteren

**Status:** [~]  
**Prioriteit:** P0  
**Afhankelijk van:** T01  
**Bestanden:** minimaal `index.html`, `styles.css`, mogelijk `script.js` en een goedgekeurde server- of formulierintegratie

### Doel

Contact opnemen laagdrempelig maken voor bezoekers die nog geen uitgewerkt plan hebben.

De contacttekst en rechtstreekse contactroutes zijn aangescherpt. Een formulier blijft geblokkeerd totdat een verzendmethode is gekozen en getest.

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

- [ ] Kies eerst een goedgekeurde verzendmethode voor de statische site.
- [ ] Voeg een kort formulier toe zonder onnodige verplichte velden.
- [ ] Voeg duidelijke fout-, verzend- en succesmeldingen toe.
- [ ] Bescherm het formulier passend tegen spam zonder bezoekers onnodig te hinderen.
- [ ] Link naar de privacyverklaring.
- [ ] Behoud telefoon en e-mail als alternatieve contactroutes.
- [ ] Voeg alleen een reactietermijn toe als deze in T01 is bevestigd.
- [ ] Log geen gevoelige formulierinhoud in analytics.

### Acceptatiecriteria

- [ ] Een formulierinzending komt aantoonbaar op de juiste plek aan.
- [ ] De bezoeker krijgt na verzenden een duidelijke bevestiging.
- [ ] Het formulier werkt met toetsenbord en schermlezer en heeft correcte labels.
- [ ] Validatiefouten wissen geen reeds ingevulde inhoud.
- [ ] E-mailadressen, sleutels en secrets staan niet in client-side JavaScript of in de repository.
- [ ] De primaire CTA's op homepage en cases leiden naar deze contactmogelijkheid.

---

## T09 — Veelgestelde vragen toevoegen

**Status:** [ ]  
**Prioriteit:** P1  
**Afhankelijk van:** T01  
**Bestanden:** `index.html`, mogelijk `styles.css`

### Doel

Belangrijke twijfels wegnemen voordat een potentiële klant contact opneemt.

### Onderwerpen

- [ ] Kun je ook een bestaande website verbeteren?
- [ ] Bouw je alleen maatwerk of ook een reguliere bedrijfswebsite?
- [ ] Werk je alleen met WordPress?
- [ ] Kan ik de website zelf beheren?
- [ ] Kun je hosting, onderhoud en doorontwikkeling verzorgen?
- [ ] Hoe verloopt een traject na het eerste gesprek?
- [ ] Wat kost een website of online tool ongeveer?
- [ ] Hoe lang duurt een gemiddeld traject?

Publiceer alleen antwoorden die door Joris zijn bevestigd. Als een vaste prijs of doorlooptijd niet passend is, leg dan helder uit waarvan deze afhangt en wat de bezoeker na een eerste gesprek ontvangt.

### Acceptatiecriteria

- [ ] De FAQ bevat alleen vragen die koopbezwaren wegnemen.
- [ ] De antwoorden zijn kort, concreet en controleerbaar.
- [ ] Eventuele uitklapfunctionaliteit werkt zonder muis en heeft correcte ARIA-statussen.
- [ ] Eventuele FAQ-structured data komt exact overeen met zichtbare content.

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
- [ ] Controleer unieke page titles en meta descriptions.
- [ ] Voeg relevante Open Graph- en social metadata toe.
- [x] Maak een XML-sitemap met alleen canonieke, indexeerbare productie-URL's.
- [x] Maak een passende `robots.txt` die naar de sitemap verwijst.
- [~] Zorg dat de stagingomgeving `test.jpwebcreation.nl` niet in zoekmachines wordt geïndexeerd, bij voorkeur via een omgevingsspecifieke `X-Robots-Tag: noindex, nofollow` of gelijkwaardige serverinstelling. De hostafhankelijke header staat in `.htaccess`, maar moet na deployment op de server worden gecontroleerd.
- [ ] Blokkeer productie niet per ongeluk wanneer de stagingblokkade wordt ingesteld.
- [x] Maak een bruikbare 404-pagina met routes naar homepage, cases en contact.
- [ ] Voeg alleen structured data toe die overeenkomt met zichtbare en verifieerbare inhoud.

### Acceptatiecriteria

- [ ] Oude URL's geven rechtstreeks één 301 naar de juiste nieuwe URL.
- [ ] Productiepagina's geven 200 en hebben een zelfverwijzende canonical.
- [ ] Staging geeft aantoonbaar een `noindex`-signaal en productie niet.
- [ ] `sitemap.xml` en `robots.txt` geven 200 en bevatten productie-URL's.
- [ ] Geen interne link verwijst naar een oude of omgeleide URL.
- [ ] Er zijn geen onbedoelde 404's in een volledige crawl.

---

## T12 — Meting van aanvragen en CTA's inrichten

**Status:** [ ]  
**Prioriteit:** P1  
**Afhankelijk van:** T01 en T08

### Doel

Kunnen vaststellen of de nieuwe site daadwerkelijk meer en betere aanvragen oplevert.

### Te meten conversies

- [ ] Succesvolle formulierinzending.
- [ ] Klik op telefoonnummer.
- [ ] Klik op e-mailadres.
- [ ] Klik op primaire hero-CTA.
- [ ] Klik op CTA onder een case.
- [ ] Eventueel klik naar een afspraakplanner of WhatsApp als die wordt toegevoegd.

### Werkzaamheden

- [ ] Leg vóór livegang een nulmeting vast van huidig verkeer en huidige aanvragen, voor zover beschikbaar.
- [ ] Gebruik de bestaande analytics-oplossing of vraag toestemming voordat een nieuwe wordt toegevoegd.
- [ ] Definieer duidelijke eventnamen en documenteer deze.
- [ ] Markeer een formulier pas als conversie na bevestigde succesvolle verzending.
- [ ] Controleer privacy- en cookietoestemming voordat niet-noodzakelijke tracking wordt geladen.
- [ ] Maak onderscheid tussen totaal aantal leads en geschikte leads.

### Acceptatiecriteria

- [ ] Iedere afgesproken conversie is in een test aantoonbaar zichtbaar.
- [ ] Dubbele events worden voorkomen.
- [ ] Persoonsgegevens en berichtinhoud worden niet naar analytics gestuurd.
- [ ] Er is vastgelegd hoe na 30 en 90 dagen wordt beoordeeld of de site beter presteert.

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

- [ ] Start de lokale preview volgens `AGENTS.md`.
- [ ] Test alle navigatie-, telefoon-, mail-, case-, externe en privacy-links.
- [ ] Test het mobiele menu met muis, toetsenbord en touch.
- [ ] Test het contactformulier volledig, inclusief foutscenario's.
- [ ] Controleer console en netwerkverzoeken op fouten.
- [ ] Controleer dat externe links veilig worden geopend.

### Responsive en toegankelijkheid

- [ ] Controleer minimaal 320, 375, 768, 1024 en 1440 pixels breed.
- [ ] Controleer focusstijlen, contrast, headingvolgorde en landmarkstructuur.
- [ ] Controleer alt-teksten en voorkom decoratieve ruis voor schermlezers.
- [ ] Controleer carrousels met `prefers-reduced-motion`.
- [ ] Controleer dat belangrijke inhoud zonder animatie of JavaScript bereikbaar blijft.

### Performance

- [ ] Optimaliseer nieuwe afbeeldingen.
- [ ] Controleer layout shifts, laadtijd en mobiele leesbaarheid.
- [ ] Voer een Lighthouse- of gelijkwaardige controle uit en los regressies met hoge impact op.

### Acceptatiecriteria

- [ ] Geen bekende kapotte links, consolefouten of formulierfouten.
- [ ] Geen onbevestigde inhoud staat live.
- [ ] Alle P0-taken zijn aantoonbaar afgerond.
- [ ] De site is bruikbaar met toetsenbord en op kleine mobiele schermen.
- [ ] Eventuele resterende punten zijn expliciet gedocumenteerd.

---

## T14 — Livegang en controle na livegang

**Status:** [ ]  
**Prioriteit:** P0  
**Afhankelijk van:** T10–T13 en expliciete toestemming van Joris

### Voor livegang

- [ ] Maak of bevestig een herstelmogelijkheid van de huidige productieomgeving.
- [ ] Controleer dat de te deployen commit exact de goedgekeurde versie is.
- [ ] Controleer redirectregels, formulieren, sitemap, canonicals en productie-robots.
- [ ] Bevestig dat analytics en cookietoestemming correct zijn ingericht.
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
- [ ] conversies meetbaar zijn zonder onnodige verwerking van persoonsgegevens;
- [ ] de site inhoudelijk, technisch, responsive en toegankelijk is gecontroleerd;
- [ ] livegang en monitoring aantoonbaar succesvol zijn afgerond.
