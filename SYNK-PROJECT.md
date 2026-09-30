# Synk by Jeanette — 30 september 2026

Nieuwe case: `/projecten/synk-by-jeanette/`, toegevoegd bij de websitevoorbeelden
op de homepage en als zevende beeld in de headeranimatie.

## Bronnen en beelden

- Projectcontext gelezen in Notion: `synk by jeanette` (klanten en huidig werk).
- Publieke inhoud en credits gecontroleerd op https://www.synkbyjeanette.com/.
- Drie echte screenshots van de Nederlandse homepage, 1440 × 1000 pixels,
  gemaakt nadat de laad- en scrollanimaties zichtbaar waren:
  1. Portret en merkintroductie, bovenaan.
  2. Goudkleurige golf, `Regie zonder ruis`, persoonlijke introductie.
  3. `Business / Humor`, typografie en bewegende dienstenstrook.
- Alleen homepagesecties gebruikt, conform de keuze van Joris; geen beelden van
  de diensten- of FAQ-pagina.
- PNG-originelen, WebP-versies en responsive homepagevarianten staan in
  `assets/projects/synk-by-jeanette-*`.
- Credits onderscheiden webdevelopment van design, strategie en copy.
- Geen omzet-, conversie- of andere meetbare resultaatclaims toegevoegd.

## Controles

- Alle 19 sitemaproutes gecontroleerd op 320, 375, 768, 1024 en 1440 pixels.
- Homepage en Synk-case: geen horizontale overflow op deze breedtes.
- Geen kapotte afbeeldingen of JavaScript-uitvoeringsfouten gevonden.
- Nieuwe case, homepagevermelding en Synk in de header visueel gecontroleerd.
- Mobiel menu opent en sluit; reduced motion schakelt headeranimatie uit.
- Interne links en bestanden, JSON-LD, sitemapmetadata, CSS-accolades en gedeelde
  cacheversies gecontroleerd. `git diff --check` slaagt.
- De nieuwe route is toegevoegd aan beide deployment-smokechecks.
- Bestaande overflow op 320 pixels waargenomen bij `/blog/` en
  `/projecten/wildfloweroffice/`; deze pagina's kregen alleen de gedeelde
  CSS-cacheversie. Geen inhoudelijke wijziging aan die pagina's.
- De lokale preview voert geen PHP uit; e-mailbezorging is hiermee niet getest.
