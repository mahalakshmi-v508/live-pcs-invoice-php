<?php
/**
 * Central MySQL (mysqli) connection for PCS Invoice.
 *
 * Exposes:
 *   $c    — mysqli instance (OO style: $c->query(), used by check_db, Invoice, etc.)
 *   $conn — same connection (procedural style: mysqli_query($conn, ...))
 *
 * Include once per request:
 *   require_once __DIR__ . '/db.php';
 */

declare(strict_types=1);

if (!defined('PCS_DB_HOST')) {
    define('PCS_DB_HOST', 'localhost');
    define('PCS_DB_USER', 'root');
    define('PCS_DB_PASS', '');
    define('PCS_DB_NAME', 'pcs_invoice');
}

$c = new mysqli(PCS_DB_HOST, PCS_DB_USER, PCS_DB_PASS, PCS_DB_NAME);

if ($c->connect_error) {
    error_log('PCS Invoice: database connection failed — ' . $c->connect_error);
    die('Database connection failed. Please try again later.');
}

if (!$c->set_charset('utf8mb4')) {
    error_log('PCS Invoice: could not set charset utf8mb4 — ' . $c->error);
}

/** @var mysqli $conn Alias for procedural mysqli_* calls */
$conn = $c;
