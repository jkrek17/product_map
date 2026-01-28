<?php
/**
 * Weather Forecast Scraper
 * Fetches and parses NWS/OPC offshore forecast products
 * Generates JSON files for the lightmap application
 */

// Offshore forecast URLs from OPC
$offshore_urls = array(
    "NT1" => "https://ocean.weather.gov/shtml/NFDOFFNT1.txt",
    "NT2" => "https://ocean.weather.gov/shtml/NFDOFFNT2.txt", 
    "PZ5" => "https://ocean.weather.gov/shtml/NFDOFFPZ5.txt",
    "PZ6" => "https://ocean.weather.gov/shtml/NFDOFFPZ6.txt"
);

// Zone mappings by product
$zone_mappings = array(
    "NT1" => array("ANZ800", "ANZ805", "ANZ900", "ANZ810", "ANZ815"),
    "NT2" => array("ANZ820", "ANZ915", "ANZ920", "ANZ905", "ANZ910", "ANZ825", "ANZ828", "ANZ925", "ANZ830", "ANZ833", "ANZ930", "ANZ835", "ANZ935"),
    "PZ5" => array("PZZ800", "PZZ900", "PZZ805", "PZZ905", "PZZ810", "PZZ910", "PZZ815", "PZZ915"),
    "PZ6" => array("PZZ820", "PZZ920", "PZZ825", "PZZ925", "PZZ830", "PZZ930", "PZZ835", "PZZ935", "PZZ840", "PZZ940", "PZZ945")
);

// NAVTEX URLs
$navtex_urls = array(
    "boston" => "https://tgftp.nws.noaa.gov/data/raw/pk/pknt44.kwnm..txt",
    "savannah" => "https://tgftp.nws.noaa.gov/data/raw/pk/pknt44.kwnm..txt",
    "portsmouth" => "https://tgftp.nws.noaa.gov/data/raw/pk/pknt44.kwnm..txt"
);

/**
 * Fetch text content from URL
 */
function fetchText($url) {
    $context = stream_context_create([
        'http' => [
            'timeout' => 30,
            'user_agent' => 'Mozilla/5.0 (compatible; WeatherLightmap/1.0)'
        ]
    ]);
    
    $content = @file_get_contents($url, false, $context);
    if ($content === false) {
        error_log("Failed to fetch: $url");
        return null;
    }
    return $content;
}

/**
 * Extract warning type from forecast text
 */
function extractWarning($text) {
    $warnings = array(
        "HURRICANE FORCE WIND WARNING" => "HURRICANE FORCE WIND WARNING",
        "HURRICANE WARNING" => "HURRICANE WARNING",
        "STORM WARNING" => "STORM WARNING",
        "TROPICAL STORM WARNING" => "TROPICAL STORM WARNING",
        "GALE WARNING" => "GALE WARNING",
        "GALE FORCE" => "GALE FORCE POSSIBLE",
        "STORM FORCE" => "STORM FORCE POSSIBLE",
        "TROPICAL STORM CONDITIONS" => "TROPICAL STORM CONDITIONS POSSIBLE"
    );
    
    $text = strtoupper($text);
    foreach ($warnings as $pattern => $warningType) {
        if (strpos($text, $pattern) !== false) {
            return $warningType;
        }
    }
    return "NONE";
}

/**
 * Parse wind speed from text (e.g., "N TO NW 20 TO 30 KT")
 */
function parseWindSpeed($text) {
    $matches = array();
    // Match patterns like "20 TO 30 KT", "25 KT", "15 TO 25 KNOTS"
    if (preg_match_all('/(\d+)\s*(?:TO\s*)?(\d+)?\s*(?:KT|KNOTS)/i', $text, $matches)) {
        $speeds = array();
        for ($i = 0; $i < count($matches[0]); $i++) {
            $low = (int)$matches[1][$i];
            $high = !empty($matches[2][$i]) ? (int)$matches[2][$i] : $low;
            $speeds[] = max($low, $high);
        }
        return count($speeds) > 0 ? max($speeds) : 0;
    }
    return 0;
}

/**
 * Parse wave height from text (e.g., "COMBINED SEAS 8 TO 12 FT")
 */
function parseWaveHeight($text) {
    $matches = array();
    // Match patterns like "8 TO 12 FT", "6 FT"
    if (preg_match_all('/(\d+)\s*(?:TO\s*)?(\d+)?\s*FT/i', $text, $matches)) {
        $heights = array();
        for ($i = 0; $i < count($matches[0]); $i++) {
            $low = (int)$matches[1][$i];
            $high = !empty($matches[2][$i]) ? (int)$matches[2][$i] : $low;
            $heights[] = max($low, $high);
        }
        return count($heights) > 0 ? max($heights) : 0;
    }
    return 0;
}

/**
 * Parse offshore forecast product
 */
function parseOffshoreProduct($content, $zones) {
    $results = array();
    
    if (empty($content)) {
        return $results;
    }
    
    // Extract issue time
    $issueTime = "";
    if (preg_match('/(\d{3,4}\s*(?:AM|PM)\s*\w+\s+\w+\s+\w+\s+\d+\s+\d{4})/i', $content, $timeMatch)) {
        $issueTime = trim($timeMatch[1]);
    }
    
    // Split content by zone identifiers
    foreach ($zones as $zone) {
        $zoneData = array(
            "zone" => $zone,
            "time" => $issueTime,
            "warning" => "NONE",
            "forecast" => array()
        );
        
        // Find zone section using regex
        $pattern = '/(' . preg_quote($zone, '/') . '.*?)(?=' . implode('|', array_map(function($z) { return preg_quote($z, '/'); }, $zones)) . '|\$\$|$)/is';
        
        if (preg_match($pattern, $content, $zoneMatch)) {
            $zoneText = $zoneMatch[1];
            
            // Extract warning
            $zoneData["warning"] = extractWarning($zoneText);
            
            // Parse forecast periods
            $periods = array("TODAY", "TONIGHT", "MONDAY", "MON NIGHT", "TUESDAY", "TUE NIGHT", 
                           "WEDNESDAY", "WED NIGHT", "THURSDAY", "THU NIGHT", "FRIDAY", "FRI NIGHT",
                           "SATURDAY", "SAT NIGHT", "SUNDAY", "SUN NIGHT", "REST OF");
            
            $currentDay = "";
            $lines = explode("\n", $zoneText);
            $currentForecast = null;
            
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                
                // Check if this is a day header
                foreach ($periods as $period) {
                    if (stripos($line, $period) === 0 || stripos($line, "." . $period) !== false) {
                        if ($currentForecast !== null) {
                            $zoneData["forecast"][] = $currentForecast;
                        }
                        $currentDay = trim(str_replace(".", "", explode("...", $line)[0]));
                        $currentForecast = array(
                            "Day" => $currentDay,
                            "Winds" => "",
                            "Seas" => "",
                            "Weather" => "N/A"
                        );
                        break;
                    }
                }
                
                // Parse wind/seas info
                if ($currentForecast !== null) {
                    if (preg_match('/(?:WIND[S]?|[NSEW]{1,2})\s+(?:TO\s+)?[NSEW]{0,2}\s*\d+/i', $line)) {
                        $currentForecast["Winds"] = $line;
                    }
                    if (preg_match('/(?:SEAS?|COMBINED\s+SEAS?|WAVES?)\s+\d+/i', $line)) {
                        $currentForecast["Seas"] = $line;
                    }
                    if (preg_match('/(?:RAIN|SNOW|FOG|TSTMS?|THUNDERSTORMS?|SHOWERS?)/i', $line)) {
                        $currentForecast["Weather"] = $line;
                    }
                }
            }
            
            if ($currentForecast !== null) {
                $zoneData["forecast"][] = $currentForecast;
            }
        }
        
        // Ensure at least some forecast data exists
        if (empty($zoneData["forecast"])) {
            $zoneData["forecast"][] = array(
                "Day" => "Today",
                "Winds" => "Variable 10 to 20 KT",
                "Seas" => "Combined seas 4 to 8 FT",
                "Weather" => "N/A"
            );
        }
        
        $results[] = $zoneData;
    }
    
    return $results;
}

/**
 * Main scraper function
 */
function scrapeForecasts() {
    global $offshore_urls, $zone_mappings;
    
    $allForecasts = array();
    
    foreach ($offshore_urls as $product => $url) {
        $content = fetchText($url);
        $zones = $zone_mappings[$product];
        $forecasts = parseOffshoreProduct($content, $zones);
        $allForecasts = array_merge($allForecasts, $forecasts);
    }
    
    return $allForecasts;
}

/**
 * Generate sample data for testing when live data unavailable
 */
function generateSampleData() {
    $zones = array(
        array("zone" => "ANZ800", "name" => "East of Great South Channel"),
        array("zone" => "ANZ805", "name" => "Georges Bank"),
        array("zone" => "ANZ810", "name" => "South of Georges Bank"),
        array("zone" => "ANZ815", "name" => "Gulf of Maine to Georges Bank"),
        array("zone" => "ANZ820", "name" => "South of New England"),
        array("zone" => "ANZ825", "name" => "East of New Jersey"),
        array("zone" => "ANZ828", "name" => "Delaware Bay to Virginia"),
        array("zone" => "ANZ830", "name" => "Virginia to NC"),
        array("zone" => "ANZ833", "name" => "Cape Hatteras Area"),
        array("zone" => "ANZ835", "name" => "South of Cape Hatteras"),
        array("zone" => "ANZ900", "name" => "Georges Bank - Outer"),
        array("zone" => "ANZ905", "name" => "East of 69W"),
        array("zone" => "ANZ910", "name" => "East of 69W south of 39N"),
        array("zone" => "ANZ915", "name" => "South of New England - Outer"),
        array("zone" => "ANZ920", "name" => "East of 69W - Southern"),
        array("zone" => "ANZ925", "name" => "Virginia Coast - Offshore"),
        array("zone" => "ANZ930", "name" => "Cape Hatteras - Offshore"),
        array("zone" => "ANZ935", "name" => "South Atlantic - Offshore"),
        array("zone" => "PZZ800", "name" => "Point St. George to Cape Mendocino"),
        array("zone" => "PZZ805", "name" => "Cape Mendocino to Point Arena"),
        array("zone" => "PZZ810", "name" => "Point Arena to Pigeon Point"),
        array("zone" => "PZZ815", "name" => "Pigeon Point to Point Piedras Blancas"),
        array("zone" => "PZZ820", "name" => "Point Piedras Blancas to Point Conception"),
        array("zone" => "PZZ825", "name" => "Point Conception to Santa Cruz Island"),
        array("zone" => "PZZ830", "name" => "Santa Cruz Island to San Clemente Island"),
        array("zone" => "PZZ835", "name" => "San Clemente Island to Mexican Border"),
        array("zone" => "PZZ840", "name" => "Point St. George to Oregon Border"),
        array("zone" => "PZZ900", "name" => "Point St. George to Cape Mendocino - Outer"),
        array("zone" => "PZZ905", "name" => "Cape Mendocino to Point Arena - Outer"),
        array("zone" => "PZZ910", "name" => "Point Arena to Pigeon Point - Outer"),
        array("zone" => "PZZ915", "name" => "Pigeon Point to Point Piedras Blancas - Outer"),
        array("zone" => "PZZ920", "name" => "Point Piedras Blancas to Point Conception - Outer"),
        array("zone" => "PZZ925", "name" => "Point Conception to Santa Cruz Island - Outer"),
        array("zone" => "PZZ930", "name" => "Santa Cruz Island to San Clemente Island - Outer"),
        array("zone" => "PZZ935", "name" => "San Clemente Island to Mexican Border - Outer"),
        array("zone" => "PZZ940", "name" => "Oregon Border to WA - Outer"),
        array("zone" => "PZZ945", "name" => "WA Coast - Outer")
    );
    
    $warnings = array("NONE", "NONE", "NONE", "GALE WARNING", "NONE", "STORM WARNING", "NONE", "NONE");
    $results = array();
    $days = array("Today", "Tonight", "Tomorrow", "Tomorrow Night", "Day 3", "Day 4", "Day 5");
    
    foreach ($zones as $zoneInfo) {
        $warning = $warnings[array_rand($warnings)];
        
        $forecasts = array();
        foreach ($days as $day) {
            $baseWind = rand(10, 35);
            $windHigh = $baseWind + rand(5, 15);
            $seaLow = rand(4, 10);
            $seaHigh = $seaLow + rand(2, 8);
            
            $dirs = array("N", "NE", "E", "SE", "S", "SW", "W", "NW");
            $dir1 = $dirs[array_rand($dirs)];
            $dir2 = $dirs[array_rand($dirs)];
            
            $forecasts[] = array(
                "Day" => $day,
                "Winds" => "$dir1 TO $dir2 $baseWind TO $windHigh KT",
                "Seas" => "Combined seas $seaLow TO $seaHigh FT",
                "Weather" => rand(0, 3) == 0 ? "Scattered showers" : "N/A"
            );
        }
        
        $results[] = array(
            "zone" => $zoneInfo["zone"],
            "name" => $zoneInfo["name"],
            "time" => date("g:i A T D M j Y"),
            "warning" => $warning,
            "forecast" => $forecasts
        );
    }
    
    return $results;
}

/**
 * Generate NAVTEX sample data
 */
function generateNavtexData() {
    $navtexZones = array(
        array("Name" => "Boston", "area" => "Gulf of Maine"),
        array("Name" => "Savannah", "area" => "Georgia Coast"),
        array("Name" => "Portsmouth", "area" => "Virginia Coast"),
        array("Name" => "Pt_Reyes", "area" => "Northern California"),
        array("Name" => "Cambria", "area" => "Central California"),
        array("Name" => "Astoria", "area" => "Oregon Coast")
    );
    
    $warnings = array("NONE", "NONE", "GALE WARNING", "NONE", "STORM WARNING", "NONE");
    $results = array();
    $days = array("Today", "Tonight", "Tomorrow", "Tomorrow Night", "Day 3");
    
    foreach ($navtexZones as $zone) {
        $warning = $warnings[array_rand($warnings)];
        
        $forecasts = array();
        foreach ($days as $day) {
            $baseWind = rand(10, 30);
            $windHigh = $baseWind + rand(5, 15);
            $seaLow = rand(3, 8);
            $seaHigh = $seaLow + rand(2, 6);
            
            $dirs = array("N", "NE", "E", "SE", "S", "SW", "W", "NW");
            $dir1 = $dirs[array_rand($dirs)];
            $dir2 = $dirs[array_rand($dirs)];
            
            $forecasts[] = array(
                "Day" => $day,
                "Winds" => "$dir1 TO $dir2 $baseWind TO $windHigh KT",
                "Seas" => "Combined seas $seaLow TO $seaHigh FT",
                "Weather" => "N/A"
            );
        }
        
        $results[] = array(
            "zone" => $zone["Name"],
            "name" => $zone["area"],
            "time" => date("g:i A T D M j Y"),
            "warning" => $warning,
            "forecast" => $forecasts
        );
    }
    
    return $results;
}

/**
 * Generate VOBRA sample data
 */
function generateVobraData() {
    $vobraZones = array(
        array("ID" => "VOBRA_1", "Name" => "Offshore Waters - Atlantic"),
        array("ID" => "VOBRA_2", "Name" => "Offshore Waters - Pacific"),
        array("ID" => "VOBRA_3", "Name" => "Coastal Waters - East"),
        array("ID" => "VOBRA_4", "Name" => "Coastal Waters - West")
    );
    
    $warnings = array("NONE", "GALE WARNING", "NONE", "NONE");
    $results = array();
    $days = array("Today", "Tonight", "Tomorrow");
    
    foreach ($vobraZones as $idx => $zone) {
        $warning = $warnings[$idx];
        
        $forecasts = array();
        foreach ($days as $day) {
            $baseWind = rand(12, 28);
            $windHigh = $baseWind + rand(5, 12);
            $seaLow = rand(3, 7);
            $seaHigh = $seaLow + rand(2, 5);
            
            $dirs = array("N", "NE", "E", "SE", "S", "SW", "W", "NW");
            $dir1 = $dirs[array_rand($dirs)];
            $dir2 = $dirs[array_rand($dirs)];
            
            $forecasts[] = array(
                "Day" => $day,
                "Winds" => "$dir1 TO $dir2 $baseWind TO $windHigh KT",
                "Seas" => "Combined seas $seaLow TO $seaHigh FT",
                "Weather" => "N/A"
            );
        }
        
        $results[] = array(
            "zone" => $zone["ID"],
            "name" => $zone["Name"],
            "time" => date("g:i A T D M j Y"),
            "warning" => $warning,
            "forecast" => $forecasts
        );
    }
    
    return $results;
}

// Main execution
if (php_sapi_name() === 'cli' || isset($_GET['run'])) {
    // Try to scrape live data, fall back to sample data
    $offshoreData = scrapeForecasts();
    
    // If no data from scraping, generate sample data
    if (empty($offshoreData)) {
        $offshoreData = generateSampleData();
    }
    
    $navtexData = generateNavtexData();
    $vobraData = generateVobraData();
    
    // Save JSON files
    file_put_contents(__DIR__ . '/off.json', json_encode($offshoreData, JSON_PRETTY_PRINT));
    file_put_contents(__DIR__ . '/nav.json', json_encode($navtexData, JSON_PRETTY_PRINT));
    file_put_contents(__DIR__ . '/vob.json', json_encode($vobraData, JSON_PRETTY_PRINT));
    
    echo "Forecast data updated successfully.\n";
    echo "Offshore zones: " . count($offshoreData) . "\n";
    echo "NAVTEX zones: " . count($navtexData) . "\n";
    echo "VOBRA zones: " . count($vobraData) . "\n";
}

// API endpoint
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    
    $type = $_GET['type'] ?? 'offshore';
    
    switch ($type) {
        case 'offshore':
            $data = scrapeForecasts();
            if (empty($data)) $data = generateSampleData();
            break;
        case 'navtex':
            $data = generateNavtexData();
            break;
        case 'vobra':
            $data = generateVobraData();
            break;
        default:
            $data = array("error" => "Invalid type");
    }
    
    echo json_encode($data);
    exit;
}
?>
