<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/access_control.php';

requireApprovedProvider();

// Providers cannot edit a service once it has been added.
header('Location: services.php?error=not_allowed');
exit();
?>
