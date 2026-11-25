<?php

session_start();

if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "provider") {
    header("Location: ../login.php");
    exit();
}

require_once "../config/database.php";

$user_id = $_SESSION["user_id"];

$provider_sql = "SELECT id FROM providers WHERE user_id = ? LIMIT 1";

$provider_stmt = $conn->prepare($provider_sql);
$provider_stmt->bind_param("i", $user_id);
$provider_stmt->execute();

$provider_result = $provider_stmt->get_result();

if ($provider_result->num_rows === 0) {
    die("Provider profile not found.");
}

$provider = $provider_result->fetch_assoc();
$provider_id = $provider["id"];

$provider_stmt->close();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: availability.php");
    exit();
}

$availability_id = $_POST["availability_id"] ?? 0;

$sql = "DELETE FROM availability
        WHERE id = ? AND provider_id = ?";

$stmt = $conn->prepare($sql);

$stmt->bind_param(
    "ii",
    $availability_id,
    $provider_id
);

$stmt->execute();

$stmt->close();

header("Location: availability.php?deleted=1");
exit();