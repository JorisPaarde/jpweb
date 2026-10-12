# Gedeelde contracten (v1) — eerst als één kleine PR mergen, daarna pas A1-A3 / B1-B3 starten
- snapshot.schema.json  : {data_dir}/snapshots/{YYYY-MM-DD}.json  (schrijver A1, lezers A2)
- actions.schema.json   : {data_dir}/actions.json                  (schrijver A2, lezer A3)
- changelog-row         : changelog.jsonl regel met action_id (string|lijst), log_id, date_live, by (eigenaar|beheerder)
- ai-scan-api.md        : formulier → /ai-scan.php → queue → scan-runner (B1/B2/B3)
Placeholders: alleen vorm [jouw ...]; prijzen altijd [bedrag]. Label: "Controleer of dit klopt voor jouw bedrijf".
