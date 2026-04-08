# NWS Marine Weather Map

Interactive marine forecast map: offshore zones, coastal (CWF), NAVTEX, and high seas, with zones colored by active marine warnings. Data is loaded from the **NWS API** (`api.weather.gov`) with local caching and optional fallback to text files under `/shtml/`.

## Features

- **Products** (dropdown): **Offshore**, **Coastal**, **NAVTEX**, **High Seas** — each uses the matching zone layer (GeoJSON or TopoJSON).
- **Regions**: Atlantic, Gulf/Caribbean, Tropical Atlantic, Pacific, plus optional Hawaii, Alaska, Great Lakes (some can be hidden via config; see below).
- **Warnings**: Broad set of NWS marine/advisory colors (gale, storm, hurricane, small craft, tsunami, etc.); legend entries show only warnings present in the current dataset.
- **Forecast panel**: Click a zone for text; **wind/wave chart** (Chart.js) for products that expose period-based wind/seas — hidden for **High Seas** (raw text only).
- **Basemap**: Esri Ocean Base + Reference; **US state outlines** (Natural Earth 1:110m, light stroke under zones).
- **UI configuration**: `assets/ui-config.json` controls dropdown visibility for products/regions and optional **map exclusion** of zone ID prefixes (e.g. Hawaiian `PHZ*` / Alaskan `PKZ*` polygons). Options stay in the DOM when hidden so bookmark URLs still work.

## Pages

| File | Purpose |
|------|---------|
| `index.html` | Main marine map (all products above) |
| `navy.html` | Navy OPAREA forecasts (separate UI) |

## API (`api.php`)

JSON forecast arrays. Primary source: **NWS API**, with responses cached under `cache/`. Add `&debug=1` for diagnostic payloads where supported.

| Query | Description |
|-------|-------------|
| `api.php?type=offshore` | OPC/NHC offshore waters (OFF products) |
| `api.php?type=coastal` | Coastal Forecast (CWF) plus related marine zones |
| `api.php?type=navtex` | NAVTEX-style OFF products |
| `api.php?type=highseas` | High Seas Forecast (HSF) |
| `api.php?type=diagnose` | Local file / connectivity checks |

**Prefetch:** `prefetch.php` can refresh cached JSON in the background (see script header for cron / `exec` / URL options). Ensures fast reads when the cache is warm.

## Configuration

- **`assets/ui-config.json`** — `products.*.showInDropdown`, `regions.*.showInDropdown`, `map.excludeZoneIdPrefixes`.
- **`api.php`** (top) — `$LOCAL_DATA_DIR`, NWS cache TTL, user agent.

## File structure

```
├── index.html                 # Main app
├── navy.html                  # Navy OPAREA page
├── api.php                    # Forecast JSON API
├── prefetch.php               # Optional cache warmer
├── getText.php                # Legacy text fetch helper
├── get-forecast.php           # PIL-gated plain-text forecast files
├── cache/                     # NWS + parsed JSON cache (see .gitignore)
├── assets/
│   ├── ui-config.json         # Dropdown + map filter toggles
│   ├── us-states-outline.geojson
│   ├── offshores.geojson
│   ├── coastal.geojson
│   ├── navtex.geojson
│   ├── highseas.topojson      # (and highseas.geojson if present)
│   ├── offshore-forecasts.json
│   └── navtex-forecasts.json  # Static fallbacks when API fails
├── css/ , js/                 # navy.html assets
└── libs/
    ├── leaflet/
    ├── chartjs/
    └── topojson/              # TopoJSON → GeoJSON for highseas layer
```

## Requirements

- **PHP** with `allow_url_fopen` or equivalent for HTTPS to `api.weather.gov`
- **Writable** `cache/` directory for the web server user
- **Modern browser** (ES5+ bundle in page)
- Optional: **`/shtml/`** text files as in `api.php` fallback paths

## URLs

Bookmark examples: `?product=offshore&basin=atlantic&zone=ANZ800`, `?product=navtex`, `?product=highseas`.

## Data and credits

- **Forecasts**: [National Weather Service](https://www.weather.gov/) / NOAA (public domain).
- **State outlines**: [Natural Earth](https://www.naturalearthdata.com/) 1:110m admin-1 (public domain).
- **Ocean tiles**: Esri Ocean Basemap / Reference (see Esri terms).

## License

NWS/NOAA forecast data is in the public domain. Natural Earth data is public domain. Application code follows the repository’s license.
