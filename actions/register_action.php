<?php

require_once "../config/database.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../register.php");
    exit();
}

$full_name = trim($_POST["full_name"]);
$email = trim($_POST["email"]);
$phone = trim($_POST["phone"]);
$location = trim($_POST["location"]);
$role = $_POST["role"];
$password = $_POST["password"];
$confirm_password = $_POST["confirm_password"];

// Check if passwords match
if ($password !== $confirm_password) {
    die("Passwords do not match. <a href='../register.php'>Go back</a>");
}

// Allow only client and provider registration
if ($role !== "client" && $role !== "provider") {
    die("Invalid account type. <a href='../register.php'>Go back</a>");
}

// Check if email already exists
$check_email = $conn->prepare("SELECT id FROM users WHERE email = ?");
$check_email->bind_param("s", $email);
$check_email->execute();
$result = $check_email->get_result();

if ($result->num_rows > 0) {
    die("Email already registered. <a href='../register.php'>Go back</a>");
}

// Securely hash password
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

// Insert user
$stmt = $conn->prepare("
    INSERT INTO users
    (full_name, email, phone, password, role, location)
    VALUES (?, ?, ?, ?, ?, ?)
");

$stmt->bind_param(
    "ssssss",
    $full_name,
    $email,
    $phone,
    $hashed_password,
    $role,
    $location
);

if ($stmt->execute()) {

    // If registering as provider, create provider profile
    if ($role === "provider") {

        $user_id = $stmt->insert_id;

        $provider_stmt = $conn->prepare("
            INSERT INTO providers (user_id)
            VALUES (?)
        ");

        $provider_stmt->bind_param("i", $user_id);
        $provider_stmt->execute();

        $provider_stmt->close();
    }

    echo "
        <h2>Registration Successful!</h2>
        <p>Your HandyLink account has been created successfully.</p>
        <p><a href='../login.php'>Login to your account</a></p>
    ";

} else {

    echo "
        <h2>Registration Failed</h2>
        <p>Something went wrong while creating your account.</p>
        <p><a href='../register.php'>Try again</a></p>
    ";
}

$check_email->close();
$stmt->close();
$conn->close();

?>