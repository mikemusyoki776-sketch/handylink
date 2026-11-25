<?php

session_start();

require_once "../config/database.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../login.php");
    exit();
}

$email = trim($_POST["email"]);
$password = $_POST["password"];

// Find user by email
$stmt = $conn->prepare("
    SELECT id, full_name, email, password, role, status
    FROM users
    WHERE email = ?
    LIMIT 1
");

$stmt->bind_param("s", $email);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows !== 1) {
    die("Invalid email or password. <a href='../login.php'>Try again</a>");
}

$user = $result->fetch_assoc();

// Check account status
if ($user["status"] !== "active") {
    die("Your account is currently not active. Please contact HandyLink administration.");
}

// Verify password
if (!password_verify($password, $user["password"])) {
    die("Invalid email or password. <a href='../login.php'>Try again</a>");
}

// Create session
$_SESSION["user_id"] = $user["id"];
$_SESSION["full_name"] = $user["full_name"];
$_SESSION["email"] = $user["email"];
$_SESSION["role"] = $user["role"];

// Redirect according to role
if ($user["role"] === "client") {

    header("Location: ../client/dashboard.php");
    exit();

} elseif ($user["role"] === "provider") {

    header("Location: ../provider/dashboard.php");
    exit();

} elseif ($user["role"] === "admin") {

    header("Location: ../admin/dashboard.php");
    exit();

} else {

    die("Invalid user role.");
}

$stmt->close();
$conn->close();

?>