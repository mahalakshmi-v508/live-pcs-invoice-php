<!-- Invoice + User Dropdown Section -->
<div class="d-flex justify-content-between align-items-center px-3 py-2" style="background-color: #f8f9fa;">
    <ul class="nav">
        <li class="nav-item dropdown">
            <button class="btn btn-primary dropdown-toggle" type="button" data-toggle="dropdown">
                Invoice <span class="caret"></span>
            </button>
            <ul class="dropdown-menu">
                <li><a class="dropdown-item" href="dashboard.php">Dashboard</a></li>
                <li><a class="dropdown-item" href="invoice_list.php">Invoice List</a></li>
                <li><a class="dropdown-item" href="create_invoice.php">Create Invoice</a></li>
            </ul>
        </li>
        <li class="nav-item dropdown ml-2">
            <button class="btn btn-primary dropdown-toggle" type="button" data-toggle="dropdown">
                Quotation <span class="caret"></span>
            </button>
            <ul class="dropdown-menu">
                <li><a class="dropdown-item" href="main.php?page=quotation_list">Quotation List</a></li>
                <li><a class="dropdown-item" href="create_quotation.php">Create Quotation</a></li>
            </ul>
        </li>
    </ul>

    <?php if (!empty($_SESSION['userId'])) { ?>
    <ul class="nav">
        <li class="nav-item dropdown">
            <button class="btn btn-secondary dropdown-toggle" type="button" data-toggle="dropdown">
                Logged in: <?php echo $_SESSION['user']; ?> <span class="caret"></span>
            </button>
            <ul class="dropdown-menu dropdown-menu-right">
                <!-- <li><a class="dropdown-item" href="#">Account</a></li> -->
                <li><a class="dropdown-item" href="action.php?action=logout">Logout</a></li>
            </ul>
        </li>
    </ul>
    <?php } ?>
</div>