# JPWebcreation static site

Static coded concept for the next JPWebcreation site. It reuses the strongest
positioning, testimonials, project names, services, and contact details from the
current WordPress site, but does not depend on WordPress.

## Local preview

```sh
python3 -m http.server 4173
```

Then open `http://127.0.0.1:4173`.

## Server target

The staging domain found in cPanel is:

- `test.jpwebcreation.nl`
- document root: `/home/jpwebcreation/test.jpwebcreation.nl`

Deploy by uploading the static files in this folder to that document root.

## GitHub Actions deploys

Pushes to `main` run `.github/workflows/deploy.yml` and deploy the checked out
static files to test with `rsync`:

- test: `/home/jpwebcreation/test.jpwebcreation.nl`
- production: `/home/jpwebcreation/public_html`

Production deploys are manual. After reviewing test, run the `Deploy site`
workflow from GitHub Actions with `Run workflow`; that job uses the `production`
environment.

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
