<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/access_control.php';

requireApprovedProvider();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: bookings.php');
    exit;
}

$user_id = (int) $_SESSION['user_id'];

$booking_id = filter_input(
    INPUT_POST,
    'booking_id',
    FILTER_VALIDATE_INT
);

$action = strtolower(trim($_POST['action'] ?? ''));

$allowedActions = [
    'accepted',
    'rejected',
    'completed'
];

if (
    !$booking_id ||
    $booking_id < 1 ||
    !in_array($action, $allowedActions, true)
) {
    header('Location: bookings.php?error=invalid');
    exit;
}

/* Get the provider's actual database ID. */
$providerStmt = $conn->prepare(
    "SELECT id FROM providers WHERE user_id = ? LIMIT 1"
);

if (!$providerStmt) {
    error_log('Provider lookup failed: ' . $conn->error);
    header('Location: bookings.php?error=failed');
    exit;
}

$providerStmt->bind_param('i', $user_id);
$providerStmt->execute();

$providerResult = $providerStmt->get_result();
$provider = $providerResult->fetch_assoc();

$providerStmt->close();

if (!$provider) {
    header('Location: bookings.php?error=not_allowed');
    exit;
}

$provider_id = (int) $provider['id'];

/* Only permit valid status transitions for this provider's booking. */
$allowedTransitions = [
    'accepted' => ['pending'],
    'rejected' => ['pending'],
    'completed' => ['accepted']
];

$previousStatuses = $allowedTransitions[$action];
$placeholders = implode(
    ', ',
    array_fill(0, count($previousStatuses), '?')
);

$sql = "
    UPDATE bookings
    SET status = ?
    WHERE id = ?
      AND provider_id = ?
      AND status IN ($placeholders)
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    error_log('Booking update preparation failed: ' . $conn->error);
    header('Location: bookings.php?error=failed');
    exit;
}

/* Bind the new status, booking ID, provider ID and permitted old status. */
$types = 'sii' . str_repeat('s', count($previousStatuses));
$params = array_merge(
    [$action, $booking_id, $provider_id],
    $previousStatuses
);

$stmt->bind_param($types, ...$params);

if (!$stmt->execute()) {
    error_log('Booking update failed: ' . $stmt->error);
    $stmt->close();

    header('Location: bookings.php?error=failed');
    exit;
}

$updated = $stmt->affected_rows;
$stmt->close();

if ($updated === 1) {
    header('Location: bookings.php?success=updated');
    exit;
}

/* The booking may not belong to this provider or may have changed status. */
header('Location: bookings.php?error=not_allowed');
exit;
?>
