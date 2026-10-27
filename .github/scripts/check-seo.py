"""Validate canonical pages and metadata before deployment; no dependencies."""
from pathlib import Path
from html.parser import HTMLParser
from urllib.parse import urlsplit
import json, re, struct, xml.etree.ElementTree as ET

root = Path(__file__).resolve().parents[2]
HOMEPAGE_OG = "https://jpwebcreation.nl/assets/social/jpwebcreation-og.jpg"
BOTS_OG = "https://jpwebcreation.nl/assets/social/bots-og-v1.jpg"


class Head(HTMLParser):
    def __init__(self):
        super().__init__()
        self.meta = {}
        self.canon = []
        self.h1 = 0

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == "meta":
            self.meta.setdefault(a.get("name", a.get("property")), []).append(a.get("content", ""))
        if tag == "link" and a.get("rel") == "canonical":
            self.canon.append(a["href"])
        if tag == "h1":
            self.h1 += 1


def jpeg_size(path: Path):
    data = path.read_bytes()
    assert data[:3] == b"\xff\xd8\xff", f"{path}: not JPEG"
    i = 2
    while i < len(data) - 9:
        if data[i] != 0xFF:
            i += 1
            continue
        marker = data[i + 1]
        if marker in (0xC0, 0xC1, 0xC2):
            height, width = struct.unpack(">HH", data[i + 5 : i + 9])
            return width, height
        if marker == 0xD9 or marker == 0xDA:
            break
        if marker == 0x01 or 0xD0 <= marker <= 0xD9:
            i += 2
            continue
        length = struct.unpack(">H", data[i + 2 : i + 4])[0]
        i += 2 + length
    raise AssertionError(f"{path}: JPEG size not found")


ns = {"s": "http://www.sitemaps.org/schemas/sitemap/0.9"}
urls = [n.text for n in ET.parse(root / "sitemap.xml").findall("s:url/s:loc", ns)]
assert len(urls) == len(set(urls)), "Duplicate sitemap URL"
titles = set()
descriptions = set()
count = 0
for url in urls:
    u = urlsplit(url)
    assert u.scheme == "https" and u.netloc == "jpwebcreation.nl", url
    p = root / u.path.lstrip("/")
    p = p / "index.html" if u.path.endswith("/") else p
    s = p.read_text()
    h = Head()
    h.feed(s)
    assert h.canon == [url], f"{p}: canonical"
    assert h.h1 == 1, f"{p}: H1"
    assert not any("noindex" in v for v in h.meta.get("robots", [])), f"{p}: noindex"
    title = re.findall(r"<title>(.*?)</title>", s, re.S)
    assert len(title) == 1 and title[0] not in titles, f"{p}: title"
    titles.add(title[0])
    for key in ["description", "og:title", "og:description", "og:url", "og:image", "twitter:card"]:
        assert len(h.meta.get(key, [])) == 1 and h.meta[key][0], f"{p}: {key}"
    assert h.meta["description"][0] not in descriptions, f"{p}: duplicate description"
    descriptions.add(h.meta["description"][0])
    assert h.meta["og:url"] == [url], f"{p}: og:url"
    for block in re.findall(r'<script type="application/ld\+json">(.*?)</script>', s, re.S):
        json.loads(block)
    count += 1

assert "noindex" in (root / "404.html").read_text()

# /bots/ must not reuse the homepage social image, and its OG asset must be 1200x630.
bots_html = (root / "bots" / "index.html").read_text()
bots_head = Head()
bots_head.feed(bots_html)
bots_image = bots_head.meta.get("og:image", [None])[0]
home_image = Head()
home_image.feed((root / "index.html").read_text())
home_og = home_image.meta.get("og:image", [None])[0]
assert home_og == HOMEPAGE_OG, f"homepage og:image unexpectedly {home_og}"
assert bots_image == BOTS_OG, f"bots og:image unexpectedly {bots_image}"
assert bots_image != home_og, "bots og:image must differ from homepage"
assert bots_head.meta.get("twitter:image", [None])[0] == BOTS_OG, "bots twitter:image mismatch"
assert bots_head.meta.get("og:image:width", [None])[0] == "1200"
assert bots_head.meta.get("og:image:height", [None])[0] == "630"
assert "https://jpwebcreation.nl/bots/" in urls, "sitemap missing /bots/"

bots_og_path = root / "assets" / "social" / "bots-og-v1.jpg"
assert bots_og_path.is_file(), f"missing {bots_og_path}"
width, height = jpeg_size(bots_og_path)
assert (width, height) == (1200, 630), f"{bots_og_path}: {width}x{height}"
assert bots_og_path.stat().st_size < 300 * 1024, f"{bots_og_path}: too large"

print(f"SEO metadata and sitemap checked: {count} canonical pages.")
print(f"Bots OG image OK: {bots_image} ({width}x{height}).")
