<?php
// auth_check.php
// Include this at the TOP of any protected page.
// Optionally set $required_role = 'lecturer' or 'student' BEFORE including this file
// to restrict a page to a single role.

require_once "config.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

if (isset($required_role) && $_SESSION["role"] !== $required_role) {
    // Logged in, but wrong role trying to access this page
    header("Location: login.php");
    exit;
}
?>