# URL-migratie JPWebcreation

Feitelijke URL-inventaris voor de overgang van de huidige WordPress-site naar de statische site in deze repository.

## Bronnen en beperkingen

- Opgehaald op 12 juli 2026 uit:
  - `https://jpwebcreation.nl/page-sitemap.xml`
  - `https://jpwebcreation.nl/post-sitemap.xml`
- De twee sitemaps bevatten samen 47 pagina-URL's: 3 pagina's en 44 berichten.
- Op 13 juli 2026 toont `https://jpwebcreation.nl/` nog de bestaande
  WordPress-site. De statische site uit deze repository wordt automatisch naar
  test gedeployed; productie blijft een afzonderlijke handmatige deploy.
- Verkeer, vertoningen, conversies en backlinks zijn nog niet bekend. In dit document worden daar geen aannames over gedaan.
- Een voorgestelde redirect wordt pas definitief nadat Joris de mapping heeft goedgekeurd.
- Redirect oude inhoud niet zonder inhoudelijke overeenkomst naar de homepage.

## Statuslegenda

- `BEHOUDEN`: pad bestaat ook in de nieuwe site.
- `301 VOORGESTELD`: inhoudelijk logische bestemming, nog goed te keuren.
- `301 IN REPO`: redirectregel staat in `.htaccess`, maar moet na de
  productiedeploy nog op statuscode en bestemming worden getest.
- `DATA NODIG`: eerst Search Console, analytics en eventuele backlinks beoordelen.
- `NIEUW`: bestaat alleen in de nieuwe statische site; geen oude URL te migreren.

## Directe en project-URL's

| Oude URL | Status | Voorgestelde nieuwe URL | Toelichting |
| --- | --- | --- | --- |
| `/` | BEHOUDEN | `/` | Homepage blijft het hoofdpunt. |
| `/contact-voor-hulp-met-jouw-website/` | 301 VOORGESTELD | `/#contact` | Nieuwe homepage bevat het contactgedeelte. Goedkeuring nodig. |
| `/projecten/website-mikes-pianoshow/` | 301 IN REPO | `/projecten/mikes-pianoshow/` | Dezelfde case met een nieuw pad; regel staat in `.htaccess`. |
| `/projecten/website-wildfloweroffice/` | 301 IN REPO | `/projecten/wildfloweroffice/` | Dezelfde case met een nieuw pad; regel staat in `.htaccess`. |
| `/projecten/archipel-bouwadvies/` | BEHOUDEN | `/projecten/archipel-bouwadvies/` | Pad blijft gelijk. |

## Nieuwe cases zonder oude URL in de sitemaps

| Nieuwe URL | Status |
| --- | --- |
| `/projecten/haveka-installatie/` | NIEUW |
| `/projecten/supper-services/` | NIEUW |
| `/projecten/ec-eens/` | NIEUW |

## Blogoverzicht

| Oude URL | Status | Benodigde beslissing |
| --- | --- | --- |
| `/blog/` | DATA NODIG | Blog behouden, vervangen door een kennisoverzicht of redirecten naar een relevante nieuwe pagina. |

## Algemene website- en webdesignartikelen

Deze pagina's overlappen inhoudelijk mogelijk sterk. Bepaal met Search Console en backlinks welke pagina's waarde hebben. Voeg waardevolle informatie daarna samen tot een beperkt aantal sterke dienst- of kennispagina's.

| Oude URL | Status |
| --- | --- |
| `/general/nieuwe-site-laten-bouwen/` | DATA NODIG |
| `/general/snel-website-laten-ontwikkelen/` | DATA NODIG |
| `/general/professioneel-webdesign-laten-maken/` | DATA NODIG |
| `/general/professionele-website-laten-bouwen/` | DATA NODIG |
| `/general/webdesign-laten-maken/` | DATA NODIG |
| `/general/professionele-webdesign/` | DATA NODIG |
| `/general/website-maken-met-squarespace-of-elementor/` | DATA NODIG |
| `/general/betaalbare-website-laten-maken/` | DATA NODIG |
| `/general/complete-website-laten-maken/` | DATA NODIG |
| `/general/internetsite-laten-maken/` | DATA NODIG |
| `/general/website-maken/` | DATA NODIG |
| `/general/professioneel-webdesign/` | DATA NODIG |
| `/general/eigen-professionele-website-maken/` | DATA NODIG |
| `/general/betaalbare-website-laten-ontwikkelen/` | DATA NODIG |
| `/general/betaalbare-website-laten-bouwen/` | DATA NODIG |
| `/general/eenvoudige-website-laten-bouwen/` | DATA NODIG |
| `/general/betaalbaar-webdesign/` | DATA NODIG |
| `/general/een-professioneel-website-laten-ontwerpen/` | DATA NODIG |
| `/general/een-website-laten-bouwen/` | DATA NODIG |
| `/uncategorized/maken-van-website/` | DATA NODIG |
| `/general/snel-website-laten-bouwen/` | DATA NODIG |

## Prijsartikelen

| Oude URL | Status |
| --- | --- |
| `/general/nieuwe-website-laten-maken-prijs/` | DATA NODIG |
| `/general/professionele-website-laten-maken-prijs/` | DATA NODIG |
| `/general/site-laten-maken-prijs/` | DATA NODIG |
| `/general/prijzen-webdesign/` | DATA NODIG |
| `/general/kosten-professionele-website-laten-maken/` | DATA NODIG |

## Webshopartikelen

| Oude URL | Status |
| --- | --- |
| `/general/professionele-webshop-laten-bouwen/` | DATA NODIG |
| `/general/webdesigner-webshop/` | DATA NODIG |
| `/general/professionele-webshop-bouwen/` | DATA NODIG |
| `/general/webshop-laten-ontwerpen/` | DATA NODIG |
| `/general/webshop-laten-ontwikkelen/` | DATA NODIG |

## Content, SEO, onderhoud en hulp

| Oude URL | Status |
| --- | --- |
| `/general/website-teksten-schrijven/` | DATA NODIG |
| `/general/waarom-het-belangrijk-is-om-je-website-goed-te-onderhouden/` | DATA NODIG |
| `/general/effectief-communiceren-met-je-website/` | DATA NODIG |
| `/general/verbeter-seo-door-relevante-content/` | DATA NODIG |
| `/general/hulp-bij-uw-website-nodig-wij-staan-direct-voor-u-klaar/` | DATA NODIG |

## Lokale landingspagina's

Deze pagina's niet verwijderen of samenvoegen zonder eerst lokale zoekvertoningen, verkeer en aanvragen te controleren.

| Oude URL | Status |
| --- | --- |
| `/uncategorized/professionele-website-laten-maken-haarlem/` | DATA NODIG |
| `/stad/professionele-website-laten-maken-heemstede/` | DATA NODIG |
| `/stad/professionele-website-laten-maken-haarlem-2/` | DATA NODIG |
| `/stad/professionele-website-laten-maken-hoofddorp/` | DATA NODIG |
| `/stad/professionele-website-laten-maken-beverwijk/` | DATA NODIG |

## Nog benodigde gegevens

- [ ] Exporteer uit Google Search Console per pagina de laatste 12–16 maanden:
  - klikken;
  - vertoningen;
  - gemiddelde positie;
  - belangrijkste zoekopdrachten.
- [ ] Controleer organische landingen en conversies in de beschikbare analytics.
- [ ] Controleer backlinks naar oude pagina's.
- [ ] Bevestig welke diensten en plaatsen commercieel belangrijk blijven.
- [ ] Keur iedere `301 VOORGESTELD`-mapping goed.
- [ ] Kies per `DATA NODIG`-URL: behouden, herschrijven, samenvoegen, 301 of bewust verwijderen.

## Redirects die al in de statische site staan

```apache
Redirect 301 /projecten/website-mikes-pianoshow/ /projecten/mikes-pianoshow/
Redirect 301 /projecten/website-wildfloweroffice/ /projecten/wildfloweroffice/
```

Deze regels zijn nog niet aangemerkt als productiegetest. Controleer na de
handmatige productiedeploy met `curl -I` dat iedere oude URL exact één `301`
geeft en rechtstreeks naar de genoemde nieuwe URL verwijst.

De contactredirect wordt pas toegevoegd na goedkeuring van de bestemming. De
overige URL's wachten op data en een inhoudelijke beslissing.
