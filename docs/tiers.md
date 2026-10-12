# Tiers-contract (v1)

**Eén bron:** `assets/data/tiers.json`. Vervangt `assets/data/pricing.json` (zelfde veldnamen per tier, plus `id`, `free`, `version`).

| Lezer | Hoe |
|---|---|
| Prijsslider promo (`ai-vindbaarheid/ai-vindbaarheid.js`) | `fetch("/assets/data/tiers.json")` |
| Backend + app (PHP) | `lib/tiers.php` leest `__DIR__.'/../assets/data/tiers.json'` (geen kopie) |
| Drift-test | `tests/tiers_test.php` |

## Regels (invarianten, de drift-test controleert ze)
- `tiers` oplopend op `questions`; `monthly` en `actions` niet-dalend; `id = "q{questions}"`.
- `annual = monthly × annual_months_charged`; `weekly = round_half_up(monthly×12/52)`.
- `compleet[h] = round_half_up(monthly + h × hourly_rate × (1 − bundle_discount))` voor elke `h` in `compleet_hours`.
- Snap: een gevraagd aantal vragen `n` → de eerste tier met `questions ≥ n`. Boven `above_max.from_questions`: prijs = laatste tier + `per_started_100` per begonnen 100; acties = `above_max.actions`; alleen "op aanvraag".
- Waarden (definitief, Joris): 25 €19 · 50 €35 · 75 €49 · 100 €65 · 150 €89 · 200 €109 · 250 €129 · 350 €179 · 500 €239 · 750 €339 · 1000 €429; acties 3,4,5,6,7,8,9,10,12,15,20. Alles excl. btw.

## Account
- Elk account heeft `tier_id`: `"free"` of een `tiers[].id` (of `"custom"` + `custom_questions` voor > 1000).
- **Gratis** (`free`): precies 1 scan, alles alleen-lezen, 1 voorbeeldactie, geen wekelijkse meting.
- **Betaald:** max vragen per meting = `tier.questions`; verbeteracties per kalendermaand = `tier.actions`.
- Limieten komen ALTIJD uit `tiers.json` via `lib/tiers.php`; nergens hardcoden.

## Opslag per account (SQLite, contract voor backend PR #20)
Tier is **per account** (kolommen op `users`), nooit globaal. Migratie via bestaande `ai_scan_ensure_column` (idempotent):

| Kolom | Type | Default | Betekenis |
|---|---|---|---|
| `tier_id` | TEXT NOT NULL | `'free'` | `free` · `q25`…`q1000` · `custom` |
| `custom_questions` | INTEGER NULL | NULL | alleen bij `custom` (> 1000, veelvoud van 100) |
| `subscription_status` | TEXT NOT NULL | `'none'` | `none` · `active` · `canceling` · `past_due` · `ended` |
| `billing_period` | TEXT NULL | NULL | `month` · `year` |
| `period_start` / `period_end` | INTEGER NULL | NULL | unix-tijd huidige periode |
| `pending_tier_id` | TEXT NULL | NULL | geplande downgrade, gaat in op `period_end` |
| `tier_changed_at` | INTEGER NULL | NULL | laatste wijziging |

Historie in nieuwe tabel `subscription_events(id, user_id → users ON DELETE CASCADE, ts, event, from_tier, to_tier, by_role, note)`, `event` ∈ `start|upgrade|downgrade_scheduled|downgrade_applied|cancel|resume|end|admin_set`.
Verbruik in `usage_counters(user_id, month 'YYYY-MM', actions_used, question_edits, PRIMARY KEY(user_id, month))`.

### Effectieve tier (één functie: `ai_scan_effective_tier(array $user, array $tiers): array`)
- `subscription_status ∈ {active, canceling, past_due}` en `tier_id ≠ free` → die tier (custom: vragen = `custom_questions`, prijs = 1000-tier + `per_started_100` per begonnen 100 boven 1000, acties = `above_max.actions`).
- Anders (`none`, `ended`, onbekende `tier_id`) → `free`. Onbekende id = fail closed naar free + log.
- Resultaat: `{id, questions, actions, monthly, read_only, weekly_measurement}`.

### Up/downgrade
- **Upgrade:** direct. `tier_id` = nieuw, limieten direct hoger; acties deze maand = nieuw limiet − al gebruikt (min 0).
- **Downgrade:** gepland: `pending_tier_id` gezet, gaat in op `period_end`. Dan: vragen boven het nieuwe max worden **niet verwijderd** maar `paused` (eigenaar kiest welke; anders de laatst toegevoegde). Gebruikte acties tellen door.
- **Opzeggen:** `canceling` tot `period_end`, dan `ended` → effectief free (laatste uitslag blijft zichtbaar, alleen-lezen, geen meting).
- **past_due:** limieten blijven 14 dagen, daarna `ended`.
- Betaling/Mollie: buiten scope v1; status wordt gezet door beheerder (`admin_set`) of later door een webhook. Geen betaalcode in deze ronde.

### Afdwingen
- Server-side alleen (PHP), nooit alleen in de UI. Vragen toevoegen boven `questions` → `Je abonnement heeft ruimte voor {n} vragen. Kies een groter abonnement of haal een vraag weg.`
- Acties per kalendermaand (Europe/Amsterdam) ≤ `actions`; de runner vraagt `GET api/runner.php?limits=<scan/user>` en krijgt `{questions, actions_remaining}`.
- Free: elke schrijf-endpoint → 403 + de regel `Zit in het abonnement: elke week een nieuwe meting.`

## Drift-test (moet falen bij afwijking)
`tests/tiers_test.php` (in `tests/run.php` + CI): (1) schema-invarianten hierboven; (2) waarden = de definitieve staffel; (3) `ai-vindbaarheid/` bevat geen hardcoded tierbedragen en fetcht `tiers.json`; (4) `assets/data/pricing.json` bestaat niet meer; (5) `ai_scan_effective_tier` levert voor elke tier exact `questions`/`actions` uit het bestand; (6) geen PHP-bestand buiten `lib/tiers.php` noemt tierlimieten letterlijk.
