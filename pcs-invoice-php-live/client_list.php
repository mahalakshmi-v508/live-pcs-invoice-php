<?php
require_once 'session.php';
ensureSessionStarted();
require_once 'Invoice.php';

$invoice = new Invoice();
$invoice->checkLoggedIn();

$perPage = 10;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $searchAjax = trim((string) ($_POST['client_q'] ?? ''));
    $pageAjax = max(1, intval($_POST['client_page'] ?? 1));
    $clientAjaxResult = $invoice->getClientList($searchAjax, $pageAjax, $perPage);
    echo json_encode([
        'status' => 1,
        'clients' => $clientAjaxResult['clients'] ?? [],
        'total' => intval($clientAjaxResult['total'] ?? 0),
        'totalPages' => intval($clientAjaxResult['totalPages'] ?? 1),
        'currentPage' => intval($clientAjaxResult['currentPage'] ?? 1)
    ]);
    exit;
}

$search = trim((string) ($_GET['client_q'] ?? ''));
$page = max(1, intval($_GET['client_page'] ?? 1));

$clientResult = $invoice->getClientList($search, $page, $perPage);
$clients = $clientResult['clients'] ?? [];
$totalPages = intval($clientResult['totalPages'] ?? 1);
$currentPage = intval($clientResult['currentPage'] ?? 1);
$totalClients = intval($clientResult['total'] ?? 0);
?>

<style>
    .view-clients-title {
        color: #1f4e79 !important;
    }

    .view-clients-total-badge {
        background-color: #1f4e79 !important;
    }

    .view-clients-search-btn {
        background-color: #1f4e79 !important;
        border-color: #1f4e79 !important;
    }

    .view-clients-table thead,
    .view-clients-table thead th {
        background-color: #1f4e79 !important;
        color: #ffffff !important;
    }
</style>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-body">
        <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between mb-3 gap-2">
            <h4 class="mb-0 view-clients-title">View Clients</h4>
            <span class="badge view-clients-total-badge" id="viewClientsTotalBadge">Total: <?php echo $totalClients; ?></span>
        </div>

        <form method="get" class="row g-2 mb-3" id="clientSearchForm">
            <input type="hidden" name="page" value="client_list">
            <div class="col-12 col-md-9">
                <input
                    type="text"
                    name="client_q"
                    id="clientSearchInput"
                    class="form-control"
                    placeholder="Search by Client Name / GST No / PAN No"
                    value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="col-6 col-md-2">
                <button type="submit" class="btn view-clients-search-btn w-100 text-white">Search</button>
            </div>
            <div class="col-6 col-md-1">
                <a href="?page=client_list" class="btn btn-outline-secondary w-100">Clear</a>
            </div>
        </form>

        <div class="table-responsive" id="clientTableContainer">
            <table class="table table-hover align-middle view-clients-table">
                <thead>
                    <tr>
                        <th>Client Name</th>
                        <th>Address</th>
                        <th>GST No</th>
                        <th>PAN No</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($clients)): ?>
                        <tr>
                            <td colspan="4" class="text-center text-muted py-4">No clients found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($clients as $client): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($client['receiver_name'] ?: '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo nl2br(htmlspecialchars($client['receiver_address'] ?: '-', ENT_QUOTES, 'UTF-8')); ?></td>
                                <td><?php echo htmlspecialchars($client['gst_no'] ?: '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($client['pan_no'] ?: '-', ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav aria-label="Client pagination" id="clientPaginationNav">
                <ul class="pagination justify-content-center mt-3">
                    <?php if ($currentPage > 1): ?>
                        <li class="page-item">
                            <a class="page-link"
                               href="?page=client_list&client_q=<?php echo urlencode($search); ?>&client_page=<?php echo $currentPage - 1; ?>">
                                Previous
                            </a>
                        </li>
                    <?php endif; ?>

                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                        <li class="page-item <?php echo $p === $currentPage ? 'active' : ''; ?>">
                            <a class="page-link"
                               href="?page=client_list&client_q=<?php echo urlencode($search); ?>&client_page=<?php echo $p; ?>">
                                <?php echo $p; ?>
                            </a>
                        </li>
                    <?php endfor; ?>

                    <?php if ($currentPage < $totalPages): ?>
                        <li class="page-item">
                            <a class="page-link"
                               href="?page=client_list&client_q=<?php echo urlencode($search); ?>&client_page=<?php echo $currentPage + 1; ?>">
                                Next
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
        <?php endif; ?>
    </div>
</div>

<script>
    (function () {
        const $searchInput = $('#clientSearchInput');
        const $searchForm = $('#clientSearchForm');
        const $tableContainer = $('#clientTableContainer');
        const $totalBadge = $('#viewClientsTotalBadge');
        const $cardBody = $searchForm.closest('.card-body');
        let debounceTimer = null;
        let activeRequest = null;

        function escapeHtml(value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function renderClientTable(clients) {
            let rowsHtml = '';
            if (!Array.isArray(clients) || clients.length === 0) {
                rowsHtml = '<tr><td colspan="4" class="text-center text-muted py-4">No clients found.</td></tr>';
            } else {
                clients.forEach(function (client) {
                    const name = escapeHtml(client.receiver_name || '-');
                    const address = escapeHtml(client.receiver_address || '-').replace(/\n/g, '<br>');
                    const gst = escapeHtml(client.gst_no || '-');
                    const pan = escapeHtml(client.pan_no || '-');
                    rowsHtml += '<tr>'
                        + '<td>' + name + '</td>'
                        + '<td>' + address + '</td>'
                        + '<td>' + gst + '</td>'
                        + '<td>' + pan + '</td>'
                        + '</tr>';
                });
            }

            const tableHtml = ''
                + '<table class="table table-hover align-middle view-clients-table">'
                + '  <thead>'
                + '    <tr>'
                + '      <th>Client Name</th>'
                + '      <th>Address</th>'
                + '      <th>GST No</th>'
                + '      <th>PAN No</th>'
                + '    </tr>'
                + '  </thead>'
                + '  <tbody>' + rowsHtml + '</tbody>'
                + '</table>';

            $tableContainer.html(tableHtml);
        }

        function renderPagination(totalPages, currentPage) {
            $('#clientPaginationNav').remove();
            totalPages = parseInt(totalPages, 10) || 1;
            currentPage = parseInt(currentPage, 10) || 1;

            if (totalPages <= 1) {
                return;
            }

            let html = '<nav aria-label="Client pagination" id="clientPaginationNav"><ul class="pagination justify-content-center mt-3">';
            if (currentPage > 1) {
                html += '<li class="page-item"><a class="page-link client-page-link" href="#" data-page="' + (currentPage - 1) + '">Previous</a></li>';
            }
            for (let p = 1; p <= totalPages; p++) {
                const activeClass = p === currentPage ? ' active' : '';
                html += '<li class="page-item' + activeClass + '"><a class="page-link client-page-link" href="#" data-page="' + p + '">' + p + '</a></li>';
            }
            if (currentPage < totalPages) {
                html += '<li class="page-item"><a class="page-link client-page-link" href="#" data-page="' + (currentPage + 1) + '">Next</a></li>';
            }
            html += '</ul></nav>';

            $cardBody.append(html);
        }

        function loadClients(page) {
            const query = ($searchInput.val() || '').trim();
            if (activeRequest && activeRequest.readyState !== 4) {
                activeRequest.abort();
            }

            activeRequest = $.ajax({
                url: 'client_list.php',
                type: 'POST',
                dataType: 'json',
                data: {
                    ajax: 1,
                    client_q: query,
                    client_page: page || 1
                },
                success: function (response) {
                    if (!response || Number(response.status) !== 1) {
                        return;
                    }
                    renderClientTable(response.clients || []);
                    $totalBadge.text('Total: ' + (response.total || 0));
                    renderPagination(response.totalPages || 1, response.currentPage || 1);
                }
            });
        }

        $searchInput.on('input keyup', function () {
            if (debounceTimer) {
                clearTimeout(debounceTimer);
            }
            debounceTimer = setTimeout(function () {
                loadClients(1);
            }, 250);
        });

        $searchForm.on('submit', function (e) {
            e.preventDefault();
            loadClients(1);
        });

        $(document).on('click', '.client-page-link', function (e) {
            e.preventDefault();
            const page = parseInt($(this).attr('data-page'), 10) || 1;
            loadClients(page);
        });
    })();
</script>
