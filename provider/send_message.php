
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/access_control.php';

requireApprovedProvider();

$sender_id = (int) $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: messages.php');
    exit();
}

$receiver_id = filter_input(INPUT_POST, 'receiver_id', FILTER_VALIDATE_INT);
$booking_id = filter_input(INPUT_POST, 'booking_id', FILTER_VALIDATE_INT);
$message = trim($_POST['message'] ?? '');

$receiver_id = $receiver_id ? (int) $receiver_id : 0;
$booking_id = $booking_id ? (int) $booking_id : 0;

if ($receiver_id <= 0 || $receiver_id === $sender_id) {
    $_SESSION['message_error'] = 'Please select a valid recipient.';
    header('Location: messages.php');
    exit();
}

if ($message === '') {
    header('Location: messages.php?client_id=' . $receiver_id);
    exit();
}

if (mb_strlen($message) > 5000) {
    $_SESSION['message_error'] = 'Your message is too long. Maximum length is 5000 characters.';
    header('Location: messages.php?client_id=' . $receiver_id);
    exit();
}

/* Confirm the recipient is an active client or administrator. */
$stmt = $conn->prepare(
    "SELECT id, role
     FROM users
     WHERE id = ?
       AND role IN ('client', 'admin')
       AND status = 'active'
     LIMIT 1"
);

$stmt->bind_param("i", $receiver_id);
$stmt->execute();
$recipient = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$recipient) {
    $_SESSION['message_error'] = 'The selected recipient is unavailable.';
    header('Location: messages.php');
    exit();
}

/*
 * Store NULL for messages not linked to a booking.
 * If a booking ID is supplied, verify that it belongs to this provider
 * and the selected client before inserting the message.
 */
if ($booking_id > 0) {
    if (strtolower($recipient['role']) !== 'client') {
        $booking_id = null;
    } else {
        $stmt = $conn->prepare(
            "SELECT b.id
             FROM bookings b
             INNER JOIN providers p ON p.id = b.provider_id
             WHERE b.id = ?
               AND b.client_id = ?
               AND p.user_id = ?
             LIMIT 1"
        );

        $stmt->bind_param(
            "iii",
            $booking_id,
            $receiver_id,
            $sender_id
        );

        $stmt->execute();
        $validBooking = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$validBooking) {
            $_SESSION['message_error'] =
                'The selected booking is invalid. Please reopen the conversation and try again.';

            header('Location: messages.php?client_id=' . $receiver_id);
            exit();
        }
    }
} else {
    $booking_id = null;
}

/* Insert the message using NULL when there is no associated booking. */
$stmt = $conn->prepare(
    "INSERT INTO messages
        (sender_id, receiver_id, booking_id, message, is_read, created_at)
     VALUES (?, ?, ?, ?, 0, NOW())"
);

if (!$stmt) {
    $_SESSION['message_error'] = 'Unable to prepare your message. Please try again.';
    header('Location: messages.php?client_id=' . $receiver_id);
    exit();
}

/*
 * mysqli bind_param uses a variable for the nullable booking ID.
 * Passing NULL here stores SQL NULL, not the number zero.
 */
$stmt->bind_param(
    "iiis",
    $sender_id,
    $receiver_id,
    $booking_id,
    $message
);

try {
    $success = $stmt->execute();
} catch (mysqli_sql_exception $exception) {
    $success = false;
}

$stmt->close();

if ($success) {
    header(
        'Location: messages.php?client_id=' . $receiver_id .
        ($booking_id !== null ? '&booking_id=' . $booking_id : '')
    );
    exit();
}

$_SESSION['message_error'] = 'Your message could not be sent. Please try again.';
header('Location: messages.php?client_id=' . $receiver_id);
exit();
