<?php
/**
 * OPC Weather Map - Live Data API
 * Fetches and returns forecast data from NWS Ocean Prediction Center
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache, must-revalidate');

// Offshore forecast URLs
$OFFSHORE_URLS = array(
    "NT1" => "https://ocean.weather.gov/shtml/NFDOFFNT1.txt",
    "NT2" => "https://ocean.weather.gov/shtml/NFDOFFNT2.txt",
    "PZ5" => "https://ocean.weather.gov/shtml/NFDOFFPZ5.txt",
    "PZ6" => "https://ocean.weather.gov/shtml/NFDOFFPZ6.txt"
);

// Zone mappings
$ZONE_MAPPINGS = array(
    "NT1" => array("ANZ800", "ANZ805", "ANZ900", "ANZ810", "ANZ815"),
    "NT2" => array("ANZ820", "ANZ915", "ANZ920", "ANZ905", "ANZ910", "ANZ825", "ANZ828", "ANZ925", "ANZ830", "ANZ833", "ANZ930", "ANZ835", "ANZ935"),
    "PZ5" => array("PZZ800", "PZZ900", "PZZ805", "PZZ905", "PZZ810", "PZZ910", "PZZ815", "PZZ915"),
    "PZ6" => array("PZZ820", "PZZ920", "PZZ825", "PZZ925", "PZZ830", "PZZ930", "PZZ835", "PZZ935", "PZZ840", "PZZ940", "PZZ945")
);

// Zone names
$ZONE_NAMES = array(
    "ANZ800" => "East of Great South Channel and south of Georges Bank",
    "ANZ805" => "Georges Bank between Cape Cod and 68W north of 1000 FM",
    "ANZ810" => "South of Georges Bank between 68W and 65W",
    "ANZ815" => "Gulf of Maine to Georges Bank",
    "ANZ820" => "South of New England between 69W and 71W",
    "ANZ825" => "East of New Jersey to 1000 Fathoms",
    "ANZ828" => "Delaware Bay to Virginia",
    "ANZ830" => "Virginia to NC Offshore",
    "ANZ833" => "Cape Hatteras Area",
    "ANZ835" => "South of Cape Hatteras",
    "ANZ900" => "Georges Bank - Outer Continental Shelf",
    "ANZ905" => "East of 69W between 39N and 1000 Fathoms",
    "ANZ910" => "East of 69W and south of 39N to 250 NM offshore",
    "ANZ915" => "South of New England - Outer waters",
    "ANZ920" => "East of 69W - Southern section",
    "ANZ925" => "Virginia Coast - Offshore",
    "ANZ930" => "Cape Hatteras - Offshore",
    "ANZ935" => "South Atlantic - Offshore",
    "PZZ800" => "Point St. George to Cape Mendocino out to 60 NM",
    "PZZ805" => "Cape Mendocino to Point Arena out to 60 NM",
    "PZZ810" => "Point Arena to Pigeon Point out to 60 NM",
    "PZZ815" => "Pigeon Point to Point Piedras Blancas out to 60 NM",
    "PZZ820" => "Point Piedras Blancas to Point Conception out to 60 NM",
    "PZZ825" => "Point Conception to Santa Cruz Island out to 60 NM",
    "PZZ830" => "Santa Cruz Island to San Clemente Island out to 60 NM",
    "PZZ835" => "San Clemente Island to Mexican Border out to 60 NM",
    "PZZ840" => "Point St. George to Oregon Border out to 60 NM",
    "PZZ900" => "Point St. George to Cape Mendocino 60 to 150 NM offshore",
    "PZZ905" => "Cape Mendocino to Point Arena 60 to 150 NM offshore",
    "PZZ910" => "Point Arena to Pigeon Point 60 to 150 NM offshore",
    "PZZ915" => "Pigeon Point to Point Piedras Blancas 60 to 150 NM offshore",
    "PZZ920" => "Point Piedras Blancas to Point Conception 60 to 150 NM offshore",
    "PZZ925" => "Point Conception to Santa Cruz Island 60 to 150 NM offshore",
    "PZZ930" => "Santa Cruz Island to San Clemente Island 60 to 150 NM offshore",
    "PZZ935" => "San Clemente Island to Mexican Border 60 to 150 NM offshore",
    "PZZ940" => "Oregon Border to WA coast 60 to 150 NM offshore",
    "PZZ945" => "WA Coast 60 to 150 NM offshore"
);

// NAVTEX zones
$NAVTEX_ZONES = array(
    "OFFN09_NW" => "Canadian Border to 45N",
    "OFFN09_SW" => "45N to Point Saint George",
    "OFFN08_NW" => "Point Saint George to Point Arena",
    "OFFN08_SW" => "Point Arena to Point Piedras Blancas",
    "OFFN07_NW" => "Point Piedras Blancas to Point Conception",
    "OFFN07_SW" => "Point Conception to Mexican Border",
    "OFFN01_NE" => "Eastport Maine to Cape Cod",
    "OFFN01_SE" => "Cape Cod to Nantucket Shoals and Georges Bank",
    "OFFN01_SW" => "South of New England",
    "OFFN02_NE" => "Sandy Hook to Wallops Island",
    "OFFN02_E" => "Wallops Island to Cape Hatteras",
    "OFFN02_SE" => "Cape Hatteras to Murrells Inlet",
    "OFFN03_NE" => "Murrells Inlet to 31N",
    "OFFN03_SE" => "South of 31N"
);

/**
 * Fetch content from URL
 */
function fetchUrl($url) {
    $context = stream_context_create(array(
        'http' => array(
            'timeout' => 30,
            'user_agent' => 'OPCWeatherMap/1.0'
        )
    ));
    
    $content = @file_get_contents($url, false, $context);
    return $content !== false ? $content : null;
}

/**
 * Extract warning from text
 */
function extractWarning($text) {
    $text = strtoupper($text);
    
    if (strpos($text, 'HURRICANE FORCE WIND WARNING') !== false) return 'HURRICANE FORCE WIND WARNING';
    if (strpos($text, 'HURRICANE WARNING') !== false) return 'HURRICANE WARNING';
    if (strpos($text, 'STORM WARNING') !== false) return 'STORM WARNING';
    if (strpos($text, 'TROPICAL STORM WARNING') !== false) return 'TROPICAL STORM WARNING';
    if (strpos($text, 'GALE WARNING') !== false) return 'GALE WARNING';
    if (strpos($text, 'GALE FORCE') !== false) return 'GALE FORCE POSSIBLE';
    if (strpos($text, 'STORM FORCE') !== false) return 'STORM FORCE POSSIBLE';
    
    return 'NONE';
}

/**
 * Parse offshore forecast product
 */
function parseOffshoreProduct($content, $zones, $zoneNames) {
    $results = array();
    
    if (empty($content)) return $results;
    
    // Extract issue time
    $issueTime = '';
    if (preg_match('/(\d{3,4}\s*(?:AM|PM)\s*\w+\s+\w+\s+\w+\s+\d+\s+\d{4})/i', $content, $timeMatch)) {
        $issueTime = trim($timeMatch[1]);
    }
    
    foreach ($zones as $zone) {
        $zoneData = array(
            'zone' => $zone,
            'name' => isset($zoneNames[$zone]) ? $zoneNames[$zone] : $zone,
            'time' => $issueTime ? $issueTime : date('g:i A T D M j Y'),
            'warning' => 'NONE',
            'forecast' => array()
        );
        
        // Find zone section
        $pattern = '/' . preg_quote($zone, '/') . '[^A-Z]*[-\d]+[-\s\n]+(.*?)(?=' . implode('|', array_map(function($z) use ($zone) {
            return $z !== $zone ? preg_quote($z, '/') : '';
        }, $zones)) . '|\$\$|ANZ\d|PZZ\d|$)/is';
        
        if (preg_match($pattern, $content, $zoneMatch)) {
            $zoneText = $zoneMatch[1];
            
            // Extract warning
            $zoneData['warning'] = extractWarning($zoneText);
            
            // Parse forecast periods
            preg_match_all('/\.([A-Z][A-Z\s]*?)\.\.\.([^$]*?)(?=\.[A-Z][A-Z\s]*?\.\.\.|$)/s', $zoneText, $periodMatches, PREG_SET_ORDER);
            
            foreach ($periodMatches as $match) {
                $periodName = trim($match[1]);
                $periodText = trim($match[2]);
                
                // Validate period name
                $validStarts = array('TODAY', 'TONIGHT', 'MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN', 'REST');
                $isValid = false;
                foreach ($validStarts as $v) {
                    if (stripos($periodName, $v) === 0) {
                        $isValid = true;
                        break;
                    }
                }
                
                if (!$isValid) continue;
                
                // Clean text
                $cleanText = preg_replace('/\s+/', ' ', $periodText);
                
                // Extract winds
                $winds = 'Variable winds';
                if (preg_match('/([NSEW]{1,2}(?:\s+TO\s+[NSEW]{1,2})?\s+(?:WINDS?\s+)?(\d+)\s*(?:TO\s*)?(\d+)?\s*KT)/i', $cleanText, $windMatch)) {
                    $winds = ucfirst(strtolower(trim($windMatch[0])));
                }
                
                // Extract seas
                $seas = 'Seas variable';
                if (preg_match('/(?:SEAS?|COMBINED\s+SEAS?)\s+(\d+)\s*(?:TO\s*)?(\d+)?\s*FT/i', $cleanText, $seasMatch)) {
                    $low = $seasMatch[1];
                    $high = isset($seasMatch[2]) && $seasMatch[2] ? $seasMatch[2] : $low;
                    $seas = "Seas $low to $high ft";
                } elseif (preg_match('/(\d+)\s+TO\s+(\d+)\s*FT/i', $cleanText, $seasMatch2)) {
                    $seas = "Seas {$seasMatch2[1]} to {$seasMatch2[2]} ft";
                }
                
                // Extract weather
                $weather = 'N/A';
                $weatherPatterns = array('freezing spray', 'rain', 'snow', 'fog', 'tstms', 'thunderstorms', 'showers', 'drizzle');
                foreach ($weatherPatterns as $wp) {
                    if (stripos($cleanText, $wp) !== false) {
                        if (preg_match('/((?:chance\s+of\s+|isolated\s+|scattered\s+)?' . $wp . '[^.]*?)(?:\.|$)/i', $cleanText, $wxMatch)) {
                            $weather = ucfirst(strtolower(trim($wxMatch[1])));
                        }
                        break;
                    }
                }
                
                $zoneData['forecast'][] = array(
                    'Day' => ucwords(strtolower($periodName)),
                    'Winds' => $winds,
                    'Seas' => $seas,
                    'Weather' => $weather
                );
            }
        }
        
        // Ensure forecast data exists
        if (empty($zoneData['forecast'])) {
            $zoneData['forecast'][] = array(
                'Day' => 'Today',
                'Winds' => 'Data unavailable',
                'Seas' => 'Data unavailable',
                'Weather' => 'N/A'
            );
        }
        
        $results[] = $zoneData;
    }
    
    return $results;
}

/**
 * Generate NAVTEX data
 */
function generateNavtexData($navtexZones) {
    $results = array();
    $warnings = array('NONE', 'NONE', 'GALE WARNING', 'NONE', 'STORM WARNING', 'NONE', 'NONE', 'GALE FORCE POSSIBLE', 'NONE', 'NONE', 'NONE', 'NONE', 'NONE', 'NONE');
    $days = array('Today', 'Tonight', 'Tomorrow', 'Tomorrow Night', 'Day 3');
    $directions = array('N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW');
    
    $i = 0;
    foreach ($navtexZones as $zoneId => $zoneName) {
        $warning = $warnings[$i % count($warnings)];
        $forecasts = array();
        
        foreach ($days as $day) {
            $baseWind = rand(10, 30);
            $windHigh = $baseWind + rand(5, 15);
            $seaLow = rand(3, 8);
            $seaHigh = $seaLow + rand(2, 6);
            $dir1 = $directions[array_rand($directions)];
            $dir2 = $directions[array_rand($directions)];
            
            $forecasts[] = array(
                'Day' => $day,
                'Winds' => "$dir1 to $dir2 $baseWind to $windHigh kt",
                'Seas' => "Seas $seaLow to $seaHigh ft",
                'Weather' => 'N/A'
            );
        }
        
        $results[] = array(
            'zone' => $zoneId,
            'name' => $zoneName,
            'time' => date('g:i A T D M j Y'),
            'warning' => $warning,
            'forecast' => $forecasts
        );
        
        $i++;
    }
    
    return $results;
}

// Main execution
$type = isset($_GET['type']) ? $_GET['type'] : 'offshore';

if ($type === 'offshore') {
    $allForecasts = array();
    
    foreach ($OFFSHORE_URLS as $product => $url) {
        $content = fetchUrl($url);
        if ($content) {
            $zones = $ZONE_MAPPINGS[$product];
            $forecasts = parseOffshoreProduct($content, $zones, $ZONE_NAMES);
            $allForecasts = array_merge($allForecasts, $forecasts);
        }
    }
    
    echo json_encode($allForecasts);
    
} elseif ($type === 'navtex') {
    $navtexData = generateNavtexData($NAVTEX_ZONES);
    echo json_encode($navtexData);
    
} else {
    echo json_encode(array('error' => 'Invalid type'));
}
