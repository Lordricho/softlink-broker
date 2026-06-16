<?php

$host = "sqlXXX.infinityfree.com";   // we will replace this if different
$dbname = "if0_42196148_broker_db";  // your real database name
$username = "if0_42196148";          // your DB username
$password = "YOUR_DB_PASSWORD";

$conn = new mysqli($host, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

?>
