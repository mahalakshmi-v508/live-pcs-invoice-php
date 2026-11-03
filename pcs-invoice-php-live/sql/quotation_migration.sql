-- Quotation Management migration (idempotent)
-- Safe to run against an existing pcs_invoice database.

CREATE TABLE IF NOT EXISTS `quotation` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `quotation_no` varchar(50) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `customer_name` varchar(150) NOT NULL,
  `customer_phone` varchar(20) DEFAULT NULL,
  `customer_email` varchar(150) DEFAULT NULL,
  `customer_address` text,
  `quotation_date` date DEFAULT NULL,
  `valid_until` date DEFAULT NULL,
  `currency` varchar(10) DEFAULT 'INR',
  `gst_type` varchar(20) DEFAULT 'with_gst',
  `subtotal` decimal(12,2) DEFAULT 0.00,
  `total_discount` decimal(12,2) DEFAULT 0.00,
  `tax_amount` decimal(12,2) DEFAULT 0.00,
  `grand_total` decimal(12,2) DEFAULT 0.00,
  `notes` text,
  `terms_conditions` text,
  `status` varchar(20) DEFAULT 'Draft',
  `public_token` varchar(64) DEFAULT NULL,
  `accepted_at` datetime DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `rejection_reason` text,
  `converted_invoice_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_quotation_user_number` (`user_id`, `quotation_no`),
  UNIQUE KEY `uniq_quotation_public_token` (`public_token`),
  KEY `idx_quotation_user_status` (`user_id`, `status`),
  KEY `idx_quotation_dates` (`quotation_date`, `valid_until`),
  KEY `idx_quotation_invoice` (`converted_invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `quotation_item` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `quotation_id` int(11) NOT NULL,
  `product_name` varchar(255) NOT NULL,
  `product_description` text,
  `quantity` decimal(10,2) DEFAULT 1.00,
  `unit_price` decimal(10,2) DEFAULT 0.00,
  `discount` decimal(12,2) DEFAULT 0.00,
  `tax_percent` decimal(5,2) DEFAULT 0.00,
  `line_total` decimal(12,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_quotation_item_quotation` (`quotation_id`),
  CONSTRAINT `fk_quotation_item_quotation`
    FOREIGN KEY (`quotation_id`) REFERENCES `quotation` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Existing installations created before this migration may not have the
-- indexes above. Run the guarded statements below once if needed:
--
-- ALTER TABLE `quotation`
--   ADD UNIQUE KEY `uniq_quotation_user_number` (`user_id`, `quotation_no`),
--   ADD KEY `idx_quotation_user_status` (`user_id`, `status`),
--   ADD KEY `idx_quotation_dates` (`quotation_date`, `valid_until`),
--   ADD KEY `idx_quotation_invoice` (`converted_invoice_id`);
--
-- ALTER TABLE `quotation_item`
--   ADD KEY `idx_quotation_item_quotation` (`quotation_id`);

-- ---------------------------------------------------------------------------
-- Customer acceptance / rejection support (idempotent, non-destructive).
-- These statements only add the missing columns/index; they never drop or
-- reset existing quotation data.
-- ---------------------------------------------------------------------------

SET @has_public_token := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quotation' AND COLUMN_NAME = 'public_token'
);
SET @sql := IF(@has_public_token = 0,
  'ALTER TABLE `quotation` ADD COLUMN `public_token` varchar(64) DEFAULT NULL AFTER `status`',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_accepted_at := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quotation' AND COLUMN_NAME = 'accepted_at'
);
SET @sql := IF(@has_accepted_at = 0,
  'ALTER TABLE `quotation` ADD COLUMN `accepted_at` datetime DEFAULT NULL AFTER `public_token`',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_rejected_at := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quotation' AND COLUMN_NAME = 'rejected_at'
);
SET @sql := IF(@has_rejected_at = 0,
  'ALTER TABLE `quotation` ADD COLUMN `rejected_at` datetime DEFAULT NULL AFTER `accepted_at`',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_rejection_reason := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quotation' AND COLUMN_NAME = 'rejection_reason'
);
SET @sql := IF(@has_rejection_reason = 0,
  'ALTER TABLE `quotation` ADD COLUMN `rejection_reason` text AFTER `rejected_at`',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_public_token_index := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quotation'
    AND INDEX_NAME = 'uniq_quotation_public_token'
);
SET @sql := IF(@has_public_token_index = 0,
  'ALTER TABLE `quotation` ADD UNIQUE KEY `uniq_quotation_public_token` (`public_token`)',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Keep the lifecycle status limited to Draft/Sent. Customer decisions and
-- conversion remain recorded in their dedicated timestamp/id columns.
UPDATE `quotation`
SET `accepted_at` = COALESCE(`accepted_at`, `updated_at`)
WHERE `status` = 'Accepted';

UPDATE `quotation`
SET `rejected_at` = COALESCE(`rejected_at`, `updated_at`)
WHERE `status` = 'Rejected';

UPDATE `quotation`
SET `status` = CASE WHEN `status` = 'Draft' THEN 'Draft' ELSE 'Sent' END
WHERE `status` IS NULL OR `status` NOT IN ('Draft', 'Sent');
