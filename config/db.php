<?php

// Load environment variables
$host = getenv('DB_HOST') ?: 'sqlXXX.infinityfree.com';
$dbname = getenv('DB_NAME') ?: 'if0_42196148_broker_db';
$username = getenv('DB_USER') ?: 'if0_42196148';
$password = getenv('DB_PASS') ?: '';
$port = getenv('DB_PORT') ?: 3306;

// Attempt connection with error handling
$conn = @new mysqli($host, $username, $password, $dbname, $port);

if ($conn->connect_error) {
    // Log error (in production, write to file)
    error_log('Database connection failed: ' . $conn->connect_error);
    die(json_encode(['error' => 'Database connection failed. Please try again later.']));
}

// Set charset
$conn->set_charset('utf8mb4');

?>
