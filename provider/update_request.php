<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/access_control.php';

requireApprovedProvider();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: requests.php');
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$request_id = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
$status = strtolower(trim($_POST['status'] ?? ''));

if (
    !$request_id ||
    $request_id < 1 ||
    !in_array($status, ['accepted', 'rejected'], true)
) {
    header('Location: requests.php?error=invalid');
    exit;
}

/* Get the provider's database ID. */
$providerStmt = $conn->prepare(
    "SELECT id FROM providers WHERE user_id = ? LIMIT 1"
);

if (!$providerStmt) {
    error_log('Provider lookup failed: ' . $conn->error);
    header('Location: requests.php?error=failed');
    exit;
}

$providerStmt->bind_param('i', $user_id);
$providerStmt->execute();

$providerResult = $providerStmt->get_result();
$provider = $providerResult->fetch_assoc();

$providerStmt->close();

if (!$provider) {
    header('Location: requests.php?error=not_allowed');
    exit;
}

$provider_id = (int) $provider['id'];

/*
 * Use a transaction so the request status and booking
 * are saved together. If either operation fails,
 * both changes are rolled back.
 */
$conn->begin_transaction();

try {

    /*
     * Lock the request and confirm that it is still pending
     * and matches one of this provider's service categories.
     */
    $requestStmt = $conn->prepare(
        "SELECT
            sr.id,
            sr.client_id,
            sr.preferred_date,
            sr.preferred_time
         FROM service_requests sr
         WHERE sr.id = ?
           AND sr.status = 'pending'
           AND EXISTS (
               SELECT 1
               FROM services s
               WHERE s.provider_id = ?
                 AND s.category_id = sr.category_id
           )
         LIMIT 1
         FOR UPDATE"
    );

    if (!$requestStmt) {
        throw new Exception('Could not prepare request lookup.');
    }

    $requestStmt->bind_param('ii', $request_id, $provider_id);
    $requestStmt->execute();

    $requestResult = $requestStmt->get_result();
    $request = $requestResult->fetch_assoc();

    $requestStmt->close();

    if (!$request) {
        $conn->rollback();
        header('Location: requests.php?error=not_allowed');
        exit;
    }

    /*
     * When the provider rejects the request, update only
     * the request status. Do not create a booking.
     */
    if ($status === 'rejected') {

        $updateStmt = $conn->prepare(
            "UPDATE service_requests
             SET status = 'rejected'
             WHERE id = ? AND status = 'pending'"
        );

        if (!$updateStmt) {
            throw new Exception('Could not prepare rejection update.');
        }

        $updateStmt->bind_param('i', $request_id);
        $updateStmt->execute();

        if ($updateStmt->affected_rows !== 1) {
            $updateStmt->close();
            throw new Exception('Request rejection did not complete.');
        }

        $updateStmt->close();

        $conn->commit();

        header('Location: requests.php?success=updated');
        exit;
    }

    /*
     * Prevent a duplicate booking for the same request.
     * The request is still pending at this point.
     */
    $existingStmt = $conn->prepare(
        "SELECT id FROM bookings WHERE request_id = ? LIMIT 1"
    );

    if (!$existingStmt) {
        throw new Exception('Could not check existing bookings.');
    }

    $existingStmt->bind_param('i', $request_id);
    $existingStmt->execute();

    $existingResult = $existingStmt->get_result();
    $existingBooking = $existingResult->fetch_assoc();

    $existingStmt->close();

    if ($existingBooking) {
        throw new Exception(
            'A booking already exists for this request.'
        );
    }

    /*
     * Create the booking using the client's requested date
     * and time. Keep the booking pending until the client
     * and provider booking workflow confirms the appointment.
     */
    $bookingDate = $request['preferred_date'];

    if (empty($bookingDate) || $bookingDate === '0000-00-00') {
        $bookingDate = date('Y-m-d');
    }

    $bookingTime = $request['preferred_time'];

    if (empty($bookingTime) || $bookingTime === '00:00:00') {
        $bookingTime = '09:00:00';
    }

    $client_id = (int) $request['client_id'];

    $bookingStmt = $conn->prepare(
        "INSERT INTO bookings
            (
                request_id,
                client_id,
                provider_id,
                booking_date,
                booking_time,
                status
            )
         VALUES (?, ?, ?, ?, ?, 'pending')"
    );

    if (!$bookingStmt) {
        throw new Exception('Could not prepare booking creation.');
    }

    $bookingStmt->bind_param(
        'iiiss',
        $request_id,
        $client_id,
        $provider_id,
        $bookingDate,
        $bookingTime
    );

    if (!$bookingStmt->execute()) {
        $bookingError = $bookingStmt->error;
        $bookingStmt->close();

        throw new Exception(
            'Booking creation failed: ' . $bookingError
        );
    }

    $bookingStmt->close();

    /*
     * Update the request status only after the booking
     * has been successfully created.
     */
    $updateStmt = $conn->prepare(
        "UPDATE service_requests
         SET status = 'accepted'
         WHERE id = ? AND status = 'pending'"
    );

    if (!$updateStmt) {
        throw new Exception('Could not prepare request update.');
    }

    $updateStmt->bind_param('i', $request_id);
    $updateStmt->execute();

    if ($updateStmt->affected_rows !== 1) {
        $updateStmt->close();
        throw new Exception('Request status update failed.');
    }

    $updateStmt->close();

    $conn->commit();

    header('Location: requests.php?success=updated');
    exit;

} catch (Throwable $e) {

    $conn->rollback();

    error_log('Request/booking processing error: ' . $e->getMessage());

    header('Location: requests.php?error=failed');
    exit;
}
?>
