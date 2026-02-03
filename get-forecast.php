<?php
// get-forecast.php
header('Content-Type: text/plain');
header('Cache-Control: no-cache, must-revalidate');

$pil = $_GET['pil'] ?? '';
$allowed = ['WRKFWNX01', 'WRKFWNX02', 'WRKFWNX03', 'WRKFWNXPT', 'FWCSD'];

if (!in_array($pil, $allowed)) {
    http_response_code(400);
    exit('Invalid PIL');
}

$docRoot = $_SERVER['DOCUMENT_ROOT'];

// Map PILs to file paths (relative to document root)
$files = [
    'WRKFWNX01' => $docRoot . '/shtml/WRKFWNX01.txt',
    'WRKFWNX02' => $docRoot . '/shtml/WRKFWNX02.txt',
    'WRKFWNX03' => $docRoot . '/shtml/WRKFWNX03.txt',
    'WRKFWNXPT' => $docRoot . '/shtml/WRKFWNXPT.txt',
    'FWCSD' => $docRoot . '/.cj/.monitor/.navy/gfe_navy_zones_fwcsc1_onp_latest.txt'
];

$file = $files[$pil] ?? null;

if ($file && file_exists($file) && is_readable($file)) {
    readfile($file);
} else {
    http_response_code(404);
    echo "File not found: $pil";
}
?>
