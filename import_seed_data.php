<?php
/**
 * Financial Seed Data Import Utility
 * Imports seed_realistic_financial_data.sql into the database
 */

require_once 'includes/database.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
    
    // Read the SQL file
    $sqlFile = __DIR__ . '/seed_realistic_financial_data.sql';
    
    if (!file_exists($sqlFile)) {
        echo "ERROR: seed_realistic_financial_data.sql not found\n";
        exit(1);
    }
    
    $sql = file_get_contents($sqlFile);
    
    // Split by semicolon and execute each statement
    $statements = array_filter(array_map('trim', preg_split('/;(?=(?:[^\']*\'[^\']*\')*[^\']*$)/', $sql)));
    
    echo "Starting import...\n";
    $count = 0;
    
    foreach ($statements as $statement) {
        if (empty($statement) || strpos($statement, '--') === 0) {
            continue;
        }
        
        try {
            $pdo->exec($statement);
            $count++;
            echo ".";
        } catch (PDOException $e) {
            echo "\nERROR executing statement:\n";
            echo $statement . "\n";
            echo "Error: " . $e->getMessage() . "\n";
            exit(1);
        }
    }
    
    echo "\n\n✓ Successfully imported $count SQL statements\n";
    echo "✓ Financial seed data has been loaded into the database\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
?>
