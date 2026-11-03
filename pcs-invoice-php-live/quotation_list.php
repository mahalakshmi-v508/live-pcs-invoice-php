<?php
require_once 'session.php';
ensureSessionStarted();
require_once 'Quotation.php';

if (empty($_SESSION['userId'])) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['ajax'])) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['error' => 'Your session has expired. Please log in again.']);
        exit;
    }
    header('Location: index.php');
    exit;
}

if (!function_exists('pcs_quotation_h')) {
    function pcs_quotation_h($value): string
    {
        return Quotation::escape($value);
    }

    function pcs_quotation_date($date): string
    {
        if (empty($date)) {
            return '&mdash;';
        }
        $timestamp = strtotime((string) $date);
        return $timestamp ? date('d M Y', $timestamp) : '&mdash;';
    }

    function pcs_quotation_money($amount, $currency = 'INR'): string
    {
        return Quotation::currencySymbol((string) $currency) . ' ' . number_format((float) $amount, 2);
    }

    function pcs_quotation_page_numbers(int $currentPage, int $totalPages): array
    {
        if ($totalPages <= 1) {
            return [];
        }
        if ($totalPages <= 7) {
            return range(1, $totalPages);
        }

        $pages = [1];
        if ($currentPage > 4) {
            $pages[] = '...';
        }
        for ($page = max(2, $currentPage - 2); $page <= min($totalPages - 1, $currentPage + 2); $page++) {
            $pages[] = $page;
        }
        if ($currentPage < $totalPages - 3) {
            $pages[] = '...';
        }
        $pages[] = $totalPages;

        return array_values(array_unique($pages));
    }

    function pcs_quotation_summary(array $data): string
    {
        $total = intval($data['total'] ?? 0);
        if ($total === 0) {
            return 'No quotations found.';
        }
        $perPage = max(1, intval($data['perPage'] ?? 5));
        $currentPage = max(1, intval($data['currentPage'] ?? 1));
        $first = (($currentPage - 1) * $perPage) + 1;
        $last = min($total, $first + $perPage - 1);
        return 'Showing ' . $first . '&ndash;' . $last . ' of ' . $total . ' quotation' . ($total === 1 ? '' : 's');
    }
}

$quotationService = new Quotation();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $filters = isset($_POST['filters']) && is_array($_POST['filters']) ? $_POST['filters'] : [];
        $page = max(1, intval($_POST['page'] ?? 1));
        $result = $quotationService->getQuotationList($filters, $page, 5);

        foreach ($result['quotations'] as &$quotationRow) {
            // The public response token is a credential and is not needed by
            // the management list UI.
            unset($quotationRow['public_token']);
            $quotationRow['whatsapp'] = Quotation::formatWhatsAppNumber($quotationRow['customer_phone'] ?? '');
        }
        unset($quotationRow);

        $result['summary'] = html_entity_decode(pcs_quotation_summary($result), ENT_QUOTES, 'UTF-8');
        echo json_encode($result);
    } catch (Throwable $exception) {
        http_response_code(500);
        echo json_encode(['error' => $exception->getMessage()]);
    }
    exit;
}

$initialData = $quotationService->getQuotationList([], 1, 5);
$initialQuotations = $initialData['quotations'];
$initialCurrentPage = intval($initialData['currentPage']);
$initialTotalPages = intval($initialData['totalPages']);
$csrfToken = Quotation::csrfToken();
?>

<div class="quotation-page">
    <div class="card quotation-card">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-start mb-3">
                <div class="pe-3">
                    <h2 class="quotation-title">Quotation Management</h2>
                    <p class="quotation-subtitle mb-0">Create, track and share customer quotations.</p>
                </div>
                <a href="create_quotation.php" class="btn btn-primary mt-2 mt-sm-0">
                    <i class="bi bi-plus-lg"></i> Create Quotation
                </a>
            </div>

            <?php if (isset($_GET['sent']) && $_GET['sent'] === '1'): ?>
                <div class="alert alert-success py-2 px-3 mb-3">
                    <i class="bi bi-check-circle"></i> Quotation sent to the customer successfully.
                </div>
            <?php elseif (isset($_GET['resent']) && $_GET['resent'] === '1'): ?>
                <div class="alert alert-success py-2 px-3 mb-3">
                    <i class="bi bi-check-circle"></i> Quotation updated and resent to the customer successfully.
                </div>
            <?php elseif (isset($_GET['updated']) && $_GET['updated'] === '1'): ?>
                <div class="alert alert-success py-2 px-3 mb-3">
                    <i class="bi bi-check-circle"></i> Quotation updated successfully.
                </div>
            <?php elseif (isset($_GET['draft_saved']) && $_GET['draft_saved'] === '1'): ?>
                <div class="alert alert-success py-2 px-3 mb-3">
                    <i class="bi bi-check-circle"></i> Quotation saved as Draft successfully.
                </div>
            <?php endif; ?>

            <div class="quotation-toolbar mb-3">
                <div class="row g-2 align-items-end">
                    <div class="col-lg-2 col-md-3 col-6">
                        <label class="form-label fw-semibold" for="quotationMonth">Month</label>
                        <div class="quotation-month-filter">
                            <button type="button" id="quotationMonth" class="form-select quotation-month-toggle"
                                value="" aria-haspopup="listbox" aria-expanded="false" aria-controls="quotationMonthOptions">
                                All Months
                            </button>
                            <div id="quotationMonthOptions" class="quotation-month-options" role="listbox"
                                aria-label="Filter by month" hidden>
                                <button type="button" class="quotation-month-option" role="option"
                                    data-value="" aria-selected="true">All Months</button>
                                <?php
                                $monthNames = [
                                    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
                                    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
                                    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
                                ];
                                foreach ($monthNames as $monthNumber => $monthName): ?>
                                    <button type="button" class="quotation-month-option" role="option"
                                        data-value="<?php echo $monthNumber; ?>" aria-selected="false"><?php echo pcs_quotation_h($monthName); ?></button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-2 col-md-3 col-6">
                        <label class="form-label fw-semibold" for="quotationDateFilter">Exact Date</label>
                        <input type="date" id="quotationDateFilter" class="form-control">
                    </div>
                    <div class="col-lg-2 col-md-3 col-6">
                        <label class="form-label fw-semibold" for="quotationCurrencyFilter">Currency</label>
                        <select id="quotationCurrencyFilter" class="form-select">
                            <option value="">All Currencies</option>
                            <option value="INR">Rupees (INR)</option>
                            <option value="USD">Dollars (USD)</option>
                        </select>
                    </div>
                    <div class="col-lg-3 col-md-6 col-12" id="quotationGstFilterContainer" style="display: none;">
                        <label class="form-label fw-semibold d-block">GST Type</label>
                        <div class="gst-toggle" role="group" aria-label="GST Type">
                            <input type="radio" class="btn-check" name="quotationGstFilter" id="quotationWithGstFilter"
                                value="with_gst" autocomplete="off">
                            <label class="btn" for="quotationWithGstFilter">With GST</label>
                            <input type="radio" class="btn-check" name="quotationGstFilter" id="quotationWithoutGstFilter"
                                value="without_gst" autocomplete="off">
                            <label class="btn" for="quotationWithoutGstFilter">Without GST</label>
                        </div>
                    </div>
                    <div class="col-lg-2 col-md-6 col-6">
                        <label class="form-label fw-semibold" for="quotationStatusFilter">Status</label>
                        <select id="quotationStatusFilter" class="form-select">
                            <option value="">All Statuses</option>
                            <?php foreach (Quotation::listStatuses() as $status): ?>
                                <option value="<?php echo pcs_quotation_h($status); ?>"><?php echo pcs_quotation_h($status); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-1 col-md-6 col-6">
                        <button type="button" id="clearQuotationFilters" class="btn btn-sm btn-outline-secondary w-100"
                            title="Clear quotation filters" aria-label="Clear quotation filters">
                            <i class="bi bi-x-circle"></i> Clear
                        </button>
                    </div>
                </div>
            </div>

            <div id="quotationTableContainer">
                <div class="table-responsive quotation-table-wrap">
                    <table class="quotation-table">
                        <thead>
                            <tr>
                                <th>Quotation No.</th>
                                <th>Customer Name</th>
                                <th>Quotation Date</th>
                                <th>Valid Until</th>
                                <th>Total Amount</th>
                                <th>Status</th>
                                <th>View</th>
                                <th>Print</th>
                                <th>Convert</th>
                                <th>Edit</th>
                                <th>Delete</th>
                            </tr>
                        </thead>
                        <tbody id="quotationTableBody">
                            <?php if (empty($initialQuotations)): ?>
                                <tr>
                                    <td colspan="11" class="quotation-empty">No quotations available yet. Create your first quotation.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($initialQuotations as $quotationRow):
                                    $quotationId = intval($quotationRow['id']);
                                    $status = (string) $quotationRow['status'];
                                    $isConverted = !empty($quotationRow['is_converted'])
                                        || !empty($quotationRow['converted_invoice_id']);
                                    $canEdit = !$isConverted;
                                    $whatsApp = Quotation::formatWhatsAppNumber($quotationRow['customer_phone'] ?? '');
                                ?>
                                    <tr id="quotationRow_<?php echo $quotationId; ?>"
                                        data-id="<?php echo $quotationId; ?>"
                                        data-quotation-no="<?php echo pcs_quotation_h($quotationRow['quotation_no']); ?>"
                                        data-customer-name="<?php echo pcs_quotation_h($quotationRow['customer_name']); ?>"
                                        data-phone="<?php echo pcs_quotation_h($quotationRow['customer_phone']); ?>"
                                        data-whatsapp="<?php echo pcs_quotation_h($whatsApp); ?>"
                                        data-email="<?php echo pcs_quotation_h($quotationRow['customer_email']); ?>"
                                        data-total="<?php echo pcs_quotation_h($quotationRow['grand_total']); ?>"
                                        data-currency="<?php echo pcs_quotation_h($quotationRow['currency'] ?? 'INR'); ?>"
                                        data-quotation-date="<?php echo pcs_quotation_h($quotationRow['quotation_date']); ?>"
                                        data-valid-until="<?php echo pcs_quotation_h($quotationRow['valid_until']); ?>">
                                        <td class="fw-semibold">
                                            <a href="quotation_view.php?id=<?php echo $quotationId; ?>">
                                                <?php echo pcs_quotation_h($quotationRow['quotation_no']); ?>
                                            </a>
                                        </td>
                                        <td><?php echo pcs_quotation_h($quotationRow['customer_name']); ?></td>
                                        <td><?php echo pcs_quotation_h(Quotation::formatQuotationDateTime($quotationRow)); ?></td>
                                        <td><?php echo pcs_quotation_date($quotationRow['valid_until'] ?? ''); ?></td>
                                        <td><?php echo pcs_quotation_money($quotationRow['grand_total'] ?? 0, $quotationRow['currency'] ?? 'INR'); ?></td>
                                        <td>
                                            <span class="quotation-status <?php echo pcs_quotation_h('status-' . strtolower($status)); ?>">
                                                <?php echo pcs_quotation_h($status); ?>
                                            </span>
                                        </td>
                                        <td class="quotation-actions">
                                            <a href="quotation_view.php?id=<?php echo $quotationId; ?>" title="View quotation">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                        </td>
                                        <td class="quotation-actions">
                                            <a href="quotation_pdf.php?id=<?php echo $quotationId; ?>" target="_blank"
                                                rel="noopener" title="Print quotation">
                                                <i class="bi bi-printer"></i>
                                            </a>
                                        </td>
                                        <td class="quotation-actions">
                                            <?php if ($isConverted && !empty($quotationRow['converted_invoice_id'])): ?>
                                                <a href="print_invoice.php?invoice_id=<?php echo intval($quotationRow['converted_invoice_id']); ?>"
                                                    title="Open converted invoice">
                                                    <i class="bi bi-receipt"></i>
                                                </a>
                                            <?php elseif ($status === 'Sent'
                                                && empty($quotationRow['is_rejected'])
                                                && empty($quotationRow['is_expired'])): ?>
                                                <a href="create_invoice.php?from_quotation=<?php echo $quotationId; ?>"
                                                    title="Convert quotation to invoice">
                                                    <i class="bi bi-arrow-repeat"></i>
                                                </a>
                                            <?php else: ?>
                                                <span class="disabled" title="Only sent, unexpired quotations can be converted">
                                                    <i class="bi bi-arrow-right-circle"></i>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="quotation-actions">
                                            <?php if (!$canEdit): ?>
                                                <span class="disabled" title="Converted quotations cannot be edited">
                                                    <i class="bi bi-pencil"></i>
                                                </span>
                                            <?php else: ?>
                                                <a href="edit_quotation.php?update_id=<?php echo $quotationId; ?>" title="Edit quotation">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                        <td class="quotation-actions">
                                            <?php if ($isConverted): ?>
                                                <span class="disabled" title="Converted quotations cannot be deleted">
                                                    <i class="bi bi-trash"></i>
                                                </span>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-link p-0 deleteQuotation"
                                                    data-id="<?php echo $quotationId; ?>" title="Delete quotation">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="d-flex flex-wrap justify-content-between align-items-center mt-3">
                    <small class="quotation-muted" id="quotationSummary"><?php echo pcs_quotation_summary($initialData); ?></small>
                    <nav aria-label="Quotation pagination" id="quotationPagination">
                        <ul class="pagination pagination-sm mb-0">
                            <?php if ($initialCurrentPage > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="#" data-page="<?php echo $initialCurrentPage - 1; ?>">Previous</a>
                                </li>
                            <?php endif; ?>
                            <?php foreach (pcs_quotation_page_numbers($initialCurrentPage, $initialTotalPages) as $pageNumber): ?>
                                <?php if ($pageNumber === '...'): ?>
                                    <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                                <?php else: ?>
                                    <li class="page-item <?php echo intval($pageNumber) === $initialCurrentPage ? 'active' : ''; ?>">
                                        <a class="page-link" href="#" data-page="<?php echo intval($pageNumber); ?>"><?php echo intval($pageNumber); ?></a>
                                    </li>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php if ($initialCurrentPage < $initialTotalPages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="#" data-page="<?php echo $initialCurrentPage + 1; ?>">Next</a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="shareQuotationModal" tabindex="-1" aria-labelledby="shareQuotationModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="shareQuotationModalLabel">Share Quotation</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <div class="fw-semibold" id="shareQuotationTitle">Quotation</div>
                    <div class="small quotation-muted" id="shareQuotationSubtitle"></div>
                </div>

                <div class="d-grid gap-2 mb-3">
                    <a href="#" id="shareQuotationWhatsApp" class="btn btn-success" target="_blank" rel="noopener">
                        <i class="bi bi-whatsapp"></i> Share on WhatsApp
                    </a>
                    <a href="#" id="shareQuotationEmail" class="btn btn-outline-primary">
                        <i class="bi bi-envelope"></i> Email Quotation Details
                    </a>
                    <input type="email" id="shareQuotationEmailTo" class="form-control" placeholder="Customer email address">
                    <button type="button" id="sendQuotationEmail" class="btn btn-primary">
                        <i class="bi bi-send"></i> Send Email with Accept / Reject Links
                    </button>
                    <a href="#" id="shareQuotationDownload" class="btn btn-outline-dark" target="_blank" rel="noopener">
                        <i class="bi bi-download"></i> Download PDF
                    </a>
                    <a href="#" id="shareQuotationPrint" class="btn btn-outline-secondary" target="_blank" rel="noopener">
                        <i class="bi bi-printer"></i> Print / Open PDF
                    </a>
                    <button type="button" id="copyQuotationMessage" class="btn btn-outline-success">
                        <i class="bi bi-clipboard"></i> Copy Share Message
                    </button>
                </div>

                <label class="form-label fw-semibold" for="shareQuotationMessagePreview">Message Preview</label>
                <textarea id="shareQuotationMessagePreview" class="form-control" rows="6" readonly></textarea>
                <div id="shareQuotationAlert" class="small mt-2"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="deleteQuotationModal" tabindex="-1" aria-labelledby="deleteQuotationModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="deleteQuotationModalLabel">Delete Quotation</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-1">Are you sure you want to delete this quotation?</p>
                <p class="small text-muted mb-0">This also deletes its items and cannot be undone. Converted quotations are protected.</p>
                <div id="deleteQuotationAlert" class="small mt-2"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmDeleteQuotation">Delete</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    var currentPage = <?php echo $initialCurrentPage; ?>;
    var currentFilters = {};
    var csrfToken = <?php echo json_encode($csrfToken); ?>;
    var pendingDeleteId = null;
    var shareData = null;

    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatDate(raw) {
        if (!raw) {
            return '\u2014';
        }
        var parts = String(raw).split('-');
        if (parts.length !== 3) {
            return raw;
        }
        var date = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
        if (isNaN(date.getTime())) {
            return raw;
        }
        return date.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
    }

    function formatQuotationDateTime(dateRaw, createdAtRaw) {
        var dateText = formatDate(dateRaw);
        var match = String(createdAtRaw || '').match(/\b\d{4}-\d{2}-\d{2}\s+(\d{2}):(\d{2}):\d{2}\b/);
        if (!match || dateText === '\u2014') {
            return dateText;
        }

        var hour = Number(match[1]);
        var suffix = hour >= 12 ? 'PM' : 'AM';
        hour = hour % 12 || 12;
        return dateText + ', ' + String(hour).padStart(2, '0') + ':' + match[2] + ' ' + suffix + ' IST';
    }

    function formatMoney(amount, currency) {
        var value = Number(amount || 0);
        var symbol = String(currency || 'INR').toUpperCase() === 'USD' ? '$' : '\u20B9';
        return symbol + ' ' + value.toLocaleString('en-IN', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function pageNumbers(current, total) {
        var pages = [];
        if (total <= 1) {
            return pages;
        }
        if (total <= 7) {
            for (var i = 1; i <= total; i++) {
                pages.push(i);
            }
            return pages;
        }

        pages.push(1);
        if (current > 4) {
            pages.push('...');
        }
        for (var page = Math.max(2, current - 2); page <= Math.min(total - 1, current + 2); page++) {
            pages.push(page);
        }
        if (current < total - 3) {
            pages.push('...');
        }
        pages.push(total);
        return pages.filter(function (item, index, list) {
            return list.indexOf(item) === index;
        });
    }

    function tableRow(quotation) {
        var id = Number(quotation.id);
        var status = String(quotation.status || 'Draft');
        var converted = Boolean(quotation.is_converted || quotation.converted_invoice_id);
        var canEdit = !converted;
        var whatsApp = quotation.whatsapp || '';
        var editAction = !canEdit
            ? '<span class="disabled" title="Converted quotations cannot be edited"><i class="bi bi-pencil"></i></span>'
            : '<a href="edit_quotation.php?update_id=' + id + '" title="Edit quotation"><i class="bi bi-pencil"></i></a>';
        var deleteAction = converted
            ? '<span class="disabled" title="Converted quotations cannot be deleted"><i class="bi bi-trash"></i></span>'
            : '<button type="button" class="btn btn-link p-0 deleteQuotation" data-id="' + id + '" title="Delete quotation"><i class="bi bi-trash"></i></button>';

        return '<tr id="quotationRow_' + id + '"' +
            ' data-id="' + id + '"' +
            ' data-quotation-no="' + escapeHtml(quotation.quotation_no) + '"' +
            ' data-customer-name="' + escapeHtml(quotation.customer_name) + '"' +
            ' data-phone="' + escapeHtml(quotation.customer_phone) + '"' +
            ' data-whatsapp="' + escapeHtml(whatsApp) + '"' +
            ' data-email="' + escapeHtml(quotation.customer_email) + '"' +
            ' data-total="' + escapeHtml(quotation.grand_total) + '"' +
            ' data-currency="' + escapeHtml(quotation.currency || 'INR') + '"' +
            ' data-quotation-date="' + escapeHtml(quotation.quotation_date) + '"' +
            ' data-valid-until="' + escapeHtml(quotation.valid_until) + '">' +
            '<td class="fw-semibold"><a href="quotation_view.php?id=' + id + '">' + escapeHtml(quotation.quotation_no) + '</a></td>' +
            '<td>' + escapeHtml(quotation.customer_name) + '</td>' +
            '<td>' + escapeHtml(formatQuotationDateTime(quotation.quotation_date, quotation.created_at)) + '</td>' +
            '<td>' + escapeHtml(formatDate(quotation.valid_until)) + '</td>' +
            '<td>' + escapeHtml(formatMoney(quotation.grand_total, quotation.currency)) + '</td>' +
            '<td><span class="quotation-status status-' + escapeHtml(status.toLowerCase()) + '">' + escapeHtml(status) + '</span></td>' +
            '<td class="quotation-actions"><a href="quotation_view.php?id=' + id + '" title="View quotation"><i class="bi bi-eye"></i></a></td>' +
            '<td class="quotation-actions"><a href="quotation_pdf.php?id=' + id + '" target="_blank" rel="noopener" title="Print quotation"><i class="bi bi-printer"></i></a></td>' +
            '<td class="quotation-actions">' + (converted && quotation.converted_invoice_id
                ? '<a href="print_invoice.php?invoice_id=' + Number(quotation.converted_invoice_id) + '" title="Open converted invoice"><i class="bi bi-receipt"></i></a>'
                : (status === 'Sent' && !quotation.is_rejected && !quotation.is_expired
                    ? '<a href="create_invoice.php?from_quotation=' + id + '" title="Convert quotation to invoice"><i class="bi bi-arrow-repeat"></i></a>'
                    : '<span class="disabled" title="Only sent, unexpired quotations can be converted"><i class="bi bi-arrow-right-circle"></i></span>')) + '</td>' +
            '<td class="quotation-actions">' + editAction + '</td>' +
            '<td class="quotation-actions">' + deleteAction + '</td>' +
            '</tr>';
    }

    function renderTable(quotations) {
        var body = document.getElementById('quotationTableBody');
        if (!body) {
            return;
        }

        if (!quotations || quotations.length === 0) {
            body.innerHTML = '<tr><td colspan="11" class="quotation-empty">No quotations match the selected filters.</td></tr>';
            return;
        }

        body.innerHTML = quotations.map(tableRow).join('');
    }

    function renderPagination(totalPages, activePage) {
        var container = document.getElementById('quotationPagination');
        if (!container) {
            return;
        }

        if (totalPages <= 1) {
            container.innerHTML = '';
            return;
        }

        var html = '<ul class="pagination pagination-sm mb-0">';
        if (activePage > 1) {
            html += '<li class="page-item"><a class="page-link" href="#" data-page="' + (activePage - 1) + '">Previous</a></li>';
        }
        pageNumbers(activePage, totalPages).forEach(function (page) {
            if (page === '...') {
                html += '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
            } else {
                html += '<li class="page-item' + (Number(page) === Number(activePage) ? ' active' : '') + '">' +
                    '<a class="page-link" href="#" data-page="' + page + '">' + page + '</a></li>';
            }
        });
        if (activePage < totalPages) {
            html += '<li class="page-item"><a class="page-link" href="#" data-page="' + (activePage + 1) + '">Next</a></li>';
        }
        html += '</ul>';
        container.innerHTML = html;
    }

    function renderSummary(total, perPage, activePage) {
        var summary = document.getElementById('quotationSummary');
        if (!summary) {
            return;
        }
        if (!total) {
            summary.textContent = 'No quotations found.';
            return;
        }
        var first = ((activePage - 1) * perPage) + 1;
        var last = Math.min(total, first + perPage - 1);
        summary.textContent = 'Showing ' + first + '\u2013' + last + ' of ' + total + ' quotation' + (total === 1 ? '' : 's');
    }

    function loadQuotations(page) {
        currentPage = Math.max(1, Number(page) || 1);

        $.ajax({
            url: 'quotation_list.php',
            type: 'POST',
            dataType: 'json',
            data: {
                ajax: 1,
                page: currentPage,
                filters: currentFilters
            }
        }).done(function (response) {
            if (!response || response.error) {
                showListError(response && response.error ? response.error : 'Unable to load quotations.');
                return;
            }

            currentPage = Number(response.currentPage) || 1;
            renderTable(response.quotations || []);
            renderPagination(Number(response.totalPages) || 0, currentPage);
            renderSummary(Number(response.total) || 0, Number(response.perPage) || 5, currentPage);
        }).fail(function (xhr) {
            var message = xhr.responseJSON && xhr.responseJSON.error
                ? xhr.responseJSON.error
                : 'Unable to load quotations. Please try again.';
            showListError(message);
        });
    }

    function showListError(message) {
        var body = document.getElementById('quotationTableBody');
        if (body) {
            body.innerHTML = '<tr><td colspan="11" class="quotation-empty text-danger">' +
                escapeHtml(message) + '</td></tr>';
        }
    }

    function collectFilters() {
        var filters = {};
        var month = document.getElementById('quotationMonth');
        var date = document.getElementById('quotationDateFilter');
        var currency = document.getElementById('quotationCurrencyFilter');
        var status = document.getElementById('quotationStatusFilter');
        var gstType = document.querySelector('input[name="quotationGstFilter"]:checked');

        if (month && month.value !== '') {
            filters.month = month.value;
        }
        if (date && date.value !== '') {
            filters.date = date.value;
        }
        if (currency && currency.value !== '') {
            filters.currency = currency.value;
        }
        if (currency && currency.value === 'INR' && gstType) {
            filters.gst_type = gstType.value;
        }
        if (status && status.value !== '') {
            filters.status = status.value;
        }

        return filters;
    }

    function applyFilters() {
        currentFilters = collectFilters();
        loadQuotations(1);
    }

    function setQuotationMonth(value) {
        var trigger = document.getElementById('quotationMonth');
        var options = document.querySelectorAll('#quotationMonthOptions .quotation-month-option');
        var selectedOption = options[0];

        Array.prototype.forEach.call(options, function (option) {
            var selected = option.getAttribute('data-value') === String(value);
            option.setAttribute('aria-selected', selected ? 'true' : 'false');
            if (selected) {
                selectedOption = option;
            }
        });

        if (trigger && selectedOption) {
            trigger.value = selectedOption.getAttribute('data-value');
            trigger.textContent = selectedOption.textContent.trim();
        }
    }

    function closeQuotationMonthOptions(returnFocus) {
        var trigger = document.getElementById('quotationMonth');
        var options = document.getElementById('quotationMonthOptions');
        if (!trigger || !options || options.hidden) {
            return;
        }
        options.hidden = true;
        trigger.setAttribute('aria-expanded', 'false');
        if (returnFocus) {
            trigger.focus();
        }
    }

    function buildShareMessage(data) {
        var customer = data.customerName ? 'Hello ' + data.customerName + ',' : 'Hello,';
        return [
            customer,
            '',
            'Please find our quotation ' + data.quotationNo + ' dated ' + formatDate(data.quotationDate) + ' for ' + formatMoney(data.total, data.currency) + '.',
            'Valid until: ' + formatDate(data.validUntil) + '.',
            '',
            'Thank you,',
            'Pavitha Consultancy Services OPC Pvt Ltd.'
        ].join('\n');
    }

    function openShareModal(row) {
        var data = {
            id: Number(row.getAttribute('data-id')),
            quotationNo: row.getAttribute('data-quotation-no') || '',
            customerName: row.getAttribute('data-customer-name') || '',
            phone: row.getAttribute('data-phone') || '',
            whatsApp: row.getAttribute('data-whatsapp') || '',
            email: row.getAttribute('data-email') || '',
            total: row.getAttribute('data-total') || 0,
            currency: row.getAttribute('data-currency') || 'INR',
            quotationDate: row.getAttribute('data-quotation-date') || '',
            validUntil: row.getAttribute('data-valid-until') || ''
        };

        shareData = data;
        var message = buildShareMessage(data);
        var preview = document.getElementById('shareQuotationMessagePreview');
        var alertBox = document.getElementById('shareQuotationAlert');
        var whatsAppButton = document.getElementById('shareQuotationWhatsApp');
        var emailButton = document.getElementById('shareQuotationEmail');
        var downloadButton = document.getElementById('shareQuotationDownload');
        var printButton = document.getElementById('shareQuotationPrint');
        var emailToInput = document.getElementById('shareQuotationEmailTo');
        var sendEmailButton = document.getElementById('sendQuotationEmail');

        document.getElementById('shareQuotationTitle').textContent = data.quotationNo || 'Quotation';
        document.getElementById('shareQuotationSubtitle').textContent =
            data.customerName + (data.phone ? ' \u00B7 ' + data.phone : '');
        preview.value = message;
        if (alertBox) {
            alertBox.textContent = '';
            alertBox.className = 'small mt-2';
        }
        if (emailToInput) {
            emailToInput.value = data.email || '';
        }
        if (sendEmailButton) {
            sendEmailButton.disabled = false;
            sendEmailButton.innerHTML = '<i class="bi bi-send"></i> Send Email with Accept / Reject Links';
        }

        if (data.whatsApp) {
            whatsAppButton.href = 'https://wa.me/' + encodeURIComponent(data.whatsApp) + '?text=' + encodeURIComponent(message);
            whatsAppButton.classList.remove('disabled');
            whatsAppButton.removeAttribute('aria-disabled');
        } else {
            whatsAppButton.href = '#';
            whatsAppButton.classList.add('disabled');
            whatsAppButton.setAttribute('aria-disabled', 'true');
        }

        if (data.email) {
            emailButton.href = 'mailto:' + encodeURIComponent(data.email) +
                '?subject=' + encodeURIComponent('Quotation ' + data.quotationNo) +
                '&body=' + encodeURIComponent(message);
            emailButton.classList.remove('disabled');
            emailButton.removeAttribute('aria-disabled');
        } else {
            emailButton.href = '#';
            emailButton.classList.add('disabled');
            emailButton.setAttribute('aria-disabled', 'true');
        }

        downloadButton.href = 'quotation_pdf.php?id=' + data.id + '&download=1';
        printButton.href = 'quotation_pdf.php?id=' + data.id;

        var modalElement = document.getElementById('shareQuotationModal');
        if (modalElement) {
            bootstrap.Modal.getOrCreateInstance(modalElement).show();
        }
    }

    function copyMessage() {
        var preview = document.getElementById('shareQuotationMessagePreview');
        var alertBox = document.getElementById('shareQuotationAlert');
        if (!preview) {
            return;
        }

        var done = function () {
            if (alertBox) {
                alertBox.className = 'small mt-2 text-success';
                alertBox.textContent = 'Share message copied to the clipboard.';
            }
        };
        var failed = function () {
            preview.focus();
            preview.select();
            try {
                document.execCommand('copy');
                done();
            } catch (error) {
                if (alertBox) {
                    alertBox.className = 'small mt-2 text-danger';
                    alertBox.textContent = 'Could not copy automatically. Please select and copy the message.';
                }
            }
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(preview.value).then(done).catch(failed);
        } else {
            failed();
        }
    }

    $(document).ready(function () {
        ['quotationMonth', 'quotationDateFilter', 'quotationCurrencyFilter', 'quotationStatusFilter'].forEach(function (id) {
            var element = document.getElementById(id);
            if (element) {
                element.addEventListener('change', applyFilters);
            }
        });

        var currencyFilter = document.getElementById('quotationCurrencyFilter');
        var gstFilterContainer = document.getElementById('quotationGstFilterContainer');
        function updateQuotationGstFilter() {
            var isInr = currencyFilter && currencyFilter.value === 'INR';
            if (gstFilterContainer) {
                gstFilterContainer.style.display = isInr ? '' : 'none';
            }
            if (!isInr) {
                document.querySelectorAll('input[name="quotationGstFilter"]').forEach(function (input) {
                    input.checked = false;
                });
            }
        }
        if (currencyFilter) {
            currencyFilter.addEventListener('change', updateQuotationGstFilter);
            updateQuotationGstFilter();
        }
        document.querySelectorAll('input[name="quotationGstFilter"]').forEach(function (input) {
            input.addEventListener('change', applyFilters);
        });

        var monthTrigger = document.getElementById('quotationMonth');
        var monthOptions = document.getElementById('quotationMonthOptions');
        if (monthTrigger && monthOptions) {
            monthTrigger.addEventListener('click', function () {
                monthOptions.hidden = !monthOptions.hidden;
                monthTrigger.setAttribute('aria-expanded', monthOptions.hidden ? 'false' : 'true');
                if (!monthOptions.hidden) {
                    var selectedOption = monthOptions.querySelector('[aria-selected="true"]');
                    if (selectedOption) {
                        selectedOption.focus();
                    }
                }
            });

            monthTrigger.addEventListener('keydown', function (event) {
                if (event.key === 'ArrowDown' || event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    if (monthOptions.hidden) {
                        monthTrigger.click();
                    }
                }
            });

            monthOptions.addEventListener('click', function (event) {
                var option = event.target.closest('.quotation-month-option');
                if (!option) {
                    return;
                }
                setQuotationMonth(option.getAttribute('data-value'));
                closeQuotationMonthOptions(true);
                var changeEvent = document.createEvent('HTMLEvents');
                changeEvent.initEvent('change', true, false);
                monthTrigger.dispatchEvent(changeEvent);
            });

            monthOptions.addEventListener('keydown', function (event) {
                var options = Array.prototype.slice.call(monthOptions.querySelectorAll('.quotation-month-option'));
                var index = options.indexOf(document.activeElement);
                var nextIndex = index;
                if (event.key === 'ArrowDown') {
                    nextIndex = Math.min(options.length - 1, index + 1);
                } else if (event.key === 'ArrowUp') {
                    nextIndex = Math.max(0, index - 1);
                } else if (event.key === 'Home') {
                    nextIndex = 0;
                } else if (event.key === 'End') {
                    nextIndex = options.length - 1;
                } else if (event.key === 'Escape') {
                    event.preventDefault();
                    closeQuotationMonthOptions(true);
                    return;
                } else {
                    return;
                }
                event.preventDefault();
                options[nextIndex].focus();
            });

            document.addEventListener('click', function (event) {
                if (!event.target.closest('.quotation-month-filter')) {
                    closeQuotationMonthOptions(false);
                }
            });
        }

        var clearButton = document.getElementById('clearQuotationFilters');
        if (clearButton) {
            clearButton.addEventListener('click', function () {
                ['quotationDateFilter', 'quotationCurrencyFilter', 'quotationStatusFilter'].forEach(function (id) {
                    var element = document.getElementById(id);
                    if (element) {
                        element.value = '';
                    }
                });
                document.querySelectorAll('input[name="quotationGstFilter"]').forEach(function (input) {
                    input.checked = false;
                });
                updateQuotationGstFilter();
                setQuotationMonth('');
                closeQuotationMonthOptions(false);
                currentFilters = {};
                loadQuotations(1);
            });
        }

        $(document).on('click', '#quotationPagination .page-link', function (event) {
            event.preventDefault();
            var page = Number($(this).attr('data-page'));
            if (page > 0) {
                loadQuotations(page);
            }
        });

        $(document).on('click', '.shareQuotation', function (event) {
            event.preventDefault();
            var row = this.closest('tr');
            if (row) {
                openShareModal(row);
            }
        });

        var copyButton = document.getElementById('copyQuotationMessage');
        if (copyButton) {
            copyButton.addEventListener('click', copyMessage);
        }

        var sendEmailButton = document.getElementById('sendQuotationEmail');
        if (sendEmailButton) {
            sendEmailButton.addEventListener('click', function () {
                var alertBox = document.getElementById('shareQuotationAlert');
                var emailInput = document.getElementById('shareQuotationEmailTo');
                var toEmail = emailInput ? emailInput.value.trim() : '';
                if (!shareData || !shareData.id) {
                    return;
                }
                if (!toEmail || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(toEmail)) {
                    if (alertBox) {
                        alertBox.className = 'small mt-2 text-danger';
                        alertBox.textContent = 'Please enter a valid customer email address.';
                    }
                    return;
                }

                sendEmailButton.disabled = true;
                sendEmailButton.innerHTML = '<i class="bi bi-send"></i> Sending...';
                if (alertBox) {
                    alertBox.className = 'small mt-2';
                    alertBox.textContent = '';
                }

                $.ajax({
                    url: 'action.php',
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'send_quotation_email',
                        quotation_id: shareData.id,
                        to_email: toEmail,
                        csrf_token: csrfToken
                    }
                }).done(function (response) {
                    if (response && Number(response.status) === 1) {
                        if (alertBox) {
                            alertBox.className = 'small mt-2 text-success';
                            alertBox.textContent = response.message || 'Quotation email sent successfully.';
                        }
                        sendEmailButton.innerHTML = '<i class="bi bi-send"></i> Sent';
                        loadQuotations(currentPage);
                    } else {
                        if (alertBox) {
                            alertBox.className = 'small mt-2 text-danger';
                            alertBox.textContent = (response && response.message) || 'Unable to send quotation email.';
                        }
                        sendEmailButton.disabled = false;
                        sendEmailButton.innerHTML = '<i class="bi bi-send"></i> Send Email with Accept / Reject Links';
                    }
                }).fail(function (xhr) {
                    if (alertBox) {
                        alertBox.className = 'small mt-2 text-danger';
                        alertBox.textContent = (xhr.responseJSON && xhr.responseJSON.message) ||
                            'Unable to send quotation email.';
                    }
                    sendEmailButton.disabled = false;
                    sendEmailButton.innerHTML = '<i class="bi bi-send"></i> Send Email with Accept / Reject Links';
                });
            });
        }

        $(document).on('click', '.deleteQuotation', function (event) {
            event.preventDefault();
            pendingDeleteId = Number($(this).attr('data-id')) || 0;
            var alertBox = document.getElementById('deleteQuotationAlert');
            if (alertBox) {
                alertBox.textContent = '';
                alertBox.className = 'small mt-2';
            }
            if (pendingDeleteId > 0) {
                bootstrap.Modal.getOrCreateInstance(document.getElementById('deleteQuotationModal')).show();
            }
        });

        var confirmDelete = document.getElementById('confirmDeleteQuotation');
        if (confirmDelete) {
            confirmDelete.addEventListener('click', function () {
                if (!pendingDeleteId) {
                    return;
                }
                var alertBox = document.getElementById('deleteQuotationAlert');
                confirmDelete.disabled = true;
                confirmDelete.textContent = 'Deleting...';

                $.ajax({
                    url: 'action.php',
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'delete_quotation',
                        id: pendingDeleteId,
                        csrf_token: csrfToken
                    }
                }).done(function (response) {
                    if (response && Number(response.status) === 1) {
                        bootstrap.Modal.getOrCreateInstance(document.getElementById('deleteQuotationModal')).hide();
                        pendingDeleteId = null;
                        loadQuotations(currentPage);
                    } else if (alertBox) {
                        alertBox.className = 'small mt-2 text-danger';
                        alertBox.textContent = (response && response.message) || 'Unable to delete quotation.';
                    }
                }).fail(function (xhr) {
                    if (alertBox) {
                        alertBox.className = 'small mt-2 text-danger';
                        alertBox.textContent = (xhr.responseJSON && xhr.responseJSON.message) ||
                            'Unable to delete quotation.';
                    }
                }).always(function () {
                    confirmDelete.disabled = false;
                    confirmDelete.textContent = 'Delete';
                });
            });
        }

        currentFilters = collectFilters();
        if (Object.keys(currentFilters).length > 0) {
            loadQuotations(1);
        }
    });
}());
</script>
