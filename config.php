<?php
// config.php - Database connection

$db_host = "localhost";
$db_user = "root";      // default XAMPP username
$db_pass = "";          // default XAMPP password (empty)
$db_name = "attendance_system";

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Start session for all pages that include this file
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>