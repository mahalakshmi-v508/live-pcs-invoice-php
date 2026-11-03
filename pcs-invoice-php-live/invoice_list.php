<?php
require_once 'session.php';
ensureSessionStarted();
require_once 'Invoice.php';
$invoice = new Invoice();
$invoice->checkLoggedIn();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $filters = isset($_POST['filters']) && is_array($_POST['filters']) ? $_POST['filters'] : [];
    $page = max(1, intval($_POST['page'] ?? 1));
    $result = $invoice->getInvoiceList($filters, $page);
    echo json_encode($result);
    exit;
}

$initialData = $invoice->getInvoiceList([], 1);
$initialInvoices = $initialData['invoices'];
$initialTotalPages = $initialData['totalPages'];
$initialCurrentPage = $initialData['currentPage'];
?>

<style>
    .invoice-action-link {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        transition: transform 0.18s ease, color 0.18s ease;
    }

    .invoice-action-link i {
        font-size: 1rem;
    }

    .invoice-action-link:hover {
        transform: scale(1.08);
    }

    .invoice-action-print {
        color: #0d6efd;
    }

    .invoice-action-print:hover {
        color: #0b5ed7;
    }

    .invoice-action-share {
        color: #20c997;
    }

    .invoice-action-share:hover {
        color: #1aa179;
    }

    .invoice-action-edit {
        color: #fd7e14;
    }

    .invoice-action-edit:hover {
        color: #e36f0a;
    }

    .invoice-action-delete {
        color: #dc3545;
    }

    .invoice-action-delete:hover {
        color: #bb2d3b;
    }

    /* GST filter: rounded pill toggle, aligned with the other filter fields */
    #gstFilterContainer .gst-toggle {
        display: flex;
        width: 100%;
        gap: 3px;
        padding: 3px;
        background: #eef2f7;
        border: 1px solid #dbe3ec;
        border-radius: 999px;
    }

    #gstFilterContainer .gst-toggle .btn {
        flex: 1 1 0;
        min-width: 0;
        min-height: 30px;
        margin: 0;
        padding: 0.3rem 0.4rem;
        border: 0;
        border-radius: 999px;
        background: transparent;
        color: #5f6b7a;
        font-size: 0.8rem;
        font-weight: 600;
        line-height: 1;
        white-space: nowrap;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        box-shadow: none;
    }

    #gstFilterContainer .gst-toggle .btn:hover {
        background: #e2e8f0;
        color: #1e3c72;
    }

    #gstFilterContainer .gst-toggle .btn-check:checked + .btn {
        background: linear-gradient(135deg, #0f1e45 0%, #1e3c72 100%);
        color: #ffffff;
        box-shadow: 0 2px 6px rgba(30, 60, 114, 0.35);
    }
</style>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-body">
        <!-- <h2 style="font-size: 28px; font-weight: 600; color: #2c3e50; text-align: center; margin-bottom: 30px;">
            Invoice List</h2> -->

        <!-- Filter Section -->
        <div class="row mb-4 align-items-end">
            <div class="col-md-2">
                <label for="monthFilter" class="form-label fw-bold">Month</label>
                <select id="monthFilter" class="form-select">
                    <option value="">All Months</option>
                    <option value="1">January</option>
                    <option value="2">February</option>
                    <option value="3">March</option>
                    <option value="4">April</option>
                    <option value="5">May</option>
                    <option value="6">June</option>
                    <option value="7">July</option>
                    <option value="8">August</option>
                    <option value="9">September</option>
                    <option value="10">October</option>
                    <option value="11">November</option>
                    <option value="12">December</option>
                </select>
            </div>
            <div class="col-md-2">
                <label for="dateFilter" class="form-label fw-bold">Exact Date</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-calendar-alt"></i></span>
                    <input type="date" id="dateFilter" class="form-control" />
                </div>
            </div>
            <div class="col-md-3">
                <label for="currencyFilter" class="form-label fw-bold">Currency</label>
                <select id="currencyFilter" class="form-select">
                    <option value="">All Currencies</option>
                    <option value="INR">Rupees (INR)</option>
                    <option value="USD">Dollars (USD)</option>
                </select>
            </div>
            <div class="col-md-3" id="gstFilterContainer" style="display: none;">
                <label class="form-label fw-bold d-block">GST Type</label>
                <div class="gst-toggle" role="group" aria-label="GST Type">
                    <input type="radio" class="btn-check" name="gstFilter" id="withGst" value="with_gst" autocomplete="off">
                    <label class="btn" for="withGst">With GST</label>
                    <input type="radio" class="btn-check" name="gstFilter" id="withoutGst" value="without_gst" autocomplete="off">
                    <label class="btn" for="withoutGst">Without GST</label>
                </div>
            </div>
            <div class="col-md-2">
                <div class="d-flex gap-2">
                    <button id="exportInvoicesExcel" type="button" class="btn btn-success"
                        title="Download Excel" aria-label="Download Excel">
                        <i class="fas fa-file-excel"></i>
                    </button>
                    <button id="clearFilters" class="btn btn-secondary flex-fill">Clear Filters</button>
                </div>
            </div>
        </div>

        <div id="invoiceTableContainer">
            <?php if (empty($initialInvoices)): ?>
                <table style="width: 100%; border-collapse: collapse; text-align: center;">
                    <thead style="background-color: #1f4e79; color: #ffffff;">
                        <tr>
                            <th style="padding: 12px;">Invoice No.</th>
                            <th style="padding: 12px;">Customer Name</th>
                            <th style="padding: 12px;">Created Date</th>
                            <th style="padding: 12px;">Print</th>
                            <th style="padding: 12px;">Share</th>
                            <th style="padding: 12px;">Edit</th>
                            <th style="padding: 12px;">Delete</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td colspan="7" style="padding-top: 20px; color: #999; text-align: center; font-style: italic;">
                                No data(s) available
                            </td>
                        </tr>
                    </tbody>
                </table>
            <?php else: ?>
                <table style="width: 100%; border-collapse: collapse; text-align: center;">
                    <thead style="background-color: #1f4e79; color: #ffffff;">
                        <tr>
                            <th style="padding: 12px;">Invoice No.</th>
                            <th style="padding: 12px;">Customer Name</th>
                            <th style="padding: 12px;">Created Date</th>
                            <th style="padding: 12px;">Print</th>
                            <th style="padding: 12px;">Share</th>
                            <th style="padding: 12px;">Edit</th>
                            <th style="padding: 12px;">Delete</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($initialInvoices as $invoiceDetails): ?>
                            <tr id="row_<?php echo $invoiceDetails['id']; ?>" style="border-bottom: 1px solid #ddd;">
                                <td style="padding: 10px;"><?php echo htmlspecialchars((string) ($invoiceDetails['invoice_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td style="padding: 10px;"><?php echo $invoiceDetails['receiver_name']; ?></td>
                                <td style="padding: 10px;"><?php echo date("d/M/Y, H:i:s", strtotime($invoiceDetails['created_at'])); ?></td>
                                <td>
                                    <a href="print_invoice.php?invoice_id=<?php echo $invoiceDetails['id']; ?>" class="invoice-action-link invoice-action-print" title="Print Invoice">
                                        <i class="fas fa-print"></i>
                                    </a>
                                </td>
                                <td>
                                    <a href="#" class="shareInvoice invoice-action-link invoice-action-share" data-id="<?php echo $invoiceDetails['id']; ?>" title="Share Invoice">
                                        <i class="fas fa-share-square"></i>
                                    </a>
                                </td>
                                <td>
                                    <a href="edit_invoice.php?update_id=<?php echo $invoiceDetails['id']; ?>" class="invoice-action-link invoice-action-edit" title="Edit Invoice">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                </td>
                                <td>
                                    <a href="#" class="deleteInvoice invoice-action-link invoice-action-delete" data-id="<?php echo $invoiceDetails['id']; ?>" title="Delete Invoice">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            <nav aria-label="Invoice pagination" class="mt-4">
                <ul class="pagination justify-content-center">
                    <?php if ($initialCurrentPage > 1): ?>
                        <li class="page-item"><a class="page-link" href="#" data-page="<?php echo $initialCurrentPage - 1; ?>">Previous</a></li>
                    <?php endif; ?>
                    <?php for ($p = 1; $p <= $initialTotalPages; $p++): ?>
                        <li class="page-item <?php echo ($p === $initialCurrentPage) ? 'active' : ''; ?>">
                            <a class="page-link" href="#" data-page="<?php echo $p; ?>"><?php echo $p; ?></a>
                        </li>
                    <?php endfor; ?>
                    <?php if ($initialCurrentPage < $initialTotalPages): ?>
                        <li class="page-item"><a class="page-link" href="#" data-page="<?php echo $initialCurrentPage + 1; ?>">Next</a></li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>

        <!-- Share Invoice Modal -->
        <div class="modal fade" id="shareInvoiceModal" tabindex="-1" aria-labelledby="shareInvoiceModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="shareInvoiceModalLabel">Share Invoice</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <form id="shareInvoiceForm">
                            <input type="hidden" id="shareInvoiceId" name="invoice_id" />
                            <div class="mb-3">
                                <label for="shareToEmail" class="form-label">To Email</label>
                                <input type="email" id="shareToEmail" class="form-control" placeholder="recipient@example.com" required>
                            </div>
                            <div class="mb-3">
                                <label for="shareSubject" class="form-label">Subject</label>
                                <input type="text" id="shareSubject" class="form-control" value="Invoice PDF" required>
                            </div>
                            <div class="mb-3">
                                <label for="shareMessage" class="form-label">Message</label>
                                <textarea id="shareMessage" class="form-control" rows="4" required>Please find attached invoice PDF.</textarea>
                            </div>
                        </form>
                        <div id="shareInvoiceAlert" class="alert d-none" role="alert"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary" id="sendInvoiceEmailBtn">
                            <span class="spinner-border spinner-border-sm d-none" id="shareEmailSpinner" role="status" aria-hidden="true"></span>
                            <span id="shareEmailButtonText">Send</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Delete Confirmation Modal -->
        <div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-labelledby="deleteConfirmLabel"
            aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title" id="deleteConfirmLabel">Confirm Delete</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        Are you sure you want to delete this invoice?
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Delete</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </div>
    <script>
    let currentPage = 1;
    let currentFilters = {};
    let currentTotal = 0;

    function loadInvoices(page = 1) {
        currentPage = page;
        $.ajax({
            url: 'invoice_list.php',
            type: 'POST',
            data: {
                ajax: 1,
                filters: currentFilters,
                page: page
            },
            dataType: 'json',
            success: function(response) {
                if (!response || !response.invoices) {
                    console.error('Invalid invoice response:', response);
                    return;
                }
                currentTotal = Number(response.total) || 0;
                renderTable(response.invoices);
                renderPagination(response.totalPages, response.currentPage);
            },
            error: function(xhr, status, error) {
                console.error('Invoice load failed:', status, error, xhr.responseText);
            }
        });
    }

    function renderTable(invoices) {
        let html = `
            <table style="width: 100%; border-collapse: collapse; text-align: center;">
                <thead style="background-color: #1f4e79; color: #ffffff;">
                    <tr>
                        <th style="padding: 12px;">Invoice No.</th>
                        <th style="padding: 12px;">Customer Name</th>
                        <th style="padding: 12px;">Created Date</th>
                        <th style="padding: 12px;">Print</th>
                        <th style="padding: 12px;">Share</th>
                        <th style="padding: 12px;">Edit</th>
                        <th style="padding: 12px;">Delete</th>
                    </tr>
                </thead>
                <tbody>`;

        if (invoices.length === 0) {
            html += `
                <tr>
                    <td colspan="7" style="padding-top: 20px; color: #999; text-align: center; font-style: italic;">
                        No data(s) available
                    </td>
                </tr>`;
        } else {
            invoices.forEach(function(invoice) {
                const invoiceDate = new Date(invoice.created_at).toLocaleString('en-GB', {
                    day: '2-digit',
                    month: '2-digit',
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit'
                });
                html += `
                    <tr id='row_${invoice.id}' style='border-bottom: 1px solid #ddd;'>
                        <td style='padding: 10px;'>${invoice.invoice_number || ''}</td>
                        <td style='padding: 10px;'>${invoice.receiver_name}</td>
                        <td style='padding: 10px;'>${invoiceDate}</td>
                        <td>
                            <a href='print_invoice.php?invoice_id=${invoice.id}' class='invoice-action-link invoice-action-print' title='Print Invoice'>
                                <i class='fas fa-print'></i>
                            </a>
                        </td>
                        <td>
                            <a href='#' class='shareInvoice invoice-action-link invoice-action-share' data-id='${invoice.id}' title='Share Invoice'>
                                <i class='fas fa-share-square'></i>
                            </a>
                        </td>
                        <td>
                            <a href='edit_invoice.php?update_id=${invoice.id}' class='invoice-action-link invoice-action-edit' title='Edit Invoice'>
                                <i class='fas fa-edit'></i>
                            </a>
                        </td>
                        <td>
                            <a href='#' class='deleteInvoice invoice-action-link invoice-action-delete' data-id='${invoice.id}' title='Delete Invoice'>
                                <i class='fas fa-trash'></i>
                            </a>
                        </td>
                    </tr>`;
            });
        }

        html += `
                </tbody>
            </table>`;

        $('#invoiceTableContainer').html(html);
    }

    function renderPagination(totalPages, currentPage) {
        let html = '<nav aria-label="Invoice pagination" class="mt-4"><ul class="pagination justify-content-center">';

        if (currentPage > 1) {
            html += `<li class="page-item"><a class="page-link" href="#" data-page="${currentPage - 1}">Previous</a></li>`;
        }

        for (let p = 1; p <= totalPages; p++) {
            const active = p === currentPage ? ' active' : '';
            html += `<li class="page-item${active}"><a class="page-link" href="#" data-page="${p}">${p}</a></li>`;
        }

        if (currentPage < totalPages) {
            html += `<li class="page-item"><a class="page-link" href="#" data-page="${currentPage + 1}">Next</a></li>`;
        }

        html += '</ul></nav>';

        // Replace existing pagination or add after table
        if ($('#invoiceTableContainer nav').length) {
            $('#invoiceTableContainer nav').replaceWith(html);
        } else {
            $('#invoiceTableContainer').append(html);
        }
    }

    $(document).ready(function() {
        loadInvoices(1); // Load initial

        $('#monthFilter, #currencyFilter, #dateFilter').on('change', function() {
            currentFilters.month = $('#monthFilter').val();
            currentFilters.currency = $('#currencyFilter').val();

            const selectedDate = $('#dateFilter').val();
            if (selectedDate) {
                currentFilters.date = selectedDate;
            } else {
                delete currentFilters.date;
            }

            if (currentFilters.currency === 'INR') {
                $('#gstFilterContainer').show();
            } else {
                $('#gstFilterContainer').hide();
                $('input[name="gstFilter"]').prop('checked', false);
                delete currentFilters.gst_type;
            }

            loadInvoices(1);
        });

        $(document).on('change', 'input[name="gstFilter"]', function() {
            currentFilters.gst_type = $(this).val();
            loadInvoices(1);
        });

        $('#clearFilters').on('click', function() {
            $('#monthFilter').val('');
            $('#dateFilter').val('');
            $('#currencyFilter').val('');
            $('input[name="gstFilter"]').prop('checked', false);
            $('#gstFilterContainer').hide();
            currentFilters = {};
            loadInvoices(1);
        });

        $('#exportInvoicesExcel').on('click', function() {
            if (currentTotal <= 0) {
                alert('No data available to download.');
                return;
            }
            const query = $.param(currentFilters || {});
            window.location.href = 'invoice_export.php' + (query ? '?' + query : '');
        });

        $(document).on('click', '.pagination .page-link', function(e) {
            e.preventDefault();
            const page = $(this).data('page');
            loadInvoices(page);
        });
    });

    let selectedInvoiceId = null;
    let activeShareModal = null;

    function resetShareModal() {
        $('#shareInvoiceForm')[0].reset();
        $('#shareSubject').val('Invoice PDF');
        $('#shareMessage').val('Please find attached invoice PDF.');
        $('#shareInvoiceAlert').removeClass('alert-success alert-danger').addClass('d-none').text('');
        $('#sendInvoiceEmailBtn').prop('disabled', false);
        $('#shareEmailSpinner').addClass('d-none');
        $('#shareEmailButtonText').text('Send');
    }

    $(document).on('click', '.shareInvoice', function(e) {
        e.preventDefault();
        const invoiceId = $(this).data('id');
        if (!invoiceId) {
            alert('Invalid invoice ID.');
            return;
        }

        $('#shareInvoiceId').val(invoiceId);
        resetShareModal();
        activeShareModal = new bootstrap.Modal(document.getElementById('shareInvoiceModal'));
        activeShareModal.show();
    });

    $('#sendInvoiceEmailBtn').on('click', function() {
        const invoiceId = parseInt($('#shareInvoiceId').val(), 10);
        const toEmail = $('#shareToEmail').val().trim();
        const subject = $('#shareSubject').val().trim();
        const message = $('#shareMessage').val().trim();

        if (!invoiceId || invoiceId <= 0) {
            $('#shareInvoiceAlert').removeClass('d-none alert-success').addClass('alert-danger').text('Invalid invoice selected.');
            return;
        }
        if (!toEmail || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(toEmail)) {
            $('#shareInvoiceAlert').removeClass('d-none alert-success').addClass('alert-danger').text('Please enter a valid recipient email address.');
            return;
        }

        $('#sendInvoiceEmailBtn').prop('disabled', true);
        $('#shareEmailSpinner').removeClass('d-none');
        $('#shareEmailButtonText').text('Sending...');
        $('#shareInvoiceAlert').addClass('d-none').removeClass('alert-success alert-danger');

        $.ajax({
            url: 'action.php',
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'send_invoice_email',
                invoice_id: invoiceId,
                to_email: toEmail,
                subject: subject,
                message: message
            },
            success: function(response) {
                if (response && response.status == 1) {
                    $('#shareInvoiceAlert').removeClass('d-none alert-danger').addClass('alert-success').text(response.message || 'Invoice email sent successfully.');
                    $('#shareEmailSpinner').addClass('d-none');
                    $('#shareEmailButtonText').text('Sent');
                } else {
                    $('#shareInvoiceAlert').removeClass('d-none alert-success').addClass('alert-danger').text(response.message || 'Unable to send invoice email.');
                    $('#sendInvoiceEmailBtn').prop('disabled', false);
                    $('#shareEmailSpinner').addClass('d-none');
                    $('#shareEmailButtonText').text('Send');
                }
            },
            error: function(xhr, status, error) {
                $('#shareInvoiceAlert').removeClass('d-none alert-success').addClass('alert-danger').text('Email send failed: ' + (xhr.responseJSON?.message || error || 'Server error.'));
                $('#sendInvoiceEmailBtn').prop('disabled', false);
                $('#shareEmailSpinner').addClass('d-none');
                $('#shareEmailButtonText').text('Send');
            }
        });
    });

    $(document).on("click", ".deleteInvoice", function(e) {
        e.preventDefault();
        selectedInvoiceId = $(this).data("id");

        if (!selectedInvoiceId) {
            alert("Invalid invoice ID.");
            return;
        }

        $('#deleteConfirmModal').modal('show');
    });

    $('#confirmDeleteBtn').on('click', function() {
        if (!selectedInvoiceId) return;

        $.ajax({
            url: "action.php",
            type: "POST",
            data: {
                action: "delete_invoice",
                id: selectedInvoiceId
            },
            dataType: "json",
            success: function(response) {
                if (response.status == 1) {
                    $('#deleteConfirmModal').modal('hide');
                    loadInvoices(currentPage); // Reload current page
                } else {
                    alert("Failed to delete invoice.");
                }
            },
            error: function(xhr, status, error) {
                alert("AJAX error: " + error);
                console.error("Raw response:", xhr.responseText);
            }
        });
    });
    </script>