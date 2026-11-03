<?php
require_once __DIR__ . '/db.php';

class Invoice
{
    private $invoiceUserTable = 'invoice_user';
    private $invoiceOrderTable = 'invoice_order';
    private $invoiceOrderItemTable = 'invoice_order_item';
    /** @var mysqli */
    private $dbConnect;
    /** @var array<string, array<string, bool>> */
    private $tableColumnCache = [];
    /** @var string */
    private $lastDbError = '';

    public function __construct()
    {
        global $c;
        $this->dbConnect = $c;
    }

    public function getLastDbError()
    {
        return $this->lastDbError;
    }

    private function setLastDbError($message)
    {
        $this->lastDbError = trim((string) $message);
    }

    /** @return array<string, bool> */
    private function getTableColumnMap($tableName)
    {
        if (isset($this->tableColumnCache[$tableName])) {
            return $this->tableColumnCache[$tableName];
        }

        $map = [];
        $tableSafe = mysqli_real_escape_string($this->dbConnect, $tableName);
        $result = mysqli_query($this->dbConnect, "SHOW COLUMNS FROM `{$tableSafe}`");
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $field = isset($row['Field']) ? (string) $row['Field'] : '';
                if ($field !== '') {
                    $map[$field] = true;
                }
            }
            mysqli_free_result($result);
        }

        $this->tableColumnCache[$tableName] = $map;
        return $map;
    }

    private function hasTableColumn($tableName, $columnName)
    {
        $columns = $this->getTableColumnMap($tableName);
        return isset($columns[$columnName]);
    }

    private function clearTableColumnCache($tableName)
    {
        unset($this->tableColumnCache[$tableName]);
    }

    private function getInvoiceItemMonthColumnName()
    {
        if ($this->hasTableColumn($this->invoiceOrderItemTable, 'item_month')) {
            return 'item_month';
        }
        if ($this->hasTableColumn($this->invoiceOrderItemTable, 'month')) {
            return 'month';
        }
        return '';
    }

    /** Try to self-heal schema drift on hosting; non-fatal if ALTER is denied. */
    private function ensureInvoiceSchema()
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        $tableOrder = $this->invoiceOrderTable;
        $tableItems = $this->invoiceOrderItemTable;
        $queries = [];

        if (!$this->hasTableColumn($tableOrder, 'currency')) {
            $queries[] = "ALTER TABLE `{$tableOrder}` ADD `currency` VARCHAR(10) DEFAULT 'INR' AFTER `supply`";
        }
        if (!$this->hasTableColumn($tableOrder, 'gst_type')) {
            $queries[] = "ALTER TABLE `{$tableOrder}` ADD `gst_type` VARCHAR(20) DEFAULT 'with_gst' AFTER `currency`";
        }
        if (!$this->hasTableColumn($tableOrder, 'invoice_date')) {
            $queries[] = "ALTER TABLE `{$tableOrder}` ADD `invoice_date` DATE NULL AFTER `total`";
        }
        if (!$this->hasTableColumn($tableOrder, 'invoice_no')) {
            $queries[] = "ALTER TABLE `{$tableOrder}` ADD `invoice_no` VARCHAR(20) NULL AFTER `id`";
        }
        if (
            !$this->hasTableColumn($tableItems, 'item_month')
            && !$this->hasTableColumn($tableItems, 'month')
        ) {
            $queries[] = "ALTER TABLE `{$tableItems}` ADD `item_month` VARCHAR(20) NOT NULL DEFAULT '' AFTER `taxable_value`";
        }

        foreach ($queries as $sql) {
            if (!mysqli_query($this->dbConnect, $sql)) {
                error_log('PCS Invoice schema sync warning: ' . mysqli_error($this->dbConnect) . ' SQL=' . $sql);
            }
        }

        $this->clearTableColumnCache($tableOrder);
        $this->clearTableColumnCache($tableItems);

        if ($this->hasTableColumn($tableOrder, 'invoice_date')) {
            mysqli_query(
                $this->dbConnect,
                "UPDATE `{$tableOrder}` SET `invoice_date` = DATE(`created_at`) WHERE `invoice_date` IS NULL"
            );
        }

        if ($this->hasTableColumn($tableOrder, 'invoice_no')) {
            $hasCurrency = $this->hasTableColumn($tableOrder, 'currency');
            $hasGstType = $this->hasTableColumn($tableOrder, 'gst_type');
            $numberExpr = "CONCAT('PCS', LPAD(id, 4, '0'))";
            if ($hasCurrency && $hasGstType) {
                // INR Without GST invoices use their own plain 0001 series.
                $numberExpr = "CASE
                    WHEN UPPER(currency) = 'INR' AND gst_type = 'without_gst' THEN LPAD(id, 4, '0')
                    ELSE CONCAT('PCS', LPAD(id, 4, '0'))
                END";
            }
            mysqli_query(
                $this->dbConnect,
                "UPDATE `{$tableOrder}` SET `invoice_no` = {$numberExpr}
                 WHERE `invoice_no` IS NULL OR TRIM(`invoice_no`) = ''"
            );
        }
    }
    private function getData($sqlQuery)
    {
        $result = mysqli_query($this->dbConnect, $sqlQuery);
        if (!$result) {
            trigger_error('Error in query: ' . mysqli_error($this->dbConnect) . ' SQL: ' . $sqlQuery, E_USER_WARNING);
            return [];
        }
        $data = [];
        while ($row = mysqli_fetch_array($result, MYSQLI_ASSOC)) {
            $data[] = $row;
        }
        return $data;
    }

    private function getNumRows($sqlQuery)
    {
        $result = mysqli_query($this->dbConnect, $sqlQuery);
        if (!$result) {
            trigger_error('Error in query: ' . mysqli_error($this->dbConnect) . ' SQL: ' . $sqlQuery, E_USER_WARNING);
            return 0;
        }
        return mysqli_num_rows($result);
    }

    private function getCurrentUserId()
    {
        return isset($_SESSION['userId']) ? intval($_SESSION['userId']) : 0;
    }

    // User login
    public function loginUsers($email, $password)
    {
        $sqlQuery = "
            SELECT id, email, first_name, last_name, address, mobile 
            FROM " . $this->invoiceUserTable . " 
            WHERE email='" . $email . "' AND password='" . md5($password) . "'";
        return $this->getData($sqlQuery);
    }

    // After Loggedin
    public function checkLoggedIn()
    {
        if (empty($_SESSION['userId'])) {
            header('Location:index.php');
            exit;
        }
    }

    private function normalizeCurrency($currency)
    {
        $currency = strtoupper(trim((string) $currency));
        return $currency === 'USD' ? 'USD' : 'INR';
    }

    private function normalizeGstType($gstType, $currency)
    {
        $gstType = trim((string) $gstType);
        if ($currency !== 'INR') {
            return 'without_gst';
        }
        return $gstType === 'without_gst' ? 'without_gst' : 'with_gst';
    }

    private function sanitizeString($value)
    {
        return mysqli_real_escape_string($this->dbConnect, trim((string) $value));
    }

    /** Parse form/DB amount; strips commas so "6,000" and 6000 both work. */
    private function parseAmount($value)
    {
        $cleaned = str_replace([',', ' '], '', trim((string) $value));
        if ($cleaned === '') {
            return 0.0;
        }
        return (float) $cleaned;
    }

    /** @return string[] */
    public function getInvoiceItemMonthChoices()
    {
        return [
            'January', 'February', 'March', 'April', 'May', 'June',
            'July', 'August', 'September', 'October', 'November', 'December',
        ];
    }

    private function normalizeItemMonth($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        $allowed = array_flip($this->getInvoiceItemMonthChoices());
        return isset($allowed[$value]) ? $value : '';
    }

    /** Normalize HTML date input (Y-m-d) for storage; invalid or empty uses today (local PHP date). */
    private function normalizeInvoiceDateForSave($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return date('Y-m-d');
        }
        $dt = DateTime::createFromFormat('Y-m-d', $raw);
        if ($dt instanceof DateTime && $dt->format('Y-m-d') === $raw) {
            return $raw;
        }
        return date('Y-m-d');
    }

    public function formatInvoiceNumber($sequenceNumber)
    {
        $sequenceNumber = max(0, intval($sequenceNumber));
        return 'PCS' . str_pad((string) $sequenceNumber, 4, '0', STR_PAD_LEFT);
    }

    /** INR Without GST invoices use a separate plain 0001, 0002, ... series. */
    public function formatWithoutGstInvoiceNumber($sequenceNumber)
    {
        $sequenceNumber = max(0, intval($sequenceNumber));
        return str_pad((string) $sequenceNumber, 4, '0', STR_PAD_LEFT);
    }

    private function usesWithoutGstNumberSeries($currency, $gstType)
    {
        return strtoupper(trim((string) $currency)) === 'INR'
            && trim((string) $gstType) === 'without_gst';
    }

    /**
     * Highest numeric sequence from invoice_order.invoice_no (not id, not COUNT).
     * Without-GST INR invoices are counted separately from the PCS series.
     */
    private function getMaxInvoiceSequenceNumber($currency = 'INR', $gstType = 'with_gst')
    {
        $table = $this->invoiceOrderTable;
        if (!$this->hasTableColumn($table, 'invoice_no')) {
            return 0;
        }

        if ($this->usesWithoutGstNumberSeries($currency, $gstType)) {
            if (
                !$this->hasTableColumn($table, 'currency')
                || !$this->hasTableColumn($table, 'gst_type')
            ) {
                return 0;
            }
            $sql = "SELECT MAX(CAST(invoice_no AS UNSIGNED)) AS last_invoice
                    FROM `{$table}`
                    WHERE UPPER(currency) = 'INR'
                      AND gst_type = 'without_gst'
                      AND invoice_no REGEXP '^[0-9]+$'";
        } else {
            $sql = "SELECT MAX(CAST(SUBSTRING(invoice_no, 4) AS UNSIGNED)) AS last_invoice
                    FROM `{$table}`
                    WHERE invoice_no LIKE 'PCS%'";
        }

        $result = mysqli_query($this->dbConnect, $sql);
        if (!$result) {
            trigger_error('Error in getMaxInvoiceSequenceNumber: ' . mysqli_error($this->dbConnect), E_USER_WARNING);
            return 0;
        }

        $row = mysqli_fetch_assoc($result);
        mysqli_free_result($result);

        return intval($row['last_invoice'] ?? 0);
    }

    /**
     * Next invoice number: PCS0049 for GST/USD invoices, plain 0001 for INR
     * Without GST invoices (separate series).
     */
    public function getNextInvoiceNumber($currency = 'INR', $gstType = 'with_gst')
    {
        $nextSequence = $this->getMaxInvoiceSequenceNumber($currency, $gstType) + 1;
        if ($this->usesWithoutGstNumberSeries($currency, $gstType)) {
            return $this->formatWithoutGstInvoiceNumber($nextSequence);
        }
        return $this->formatInvoiceNumber($nextSequence);
    }

    /** Display number from stored invoice_no only (never AUTO_INCREMENT id). */
    public function getDisplayInvoiceNumber(array $invoiceRow)
    {
        $stored = trim((string) ($invoiceRow['invoice_no'] ?? ''));
        if ($stored !== '') {
            return $stored;
        }
        return trim((string) ($invoiceRow['invoice_number'] ?? ''));
    }

    private function appendInvoiceNumber(array $invoiceRow)
    {
        $invoiceRow['invoice_number'] = $this->getDisplayInvoiceNumber($invoiceRow);
        return $invoiceRow;
    }

    /**
     * GST / subtotal line amount. Price is the source of truth when it is greater than
     * stored taxable_value (fixes rows saved as price=6000, taxable_value=6).
     */
    public function resolveItemTaxableAmount(array $item)
    {
        $price = round($this->parseAmount($item['price'] ?? 0), 2);
        $taxableValue = round($this->parseAmount($item['taxable_value'] ?? 0), 2);

        if ($price > 0 && ($taxableValue <= 0 || $taxableValue < $price)) {
            return $price;
        }
        if ($taxableValue > 0) {
            return $taxableValue;
        }
        return $price;
    }

    /** PDF Amount column — stored price. */
    public function resolveItemDisplayPrice(array $item)
    {
        return round($this->parseAmount($item['price'] ?? 0), 2);
    }

    private function parseInvoiceItems($POST)
    {
        $items = [];
        $productCodes = $POST['productCode'] ?? [];
        $productNames = $POST['productName'] ?? [];
        $prices = $POST['price'] ?? [];
        $taxableValues = $POST['taxableValue'] ?? [];
        $itemMonths = $POST['itemMonth'] ?? [];

        $rows = max(
            count($productCodes),
            count($productNames),
            count($prices),
            count($taxableValues),
            count($itemMonths)
        );
        for ($i = 0; $i < $rows; $i++) {
            $name = trim((string) ($productNames[$i] ?? ''));
            $code = trim((string) ($productCodes[$i] ?? ''));
            $price = $this->parseAmount($prices[$i] ?? 0);
            $taxableValue = $this->parseAmount($taxableValues[$i] ?? 0);
            // Price is source of truth for line total (prevents taxable_value=6 with price=6000).
            if ($price > 0) {
                $taxableValue = $price;
            } elseif ($taxableValue <= 0) {
                $taxableValue = 0;
            }
            $itemMonth = $this->normalizeItemMonth($itemMonths[$i] ?? '');

            if ($name === '' && $code === '' && $price <= 0 && $taxableValue <= 0) {
                continue;
            }

            $items[] = [
                'name' => $name,
                'hsn_sac' => $code,
                'price' => $price,
                'taxable_value' => $taxableValue,
                'item_month' => $itemMonth,
            ];
        }

        return $items;
    }

    private function calculateTotals($items, $currency, $gstType)
    {
        $rate = 0;
        foreach ($items as $item) {
            $rate += $this->resolveItemTaxableAmount($item);
        }

        $result = [
            'rate' => round($rate, 2),
            'cgst_tax' => 0,
            'sgst_tax' => 0,
            'cgst_amount' => 0,
            'sgst_amount' => 0,
            'total' => round($rate, 2),
        ];

        if ($currency === 'INR' && $gstType === 'with_gst') {
            $result['cgst_tax'] = 9;
            $result['sgst_tax'] = 9;
            $result['cgst_amount'] = round($rate * 0.09, 2);
            $result['sgst_amount'] = round($rate * 0.09, 2);
            $result['total'] = round($rate + $result['cgst_amount'] + $result['sgst_amount'], 2);
        }

        return $result;
    }

    // Create and Save 
    public function saveInvoice($POST)
    {
        $this->setLastDbError('');
        $this->ensureInvoiceSchema();
        $currency = $this->normalizeCurrency($POST['currency'] ?? 'INR');
        $gstType = $this->normalizeGstType($POST['gst_type'] ?? 'with_gst', $currency);

        $items = $this->parseInvoiceItems($POST);
        $totals = $this->calculateTotals($items, $currency, $gstType);

        $userId = intval($POST['userId'] ?? 0);
        $receiverName = $this->sanitizeString($POST['receiverName'] ?? '');
        $receiverAddress = $this->sanitizeString($POST['receiverAddress'] ?? '');
        $gstNo = $this->sanitizeString($POST['gstNo'] ?? '');
        $panNo = $this->sanitizeString($POST['panNo'] ?? '');
        $supply = $this->sanitizeString($POST['supply'] ?? '');
        $note = $this->sanitizeString($POST['notes'] ?? '');
        $invoiceDate = $this->sanitizeString($this->normalizeInvoiceDateForSave($POST['invoiceDate'] ?? ''));

        $cgstAmount = $totals['cgst_amount'] === null ? 'NULL' : $totals['cgst_amount'];
        $sgstAmount = $totals['sgst_amount'] === null ? 'NULL' : $totals['sgst_amount'];
        $cgstTax = $totals['cgst_tax'] === null ? 'NULL' : $totals['cgst_tax'];
        $sgstTax = $totals['sgst_tax'] === null ? 'NULL' : $totals['sgst_tax'];
        $vatTax = 'NULL';

        $insertColumns = [
            'user_id',
            'receiver_name',
            'receiver_address',
            'gst_no',
            'pan_no',
            'supply',
            'rate',
            'cgst_amount',
            'cgst_tax',
            'sgst_amount',
            'sgst_tax',
            'vat_tax',
            'total',
            'note',
        ];
        $insertValues = [
            "$userId",
            "'$receiverName'",
            "'$receiverAddress'",
            "'$gstNo'",
            "'$panNo'",
            "'$supply'",
            "{$totals['rate']}",
            "$cgstAmount",
            "$cgstTax",
            "$sgstAmount",
            "$sgstTax",
            "$vatTax",
            "{$totals['total']}",
            "'$note'",
        ];
        if ($this->hasTableColumn($this->invoiceOrderTable, 'currency')) {
            $insertColumns[] = 'currency';
            $insertValues[] = "'$currency'";
        }
        if ($this->hasTableColumn($this->invoiceOrderTable, 'gst_type')) {
            $insertColumns[] = 'gst_type';
            $insertValues[] = "'$gstType'";
        }
        if ($this->hasTableColumn($this->invoiceOrderTable, 'invoice_date')) {
            $insertColumns[] = 'invoice_date';
            $insertValues[] = "'$invoiceDate'";
        }
        if (!$this->hasTableColumn($this->invoiceOrderTable, 'invoice_no')) {
            $this->setLastDbError('Missing invoice_no column. Run check_db.php or save again after schema sync.');
            return false;
        }
        $nextInvoiceNo = $this->sanitizeString($this->getNextInvoiceNumber($currency, $gstType));
        $insertColumns[] = 'invoice_no';
        $insertValues[] = "'$nextInvoiceNo'";

        $sqlInsert = "INSERT INTO " . $this->invoiceOrderTable . " 
            (" . implode(', ', $insertColumns) . ")
            VALUES (" . implode(', ', $insertValues) . ")";

        if (!mysqli_query($this->dbConnect, $sqlInsert)) {
            $this->setLastDbError('Save failed: ' . mysqli_error($this->dbConnect));
            error_log('PCS Invoice saveInvoice error: ' . mysqli_error($this->dbConnect) . ' SQL=' . $sqlInsert);
            return false;
        }
        $lastInsertId = mysqli_insert_id($this->dbConnect);

        $receiverInitials = '';
        foreach (explode(' ', strtoupper($receiverName)) as $word) {
            if ($word !== '') {
                $receiverInitials .= $word[0];
            }
        }
        $receiverCode = $receiverInitials . str_pad($lastInsertId, 3, '0', STR_PAD_LEFT);

        $updateQuery = "UPDATE " . $this->invoiceOrderTable . " SET receiver_id = '$receiverCode' WHERE id = '$lastInsertId'";
        if (!mysqli_query($this->dbConnect, $updateQuery)) {
            $this->setLastDbError('Save failed while setting receiver code: ' . mysqli_error($this->dbConnect));
            error_log('PCS Invoice saveInvoice receiver_id update error: ' . mysqli_error($this->dbConnect));
            return false;
        }

        $monthColumn = $this->getInvoiceItemMonthColumnName();
        foreach ($items as $item) {
            $itemName = $this->sanitizeString($item['name']);
            $itemCode = $this->sanitizeString($item['hsn_sac']);
            $itemPrice = floatval($item['price']);
            $itemTaxable = floatval($item['taxable_value']);
            $itemMonth = $this->sanitizeString($item['item_month'] ?? '');

            $itemColumns = ['order_id', 'hsn_sac', 'name', 'price', 'taxable_value'];
            $itemValues = ["$lastInsertId", "'$itemCode'", "'$itemName'", "$itemPrice", "$itemTaxable"];
            if ($monthColumn !== '') {
                $itemColumns[] = $monthColumn;
                $itemValues[] = "'$itemMonth'";
            }
            $sqlInsertItem = "INSERT INTO " . $this->invoiceOrderItemTable . "
                (" . implode(', ', $itemColumns) . ")
                VALUES (" . implode(', ', $itemValues) . ")";
            if (!mysqli_query($this->dbConnect, $sqlInsertItem)) {
                $this->setLastDbError('Save failed while inserting item: ' . mysqli_error($this->dbConnect));
                error_log('PCS Invoice saveInvoice item insert error: ' . mysqli_error($this->dbConnect) . ' SQL=' . $sqlInsertItem);
                return false;
            }
        }
        return intval($lastInsertId);
    }

    // Edit and Update(Save)
    public function updateInvoice($POST)
    {
        $this->setLastDbError('');
        $this->ensureInvoiceSchema();
        if (empty($POST['invoiceId'])) {
            $this->setLastDbError('Missing invoice ID.');
            return false;
        }

        $invoiceId = intval($POST['invoiceId']);
        $currency = $this->normalizeCurrency($POST['currency'] ?? 'INR');
        $gstType = $this->normalizeGstType($POST['gst_type'] ?? 'with_gst', $currency);

        $items = $this->parseInvoiceItems($POST);
        $totals = $this->calculateTotals($items, $currency, $gstType);

        $userId = intval($POST['userId'] ?? 0);
        $receiverName = $this->sanitizeString($POST['receiverName'] ?? '');
        $receiverAddress = $this->sanitizeString($POST['receiverAddress'] ?? '');
        $gstNo = $this->sanitizeString($POST['gstNo'] ?? '');
        $panNo = $this->sanitizeString($POST['panNo'] ?? '');
        $supply = $this->sanitizeString($POST['supply'] ?? '');
        $note = $this->sanitizeString($POST['notes'] ?? '');
        $invoiceDate = $this->sanitizeString($this->normalizeInvoiceDateForSave($POST['invoiceDate'] ?? ''));

        $receiverInitials = '';
        foreach (explode(' ', strtoupper($receiverName)) as $word) {
            if ($word !== '') {
                $receiverInitials .= $word[0];
            }
        }
        $receiverId = $receiverInitials . str_pad($invoiceId, 3, '0', STR_PAD_LEFT);

        $cgstAmount = $totals['cgst_amount'] === null ? 'NULL' : $totals['cgst_amount'];
        $sgstAmount = $totals['sgst_amount'] === null ? 'NULL' : $totals['sgst_amount'];
        $cgstTax = $totals['cgst_tax'] === null ? 'NULL' : $totals['cgst_tax'];
        $sgstTax = $totals['sgst_tax'] === null ? 'NULL' : $totals['sgst_tax'];
        $vatTax = 'NULL';

        $setParts = [
            "user_id = $userId",
            "receiver_id = '$receiverId'",
            "receiver_name = '$receiverName'",
            "receiver_address = '$receiverAddress'",
            "gst_no = '$gstNo'",
            "pan_no = '$panNo'",
            "supply = '$supply'",
            "rate = {$totals['rate']}",
            "cgst_tax = $cgstTax",
            "sgst_tax = $sgstTax",
            "cgst_amount = $cgstAmount",
            "sgst_amount = $sgstAmount",
            "vat_tax = $vatTax",
            "total = {$totals['total']}",
            "note = '$note'",
        ];
        if ($this->hasTableColumn($this->invoiceOrderTable, 'currency')) {
            $setParts[] = "currency = '$currency'";
        }
        if ($this->hasTableColumn($this->invoiceOrderTable, 'gst_type')) {
            $setParts[] = "gst_type = '$gstType'";
        }
        if ($this->hasTableColumn($this->invoiceOrderTable, 'invoice_date')) {
            $setParts[] = "invoice_date = '$invoiceDate'";
        }

        $sqlUpdate = "UPDATE {$this->invoiceOrderTable} SET 
                " . implode(",\n                ", $setParts) . "
            WHERE id = $invoiceId";

        if (!mysqli_query($this->dbConnect, $sqlUpdate)) {
            $this->setLastDbError('Update failed: ' . mysqli_error($this->dbConnect));
            error_log('PCS Invoice updateInvoice order update error: ' . mysqli_error($this->dbConnect) . ' SQL=' . $sqlUpdate);
            return false;
        }

        $this->deleteInvoiceItems($invoiceId);

        $monthColumn = $this->getInvoiceItemMonthColumnName();
        foreach ($items as $item) {
            $itemName = $this->sanitizeString($item['name']);
            $itemCode = $this->sanitizeString($item['hsn_sac']);
            $itemPrice = floatval($item['price']);
            $itemTaxable = floatval($item['taxable_value']);
            $itemMonth = $this->sanitizeString($item['item_month'] ?? '');

            $itemColumns = ['order_id', 'name', 'hsn_sac', 'price', 'taxable_value'];
            $itemValues = ["$invoiceId", "'$itemName'", "'$itemCode'", "$itemPrice", "$itemTaxable"];
            if ($monthColumn !== '') {
                $itemColumns[] = $monthColumn;
                $itemValues[] = "'$itemMonth'";
            }
            $sqlInsertItem = "INSERT INTO {$this->invoiceOrderItemTable} 
                (" . implode(', ', $itemColumns) . ")
                VALUES (" . implode(', ', $itemValues) . ")";

            if (!mysqli_query($this->dbConnect, $sqlInsertItem)) {
                $this->setLastDbError('Item save failed: ' . mysqli_error($this->dbConnect));
                error_log('PCS Invoice updateInvoice item insert error: ' . mysqli_error($this->dbConnect) . ' SQL=' . $sqlInsertItem);
                return false;
            }
        }

        return true;
    }

    /** Shared WHERE clause for invoice list filters (month/date/currency/gst_type). */
    private function buildInvoiceListWhere($filters = [])
    {
        $userId = intval($this->getCurrentUserId());
        $whereClauses = ["user_id = '$userId'"];

        if (!empty($filters['month'])) {
            $month = intval($filters['month']);
            if ($month >= 1 && $month <= 12) {
                $whereClauses[] = "MONTH(created_at) = $month";
            }
        }

        if (!empty($filters['date'])) {
            $date = trim((string) $filters['date']);
            $dateObject = DateTime::createFromFormat('Y-m-d', $date);
            if ($dateObject && $dateObject->format('Y-m-d') === $date) {
                $whereClauses[] = "DATE(created_at) = '$date'";
            }
        }

        if (!empty($filters['currency'])) {
            $currency = strtoupper(trim((string) $filters['currency']));
            if (in_array($currency, ['INR', 'USD'], true)) {
                $whereClauses[] = "currency = '$currency'";
            }
        }

        if (!empty($filters['gst_type']) && (!empty($filters['currency']) && strtoupper((string) $filters['currency']) === 'INR')) {
            $gstType = strtolower(trim((string) $filters['gst_type']));
            if (in_array($gstType, ['with_gst', 'without_gst'], true)) {
                $whereClauses[] = "gst_type = '$gstType'";
            }
        }

        return implode(' AND ', $whereClauses);
    }

    public function getInvoiceList($filters = [], $page = 1, $perPage = 6)
    {
        $where = $this->buildInvoiceListWhere($filters);

        // Get total count
        $countQuery = "SELECT COUNT(*) as total FROM {$this->invoiceOrderTable} WHERE $where";
        $countResult = $this->getData($countQuery);
        $total = $countResult[0]['total'] ?? 0;

        // Get paged data
        $offset = ($page - 1) * $perPage;
        $sqlQuery = "SELECT * FROM {$this->invoiceOrderTable} WHERE $where ORDER BY created_at DESC LIMIT $offset, $perPage";
        $invoices = $this->getData($sqlQuery);
        foreach ($invoices as $index => $row) {
            $invoices[$index] = $this->appendInvoiceNumber($row);
        }

        return [
            'invoices' => $invoices,
            'total' => $total,
            'totalPages' => ceil($total / $perPage),
            'currentPage' => $page
        ];
    }

    /** All invoices matching the active list filters, in list order (for Excel export). */
    public function getInvoicesForExport($filters = [])
    {
        $where = $this->buildInvoiceListWhere($filters);
        $sqlQuery = "SELECT * FROM {$this->invoiceOrderTable} WHERE $where ORDER BY created_at DESC, id DESC";
        $invoices = $this->getData($sqlQuery);
        foreach ($invoices as $index => $row) {
            $invoices[$index] = $this->appendInvoiceNumber($row);
        }
        return $invoices;
    }

    /**
     * Distinct HSN/SAC codes for each invoice, keyed by order id.
     * Codes keep the order the items were entered in.
     */
    public function getHsnSacMapForInvoices($invoiceIds)
    {
        $ids = [];
        foreach ((array) $invoiceIds as $invoiceId) {
            $invoiceId = intval($invoiceId);
            if ($invoiceId > 0) {
                $ids[$invoiceId] = $invoiceId;
            }
        }
        if (empty($ids)) {
            return [];
        }

        $idList = implode(',', array_values($ids));
        $sqlQuery = "SELECT order_id, hsn_sac FROM {$this->invoiceOrderItemTable} 
            WHERE order_id IN ($idList) ORDER BY order_id ASC, id ASC";
        $rows = $this->getData($sqlQuery);

        $map = [];
        foreach ($rows as $row) {
            $orderId = intval($row['order_id'] ?? 0);
            $code = trim((string) ($row['hsn_sac'] ?? ''));
            if ($orderId <= 0 || $code === '') {
                continue;
            }
            if (!isset($map[$orderId])) {
                $map[$orderId] = [];
            }
            if (!in_array($code, $map[$orderId], true)) {
                $map[$orderId][] = $code;
            }
        }

        $result = [];
        foreach ($map as $orderId => $codes) {
            $result[$orderId] = implode(', ', $codes);
        }
        return $result;
    }
    
    public function getInvoice($invoiceId)
    {
        $userId = $this->getCurrentUserId();
        $invoiceId = intval($invoiceId);
        if ($userId === 0 || $invoiceId === 0) {
            return [];
        }

        $sqlQuery = "SELECT * FROM {$this->invoiceOrderTable} WHERE user_id = '$userId' AND id = '$invoiceId'";
        $result = mysqli_query($this->dbConnect, $sqlQuery);
        if (!$result) {
            trigger_error('Error in getInvoice: ' . mysqli_error($this->dbConnect), E_USER_WARNING);
            return [];
        }
        $invoice = mysqli_fetch_array($result, MYSQLI_ASSOC) ?: [];
        return $this->appendInvoiceNumber($invoice);
    }
    
    public function getInvoiceItems($invoiceId)
    {
        $monthColumn = $this->getInvoiceItemMonthColumnName();
        if ($monthColumn !== '') {
            $sqlQuery = "SELECT *, {$monthColumn} AS item_month FROM {$this->invoiceOrderItemTable} WHERE order_id = '$invoiceId' ORDER BY id ASC";
        } else {
            $sqlQuery = "SELECT *, '' AS item_month FROM {$this->invoiceOrderItemTable} WHERE order_id = '$invoiceId' ORDER BY id ASC";
        }
        return $this->getData($sqlQuery);
    }

    public function deleteInvoiceItems($invoiceId)
    {
        $invoiceId = intval($invoiceId);
        $sqlQuery = "DELETE FROM {$this->invoiceOrderItemTable} WHERE order_id = '$invoiceId'";
        $result = mysqli_query($this->dbConnect, $sqlQuery);
        if (!$result) {
            die("Delete Error: " . mysqli_error($this->dbConnect));
        }
    }
    
    public function deleteInvoice($invoiceId)
    {
        $invoiceId = intval($invoiceId);
        $this->deleteInvoiceItems($invoiceId);
        $sqlQuery = "DELETE FROM {$this->invoiceOrderTable} WHERE id = '$invoiceId'";
        $result = mysqli_query($this->dbConnect, $sqlQuery);
        return $result ? 1 : 0;
    }

    // 📊 For Dashboard 
    public function getTotalInvoices()
    {
        $userId = $this->getCurrentUserId();
        $sql = "SELECT COUNT(*) as total FROM {$this->invoiceOrderTable} WHERE user_id = '$userId'";
        $result = mysqli_query($this->dbConnect, $sql);
        if (!$result) {
            trigger_error('Error in getTotalInvoices: ' . mysqli_error($this->dbConnect), E_USER_WARNING);
            return 0;
        }
        return intval(mysqli_fetch_assoc($result)['total'] ?? 0);
    }

    public function getTotalCustomers()
    {
        $userId = $this->getCurrentUserId();
        $sql = "SELECT COUNT(DISTINCT receiver_name) as total_customers FROM {$this->invoiceOrderTable} WHERE user_id = '$userId'";
        $result = mysqli_query($this->dbConnect, $sql);
        if (!$result) {
            trigger_error('Error in getTotalCustomers: ' . mysqli_error($this->dbConnect), E_USER_WARNING);
            return 0;
        }
        return intval(mysqli_fetch_assoc($result)['total_customers'] ?? 0);
    }

    public function getTotalBilledAmount()
    {
        $userId = $this->getCurrentUserId();
        $sql = "SELECT SUM(total) as total_billed FROM {$this->invoiceOrderTable} WHERE user_id = '$userId'";
        $result = mysqli_query($this->dbConnect, $sql);
        if (!$result) {
            trigger_error('Error in getTotalBilledAmount: ' . mysqli_error($this->dbConnect), E_USER_WARNING);
            return 0;
        }
        return floatval(mysqli_fetch_assoc($result)['total_billed'] ?? 0);
    }

    public function getInvoicesByCurrency()
    {
        $userId = $this->getCurrentUserId();
        $currencyStats = ['INR' => 0, 'USD' => 0];
        $sql = "SELECT currency, COUNT(*) as count FROM {$this->invoiceOrderTable} WHERE user_id = '$userId' GROUP BY currency";
        $result = mysqli_query($this->dbConnect, $sql);
        if (!$result) {
            trigger_error('Error in getInvoicesByCurrency: ' . mysqli_error($this->dbConnect), E_USER_WARNING);
            return $currencyStats;
        }
        while ($row = mysqli_fetch_assoc($result)) {
            $key = strtoupper(trim((string)($row['currency'] ?? '')));
            if ($key === 'USD' || $key === 'INR') {
                $currencyStats[$key] = intval($row['count']);
            }
        }
        return $currencyStats;
    }

    public function getRecentInvoices($limit = 3)
    {
        $userId = $this->getCurrentUserId();
        $limit = intval($limit);
        if ($limit <= 0) {
            $limit = 3;
        }
        $sql = "SELECT * FROM {$this->invoiceOrderTable} WHERE user_id = '$userId' ORDER BY created_at DESC LIMIT $limit";
        $invoices = $this->getData($sql);
        foreach ($invoices as $index => $row) {
            $invoices[$index] = $this->appendInvoiceNumber($row);
        }
        return $invoices;
    }

    public function getTopReceivers($limit = 3)
    {
        $userId = $this->getCurrentUserId();
        $limit = intval($limit);
        if ($limit <= 0) {
            $limit = 3;
        }
        $sql = "SELECT receiver_name, COUNT(*) as count FROM {$this->invoiceOrderTable} WHERE user_id = '$userId' GROUP BY receiver_name ORDER BY count DESC LIMIT $limit";
        return $this->getData($sql);
    }

    public function getClientList($search = '', $page = 1, $perPage = 10)
    {
        $userId = $this->getCurrentUserId();
        if ($userId <= 0) {
            return [
                'clients' => [],
                'total' => 0,
                'totalPages' => 1,
                'currentPage' => 1
            ];
        }

        $page = max(1, intval($page));
        $perPage = max(1, intval($perPage));
        $search = trim((string) $search);
        $hasSearch = $search !== '';
        $searchLike = '%' . $search . '%';

        $uniqueExpr = "CASE
            WHEN TRIM(IFNULL(pan_no, '')) <> '' THEN CONCAT('PAN:', UPPER(TRIM(pan_no)))
            ELSE CONCAT(
                'ALT:',
                UPPER(TRIM(IFNULL(receiver_name, ''))), '|',
                UPPER(TRIM(IFNULL(gst_no, ''))), '|',
                UPPER(TRIM(IFNULL(receiver_address, '')))
            )
        END";

        $baseWhere = "user_id = ?";
        if ($hasSearch) {
            $baseWhere .= " AND (
                receiver_name LIKE ?
                OR gst_no LIKE ?
                OR pan_no LIKE ?
            )";
        }

        $countSql = "SELECT COUNT(*) AS total_clients FROM (
                SELECT 1
                FROM {$this->invoiceOrderTable}
                WHERE $baseWhere
                GROUP BY $uniqueExpr
            ) dedup";

        $countStmt = mysqli_prepare($this->dbConnect, $countSql);
        if (!$countStmt) {
            return [
                'clients' => [],
                'total' => 0,
                'totalPages' => 1,
                'currentPage' => 1
            ];
        }

        if ($hasSearch) {
            mysqli_stmt_bind_param($countStmt, "isss", $userId, $searchLike, $searchLike, $searchLike);
        } else {
            mysqli_stmt_bind_param($countStmt, "i", $userId);
        }

        mysqli_stmt_execute($countStmt);
        mysqli_stmt_bind_result($countStmt, $totalClients);
        mysqli_stmt_fetch($countStmt);
        mysqli_stmt_close($countStmt);

        $total = intval($totalClients ?? 0);
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;

        $listSql = "SELECT io.receiver_name, io.receiver_address, io.gst_no, io.pan_no, io.created_at
            FROM {$this->invoiceOrderTable} io
            INNER JOIN (
                SELECT MAX(id) AS latest_id
                FROM {$this->invoiceOrderTable}
                WHERE $baseWhere
                GROUP BY $uniqueExpr
            ) dedup ON dedup.latest_id = io.id
            ORDER BY io.created_at DESC, io.id DESC
            LIMIT ?, ?";

        $listStmt = mysqli_prepare($this->dbConnect, $listSql);
        if (!$listStmt) {
            return [
                'clients' => [],
                'total' => $total,
                'totalPages' => $totalPages,
                'currentPage' => $page
            ];
        }

        if ($hasSearch) {
            mysqli_stmt_bind_param($listStmt, "isssii", $userId, $searchLike, $searchLike, $searchLike, $offset, $perPage);
        } else {
            mysqli_stmt_bind_param($listStmt, "iii", $userId, $offset, $perPage);
        }

        mysqli_stmt_execute($listStmt);
        mysqli_stmt_bind_result($listStmt, $receiverName, $receiverAddress, $gstNo, $panNo, $createdAt);

        $clients = [];
        while (mysqli_stmt_fetch($listStmt)) {
            $clients[] = [
                'receiver_name' => (string) $receiverName,
                'receiver_address' => (string) $receiverAddress,
                'gst_no' => (string) $gstNo,
                'pan_no' => (string) $panNo,
                'created_at' => (string) $createdAt
            ];
        }
        mysqli_stmt_close($listStmt);

        return [
            'clients' => $clients,
            'total' => $total,
            'totalPages' => $totalPages,
            'currentPage' => $page
        ];
    }

    public function getGraphData($days = 10)
    {
        $userId = $this->getCurrentUserId();
        $days = intval($days);
        if ($days <= 0) {
            $days = 10;
        }
        $sql = "SELECT DATE(created_at) as date, SUM(total) as total FROM {$this->invoiceOrderTable} WHERE user_id = '$userId' GROUP BY DATE(created_at) ORDER BY date DESC LIMIT $days";
        return $this->getData($sql);
    }

    public function getCustomerSuggestions($searchTerm, $limit = 8)
    {
        $userId = $this->getCurrentUserId();
        if ($userId <= 0) {
            return [];
        }

        $searchTerm = trim((string) $searchTerm);
        if ($searchTerm === '') {
            return [];
        }

        $limit = intval($limit);
        if ($limit <= 0 || $limit > 20) {
            $limit = 8;
        }

        $like = '%' . $searchTerm . '%';
        $clientKeyExpr = "CASE
                            WHEN TRIM(COALESCE(pan_no, '')) <> '' THEN CONCAT('PAN:', UPPER(TRIM(pan_no)))
                            WHEN TRIM(COALESCE(gst_no, '')) <> '' THEN CONCAT('GST:', UPPER(TRIM(gst_no)))
                            WHEN TRIM(COALESCE(receiver_address, '')) <> '' THEN CONCAT('ADDR:', LOWER(TRIM(receiver_address)))
                            ELSE CONCAT('ROW:', id)
                          END";

        $sql = "SELECT io.receiver_name, io.receiver_address, io.gst_no, io.pan_no, io.supply
                FROM {$this->invoiceOrderTable} io
                INNER JOIN (
                    SELECT $clientKeyExpr AS client_key, MAX(id) AS latest_id
                    FROM {$this->invoiceOrderTable}
                    WHERE user_id = ? AND receiver_name LIKE ? AND receiver_name <> ''
                    GROUP BY client_key
                ) latest ON latest.latest_id = io.id
                ORDER BY io.receiver_name ASC, io.id DESC
                LIMIT $limit";

        $stmt = mysqli_prepare($this->dbConnect, $sql);
        if (!$stmt) {
            return [];
        }

        mysqli_stmt_bind_param($stmt, "is", $userId, $like);
        if (!mysqli_stmt_execute($stmt)) {
            mysqli_stmt_close($stmt);
            return [];
        }

        if (!mysqli_stmt_bind_result($stmt, $receiverName, $receiverAddress, $gstNo, $panNo, $supply)) {
            mysqli_stmt_close($stmt);
            return [];
        }

        $customers = [];
        while (mysqli_stmt_fetch($stmt)) {
            $customers[] = [
                'receiver_name' => trim((string) $receiverName),
                'receiver_address' => (string) $receiverAddress,
                'gst_no' => (string) $gstNo,
                'pan_no' => (string) $panNo,
                'supply' => (string) $supply
            ];
        }

        mysqli_stmt_close($stmt);
        return $customers;
    }
}
?>