<?php
/**
 * OPC Weather Map - Live Data API
 *
 * Data source priority for every product:
 *   1. NWS public API  (api.weather.gov) — live, cached locally for 15 min
 *   2. Local /shtml/ files              — fallback if API unreachable
 */

// =============================================================================
// CONFIGURATION
// =============================================================================
$LOCAL_DATA_DIR = $_SERVER['DOCUMENT_ROOT'] . "/shtml";

// NWS API settings
if (!defined('NWS_API_BASE'))   define('NWS_API_BASE',   'https://api.weather.gov');
if (!defined('NWS_CACHE_DIR'))  define('NWS_CACHE_DIR',  __DIR__ . '/cache');
if (!defined('NWS_CACHE_TTL'))  define('NWS_CACHE_TTL',  7200); // 2 hours — products update every 6-12h
if (!defined('NWS_USER_AGENT')) define('NWS_USER_AGENT', '(NWS Marine Weather, nws-marine-weather)');

// =============================================================================
// NWS API — product→(type, office, match) lookup table
// "match" is the string that appears on line 3 of the product text
// =============================================================================
$NWS_PRODUCT_API = array(
    // ---- Offshore (OFF) — OPC Atlantic (KWBC) ----
    "NT1"  => array("OFF", "KWBC", "OFFNT1"),
    "NT2"  => array("OFF", "KWBC", "OFFNT2"),
    "PZ5"  => array("OFF", "KWBC", "OFFPZ5"),
    "PZ6"  => array("OFF", "KWBC", "OFFPZ6"),
    // ---- Offshore (OFF) — NHC Miami (KNHC) ----
    "NT3"  => array("OFF", "KNHC", "OFFNT3"),
    "NT4"  => array("OFF", "KNHC", "OFFNT4"),
    "NT5"  => array("OFF", "KNHC", "OFFNT5"),
    "PZ7"  => array("OFF", "KNHC", "OFFPZ7"),
    "PZ8"  => array("OFF", "KNHC", "OFFPZ8"),
    // ---- Offshore (OFF) — Hawaii (PHFO) ----
    "PH"   => array("OFF", "PHFO", "OFFHFO"),
    // ---- Offshore (OFF) — Alaska (PAJK / PAFC / PAFG) ----
    "PKG"  => array("OFF", "PAJK", "OFFAJK"),  // Gulf of Alaska (Juneau)
    "PKB"  => array("OFF", "PAJK", "OFFAER"),  // Eastern Gulf / SE Alaska (Juneau)
    "PKS"  => array("OFF", "PAFC", "OFFALU"),  // Aleutians / Bering Sea (Anchorage)
    "PKA"  => array("OFF", "PAFG", "OFFAFG"),  // Arctic (Fairbanks)
    // ---- NAVTEX (OFF) — OPC Pacific (KWNM) ----
    "N07"  => array("OFF", "KWNM", "OFFN07"),
    "N08"  => array("OFF", "KWNM", "OFFN08"),
    "N09"  => array("OFF", "KWNM", "OFFN09"),
    // ---- NAVTEX (OFF) — OPC Atlantic (KWBC) ----
    "N01"  => array("OFF", "KWBC", "OFFN01"),
    "N02"  => array("OFF", "KWBC", "OFFN02"),
    "N03"  => array("OFF", "KWBC", "OFFN03"),
    // ---- NAVTEX (OFF) — NHC Miami (KNHC) ----
    "N04"  => array("OFF", "KNHC", "OFFN04"),
    "N05"  => array("OFF", "KNHC", "OFFN05"),
    "N06"  => array("OFF", "KNHC", "OFFN06"),
    // ---- Alaska coastal CWF (full types/CWF list — location filter broken for PAFC/PAFG) ----
    "CWFAER" => array("CWF", "PAFC", "CWFAER"),  // N Gulf, Kodiak, Cook Inlet
    "CWFALU" => array("CWF", "PAFC", "CWFALU"),  // SW Alaska, Bristol Bay, Aleutians
    "CWFNSB" => array("CWF", "PAFG", "CWFNSB"),  // Arctic / North Slope
    "CWFWCZ" => array("CWF", "PAFG", "CWFWCZ"),  // Northwest Alaska
    // ---- High Seas (HSF) ----
    "HSFAT1" => array("HSF", "KWBC", "HSFAT1"),
    "HSFAT2" => array("HSF", "KNHC", "HSFAT2"),
    "HSFEP1" => array("HSF", "KWBC", "HSFEP1"),
    "HSFEP2" => array("HSF", "KNHC", "HSFEP2"),
    "HSFNP"  => array("HSF", "PHFO", "HSFNP"),
);
// Coastal WFOs use type=CWF, one product per WFO — added dynamically in handler

// =============================================================================
// NWS API HELPERS
// =============================================================================

/**
 * Create cache directory if needed, return its path.
 */
function nwsCacheDir() {
    $dir = NWS_CACHE_DIR;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

/**
 * Make an HTTP GET to the NWS API; return decoded JSON array or null.
 */
function nwsApiGet($url) {
    $opts = array(
        'http' => array(
            'method'  => 'GET',
            'header'  => "User-Agent: " . NWS_USER_AGENT . "\r\nAccept: application/geo+json\r\n",
            'timeout' => 10,
            'ignore_errors' => true,
        )
    );
    $raw = @file_get_contents($url, false, stream_context_create($opts));
    if (!$raw) return null;
    $data = json_decode($raw, true);
    return ($data && !isset($data['status'])) ? $data : null;
}

/**
 * Fetch the productText for a single product UUID from api.weather.gov.
 */
function nwsGetProductText($productId) {
    $cacheFile = nwsCacheDir() . '/prod_' . md5($productId) . '.txt';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < NWS_CACHE_TTL) {
        return file_get_contents($cacheFile) ?: null;
    }
    $data = nwsApiGet(NWS_API_BASE . "/products/{$productId}");
    if (!$data || empty($data['productText'])) return null;
    $text = $data['productText'];
    file_put_contents($cacheFile, $text);
    return $text;
}

/**
 * Fetch the most recent product of $type from $office whose text contains $matchStr.
 * Results are cached per (type, office, matchStr).
 * Falls back to $localFilepath if the API is unreachable or returns nothing.
 */
function fetchProductContent($type, $office, $matchStr, $localFilepath = null) {
    // Per-product cache (keyed by type+office+match)
    $cacheKey  = strtolower("{$type}_{$office}" . ($matchStr ? "_{$matchStr}" : ''));
    $cacheFile = nwsCacheDir() . '/match_' . md5($cacheKey) . '.txt';

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < NWS_CACHE_TTL) {
        $cached = file_get_contents($cacheFile);
        if ($cached) return $cached;
    }

    // Query the product list
    $listUrl = NWS_API_BASE . "/products/types/{$type}/locations/{$office}";
    $listData = nwsApiGet($listUrl);
    $items = $listData ? ($listData['@graph'] ?? []) : [];

    $seen = array();
    foreach ($items as $item) {
        $pid = $item['id'] ?? '';
        if (!$pid || in_array($pid, $seen)) continue;
        $seen[] = $pid;

        $text = nwsGetProductText($pid);
        if (!$text) continue;

        // Match: if no matchStr, take the first product; otherwise search text
        if (!$matchStr || stripos($text, $matchStr) !== false) {
            file_put_contents($cacheFile, $text);
            return $text;
        }

        // Stop scanning after checking a reasonable window
        if (count($seen) >= 15) break;
    }

    // Fallback to local file
    if ($localFilepath) {
        return readLocalFile($localFilepath) ?: null;
    }
    return null;
}

// =============================================================================

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
    "PZ8" => "MIAOFFPZ8.txt",
    "PH"  => "HFOOFFPH.txt",           // PHZ180 - Hawaiian Offshore Waters
    "PKG" => "AFCOFFPKG.txt",          // PKZ311/PKZ312 - Gulf of Alaska (AFC)
    "PKB" => "AFCOFFPKB.txt",          // PKZ351/PKZ352 - Gulf of Alaska offshore (AFC)
    "PKS" => "AJKOFFPKS.txt",          // PKZ411-PKZ414 - Bering Sea (AJK)
    "PKA" => "AFGOFFPKA.txt",          // PKZ500-PKZ510 - Arctic (AFG)
);

// Coastal Waters Forecast (CWF) files — one per issuing WFO
// File naming: {WFO}CWF{WFO}.txt  e.g. BOXCWFBOX.txt
$COASTAL_FILES = array(
    // Atlantic - New England
    "BOX" => "BOXCWFBOX.txt",
    "GYX" => "GYXCWFGYX.txt",
    "CAR" => "CARCWFCAR.txt",
    // Atlantic - Mid-Atlantic / Chesapeake
    "OKX" => "OKXCWFOKX.txt",
    "PHI" => "PHICWFPHI.txt",
    "LWX" => "LWXCWFLWX.txt",
    "AKQ" => "AKQCWFAKQ.txt",
    // SE Atlantic / Caribbean
    "MHX" => "MHXCWFMHX.txt",
    "ILM" => "ILMCWFILM.txt",
    "CHS" => "CHSCWFCHS.txt",
    "JAX" => "JAXCWFJAX.txt",
    "MLB" => "MLBCWFMLB.txt",
    "MFL" => "MFLCWFMFL.txt",
    "SJU" => "SJUCWFSJU.txt",
    // Gulf of America
    "KEY" => "KEYCWFKEY.txt",
    "TBW" => "TBWCWFTBW.txt",
    "TAE" => "TAECWFTAE.txt",
    "MOB" => "MOBCWFMOB.txt",
    "LIX" => "LIXCWFLIX.txt",
    "LCH" => "LCHCWFLCH.txt",
    "HGX" => "HGXCWFHGX.txt",
    "CRP" => "CRPCWFCRP.txt",
    "BRO" => "BROCWFBRO.txt",
    // Pacific - West Coast
    "SEW" => "SEWCWFSEW.txt",
    "PQR" => "PQRCWFPQR.txt",
    "MFR" => "MFRCWFMFR.txt",
    "EKA" => "EKACWFEKA.txt",
    "MTR" => "MTRCWFMTR.txt",
    "LOX" => "LOXCWFLOX.txt",
    "SGX" => "SGXCWFSGX.txt",
    // Hawaii
    "HFO" => "HFOCWFHFO.txt",
    // Alaska
    "AFC" => "AFCCWFAFC.txt",
    "AFG" => "AFGCWFAFG.txt",
    "AJK" => "AJKCWFAJK.txt",
    // Great Lakes
    "APX" => "APXCWFAPX.txt",
    "BOX_GL" => "BOXCWFBOX.txt",       // note: BOX also covers some GL zones
    "BUF" => "BUFCWFBUF.txt",
    "CLE" => "CLECWFCLE.txt",
    "DLH" => "DLHCWFDLH.txt",
    "DTX" => "DTXCWFDTX.txt",
    "GRB" => "GRBCWFGRB.txt",
    "GRR" => "GRRCWFGRR.txt",
    "IWX" => "IWXCWFIWX.txt",
    "LOT" => "LOTCWFLOT.txt",
    "MKX" => "MKXCWFMKX.txt",
    "MQT" => "MQTCWFMQT.txt",
    // Pacific Islands
    "GUM" => "GUMCWFGUM.txt",
    "PQE" => "PQECWFPQE.txt",
    "PQW" => "PQWCWFPQW.txt",
    "STU" => "STUCWFSTU.txt",
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
    "PZ8" => array("PMZ111", "PMZ113", "PMZ115", "PMZ117", "PMZ119", "PMZ121", "PMZ123"),
    "PH"  => array("PHZ180"),
    "PKG" => array("PKZ311", "PKZ312"),
    "PKB" => array("PKZ351", "PKZ352"),
    "PKS" => array("PKZ411", "PKZ412", "PKZ413", "PKZ414"),
    "PKA" => array("PKZ500", "PKZ505", "PKZ510"),
);

// Coastal zone mappings: WFO -> array of zone IDs it covers
$COASTAL_ZONE_MAPPINGS = array(
    "AFC" => array("PKZ787", "PKZ786", "PKZ751", "PKZ760", "PKZ772", "PKZ766", "PKZ774", "PKZ778", "PKZ776", "PKZ783", "PKZ781", "PKZ784", "PKZ764", "PKZ767", "PKZ712", "PKZ734", "PKZ733", "PKZ716", "PKZ765", "PKZ711", "PKZ785", "PKZ757", "PKZ738", "PKZ775", "PKZ771", "PKZ723", "PKZ722", "PKZ721", "PKZ720", "PKZ736", "PKZ737", "PKZ714", "PKZ742", "PKZ741", "PKZ740", "PKZ730", "PKZ710", "PKZ726", "PKZ715", "PKZ731", "PKZ732", "PKZ750", "PKZ754", "PKZ773", "PKZ777", "PKZ782", "PKZ780", "PKZ770", "PKZ758", "PKZ756", "PKZ761", "PKZ762", "PKZ763", "PKZ752", "PKZ753", "PKZ725", "PKZ724", "PKZ768", "PKZ755", "PKZ759"),
    "AFG" => array("PKZ861", "PKZ860", "PKZ859", "PKZ858", "PKZ857", "PKZ852", "PKZ850", "PKZ853", "PKZ854", "PKZ851", "PKZ856", "PKZ801", "PKZ816", "PKZ805", "PKZ803", "PKZ806", "PKZ808", "PKZ855", "PKZ810", "PKZ811", "PKZ812", "PKZ813", "PKZ814", "PKZ815", "PKZ804", "PKZ802", "PKZ817", "PKZ809", "PKZ807"),
    "AJK" => array("PKZ672", "PKZ671", "PKZ663", "PKZ662", "PKZ661", "PKZ664", "PKZ053", "PKZ036", "PKZ012", "PKZ011", "PKZ013", "PKZ031", "PKZ022", "PKZ021", "PKZ032", "PKZ033", "PKZ034", "PKZ035", "PKZ642", "PKZ643", "PKZ644", "PKZ651", "PKZ652", "PKZ641"),
    "AKQ" => array("ANZ632", "ANZ636", "ANZ637", "ANZ631", "ANZ635", "ANZ630", "ANZ633", "ANZ634", "ANZ658", "ANZ654", "ANZ650", "ANZ652", "ANZ656", "ANZ686", "ANZ680", "ANZ682", "ANZ684", "ANZ688", "ANZ639"),
    "APX" => array("LSZ321", "LSZ322", "LHZ346", "LMZ341", "LHZ361", "LHZ347", "LHZ345", "LMZ323", "LMZ364", "LMZ362", "LMZ344", "LMZ342", "LMZ346", "LMZ366", "LHZ348", "LHZ349", "LHZ363", "LHZ362", "LMZ345"),
    "BOX" => array("ANZ235", "ANZ236", "ANZ237", "ANZ234", "ANZ230", "ANZ233", "ANZ232", "ANZ231", "ANZ251", "ANZ256", "ANZ255", "ANZ254", "ANZ250", "ANZ281", "ANZ283", "ANZ282", "ANZ280"),
    "BRO" => array("GMZ155", "GMZ170", "GMZ175", "GMZ150", "GMZ135", "GMZ132", "GMZ130"),
    "BUF" => array("LOZ030", "LEZ040", "LOZ043", "LOZ044", "LEZ041", "LEZ020", "SLZ024", "LOZ045", "LEZ061", "LOZ042", "LOZ062", "LOZ063", "LOZ064", "LOZ065", "SLZ022"),
    "CAR" => array("ANZ052", "ANZ051", "ANZ050", "ANZ080", "ANZ081"),
    "CHS" => array("AMZ380", "AMZ384", "AMZ382", "AMZ364", "AMZ362", "AMZ360", "AMZ340"),
    "CLE" => array("LEZ142", "LEZ163", "LEZ143", "LEZ144", "LEZ145", "LEZ146", "LEZ147", "LEZ148", "LEZ149", "LEZ162", "LEZ164", "LEZ165", "LEZ166", "LEZ167", "LEZ168", "LEZ169"),
    "CRP" => array("GMZ270", "GMZ275", "GMZ250", "GMZ255", "GMZ237", "GMZ231", "GMZ232", "GMZ236"),
    "DLH" => array("LSZ144", "LSZ145", "LSZ143", "LSZ142", "LSZ141", "LSZ140", "LSZ146", "LSZ147", "LSZ121", "LSZ148", "LSZ162", "LSZ150"),
    "DTX" => array("LHZ442", "LCZ460", "LHZ443", "LCZ422", "LCZ423", "LEZ444", "LHZ421", "LHZ422", "LHZ441", "LHZ462", "LHZ463", "LHZ464"),
    "EKA" => array("PZZ475", "PZZ450", "PZZ415", "PZZ470", "PZZ455", "PZZ410"),
    "GRB" => array("LMZ522", "LMZ521", "LMZ541", "LMZ543", "LMZ542", "LMZ567", "LMZ565", "LMZ563"),
    "GRR" => array("LMZ878", "LMZ876", "LMZ870", "LMZ845", "LMZ846", "LMZ847", "LMZ868", "LMZ844", "LMZ849", "LMZ848", "LMZ874", "LMZ872"),
    "GUM" => array("PMZ191", "PMZ151", "PMZ152", "PMZ153", "PMZ154"),
    "GYX" => array("ANZ153", "ANZ151", "ANZ154", "ANZ152", "ANZ150", "ANZ182", "ANZ180", "ANZ184"),
    "HFO" => array("PHZ113", "PHZ112", "PHZ111", "PHZ110", "PHZ116", "PHZ115", "PHZ114", "PHZ118", "PHZ120", "PHZ117", "PHZ124", "PHZ119", "PHZ121", "PHZ123", "PHZ122"),
    "HGX" => array("GMZ370", "GMZ350", "GMZ355", "GMZ375", "GMZ335", "GMZ330"),
    "ILM" => array("AMZ254", "AMZ256", "AMZ250", "AMZ252", "AMZ280", "AMZ284"),
    "IWX" => array("LMZ080", "LMZ043", "LMZ046"),
    "JAX" => array("AMZ470", "AMZ472", "AMZ474", "AMZ452", "AMZ454", "AMZ450"),
    "KEY" => array("GMZ044", "GMZ052", "GMZ053", "GMZ054", "GMZ075", "GMZ073", "GMZ072", "GMZ033", "GMZ074", "GMZ055", "GMZ043", "GMZ035", "GMZ032", "GMZ034", "GMZ031", "GMZ042"),
    "LCH" => array("GMZ450", "GMZ430", "GMZ472", "GMZ475", "GMZ470", "GMZ435", "GMZ452", "GMZ432", "GMZ455", "GMZ436"),
    "LIX" => array("GMZ533", "GMZ531", "GMZ529", "GMZ541", "GMZ554", "GMZ551", "GMZ553", "GMZ543", "GMZ570", "GMZ577", "GMZ572", "GMZ557", "GMZ575", "GMZ534", "GMZ532", "GMZ536", "GMZ535"),
    "LOT" => array("LMZ779", "LMZ777", "LMZ745", "LMZ744", "LMZ743", "LMZ742", "LMZ741", "LMZ740"),
    "LOX" => array("PZZ650", "PZZ673", "PZZ645", "PZZ655", "PZZ676", "PZZ670"),
    "LWX" => array("ANZ543", "ANZ542", "ANZ534", "ANZ539", "ANZ538", "ANZ531", "ANZ530", "ANZ540", "ANZ533", "ANZ532", "ANZ541", "ANZ535", "ANZ536", "ANZ537"),
    "MFL" => array("GMZ657", "GMZ656", "AMZ671", "GMZ676", "AMZ651", "AMZ610", "AMZ650", "AMZ630", "AMZ670"),
    "MFR" => array("PZZ376", "PZZ370", "PZZ350", "PZZ356"),
    "MHX" => array("AMZ136", "AMZ137", "AMZ135", "AMZ230", "AMZ131", "AMZ231", "AMZ156", "AMZ158", "AMZ154", "AMZ152", "AMZ150", "AMZ188", "AMZ180", "AMZ182", "AMZ184", "AMZ186"),
    "MKX" => array("LMZ675", "LMZ673", "LMZ671", "LMZ669", "LMZ643", "LMZ646", "LMZ645", "LMZ644"),
    "MLB" => array("AMZ550", "AMZ555", "AMZ552", "AMZ575", "AMZ570", "AMZ572"),
    "MOB" => array("GMZ670", "GMZ675", "GMZ631", "GMZ632", "GMZ630", "GMZ655", "GMZ634", "GMZ633", "GMZ650", "GMZ636", "GMZ635"),
    "MQT" => array("LSZ240", "LSZ241", "LSZ245", "LSZ246", "LSZ247", "LSZ248", "LSZ249", "LSZ250", "LSZ251", "LSZ265", "LMZ221", "LMZ250", "LMZ248", "LSZ266", "LSZ267", "LMZ261", "LSZ242", "LSZ243", "LSZ244", "LSZ263", "LSZ264"),
    "MTR" => array("PZZ576", "PZZ530", "PZZ531", "PZZ570", "PZZ545", "PZZ540", "PZZ565", "PZZ560", "PZZ535", "PZZ575", "PZZ571"),
    "OKX" => array("ANZ338", "ANZ335", "ANZ345", "ANZ340", "ANZ331", "ANZ332", "ANZ355", "ANZ353", "ANZ350", "ANZ380", "ANZ383", "ANZ385"),
    "PHI" => array("ANZ430", "ANZ431", "ANZ480", "ANZ455", "ANZ454", "ANZ453", "ANZ452", "ANZ451", "ANZ450", "ANZ485", "ANZ481", "ANZ482", "ANZ483"),
    "PQE" => array("PMZ173", "PMZ174", "PMZ181"),
    "PQR" => array("PZZ210", "PZZ271", "PZZ251", "PZZ253", "PZZ252", "PZZ273", "PZZ272"),
    "PQW" => array("PMZ171", "PMZ172", "PMZ161"),
    "SEW" => array("PZZ176", "PZZ173", "PZZ170", "PZZ130", "PZZ131", "PZZ135", "PZZ156", "PZZ150", "PZZ110", "PZZ153", "PZZ132", "PZZ134", "PZZ133"),
    "SGX" => array("PZZ745", "PZZ740"),
    "SJU" => array("AMZ745", "AMZ733", "AMZ742", "AMZ741", "AMZ726", "AMZ735", "AMZ716", "AMZ712", "AMZ711", "AMZ723"),
    "STU" => array("PSZ156", "PSZ157", "PSZ152", "PSZ158", "PSZ159", "PSZ155", "PSZ154"),
    "TAE" => array("GMZ770", "GMZ775", "GMZ765", "GMZ730", "GMZ755", "GMZ751", "GMZ752", "GMZ772", "GMZ735"),
    "TBW" => array("GMZ876", "GMZ873", "GMZ870", "GMZ850", "GMZ853", "GMZ830", "GMZ856", "GMZ836"),
);

// Alaska CWF zone mappings (product name → zone IDs)
// Source: https://www.weather.gov/source/afc/mobile/marine.html
$ALASKA_COASTAL_ZONES = array(
    "CWFAER" => array(  // PAFC — Northern Gulf, Kodiak, Cook Inlet
        "PKZ710","PKZ711","PKZ712","PKZ714","PKZ715","PKZ716",
        "PKZ720","PKZ721","PKZ722","PKZ723","PKZ724","PKZ725","PKZ726",
        "PKZ730","PKZ731","PKZ732","PKZ733","PKZ734",
        "PKZ736","PKZ737","PKZ738","PKZ740","PKZ741","PKZ742",
    ),
    "CWFALU" => array(  // PAFC — SW Alaska, Bristol Bay, Aleutians
        "PKZ750","PKZ751","PKZ752","PKZ753","PKZ754","PKZ755",
        "PKZ756","PKZ757","PKZ758","PKZ759",
        "PKZ760","PKZ761","PKZ762","PKZ763","PKZ764","PKZ765","PKZ766","PKZ767",
        "PKZ770","PKZ771","PKZ772","PKZ773","PKZ774","PKZ775",
        "PKZ776","PKZ777","PKZ778",
        "PKZ780","PKZ781","PKZ782","PKZ783","PKZ784","PKZ785","PKZ786","PKZ787",
    ),
    "CWFNSB" => array(  // PAFG — Arctic / North Slope Beaufort
        "PKZ811","PKZ812","PKZ813","PKZ814","PKZ815",
        "PKZ857","PKZ858","PKZ859","PKZ860","PKZ861",
    ),
    "CWFWCZ" => array(  // PAFG — Northwest Alaska / Western Coastal Zone
        "PKZ801","PKZ802","PKZ803","PKZ804","PKZ805","PKZ806","PKZ807",
        "PKZ808","PKZ809","PKZ810","PKZ816","PKZ817",
        "PKZ850","PKZ851","PKZ852","PKZ853","PKZ854","PKZ855","PKZ856",
    ),
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
 * Extract the highest-priority active marine warning/advisory from forecast text.
 * Order matters — most severe checked first.
 */
function extractWarning($text) {
    $t = strtoupper($text);

    // Tropical / Hurricane (highest severity)
    if (strpos($t, 'HURRICANE FORCE WIND WARNING') !== false) return 'HURRICANE FORCE WIND WARNING';
    if (strpos($t, 'HURRICANE FORCE WIND WATCH')   !== false) return 'HURRICANE FORCE WIND WATCH';
    if (strpos($t, 'HURRICANE WARNING')             !== false) return 'HURRICANE WARNING';
    if (strpos($t, 'HURRICANE WATCH')               !== false) return 'HURRICANE WATCH';
    if (strpos($t, 'TROPICAL STORM WARNING')        !== false) return 'TROPICAL STORM WARNING';
    if (strpos($t, 'TROPICAL STORM WATCH')          !== false) return 'TROPICAL STORM WATCH';

    // Storm / Gale
    if (strpos($t, 'STORM WARNING')                !== false) return 'STORM WARNING';
    if (strpos($t, 'STORM WATCH')                  !== false) return 'STORM WATCH';
    if (strpos($t, 'GALE WARNING')                 !== false) return 'GALE WARNING';
    if (strpos($t, 'GALE WATCH')                   !== false) return 'GALE WATCH';

    // Hazardous Seas
    if (strpos($t, 'HAZARDOUS SEAS WARNING')        !== false) return 'HAZARDOUS SEAS WARNING';
    if (strpos($t, 'HAZARDOUS SEAS WATCH')          !== false) return 'HAZARDOUS SEAS WATCH';

    // Storm Surge
    if (strpos($t, 'STORM SURGE WARNING')           !== false) return 'STORM SURGE WARNING';
    if (strpos($t, 'STORM SURGE WATCH')             !== false) return 'STORM SURGE WATCH';

    // Freezing Spray
    if (strpos($t, 'HEAVY FREEZING SPRAY WARNING')  !== false) return 'HEAVY FREEZING SPRAY WARNING';
    if (strpos($t, 'HEAVY FREEZING SPRAY WATCH')    !== false) return 'HEAVY FREEZING SPRAY WATCH';
    if (strpos($t, 'FREEZING SPRAY ADVISORY')       !== false) return 'FREEZING SPRAY ADVISORY';

    // Special Marine Warning
    if (strpos($t, 'SPECIAL MARINE WARNING')        !== false) return 'SPECIAL MARINE WARNING';

    // High Surf
    if (strpos($t, 'HIGH SURF WARNING')             !== false) return 'HIGH SURF WARNING';
    if (strpos($t, 'HIGH SURF ADVISORY')            !== false) return 'HIGH SURF ADVISORY';

    // Small Craft / Wind
    if (strpos($t, 'SMALL CRAFT ADVISORY')          !== false) return 'SMALL CRAFT ADVISORY';
    if (strpos($t, 'BRISK WIND ADVISORY')           !== false) return 'BRISK WIND ADVISORY';
    if (strpos($t, 'WIND ADVISORY')                 !== false) return 'WIND ADVISORY';
    if (strpos($t, 'LAKE WIND ADVISORY')            !== false) return 'LAKE WIND ADVISORY';

    // Dense Fog
    if (strpos($t, 'DENSE FOG ADVISORY')            !== false) return 'DENSE FOG ADVISORY';

    // Coastal / Lakeshore Flood
    if (strpos($t, 'COASTAL FLOOD WARNING')         !== false) return 'COASTAL FLOOD WARNING';
    if (strpos($t, 'COASTAL FLOOD WATCH')           !== false) return 'COASTAL FLOOD WATCH';
    if (strpos($t, 'COASTAL FLOOD ADVISORY')        !== false) return 'COASTAL FLOOD ADVISORY';
    if (strpos($t, 'LAKESHORE FLOOD WARNING')       !== false) return 'LAKESHORE FLOOD WARNING';
    if (strpos($t, 'LAKESHORE FLOOD WATCH')         !== false) return 'LAKESHORE FLOOD WATCH';
    if (strpos($t, 'LAKESHORE FLOOD ADVISORY')      !== false) return 'LAKESHORE FLOOD ADVISORY';

    // Tsunami
    if (strpos($t, 'TSUNAMI WARNING')               !== false) return 'TSUNAMI WARNING';
    if (strpos($t, 'TSUNAMI WATCH')                 !== false) return 'TSUNAMI WATCH';
    if (strpos($t, 'TSUNAMI ADVISORY')              !== false) return 'TSUNAMI ADVISORY';

    // Marine Weather Statement (lowest priority)
    if (strpos($t, 'MARINE WEATHER STATEMENT')      !== false) return 'MARINE WEATHER STATEMENT';

    return 'NONE';
}

/**
 * Expand a zone group header line into an array of full zone IDs.
 *
 * Handles all CWF/offshore header formats:
 *   GMZ031-070330-           → [GMZ031]
 *   GMZ032-035-070330-       → [GMZ032, GMZ035]
 *   GMZ042>044-070330-       → [GMZ042, GMZ043, GMZ044]
 *   AMZ600-GMZ606-070700-    → [AMZ600, GMZ606]
 *   GMZ130-132-135-061915-   → [GMZ130, GMZ132, GMZ135]
 */
function expandZoneHeader($header) {
    $header = rtrim(trim($header), '-');
    $header = preg_replace('/-\d{6}$/', '', $header);   // strip timestamp
    $parts  = explode('-', $header);
    $ids    = array();
    $prefix = null;

    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') continue;

        if (preg_match('/^([A-Z]{2}Z)(\d{3})(?:>(\d{3}))?$/', $part, $m)) {
            $prefix = $m[1];
            if (!empty($m[3])) {
                for ($i = intval($m[2]); $i <= intval($m[3]); $i++) {
                    $ids[] = $prefix . sprintf('%03d', $i);
                }
            } else {
                $ids[] = $prefix . $m[2];
            }
        } elseif ($prefix && preg_match('/^(\d{3})(?:>(\d{3}))?$/', $part, $m)) {
            if (!empty($m[2])) {
                for ($i = intval($m[1]); $i <= intval($m[2]); $i++) {
                    $ids[] = $prefix . sprintf('%03d', $i);
                }
            } else {
                $ids[] = $prefix . $m[1];
            }
        }
    }
    return array_unique($ids);
}

/**
 * Split a product text into a map of zoneId => sectionText.
 * Handles both offshore (single zone per section) and CWF (grouped zones).
 */
function buildZoneSectionMap($content) {
    $map      = array();
    $sections = preg_split('/\n\$\$[ \t]*(?:\n|$)/', $content);

    foreach ($sections as $section) {
        $lines     = explode("\n", ltrim($section, "\r\n"));
        $headerIdx = -1;

        foreach ($lines as $i => $line) {
            if (preg_match('/^[A-Z]{2}Z\d{3}[-|>]/', trim($line))) {
                $headerIdx = $i;
                break;
            }
        }
        if ($headerIdx === -1) continue;

        $zoneIds = expandZoneHeader($lines[$headerIdx]);
        if (empty($zoneIds)) continue;

        $body = implode("\n", array_slice($lines, $headerIdx + 1));
        foreach ($zoneIds as $zoneId) {
            if (!isset($map[$zoneId])) {   // keep first (most recent) occurrence
                $map[$zoneId] = $body;
            }
        }
    }
    return $map;
}

/**
 * Parse offshore/coastal forecast product
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
    
    // Build section map once for the whole product (handles grouped CWF headers)
    $sectionMap = buildZoneSectionMap($content);
    debugLog("Zone section map built", array('zones_found' => count($sectionMap)));

    foreach ($zones as $zone) {
        if (!isset($sectionMap[$zone])) {
            debugLog("Zone not found in product: " . $zone);
            continue;
        }

        $zoneText = $sectionMap[$zone];
        $zoneData = array(
            'zone'     => $zone,
            'name'     => isset($zoneNames[$zone]) ? $zoneNames[$zone] : $zone,
            'time'     => $issueTime ? $issueTime : date('g:i A T D M j Y'),
            'warning'  => extractWarning($zoneText),
            'forecast' => array()
        );

        preg_match_all('/\.([A-Z][A-Z\s]*?)\.\.\.([^.]*(?:\.[^A-Z][^.]*)*)/s', $zoneText, $periodMatches, PREG_SET_ORDER);
        debugLog("Periods for $zone", array('count' => count($periodMatches)));

        foreach ($periodMatches as $match) {
            $periodName = trim($match[1]);
            $periodText = trim($match[2]);

            $validStarts = array('TODAY', 'TONIGHT', 'MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN', 'REST');
            $isValid = false;
            foreach ($validStarts as $v) {
                if (stripos($periodName, $v) === 0) { $isValid = true; break; }
            }
            if (!$isValid) continue;

            $cleanText = preg_replace('/\s+/', ' ', $periodText);

            $winds = 'Variable winds';
            if (preg_match('/([NSEW]{1,2}(?:\s+TO\s+[NSEW]{1,2})?\s+(?:WINDS?\s+)?(\d+)\s*(?:TO\s*)?(\d+)?\s*KT)/i', $cleanText, $wm)) {
                $winds = ucfirst(strtolower(trim($wm[0])));
            }

            $seas = 'Seas variable';
            if (preg_match('/(?:SEAS?|COMBINED\s+SEAS?)\s+(\d+)\s*(?:TO\s*)?(\d+)?\s*FT/i', $cleanText, $sm)) {
                $lo = $sm[1]; $hi = isset($sm[2]) && $sm[2] ? $sm[2] : $lo;
                $seas = "Seas $lo to $hi ft";
            } elseif (preg_match('/(\d+)\s+TO\s+(\d+)\s*FT/i', $cleanText, $sm2)) {
                $seas = "Seas {$sm2[1]} to {$sm2[2]} ft";
            }

            $weather = 'N/A';
            foreach (array('freezing spray','rain','snow','fog','tstms','thunderstorms','showers','drizzle') as $wp) {
                if (stripos($cleanText, $wp) !== false) {
                    if (preg_match('/((?:chance\s+of\s+|isolated\s+|scattered\s+)?' . $wp . '[^.]*?)(?:\.|$)/i', $cleanText, $wxm)) {
                        $weather = ucfirst(strtolower(trim($wxm[1])));
                    }
                    break;
                }
            }

            $zoneData['forecast'][] = array(
                'Day'     => ucwords(strtolower($periodName)),
                'Winds'   => $winds,
                'Seas'    => $seas,
                'Weather' => $weather
            );
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


// When included by prefetch.php, skip all HTTP request-handling below.
// All configuration arrays and parsing functions above remain available.
if (defined('PREFETCH_INCLUDED')) return;

// =============================================================================
// RESULT CACHE — pre-built JSON written by prefetch.php
// =============================================================================
$RESULT_FILES = array(
    'offshore' => NWS_CACHE_DIR . '/result_offshore.json',
    'navtex'   => NWS_CACHE_DIR . '/result_navtex.json',
    'coastal'  => NWS_CACHE_DIR . '/result_coastal.json',
    'highseas' => NWS_CACHE_DIR . '/result_highseas.json',
);

/**
 * Return pre-built result JSON if it is fresh (within TTL), otherwise null.
 */
function getResultCache($type, $resultFiles) {
    if (!isset($resultFiles[$type])) return null;
    $file = $resultFiles[$type];
    if (!file_exists($file)) return null;
    if ((time() - filemtime($file)) >= NWS_CACHE_TTL) return null;
    $data = @file_get_contents($file);
    // Don't serve an empty result (2 bytes = "[]")
    return ($data && strlen($data) > 5) ? $data : null;
}

/**
 * Return stale result JSON regardless of TTL — used for stale-while-revalidate.
 * Returns null only if the file doesn't exist or is empty.
 */
function getStaleResultCache($type, $resultFiles) {
    if (!isset($resultFiles[$type])) return null;
    $file = $resultFiles[$type];
    if (!file_exists($file)) return null;
    $data = @file_get_contents($file);
    return ($data && strlen($data) > 5) ? $data : null;
}

/**
 * Write a result to the cache file — only if the result is non-empty.
 * This prevents a failed live-fetch from overwriting good prefetch data.
 */
function setResultCache($type, $resultFiles, $data) {
    if (!isset($resultFiles[$type])) return;
    if (empty($data)) return;   // Never overwrite good cache with empty data
    nwsCacheDir();
    file_put_contents($resultFiles[$type], json_encode($data));
}

/**
 * Fire prefetch.php as a background process (non-blocking).
 * Used for stale-while-revalidate — users get old data immediately,
 * next request gets fresh data after prefetch completes.
 */
function triggerBackgroundPrefetch() {
    $lockFile = NWS_CACHE_DIR . '/prefetch.lock';
    // Don't trigger if a prefetch is already running
    if (file_exists($lockFile) && (time() - filemtime($lockFile)) < 300) return;

    $script = __DIR__ . '/prefetch.php';
    if (!file_exists($script)) return;

    // Write a provisional lock so we don't double-trigger
    @file_put_contents($lockFile, 'triggered-by-api ' . date('c'));

    // Spawn as background process (works on Linux/Mac servers)
    @exec('php ' . escapeshellarg($script) . ' > /dev/null 2>&1 &');

    debugLog("Triggered background prefetch");
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
    $cached = getResultCache('offshore', $RESULT_FILES);
    if ($cached) { echo $cached; exit; }
    // Stale-while-revalidate: serve existing data instantly, refresh in background
    triggerBackgroundPrefetch();
    $stale = getStaleResultCache('offshore', $RESULT_FILES);
    if ($stale) { echo $stale; exit; }

    debugLog("Loading offshore data (NWS API → local fallback)");
    $allForecasts = array();

    foreach ($OFFSHORE_FILES as $product => $filename) {
        $apiCfg  = isset($NWS_PRODUCT_API[$product]) ? $NWS_PRODUCT_API[$product] : null;
        $content = $apiCfg
            ? fetchProductContent($apiCfg[0], $apiCfg[1], $apiCfg[2], $LOCAL_DATA_DIR . '/' . $filename)
            : readLocalFile($LOCAL_DATA_DIR . '/' . $filename);

        if ($content && isset($ZONE_MAPPINGS[$product])) {
            $forecasts = parseOffshoreProduct($content, $ZONE_MAPPINGS[$product], $ZONE_NAMES);
            debugLog("Product {$product} parsed", array('count' => count($forecasts)));
            $allForecasts = array_merge($allForecasts, $forecasts);
        }
    }

    debugLog("Offshore complete", array('total' => count($allForecasts)));
    if (!$DEBUG) setResultCache('offshore', $RESULT_FILES, $allForecasts);
    echo $DEBUG
        ? json_encode(array('debug' => $debugLog, 'data' => $allForecasts))
        : json_encode($allForecasts);

} elseif ($type === 'navtex') {
    $cached = getResultCache('navtex', $RESULT_FILES);
    if ($cached) { echo $cached; exit; }
    triggerBackgroundPrefetch();
    $stale = getStaleResultCache('navtex', $RESULT_FILES);
    if ($stale) { echo $stale; exit; }

    debugLog("Loading NAVTEX data (NWS API → local fallback)");
    $allForecasts = array();

    foreach ($NAVTEX_FILES as $product => $filename) {
        $apiCfg  = isset($NWS_PRODUCT_API[$product]) ? $NWS_PRODUCT_API[$product] : null;
        $content = $apiCfg
            ? fetchProductContent($apiCfg[0], $apiCfg[1], $apiCfg[2], $LOCAL_DATA_DIR . '/' . $filename)
            : readLocalFile($LOCAL_DATA_DIR . '/' . $filename);

        if ($content) {
            $forecasts = parseNavtexProduct($content, $NAVTEX_NAME_TO_ID, $NAVTEX_ZONES);
            debugLog("NAVTEX product {$product} parsed", array('count' => count($forecasts)));
            $allForecasts = array_merge($allForecasts, $forecasts);
        }
    }

    debugLog("NAVTEX complete", array('total' => count($allForecasts)));
    if (!$DEBUG) setResultCache('navtex', $RESULT_FILES, $allForecasts);
    echo $DEBUG
        ? json_encode(array('debug' => $debugLog, 'data' => $allForecasts))
        : json_encode($allForecasts);

} elseif ($type === 'coastal') {
    $cached = getResultCache('coastal', $RESULT_FILES);
    if ($cached) { echo $cached; exit; }
    triggerBackgroundPrefetch();
    $stale = getStaleResultCache('coastal', $RESULT_FILES);
    if ($stale) { echo $stale; exit; }

    debugLog("Loading coastal data (NWS API → local fallback)");
    $allForecasts = array();

    foreach ($COASTAL_FILES as $wfo => $filename) {
        // CWF: one product per WFO, no matchStr needed
        // AJK uses MWW (Marine Weather Watch) which covers all PKZ coastal zones.
        // AFC/AFG have no API product; they fall back to local /shtml/ files only.
        $coastalProductType = ($wfo === 'AJK') ? 'MWW' : 'CWF';
        $content = fetchProductContent($coastalProductType, $wfo, null, $LOCAL_DATA_DIR . '/' . $filename);

        if ($content && isset($COASTAL_ZONE_MAPPINGS[$wfo])) {
            $forecasts = parseOffshoreProduct($content, $COASTAL_ZONE_MAPPINGS[$wfo], array());
            debugLog("Coastal WFO {$wfo} parsed", array('count' => count($forecasts)));
            $allForecasts = array_merge($allForecasts, $forecasts);
        }
    }

    // Alaska CWF products (CWFAER/CWFALU/CWFNSB/CWFWCZ) — fetched via the
    // full types/CWF list since location filter is broken for PAFC/PAFG
    foreach ($ALASKA_COASTAL_ZONES as $matchStr => $zones) {
        $apiCfg  = isset($NWS_PRODUCT_API[$matchStr]) ? $NWS_PRODUCT_API[$matchStr] : null;
        $content = $apiCfg
            ? fetchProductContent($apiCfg[0], $apiCfg[1], $apiCfg[2])
            : null;
        if ($content) {
            $forecasts = parseOffshoreProduct($content, $zones, array());
            debugLog("Alaska coastal {$matchStr} parsed", array('count' => count($forecasts)));
            $allForecasts = array_merge($allForecasts, $forecasts);
        }
    }

    debugLog("Coastal complete", array('total' => count($allForecasts)));
    if (!$DEBUG) setResultCache('coastal', $RESULT_FILES, $allForecasts);
    echo $DEBUG
        ? json_encode(array('debug' => $debugLog, 'data' => $allForecasts))
        : json_encode($allForecasts);

} elseif ($type === 'highseas') {
    $cached = getResultCache('highseas', $RESULT_FILES);
    if ($cached) { echo $cached; exit; }
    triggerBackgroundPrefetch();
    $stale = getStaleResultCache('highseas', $RESULT_FILES);
    if ($stale) { echo $stale; exit; }

    debugLog("Loading high seas data (NWS API → local fallback)");

    // Extract issue time from product text
    function extractIssueTime($text) {
        if (preg_match('/(\d{3,4}\s*(?:AM|PM)\s*\w+\s+\w+\s+\w+\s+\d+\s+\d{4})/i', $text, $m)) {
            return trim($m[1]);
        }
        return date('g:i A T D M j Y');
    }

    $highSeasZones = array(
        array('id' => 'HSFAT1', 'name' => 'North Atlantic High Seas',
              'api' => array('HSF', 'KWBC', 'HSFAT1')),
        array('id' => 'HSFAT2', 'name' => 'Tropical Atlantic / Caribbean / Gulf High Seas',
              'api' => array('HSF', 'KNHC', 'HSFAT2')),
        array('id' => 'HSFEP1', 'name' => 'North Pacific High Seas',
              'api' => array('HSF', 'KWBC', 'HSFEP1')),
        array('id' => 'HSFEP2', 'name' => 'Eastern North Pacific High Seas',
              'api' => array('HSF', 'KNHC', 'HSFEP2')),
        array('id' => 'HSFNP',  'name' => 'Central North Pacific High Seas',
              'api' => array('HSF', 'PHFO', 'HSFNP')),
    );

    $allForecasts = array();
    foreach ($highSeasZones as $zone) {
        $cfg  = $zone['api'];
        $text = fetchProductContent($cfg[0], $cfg[1], $cfg[2]);
        if ($text) {
            $allForecasts[] = array(
                'zone'    => $zone['id'],
                'name'    => $zone['name'],
                'time'    => extractIssueTime($text),
                'warning' => extractWarning($text),
                'rawText' => trim($text),
            );
            debugLog("High seas zone {$zone['id']} fetched");
        }
    }

    debugLog("High seas complete", array('total' => count($allForecasts)));
    if (!$DEBUG) setResultCache('highseas', $RESULT_FILES, $allForecasts);
    echo $DEBUG
        ? json_encode(array('debug' => $debugLog, 'data' => $allForecasts))
        : json_encode($allForecasts);

} else {
    debugLog("ERROR: Invalid type requested", $type);
    echo json_encode(array('error' => 'Invalid type'));
}
