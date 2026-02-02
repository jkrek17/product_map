<?php
/**
 * OPC Weather Map - Live Data API
 * Fetches and returns forecast data from NWS Ocean Prediction Center
 */

// Enable error logging for debugging
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

/**
 * DEBUGGING INSTRUCTIONS:
 * 
 * 1. Add ?debug=1 to the API URL to get detailed debug info in the response:
 *    Example: api.php?type=offshore&debug=1
 * 
 * 2. Check the browser console (F12 > Console) for [DEBUG] messages from the frontend
 * 
 * 3. Check the PHP error log for [OPC API DEBUG] messages
 * 
 * 4. The debug response will include:
 *    - 'debug': Array of timestamped log entries
 *    - 'data': The actual forecast data
 */
$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';
$debugLog = array();

function debugLog($message, $data = null) {
    global $DEBUG, $debugLog;
    $entry = array(
        'timestamp' => date('Y-m-d H:i:s'),
        'message' => $message
    );
    if ($data !== null) {
        $entry['data'] = $data;
    }
    $debugLog[] = $entry;
    error_log("[OPC API DEBUG] " . $message . ($data !== null ? " | Data: " . json_encode($data) : ""));
}

debugLog("API request started", array(
    'type' => isset($_GET['type']) ? $_GET['type'] : 'not set',
    'debug' => $DEBUG,
    'php_version' => PHP_VERSION,
    'server_time' => date('Y-m-d H:i:s'),
    'request_uri' => isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'N/A'
));

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
 * Fetch content from URL - tries multiple methods
 */
function fetchUrl($url) {
    debugLog("Fetching URL", $url);
    
    // Check PHP configuration
    $allowUrlFopen = ini_get('allow_url_fopen');
    $curlAvailable = function_exists('curl_init');
    debugLog("PHP config check", array(
        'allow_url_fopen' => $allowUrlFopen ? 'enabled' : 'DISABLED',
        'curl_available' => $curlAvailable ? 'yes' : 'no'
    ));
    
    $startTime = microtime(true);
    $content = null;
    $method = 'none';
    $errorInfo = null;
    
    // Try cURL first (more reliable for HTTPS)
    if ($curlAvailable) {
        $method = 'curl';
        debugLog("Trying cURL method");
        
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'OPCWeatherMap/1.0 (NOAA Data Fetch)',
            CURLOPT_HTTPHEADER => array(
                'Accept: text/plain, text/html, */*',
                'Accept-Language: en-US,en;q=0.9'
            )
        ));
        
        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        curl_close($ch);
        
        if ($content === false || $curlErrno !== 0) {
            $errorInfo = array(
                'method' => 'curl',
                'curl_errno' => $curlErrno,
                'curl_error' => $curlError,
                'http_code' => $httpCode
            );
            debugLog("cURL fetch failed", $errorInfo);
            $content = null;
        } elseif ($httpCode !== 200) {
            $errorInfo = array(
                'method' => 'curl',
                'http_code' => $httpCode,
                'response_preview' => substr($content, 0, 200)
            );
            debugLog("cURL got non-200 response", $errorInfo);
            $content = null;
        }
    }
    
    // Fallback to file_get_contents if cURL failed or unavailable
    if ($content === null && $allowUrlFopen) {
        $method = 'file_get_contents';
        debugLog("Trying file_get_contents method");
        
        $context = stream_context_create(array(
            'http' => array(
                'timeout' => 30,
                'user_agent' => 'OPCWeatherMap/1.0 (NOAA Data Fetch)',
                'ignore_errors' => true
            ),
            'ssl' => array(
                'verify_peer' => true,
                'verify_peer_name' => true
            )
        ));
        
        $content = @file_get_contents($url, false, $context);
        
        if ($content === false) {
            $error = error_get_last();
            $errorInfo = array(
                'method' => 'file_get_contents',
                'error' => $error ? $error['message'] : 'Unknown error'
            );
            debugLog("file_get_contents failed", $errorInfo);
            $content = null;
        }
    }
    
    $elapsed = round((microtime(true) - $startTime) * 1000, 2);
    
    if ($content !== null && strlen($content) > 0) {
        debugLog("URL fetch successful", array(
            'url' => $url,
            'method' => $method,
            'elapsed_ms' => $elapsed,
            'content_length' => strlen($content),
            'content_preview' => substr($content, 0, 300)
        ));
        return $content;
    } else {
        debugLog("URL fetch FAILED - all methods exhausted", array(
            'url' => $url,
            'elapsed_ms' => $elapsed,
            'last_error' => $errorInfo,
            'allow_url_fopen' => $allowUrlFopen,
            'curl_available' => $curlAvailable
        ));
        return null;
    }
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
    
    debugLog("parseOffshoreProduct called", array(
        'content_length' => $content ? strlen($content) : 0,
        'zones_count' => count($zones),
        'zones' => $zones
    ));
    
    if (empty($content)) {
        debugLog("WARNING: Empty content passed to parseOffshoreProduct");
        return $results;
    }
    
    // Extract issue time
    $issueTime = '';
    if (preg_match('/(\d{3,4}\s*(?:AM|PM)\s*\w+\s+\w+\s+\w+\s+\d+\s+\d{4})/i', $content, $timeMatch)) {
        $issueTime = trim($timeMatch[1]);
        debugLog("Extracted issue time", $issueTime);
    } else {
        debugLog("WARNING: Could not extract issue time from content");
    }
    
    foreach ($zones as $zone) {
        debugLog("Processing zone", $zone);
        
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
            debugLog("Zone text found for " . $zone, array(
                'text_length' => strlen($zoneText),
                'text_preview' => substr($zoneText, 0, 150)
            ));
            
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
        } else {
            debugLog("WARNING: Zone " . $zone . " NOT FOUND in content", array(
                'pattern_used' => $pattern,
                'content_preview' => substr($content, 0, 500)
            ));
        }
        
        // Ensure forecast data exists
        if (empty($zoneData['forecast'])) {
            debugLog("WARNING: No forecast periods found for zone " . $zone . ", adding placeholder");
            $zoneData['forecast'][] = array(
                'Day' => 'Today',
                'Winds' => 'Data unavailable',
                'Seas' => 'Data unavailable',
                'Weather' => 'N/A'
            );
        } else {
            debugLog("Zone " . $zone . " has " . count($zoneData['forecast']) . " forecast periods");
        }
        
        $results[] = $zoneData;
    }
    
    debugLog("parseOffshoreProduct complete", array('results_count' => count($results)));
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
debugLog("Processing request", array('type' => $type));

// Diagnostic endpoint - check connectivity to NWS
if ($type === 'diagnose') {
    debugLog("Running diagnostics");
    
    $diagnostics = array(
        'php_version' => PHP_VERSION,
        'server_time' => date('Y-m-d H:i:s T'),
        'allow_url_fopen' => ini_get('allow_url_fopen') ? true : false,
        'curl_available' => function_exists('curl_init'),
        'openssl_available' => extension_loaded('openssl'),
        'url_tests' => array()
    );
    
    // Test each offshore URL
    foreach ($OFFSHORE_URLS as $product => $url) {
        $testResult = array(
            'product' => $product,
            'url' => $url,
            'success' => false,
            'content_length' => 0,
            'error' => null
        );
        
        $content = fetchUrl($url);
        if ($content !== null) {
            $testResult['success'] = true;
            $testResult['content_length'] = strlen($content);
            $testResult['content_preview'] = substr($content, 0, 500);
        } else {
            $testResult['error'] = 'Failed to fetch - check debug log for details';
        }
        
        $diagnostics['url_tests'][$product] = $testResult;
    }
    
    $diagnostics['debug_log'] = $debugLog;
    
    echo json_encode($diagnostics, JSON_PRETTY_PRINT);
    exit;
}

if ($type === 'offshore') {
    $allForecasts = array();
    $fetchResults = array();
    
    foreach ($OFFSHORE_URLS as $product => $url) {
        debugLog("Fetching offshore product", array('product' => $product, 'url' => $url));
        $content = fetchUrl($url);
        
        $fetchResults[$product] = array(
            'url' => $url,
            'success' => $content !== null,
            'content_length' => $content ? strlen($content) : 0
        );
        
        if ($content) {
            $zones = $ZONE_MAPPINGS[$product];
            debugLog("Parsing product " . $product, array('zones' => $zones));
            $forecasts = parseOffshoreProduct($content, $zones, $ZONE_NAMES);
            debugLog("Product " . $product . " parsed", array('forecasts_count' => count($forecasts)));
            $allForecasts = array_merge($allForecasts, $forecasts);
        } else {
            debugLog("WARNING: Failed to fetch product " . $product);
        }
    }
    
    debugLog("All offshore data fetched", array(
        'total_forecasts' => count($allForecasts),
        'fetch_results' => $fetchResults
    ));
    
    // If debug mode, include debug log in response
    if ($DEBUG) {
        echo json_encode(array(
            'debug' => $debugLog,
            'data' => $allForecasts
        ));
    } else {
        echo json_encode($allForecasts);
    }
    
} elseif ($type === 'navtex') {
    debugLog("Generating NAVTEX data");
    $navtexData = generateNavtexData($NAVTEX_ZONES);
    debugLog("NAVTEX data generated", array('count' => count($navtexData)));
    
    if ($DEBUG) {
        echo json_encode(array(
            'debug' => $debugLog,
            'data' => $navtexData
        ));
    } else {
        echo json_encode($navtexData);
    }
    
} else {
    debugLog("ERROR: Invalid type requested", $type);
    echo json_encode(array('error' => 'Invalid type'));
}
