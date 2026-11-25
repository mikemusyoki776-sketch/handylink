<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/access_control.php';

requireApprovedProvider();

$user_id = (int) $_SESSION['user_id'];
$provider_name = $_SESSION['full_name'] ?? 'Provider';
$profile_initial = strtoupper(substr($provider_name, 0, 1));

function requestStatusClass($status)
{
    $status = strtolower(trim((string) $status));

    if ($status === 'pending') {
        return 'status-pending';
    }

    if ($status === 'accepted') {
        return 'status-accepted';
    }

    if ($status === 'rejected') {
        return 'status-rejected';
    }

    return 'status-other';
}

function requestDisplayDate($value, $format = 'd M Y')
{
    if (empty($value) || $value === '0000-00-00') {
        return 'Not specified';
    }

    $timestamp = strtotime($value);

    return $timestamp ? date($format, $timestamp) : 'Not specified';
}

// Get the provider profile.
$provider_stmt = $conn->prepare(
    "SELECT id FROM providers WHERE user_id = ? LIMIT 1"
);

$provider_stmt->bind_param("i", $user_id);
$provider_stmt->execute();

$provider_result = $provider_stmt->get_result();
$provider = $provider_result->fetch_assoc();

$provider_stmt->close();

if (!$provider) {
    header("Location: dashboard.php");
    exit();
}

$provider_id = (int) $provider['id'];

// Retrieve requests matching the categories of this provider's services.
$sql = "
    SELECT
        sr.id,
        sr.title,
        sr.description,
        sr.location,
        sr.preferred_date,
        sr.preferred_time,
        sr.status,
        sr.created_at,
        u.full_name AS client_name,
        cat.name AS category_name
    FROM service_requests sr
    LEFT JOIN users u ON sr.client_id = u.id
    LEFT JOIN categories cat ON sr.category_id = cat.id
    WHERE EXISTS (
        SELECT 1
        FROM services s
        WHERE s.provider_id = ?
          AND s.category_id = sr.category_id
    )
    ORDER BY sr.created_at DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $provider_id);
$stmt->execute();

$result = $stmt->get_result();

$requests = [];
$total_requests = 0;
$pending_count = 0;
$accepted_count = 0;
$rejected_count = 0;

while ($request = $result->fetch_assoc()) {
    $requests[] = $request;
    $total_requests++;

    $status = strtolower(trim((string) $request['status']));

    if ($status === 'pending') {
        $pending_count++;
    } elseif ($status === 'accepted') {
        $accepted_count++;
    } elseif ($status === 'rejected') {
        $rejected_count++;
    }
}

$stmt->close();

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Service Requests | HandyLink</title>

    <link rel="stylesheet" href="../assets/css/provider_dashboard.css">
    <link rel="stylesheet" href="../assets/css/provider_requests.css">

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"
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

        <a href="requests.php" class="active">
            <i class="fas fa-clipboard-list"></i>
            <span>Requests</span>
        </a>

        <a href="bookings.php">
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

        <a href="review.php">
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
                        <?php echo htmlspecialchars($profile_initial); ?>
                    </span>
                </span>

                <span class="profile-name">
                    <?php echo htmlspecialchars($provider_name); ?>
                </span>

                <i class="fas fa-chevron-down profile-chevron"></i>
            </button>

            <div class="profile-dropdown" id="profileDropdown">

                <div class="profile-dropdown-heading">
                    <span class="dropdown-user-name">
                        <?php echo htmlspecialchars($provider_name); ?>
                    </span>

                    <span class="dropdown-user-role">
                        Service Provider
                    </span>
                </div>

                <a href="profile.php">
                    <i class="fas fa-user"></i>
                    My Profile
                </a>

                <a href="verification.php">
                    <i class="fas fa-shield-alt"></i>
                    Verification
                </a>

                <a href="availability.php">
                    <i class="fas fa-clock"></i>
                    Availability
                </a>

                <div class="dropdown-divider"></div>

                <a href="../actions/logout.php" class="logout-link">
                    <i class="fas fa-sign-out-alt"></i>
                    Logout
                </a>

            </div>

        </div>

    </div>

</header>

<main class="provider-page requests-page">

    <section class="requests-page-heading">

        <div class="requests-heading-text">
            <span class="requests-eyebrow">SERVICE MANAGEMENT</span>

            <h1>Service Requests</h1>

            <p>
                Review and respond to client requests matching your services.
            </p>
        </div>

        <a href="services.php" class="requests-back-button">
            <i class="fas fa-tools"></i>
            My Services
        </a>

    </section>

    <?php if (isset($_GET['success']) && $_GET['success'] === 'updated'): ?>

        <div class="requests-alert success-alert">
            <i class="fas fa-check-circle"></i>
            Request status updated successfully.
        </div>

    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>

        <div class="requests-alert error-alert">
            <i class="fas fa-exclamation-circle"></i>

            <?php
            $error_messages = [
                'invalid' => 'The request details are invalid.',
                'failed' => 'The request could not be updated. Please try again.',
                'not_allowed' => 'You are not authorized to update this request.'
            ];

            echo htmlspecialchars(
                $error_messages[$_GET['error']]
                ?? 'An unexpected error occurred.'
            );
            ?>
        </div>

    <?php endif; ?>

    <section class="request-summary">

        <article class="request-stat">
            <div class="request-stat-icon">
                <i class="fas fa-clipboard-list"></i>
            </div>

            <div class="request-stat-content">
                <span>Total Requests</span>
                <strong><?php echo $total_requests; ?></strong>
            </div>
        </article>

        <article class="request-stat">
            <div class="request-stat-icon">
                <i class="fas fa-hourglass-half"></i>
            </div>

            <div class="request-stat-content">
                <span>Pending</span>
                <strong><?php echo $pending_count; ?></strong>
            </div>
        </article>

        <article class="request-stat">
            <div class="request-stat-icon">
                <i class="fas fa-check-circle"></i>
            </div>

            <div class="request-stat-content">
                <span>Accepted</span>
                <strong><?php echo $accepted_count; ?></strong>
            </div>
        </article>

        <article class="request-stat">
            <div class="request-stat-icon">
                <i class="fas fa-times-circle"></i>
            </div>

            <div class="request-stat-content">
                <span>Rejected</span>
                <strong><?php echo $rejected_count; ?></strong>
            </div>
        </article>

    </section>

    <section class="requests-section">

        <div class="requests-section-heading">

            <div>
                <h2>Client Requests</h2>
                <p>Review request details before responding.</p>
            </div>

            <span class="request-count">
                <?php echo $total_requests; ?>
                <?php echo $total_requests === 1 ? 'request' : 'requests'; ?>
            </span>

        </div>

        <?php if (!empty($requests)): ?>

            <div class="requests-grid">

                <?php foreach ($requests as $request): ?>

                    <article class="request-card">

                        <div class="request-card-top">

                            <div class="request-category-icon">
                                <i class="fas fa-tools"></i>
                            </div>

                            <span class="request-status <?php
                                echo requestStatusClass($request['status']);
                            ?>">
                                <?php
                                echo htmlspecialchars(
                                    ucfirst((string) $request['status'])
                                );
                                ?>
                            </span>

                        </div>

                        <h3 class="request-title">
                            <?php
                            echo htmlspecialchars(
                                $request['title'] ?? 'Untitled Request'
                            );
                            ?>
                        </h3>

                        <div class="request-category">
                            <i class="fas fa-tag"></i>

                            <?php
                            echo htmlspecialchars(
                                $request['category_name'] ?? 'Uncategorized'
                            );
                            ?>
                        </div>

                        <div class="request-client">

                            <div class="client-avatar">
                                <i class="fas fa-user"></i>
                            </div>

                            <div class="request-client-info">
                                <span>Requested by</span>

                                <strong>
                                    <?php
                                    echo htmlspecialchars(
                                        $request['client_name'] ?? 'Unknown Client'
                                    );
                                    ?>
                                </strong>
                            </div>

                        </div>

                        <div class="request-description">

                            <span class="detail-label">Description</span>

                            <p>
                                <?php
                                $description = trim(
                                    (string) ($request['description'] ?? '')
                                );

                                echo nl2br(htmlspecialchars(
                                    $description !== ''
                                        ? $description
                                        : 'No description provided.'
                                ));
                                ?>
                            </p>

                        </div>

                        <div class="request-details">

                            <div class="request-detail">
                                <i class="fas fa-map-marker-alt"></i>

                                <div>
                                    <span>Location</span>

                                    <strong>
                                        <?php
                                        echo htmlspecialchars(
                                            $request['location'] ?? 'Not specified'
                                        );
                                        ?>
                                    </strong>
                                </div>
                            </div>

                            <div class="request-detail">
                                <i class="fas fa-calendar-alt"></i>

                                <div>
                                    <span>Preferred Date</span>

                                    <strong>
                                        <?php
                                        echo htmlspecialchars(
                                            requestDisplayDate(
                                                $request['preferred_date'] ?? ''
                                            )
                                        );
                                        ?>
                                    </strong>
                                </div>
                            </div>

                            <div class="request-detail">
                                <i class="fas fa-clock"></i>

                                <div>
                                    <span>Preferred Time</span>

                                    <strong>
                                        <?php
                                        $time = $request['preferred_time'] ?? '';

                                        if (
                                            $time !== '' &&
                                            $time !== '00:00:00'
                                        ) {
                                            $time_timestamp = strtotime($time);

                                            echo htmlspecialchars(
                                                $time_timestamp
                                                    ? date('h:i A', $time_timestamp)
                                                    : $time
                                            );
                                        } else {
                                            echo 'Not specified';
                                        }
                                        ?>
                                    </strong>
                                </div>
                            </div>

                            <div class="request-detail">
                                <i class="fas fa-paper-plane"></i>

                                <div>
                                    <span>Submitted</span>

                                    <strong>
                                        <?php
                                        echo htmlspecialchars(
                                            requestDisplayDate(
                                                $request['created_at'] ?? '',
                                                'd M Y, h:i A'
                                            )
                                        );
                                        ?>
                                    </strong>
                                </div>
                            </div>

                        </div>

                        <?php
                        $request_status = strtolower(
                            trim((string) $request['status'])
                        );
                        ?>

                        <?php if ($request_status === 'pending'): ?>

                            <div class="request-actions">

                                <form action="update_request.php" method="POST">

                                    <input
                                        type="hidden"
                                        name="request_id"
                                        value="<?php echo (int) $request['id']; ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="status"
                                        value="Accepted"
                                    >

                                    <button
                                        type="submit"
                                        class="request-accept-button"
                                    >
                                        <i class="fas fa-check"></i>
                                        Accept Request
                                    </button>

                                </form>

                                <form
                                    action="update_request.php"
                                    method="POST"
                                    onsubmit="return confirm('Are you sure you want to reject this request?');"
                                >

                                    <input
                                        type="hidden"
                                        name="request_id"
                                        value="<?php echo (int) $request['id']; ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="status"
                                        value="Rejected"
                                    >

                                    <button
                                        type="submit"
                                        class="request-reject-button"
                                    >
                                        <i class="fas fa-times"></i>
                                        Reject
                                    </button>

                                </form>

                            </div>

                        <?php else: ?>

                            <div class="request-action-note">
                                <i class="fas fa-info-circle"></i>

                                <span>
                                    You have already responded to this request.
                                </span>
                            </div>

                        <?php endif; ?>

                    </article>

                <?php endforeach; ?>

            </div>

        <?php else: ?>

            <div class="requests-empty-state">

                <div class="empty-state-icon">
                    <i class="fas fa-inbox"></i>
                </div>

                <h3>No Service Requests Yet</h3>

                <p>
                    No client requests currently match the categories of
                    your listed services. Matching requests will appear here.
                </p>

                <a href="services.php" class="requests-back-button">
                    <i class="fas fa-tools"></i>
                    View My Services
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

        if (themeToggle) {
            themeToggle.setAttribute(
                'aria-label',
                theme === 'dark'
                    ? 'Switch to light mode'
                    : 'Switch to dark mode'
            );
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
                // Theme switching still works if browser storage is unavailable.
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
