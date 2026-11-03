<?php
require dirname(__DIR__) . '/Invoice.php';
$inv = new Invoice();
$ref = new ReflectionClass($inv);
$p = $ref->getProperty('dbConnect');
$p->setAccessible(true);
$db = $p->getValue($inv);
foreach (['invoice_order_item', 'invoice_order'] as $table) {
    echo "== $table ==\n";
    $res = mysqli_query($db, "SHOW COLUMNS FROM $table");
    while ($row = mysqli_fetch_assoc($res)) {
        echo str_pad($row['Field'], 22) . $row['Type'] . "\n";
    }
}
echo "== sample items ==\n";
$res = mysqli_query($db, "SELECT order_id, hsn_sac, name FROM invoice_order_item ORDER BY order_id DESC LIMIT 8");
while ($row = mysqli_fetch_assoc($res)) {
    echo $row['order_id'] . ' | [' . $row['hsn_sac'] . '] | ' . $row['name'] . "\n";
}
echo "== per-order hsn counts ==\n";
$res = mysqli_query($db, "SELECT order_id, GROUP_CONCAT(DISTINCT hsn_sac ORDER BY hsn_sac SEPARATOR ', ') AS codes FROM invoice_order_item GROUP BY order_id ORDER BY order_id DESC LIMIT 10");
while ($row = mysqli_fetch_assoc($res)) {
    echo $row['order_id'] . ' => ' . $row['codes'] . "\n";
}
