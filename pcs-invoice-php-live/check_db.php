<?php
require_once __DIR__ . '/db.php';

$r = $c->query("SHOW COLUMNS FROM invoice_order LIKE 'gst_type'");
if ($r->num_rows == 0) {
    if ($c->query("ALTER TABLE invoice_order ADD gst_type VARCHAR(20) DEFAULT 'with_gst' AFTER currency")) {
        echo "Added gst_type\n";
    } else {
        echo "Error adding gst_type: " . $c->error . "\n";
    }
} else {
    echo "gst_type exists\n";
}

$r = $c->query("SHOW COLUMNS FROM invoice_order LIKE 'currency'");
if ($r->num_rows == 0) {
    if ($c->query("ALTER TABLE invoice_order ADD currency VARCHAR(10) DEFAULT 'INR' AFTER supply")) {
        echo "Added currency\n";
    } else {
        echo "Error adding currency: " . $c->error . "\n";
    }
} else {
    echo "currency exists\n";
}

$r = $c->query("SHOW COLUMNS FROM invoice_order_item LIKE 'item_month'");
if ($r->num_rows == 0) {
    if ($c->query("ALTER TABLE invoice_order_item ADD item_month VARCHAR(20) NOT NULL DEFAULT '' AFTER taxable_value")) {
        echo "Added item_month\n";
    } else {
        echo "Error adding item_month: " . $c->error . "\n";
    }
} else {
    echo "item_month exists\n";
}

$r = $c->query("SHOW COLUMNS FROM invoice_order LIKE 'invoice_date'");
if ($r->num_rows == 0) {
    if ($c->query("ALTER TABLE invoice_order ADD invoice_date DATE NULL AFTER total")) {
        echo "Added invoice_date\n";
        $c->query("UPDATE invoice_order SET invoice_date = DATE(created_at) WHERE invoice_date IS NULL");
    } else {
        echo "Error adding invoice_date: " . $c->error . "\n";
    }
} else {
    echo "invoice_date exists\n";
}

$r = $c->query("SHOW COLUMNS FROM invoice_order LIKE 'invoice_no'");
if ($r->num_rows == 0) {
    if ($c->query("ALTER TABLE invoice_order ADD invoice_no VARCHAR(20) NULL AFTER id")) {
        echo "Added invoice_no\n";
        $c->query("UPDATE invoice_order SET invoice_no = CASE
            WHEN UPPER(currency) = 'INR' AND gst_type = 'without_gst' THEN LPAD(id, 4, '0')
            ELSE CONCAT('PCS', LPAD(id, 4, '0'))
        END WHERE invoice_no IS NULL OR TRIM(invoice_no) = ''");
    } else {
        echo "Error adding invoice_no: " . $c->error . "\n";
    }
} else {
    echo "invoice_no exists\n";
}
?>
