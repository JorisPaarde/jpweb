#!/usr/bin/env bash
# Render assets/social/bots-og-v1.jpg from bots-og-v1.html with headless Chrome.
# Requires: google-chrome/chromium, python3, ffmpeg, curl.
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
src="$root/assets/social/bots-og-v1.html"
out="$root/assets/social/bots-og-v1.jpg"
tmp="$(mktemp -d)"
profile="$tmp/chrome-profile"
png="$tmp/bots-og-v1.png"
port=8765

cleanup() {
  if [ -n "${server_pid:-}" ]; then
    kill "$server_pid" >/dev/null 2>&1 || true
    wait "$server_pid" 2>/dev/null || true
  fi
  rm -rf "$tmp"
}
trap cleanup EXIT

chrome=""
for candidate in google-chrome-stable google-chrome chromium chromium-browser; do
  if command -v "$candidate" >/dev/null 2>&1; then
    chrome="$candidate"
    break
  fi
done

if [ -z "$chrome" ]; then
  echo "Headless Chrome/Chromium not found." >&2
  exit 1
fi

if [ ! -f "$src" ]; then
  echo "Missing source: $src" >&2
  exit 1
fi

# Serve the repo root so relative asset paths resolve the same way as on the site.
python3 -m http.server --bind 127.0.0.1 "$port" --directory "$root" >/dev/null 2>&1 &
server_pid=$!

for _ in $(seq 1 25); do
  if curl --silent --fail "http://127.0.0.1:$port/assets/social/bots-og-v1.html" >/dev/null; then
    break
  fi
  sleep 0.2
done

mkdir -p "$profile"
# Isolated profile + timeout: shared Chrome profiles in this environment can hang
# after --screenshot writes the file.
timeout 45s "$chrome" \
  --headless=new \
  --no-sandbox \
  --disable-gpu \
  --disable-dev-shm-usage \
  --hide-scrollbars \
  --force-device-scale-factor=1 \
  --window-size=1200,630 \
  --user-data-dir="$profile" \
  --no-first-run \
  --no-default-browser-check \
  --screenshot="$png" \
  "http://127.0.0.1:$port/assets/social/bots-og-v1.html" \
  >/dev/null 2>&1 || true

if [ ! -f "$png" ]; then
  echo "Chrome did not write a screenshot to $png" >&2
  exit 1
fi

# Re-encode to JPEG under 300 KB while keeping exact 1200x630.
ffmpeg -y -i "$png" -frames:v 1 -update 1 -vf "scale=1200:630:flags=lanczos" \
  -q:v 3 "$out" >/dev/null 2>&1

python3 - "$out" "$root" <<'PY'
from pathlib import Path
import sys
path = Path(sys.argv[1])
root = Path(sys.argv[2])
data = path.read_bytes()
assert data[:3] == b"\xff\xd8\xff", f"{path} is not JPEG"
size = path.stat().st_size
assert size < 300 * 1024, f"{path} is {size} bytes (>= 300 KB)"
i = 2
width = height = None
while i < len(data) - 9:
    if data[i] != 0xFF:
        i += 1
        continue
    marker = data[i + 1]
    if marker in (0xC0, 0xC1, 0xC2):
        height = int.from_bytes(data[i + 5:i + 7], "big")
        width = int.from_bytes(data[i + 7:i + 9], "big")
        break
    if marker == 0xD9 or marker == 0xDA:
        break
    if marker == 0x01 or 0xD0 <= marker <= 0xD9:
        i += 2
        continue
    length = int.from_bytes(data[i + 2:i + 4], "big")
    i += 2 + length
assert (width, height) == (1200, 630), f"{path} is {width}x{height}, expected 1200x630"
print(f"Wrote {path.relative_to(root)} ({width}x{height}, {size} bytes)")
PY
