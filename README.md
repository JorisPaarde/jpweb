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
