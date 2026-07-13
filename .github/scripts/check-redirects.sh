#!/usr/bin/env bash

set -euo pipefail

base_url="${1%/}"

redirects=(
  "/contact-voor-hulp-met-jouw-website/|/#contact"
  "/projecten/website-mikes-pianoshow/|/projecten/mikes-pianoshow/"
  "/projecten/website-wildfloweroffice/|/projecten/wildfloweroffice/"
  "/blog/|/"
  "/general/nieuwe-site-laten-bouwen/|/"
  "/general/snel-website-laten-ontwikkelen/|/"
  "/general/professioneel-webdesign-laten-maken/|/"
  "/general/professionele-website-laten-bouwen/|/"
  "/general/webdesign-laten-maken/|/"
  "/general/professionele-webdesign/|/"
  "/general/website-maken-met-squarespace-of-elementor/|/"
  "/general/betaalbare-website-laten-maken/|/"
  "/general/complete-website-laten-maken/|/"
  "/general/internetsite-laten-maken/|/"
  "/general/website-maken/|/"
  "/general/professioneel-webdesign/|/"
  "/general/eigen-professionele-website-maken/|/"
  "/general/betaalbare-website-laten-ontwikkelen/|/"
  "/general/betaalbare-website-laten-bouwen/|/"
  "/general/eenvoudige-website-laten-bouwen/|/"
  "/general/betaalbaar-webdesign/|/"
  "/general/een-professioneel-website-laten-ontwerpen/|/"
  "/general/een-website-laten-bouwen/|/"
  "/uncategorized/maken-van-website/|/"
  "/general/snel-website-laten-bouwen/|/"
  "/general/nieuwe-website-laten-maken-prijs/|/"
  "/general/professionele-website-laten-maken-prijs/|/"
  "/general/site-laten-maken-prijs/|/"
  "/general/prijzen-webdesign/|/"
  "/general/kosten-professionele-website-laten-maken/|/"
  "/general/professionele-webshop-laten-bouwen/|/"
  "/general/webdesigner-webshop/|/"
  "/general/professionele-webshop-bouwen/|/"
  "/general/webshop-laten-ontwerpen/|/"
  "/general/webshop-laten-ontwikkelen/|/"
  "/general/website-teksten-schrijven/|/"
  "/general/waarom-het-belangrijk-is-om-je-website-goed-te-onderhouden/|/"
  "/general/effectief-communiceren-met-je-website/|/"
  "/general/verbeter-seo-door-relevante-content/|/"
  "/general/hulp-bij-uw-website-nodig-wij-staan-direct-voor-u-klaar/|/"
  "/uncategorized/professionele-website-laten-maken-haarlem/|/"
  "/stad/professionele-website-laten-maken-heemstede/|/"
  "/stad/professionele-website-laten-maken-haarlem-2/|/"
  "/stad/professionele-website-laten-maken-hoofddorp/|/"
  "/stad/professionele-website-laten-maken-beverwijk/|/"
  "/page-sitemap.xml|/sitemap.xml"
  "/post-sitemap.xml|/sitemap.xml"
  "/sitemap_index.xml|/sitemap.xml"
  "/wp-sitemap.xml|/sitemap.xml"
)

for mapping in "${redirects[@]}"; do
  IFS='|' read -r old_path expected_path <<< "$mapping"
  url="$base_url$old_path"

  headers="$(curl --silent --show-error --head --max-redirs 0 \
    --retry 5 --retry-delay 2 --retry-all-errors "$url")"
  status="$(awk 'toupper($1) ~ /^HTTP\// { code=$2 } END { print code }' <<< "$headers")"
  location="$(awk 'tolower($1) == "location:" { $1=""; sub(/^ /, ""); gsub(/\r/, ""); value=$0 } END { print value }' <<< "$headers")"

  if [ "$status" != "301" ]; then
    echo "$old_path gaf HTTP $status in plaats van 301." >&2
    exit 1
  fi

  if [ "$location" != "$expected_path" ] && [ "$location" != "$base_url$expected_path" ]; then
    echo "$old_path verwijst naar '$location' in plaats van '$expected_path'." >&2
    exit 1
  fi

  read -r final_status redirect_count < <(curl --silent --show-error --location \
    --max-redirs 1 --output /dev/null --retry 5 --retry-delay 2 \
    --retry-all-errors --write-out '%{http_code} %{num_redirects}' "$url")

  if [ "$final_status" != "200" ] || [ "$redirect_count" != "1" ]; then
    echo "$old_path eindigde met HTTP $final_status na $redirect_count redirects." >&2
    exit 1
  fi
done

echo "Alle ${#redirects[@]} legacy-URL's geven exact één 301 naar een live bestemming."
