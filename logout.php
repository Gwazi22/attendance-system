<?php
require_once "config.php";

// Clear all session variables and destroy the session
$_SESSION = [];
session_destroy();

header("Location: login.php");
exit;
?>