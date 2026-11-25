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

# /wie-ik-ben/ staat achter een server-side pincode (index.php + foto.php) en is onvindbaar:
# niet in de sitemap, geen links (tests/ telt niet: wordt niet gedeployed), geen social tags, foto's en pincode-hash alleen buiten de repo.
WIE_IK_BEN = "https://jpwebcreation.nl/wie-ik-ben/"
WIB_DIR = root / "wie-ik-ben"
assert WIE_IK_BEN not in urls, "sitemap must not include /wie-ik-ben/"
assert "wie-ik-ben" not in (root / "sitemap.xml").read_text(), "sitemap must not mention wie-ik-ben"
assert not (WIB_DIR / "index.html").exists(), "/wie-ik-ben/index.html must not exist (DirectoryIndex would bypass the pin)"
wie_php = (WIB_DIR / "index.php").read_text()
assert '<meta name="robots" content="noindex, nofollow, noarchive">' in wie_php, "/wie-ik-ben/: robots meta"
assert "X-Robots-Tag" in (WIB_DIR / "_gate.php").read_text() and "wib_send_common_headers();" in wie_php, "/wie-ik-ben/: X-Robots-Tag"
assert "wib_send_common_headers();" in (WIB_DIR / "foto.php").read_text(), "/wie-ik-ben/foto.php: headers"
assert not re.search(r"og:|twitter:|rel=\"canonical\"|name=\"description\"", wie_php), "/wie-ik-ben/: no og/twitter/canonical/description"
assert "password_verify" in (WIB_DIR / "_gate.php").read_text(), "/wie-ik-ben/: pin via password_verify"
for f in root.rglob("*"):
    rel = f.relative_to(root)
    if not f.is_file() or rel.parts[0] in (".git", "wie-ik-ben", "tests"):
        continue
    if f.suffix.lower() in (".html", ".php", ".js", ".json", ".xml", ".txt"):
        assert "/wie-ik-ben" not in f.read_text(errors="ignore"), f"{rel}: verwijst naar /wie-ik-ben/"
    if f.suffix.lower() in (".webp", ".jpg", ".jpeg", ".png", ".avif"):
        low = rel.as_posix().lower()
        assert "wie-ik-ben" not in low and "flap" not in low and not re.search(r"(^|[/_-])pl[-_]", low), f"{rel}: foto hoort niet in de repo"
assert not (root / "assets" / "wie-ik-ben").exists(), "assets/wie-ik-ben/ must not exist"
for f in WIB_DIR.iterdir():
    assert f.suffix == ".php", f"wie-ik-ben/{f.name}: alleen PHP, geen foto's of data"
# Geen hardcoded pincode of hash: alleen lezen uit $HOME/private/flappen-config.php.
for f in list(WIB_DIR.glob("*.php")) + [root / "tests" / "wie_ik_ben_gate_test.php"]:
    src = f.read_text()
    assert not re.search(r"\$2[aby]\$\d\d\$[./A-Za-z0-9]{20,}", src), f"{f.name}: bcrypt-hash in repo"
    assert not re.search(r"pin_hash'\s*=>\s*'\$", src), f"{f.name}: pin_hash-waarde in repo"
    assert not re.search(r"(?i)pin\w*['\"]?\]?\s*(?:=>|===?|!==?|=)\s*['\"]?\d{4,8}\b", src), f"{f.name}: hardcoded pincode"
    assert not re.search(r"password_verify\(\s*['\"]\d", src), f"{f.name}: hardcoded pincode in password_verify"
for f in root.rglob("*"):
    if f.is_file() and ".git" not in f.parts and f.suffix.lower() in (".php", ".js", ".html", ".json", ".yml", ".md", ".py", ".sh"):
        assert not re.search(r"\$2y\$12\$[./A-Za-z0-9]{53}", f.read_text(errors="ignore")), f"{f}: bcrypt-hash in repo"

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

# Bedrijfsgegevens: footer en JSON-LD komen uit .github/scripts/nap.py.
import subprocess, sys
subprocess.run([sys.executable, str(root / ".github" / "scripts" / "nap.py"), "--check"], check=True)

# Schrijfwijze: "JP Web Creation" in tekst; alleen domein, mailadres en paden zijn "jpwebcreation".
spelling = re.compile(r"jp[\s_-]?web[\s_-]?creation", re.I)
for page in root.rglob("*.html"):
    if page.relative_to(root).parts[0] == "assets":
        continue
    for m in spelling.finditer(page.read_text()):
        assert m.group(0) in ("JP Web Creation", "jpwebcreation"), f"{page}: schrijfwijze {m.group(0)!r}"
    text = page.read_text()
    assert not re.search(r"Joris Paardekooper(?! Overman)", text), f"{page}: auteursnaam zonder Overman"

print(f"SEO metadata and sitemap checked: {count} canonical pages.")
print(f"Bots OG image OK: {bots_image} ({width}x{height}).")
