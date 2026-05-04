# Financial Data Import Instructions

## Quick Summary of Changes

I've created comprehensive financial data that will populate your Reports with:

### 1. **COGS Data** ✓
- Room Inventory Cost: PHP 5,500.00
- Food & Beverage Inventory Cost: PHP 3,200.00  
- Cleaning Supplies Cost: PHP 1,812.52
- **Total COGS: PHP 10,512.52**

### 2. **Detailed Revenue Breakdown** ✓
- Standard Room Sales: PHP 7,500.00
- Deluxe Room Sales: PHP 4,298.00
- Suite Sales: PHP 3,200.00
- Restaurant Sales: PHP 2,100.00
- Bar Sales: PHP 900.00
- **Total Revenue: PHP 18,998.00**

### 3. **New Chart of Accounts**
Added these expense and revenue accounts:
- 5100 - Cost of Goods Sold (main)
- 5110 - Cost of Inventory - Rooms
- 5120 - Cost of Inventory - Food & Beverage
- 5130 - Cost of Supplies
- 5140 - Laundry & Linens
- 4100 - Room Sales Revenue (main)
- 4110 - Standard Room Sales
- 4120 - Deluxe Room Sales
- 4130 - Suite Sales Revenue
- 4200 - Food & Beverage Sales
- 4210 - Restaurant Sales
- 4220 - Bar Sales

---

## How to Import via phpMyAdmin

### Method 1: Import the SQL File
1. Open phpMyAdmin in your browser
2. Click on your database (`fina_financialmngmnt`)
3. Click the **Import** tab at the top
4. Click **Choose File** and select: `seed_financial_data.sql`
5. At the bottom, click **Go** to execute

### Method 2: Manual SQL Execution in phpMyAdmin
1. Go to phpMyAdmin → Select your database
2. Click the **SQL** tab
3. Copy all SQL from the file content below
4. Paste into the SQL editor
5. Click **Go**

---

## SQL Content to Execute

The file `seed_financial_data.sql` contains all necessary SQL statements to:
- Create new Chart of Accounts entries
- Add journal entries for COGS transactions
- Add detailed revenue journal entries

All data is dated **2026-05-01** to align with your current system date.

---

## What Happens After Import

After successfully importing:

1. **Reports Page** will now show:
   - **Profit & Loss Statement** with detailed revenue and COGS breakdown
   - Revenue sources clearly itemized
   - COGS no longer showing PHP 0.00

2. **Individual Account Details** will display transaction history

3. **Income Statement** will display:
   - Total Revenue: PHP 18,998.00
   - Total COGS: PHP 10,512.52
   - Gross Profit: PHP 8,485.48
   - (Combined with existing operating expenses of PHP 31,210.52)

---

## Files Created

1. **seed_financial_data.sql** - Main data file (ready for import)
2. **import_financial_data.php** - PHP script (requires MySQL service running)
3. **FINANCIAL_DATA_IMPORT.md** - This guide

---

## Troubleshooting

### If you get "Account code already exists" error:
This is normal if you've already run the import. The SQL uses `ON DUPLICATE KEY UPDATE` to handle re-imports safely.

### If journal entries don't appear:
1. Verify all chart_of_accounts exist (use Admin → Chart of Accounts)
2. Check that the accounts are marked as active (is_active = 1)
3. Verify the date range in your reports matches 2026-05-01

### To verify the import worked:
1. Go to Admin → General Ledger
2. Filter by date range starting 2026-05-01
3. You should see the new journal entries (JE-20260504-001, etc.)

---

## Next Steps (Optional)

To further enhance your reports, you could:
1. Add more revenue categories (room service, parking, etc.)
2. Add break-even analysis data
3. Create monthly variance reports
4. Add budget allocation data

Consult the README_FORECAST.md and README_BUDGET_ALLOCATION.md for additional features.
