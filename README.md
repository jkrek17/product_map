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
| `oceanic.html` | Oceanic forecast on a MapLibre GL (WebGL) globe: NDFD grids + OPC fronts/isobars |

## Oceanic forecast globe (`oceanic.html`)

MapLibre GL (WebGL) globe with a dark, map-first layout: top status bar, layer panel, legend and a bottom forecast timeline.

- **Base grids** (NDFD WMS): wave height, wind speed, wind gust or none, with opacity control and a server-driven color legend.
- **Wind barbs overlay**: NDFD barbs (`ndfd:wind`) drawn above any base grid, black or white.
- **Timeline**: −12 h to +96 h in 3 h steps with play/pause. Each step is marked for NDFD grid and fronts availability; NDFD availability is read from each layer's WMS `GetCapabilities` time list (ocean grids are 3-hourly, about 72 h ahead, and no past grids are kept). If a step has no exact grid, the nearest 3-hourly grid within 3 h is shown and labeled. Steps load into a hidden buffer and cross-fade in, so stepping and playback don't flash.
- **Preloaded frames** (`js/oceanic-frames.js`): once the map stops moving, every step with a grid is fetched as one GetMap image of the visible area (two for views across the date line) and kept in memory, so scrubbing and loops are instant; sharp tiles then replace the frame when paused. Frames and tiles share the throttled NOAA request pool (tiles first). The timeline shows preloaded steps and a "Preloading n/N" / "Loop ready" status.
- **Loop speed**: ½×, 1×, 2×, 4× (1× ≈ 1 frame/s, keys `[` `]`) with optional pause on the last frame.
- **Click readout**: wave height, wind speed and gust at the point (`GetFeatureInfo`) plus current watches/warnings there (NOAA `WWA/watch_warn_adv`).
- **Fronts, isobars, pressure centers**: read from `/data/geoJson/{Pacific|Atlantic}_HS_Surface.YYYYMMDD.HH00.FNNN.geo.json` (the cycle that issued a chart valid at the selected time is found automatically), smoothed with Turf and drawn as native MapLibre layers.
- **Reliability**: status chips for grid and fronts state, loading bar, toasts for server errors, retries for availability checks, automatic re-check when a grid time rolls off the server, and a prompt when a newer cycle starts. Preferences are kept in `localStorage`.
- **Keyboard**: ←/→ step, Space play, [ ] loop speed, 1–4 base grid, B barbs, F/I/C/W overlays, L panel, R reset view, ? help.
- **Config** (`window.OCEANIC_CONFIG`): `geojsonDir`, `homeLink`, and `demoFrontsDir` to load synthetic fronts from `tools/gen_demo_fronts.py` instead of the PGEN feed (used for the static copy at https://jkrek17.github.io/web/ndfd/).
- **Code**: `js/oceanic.js` (app shell), `js/oceanic-ndfd.js` (grids), `js/oceanic-frames.js` (preloaded frames), `js/oceanic-pgen.js` (fronts rendering), `css/oceanic.css`.

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
├── oceanic.html               # MapLibre globe: NDFD + OPC fronts
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
├── css/ , js/                 # navy.html / oceanic.html assets
└── libs/
    ├── leaflet/
    ├── chartjs/
    ├── maplibre/              # MapLibre GL JS 5.x (oceanic.html)
    ├── turf/                  # Turf 7 (front/isobar smoothing)
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
