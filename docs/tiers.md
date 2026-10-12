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
