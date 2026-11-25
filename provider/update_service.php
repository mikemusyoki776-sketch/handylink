<?php
session_start();

if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "provider") {
    header("Location: ../login.php");
    exit();
}

require_once "../config/database.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: services.php");
    exit();
}

$user_id = $_SESSION["user_id"];

$service_id = intval($_POST["service_id"] ?? 0);
$service_name = trim($_POST["service_name"] ?? "");
$category_id = intval($_POST["category_id"] ?? 0);
$description = trim($_POST["description"] ?? "");
$price = floatval($_POST["price"] ?? 0);
$location = trim($_POST["location"] ?? "");
$status = $_POST["status"] ?? "active";

if (
    $service_id <= 0 ||
    empty($service_name) ||
    $category_id <= 0 ||
    empty($description) ||
    $price < 0 ||
    empty($location)
) {
    header("Location: services.php?error=1");
    exit();
}

if (!in_array($status, ["active", "inactive"])) {
    $status = "active";
}

$provider_sql = "SELECT id FROM providers WHERE user_id = ? LIMIT 1";

$provider_stmt = $conn->prepare($provider_sql);

if (!$provider_stmt) {
    die("Database error: " . $conn->error);
}

$provider_stmt->bind_param("i", $user_id);
$provider_stmt->execute();

$provider_result = $provider_stmt->get_result();

if ($provider_result->num_rows === 0) {
    die("Provider profile not found.");
}

$provider = $provider_result->fetch_assoc();
$provider_id = $provider["id"];

$provider_stmt->close();

$sql = "UPDATE services
        SET service_name = ?,
            category_id = ?,
            description = ?,
            price = ?,
            location = ?,
            status = ?
        WHERE id = ?
        AND provider_id = ?";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Database error: " . $conn->error);
}

$stmt->bind_param(
    "sisdssii",
    $service_name,
    $category_id,
    $description,
    $price,
    $location,
    $status,
    $service_id,
    $provider_id
);

if ($stmt->execute()) {
    header("Location: services.php?updated=1");
    exit();
}

die("Failed to update service: " . $stmt->error);
?>