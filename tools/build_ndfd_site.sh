#!/usr/bin/env bash
# Build the static copy of the oceanic globe served at
# https://jkrek17.github.io/web/ndfd/ (published by
# .github/workflows/publish_ndfd.yml).
#
# GitHub Pages runs no PHP and has no PGEN feed, so the copy is oceanic.html
# as index.html with its js/css/libs and synthetic fronts from
# tools/gen_demo_fronts.py (needs numpy + contourpy). The NDFD grids, warnings
# and basemap still come live from NOAA and Esri.
#
# Usage: tools/build_ndfd_site.sh OUT_DIR
set -euo pipefail

out=${1:?usage: tools/build_ndfd_site.sh OUT_DIR}
root=$(cd "$(dirname "$0")/.." && pwd)

rm -rf "$out"
mkdir -p "$out/libs" "$out/js"
cp -r "$root/css" "$out/css"
cp "$root"/js/oceanic*.js "$out/js/"
cp -r "$root/libs/maplibre" "$root/libs/turf" "$out/libs/"
python3 "$root/tools/gen_demo_fronts.py" "$out/fronts" >/dev/null

# Point the page at the demo fronts and link back to the projects index.
sed "s|window.OCEANIC_CONFIG = {[^}]*}[^}]*};|window.OCEANIC_CONFIG = { homeLink: { href: '../', label: 'All projects' }, demoFrontsDir: 'fronts/' };|" \
    "$root/oceanic.html" > "$out/index.html"
grep -q "demoFrontsDir: 'fronts/'" "$out/index.html" || {
    echo "oceanic.html: could not set OCEANIC_CONFIG" >&2
    exit 1
}
du -sh "$out"
