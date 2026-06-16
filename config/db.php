<?php

$host = "sqlXXX.infinityfree.com";
$dbname = "if0_XXXXXX_broker";
$username = "if0_XXXXXX";
$password = "YOUR_PASSWORD";

$conn = new mysqli($host, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

?>
