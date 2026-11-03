<?php
if (ob_get_level() > 0) {
    ob_clean();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
ini_set('display_errors', '0');

require_once 'session.php';
ensureSessionStarted();
require_once 'Invoice.php';

if (empty($_SESSION['userId'])) {
    echo json_encode([
        'status' => 0,
        'customers' => []
    ]);
    exit;
}

$query = trim((string) ($_GET['q'] ?? $_POST['q'] ?? ''));
if ($query === '') {
    echo json_encode([
        'status' => 1,
        'customers' => []
    ]);
    exit;
}

try {
    $invoice = new Invoice();
    $customers = $invoice->getCustomerSuggestions($query, 8);

    echo json_encode([
        'status' => 1,
        'customers' => is_array($customers) ? $customers : []
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode([
        'status' => 0,
        'customers' => []
    ]);
}
exit;
