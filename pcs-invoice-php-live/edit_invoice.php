<?php
require_once 'session.php';
ensureSessionStarted();
include('header.php');
require_once('Invoice.php');
$invoice = new Invoice();
$invoice->checkLoggedIn();

$invoiceValues = [];
$invoiceItems = [];
if (!empty($_GET['update_id']) && $_GET['update_id']) {
    $invoiceValues = $invoice->getInvoice($_GET['update_id']);
    $invoiceItems = $invoice->getInvoiceItems($_GET['update_id']);
}
$editInvoiceDateValue = date('Y-m-d');
if (!empty($invoiceValues['invoice_date'])) {
    $ts = strtotime((string) $invoiceValues['invoice_date']);
    if ($ts !== false) {
        $editInvoiceDateValue = date('Y-m-d', $ts);
    }
} elseif (!empty($invoiceValues['created_at'])) {
    $ts = strtotime((string) $invoiceValues['created_at']);
    if ($ts !== false) {
        $editInvoiceDateValue = date('Y-m-d', $ts);
    }
}
?>

<head>
    <title>Edit Invoice</title>
    <script src="js/invoice.js?v=<?php echo @filemtime(__DIR__ . '/js/invoice.js') ?: time(); ?>"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"
        integrity="sha512-..." crossorigin="anonymous" referrerpolicy="no-referrer" />
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
        padding: 0.5rem 1.75rem;
        font-weight: 600;
        letter-spacing: 0.5px;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        border: 2px solid transparent;
        color: #6c757d;
        background-color: transparent;
        margin: 0 2px;
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
        box-shadow: inset 0 2px 5px rgba(0,0,0,0.05);
    }
    .form-select.shadow-sm {
        border: 1px solid #ced4da;
        padding: 0.5rem 1rem;
        height: calc(2.25rem + 12px); /* matching toggle height roughly */
    }
    </style>
</head>

<body
    style="font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: linear-gradient(135deg, #0f1e45 0%, #1e3c72 100%); margin: 0; padding: 0;">
    <div class="container content-invoice py-4">
        <div class="card shadow rounded-3">
            <div class="card-body">
                <form action="" id="invoice-form" method="post" class="invoice-form" role="form" novalidate>
                    <div class="load-animate animated fadeInUp">
                        <div class="row mb-4">
                            <div class="col-12">
                                <h2 class="text-primary">Edit Invoice</h2>
                            </div>
                        </div>

                        <div class="row mb-4">
                            <div class="col-md-6">
                                <h5 class="text-secondary">From</h5>
                                <p class="mb-1"><?php echo $_SESSION['user']; ?></p>
                                <p class="mb-1"><?php echo $_SESSION['address']; ?></p>
                                <p class="mb-1"><?php echo $_SESSION['mobile']; ?></p>
                                <p class="mb-1"><?php echo $_SESSION['email']; ?></p>
                                <div class="mb-1" style="padding-top: 182px;">
                                    <label for="invoice_number" class="form-label">Invoice No</label>
                                    <input type="text" class="form-control col-md-4" name="invoiceNumber"
                                        value="<?php echo htmlspecialchars($invoice->getDisplayInvoiceNumber($invoiceValues), ENT_QUOTES, 'UTF-8'); ?>" readonly>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <h5 class="text-secondary">To</h5>
                                <div class="form-group mb-3 customer-autocomplete-wrapper">
                                    <input value="<?php echo $invoiceValues['receiver_name'] ?? ''; ?>" type="text"
                                        class="form-control" name="receiverName" id="receiverName" placeholder="Name" autocomplete="off">
                                </div>
                                <div class="form-group mb-3">
                                    <textarea class="form-control" rows="2" name="receiverAddress"
                                        placeholder="Address"><?php echo $invoiceValues['receiver_address'] ?? ''; ?></textarea>
                                </div>
                                <div class="form-group mb-3">
                                    <input value="<?php echo $invoiceValues['gst_no'] ?? ''; ?>" type="text"
                                        class="form-control" name="gstNo" placeholder="GST No." autocomplete="off">
                                </div>
                                <div class="form-group mb-3">
                                    <input value="<?php echo $invoiceValues['pan_no'] ?? ''; ?>" type="text"
                                        class="form-control" name="panNo" placeholder="PAN No." autocomplete="off">
                                </div>
                                <div class="form-group mb-3">
                                    <input value="<?php echo $invoiceValues['supply'] ?? ''; ?>" type="text"
                                        class="form-control" name="supply" placeholder="Place of Supply"
                                        autocomplete="off">
                                </div>
                                <div class="form-group mb-3">
                                    <label for="invoiceDate" class="form-label fw-bold text-secondary mb-2">Invoice Date</label>
                                    <input type="date" name="invoiceDate" id="invoiceDate" class="form-control shadow-sm"
                                        style="border-radius: 8px;" required autocomplete="off"
                                        value="<?php echo htmlspecialchars($editInvoiceDateValue, ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                                <div class="form-group mb-3">
                                    <label for="currency" class="form-label fw-bold text-secondary mb-2">Currency Type :</label>
                                    <select name="currency" id="currency" class="form-select shadow-sm" style="border-radius: 8px;" required>
                                        <option value="INR" <?php echo strtoupper($invoiceValues['currency'] ?? '') === 'INR' ? 'selected' : ''; ?>>₹ Rupees</option>
                                        <option value="USD" <?php echo strtoupper($invoiceValues['currency'] ?? '') === 'USD' ? 'selected' : ''; ?>>$ Dollars</option>
                                    </select>
                                </div>

                                <div class="form-group mb-3" id="gst-toggle-wrapper">
                                    <label class="form-label d-block fw-bold text-secondary mb-2">Invoice Type :</label>
                                    <div class="custom-pill-toggle" role="group" aria-label="GST Type">
                                        <input type="radio" class="btn-check" name="gst_type" id="with_gst" value="with_gst" <?php echo ($invoiceValues['gst_type'] ?? 'with_gst') === 'with_gst' ? 'checked' : ''; ?> autocomplete="off">
                                        <label class="btn" for="with_gst">With GST</label>

                                        <input type="radio" class="btn-check" name="gst_type" id="without_gst" value="without_gst" <?php echo ($invoiceValues['gst_type'] ?? '') === 'without_gst' ? 'checked' : ''; ?> autocomplete="off">
                                        <label class="btn" for="without_gst">Without GST</label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <hr>

                        <div class="table-responsive mb-4">
                            <table class="table table-bordered table-hover" id="invoiceItem">
                                <thead class="thead-light">
                                    <tr>
                                        <th width="2%" class="text-center align-middle">
                                            <div class="form-check m-0">
                                                <input type="checkbox" class="form-check-input" id="checkAll">
                                            </div>
                                        </th>
                                        <th>Name of the Product / Service</th>
                                        <th>HSN / SAC</th>
                                        <th>Price</th>
                                        <th>Taxable Value</th>
                                        <th style="min-width: 140px;">Month</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $count = 0;
                                    $monthChoices = $invoice->getInvoiceItemMonthChoices();
                                    if (!empty($invoiceItems) && is_array($invoiceItems)):
                                        foreach ($invoiceItems as $invoiceItem): $count++;
                                            $savedMonth = isset($invoiceItem['item_month']) ? (string) $invoiceItem['item_month'] : '';
                                            ?>
                                    <tr>
                                        <td>
                                            <div class="custom-control custom-checkbox">
                                                <input type="checkbox" class="form-check-input itemRow"
                                                    id="itemRow_<?php echo $count; ?>">
                                            </div>
                                        </td>
                                        <td><input type="text" name="productName[]"
                                                id="productName_<?php echo $count; ?>"
                                                value="<?php echo htmlspecialchars($invoiceItem['name'], ENT_QUOTES, 'UTF-8'); ?>" class="form-control"></td>
                                        <td><input type="text" name="productCode[]"
                                                id="productCode_<?php echo $count; ?>"
                                                value="<?php echo htmlspecialchars($invoiceItem['hsn_sac'], ENT_QUOTES, 'UTF-8'); ?>" class="form-control">
                                        </td>
                                        <td><input type="number" name="price[]" id="price_<?php echo $count; ?>"
                                                value="<?php echo htmlspecialchars((string) $invoiceItem['price'], ENT_QUOTES, 'UTF-8'); ?>" class="form-control price" min="0" step="any">
                                        </td>
                                        <td>
                                            <input type="number" name="taxableValue[]"
                                                id="taxableValue_<?php echo $count; ?>"
                                                value="<?php echo htmlspecialchars((string) $invoiceItem['taxable_value'], ENT_QUOTES, 'UTF-8'); ?>"
                                                class="form-control taxableValue" min="0" step="any">
                                            <input type="hidden" name="itemId[]"
                                                value="<?php echo (int) $invoiceItem['id']; ?>">
                                        </td>
                                        <td>
                                            <select name="itemMonth[]" id="itemMonth_<?php echo $count; ?>" class="form-select form-select-sm" autocomplete="off">
                                                <option value="">— Select —</option>
                                                <?php foreach ($monthChoices as $monthChoice):
                                                    $sel = ($savedMonth === $monthChoice) ? ' selected' : '';
                                                    ?>
                                                <option value="<?php echo htmlspecialchars($monthChoice, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $sel; ?>><?php echo htmlspecialchars($monthChoice, ENT_QUOTES, 'UTF-8'); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                    </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                            <div class="d-flex gap-2">
                                <button class="btn btn-danger" id="removeRows" type="button">- Delete</button>&nbsp;
                                <button class="btn btn-primary" id="addRows" type="button">+ Add More</button>
                            </div>
                        </div>

                        <hr>

                        <div class="row mt-4">
                            <div class="col-md-6 mb-3">
                                <label>Rate:</label>
                                <div class="input-group">
                                    <span class="input-group-text currency-symbol"><?php echo strtoupper($invoiceValues['currency'] ?? '') === 'USD' ? '$' : '₹'; ?></span>
                                    <input type="number" name="rate" id="rate" class="form-control"
                                        value="<?php echo $invoiceValues['rate'] ?? ''; ?>" readonly>
                                </div>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label>Total:</label>
                                <div class="input-group">
                                    <span class="input-group-text currency-symbol"><?php echo strtoupper($invoiceValues['currency'] ?? '') === 'USD' ? '$' : '₹'; ?></span>
                                    <input type="number" name="total" id="total" class="form-control"
                                        value="<?php echo $invoiceValues['total'] ?? ''; ?>" readonly>
                                </div>
                            </div>
                        </div>

                        <div id="gst-fields-wrapper" class="row">
                            <div class="col-md-3 mb-3 gst-only-fields">
                                <label>CGST (%)</label>
                                <div class="input-group">
                                    <span class="input-group-text">%</span>
                                    <input type="number" name="cgstTax" id="cgstTax" class="form-control" readonly
                                        value="<?php echo $invoiceValues['cgst_tax'] ?? ''; ?>">
                                </div>
                            </div>

                            <div class="col-md-3 mb-3 gst-only-fields">
                                <label>CGST Amount:</label>
                                <div class="input-group">
                                    <span class="input-group-text currency-symbol">₹</span>
                                    <input type="number" name="cgstAmount" id="cgstAmount" class="form-control" readonly
                                        value="<?php echo $invoiceValues['cgst_amount'] ?? ''; ?>">
                                </div>
                            </div>

                            <div class="col-md-3 mb-3 gst-only-fields">
                                <label>SGST (%)</label>
                                <div class="input-group">
                                    <span class="input-group-text">%</span>
                                    <input type="number" name="sgstTax" id="sgstTax" class="form-control" readonly
                                        value="<?php echo $invoiceValues['sgst_tax'] ?? ''; ?>">
                                </div>
                            </div>

                            <div class="col-md-3 mb-3 gst-only-fields">
                                <label>SGST Amount:</label>
                                <div class="input-group">
                                    <span class="input-group-text currency-symbol">₹</span>
                                    <input type="number" name="sgstAmount" id="sgstAmount" class="form-control" readonly
                                        value="<?php echo $invoiceValues['sgst_amount'] ?? ''; ?>">
                                </div>
                            </div>
                        </div>

                        <hr>

                        <div class="form-group mb-3">
                            <label>Notes:</label>
                            <textarea class="form-control" rows="3" name="notes" id="notes"
                                placeholder="Additional Notes"><?php echo $invoiceValues['note'] ?? ''; ?></textarea>
                        </div>

                        <div class="form-group text-end">
                            <input type="hidden" value="<?php echo $_SESSION['userId']; ?>" name="userId">
                            <input type="hidden" value="<?php echo $invoiceValues['id'] ?? ''; ?>" name="invoiceId"
                                id="invoiceId">
                            <button type="submit" name="invoice_btn" class="btn btn-success px-3">Save</button>
                            <button type="button" style="background-color: #f05050; color: white;"
                                class="btn btn-close px-3" onclick="window.location.href='main.php?page=invoice_list'">
                                Close</button>
                            <?php if (isset($_POST['invoice_btn'])) {
                                if ($invoice->updateInvoice($_POST)) {
                                    echo "<script>window.location.href = 'main.php?page=invoice_list';</script>";
                                    exit;
                                } else {
                                    $dbErrorMessage = trim((string) $invoice->getLastDbError());
                                    if ($dbErrorMessage === '') {
                                        $dbErrorMessage = 'Failed to update invoice. Please try again.';
                                    }
                                    echo "<div class='alert alert-danger'>" . htmlspecialchars($dbErrorMessage, ENT_QUOTES, 'UTF-8') . "</div>";
                                }
                            }
                            ?>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

</body>
<?php include('footer.php'); ?>