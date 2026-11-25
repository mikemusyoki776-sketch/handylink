<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/access_control.php';

requireNotificationAccess();

/* Get provider approval status. */
$providerAccess = getProviderApprovalStatus();

$provider_id = (int) $_SESSION['user_id'];
$provider_status = $providerAccess['status'];
$isApprovedProvider = $providerAccess['approved'];

$error = "";
$success = "";

/* CSRF protection. */
if (empty($_SESSION['notification_csrf'])) {
    $_SESSION['notification_csrf'] = bin2hex(random_bytes(32));
}

function notification_h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

/* Mark notification as read. */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['notification_id'])
) {
    $notification_id = filter_var(
        $_POST['notification_id'],
        FILTER_VALIDATE_INT
    );

    $csrf_token = $_POST['csrf_token'] ?? '';

    if (
        !$notification_id ||
        $notification_id <= 0 ||
        !hash_equals($_SESSION['notification_csrf'], $csrf_token)
    ) {
        $error = "Invalid request. Please refresh the page and try again.";
    } else {
        try {
            $stmt = $conn->prepare(
                "UPDATE notifications
                 SET is_read = 1
                 WHERE id = ? AND user_id = ?"
            );

            if (!$stmt) {
                throw new Exception($conn->error);
            }

            $stmt->bind_param("ii", $notification_id, $provider_id);
            $stmt->execute();

            if ($stmt->affected_rows > 0) {
                $stmt->close();

                header("Location: notification.php?read=success");
                exit;
            }

            $stmt->close();

            /* Check whether the notification exists and is already read. */
            $check = $conn->prepare(
                "SELECT is_read
                 FROM notifications
                 WHERE id = ? AND user_id = ?"
            );

            if (!$check) {
                throw new Exception($conn->error);
            }

            $check->bind_param("ii", $notification_id, $provider_id);
            $check->execute();
            $check->bind_result($found_read);

            if ($check->fetch()) {
                $check->close();

                if ((int) $found_read === 1) {
                    header("Location: notification.php?read=success");
                    exit;
                }

                $error = "The notification could not be marked as read.";
            } else {
                $check->close();
                $error = "Notification not found.";
            }

        } catch (Throwable $e) {
            error_log(
                "HandyLink notification update error: " . $e->getMessage()
            );

            $error = "Unable to update the notification. Please try again.";
        }
    }
}

if (isset($_GET['read']) && $_GET['read'] === 'success') {
    $success = "Notification marked as read successfully.";
}

/* Load notifications belonging to the logged-in provider. */
$notifications = [];
$unread_count = 0;

try {
    $stmt = $conn->prepare(
        "SELECT id, title, message, type, is_read, created_at
         FROM notifications
         WHERE user_id = ?
         ORDER BY created_at DESC, id DESC"
    );

    if (!$stmt) {
        throw new Exception($conn->error);
    }

    $stmt->bind_param("i", $provider_id);
    $stmt->execute();

    $result = $stmt->get_result();

    while ($notification = $result->fetch_assoc()) {
        $notifications[] = $notification;

        if ((int) $notification['is_read'] === 0) {
            $unread_count++;
        }
    }

    $stmt->close();

} catch (Throwable $e) {
    error_log(
        "HandyLink notification loading error: " . $e->getMessage()
    );

    $error = "Unable to load notifications. Check your database configuration.";
}

/* Load profile details for the provider header. */
$profileImage = '';
$fullName = '';
$email = '';
$firstName = '';
$profileInitial = 'P';

$profileStmt = $conn->prepare(
    "SELECT full_name, email, profile_image
     FROM users
     WHERE id = ?
     LIMIT 1"
);

$profileStmt->bind_param("i", $provider_id);
$profileStmt->execute();

$profile = $profileStmt->get_result()->fetch_assoc() ?: [];

$profileStmt->close();

$fullName = $profile['full_name'] ?? 'Provider';
$email = $profile['email'] ?? '';
$profileImage = $profile['profile_image'] ?? '';
$firstName = explode(' ', trim($fullName))[0];
$profileInitial = strtoupper(substr($firstName ?: 'P', 0, 1));

/* Recent notifications for the header dropdown. */
$recentNotifications = array_slice($notifications, 0, 5);
$unreadNotifications = $unread_count;

/* Count unread messages for the navigation badge. */
$unreadMessages = 0;

$messageStmt = $conn->prepare(
    "SELECT COUNT(*) AS unread_count
     FROM messages
     WHERE receiver_id = ? AND is_read = 0"
);

if ($messageStmt) {
    $messageStmt->bind_param("i", $provider_id);
    $messageStmt->execute();

    $messageResult = $messageStmt->get_result()->fetch_assoc();
    $unreadMessages = (int) ($messageResult['unread_count'] ?? 0);

    $messageStmt->close();
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Notifications | HandyLink</title>

    <link rel="stylesheet" href="../assets/css/style.css">
     <link rel="stylesheet" href="../assets/css/provider_dashboard.css">
    <link rel="stylesheet" href="../assets/css/provider_jobs.css?v=3">
    <link rel="stylesheet" href="../assets/css/provider_notifications.css">

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>


<script src="../assets/js/provider_notifications.js?v=5"></script>
<body class="jobs-page">

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

        <a href="notification.php" class="active">
            <i class="fas fa-bell"></i>
            <span>Notifications</span>
        </a>

    </nav>

    <div class="header-right">

        <!-- Notification Bell -->
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

                            <a href="notification.php"
                               class="notification-dropdown-item">

                                <strong>
                                    <?= notification_h($notification['title']) ?>
                                </strong>

                                <span>
                                    <?= notification_h($notification['message']) ?>
                                </span>

                                <small>
                                    <?= notification_h(
                                        date('d M Y, h:i A', strtotime($notification['created_at']))
                                    ) ?>
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

        <!-- Profile Menu -->
        <div class="profile-menu">

            <button type="button"
                    class="profile-trigger"
                    id="profileTrigger"
                    aria-expanded="false">

                <?php if (!empty($profileImage)): ?>

                    <img src="../<?= notification_h(ltrim($profileImage, '/')) ?>"
                         alt="Profile"
                         class="profile-avatar">

                <?php else: ?>

                    <span class="profile-avatar profile-initial">
                        <?= notification_h($profileInitial) ?>
                    </span>

                <?php endif; ?>

                <span class="profile-name">
                    <?= notification_h($firstName) ?>
                </span>

                <i class="fas fa-chevron-down dropdown-arrow"></i>

            </button>

            <div class="profile-dropdown" id="profileDropdown">

                <div class="dropdown-user-info">
                    <strong><?= notification_h($fullName) ?></strong>
                    <span><?= notification_h($email) ?></span>
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

<main class="jobs-container notifications-container">

    <section class="notifications-heading">

        <div>
            <p class="dashboard-eyebrow">PROVIDER WORKSPACE</p>

            <h1>Notifications</h1>

            <p>
                View verification decisions and other account updates from HandyLink.
            </p>
        </div>

        <div class="notification-count">
            <i class="fa-solid fa-bell"></i>
            <span><?= (int) $unread_count ?> unread</span>
        </div>

    </section>

    <?php if ($success !== ""): ?>

        <div class="notification-success">
            <i class="fa-solid fa-circle-check"></i>
            <?= notification_h($success) ?>
        </div>

    <?php endif; ?>

    <?php if ($error !== ""): ?>

        <div class="notification-error">
            <i class="fa-solid fa-circle-exclamation"></i>
            <?= notification_h($error) ?>
        </div>

    <?php endif; ?>

    <section class="notifications-list">

        <?php if (!empty($notifications)): ?>

            <?php foreach ($notifications as $notification): ?>

                <?php

                $type = strtolower((string) $notification['type']);

                $class = 'general';
                $icon = 'fa-bell';

                if ($type === 'approval' || $type === 'approved') {
                    $class = 'approval';
                    $icon = 'fa-circle-check';

                } elseif ($type === 'rejection' || $type === 'rejected') {
                    $class = 'rejection';
                    $icon = 'fa-circle-xmark';
                }

                $is_read = (int) $notification['is_read'] === 1;

                ?>

                <article class="notification-item <?= notification_h($class) ?> <?= $is_read ? 'read' : 'unread' ?>">

                    <div class="notification-icon">
                        <i class="fa-solid <?= notification_h($icon) ?>"></i>
                    </div>

                    <div class="notification-content">

                        <div class="notification-title-row">

                            <h2>
                                <?= notification_h($notification['title']) ?>
                            </h2>

                            <?php if (!$is_read): ?>

                                <span class="unread-label">
                                    New
                                </span>

                            <?php else: ?>

                                <span class="read-label">
                                    <i class="fa-solid fa-check"></i>
                                    Read
                                </span>

                            <?php endif; ?>

                        </div>

                        <p class="notification-message">
                            <?= notification_h($notification['message']) ?>
                        </p>

                        <span class="notification-date">
                            <i class="fa-regular fa-clock"></i>
                            <?= notification_h($notification['created_at']) ?>
                        </span>

                        <?php if (!$is_read): ?>

                            <form
                                method="POST"
                                action="notification.php"
                                class="mark-read-form">

                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= notification_h($_SESSION['notification_csrf']) ?>">

                                <input
                                    type="hidden"
                                    name="notification_id"
                                    value="<?= (int) $notification['id'] ?>">

                                <button type="submit" class="mark-read-button">
                                    <i class="fa-solid fa-check"></i>
                                    Mark as read
                                </button>

                            </form>

                        <?php endif; ?>

                    </div>

                </article>

            <?php endforeach; ?>

        <?php else: ?>

            <div class="notifications-empty">

                <i class="fa-regular fa-bell-slash"></i>

                <h2>No notifications yet</h2>

                <p>
                    When the administrator reviews your verification
                    or sends an update, it will appear here.
                </p>

            </div>

        <?php endif; ?>

    </section>

</main>

<footer class="provider-dashboard-footer">
    <p>
        &copy; <?= date("Y") ?> HandyLink Solution. All rights reserved.
    </p>
</footer>

<script src="../assets/js/provider_notifications.js"></script>

</body>
</html>
