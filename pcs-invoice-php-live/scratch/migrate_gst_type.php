<?php
require_once __DIR__ . '/../db.php';

$sql = "SHOW COLUMNS FROM invoice_order LIKE 'gst_type'";
$result = $c->query($sql);

if ($result->num_rows == 0) {
    $sql = "ALTER TABLE invoice_order ADD COLUMN gst_type VARCHAR(20) DEFAULT 'with_gst' AFTER currency";
    if ($c->query($sql) === TRUE) {
        echo "Column 'gst_type' added successfully.";
    } else {
        echo "Error adding column: " . $c->error;
    }
} else {
    echo "Column 'gst_type' already exists.";
}

$c->close();
?>
