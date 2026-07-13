# URL-migratie JPWebcreation

Definitieve één-hop redirectkaart voor de overgang van de oude WordPress-site
naar de statische site. De regels staan in `.htaccess`, passend bij de huidige
Apache/LiteSpeed-stack.

## Bronnen

- `site:jpwebcreation.nl`-zoekopdrachten, opnieuw uitgevoerd op 13 juli 2026;
- de eerder geëxporteerde `page-sitemap.xml` en `post-sitemap.xml` van de oude
  WordPress-site: samen 47 URL's (3 pagina's en 44 berichten/projecten);
- de publieke routes en bestanden in deze repository.

De zoekopdracht toont momenteel de homepage en de oude contactpagina. De
redirects dekken daarnaast bewust alle 47 eerder bekende WordPress-URL's af,
zodat een nog niet zichtbaar of later opnieuw gecrawld oud resultaat niet op
een 404 uitkomt.

## Bestaande URL's die 200 blijven geven

| URL | Actie |
| --- | --- |
| `/` | Behouden |
| `/projecten/archipel-bouwadvies/` | Behouden |

## Definitieve redirecttabel

Alle bestemmingen hieronder zijn definitieve live routes. Geen enkele regel
verwijst naar een andere legacy-URL.

| Oude URL | Nieuwe URL |
| --- | --- |
| `/contact-voor-hulp-met-jouw-website/` | `/#contact` |
| `/projecten/website-mikes-pianoshow/` | `/projecten/mikes-pianoshow/` |
| `/projecten/website-wildfloweroffice/` | `/projecten/wildfloweroffice/` |
| `/blog/` | `/` |
| `/general/nieuwe-site-laten-bouwen/` | `/` |
| `/general/snel-website-laten-ontwikkelen/` | `/` |
| `/general/professioneel-webdesign-laten-maken/` | `/` |
| `/general/professionele-website-laten-bouwen/` | `/` |
| `/general/webdesign-laten-maken/` | `/` |
| `/general/professionele-webdesign/` | `/` |
| `/general/website-maken-met-squarespace-of-elementor/` | `/` |
| `/general/betaalbare-website-laten-maken/` | `/` |
| `/general/complete-website-laten-maken/` | `/` |
| `/general/internetsite-laten-maken/` | `/` |
| `/general/website-maken/` | `/` |
| `/general/professioneel-webdesign/` | `/` |
| `/general/eigen-professionele-website-maken/` | `/` |
| `/general/betaalbare-website-laten-ontwikkelen/` | `/` |
| `/general/betaalbare-website-laten-bouwen/` | `/` |
| `/general/eenvoudige-website-laten-bouwen/` | `/` |
| `/general/betaalbaar-webdesign/` | `/` |
| `/general/een-professioneel-website-laten-ontwerpen/` | `/` |
| `/general/een-website-laten-bouwen/` | `/` |
| `/uncategorized/maken-van-website/` | `/` |
| `/general/snel-website-laten-bouwen/` | `/` |
| `/general/nieuwe-website-laten-maken-prijs/` | `/` |
| `/general/professionele-website-laten-maken-prijs/` | `/` |
| `/general/site-laten-maken-prijs/` | `/` |
| `/general/prijzen-webdesign/` | `/` |
| `/general/kosten-professionele-website-laten-maken/` | `/` |
| `/general/professionele-webshop-laten-bouwen/` | `/` |
| `/general/webdesigner-webshop/` | `/` |
| `/general/professionele-webshop-bouwen/` | `/` |
| `/general/webshop-laten-ontwerpen/` | `/` |
| `/general/webshop-laten-ontwikkelen/` | `/` |
| `/general/website-teksten-schrijven/` | `/` |
| `/general/waarom-het-belangrijk-is-om-je-website-goed-te-onderhouden/` | `/` |
| `/general/effectief-communiceren-met-je-website/` | `/` |
| `/general/verbeter-seo-door-relevante-content/` | `/` |
| `/general/hulp-bij-uw-website-nodig-wij-staan-direct-voor-u-klaar/` | `/` |
| `/uncategorized/professionele-website-laten-maken-haarlem/` | `/` |
| `/stad/professionele-website-laten-maken-heemstede/` | `/` |
| `/stad/professionele-website-laten-maken-haarlem-2/` | `/` |
| `/stad/professionele-website-laten-maken-hoofddorp/` | `/` |
| `/stad/professionele-website-laten-maken-beverwijk/` | `/` |

De oude WordPress-sitemaproutes gaan rechtstreeks naar de nieuwe sitemap:

| Oude URL | Nieuwe URL |
| --- | --- |
| `/page-sitemap.xml` | `/sitemap.xml` |
| `/post-sitemap.xml` | `/sitemap.xml` |
| `/sitemap_index.xml` | `/sitemap.xml` |
| `/wp-sitemap.xml` | `/sitemap.xml` |

## Geïmplementeerde Apache/LiteSpeed-regels

```apache
Redirect 301 /contact-voor-hulp-met-jouw-website/ /#contact
Redirect 301 /projecten/website-mikes-pianoshow/ /projecten/mikes-pianoshow/
Redirect 301 /projecten/website-wildfloweroffice/ /projecten/wildfloweroffice/
Redirect 301 /page-sitemap.xml /sitemap.xml
Redirect 301 /post-sitemap.xml /sitemap.xml
Redirect 301 /sitemap_index.xml /sitemap.xml
Redirect 301 /wp-sitemap.xml /sitemap.xml
RedirectMatch 301 ^/stad(?:/.*)?$ /
RedirectMatch 301 ^/(?:blog|general|uncategorized)(?:/.*)?$ /
```

## Acceptatiecontrole na productiedeploy

Controleer iedere exacte URL uit de tabel met redirects uitgeschakeld in de
HTTP-client:

1. eerste response is `301`;
2. `Location` is exact de bestemming uit de tabel;
3. de bestemming geeft `200`;
4. maximaal één redirect voordat de bestemming wordt bereikt;
5. geen oude URL geeft `404`.

De regels zijn in de repository gecontroleerd, maar zijn pas productiegetest
nadat de handmatige productiedeploy is uitgevoerd.
