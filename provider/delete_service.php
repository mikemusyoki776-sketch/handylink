<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/access_control.php';

requireApprovedProvider();

$user_id = $_SESSION['user_id'];

$stmt = $conn->prepare(
    "SELECT id FROM providers WHERE user_id = ? LIMIT 1"
);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $stmt->close();
    header("Location: services.php?error=provider_not_found");
    exit();
}

$provider = $result->fetch_assoc();
$provider_id = (int) $provider['id'];
$stmt->close();

$stmt = $conn->prepare(
    "SELECT id FROM services WHERE provider_id = ? LIMIT 1"
);
$stmt->bind_param("i", $provider_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $stmt->close();
    header("Location: services.php?error=already_added");
    exit();
}

$stmt->close();

// Only allow POST requests.
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: services.php");
    exit();
}

// Providers cannot delete services themselves.
header("Location: services.php?error=not_allowed");
exit();

?>