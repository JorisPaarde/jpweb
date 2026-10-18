# ai-scan API v1 (B1 formulier ↔ B2 PHP ↔ B3 runner)

## Formulier → `/ai-scan.php`

`POST /ai-scan.php` (form-urlencoded):

| Veld | Verplicht | Opmerking |
|------|-----------|-----------|
| `email` | ja | genormaliseerd (lowercase; Gmail: punten/`+tag` weg) |
| `website` | ja | host wordt genormaliseerd; bereikbaarheid wordt gecheckt |
| `consent` | ja | `1` of `accepted` (of legacy `privacy=accepted`) |
| `hp_field` | honeypot | moet leeg; alias `bedrijfsfax` ook gehonoreerd |
| `csrf` | ja | uit `GET /ai-scan.php?action=csrf` of `?action=state` |

Antwoord: altijd **303** naar `/ai-scan/?s=<code>` (of JSON `{ok, code}` als `Accept: application/json` / `format=json`).

Bij `rate_limited`: **HTTP 429** + `Retry-After`, en `Location: /ai-scan/?s=rate_limited` (JSON: `{ok:false, code:"rate_limited", retry_after}`).

Codes:

| Code | Betekenis |
|------|-----------|
| `check_mail` | Bevestigingsmail verstuurd (ook bij ingevulde honeypot) |
| `invalid` | E-mail/website/toestemming afgewezen — **één neutrale melding**, geen onderscheid welke regel faalde (regel alleen in server-log) |
| `already_scanned` | Dit e-mailadres of domein had al een (gratis) scan; verraadt geen e-mail |
| `daily_full` | Aanvraag geaccepteerd, maar het dagplafond is vol (wachtlijst na bevestiging) |
| `rate_limited` | Te veel pogingen (IP / e-mail / domein / burst / bevestigingsmail-throttle) → **429** |
| `error` | Generieke fout / verlopen CSRF |
| `confirmed` | Alleen na bevestigingslink |
| `deleted` | Account verwijderd (AVG) |

Publieke fouttekst bij `invalid`: *Controleer je e-mailadres en je website en probeer het opnieuw.*

Signup-regels (intern gelogd als `rule=`):

1. Strikte e-mailsyntax (`filter_var` + regex: geen rare tekens, geen `..`, TLD ≥ 2 letters)
2. MX/DNS op het e-maildomein (`checkdnsrr` MX, anders A/AAAA)
3. Geblokkeerde e-mail-TLD’s (`email_blocked_tlds`, default `ru su by in cn tk top xyz buzz`)
4. Wegwerp-maildomeinen (bestand + `disposable_email_domains`)
5. Website-DNS moet resolven; toegestane website-TLD’s (`website_allowed_tlds`, default `nl be eu com`)

Hulptijnen:

- `GET /ai-scan.php?action=csrf` → `{csrf}`
- `GET /ai-scan.php?action=state` → `{values:{email,website}, errors:{}, csrf}` (eenmalig, ≤10 min; geen honeypot/consent)

`GET /ai-scan.php?confirm=<token>` (eenmalig, 24 u) → status bevestigd, job in queue, account aangemaakt, set-password-mail → **303** `/ai-scan/?s=confirmed`.

## Account / wachtwoord / dashboard

- `GET /ai-scan/wachtwoord/?t=<token>` (eenmalig, 24 u) → gebruiker kiest zelf een wachtwoord. **NOOIT** een wachtwoord per mail. Opslag via `password_hash`.
- `GET|POST /login/` — PHP-sessie (`jpweb_ai_scan`), secure cookies, CSRF, login rate limit.
- `GET /logout.php`
- `GET /dashboard/` — uitslag van de eigen scan (HTML van de runner, of wachtstatus). `?format=json` voor status/JSON-resultaat.
- `POST /account-delete.php` — AVG: wist account + PII; bewaart alleen e-mail-/domein-hashes zodat de gratis scan niet opnieuw kan.

## Queue / opslag

SQLite **buiten de webroot** (pad via config; fallback `../private/ai-scan.sqlite`).

Queue-record (conceptueel):

```json
{
  "id": 1,
  "public_id": "…",
  "email_hash": "…",
  "email": "…",
  "domain": "example.nl",
  "status": "onbevestigd|bevestigd|wacht_dag|bezig|klaar|mislukt",
  "created": 0,
  "confirmed": 0,
  "dashboard_path": "/dashboard/"
}
```

Regels (defaults; alles via config overschrijfbaar):

- 1 gratis scan per e-mail **én** per domein
- dagplafond **20** bevestigde gratis scans (`daily_free_scans`); overflow → `wacht_dag`
- rate limit per IP: **3/uur** (`form_ip_max_per_hour`), plus dagplafond
- rate limit per e-mail en per domein (`form_email_max_per_hour`, `form_domain_max_per_hour`)
- korte burst: herhaalde requests binnen `form_burst_seconds` → **429**
- bevestigingsmail: max **1 per e-mail per 10 min** + globaal uurplafond (`confirm_mail_*`)
- strikte e-mail- + websitevalidatie (publiek altijd `invalid`; regels in server-log)
- eerste scan gratis; daarna alleen abonnement (`already_scanned`)
- kostenplafond €0,60/scan is runner-side (B3)

## Runner API (B3 box) — `/api/runner.php`

Auth (verplicht):

```http
Authorization: Bearer <runner_token>
```

of header `X-Runner-Token: <runner_token>`. Token komt uit de serverconfig (`runner_token`), nooit uit git.

Alle antwoorden zijn JSON.

### `GET|POST /api/runner.php?action=next`

Geeft de volgende job met status `bevestigd` (na eventuele waitlist-promotie). Roept ook de stale-job check aan.

```json
{ "ok": true, "job": { "id": 12, "public_id": "…", "email": "…", "website_url": "https://…/", "domain": "…", "status": "bevestigd", "queued_at": 0 } }
```

`job` is `null` als de queue leeg is.

### `POST /api/runner.php` body `{"action":"claim","id":12}`

Claimt de job → status `bezig`. **409** als niet claimbaar.

```json
{ "ok": true, "job": { "id": 12, "status": "bezig", "claimed_at": 0, "…": "…" } }
```

### `POST /api/runner.php` body `{"action":"complete","id":12,"html":"<section>…</section>","json":{…}}`

Slaat HTML + JSON op, status `klaar`. `json` mag object of JSON-string zijn. **409** als de job niet `bezig` is.

B2 stuurt daarna de **"scan klaar"-mail** (Nederlands, `Hoi,`, ik-vorm, korte tekst, link naar dashboard via login, eindigt op `Groet,`). Maximaal **één keer per scan** (`ready_mail_sent_at`); daarnaast throttling via `ready_mail_per_email_seconds` en `ready_mail_global_max_per_hour`. Bij throttle probeert `action=health` later opnieuw.

### `POST /api/runner.php` body `{"action":"fail","id":12,"message":"…"}`

Maximaal **één automatische herpoging** per scan (`scan_max_retries`, default 1; kolom `scans.attempts`).

- Eerste mislukking (job `bevestigd` of `bezig`): de job gaat terug naar `bevestigd` (claim gewist, voortgang gewist, `queued_at` = nu, melding bewaard in `last_failure`). Antwoord: `{"ok":true,"retry":true,"final":false,"attempts":1}`. `action=next` geeft hem daarna weer; het jobobject bevat `attempts`.
- Tweede mislukking: status `mislukt`, melding in `error_message`, interne melding aan `info@` (geen mail naar de klant). Antwoord: `{"ok":true,"retry":false,"final":true,"attempts":2}`.
- **409** `not_failable` als de job al `klaar` of `mislukt` is. Geen "scan klaar"-mail.

### `GET|POST /api/runner.php?action=health`

Queue-tellingen + stale-job check (alert naar `info@` als jobs >30 min wachten; max. 1 alert/uur).

```json
{ "ok": true, "counts": { "bevestigd": 1, "bezig": 0, "…": 0 }, "retrying": 0, "failed": [{ "public_id": "…", "domain": "…", "attempts": 2, "failed_at": 1791885701, "message": "…" }], "stale": { "checked": true, "alerted": false, "stale": 0 } }
```

`retrying` = jobs met `attempts > 0` die weer klaarstaan of lopen; `failed` = laatste 10 definitief mislukte scans (na herpoging). Zichtbaar voor beheer op `/admin/scans.php`.

### `GET /ai-scan-queue.php` (publiek, zonder token)

Voor de scan-wachter. Antwoord altijd alleen `{"work":true}` of `{"work":false}` (`Cache-Control: no-store`). `true` = er staat een job klaar voor de runner: een `bevestigd`e scan (ook een opnieuw te proberen scan), of een `wacht_dag`-scan waarvoor vandaag nog ruimte is (zelfde definitie als `action=next`, maar alleen lezen). Geen aantallen, id's of persoonsgegevens. Alleen GET/HEAD (anders 405 met `{"work":false}`); bij een storing HTTP 503 met `{"work":false}`.

## Stale-job alert

Als een job in `bevestigd` of `wacht_dag` langer dan **30 minuten** (`stale_job_seconds`, default `1800`) in de queue staat, mailt B2 `info@jpwebcreation.nl`. Alleen gecheckt (en verlopen claims hersteld) bij runner-aanroepen (`api/runner.php`), niet bij publieke requests. `/ai-scan-queue.php` leest alleen, maar meldt `work:true` ook bij een `bezig`-job met een claim ouder dan dezelfde drempel, zodat de box-wachter de runner start en het herstel snel volgt. Cooldown: **1 uur** (`stale_alert_cooldown`).

## Config (buiten webroot)

Zie `config-examples/ai-scan-config.php`. Lookup:

1. env `JPWEB_AI_SCAN_CONFIG`
2. `../private/ai-scan-config.php` (relatief t.o.v. webroot)
3. `/home/jpwebcreation/private/ai-scan-config.php`

Keys: `sqlite_path`, `runner_token`, `app_key`, `base_url`, `smtp` (`host`, `port`, `encryption`, `username`, `password`, `from_email`, `from_name`, `alert_to`), `daily_free_scans`, `confirm_token_ttl`, `password_token_ttl`, `stale_job_seconds`, `stale_alert_cooldown`, `login_max_attempts`, `login_window_seconds`, `form_ip_max_per_hour`, `form_ip_max_per_day`, `form_email_max_per_hour`, `form_domain_max_per_hour`, `form_burst_seconds`, `form_burst_max`, `confirm_mail_per_email_seconds`, `confirm_mail_global_max_per_hour`, `ready_mail_per_email_seconds`, `ready_mail_global_max_per_hour`, `email_blocked_tlds`, `website_allowed_tlds`, `disposable_email_domains`, `website_require_http`, `allowed_hosts`.

## Mailtoon

Nederlands, begint met `Hoi,`, ik-vorm, kort, eindigt met `Groet,`. SMTP: `info@jpwebcreation.nl` via s1155, poort 465 SSL.
