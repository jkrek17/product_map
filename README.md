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

## API Endpoints

`api.php` serves forecast data from static JSON files:

- `api.php?type=offshore` - Offshore forecasts (37 zones)
- `api.php?type=navtex` - NAVTEX forecasts (14 zones)
- `api.php?type=diagnose` - Check data file status
- Add `&debug=1` to any request for detailed debug info

Data is loaded from `offshore-forecasts.json` and `navtex-forecasts.json` files, which are updated by running `scraper.py`.

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
├── navy.html               # Navy forecasts page
├── scraper.py              # Forecast data scraper
├── serve.py                # Local test server
├── offshore-forecasts.json # Offshore forecast data
├── navtex-forecasts.json   # NAVTEX forecast data
├── offshores.geojson       # Offshore zone polygons
├── navtex.geojson          # NAVTEX zone polygons
├── navy-san-diego.geojson  # Navy San Diego OPAREA polygons
├── navy.css                # Navy page styles
├── navy.js                 # Navy page JavaScript
├── libs/
│   ├── leaflet/            # Leaflet 1.9.4
│   └── chartjs/            # Chart.js 4.4.1
└── README.md
```

## Automation with Cron

The scraper should be run periodically to keep forecast data current. NWS updates forecasts approximately every 6 hours.

### Setting Up the Cron Job

1. **Open crontab editor:**
   ```bash
   crontab -e
   ```

2. **Add one of these schedules:**

   ```bash
   # Every 6 hours (recommended - matches NWS update schedule)
   0 */6 * * * cd /path/to/project && /usr/bin/python3 scraper.py >> /path/to/project/scraper.log 2>&1

   # Every 3 hours (more frequent updates)
   0 */3 * * * cd /path/to/project && /usr/bin/python3 scraper.py >> /path/to/project/scraper.log 2>&1

   # Twice daily (6 AM and 6 PM)
   0 6,18 * * * cd /path/to/project && /usr/bin/python3 scraper.py >> /path/to/project/scraper.log 2>&1
   ```

3. **Save and exit** (`:wq` in vim, or Ctrl+X in nano)

### Important Notes

- **Use full paths**: Cron runs with minimal environment, so use full paths for `python3` (find with `which python3`)
- **Logging**: The `>> scraper.log 2>&1` part saves output for debugging
- **Permissions**: Ensure the script has execute permissions: `chmod +x scraper.py`

### Verify Cron is Running

```bash
# List your cron jobs
crontab -l

# Check cron logs (Linux)
grep CRON /var/log/syslog

# Check scraper output
tail -f /path/to/project/scraper.log
```

### Remote Server Setup

If your web server can't access external URLs (firewall), run the scraper elsewhere and sync files:

```bash
# On local machine with internet access
0 */6 * * * cd /path/to/project && python3 scraper.py && scp offshore-forecasts.json navtex-forecasts.json user@server:/var/www/html/project/
```

Or use rsync for more reliability:
```bash
0 */6 * * * cd /path/to/project && python3 scraper.py && rsync -avz offshore-forecasts.json navtex-forecasts.json user@server:/var/www/html/project/
```

## Requirements

- Python 3.x
- Modern web browser
- Network access to NOAA servers

## License

NWS forecast data is in the public domain.
