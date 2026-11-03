<?php
require_once 'session.php';
ensureSessionStarted();
require_once 'Invoice.php';
require_once 'Quotation.php';
$invoice = new Invoice();
$invoice->checkLoggedIn();

$quotationService = new Quotation();
$sourceQuotationId = intval($_GET['from_quotation'] ?? $_POST['source_quotation_id'] ?? 0);
$sourceQuotation = [];
$sourceItems = [];

if ($sourceQuotationId > 0) {
    $sourceQuotation = $quotationService->getQuotation($sourceQuotationId);
    if (empty($sourceQuotation)
        || (empty($sourceQuotation['is_accepted']) && $sourceQuotation['status'] !== 'Sent')
        || !empty($sourceQuotation['is_rejected'])
        || !empty($sourceQuotation['is_expired'])
        || !empty($sourceQuotation['converted_invoice_id'])) {
        header('Location: quotation_view.php?id=' . $sourceQuotationId . '&notice=convert_unavailable');
        exit;
    }
    $sourceItems = $quotationService->getQuotationItems($sourceQuotationId);
}

if (!empty($_POST['receiverName']) && $_POST['receiverName']) {
    $invoiceId = $invoice->saveInvoice($_POST);
    $submittedQuotationId = intval($_POST['source_quotation_id'] ?? 0);
    if ($submittedQuotationId > 0 && $invoiceId > 0) {
        // The quotation becomes Converted only after the invoice is saved.
        $quotationService->markConverted($submittedQuotationId, $invoiceId);
    }
    header("Location:main.php?page=invoice_list");
    exit;
}

$prefill = [
    'receiverName' => '',
    'receiverAddress' => '',
    'gstNo' => '',
    'panNo' => '',
    'supply' => '',
    'currency' => 'INR',
    'gst_type' => 'with_gst',
    'notes' => '',
];
$prefillItems = [];

if (!empty($sourceQuotation)) {
    $existingCustomerDetails = $quotationService->getLatestInvoiceCustomerDetails(
        (string) ($sourceQuotation['customer_name'] ?? '')
    );
    $prefill = [
        'receiverName' => (string) ($sourceQuotation['customer_name'] ?? ''),
        'receiverAddress' => (string) (($sourceQuotation['customer_address'] ?? '') !== ''
            ? $sourceQuotation['customer_address'] : ($existingCustomerDetails['receiver_address'] ?? '')),
        'gstNo' => (string) ($existingCustomerDetails['gst_no'] ?? ''),
        'panNo' => (string) ($existingCustomerDetails['pan_no'] ?? ''),
        'supply' => (string) ($existingCustomerDetails['supply'] ?? ''),
        'currency' => in_array(($sourceQuotation['currency'] ?? 'INR'), ['INR', 'USD'], true)
            ? (string) $sourceQuotation['currency'] : 'INR',
        'gst_type' => ($sourceQuotation['gst_type'] ?? '') === 'with_gst' ? 'with_gst' : 'without_gst',
        'notes' => (string) ($sourceQuotation['notes'] ?? ''),
    ];

    foreach ($sourceItems as $sourceItem) {
        $name = trim((string) ($sourceItem['product_name'] ?? ''));
        $description = trim((string) ($sourceItem['product_description'] ?? ''));
        if ($description !== '') {
            $name .= ' - ' . $description;
        }
        // The invoice form has no quantity/discount columns and treats Price as
        // the billed line amount, so carry the quotation line total (after any
        // discount) into both Price and Taxable Value.
        $lineTotal = (float) ($sourceItem['line_total'] ?? 0);
        $prefillItems[] = [
            'name' => mb_substr($name, 0, 150),
            'code' => '998314',
            'price' => $lineTotal,
            'taxable' => $lineTotal,
            'month' => '',
        ];
    }
}

if (empty($prefillItems)) {
    $prefillItems[] = ['name' => '', 'code' => '998314', 'price' => '', 'taxable' => '', 'month' => ''];
}

$e = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

include('header.php');
?>
<title>Create Invoice</title>
<script src="js/invoice.js?v=<?php echo @filemtime(__DIR__ . '/js/invoice.js') ?: time(); ?>"></script>
<!-- <link href="css/style.css" rel="stylesheet"> -->

<style>
.custom-pill-toggle .btn-check:checked + .btn {
    background: linear-gradient(135deg, #0f1e45 0%, #1e3c72 100%);
    color: white;
    border-color: transparent;
    box-shadow: 0 4px 15px rgba(30, 60, 114, 0.4);
    transform: translateY(-1px);
}
.custom-pill-toggle .btn {
    border-radius: 50px !important;
    padding: 0.5rem 1.5rem;
    font-weight: 600;
    letter-spacing: 0.5px;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    border: 2px solid transparent;
    color: #6c757d;
    background-color: transparent;
    margin: 0 2px;
    min-height: 48px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.custom-pill-toggle .btn-check:not(:checked) + .btn:hover {
    color: #1e3c72;
    background-color: #e9ecef;
}
.custom-pill-toggle input[type="radio"],
.custom-pill-toggle .btn-check {
    display: none !important;
    opacity: 0 !important;
    position: absolute !important;
    z-index: -1 !important;
    pointer-events: none !important;
}
.custom-pill-toggle {
    background-color: #f1f3f5;
    padding: 4px;
    border-radius: 50px;
    display: inline-flex;
    align-items: center;
    box-shadow: inset 0 2px 5px rgba(0,0,0,0.05);
    min-height: 56px;
}
.invoice-control-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 10px 12px;
    height: auto;
}
.invoice-control-label {
    font-size: 0.85rem;
    letter-spacing: 0.2px;
    color: #475569;
    line-height: 1.2;
    margin-bottom: 0.45rem !important;
}
.invoice-controls-row .form-group {
    margin-bottom: 0;
}
@media (max-width: 767.98px) {
    .invoice-control-card {
        margin-bottom: 0.5rem;
    }
    .custom-pill-toggle {
        width: 100%;
        justify-content: center;
    }
}
/* Invoice items table: header only (matches dashboard #1f4e79 accent) */
#invoiceItem thead th {
    background-color: #1f4e79;
    color: #ffffff;
}
/* + Add More only — dashboard accent */
#addRows.btn-primary {
    background-color: #1f4e79;
    border-color: #1f4e79;
    color: #ffffff;
}
#addRows.btn-primary:hover,
#addRows.btn-primary:focus,
#addRows.btn-primary:active {
    background-color: #1f4e79;
    border-color: #1f4e79;
    color: #ffffff;
}
</style>

<body
    style="font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: linear-gradient(135deg, #0f1e45 0%, #1e3c72 100%); margin: 0; padding: 0;">
    <main class="container py-4">
        <div class="card shadow rounded-3">
            <div class="card-body">
                <form action="" id="invoice-form" method="post" class="invoice-form" role="form" novalidate>
                    <div class="load-animate animated fadeInUp">
                        <div class="row mb-4">
                            <div class="col-12">
                                <h2 style="color:#1f4e79;">Create Invoice</h2>
                                <?php if (!empty($sourceQuotation)): ?>
                                    <div class="alert alert-info py-2 px-3 mb-0">
                                        Creating an invoice from quotation
                                        <strong><?php echo $e($sourceQuotation['quotation_no'] ?? ''); ?></strong>.
                                        The quotation will be marked as converted only after you save this invoice.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="row mb-4">
                            <div class="col-md-6">
                                <h5 class="text-secondary">From</h5>
                                <p class="mb-1"><?php echo $_SESSION['user']; ?></p>
                                <p class="mb-1"><?php echo $_SESSION['address']; ?></p>
                                <p class="mb-1"><?php echo $_SESSION['mobile']; ?></p>
                                <p class="mb-1"><?php echo $_SESSION['email']; ?></p>
                            </div>
                            <div class="col-md-6">
                                <h5 class="text-secondary">To</h5>
                                <div class="form-group mb-3 customer-autocomplete-wrapper">
                                    <input type="text" class="form-control" name="receiverName" id="receiverName" placeholder="Name"
                                        value="<?php echo $e($prefill['receiverName']); ?>" autocomplete="off">
                                </div>
                                <div class="form-group mb-3">
                                    <textarea class="form-control" rows="2" name="receiverAddress"
                                        placeholder="Address"><?php echo $e($prefill['receiverAddress']); ?></textarea>
                                </div>
                                <div class="form-group mb-3">
                                    <input type="text" class="form-control" name="gstNo" placeholder="GST No."
                                        value="<?php echo $e($prefill['gstNo']); ?>" autocomplete="off">
                                </div>
                                <div class="form-group mb-3">
                                    <input type="text" class="form-control" name="panNo" placeholder="PAN No."
                                        value="<?php echo $e($prefill['panNo']); ?>" autocomplete="off">
                                </div>
                                <div class="form-group mb-3">
                                    <input type="text" class="form-control" name="supply" placeholder="Place of Supply"
                                        value="<?php echo $e($prefill['supply']); ?>" autocomplete="off">
                                </div>
                            </div>
                        </div>

                        <div class="row g-3 align-items-start mb-3 invoice-controls-row">
                            <div class="col-12 col-lg-4">
                                <div class="form-group invoice-control-card h-100">
                                    <label for="invoiceDate" class="form-label d-block fw-bold mb-2 invoice-control-label">Invoice Date</label>
                                    <input type="date" name="invoiceDate" id="invoiceDate" class="form-control" required
                                        value="<?php echo htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off">
                                </div>
                            </div>
                            <div class="col-12 col-lg-4">
                                <div class="form-group invoice-control-card">
                                    <label class="form-label d-block fw-bold mb-2 invoice-control-label">Currency Type :</label>
                                    <div class="custom-pill-toggle" role="group" aria-label="Currency">
                                        <input type="radio" class="btn-check" name="currency" id="currency_inr" value="INR" <?php echo $prefill['currency'] === 'INR' ? 'checked' : ''; ?> autocomplete="off">
                                        <label class="btn" for="currency_inr">₹ Rupees</label>
                                        <input type="radio" class="btn-check" name="currency" id="currency_usd" value="USD" <?php echo $prefill['currency'] === 'USD' ? 'checked' : ''; ?> autocomplete="off">
                                        <label class="btn" for="currency_usd">$ Dollars</label>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12 col-lg-4">
                                <div class="form-group invoice-control-card" id="gst-toggle-wrapper">
                                    <label class="form-label d-block fw-bold mb-2 invoice-control-label">Invoice Type :</label>
                                    <div class="custom-pill-toggle" role="group" aria-label="GST Type">
                                        <input type="radio" class="btn-check" name="gst_type" id="with_gst" value="with_gst" <?php echo $prefill['gst_type'] === 'with_gst' ? 'checked' : ''; ?> autocomplete="off">
                                        <label class="btn" for="with_gst">With GST</label>

                                        <input type="radio" class="btn-check" name="gst_type" id="without_gst" value="without_gst" <?php echo $prefill['gst_type'] === 'without_gst' ? 'checked' : ''; ?> autocomplete="off">
                                        <label class="btn" for="without_gst">Without GST</label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <hr>

                        <div class="table-responsive mb-4">
                            <table class="table table-bordered table-hover" id="invoiceItem">
                                <thead>
                                    <tr>
                                        <th width="2%" class="text-center align-middle">
                                            <div class="form-check m-0">
                                                <input type="checkbox" class="form-check-input" id="checkAll">
                                            </div>
                                        </th>
                                        <th class="align-middle">Name of the Product / Service</th>
                                        <th class="align-middle">HSN / SAC</th>
                                        <th class="align-middle">Price</th>
                                        <th class="align-middle">Taxable Value</th>
                                        <th class="align-middle" style="min-width: 140px;">Month</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($prefillItems as $index => $prefillItem):
                                        $rowNumber = $index + 1;
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="custom-control custom-checkbox">
                                                    <input type="checkbox" class="form-check-input itemRow"
                                                        id="itemRow_<?php echo $rowNumber; ?>">
                                                </div>
                                            </td>
                                            <td><input type="text" name="productName[]" id="productName_<?php echo $rowNumber; ?>"
                                                    class="form-control" value="<?php echo $e($prefillItem['name']); ?>"
                                                    autocomplete="off"></td>
                                            <td><input type="text" name="productCode[]" id="productCode_<?php echo $rowNumber; ?>"
                                                    class="form-control" value="<?php echo $e($prefillItem['code']); ?>"
                                                    autocomplete="off"></td>
                                            <td><input type="number" name="price[]" id="price_<?php echo $rowNumber; ?>"
                                                    class="form-control price" min="0" step="any"
                                                    value="<?php echo $prefillItem['price'] === '' ? '' : $e($prefillItem['price']); ?>"
                                                    autocomplete="off"></td>
                                            <td><input type="number" name="taxableValue[]"
                                                    id="taxableValue_<?php echo $rowNumber; ?>"
                                                    class="form-control taxableValue" min="0" step="any"
                                                    value="<?php echo $prefillItem['taxable'] === '' ? '' : $e($prefillItem['taxable']); ?>"
                                                    autocomplete="off"></td>
                                            <td>
                                                <select name="itemMonth[]" id="itemMonth_<?php echo $rowNumber; ?>" class="form-select form-select-sm" autocomplete="off">
                                                    <option value="">— Select —</option>
                                                    <?php foreach ($invoice->getInvoiceItemMonthChoices() as $monthChoice): ?>
                                                    <option value="<?php echo htmlspecialchars($monthChoice, ENT_QUOTES, 'UTF-8'); ?>"
                                                        <?php echo $prefillItem['month'] === $monthChoice ? 'selected' : ''; ?>><?php echo htmlspecialchars($monthChoice, ENT_QUOTES, 'UTF-8'); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <div class="d-flex gap-2">
                                <button class="btn btn-danger" id="removeRows" type="button">- Delete</button>&nbsp;
                                <button class="btn btn-primary" id="addRows" type="button">+ Add More</button>
                            </div>
                        </div>

                        <hr>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label>Rate:</label>
                                <div class="input-group">
                                    <span class="input-group-text currency-symbol">₹</span>
                                    <input type="number" class="form-control" name="rate" id="rate" readonly>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label>Total:</label>
                                <div class="input-group">
                                    <span class="input-group-text currency-symbol">₹</span>
                                    <input type="number" class="form-control" name="total" id="total" readonly>
                                </div>
                            </div>
                        </div>

                        <div id="gst-fields-wrapper" class="row">
                            <div class="col-md-3 mb-3 gst-only-fields">
                                <label for="cgstTax">CGST (%)</label>
                                <div class="input-group">
                                    <span class="input-group-text">%</span>
                                    <input type="number" class="form-control" name="cgstTax" id="cgstTax" value="9" readonly>
                                </div>
                            </div>

                            <div class="col-md-3 mb-3 gst-only-fields">
                                <label>CGST Amount:</label>
                                <div class="input-group">
                                    <span class="input-group-text currency-symbol">₹</span>
                                    <input type="number" class="form-control" name="cgstAmount" id="cgstAmount" readonly>
                                </div>
                            </div>

                            <div class="col-md-3 mb-3 gst-only-fields">
                                <label for="sgstTax">SGST (%)</label>
                                <div class="input-group">
                                    <span class="input-group-text">%</span>
                                    <input type="number" class="form-control" name="sgstTax" id="sgstTax" value="9" readonly>
                                </div>
                            </div>

                            <div class="col-md-3 mb-3 gst-only-fields">
                                <label>SGST Amount:</label>
                                <div class="input-group">
                                    <span class="input-group-text currency-symbol">₹</span>
                                    <input type="number" class="form-control" name="sgstAmount" id="sgstAmount" readonly>
                                </div>
                            </div>
                        </div>

                        <hr>

                        <div class="form-group mb-3">
                            <label>Notes:</label>
                            <textarea class="form-control" rows="3" name="notes" id="notes"
                                placeholder="Additional Notes"><?php echo $e($prefill['notes']); ?></textarea>
                        </div>

                        <div class="form-group text-end">
                            <input type="hidden" value="<?php echo $_SESSION['userId']; ?>" name="userId">
                            <?php if (!empty($sourceQuotation)): ?>
                                <input type="hidden" name="source_quotation_id"
                                    value="<?php echo intval($sourceQuotationId); ?>">
                            <?php endif; ?>
                            <button type="submit" name="invoice_btn" class="btn btn-success px-3">Save</button>
                            <button type="button" style="background-color: #f05050; color: white;"
                                class="btn btn-close px-3"
                                onclick="window.location.href='main.php?page=invoice_list'">Close</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </main>
</body>

<?php include('footer.php'); ?>