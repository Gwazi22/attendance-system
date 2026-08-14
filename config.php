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

// Set PHP timezone to match Nigeria (WAT, UTC+1)
date_default_timezone_set('Africa/Lagos');

// Set MySQL session timezone to match (UTC+1)
$conn->query("SET time_zone = '+01:00'");

// Start session for all pages that include this file
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>