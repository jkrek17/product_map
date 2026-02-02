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
- **Multiple Products**: Offshore and NAVTEX forecasts
- **Region Selection**: Western Atlantic and Eastern Pacific

## Quick Start

### Option 1: With PHP Server (Recommended - Live Data)

If you have PHP installed, data is fetched live from NWS on each page load:

```bash
php -S localhost:8000
```

Navigate to http://localhost:8000

### Option 2: With Python (Static Data)

Generate static data files first, then serve:

```bash
python3 scraper.py    # Fetch and save data
python3 serve.py      # Start server
```

Navigate to http://localhost:8000

## API Endpoint

`api.php` provides live forecast data:

- `api.php?type=offshore` - Offshore forecasts (37 zones)
- `api.php?type=navtex` - NAVTEX forecasts (14 zones)

Data is fetched directly from NWS servers on each request.

## Data Sources

Live forecast data from NOAA Ocean Prediction Center:

- NT1: https://ocean.weather.gov/shtml/NFDOFFNT1.txt
- NT2: https://ocean.weather.gov/shtml/NFDOFFNT2.txt
- PZ5: https://ocean.weather.gov/shtml/NFDOFFPZ5.txt
- PZ6: https://ocean.weather.gov/shtml/NFDOFFPZ6.txt

## File Structure

```
├── index.html          # Main application
├── scraper.py          # Forecast data scraper
├── serve.py            # Local test server
├── off.json            # Offshore forecast data
├── nav.json            # NAVTEX forecast data
├── offshores.geojson   # Offshore zone polygons
├── navtex.geojson      # NAVTEX zone polygons
├── libs/
│   ├── leaflet/        # Leaflet 1.9.4
│   └── chartjs/        # Chart.js 4.4.1
└── README.md
```

## Automation

Set up a cron job to keep data current:

```bash
# Run every 6 hours
0 */6 * * * cd /path/to/project && python3 scraper.py
```

## Requirements

- Python 3.x
- Modern web browser
- Network access to NOAA servers

## License

NWS forecast data is in the public domain.
