<?php

/**
 * PostgreSQL Database Connection
 * Uses PDO for better compatibility and security
 */

$db_host = getenv('PGHOST') ?: getenv('DB_HOST') ?: 'localhost';
$db_name = getenv('PGDATABASE') ?: getenv('DB_NAME') ?: 'softlink_broker';
$db_user = getenv('PGUSER') ?: getenv('DB_USER') ?: 'postgres';
$db_pass = getenv('PGPASSWORD') ?: getenv('DB_PASS') ?: '';
$db_port = getenv('PGPORT') ?: getenv('DB_PORT') ?: 5432;

try {
    // DSN for PostgreSQL
    $dsn = "pgsql:host={$db_host};port={$db_port};dbname={$db_name}";
    
    // Create PDO connection
    $conn = new PDO($dsn, $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5
    ]);
    
    // Test connection
    $conn->query('SELECT 1');
    
} catch (PDOException $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    http_response_code(500);
    die(json_encode(['error' => 'Database connection failed. Please try again later.']));
}

?>
