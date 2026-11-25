<?php

require_once "config/database.php";

$full_name = "HandyLink Administrator";
$email = "admin@handylink.com";
$phone = "0700000000";
$password = "9956";
$location = "Nairobi";
$role = "admin";

$check = $conn->prepare("SELECT id FROM users WHERE email = ?");
$check->bind_param("s", $email);
$check->execute();

$result = $check->get_result();

if ($result->num_rows > 0) {
    die("Admin account already exists.");
}

$hashed_password = password_hash($password, PASSWORD_DEFAULT);

$stmt = $conn->prepare("
    INSERT INTO users
    (full_name, email, phone, password, role, location, status)
    VALUES (?, ?, ?, ?, ?, ?, 'active')
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
    echo "<h2>Admin account created successfully!</h2>";
    echo "<p>Email: " . $email . "</p>";
    echo "<p>Password: " . $password . "</p>";
    echo "<p><strong>Delete create_admin.php after this.</strong></p>";
} else {
    echo "Failed to create admin account.";
}

$stmt->close();
$check->close();
$conn->close();

?>