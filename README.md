# JPWebcreation static site

Static JPWebcreation site built with plain HTML, CSS, JavaScript, and a small PHP
contact handler. It contains the homepage, six project cases, a privacy page, a
custom 404, sitemap, robots rules, and Apache/LiteSpeed configuration. It does
not depend on WordPress.

## Local preview

```sh
python3 -m http.server 4173
```

Then open `http://127.0.0.1:4173`.

This previews the static pages only. `contact.php` and actual mail delivery must
be tested in a PHP-capable environment, preferably on the test domain.

## Main structure

- `index.html`: homepage and contact form
- `styles.css`: shared design and responsive rules
- `script.js`: navigation, reveal effects, carousel controls, and form-result feedback
- `contact.php`: validation, one-time form recovery, rate limiting, and mail delivery
- `projecten/*/index.html`: six project cases
- `.htaccess`: redirects, caching, 404 handling, and staging noindex header
- `.github/workflows/deploy.yml`: test and production deployment

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
the custom 404, the contact-state endpoint, and the environment-specific
`X-Robots-Tag`. A failed check makes the deployment workflow fail.

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
and every HTML file uses the same current `styles.css?v=` and `script.js?v=`
value. After the test deploy, inspect the homepage at mobile and desktop widths,
open all cases, and test the contact form. Before production deploy, complete
the open checks in `QA-RAPPORT.md` and `SITE-VERBETERPLAN.md`.
