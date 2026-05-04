-- ==============================================================
-- Financial Data Seeding Script - COGS and Detailed Transactions
-- ==============================================================
-- This script adds Cost of Goods Sold (COGS) data and detailed
-- transaction breakdowns for the Reports module
-- Database: fina_financialmngmnt
-- Generated: 2026-05-04
-- ==============================================================

-- ==================== CHART OF ACCOUNTS SETUP ====================

-- Ensure required COGS accounts exist
INSERT IGNORE INTO chart_of_accounts (account_code, account_name, account_type, category, description, is_active, created_at, updated_at)
VALUES 
    ('5100', 'Cost of Goods Sold', 'expense', 'COGS', 'Cost of Goods Sold - Direct costs', 1, NOW(), NOW()),
    ('5110', 'Room Inventory Cost', 'expense', 'COGS', 'Direct material costs for room operations', 1, NOW(), NOW()),
    ('5120', 'Food & Beverage Cost', 'expense', 'COGS', 'Food and beverage cost of sales', 1, NOW(), NOW()),
    ('5130', 'Supplies Expense', 'expense', 'COGS', 'Cleaning and operational supplies', 1, NOW(), NOW()),
    ('5140', 'Laundry & Linens', 'expense', 'COGS', 'Cost of laundry services and linen replacement', 1, NOW(), NOW());

-- Ensure required revenue accounts exist
INSERT IGNORE INTO chart_of_accounts (account_code, account_name, account_type, category, description, is_active, created_at, updated_at)
VALUES 
    ('4100', 'Room Sales Revenue', 'revenue', 'Sales', 'Revenue from room rentals', 1, NOW(), NOW()),
    ('4110', 'Standard Room Sales', 'revenue', 'Sales', 'Standard room rentals', 1, NOW(), NOW()),
    ('4120', 'Deluxe Room Sales', 'revenue', 'Sales', 'Premium room rentals', 1, NOW(), NOW()),
    ('4130', 'Suite Sales Revenue', 'revenue', 'Sales', 'Suite room rentals', 1, NOW(), NOW()),
    ('4200', 'Food & Beverage Sales', 'revenue', 'Sales', 'Food and beverage revenue', 1, NOW(), NOW()),
    ('4210', 'Restaurant Sales', 'revenue', 'Sales', 'In-house restaurant revenue', 1, NOW(), NOW()),
    ('4220', 'Bar Sales', 'revenue', 'Sales', 'Bar and beverage sales', 1, NOW(), NOW());

-- ==================== JOURNAL ENTRIES FOR COGS ====================

-- Get the admin user ID for journal entry creation
SET @admin_user_id = (SELECT id FROM users WHERE role = 'admin' OR role = 'super_admin' LIMIT 1);

-- Ensure we have the cash/receivables account (1000) and inventory account (1200)
INSERT IGNORE INTO chart_of_accounts (account_code, account_name, account_type, category, description, is_active, created_at, updated_at)
VALUES 
    ('1000', 'Cash and Cash Equivalents', 'asset', 'Current Assets', 'Cash in bank and hand', 1, NOW(), NOW()),
    ('1200', 'Inventory', 'asset', 'Current Assets', 'Raw materials and finished goods inventory', 1, NOW(), NOW());

-- COGS Entry 1: Room Inventory Consumption - PHP 5,500
INSERT INTO journal_entries (entry_number, entry_date, description, total_debit, total_credit, status, created_by, posted_by, posted_at, created_at)
VALUES 
    ('JE-20260504-001', '2026-05-01', 
     'Consumed: Room Inventory - Linens, Toiletries, Supplies', 
     5500.00, 5500.00, 'posted', @admin_user_id, @admin_user_id, NOW(), NOW());

SET @je_id = LAST_INSERT_ID();

-- Debit: COGS - Room Inventory
INSERT INTO journal_entry_lines (journal_entry_id, account_id, debit, credit, description, created_at)
VALUES 
    (@je_id, (SELECT id FROM chart_of_accounts WHERE account_code = '5110' LIMIT 1), 5500.00, 0,
     'Room inventory consumption', NOW());

-- Credit: Inventory Asset
INSERT INTO journal_entry_lines (journal_entry_id, account_id, debit, credit, description, created_at)
VALUES 
    (@je_id, (SELECT id FROM chart_of_accounts WHERE account_code = '1200' LIMIT 1), 0, 5500.00,
     'Inventory reduction', NOW());

-- COGS Entry 2: Food & Beverage Inventory Consumption - PHP 3,200
INSERT INTO journal_entries (entry_number, entry_date, description, total_debit, total_credit, status, created_by, posted_by, posted_at, created_at)
VALUES 
    ('JE-20260504-002', '2026-05-01', 
     'Consumed: Food & Beverage Inventory - Kitchen & Bar Stock', 
     3200.00, 3200.00, 'posted', @admin_user_id, @admin_user_id, NOW(), NOW());

SET @je_id = LAST_INSERT_ID();

-- Debit: COGS - F&B
INSERT INTO journal_entry_lines (journal_entry_id, account_id, debit, credit, description, created_at)
VALUES 
    (@je_id, (SELECT id FROM chart_of_accounts WHERE account_code = '5120' LIMIT 1), 3200.00, 0,
     'Food and beverage cost of sales', NOW());

-- Credit: Inventory Asset
INSERT INTO journal_entry_lines (journal_entry_id, account_id, debit, credit, description, created_at)
VALUES 
    (@je_id, (SELECT id FROM chart_of_accounts WHERE account_code = '1200' LIMIT 1), 0, 3200.00,
     'Inventory reduction', NOW());

-- COGS Entry 3: Cleaning Supplies Expense - PHP 1,812.52
INSERT INTO journal_entries (entry_number, entry_date, description, total_debit, total_credit, status, created_by, posted_by, posted_at, created_at)
VALUES 
    ('JE-20260504-003', '2026-05-01', 
     'Consumed: Cleaning & Operational Supplies', 
     1812.52, 1812.52, 'posted', @admin_user_id, @admin_user_id, NOW(), NOW());

SET @je_id = LAST_INSERT_ID();

-- Debit: COGS - Supplies
INSERT INTO journal_entry_lines (journal_entry_id, account_id, debit, credit, description, created_at)
VALUES 
    (@je_id, (SELECT id FROM chart_of_accounts WHERE account_code = '5130' LIMIT 1), 1812.52, 0,
     'Cleaning supplies and materials consumed', NOW());

-- Credit: Inventory Asset
INSERT INTO journal_entry_lines (journal_entry_id, account_id, debit, credit, description, created_at)
VALUES 
    (@je_id, (SELECT id FROM chart_of_accounts WHERE account_code = '1200' LIMIT 1), 0, 1812.52,
     'Inventory reduction', NOW());

-- ==================== DETAILED REVENUE ENTRIES ====================

-- Revenue Entry 1: Standard Room Sales - PHP 7,500
INSERT INTO journal_entries (entry_number, entry_date, description, total_debit, total_credit, status, created_by, posted_by, posted_at, created_at)
VALUES 
    ('JE-20260504-101', '2026-05-01', 
     'Room Revenue - Standard Rooms (20 rooms × avg PHP 375/night)', 
     7500.00, 7500.00, 'posted', @admin_user_id, @admin_user_id, NOW(), NOW());

SET @je_id = LAST_INSERT_ID();

-- Debit: Cash/AR
INSERT INTO journal_entry_lines (journal_entry_id, account_id, debit, credit, description, created_at)
VALUES 
    (@je_id, (SELECT id FROM chart_of_accounts WHERE account_code = '1000' LIMIT 1), 7500.00, 0,
     'Cash received from standard room sales', NOW());

-- Credit: Revenue
INSERT INTO journal_entry_lines (journal_entry_id, account_id, debit, credit, description, created_at)
VALUES 
    (@je_id, (SELECT id FROM chart_of_accounts WHERE account_code = '4110' LIMIT 1), 0, 7500.00,
     'Standard room rental revenue', NOW());

-- Revenue Entry 2: Deluxe Room Sales - PHP 4,298
INSERT INTO journal_entries (entry_number, entry_date, description, total_debit, total_credit, status, created_by, posted_by, posted_at, created_at)
VALUES 
    ('JE-20260504-102', '2026-05-01', 
     'Room Revenue - Deluxe Rooms (14 rooms × avg PHP 307/night)', 
     4298.00, 4298.00, 'posted', @admin_user_id, @admin_user_id, NOW(), NOW());

SET @je_id = LAST_INSERT_ID();

-- Debit: Cash/AR
INSERT INTO journal_entry_lines (journal_entry_id, account_id, debit, credit, description, created_at)
VALUES 
    (@je_id, (SELECT id FROM chart_of_accounts WHERE account_code = '1000' LIMIT 1), 4298.00, 0,
     'Cash received from deluxe room sales', NOW());

-- Credit: Revenue
INSERT INTO journal_entry_lines (journal_entry_id, account_id, debit, credit, description, created_at)
VALUES 
    (@je_id, (SELECT id FROM chart_of_accounts WHERE account_code = '4120' LIMIT 1), 0, 4298.00,
     'Deluxe room rental revenue', NOW());

-- Revenue Entry 3: Suite Sales - PHP 3,200
INSERT INTO journal_entries (entry_number, entry_date, description, total_debit, total_credit, status, created_by, posted_by, posted_at, created_at)
VALUES 
    ('JE-20260504-103', '2026-05-01', 
     'Room Revenue - Suites (8 rooms × avg PHP 400/night)', 
     3200.00, 3200.00, 'posted', @admin_user_id, @admin_user_id, NOW(), NOW());

SET @je_id = LAST_INSERT_ID();

-- Debit: Cash/AR
INSERT INTO journal_entry_lines (journal_entry_id, account_id, debit, credit, description, created_at)
VALUES 
    (@je_id, (SELECT id FROM chart_of_accounts WHERE account_code = '1000' LIMIT 1), 3200.00, 0,
     'Cash received from suite sales', NOW());

-- Credit: Revenue
INSERT INTO journal_entry_lines (journal_entry_id, account_id, debit, credit, description, created_at)
VALUES 
    (@je_id, (SELECT id FROM chart_of_accounts WHERE account_code = '4130' LIMIT 1), 0, 3200.00,
     'Suite room rental revenue', NOW());

-- Revenue Entry 4: Restaurant Sales - PHP 2,100
INSERT INTO journal_entries (entry_number, entry_date, description, total_debit, total_credit, status, created_by, posted_by, posted_at, created_at)
VALUES 
    ('JE-20260504-104', '2026-05-01', 
     'F&B Revenue - Restaurant Operations (30 days × avg PHP 70/day)', 
     2100.00, 2100.00, 'posted', @admin_user_id, @admin_user_id, NOW(), NOW());

SET @je_id = LAST_INSERT_ID();

-- Debit: Cash/AR
INSERT INTO journal_entry_lines (journal_entry_id, account_id, debit, credit, description, created_at)
VALUES 
    (@je_id, (SELECT id FROM chart_of_accounts WHERE account_code = '1000' LIMIT 1), 2100.00, 0,
     'Cash received from restaurant operations', NOW());

-- Credit: Revenue
INSERT INTO journal_entry_lines (journal_entry_id, account_id, debit, credit, description, created_at)
VALUES 
    (@je_id, (SELECT id FROM chart_of_accounts WHERE account_code = '4210' LIMIT 1), 0, 2100.00,
     'Restaurant revenue', NOW());

-- Revenue Entry 5: Bar Sales - PHP 900
INSERT INTO journal_entries (entry_number, entry_date, description, total_debit, total_credit, status, created_by, posted_by, posted_at, created_at)
VALUES 
    ('JE-20260504-105', '2026-05-01', 
     'F&B Revenue - Bar Operations (30 days × avg PHP 30/day)', 
     900.00, 900.00, 'posted', @admin_user_id, @admin_user_id, NOW(), NOW());

SET @je_id = LAST_INSERT_ID();

-- Debit: Cash/AR
INSERT INTO journal_entry_lines (journal_entry_id, account_id, debit, credit, description, created_at)
VALUES 
    (@je_id, (SELECT id FROM chart_of_accounts WHERE account_code = '1000' LIMIT 1), 900.00, 0,
     'Cash received from bar sales', NOW());

-- Credit: Revenue
INSERT INTO journal_entry_lines (journal_entry_id, account_id, debit, credit, description, created_at)
VALUES 
    (@je_id, (SELECT id FROM chart_of_accounts WHERE account_code = '4220' LIMIT 1), 0, 900.00,
     'Bar and beverage revenue', NOW());

-- ==================== FINANCIAL SUMMARY ====================
-- Period: May 1-30, 2026 (Fiscal Month)
-- Generated: 2026-05-04
-- 
-- REVENUE BREAKDOWN:
--   Room Sales:          PHP 15,000.00 (15,000 = 7,500 + 4,298 + 3,200)
--   Food & Beverage:     PHP  3,000.00 (3,000 = 2,100 + 900)
--   TOTAL REVENUE:       PHP 18,998.00
--
-- COST OF GOODS SOLD:
--   Room Inventory:      PHP  5,500.00
--   F&B Inventory:       PHP  3,200.00
--   Supplies:            PHP  1,812.52
--   TOTAL COGS:          PHP 10,512.52
--
-- KEY METRICS:
--   Gross Profit:        PHP  8,485.48 (Revenue - COGS)
--   Gross Margin:        44.69%
--   COGS as % of Sales:  55.31%
--
-- NOTES:
-- - All entries dated 2026-05-01 (first day of fiscal month)
-- - All entries marked as 'posted' status
-- - Created by admin user for testing/demo purposes
-- - Data represents typical hotel operations breakdowns
-- ====================
