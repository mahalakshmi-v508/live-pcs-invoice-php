<?php
require_once 'session.php';
ensureSessionStarted();

if (empty($_SESSION['userId'])) {
    http_response_code(401);
    echo 'Please log in to view this quotation.';
    exit;
}

require_once 'Quotation.php';
require_once 'quotation_pdf_generator.php';

$quotationId = intval($_GET['id'] ?? 0);
if ($quotationId <= 0) {
    http_response_code(400);
    echo 'Invalid quotation ID.';
    exit;
}

$quotationService = new Quotation();
$quotation = $quotationService->getQuotation($quotationId);
if (empty($quotation)) {
    http_response_code(404);
    echo 'Quotation not found.';
    exit;
}

$pdfBytes = generateQuotationPdfBytes($quotationId);
if ($pdfBytes === '') {
    http_response_code(500);
    echo 'Unable to generate the quotation PDF.';
    exit;
}

$safeNumber = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $quotation['quotation_no']);
if ($safeNumber === '') {
    $safeNumber = 'Quotation-' . $quotationId;
}
$filename = 'Quotation-' . $safeNumber . '.pdf';
$isDownload = isset($_GET['download']) && $_GET['download'] === '1';

header('Content-Type: application/pdf');
header('Content-Disposition: ' . ($isDownload ? 'attachment' : 'inline') . '; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdfBytes));
header('X-Content-Type-Options: nosniff');
echo $pdfBytes;
exit;
