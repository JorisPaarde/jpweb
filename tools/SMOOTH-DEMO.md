Smooth Operator-demo

De import leest uitsluitend de bronmap. Maak het uitvoerbestand buiten de webroot:

```sh
php tools/import_smooth_demo.php /workspace/reports/geo/smooth-operator /tmp/smooth-demo-result.json
```

Maak daarna via de bestaande eenmalige ops-route het account aan. Gebruik een bestaand, beheerd agentmail-adres; het script maakt geen mailbox aan. Het onderstaande adres is het lokale demo-adres, waarvan ontvangst niet is gecontroleerd.

```sh
OPS_ROOT=/workspace/wt-jpweb-demo OPS_JOB=smooth-demo-v2 \
OPS_MODE=create_smooth_demo OPS_EMAIL=smooth-demo@agentmail.to \
OPS_DEMO_FILE=/tmp/smooth-demo-result.json OPS_DEMO_LIB=tools/import_smooth_demo.php \
JPWEB_AI_SCAN_CONFIG=/pad/buiten/webroot/config.php php ops/cleanup.php
```

De ops-route maakt eerst een backup en gebruikt een transactie en een eenmalige marker. Een tweede uitvoering met dezelfde job slaat de aanmaak over; een bestaand account wordt nooit overschreven. Er wordt geen wachtwoord, resetlink of mail aangemaakt. Joris kan later de bestaande wachtwoord-resetflow gebruiken op het gekozen mailboxadres.

Het account heeft een gepauzeerd abonnement. Het dashboard toont alle drie voorstellen en de bestaande cijfers, zonder schrijfrechten of nieuwe scanopdracht. `ready_mail_sent_at` voorkomt dat de mailwachtrij alsnog een uitslagmail verstuurt. De demo toont de huidige set als “Koopvragen, versie 2”. De technische herkomst blijft `core-v2-koop`.

De bronmeting van 12 oktober gebruikt eerdere vraagteksten. De 17 vermeldingen in 113 antwoorden en de voorstellen blijven expliciet als eerdere meting herkenbaar. Acht vervangen vragen krijgen geen oude score; de overige vragen krijgen alleen een score bij exact dezelfde ID en tekst. De bronbestanden worden niet aangepast. De testfixtures zijn een kopie van de vier benodigde bronbestanden, zodat tests geen externe rapportmap nodig hebben.

Lokaal is het account aangemaakt in `/tmp/jpweb-app-ui-demo/smooth.sqlite`, met config in dezelfde map (alleen eigenaar kan lezen). `tests/demo_router.php` ondersteunt `?demo=smooth` met `smooth_id` in het bestaande lokale ids-bestand. Deze router is uitsluitend voor lokale previews. Zonder deze router blijft de gewone login vereist; het demo-account heeft nog geen wachtwoord.

Verificatie: `php tests/run.php` — 132 geslaagd, 0 mislukt. De ops-aanmaak en herhaling zijn lokaal uitgevoerd: één account, één afgeronde scanrij, 25 vragen, drie voorstellen, nul mail. De ingebouwde PHP-previewserver kon in deze sandbox niet luisteren; desktop- en mobiele browsercontrole zijn niet uitgevoerd. Geen deploywijziging en geen push.

Het gevraagde rapport `/workspace/reports/geo/wk42/demo-result.md` kon niet worden geschreven: die map is in deze sandbox alleen-lezen (`Read-only file system`). Dit document bevat de samenvatting als overdraagbaar alternatief.

Ook de gevraagde commit is geblokkeerd door de sandbox: `git add` kan `/workspace/jpweb/.git/worktrees/wt-jpweb-demo/index.lock` niet aanmaken (`Read-only file system`). De wijzigingen staan volledig in de werkmap; er is geen commit gemaakt en niets gepusht. Na toegang tot de Git-metadata kan alles in één commit worden vastgelegd.
