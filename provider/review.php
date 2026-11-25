<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/access_control.php';

if (empty($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

requireApprovedProvider();

$user_id = (int) $_SESSION['user_id'];

function reviewEscape($value)
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function reviewStars($rating)
{
    $rating = max(0, min(5, (float) $rating));
    $output = '';

    for ($i = 1; $i <= 5; $i++) {
        if ($rating >= $i) {
            $output .= '<i class="fa-solid fa-star"></i>';
        } elseif ($rating >= $i - 0.5) {
            $output .= '<i class="fa-solid fa-star-half-stroke"></i>';
        } else {
            $output .= '<i class="fa-regular fa-star"></i>';
        }
    }

    return $output;
}

/* Provider account details */
$stmt = $conn->prepare("
    SELECT full_name, email, profile_image
    FROM users
    WHERE id = ? AND role = 'provider'
    LIMIT 1
");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    http_response_code(403);
    exit('Provider account not found.');
}

$fullName = $user['full_name'] ?? 'Provider';
$firstName = explode(' ', trim($fullName))[0] ?: 'Provider';
$email = $user['email'] ?? '';
$profileImage = $user['profile_image'] ?? '';
$profileInitial = strtoupper(substr($fullName, 0, 1));

/* Get providers.id */
$stmt = $conn->prepare("
    SELECT id
    FROM providers
    WHERE user_id = ?
    LIMIT 1
");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$provider = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$provider) {
    http_response_code(403);
    exit('Your provider record could not be found.');
}

$provider_id = (int) $provider['id'];

/* Unread message count */
$unreadMessages = 0;

$stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM messages
    WHERE receiver_id = ? AND is_read = 0
");

if ($stmt) {
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $unreadMessages = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
}

/* Unread notification count */
$unreadNotifications = 0;
$tableResult = $conn->query("SHOW TABLES LIKE 'notifications'");

if ($tableResult && $tableResult->num_rows > 0) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM notifications
        WHERE user_id = ? AND is_read = 0
    ");

    if ($stmt) {
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $unreadNotifications = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();
    }
}

/* Reviews and rating statistics */
$reviews = [];
$reviewError = false;
$averageRating = 0;
$totalReviews = 0;
$ratingTotal = 0;

/* Always initialize exactly five valid rating keys */
$ratingCounts = [
    1 => 0,
    2 => 0,
    3 => 0,
    4 => 0,
    5 => 0
];

$stmt = $conn->prepare("
    SELECT
        r.rating,
        r.comment,
        r.created_at,
        u.full_name AS client_name
    FROM reviews r
    LEFT JOIN users u ON u.id = r.client_id
    WHERE r.provider_id = ?
    ORDER BY r.created_at DESC
");

if ($stmt) {
    $stmt->bind_param('i', $provider_id);

    if ($stmt->execute()) {
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $rating = (int) $row['rating'];

            /* Ignore invalid database ratings instead of inventing a rating */
            if ($rating < 1 || $rating > 5) {
                continue;
            }

            $row['rating'] = $rating;
            $reviews[] = $row;

            /* Increment only a valid, initialized key */
            $ratingCounts[$rating]++;
            $ratingTotal += $rating;
        }
    } else {
        $reviewError = true;
    }

    $stmt->close();
} else {
    $reviewError = true;
}

$totalReviews = count($reviews);

if ($totalReviews > 0) {
    $averageRating = $ratingTotal / $totalReviews;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Reviews | HandyLink</title>

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <link rel="stylesheet" href="../assets/css/provider_dashboard.css">
    <link rel="stylesheet" href="../assets/css/provider_reviews.css?v=11">
</head>

<body class="reviews-body">

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

        <a href="verification.php">
            <i class="fas fa-shield-halved"></i>
            <span>Verification</span>
        </a>

        <a href="reviews.php" class="active" aria-current="page">
            <i class="fas fa-star"></i>
            <span>Reviews</span>
        </a>

        <a href="messages.php" class="nav-link-with-badge">
            <i class="fas fa-envelope"></i>
            <span>Messages</span>

            <?php if ($unreadMessages > 0): ?>
                <span class="nav-badge">
                    <?= $unreadMessages > 99 ? '99+' : $unreadMessages ?>
                </span>
            <?php endif; ?>
        </a>

        <a href="notification.php" class="nav-link-with-badge">
            <i class="fas fa-bell"></i>
            <span>Notifications</span>

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
                    aria-expanded="false"
                    aria-controls="profileDropdown">

                <?php if (!empty($profileImage)): ?>
                    <img
                        src="../<?= reviewEscape(ltrim($profileImage, '/')) ?>"
                        alt="Profile"
                        class="profile-avatar">
                <?php else: ?>
                    <span class="profile-avatar profile-initial">
                        <?= reviewEscape($profileInitial) ?>
                    </span>
                <?php endif; ?>

                <span class="profile-name">
                    <?= reviewEscape($firstName) ?>
                </span>

                <i class="fas fa-chevron-down dropdown-arrow"></i>
            </button>

            <div class="profile-dropdown" id="profileDropdown">
                <div class="dropdown-user-info">
                    <strong><?= reviewEscape($fullName) ?></strong>
                    <span><?= reviewEscape($email) ?></span>
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

                <a href="help.php">
                    <i class="fas fa-circle-question"></i>
                    Help &amp; Support
                </a>

                <div class="dropdown-divider"></div>

                <a href="../actions/logout.php" class="logout-link">
                    <i class="fas fa-right-from-bracket"></i>
                    Log Out
                </a>
            </div>
        </div>
    </div>
</header>

<main class="reviews-page">

    <section class="reviews-welcome">
        <div class="welcome-copy">
            <span class="reviews-eyebrow">
                <i class="fas fa-chart-line"></i>
                PROVIDER WORKSPACE
            </span>

            <h1>Customer Reviews</h1>

            <p class="reviews-welcome-text">
                Your work speaks for itself. Explore client feedback
                and see how your service ratings are performing.
            </p>
        </div>

        <div class="reviews-welcome-icon">
            <i class="fas fa-star"></i>
        </div>
    </section>

    <?php if ($reviewError): ?>

        <div class="reviews-alert reviews-alert-error" role="alert">
            <i class="fas fa-circle-exclamation"></i>
            Reviews could not be loaded. Please check your reviews table and try again.
        </div>

    <?php else: ?>

        <section class="reviews-summary-grid">

            <article class="review-summary overall-summary">
                <div class="summary-heading">
                    <span>Overall Rating</span>
                    <span class="summary-icon">
                        <i class="fas fa-star"></i>
                    </span>
                </div>

                <div class="overall-rating-content">
                    <div class="average-rating">
                        <?= number_format($averageRating, 1) ?>
                        <span class="rating-out-of">/ 5.0</span>
                    </div>

                    <div class="rating-stars"
                         aria-label="<?= number_format($averageRating, 1) ?> out of 5 stars">
                        <?= reviewStars($averageRating) ?>
                    </div>
                </div>

                <div class="summary-divider"></div>

                <p class="summary-footnote">
                    <i class="fas fa-heart"></i>
                    Your clients' feedback
                </p>
            </article>

            <article class="review-summary total-summary">
                <div class="summary-heading">
                    <span>Total Reviews</span>
                    <span class="summary-icon">
                        <i class="fas fa-comments"></i>
                    </span>
                </div>

                <div class="average-rating">
                    <?= number_format($totalReviews) ?>
                </div>

                <p class="total-summary-label">
                    <?= $totalReviews === 1
                        ? 'Client review received'
                        : 'Client reviews received' ?>
                </p>

                <div class="summary-divider"></div>

                <p class="summary-footnote">
                    <i class="fas fa-message"></i>
                    All recorded feedback
                </p>
            </article>

            <article class="rating-breakdown">
                <div class="breakdown-heading">
                    <div>
                        <h2>Rating Breakdown</h2>
                        <p>Ratings from your clients</p>
                    </div>

                    <span class="breakdown-heading-icon">
                        <i class="fas fa-chart-bar"></i>
                    </span>
                </div>

                <?php for ($star = 5; $star >= 1; $star--): ?>
                    <?php
                    $count = $ratingCounts[$star] ?? 0;
                    $percentage = $totalReviews > 0
                        ? ($count / $totalReviews) * 100
                        : 0;
                    ?>

                    <div class="rating-row">
                        <span class="rating-label">
                            <?= $star ?>
                            <i class="fas fa-star"></i>
                        </span>

                        <div class="rating-track">
                            <div class="rating-fill"
                                 style="width: <?= number_format($percentage, 2, '.', '') ?>%">
                            </div>
                        </div>

                        <span class="rating-count"><?= $count ?></span>
                    </div>
                <?php endfor; ?>
            </article>

        </section>

        <section class="reviews-content">
            <div class="reviews-content-heading">
                <div>
                    <span class="section-eyebrow">CLIENT FEEDBACK</span>
                    <h2>Recent Reviews</h2>
                    <p>Read the feedback clients have shared about your services.</p>
                </div>

                <span class="reviews-count">
                    <i class="fas fa-star"></i>
                    <?= $totalReviews ?>
                    <?= $totalReviews === 1 ? 'review' : 'reviews' ?>
                </span>
            </div>

            <?php if (empty($reviews)): ?>

                <div class="empty-state">
                    <div class="empty-state-icon">
                        <i class="far fa-star"></i>
                    </div>

                    <h3>No Reviews Yet</h3>

                    <p>
                        Your customer reviews will appear here after clients
                        rate your services.
                    </p>
                </div>

            <?php else: ?>

                <div class="reviews-list">
                    <?php foreach ($reviews as $review): ?>
                        <?php
                        $clientName = trim($review['client_name'] ?? '') ?: 'HandyLink Client';
                        $clientInitial = strtoupper(substr($clientName, 0, 1));

                        $reviewDate = !empty($review['created_at'])
                            ? strtotime($review['created_at'])
                            : false;
                        ?>

                        <article class="review-card">

                            <div class="review-card-top">
                                <div class="client-avatar">
                                    <?= reviewEscape($clientInitial) ?>
                                </div>

                                <div class="review-client-details">
                                    <h3><?= reviewEscape($clientName) ?></h3>

                                    <span class="review-date">
                                        <i class="far fa-calendar"></i>
                                        <?= $reviewDate
                                            ? reviewEscape(date('d M Y', $reviewDate))
                                            : 'Date unavailable' ?>
                                    </span>
                                </div>

                                <div class="review-rating">
                                    <span class="review-rating-number">
                                        <?= (int) $review['rating'] ?>.0
                                    </span>

                                    <span class="review-stars">
                                        <?= reviewStars($review['rating']) ?>
                                    </span>
                                </div>
                            </div>

                            <div class="review-comment">
                                <?php if (trim((string) ($review['comment'] ?? '')) !== ''): ?>
                                    <p><?= nl2br(reviewEscape($review['comment'])) ?></p>
                                <?php else: ?>
                                    <p class="review-no-comment">
                                        No written comment was provided.
                                    </p>
                                <?php endif; ?>
                            </div>

                        </article>
                    <?php endforeach; ?>
                </div>

            <?php endif; ?>
        </section>

    <?php endif; ?>

</main>

<footer class="provider-footer reviews-footer">
    <p>&copy; <?= date('Y') ?> HandyLink. All rights reserved.</p>
    <p>Connecting clients with trusted service providers.</p>
</footer>

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
        const isDark = document.body.classList.contains('dark-mode');

        themeIcon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
        themeText.textContent = isDark ? 'Light Mode' : 'Dark Mode';

        darkModeToggle.setAttribute(
            'aria-pressed',
            isDark ? 'true' : 'false'
        );
    }

    function applySavedTheme() {
        let savedTheme = 'light';

        try {
            savedTheme =
                localStorage.getItem('handylink-provider-theme') || 'light';
        } catch (error) {
            // Use the light theme if browser storage is unavailable.
        }

        document.body.classList.toggle('dark-mode', savedTheme === 'dark');
        updateThemeButton();
    }

    applySavedTheme();

    profileTrigger.addEventListener('click', function (event) {
        event.stopPropagation();

        const isOpen = profileDropdown.classList.toggle('show');

        profileTrigger.setAttribute(
            'aria-expanded',
            isOpen ? 'true' : 'false'
        );
    });

    profileDropdown.addEventListener('click', function (event) {
        event.stopPropagation();
    });

    document.addEventListener('click', function () {
        profileDropdown.classList.remove('show');
        profileTrigger.setAttribute('aria-expanded', 'false');
    });

    darkModeToggle.addEventListener('click', function () {
        const isDark = document.body.classList.toggle('dark-mode');

        try {
            localStorage.setItem(
                'handylink-provider-theme',
                isDark ? 'dark' : 'light'
            );
        } catch (error) {
            // The theme still changes for this page.
        }

        updateThemeButton();
    });

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

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            profileDropdown.classList.remove('show');
            profileTrigger.setAttribute('aria-expanded', 'false');

            mainNav.classList.remove('show');
            mobileMenuToggle.setAttribute('aria-expanded', 'false');
        }
    });
});
</script>

</body>
</html>
