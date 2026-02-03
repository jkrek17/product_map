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

// Map PILs to file paths
$files = [
    'WRKFWNX01' => '/home/people/opc/www/htdocs/shtml/WRKFWNX01.txt',
    'WRKFWNX02' => '/home/people/opc/www/htdocs/shtml/WRKFWNX02.txt',
    'WRKFWNX03' => '/home/people/opc/www/htdocs/shtml/WRKFWNX03.txt',
    'WRKFWNXPT' => '/home/people/opc/www/htdocs/shtml/WRKFWNXPT.txt',
    'FWCSD' => '/home/people/opc/www/htdocs/.cj/.monitor/.navy/gfe_navy_zones_fwcsc1_onp_latest.txt'
];

$file = $files[$pil] ?? null;

if ($file && file_exists($file) && is_readable($file)) {
    readfile($file);
} else {
    http_response_code(404);
    echo "File not found: $pil";
}
?>
