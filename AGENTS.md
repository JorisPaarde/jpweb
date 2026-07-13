# Agent Notes

This is a static JPWebcreation site. Keep changes simple: edit the HTML, CSS,
JS, and assets directly, then preview with a local static server.

## Local Preview

Use:

```sh
python3 -m http.server 4173
```

Open `http://127.0.0.1:4173/`. If port 4173 is busy, use another port.

The Python server is sufficient for layout and link checks, but it does not run
`contact.php`. Use a PHP-capable local server or the test environment for an
end-to-end form check. Never claim that mail delivery works based only on the
Python preview.

## Homepage Project Showcase

The homepage uses an editorial project showcase, not a card grid:

- four primary cases are full-width rows inside `.work-list`;
- odd rows show the screenshot on the left and even rows on the right;
- the HTML order always remains screenshot first, copy second; CSS handles the
  visual alternation;
- Archipel Bouwadvies and Wildfloweroffice remain compact links under
  `.work-more`;
- project labels are plain inline text separated by dots, not pill badges;
- rows use horizontal dividers and whitespace instead of outer card borders and
  hover shadows.

Do not turn this section back into a uniform card grid without an explicit
design decision from Joris. When adding a primary case, copy an existing
`.work-item`, update its number, image, alt text, result-led copy, labels, and
case URL, then test both the alternating desktop layout and the single-column
mobile order.

## Project Screenshot Carousels

Project pages use a hero carousel in place of the old mockup image. Each project
should have at least three flat page screenshots in `assets/projects/`:

- `mikes-page-1.png`, `mikes-page-2.png`, `mikes-page-3.png`
- `haveka-page-1.png`, `haveka-page-2.png`, `haveka-page-3.png`
- `supper-services-page-1.png`, `supper-services-page-2.png`, `supper-services-page-3.png`
- `eceens-page-1.png`, `eceens-page-2.png`, `eceens-page-3.png`
- `archipel-page-1.png`, `archipel-page-2.png`, `archipel-page-3.png`
- `wildfloweroffice-page-1.png`, `wildfloweroffice-page-2.png`, `wildfloweroffice-page-3.png`

Mike's Pianoshow also keeps `mikes-piano-planner.png` as an extra app panel,
because the app is an important add-on but the website remains the base project.

When replacing screenshots, capture real live pages, not device mockups. For
Supper Services, check for a cookie banner and dismiss it before capturing.

## CSS Cache Busting

HTML files reference `styles.css` with a `?v=` cache-busting query. When
changing layout/CSS, bump the version in every HTML file so browser previews do
not show stale CSS.

## GitHub Push

The remote is:

```sh
origin https://github.com/JorisPaarde/jpweb.git
```

Plain `git push origin main` may fail with:

```text
fatal: could not read Username for 'https://github.com': Device not configured
```

The working fix was:

```sh
gh auth setup-git
git push origin main
```

## Deploy

`.github/workflows/deploy.yml` is the canonical deploy route:

- every push to `main` deploys the exact commit to
  `https://test.jpwebcreation.nl/` with `rsync --delete`;
- production is never deployed by a normal push;
- production deploy is a manual `workflow_dispatch` run of `Deploy site` and
  targets `/home/jpwebcreation/public_html`;
- the workflow excludes `.git/`, `.github/`, `.DS_Store`, and Markdown files;
- both jobs require the `JPWEB_DEPLOY_SSH_KEY` repository secret.

After a push, check the GitHub Actions run before describing test as deployed.
After a manual production run, test the production homepage, all project routes,
the form, redirects, sitemap, robots headers, and the custom 404. Do not use the
manual server sync below as the normal deploy path.

## SSH Access (manual recovery only)

An SSH alias named `jpweb` may be configured on Joris's Mac:

```sshconfig
Host jpweb
  HostName test.jpwebcreation.nl
  User jpwebcreation
  IdentityFile ~/.ssh/id_ed25519
  IdentitiesOnly yes
  ServerAliveInterval 30
```

Before relying on it, run:

```sh
ssh jpweb 'pwd && whoami'
```

If the alias or key is unavailable in the current environment, stop instead of
guessing credentials or server paths. SSH is for diagnosis and recovery; normal
deploys go through GitHub Actions.
