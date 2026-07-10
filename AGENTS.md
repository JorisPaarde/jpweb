# Agent Notes

This is a static JPWebcreation site. Keep changes simple: edit the HTML, CSS,
JS, and assets directly, then preview with a local static server.

## Local Preview

Use:

```sh
python3 -m http.server 8080
```

Open `http://127.0.0.1:8080/`. If port 8080 is busy, use another port.

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

HTML files reference `styles.css?v=13`. When changing layout/CSS, bump the
version in every HTML file so browser previews do not show stale CSS.

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

## Server Deploy

README lists the staging target:

- domain: `test.jpwebcreation.nl`
- document root: `/home/jpwebcreation/test.jpwebcreation.nl`

There is currently no deploy script in this repo. The expected deploy flow is to
log in to the server terminal, go to the site document root/repo, and pull the
latest `main` branch there. Do not assume files need to be uploaded manually
unless the server checkout is missing or broken.

Likely flow after logging in:

```sh
cd /home/jpwebcreation/test.jpwebcreation.nl
git status
git pull origin main
```

SSH was tested with:

```sh
ssh -o BatchMode=yes -o ConnectTimeout=10 jpwebcreation@test.jpwebcreation.nl pwd
```

The host key can be accepted with `StrictHostKeyChecking=accept-new`, but the
server then failed authentication:

```text
Permission denied (publickey,gssapi-keyex,gssapi-with-mic,password).
```

That means this local SSH command did not have the right authentication at that
moment. If the user says server access is available, use the normal server
terminal/login route and run `git pull` from the checkout instead of trying to
rsync/upload the static files.
