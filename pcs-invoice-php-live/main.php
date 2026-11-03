<?php
require_once 'session.php';
ensureSessionStarted();
require_once("Invoice.php");
include("header.php");
$invoice = new Invoice();
$invoice->checkLoggedIn();

$page = $_GET['page'] ?? 'dashboard';
$currentPaginationPage = $_GET['p'] ?? 1;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page === 'quotation_list' ? 'Quotation Management' : 'Dashboard'; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <link href="css/quotation.css?v=<?php echo @filemtime(__DIR__ . '/css/quotation.css') ?: time(); ?>" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <style>
    main {
        background-color: #eeeeee;
        font-family: 'Segoe UI', sans-serif;
    }

    .dark-theme main {
        background-color: #121212;
        color: #f1f1f1;
    }

    .sidebar {
        min-height: 100vh;
        background: linear-gradient(135deg, #0f1e45 0%, #1e3c72 100%);
        color: white;
        padding-top: 2rem;
    }

    .sidebar .py-2 {
        margin-bottom: 1.5rem;
    }

    .sidebar .nav {
        display: flex;
        flex-direction: column;
        gap: 0.85rem;
    }

    .sidebar a {
        color: white;
        padding: 12px 18px;
        display: flex !important;
        align-items: center;
        gap: 10px;
        text-decoration: none;
        transition: background-color 0.15s ease;
    }

    .sidebar a:hover {
        background-color: rgb(36, 51, 100);
    }

    .sidebar a.active {
        background-color: rgba(255, 255, 255, 0.18);
        border-left: 3px solid #ffffff;
    }

    .sidebar .nav > div {
        margin-top: 0.35rem;
    }

    .sidebar .collapse .d-flex {
        padding-left: 1.2rem;
        margin-bottom: 0.5rem;
    }

    .sidebar .collapse .d-flex:last-child {
        margin-bottom: 0;
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

    .dark-theme .card-custom {
        background: #1e1e1e;
        color: #f1f1f1;
    }

    .container {
        background: white;
    }

    .dark-theme .container {
        background: #1e1e1e;
        color: #f1f1f1;
    }

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
            <!-- Sidebar -->
            <nav class="col-md-2 col-12 sidebar">
                <div class="py-2 text-center">
                    <img src="<?php echo asset_url('img/PCS-Logo.png'); ?>" alt="PCS-Logo" class="img-fluid"
                        style="max-height: 55px; max-width: 150px; filter: brightness(0) invert(1);">
                </div>
                <hr class="border-light opacity-25">
                <div class="nav flex-column">
                    <a href="?page=dashboard" class="d-flex align-items-center <?php echo $page === 'dashboard' ? 'active' : ''; ?>">
                        <i class="bi bi-speedometer2"></i><span>Dashboard</span>
                    </a>
                    <a href="?page=view_clients" class="d-flex align-items-center <?php echo ($page === 'view_clients' || $page === 'client_list') ? 'active' : ''; ?>">
                        <i class="bi bi-people"></i><span>View Clients</span>
                    </a>
                    <a href="?page=quotation_list" class="d-flex align-items-center <?php echo $page === 'quotation_list' ? 'active' : ''; ?>">
                        <i class="bi bi-file-earmark-text"></i><span>Quotation</span>
                    </a>
                    <div class="mt-2">
                        <a class="d-flex justify-content-between align-items-center <?php echo $page === 'invoice_list' ? 'active' : ''; ?>"
                            data-bs-toggle="collapse" href="#invoiceSubmenu" role="button" aria-expanded="<?php echo $page === 'invoice_list' ? 'true' : 'false'; ?>" aria-controls="invoiceSubmenu">
                            <span><i class="bi bi-person"></i> Invoice</span>
                            <i class="bi bi-caret-down-fill"></i>
                        </a>
                        <div class="collapse ms-3 <?php echo $page === 'invoice_list' ? 'show' : ''; ?>" id="invoiceSubmenu">
                            <a href="?page=invoice_list" class="d-flex align-items-center mt-2 <?php echo $page === 'invoice_list' ? 'active' : ''; ?>">
                                <i class="bi bi-list"></i><span>View Invoice</span>
                            </a>
                            <a href="create_invoice.php" class="d-flex align-items-center mt-2">
                                <i class="bi bi-plus-circle"></i><span>Create Invoice</span>
                            </a>
                        </div>
                    </div>
                    <a href="#" class="d-flex align-items-center <?php echo $page === 'supplier' ? 'active' : ''; ?>">
                        <i class="bi bi-truck"></i><span>Supplier</span>
                    </a>
                </div>
            </nav>

            <!-- Main content -->
            <main class="col-md-10 col-12 p-0 m-0">
                <!-- Top Bar -->
                <nav class="navbar navbar-expand navbar-custom w-100 p-2 mb-4">
                    <div class="container-fluid justify-content-end">
                        <button class="btn btn-light me-2" onclick="toggleTheme()"><i class="bi bi-moon"></i></button>
                        <a href="#" class="btn btn-light me-2"><i class="bi bi-person-circle"></i></a>
                        <a href="action.php?action=logout" class="btn btn-danger"><i
                                class="bi bi-box-arrow-right"></i></a>
                    </div>
                </nav>

                <div class="container-fluid px-3">
                    <?php
                        if ($page === 'dashboard') {
                            include('dashboard.php');
                        } elseif ($page === 'view_clients' || $page === 'client_list') {
                            include('client_list.php');
                        } elseif ($page === 'invoice_list') {
                            include('invoice_list.php');
                        } elseif ($page === 'quotation_list') {
                            include('quotation_list.php');
                        } else {
                            echo "<div class='alert alert-warning'>Page not found</div>";
                        }
                    ?>
                </div>
            </main>
        </div>
    </div>

    <script>
    function toggleTheme() {
        document.body.classList.toggle('dark-theme');
    }
    </script>
</body>

</html>