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

## Server Deploy

README lists the staging target:

- domain: `test.jpwebcreation.nl`
- document root: `/home/jpwebcreation/test.jpwebcreation.nl`

There is currently no deploy script in this repo. The document root is not a git
repository. The expected deploy flow is to keep a checkout in
`/home/jpwebcreation/repos/jpweb`, pull there, then sync the static files into
the document root.

Use this from the server terminal:

```sh
mkdir -p /home/jpwebcreation/repos
if [ -d /home/jpwebcreation/repos/jpweb/.git ]; then
  cd /home/jpwebcreation/repos/jpweb
  git fetch origin main
  git checkout main
  git pull --ff-only origin main
else
  git clone https://github.com/JorisPaarde/jpweb.git /home/jpwebcreation/repos/jpweb
  cd /home/jpwebcreation/repos/jpweb
fi

git status
rsync -av --exclude='.git' --exclude='*.md' /home/jpwebcreation/repos/jpweb/ /home/jpwebcreation/test.jpwebcreation.nl/
```

Do not run `git status`, `git pull`, or other git commands directly inside
`/home/jpwebcreation/test.jpwebcreation.nl`; it has no `.git` directory and will
fail with `fatal: not a git repository`.

## SSH Access

Local SSH alias `jpweb` is configured in `~/.ssh/config` on this machine:

```sshconfig
Host jpweb
  HostName test.jpwebcreation.nl
  User jpwebcreation
  IdentityFile ~/.ssh/id_ed25519
  IdentitiesOnly yes
  ServerAliveInterval 30
```

The matching public key has been added to the server's
`~/.ssh/authorized_keys`. SSH was verified from this machine with:

```sh
ssh jpweb 'pwd && whoami'
```

Use `ssh jpweb 'command here'` for future deploy checks.
