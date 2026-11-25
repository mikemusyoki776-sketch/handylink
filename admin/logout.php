<?php
session_start();

// Clear the current session
$_SESSION = [];

// Destroy the session
session_destroy();

// Redirect to the shared HandyLink login page
header("Location: .../login_action.php");
exit;
?>
