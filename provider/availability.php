<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Africa/Nairobi');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/access_control.php';

requireApprovedProvider();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'provider') {
    header('Location: ../login.php');
    exit();
}

$user_id = (int) $_SESSION['user_id'];
$error_message = '';
$success_message = '';

$days = [
    'Monday', 'Tuesday', 'Wednesday', 'Thursday',
    'Friday', 'Saturday', 'Sunday'
];

$leave_types = [
    'Annual', 'Sick', 'Maternity', 'Paternity',
    'Compassionate', 'Unpaid', 'Other'
];

function availabilityEscape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function availabilityRedirect($message)
{
    header('Location: availability.php?message=' . urlencode($message));
    exit();
}

function availabilityTime($time)
{
    return $time ? date('g:i A', strtotime($time)) : '—';
}

/* Provider details */
$stmt = $conn->prepare(
    "SELECT id FROM providers WHERE user_id = ? LIMIT 1"
);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$provider = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$provider) {
    header('Location: verification.php');
    exit();
}

$provider_id = (int) $provider['id'];

/* Header profile details */
$stmt = $conn->prepare(
    "SELECT full_name, email, profile_image
     FROM users
     WHERE id = ? AND role = 'provider'
     LIMIT 1"
);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

$fullName = $user['full_name'] ?? 'Provider';
$firstName = explode(' ', trim($fullName))[0];
$email = $user['email'] ?? '';
$profileImage = $user['profile_image'] ?? '';
$profileInitial = strtoupper(substr($fullName, 0, 1));

/* Header notification counts */
$unreadMessages = 0;
$unreadNotifications = 0;

$stmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM messages
     WHERE receiver_id = ? AND is_read = 0"
);

if ($stmt) {
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $unreadMessages = (int) (
        $stmt->get_result()->fetch_assoc()['total'] ?? 0
    );
    $stmt->close();
}

$tableResult = $conn->query("SHOW TABLES LIKE 'notifications'");

if ($tableResult && $tableResult->num_rows > 0) {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM notifications
         WHERE user_id = ? AND is_read = 0"
    );

    if ($stmt) {
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $unreadNotifications = (int) (
            $stmt->get_result()->fetch_assoc()['total'] ?? 0
        );
        $stmt->close();
    }
}

/* Process page forms */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* ATTENDANCE: Check in */
    if ($action === 'check_in') {
        $today = date('Y-m-d');
        $now = date('H:i:s');

        $stmt = $conn->prepare(
            "SELECT id, check_in
             FROM employee_attendance
             WHERE provider_id = ? AND attendance_date = ?
             LIMIT 1"
        );
        $stmt->bind_param('is', $provider_id, $today);
        $stmt->execute();
        $todayAttendance = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($todayAttendance && !empty($todayAttendance['check_in'])) {
            availabilityRedirect('already_checked_in');
        }

        if ($todayAttendance) {
            $attendance_id = (int) $todayAttendance['id'];

            $stmt = $conn->prepare(
                "UPDATE employee_attendance
                 SET check_in = ?, status = 'Present'
                 WHERE id = ? AND provider_id = ?"
            );
            $stmt->bind_param('sii', $now, $attendance_id, $provider_id);
        } else {
            $status = 'Present';

            $stmt = $conn->prepare(
                "INSERT INTO employee_attendance
                 (provider_id, attendance_date, check_in, status, recorded_by)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->bind_param(
                'isssi',
                $provider_id,
                $today,
                $now,
                $status,
                $user_id
            );
        }

        if ($stmt->execute()) {
            $stmt->close();
            availabilityRedirect('checked_in');
        }

        $stmt->close();
        $error_message = 'Unable to record your check-in. Please try again.';
    }

    /* ATTENDANCE: Check out */
    elseif ($action === 'check_out') {
        $today = date('Y-m-d');
        $now = date('H:i:s');

        $stmt = $conn->prepare(
            "SELECT id, check_in, check_out
             FROM employee_attendance
             WHERE provider_id = ? AND attendance_date = ?
             LIMIT 1"
        );
        $stmt->bind_param('is', $provider_id, $today);
        $stmt->execute();
        $todayAttendance = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$todayAttendance || empty($todayAttendance['check_in'])) {
            $error_message = 'You must check in before checking out.';
        } elseif (!empty($todayAttendance['check_out'])) {
            availabilityRedirect('already_checked_out');
        } else {
            $attendance_id = (int) $todayAttendance['id'];

            $stmt = $conn->prepare(
                "UPDATE employee_attendance
                 SET check_out = ?
                 WHERE id = ? AND provider_id = ?"
            );
            $stmt->bind_param('sii', $now, $attendance_id, $provider_id);

            if ($stmt->execute()) {
                $stmt->close();
                availabilityRedirect('checked_out');
            }

            $stmt->close();
            $error_message = 'Unable to record your check-out. Please try again.';
        }
    }

    /* AVAILABILITY: Add or edit a schedule */
    elseif ($action === 'save_availability') {
        $availability_id = (int) ($_POST['availability_id'] ?? 0);
        $day_of_week = trim($_POST['day_of_week'] ?? '');
        $start_time = trim($_POST['start_time'] ?? '');
        $end_time = trim($_POST['end_time'] ?? '');
        $status = trim($_POST['status'] ?? 'Available');

        if (!in_array($day_of_week, $days, true)) {
            $error_message = 'Please select a valid working day.';
        } elseif (
            !preg_match('/^\d{2}:\d{2}$/', $start_time) ||
            !preg_match('/^\d{2}:\d{2}$/', $end_time)
        ) {
            $error_message = 'Please enter valid working hours.';
        } elseif ($start_time >= $end_time) {
            $error_message = 'End time must be later than start time.';
        } elseif (!in_array($status, ['Available', 'Unavailable'], true)) {
            $error_message = 'Please select a valid availability status.';
        } else {
            $start_time_db = $start_time . ':00';
            $end_time_db = $end_time . ':00';

            if ($availability_id > 0) {
                $stmt = $conn->prepare(
                    "SELECT id FROM availability
                     WHERE id = ? AND provider_id = ?
                     LIMIT 1"
                );
                $stmt->bind_param('ii', $availability_id, $provider_id);
                $stmt->execute();
                $exists = $stmt->get_result()->num_rows > 0;
                $stmt->close();

                if (!$exists) {
                    $error_message = 'The selected schedule could not be found.';
                } else {
                    $stmt = $conn->prepare(
                        "UPDATE availability
                         SET day_of_week = ?, start_time = ?,
                             end_time = ?, status = ?
                         WHERE id = ? AND provider_id = ?"
                    );
                    $stmt->bind_param(
                        'ssssii',
                        $day_of_week,
                        $start_time_db,
                        $end_time_db,
                        $status,
                        $availability_id,
                        $provider_id
                    );

                    if ($stmt->execute()) {
                        $stmt->close();
                        availabilityRedirect('schedule_updated');
                    }

                    $stmt->close();
                    $error_message = 'Unable to update the schedule. '
                        . 'Check whether this day already has a schedule.';
                }
            } else {
                $stmt = $conn->prepare(
                    "SELECT id FROM availability
                     WHERE provider_id = ? AND day_of_week = ?
                     LIMIT 1"
                );
                $stmt->bind_param('is', $provider_id, $day_of_week);
                $stmt->execute();
                $dayExists = $stmt->get_result()->num_rows > 0;
                $stmt->close();

                if ($dayExists) {
                    $error_message = 'You already have a schedule for this day. '
                        . 'Edit the existing schedule instead.';
                } else {
                    $stmt = $conn->prepare(
                        "INSERT INTO availability
                         (provider_id, day_of_week, start_time, end_time, status)
                         VALUES (?, ?, ?, ?, ?)"
                    );
                    $stmt->bind_param(
                        'issss',
                        $provider_id,
                        $day_of_week,
                        $start_time_db,
                        $end_time_db,
                        $status
                    );

                    if ($stmt->execute()) {
                        $stmt->close();
                        availabilityRedirect('schedule_added');
                    }

                    $stmt->close();
                    $error_message = 'Unable to add the schedule. Please try again.';
                }
            }
        }
    }

    /* AVAILABILITY: Delete a schedule */
    elseif ($action === 'delete_availability') {
        $availability_id = (int) ($_POST['availability_id'] ?? 0);

        $stmt = $conn->prepare(
            "DELETE FROM availability
             WHERE id = ? AND provider_id = ?"
        );
        $stmt->bind_param('ii', $availability_id, $provider_id);

        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $stmt->close();
            availabilityRedirect('schedule_deleted');
        }

        $stmt->close();
        $error_message = 'The schedule could not be deleted.';
    }

    /* LEAVE: Submit a leave request */
    elseif ($action === 'submit_leave') {
        $leave_type = trim($_POST['leave_type'] ?? '');
        $start_date = trim($_POST['start_date'] ?? '');
        $end_date = trim($_POST['end_date'] ?? '');
        $reason = trim($_POST['reason'] ?? '');

        $validDates = (
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) &&
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date) &&
            checkdate(
                (int) substr($start_date, 5, 2),
                (int) substr($start_date, 8, 2),
                (int) substr($start_date, 0, 4)
            ) &&
            checkdate(
                (int) substr($end_date, 5, 2),
                (int) substr($end_date, 8, 2),
                (int) substr($end_date, 0, 4)
            )
        );

        if (!in_array($leave_type, $leave_types, true)) {
            $error_message = 'Please select a valid leave type.';
        } elseif (!$validDates || $end_date < $start_date) {
            $error_message = 'Please select valid leave dates.';
        } elseif ($start_date < date('Y-m-d')) {
            $error_message = 'The leave start date cannot be in the past.';
        } elseif ($reason === '') {
            $error_message = 'Please provide a reason for your leave request.';
        } elseif (strlen($reason) > 2000) {
            $error_message = 'Your leave reason is too long.';
        } else {
            $leaveStatus = 'Pending';

            $stmt = $conn->prepare(
                "INSERT INTO leave_requests
                 (provider_id, leave_type, start_date, end_date, reason, status)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param(
                'isssss',
                $provider_id,
                $leave_type,
                $start_date,
                $end_date,
                $reason,
                $leaveStatus
            );

            if ($stmt->execute()) {
                $stmt->close();
                availabilityRedirect('leave_submitted');
            }

            $stmt->close();
            $error_message = 'Unable to submit your leave request. Please try again.';
        }
    }
}

/* Edit schedule data */
$edit_record = null;
$edit_id = (int) ($_GET['edit'] ?? 0);

if ($edit_id > 0) {
    $stmt = $conn->prepare(
        "SELECT id, day_of_week, start_time, end_time, status
         FROM availability
         WHERE id = ? AND provider_id = ?
         LIMIT 1"
    );
    $stmt->bind_param('ii', $edit_id, $provider_id);
    $stmt->execute();
    $edit_record = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$edit_record) {
        $error_message = 'The schedule you selected could not be found.';
    }
}

/* Display feedback after redirects */
$message = $_GET['message'] ?? '';

$messages = [
    'checked_in' => 'You have successfully checked in.',
    'checked_out' => 'You have successfully checked out.',
    'already_checked_in' => 'You have already checked in today.',
    'already_checked_out' => 'You have already checked out today.',
    'schedule_added' => 'Availability schedule added successfully.',
    'schedule_updated' => 'Availability schedule updated successfully.',
    'schedule_deleted' => 'Availability schedule deleted successfully.',
    'leave_submitted' => 'Your leave request was submitted for admin review.'
];

if (isset($messages[$message])) {
    $success_message = $messages[$message];
}

/* Today's attendance */
$today = date('Y-m-d');
$stmt = $conn->prepare(
    "SELECT id, attendance_date, check_in, check_out, status, remarks
     FROM employee_attendance
     WHERE provider_id = ? AND attendance_date = ?
     LIMIT 1"
);
$stmt->bind_param('is', $provider_id, $today);
$stmt->execute();
$todayAttendance = $stmt->get_result()->fetch_assoc();
$stmt->close();

/* Attendance history */
$stmt = $conn->prepare(
    "SELECT attendance_date, check_in, check_out, status, remarks
     FROM employee_attendance
     WHERE provider_id = ?
     ORDER BY attendance_date DESC
     LIMIT 30"
);
$stmt->bind_param('i', $provider_id);
$stmt->execute();
$attendanceResult = $stmt->get_result();
$attendanceRecords = [];

while ($row = $attendanceResult->fetch_assoc()) {
    $attendanceRecords[] = $row;
}
$stmt->close();

/* Weekly availability */
$stmt = $conn->prepare(
    "SELECT id, day_of_week, start_time, end_time, status
     FROM availability
     WHERE provider_id = ?
     ORDER BY FIELD(
         day_of_week,
         'Monday', 'Tuesday', 'Wednesday', 'Thursday',
         'Friday', 'Saturday', 'Sunday'
     )"
);
$stmt->bind_param('i', $provider_id);
$stmt->execute();
$result = $stmt->get_result();
$availabilityRecords = [];

while ($row = $result->fetch_assoc()) {
    $availabilityRecords[] = $row;
}
$stmt->close();

$availableCount = 0;

foreach ($availabilityRecords as $record) {
    if (strcasecmp($record['status'], 'Available') === 0) {
        $availableCount++;
    }
}

/* Provider's leave requests */
$stmt = $conn->prepare(
    "SELECT id, leave_type, start_date, end_date, reason,
            status, review_comment, created_at
     FROM leave_requests
     WHERE provider_id = ?
     ORDER BY created_at DESC
     LIMIT 50"
);
$stmt->bind_param('i', $provider_id);
$stmt->execute();
$leaveResult = $stmt->get_result();
$leaveRecords = [];

while ($row = $leaveResult->fetch_assoc()) {
    $leaveRecords[] = $row;
}
$stmt->close();

$pendingLeaveCount = 0;

foreach ($leaveRecords as $leave) {
    if (strcasecmp($leave['status'], 'Pending') === 0) {
        $pendingLeaveCount++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Availability | HandyLink</title>

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/provider_dashboard.css">
    <link rel="stylesheet" href="../assets/css/availability.css">
    <link rel="stylesheet" href="../assets/css/provider_availability.css">

    <style>
        .availability-main {
            width: min(1440px, 96%);
            margin: 30px auto;
            padding-bottom: 40px;
        }

        .availability-main section {
            margin-bottom: 28px;
        }

        .availability-heading,
        .availability-section-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            flex-wrap: wrap;
        }

        .availability-heading h1 {
            margin: 8px 0;
        }

        .availability-eyebrow {
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 1.2px;
            opacity: .75;
        }

        .availability-summary {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
            margin-top: 24px;
        }

        .availability-summary-card {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 20px;
            border: 1px solid rgba(120, 140, 120, .25);
            border-radius: 12px;
            background: var(--card-bg, #fff);
        }

        .availability-summary-card strong {
            display: block;
            font-size: 25px;
            margin-top: 5px;
        }

        .summary-icon,
        .day-icon {
            display: grid;
            place-items: center;
            width: 44px;
            height: 44px;
            flex-shrink: 0;
            border-radius: 10px;
            background: rgba(5, 47, 12, .10);
            color: #17632b;
        }

        .availability-form-section,
        .availability-list-section,
        .provider-attendance-section,
        .provider-leave-section {
            padding: 24px;
            border: 1px solid rgba(120, 140, 120, .25);
            border-radius: 14px;
            background: var(--card-bg, #fff);
        }

        .availability-form-grid,
        .leave-form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
            margin-top: 20px;
        }

        .availability-field {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .availability-field input,
        .availability-field select,
        .availability-field textarea {
            width: 100%;
            box-sizing: border-box;
            padding: 12px;
            border: 1px solid #cbd5cb;
            border-radius: 8px;
            font: inherit;
        }

        .availability-field textarea {
            min-height: 100px;
            resize: vertical;
        }

        .full-width-field {
            grid-column: 1 / -1;
        }

        .availability-primary-button,
        .availability-secondary-button,
        .attendance-action-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 11px 16px;
            border: 0;
            border-radius: 8px;
            text-decoration: none;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
        }

        .availability-primary-button,
        .attendance-action-button {
            color: #fff;
            background: #052f0c;
        }

        .availability-secondary-button {
            color: inherit;
            border: 1px solid #cbd5cb;
        }

        .availability-form-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 20px;
        }

        .availability-table-wrapper {
            overflow-x: auto;
            margin-top: 18px;
        }

        .availability-table {
            width: 100%;
            border-collapse: collapse;
        }

        .availability-table th,
        .availability-table td {
            padding: 13px 12px;
            text-align: left;
            border-bottom: 1px solid rgba(120, 140, 120, .22);
            vertical-align: middle;
        }

        .availability-table th {
            font-size: 13px;
            white-space: nowrap;
        }

        .availability-day,
        .availability-row-actions {
            display: flex;
            align-items: center;
            gap: 9px;
        }

        .availability-row-actions {
            flex-wrap: wrap;
        }

        .availability-row-actions form {
            margin: 0;
        }

        .availability-edit-button,
        .availability-delete-button {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 10px;
            border-radius: 6px;
            font: inherit;
            text-decoration: none;
            cursor: pointer;
        }

        .availability-edit-button {
            color: #17632b;
            background: rgba(23, 99, 43, .10);
        }

        .availability-delete-button {
            color: #b42318;
            border: 0;
            background: rgba(180, 35, 24, .09);
        }

        .availability-status,
        .leave-status {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .status-available,
        .leave-approved {
            color: #17632b;
            background: #e1f5e5;
        }

        .status-unavailable,
        .leave-rejected {
            color: #b42318;
            background: #fde8e7;
        }

        .leave-pending {
            color: #8a5700;
            background: #fff0c2;
        }

        .availability-alert {
            margin: 18px 0;
            padding: 13px 16px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .success-alert {
            color: #17632b;
            background: #e1f5e5;
        }

        .error-alert {
            color: #b42318;
            background: #fde8e7;
        }

        .attendance-today-card {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            flex-wrap: wrap;
            padding: 18px;
            margin-top: 18px;
            border-radius: 10px;
            background: rgba(5, 47, 12, .06);
        }

        .attendance-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .attendance-action-button:disabled {
            opacity: .45;
            cursor: not-allowed;
        }

        .availability-empty-state {
            text-align: center;
            padding: 30px 12px;
        }

        .availability-empty-state i {
            font-size: 32px;
            margin-bottom: 12px;
            color: #17632b;
        }

        .review-comment {
            max-width: 260px;
            white-space: normal;
            overflow-wrap: anywhere;
        }

        .availability-info {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            padding: 18px;
            border-radius: 10px;
            background: rgba(5, 47, 12, .07);
        }

        .availability-info > i {
            margin-top: 4px;
            color: #17632b;
        }

        .availability-info a {
            display: inline-block;
            margin-top: 10px;
        }

        body.dark-mode .availability-summary-card,
        body.dark-mode .availability-form-section,
        body.dark-mode .availability-list-section,
        body.dark-mode .provider-attendance-section,
        body.dark-mode .provider-leave-section {
            color: #e8eee8;
            background: #172119;
            border-color: #344437;
        }

        body.dark-mode .availability-field input,
        body.dark-mode .availability-field select,
        body.dark-mode .availability-field textarea {
            color: #e8eee8;
            background: #202b22;
            border-color: #465448;
        }

        body.dark-mode .attendance-today-card,
        body.dark-mode .availability-info {
            background: #202b22;
        }

        body.dark-mode .availability-table th,
        body.dark-mode .availability-table td {
            border-color: #344437;
        }

        @media (max-width: 760px) {
            .availability-main {
                width: 94%;
                margin-top: 20px;
            }

            .availability-summary {
                grid-template-columns: 1fr;
            }

            .availability-form-grid,
            .leave-form-grid {
                grid-template-columns: 1fr;
            }

            .full-width-field {
                grid-column: auto;
            }

            .availability-form-section,
            .availability-list-section,
            .provider-attendance-section,
            .provider-leave-section {
                padding: 16px;
            }

            .availability-table th,
            .availability-table td {
                padding: 10px 8px;
            }
        }
    </style>
</head>

<body>

<header class="provider-header">

    <div class="header-left">
        <a href="dashboard.php" class="brand">
            <img src="../assets/images/handylink-logo.png"
                 alt="HandyLink Logo"
                 class="brand-logo">
            <span>HandyLink</span>
        </a>

        <button class="mobile-menu-toggle"
                type="button"
                id="mobileMenuToggle"
                aria-label="Toggle navigation"
                aria-expanded="false">
            <i class="fas fa-bars"></i>
        </button>
    </div>

    <nav class="main-nav" id="mainNav">
        <a href="dashboard.php">
            <i class="fas fa-house"></i><span>Dashboard</span>
        </a>

        <a href="verification.php">
            <i class="fas fa-shield-halved"></i><span>Verification</span>
        </a>

        <a href="services.php">
            <i class="fas fa-screwdriver-wrench"></i><span>My Services</span>
        </a>

        <a href="requests.php">
            <i class="fas fa-clipboard-list"></i><span>Requests</span>
        </a>

        <a href="bookings.php">
            <i class="fas fa-calendar-check"></i><span>Bookings</span>
        </a>

        <a href="availability.php" class="active">
            <i class="fas fa-calendar-days"></i><span>Availability</span>
        </a>

        <a href="review.php">
            <i class="fas fa-star"></i><span>Reviews</span>
        </a>

        <a href="messages.php">
            <i class="fas fa-envelope"></i><span>Messages</span>
            <?php if ($unreadMessages > 0): ?>
                <span class="nav-badge">
                    <?= $unreadMessages > 99 ? '99+' : $unreadMessages ?>
                </span>
            <?php endif; ?>
        </a>

        <a href="notification.php">
            <i class="fas fa-bell"></i><span>Notifications</span>
            <?php if ($unreadNotifications > 0): ?>
                <span class="nav-badge">
                    <?= $unreadNotifications > 99 ? '99+' : $unreadNotifications ?>
                </span>
            <?php endif; ?>
        </a>
    </nav>

    <div class="header-right">
        <div class="profile-menu">
            <button type="button"
                    class="profile-trigger"
                    id="profileTrigger"
                    aria-expanded="false">

                <?php if ($profileImage !== ''): ?>
                    <img src="../<?= availabilityEscape(ltrim($profileImage, '/')) ?>"
                         alt="Profile"
                         class="profile-avatar">
                <?php else: ?>
                    <span class="profile-avatar profile-initial">
                        <?= availabilityEscape($profileInitial) ?>
                    </span>
                <?php endif; ?>

                <span class="profile-name">
                    <?= availabilityEscape($firstName) ?>
                </span>
                <i class="fas fa-chevron-down dropdown-arrow"></i>
            </button>

            <div class="profile-dropdown" id="profileDropdown">
                <div class="dropdown-user-info">
                    <strong><?= availabilityEscape($fullName) ?></strong>
                    <span><?= availabilityEscape($email) ?></span>
                    <small>Service Provider</small>
                </div>

                <div class="dropdown-divider"></div>

                <a href="settings.php">
                    <i class="fas fa-gear"></i> Settings &amp; Profile
                </a>

                <button type="button" id="darkModeToggle">
                    <i class="fas fa-moon" id="themeIcon"></i>
                    <span id="themeText">Dark Mode</span>
                </button>

                <a href="help.php">
                    <i class="fas fa-circle-question"></i> Help &amp; Support
                </a>

                <div class="dropdown-divider"></div>

                <a href="../actions/logout.php" class="logout-link">
                    <i class="fas fa-right-from-bracket"></i> Log Out
                </a>
            </div>
        </div>
    </div>
</header>

<main class="availability-main">

    <section class="availability-heading">
        <div>
            <span class="availability-eyebrow">PROVIDER WORK MANAGEMENT</span>
            <h1>Attendance &amp; Availability</h1>
            <p>Manage your attendance, working schedule, and leave requests in one place.</p>
        </div>
    </section>

    <?php if ($success_message !== ''): ?>
        <div class="availability-alert success-alert">
            <i class="fa-solid fa-circle-check"></i>
            <?= availabilityEscape($success_message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error_message !== ''): ?>
        <div class="availability-alert error-alert">
            <i class="fa-solid fa-circle-exclamation"></i>
            <?= availabilityEscape($error_message) ?>
        </div>
    <?php endif; ?>

    <!-- PART 1: ATTENDANCE MANAGEMENT -->
    <section class="provider-attendance-section" id="attendance">
        <div class="availability-section-heading">
            <div>
                <h2><i class="fa-solid fa-user-clock"></i> Attendance Management</h2>
                <p>Record your daily check-in and check-out.</p>
            </div>
        </div>

        <div class="attendance-today-card">
            <div>
                <strong>Today's Attendance</strong>
                <p><?= date('l, d M Y') ?></p>

                <p>
                    Check-in:
                    <strong>
                        <?= availabilityEscape(
                            availabilityTime($todayAttendance['check_in'] ?? null)
                        ) ?>
                    </strong>
                    &nbsp; | &nbsp;
                    Check-out:
                    <strong>
                        <?= availabilityEscape(
                            availabilityTime($todayAttendance['check_out'] ?? null)
                        ) ?>
                    </strong>
                </p>

                <p>
                    Status:
                    <strong>
                        <?= availabilityEscape($todayAttendance['status'] ?? 'Not checked in') ?>
                    </strong>
                </p>
            </div>

            <div class="attendance-actions">
                <form method="POST" action="availability.php#attendance">
                    <input type="hidden" name="action" value="check_in">
                    <button type="submit"
                            class="attendance-action-button"
                            <?= !empty($todayAttendance['check_in']) ? 'disabled' : '' ?>>
                        <i class="fa-solid fa-right-to-bracket"></i> Check In
                    </button>
                </form>

                <form method="POST" action="availability.php#attendance">
                    <input type="hidden" name="action" value="check_out">
                    <button type="submit"
                            class="attendance-action-button"
                            <?= (
                                empty($todayAttendance['check_in']) ||
                                !empty($todayAttendance['check_out'])
                            ) ? 'disabled' : '' ?>>
                        <i class="fa-solid fa-right-from-bracket"></i> Check Out
                    </button>
                </form>
            </div>
        </div>

        <div class="availability-section-heading" style="margin-top:25px;">
            <div>
                <h3>My Attendance History</h3>
                <p>Your latest 30 attendance records.</p>
            </div>
        </div>

        <?php if ($attendanceRecords): ?>
            <div class="availability-table-wrapper">
                <table class="availability-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Check In</th>
                            <th>Check Out</th>
                            <th>Status</th>
                            <th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($attendanceRecords as $attendance): ?>
                            <tr>
                                <td><?= availabilityEscape(
                                    date('d M Y', strtotime($attendance['attendance_date']))
                                ) ?></td>
                                <td><?= availabilityEscape(
                                    availabilityTime($attendance['check_in'])
                                ) ?></td>
                                <td><?= availabilityEscape(
                                    availabilityTime($attendance['check_out'])
                                ) ?></td>
                                <td><?= availabilityEscape($attendance['status']) ?></td>
                                <td><?= availabilityEscape($attendance['remarks'] ?: '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="availability-empty-state">
                <i class="fa-regular fa-calendar"></i>
                <h3>No attendance records yet</h3>
                <p>Your attendance history will appear here after you check in.</p>
            </div>
        <?php endif; ?>
    </section>

    <!-- PART 2: PROVIDER AVAILABILITY -->
    <section id="availability">
        <div class="availability-summary">
            <div class="availability-summary-card">
                <div class="summary-icon">
                    <i class="fa-solid fa-calendar-week"></i>
                </div>
                <div>
                    <span>Scheduled Days</span>
                    <strong><?= count($availabilityRecords) ?></strong>
                </div>
            </div>

            <div class="availability-summary-card">
                <div class="summary-icon">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <div>
                    <span>Available Schedules</span>
                    <strong><?= $availableCount ?></strong>
                </div>
            </div>

            <div class="availability-summary-card">
                <div class="summary-icon">
                    <i class="fa-solid fa-calendar-xmark"></i>
                </div>
                <div>
                    <span>Unavailable Schedules</span>
                    <strong><?= count($availabilityRecords) - $availableCount ?></strong>
                </div>
            </div>
        </div>

        <section class="availability-form-section" id="availability-form">
            <div class="availability-section-heading">
                <div>
                    <h2>
                        <?= $edit_record ? 'Edit Schedule' : 'Set My Availability' ?>
                    </h2>
                    <p>Choose your working days and hours, including weekends.</p>
                </div>

                <?php if ($edit_record): ?>
                    <a href="availability.php#availability-form"
                       class="availability-secondary-button">Cancel Editing</a>
                <?php endif; ?>
            </div>

            <form method="POST" action="availability.php#availability">
                <input type="hidden" name="action" value="save_availability">
                <input type="hidden"
                       name="availability_id"
                       value="<?= (int) ($edit_record['id'] ?? 0) ?>">

                <div class="availability-form-grid">
                    <div class="availability-field">
                        <label for="day_of_week">Working Day</label>
                        <select name="day_of_week" id="day_of_week" required>
                            <option value="">Select a day</option>
                            <?php foreach ($days as $day): ?>
                                <option value="<?= availabilityEscape($day) ?>"
                                    <?= (($edit_record['day_of_week'] ?? '') === $day)
                                        ? 'selected' : '' ?>>
                                    <?= availabilityEscape($day) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="availability-field">
                        <label for="start_time">Start Time</label>
                        <input type="time"
                               name="start_time"
                               id="start_time"
                               required
                               value="<?= availabilityEscape(
                                   isset($edit_record['start_time'])
                                       ? substr($edit_record['start_time'], 0, 5) : ''
                               ) ?>">
                    </div>

                    <div class="availability-field">
                        <label for="end_time">End Time</label>
                        <input type="time"
                               name="end_time"
                               id="end_time"
                               required
                               value="<?= availabilityEscape(
                                   isset($edit_record['end_time'])
                                       ? substr($edit_record['end_time'], 0, 5) : ''
                               ) ?>">
                    </div>

                    <div class="availability-field">
                        <label for="status">Availability Status</label>
                        <select name="status" id="status" required>
                            <option value="Available"
                                <?= (($edit_record['status'] ?? 'Available') === 'Available')
                                    ? 'selected' : '' ?>>
                                Available
                            </option>
                            <option value="Unavailable"
                                <?= (($edit_record['status'] ?? '') === 'Unavailable')
                                    ? 'selected' : '' ?>>
                                Unavailable
                            </option>
                        </select>
                    </div>
                </div>

                <div class="availability-form-actions">
                    <button type="submit" class="availability-primary-button">
                        <i class="fa-solid fa-floppy-disk"></i>
                        <?= $edit_record ? 'Save Changes' : 'Add Schedule' ?>
                    </button>

                    <?php if ($edit_record): ?>
                        <a href="availability.php#availability"
                           class="availability-secondary-button">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </section>

        <section class="availability-list-section">
            <div class="availability-section-heading">
                <div>
                    <h2>My Weekly Schedule</h2>
                    <p>Review and update your saved working hours.</p>
                </div>
                <span><?= count($availabilityRecords) ?> record(s)</span>
            </div>

            <?php if ($availabilityRecords): ?>
                <div class="availability-table-wrapper">
                    <table class="availability-table">
                        <thead>
                            <tr>
                                <th>Day</th>
                                <th>Start Time</th>
                                <th>End Time</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($availabilityRecords as $schedule): ?>
                                <tr>
                                    <td>
                                        <div class="availability-day">
                                            <span class="day-icon">
                                                <i class="fa-regular fa-calendar"></i>
                                            </span>
                                            <strong><?= availabilityEscape($schedule['day_of_week']) ?></strong>
                                        </div>
                                    </td>
                                    <td><?= availabilityEscape(
                                        availabilityTime($schedule['start_time'])
                                    ) ?></td>
                                    <td><?= availabilityEscape(
                                        availabilityTime($schedule['end_time'])
                                    ) ?></td>
                                    <td>
                                        <span class="availability-status <?= (
                                            strcasecmp($schedule['status'], 'Available') === 0
                                        ) ? 'status-available' : 'status-unavailable' ?>">
                                            <?= availabilityEscape($schedule['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="availability-row-actions">
                                            <a class="availability-edit-button"
                                               href="availability.php?edit=<?= (int) $schedule['id'] ?>#availability-form">
                                                <i class="fa-solid fa-pen"></i> Edit
                                            </a>

                                            <form method="POST"
                                                  action="availability.php#availability"
                                                  onsubmit="return confirm('Delete this schedule?');">
                                                <input type="hidden"
                                                       name="action"
                                                       value="delete_availability">
                                                <input type="hidden"
                                                       name="availability_id"
                                                       value="<?= (int) $schedule['id'] ?>">
                                                <button type="submit"
                                                        class="availability-delete-button">
                                                    <i class="fa-solid fa-trash"></i> Delete
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="availability-empty-state">
                    <i class="fa-regular fa-calendar-xmark"></i>
                    <h3>No availability added yet</h3>
                    <p>Add your working days and hours to create your weekly schedule.</p>
                </div>
            <?php endif; ?>
        </section>
    </section>

    <!-- PART 3: LEAVE REQUESTS -->
    <section class="provider-leave-section" id="leave">
        <div class="availability-section-heading">
            <div>
                <h2><i class="fa-solid fa-calendar-minus"></i> Leave Requests</h2>
                <p>Submit leave requests and track the admin's decision.</p>
            </div>
            <span class="leave-status leave-pending">
                <?= $pendingLeaveCount ?> pending
            </span>
        </div>

        <form method="POST" action="availability.php#leave">
            <input type="hidden" name="action" value="submit_leave">

            <div class="leave-form-grid">
                <div class="availability-field">
                    <label for="leave_type">Leave Type</label>
                    <select name="leave_type" id="leave_type" required>
                        <option value="">Select leave type</option>
                        <?php foreach ($leave_types as $type): ?>
                            <option value="<?= availabilityEscape($type) ?>">
                                <?= availabilityEscape($type) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="availability-field">
                    <label for="start_date">Start Date</label>
                    <input type="date"
                           name="start_date"
                           id="start_date"
                           min="<?= date('Y-m-d') ?>"
                           required>
                </div>

                <div class="availability-field">
                    <label for="end_date">End Date</label>
                    <input type="date"
                           name="end_date"
                           id="end_date"
                           min="<?= date('Y-m-d') ?>"
                           required>
                </div>

                <div class="availability-field full-width-field">
                    <label for="reason">Reason for Leave</label>
                    <textarea name="reason"
                              id="reason"
                              maxlength="2000"
                              placeholder="Explain why you are requesting leave..."
                              required></textarea>
                </div>
            </div>

            <div class="availability-form-actions">
                <button type="submit" class="availability-primary-button">
                    <i class="fa-solid fa-paper-plane"></i> Submit Leave Request
                </button>
            </div>
        </form>

        <div class="availability-section-heading" style="margin-top:30px;">
            <div>
                <h3>My Leave History</h3>
                <p>Review your recent leave requests and their status.</p>
            </div>
        </div>

        <?php if ($leaveRecords): ?>
            <div class="availability-table-wrapper">
                <table class="availability-table">
                    <thead>
                        <tr>
                            <th>Leave Type</th>
                            <th>Start Date</th>
                            <th>End Date</th>
                            <th>Reason</th>
                            <th>Status</th>
                            <th>Admin Comment</th>
                            <th>Submitted</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($leaveRecords as $leave): ?>
                            <?php
                            $leaveStatusClass = 'leave-pending';

                            if (strcasecmp($leave['status'], 'Approved') === 0) {
                                $leaveStatusClass = 'leave-approved';
                            } elseif (strcasecmp($leave['status'], 'Rejected') === 0) {
                                $leaveStatusClass = 'leave-rejected';
                            }
                            ?>
                            <tr>
                                <td><?= availabilityEscape($leave['leave_type']) ?></td>
                                <td><?= availabilityEscape(
                                    date('d M Y', strtotime($leave['start_date']))
                                ) ?></td>
                                <td><?= availabilityEscape(
                                    date('d M Y', strtotime($leave['end_date']))
                                ) ?></td>
                                <td><?= availabilityEscape($leave['reason']) ?></td>
                                <td>
                                    <span class="leave-status <?= $leaveStatusClass ?>">
                                        <?= availabilityEscape($leave['status']) ?>
                                    </span>
                                </td>
                                <td class="review-comment">
                                    <?= availabilityEscape(
                                        $leave['review_comment'] ?: '—'
                                    ) ?>
                                </td>
                                <td><?= availabilityEscape(
                                    date('d M Y', strtotime($leave['created_at']))
                                ) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="availability-empty-state">
                <i class="fa-regular fa-calendar"></i>
                <h3>No leave requests yet</h3>
                <p>Your submitted leave requests will appear here.</p>
            </div>
        <?php endif; ?>
    </section>

    <section class="availability-info">
        <i class="fa-solid fa-circle-info"></i>
        <div>
            <strong>Important information</strong>
            <p>
                Attendance records your check-in and check-out. Availability tells
                clients when you can accept work. Leave requests must be reviewed
                by the admin; submitting a request does not automatically approve it.
            </p>
        </div>
    </section>

</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const profileTrigger = document.getElementById('profileTrigger');
    const profileDropdown = document.getElementById('profileDropdown');
    const darkModeToggle = document.getElementById('darkModeToggle');
    const themeIcon = document.getElementById('themeIcon');
    const themeText = document.getElementById('themeText');
    const mobileMenuToggle = document.getElementById('mobileMenuToggle');
    const mainNav = document.getElementById('mainNav');

    function updateThemeButton() {
        const dark = document.body.classList.contains('dark-mode');
        themeIcon.className = dark ? 'fas fa-sun' : 'fas fa-moon';
        themeText.textContent = dark ? 'Light Mode' : 'Dark Mode';
    }

    try {
        if (localStorage.getItem('handylink-provider-theme') === 'dark') {
            document.body.classList.add('dark-mode');
        }
    } catch (error) {}

    updateThemeButton();

    profileTrigger.addEventListener('click', function (event) {
        event.stopPropagation();
        const open = profileDropdown.classList.toggle('show');
        profileTrigger.setAttribute('aria-expanded', open ? 'true' : 'false');
    });

    profileDropdown.addEventListener('click', function (event) {
        event.stopPropagation();
    });

    document.addEventListener('click', function () {
        profileDropdown.classList.remove('show');
        profileTrigger.setAttribute('aria-expanded', 'false');
    });

    darkModeToggle.addEventListener('click', function () {
        document.body.classList.toggle('dark-mode');

        try {
            localStorage.setItem(
                'handylink-provider-theme',
                document.body.classList.contains('dark-mode') ? 'dark' : 'light'
            );
        } catch (error) {}

        updateThemeButton();
    });

    mobileMenuToggle.addEventListener('click', function () {
        const open = mainNav.classList.toggle('show');
        mobileMenuToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });

    mainNav.querySelectorAll('a').forEach(function (link) {
        link.addEventListener('click', function () {
            mainNav.classList.remove('show');
            mobileMenuToggle.setAttribute('aria-expanded', 'false');
        });
    });

    const startDate = document.getElementById('start_date');
    const endDate = document.getElementById('end_date');

    startDate.addEventListener('change', function () {
        endDate.min = startDate.value || '<?= date('Y-m-d') ?>';

        if (endDate.value && endDate.value < startDate.value) {
            endDate.value = startDate.value;
        }
    });
});
</script>

</body>
</html>