-- ATIERA Financial Management System database seed data.
-- This file moves the former UI-only financial seed records into database tables.

CREATE TABLE IF NOT EXISTS `financial_work_queue` (
  `id` varchar(40) NOT NULL,
  `queue_type` enum('hr_claim','hr_payroll','hr_incentive') NOT NULL,
  `employee_name` varchar(120) DEFAULT NULL,
  `department` varchar(100) DEFAULT NULL,
  `position` varchar(100) DEFAULT NULL,
  `period_display` varchar(100) DEFAULT NULL,
  `claim_type` varchar(100) DEFAULT NULL,
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `employee_count` int DEFAULT 0,
  `submitted_by` varchar(120) DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `status` varchar(40) NOT NULL DEFAULT 'pending',
  `can_process` tinyint(1) NOT NULL DEFAULT 1,
  `reference_number` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_financial_work_queue_type_status` (`queue_type`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `vendors` (`id`, `vendor_code`, `company_name`, `contact_person`, `email`, `phone`, `address`, `payment_terms`, `status`) VALUES
(9101, 'VEND-9101', 'Sterling Food Solutions', 'Nora Castillo', 'ap@sterlingfood.ph', '+63 917 200 3311', 'Makati City, Metro Manila', 'Net 30', 'active'),
(9102, 'VEND-9102', 'Harborline Utilities Group', 'Dennis Uy', 'billing@harborline-utilities.ph', '+63 917 200 3377', 'Manila City, Metro Manila', 'Net 15', 'active'),
(9103, 'VEND-9103', 'Northpoint Facilities Supply', 'Irene Santos', 'accounts@northpointfacilities.ph', '+63 917 200 3399', 'Pasig City, Metro Manila', 'Net 30', 'active')
ON DUPLICATE KEY UPDATE
  `vendor_code` = VALUES(`vendor_code`),
  `company_name` = VALUES(`company_name`),
  `contact_person` = VALUES(`contact_person`),
  `email` = VALUES(`email`),
  `phone` = VALUES(`phone`),
  `address` = VALUES(`address`),
  `payment_terms` = VALUES(`payment_terms`),
  `status` = VALUES(`status`);

INSERT INTO `customers` (`id`, `customer_code`, `company_name`, `contact_person`, `email`, `phone`, `address`, `credit_limit`, `current_balance`, `status`) VALUES
(9201, 'CUST-9201', 'Silver Crest Events', 'Leah Flores', 'finance@silvercrest-events.com', '+63 917 200 4481', 'Makati City, Metro Manila', 850000.00, 248000.00, 'active'),
(9202, 'CUST-9202', 'Blue Harbor Suites', 'Marco Lim', 'ap@blueharborsuites.com', '+63 917 200 5521', 'Pasay City, Metro Manila', 640000.00, 96500.00, 'active'),
(9203, 'CUST-9203', 'Summit Dining Concepts', 'Ariana Cruz', 'payments@summitdining.ph', '+63 917 200 6188', 'Taguig City, Metro Manila', 720000.00, 129900.00, 'active')
ON DUPLICATE KEY UPDATE
  `customer_code` = VALUES(`customer_code`),
  `company_name` = VALUES(`company_name`),
  `contact_person` = VALUES(`contact_person`),
  `email` = VALUES(`email`),
  `phone` = VALUES(`phone`),
  `address` = VALUES(`address`),
  `credit_limit` = VALUES(`credit_limit`),
  `current_balance` = VALUES(`current_balance`),
  `status` = VALUES(`status`);

INSERT INTO `bills` (`id`, `bill_number`, `vendor_id`, `bill_date`, `due_date`, `subtotal`, `tax_rate`, `tax_amount`, `total_amount`, `paid_amount`, `balance`, `status`, `notes`, `created_by`, `approved_by`) VALUES
(9301, 'BILL-2026-301', 9101, '2026-04-16', '2026-05-12', 162901.79, 12.00, 19548.21, 182450.00, 0.00, 182450.00, 'approved', 'Kitchen operating supplies pending approval workflow.', 1, 1),
(9302, 'BILL-2026-302', 9102, '2026-04-09', '2026-04-28', 86000.00, 12.00, 10320.00, 96320.00, 48250.00, 48070.00, 'overdue', 'Utility servicing bill with partial settlement.', 1, 1),
(9303, 'BILL-2026-303', 9103, '2026-04-23', '2026-05-18', 67392.86, 12.00, 8087.14, 75480.00, 0.00, 75480.00, 'draft', 'Facilities supply replenishment draft.', 1, NULL)
ON DUPLICATE KEY UPDATE
  `bill_number` = VALUES(`bill_number`),
  `vendor_id` = VALUES(`vendor_id`),
  `bill_date` = VALUES(`bill_date`),
  `due_date` = VALUES(`due_date`),
  `subtotal` = VALUES(`subtotal`),
  `tax_rate` = VALUES(`tax_rate`),
  `tax_amount` = VALUES(`tax_amount`),
  `total_amount` = VALUES(`total_amount`),
  `paid_amount` = VALUES(`paid_amount`),
  `balance` = VALUES(`balance`),
  `status` = VALUES(`status`),
  `notes` = VALUES(`notes`);

INSERT INTO `invoices` (`id`, `invoice_number`, `customer_id`, `invoice_date`, `due_date`, `subtotal`, `tax_rate`, `tax_amount`, `total_amount`, `paid_amount`, `balance`, `status`, `notes`, `created_by`) VALUES
(9401, 'INV-2026-401', 9201, '2026-04-08', '2026-05-08', 221428.57, 12.00, 26571.43, 248000.00, 0.00, 248000.00, 'sent', 'Corporate events package for Silver Crest Events.', 1),
(9402, 'INV-2026-402', 9202, '2026-04-15', '2026-05-05', 166517.86, 12.00, 19982.14, 186500.00, 90000.00, 96500.00, 'sent', 'Hotel accommodation block with partial collection.', 1),
(9403, 'INV-2026-403', 9203, '2026-03-20', '2026-04-20', 115982.14, 12.00, 13917.86, 129900.00, 0.00, 129900.00, 'overdue', 'Dining event settlement pending.', 1)
ON DUPLICATE KEY UPDATE
  `invoice_number` = VALUES(`invoice_number`),
  `customer_id` = VALUES(`customer_id`),
  `invoice_date` = VALUES(`invoice_date`),
  `due_date` = VALUES(`due_date`),
  `subtotal` = VALUES(`subtotal`),
  `tax_rate` = VALUES(`tax_rate`),
  `tax_amount` = VALUES(`tax_amount`),
  `total_amount` = VALUES(`total_amount`),
  `paid_amount` = VALUES(`paid_amount`),
  `balance` = VALUES(`balance`),
  `status` = VALUES(`status`),
  `notes` = VALUES(`notes`);

INSERT INTO `payments_made` (`id`, `payment_number`, `vendor_id`, `bill_id`, `payment_date`, `amount`, `amount_paid`, `payment_method`, `reference_number`, `reference_no`, `notes`, `approved_by`, `recorded_by`, `created_by`) VALUES
(9501, 'PMT-2026-601', 9102, 9302, '2026-04-21', 48250.00, 48250.00, 'bank_transfer', 'HBG-484920', 'HBG-484920', 'Partial settlement for utility servicing.', 1, 1, 1)
ON DUPLICATE KEY UPDATE
  `payment_number` = VALUES(`payment_number`),
  `vendor_id` = VALUES(`vendor_id`),
  `bill_id` = VALUES(`bill_id`),
  `payment_date` = VALUES(`payment_date`),
  `amount` = VALUES(`amount`),
  `amount_paid` = VALUES(`amount_paid`),
  `payment_method` = VALUES(`payment_method`),
  `reference_number` = VALUES(`reference_number`),
  `reference_no` = VALUES(`reference_no`),
  `notes` = VALUES(`notes`);

INSERT INTO `payments_received` (`id`, `payment_number`, `customer_id`, `invoice_id`, `payment_date`, `amount`, `amount_paid`, `payment_method`, `reference_number`, `reference_no`, `notes`, `recorded_by`, `created_by`) VALUES
(9601, 'RCV-2026-701', 9202, 9402, '2026-04-27', 90000.00, 90000.00, 'bank_transfer', 'BHS-220711', 'BHS-220711', 'Collection from Blue Harbor Suites.', 1, 1)
ON DUPLICATE KEY UPDATE
  `payment_number` = VALUES(`payment_number`),
  `customer_id` = VALUES(`customer_id`),
  `invoice_id` = VALUES(`invoice_id`),
  `payment_date` = VALUES(`payment_date`),
  `amount` = VALUES(`amount`),
  `amount_paid` = VALUES(`amount_paid`),
  `payment_method` = VALUES(`payment_method`),
  `reference_number` = VALUES(`reference_number`),
  `reference_no` = VALUES(`reference_no`),
  `notes` = VALUES(`notes`);

INSERT INTO `adjustments` (`id`, `adjustment_number`, `adjustment_type`, `vendor_id`, `customer_id`, `bill_id`, `invoice_id`, `amount`, `reason`, `adjustment_date`, `recorded_by`) VALUES
(9701, 'ADJ-P-2026-101', 'credit_memo', 9101, NULL, 9301, NULL, 12450.00, 'Quality claim for returned produce batch.', '2026-04-25', 1),
(9702, 'ADJ-R-2026-201', 'debit_memo', NULL, 9203, NULL, 9403, 6400.00, 'Rebilling for additional banquet service hours.', '2026-04-29', 1)
ON DUPLICATE KEY UPDATE
  `adjustment_number` = VALUES(`adjustment_number`),
  `adjustment_type` = VALUES(`adjustment_type`),
  `vendor_id` = VALUES(`vendor_id`),
  `customer_id` = VALUES(`customer_id`),
  `bill_id` = VALUES(`bill_id`),
  `invoice_id` = VALUES(`invoice_id`),
  `amount` = VALUES(`amount`),
  `reason` = VALUES(`reason`),
  `adjustment_date` = VALUES(`adjustment_date`);

INSERT INTO `budgets` (`id`, `department_id`, `budget_year`, `budget_name`, `description`, `total_budgeted`, `status`, `created_by`, `approved_by`, `vendor_id`, `start_date`, `end_date`) VALUES
(9901, 13, '2026', 'FY2026 Core Operations Plan', 'Core operating plan for finance, people, and operations.', 4200000.00, 'active', 1, 1, NULL, '2026-01-01', '2026-12-31'),
(9902, 12, '2026', 'Guest Experience Acceleration', 'Revenue and customer experience acceleration budget.', 1325000.00, 'active', 1, 1, NULL, '2026-04-01', '2026-09-30'),
(9903, 8, '2026', 'Facilities Reliability Program', 'Preventive maintenance and facility reliability budget.', 1680000.00, 'active', 1, 1, 9103, '2026-02-01', '2026-11-30')
ON DUPLICATE KEY UPDATE
  `department_id` = VALUES(`department_id`),
  `budget_year` = VALUES(`budget_year`),
  `budget_name` = VALUES(`budget_name`),
  `description` = VALUES(`description`),
  `total_budgeted` = VALUES(`total_budgeted`),
  `status` = VALUES(`status`),
  `vendor_id` = VALUES(`vendor_id`),
  `start_date` = VALUES(`start_date`),
  `end_date` = VALUES(`end_date`);

INSERT INTO `budget_items` (`id`, `budget_id`, `category_id`, `account_id`, `budgeted_amount`, `actual_amount`, `variance`, `notes`, `department_id`, `vendor_id`) VALUES
(9911, 9901, 38, NULL, 1320000.00, 978500.00, 341500.00, 'Operations allocation.', 13, NULL),
(9912, 9901, 31, NULL, 860000.00, 704000.00, 156000.00, 'Workforce support allocation.', 18, NULL),
(9913, 9902, 34, NULL, 725000.00, 612400.00, 112600.00, 'Revenue programs allocation.', 12, NULL),
(9914, 9903, 33, NULL, 980000.00, 568400.00, 411600.00, 'Facilities maintenance allocation.', 8, 9103)
ON DUPLICATE KEY UPDATE
  `budget_id` = VALUES(`budget_id`),
  `category_id` = VALUES(`category_id`),
  `account_id` = VALUES(`account_id`),
  `budgeted_amount` = VALUES(`budgeted_amount`),
  `actual_amount` = VALUES(`actual_amount`),
  `variance` = VALUES(`variance`),
  `notes` = VALUES(`notes`),
  `department_id` = VALUES(`department_id`),
  `vendor_id` = VALUES(`vendor_id`);

INSERT INTO `budget_adjustments` (`id`, `budget_id`, `department_id`, `vendor_id`, `adjustment_type`, `amount`, `reason`, `status`, `requested_by`, `approved_by`, `effective_date`) VALUES
(9921, 9901, 18, 9102, 'increase', 85000.00, 'Higher-than-expected workforce reimbursements for April cycle.', 'pending', 1, NULL, '2026-05-06'),
(9922, 9903, 8, 9103, 'transfer', 64000.00, 'Critical refrigeration preventive maintenance package.', 'approved', 1, 1, '2026-05-10')
ON DUPLICATE KEY UPDATE
  `budget_id` = VALUES(`budget_id`),
  `department_id` = VALUES(`department_id`),
  `vendor_id` = VALUES(`vendor_id`),
  `adjustment_type` = VALUES(`adjustment_type`),
  `amount` = VALUES(`amount`),
  `reason` = VALUES(`reason`),
  `status` = VALUES(`status`),
  `approved_by` = VALUES(`approved_by`),
  `effective_date` = VALUES(`effective_date`);

INSERT INTO `financial_work_queue` (`id`, `queue_type`, `employee_name`, `department`, `position`, `period_display`, `claim_type`, `total_amount`, `employee_count`, `submitted_by`, `submitted_at`, `status`, `can_process`, `reference_number`) VALUES
('CLM-301', 'hr_claim', 'Jules Navarro', 'Operations', NULL, NULL, 'Travel Reimbursement', 12880.00, 0, NULL, '2026-04-28 09:15:00', 'approved', 1, 'REQ-CLM-301'),
('CLM-302', 'hr_claim', 'Mira Santos', 'People Operations', NULL, NULL, 'Medical Reimbursement', 9650.00, 0, NULL, '2026-04-29 13:42:00', 'approved', 1, 'REQ-CLM-302'),
('PAY-APR-01', 'hr_payroll', NULL, NULL, NULL, 'Apr 16-30, 2026', NULL, 542880.00, 48, 'Compensation Team', '2026-04-30 17:20:00', 'pending', 1, 'PAYROLL-PAY-APR-01'),
('INC-801', 'hr_incentive', 'Janelle Cruz', 'Sales', 'Account Executive', 'April 2026', NULL, 18500.00, 0, NULL, NULL, 'pending', 1, 'INCENTIVE-INC-801'),
('INC-802', 'hr_incentive', 'Rafael Ong', 'Operations', 'Shift Lead', 'April 2026', NULL, 12400.00, 0, NULL, NULL, 'pending', 1, 'INCENTIVE-INC-802')
ON DUPLICATE KEY UPDATE
  `queue_type` = VALUES(`queue_type`),
  `employee_name` = VALUES(`employee_name`),
  `department` = VALUES(`department`),
  `position` = VALUES(`position`),
  `period_display` = VALUES(`period_display`),
  `claim_type` = VALUES(`claim_type`),
  `total_amount` = VALUES(`total_amount`),
  `employee_count` = VALUES(`employee_count`),
  `submitted_by` = VALUES(`submitted_by`),
  `submitted_at` = VALUES(`submitted_at`),
  `reference_number` = VALUES(`reference_number`);

INSERT INTO `disbursements` (`id`, `disbursement_number`, `disbursement_date`, `payee`, `amount`, `payment_method`, `reference_number`, `purpose`, `department`, `account_id`, `approved_by`, `recorded_by`, `status`, `needs_approval`, `approval_status`) VALUES
(9801, 'DISB-20260429-301', '2026-04-29', 'Jules Navarro', 12880.00, 'bank_transfer', 'REQ-CLM-301', 'Processed HR3 claim: Travel Reimbursement', 'Operations', 81, 1, 1, 'paid', 0, 'not_required'),
(9802, 'DISB-20260430-302', '2026-04-30', 'Harborline Utilities Group', 48250.00, 'bank_transfer', 'HBG-484920', 'Partial AP settlement for utilities.', 'Finance', 81, 1, 1, 'pending', 0, 'not_required'),
(9803, 'DISB-20260501-303', '2026-05-01', 'Payroll Apr 16-30, 2026', 542880.00, 'bank_transfer', 'PAYROLL-PAY-APR-01', 'Payroll batch pending release.', 'People Operations', 158, 1, 1, 'pending', 0, 'not_required')
ON DUPLICATE KEY UPDATE
  `disbursement_number` = VALUES(`disbursement_number`),
  `disbursement_date` = VALUES(`disbursement_date`),
  `payee` = VALUES(`payee`),
  `amount` = VALUES(`amount`),
  `payment_method` = VALUES(`payment_method`),
  `reference_number` = VALUES(`reference_number`),
  `purpose` = VALUES(`purpose`),
  `department` = VALUES(`department`),
  `account_id` = VALUES(`account_id`),
  `status` = VALUES(`status`);
