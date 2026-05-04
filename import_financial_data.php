<?php
/**
 * Import Financial Data Script
 * This script imports the COGS and detailed transaction data into the database
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Get the script directory and require the config and database files
$scriptDir = __DIR__;
require_once $scriptDir . '/config.php';
require_once $scriptDir . '/includes/database.php';

try {
    echo "Connecting to database...\n";
    $db = Database::getInstance()->getConnection();
    
    // Read the SQL file
    $sqlFile = $scriptDir . '/seed_financial_data.sql';
    if (!file_exists($sqlFile)) {
        die("Error: SQL file not found at $sqlFile\n");
    }
    
    $sqlContent = file_get_contents($sqlFile);
    if ($sqlContent === false) {
        die("Error: Could not read SQL file\n");
    }
    
    echo "SQL file loaded successfully\n";
    echo "File size: " . strlen($sqlContent) . " bytes\n\n";
    
    // Split the SQL file into individual statements
    // Handle comments and multi-line statements
    $statements = [];
    $currentStatement = '';
    $inComment = false;
    
    $lines = explode("\n", $sqlContent);
    
    foreach ($lines as $line) {
        // Skip comments
        if (strpos(trim($line), '--') === 0) {
            continue;
        }
        
        // Skip empty lines
        if (trim($line) === '') {
            continue;
        }
        
        $currentStatement .= $line . "\n";
        
        // Check if statement ends with semicolon
        if (substr(trim($line), -1) === ';') {
            $statements[] = trim($currentStatement);
            $currentStatement = '';
        }
    }
    
    // Add any remaining statement
    if (trim($currentStatement) !== '') {
        $statements[] = trim($currentStatement);
    }
    
    echo "Found " . count($statements) . " SQL statements\n\n";
    
    // Execute each statement
    $successCount = 0;
    $errorCount = 0;
    
    foreach ($statements as $index => $statement) {
        if (trim($statement) === '' || strpos(trim($statement), '--') === 0) {
            continue;
        }
        
        try {
            echo "Executing statement " . ($index + 1) . "...\n";
            
            // Show first 100 characters of the statement
            $preview = substr($statement, 0, 100);
            if (strlen($statement) > 100) {
                $preview .= '...';
            }
            echo "  SQL: " . str_replace("\n", " ", $preview) . "\n";
            
            $db->exec($statement);
            echo "  ✓ Success\n";
            $successCount++;
        } catch (PDOException $e) {
            echo "  ✗ Error: " . $e->getMessage() . "\n";
            echo "  Statement: " . substr($statement, 0, 200) . "...\n";
            $errorCount++;
        }
    }
    
    echo "\n========================================\n";
    echo "Import Summary:\n";
    echo "  Successful: $successCount\n";
    echo "  Errors: $errorCount\n";
    echo "========================================\n";
    
    if ($errorCount === 0) {
        echo "\n✓ All financial data imported successfully!\n";
        echo "\nThe following data has been added:\n";
        echo "  - COGS (Cost of Goods Sold) accounts\n";
        echo "  - Revenue accounts with detailed breakdowns\n";
        echo "  - Journal entries for:\n";
        echo "    * Room inventory cost: PHP 5,500\n";
        echo "    * F&B inventory cost: PHP 3,200\n";
        echo "    * Supplies cost: PHP 1,812.52\n";
        echo "  - Detailed revenue entries for:\n";
        echo "    * Standard Room Sales: PHP 7,500\n";
        echo "    * Deluxe Room Sales: PHP 4,298\n";
        echo "    * Suite Sales: PHP 3,200\n";
        echo "    * Restaurant Sales: PHP 2,100\n";
        echo "    * Bar Sales: PHP 900\n";
        echo "\nTotal COGS: PHP 10,512.52\n";
        echo "Total Revenue: PHP 18,998.00\n";
    } else {
        echo "\n⚠ Some statements failed to execute. Check the errors above.\n";
    }
    
} catch (Exception $e) {
    echo "Fatal Error: " . $e->getMessage() . "\n";
    die(1);
}
?>
