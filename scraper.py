#!/usr/bin/env python3
"""
Weather Forecast Scraper for OPC Weather Map
Fetches and parses NWS/OPC offshore forecast products
"""

import json
import re
from datetime import datetime
from urllib.request import urlopen, Request
from urllib.error import URLError, HTTPError
import os

# Offshore forecast URLs from OPC
OFFSHORE_URLS = {
    "NT1": "https://ocean.weather.gov/shtml/NFDOFFNT1.txt",
    "NT2": "https://ocean.weather.gov/shtml/NFDOFFNT2.txt",
    "PZ5": "https://ocean.weather.gov/shtml/NFDOFFPZ5.txt",
    "PZ6": "https://ocean.weather.gov/shtml/NFDOFFPZ6.txt"
}

# NAVTEX forecast URLs from OPC
NAVTEX_URLS = {
    "N01": "https://ocean.weather.gov/shtml/NFDOFFN01.php",
    "N02": "https://ocean.weather.gov/shtml/NFDOFFN02.php",
    "N03": "https://ocean.weather.gov/shtml/NFDOFFN03.php",
    "N07": "https://ocean.weather.gov/shtml/NFDOFFN07.php",
    "N08": "https://ocean.weather.gov/shtml/NFDOFFN08.php",
    "N09": "https://ocean.weather.gov/shtml/NFDOFFN09.php"
}

# NAVTEX zone mappings by product
NAVTEX_ZONE_MAPPINGS = {
    "N01": ["OFFN01_NE", "OFFN01_SE", "OFFN01_SW"],
    "N02": ["OFFN02_NE", "OFFN02_E", "OFFN02_SE"],
    "N03": ["OFFN03_NE", "OFFN03_SE"],
    "N07": ["OFFN07_NW", "OFFN07_SW"],
    "N08": ["OFFN08_NW", "OFFN08_SW"],
    "N09": ["OFFN09_NW", "OFFN09_SW"]
}

# Zone mappings by product
ZONE_MAPPINGS = {
    "NT1": ["ANZ800", "ANZ805", "ANZ900", "ANZ810", "ANZ815"],
    "NT2": ["ANZ820", "ANZ915", "ANZ920", "ANZ905", "ANZ910", "ANZ825", "ANZ828", "ANZ925", "ANZ830", "ANZ833", "ANZ930", "ANZ835", "ANZ935"],
    "PZ5": ["PZZ800", "PZZ900", "PZZ805", "PZZ905", "PZZ810", "PZZ910", "PZZ815", "PZZ915"],
    "PZ6": ["PZZ820", "PZZ920", "PZZ825", "PZZ925", "PZZ830", "PZZ930", "PZZ835", "PZZ935", "PZZ840", "PZZ940", "PZZ945"]
}

# Zone names for display
ZONE_NAMES = {
    "ANZ800": "East of Great South Channel and south of Georges Bank",
    "ANZ805": "Georges Bank between Cape Cod and 68W north of 1000 FM",
    "ANZ810": "South of Georges Bank between 68W and 65W",
    "ANZ815": "Gulf of Maine to Georges Bank",
    "ANZ820": "South of New England between 69W and 71W",
    "ANZ825": "East of New Jersey to 1000 Fathoms",
    "ANZ828": "Delaware Bay to Virginia",
    "ANZ830": "Virginia to NC Offshore",
    "ANZ833": "Cape Hatteras Area",
    "ANZ835": "South of Cape Hatteras",
    "ANZ900": "Georges Bank - Outer Continental Shelf",
    "ANZ905": "East of 69W between 39N and 1000 Fathoms",
    "ANZ910": "East of 69W and south of 39N to 250 NM offshore",
    "ANZ915": "South of New England - Outer waters",
    "ANZ920": "East of 69W - Southern section",
    "ANZ925": "Virginia Coast - Offshore",
    "ANZ930": "Cape Hatteras - Offshore",
    "ANZ935": "South Atlantic - Offshore",
    "PZZ800": "Point St. George to Cape Mendocino out to 60 NM",
    "PZZ805": "Cape Mendocino to Point Arena out to 60 NM",
    "PZZ810": "Point Arena to Pigeon Point out to 60 NM",
    "PZZ815": "Pigeon Point to Point Piedras Blancas out to 60 NM",
    "PZZ820": "Point Piedras Blancas to Point Conception out to 60 NM",
    "PZZ825": "Point Conception to Santa Cruz Island out to 60 NM",
    "PZZ830": "Santa Cruz Island to San Clemente Island out to 60 NM",
    "PZZ835": "San Clemente Island to Mexican Border out to 60 NM",
    "PZZ840": "Point St. George to Oregon Border out to 60 NM",
    "PZZ900": "Point St. George to Cape Mendocino 60 to 150 NM offshore",
    "PZZ905": "Cape Mendocino to Point Arena 60 to 150 NM offshore",
    "PZZ910": "Point Arena to Pigeon Point 60 to 150 NM offshore",
    "PZZ915": "Pigeon Point to Point Piedras Blancas 60 to 150 NM offshore",
    "PZZ920": "Point Piedras Blancas to Point Conception 60 to 150 NM offshore",
    "PZZ925": "Point Conception to Santa Cruz Island 60 to 150 NM offshore",
    "PZZ930": "Santa Cruz Island to San Clemente Island 60 to 150 NM offshore",
    "PZZ935": "San Clemente Island to Mexican Border 60 to 150 NM offshore",
    "PZZ940": "Oregon Border to WA coast 60 to 150 NM offshore",
    "PZZ945": "WA Coast 60 to 150 NM offshore"
}

# NAVTEX zones matching navtex.geojson
NAVTEX_ZONES = {
    "OFFN09_NW": "Canadian Border to 45N",
    "OFFN09_SW": "45N to Point Saint George",
    "OFFN08_NW": "Point Saint George to Point Arena",
    "OFFN08_SW": "Point Arena to Point Piedras Blancas",
    "OFFN07_NW": "Point Piedras Blancas to Point Conception",
    "OFFN07_SW": "Point Conception to Mexican Border",
    "OFFN01_NE": "Eastport Maine to Cape Cod",
    "OFFN01_SE": "Cape Cod to Nantucket Shoals and Georges Bank",
    "OFFN01_SW": "South of New England",
    "OFFN02_NE": "Sandy Hook to Wallops Island",
    "OFFN02_E": "Wallops Island to Cape Hatteras",
    "OFFN02_SE": "Cape Hatteras to Murrells Inlet",
    "OFFN03_NE": "Murrells Inlet to 31N",
    "OFFN03_SE": "South of 31N"
}


def fetch_text(url):
    """Fetch text content from URL"""
    try:
        req = Request(url, headers={'User-Agent': 'Mozilla/5.0 (compatible; OPCWeatherMap/1.0)'})
        with urlopen(req, timeout=30) as response:
            return response.read().decode('utf-8', errors='ignore')
    except (URLError, HTTPError) as e:
        print("Failed to fetch {}: {}".format(url, e))
        return None


def extract_warning(text):
    """Extract warning type from forecast text"""
    warnings_map = [
        ("HURRICANE FORCE WIND WARNING", "HURRICANE FORCE WIND WARNING"),
        ("HURRICANE WARNING", "HURRICANE WARNING"),
        ("STORM WARNING", "STORM WARNING"),
        ("TROPICAL STORM WARNING", "TROPICAL STORM WARNING"),
        ("GALE WARNING", "GALE WARNING"),
        ("GALE FORCE", "GALE FORCE POSSIBLE"),
        ("STORM FORCE", "STORM FORCE POSSIBLE"),
        ("TROPICAL STORM CONDITIONS", "TROPICAL STORM CONDITIONS POSSIBLE")
    ]

    text_upper = text.upper()
    for pattern, warning_type in warnings_map:
        if pattern in text_upper:
            return warning_type
    return "NONE"


def parse_offshore_product(content, zones):
    """Parse offshore forecast product"""
    results = []

    if not content:
        return results

    # Extract issue time
    issue_time = ""
    time_match = re.search(r'(\d{3,4}\s*(?:AM|PM)\s*\w+\s+\w+\s+\w+\s+\d+\s+\d{4})', content, re.IGNORECASE)
    if time_match:
        issue_time = time_match.group(1).strip()

    # Process each zone
    for zone in zones:
        zone_data = {
            "zone": zone,
            "name": ZONE_NAMES.get(zone, zone),
            "time": issue_time or datetime.now().strftime("%I:%M %p %Z %a %b %d %Y"),
            "warning": "NONE",
            "forecast": []
        }

        # Find zone section - look for zone ID followed by content until next zone or $$
        zone_pattern = r'{}[^A-Z]*[-\d]+[-\s\n]+(.*?)(?={}|\$\$|ANZ\d|PZZ\d|$)'.format(
            re.escape(zone),
            "|".join(re.escape(z) for z in zones if z != zone)
        )
        zone_match = re.search(zone_pattern, content, re.IGNORECASE | re.DOTALL)

        if zone_match:
            zone_text = zone_match.group(1)

            # Extract warning from zone text
            zone_data["warning"] = extract_warning(zone_text)

            # Parse forecast periods - look for .DAY... pattern
            period_pattern = r'\.([A-Z][A-Z\s]*?)\.\.\.([^$]*?)(?=\.[A-Z][A-Z\s]*?\.\.\.|$)'
            period_matches = re.findall(period_pattern, zone_text, re.DOTALL)

            for period_name, period_text in period_matches:
                period_name = period_name.strip()

                # Skip if not a valid day/period name
                valid_starts = ["TODAY", "TONIGHT", "MON", "TUE", "WED", "THU", "FRI", "SAT", "SUN", "REST"]
                is_valid = any(period_name.upper().startswith(v) for v in valid_starts)

                if not is_valid:
                    continue

                # Clean up text
                clean_text = re.sub(r'\s+', ' ', period_text).strip()

                # Extract wind info
                wind_match = re.search(r'([NSEW]{1,2}(?:\s+TO\s+[NSEW]{1,2})?\s+(?:WINDS?\s+)?(\d+)\s*(?:TO\s*)?(\d+)?\s*KT)', clean_text, re.IGNORECASE)
                if wind_match:
                    winds = wind_match.group(0).strip()
                else:
                    # Try simpler pattern
                    wind_match2 = re.search(r'(\d+)\s*(?:TO\s*)?(\d+)?\s*KT', clean_text, re.IGNORECASE)
                    winds = clean_text[:60] if not wind_match2 else "Winds {} KT".format(wind_match2.group(0))

                # Extract seas info
                seas_match = re.search(r'(?:SEAS?|COMBINED\s+SEAS?)\s+(\d+)\s*(?:TO\s*)?(\d+)?\s*FT', clean_text, re.IGNORECASE)
                if seas_match:
                    low = seas_match.group(1)
                    high = seas_match.group(2) or low
                    seas = "Seas {} to {} ft".format(low, high)
                else:
                    seas_match2 = re.search(r'(\d+)\s+TO\s+(\d+)\s*FT', clean_text, re.IGNORECASE)
                    if seas_match2:
                        seas = "Seas {} to {} ft".format(seas_match2.group(1), seas_match2.group(2))
                    else:
                        seas = "Seas variable"

                # Extract weather conditions
                weather = "N/A"
                weather_patterns = ['rain', 'snow', 'fog', 'tstms', 'thunderstorms', 'showers', 'freezing spray', 'drizzle']
                for wp in weather_patterns:
                    if wp in clean_text.lower():
                        weather_match = re.search(r'((?:chance\s+of\s+|isolated\s+|scattered\s+)?(?:{})[^.]*?)(?:\.|$)'.format(wp), clean_text, re.IGNORECASE)
                        if weather_match:
                            weather = weather_match.group(1).strip().capitalize()
                            break

                zone_data["forecast"].append({
                    "Day": period_name.title(),
                    "Winds": winds.capitalize() if winds else "Variable winds",
                    "Seas": seas,
                    "Weather": weather
                })

        # Ensure at least some forecast data exists
        if not zone_data["forecast"]:
            zone_data["forecast"].append({
                "Day": "Today",
                "Winds": "Data unavailable",
                "Seas": "Data unavailable",
                "Weather": "N/A"
            })

        results.append(zone_data)

    return results


def scrape_forecasts():
    """Main scraper function for offshore forecasts"""
    all_forecasts = []

    for product, url in OFFSHORE_URLS.items():
        print("Fetching {}...".format(product))
        content = fetch_text(url)
        if content:
            zones = ZONE_MAPPINGS[product]
            forecasts = parse_offshore_product(content, zones)
            all_forecasts.extend(forecasts)
            print("  Parsed {} zones".format(len(forecasts)))
        else:
            print("  Failed to fetch")

    return all_forecasts


def parse_navtex_product(content, zones):
    """Parse NAVTEX forecast product"""
    results = []

    if not content:
        return results

    # Extract issue time
    issue_time = ""
    time_match = re.search(r'(\d{3,4}\s*(?:AM|PM)\s*\w+\s+\w+\s+\w+\s+\d+\s+\d{4})', content, re.IGNORECASE)
    if time_match:
        issue_time = time_match.group(1).strip()

    # Process each zone
    for zone in zones:
        zone_data = {
            "zone": zone,
            "name": NAVTEX_ZONES.get(zone, zone),
            "time": issue_time or datetime.now().strftime("%I:%M %p %Z %a %b %d %Y"),
            "warning": "NONE",
            "forecast": []
        }

        # Find zone section - NAVTEX uses zone names, look for the zone pattern
        # Try to find content between zone markers
        zone_pattern = r'{}[^A-Z]*[-\d]*[-\s\n]+(.*?)(?={}|\$\$|OFFN\d|$)'.format(
            re.escape(zone),
            "|".join(re.escape(z) for z in zones if z != zone)
        )
        zone_match = re.search(zone_pattern, content, re.IGNORECASE | re.DOTALL)

        if zone_match:
            zone_text = zone_match.group(1)

            # Extract warning from zone text
            zone_data["warning"] = extract_warning(zone_text)

            # Parse forecast periods
            period_pattern = r'\.([A-Z][A-Z\s]*?)\.\.\.([^$]*?)(?=\.[A-Z][A-Z\s]*?\.\.\.|$)'
            period_matches = re.findall(period_pattern, zone_text, re.DOTALL)

            for period_name, period_text in period_matches:
                period_name = period_name.strip()

                valid_starts = ["TODAY", "TONIGHT", "MON", "TUE", "WED", "THU", "FRI", "SAT", "SUN", "REST"]
                is_valid = any(period_name.upper().startswith(v) for v in valid_starts)

                if not is_valid:
                    continue

                clean_text = re.sub(r'\s+', ' ', period_text).strip()

                # Extract wind info
                wind_match = re.search(r'([NSEW]{1,2}(?:\s+TO\s+[NSEW]{1,2})?\s+(?:WINDS?\s+)?(\d+)\s*(?:TO\s*)?(\d+)?\s*KT)', clean_text, re.IGNORECASE)
                if wind_match:
                    winds = wind_match.group(0).strip()
                else:
                    wind_match2 = re.search(r'(\d+)\s*(?:TO\s*)?(\d+)?\s*KT', clean_text, re.IGNORECASE)
                    winds = clean_text[:60] if not wind_match2 else "Winds {} KT".format(wind_match2.group(0))

                # Extract seas info
                seas_match = re.search(r'(?:SEAS?|COMBINED\s+SEAS?)\s+(\d+)\s*(?:TO\s*)?(\d+)?\s*FT', clean_text, re.IGNORECASE)
                if seas_match:
                    low = seas_match.group(1)
                    high = seas_match.group(2) or low
                    seas = "Seas {} to {} ft".format(low, high)
                else:
                    seas_match2 = re.search(r'(\d+)\s+TO\s+(\d+)\s*FT', clean_text, re.IGNORECASE)
                    if seas_match2:
                        seas = "Seas {} to {} ft".format(seas_match2.group(1), seas_match2.group(2))
                    else:
                        seas = "Seas variable"

                # Extract weather conditions
                weather = "N/A"
                weather_patterns = ['rain', 'snow', 'fog', 'tstms', 'thunderstorms', 'showers', 'freezing spray', 'drizzle']
                for wp in weather_patterns:
                    if wp in clean_text.lower():
                        weather_match = re.search(r'((?:chance\s+of\s+|isolated\s+|scattered\s+)?(?:{})[^.]*?)(?:\.|$)'.format(wp), clean_text, re.IGNORECASE)
                        if weather_match:
                            weather = weather_match.group(1).strip().capitalize()
                            break

                zone_data["forecast"].append({
                    "Day": period_name.title(),
                    "Winds": winds.capitalize() if winds else "Variable winds",
                    "Seas": seas,
                    "Weather": weather
                })

        # Ensure at least some forecast data exists
        if not zone_data["forecast"]:
            zone_data["forecast"].append({
                "Day": "Today",
                "Winds": "Data unavailable",
                "Seas": "Data unavailable",
                "Weather": "N/A"
            })

        results.append(zone_data)

    return results


def scrape_navtex_forecasts():
    """Scrape NAVTEX forecasts from OPC"""
    all_forecasts = []

    for product, url in NAVTEX_URLS.items():
        print("Fetching NAVTEX {}...".format(product))
        content = fetch_text(url)
        if content:
            zones = NAVTEX_ZONE_MAPPINGS.get(product, [])
            forecasts = parse_navtex_product(content, zones)
            all_forecasts.extend(forecasts)
            print("  Parsed {} zones".format(len(forecasts)))
        else:
            print("  Failed to fetch")

    return all_forecasts


def main():
    """Main function"""
    script_dir = os.path.dirname(os.path.abspath(__file__))

    print("OPC Weather Map - Forecast Scraper")
    print("=" * 40)

    # Scrape live offshore data
    print("\nFetching live offshore forecast data...")
    offshore_data = scrape_forecasts()

    if not offshore_data:
        print("Warning: No offshore data retrieved")

    # Scrape live NAVTEX data
    print("\nFetching live NAVTEX forecast data...")
    navtex_data = scrape_navtex_forecasts()

    if not navtex_data:
        print("Warning: No NAVTEX data retrieved")

    # Save JSON files
    off_path = os.path.join(script_dir, 'off.json')
    nav_path = os.path.join(script_dir, 'nav.json')

    if offshore_data:
        with open(off_path, 'w') as f:
            json.dump(offshore_data, f, indent=2)
        print("\nSaved: {} ({} zones)".format(off_path, len(offshore_data)))
    else:
        print("\nSkipped off.json - no data")

    if navtex_data:
        with open(nav_path, 'w') as f:
            json.dump(navtex_data, f, indent=2)
        print("Saved: {} ({} zones)".format(nav_path, len(navtex_data)))
    else:
        print("Skipped nav.json - no data")

    print("\nDone!")


if __name__ == "__main__":
    main()
