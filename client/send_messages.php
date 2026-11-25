```php id="8k2mqp"
<?php
session_start();
require_once "../config/database.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'client') {
    header("Location: ../login.php");
    exit();
}

$client_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: messages.php");
    exit();
}

$receiver_id = intval($_POST['receiver_id'] ?? 0);
$booking_id = intval($_POST['booking_id'] ?? 0);
$message = trim($_POST['message'] ?? '');

if ($receiver_id <= 0 || empty($message)) {
    header("Location: messages.php");
    exit();
}

$provider_sql = "SELECT id
                 FROM users
                 WHERE id = ?
                   AND role = 'provider'
                   AND status = 'active'";

$provider_stmt = $conn->prepare($provider_sql);
$provider_stmt->bind_param("i", $receiver_id);
$provider_stmt->execute();

$provider_result = $provider_stmt->get_result();

if ($provider_result->num_rows === 0) {
    header("Location: messages.php");
    exit();
}

if ($booking_id > 0) {

    $booking_sql = "SELECT id
                    FROM bookings
                    WHERE id = ?
                      AND client_id = ?
                      AND provider_id = ?";

    $booking_stmt = $conn->prepare($booking_sql);

    $booking_stmt->bind_param(
        "iii",
        $booking_id,
        $client_id,
        $receiver_id
    );

    $booking_stmt->execute();

    $booking_result = $booking_stmt->get_result();

    if ($booking_result->num_rows === 0) {
        $booking_id = 0;
    }
}

$insert_sql = "INSERT INTO messages
               (sender_id, receiver_id, booking_id, message, is_read, created_at)
               VALUES (?, ?, ?, ?, 0, NOW())";

$insert_stmt = $conn->prepare($insert_sql);

$insert_stmt->bind_param(
    "iiis",
    $client_id,
    $receiver_id,
    $booking_id,
    $message
);

$insert_stmt->execute();

header(
    "Location: messages.php?provider_id="
    . $receiver_id
    . "&booking_id="
    . $booking_id
);

exit();
?>
```
