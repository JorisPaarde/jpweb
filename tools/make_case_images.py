#!/usr/bin/env python3
"""Maak de afbeeldingen voor een casepagina uit drie schermafbeeldingen.

Gebruik:
    python3 tools/make_case_images.py <prefix> page1.png page2.png page3.png

Voorbeeld:
    python3 tools/make_case_images.py nuairco home.png airco.png contact.png

Elke bron wordt bijgesneden en geschaald naar 1440x1000 (bovenkant blijft
staan) en opgeslagen als assets/projects/<prefix>-page-N.png en .webp.
De eerste wordt ook de rij-afbeelding: <prefix>-flat.jpg, .webp en de
varianten -480, -720 en -1080.webp.
"""
import sys
from pathlib import Path

from PIL import Image

W, H = 1440, 1000
OUT = Path(__file__).resolve().parent.parent / "assets" / "projects"


def fit(path):
    im = Image.open(path).convert("RGB")
    scale = W / im.width
    im = im.resize((W, round(im.height * scale)), Image.LANCZOS)
    if im.height < H:
        canvas = Image.new("RGB", (W, H), "white")
        canvas.paste(im, (0, 0))
        return canvas
    return im.crop((0, 0, W, H))


def main():
    if len(sys.argv) != 5:
        sys.exit(__doc__)
    prefix, sources = sys.argv[1], sys.argv[2:]
    for n, src in enumerate(sources, start=1):
        im = fit(src)
        im.save(OUT / f"{prefix}-page-{n}.png", optimize=True)
        im.save(OUT / f"{prefix}-page-{n}.webp", quality=82)
        if n == 1:
            im.save(OUT / f"{prefix}-flat.jpg", quality=84, optimize=True, progressive=True)
            im.save(OUT / f"{prefix}-flat.webp", quality=82)
            for w in (480, 720, 1080):
                im.resize((w, round(H * w / W)), Image.LANCZOS).save(
                    OUT / f"{prefix}-flat-{w}.webp", quality=80
                )
    print(f"Afbeeldingen voor {prefix} opgeslagen in {OUT}")


if __name__ == "__main__":
    main()
