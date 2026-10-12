# ai-scan API v1 (B1 formulier ↔ B2 PHP ↔ B3 runner)
POST /ai-scan.php (form-urlencoded): email, website, consent=1, hp_field (honeypot, moet leeg), csrf
Antwoord: altijd 303 naar /ai-scan/?s=<code>; codes: check_mail | invalid_email | site_unreachable | already_scanned | disposable | daily_full | rate_limited | error
  (honeypot → ook check_mail; already_scanned verraadt geen e-mail)
GET /ai-scan.php?confirm=<token>   (eenmalig, 24 u) → status bevestigd, job in queue → 303 /ai-scan/?s=confirmed
GET /ai-scan/wachtwoord/?t=<token> (eenmalig, 24 u) → gebruiker kiest zelf wachtwoord. NOOIT een wachtwoord per mail.
Queue-record (JSON, buiten webroot): {id, email_hash, email, domain, status: onbevestigd|bevestigd|wacht_dag|bezig|klaar|mislukt, created, confirmed, scan_id, dashboard_path}
Regels: 1 scan per e-mail én per domein; dagplafond N (TBD Joris); rate limit per IP; kostenplafond €0,60/scan (runner breekt af).
Prijsmodel: eerste scan gratis; daarna alleen abonnement (geen losse betaalde scan). Prijzen in copy: [bedrag].
Runner (B3, scripts-repo) leidt branche, plaats en concurrenten zelf af uit de site; schrijft dashboard + mailt "scan klaar" (ik-vorm, eindigt 'Groet,').
