<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/access_control.php';

requireApprovedProvider();

$user_id = (int) $_SESSION['user_id'];
$provider_name = $_SESSION['full_name'] ?? 'Service Provider';
$profile_initial = strtoupper(substr($provider_name, 0, 1));

function escapeBooking($value)
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function bookingStatusClass($status)
{
    $status = strtolower(trim((string) ($status ?? '')));

    $allowed = [
        'pending',
        'accepted',
        'rejected',
        'completed',
        'cancelled'
    ];

    return in_array($status, $allowed, true) ? $status : 'unknown';
}

function bookingDisplayDate($value, $format = 'd M Y')
{
    if (empty($value) || $value === '0000-00-00') {
        return 'Not specified';
    }

    $timestamp = strtotime($value);

    return $timestamp ? date($format, $timestamp) : 'Not specified';
}

function bookingDisplayTime($value)
{
    if (empty($value) || $value === '00:00:00') {
        return 'Not specified';
    }

    $timestamp = strtotime($value);

    return $timestamp ? date('h:i A', $timestamp) : $value;
}

// Get the provider's actual providers.id.
$provider_stmt = $conn->prepare(
    "SELECT id FROM providers WHERE user_id = ? LIMIT 1"
);

$provider_stmt->bind_param('i', $user_id);
$provider_stmt->execute();

$provider_result = $provider_stmt->get_result();
$provider_record = $provider_result->fetch_assoc();

$provider_stmt->close();

if (!$provider_record) {
    header('Location: dashboard.php');
    exit();
}

$provider_id = (int) $provider_record['id'];

// Fetch this provider's bookings.
$sql = "
    SELECT
        b.id,
        b.request_id,
        b.client_id,
        b.booking_date,
        b.booking_time,
        b.status,
        b.notes,
        b.created_at,
        u.full_name AS client_name
    FROM bookings b
    INNER JOIN users u ON b.client_id = u.id
    WHERE b.provider_id = ?
    ORDER BY b.booking_date DESC, b.booking_time DESC, b.id DESC
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    error_log('Bookings query failed: ' . $conn->error);
    die('Unable to load bookings. Please try again later.');
}

$stmt->bind_param('i', $provider_id);
$stmt->execute();

$result = $stmt->get_result();
$bookings = [];

while ($row = $result->fetch_assoc()) {
    $bookings[] = $row;
}

$stmt->close();

$totalBookings = count($bookings);
$pendingBookings = 0;
$acceptedBookings = 0;
$completedBookings = 0;
$rejectedBookings = 0;
$cancelledBookings = 0;

foreach ($bookings as $booking) {
    $status = strtolower(trim((string) ($booking['status'] ?? '')));

    if ($status === 'pending') {
        $pendingBookings++;
    } elseif ($status === 'accepted') {
        $acceptedBookings++;
    } elseif ($status === 'completed') {
        $completedBookings++;
    } elseif ($status === 'rejected') {
        $rejectedBookings++;
    } elseif ($status === 'cancelled') {
        $cancelledBookings++;
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Bookings | HandyLink</title>

    <link rel="stylesheet" href="../assets/css/provider_dashboard.css">
    <link rel="stylesheet" href="../assets/css/provider_bookings.css">

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css"
    >
</head>

<body>

<header class="provider-header">

    <div class="header-left">

        <a href="dashboard.php" class="brand">
            <img
                src="/handylink/assets/images/handylink-logo.png"
                alt="HandyLink Logo"
                class="brand-logo"
            >
        </a>

        <button
            type="button"
            class="mobile-menu-toggle"
            id="mobileMenuToggle"
            aria-label="Toggle navigation"
            aria-expanded="false"
        >
            <i class="fas fa-bars"></i>
        </button>

    </div>

    <nav class="main-nav" id="mainNav">

        <a href="dashboard.php">
            <i class="fas fa-chart-line"></i>
            <span>Dashboard</span>
        </a>


        <a href="services.php">
            <i class="fas fa-tools"></i>
            <span>My Services</span>
        </a>

        <a href="requests.php">
            <i class="fas fa-clipboard-list"></i>
            <span>Requests</span>
        </a>

        <a href="bookings.php" class="active">
            <i class="fas fa-calendar-check"></i>
            <span>Bookings</span>
        </a>

        <a href="messages.php">
            <i class="fas fa-comments"></i>
            <span>Messages</span>
        </a>

        <a href="availability.php">
            <i class="fas fa-clock"></i>
            <span>Availability</span>
        </a>

        <a href="verification.php">
            <i class="fas fa-shield-alt"></i>
            <span>Verification</span>
        </a>

        <a href="reviews.php">
            <i class="fas fa-star"></i>
            <span>Reviews</span>
        </a>

        <a href="notification.php">
                <i class="fas fa-bell"></i>
                <span>Notifications</span>
         </a>       

    </nav>

    <div class="header-right">

        <button
            type="button"
            class="theme-toggle"
            id="themeToggle"
            aria-label="Toggle dark mode"
            title="Toggle dark mode"
        >
            <i class="fas fa-moon"></i>
        </button>

        <div class="profile-menu">

            <button
                type="button"
                class="profile-trigger"
                id="profileTrigger"
                aria-expanded="false"
            >
                <span class="profile-avatar">
                    <span class="profile-initial">
                        <?= escapeBooking($profile_initial); ?>
                    </span>
                </span>

                <span class="profile-name">
                    <?= escapeBooking($provider_name); ?>
                </span>

                <i class="fas fa-chevron-down profile-chevron"></i>
            </button>

            <div class="profile-dropdown" id="profileDropdown">

                <div class="profile-dropdown-heading">
                    <span class="dropdown-user-name">
                        <?= escapeBooking($provider_name); ?>
                    </span>

                    <span class="dropdown-user-role">
                        Service Provider
                    </span>
                </div>
                  <div class="dropdown-divider"></div>

                       <a href="settings.php">
                           <i class="fas fa-gear"></i>
                           Settings &amp; Profile
                       </a>
               
                       <button type="button" id="darkModeToggle">
                           <i class="fas fa-moon" id="themeIcon"></i>
                           <span id="themeText">Dark Mode</span>
                       </button>
               
                       <a href="help_support.php">
                           <i class="fas fa-circle-question"></i>
                           Help &amp; Support
                       </a>
               

                <div class="dropdown-divider"></div>

                <a href="logout.php" class="logout-link">
                    <i class="fas fa-sign-out-alt"></i>
                    Logout
                </a>

            </div>

        </div>

    </div>

</header>

<main class="provider-page bookings-page">

    <section class="bookings-page-heading">

        <div>
            <span class="bookings-eyebrow">SERVICE MANAGEMENT</span>

            <h1>My Bookings</h1>

            <p>
                View your scheduled services and manage your client bookings.
            </p>
        </div>

        <a href="requests.php" class="bookings-primary-button">
            <i class="fas fa-clipboard-list"></i>
            View Requests
        </a>

    </section>

    <?php if (isset($_GET['success']) && $_GET['success'] === 'updated'): ?>

        <div class="bookings-alert">
            <i class="fas fa-check-circle"></i>
            Booking status updated successfully.
        </div>

    <?php elseif (isset($_GET['error'])): ?>

        <div class="bookings-alert">
            <i class="fas fa-exclamation-circle"></i>

            <?php
            $errorMessages = [
                'invalid' => 'The booking information is invalid.',
                'failed' => 'The booking could not be updated. Please try again.',
                'not_allowed' => 'You are not authorized to update this booking.'
            ];

            echo escapeBooking(
                $errorMessages[$_GET['error']]
                ?? 'An unexpected error occurred.'
            );
            ?>
        </div>

    <?php endif; ?>

    <section class="booking-stats">

        <article class="booking-stat-card">
            <div class="booking-stat-icon">
                <i class="fas fa-calendar-check"></i>
            </div>

            <div class="booking-stat-info">
                <p>Total Bookings</p>
                <h3><?= $totalBookings; ?></h3>
            </div>
        </article>

        <article class="booking-stat-card">
            <div class="booking-stat-icon">
                <i class="fas fa-clock"></i>
            </div>

            <div class="booking-stat-info">
                <p>Pending</p>
                <h3><?= $pendingBookings; ?></h3>
            </div>
        </article>

        <article class="booking-stat-card">
            <div class="booking-stat-icon">
                <i class="fas fa-check-circle"></i>
            </div>

            <div class="booking-stat-info">
                <p>Accepted</p>
                <h3><?= $acceptedBookings; ?></h3>
            </div>
        </article>

        <article class="booking-stat-card">
            <div class="booking-stat-icon">
                <i class="fas fa-clipboard-check"></i>
            </div>

            <div class="booking-stat-info">
                <p>Completed</p>
                <h3><?= $completedBookings; ?></h3>
            </div>
        </article>

    </section>

    <section class="bookings-content-card">

        <div class="bookings-content-heading">

            <div>
                <h2>Booking Records</h2>
                <p>Review client details, appointment times and status.</p>
            </div>

            <span class="bookings-count">
                <?= $totalBookings; ?>
                <?= $totalBookings === 1 ? 'booking' : 'bookings'; ?>
            </span>

        </div>

        <?php if ($totalBookings > 0): ?>

            <div class="bookings-table-container">

                <table class="bookings-table">

                    <thead>
                        <tr>
                            <th>Client</th>
                            <th>Request ID</th>
                            <th>Booking Date</th>
                            <th>Booking Time</th>
                            <th>Status</th>
                            <th>Notes</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php foreach ($bookings as $booking): ?>

                        <?php
                        $status = strtolower(
                            trim((string) ($booking['status'] ?? ''))
                        );

                        $statusClass = bookingStatusClass($status);
                        $clientName = $booking['client_name'] ?: 'Unknown Client';
                        $initial = strtoupper(substr($clientName, 0, 1));
                        ?>

                        <tr>

                            <td>
                                <div class="booking-client">

                                    <div class="booking-client-avatar">
                                        <?= escapeBooking($initial); ?>
                                    </div>

                                    <span class="booking-client-name">
                                        <?= escapeBooking($clientName); ?>
                                    </span>

                                </div>
                            </td>

                            <td>
                                <span class="booking-reference">
                                    #<?= (int) $booking['request_id']; ?>
                                </span>
                            </td>

                            <td>
                                <span class="booking-date-time">
                                    <i class="far fa-calendar"></i>

                                    <?= escapeBooking(
                                        bookingDisplayDate($booking['booking_date'] ?? '')
                                    ); ?>
                                </span>
                            </td>

                            <td>
                                <span class="booking-date-time">
                                    <i class="far fa-clock"></i>

                                    <?= escapeBooking(
                                        bookingDisplayTime($booking['booking_time'] ?? '')
                                    ); ?>
                                </span>
                            </td>

                            <td>
                                <span class="booking-status <?= escapeBooking($statusClass); ?>">
                                    <?= escapeBooking(ucfirst($status ?: 'Unknown')); ?>
                                </span>
                            </td>

                            <td>
                                <div class="booking-notes">
                                    <?php if (!empty($booking['notes'])): ?>
                                        <?= nl2br(escapeBooking($booking['notes'])); ?>
                                    <?php else: ?>
                                        <span class="booking-no-notes">No notes</span>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <td>
                                <div class="booking-actions">

                                    <?php if ($status === 'pending'): ?>

                                        <form
                                            action="update_booking.php"
                                            method="POST"
                                            onsubmit="return confirm('Accept this booking?');"
                                        >
                                            <input
                                                type="hidden"
                                                name="booking_id"
                                                value="<?= (int) $booking['id']; ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="accepted"
                                            >

                                            <button
                                                type="submit"
                                                class="booking-action-button"
                                            >
                                                <i class="fas fa-check"></i>
                                                Accept
                                            </button>
                                        </form>

                                        <form
                                            action="update_booking.php"
                                            method="POST"
                                            onsubmit="return confirm('Reject this booking?');"
                                        >
                                            <input
                                                type="hidden"
                                                name="booking_id"
                                                value="<?= (int) $booking['id']; ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="rejected"
                                            >

                                            <button
                                                type="submit"
                                                class="booking-action-button reject-booking-button"
                                            >
                                                <i class="fas fa-times"></i>
                                                Reject
                                            </button>
                                        </form>

                                    <?php elseif ($status === 'accepted'): ?>

                                        <form
                                            action="update_booking.php"
                                            method="POST"
                                            onsubmit="return confirm('Mark this booking as completed?');"
                                        >
                                            <input
                                                type="hidden"
                                                name="booking_id"
                                                value="<?= (int) $booking['id']; ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="completed"
                                            >

                                            <button
                                                type="submit"
                                                class="booking-action-button"
                                            >
                                                <i class="fas fa-check-double"></i>
                                                Complete
                                            </button>
                                        </form>

                                    <?php else: ?>

                                        <span class="booking-no-action">
                                            <i class="fas fa-minus"></i>
                                            No actions
                                        </span>

                                    <?php endif; ?>

                                </div>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php else: ?>

            <div class="bookings-empty-state">

                <div class="bookings-empty-icon">
                    <i class="far fa-calendar"></i>
                </div>

                <h3>No bookings yet</h3>

                <p>
                    Your bookings will appear here when clients schedule
                    services with you.
                </p>

                <a href="requests.php" class="bookings-primary-button">
                    <i class="fas fa-clipboard-list"></i>
                    View Service Requests
                </a>

            </div>

        <?php endif; ?>

    </section>

</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const menuToggle = document.getElementById('mobileMenuToggle');
    const mainNav = document.getElementById('mainNav');

    const profileTrigger = document.getElementById('profileTrigger');
    const profileDropdown = document.getElementById('profileDropdown');

    const themeToggle = document.getElementById('themeToggle');
    const themeIcon = themeToggle
        ? themeToggle.querySelector('i')
        : null;

    const themeKey = 'handylink-provider-theme';

    function applyTheme(theme) {
        document.body.classList.toggle('dark-mode', theme === 'dark');

        if (themeIcon) {
            themeIcon.className = theme === 'dark'
                ? 'fas fa-sun'
                : 'fas fa-moon';
        }
    }

    let savedTheme = 'light';

    try {
        savedTheme = localStorage.getItem(themeKey) || 'light';
    } catch (error) {
        savedTheme = 'light';
    }

    applyTheme(savedTheme);

    if (themeToggle) {
        themeToggle.addEventListener('click', function () {
            const nextTheme = document.body.classList.contains('dark-mode')
                ? 'light'
                : 'dark';

            applyTheme(nextTheme);

            try {
                localStorage.setItem(themeKey, nextTheme);
            } catch (error) {
                // Theme switching remains available without browser storage.
            }
        });
    }

    if (menuToggle && mainNav) {
        menuToggle.addEventListener('click', function () {
            const isOpen = mainNav.classList.toggle('open');

            menuToggle.setAttribute('aria-expanded', String(isOpen));

            const icon = menuToggle.querySelector('i');

            if (icon) {
                icon.className = isOpen
                    ? 'fas fa-times'
                    : 'fas fa-bars';
            }
        });
    }

    if (profileTrigger && profileDropdown) {
        profileTrigger.addEventListener('click', function (event) {
            event.stopPropagation();

            const isOpen = profileDropdown.classList.toggle('show');

            profileTrigger.setAttribute(
                'aria-expanded',
                String(isOpen)
            );
        });

        document.addEventListener('click', function (event) {
            if (
                !profileDropdown.contains(event.target) &&
                !profileTrigger.contains(event.target)
            ) {
                profileDropdown.classList.remove('show');
                profileTrigger.setAttribute('aria-expanded', 'false');
            }
        });
    }
});
</script>

</body>
</html>
