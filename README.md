# Weather Warning Lightmap

An interactive marine weather forecast visualization application that displays NWS/OPC offshore forecast zones on a map, color-coded by active weather warnings.

## Features

- **Interactive Map**: Leaflet-based map displaying offshore forecast zones
- **Warning Visualization**: Zones are color-coded based on active warnings:
  - Red: Hurricane Force Wind Warning / Hurricane Warning
  - Orange: Storm Warning / Tropical Storm Warning
  - Yellow: Gale Warning
  - Pink: Gale Force Possible
  - Purple: Storm Force Possible
  - Gray: No Warning
- **Forecast Details**: Click any zone to view detailed forecast text
- **Wind & Wave Charts**: Chart.js visualization of wind speed and wave height over time
- **Multiple Products**: Support for Offshore and NAVTEX forecasts
- **Region Selection**: Western Atlantic and Eastern Pacific basins
- **Offline Capable**: All libraries included locally (no CDN dependencies)

## File Structure

```
├── index.html          # Main application
├── scraper.py          # Python script to fetch/parse NWS forecasts
├── serve.py            # Simple HTTP server for local testing
├── off.json            # Offshore forecast data
├── nav.json            # NAVTEX forecast data
├── offshores.geojson   # Offshore zone polygons
├── navtex.geojson      # NAVTEX zone polygons
├── libs/
│   ├── leaflet/        # Leaflet 1.9.4
│   │   ├── leaflet.js
│   │   ├── leaflet.css
│   │   └── images/
│   └── chartjs/        # Chart.js 4.4.1
│       └── chart.min.js
└── README.md
```

## Quick Start

### 1. Update Forecast Data

Run the scraper to fetch the latest forecast data from NWS:

```bash
python3 scraper.py
```

This will generate/update `off.json`, `nav.json`, and `vob.json` with current forecast data.

### 2. Start Local Server

```bash
python3 serve.py
```

### 3. Open Application

Navigate to `http://localhost:8000` in your browser.

## Data Sources

The scraper fetches forecast data from NOAA/NWS Ocean Prediction Center:

- **NT1**: https://ocean.weather.gov/shtml/NFDOFFNT1.txt
- **NT2**: https://ocean.weather.gov/shtml/NFDOFFNT2.txt
- **PZ5**: https://ocean.weather.gov/shtml/NFDOFFPZ5.txt
- **PZ6**: https://ocean.weather.gov/shtml/NFDOFFPZ6.txt

## Usage

1. **Select Region**: Choose Western Atlantic or Eastern Pacific from the dropdown
2. **Select Product**: Choose Offshore, NAVTEX, or VOBRA forecasts
3. **View Warnings**: Zones are automatically colored based on active warnings
4. **Click Zones**: Click any zone to view detailed forecast in the sidebar
5. **View Charts**: Wind and wave forecasts are displayed as a time series chart
6. **Refresh Data**: Click "Refresh Data" to fetch latest forecasts

## Automation

To keep forecast data current, set up a cron job to run the scraper periodically:

```bash
# Run every 6 hours
0 */6 * * * cd /path/to/project && python3 scraper.py
```

## Requirements

- Python 3.x (for scraper and local server)
- Modern web browser with JavaScript enabled
- Network access to NOAA/NWS servers (for live data updates)

## Libraries

All libraries are included locally:

- **Leaflet 1.9.4** - Interactive maps
- **Chart.js 4.4.1** - Wind/wave charts

## License

This project uses publicly available NWS forecast data. NWS data is in the public domain.
