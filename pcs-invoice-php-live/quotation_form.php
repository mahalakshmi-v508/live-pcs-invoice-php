<?php
/**
 * Shared create/edit quotation form. Expects:
 * $quotation, $formItems, $formErrors, $csrfToken,
 * $formMode ('create'|'edit'), $actionUrl, optional $cancelUrl.
 */
if (!defined('QUOTATION_FORM_INCLUDED')) {
    define('QUOTATION_FORM_INCLUDED', true);
}

$formMode = $formMode ?? 'create';
$isEdit = $formMode === 'edit';
$formErrors = isset($formErrors) && is_array($formErrors) ? $formErrors : [];
$csrfToken = $csrfToken ?? '';
$actionUrl = $actionUrl ?? ($isEdit ? 'edit_quotation.php' : 'create_quotation.php');
$cancelUrl = $cancelUrl ?? 'main.php?page=quotation_list';

$currentStatus = (string) ($quotation['status'] ?? 'Draft');

$quotation = array_merge([
    'quotation_no' => '',
    'quotation_date' => date('Y-m-d'),
    'valid_until' => date('Y-m-d', strtotime('+15 days')),
    'customer_name' => '',
    'customer_phone' => '',
    'customer_email' => '',
    'customer_address' => '',
    'currency' => 'INR',
    'gst_type' => 'with_gst',
    'status' => 'Draft',
    'terms_conditions' => '',
], is_array($quotation ?? null) ? $quotation : []);

if (trim((string) ($quotation['terms_conditions'] ?? '')) === '') {
    $quotation['terms_conditions'] = 'Thanks for doing business with us!';
}

$displayStatus = !empty($quotation['is_converted'])
    ? 'Converted'
    : (!empty($quotation['is_accepted'])
        ? 'Accepted'
        : (!empty($quotation['is_rejected'])
            ? 'Rejected'
            : (!empty($quotation['is_expired']) ? 'Expired' : $currentStatus)));

if (empty($formItems) || !is_array($formItems)) {
    $formItems = [[]];
}

$e = static function ($value): string {
    return Quotation::escape($value);
};

$totals = ['subtotal' => 0.0, 'discount' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0, 'tax' => 0.0, 'grand' => 0.0];
$formItems = array_values($formItems);
$formCurrency = strtoupper((string) ($quotation['currency'] ?? 'INR')) === 'USD' ? 'USD' : 'INR';
$currencySymbol = Quotation::currencySymbol($formCurrency);
$formGstType = (($quotation['gst_type'] ?? 'with_gst') === 'without_gst') ? 'without_gst' : 'with_gst';
if ($formCurrency !== 'INR') {
    $formGstType = 'without_gst';
}
$itemTaxRate = $formGstType === 'with_gst' ? 18 : 0;
$customerSuggestions = isset($customerSuggestions) && is_array($customerSuggestions)
    ? $customerSuggestions : [];
?>
<div class="quotation-page">
    <div class="card quotation-card">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-start mb-3">
                <div class="pr-3">
                    <h2 class="quotation-title">
                        <?php echo $isEdit ? 'Edit Quotation' : 'Create Quotation'; ?>
                    </h2>
                    <p class="quotation-subtitle mb-0">
                        Pavitha Consultancy Services OPC Pvt Ltd. &middot; GSTIN: 33AALCP8510A1ZS
                    </p>
                </div>
                <a href="main.php?page=quotation_list" class="btn btn-outline-secondary mt-2 mt-sm-0">
                    <i class="bi bi-arrow-left"></i> Back to List
                </a>
            </div>

            <div id="quotationFormErrors" class="alert alert-danger <?php echo empty($formErrors) ? 'd-none' : ''; ?>" role="alert">
                <?php if (!empty($formErrors)): ?>
                    <ul class="mb-0">
                        <?php foreach ($formErrors as $error): ?>
                            <li><?php echo $e($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <form id="quotationForm" method="post" action="<?php echo $e($actionUrl); ?>" novalidate
                data-currency-symbol="<?php echo $e($currencySymbol); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo $e($csrfToken); ?>">
                <?php if ($isEdit): ?>
                    <input type="hidden" name="quotation_id" value="<?php echo intval($quotation['id'] ?? 0); ?>">
                <?php endif; ?>

                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label font-weight-bold" for="quotation_no">Quotation No.</label>
                        <input type="text" class="form-control" id="quotation_no" name="quotation_no"
                            value="<?php echo $e($quotation['quotation_no']); ?>" readonly>
                        <small class="form-text text-muted">Generated automatically.</small>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label font-weight-bold" for="quotation_date">Quotation Date *</label>
                        <input type="date" class="form-control" id="quotation_date" name="quotation_date"
                            value="<?php echo $e($quotation['quotation_date']); ?>" required>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label font-weight-bold" for="valid_until">Valid Until *</label>
                        <input type="date" class="form-control" id="valid_until" name="valid_until"
                            value="<?php echo $e($quotation['valid_until']); ?>" required>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-3 mb-3">
                        <label class="form-label font-weight-bold" for="customer_name">Customer Name *</label>
                        <div class="quotation-customer-picker">
                            <input type="text" class="form-control" id="customer_name" name="customer_name"
                                maxlength="150" value="<?php echo $e($quotation['customer_name']); ?>"
                                placeholder="Select or type a customer"
                                list="quotationCustomerSuggestions" autocomplete="off" required>
                            <div class="quotation-customer-options" id="quotationCustomerOptions" hidden></div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label font-weight-bold" for="customer_phone">Phone / WhatsApp <span class="text-muted font-weight-normal">(Optional)</span></label>
                        <input type="text" class="form-control" id="customer_phone" name="customer_phone"
                            maxlength="20" value="<?php echo $e($quotation['customer_phone']); ?>"
                            placeholder="+91 98765 43210">
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label font-weight-bold" for="customer_email">Email</label>
                        <input type="email" class="form-control" id="customer_email" name="customer_email"
                            maxlength="150" value="<?php echo $e($quotation['customer_email']); ?>"
                            placeholder="customer@example.com">
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label font-weight-bold" for="customer_address">Address</label>
                        <textarea class="form-control" id="customer_address" name="customer_address" rows="2"
                            maxlength="1000" placeholder="Billing address"><?php echo $e($quotation['customer_address']); ?></textarea>
                    </div>
                </div>

                <?php if ($isEdit): ?>
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label font-weight-bold" for="status_display">Status</label>
                            <input type="text" class="form-control" id="status_display"
                                value="<?php echo $e($displayStatus); ?>" readonly>
                            <small class="form-text text-muted">Status updates automatically.</small>
                        </div>
                    </div>
                <?php endif; ?>

                <datalist id="quotationCustomerSuggestions">
                    <?php foreach ($customerSuggestions as $suggestedCustomer): ?>
                        <option value="<?php echo $e($suggestedCustomer['customer_name'] ?? ''); ?>"
                            data-phone="<?php echo $e($suggestedCustomer['customer_phone'] ?? ''); ?>"
                            data-email="<?php echo $e($suggestedCustomer['customer_email'] ?? ''); ?>"
                            data-address="<?php echo $e($suggestedCustomer['customer_address'] ?? ''); ?>"></option>
                    <?php endforeach; ?>
                </datalist>

                <div class="row">
                    <div class="col-12 col-md-6 mb-3">
                        <div class="quotation-control-card">
                            <label class="form-label font-weight-bold d-block mb-2">Currency Type :</label>
                            <div class="custom-pill-toggle" role="group" aria-label="Currency">
                                <input type="radio" class="btn-check" name="currency" id="quotation_currency_inr"
                                    value="INR" <?php echo $formCurrency === 'INR' ? 'checked' : ''; ?> autocomplete="off">
                                <label class="btn" for="quotation_currency_inr">₹ Rupees</label>
                                <input type="radio" class="btn-check" name="currency" id="quotation_currency_usd"
                                    value="USD" <?php echo $formCurrency === 'USD' ? 'checked' : ''; ?> autocomplete="off">
                                <label class="btn" for="quotation_currency_usd">$ Dollars</label>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 mb-3">
                        <div class="quotation-control-card" id="quotation-gst-toggle-wrapper"
                            <?php echo $formCurrency === 'USD' ? 'style="display:none"' : ''; ?>>
                            <label class="form-label font-weight-bold d-block mb-2">Quotation Type :</label>
                            <div class="custom-pill-toggle" role="group" aria-label="GST Type">
                                <input type="radio" class="btn-check" name="gst_type" id="quotation_with_gst"
                                    value="with_gst" <?php echo $formGstType === 'with_gst' ? 'checked' : ''; ?>
                                    autocomplete="off">
                                <label class="btn" for="quotation_with_gst">With GST</label>
                                <input type="radio" class="btn-check" name="gst_type" id="quotation_without_gst"
                                    value="without_gst" <?php echo $formGstType === 'without_gst' ? 'checked' : ''; ?>
                                    autocomplete="off">
                                <label class="btn" for="quotation_without_gst">Without GST</label>
                            </div>
                        </div>
                    </div>
                </div>

                <hr>

                <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
                    <div>
                        <h4 class="h5 font-weight-bold text-dark mb-1">Products / Services</h4>
                        <p class="text-muted mb-0 small">Add each product or service on its own row.</p>
                    </div>
                </div>

                <div class="table-responsive mb-4">
                    <table class="table table-bordered quotation-items-table">
                        <thead>
                            <tr>
                                <th style="width: 45px;" class="text-center align-middle">
                                    <div class="form-check m-0">
                                        <input type="checkbox" class="form-check-input" id="checkAll">
                                    </div>
                                </th>
                                <th style="min-width: 220px;">Description *</th>
                                <th style="width: 85px;">Qty *</th>
                                <th style="width: 120px;">Unit Price *</th>
                                <th style="width: 110px;">Discount</th>
                                <th style="width: 85px;">Tax %</th>
                                <th style="width: 125px;">Total</th>
                            </tr>
                        </thead>
                        <tbody id="quotationItemsBody">
                            <?php foreach ($formItems as $index => $item):
                                $item = array_merge([
                                    'product_name' => '',
                                    'quantity' => 0,
                                    'unit_price' => 0,
                                    'discount' => 0,
                                ], is_array($item) ? $item : []);

                                $quantity = is_numeric($item['quantity']) ? (float) $item['quantity'] : 0.0;
                                $quantityInputValue = $quantity > 0 ? $e($item['quantity']) : '';
                                $unitPrice = is_numeric($item['unit_price']) ? (float) $item['unit_price'] : 0.0;
                                $discount = is_numeric($item['discount']) ? (float) $item['discount'] : 0.0;
                                $priceInputValue = $unitPrice > 0 ? $e($item['unit_price']) : '';
                                $discountInputValue = $discount > 0 ? $e($item['discount']) : '';

                                $gross = round(($quantity > 0 ? $quantity : 1) * $unitPrice, 2);
                                $appliedDiscount = min(max($discount, 0), $gross);
                                $lineTotal = round(max(0, $gross - $appliedDiscount), 2);

                                $totals['subtotal'] += $gross;
                                $totals['discount'] += $appliedDiscount;
                            ?>
                                <tr class="item-row">
                                    <td class="text-center align-middle">
                                        <div class="form-check m-0">
                                            <input type="checkbox" class="form-check-input itemRow"
                                                id="quotationItemRow_<?php echo $index + 1; ?>">
                                        </div>
                                    </td>
                                    <td>
                                        <input type="text" class="form-control item-name" name="product_name[]"
                                            data-field="product_name" maxlength="255"
                                            value="<?php echo $e($item['product_name']); ?>"
                                            placeholder="Item description" required>
                                    </td>
                                    <td>
                                        <input type="number" class="form-control item-qty" name="quantity[]"
                                            data-field="quantity" min="0" step="0.01"
                                            value="<?php echo $quantityInputValue; ?>" placeholder="0">
                                    </td>
                                    <td>
                                        <input type="number" class="form-control item-price" name="unit_price[]"
                                            data-field="unit_price" min="0" step="0.01"
                                            value="<?php echo $priceInputValue; ?>" placeholder="0">
                                    </td>
                                    <td>
                                        <input type="number" class="form-control item-discount" name="discount[]"
                                            data-field="discount" min="0" step="0.01"
                                            value="<?php echo $discountInputValue; ?>" placeholder="0">
                                    </td>
                                    <td>
                                        <input type="number" class="form-control item-tax" name="tax_percent[]"
                                            data-field="tax_percent" value="<?php echo $itemTaxRate; ?>" readonly>
                                    </td>
                                    <td class="text-right line-total"><?php echo $e($currencySymbol . ' ' . number_format($lineTotal, 2)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="d-flex mb-4">
                    <button type="button" class="btn btn-danger" id="removeRows">- Delete</button>&nbsp;
                    <button type="button" class="btn btn-primary" id="addRows">+ Add More</button>
                </div>

                <template id="quotationItemTemplate">
                    <tr class="item-row">
                        <td class="text-center align-middle">
                            <div class="form-check m-0">
                                <input type="checkbox" class="form-check-input itemRow" id="quotationItemRow_1">
                            </div>
                        </td>
                        <td>
                            <input type="text" class="form-control item-name" name="product_name[]"
                                data-field="product_name" maxlength="255" placeholder="Item description" required>
                        </td>
                        <td>
                            <input type="number" class="form-control item-qty" name="quantity[]"
                                data-field="quantity" min="0" step="0.01" placeholder="0">
                        </td>
                        <td>
                            <input type="number" class="form-control item-price" name="unit_price[]"
                                data-field="unit_price" min="0" step="0.01" placeholder="0">
                        </td>
                        <td>
                            <input type="number" class="form-control item-discount" name="discount[]"
                                data-field="discount" min="0" step="0.01" placeholder="0">
                        </td>
                        <td>
                            <input type="number" class="form-control item-tax" name="tax_percent[]"
                                data-field="tax_percent" value="<?php echo $itemTaxRate; ?>" readonly>
                        </td>
                        <td class="text-right line-total"><?php echo $e($currencySymbol . ' 0.00'); ?></td>
                    </tr>
                </template>

                <?php
                $totals['subtotal'] = round($totals['subtotal'], 2);
                $totals['discount'] = round($totals['discount'], 2);
                $taxableTotal = round($totals['subtotal'] - $totals['discount'], 2);
                $totals['cgst'] = $formGstType === 'with_gst' ? round($taxableTotal * 0.09, 2) : 0.0;
                $totals['sgst'] = $formGstType === 'with_gst' ? round($taxableTotal * 0.09, 2) : 0.0;
                $totals['tax'] = round($totals['cgst'] + $totals['sgst'], 2);
                $totals['grand'] = round($taxableTotal + $totals['tax'], 2);
                $gstRowStyle = $formGstType === 'with_gst' ? '' : ' style="display:none;"';
                ?>
                <div class="row justify-content-end mb-4">
                    <div class="col-lg-5 col-md-7">
                        <div class="quotation-totals">
                            <table>
                                <tr>
                                    <th>Subtotal</th>
                                    <td id="summarySubtotal"><?php echo $e($currencySymbol . ' ' . number_format($totals['subtotal'], 2)); ?></td>
                                </tr>
                                <tr>
                                    <th>Total Discount</th>
                                    <td id="summaryDiscount"><?php echo $e($currencySymbol . ' ' . number_format($totals['discount'], 2)); ?></td>
                                </tr>
                                <tr id="summaryCgstRow"<?php echo $gstRowStyle; ?>>
                                    <th>CGST (9%)</th>
                                    <td id="summaryCgst"><?php echo $e($currencySymbol . ' ' . number_format($totals['cgst'], 2)); ?></td>
                                </tr>
                                <tr id="summarySgstRow"<?php echo $gstRowStyle; ?>>
                                    <th>SGST (9%)</th>
                                    <td id="summarySgst"><?php echo $e($currencySymbol . ' ' . number_format($totals['sgst'], 2)); ?></td>
                                </tr>
                                <tr class="grand-total">
                                    <th>Grand Total</th>
                                    <td id="summaryGrandTotal"><?php echo $e($currencySymbol . ' ' . number_format($totals['grand'], 2)); ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label font-weight-bold">Bank Details</label>
                        <div class="border rounded bg-light p-3" id="quotationBankDetails"
                            data-inr-with-gst="<?php echo $e(json_encode(Quotation::bankDetails('with_gst', 'INR'))); ?>"
                            data-inr-without-gst="<?php echo $e(json_encode(Quotation::bankDetails('without_gst', 'INR'))); ?>"
                            data-usd="<?php echo $e(json_encode(Quotation::bankDetails('without_gst', 'USD'))); ?>">
                            <?php foreach (Quotation::bankDetails($formGstType, $formCurrency) as $bankLabel => $bankValue): ?>
                                <div class="small mb-1">
                                    <strong><?php echo $e($bankLabel); ?>:</strong>
                                    <?php echo $e($bankValue); ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label font-weight-bold" for="terms_conditions">Terms &amp; Conditions</label>
                        <textarea class="form-control" id="terms_conditions" name="terms_conditions" rows="4"
                            maxlength="5000"
                            placeholder="Payment terms, delivery timeline, validity, etc."><?php echo $e($quotation['terms_conditions']); ?></textarea>
                    </div>
                </div>

                <div class="d-flex flex-wrap justify-content-end pt-2">
                    <a href="<?php echo $e($cancelUrl); ?>" class="btn btn-outline-secondary mr-2 mb-2 mb-sm-0">Cancel</a>
                    <?php if ($isEdit): ?>
                        <button type="submit" name="quotation_action" value="save"
                            class="btn btn-success px-4 mr-2 mb-2 mb-sm-0">
                            <i class="bi bi-check2-circle"></i>
                            Update Quotation
                        </button>
                        <?php
                        $canSendFromForm = (($quotation['status'] ?? '') === 'Draft'
                                || (($quotation['status'] ?? '') === 'Sent' && empty($quotation['is_accepted']))
                                || !empty($quotation['is_rejected'])
                                || !empty($quotation['is_expired']))
                            && empty($quotation['is_accepted']);
                        ?>
                        <?php if ($canSendFromForm): ?>
                            <button type="submit" name="quotation_action" value="save_and_resend"
                                class="btn btn-primary px-4">
                                <i class="bi bi-send"></i>
                                <?php echo (($quotation['status'] ?? '') === 'Draft' && empty($quotation['is_rejected']) && empty($quotation['is_expired']))
                                    ? 'Save &amp; Send Quotation'
                                    : 'Save &amp; Resend Quotation'; ?>
                            </button>
                        <?php endif; ?>
                    <?php else: ?>
                        <button type="submit" name="quotation_action" value="save_draft"
                            class="btn btn-outline-secondary mr-2 mb-2 mb-sm-0">
                            <i class="bi bi-save"></i> Save Draft
                        </button>
                        <button type="submit" name="quotation_action" value="send_quotation"
                            class="btn btn-success px-4">
                            <i class="bi bi-send"></i> Send Quotation
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="js/quotation.js?v=<?php echo filemtime(__DIR__ . '/js/quotation.js'); ?>"></script>
