# JPWebcreation static site

Static JPWebcreation site built with plain HTML, CSS, JavaScript, and a small PHP
contact handler. It contains the homepage, a practical AI landing page, six
project cases, a privacy page, a custom 404, sitemap, robots rules, and
Apache/LiteSpeed configuration. It does not depend on WordPress.

## Local preview

```sh
python3 -m http.server 4173
```

Then open `http://127.0.0.1:4173`.

This previews the static pages only. `contact.php` and actual mail delivery must
be tested in a PHP-capable environment, preferably on the test domain.

## Main structure

- `index.html`: homepage and contact form
- `ai/index.html`: landing page for AI tools, workshops, and AI-assisted development
- `digitale-opname/index.html`: landing page voor Digitale Opname
- `styles.css`: shared design and responsive rules
- `script.js`: navigation, reveal effects, and form-result feedback
- `contact.php`: validation, one-time form recovery, rate limiting, and mail delivery
- `projecten/*/index.html`: six project cases
- `assets/social/jpwebcreation-og.jpg`: shared 1200 x 630 social preview image
- `sitemap.xml`: the ten canonical, indexable production routes
- `.htaccess`: one-hop legacy redirects, caching, 404 handling, and staging noindex header
- `.github/scripts/check-redirects.sh`: HTTP check for all documented legacy redirects
- `.github/workflows/deploy.yml`: test and production deployment
- `URL-MIGRATIE.md`: source list and definitive legacy-to-current URL map
- `CONTENT-HERSTART.md`: ten unpublished article outlines for a later content phase

The homepage project section is an editorial showcase with four primary case
rows and two compact secondary project links. Implementation rules for agents
are documented in `AGENTS.md`.

## Server target

The staging domain found in cPanel is:

- `test.jpwebcreation.nl`
- document root: `/home/jpwebcreation/test.jpwebcreation.nl`

Production uses `/home/jpwebcreation/public_html`. Do not upload or sync files
manually during the normal workflow; use GitHub Actions as described below.

## GitHub Actions deploys

Pushes to `main` run `.github/workflows/deploy.yml` and deploy the checked out
static files to test with `rsync`:

- test: `/home/jpwebcreation/test.jpwebcreation.nl`
- production: `/home/jpwebcreation/public_html`

Production deploys are manual. After reviewing test, run the `Deploy site`
workflow from GitHub Actions with `Run workflow`; that job uses the `production`
environment and deploys the selected `main` commit.

Both deploy jobs finish with an HTTP smoke test. It checks all public routes,
including `/ai/`, the custom 404, the contact-state endpoint, the
environment-specific `X-Robots-Tag`, and every redirect in the migration map.
Each legacy URL must return one `301`, reach the documented live destination in
one hop, and end in `200`. A failed check makes the deployment workflow fail.

The workflow requires this repository secret:

- `JPWEB_DEPLOY_SSH_KEY`: private SSH key for the GitHub Actions deploy user

Recommended setup:

```sh
ssh-keygen -t ed25519 -N "" -C "github-actions-jpweb-deploy" -f jpweb_deploy_key
ssh jpweb "mkdir -p ~/.ssh && chmod 700 ~/.ssh && touch ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys"
cat jpweb_deploy_key.pub | ssh jpweb "cat >> ~/.ssh/authorized_keys"
gh secret set JPWEB_DEPLOY_SSH_KEY < jpweb_deploy_key
rm jpweb_deploy_key jpweb_deploy_key.pub
```

The workflow excludes repository metadata, Markdown files, and `.DS_Store`, then
uses `--delete` so removed local files are also removed from the server.

## Required checks

Before pushing, verify that CSS braces are balanced, internal paths still exist,
the JSON-LD is valid JSON, and every HTML file uses the same current
`styles.css?v=` and `script.js?v=` value. After the test deploy, inspect the
homepage and `/ai/` at mobile and desktop widths, open all cases, and test the
contact form. Before production deploy, complete the open checks in
`QA-RAPPORT.md` and `SITE-VERBETERPLAN.md`.

The repository does not submit URLs to Google Search Console. Sitemap
submission, recrawl requests, and removal requests must be completed through an
authorized Search Console account after the production deploy.
