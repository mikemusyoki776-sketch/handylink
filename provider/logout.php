<?php
session_start();

// Clear all session variables
$_SESSION = [];

// Destroy the session
session_destroy();

// Redirect to the shared login page
header("Location: /handylink/login.php");
exit();