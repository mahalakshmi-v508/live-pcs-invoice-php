<?php
$file = __DIR__ . '/export_test.xlsx';
$zip = new ZipArchive();
if ($zip->open($file) !== true) {
    echo "ZIP OPEN FAILED\n";
    exit(1);
}
$sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
preg_match_all('/<row r="\d+"[^>]*>.*?<\/row>/s', $sheet, $m);
echo "rows: " . count($m[0]) . "\n";
foreach (array_slice($m[0], 0, 5) as $row) {
    preg_match_all('/<c r="([A-Z]+)\d+"[^>]*>(?:<is><t[^>]*>(.*?)<\/t><\/is>|<v>(.*?)<\/v>)<\/c>/s', $row, $cells, PREG_SET_ORDER);
    $line = [];
    foreach ($cells as $cell) {
        $line[] = ($cell[2] !== '' ? $cell[2] : $cell[3]);
    }
    echo implode(' | ', $line) . "\n";
}
$zip->close();
