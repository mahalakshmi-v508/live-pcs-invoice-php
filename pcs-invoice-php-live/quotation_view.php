<?php
require_once 'session.php';
ensureSessionStarted();
require_once 'Invoice.php';
require_once 'Quotation.php';

$invoice = new Invoice();
$invoice->checkLoggedIn();

$quotationService = new Quotation();
$quotationId = intval($_GET['id'] ?? 0);
$quotation = $quotationId > 0 ? $quotationService->getQuotation($quotationId) : [];
$items = $quotation ? $quotationService->getQuotationItems($quotationId) : [];
$showQuantityColumn = false;
foreach ($items as $item) {
    if ((float) ($item['quantity'] ?? 0) > 0) {
        $showQuantityColumn = true;
        break;
    }
}
$csrfToken = Quotation::csrfToken();

$pageError = $quotation ? '' : 'The quotation you are looking for could not be found.';

if ($pageError !== '') {
    include('header.php');
    ?>
    <title>Quotation Details</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/quotation.css?v=<?php echo filemtime(__DIR__ . '/css/quotation.css'); ?>">
    </head>

    <body class="quotation-body">
        <div class="quotation-page">
            <div class="card quotation-card">
                <div class="card-body">
                    <h2 class="quotation-title">Quotation Details</h2>
                    <div class="alert alert-danger mt-3"><?php echo Quotation::escape($pageError); ?></div>
                    <a href="main.php?page=quotation_list" class="btn btn-primary">
                        <i class="bi bi-arrow-left"></i> Back to Quotation List
                    </a>
                </div>
            </div>
        </div>
    <?php include('footer.php'); ?>
    <?php
    exit;
}

$currency = (string) ($quotation['currency'] ?? 'INR');
$currencySymbol = Quotation::currencySymbol($currency);
$status = (string) $quotation['status'];
$isConverted = !empty($quotation['is_converted']) || !empty($quotation['converted_invoice_id']);
$responseStatus = !empty($quotation['is_accepted'])
    ? 'Accepted'
    : (!empty($quotation['is_rejected'])
        ? 'Rejected'
        : (!empty($quotation['is_expired']) ? 'Expired' : ''));
$canEdit = !$isConverted;
$displayStatus = $isConverted ? 'Converted' : ($responseStatus !== '' ? $responseStatus : $status);

$whatsApp = Quotation::formatWhatsAppNumber($quotation['customer_phone'] ?? '');
$shareMessage = 'Hello ' . ($quotation['customer_name'] ?? '') . ",\n\n"
    . 'Please find our quotation ' . ($quotation['quotation_no'] ?? '') . ' dated '
    . Quotation::formatQuotationDateTime($quotation)
    . ' for ' . $currencySymbol . ' ' . number_format((float) $quotation['grand_total'], 2) . ".\n"
    . 'Valid until: ' . date('d M Y', strtotime((string) $quotation['valid_until'])) . ".\n\n"
    . "Thank you,\nPavitha Consultancy Services OPC Pvt Ltd.";
$whatsAppUrl = $whatsApp !== ''
    ? 'https://wa.me/' . rawurlencode($whatsApp) . '?text=' . rawurlencode($shareMessage)
    : '';
$emailUrl = !empty($quotation['customer_email'])
    ? 'mailto:' . $quotation['customer_email']
        . '?subject=' . rawurlencode('Quotation ' . $quotation['quotation_no'])
        . '&body=' . rawurlencode($shareMessage)
    : '';

$statusNotice = '';
if (isset($_GET['created'])) {
    $statusNotice = 'Quotation created successfully. You can print, share or download it below.';
} elseif (isset($_GET['draft_saved'])) {
    $statusNotice = 'Quotation saved as Draft successfully.';
} elseif (isset($_GET['updated'])) {
    $statusNotice = isset($_GET['sent'])
        ? 'Quotation updated and email sent successfully.'
        : 'Quotation updated successfully.';
} elseif (isset($_GET['converted'])) {
    $statusNotice = 'Quotation converted to an invoice successfully.';
} elseif (isset($_GET['resent'])) {
    $statusNotice = 'Quotation resent to the customer successfully.';
} elseif (isset($_GET['sent'])) {
    $statusNotice = 'Quotation email sent successfully.';
} elseif (isset($_GET['email_failed'])) {
    $statusNotice = 'Quotation saved as Draft, but the email could not be sent. You can try again from the Share option.';
} elseif (isset($_GET['notice']) && $_GET['notice'] === 'converted') {
    $statusNotice = 'Converted quotations cannot be edited.';
} elseif (isset($_GET['notice']) && $_GET['notice'] === 'read_only') {
    $statusNotice = 'This quotation cannot be edited.';
} elseif (isset($_GET['notice']) && $_GET['notice'] === 'convert_unavailable') {
    $statusNotice = 'This quotation cannot be converted. It must be sent, unexpired, and not rejected or already converted.';
}

include('header.php');
?>
<title>Quotation <?php echo Quotation::escape($quotation['quotation_no']); ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="css/quotation.css?v=<?php echo filemtime(__DIR__ . '/css/quotation.css'); ?>">
</head>

<body class="quotation-body">
    <div class="quotation-page">
        <div class="card quotation-card">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-start mb-3">
                    <div class="pr-3">
                        <div class="d-flex flex-wrap align-items-center">
                            <h2 class="quotation-title mr-2">Quotation Details</h2>
                            <span class="quotation-status <?php echo Quotation::escape('status-' . strtolower($displayStatus)); ?>">
                                <?php echo Quotation::escape($displayStatus); ?>
                            </span>
                        </div>
                        <p class="quotation-subtitle mb-0"><?php echo Quotation::escape($quotation['quotation_no']); ?></p>
                    </div>
                    <div class="mt-2 mt-sm-0">
                        <a href="main.php?page=quotation_list" class="btn btn-outline-secondary mb-2">
                            <i class="bi bi-arrow-left"></i> Back to List
                        </a>
                    </div>
                </div>

                <?php if ($statusNotice !== ''): ?>
                    <div class="alert alert-info" role="alert"><?php echo Quotation::escape($statusNotice); ?></div>
                <?php endif; ?>

                <div class="mb-3">
                    <a href="quotation_pdf.php?id=<?php echo $quotationId; ?>" target="_blank" rel="noopener"
                        class="btn btn-outline-dark mb-2">
                        <i class="bi bi-printer"></i> Print / Open PDF
                    </a>
                    <a href="quotation_pdf.php?id=<?php echo $quotationId; ?>&download=1"
                        class="btn btn-outline-dark mb-2">
                        <i class="bi bi-download"></i> Download PDF
                    </a>
                    <button type="button" class="btn btn-success mb-2" data-toggle="modal" data-target="#shareQuotationModal">
                        <i class="bi bi-share"></i> Share Quotation
                    </button>
                    <?php if ($canEdit): ?>
                        <a href="edit_quotation.php?update_id=<?php echo $quotationId; ?>" class="btn btn-outline-primary mb-2">
                            <i class="bi bi-pencil-square"></i> Edit Quotation
                        </a>
                    <?php endif; ?>
                </div>

                <div class="row mb-4">
                    <div class="col-lg-4 col-md-6 mb-3">
                        <div class="quotation-view-meta">
                            <div class="label">Quotation No.</div>
                            <div class="value"><?php echo Quotation::escape($quotation['quotation_no']); ?></div>
                            <div class="label">Quotation Date &amp; Time</div>
                            <div class="value"><?php echo Quotation::escape(Quotation::formatQuotationDateTime($quotation)); ?></div>
                            <div class="label">Valid Until</div>
                            <div class="value"><?php echo date('d M Y', strtotime((string) $quotation['valid_until'])); ?></div>
                            <div class="label">Status</div>
                            <div class="value<?php echo (empty($quotation['accepted_at']) && empty($quotation['rejected_at'])) ? ' mb-0' : ''; ?>"><?php echo Quotation::escape($displayStatus); ?></div>
                            <?php if ($responseStatus === 'Accepted' && !empty($quotation['accepted_at'])): ?>
                                <div class="label">Customer Response</div>
                                <div class="value">Accepted</div>
                                <div class="label">Accepted On</div>
                                <div class="value mb-0"><?php echo date('d M Y, h:i A', strtotime((string) $quotation['accepted_at'])); ?></div>
                            <?php elseif ($responseStatus === 'Rejected' && !empty($quotation['rejected_at'])): ?>
                                <div class="label">Customer Response</div>
                                <div class="value">Rejected</div>
                                <div class="label">Rejected On</div>
                                <div class="value<?php echo empty($quotation['rejection_reason']) ? ' mb-0' : ''; ?>"><?php echo date('d M Y, h:i A', strtotime((string) $quotation['rejected_at'])); ?></div>
                                <?php if (!empty($quotation['rejection_reason'])): ?>
                                    <div class="label">Rejection Reason</div>
                                    <div class="value mb-0"><?php echo nl2br(Quotation::escape($quotation['rejection_reason'])); ?></div>
                                <?php endif; ?>
                            <?php elseif ($responseStatus === 'Expired'): ?>
                                <div class="label">Validity</div>
                                <div class="value mb-0">Expired</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-lg-4 col-md-6 mb-3">
                        <div class="quotation-view-meta">
                            <div class="label">Customer</div>
                            <div class="value"><?php echo Quotation::escape($quotation['customer_name']); ?></div>
                            <div class="label">Phone / WhatsApp</div>
                            <div class="value"><?php echo $quotation['customer_phone'] !== '' ? Quotation::escape($quotation['customer_phone']) : '&mdash;'; ?></div>
                            <div class="label">Email</div>
                            <div class="value"><?php echo $quotation['customer_email'] !== '' ? Quotation::escape($quotation['customer_email']) : '&mdash;'; ?></div>
                            <div class="label">Address</div>
                            <div class="value mb-0">
                                <?php echo $quotation['customer_address'] !== '' ? nl2br(Quotation::escape($quotation['customer_address'])) : '&mdash;'; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4 col-md-12 mb-3">
                        <div class="quotation-view-meta h-100">
                            <div class="label">Grand Total</div>
                            <div class="value" style="font-size: 1.5rem; color: #1e3c72;">
                                <?php echo Quotation::escape($currencySymbol . ' ' . number_format((float) $quotation['grand_total'], 2)); ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="table-responsive mb-4">
                    <table class="quotation-view-table">
                        <thead>
                            <tr>
                                <th style="width: 45px;">#</th>
                                <th>Description</th>
                                <?php if ($showQuantityColumn): ?>
                                    <th style="width: 70px;">Qty</th>
                                <?php endif; ?>
                                <th style="width: 110px;">Unit Price</th>
                                <th style="width: 100px;">Discount</th>
                                <th style="width: 120px;">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($items)): ?>
                                <tr>
                                    <td colspan="<?php echo $showQuantityColumn ? 6 : 5; ?>" class="text-center quotation-muted">No items were saved for this quotation.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($items as $index => $item): ?>
                                    <tr>
                                        <td><?php echo $index + 1; ?></td>
                                        <td>
                                            <strong><?php echo Quotation::escape($item['product_name']); ?></strong>
                                        </td>
                                        <?php if ($showQuantityColumn): ?>
                                            <td><?php echo rtrim(rtrim(number_format((float) $item['quantity'], 2, '.', ''), '0'), '.'); ?></td>
                                        <?php endif; ?>
                                        <td><?php echo Quotation::escape($currencySymbol . ' ' . number_format((float) $item['unit_price'], 2)); ?></td>
                                        <td><?php echo Quotation::escape($currencySymbol . ' ' . number_format((float) $item['discount'], 2)); ?></td>
                                        <td><strong><?php echo Quotation::escape($currencySymbol . ' ' . number_format((float) $item['line_total'], 2)); ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="row mb-4">
                    <div class="col-lg-7 col-md-6 mb-3">
                        <div class="quotation-view-meta">
                            <div class="label">Terms &amp; Conditions</div>
                            <div class="value mb-0">
                                <?php echo trim((string) $quotation['terms_conditions']) !== '' ? nl2br(Quotation::escape($quotation['terms_conditions'])) : 'Thanks for doing business with us!'; ?>
                            </div>
                            <div class="label mt-3">Bank Details</div>
                            <div class="value mb-0">
                                <?php foreach (Quotation::bankDetails(
                                    (string) ($quotation['gst_type'] ?? 'with_gst'),
                                    (string) ($quotation['currency'] ?? 'INR')
                                ) as $bankLabel => $bankValue): ?>
                                    <div><strong><?php echo Quotation::escape($bankLabel); ?>:</strong>
                                        <?php echo Quotation::escape($bankValue); ?></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-5 col-md-6 mb-3">
                        <?php
                        $viewTaxAmount = round((float) $quotation['tax_amount'], 2);
                        $viewCgst = round($viewTaxAmount / 2, 2);
                        $viewSgst = round($viewTaxAmount - $viewCgst, 2);
                        ?>
                        <div class="quotation-totals">
                            <table>
                                <tr>
                                    <th>Subtotal</th>
                                    <td><?php echo Quotation::escape($currencySymbol . ' ' . number_format((float) $quotation['subtotal'], 2)); ?></td>
                                </tr>
                                <tr>
                                    <th>Total Discount</th>
                                    <td><?php echo Quotation::escape($currencySymbol . ' ' . number_format((float) $quotation['total_discount'], 2)); ?></td>
                                </tr>
                                <?php if ($viewTaxAmount > 0): ?>
                                    <tr>
                                        <th>CGST (9%)</th>
                                        <td><?php echo Quotation::escape($currencySymbol . ' ' . number_format($viewCgst, 2)); ?></td>
                                    </tr>
                                    <tr>
                                        <th>SGST (9%)</th>
                                        <td><?php echo Quotation::escape($currencySymbol . ' ' . number_format($viewSgst, 2)); ?></td>
                                    </tr>
                                <?php endif; ?>
                                <tr class="grand-total">
                                    <th>Grand Total</th>
                                    <td><?php echo Quotation::escape($currencySymbol . ' ' . number_format((float) $quotation['grand_total'], 2)); ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-lg-7 mb-3">
                        <div class="quotation-view-meta h-100">
                            <div class="label">Quotation Status</div>
                            <?php if ($isConverted): ?>
                                <div class="value mb-0">
                                    This quotation has been converted to an invoice.
                                    <?php if (!empty($quotation['converted_invoice_id'])): ?>
                                        <a href="edit_invoice.php?update_id=<?php echo intval($quotation['converted_invoice_id']); ?>">
                                            View invoice #<?php echo intval($quotation['converted_invoice_id']); ?>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="value mb-0"><?php echo Quotation::escape($displayStatus); ?></div>
                                <?php if (!empty($quotation['is_rejected']) || !empty($quotation['is_expired'])): ?>
                                    <button type="button" class="btn btn-primary mt-3" id="resendQuotation">
                                        <i class="bi bi-send"></i> Resend Quotation
                                    </button>
                                    <div id="resendQuotationAlert" class="small mt-2"></div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-lg-5 mb-3">
                        <div class="quotation-view-meta h-100">
                            <div class="label">Convert to Invoice</div>
                            <?php if ($isConverted): ?>
                                <p class="mb-2">Converted successfully.</p>
                                <?php if (!empty($quotation['converted_invoice_id'])): ?>
                                    <a href="print_invoice.php?invoice_id=<?php echo intval($quotation['converted_invoice_id']); ?>"
                                        class="btn btn-outline-primary" target="_blank" rel="noopener">
                                        <i class="bi bi-file-earmark-pdf"></i> Open Invoice
                                    </a>
                                <?php endif; ?>
                            <?php elseif (empty($quotation['is_expired'])
                                && ($responseStatus === 'Accepted'
                                    || ($status === 'Sent' && empty($quotation['is_rejected'])))): ?>
                                <p class="mb-2">This sent quotation is ready to become an invoice.</p>
                                <a href="create_invoice.php?from_quotation=<?php echo intval($quotationId); ?>"
                                    class="btn btn-success" id="convertQuotation">
                                    <i class="bi bi-arrow-right-circle"></i> Convert to Invoice
                                </a>
                                <div class="small quotation-muted mt-2">You can convert this quotation to an invoice when you are ready. Customer and item details will be filled in, and the quotation becomes Converted after you save the invoice.</div>
                            <?php else: ?>
                                <p class="mb-0 quotation-muted">Send this quotation first; a valid sent quotation can then be converted to an invoice.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="shareQuotationModal" tabindex="-1" role="dialog" aria-labelledby="shareQuotationModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="shareQuotationModalLabel">Share Quotation</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <p class="mb-3"><strong><?php echo Quotation::escape($quotation['quotation_no']); ?></strong><br>
                        <span class="small quotation-muted"><?php echo Quotation::escape($quotation['customer_name']); ?></span>
                    </p>
                    <div class="mb-3">
                        <?php if ($whatsAppUrl !== ''): ?>
                            <a href="<?php echo Quotation::escape($whatsAppUrl); ?>" target="_blank" rel="noopener"
                                class="btn btn-success btn-block mb-2">
                                <i class="bi bi-whatsapp"></i> Share on WhatsApp
                            </a>
                        <?php else: ?>
                            <button type="button" class="btn btn-success btn-block mb-2" disabled>
                                <i class="bi bi-whatsapp"></i> WhatsApp (no saved phone)
                            </button>
                        <?php endif; ?>

                        <?php if ($emailUrl !== ''): ?>
                            <a href="<?php echo Quotation::escape($emailUrl); ?>" class="btn btn-outline-primary btn-block mb-2">
                                <i class="bi bi-envelope"></i> Email Quotation Details
                            </a>
                        <?php else: ?>
                            <button type="button" class="btn btn-outline-primary btn-block mb-2" disabled>
                                <i class="bi bi-envelope"></i> Email (no saved email)
                            </button>
                        <?php endif; ?>

                        <input type="email" id="shareQuotationEmailToView" class="form-control mb-2"
                            placeholder="Customer email address"
                            value="<?php echo Quotation::escape((string) ($quotation['customer_email'] ?? '')); ?>">
                        <button type="button" class="btn btn-primary btn-block mb-2" id="sendQuotationEmailView">
                            <i class="bi bi-send"></i> Send Email with Accept / Reject Links
                        </button>
                        <div id="sendQuotationEmailAlertView" class="small mb-2"></div>

                        <a href="quotation_pdf.php?id=<?php echo $quotationId; ?>&download=1"
                            class="btn btn-outline-dark btn-block mb-2">
                            <i class="bi bi-download"></i> Download PDF
                        </a>
                        <a href="quotation_pdf.php?id=<?php echo $quotationId; ?>" target="_blank" rel="noopener"
                            class="btn btn-outline-secondary btn-block mb-2">
                            <i class="bi bi-printer"></i> Print / Open PDF
                        </a>
                        <button type="button" class="btn btn-outline-success btn-block" id="copyQuotationMessageView">
                            <i class="bi bi-clipboard"></i> Copy Share Message
                        </button>
                    </div>

                    <label class="font-weight-bold small" for="shareQuotationMessageView">Message Preview</label>
                    <textarea id="shareQuotationMessageView" class="form-control" rows="6" readonly><?php echo Quotation::escape($shareMessage); ?></textarea>
                    <div id="copyQuotationAlertView" class="small mt-2"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script>
    (function () {
        'use strict';

        var quotationId = <?php echo $quotationId; ?>;
        var csrfToken = <?php echo json_encode($csrfToken); ?>;

        function alertBox(id, message, type) {
            var box = document.getElementById(id);
            if (!box) {
                return;
            }
            box.className = 'small ' + (type === 'error' ? 'text-danger' : 'text-success');
            box.textContent = message;
        }

        var copyButton = document.getElementById('copyQuotationMessageView');
        if (copyButton) {
            copyButton.addEventListener('click', function () {
                var textarea = document.getElementById('shareQuotationMessageView');
                var done = function () {
                    alertBox('copyQuotationAlertView', 'Share message copied to the clipboard.', 'success');
                };
                var fallback = function () {
                    textarea.focus();
                    textarea.select();
                    try {
                        document.execCommand('copy');
                        done();
                    } catch (error) {
                        alertBox('copyQuotationAlertView', 'Could not copy automatically. Please select and copy the message.', 'error');
                    }
                };

                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(textarea.value).then(done).catch(fallback);
                } else {
                    fallback();
                }
            });
        }

        var sendEmailButton = document.getElementById('sendQuotationEmailView');
        if (sendEmailButton) {
            sendEmailButton.addEventListener('click', function () {
                var emailInput = document.getElementById('shareQuotationEmailToView');
                var alertBox = document.getElementById('sendQuotationEmailAlertView');
                var toEmail = emailInput ? emailInput.value.trim() : '';
                if (!toEmail || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(toEmail)) {
                    if (alertBox) {
                        alertBox.className = 'small mb-2 text-danger';
                        alertBox.textContent = 'Please enter a valid customer email address.';
                    }
                    return;
                }

                sendEmailButton.disabled = true;
                sendEmailButton.innerHTML = '<i class="bi bi-send"></i> Sending...';
                if (alertBox) {
                    alertBox.className = 'small mb-2';
                    alertBox.textContent = '';
                }

                $.ajax({
                    url: 'action.php',
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'send_quotation_email',
                        quotation_id: quotationId,
                        to_email: toEmail,
                        csrf_token: csrfToken
                    }
                }).done(function (response) {
                    if (response && Number(response.status) === 1) {
                        if (alertBox) {
                            alertBox.className = 'small mb-2 text-success';
                            alertBox.textContent = response.message || 'Quotation email sent successfully.';
                        }
                        window.setTimeout(function () {
                            window.location.href = 'quotation_view.php?id=' + encodeURIComponent(quotationId) + '&sent=1';
                        }, 700);
                    } else {
                        if (alertBox) {
                            alertBox.className = 'small mb-2 text-danger';
                            alertBox.textContent = (response && response.message) || 'Unable to send quotation email.';
                        }
                        sendEmailButton.disabled = false;
                        sendEmailButton.innerHTML = '<i class="bi bi-send"></i> Send Email with Accept / Reject Links';
                    }
                }).fail(function (xhr) {
                    if (alertBox) {
                        alertBox.className = 'small mb-2 text-danger';
                        alertBox.textContent = (xhr.responseJSON && xhr.responseJSON.message) ||
                            'Unable to send quotation email.';
                    }
                    sendEmailButton.disabled = false;
                    sendEmailButton.innerHTML = '<i class="bi bi-send"></i> Send Email with Accept / Reject Links';
                });
            });
        }

        var resendButton = document.getElementById('resendQuotation');
        if (resendButton) {
            resendButton.addEventListener('click', function () {
                var box = document.getElementById('resendQuotationAlert');
                var setMessage = function (message, type) {
                    if (box) {
                        box.className = 'small mt-2 ' + (type === 'error' ? 'text-danger' : 'text-success');
                        box.textContent = message;
                    }
                };

                if (!window.confirm('Resend this quotation email to the customer?')) {
                    return;
                }

                resendButton.disabled = true;
                resendButton.innerHTML = '<i class="bi bi-send"></i> Resending...';
                setMessage('', 'success');

                $.ajax({
                    url: 'action.php',
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'resend_quotation',
                        id: quotationId,
                        csrf_token: csrfToken
                    }
                }).done(function (response) {
                    if (response && Number(response.status) === 1) {
                        setMessage(response.message || 'Quotation resent successfully.', 'success');
                        window.setTimeout(function () {
                            window.location.href = 'quotation_view.php?id=' + encodeURIComponent(quotationId) + '&resent=1';
                        }, 700);
                    } else {
                        setMessage((response && response.message) || 'Unable to resend the quotation.', 'error');
                        resendButton.disabled = false;
                        resendButton.innerHTML = '<i class="bi bi-send"></i> Resend Quotation';
                    }
                }).fail(function (xhr) {
                    setMessage((xhr.responseJSON && xhr.responseJSON.message) ||
                        'Unable to resend the quotation.', 'error');
                    resendButton.disabled = false;
                    resendButton.innerHTML = '<i class="bi bi-send"></i> Resend Quotation';
                });
            });
        }

        var convertButton = document.getElementById('convertQuotation');
        if (convertButton) {
            convertButton.addEventListener('click', function (event) {
                if (!window.confirm('Open the invoice form with this quotation?')) {
                    event.preventDefault();
                }
            });
        }
    }());
    </script>

    <?php include('footer.php'); ?>
