<?php
/**
 * Quotation management backend.
 *
 * Shares the central mysqli connection from db.php with Invoice.php.
 * All queries that touch user input use prepared statements.
 */
require_once __DIR__ . '/db.php';

class Quotation
{
    private $quotationTable = 'quotation';
    private $quotationItemTable = 'quotation_item';
    private $invoiceOrderTable = 'invoice_order';
    private $invoiceOrderItemTable = 'invoice_order_item';
    private $dbConnect = false;

    public const STATUSES = ['Draft', 'Sent'];
    public const LIST_STATUSES = ['Draft', 'Sent', 'Converted'];

    public function __construct()
    {
        global $c;
        $this->dbConnect = $c;
        if (!$this->dbConnect instanceof mysqli) {
            throw new RuntimeException('Unable to connect to the database.');
        }
        // Keep "today" checks and NOW() timestamps on India Standard Time.
        if (!$this->dbConnect->query("SET time_zone = '+05:30'")) {
            error_log('PCS Quotation: could not set database timezone — ' . $this->dbConnect->error);
        }
    }

    public function getConnection(): mysqli
    {
        return $this->dbConnect;
    }

    /* ---------------------------------------------------------------------
     * Small helpers
     * ------------------------------------------------------------------ */

    private function currentUserId(): int
    {
        return isset($_SESSION['userId']) ? intval($_SESSION['userId']) : 0;
    }

    private function esc(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function statuses(): array
    {
        return self::STATUSES;
    }

    public static function listStatuses(): array
    {
        return self::LIST_STATUSES;
    }

    public static function formatQuotationDateTime(array $quotation): string
    {
        $timezone = new DateTimeZone('Asia/Kolkata');
        $quotationDate = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            substr((string) ($quotation['quotation_date'] ?? ''), 0, 10),
            $timezone
        );
        if ($quotationDate === false) {
            return '—';
        }

        $createdAt = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            (string) ($quotation['created_at'] ?? ''),
            $timezone
        );
        if ($createdAt === false) {
            return $quotationDate->format('d M Y');
        }

        return $quotationDate->format('d M Y') . ', ' . $createdAt->format('h:i A') . ' IST';
    }

    /**
     * Escape a value for HTML output. Kept here so every quotation page uses
     * exactly the same escaping rules.
     */
    public static function escape($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Build display-friendly item rows from submitted form arrays. Used when a
     * validation error occurs so the customer does not lose their input.
     */
    public static function itemsFromPost(array $post): array
    {
        $names = $post['product_name'] ?? [];
        $descriptions = $post['product_description'] ?? [];
        $quantities = $post['quantity'] ?? [];
        $unitPrices = $post['unit_price'] ?? [];
        $discounts = $post['discount'] ?? [];
        $taxPercents = $post['tax_percent'] ?? [];

        $rowCount = max(
            is_array($names) ? count($names) : 0,
            is_array($descriptions) ? count($descriptions) : 0,
            is_array($quantities) ? count($quantities) : 0,
            is_array($unitPrices) ? count($unitPrices) : 0,
            is_array($discounts) ? count($discounts) : 0,
            is_array($taxPercents) ? count($taxPercents) : 0
        );

        $items = [];
        for ($i = 0; $i < $rowCount; $i++) {
            $item = [
                'product_name' => trim((string) ($names[$i] ?? '')),
                'product_description' => trim((string) ($descriptions[$i] ?? '')),
                'quantity' => trim((string) ($quantities[$i] ?? '')),
                'unit_price' => trim((string) ($unitPrices[$i] ?? '')),
                'discount' => trim((string) ($discounts[$i] ?? '')),
                'tax_percent' => trim((string) ($taxPercents[$i] ?? '')),
            ];

            if (implode('', $item) === '') {
                continue;
            }
            $items[] = $item;
        }

        return $items;
    }

    public static function csrfToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['quotation_csrf'])) {
            $_SESSION['quotation_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['quotation_csrf'];
    }

    public static function validateCsrf(?string $token): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        return !empty($_SESSION['quotation_csrf'])
            && is_string($token)
            && hash_equals($_SESSION['quotation_csrf'], $token);
    }

    private function bindParams(mysqli_stmt $stmt, string $types, array $params): void
    {
        if ($types === '') {
            return;
        }
        $values = [];
        $values[] = $types;
        foreach ($params as $key => $value) {
            $values[] = $value;
        }
        $stmt->bind_param(...$values);
    }

    private function fetchAll(mysqli_stmt $stmt): array
    {
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }

    private function fetchOne(mysqli_stmt $stmt): array
    {
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        return $row ?: [];
    }

    /* ---------------------------------------------------------------------
     * Number generation and validation
     * ------------------------------------------------------------------ */

    public function generateQuotationNumber(bool $forceNew = false): string
    {
        if (!$forceNew && !empty($_SESSION['quotation_draft_number'])) {
            return $_SESSION['quotation_draft_number'];
        }

        $baseSequence = $this->getMaxQuotationSequenceNumber();
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $candidate = $this->formatQuotationNumber($baseSequence + $attempt + 1);
            $stmt = $this->dbConnect->prepare(
                "SELECT id FROM {$this->quotationTable} WHERE quotation_no = ? LIMIT 1"
            );
            $stmt->bind_param('s', $candidate);
            $stmt->execute();
            $existing = $this->fetchOne($stmt);
            $stmt->close();
            if (empty($existing)) {
                if (!$forceNew) {
                    $_SESSION['quotation_draft_number'] = $candidate;
                }
                return $candidate;
            }
        }

        // Extremely unlikely fallback.
        return $this->formatQuotationNumber($baseSequence + 51);
    }

    public function formatQuotationNumber(int $sequenceNumber): string
    {
        $sequenceNumber = max(1, $sequenceNumber);
        return 'PCS-QTN-' . str_pad((string) $sequenceNumber, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Highest numeric sequence from quotation_no values stored in the
     * PCS-QTN-0001 format (old QTN-... numbers are ignored).
     */
    private function getMaxQuotationSequenceNumber(): int
    {
        $prefixLength = strlen('PCS-QTN-');
        $sql = "SELECT MAX(CAST(SUBSTRING(quotation_no, " . ($prefixLength + 1) . ") AS UNSIGNED)) AS last_quotation
                FROM {$this->quotationTable}
                WHERE quotation_no LIKE 'PCS-QTN-%'";
        $result = $this->dbConnect->query($sql);
        if (!$result) {
            return 0;
        }
        $row = $result->fetch_assoc();
        $result->free();

        return intval($row['last_quotation'] ?? 0);
    }

    public function isNumberUnique(string $number, int $ignoreId = 0): bool
    {
        $userId = $this->currentUserId();
        $stmt = $this->dbConnect->prepare(
            "SELECT id FROM {$this->quotationTable} WHERE quotation_no = ? AND user_id = ? AND id <> ? LIMIT 1"
        );
        $stmt->bind_param('sii', $number, $userId, $ignoreId);
        $stmt->execute();
        $found = $this->fetchOne($stmt);
        $stmt->close();
        return empty($found);
    }

    private function validDate(string $date): bool
    {
        $dt = DateTime::createFromFormat('Y-m-d', $date);
        return $dt instanceof DateTime && $dt->format('Y-m-d') === $date;
    }

    private function normalisePhone(string $phone): string
    {
        return trim($phone);
    }

    /**
     * Normalise a customer phone number into WhatsApp friendly digits.
     * Returns '' when the number is not usable.
     */
    public static function formatWhatsAppNumber(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if ($digits === '') {
            return '';
        }
        if (strlen($digits) === 10) {
            return '91' . $digits;
        }
        if (strlen($digits) === 11 && $digits[0] === '0') {
            return '91' . substr($digits, 1);
        }
        if (strlen($digits) >= 11 && strlen($digits) <= 15) {
            return $digits;
        }
        return '';
    }

    /**
     * Validate and normalise the submitted form. Totals are always
     * recalculated here - posted totals are never trusted.
     *
     * @return array{data:array,items:array,errors:array}
     */
    public function preparePayload(array $post, bool $isUpdate = false, int $ignoreId = 0): array
    {
        $errors = [];

        $customerName = trim((string) ($post['customer_name'] ?? ''));
        if ($customerName === '') {
            $errors[] = 'Customer name is required.';
        } elseif (mb_strlen($customerName) > 150) {
            $errors[] = 'Customer name must not exceed 150 characters.';
        }

        $customerPhone = $this->normalisePhone((string) ($post['customer_phone'] ?? ''));
        if ($customerPhone !== '' && !preg_match('/^[0-9+\-\s().]{6,20}$/', $customerPhone)) {
            $errors[] = 'Customer phone number is not valid.';
        }

        $customerEmail = trim((string) ($post['customer_email'] ?? ''));
        if ($customerEmail !== '' && !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Customer email address is not valid.';
        }

        $customerAddress = trim((string) ($post['customer_address'] ?? ''));
        if (mb_strlen($customerAddress) > 1000) {
            $errors[] = 'Customer address is too long.';
        }

        $quotationDate = trim((string) ($post['quotation_date'] ?? ''));
        if (!$this->validDate($quotationDate)) {
            $errors[] = 'A valid quotation date is required.';
            $quotationDate = date('Y-m-d');
        }

        $validUntil = trim((string) ($post['valid_until'] ?? ''));
        if (!$this->validDate($validUntil)) {
            $errors[] = 'A valid "valid until" date is required.';
            $validUntil = $quotationDate;
        } elseif ($validUntil < $quotationDate) {
            $errors[] = '"Valid until" cannot be earlier than the quotation date.';
        }

        $notes = trim((string) ($post['notes'] ?? ''));
        if (mb_strlen($notes) > 5000) {
            $errors[] = 'Notes are too long.';
        }

        $terms = trim((string) ($post['terms_conditions'] ?? ''));
        if (mb_strlen($terms) > 5000) {
            $errors[] = 'Terms and conditions are too long.';
        }

        $status = trim((string) ($post['status'] ?? 'Draft'));
        // A newly created quotation always starts as Draft.
        $allowedCreateStatuses = ['Draft'];
        if (!in_array($status, self::STATUSES, true)
            || (!$isUpdate && !in_array($status, $allowedCreateStatuses, true))) {
            $status = 'Draft';
        }

        $quotationNo = trim((string) ($post['quotation_no'] ?? ''));
        if ($quotationNo === '' || !preg_match('/^[A-Za-z0-9\-\/]{3,50}$/', $quotationNo)) {
            $quotationNo = $this->generateQuotationNumber(true);
        } elseif (!$this->isNumberUnique($quotationNo, $ignoreId)) {
            if ($isUpdate) {
                $errors[] = 'That quotation number is already in use.';
            } else {
                // The create form shows a read-only, auto-generated number.
                // If it was used by another tab in the meantime, silently
                // issue a fresh one instead of trapping the user.
                $quotationNo = $this->generateQuotationNumber(true);
            }
        }

        $items = [];
        $names = $post['product_name'] ?? [];
        $descriptions = $post['product_description'] ?? [];
        $quantities = $post['quantity'] ?? [];
        $unitPrices = $post['unit_price'] ?? [];
        $discounts = $post['discount'] ?? [];

        // Currency and GST follow the same rules as invoice creation.
        // USD quotations are always Without GST.
        $currency = strtoupper(trim((string) ($post['currency'] ?? 'INR'))) === 'USD' ? 'USD' : 'INR';
        $gstType = ((string) ($post['gst_type'] ?? 'with_gst')) === 'without_gst'
            ? 'without_gst'
            : 'with_gst';
        if ($currency !== 'INR') {
            $gstType = 'without_gst';
        }
        // Per-item rows keep the combined GST rate for reference only.
        $itemTaxRate = $gstType === 'with_gst' ? 18.0 : 0.0;

        $rowCount = max(
            is_array($names) ? count($names) : 0,
            is_array($quantities) ? count($quantities) : 0,
            is_array($unitPrices) ? count($unitPrices) : 0
        );

        for ($i = 0; $i < $rowCount; $i++) {
            $name = trim((string) ($names[$i] ?? ''));
            $description = trim((string) ($descriptions[$i] ?? ''));
            $rawQty = trim((string) ($quantities[$i] ?? ''));
            $rawPrice = trim((string) ($unitPrices[$i] ?? ''));
            $rawDiscount = trim((string) ($discounts[$i] ?? '0'));

            $emptyQuantity = $rawQty === '' || (is_numeric($rawQty) && floatval($rawQty) === 0.0);
            $emptyPrice = $rawPrice === '' || (is_numeric($rawPrice) && floatval($rawPrice) === 0.0);
            $emptyDiscount = $rawDiscount === '' || (is_numeric($rawDiscount) && floatval($rawDiscount) === 0.0);
            if ($name === '' && $description === '' && $emptyQuantity && $emptyPrice && $emptyDiscount) {
                continue;
            }

            if ($name === '') {
                $errors[] = 'Every item row needs a product or service name.';
            }
            if ($rawQty !== '' && (!is_numeric($rawQty) || floatval($rawQty) < 0)) {
                $errors[] = 'Quantity must be zero or greater for all items.';
            }
            if ($rawPrice !== '' && (!is_numeric($rawPrice) || floatval($rawPrice) < 0)) {
                $errors[] = 'Unit price must be zero or greater for all items.';
            }
            if ($rawDiscount !== '' && (!is_numeric($rawDiscount) || floatval($rawDiscount) < 0)) {
                $errors[] = 'Discount must be zero or greater for all items.';
            }

            // Round the stored values first so line totals, subtotal and tax
            // always agree with exactly what is written to the database.
            $qty = is_numeric($rawQty) ? round(floatval($rawQty), 2) : 0.0;
            $price = is_numeric($rawPrice) ? round(floatval($rawPrice), 2) : 0.0;
            $gross = round(($qty > 0 ? $qty : 1) * $price, 2);
            $discount = is_numeric($rawDiscount) ? round(floatval($rawDiscount), 2) : 0.0;

            if ($discount > $gross) {
                $errors[] = 'Discount cannot be greater than the item amount.';
            }

            $lineTotal = round(max(0, $gross - $discount), 2);
            // Per-item tax is informational only now; totals use the single
            // GST choice below, exactly like invoice creation.
            $itemTaxAmount = round($lineTotal * ($itemTaxRate / 100), 2);

            if (mb_strlen($name) > 255) {
                $errors[] = 'Product or service name is too long.';
            }
            if (mb_strlen($description) > 2000) {
                $errors[] = 'Product description is too long.';
            }

            $items[] = [
                'product_name' => $name,
                'product_description' => $description,
                'quantity' => $qty,
                'unit_price' => $price,
                'discount' => $discount,
                'tax_percent' => $itemTaxRate,
                'line_total' => $lineTotal,
                'tax_amount' => $itemTaxAmount,
            ];
        }

        if (empty($items)) {
            $errors[] = 'Add at least one product or service.';
        }

        $subtotal = 0.0;
        $totalDiscount = 0.0;
        foreach ($items as $item) {
            // line_total already has the discount removed; add it back so
            // subtotal - total_discount equals the sum of the line totals.
            $subtotal += $item['line_total'] + $item['discount'];
            $totalDiscount += $item['discount'];
        }
        $subtotal = round($subtotal, 2);
        $totalDiscount = round($totalDiscount, 2);
        $taxableTotal = round($subtotal - $totalDiscount, 2);
        $cgstAmount = $gstType === 'with_gst' ? round($taxableTotal * 0.09, 2) : 0.0;
        $sgstAmount = $cgstAmount;
        $taxAmount = round($cgstAmount + $sgstAmount, 2);
        $grandTotal = round($taxableTotal + $taxAmount, 2);

        if ($grandTotal < 0) {
            $errors[] = 'The quotation total cannot be negative.';
        }

        $data = [
            'quotation_no' => $quotationNo,
            'customer_name' => $customerName,
            'customer_phone' => $customerPhone,
            'customer_email' => $customerEmail,
            'customer_address' => $customerAddress,
            'quotation_date' => $quotationDate,
            'valid_until' => $validUntil,
            'currency' => $currency,
            'gst_type' => $gstType,
            'subtotal' => $subtotal,
            'total_discount' => $totalDiscount,
            'tax_amount' => $taxAmount,
            'grand_total' => $grandTotal,
            'notes' => $notes,
            'terms_conditions' => $terms,
            'status' => $status,
        ];

        return ['data' => $data, 'items' => $items, 'errors' => $errors];
    }

    /* ---------------------------------------------------------------------
     * Create / update / delete
     * ------------------------------------------------------------------ */

    public function saveQuotation(array $post): int
    {
        $userId = $this->currentUserId();
        if ($userId <= 0) {
            throw new RuntimeException('Your session has expired. Please log in again.');
        }

        $prepared = $this->preparePayload($post);
        if (!empty($prepared['errors'])) {
            throw new InvalidArgumentException(implode(' ', $prepared['errors']));
        }

        $data = $prepared['data'];
        $items = $prepared['items'];

        $this->dbConnect->begin_transaction();
        try {
            $stmt = $this->dbConnect->prepare(
                "INSERT INTO {$this->quotationTable}
                (quotation_no, user_id, customer_name, customer_phone, customer_email, customer_address,
                 quotation_date, valid_until, currency, gst_type, subtotal, total_discount, tax_amount,
                 grand_total, notes, terms_conditions, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param(
                'sissssssssddddsss',
                $data['quotation_no'],
                $userId,
                $data['customer_name'],
                $data['customer_phone'],
                $data['customer_email'],
                $data['customer_address'],
                $data['quotation_date'],
                $data['valid_until'],
                $data['currency'],
                $data['gst_type'],
                $data['subtotal'],
                $data['total_discount'],
                $data['tax_amount'],
                $data['grand_total'],
                $data['notes'],
                $data['terms_conditions'],
                $data['status']
            );
            if (!$stmt->execute()) {
                throw new RuntimeException('Could not save the quotation.');
            }
            $quotationId = $stmt->insert_id;
            $stmt->close();

            $this->insertItems($quotationId, $items);

            $this->dbConnect->commit();
            unset($_SESSION['quotation_draft_number']);
            return $quotationId;
        } catch (Throwable $e) {
            $this->dbConnect->rollback();
            throw $e;
        }
    }

    public function updateQuotation(int $quotationId, array $post): bool
    {
        $userId = $this->currentUserId();
        $existing = $this->getQuotation($quotationId);
        if (empty($existing)) {
            throw new InvalidArgumentException('Quotation not found.');
        }
        if (!empty($existing['is_converted']) || !empty($existing['converted_invoice_id'])) {
            throw new InvalidArgumentException('Converted quotations cannot be edited.');
        }

        $prepared = $this->preparePayload($post, true, $quotationId);
        if (!empty($prepared['errors'])) {
            throw new InvalidArgumentException(implode(' ', $prepared['errors']));
        }

        $data = $prepared['data'];
        $items = $prepared['items'];

        $this->dbConnect->begin_transaction();
        try {
            $stmt = $this->dbConnect->prepare(
                "UPDATE {$this->quotationTable} SET
                    quotation_no = ?, customer_name = ?, customer_phone = ?, customer_email = ?,
                    customer_address = ?, quotation_date = ?, valid_until = ?, currency = ?, gst_type = ?,
                    subtotal = ?, total_discount = ?, tax_amount = ?, grand_total = ?, notes = ?,
                    terms_conditions = ?, status = ?
                 WHERE id = ? AND user_id = ?"
            );
            $stmt->bind_param(
                'sssssssssddddsssii',
                $data['quotation_no'],
                $data['customer_name'],
                $data['customer_phone'],
                $data['customer_email'],
                $data['customer_address'],
                $data['quotation_date'],
                $data['valid_until'],
                $data['currency'],
                $data['gst_type'],
                $data['subtotal'],
                $data['total_discount'],
                $data['tax_amount'],
                $data['grand_total'],
                $data['notes'],
                $data['terms_conditions'],
                $data['status'],
                $quotationId,
                $userId
            );
            if (!$stmt->execute()) {
                throw new RuntimeException('Could not update the quotation.');
            }
            $stmt->close();

            $deleteStmt = $this->dbConnect->prepare(
                "DELETE FROM {$this->quotationItemTable} WHERE quotation_id = ?"
            );
            $deleteStmt->bind_param('i', $quotationId);
            if (!$deleteStmt->execute()) {
                throw new RuntimeException('Could not update the quotation items.');
            }
            $deleteStmt->close();

            $this->insertItems($quotationId, $items);

            $this->dbConnect->commit();
            return true;
        } catch (Throwable $e) {
            $this->dbConnect->rollback();
            throw $e;
        }
    }

    private function insertItems(int $quotationId, array $items): void
    {
        $stmt = $this->dbConnect->prepare(
            "INSERT INTO {$this->quotationItemTable}
            (quotation_id, product_name, product_description, quantity, unit_price, discount, tax_percent, line_total)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        foreach ($items as $item) {
            $stmt->bind_param(
                'issddddd',
                $quotationId,
                $item['product_name'],
                $item['product_description'],
                $item['quantity'],
                $item['unit_price'],
                $item['discount'],
                $item['tax_percent'],
                $item['line_total']
            );
            if (!$stmt->execute()) {
                throw new RuntimeException('Could not save quotation items.');
            }
        }
        $stmt->close();
    }

    public function deleteQuotation(int $quotationId): bool
    {
        $userId = $this->currentUserId();
        $quotation = $this->getQuotation($quotationId);
        if (empty($quotation) || intval($quotation['user_id']) !== $userId) {
            return false;
        }
        if (!empty($quotation['is_converted']) || !empty($quotation['converted_invoice_id'])) {
            return false;
        }

        $this->dbConnect->begin_transaction();
        try {
            $stmt = $this->dbConnect->prepare(
                "DELETE FROM {$this->quotationItemTable} WHERE quotation_id = ?"
            );
            $stmt->bind_param('i', $quotationId);
            $stmt->execute();
            $stmt->close();

            $stmt = $this->dbConnect->prepare(
                "DELETE FROM {$this->quotationTable} WHERE id = ? AND user_id = ?"
            );
            $stmt->bind_param('ii', $quotationId, $userId);
            $stmt->execute();
            $deleted = $stmt->affected_rows > 0;
            $stmt->close();

            $this->dbConnect->commit();
            return $deleted;
        } catch (Throwable $e) {
            $this->dbConnect->rollback();
            throw $e;
        }
    }

    /* ---------------------------------------------------------------------
     * Read operations
     * ------------------------------------------------------------------ */

    public function getQuotationList(array $filters = [], int $page = 1, int $perPage = 10): array
    {
        $userId = $this->currentUserId();
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        $where = ['user_id = ?'];
        $types = 'i';
        $params = [$userId];

        $month = intval($filters['month'] ?? 0);
        if ($month >= 1 && $month <= 12) {
            $where[] = 'MONTH(quotation_date) = ?';
            $types .= 'i';
            $params[] = $month;
        }

        $date = trim((string) ($filters['date'] ?? ''));
        if ($date !== '' && $this->validDate($date)) {
            $where[] = 'quotation_date = ?';
            $types .= 's';
            $params[] = $date;
        }

        $currency = strtoupper(trim((string) ($filters['currency'] ?? '')));
        if (in_array($currency, ['INR', 'USD'], true)) {
            $where[] = 'currency = ?';
            $types .= 's';
            $params[] = $currency;
        }

        $gstType = strtolower(trim((string) ($filters['gst_type'] ?? '')));
        if ($currency === 'INR' && in_array($gstType, ['with_gst', 'without_gst'], true)) {
            $where[] = 'gst_type = ?';
            $types .= 's';
            $params[] = $gstType;
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status === 'Converted') {
            $where[] = 'converted_invoice_id IS NOT NULL';
        } elseif (in_array($status, self::STATUSES, true)) {
            if ($status === 'Draft') {
                $where[] = "status = 'Draft' AND converted_invoice_id IS NULL";
            } else {
                $where[] = "(status <> 'Draft' OR status IS NULL) AND converted_invoice_id IS NULL";
            }
        }

        $whereSql = implode(' AND ', $where);

        $countStmt = $this->dbConnect->prepare(
            "SELECT COUNT(*) AS total FROM {$this->quotationTable} WHERE {$whereSql}"
        );
        $this->bindParams($countStmt, $types, $params);
        $countStmt->execute();
        $countRow = $this->fetchOne($countStmt);
        $countStmt->close();
        $total = intval($countRow['total'] ?? 0);

        $totalPages = (int) ceil($total / $perPage);
        if ($totalPages > 0 && $page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;

        $listStmt = $this->dbConnect->prepare(
            "SELECT * FROM {$this->quotationTable}
             WHERE {$whereSql}
             ORDER BY quotation_date DESC, id DESC
             LIMIT ? OFFSET ?"
        );
        $listTypes = $types . 'ii';
        $listParams = array_merge($params, [$perPage, $offset]);
        $this->bindParams($listStmt, $listTypes, $listParams);
        $listStmt->execute();
        $quotations = $this->fetchAll($listStmt);
        $listStmt->close();
        foreach ($quotations as &$quotation) {
            $quotation = $this->normalizeLifecycleStatus($quotation);
        }
        unset($quotation);

        return [
            'quotations' => $quotations,
            'total' => $total,
            'totalPages' => $totalPages,
            'currentPage' => $page,
            'perPage' => $perPage,
        ];
    }

    public function getQuotation(int $quotationId): array
    {
        $userId = $this->currentUserId();
        $quotationId = intval($quotationId);
        if ($userId <= 0 || $quotationId <= 0) {
            return [];
        }
        $stmt = $this->dbConnect->prepare(
            "SELECT * FROM {$this->quotationTable} WHERE id = ? AND user_id = ? LIMIT 1"
        );
        $stmt->bind_param('ii', $quotationId, $userId);
        $stmt->execute();
        $row = $this->fetchOne($stmt);
        $stmt->close();

        return $this->normalizeLifecycleStatus($row);
    }

    private function normalizeLifecycleStatus(array $quotation): array
    {
        if (empty($quotation)) {
            return $quotation;
        }

        $storedStatus = (string) ($quotation['status'] ?? 'Draft');
        $quotation['is_converted'] = $storedStatus === 'Converted'
            || !empty($quotation['converted_invoice_id']);
        $quotation['is_accepted'] = $storedStatus === 'Accepted'
            || !empty($quotation['accepted_at']);
        $quotation['is_rejected'] = $storedStatus === 'Rejected'
            || !empty($quotation['rejected_at']);
        $quotation['is_expired'] = $storedStatus === 'Expired'
            || (!$quotation['is_converted']
                && !$quotation['is_accepted']
                && !$quotation['is_rejected']
                && !empty($quotation['valid_until'])
                && $quotation['valid_until'] < date('Y-m-d'));
        $quotation['status'] = $quotation['is_converted']
            ? 'Converted'
            : ($storedStatus === 'Draft' ? 'Draft' : 'Sent');

        return $quotation;
    }

    public function getQuotationItems(int $quotationId): array
    {
        $stmt = $this->dbConnect->prepare(
            "SELECT * FROM {$this->quotationItemTable} WHERE quotation_id = ? ORDER BY id ASC"
        );
        $stmt->bind_param('i', $quotationId);
        $stmt->execute();
        $items = $this->fetchAll($stmt);
        $stmt->close();
        return $items;
    }

    /**
     * Latest saved details for each customer this user has quoted or
     * invoiced before. Used only to power the customer-name suggestion
     * dropdown. Quotation details win when the same name exists in both
     * sources because invoice records do not store phone/email.
     */
    public function getCustomerSuggestions(int $limit = 100): array
    {
        $userId = $this->currentUserId();
        if ($userId <= 0) {
            return [];
        }
        $limit = max(1, min(200, $limit));

        $stmt = $this->dbConnect->prepare(
            "SELECT q.customer_name, q.customer_phone, q.customer_email, q.customer_address
             FROM {$this->quotationTable} q
             INNER JOIN (
                 SELECT customer_name, MAX(id) AS latest_id
                 FROM {$this->quotationTable}
                 WHERE user_id = ? AND TRIM(customer_name) <> ''
                 GROUP BY customer_name
             ) latest ON latest.latest_id = q.id
             WHERE q.user_id = ?
             ORDER BY q.id DESC
             LIMIT ?"
        );
        $stmt->bind_param('iii', $userId, $userId, $limit);
        $stmt->execute();
        $quotationCustomers = $this->fetchAll($stmt);
        $stmt->close();

        $invoiceStmt = $this->dbConnect->prepare(
            "SELECT i.receiver_name AS customer_name, i.receiver_address AS customer_address
             FROM {$this->invoiceOrderTable} i
             INNER JOIN (
                 SELECT receiver_name, MAX(id) AS latest_id
                 FROM {$this->invoiceOrderTable}
                 WHERE user_id = ? AND TRIM(receiver_name) <> ''
                 GROUP BY receiver_name
             ) latest ON latest.latest_id = i.id
             WHERE i.user_id = ?
             ORDER BY i.id DESC
             LIMIT ?"
        );
        $invoiceStmt->bind_param('iii', $userId, $userId, $limit);
        $invoiceStmt->execute();
        $invoiceCustomers = $this->fetchAll($invoiceStmt);
        $invoiceStmt->close();

        $customers = [];
        $byName = [];
        foreach ($quotationCustomers as $customer) {
            $key = mb_strtolower(trim((string) ($customer['customer_name'] ?? '')));
            if ($key === '') {
                continue;
            }
            $customers[] = [
                'customer_name' => $customer['customer_name'] ?? '',
                'customer_phone' => $customer['customer_phone'] ?? '',
                'customer_email' => $customer['customer_email'] ?? '',
                'customer_address' => $customer['customer_address'] ?? '',
            ];
            $byName[$key] = true;
        }

        foreach ($invoiceCustomers as $customer) {
            $key = mb_strtolower(trim((string) ($customer['customer_name'] ?? '')));
            if ($key === '' || isset($byName[$key])) {
                continue;
            }
            $customers[] = [
                'customer_name' => $customer['customer_name'] ?? '',
                'customer_phone' => '',
                'customer_email' => '',
                'customer_address' => $customer['customer_address'] ?? '',
            ];
            $byName[$key] = true;
        }

        return array_slice($customers, 0, $limit);
    }

    public function getLatestInvoiceCustomerDetails(string $customerName): array
    {
        $userId = $this->currentUserId();
        $customerName = trim($customerName);
        if ($userId <= 0 || $customerName === '') {
            return [];
        }

        $stmt = $this->dbConnect->prepare(
            "SELECT receiver_address, gst_no, pan_no, supply
             FROM {$this->invoiceOrderTable}
             WHERE user_id = ? AND LOWER(TRIM(receiver_name)) = LOWER(TRIM(?))
             ORDER BY id DESC
             LIMIT 1"
        );
        $stmt->bind_param('is', $userId, $customerName);
        $stmt->execute();
        $details = $this->fetchOne($stmt);
        $stmt->close();

        return $details;
    }

    /* ---------------------------------------------------------------------
     * Secure customer response links
     * ------------------------------------------------------------------ */

    /**
     * Return the quotation's persistent public token, creating it once.
     * The token is only ever exposed through the authenticated email/share
     * flow and the customer-facing response page.
     */
    public function ensurePublicToken(int $quotationId): string
    {
        $userId = $this->currentUserId();
        if ($userId <= 0 || $quotationId <= 0) {
            throw new RuntimeException('Quotation not found.');
        }

        $stmt = $this->dbConnect->prepare(
            "SELECT public_token FROM {$this->quotationTable} WHERE id = ? AND user_id = ? LIMIT 1"
        );
        $stmt->bind_param('ii', $quotationId, $userId);
        $stmt->execute();
        $row = $this->fetchOne($stmt);
        $stmt->close();

        if (empty($row)) {
            throw new InvalidArgumentException('Quotation not found.');
        }
        if (!empty($row['public_token']) && preg_match('/^[a-f0-9]{64}$/', (string) $row['public_token'])) {
            return (string) $row['public_token'];
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $token = bin2hex(random_bytes(32));
            try {
                $update = $this->dbConnect->prepare(
                    "UPDATE {$this->quotationTable}
                     SET public_token = ?
                     WHERE id = ? AND user_id = ?
                       AND (public_token IS NULL OR public_token = '')"
                );
                $update->bind_param('sii', $token, $quotationId, $userId);
                $update->execute();
                $updated = $update->affected_rows;
                $update->close();
            } catch (Throwable $exception) {
                // A token collision is practically impossible; retry with a
                // fresh value instead of failing the email outright.
                continue;
            }

            if ($updated > 0) {
                return $token;
            }

            // Another request may have stored a token first.
            $check = $this->dbConnect->prepare(
                "SELECT public_token FROM {$this->quotationTable} WHERE id = ? AND user_id = ? LIMIT 1"
            );
            $check->bind_param('ii', $quotationId, $userId);
            $check->execute();
            $existing = $this->fetchOne($check);
            $check->close();
            if (!empty($existing['public_token'])
                && preg_match('/^[a-f0-9]{64}$/', (string) $existing['public_token'])) {
                return (string) $existing['public_token'];
            }
        }

        throw new RuntimeException('Could not create a secure quotation link.');
    }

    /**
     * Fetch a quotation by its public token. This is the only read method
     * that deliberately does not require a logged-in user id.
     */
    public function getQuotationByToken(string $token): array
    {
        $token = strtolower(trim($token));
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return [];
        }

        $stmt = $this->dbConnect->prepare(
            "SELECT * FROM {$this->quotationTable} WHERE public_token = ? LIMIT 1"
        );
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $row = $this->fetchOne($stmt);
        $stmt->close();

        if (empty($row)) {
            return [];
        }

        return $this->normalizeLifecycleStatus($row);
    }

    /**
     * Apply an explicit customer accept/reject decision.
     *
     * @return array{ok:bool,message:string,already?:bool,status?:string}
     */
    public function respondToQuotation(string $token, string $decision, string $reason = ''): array
    {
        $token = strtolower(trim($token));
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return ['ok' => false, 'message' => 'This quotation link is invalid or has expired.'];
        }

        $decision = strtolower(trim($decision));
        if (!in_array($decision, ['accept', 'reject'], true)) {
            return ['ok' => false, 'message' => 'Please choose whether to accept or reject this quotation.'];
        }

        $reason = trim($reason);
        if (mb_strlen($reason) > 1000) {
            return ['ok' => false, 'message' => 'The rejection reason must not exceed 1000 characters.'];
        }

        $this->dbConnect->begin_transaction();
        try {
            $stmt = $this->dbConnect->prepare(
                "SELECT * FROM {$this->quotationTable} WHERE public_token = ? LIMIT 1 FOR UPDATE"
            );
            $stmt->bind_param('s', $token);
            $stmt->execute();
            $quotation = $this->fetchOne($stmt);
            $stmt->close();

            if (empty($quotation)) {
                $this->dbConnect->rollback();
                return ['ok' => false, 'message' => 'This quotation link is invalid or has expired.'];
            }

            $status = (string) $quotation['status'];
            if ($status === 'Converted' || !empty($quotation['converted_invoice_id'])) {
                $this->dbConnect->rollback();
                return ['ok' => false, 'message' => 'This quotation has already been converted and can no longer be changed.'];
            }
            if ($status === 'Accepted' || !empty($quotation['accepted_at'])) {
                $this->dbConnect->rollback();
                return [
                    'ok' => false,
                    'already' => true,
                    'status' => 'Accepted',
                    'message' => 'This quotation has already been accepted.',
                ];
            }
            if ($status === 'Rejected' || !empty($quotation['rejected_at'])) {
                $this->dbConnect->rollback();
                return [
                    'ok' => false,
                    'already' => true,
                    'status' => 'Rejected',
                    'message' => 'This quotation has already been rejected.',
                ];
            }

            $isExpired = $status === 'Expired'
                || (!empty($quotation['valid_until']) && $quotation['valid_until'] < date('Y-m-d'));

            if ($isExpired) {
                $this->dbConnect->commit();
                return [
                    'ok' => false,
                    'status' => 'Expired',
                    'message' => 'This quotation expired on '
                        . date('d M Y', strtotime((string) $quotation['valid_until']))
                        . ' and can no longer be accepted or rejected.',
                ];
            }

            if ($status !== 'Sent') {
                $this->dbConnect->rollback();
                return ['ok' => false, 'message' => 'Only sent quotations can be accepted or rejected.'];
            }

            if ($decision === 'accept') {
                $update = $this->dbConnect->prepare(
                    "UPDATE {$this->quotationTable}
                     SET accepted_at = NOW(),
                         rejected_at = NULL, rejection_reason = NULL
                     WHERE id = ? AND status = 'Sent'"
                );
                $update->bind_param('i', $quotation['id']);
                $update->execute();
                $changed = $update->affected_rows;
                $update->close();

                if ($changed <= 0) {
                    $this->dbConnect->rollback();
                    return ['ok' => false, 'message' => 'This quotation was updated by someone else. Please refresh the link.'];
                }
                $this->dbConnect->commit();
                return [
                    'ok' => true,
                    'status' => 'Accepted',
                    'message' => 'Thank you. The quotation has been accepted successfully.',
                ];
            }

            $update = $this->dbConnect->prepare(
                "UPDATE {$this->quotationTable}
                 SET rejected_at = NOW(), rejection_reason = ?,
                     accepted_at = NULL
                 WHERE id = ? AND status = 'Sent'"
            );
            $update->bind_param('si', $reason, $quotation['id']);
            $update->execute();
            $changed = $update->affected_rows;
            $update->close();

            if ($changed <= 0) {
                $this->dbConnect->rollback();
                return ['ok' => false, 'message' => 'This quotation was updated by someone else. Please refresh the link.'];
            }
            $this->dbConnect->commit();
            return [
                'ok' => true,
                'status' => 'Rejected',
                'message' => 'Thank you. The quotation has been rejected successfully.',
            ];
        } catch (Throwable $exception) {
            $this->dbConnect->rollback();
            throw $exception;
        }
    }

    /** Mark a successfully emailed, still-valid quotation as Sent. */
    public function recordEmailSent(int $quotationId): bool
    {
        $userId = $this->currentUserId();
        if ($userId <= 0 || $quotationId <= 0) {
            return false;
        }

        $stmt = $this->dbConnect->prepare(
            "UPDATE {$this->quotationTable}
             SET status = 'Sent'
             WHERE id = ? AND user_id = ?
               AND status IN ('Draft', 'Sent')
               AND converted_invoice_id IS NULL
               AND valid_until >= CURDATE()"
        );
        $stmt->bind_param('ii', $quotationId, $userId);
        $stmt->execute();
        $stmt->close();
        return true;
    }

    public static function publicBaseUrl(): string
    {
        $configured = trim((string) getenv('APP_URL'));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        // Reuse the project-wide base URL logic so localhost subfolders and
        // live hosting both produce the correct share/accept links.
        require_once __DIR__ . '/app_config.php';
        return rtrim(base_url(), '/');
    }

    /**
     * Send a quotation email using the existing SMTP configuration.
     * Returns an array so both the AJAX share action and the create page can
     * reuse exactly the same sending behaviour.
     *
     * @return array{ok:bool,message:string}
     */
    public function sendQuotationEmail(int $quotationId, string $toEmail = ''): array
    {
        $userId = $this->currentUserId();
        if ($userId <= 0) {
            return ['ok' => false, 'message' => 'Your session has expired. Please log in again.'];
        }

        $quotation = $this->getQuotation($quotationId);
        if (empty($quotation)) {
            return ['ok' => false, 'message' => 'Quotation not found.'];
        }
        if (!empty($quotation['is_converted']) || !empty($quotation['converted_invoice_id'])) {
            return ['ok' => false, 'message' => 'Converted quotations cannot be emailed.'];
        }
        if (!empty($quotation['accepted_at'])) {
            return ['ok' => false, 'message' => 'An accepted quotation cannot be resent.'];
        }
        if (!empty($quotation['valid_until']) && (string) $quotation['valid_until'] < date('Y-m-d')) {
            return ['ok' => false, 'message' => 'This quotation has expired. Update its valid-until date before resending.'];
        }

        $toEmail = trim($toEmail);
        if ($toEmail === '') {
            $toEmail = trim((string) ($quotation['customer_email'] ?? ''));
        }
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'Please provide a valid customer email address.'];
        }

        require_once __DIR__ . '/mail_config.php';
        if (SMTP_USER === '' || SMTP_PASSWORD === '') {
            return ['ok' => false, 'message' => 'Email is not configured. Please set the SMTP settings and try again.'];
        }

        $items = $this->getQuotationItems($quotationId);
        if (empty($items)) {
            return ['ok' => false, 'message' => 'This quotation has no items to email.'];
        }

        $subject = 'Quotation ' . (string) ($quotation['quotation_no'] ?? '') . ' from ' . SMTP_FROM_NAME;

        try {
            require_once __DIR__ . '/quotation_pdf_generator.php';
            $quotationPdfHtml = buildQuotationHtml($quotation, $items);
            if ($quotationPdfHtml === '') {
                return ['ok' => false, 'message' => 'Unable to generate the quotation PDF.'];
            }

            $dompdf = createInvoiceDompdf();
            $dompdf->loadHtml($quotationPdfHtml);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();
            $pdfBytes = $dompdf->output();
            if ($pdfBytes === '') {
                return ['ok' => false, 'message' => 'Unable to generate the quotation PDF.'];
            }
        } catch (\Throwable $exception) {
            return ['ok' => false, 'message' => 'Unable to generate the quotation PDF.'];
        }

        try {
            require_once __DIR__ . '/vendor/autoload.php';
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = SMTP_HOST;
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USER;
            $mail->Password = SMTP_PASSWORD;
            $mail->SMTPSecure = SMTP_SECURE;
            $mail->Port = SMTP_PORT;
            $mail->SMTPAutoTLS = true;
            $mail->CharSet = 'UTF-8';
            $mail->setFrom(SMTP_USER, SMTP_FROM_NAME);
            $mail->addAddress($toEmail);
            $mail->Subject = $subject;
            // The customer should receive only the quotation PDF attachment.
            // No HTML summary and no Accept/Reject buttons in the body.
            $mail->AllowEmpty = true;
            $mail->Body = '';
            $safeNumber = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) ($quotation['quotation_no'] ?? ''));
            if ($safeNumber === '') {
                $safeNumber = 'Quotation-' . $quotationId;
            }
            $mail->addStringAttachment(
                $pdfBytes,
                'Quotation-' . $safeNumber . '.pdf',
                'base64',
                'application/pdf'
            );
            $mail->send();
        } catch (\PHPMailer\PHPMailer\Exception $exception) {
            return ['ok' => false, 'message' => 'Unable to send the quotation email. Please check the email configuration and try again.'];
        } catch (\Throwable $exception) {
            return ['ok' => false, 'message' => 'Unable to send the quotation email. Please check the email configuration and try again.'];
        }

        // Rejected or expired quotations are reopened only after the email
        // succeeds; customer responses are accepted only while the quote is Sent.
        $this->recordEmailSent($quotationId);

        // A successful resend clears the previous rejection so the quotation is
        // treated as Sent again and the customer can respond.
        $clearRejection = $this->dbConnect->prepare(
            "UPDATE {$this->quotationTable}
             SET rejected_at = NULL, rejection_reason = NULL
             WHERE id = ? AND user_id = ?
               AND accepted_at IS NULL
               AND converted_invoice_id IS NULL"
        );
        $clearRejection->bind_param('ii', $quotationId, $userId);
        $clearRejection->execute();
        $clearRejection->close();

        return ['ok' => true, 'message' => 'Quotation email sent successfully.'];
    }

    /**
     * Resend a rejected or expired quotation. An expired validity date is
     * extended by 15 days first so the resent quotation can be accepted again.
     */
    public function resendQuotationEmail(int $quotationId, string $toEmail = ''): array
    {
        $userId = $this->currentUserId();
        if ($userId <= 0) {
            return ['ok' => false, 'message' => 'Your session has expired. Please log in again.'];
        }

        $quotation = $this->getQuotation($quotationId);
        if (empty($quotation)) {
            return ['ok' => false, 'message' => 'Quotation not found.'];
        }
        if (!empty($quotation['is_converted']) || !empty($quotation['converted_invoice_id'])) {
            return ['ok' => false, 'message' => 'Converted quotations cannot be emailed.'];
        }
        if (!empty($quotation['accepted_at'])) {
            return ['ok' => false, 'message' => 'An accepted quotation cannot be resent.'];
        }

        $extendedUntil = '';
        if (!empty($quotation['is_expired'])) {
            $extendedUntil = date('Y-m-d', strtotime('+15 days'));
            $update = $this->dbConnect->prepare(
                "UPDATE {$this->quotationTable}
                 SET valid_until = ?
                 WHERE id = ? AND user_id = ?
                   AND accepted_at IS NULL
                   AND converted_invoice_id IS NULL"
            );
            $update->bind_param('sii', $extendedUntil, $quotationId, $userId);
            $update->execute();
            $update->close();
        }

        $result = $this->sendQuotationEmail($quotationId, $toEmail);
        if (!empty($result['ok']) && $extendedUntil !== '') {
            $result['message'] = 'Quotation resent successfully. Validity extended to '
                . date('d M Y', strtotime($extendedUntil)) . '.';
        }

        return $result;
    }

    public function buildQuotationEmailHtml(array $quotation, array $items, string $acceptUrl, string $rejectUrl): string
    {
        $e = static function ($value): string {
            return self::escape($value);
        };
        $currency = (string) ($quotation['currency'] ?? 'INR');
        $symbol = self::currencySymbol($currency);
        $quotationDate = self::formatQuotationDateTime($quotation);
        $validUntil = !empty($quotation['valid_until'])
            ? date('d M Y', strtotime((string) $quotation['valid_until'])) : '';

        $showQuantityColumn = false;
        foreach ($items as $item) {
            if ((float) ($item['quantity'] ?? 0) > 0) {
                $showQuantityColumn = true;
                break;
            }
        }

        $rows = '';
        foreach ($items as $index => $item) {
            $rows .= '<tr>'
                . '<td style="padding:8px;border:1px solid #e2e8f0;text-align:center;">' . ($index + 1) . '</td>'
                . '<td style="padding:8px;border:1px solid #e2e8f0;">'
                . '<strong>' . $e($item['product_name'] ?? '') . '</strong>'
                . '</td>';
            if ($showQuantityColumn) {
                $rows .= '<td style="padding:8px;border:1px solid #e2e8f0;text-align:center;">'
                    . rtrim(rtrim(number_format((float) ($item['quantity'] ?? 0), 2, '.', ''), '0'), '.') . '</td>';
            }
            $rows .= '<td style="padding:8px;border:1px solid #e2e8f0;text-align:right;">'
                . $e($symbol) . ' ' . number_format((float) ($item['unit_price'] ?? 0), 2) . '</td>'
                . '<td style="padding:8px;border:1px solid #e2e8f0;text-align:right;">'
                . $e($symbol) . ' ' . number_format((float) ($item['discount'] ?? 0), 2) . '</td>'
                . '<td style="padding:8px;border:1px solid #e2e8f0;text-align:right;">'
                . $e($symbol) . ' ' . number_format((float) ($item['line_total'] ?? 0), 2) . '</td>'
                . '</tr>';
        }

        $terms = trim((string) ($quotation['terms_conditions'] ?? ''));
        if ($terms === '') {
            $terms = 'Thanks for doing business with us!';
        }

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8">'
            . '<title>Quotation ' . $e($quotation['quotation_no'] ?? '') . '</title></head>'
            . '<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#1e293b;">'
            . '<div style="max-width:720px;margin:0 auto;padding:24px;">'
            . '<div style="background:#1f4e79;color:#ffffff;padding:20px 24px;border-radius:8px 8px 0 0;">'
            . '<h1 style="margin:0;font-size:22px;">Quotation ' . $e($quotation['quotation_no'] ?? '') . '</h1>'
            . '<p style="margin:6px 0 0;font-size:14px;">Pavitha Consultancy Services OPC Pvt Ltd.</p>'
            . '</div>'
            . '<div style="background:#ffffff;padding:24px;border:1px solid #e2e8f0;border-top:0;border-radius:0 0 8px 8px;">'
            . '<p style="margin:0 0 16px;font-size:15px;">Dear ' . $e($quotation['customer_name'] ?? '') . ',</p>'
            . '<p style="margin:0 0 18px;font-size:14px;line-height:1.6;">Please review the following quotation. '
            . 'You can accept or reject it using the buttons at the end of this email.</p>'
            . '<table style="width:100%;border-collapse:collapse;font-size:14px;margin-bottom:18px;">'
            . '<tr><td style="padding:5px 0;color:#64748b;width:35%;">Customer</td><td style="padding:5px 0;">' . $e($quotation['customer_name'] ?? '') . '</td></tr>'
            . '<tr><td style="padding:5px 0;color:#64748b;">Email</td><td style="padding:5px 0;">' . $e($quotation['customer_email'] ?? '') . '</td></tr>'
            . '<tr><td style="padding:5px 0;color:#64748b;">Phone</td><td style="padding:5px 0;">' . $e($quotation['customer_phone'] ?? '') . '</td></tr>'
            . '<tr><td style="padding:5px 0;color:#64748b;">Quotation Date &amp; Time</td><td style="padding:5px 0;">' . $e($quotationDate) . '</td></tr>'
            . '<tr><td style="padding:5px 0;color:#64748b;">Valid Until</td><td style="padding:5px 0;">' . $e($validUntil) . '</td></tr>'
            . '<tr><td style="padding:5px 0;color:#64748b;">Amount</td><td style="padding:5px 0;font-weight:bold;font-size:16px;">'
            . $e($symbol) . ' ' . number_format((float) ($quotation['grand_total'] ?? 0), 2) . '</td></tr>'
            . '</table>'
            . '<h2 style="font-size:16px;margin:0 0 8px;color:#1f4e79;">Quotation Details</h2>'
            . '<table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:18px;">'
            . '<thead><tr style="background:#1f4e79;color:#ffffff;">'
            . '<th style="padding:8px;border:1px solid #1f4e79;">#</th>'
            . '<th style="padding:8px;border:1px solid #1f4e79;text-align:left;">Description</th>'
            . ($showQuantityColumn ? '<th style="padding:8px;border:1px solid #1f4e79;">Qty</th>' : '')
            . '<th style="padding:8px;border:1px solid #1f4e79;">Unit Price</th>'
            . '<th style="padding:8px;border:1px solid #1f4e79;">Discount</th>'
            . '<th style="padding:8px;border:1px solid #1f4e79;">Line Total</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>'
            . '<table style="width:100%;border-collapse:collapse;font-size:14px;margin-left:auto;max-width:320px;">'
            . '<tr><td style="padding:4px 0;">Subtotal</td><td style="padding:4px 0;text-align:right;">' . $e($symbol) . ' ' . number_format((float) ($quotation['subtotal'] ?? 0), 2) . '</td></tr>'
            . '<tr><td style="padding:4px 0;">Total Discount</td><td style="padding:4px 0;text-align:right;">' . $e($symbol) . ' ' . number_format((float) ($quotation['total_discount'] ?? 0), 2) . '</td></tr>';

        $emailTaxAmount = round((float) ($quotation['tax_amount'] ?? 0), 2);
        if ($emailTaxAmount > 0) {
            $emailCgst = round($emailTaxAmount / 2, 2);
            $emailSgst = round($emailTaxAmount - $emailCgst, 2);
            $html .= '<tr><td style="padding:4px 0;">CGST (9%)</td><td style="padding:4px 0;text-align:right;">' . $e($symbol) . ' ' . number_format($emailCgst, 2) . '</td></tr>'
                . '<tr><td style="padding:4px 0;">SGST (9%)</td><td style="padding:4px 0;text-align:right;">' . $e($symbol) . ' ' . number_format($emailSgst, 2) . '</td></tr>';
        }

        $html .= '<tr><td style="padding:8px 0;font-weight:bold;border-top:2px solid #1f4e79;">Grand Total</td>'
            . '<td style="padding:8px 0;font-weight:bold;text-align:right;border-top:2px solid #1f4e79;">' . $e($symbol) . ' ' . number_format((float) ($quotation['grand_total'] ?? 0), 2) . '</td></tr>'
            . '</table>';

        $html .= '<div style="margin-top:14px;"><strong style="color:#1f4e79;">Terms &amp; Conditions</strong><div style="font-size:13px;line-height:1.6;">' . nl2br($e($terms)) . '</div></div>'
            . '<div style="margin-top:14px;"><strong style="color:#1f4e79;">Bank Details</strong><div style="font-size:13px;line-height:1.6;">';
        foreach (self::bankDetails(
            (string) ($quotation['gst_type'] ?? 'with_gst'),
            (string) ($quotation['currency'] ?? 'INR')
        ) as $bankLabel => $bankValue) {
            $html .= '<div><strong>' . $e($bankLabel) . ':</strong> ' . $e($bankValue) . '</div>';
        }
        $html .= '</div></div>';

        $html .= '<div style="margin-top:26px;padding:18px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">'
            . '<h2 style="font-size:16px;margin:0 0 8px;color:#1f4e79;">Your Response</h2>'
            . '<p style="margin:0 0 14px;font-size:14px;line-height:1.6;">Please confirm your decision. This quotation is valid until '
            . $e($validUntil) . '.</p>'
            . '<p style="margin:0 0 14px;">'
            . '<a href="' . $e($acceptUrl) . '" style="display:inline-block;background:#198754;color:#ffffff;text-decoration:none;padding:11px 20px;border-radius:6px;font-weight:bold;margin:0 8px 8px 0;">Accept Quotation</a>'
            . '<a href="' . $e($rejectUrl) . '" style="display:inline-block;background:#dc3545;color:#ffffff;text-decoration:none;padding:11px 20px;border-radius:6px;font-weight:bold;margin:0 8px 8px 0;">Reject Quotation</a>'
            . '</p>'
            . '<p style="margin:0;font-size:12px;color:#64748b;line-height:1.5;">These links are unique to this quotation. '
            . 'If the buttons do not work, copy and paste the secure link from this email into your browser.</p>'
            . '</div>'
            . '<p style="margin:20px 0 0;font-size:12px;color:#64748b;line-height:1.5;">Quotation No: '
            . $e($quotation['quotation_no'] ?? '') . '<br>Valid Until: ' . $e($validUntil) . '<br>'
            . 'Pavitha Consultancy Services OPC Pvt Ltd.</p>'
            . '</div></div></body></html>';

        return $html;
    }

    /* ---------------------------------------------------------------------
     * Status and conversion
     * ------------------------------------------------------------------ */

    /**
     * Mark a sent or accepted quotation as converted after the invoice has
     * actually been saved from the Create Invoice page. The guard conditions make the
     * call safe to repeat and keep already converted quotations untouched.
     */
    public function markConverted(int $quotationId, int $invoiceId): bool
    {
        $userId = $this->currentUserId();
        if ($userId <= 0 || $quotationId <= 0 || $invoiceId <= 0) {
            return false;
        }

        $stmt = $this->dbConnect->prepare(
            "UPDATE {$this->quotationTable}
             SET converted_invoice_id = ?
             WHERE id = ? AND user_id = ?
               AND (accepted_at IS NOT NULL OR status = 'Accepted'
                    OR (status = 'Sent' AND valid_until >= CURDATE()))
               AND converted_invoice_id IS NULL"
        );
        $stmt->bind_param('iii', $invoiceId, $quotationId, $userId);
        $stmt->execute();
        $updated = $stmt->affected_rows > 0;
        $stmt->close();

        return $updated;
    }

    /**
     * Convert a sent or accepted quotation into the existing invoice tables.
     * Returns the new invoice id.
     */
    public function convertToInvoice(int $quotationId): int
    {
        $userId = $this->currentUserId();
        if ($userId <= 0) {
            throw new RuntimeException('Your session has expired. Please log in again.');
        }

        // The existing invoice_order tables may use MyISAM, which cannot roll
        // back. Track the new invoice id so a failed conversion can remove the
        // rows it created instead of leaving an orphan invoice behind.
        $createdInvoiceId = 0;

        $this->dbConnect->begin_transaction();
        try {
            $stmt = $this->dbConnect->prepare(
                "SELECT * FROM {$this->quotationTable} WHERE id = ? AND user_id = ? FOR UPDATE"
            );
            $stmt->bind_param('ii', $quotationId, $userId);
            $stmt->execute();
            $quotation = $this->fetchOne($stmt);
            $stmt->close();

            if (empty($quotation)) {
                throw new InvalidArgumentException('Quotation not found.');
            }
            if (!empty($quotation['converted_invoice_id'])) {
                throw new InvalidArgumentException('This quotation has already been converted.');
            }
            if (!empty($quotation['rejected_at']) || $quotation['status'] === 'Rejected') {
                throw new InvalidArgumentException('Rejected or expired quotations cannot be converted to an invoice.');
            }
            if (empty($quotation['accepted_at'])
                && !empty($quotation['valid_until'])
                && $quotation['valid_until'] < date('Y-m-d')) {
                throw new InvalidArgumentException('Rejected or expired quotations cannot be converted to an invoice.');
            }
            if (empty($quotation['accepted_at'])
                && !in_array($quotation['status'], ['Sent', 'Accepted'], true)) {
                throw new InvalidArgumentException('Only sent quotations can be converted to an invoice.');
            }

            $items = $this->getQuotationItems($quotationId);
            if (empty($items)) {
                throw new InvalidArgumentException('This quotation has no items to convert.');
            }

            $quotationNo = (string) $quotation['quotation_no'];
            $receiverName = (string) $quotation['customer_name'];
            $existingCustomerDetails = $this->getLatestInvoiceCustomerDetails($receiverName);
            $receiverAddress = trim((string) ($quotation['customer_address'] ?? ''));
            if ($receiverAddress === '') {
                $receiverAddress = (string) ($existingCustomerDetails['receiver_address'] ?? '');
            }
            $gstNo = (string) ($existingCustomerDetails['gst_no'] ?? '');
            $panNo = (string) ($existingCustomerDetails['pan_no'] ?? '');
            $supply = (string) ($existingCustomerDetails['supply'] ?? '');
            $currency = in_array(($quotation['currency'] ?? 'INR'), ['INR', 'USD'], true)
                ? (string) $quotation['currency'] : 'INR';

            $initials = '';
            foreach (preg_split('/\s+/', mb_strtoupper($receiverName)) as $word) {
                if ($word !== '') {
                    $initials .= mb_substr($word, 0, 1);
                }
            }

            $subtotal = floatval($quotation['subtotal']);
            $discount = floatval($quotation['total_discount']);
            $taxAmount = floatval($quotation['tax_amount']);
            $grandTotal = floatval($quotation['grand_total']);
            $taxable = round($subtotal - $discount, 2);
            $cgstAmount = round($taxAmount / 2, 2);
            $sgstAmount = round($taxAmount - $cgstAmount, 2);
            $gstType = $taxAmount > 0 ? 'with_gst' : 'without_gst';
            $note = trim((string) $quotation['notes']);
            $note = ($note === '' ? '' : $note . "\n") . 'Converted from quotation ' . $quotationNo;

            $insertOrder = $this->dbConnect->prepare(
                "INSERT INTO {$this->invoiceOrderTable}
                (user_id, receiver_name, receiver_address, gst_no, pan_no, supply, currency, gst_type,
                 rate, cgst_amount, cgst_tax, sgst_amount, sgst_tax, vat_tax, total, note)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?)"
            );
            $cgstTax = $taxAmount > 0 ? 9 : 0;
            $sgstTax = $taxAmount > 0 ? 9 : 0;
            $insertOrder->bind_param(
                'isssssssdddddds',
                $userId,
                $receiverName,
                $receiverAddress,
                $gstNo,
                $panNo,
                $supply,
                $currency,
                $gstType,
                $taxable,
                $cgstAmount,
                $cgstTax,
                $sgstAmount,
                $sgstTax,
                $grandTotal,
                $note
            );
            if (!$insertOrder->execute()) {
                throw new RuntimeException('Could not create the invoice.');
            }
            $invoiceId = $insertOrder->insert_id;
            $createdInvoiceId = $invoiceId;
            $insertOrder->close();

            // receiver_id is a short invoice-table field; keep long customer
            // names from producing an over-length value.
            $receiverCode = mb_substr(
                $initials . str_pad((string) $invoiceId, 3, '0', STR_PAD_LEFT),
                0,
                20
            );
            $updateReceiver = $this->dbConnect->prepare(
                "UPDATE {$this->invoiceOrderTable} SET receiver_id = ? WHERE id = ?"
            );
            $updateReceiver->bind_param('si', $receiverCode, $invoiceId);
            if (!$updateReceiver->execute()) {
                throw new RuntimeException('Could not finish creating the invoice.');
            }
            $updateReceiver->close();

            $insertItem = $this->dbConnect->prepare(
                "INSERT INTO {$this->invoiceOrderItemTable}
                (order_id, hsn_sac, name, price, taxable_value)
                VALUES (?, '', ?, ?, ?)"
            );
            foreach ($items as $item) {
                $name = trim((string) $item['product_name']);
                if (!empty($item['product_description'])) {
                    $name .= ' - ' . trim((string) $item['product_description']);
                }
                // invoice_order_item.name is limited to 150 characters.
                $name = mb_substr($name, 0, 150);

                $price = floatval($item['unit_price']);
                $lineTotal = floatval($item['line_total']);
                $insertItem->bind_param('isdd', $invoiceId, $name, $price, $lineTotal);
                if (!$insertItem->execute()) {
                    throw new RuntimeException('Could not copy quotation items to the invoice.');
                }
            }
            $insertItem->close();

            $updateQuotation = $this->dbConnect->prepare(
                "UPDATE {$this->quotationTable}
                 SET converted_invoice_id = ?
                 WHERE id = ? AND user_id = ? AND converted_invoice_id IS NULL"
            );
            $updateQuotation->bind_param('iii', $invoiceId, $quotationId, $userId);
            if (!$updateQuotation->execute()) {
                throw new RuntimeException('Could not mark the quotation as converted.');
            }
            $updateQuotation->close();

            if (!$this->dbConnect->commit()) {
                throw new RuntimeException('Could not finish converting the quotation.');
            }
            return $invoiceId;
        } catch (Throwable $e) {
            try {
                $this->dbConnect->rollback();
            } catch (Throwable $rollbackError) {
                // Preserve the original conversion error.
            }

            if ($createdInvoiceId > 0) {
                try {
                    // If the commit actually reached the database but the
                    // client saw an error, keep the invoice rather than
                    // breaking the now-converted quotation.
                    $checkStmt = $this->dbConnect->prepare(
                        "SELECT status, converted_invoice_id FROM {$this->quotationTable}
                         WHERE id = ? AND user_id = ? LIMIT 1"
                    );
                    $checkStmt->bind_param('ii', $quotationId, $userId);
                    $checkStmt->execute();
                    $checkRow = $this->fetchOne($checkStmt);
                    $checkStmt->close();

                    $keepInvoice = !empty($checkRow)
                        && intval($checkRow['converted_invoice_id']) === $createdInvoiceId;

                    if (!$keepInvoice) {
                        $cleanupItems = $this->dbConnect->prepare(
                            "DELETE FROM {$this->invoiceOrderItemTable} WHERE order_id = ?"
                        );
                        $cleanupItems->bind_param('i', $createdInvoiceId);
                        $cleanupItems->execute();
                        $cleanupItems->close();

                        $cleanupInvoice = $this->dbConnect->prepare(
                            "DELETE FROM {$this->invoiceOrderTable} WHERE id = ?"
                        );
                        $cleanupInvoice->bind_param('i', $createdInvoiceId);
                        $cleanupInvoice->execute();
                        $cleanupInvoice->close();
                    }
                } catch (Throwable $cleanupError) {
                    // The original error is more useful than a cleanup error.
                }
            }

            throw $e;
        }
    }

    /* ---------------------------------------------------------------------
     * Presentation helpers
     * ------------------------------------------------------------------ */

    public static function statusBadgeClass(string $status): string
    {
        $map = [
            'Draft' => 'secondary',
            'Sent' => 'info',
        ];
        return $map[$status] ?? 'secondary';
    }

    public static function bankDetails(string $gstType = 'with_gst', string $currency = 'INR'): array
    {
        if (strtoupper($currency) === 'INR' && strtolower($gstType) === 'without_gst') {
            return [
                'Account No' => '1065101119898',
                'Name' => 'Mahalaksmi Ganesan',
                'Bank' => 'Canara Bank',
                'Branch' => 'Chandapura',
                'IFSC Code' => 'CNRB0001065',
            ];
        }

        return [
            'Receiver Name' => 'PAVITHA CONSULTANCY SERVICES OPC Pvt Ltd',
            'Bank Name' => 'FEDERAL BANK, SURANDAI',
            'Account Number' => '17480200004021',
            'IFSC' => 'FDRL0001748',
            'SWIFT Code' => 'FDRLINBBIBD',
        ];
    }

    public static function currencySymbol(string $currency): string
    {
        return strtoupper($currency) === 'USD' ? '$' : '₹';
    }
}
?>
