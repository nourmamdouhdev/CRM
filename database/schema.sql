-- Tagom CRM schema
-- Reconstructed from application queries (PHP + MySQL).
-- Default database name matches config/config.php: tagom_crm
--
-- Import (phpMyAdmin, MySQL CLI, or XAMPP):
--   mysql -u root < database/schema.sql
--
-- Default login after import:
--   username: owner
--   password: admin123
-- Change this password immediately after first login.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS `tagom_crm`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `tagom_crm`;

DROP TABLE IF EXISTS `audit_logs`;
DROP TABLE IF EXISTS `whatsapp_messages`;
DROP TABLE IF EXISTS `integration_settings`;
DROP TABLE IF EXISTS `ledger_entries`;
DROP TABLE IF EXISTS `payments`;
DROP TABLE IF EXISTS `sales_invoice_items`;
DROP TABLE IF EXISTS `purchase_invoice_items`;
DROP TABLE IF EXISTS `sales_invoices`;
DROP TABLE IF EXISTS `purchase_invoices`;
DROP TABLE IF EXISTS `stock`;
DROP TABLE IF EXISTS `products`;
DROP TABLE IF EXISTS `parties`;
DROP TABLE IF EXISTS `doc_sequences`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `roles`;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------------
-- Auth
-- ---------------------------------------------------------------------------

CREATE TABLE `roles` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(50) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roles_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `role_id` INT UNSIGNED NOT NULL,
  `full_name` VARCHAR(150) NOT NULL,
  `username` VARCHAR(60) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`),
  KEY `idx_users_role_id` (`role_id`),
  KEY `idx_users_is_active` (`is_active`),
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Parties (customers + suppliers)
-- ---------------------------------------------------------------------------

CREATE TABLE `parties` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type` ENUM('customer', 'supplier') NOT NULL,
  `name` VARCHAR(200) NOT NULL,
  `phone` VARCHAR(50) DEFAULT NULL,
  `address` VARCHAR(255) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `lead_source` ENUM(
    'marketing_campaign',
    'cold_lead',
    'business_cards',
    'marketing_agent',
    'people_referral'
  ) DEFAULT NULL,
  `opening_balance` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `opening_balance_type` ENUM('debit', 'credit') NOT NULL DEFAULT 'debit',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_parties_type_active` (`type`, `is_active`),
  KEY `idx_parties_name` (`name`),
  KEY `idx_parties_phone` (`phone`),
  KEY `idx_parties_lead_source` (`lead_source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Products + stock
-- ---------------------------------------------------------------------------

CREATE TABLE `products` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(200) NOT NULL,
  `sku` VARCHAR(80) DEFAULT NULL,
  `image_path` VARCHAR(255) DEFAULT NULL,
  `sale_price_default` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `cost_price_default` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `supplier_id` INT UNSIGNED DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_products_sku` (`sku`),
  KEY `idx_products_supplier_active` (`supplier_id`, `is_active`),
  KEY `idx_products_name` (`name`),
  CONSTRAINT `fk_products_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `parties` (`id`)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- product_id is the unique key so StockRepository ON DUPLICATE KEY UPDATE works.
CREATE TABLE `stock` (
  `product_id` INT UNSIGNED NOT NULL,
  `qty_on_hand` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
  PRIMARY KEY (`product_id`),
  CONSTRAINT `fk_stock_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Sales
-- ---------------------------------------------------------------------------

CREATE TABLE `sales_invoices` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_no` VARCHAR(40) NOT NULL,
  `customer_id` INT UNSIGNED NOT NULL,
  `invoice_date` DATE NOT NULL,
  `total_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `total` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `net_total` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `paid_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `remaining_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `due_date` DATE DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sales_invoices_no` (`invoice_no`),
  KEY `idx_sales_customer` (`customer_id`),
  KEY `idx_sales_invoice_date` (`invoice_date`),
  KEY `idx_sales_due_remaining` (`due_date`, `remaining_amount`),
  KEY `idx_sales_created_by` (`created_by`),
  CONSTRAINT `fk_sales_customer` FOREIGN KEY (`customer_id`) REFERENCES `parties` (`id`),
  CONSTRAINT `fk_sales_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sales_invoice_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sales_invoice_id` INT UNSIGNED NOT NULL,
  `product_id` INT UNSIGNED NOT NULL,
  `qty` DECIMAL(14,3) NOT NULL,
  `unit_price` DECIMAL(14,2) NOT NULL,
  `line_total` DECIMAL(14,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sii_invoice` (`sales_invoice_id`),
  KEY `idx_sii_product` (`product_id`),
  CONSTRAINT `fk_sii_invoice` FOREIGN KEY (`sales_invoice_id`) REFERENCES `sales_invoices` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `fk_sii_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Purchases
-- ---------------------------------------------------------------------------

CREATE TABLE `purchase_invoices` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_no` VARCHAR(40) NOT NULL,
  `supplier_id` INT UNSIGNED NOT NULL,
  `invoice_date` DATE NOT NULL,
  `total_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `total` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `net_total` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `paid_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `remaining_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `due_date` DATE DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_purchase_invoices_no` (`invoice_no`),
  KEY `idx_purchase_supplier` (`supplier_id`),
  KEY `idx_purchase_invoice_date` (`invoice_date`),
  KEY `idx_purchase_due_remaining` (`due_date`, `remaining_amount`),
  KEY `idx_purchase_created_by` (`created_by`),
  CONSTRAINT `fk_purchase_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `parties` (`id`),
  CONSTRAINT `fk_purchase_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `purchase_invoice_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `purchase_invoice_id` INT UNSIGNED NOT NULL,
  `product_id` INT UNSIGNED NOT NULL,
  `qty` DECIMAL(14,3) NOT NULL,
  `unit_cost` DECIMAL(14,2) NOT NULL,
  `line_total` DECIMAL(14,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pii_invoice` (`purchase_invoice_id`),
  KEY `idx_pii_product` (`product_id`),
  CONSTRAINT `fk_pii_invoice` FOREIGN KEY (`purchase_invoice_id`) REFERENCES `purchase_invoices` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `fk_pii_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Payments + ledger
-- ---------------------------------------------------------------------------

CREATE TABLE `payments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `party_id` INT UNSIGNED NOT NULL,
  `direction` ENUM('in', 'out') NOT NULL,
  `payment_date` DATE NOT NULL,
  `amount` DECIMAL(14,2) NOT NULL,
  `method` ENUM('cash', 'bank', 'cheque') NOT NULL DEFAULT 'cash',
  `cheque_id` VARCHAR(80) DEFAULT NULL,
  `ref_table` VARCHAR(64) DEFAULT NULL,
  `ref_id` INT UNSIGNED DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_payments_party` (`party_id`),
  KEY `idx_payments_direction_date` (`direction`, `payment_date`),
  KEY `idx_payments_ref` (`ref_table`, `ref_id`),
  KEY `idx_payments_created_by` (`created_by`),
  CONSTRAINT `fk_payments_party` FOREIGN KEY (`party_id`) REFERENCES `parties` (`id`),
  CONSTRAINT `fk_payments_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ledger_entries` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `party_id` INT UNSIGNED NOT NULL,
  `entry_date` DATE NOT NULL,
  `type` VARCHAR(40) NOT NULL,
  `ref_table` VARCHAR(64) DEFAULT NULL,
  `ref_id` INT UNSIGNED DEFAULT NULL,
  `debit` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `credit` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `due_date` DATE DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ledger_party_date` (`party_id`, `entry_date`, `id`),
  KEY `idx_ledger_type` (`type`),
  KEY `idx_ledger_ref` (`ref_table`, `ref_id`),
  KEY `idx_ledger_created_by` (`created_by`),
  CONSTRAINT `fk_ledger_party` FOREIGN KEY (`party_id`) REFERENCES `parties` (`id`),
  CONSTRAINT `fk_ledger_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Document sequences (used when FEATURE_USE_*_SERVICE = 1)
-- ---------------------------------------------------------------------------

CREATE TABLE `doc_sequences` (
  `doc_type` VARCHAR(32) NOT NULL,
  `ymd` CHAR(8) NOT NULL,
  `last_no` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`doc_type`, `ymd`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional: AuditLogRepository skips inserts if this table is missing.
CREATE TABLE `audit_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor_id` INT UNSIGNED DEFAULT NULL,
  `action` VARCHAR(80) NOT NULL,
  `entity_type` VARCHAR(64) NOT NULL,
  `entity_id` INT UNSIGNED NOT NULL,
  `payload_json` JSON DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_actor` (`actor_id`),
  KEY `idx_audit_entity` (`entity_type`, `entity_id`),
  KEY `idx_audit_created_at` (`created_at`),
  CONSTRAINT `fk_audit_actor` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Integrations (WhatsApp Cloud API + MCP / Claude)
-- ---------------------------------------------------------------------------

CREATE TABLE `integration_settings` (
  `setting_key` VARCHAR(80) NOT NULL,
  `setting_value` TEXT DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `whatsapp_messages` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `party_id` INT UNSIGNED DEFAULT NULL,
  `direction` ENUM('out', 'in') NOT NULL,
  `phone` VARCHAR(32) NOT NULL,
  `wa_message_id` VARCHAR(128) DEFAULT NULL,
  `body` TEXT NOT NULL,
  `status` VARCHAR(40) NOT NULL DEFAULT 'queued',
  `error_message` VARCHAR(255) DEFAULT NULL,
  `payload_json` JSON DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_wa_party` (`party_id`),
  KEY `idx_wa_phone` (`phone`),
  KEY `idx_wa_created` (`created_at`),
  KEY `idx_wa_wa_message_id` (`wa_message_id`),
  CONSTRAINT `fk_wa_party` FOREIGN KEY (`party_id`) REFERENCES `parties` (`id`)
    ON DELETE SET NULL,
  CONSTRAINT `fk_wa_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Seed data
-- ---------------------------------------------------------------------------

INSERT INTO `roles` (`id`, `name`) VALUES
  (1, 'owner'),
  (2, 'employee'),
  (3, 'admin'),
  (4, 'manager'),
  (5, 'clerk');

-- password: admin123
INSERT INTO `users` (`role_id`, `full_name`, `username`, `password_hash`, `is_active`, `created_at`) VALUES
  (1, 'System Owner', 'owner', '$2y$10$P4mXrL78TuQVXcwn7r6IeepiiRHODBLhJ046BQhAZBEKlBuTDP/WO', 1, NOW());
