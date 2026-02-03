# OPC Weather Map

Interactive marine weather forecast visualization from the NOAA Ocean Prediction Center. Displays offshore forecast zones color-coded by active weather warnings.

## Features

- **Live Data**: Fetches real-time forecasts from NWS Ocean Prediction Center
- **Interactive Map**: Leaflet-based map with offshore forecast zones
- **Warning Visualization**: Zones color-coded by warning severity:
  - Red: Hurricane Force Wind Warning
  - Orange: Storm Warning / Tropical Storm Warning
  - Yellow: Gale Warning
  - Pink: Gale Force Possible
  - Purple: Storm Force Possible
  - Gray: No Warning
- **Forecast Details**: Click any zone to view detailed forecast text
- **Wind & Wave Charts**: Time series visualization of conditions
- **Multiple Products**: Offshore, NAVTEX, and Navy forecasts
- **Region Selection**: Western Atlantic and Eastern Pacific

## Pages

- `index.html` - Main offshore and NAVTEX forecast map
- `navy.html` - Navy OPAREA forecasts

## API Endpoints

`api.php` serves forecast data:

- `api.php?type=offshore` - Offshore forecasts
- `api.php?type=navtex` - NAVTEX forecasts
- `api.php?type=diagnose` - Check data file status
- Add `&debug=1` to any request for detailed debug info

## Data Sources

Forecast data from NOAA Ocean Prediction Center:

**Offshore Forecasts:**
- NT1: https://ocean.weather.gov/shtml/NFDOFFNT1.txt (Georges Bank, Gulf of Maine)
- NT2: https://ocean.weather.gov/shtml/NFDOFFNT2.txt (Mid-Atlantic, South Atlantic)
- PZ5: https://ocean.weather.gov/shtml/NFDOFFPZ5.txt (Northern California)
- PZ6: https://ocean.weather.gov/shtml/NFDOFFPZ6.txt (Southern California, Pacific Northwest)

**NAVTEX Forecasts:**
- N01: https://ocean.weather.gov/shtml/NFDOFFN01.php (New England)
- N02: https://ocean.weather.gov/shtml/NFDOFFN02.php (Mid-Atlantic)
- N03: https://ocean.weather.gov/shtml/NFDOFFN03.php (South Atlantic)
- N07: https://ocean.weather.gov/shtml/NFDOFFN07.php (Southern California)
- N08: https://ocean.weather.gov/shtml/NFDOFFN08.php (Northern California)
- N09: https://ocean.weather.gov/shtml/NFDOFFN09.php (Pacific Northwest)

## File Structure

```
├── index.html              # Main application
├── api.php                 # API endpoint for forecast data
├── getText.php             # Text data fetcher
├── navy.html               # Navy forecasts page
├── navy.css                # Navy page styles
├── navy.js                 # Navy page JavaScript
├── offshore-forecasts.json # Offshore forecast data
├── navtex-forecasts.json   # NAVTEX forecast data
├── offshores.geojson       # Offshore zone polygons
├── navtex.geojson          # NAVTEX zone polygons
├── navy.geojson            # Navy OPAREA polygons (Atlantic & Pacific)
├── libs/
│   ├── leaflet/            # Leaflet 1.9.4
│   └── chartjs/            # Chart.js 4.4.1
└── README.md
```

## Requirements

- PHP server
- Modern web browser

## License

NWS forecast data is in the public domain.
