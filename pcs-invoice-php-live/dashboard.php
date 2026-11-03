<?php
require_once 'session.php';
ensureSessionStarted();
require_once 'Invoice.php';
$invoice = new Invoice();
$invoice->checkLoggedIn();

$totalInvoices = $invoice->getTotalInvoices();
$totalCustomers = $invoice->getTotalCustomers();
$currencyCount = $invoice->getInvoicesByCurrency();
$currencyCount = is_array($currencyCount) ? array_merge(['INR' => 0, 'USD' => 0], $currencyCount) : ['INR' => 0, 'USD' => 0];
$topReceivers = $invoice->getTopReceivers();
$topReceivers = is_array($topReceivers) ? $topReceivers : [];
$recentInvoices = $invoice->getRecentInvoices(3);
$recentInvoices = is_array($recentInvoices) ? $recentInvoices : [];

// Ensure numeric totals
$totalInvoices = intval($totalInvoices);
$totalCustomers = intval($totalCustomers);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- <title>Dashboard</title> -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
    body {
        /* transition: background-color 0.3s, color 0.3s; */
        /* background-color: #eeeeee; */
        font-family: 'Segoe UI', sans-serif;
    }

    /* .dark-theme {
        background-color: #121212;
        color: #f1f1f1;
    } */

    .sidebar {
        min-height: 100vh;
        background: linear-gradient(135deg, #0f1e45 0%, #1e3c72 100%);
        color: white;
        padding-top: 1rem;
    }

    .sidebar a {
        color: white;
        padding: 10px 15px;
        display: block;
        text-decoration: none;
    }

    .sidebar a:hover {
        background-color: rgb(36, 51, 100);
    }

    .navbar-custom {
        background: #ffffff;
        height: 50px;
    }

    .card-custom {
        background: white;
        border-left: 5px solid #00008B;
        box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
    }

    /* .dark-theme .card-custom {
        background: #1e1e1e;
        color: #f1f1f1;
    } */

    @media (max-width: 768px) {
        .sidebar {
            min-height: auto;
        }
    }
    </style>
</head>

<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Main content -->
            <main class="col-md-12 col-12 p-0 m-0">
                <!-- Dashboard Content -->
                <div class="container-fluid px-3">
                    <div class="row g-3">
                        <div class="col-md-3 col-6">
                            <div class="card card-custom p-3">
                                <h6>Total Customers</h6>
                                <h3><?php echo $totalCustomers; ?></h3>
                            </div>
                        </div>
                        <div class="col-md-3 col-6">
                            <div class="card card-custom p-3">
                                <h6>Total Invoices</h6>
                                <h3><?php echo $totalInvoices; ?></h3>
                            </div>
                        </div>
                        <div class="col-md-3 col-6">
                            <div class="card card-custom p-3">
                                <h6>INR Invoices</h6>
                                <h3><?php echo $currencyCount['INR'] ?? 0; ?></h3>
                            </div>
                        </div>
                        <div class="col-md-3 col-6">
                            <div class="card card-custom p-3">
                                <h6>USD Invoices</h6>
                                <h3><?php echo $currencyCount['USD'] ?? 0; ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="row mt-4">
                        <div class="col-md-6">
                            <div class="card card-custom p-3">
                                <h6>Recent Invoices</h6>
                                <ul class="list-group">
                                    <?php foreach ($recentInvoices as $item): ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                        <div>
                                            <div><?php echo htmlspecialchars((string) ($item['receiver_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars((string) ($item['invoice_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></small>
                                        </div>
                                        <span
                                            class="badge bg-primary"><?php echo $item['total']; ?>&nbsp;<?php echo $item['currency']; ?></span>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card card-custom p-3">
                                <h6>Top Receivers</h6>
                                <ul class="list-group">
                                    <?php foreach (array_slice($topReceivers, 0, 3) as $key => $value): ?>
                                    <?php
                                        // Support both formats:
                                        // 1) [['receiver_name' => 'ABC', 'count' => 5], ...]
                                        // 2) ['ABC' => 5, ...]
                                        if (is_array($value)) {
                                            $receiverName = $value['receiver_name'] ?? 'Unknown';
                                            $invoiceCount = intval($value['count'] ?? 0);
                                        } else {
                                            $receiverName = $key;
                                            $invoiceCount = intval($value);
                                        }
                                    ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                        <?php echo htmlspecialchars((string)$receiverName, ENT_QUOTES, 'UTF-8'); ?>
                                        <span class="badge bg-success">Invoices: <?php echo $invoiceCount; ?></span>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <div class="row mt-4">
                        <div class="col-12">
                            <div class="card card-custom p-3">
                                <h6>Invoice Trends</h6>
                                <canvas id="invoiceChart" height="100"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script>
    const ctx = document.getElementById('invoiceChart').getContext('2d');
    const invoiceChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode(array_keys($currencyCount)); ?>,
            datasets: [{
                label: 'Invoices by Currency',
                data: <?php echo json_encode(array_values($currencyCount)); ?>,
                backgroundColor: ['#092B9C', '#3487E1']
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    });
    </script>
</body>

</html>