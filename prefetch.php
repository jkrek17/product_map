#!/usr/bin/env php
<?php
/**
 * NWS Marine Forecast Pre-fetcher
 *
 * Fetches ALL marine forecast products in parallel using curl_multi_exec,
 * parses them, and writes ready-to-serve JSON to the cache directory.
 * api.php reads from those JSON files — page loads never wait on the NWS API.
 *
 * ==========================================================================
 * SCHEDULING — pick ONE of the following:
 * ==========================================================================
 *
 * OPTION A — No cron needed (auto-trigger from api.php)
 *   api.php detects a stale cache and fires this script in the background:
 *     exec('php ' . __DIR__ . '/prefetch.php > /dev/null 2>&1 &');
 *   Users always receive an instant (possibly slightly-stale) response.
 *   The next request after the background job finishes gets fresh data.
 *   Requires exec() to be enabled on the server.
 *
 * OPTION B — System cron (most reliable)
 *   Add to crontab (crontab -e):
 *     * /15 * * * *  php /path/to/prefetch.php >> /var/log/nws_prefetch.log 2>&1
 *
 * OPTION C — Web cron service (no server cron access needed)
 *   Use cron-job.org or UptimeRobot to hit this URL every 15 minutes:
 *     https://yoursite.com/prefetch.php?key=YOUR_SECRET_KEY
 *   Uncomment the secret key check below.
 *
 * OPTION D — Run manually at any time
 *     php prefetch.php
 *     curl https://yoursite.com/prefetch.php
 * ==========================================================================
 */

// --- Optional secret key for web-triggered runs (Option C) ----------------
// define('PREFETCH_SECRET', 'change-me-to-something-secret');
// if (php_sapi_name() !== 'cli') {
//     if (!isset($_GET['key']) || $_GET['key'] !== PREFETCH_SECRET) {
//         http_response_code(403); exit('Forbidden');
//     }
// }
// ---------------------------------------------------------------------------

define('NWS_API_BASE',   'https://api.weather.gov');
define('NWS_CACHE_DIR',  sys_get_temp_dir() . '/nws_product_cache');
define('NWS_USER_AGENT', '(OPC Product Map, nws-product-map)');
define('LOCK_FILE',      NWS_CACHE_DIR . '/prefetch.lock');
define('LOCK_TTL',       300);   // Consider lock stale after 5 min
define('FETCH_TIMEOUT',  20);    // Seconds per HTTP request
define('RESULT_TTL',     900);   // 15 min — cache lifetime for result JSON

// Per-type result JSON file names written by this script, read by api.php
define('RESULT_OFFSHORE',  NWS_CACHE_DIR . '/result_offshore.json');
define('RESULT_NAVTEX',    NWS_CACHE_DIR . '/result_navtex.json');
define('RESULT_COASTAL',   NWS_CACHE_DIR . '/result_coastal.json');
define('RESULT_HIGHSEAS',  NWS_CACHE_DIR . '/result_highseas.json');

// ==========================================================================
// PRODUCT DEFINITIONS
// ==========================================================================

// Non-coastal products: key => [apiType, apiOffice, matchString]
// matchString appears near the top of the product text (line 3 or title)
$PRODUCTS = [
    // Offshore — OPC Atlantic (KWBC)
    'NT1'    => ['OFF', 'KWBC', 'OFFNT1'],
    'NT2'    => ['OFF', 'KWBC', 'OFFNT2'],
    'PZ5'    => ['OFF', 'KWBC', 'OFFPZ5'],
    'PZ6'    => ['OFF', 'KWBC', 'OFFPZ6'],
    // Offshore — NHC Miami (KNHC)
    'NT3'    => ['OFF', 'KNHC', 'OFFNT3'],
    'NT4'    => ['OFF', 'KNHC', 'OFFNT4'],
    'NT5'    => ['OFF', 'KNHC', 'OFFNT5'],
    'PZ7'    => ['OFF', 'KNHC', 'OFFPZ7'],
    'PZ8'    => ['OFF', 'KNHC', 'OFFPZ8'],
    // Offshore — Hawaii (PHFO)
    'PH'     => ['OFF', 'PHFO', 'OFFPH'],
    // Offshore — Alaska
    'PKG'    => ['OFF', 'PAFC', 'OFFPKG'],
    'PKB'    => ['OFF', 'PAFC', 'OFFPKB'],
    'PKS'    => ['OFF', 'PAJK', 'OFFPKS'],
    'PKA'    => ['OFF', 'PAFG', 'OFFPKA'],
    // NAVTEX — OPC Atlantic (KWBC)
    'N01'    => ['OFF', 'KWBC', 'OFFN01'],
    'N02'    => ['OFF', 'KWBC', 'OFFN02'],
    'N03'    => ['OFF', 'KWBC', 'OFFN03'],
    // NAVTEX — NHC Miami (KNHC)
    'N04'    => ['OFF', 'KNHC', 'OFFN04'],
    'N05'    => ['OFF', 'KNHC', 'OFFN05'],
    'N06'    => ['OFF', 'KNHC', 'OFFN06'],
    // NAVTEX — OPC Pacific (KWNM)
    'N07'    => ['OFF', 'KWNM', 'OFFN07'],
    'N08'    => ['OFF', 'KWNM', 'OFFN08'],
    'N09'    => ['OFF', 'KWNM', 'OFFN09'],
    // High Seas
    'HSFAT1' => ['HSF', 'KWBC', 'HSFAT1'],
    'HSFEP1' => ['HSF', 'KWBC', 'HSFEP1'],
    'HSFAT2' => ['HSF', 'KNHC', 'HSFAT2'],
    'HSFEP2' => ['HSF', 'KNHC', 'HSFEP2'],
    'HSFNP'  => ['HSF', 'PHFO', 'HSFNP'],
];

// CWF product per WFO (one product per WFO, no matchString needed)
$COASTAL_WFOS = [
    'BOX','GYX','CAR','OKX','PHI','LWX','AKQ',
    'MHX','ILM','CHS','JAX','MLB','MFL','SJU',
    'KEY','TBW','TAE','MOB','LIX','LCH','HGX','CRP','BRO',
    'SEW','PQR','MFR','EKA','MTR','LOX','SGX',
    'HFO','AFC','AFG','AJK',
    'APX','BUF','CLE','DLH','DTX','GRB','GRR','IWX','LOT','MKX','MQT',
    'GUM','PQE','PQW','STU',
];

// ==========================================================================
// PARALLEL HTTP HELPER
// ==========================================================================

/**
 * Fire multiple HTTP GETs simultaneously.
 * Returns array keyed by the same keys as $urls, values = response body or null.
 */
function parallelGet(array $urls, int $timeout = FETCH_TIMEOUT): array {
    if (empty($urls)) return [];

    $mh      = curl_multi_init();
    $handles = [];

    foreach ($urls as $key => $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_USERAGENT      => NWS_USER_AGENT,
            CURLOPT_HTTPHEADER     => ['Accept: application/geo+json'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$key] = $ch;
    }

    $running = null;
    do {
        curl_multi_exec($mh, $running);
        if ($running) curl_multi_select($mh, 0.5);
    } while ($running > 0);

    $results = [];
    foreach ($handles as $key => $ch) {
        $code          = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $body          = curl_multi_getcontent($ch);
        $results[$key] = ($code === 200 && $body) ? $body : null;
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $results;
}

// ==========================================================================
// FETCH ALL PRODUCT TEXTS IN TWO PARALLEL PHASES
// ==========================================================================

/**
 * Phase 1: fetch product-list endpoints for each unique (type, office) pair.
 * Phase 2: fetch individual product texts (up to $scanDepth per office).
 * Returns array: matchString (lowercase) => productText
 */
function fetchAllProductTexts(array $products, int $scanDepth = 20): array {
    // ---- Phase 1: Build list URLs (one per unique type+office) ----
    $listUrls = [];
    foreach ($products as [$type, $office]) {
        $key = strtolower("{$type}_{$office}");
        $listUrls[$key] = NWS_API_BASE . "/products/types/{$type}/locations/{$office}";
    }

    log_msg("Phase 1: fetching " . count($listUrls) . " product lists in parallel...");
    $listResponses = parallelGet($listUrls);

    // ---- Parse lists → collect up to $scanDepth product IDs per office ----
    // officeKey => [productId, ...]
    $productIdsPerOffice = [];
    foreach ($listResponses as $officeKey => $raw) {
        if (!$raw) continue;
        $data  = json_decode($raw, true);
        $items = $data['@graph'] ?? [];
        $ids   = [];
        $seen  = [];
        foreach ($items as $item) {
            $pid = $item['id'] ?? '';
            if (!$pid || in_array($pid, $seen)) continue;
            $seen[] = $pid;
            $ids[]  = $pid;
            if (count($ids) >= $scanDepth) break;
        }
        $productIdsPerOffice[$officeKey] = $ids;
    }

    // ---- Phase 2: Fetch all product texts in parallel ----
    $textUrls = [];
    foreach ($productIdsPerOffice as $ids) {
        foreach ($ids as $pid) {
            $textUrls[$pid] = NWS_API_BASE . "/products/{$pid}";
        }
    }
    // Deduplicate (some products may appear in multiple office lists)
    $textUrls = array_unique($textUrls);

    log_msg("Phase 2: fetching " . count($textUrls) . " product texts in parallel...");
    $textResponses = parallelGet($textUrls);

    // ---- Match each text to its product by matchString ----
    $matched = [];  // matchString => text
    foreach ($textResponses as $pid => $raw) {
        if (!$raw) continue;
        $data = json_decode($raw, true);
        $text = $data['productText'] ?? null;
        if (!$text) continue;
        $upper = strtoupper($text);

        // Check every product to see if this text belongs to it
        foreach ($products as $matchStr => [$type, $office]) {
            if (isset($matched[$matchStr])) continue;  // Already found
            if (stripos($upper, strtoupper($matchStr)) !== false) {
                $matched[$matchStr] = $text;
            }
        }
    }

    return $matched;
}

/**
 * Fetch all CWF products (one per WFO) in two parallel phases.
 * Returns array: wfo => productText
 */
function fetchAllCWFTexts(array $wfos): array {
    // Phase 1: all list endpoints in parallel
    $listUrls = [];
    foreach ($wfos as $wfo) {
        $listUrls[$wfo] = NWS_API_BASE . "/products/types/CWF/locations/{$wfo}";
    }
    log_msg("Coastal Phase 1: fetching " . count($listUrls) . " CWF lists in parallel...");
    $listResponses = parallelGet($listUrls);

    // Extract most recent product ID per WFO
    $textUrls = [];
    foreach ($listResponses as $wfo => $raw) {
        if (!$raw) continue;
        $data  = json_decode($raw, true);
        $items = $data['@graph'] ?? [];
        if (!empty($items[0]['id'])) {
            $textUrls[$wfo] = NWS_API_BASE . "/products/{$items[0]['id']}";
        }
    }

    log_msg("Coastal Phase 2: fetching " . count($textUrls) . " CWF texts in parallel...");
    $textResponses = parallelGet($textUrls);

    $results = [];
    foreach ($textResponses as $wfo => $raw) {
        if (!$raw) continue;
        $data = json_decode($raw, true);
        $text = $data['productText'] ?? null;
        if ($text) $results[$wfo] = $text;
    }
    return $results;
}

// ==========================================================================
// SHARED PARSING (mirrors api.php logic — keep in sync)
// ==========================================================================

function pf_extractWarning(string $text): string {
    $t = strtoupper($text);
    $checks = [
        'HURRICANE FORCE WIND WARNING','HURRICANE FORCE WIND WATCH',
        'HURRICANE WARNING','HURRICANE WATCH',
        'TROPICAL STORM WARNING','TROPICAL STORM WATCH',
        'STORM WARNING','STORM WATCH',
        'GALE WARNING','GALE WATCH',
        'HAZARDOUS SEAS WARNING','HAZARDOUS SEAS WATCH',
        'STORM SURGE WARNING','STORM SURGE WATCH',
        'HEAVY FREEZING SPRAY WARNING','HEAVY FREEZING SPRAY WATCH',
        'FREEZING SPRAY ADVISORY','SPECIAL MARINE WARNING',
        'HIGH SURF WARNING','HIGH SURF ADVISORY',
        'SMALL CRAFT ADVISORY','BRISK WIND ADVISORY',
        'WIND ADVISORY','LAKE WIND ADVISORY','DENSE FOG ADVISORY',
        'COASTAL FLOOD WARNING','COASTAL FLOOD WATCH','COASTAL FLOOD ADVISORY',
        'LAKESHORE FLOOD WARNING','LAKESHORE FLOOD WATCH','LAKESHORE FLOOD ADVISORY',
        'TSUNAMI WARNING','TSUNAMI WATCH','TSUNAMI ADVISORY',
        'MARINE WEATHER STATEMENT',
    ];
    foreach ($checks as $w) {
        if (strpos($t, $w) !== false) return $w;
    }
    return 'NONE';
}

function pf_extractTime(string $text): string {
    if (preg_match('/(\d{3,4}\s*(?:AM|PM)\s*\w+\s+\w+\s+\w+\s+\d+\s+\d{4})/i', $text, $m)) {
        return trim($m[1]);
    }
    return date('g:i A T D M j Y');
}

function pf_parseZoneForecast(string $content, array $zones, array $zoneNames): array {
    $results  = [];
    $issueTime = pf_extractTime($content);

    foreach ($zones as $zone) {
        $pattern = '/' . preg_quote($zone, '/') . '-\d{6}-\s*(.*?)\n\$\$/s';
        if (!preg_match($pattern, $content, $m)) continue;

        $zoneText = $m[1];
        $forecast = [];
        preg_match_all('/\.([A-Z][A-Z\s]*?)\.\.\.([^.]*(?:\.[^A-Z][^.]*)*)/s', $zoneText, $pm, PREG_SET_ORDER);

        $validStarts = ['TODAY','TONIGHT','MON','TUE','WED','THU','FRI','SAT','SUN','REST'];
        foreach ($pm as $match) {
            $periodName = trim($match[1]);
            $periodText = trim($match[2]);
            $valid = false;
            foreach ($validStarts as $v) {
                if (stripos($periodName, $v) === 0) { $valid = true; break; }
            }
            if (!$valid) continue;

            $clean = preg_replace('/\s+/', ' ', $periodText);

            // Winds
            $winds = 'Variable winds';
            if (preg_match('/([NSEW]{1,2}(?:\s+TO\s+[NSEW]{1,2})?\s+(?:WINDS?\s+)?(\d+)\s*(?:TO\s*)?(\d+)?\s*KT)/i', $clean, $wm)) {
                $winds = ucfirst(strtolower(trim($wm[0])));
            }

            // Seas
            $seas = 'Seas variable';
            if (preg_match('/(?:SEAS?|COMBINED\s+SEAS?)\s+(\d+)\s*(?:TO\s*)?(\d+)?\s*FT/i', $clean, $sm)) {
                $lo = $sm[1]; $hi = isset($sm[2]) && $sm[2] ? $sm[2] : $lo;
                $seas = "Seas $lo to $hi ft";
            } elseif (preg_match('/(\d+)\s+TO\s+(\d+)\s*FT/i', $clean, $sm2)) {
                $seas = "Seas {$sm2[1]} to {$sm2[2]} ft";
            }

            // Weather keywords
            $weather = 'N/A';
            foreach (['freezing spray','rain','snow','fog','tstms','thunderstorms','showers','drizzle'] as $wp) {
                if (stripos($clean, $wp) !== false) {
                    if (preg_match('/((?:chance\s+of\s+|isolated\s+|scattered\s+)?' . $wp . '[^.]*?)(?:\.|$)/i', $clean, $wxm)) {
                        $weather = ucfirst(strtolower(trim($wxm[1])));
                    }
                    break;
                }
            }

            $forecast[] = ['Day' => ucwords(strtolower($periodName)), 'Winds' => $winds, 'Seas' => $seas, 'Weather' => $weather];
        }

        if (empty($forecast)) {
            $forecast[] = ['Day' => 'Today', 'Winds' => 'Data unavailable', 'Seas' => 'Data unavailable', 'Weather' => 'N/A'];
        }

        $results[] = [
            'zone'     => $zone,
            'name'     => $zoneNames[$zone] ?? $zone,
            'time'     => $issueTime,
            'warning'  => pf_extractWarning($zoneText),
            'forecast' => $forecast,
        ];
    }
    return $results;
}

// ==========================================================================
// MAIN
// ==========================================================================

function log_msg(string $msg): void {
    $ts = date('Y-m-d H:i:s');
    if (php_sapi_name() === 'cli') {
        echo "[$ts] $msg\n";
    }
    // When run via web, output is suppressed (caller doesn't wait for response)
}

function is_locked(): bool {
    if (!file_exists(LOCK_FILE)) return false;
    // Treat as stale if lock is older than LOCK_TTL
    if (time() - filemtime(LOCK_FILE) > LOCK_TTL) {
        @unlink(LOCK_FILE);
        return false;
    }
    return true;
}

function result_is_fresh(string $file): bool {
    return file_exists($file) && (time() - filemtime($file)) < RESULT_TTL;
}

// ---- Guard: don't run concurrently ----
if (is_locked()) {
    log_msg("Prefetch already running (lock file present). Exiting.");
    if (php_sapi_name() !== 'cli') {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'skipped', 'reason' => 'already_running']);
    }
    exit;
}

// ---- Ensure cache directory exists ----
if (!is_dir(NWS_CACHE_DIR)) @mkdir(NWS_CACHE_DIR, 0755, true);

// ---- Acquire lock ----
file_put_contents(LOCK_FILE, date('Y-m-d H:i:s') . ' pid=' . getmypid());

$startTime = microtime(true);
$report    = ['started' => date('c'), 'products' => []];

register_shutdown_function(function () {
    @unlink(LOCK_FILE);
});

// ==========================================================================
// We need zone/name mappings to parse offshore and coastal products.
// Pull them from api.php without executing its request-handling code.
// We do this by including api.php in a way that only defines the data arrays.
// api.php skips its main execution block when PREFETCH_INCLUDED is set.
// ==========================================================================
define('PREFETCH_INCLUDED', true);
include __DIR__ . '/api.php';
// After include, $ZONE_MAPPINGS, $ZONE_NAMES, $COASTAL_ZONE_MAPPINGS,
// $NAVTEX_NAME_TO_ID, $NAVTEX_ZONES are all available.

// ==========================================================================
// OFFSHORE + NAVTEX
// ==========================================================================
log_msg("=== Fetching offshore + NAVTEX products ===");

// Map matchString => [type, office] for the two-phase fetcher
$offshoreMatchMap = [];
foreach ($PRODUCTS as $key => [$type, $office, $match]) {
    if (in_array($type, ['OFF', 'HSF'])) {
        $offshoreMatchMap[$match] = [$type, $office];
    }
}

$productTexts = fetchAllProductTexts($offshoreMatchMap);
log_msg("Matched " . count($productTexts) . " / " . count($offshoreMatchMap) . " products");

// Build offshore result
$offshoreResult = [];
$navtexResult   = [];
foreach ($OFFSHORE_FILES as $productKey => $localFile) {
    $cfg = $NWS_PRODUCT_API[$productKey] ?? null;
    if (!$cfg) continue;
    $text = $productTexts[$cfg[2]] ?? null;
    if (!$text) { log_msg("MISS: $productKey ({$cfg[2]})"); continue; }

    if (isset($ZONE_MAPPINGS[$productKey])) {
        $parsed = pf_parseZoneForecast($text, $ZONE_MAPPINGS[$productKey], $ZONE_NAMES);
        $offshoreResult = array_merge($offshoreResult, $parsed);
        log_msg("OK offshore: $productKey → " . count($parsed) . " zones");
    }
}
foreach ($NAVTEX_FILES as $productKey => $localFile) {
    $cfg = $NWS_PRODUCT_API[$productKey] ?? null;
    if (!$cfg) continue;
    $text = $productTexts[$cfg[2]] ?? null;
    if (!$text) { log_msg("MISS navtex: $productKey ({$cfg[2]})"); continue; }

    // Reuse api.php's parseNavtexProduct via the include
    $parsed = parseNavtexProduct($text, $NAVTEX_NAME_TO_ID, $NAVTEX_ZONES);
    $navtexResult = array_merge($navtexResult, $parsed);
    log_msg("OK navtex: $productKey → " . count($parsed) . " zones");
}

// ==========================================================================
// HIGH SEAS
// ==========================================================================
log_msg("=== Fetching high seas products ===");
$highseasResult = [];
$highseasDefs = [
    ['HSFAT1', 'North Atlantic High Seas (OPC)'],
    ['HSFAT2', 'Tropical Atlantic / Caribbean / Gulf High Seas (NHC)'],
    ['HSFEP1', 'North Pacific High Seas (OPC)'],
    ['HSFEP2', 'Eastern North Pacific High Seas (NHC)'],
    ['HSFNP',  'Central North Pacific High Seas (HFO)'],
];
foreach ($highseasDefs as [$zoneId, $zoneName]) {
    $text = $productTexts[$zoneId] ?? null;
    if (!$text) { log_msg("MISS highseas: $zoneId"); continue; }
    $highseasResult[] = [
        'zone'    => $zoneId,
        'name'    => $zoneName,
        'time'    => pf_extractTime($text),
        'warning' => pf_extractWarning($text),
        'rawText' => trim($text),
    ];
    log_msg("OK highseas: $zoneId");
}

// ==========================================================================
// COASTAL
// ==========================================================================
log_msg("=== Fetching coastal products (" . count($COASTAL_WFOS) . " WFOs) ===");
$coastalTexts  = fetchAllCWFTexts($COASTAL_WFOS);
$coastalResult = [];
foreach ($coastalTexts as $wfo => $text) {
    $zones = $COASTAL_ZONE_MAPPINGS[$wfo] ?? [];
    if (empty($zones)) continue;
    $parsed = pf_parseZoneForecast($text, $zones, []);
    $coastalResult = array_merge($coastalResult, $parsed);
}
log_msg("Coastal: " . count($coastalTexts) . " WFOs fetched → " . count($coastalResult) . " zones parsed");

// ==========================================================================
// WRITE RESULT FILES
// ==========================================================================
$writes = [
    RESULT_OFFSHORE => $offshoreResult,
    RESULT_NAVTEX   => $navtexResult,
    RESULT_COASTAL  => $coastalResult,
    RESULT_HIGHSEAS => $highseasResult,
];
foreach ($writes as $file => $data) {
    if (!empty($data)) {
        file_put_contents($file, json_encode($data));
        log_msg("Wrote " . basename($file) . " (" . count($data) . " records)");
    }
}

$elapsed = round(microtime(true) - $startTime, 2);
$report  = [
    'status'   => 'ok',
    'elapsed'  => "{$elapsed}s",
    'offshore' => count($offshoreResult) . ' zones',
    'navtex'   => count($navtexResult)   . ' zones',
    'coastal'  => count($coastalResult)  . ' zones',
    'highseas' => count($highseasResult) . ' zones',
    'finished' => date('c'),
];

log_msg("Done in {$elapsed}s — offshore:" . count($offshoreResult) .
        " navtex:" . count($navtexResult) .
        " coastal:" . count($coastalResult) .
        " highseas:" . count($highseasResult));

if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json');
    echo json_encode($report, JSON_PRETTY_PRINT);
}
