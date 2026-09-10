# Blogredactie JPWebcreation

De blog helpt ondernemers een concrete keuze maken over werk dat JPWebcreation uitvoert. De lezer hoeft niet vooraf te weten welke techniek nodig is. Intake Engine past specifiek bij airco-opnames.

## Taak per artikel

| Artikel | Lezersvraag | Passende vervolgstap |
| --- | --- | --- |
| [WordPress verbeteren of opnieuw bouwen](wordpress-website-verbeteren-of-opnieuw-laten-maken.html) | Kan mijn bestaande website worden verbeterd? | Websites of onderhoud bespreken met Joris |
| [Excel als bedrijfssysteem](excel-als-bedrijfssysteem-mkb.html) | Wanneer houdt een spreadsheet op te passen bij het werk? | Bestaande software, online tool of aanpassing beoordelen |
| [Gegevens overtypen](waarom-typt-iemand-dit-nog-over-mkb.html) | Welke overdracht kan een koppeling overnemen? | Programma's, gegevens en uitzonderingen bespreken |
| [Offerteproces](offerteproces-automatiseren-zonder-chaos.html) | Hoe krijg ik een aanvraag waarop ik kan offreren? | Aanvraagformulier of offertemodule; bij airco een opnamedossier |
| [Tijd voor vooropnames](hoeveel-tijd-kost-een-vooropname-installatiebedrijf.html) | Waar gaat de tijd vóór een airco-offerte naartoe? | Intake Engine-demo en passende pilot |
| [Informatie voor de monteur](monteur-krijgt-klus-via-whatsapp-mkb.html) | Hoe blijven foto's en afspraken bij de opdracht? | Airco-opnamedossier of een passende overdracht op maat |

## Bij schrijven en aanpassen

- Lees eerst `../CONTEXT.md` en gebruik controleerbare projectdetails.
- Geef één artikel één hoofdvraag en één primaire vervolgactie.
- Houd aanvragen verzamelen, gegevens overzetten, systeemkeuze, opnametijd en werkoverdracht inhoudelijk verschillend.
- Gebruik Haveka als voorbeeld van een offertemodule, Mike voor gefaseerde uitbreiding en Supper voor beheer en doorontwikkeling. Verzin geen resultaten of migraties.
- Intake Engine is een opnamedossier voor airco-installateurs. De klant, installateur of beiden vullen aan. De installateur beoordeelt; beloof geen automatische diagnose, complete planning of het verdwijnen van alle bezoeken.
- Link naar `https://intake-engine.nl/` voor productinformatie en `https://intake-engine.nl/#demo` voor het demoblok. Externe links openen met `target="_blank" rel="noopener"`.
- Voeg een nieuw artikel toe aan `index.html`, `../sitemap.xml` en een inhoudelijk relevante bestaande pagina. Alleen een kaart op de blogindex is onvoldoende redactionele inbedding.
- Laat bestaande URL's intact bij tekstverbeteringen. Werk titel, description, Open Graph, Twitter, JSON-LD en zichtbare datum consequent bij. Verander `datePublished` niet bij een update.
- Gebruik Search Console met een expliciete meetperiode. Geen gerapporteerde rij is geen bewijs van nul vraag of slechte kwaliteit. Controleer ook URL-inspectie en actualiteit van de gegevens.
- Beoordeel niet alleen vertoningen, maar ook passende zoekopdrachten en, waar meetbaar, gesprekken, demo's en pilots. Een klik in Search Console is geen lead.
- Houd rapportages met accountcijfers buiten deze publieke repository.

## Publiceren en controleren

Volg `../AGENTS.md`: push naar `main` publiceert op test. Productie gaat via de handmatige `Deploy site`-workflow. Controleer de workflow en de werkelijke pagina's, links en metadata voordat je een geslaagde livegang meldt.
