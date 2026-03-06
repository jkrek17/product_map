<?php
/**
 * OPC Weather Map - Live Data API
 * Reads forecast data from local NWS text files on the server
 */

// =============================================================================
// CONFIGURATION - Path to local NWS data directory
// =============================================================================
// The NWS text files are served from the /shtml/ directory on this server.
// This auto-detects the path based on the web server's document root.
// Example: if DocumentRoot is /home/www, files are at /home/www/shtml/
//
// You can override this by setting a specific path:
// $LOCAL_DATA_DIR = "/home/www/shtml";
$LOCAL_DATA_DIR = $_SERVER['DOCUMENT_ROOT'] . "/shtml";

// Offshore forecast files (relative to LOCAL_DATA_DIR)
$OFFSHORE_FILES = array(
    "NT1" => "NFDOFFNT1.txt",
    "NT2" => "NFDOFFNT2.txt",
    "NT3" => "MIAOFFNT3.txt",
    "NT4" => "MIAOFFNT4.txt",
    "NT5" => "MIAOFFNT5.txt",
    "PZ5" => "NFDOFFPZ5.txt",
    "PZ6" => "NFDOFFPZ6.txt",
    "PZ7" => "MIAOFFPZ7.txt",
    "PZ8" => "MIAOFFPZ8.txt"
);

// NAVTEX forecast files (relative to LOCAL_DATA_DIR)
$NAVTEX_FILES = array(
    "N01" => "NFDOFFN01.txt",
    "N02" => "NFDOFFN02.txt",
    "N03" => "NFDOFFN03.txt",
    "N04" => "MIAOFFN04.txt",
    "N05" => "MIAOFFN05.txt",
    "N06" => "MIAOFFN06.txt",
    "N07" => "NFDOFFN07.txt",
    "N08" => "NFDOFFN08.txt",
    "N09" => "NFDOFFN09.txt"
);

// NAVTEX zone name mappings (text in file -> zone ID for GeoJSON)
$NAVTEX_NAME_TO_ID = array(
    // N09 - Pacific Northwest
    "Canadian border to 45N" => "OFFN09_NW",
    "45N to Point Saint George" => "OFFN09_SW",
    // N08 - Northern California
    "Point Saint George to Point Arena" => "OFFN08_NW",
    "Point Arena to Point Piedras Blancas" => "OFFN08_SW",
    // N07 - Southern California
    "Point Piedras Blancas to Point Conception" => "OFFN07_NW",
    "Point Conception to Mexican Border" => "OFFN07_SW",
    // N01 - New England
    "Eastport Maine to Cape Cod" => "OFFN01_NE",
    "Eastport ME to Cape Cod" => "OFFN01_NE",
    "Cape Cod to Nantucket Shoals and Georges Bank" => "OFFN01_SE",
    "South of New England" => "OFFN01_SW",
    // N02 - Mid-Atlantic
    "Sandy Hook to Wallops Island" => "OFFN02_NE",
    "Wallops Island to Cape Hatteras" => "OFFN02_E",
    "Cape Hatteras to Murrells Inlet" => "OFFN02_SE",
    // N03 - South Atlantic
    "Murrells Inlet to 31N" => "OFFN03_NE",
    "South of 31N" => "OFFN03_SE",
    // N04 - SE Gulf of America + FL Atlantic coast (Miami NAVTEX)
    "Southeast Gulf of America" => "OFFN04_SE",
    "Within 200 nm east of the coast of Florida" => "OFFN04_ATL",
    // N05 - San Juan NAVTEX (PR/VI Atlantic + Caribbean)
    "San Juan Atlantic Waters" => "OFFN05_ATL",
    "San Juan Caribbean Waters" => "OFFN05_CAR",
    // N06 - New Orleans NAVTEX (NW Gulf + NC/NE Gulf)
    "Northwest Gulf of America" => "OFFN06_NW",
    "North Central and Northeast Gulf of America" => "OFFN06_NE"
);
// =============================================================================

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
    'request_uri' => isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'N/A',
    'local_data_dir' => $LOCAL_DATA_DIR
));

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache, must-revalidate');

// Zone mappings
$ZONE_MAPPINGS = array(
    "NT1" => array("ANZ800", "ANZ805", "ANZ900", "ANZ810", "ANZ815"),
    "NT2" => array("ANZ820", "ANZ915", "ANZ920", "ANZ905", "ANZ910", "ANZ825", "ANZ828", "ANZ925", "ANZ830", "ANZ833", "ANZ930", "ANZ835", "ANZ935"),
    "NT3" => array("AMZ040", "AMZ041", "AMZ042", "AMZ043", "AMZ044", "AMZ045", "AMZ046", "AMZ047", "AMZ048", "AMZ049", "AMZ050", "AMZ051", "AMZ052", "AMZ053", "AMZ054", "AMZ055", "AMZ056", "AMZ057", "AMZ058", "AMZ059", "AMZ060", "AMZ061", "AMZ062"),
    "NT4" => array("GMZ040", "GMZ041", "GMZ045", "GMZ046", "GMZ047", "GMZ048", "GMZ049", "GMZ050", "GMZ056", "GMZ057", "GMZ058"),
    "NT5" => array("AMZ063", "AMZ064", "AMZ065", "AMZ066", "AMZ067", "AMZ068", "AMZ069", "AMZ070", "AMZ071", "AMZ072", "AMZ073", "AMZ074", "AMZ075", "AMZ076", "AMZ077", "AMZ078", "AMZ079", "AMZ080", "AMZ081", "AMZ082", "AMZ083", "AMZ084", "AMZ085", "AMZ086", "AMZ087", "AMZ088"),
    "PZ5" => array("PZZ800", "PZZ900", "PZZ805", "PZZ905", "PZZ810", "PZZ910", "PZZ815", "PZZ915"),
    "PZ6" => array("PZZ820", "PZZ920", "PZZ825", "PZZ925", "PZZ830", "PZZ930", "PZZ835", "PZZ935", "PZZ840", "PZZ940", "PZZ945"),
    "PZ7" => array("PMZ009", "PMZ011", "PMZ013", "PMZ014", "PMZ016", "PMZ017", "PMZ019", "PMZ021", "PMZ022", "PMZ024", "PMZ025", "PMZ026", "PMZ028", "PMZ029"),
    "PZ8" => array("PMZ111", "PMZ113", "PMZ115", "PMZ117", "PMZ119", "PMZ121", "PMZ123")
);

// Zone names
$ZONE_NAMES = array(
    // NT1/NT2 - Northwest/Mid Atlantic
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
    // NT3 - Caribbean and Tropical N Atlantic (AMZ040-062)
    "AMZ040" => "Caribbean N of 18N W of 85W including Yucatan Basin",
    "AMZ041" => "Caribbean N of 20N E of 85W",
    "AMZ042" => "Caribbean from 18N-20N between 80W-85W including Cayman Basin",
    "AMZ043" => "Caribbean from 18N-20N between 76W-80W",
    "AMZ044" => "Caribbean approaches to the Windward Passage",
    "AMZ045" => "Gulf of Honduras",
    "AMZ046" => "Caribbean from 15N to 18N between 80W and 85W",
    "AMZ047" => "Caribbean from 15N to 18N between 76W and 80W",
    "AMZ048" => "Caribbean from 15N to 18N between 72W and 76W",
    "AMZ049" => "Caribbean N of 15N between 68W and 72W",
    "AMZ050" => "Caribbean N of 15N between 64W and 68W",
    "AMZ051" => "Offshore Waters Leeward Islands",
    "AMZ052" => "Tropical N Atlantic from 15N to 19N between 55W and 60W",
    "AMZ053" => "W Central Caribbean from 11N to 15N W of 80W",
    "AMZ054" => "Caribbean from 11N to 15N between 76W and 80W including Colombia Basin",
    "AMZ055" => "Caribbean from 11N to 15N between 72W and 76W",
    "AMZ056" => "Caribbean S of 15N between 68W and 72W",
    "AMZ057" => "Caribbean S of 15N between 64W and 68W including Venezuela Basin",
    "AMZ058" => "Offshore Waters Windward Islands including Trinidad and Tobago",
    "AMZ059" => "Tropical N Atlantic from 11N to 15N between 55W and 60W",
    "AMZ060" => "SW Caribbean S of 11N W of 80W",
    "AMZ061" => "SW Caribbean S of 11N E of 80W including approaches to the Panama Canal",
    "AMZ062" => "Tropical N Atlantic from 7N to 11N between 55W and 60W",
    // NT4 - Gulf of America (GMZ)
    "GMZ040" => "NW Gulf of America including Stetson Bank",
    "GMZ041" => "SW Louisiana Offshore Waters including Flower Garden Bank Marine Sanctuary",
    "GMZ045" => "W Central Gulf of America from 22N to 26N between 91W and 94W",
    "GMZ046" => "Central Gulf of America from 22N to 26N between 87W and 91W",
    "GMZ047" => "SE Gulf of America from 22N to 26N E of 87W including Straits of Florida",
    "GMZ048" => "SW Gulf of America S of 22N W of 94W",
    "GMZ049" => "Central Bay of Campeche",
    "GMZ050" => "E Bay of Campeche including Campeche Bank",
    "GMZ056" => "N Central Gulf of America Offshore Waters",
    "GMZ057" => "NE Gulf of America N of 26N E of 87W",
    "GMZ058" => "W Central Gulf of America from 22N to 26N W of 94W",
    // NT5 - SW North Atlantic including the Bahamas (AMZ063-088)
    "AMZ063" => "Atlantic from 29N to 31N W of 77W",
    "AMZ064" => "Atlantic from 29N to 31N between 74W and 77W",
    "AMZ065" => "Atlantic from 29N to 31N between 70W and 74W",
    "AMZ066" => "Atlantic from 29N to 31N between 65W and 70W",
    "AMZ067" => "Atlantic from 29N to 31N between 60W and 65W",
    "AMZ068" => "Atlantic from 29N to 31N between 55W and 60W",
    "AMZ069" => "Atlantic from 27N to 29N W of 77W",
    "AMZ070" => "Atlantic from 27N to 29N between 74W and 77W",
    "AMZ071" => "Atlantic from 27N to 29N between 70W and 74W",
    "AMZ072" => "Atlantic from 27N to 29N between 65W and 70W",
    "AMZ073" => "Atlantic from 27N to 29N between 60W and 65W",
    "AMZ074" => "Atlantic from 27N to 29N between 55W and 60W",
    "AMZ075" => "Northern Bahamas from 24N to 27N",
    "AMZ076" => "Atlantic from 22N to 27N E of Bahamas to 70W",
    "AMZ077" => "Atlantic from 22N to 27N between 65W and 70W",
    "AMZ078" => "Atlantic from 25N to 27N between 60W and 65W",
    "AMZ079" => "Atlantic from 25N to 27N between 55W and 60W",
    "AMZ080" => "Central Bahamas including Cay Sal Bank",
    "AMZ081" => "Atlantic from 22N to 25N E of Bahamas to 70W",
    "AMZ082" => "Atlantic from 22N to 25N between 65W and 70W",
    "AMZ083" => "Atlantic from 22N to 25N between 60W and 65W",
    "AMZ084" => "Atlantic from 22N to 25N between 55W and 60W",
    "AMZ085" => "Atlantic S of 22N W of 70W including approaches to the Windward Passage",
    "AMZ086" => "Atlantic S of 22N between 65W and 70W including Puerto Rico Trench",
    "AMZ087" => "Atlantic from 19N to 22N between 60W and 65W",
    "AMZ088" => "Atlantic from 19N to 22N between 55W and 60W",
    // PZ5/PZ6 - California / Pacific NW
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
    "PZZ945" => "WA Coast 60 to 150 NM offshore",
    // PZ7 - Eastern Pacific / Mexico (PMZ)
    "PMZ009" => "Mexico Border S to 30N to 60 NM offshore",
    "PMZ011" => "East Pacific within 270 NM S of 30N to Punta Eugenia",
    "PMZ013" => "Punta Eugenia to Cabo San Lazaro to 250 NM offshore",
    "PMZ014" => "Cabo San Lazaro to Cabo San Lucas to 250 NM offshore N of 20N",
    "PMZ016" => "From 17N to 20N between 110W and 115W including the Revillagigedo Islands",
    "PMZ017" => "Northern Gulf of California",
    "PMZ019" => "Central Gulf of California",
    "PMZ021" => "Southern Gulf of California",
    "PMZ022" => "N of 20N E of 110W including Entrance to the Gulf of California",
    "PMZ024" => "Colima and Jalisco out 300 NM offshore S of 20N and E of 110W",
    "PMZ025" => "Michoacan and Guerrero to 250 NM offshore",
    "PMZ026" => "Oaxaca W of Puerto Angel out 250 NM offshore",
    "PMZ028" => "Oaxaca E of Puerto Angel out 300 NM including the Gulf of Tehuantepec",
    "PMZ029" => "Offshore Chiapas E of 94W",
    // PZ8 - Central America / Colombia / Ecuador (PMZ)
    "PMZ111" => "Guatemala and El Salvador to 250 NM offshore",
    "PMZ113" => "El Salvador to North Costa Rica including the Gulfs of Fonseca and Papagayo",
    "PMZ115" => "North Costa Rica to West Panama to 250 NM offshore",
    "PMZ117" => "East Panama and Colombia including the Gulf of Panama",
    "PMZ119" => "Ecuador including the Gulf of Guayaquil to 250 NM offshore",
    "PMZ121" => "Ecuador between 250 and 500 NM offshore",
    "PMZ123" => "Offshore Galapagos Islands"
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
    "OFFN02_E"  => "Wallops Island to Cape Hatteras",
    "OFFN02_SE" => "Cape Hatteras to Murrells Inlet",
    "OFFN03_NE" => "Murrells Inlet to 31N",
    "OFFN03_SE" => "South of 31N",
    "OFFN04_SE"  => "Southeast Gulf of America",
    "OFFN04_ATL" => "Within 200 nm east of the coast of Florida",
    "OFFN05_ATL" => "San Juan Atlantic Waters",
    "OFFN05_CAR" => "San Juan Caribbean Waters",
    "OFFN06_NW"  => "Northwest Gulf of America",
    "OFFN06_NE"  => "North Central and Northeast Gulf of America"
);

/**
 * Parse NAVTEX forecast product
 * NAVTEX format uses zone names instead of zone IDs
 */
function parseNavtexProduct($content, $nameToId, $zoneNames) {
    $results = array();
    
    debugLog("parseNavtexProduct called", array(
        'content_length' => $content ? strlen($content) : 0
    ));
    
    if (empty($content)) {
        debugLog("WARNING: Empty content passed to parseNavtexProduct");
        return $results;
    }
    
    // Extract issue time
    $issueTime = '';
    if (preg_match('/(\d{3,4}\s*(?:AM|PM)\s*\w+\s+\w+\s+\w+\s+\d+\s+\d{4})/i', $content, $timeMatch)) {
        $issueTime = trim($timeMatch[1]);
        debugLog("Extracted issue time", $issueTime);
    }
    
    // Build a combined lookahead from all zone names so each zone section ends
    // cleanly at the start of the next zone name header (or end of file).
    $zoneNamePatterns = array_map(function($n) { return preg_quote($n, '/'); }, array_keys($nameToId));
    $zoneNamesAlternation = implode('|', $zoneNamePatterns);

    // Find each zone by its name
    foreach ($nameToId as $zoneName => $zoneId) {
        // Look for zone name at start of line, followed by forecast content.
        // Zone content ends at the next known zone name header or end of file.
        $pattern = '/^' . preg_quote($zoneName, '/') . '\s*\n(.*?)(?=^(?:' . $zoneNamesAlternation . ')\s*$|\z)/ims';
        
        if (preg_match($pattern, $content, $zoneMatch)) {
            $zoneText = $zoneMatch[1];
            
            debugLog("NAVTEX zone found: " . $zoneName, array(
                'zone_id' => $zoneId,
                'text_length' => strlen($zoneText),
                'text_preview' => substr($zoneText, 0, 300)
            ));
            
            $zoneData = array(
                'zone' => $zoneId,
                'name' => isset($zoneNames[$zoneId]) ? $zoneNames[$zoneId] : $zoneName,
                'time' => $issueTime ? $issueTime : date('g:i A T D M j Y'),
                'warning' => 'NONE',
                'forecast' => array()
            );
            
            // Extract warning
            $zoneData['warning'] = extractWarning($zoneText);
            
            // Parse forecast periods
            preg_match_all('/\.([A-Z][A-Z\s]*?)\.\.\.([^.]*(?:\.[^A-Z][^.]*)*)/s', $zoneText, $periodMatches, PREG_SET_ORDER);
            
            debugLog("NAVTEX periods for " . $zoneId, array(
                'count' => count($periodMatches),
                'periods' => array_map(function($m) { return trim($m[1]); }, $periodMatches)
            ));
            
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
                
                $cleanText = preg_replace('/\s+/', ' ', $periodText);
                
                // Extract winds
                $winds = 'Variable winds';
                if (preg_match('/([NSEW]{1,2}(?:\s+to\s+[NSEW]{1,2})?\s+winds?\s+)?(\d+)\s+to\s+(\d+)\s*kt/i', $cleanText, $windMatch)) {
                    $winds = trim($windMatch[0]);
                } elseif (preg_match('/([NSEW]{1,2})\s+(\d+)\s*kt/i', $cleanText, $windMatch2)) {
                    $winds = trim($windMatch2[0]);
                }
                
                // Extract seas
                $seas = 'Seas variable';
                if (preg_match('/Seas?\s+(\d+)\s+to\s+(\d+)\s*ft/i', $cleanText, $seasMatch)) {
                    $seas = "Seas {$seasMatch[1]} to {$seasMatch[2]} ft";
                } elseif (preg_match('/(\d+)\s+to\s+(\d+)\s*ft/i', $cleanText, $seasMatch2)) {
                    $seas = "Seas {$seasMatch2[1]} to {$seasMatch2[2]} ft";
                }
                
                // Extract weather
                $weather = 'N/A';
                $weatherPatterns = array('rain', 'snow', 'fog', 'tstms', 'thunderstorms', 'showers', 'spray', 'drizzle');
                foreach ($weatherPatterns as $wp) {
                    if (stripos($cleanText, $wp) !== false) {
                        if (preg_match('/((?:Chance of |Areas of |Isolated |Scattered )?' . $wp . '[^.]*)/i', $cleanText, $wxMatch)) {
                            $weather = ucfirst(strtolower(trim($wxMatch[1])));
                        }
                        break;
                    }
                }
                
                $zoneData['forecast'][] = array(
                    'Day' => ucwords(strtolower($periodName)),
                    'Winds' => ucfirst(strtolower($winds)),
                    'Seas' => $seas,
                    'Weather' => $weather
                );
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
    }
    
    debugLog("parseNavtexProduct complete", array('results_count' => count($results)));
    return $results;
}

/**
 * Read content from a local file
 */
function readLocalFile($filepath) {
    debugLog("Reading local file", $filepath);
    
    if (!file_exists($filepath)) {
        debugLog("File not found", $filepath);
        return null;
    }
    
    $content = @file_get_contents($filepath);
    
    if ($content === false) {
        $error = error_get_last();
        debugLog("Failed to read file", array(
            'filepath' => $filepath,
            'error' => $error ? $error['message'] : 'Unknown error'
        ));
        return null;
    }
    
    debugLog("File read successful", array(
        'filepath' => $filepath,
        'content_length' => strlen($content),
        'file_modified' => date('Y-m-d H:i:s', filemtime($filepath)),
        'content_preview' => substr($content, 0, 300)
    ));
    
    return $content;
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
        
        // Find zone section - format is: ANZ800-220345-\n...content...\n$$
        // Zone ID followed by -DDHHTT- then content until $$
        $pattern = '/' . preg_quote($zone, '/') . '-\d{6}-\s*(.*?)\n\$\$/s';
        
        if (preg_match($pattern, $content, $zoneMatch)) {
            $zoneText = $zoneMatch[1];
            debugLog("Zone text found for " . $zone, array(
                'text_length' => strlen($zoneText),
                'text_preview' => substr($zoneText, 0, 500)
            ));
            
            // Debug: show what periods we're finding
            // Periods are like: .TODAY...text .TONIGHT...text
            preg_match_all('/\.([A-Z][A-Z\s]*?)\.\.\.([^.]*(?:\.[^A-Z][^.]*)*)/s', $zoneText, $debugPeriods, PREG_SET_ORDER);
            debugLog("Period matches for " . $zone, array(
                'count' => count($debugPeriods),
                'periods' => array_map(function($m) { return trim($m[1]); }, $debugPeriods)
            ));
            
            // Extract warning
            $zoneData['warning'] = extractWarning($zoneText);
            
            // Parse forecast periods - format is .DAY...text until next .DAY... or end
            // Example: .TODAY...W winds 15 to 25 kt. Seas 4 to 7 ft. 
            preg_match_all('/\.([A-Z][A-Z\s]*?)\.\.\.([^.]*(?:\.[^A-Z][^.]*)*)/s', $zoneText, $periodMatches, PREG_SET_ORDER);
            
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


// Main execution
$type = isset($_GET['type']) ? $_GET['type'] : 'offshore';
debugLog("Processing request", array('type' => $type));

// Diagnostic endpoint - check local file status
if ($type === 'diagnose') {
    debugLog("Running diagnostics");
    
    $diagnostics = array(
        'php_version' => PHP_VERSION,
        'server_time' => date('Y-m-d H:i:s T'),
        'local_data_dir' => $LOCAL_DATA_DIR,
        'local_data_dir_exists' => is_dir($LOCAL_DATA_DIR),
        'offshore_files' => array(),
        'navtex_files' => array()
    );
    
    // Check offshore files
    foreach ($OFFSHORE_FILES as $product => $filename) {
        $filepath = $LOCAL_DATA_DIR . '/' . $filename;
        $diagnostics['offshore_files'][$product] = array(
            'filename' => $filename,
            'path' => $filepath,
            'exists' => file_exists($filepath),
            'size' => file_exists($filepath) ? filesize($filepath) : 0,
            'modified' => file_exists($filepath) ? date('Y-m-d H:i:s T', filemtime($filepath)) : null
        );
    }
    
    // Check navtex files
    foreach ($NAVTEX_FILES as $product => $filename) {
        $filepath = $LOCAL_DATA_DIR . '/' . $filename;
        $diagnostics['navtex_files'][$product] = array(
            'filename' => $filename,
            'path' => $filepath,
            'exists' => file_exists($filepath),
            'size' => file_exists($filepath) ? filesize($filepath) : 0,
            'modified' => file_exists($filepath) ? date('Y-m-d H:i:s T', filemtime($filepath)) : null
        );
    }
    
    $diagnostics['debug_log'] = $debugLog;
    
    echo json_encode($diagnostics, JSON_PRETTY_PRINT);
    exit;
}

if ($type === 'offshore') {
    // Read and parse offshore data from local NWS text files
    debugLog("Loading offshore data from local files", $LOCAL_DATA_DIR);
    $allForecasts = array();
    
    foreach ($OFFSHORE_FILES as $product => $filename) {
        $filepath = $LOCAL_DATA_DIR . '/' . $filename;
        debugLog("Processing offshore product", array('product' => $product, 'file' => $filepath));
        
        $content = readLocalFile($filepath);
        if ($content) {
            $zones = $ZONE_MAPPINGS[$product];
            $forecasts = parseOffshoreProduct($content, $zones, $ZONE_NAMES);
            debugLog("Product " . $product . " parsed", array('forecasts_count' => count($forecasts)));
            $allForecasts = array_merge($allForecasts, $forecasts);
        } else {
            debugLog("WARNING: Failed to read product " . $product);
        }
    }
    
    debugLog("All offshore data loaded", array('total_forecasts' => count($allForecasts)));
    
    // If debug mode, include debug log in response
    if ($DEBUG) {
        echo json_encode(array(
            'debug' => $debugLog,
            'data' => $allForecasts,
            'source' => 'local_files',
            'data_dir' => $LOCAL_DATA_DIR
        ));
    } else {
        echo json_encode($allForecasts);
    }
    
} elseif ($type === 'navtex') {
    // Read and parse NAVTEX data from local NWS text files
    debugLog("Loading NAVTEX data from local files", $LOCAL_DATA_DIR);
    $allForecasts = array();
    
    foreach ($NAVTEX_FILES as $product => $filename) {
        $filepath = $LOCAL_DATA_DIR . '/' . $filename;
        debugLog("Processing NAVTEX product", array('product' => $product, 'file' => $filepath));
        
        $content = readLocalFile($filepath);
        if ($content) {
            $forecasts = parseNavtexProduct($content, $NAVTEX_NAME_TO_ID, $NAVTEX_ZONES);
            debugLog("Product " . $product . " parsed", array('forecasts_count' => count($forecasts)));
            $allForecasts = array_merge($allForecasts, $forecasts);
        } else {
            debugLog("WARNING: Failed to read product " . $product);
        }
    }
    
    debugLog("All NAVTEX data loaded", array('total_forecasts' => count($allForecasts)));
    
    if ($DEBUG) {
        echo json_encode(array(
            'debug' => $debugLog,
            'data' => $allForecasts,
            'source' => 'local_files',
            'data_dir' => $LOCAL_DATA_DIR
        ));
    } else {
        echo json_encode($allForecasts);
    }
    
} else {
    debugLog("ERROR: Invalid type requested", $type);
    echo json_encode(array('error' => 'Invalid type'));
}
