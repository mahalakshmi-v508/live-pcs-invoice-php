<?php
/**
 * Customer-facing quotation review page.
 *
 * The 64-character public token is the only credential required. It is
 * generated when a quotation email is sent and is never exposed on any
 * authenticated management screen.
 */
require_once __DIR__ . '/Quotation.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

$token = '';
if (isset($_GET['token']) && is_string($_GET['token'])) {
    $token = $_GET['token'];
} elseif (isset($_POST['token']) && is_string($_POST['token'])) {
    $token = $_POST['token'];
}
$token = strtolower(trim($token));

$quotationService = new Quotation();
$quotation = $quotationService->getQuotationByToken($token);
$items = !empty($quotation) ? $quotationService->getQuotationItems(intval($quotation['id'])) : [];
$showQuantityColumn = false;
foreach ($items as $item) {
    if ((float) ($item['quantity'] ?? 0) > 0) {
        $showQuantityColumn = true;
        break;
    }
}

$message = '';
$messageType = '';
$responseRecorded = false;
$requestedResponse = isset($_GET['response']) && is_string($_GET['response'])
    ? strtolower($_GET['response']) : '';
if (!in_array($requestedResponse, ['accept', 'reject'], true)) {
    $requestedResponse = '';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !empty($quotation)) {
    $postedToken = isset($_POST['token']) && is_string($_POST['token'])
        ? strtolower(trim($_POST['token'])) : '';
    $decision = isset($_POST['decision']) && is_string($_POST['decision'])
        ? strtolower(trim($_POST['decision'])) : '';
    $reason = isset($_POST['rejection_reason']) && is_string($_POST['rejection_reason'])
        ? $_POST['rejection_reason'] : '';
    $confirmed = isset($_POST['confirm']) && (string) $_POST['confirm'] === '1';

    if (!hash_equals($token, $postedToken)) {
        $message = 'This quotation link is invalid. Please use the link from your email.';
        $messageType = 'danger';
    } elseif (!in_array($decision, ['accept', 'reject'], true)) {
        $message = 'Please choose whether to accept or reject this quotation.';
        $messageType = 'danger';
    } elseif (!$confirmed) {
        $message = 'Please tick the confirmation box before submitting your response.';
        $messageType = 'danger';
    } else {
        try {
            $result = $quotationService->respondToQuotation($token, $decision, $reason);
            $message = (string) ($result['message'] ?? 'Your response could not be recorded.');
            $messageType = !empty($result['ok']) ? 'success' : 'danger';
            $responseRecorded = !empty($result['ok']) || !empty($result['already']);

            // Refresh the displayed record and status.
            $quotation = $quotationService->getQuotationByToken($token);
            if (empty($quotation)) {
                $items = [];
            }
        } catch (Throwable $exception) {
            $message = 'Your response could not be recorded. Please try again later.';
            $messageType = 'danger';
        }
    }
}

$status = !empty($quotation) ? (string) $quotation['status'] : '';
$responseStatus = !empty($quotation['is_converted'])
    ? 'Converted'
    : (!empty($quotation['is_accepted'])
        ? 'Accepted'
        : (!empty($quotation['is_rejected'])
            ? 'Rejected'
            : (!empty($quotation['is_expired']) ? 'Expired' : '')));
$currency = !empty($quotation) ? (string) ($quotation['currency'] ?? 'INR') : 'INR';
$currencySymbol = Quotation::currencySymbol($currency);
$validUntil = !empty($quotation['valid_until']) ? (string) $quotation['valid_until'] : '';
$isOpen = !empty($quotation)
    && $status === 'Sent'
    && $responseStatus === ''
    && ($validUntil === '' || $validUntil >= date('Y-m-d'))
    && empty($quotation['converted_invoice_id']);

$e = static function ($value): string {
    return Quotation::escape($value);
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Quotation Response</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/quotation.css?v=<?php echo filemtime(__DIR__ . '/css/quotation.css'); ?>">
</head>
<body class="bg-light">
    <div class="container py-4" style="max-width: 900px;">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
            <div>
                <h1 class="h4 mb-1">Quotation Review</h1>
                <div class="text-muted small">Pavitha Consultancy Services OPC Pvt Ltd.</div>
            </div>
            <?php if (!empty($quotation)): ?>
                <span class="badge text-bg-secondary"><?php echo $e($status); ?></span>
            <?php endif; ?>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $e($messageType === 'success' ? 'success' : 'danger'); ?>" role="alert">
                <?php echo $e($message); ?>
            </div>
        <?php endif; ?>

        <?php if (empty($quotation)): ?>
            <div class="card shadow-sm">
                <div class="card-body">
                    <h2 class="h5">Quotation not available</h2>
                    <p class="mb-0 text-muted">
                        This quotation link is invalid or has expired. Please contact us if you need a new link.
                    </p>
                </div>
            </div>
        <?php else: ?>
            <div class="card shadow-sm mb-3">
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="text-muted small">Quotation No.</div>
                            <div class="fw-semibold"><?php echo $e($quotation['quotation_no'] ?? ''); ?></div>
                            <div class="text-muted small mt-3">Customer</div>
                            <div class="fw-semibold"><?php echo $e($quotation['customer_name'] ?? ''); ?></div>
                            <?php if (!empty($quotation['customer_email'])): ?>
                                <div class="text-muted small"><?php echo $e($quotation['customer_email']); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($quotation['customer_phone'])): ?>
                                <div class="text-muted small"><?php echo $e($quotation['customer_phone']); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6 text-md-end">
                            <div class="text-muted small">Quotation Date &amp; Time</div>
                            <div class="fw-semibold">
                                <?php echo $e(Quotation::formatQuotationDateTime($quotation)); ?>
                            </div>
                            <div class="text-muted small mt-3">Valid Until</div>
                            <div class="fw-semibold">
                                <?php echo $validUntil !== '' ? $e(date('d M Y', strtotime($validUntil))) : '&mdash;'; ?>
                            </div>
                            <div class="text-muted small mt-3">Grand Total</div>
                            <div class="h4 mb-0"><?php echo $e($currencySymbol . ' ' . number_format((float) ($quotation['grand_total'] ?? 0), 2)); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm mb-3">
                <div class="card-body">
                    <h2 class="h5 mb-3">Quotation Details</h2>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Description</th>
                                    <?php if ($showQuantityColumn): ?>
                                        <th class="text-center">Qty</th>
                                    <?php endif; ?>
                                    <th class="text-end">Unit Price</th>
                                    <th class="text-end">Discount</th>
                                    <th class="text-end">Line Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($items)): ?>
                                    <tr>
                                        <td colspan="<?php echo $showQuantityColumn ? 6 : 5; ?>" class="text-center text-muted">No items available.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($items as $index => $item): ?>
                                        <tr>
                                            <td><?php echo $index + 1; ?></td>
                                            <td>
                                                <strong><?php echo $e($item['product_name'] ?? ''); ?></strong>
                                            </td>
                                            <?php if ($showQuantityColumn): ?>
                                                <td class="text-center"><?php echo rtrim(rtrim(number_format((float) ($item['quantity'] ?? 0), 2, '.', ''), '0'), '.'); ?></td>
                                            <?php endif; ?>
                                            <td class="text-end"><?php echo $e($currencySymbol . ' ' . number_format((float) ($item['unit_price'] ?? 0), 2)); ?></td>
                                            <td class="text-end"><?php echo $e($currencySymbol . ' ' . number_format((float) ($item['discount'] ?? 0), 2)); ?></td>
                                            <td class="text-end"><?php echo $e($currencySymbol . ' ' . number_format((float) ($item['line_total'] ?? 0), 2)); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="row justify-content-end">
                        <div class="col-md-5">
                            <table class="table table-sm mb-0">
                                <tr><th>Subtotal</th><td class="text-end"><?php echo $e($currencySymbol . ' ' . number_format((float) ($quotation['subtotal'] ?? 0), 2)); ?></td></tr>
                                <tr><th>Total Discount</th><td class="text-end"><?php echo $e($currencySymbol . ' ' . number_format((float) ($quotation['total_discount'] ?? 0), 2)); ?></td></tr>
                                <?php
                                $acceptTaxAmount = round((float) ($quotation['tax_amount'] ?? 0), 2);
                                if ($acceptTaxAmount > 0):
                                    $acceptCgst = round($acceptTaxAmount / 2, 2);
                                    $acceptSgst = round($acceptTaxAmount - $acceptCgst, 2);
                                    ?>
                                    <tr><th>CGST (9%)</th><td class="text-end"><?php echo $e($currencySymbol . ' ' . number_format($acceptCgst, 2)); ?></td></tr>
                                    <tr><th>SGST (9%)</th><td class="text-end"><?php echo $e($currencySymbol . ' ' . number_format($acceptSgst, 2)); ?></td></tr>
                                <?php endif; ?>
                                <tr class="fw-bold"><th>Grand Total</th><td class="text-end"><?php echo $e($currencySymbol . ' ' . number_format((float) ($quotation['grand_total'] ?? 0), 2)); ?></td></tr>
                            </table>
                        </div>
                    </div>

                    <hr>
                    <div class="text-muted small">Terms &amp; Conditions</div>
                    <div><?php echo nl2br($e(trim((string) ($quotation['terms_conditions'] ?? '')) !== '' ? $quotation['terms_conditions'] : 'Thanks for doing business with us!')); ?></div>

                    <hr>
                    <div class="text-muted small">Bank Details</div>
                    <div>
                        <?php foreach (Quotation::bankDetails(
                            (string) ($quotation['gst_type'] ?? 'with_gst'),
                            (string) ($quotation['currency'] ?? 'INR')
                        ) as $bankLabel => $bankValue): ?>
                            <div><strong><?php echo $e($bankLabel); ?>:</strong> <?php echo $e($bankValue); ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <?php if ($isOpen): ?>
                <div class="row g-3">
                    <div class="col-md-6" id="accept">
                        <div class="card border-success shadow-sm h-100">
                            <div class="card-body">
                                <h2 class="h5 text-success">Accept this quotation</h2>
                                <p class="small text-muted">
                                    By accepting, you confirm that you agree to this quotation and its terms.
                                </p>
                                <form method="post" action="quotation_accept.php" id="acceptForm">
                                    <input type="hidden" name="token" value="<?php echo $e($token); ?>">
                                    <input type="hidden" name="decision" value="accept">
                                    <div class="form-check mb-3">
                                        <input class="form-check-input" type="checkbox" name="confirm" value="1" id="confirmAccept" required>
                                        <label class="form-check-label small" for="confirmAccept">
                                            I confirm that I accept quotation <?php echo $e($quotation['quotation_no'] ?? ''); ?>.
                                        </label>
                                    </div>
                                    <button type="submit" class="btn btn-success w-100">
                                        <i class="bi bi-check2-circle"></i> Confirm Acceptance
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6" id="reject">
                        <div class="card border-danger shadow-sm h-100">
                            <div class="card-body">
                                <h2 class="h5 text-danger">Reject this quotation</h2>
                                <p class="small text-muted">
                                    You may optionally tell us why you are rejecting this quotation.
                                </p>
                                <form method="post" action="quotation_accept.php" id="rejectForm">
                                    <input type="hidden" name="token" value="<?php echo $e($token); ?>">
                                    <input type="hidden" name="decision" value="reject">
                                    <div class="mb-3">
                                        <label class="form-label small" for="rejectionReason">Reason (optional)</label>
                                        <textarea class="form-control" id="rejectionReason" name="rejection_reason"
                                            rows="3" maxlength="1000" placeholder="Why are you rejecting this quotation?"></textarea>
                                    </div>
                                    <div class="form-check mb-3">
                                        <input class="form-check-input" type="checkbox" name="confirm" value="1" id="confirmReject" required>
                                        <label class="form-check-label small" for="confirmReject">
                                            I confirm that I want to reject quotation <?php echo $e($quotation['quotation_no'] ?? ''); ?>.
                                        </label>
                                    </div>
                                    <button type="submit" class="btn btn-danger w-100">
                                        <i class="bi bi-x-circle"></i> Confirm Rejection
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="card shadow-sm">
                    <div class="card-body">
                        <?php if ($responseStatus === 'Accepted'): ?>
                            <h2 class="h5 text-success mb-1">Quotation accepted</h2>
                            <p class="mb-0 text-muted">
                                Thank you. This quotation was accepted
                                <?php if (!empty($quotation['accepted_at'])): ?>
                                    on <?php echo $e(date('d M Y, h:i A', strtotime((string) $quotation['accepted_at']))); ?>.
                                <?php else: ?>
                                    successfully.
                                <?php endif; ?>
                            </p>
                        <?php elseif ($responseStatus === 'Rejected'): ?>
                            <h2 class="h5 text-danger mb-1">Quotation rejected</h2>
                            <p class="<?php echo empty($quotation['rejection_reason']) ? 'mb-0' : ''; ?> text-muted">
                                This quotation was rejected
                                <?php if (!empty($quotation['rejected_at'])): ?>
                                    on <?php echo $e(date('d M Y, h:i A', strtotime((string) $quotation['rejected_at']))); ?>.
                                <?php else: ?>
                                    successfully.
                                <?php endif; ?>
                            </p>
                            <?php if (!empty($quotation['rejection_reason'])): ?>
                                <div class="small"><strong>Reason:</strong> <?php echo nl2br($e($quotation['rejection_reason'])); ?></div>
                            <?php endif; ?>
                        <?php elseif ($responseStatus === 'Expired'): ?>
                            <h2 class="h5 text-warning mb-1">Quotation expired</h2>
                            <p class="mb-0 text-muted">
                                This quotation expired on <?php echo $validUntil !== '' ? $e(date('d M Y', strtotime($validUntil))) : 'its validity date'; ?>
                                and can no longer be accepted or rejected. Please contact us for a new quotation.
                            </p>
                        <?php else: ?>
                            <h2 class="h5 mb-1">Response already recorded</h2>
                            <p class="mb-0 text-muted">This quotation can no longer be changed through this link.</p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <p class="text-center text-muted small mt-4 mb-0">
            If you have any questions, please contact Pavitha Consultancy Services OPC Pvt Ltd.
        </p>
    </div>

    <script>
    (function () {
        'use strict';
        var requested = <?php echo json_encode($requestedResponse); ?>;
        if (requested === 'accept' || requested === 'reject') {
            var target = document.getElementById(requested);
            if (target) {
                target.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }

        var acceptForm = document.getElementById('acceptForm');
        if (acceptForm) {
            acceptForm.addEventListener('submit', function (event) {
                if (!window.confirm('Accept this quotation?')) {
                    event.preventDefault();
                }
            });
        }

        var rejectForm = document.getElementById('rejectForm');
        if (rejectForm) {
            rejectForm.addEventListener('submit', function (event) {
                if (!window.confirm('Please confirm that you want to reject this quotation. This action cannot be undone.')) {
                    event.preventDefault();
                }
            });
        }
    }());
    </script>
</body>
</html>
