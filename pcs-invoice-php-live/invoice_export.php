<?php
/**
 * Excel (.xlsx) export for the invoice list.
 * Exports every invoice matching the filters currently applied on invoice_list.php.
 */
require_once 'session.php';
ensureSessionStarted();
require_once 'Invoice.php';

$invoiceService = new Invoice();
$invoiceService->checkLoggedIn();

$filters = [
    'month' => $_GET['month'] ?? '',
    'date' => $_GET['date'] ?? '',
    'currency' => $_GET['currency'] ?? '',
    'gst_type' => $_GET['gst_type'] ?? '',
];

$invoices = $invoiceService->getInvoicesForExport($filters);

$hsnSacMap = $invoiceService->getHsnSacMapForInvoices(array_column($invoices, 'id'));

$headers = [
    'Invoice No',
    'HSN / SAC',
    'Customer Name',
    'GST No',
    'PAN No',
    'Invoice Date',
    'Currency',
    'Subtotal',
    'CGST',
    'SGST',
    'Total',
];
$numericColumns = [7, 8, 9, 10];

$rows = [];
foreach ($invoices as $invoiceRow) {
    $dateValue = trim((string) ($invoiceRow['invoice_date'] ?? ''));
    if ($dateValue === '' || $dateValue === '0000-00-00') {
        $dateValue = (string) ($invoiceRow['created_at'] ?? '');
    }
    $dateTimestamp = $dateValue !== '' ? strtotime($dateValue) : false;

    $rows[] = [
        $invoiceService->getDisplayInvoiceNumber($invoiceRow),
        (string) ($hsnSacMap[intval($invoiceRow['id'] ?? 0)] ?? ''),
        (string) ($invoiceRow['receiver_name'] ?? ''),
        (string) ($invoiceRow['gst_no'] ?? ''),
        (string) ($invoiceRow['pan_no'] ?? ''),
        $dateTimestamp !== false ? date('d-m-Y', $dateTimestamp) : '',
        strtoupper((string) ($invoiceRow['currency'] ?? 'INR')),
        (float) ($invoiceRow['rate'] ?? 0),
        (float) ($invoiceRow['cgst_amount'] ?? 0),
        (float) ($invoiceRow['sgst_amount'] ?? 0),
        (float) ($invoiceRow['total'] ?? 0),
    ];
}

// No matching invoices: show the same alert the list page uses and stay put.
if (empty($rows)) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>No data</title></head><body>'
        . '<script>alert("No data available to download.");'
        . 'if (document.referrer) { window.location.href = document.referrer; }'
        . 'else { history.back(); }</script>'
        . '<p style="font-family: Arial, sans-serif; text-align: center; margin-top: 40px;">'
        . 'No data available to download. <a href="invoice_list.php">Back to invoices</a></p>'
        . '</body></html>';
    exit;
}

function pcsXlsxEscape($value): string
{
    $value = (string) $value;
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value);
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
}

function pcsXlsxColumnLetter(int $index): string
{
    $letter = '';
    while ($index > 0) {
        $mod = ($index - 1) % 26;
        $letter = chr(65 + $mod) . $letter;
        $index = intdiv($index - 1, 26);
    }
    return $letter;
}

$columnCount = count($headers);
$lastColumnLetter = pcsXlsxColumnLetter($columnCount);
$lastRowNumber = count($rows) + 1;

// Fallback for hosts without the PHP zip extension: CSV opens directly in Excel.
if (!class_exists('ZipArchive')) {
    $fileName = 'invoices_' . date('Y-m-d_Hi') . '.csv';
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Cache-Control: max-age=0, no-cache, must-revalidate');
    $out = fopen('php://output', 'w');
    if ($out !== false) {
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $headers);
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        fclose($out);
    }
    exit;
}

$sheetRows = '';
$headerCells = '';
foreach ($headers as $index => $header) {
    $cellRef = pcsXlsxColumnLetter($index + 1) . '1';
    $headerCells .= '<c r="' . $cellRef . '" s="1" t="inlineStr"><is><t>'
        . pcsXlsxEscape($header) . '</t></is></c>';
}
$sheetRows .= '<row r="1">' . $headerCells . '</row>';

foreach ($rows as $rowIndex => $row) {
    $excelRow = $rowIndex + 2;
    $cells = '';
    foreach ($row as $columnIndex => $value) {
        $cellRef = pcsXlsxColumnLetter($columnIndex + 1) . $excelRow;
        if (in_array($columnIndex, $numericColumns, true)) {
            $cells .= '<c r="' . $cellRef . '"><v>' . number_format((float) $value, 2, '.', '') . '</v></c>';
        } else {
            $cells .= '<c r="' . $cellRef . '" t="inlineStr"><is><t xml:space="preserve">'
                . pcsXlsxEscape($value) . '</t></is></c>';
        }
    }
    $sheetRows .= '<row r="' . $excelRow . '">' . $cells . '</row>';
}

$columnWidths = [12, 16, 32, 20, 18, 14, 10, 14, 12, 12, 14];
$colsXml = '<cols>';
foreach ($columnWidths as $index => $width) {
    $columnNumber = $index + 1;
    $colsXml .= '<col min="' . $columnNumber . '" max="' . $columnNumber . '" width="' . $width . '" customWidth="1"/>';
}
$colsXml .= '</cols>';

$contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    . '<Default Extension="xml" ContentType="application/xml"/>'
    . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
    . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
    . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
    . '</Types>';

$rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
    . '</Relationships>';

$workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
    . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
    . '<sheets><sheet name="Invoices" sheetId="1" r:id="rId1"/></sheets>'
    . '</workbook>';

$workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
    . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
    . '</Relationships>';

$styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<fonts count="2">'
    . '<font><sz val="11"/><name val="Calibri"/></font>'
    . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
    . '</fonts>'
    . '<fills count="3">'
    . '<fill><patternFill patternType="none"/></fill>'
    . '<fill><patternFill patternType="gray125"/></fill>'
    . '<fill><patternFill patternType="solid"><fgColor rgb="FF1F4E79"/><bgColor indexed="64"/></patternFill></fill>'
    . '</fills>'
    . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
    . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
    . '<cellXfs count="2">'
    . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
    . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
    . '</cellXfs>'
    . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
    . '</styleSheet>';

$worksheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<sheetViews><sheetView tabSelected="1" workbookViewId="0">'
    . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
    . '</sheetView></sheetViews>'
    . '<sheetFormatPr defaultRowHeight="15"/>'
    . $colsXml
    . '<sheetData>' . $sheetRows . '</sheetData>'
    . '<autoFilter ref="A1:' . $lastColumnLetter . $lastRowNumber . '"/>'
    . '</worksheet>';

$tempFile = tempnam(sys_get_temp_dir(), 'pcs_invoice_');
if ($tempFile === false) {
    http_response_code(500);
    echo 'Unable to create the Excel file.';
    exit;
}

$zip = new ZipArchive();
if ($zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    @unlink($tempFile);
    http_response_code(500);
    echo 'Unable to create the Excel file.';
    exit;
}

$zip->addFromString('[Content_Types].xml', $contentTypes);
$zip->addFromString('_rels/.rels', $rootRels);
$zip->addFromString('xl/workbook.xml', $workbook);
$zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
$zip->addFromString('xl/styles.xml', $styles);
$zip->addFromString('xl/worksheets/sheet1.xml', $worksheet);
$zip->close();

$fileName = 'invoices_' . date('Y-m-d_Hi') . '.xlsx';

while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Content-Length: ' . filesize($tempFile));
header('Cache-Control: max-age=0, no-cache, must-revalidate');
header('Pragma: public');
readfile($tempFile);
@unlink($tempFile);
exit;
