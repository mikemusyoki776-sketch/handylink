
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/access_control.php';

requireApprovedProvider();

$user_id = (int) $_SESSION['user_id'];

/* Get provider account details. */
$userQuery = $conn->prepare(
    "SELECT full_name, email, profile_image, status
     FROM users
     WHERE id = ? AND role = 'provider'
     LIMIT 1"
);

$userQuery->bind_param("i", $user_id);
$userQuery->execute();
$user = $userQuery->get_result()->fetch_assoc();
$userQuery->close();

if (!$user || strtolower(trim($user['status'] ?? '')) !== 'active') {
    session_destroy();
    header('Location: ../login.php');
    exit();
}

$fullName = $user['full_name'] ?? 'Provider';
$firstName = explode(' ', trim($fullName))[0];
$email = $user['email'] ?? '';
$profileImage = $user['profile_image'] ?? '';

/* Get provider details. */
$providerQuery = $conn->prepare(
    "SELECT id, verification_status, availability_status, rating
     FROM providers
     WHERE user_id = ?
     LIMIT 1"
);

$providerQuery->bind_param("i", $user_id);
$providerQuery->execute();
$provider = $providerQuery->get_result()->fetch_assoc();
$providerQuery->close();

if (!$provider || strtolower(trim($provider['verification_status'] ?? '')) !== 'approved') {
    $_SESSION['provider_access_notice'] =
        'Your provider account must be approved before accessing the dashboard.';

    header('Location: verification.php');
    exit();
}

$provider_id = (int) $provider['id'];
$verificationStatus = strtolower(trim($provider['verification_status'] ?? 'pending'));
$availabilityStatus = $provider['availability_status'] ?? 'unavailable';
$rating = (float) ($provider['rating'] ?? 0);

$servicesCount = 0;
$pendingRequests = 0;
$bookingsCount = 0;
$reviewsCount = 0;
$unreadMessages = 0;
$unreadNotifications = 0;

$recentBookings = [];
$recentNotifications = [];

/* Count provider services. */
$stmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM services
     WHERE provider_id = ?"
);

if ($stmt) {
    $stmt->bind_param("i", $provider_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $servicesCount = (int) ($result['total'] ?? 0);
    $stmt->close();
}

/* Count pending requests matching the provider's service category. */
$stmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM service_requests sr
     WHERE sr.status = 'pending'
     AND EXISTS (
         SELECT 1
         FROM services s
         WHERE s.provider_id = ?
         AND s.category_id = sr.category_id
     )"
);

if ($stmt) {
    $stmt->bind_param("i", $provider_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $pendingRequests = (int) ($result['total'] ?? 0);
    $stmt->close();
}

/* Count bookings. */
$stmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM bookings
     WHERE provider_id = ?"
);

if ($stmt) {
    $stmt->bind_param("i", $provider_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $bookingsCount = (int) ($result['total'] ?? 0);
    $stmt->close();
}

/* Count reviews. */
$stmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM reviews
     WHERE provider_id = ?"
);

if ($stmt) {
    $stmt->bind_param("i", $provider_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $reviewsCount = (int) ($result['total'] ?? 0);
    $stmt->close();
}

/* Get the latest bookings. */
$stmt = $conn->prepare(
    "SELECT b.id, b.booking_date, b.booking_time, b.status,
            u.full_name AS client_name
     FROM bookings b
     LEFT JOIN users u ON b.client_id = u.id
     WHERE b.provider_id = ?
     ORDER BY b.created_at DESC
     LIMIT 5"
);

if ($stmt) {
    $stmt->bind_param("i", $provider_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $recentBookings[] = $row;
    }

    $stmt->close();
}

/* Count unread messages. */
$stmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM messages
     WHERE receiver_id = ? AND is_read = 0"
);

if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $unreadMessages = (int) ($result['total'] ?? 0);
    $stmt->close();
}

/* Count and retrieve notifications. */
$tableResult = $conn->query("SHOW TABLES LIKE 'notifications'");

if ($tableResult && $tableResult->num_rows > 0) {

    /* Count unread notifications. */
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM notifications
         WHERE user_id = ? AND is_read = 0"
    );

    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $unreadNotifications = (int) ($result['total'] ?? 0);
        $stmt->close();
    }

    /* Get the five latest notifications. */
    $stmt = $conn->prepare(
        "SELECT id, title, message, type, is_read, created_at
         FROM notifications
         WHERE user_id = ?
         ORDER BY created_at DESC, id DESC
         LIMIT 5"
    );

    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $recentNotifications[] = $row;
        }

        $stmt->close();
    }
}

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function statusClass($status)
{
    return preg_replace(
        '/[^a-z0-9_-]/',
        '',
        strtolower(trim((string) $status))
    );
}

$profileInitial = strtoupper(substr($fullName, 0, 1));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Provider Dashboard | HandyLink</title>

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <link rel="stylesheet" href="../assets/css/provider_dashboard.css">
    <link rel="stylesheet" href="../assets/css/provider_notifications.css">
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

        <a href="dashboard.php" class="active">
            <i class="fas fa-house"></i>
            <span>Dashboard</span>
        </a>

        <a href="verification.php">
            <i class="fas fa-shield-halved"></i>
            <span>Verification</span>
        </a>

        <a href="services.php">
            <i class="fas fa-screwdriver-wrench"></i>
            <span>My Services</span>
        </a>

        <a href="requests.php">
            <i class="fas fa-clipboard-list"></i>
            <span>Requests</span>
        </a>

        <a href="bookings.php">
            <i class="fas fa-calendar-check"></i>
            <span>Bookings</span>
        </a>

        <a href="availability.php">
            <i class="fas fa-calendar-days"></i>
            <span>Availability</span>
        </a>

        <a href="review.php">
            <i class="fas fa-star"></i>
            <span>Reviews</span>
        </a>

        <a href="messages.php">
            <i class="fas fa-envelope"></i>
            <span>Messages</span>

            <?php if ($unreadMessages > 0): ?>
                <span class="nav-badge">
                    <?= $unreadMessages > 99 ? '99+' : $unreadMessages ?>
                </span>
            <?php endif; ?>
        </a>
        <a href="notification.php">
                <i class="fas fa-bell"></i>
                <span>Notifications</span>
            </a>
    </nav>

   <div class="header-right">

    <!-- Notification Bell: immediately before Profile -->
    <div class="notification-menu">
        <button type="button"
                class="notification-trigger"
                id="notificationTrigger"
                aria-label="Notifications"
                aria-expanded="false">

            <i class="fas fa-bell"></i>

            <?php if ($unreadNotifications > 0): ?>
                <span class="notification-badge">
                    <?= $unreadNotifications > 99 ? '99+' : $unreadNotifications ?>
                </span>
            <?php endif; ?>

            <i class="fas fa-chevron-down notification-arrow"></i>
        </button>

        <div class="notification-dropdown" id="notificationDropdown">
            <div class="notification-dropdown-header">
                <strong>Notifications</strong>
                <span><?= (int) $unreadNotifications ?> unread</span>
            </div>

            <div class="notification-dropdown-list">
                <?php if (!empty($recentNotifications)): ?>
                    <?php foreach ($recentNotifications as $notification): ?>
                        <a href="notification.php" class="notification-dropdown-item">
                            <strong><?= e($notification['title']) ?></strong>
                            <span><?= e($notification['message']) ?></span>
                            <small>
                                <?= e(date('d M Y, h:i A', strtotime($notification['created_at']))) ?>
                            </small>
                        </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="notification-dropdown-empty">
                        No notifications yet.
                    </div>
                <?php endif; ?>
            </div>

            <a href="notification.php" class="notification-dropdown-footer">
                View All Notifications
            </a>
        </div>
    </div>

    <!-- RESTORE YOUR ORIGINAL PROFILE MENU HERE -->
    <!-- Keep its existing HTML, classes, links and IDs unchanged. -->
     
<div class="profile-menu">

    <button type="button"
            class="profile-trigger"
            id="profileTrigger"
            aria-expanded="false">

        <?php if (!empty($profileImage)): ?>

            <img src="../<?= e(ltrim($profileImage, '/')) ?>"
                 alt="Profile"
                 class="profile-avatar">

        <?php else: ?>

            <span class="profile-avatar profile-initial">
                <?= e($profileInitial) ?>
            </span>

        <?php endif; ?>

        <span class="profile-name">
            <?= e($firstName) ?>
        </span>

        <i class="fas fa-chevron-down dropdown-arrow"></i>

    </button>

    <div class="profile-dropdown" id="profileDropdown">

        <div class="dropdown-user-info">
            <strong><?= e($fullName) ?></strong>
            <span><?= e($email) ?></span>
            <small>Service Provider</small>
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
            <i class="fas fa-right-from-bracket"></i>
            Log Out
        </a>

    </div>

</div>

</div>

</header>

<main class="dashboard-container">

    <section class="welcome-section">
        <div class="welcome-text">
            <p class="eyebrow">PROVIDER WORKSPACE</p>
            <h1>Welcome back, <?= e($firstName) ?>!</h1>
            <p>
                Manage your assigned service, bookings, availability
                and client requests from one place.
            </p>
        </div>

        <a href="services.php" class="primary-button">
            <i class="fas fa-screwdriver-wrench"></i>
            Manage Service
        </a>
    </section>

    <section class="account-status-panel">

        <article class="account-status-item">
            <span class="status-icon">
                <i class="fas fa-shield-halved"></i>
            </span>

            <div>
                <span class="status-label">Verification Status</span>
                <strong class="status-value status-<?= e(statusClass($verificationStatus)) ?>">
                    <?= e(ucfirst(str_replace('_', ' ', $verificationStatus))) ?>
                </strong>
            </div>

            <a href="verification.php" class="status-action">
                View <i class="fas fa-arrow-right"></i>
            </a>
        </article>

        <article class="account-status-item">
            <span class="status-icon">
                <i class="fas fa-calendar-days"></i>
            </span>

            <div>
                <span class="status-label">Availability</span>
                <strong class="status-value status-<?= e(statusClass($availabilityStatus)) ?>">
                    <?= e(ucfirst(str_replace('_', ' ', $availabilityStatus))) ?>
                </strong>
            </div>

            <a href="availability.php" class="status-action">
                Manage <i class="fas fa-arrow-right"></i>
            </a>
        </article>

    </section>

    <section class="stats-grid">

        <article class="stat-card">
            <div class="stat-icon">
                <i class="fas fa-screwdriver-wrench"></i>
            </div>
            <div class="stat-details">
                <p>My Services</p>
                <h2><?= $servicesCount ?></h2>
                <span>Listed service records</span>
            </div>
            <a href="services.php" class="stat-link" aria-label="View services">
                <i class="fas fa-arrow-up-right-from-square"></i>
            </a>
        </article>

        <article class="stat-card">
            <div class="stat-icon">
                <i class="fas fa-clipboard-list"></i>
            </div>
            <div class="stat-details">
                <p>Pending Requests</p>
                <h2><?= $pendingRequests ?></h2>
                <span>Awaiting attention</span>
            </div>
            <a href="requests.php" class="stat-link" aria-label="View requests">
                <i class="fas fa-arrow-up-right-from-square"></i>
            </a>
        </article>

        <article class="stat-card">
            <div class="stat-icon">
                <i class="fas fa-calendar-check"></i>
            </div>
            <div class="stat-details">
                <p>Total Bookings</p>
                <h2><?= $bookingsCount ?></h2>
                <span>Bookings received</span>
            </div>
            <a href="bookings.php" class="stat-link" aria-label="View bookings">
                <i class="fas fa-arrow-up-right-from-square"></i>
            </a>
        </article>

        <article class="stat-card">
            <div class="stat-icon">
                <i class="fas fa-star"></i>
            </div>
            <div class="stat-details">
                <p>Reviews</p>
                <h2><?= $reviewsCount ?></h2>
                <span>Client feedback</span>
            </div>
            <a href="reviews.php" class="stat-link" aria-label="View reviews">
                <i class="fas fa-arrow-up-right-from-square"></i>
            </a>
        </article>

    </section>

    <section class="dashboard-content-grid">

        <div class="content-panel recent-bookings-panel">

            <div class="panel-heading">
                <div>
                    <h2>Recent Bookings</h2>
                    <p>Keep track of your latest client bookings.</p>
                </div>

                <a href="bookings.php" class="view-all-link">
                    View All <i class="fas fa-arrow-right"></i>
                </a>
            </div>

            <?php if (!empty($recentBookings)): ?>

                <div class="table-responsive">
                    <table class="bookings-table">
                        <thead>
                        <tr>
                            <th>Client</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Status</th>
                        </tr>
                        </thead>

                        <tbody>
                        <?php foreach ($recentBookings as $booking): ?>
                            <tr>
                                <td>
                                    <div class="client-cell">
                                        <span class="client-avatar">
                                            <i class="fas fa-user"></i>
                                        </span>
                                        <span><?= e($booking['client_name'] ?? 'Client') ?></span>
                                    </div>
                                </td>

                                <td>
                                    <?= !empty($booking['booking_date'])
                                        ? e(date('d M Y', strtotime($booking['booking_date'])))
                                        : 'Not set' ?>
                                </td>

                                <td>
                                    <?= !empty($booking['booking_time'])
                                        ? e(date('h:i A', strtotime($booking['booking_time'])))
                                        : 'Not set' ?>
                                </td>

                                <td>
                                    <span class="booking-status status-<?= e(statusClass($booking['status'] ?? 'pending')) ?>">
                                        <?= e(ucfirst(str_replace('_', ' ', $booking['status'] ?? 'pending'))) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <?php else: ?>

                <div class="empty-state">
                    <span class="empty-state-icon">
                        <i class="fas fa-calendar-xmark"></i>
                    </span>
                    <h3>No bookings yet</h3>
                    <p>
                        Your recent bookings will appear here when clients
                        book your assigned service.
                    </p>
                    <a href="services.php" class="secondary-button">
                        View My Service
                    </a>
                </div>

            <?php endif; ?>

        </div>

        <aside class="content-panel quick-actions-panel">

            <div class="panel-heading">
                <div>
                    <h2>Quick Actions</h2>
                    <p>Access common provider tasks.</p>
                </div>
            </div>

            <div class="quick-actions-list">

                <a href="services.php" class="quick-action">
                    <span class="quick-action-icon">
                        <i class="fas fa-screwdriver-wrench"></i>
                    </span>
                    <span>
                        <strong>My Services</strong>
                        <small>View your assigned service</small>
                    </span>
                    <i class="fas fa-chevron-right action-arrow"></i>
                </a>

                <a href="requests.php" class="quick-action">
                    <span class="quick-action-icon">
                        <i class="fas fa-clipboard-list"></i>
                    </span>
                    <span>
                        <strong>Service Requests</strong>
                        <small>Review incoming requests</small>
                    </span>
                    <i class="fas fa-chevron-right action-arrow"></i>
                </a>

                <a href="availability.php" class="quick-action">
                    <span class="quick-action-icon">
                        <i class="fas fa-calendar-days"></i>
                    </span>
                    <span>
                        <strong>Set Availability</strong>
                        <small>Manage your working schedule</small>
                    </span>
                    <i class="fas fa-chevron-right action-arrow"></i>
                </a>

                <a href="messages.php" class="quick-action">
                    <span class="quick-action-icon">
                        <i class="fas fa-envelope"></i>
                    </span>
                    <span>
                        <strong>Messages</strong>
                        <small>Communicate with clients and admin</small>
                    </span>
                    <i class="fas fa-chevron-right action-arrow"></i>
                </a>

                <a href="verification.php" class="quick-action">
                    <span class="quick-action-icon">
                        <i class="fas fa-shield-halved"></i>
                    </span>
                    <span>
                        <strong>Verification</strong>
                        <small>View document status and feedback</small>
                    </span>
                    <i class="fas fa-chevron-right action-arrow"></i>
                </a>

            </div>
        </aside>

    </section>

    <section class="recommendation-panel">
        <div class="recommendation-icon">
            <i class="fas fa-lightbulb"></i>
        </div>

        <div class="recommendation-content">
            <h2>Keep Your Provider Profile Updated</h2>
            <p>
                Maintain accurate profile information, update your availability
                and respond to client requests promptly.
            </p>
        </div>

        <a href="settings.php" class="secondary-button">
            Update Profile
        </a>
    </section>

</main>

<footer class="provider-footer">
    <p>&copy; <?= date('Y') ?> HandyLink. All rights reserved.</p>
    <p>Connecting clients with trusted service providers.</p>
</footer>


<script>
document.addEventListener('DOMContentLoaded', function () {

    const profileTrigger = document.getElementById('profileTrigger');
    const profileDropdown = document.getElementById('profileDropdown');

    const notificationTrigger = document.getElementById('notificationTrigger');
    const notificationDropdown = document.getElementById('notificationDropdown');

    const darkModeToggle = document.getElementById('darkModeToggle');
    const themeIcon = document.getElementById('themeIcon');
    const themeText = document.getElementById('themeText');

    const mobileMenuToggle = document.getElementById('mobileMenuToggle');
    const mainNav = document.getElementById('mainNav');

    /* Update theme button */
    function updateThemeButton() {
        const darkModeEnabled = document.body.classList.contains('dark-mode');

        if (themeIcon) {
            themeIcon.className = darkModeEnabled
                ? 'fas fa-sun'
                : 'fas fa-moon';
        }

        if (themeText) {
            themeText.textContent = darkModeEnabled
                ? 'Light Mode'
                : 'Dark Mode';
        }
    }

    /* Load saved theme */
    try {
        if (localStorage.getItem('handylink-provider-theme') === 'dark') {
            document.body.classList.add('dark-mode');
        }
    } catch (error) {
        // Theme storage may be unavailable.
    }

    updateThemeButton();

    /* Close notification dropdown */
    function closeNotificationDropdown() {
        if (notificationDropdown) {
            notificationDropdown.classList.remove('show');
        }

        if (notificationTrigger) {
            notificationTrigger.classList.remove('active');
            notificationTrigger.setAttribute('aria-expanded', 'false');
        }
    }

    /* Close profile dropdown */
    function closeProfileDropdown() {
        if (profileDropdown) {
            profileDropdown.classList.remove('show');
        }

        if (profileTrigger) {
            profileTrigger.setAttribute('aria-expanded', 'false');
        }
    }

    /* Profile dropdown */
    if (profileTrigger && profileDropdown) {

        profileTrigger.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();

            closeNotificationDropdown();

            const isOpen = profileDropdown.classList.toggle('show');

            profileTrigger.setAttribute(
                'aria-expanded',
                isOpen ? 'true' : 'false'
            );
        });

        profileDropdown.addEventListener('click', function (event) {
            event.stopPropagation();
        });
    }

    /* Notification dropdown */
    if (notificationTrigger && notificationDropdown) {

        notificationTrigger.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();

            closeProfileDropdown();

            const isOpen = !notificationDropdown.classList.contains('show');

            notificationDropdown.classList.toggle('show', isOpen);
            notificationTrigger.classList.toggle('active', isOpen);

            notificationTrigger.setAttribute(
                'aria-expanded',
                isOpen ? 'true' : 'false'
            );
        });

        notificationDropdown.addEventListener('click', function (event) {
            event.stopPropagation();
        });
    }

    /* Close dropdowns when clicking outside */
    document.addEventListener('click', function (event) {

        if (!event.target.closest('.notification-menu')) {
            closeNotificationDropdown();
        }

        if (!event.target.closest('.profile-menu')) {
            closeProfileDropdown();
        }
    });

    /* Dark mode */
    if (darkModeToggle) {

        darkModeToggle.addEventListener('click', function () {

            document.body.classList.toggle('dark-mode');

            try {
                localStorage.setItem(
                    'handylink-provider-theme',
                    document.body.classList.contains('dark-mode')
                        ? 'dark'
                        : 'light'
                );
            } catch (error) {
                // Theme still changes for the current page.
            }

            updateThemeButton();
        });
    }

    /* Mobile navigation */
    if (mobileMenuToggle && mainNav) {

        mobileMenuToggle.addEventListener('click', function () {

            const isOpen = mainNav.classList.toggle('show');

            mobileMenuToggle.setAttribute(
                'aria-expanded',
                isOpen ? 'true' : 'false'
            );
        });

        mainNav.querySelectorAll('a').forEach(function (link) {

            link.addEventListener('click', function () {
                mainNav.classList.remove('show');

                mobileMenuToggle.setAttribute('aria-expanded', 'false');
            });
        });
    }

});
</script>
</body>
</html>
