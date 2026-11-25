
<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/access_control.php';

requireApprovedProvider();

$user_id = (int) $_SESSION['user_id'];

// Only allow submissions through POST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: add_service.php');
    exit();
}

// Get the provider's profile.
$provider_sql = "SELECT id FROM providers WHERE user_id = ? LIMIT 1";
$provider_stmt = $conn->prepare($provider_sql);

if (!$provider_stmt) {
    error_log('Provider lookup failed: ' . $conn->error);
    header('Location: services.php?error=failed');
    exit();
}

$provider_stmt->bind_param('i', $user_id);
$provider_stmt->execute();

$provider_result = $provider_stmt->get_result();

if ($provider_result->num_rows === 0) {
    $provider_stmt->close();
    header('Location: services.php?error=failed');
    exit();
}

$provider = $provider_result->fetch_assoc();
$provider_id = (int) $provider['id'];
$provider_stmt->close();

// Prevent the provider from adding another service.
$existing_sql = "SELECT id FROM services WHERE provider_id = ? LIMIT 1";
$existing_stmt = $conn->prepare($existing_sql);

if (!$existing_stmt) {
    error_log('Service lookup failed: ' . $conn->error);
    header('Location: services.php?error=failed');
    exit();
}

$existing_stmt->bind_param('i', $provider_id);
$existing_stmt->execute();

$existing_result = $existing_stmt->get_result();
$has_service = $existing_result->num_rows > 0;

$existing_stmt->close();

if ($has_service) {
    header('Location: services.php?error=already_added');
    exit();
}

// Read and validate the submitted fields.
$service_name = trim($_POST['service_name'] ?? '');
$category_id = filter_var(
    $_POST['category_id'] ?? null,
    FILTER_VALIDATE_INT
);
$description = trim($_POST['description'] ?? '');
$price_input = trim($_POST['price'] ?? '');
$location = trim($_POST['location'] ?? '');

if (
    $service_name === '' ||
    $category_id === false ||
    $category_id === null ||
    $category_id <= 0 ||
    $description === '' ||
    $price_input === '' ||
    !is_numeric($price_input) ||
    !is_finite((float) $price_input) ||
    (float) $price_input < 0 ||
    $location === ''
) {
    header('Location: add_service.php?error=1');
    exit();
}

$price = (float) $price_input;

// Verify that the selected category exists.
$category_sql = "SELECT id FROM categories WHERE id = ? LIMIT 1";
$category_stmt = $conn->prepare($category_sql);

if (!$category_stmt) {
    error_log('Category lookup failed: ' . $conn->error);
    header('Location: add_service.php?error=failed');
    exit();
}

$category_stmt->bind_param('i', $category_id);
$category_stmt->execute();

$category_result = $category_stmt->get_result();
$category_exists = $category_result->num_rows > 0;

$category_stmt->close();

if (!$category_exists) {
    header('Location: add_service.php?error=1');
    exit();
}

$status = 'active';

// Insert the provider's single service.
$sql = "INSERT INTO services
        (provider_id, category_id, service_name, description, price, location, status)
        VALUES (?, ?, ?, ?, ?, ?, ?)";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    error_log('Service insert preparation failed: ' . $conn->error);
    header('Location: services.php?error=failed');
    exit();
}

$stmt->bind_param(
    'iissdss',
    $provider_id,
    $category_id,
    $service_name,
    $description,
    $price,
    $location,
    $status
);

if ($stmt->execute()) {
    $stmt->close();
    header('Location: services.php?success=added');
    exit();
}

$error_number = $stmt->errno;
error_log('Service insert failed: ' . $stmt->error);
$stmt->close();

// Also handle a duplicate-service restriction if one is added to the database.
if ($error_number === 1062) {
    header('Location: services.php?error=already_added');
    exit();
}

header('Location: services.php?error=failed');
exit();
?>
