-- POS2 complete database schema

SET @OLD_UNIQUE_CHECKS = @@UNIQUE_CHECKS, UNIQUE_CHECKS = 0;
SET @OLD_FOREIGN_KEY_CHECKS = @@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS = 0;


CREATE TABLE IF NOT EXISTS `admin_users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(100) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `full_name` VARCHAR(255) NOT NULL,
  `role` VARCHAR(50) NOT NULL DEFAULT 'admin',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `suppliers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `kra_pin` VARCHAR(100) DEFAULT NULL,
  `phone` VARCHAR(50) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `products` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `brand` VARCHAR(255) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `category` VARCHAR(255) NOT NULL,
  `base_unit` VARCHAR(100) NOT NULL,
  `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `retail_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `wholesale_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `stock` DECIMAL(15,3) NOT NULL DEFAULT 0.000,
  `packets_per_bale` INT NOT NULL DEFAULT 24,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `barcode_sequences` (
  `sequence_name` VARCHAR(100) NOT NULL PRIMARY KEY,
  `next_value` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `product_barcodes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` INT UNSIGNED NOT NULL,
  `barcode` VARCHAR(100) NOT NULL,
  `sale_mode` ENUM('unit','package') NOT NULL DEFAULT 'unit',
  `quantity` DECIMAL(10,3) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_barcode` (`barcode`),
  KEY `idx_product_id` (`product_id`),
  CONSTRAINT `fk_product_barcode_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stock_intakes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `supplier_id` INT UNSIGNED NOT NULL,
  `receipt_provided` TINYINT(1) NOT NULL DEFAULT 0,
  `receipt_number` VARCHAR(255) DEFAULT NULL,
  `receipt_date` DATE DEFAULT NULL,
  `receipt_photo` VARCHAR(255) DEFAULT NULL,
  `total_cost` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `payment_method` ENUM('cash','mpesa','bank','credit') NOT NULL DEFAULT 'cash',
  `amount_paid` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `balance` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('paid','partial','credit') NOT NULL DEFAULT 'paid',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `supplier_id` (`supplier_id`),
  CONSTRAINT `stock_intakes_supplier_fk` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stock_intake_lines` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `stock_intake_id` INT UNSIGNED NOT NULL,
  `product_id` INT UNSIGNED NOT NULL,
  `package_unit` VARCHAR(100) NOT NULL,
  `package_size_value` DECIMAL(15,3) NOT NULL DEFAULT 0.000,
  `package_size_unit` VARCHAR(50) NOT NULL,
  `quantity` DECIMAL(15,3) NOT NULL DEFAULT 0.000,
  `base_quantity` DECIMAL(15,3) NOT NULL DEFAULT 0.000,
  `cost_per_package` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `total_cost` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `stock_intake_id` (`stock_intake_id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `stock_intake_lines_intake_fk` FOREIGN KEY (`stock_intake_id`) REFERENCES `stock_intakes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `stock_intake_lines_product_fk` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stock_movements` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` INT UNSIGNED NOT NULL,
  `stock_intake_line_id` INT UNSIGNED DEFAULT NULL,
  `change_quantity` DECIMAL(15,3) NOT NULL DEFAULT 0.000,
  `reason` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  KEY `stock_intake_line_id` (`stock_intake_line_id`),
  CONSTRAINT `stock_movements_product_fk` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `stock_movements_line_fk` FOREIGN KEY (`stock_intake_line_id`) REFERENCES `stock_intake_lines` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(100) NULL,
  `password_hash` VARCHAR(255) NULL,
  `full_name` VARCHAR(255) NOT NULL,
  `role` VARCHAR(50) NOT NULL DEFAULT 'cashier',
  `status` VARCHAR(32) NOT NULL DEFAULT 'active',
  `activation_token` VARCHAR(64) NULL,
  `activation_token_expires_at` DATETIME NULL,
  `activated_at` DATETIME NULL,
  `phone_number` VARCHAR(32) NULL,
  `employee_id` VARCHAR(64) NULL,
  `email` VARCHAR(255) NULL,
  `branch` VARCHAR(128) NULL,
  `pos_terminal` VARCHAR(64) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL,
  UNIQUE KEY `uq_users_username` (`username`),
  UNIQUE KEY `uq_users_employee_id` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cashier_shifts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `cashier_id` INT NULL,
  `cashier_username` VARCHAR(100) NULL,
  `cashier_name` VARCHAR(255) NOT NULL,
  `opening_cash` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `expected_cash` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `counted_cash` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `variance` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `variance_reason` TEXT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'open',
  `priority` VARCHAR(16) NOT NULL DEFAULT 'low',
  `safe_drop_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `next_day_float` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `manager_acknowledged` TINYINT(1) NOT NULL DEFAULT 0,
  `investigation_note` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `started_at` TIMESTAMP NULL,
  `closed_at` TIMESTAMP NULL,
  `reviewed_at` TIMESTAMP NULL,
  `reviewed_by` VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sales` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `total_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `amount_tendered` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `change_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `payment_method` VARCHAR(32) NOT NULL DEFAULT 'cash',
  `payment_status` VARCHAR(32) NOT NULL DEFAULT 'pending',
  `customer_phone` VARCHAR(32) NULL,
  `receipt_number` VARCHAR(64) NULL,
  `notes` TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sale_items` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `sale_id` INT NOT NULL,
  `product_id` INT UNSIGNED NOT NULL,
  `product_name` VARCHAR(255) NOT NULL,
  `quantity` DECIMAL(10,3) NOT NULL DEFAULT 0,
  `quantity_in_packets` DECIMAL(10,3) NOT NULL DEFAULT 0,
  `sale_mode` VARCHAR(16) NOT NULL DEFAULT 'packet',
  `unit_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `cost_per_unit` DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
  `cost_total` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `line_total` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `sale_id` (`sale_id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `sale_items_sale_fk` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE,
  CONSTRAINT `sale_items_product_fk` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sale_payments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `sale_id` INT NOT NULL,
  `payment_method` VARCHAR(32) NOT NULL DEFAULT 'cash',
  `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
  `transaction_code` VARCHAR(64) NULL,
  `customer_phone` VARCHAR(32) NULL,
  `receipt_number` VARCHAR(64) NULL,
  `verified_by` VARCHAR(255) NULL,
  `verified_at` DATETIME NULL,
  `statement_filename` VARCHAR(255) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `sale_id` (`sale_id`),
  CONSTRAINT `sale_payments_sale_fk` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `quick_stock_purchases` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `cashier_id` INT NULL,
  `shift_id` INT NULL,
  `supplier_id` INT NULL,
  `purchase_no` VARCHAR(50) NULL,
  `purchase_date` DATE NULL,
  `total_cost` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `payment_method` VARCHAR(32) NOT NULL DEFAULT 'cash',
  `receipt_type` VARCHAR(32) NOT NULL DEFAULT 'none',
  `notes` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `supplier_id` (`supplier_id`),
  CONSTRAINT `quick_stock_purchases_supplier_fk` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `quick_stock_purchase_lines` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `quick_purchase_id` INT NOT NULL,
  `product_id` INT UNSIGNED NOT NULL,
  `quantity` DECIMAL(10,3) NOT NULL DEFAULT 0,
  `cost_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `selling_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `expiry_date` DATE NULL,
  `total_cost` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `quick_purchase_id` (`quick_purchase_id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `quick_stock_purchase_lines_purchase_fk` FOREIGN KEY (`quick_purchase_id`) REFERENCES `quick_stock_purchases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `quick_stock_purchase_lines_product_fk` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `equity_statement_uploads` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `filename` VARCHAR(255) NOT NULL,
  `uploaded_by` VARCHAR(255) NOT NULL,
  `uploaded_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `total_transactions` INT NOT NULL DEFAULT 0,
  `matched_count` INT NOT NULL DEFAULT 0,
  `unmatched_pos_count` INT NOT NULL DEFAULT 0,
  `unmatched_bank_count` INT NOT NULL DEFAULT 0,
  `duplicate_codes_count` INT NOT NULL DEFAULT 0,
  `status` VARCHAR(32) NOT NULL DEFAULT 'processed'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `equity_statement_rows` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `upload_id` INT NOT NULL,
  `transaction_code` VARCHAR(64) NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `matched_payment_id` INT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'unmatched',
  `statement_date` VARCHAR(32) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `upload_id` (`upload_id`),
  KEY `matched_payment_id` (`matched_payment_id`),
  CONSTRAINT `equity_statement_rows_upload_fk` FOREIGN KEY (`upload_id`) REFERENCES `equity_statement_uploads` (`id`) ON DELETE CASCADE,
  CONSTRAINT `equity_statement_rows_payment_fk` FOREIGN KEY (`matched_payment_id`) REFERENCES `sale_payments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `business_expenses` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL,
  `category` VARCHAR(100) NOT NULL DEFAULT 'general',
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `payment_method` VARCHAR(32) NOT NULL DEFAULT 'cash',
  `payment_reference` VARCHAR(255) NULL,
  `cash_drawer_movement` TINYINT(1) NOT NULL DEFAULT 0,
  `cash_drawer_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `expense_date` DATE NOT NULL,
  `notes` TEXT NULL,
  `created_by` VARCHAR(255) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `expense_requests` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `cashier_id` INT NULL,
  `cashier_name` VARCHAR(255) NULL,
  `title` VARCHAR(255) NOT NULL,
  `category` VARCHAR(100) NOT NULL DEFAULT 'general',
  `amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `payment_method` VARCHAR(32) NOT NULL DEFAULT 'cash',
  `payment_reference` VARCHAR(255) NULL,
  `cash_drawer_movement` TINYINT(1) NOT NULL DEFAULT 0,
  `cash_drawer_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `request_date` DATE NOT NULL,
  `notes` TEXT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
  `reviewed_by` VARCHAR(255) NULL,
  `reviewed_at` DATETIME NULL,
  `review_notes` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = @OLD_FOREIGN_KEY_CHECKS;
SET UNIQUE_CHECKS = @OLD_UNIQUE_CHECKS;


-- Modern POS2 enhancements used by the demo build.
ALTER TABLE products ADD COLUMN IF NOT EXISTS expiry_date DATE NULL;
ALTER TABLE products ADD COLUMN IF NOT EXISTS expiry_alert_sent_at DATETIME NULL;
ALTER TABLE product_barcodes ADD COLUMN IF NOT EXISTS retail_price DECIMAL(10,2) NOT NULL DEFAULT 0.00;
ALTER TABLE product_barcodes ADD COLUMN IF NOT EXISTS wholesale_price DECIMAL(10,2) NOT NULL DEFAULT 0.00;
ALTER TABLE sale_payments ADD COLUMN IF NOT EXISTS transaction_code VARCHAR(64) NULL;
ALTER TABLE sale_payments ADD COLUMN IF NOT EXISTS verified_by VARCHAR(255) NULL;
ALTER TABLE sale_payments ADD COLUMN IF NOT EXISTS verified_at DATETIME NULL;
ALTER TABLE sale_payments ADD COLUMN IF NOT EXISTS statement_filename VARCHAR(255) NULL;

CREATE TABLE IF NOT EXISTS stock_batches (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  stock_intake_id INT UNSIGNED NULL,
  stock_intake_line_id INT UNSIGNED NULL,
  quick_purchase_id INT NULL,
  source_type ENUM('legacy','regular_intake','quick_purchase') NOT NULL DEFAULT 'legacy',
  quantity_received DECIMAL(15,3) NOT NULL DEFAULT 0.000,
  quantity_remaining DECIMAL(15,3) NOT NULL DEFAULT 0.000,
  base_quantity_received DECIMAL(15,3) NOT NULL DEFAULT 0.000,
  base_quantity_remaining DECIMAL(15,3) NOT NULL DEFAULT 0.000,
  package_size_value DECIMAL(15,3) NOT NULL DEFAULT 1.000,
  package_size_unit VARCHAR(32) NULL,
  cost_per_package DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  retail_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  wholesale_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  expiry_date DATE NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_stock_batches_product_expiry (product_id, expiry_date, id),
  KEY idx_stock_batches_remaining_expiry (quantity_remaining, expiry_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  customer_number VARCHAR(64) NOT NULL UNIQUE,
  name VARCHAR(255) NOT NULL,
  phone VARCHAR(50) NULL,
  current_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  status VARCHAR(32) NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_credit_invoices (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  invoice_number VARCHAR(64) NOT NULL UNIQUE,
  customer_id INT UNSIGNED NOT NULL,
  sale_id INT NULL,
  total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  status VARCHAR(32) NOT NULL DEFAULT 'open',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL,
  KEY idx_customer_credit_customer (customer_id),
  CONSTRAINT fk_customer_credit_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employee_salaries (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  employee_id INT NOT NULL,
  salary_type VARCHAR(32) NOT NULL DEFAULT 'monthly',
  base_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  currency VARCHAR(8) NOT NULL DEFAULT 'KES',
  department VARCHAR(128) NULL,
  bank_name VARCHAR(128) NULL,
  bank_account VARCHAR(128) NULL,
  notes TEXT NULL,
  effective_from DATE NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL,
  KEY idx_employee_salary_employee (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS salary_payments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  employee_id INT NOT NULL,
  pay_period_start DATE NOT NULL,
  pay_period_end DATE NOT NULL,
  payment_date DATE NULL,
  gross_pay DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  total_allowances DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  total_deductions DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  net_pay DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  payment_status VARCHAR(32) NOT NULL DEFAULT 'pending',
  payment_method VARCHAR(32) NOT NULL DEFAULT 'bank',
  payroll_reference VARCHAR(128) NULL,
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_salary_payment_employee (employee_id),
  KEY idx_salary_payment_period (pay_period_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS salary_components (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  salary_payment_id INT UNSIGNED NOT NULL,
  component_type VARCHAR(32) NOT NULL,
  label VARCHAR(255) NOT NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_salary_component_payment (salary_payment_id),
  CONSTRAINT fk_salary_component_payment FOREIGN KEY (salary_payment_id) REFERENCES salary_payments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  ip_address VARCHAR(64) NOT NULL,
  username VARCHAR(100) NOT NULL,
  failed_attempts INT NOT NULL DEFAULT 0,
  first_failed_at DATETIME NOT NULL,
  last_failed_at DATETIME NOT NULL,
  blocked_until DATETIME NULL,
  PRIMARY KEY (ip_address, username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Fictional demo data only. No production/customer records are included.
INSERT INTO users (id, username, password_hash, full_name, role, status, phone_number, employee_id, email, branch, pos_terminal, created_at, updated_at)
VALUES
(1, 'admin', '$2y$12$rjl0pu.Rrrytvg1VTd57FemKBJS0j2jxVwtVvRWqEW9Qfks6paG8q', 'Demo Administrator', 'admin', 'active', NULL, 'DEMO-ADMIN', 'admin@example.invalid', 'Demo Branch', 'DEMO-01', NOW(), NOW()),
(2, 'cashier', '$2y$12$rjl0pu.Rrrytvg1VTd57FemKBJS0j2jxVwtVvRWqEW9Qfks6paG8q', 'Demo Cashier', 'cashier', 'active', '0700000000', 'DEMO-001', 'cashier@example.invalid', 'Demo Branch', 'DEMO-01', NOW(), NOW());

INSERT INTO admin_users (id, username, password_hash, full_name, role, created_at)
VALUES (1, 'admin', '$2y$12$rjl0pu.Rrrytvg1VTd57FemKBJS0j2jxVwtVvRWqEW9Qfks6paG8q', 'Demo Administrator', 'admin', NOW());

INSERT INTO suppliers (id, name, kra_pin, phone, created_at) VALUES
(1, 'Demo Wholesale Supplies', NULL, '0700000001', NOW()),
(2, 'Demo Food Distributors', NULL, '0700000002', NOW());

INSERT INTO products (id, brand, name, category, base_unit, price, retail_price, wholesale_price, stock, packets_per_bale, created_at, expiry_date)
VALUES
(1, 'DemoBrand', 'Maize Flour 1KG', 'Food', 'KG', 90.00, 120.00, 110.00, 48.000, 24, NOW(), NULL),
(2, 'DemoBrand', 'White Sugar 1KG', 'Food', 'KG', 100.00, 135.00, 125.00, 36.000, 25, NOW(), NULL),
(3, 'DemoBrand', 'Cooking Oil 1L', 'Food', 'LITRE', 150.00, 190.00, 180.00, 24.000, 12, NOW(), NULL),
(4, 'DemoBrand', 'Fresh Milk 500ML', 'Dairy', 'PACKET', 45.00, 60.00, 55.00, 30.000, 12, NOW(), DATE_ADD(CURDATE(), INTERVAL 20 DAY)),
(5, 'DemoBrand', 'Rice 1KG', 'Food', 'KG', 120.00, 160.00, 150.00, 40.000, 25, NOW(), NULL);

INSERT INTO product_barcodes (product_id, barcode, sale_mode, quantity, retail_price, wholesale_price) VALUES
(1, 'DEMO-10001', 'unit', 1.000, 120.00, 110.00),
(1, 'DEMO-10001-PACK', 'package', 24.000, 2880.00, 2640.00),
(2, 'DEMO-10002', 'unit', 1.000, 135.00, 125.00),
(3, 'DEMO-10003', 'unit', 1.000, 190.00, 180.00),
(4, 'DEMO-10004', 'unit', 1.000, 60.00, 55.00),
(5, 'DEMO-10005', 'unit', 1.000, 160.00, 150.00);

INSERT INTO stock_intakes (id, supplier_id, receipt_provided, receipt_number, receipt_date, total_cost, payment_method, amount_paid, balance, status, created_at) VALUES
(1, 1, 1, 'DEMO-PO-001', CURDATE(), 4320.00, 'cash', 4320.00, 0.00, 'paid', NOW()),
(2, 2, 1, 'DEMO-PO-002', CURDATE(), 1800.00, 'bank', 1500.00, 300.00, 'partial', NOW());

INSERT INTO stock_intake_lines (id, stock_intake_id, product_id, package_unit, package_size_value, package_size_unit, quantity, base_quantity, cost_per_package, total_cost) VALUES
(1, 1, 1, 'package', 24.000, 'KG', 2.000, 48.000, 2160.00, 4320.00),
(2, 2, 3, 'package', 12.000, 'LITRE', 1.000, 12.000, 1800.00, 1800.00);

INSERT INTO stock_movements (product_id, stock_intake_line_id, change_quantity, reason, created_at) VALUES
(1, 1, 48.000, 'Package intake', NOW()),
(3, 2, 12.000, 'Package intake', NOW());

INSERT INTO stock_batches (product_id, stock_intake_id, stock_intake_line_id, source_type, quantity_received, quantity_remaining, base_quantity_received, base_quantity_remaining, package_size_value, package_size_unit, cost_per_package, retail_price, wholesale_price, expiry_date)
VALUES
(1, 1, 1, 'regular_intake', 2.000, 2.000, 48.000, 48.000, 24.000, 'KG', 2160.00, 120.00, 110.00, NULL),
(3, 2, 2, 'regular_intake', 1.000, 1.000, 12.000, 12.000, 12.000, 'LITRE', 1800.00, 190.00, 180.00, NULL),
(4, NULL, NULL, 'legacy', 30.000, 30.000, 30.000, 30.000, 1.000, 'PACKET', 45.00, 60.00, 55.00, DATE_ADD(CURDATE(), INTERVAL 20 DAY));

INSERT INTO sales (id, created_at, total_amount, amount_tendered, change_amount, payment_method, payment_status, receipt_number, notes) VALUES
(1, NOW() - INTERVAL 2 DAY, 255.00, 300.00, 45.00, 'cash', 'paid', 'DEMO-RCP-0001', 'Demo retail sale'),
(2, NOW() - INTERVAL 1 DAY, 350.00, 350.00, 0.00, 'cash', 'paid', 'DEMO-RCP-0002', 'Demo wholesale-style sale'),
(3, NOW(), 190.00, 190.00, 0.00, 'mpesa_stk', 'paid', 'DEMO-RCP-0003', 'Demo digital payment');

INSERT INTO sale_items (sale_id, product_id, product_name, quantity, quantity_in_packets, sale_mode, unit_price, cost_per_unit, cost_total, line_total) VALUES
(1, 1, 'Maize Flour 1KG', 1.000, 1.000, 'packet', 120.00, 90.0000, 90.00, 120.00),
(1, 2, 'White Sugar 1KG', 1.000, 1.000, 'packet', 135.00, 100.0000, 100.00, 135.00),
(2, 1, 'Maize Flour 1KG', 2.000, 2.000, 'packet', 110.00, 90.0000, 180.00, 220.00),
(2, 5, 'Rice 1KG', 1.000, 1.000, 'packet', 130.00, 120.0000, 120.00, 130.00),
(3, 3, 'Cooking Oil 1L', 1.000, 1.000, 'packet', 190.00, 150.0000, 150.00, 190.00);

INSERT INTO sale_payments (sale_id, payment_method, amount, status, transaction_code, receipt_number, created_at) VALUES
(1, 'cash', 255.00, 'success', NULL, 'DEMO-RCP-0001', NOW() - INTERVAL 2 DAY),
(2, 'cash', 350.00, 'success', NULL, 'DEMO-RCP-0002', NOW() - INTERVAL 1 DAY),
(3, 'mpesa_stk', 190.00, 'success', 'DEMO-TXN-0003', 'DEMO-RCP-0003', NOW());

INSERT INTO stock_movements (product_id, change_quantity, reason, created_at) VALUES
(1, -1.000, 'Demo retail sale', NOW() - INTERVAL 2 DAY),
(2, -1.000, 'Demo retail sale', NOW() - INTERVAL 2 DAY),
(1, -2.000, 'Demo wholesale sale', NOW() - INTERVAL 1 DAY),
(5, -1.000, 'Demo wholesale sale', NOW() - INTERVAL 1 DAY),
(3, -1.000, 'Demo digital payment sale', NOW());

INSERT INTO cashier_shifts (id, cashier_id, cashier_username, cashier_name, opening_cash, expected_cash, counted_cash, variance, status, priority, started_at, closed_at, created_at) VALUES
(1, 2, 'cashier', 'Demo Cashier', 1000.00, 1555.00, 1555.00, 0.00, 'closed', 'low', NOW() - INTERVAL 2 DAY, NOW() - INTERVAL 2 DAY + INTERVAL 8 HOUR, NOW() - INTERVAL 2 DAY);

INSERT INTO customers (id, customer_number, name, phone, current_balance, status, created_at, updated_at) VALUES
(1, 'CUST-0001', 'Demo Customer One', '0700000010', 240.00, 'active', NOW(), NOW()),
(2, 'CUST-0002', 'Demo Customer Two', '0700000011', 0.00, 'active', NOW(), NOW());

INSERT INTO customer_credit_invoices (id, invoice_number, customer_id, sale_id, total_amount, amount_paid, balance, status, created_at, updated_at) VALUES
(1, 'INV-DEMO-0001', 1, NULL, 240.00, 0.00, 240.00, 'open', NOW() - INTERVAL 1 DAY, NOW() - INTERVAL 1 DAY);

INSERT INTO employee_salaries (employee_id, salary_type, base_amount, currency, department, effective_from, created_at, updated_at) VALUES
(2, 'monthly', 30000.00, 'KES', 'Operations', CURDATE(), NOW(), NOW());

INSERT INTO salary_payments (employee_id, pay_period_start, pay_period_end, payment_date, gross_pay, total_allowances, total_deductions, net_pay, payment_status, payment_method, payroll_reference, notes) VALUES
(2, DATE_SUB(CURDATE(), INTERVAL 1 MONTH), LAST_DAY(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)), LAST_DAY(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)), 30000.00, 0.00, 0.00, 30000.00, 'paid', 'bank', 'DEMO-PAY-0001', 'Demo payroll record');

INSERT INTO salary_components (salary_payment_id, component_type, label, amount) VALUES
(1, 'earning', 'Base salary', 30000.00);

INSERT INTO business_expenses (title, category, amount, payment_method, expense_date, notes, created_by) VALUES
('Demo utilities', 'utilities', 2500.00, 'cash', CURDATE(), 'Fictional demo expense', 'Demo Administrator'),
('Demo stationery', 'office', 1200.00, 'cash', CURDATE(), 'Fictional demo expense', 'Demo Administrator');
