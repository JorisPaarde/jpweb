#!/usr/bin/env python3
"""Eén bron voor bedrijfsgegevens (NAP + KvK + btw): footer en JSON-LD op elke pagina.

  python3 .github/scripts/nap.py          schrijf footer en JSON-LD in alle HTML-pagina's
  python3 .github/scripts/nap.py --check  controleer alleen (exit 1 bij afwijking; draait in check-seo.py)

Pas gegevens alleen hier aan en draai het script opnieuw.
"""
from pathlib import Path
import json, re, sys

ROOT = Path(__file__).resolve().parents[2]

NAME = "JP Web Creation"
STREET = "Bernadottelaan 273"
POSTAL = "2037 GR"
CITY = "Haarlem"
PHONE_DISPLAY = "06 21 22 88 84"
PHONE_TEL = "+31621228884"
EMAIL = "info@jpwebcreation.nl"
SITE = "https://jpwebcreation.nl/"
KVK = "90397320"
VAT = "NL004813310B95"
PERSON = "Joris Paardekooper Overman"
YEAR = "2026"
SAME_AS = [
    "https://www.linkedin.com/company/jpwebcreation",
    "https://trustoo.nl/noord-holland/haarlem/webdesign/jpwebcreation/",
]
BEDRIJF_ID = SITE + "#bedrijf"
PERSON_ID = SITE + "#joris"

# Extra footerlinks per pagina (naast Contact, Privacyverklaring, Algemene voorwaarden).
FOOTER_EXTRA = {
    "digitale-opname/index.html": [
        '<a href="https://intake-engine.nl/" rel="noopener">Demo</a>',
    ],
    "digitale-opname/vergelijken/index.html": [
        '<a href="/digitale-opname/">Digitale Opname</a>',
    ],
}

SKIP = {"assets"}


def pages():
    for p in sorted(ROOT.rglob("*.html")):
        rel = p.relative_to(ROOT)
        if rel.parts[0] in SKIP or rel.parts[0] == ".git":
            continue
        yield p, rel.as_posix()


def footer_html(rel):
    contact_current = ' aria-current="page"' if rel == "contact/index.html" else ""
    links = list(FOOTER_EXTRA.get(rel, []))
    links += [
        f'<a href="/contact/"{contact_current}>Contact</a>',
        '<a href="/privacyverklaring/">Privacyverklaring</a>',
        '<a href="/algemene-voorwaarden/">Algemene voorwaarden</a>',
    ]
    links_html = "\n".join(f"        {l}" for l in links)
    return f"""<footer class="site-footer">
      <div class="footer-company">
        <strong class="footer-name">&copy; {YEAR} {NAME}</strong>
        <address class="footer-address">{STREET}, {POSTAL} {CITY}</address>
        <p class="footer-contact"><a href="tel:{PHONE_TEL}">{PHONE_DISPLAY}</a> <span aria-hidden="true">&middot;</span> <a href="mailto:{EMAIL}">{EMAIL}</a></p>
        <p class="footer-reg">KvK {KVK} <span aria-hidden="true">&middot;</span> Btw {VAT}</p>
      </div>
      <nav class="footer-links" aria-label="Footer">
{links_html}
      </nav>
    </footer>"""


def bedrijf_graph():
    return {
        "@context": "https://schema.org",
        "@graph": [
            {
                "@type": "ProfessionalService",
                "@id": BEDRIJF_ID,
                "name": NAME,
                "legalName": NAME,
                "url": SITE,
                "email": EMAIL,
                "telephone": PHONE_TEL,
                "vatID": VAT,
                "identifier": {"@type": "PropertyValue", "propertyID": "KvK", "value": KVK},
                "address": {
                    "@type": "PostalAddress",
                    "streetAddress": STREET,
                    "postalCode": POSTAL,
                    "addressLocality": CITY,
                    "addressCountry": "NL",
                },
                "areaServed": [
                    {"@type": "City", "name": CITY},
                    {"@type": "Country", "name": "NL"},
                ],
                "founder": {"@id": PERSON_ID},
                "sameAs": SAME_AS,
            },
            {
                "@type": "WebSite",
                "@id": SITE + "#website",
                "url": SITE,
                "name": NAME,
                "inLanguage": "nl-NL",
                "publisher": {"@id": BEDRIJF_ID},
            },
            {
                "@type": "Person",
                "@id": PERSON_ID,
                "name": PERSON,
                "worksFor": {"@id": BEDRIJF_ID},
            },
        ],
    }


def jsonld_html():
    body = json.dumps(bedrijf_graph(), indent=2, ensure_ascii=False)
    body = "\n".join("      " + l for l in body.splitlines())
    return f'<script type="application/ld+json" id="nap-bedrijf">\n{body}\n    </script>'


FOOTER_RE = re.compile(r'<footer class="site-footer">.*?</footer>', re.S)
LD_RE = re.compile(r'<script type="application/ld\+json" id="nap-bedrijf">.*?</script>', re.S)


def transform(rel, s):
    s, n = FOOTER_RE.subn(lambda m: footer_html(rel), s, count=1)
    assert n == 1, f"{rel}: geen site-footer gevonden"
    ld = jsonld_html()
    if LD_RE.search(s):
        s = LD_RE.sub(lambda m: ld, s, count=1)
    else:
        assert "</head>" in s, f"{rel}: geen </head>"
        s = s.replace("</head>", f"    {ld}\n  </head>", 1)
    return s


def main():
    check = "--check" in sys.argv
    bad = []
    for p, rel in pages():
        old = p.read_text()
        new = transform(rel, old)
        if new != old:
            bad.append(rel)
            if not check:
                p.write_text(new)
    if check:
        if bad:
            print("NAP-footer of JSON-LD wijkt af (draai python3 .github/scripts/nap.py):", *bad, sep="\n  ")
            sys.exit(1)
        print("NAP-footer en JSON-LD kloppen op alle pagina's.")
    else:
        print(f"{len(bad)} pagina's bijgewerkt.")


if __name__ == "__main__":
    main()
