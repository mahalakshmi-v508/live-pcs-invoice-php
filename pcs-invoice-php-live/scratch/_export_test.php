<?php
require dirname(__DIR__) . '/session.php';
ensureSessionStarted();
$_SESSION['userId'] = 1;
require dirname(__DIR__) . '/Invoice.php';
require dirname(__DIR__) . '/invoice_export.php';
