
<?php
session_start();
require_once "../config/database.php";

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'client'
) {
    header("Location: ../login.php");
    exit();
}

$clientId = (int) $_SESSION['user_id'];
$fullName = $_SESSION['full_name'] ?? 'Client';
$firstName = explode(' ', trim($fullName))[0];

$error = '';
$success = '';

/* Submit a review from this same file */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review'])) {
    $bookingId = (int) ($_POST['booking_id'] ?? 0);
    $rating = (int) ($_POST['rating'] ?? 0);
    $comment = trim($_POST['comment'] ?? '');

    if ($bookingId <= 0) {
        $error = "Please select a valid booking.";
    } elseif ($rating < 1 || $rating > 5) {
        $error = "Please select a rating from 1 to 5 stars.";
    } elseif ($comment === '') {
        $error = "Please write a comment about your experience.";
    } elseif (mb_strlen($comment) > 2000) {
        $error = "Your review must not exceed 2,000 characters.";
    } else {
        /*
         * The booking must belong to this client, be completed,
         * and have a provider associated with it.
         */
        $bookingStmt = $conn->prepare(
            "SELECT b.id, b.provider_id
             FROM bookings b
             WHERE b.id = ?
               AND b.client_id = ?
               AND b.status = 'completed'
             LIMIT 1"
        );

        $bookingStmt->bind_param("ii", $bookingId, $clientId);
        $bookingStmt->execute();
        $booking = $bookingStmt->get_result()->fetch_assoc();
        $bookingStmt->close();

        if (!$booking) {
            $error = "Only your completed bookings can be reviewed.";
        } else {
            $providerId = (int) $booking['provider_id'];

            /* Prevent a client from reviewing the same booking twice */
            $existingStmt = $conn->prepare(
                "SELECT id
                 FROM reviews
                 WHERE booking_id = ?
                   AND client_id = ?
                 LIMIT 1"
            );

            $existingStmt->bind_param("ii", $bookingId, $clientId);
            $existingStmt->execute();
            $alreadyReviewed = $existingStmt->get_result()->num_rows > 0;
            $existingStmt->close();

            if ($alreadyReviewed) {
                $error = "You have already reviewed this booking.";
            } else {
                $insertStmt = $conn->prepare(
                    "INSERT INTO reviews
                        (booking_id, client_id, provider_id, rating, comment, created_at)
                     VALUES (?, ?, ?, ?, ?, NOW())"
                );

                $insertStmt->bind_param(
                    "iiiis",
                    $bookingId,
                    $clientId,
                    $providerId,
                    $rating,
                    $comment
                );

                if ($insertStmt->execute()) {
                    $insertStmt->close();

                    header("Location: reviews.php?success=1");
                    exit();
                } else {
                    $error = "Your review could not be saved. Please try again.";
                    $insertStmt->close();
                }
            }
        }
    }
}

/* Display a success message after redirect */
if (isset($_GET['success']) && $_GET['success'] === '1') {
    $success = "Your review has been submitted successfully.";
}

/* Count unread messages for the shared header */
$unreadMessages = 0;

$unreadStmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM messages
     WHERE receiver_id = ? AND is_read = 0"
);

if ($unreadStmt) {
    $unreadStmt->bind_param("i", $clientId);
    $unreadStmt->execute();
    $unreadMessages = (int) (
        $unreadStmt->get_result()->fetch_assoc()['total'] ?? 0
    );
    $unreadStmt->close();
}

/* Review statistics for this client */
$stats = [
    'total' => 0,
    'average' => 0,
    'five_star' => 0
];

$statsStmt = $conn->prepare(
    "SELECT
        COUNT(*) AS total,
        COALESCE(AVG(rating), 0) AS average,
        COALESCE(SUM(rating = 5), 0) AS five_star
     FROM reviews
     WHERE client_id = ?"
);

$statsStmt->bind_param("i", $clientId);
$statsStmt->execute();
$stats = $statsStmt->get_result()->fetch_assoc();
$statsStmt->close();

$stats['total'] = (int) $stats['total'];
$stats['average'] = (float) $stats['average'];
$stats['five_star'] = (int) $stats['five_star'];

/* Load completed bookings that have not yet been reviewed */
$eligibleBookings = [];

$eligibleStmt = $conn->prepare(
    "SELECT
        b.id AS booking_id,
        b.booking_date,
        sr.title AS service_title,
        c.name AS category_name,
        u.full_name AS provider_name
     FROM bookings b
     LEFT JOIN service_requests sr ON sr.id = b.request_id
     LEFT JOIN categories c ON c.id = sr.category_id
     JOIN providers p ON p.id = b.provider_id
     JOIN users u ON u.id = p.user_id
     LEFT JOIN reviews r
        ON r.booking_id = b.id AND r.client_id = b.client_id
     WHERE b.client_id = ?
       AND b.status = 'completed'
       AND r.id IS NULL
     ORDER BY b.booking_date DESC, b.id DESC"
);

$eligibleStmt->bind_param("i", $clientId);
$eligibleStmt->execute();
$eligibleResult = $eligibleStmt->get_result();

while ($row = $eligibleResult->fetch_assoc()) {
    $eligibleBookings[] = $row;
}

$eligibleStmt->close();

/* Load reviews already submitted by this client */
$reviews = [];

$reviewsStmt = $conn->prepare(
    "SELECT
        r.id,
        r.booking_id,
        r.rating,
        r.comment,
        r.created_at,
        u.full_name AS provider_name,
        sr.title AS service_title,
        c.name AS category_name
     FROM reviews r
     JOIN bookings b ON b.id = r.booking_id
     JOIN providers p ON p.id = r.provider_id
     JOIN users u ON u.id = p.user_id
     LEFT JOIN service_requests sr ON sr.id = b.request_id
     LEFT JOIN categories c ON c.id = sr.category_id
     WHERE r.client_id = ?
     ORDER BY r.created_at DESC, r.id DESC"
);

$reviewsStmt->bind_param("i", $clientId);
$reviewsStmt->execute();
$reviewsResult = $reviewsStmt->get_result();

while ($row = $reviewsResult->fetch_assoc()) {
    $reviews[] = $row;
}

$reviewsStmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Reviews | HandyLink</title>

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/clients/client.css">
    <link rel="stylesheet" href="../assets/css/clients/reviews.css">
        <script src="../assets/js/client_darkmode.js" defer></script>
</head>
<body>

<header class="client-header">
    <a href="dashboard.php" class="brand">
        <img src="../assets/images/handylink-logo.png" alt="HandyLink Logo">
        <span>HandyLink</span>
    </a>

    <button class="mobile-menu-button" id="mobileMenuButton"
            type="button" aria-label="Toggle navigation" aria-expanded="false">
        <i class="fa-solid fa-bars"></i>
    </button>

    <nav class="main-navigation" id="mainNavigation">
        <a class="nav-link" href="dashboard.php">
            <i class="fa-solid fa-house"></i><span>Dashboard</span>
        </a>
        <a class="nav-link" href="find_services.php">
            <i class="fa-solid fa-magnifying-glass"></i><span>Find Services</span>
        </a>
        <a class="nav-link" href="my_request.php">
            <i class="fa-regular fa-clipboard"></i><span>My Requests</span>
        </a>
        <a class="nav-link" href="bookings.php">
            <i class="fa-regular fa-calendar-check"></i><span>My Bookings</span>
        </a>
        <a class="nav-link" href="messages.php">
            <i class="fa-regular fa-comments"></i><span>Messages</span>
        </a>
        <a class="nav-link active" href="reviews.php">
            <i class="fa-regular fa-star"></i><span>My Reviews</span>
        </a>
    </nav>

    <div class="header-actions">
        <a href="messages.php" class="notification-button"
           aria-label="Unread messages" title="Messages">
            <i class="fa-regular fa-bell bell-icon"></i>
            <?php if ($unreadMessages > 0): ?>
                <span class="notification-dot"></span>
            <?php endif; ?>
        </a>

        <div class="profile-menu-wrapper">
            <button type="button" class="profile-button"
                    id="profileButton" aria-expanded="false">
                <span class="profile-avatar profile-initial">
                    <?= htmlspecialchars(strtoupper(substr($firstName, 0, 1))) ?>
                </span>
                <span class="profile-name"><?= htmlspecialchars($firstName) ?></span>
                <i class="fa-solid fa-chevron-down chevron-icon"></i>
            </button>

            <div class="profile-dropdown" id="profileDropdown">
                <div class="dropdown-user">
                    <strong><?= htmlspecialchars($fullName) ?></strong>
                    <span>Client account</span>
                </div>
                <a href="settings.php">
                    <i class="fa-solid fa-gear"></i>Profile &amp; Settings
                </a>
                <a href="../logout.php" class="dropdown-logout">
                    <i class="fa-solid fa-right-from-bracket"></i>Log Out
                </a>
            </div>
        </div>
    </div>
</header>

<main class="reviews-page">

    <section class="reviews-hero">
        <div class="reviews-hero-content">
            <span class="reviews-eyebrow">YOUR EXPERIENCE MATTERS</span>
            <h1>My Reviews</h1>
            <p>Share your experience and help other clients find reliable service providers.</p>
        </div>
        <div class="reviews-hero-icon">
            <i class="fa-solid fa-star"></i>
        </div>
    </section>

    <?php if ($success !== ''): ?>
        <div class="review-alert success-alert" role="status">
            <i class="fa-solid fa-circle-check"></i>
            <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="review-alert error-alert" role="alert">
            <i class="fa-solid fa-circle-exclamation"></i>
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <section class="review-stats">
        <article class="review-stat-card">
            <div class="review-stat-icon green-icon">
                <i class="fa-regular fa-pen-to-square"></i>
            </div>
            <div>
                <span>Total reviews</span>
                <strong><?= $stats['total'] ?></strong>
                <small>Reviews you've submitted</small>
            </div>
        </article>

        <article class="review-stat-card">
            <div class="review-stat-icon gold-icon">
                <i class="fa-solid fa-star"></i>
            </div>
            <div>
                <span>Your average rating</span>
                <strong><?= number_format($stats['average'], 1) ?><small class="stat-out-of"> / 5</small></strong>
                <small>Your average score</small>
            </div>
        </article>

        <article class="review-stat-card">
            <div class="review-stat-icon blue-icon">
                <i class="fa-solid fa-award"></i>
            </div>
            <div>
                <span>Five-star reviews</span>
                <strong><?= $stats['five_star'] ?></strong>
                <small>Excellent experiences</small>
            </div>
        </article>
    </section>

    <section class="review-section">
        <div class="section-heading">
            <div>
                <span class="section-eyebrow">FEEDBACK</span>
                <h2>Review a completed service</h2>
                <p>Choose a completed booking that you haven't reviewed yet.</p>
            </div>
            <span class="section-count">
                <?= count($eligibleBookings) ?> available
            </span>
        </div>

        <?php if (count($eligibleBookings) > 0): ?>
            <div class="eligible-bookings-grid">
                <?php foreach ($eligibleBookings as $booking): ?>
                    <article class="eligible-booking-card">
                        <div class="eligible-card-top">
                            <div class="provider-review-avatar">
                                <?= htmlspecialchars(strtoupper(substr($booking['provider_name'], 0, 1))) ?>
                            </div>
                            <span class="completed-label">
                                <i class="fa-solid fa-circle-check"></i> Completed
                            </span>
                        </div>

                        <h3><?= htmlspecialchars($booking['service_title'] ?: 'Service booking') ?></h3>
                        <p class="booking-category">
                            <?= htmlspecialchars($booking['category_name'] ?: 'General service') ?>
                        </p>

                        <div class="booking-provider">
                            <i class="fa-solid fa-user"></i>
                            <span><?= htmlspecialchars($booking['provider_name']) ?></span>
                        </div>

                        <div class="booking-date">
                            <i class="fa-regular fa-calendar"></i>
                            <span>
                                <?= !empty($booking['booking_date'])
                                    ? date('d M Y', strtotime($booking['booking_date']))
                                    : 'Date not available' ?>
                            </span>
                        </div>

                        <button
                            type="button"
                            class="write-review-button"
                            data-booking-id="<?= (int) $booking['booking_id'] ?>"
                            data-provider="<?= htmlspecialchars($booking['provider_name'], ENT_QUOTES, 'UTF-8') ?>"
                            data-service="<?= htmlspecialchars($booking['service_title'] ?: 'Service booking', ENT_QUOTES, 'UTF-8') ?>"
                        >
                            <i class="fa-solid fa-star"></i> Write a review
                        </button>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="review-empty-state">
                <div class="review-empty-icon">
                    <i class="fa-regular fa-star"></i>
                </div>
                <h3>No services waiting for a review</h3>
                <p>When a booking is marked completed, you can share your experience here.</p>
                <a href="bookings.php" class="secondary-action">
                    <i class="fa-regular fa-calendar-check"></i> View my bookings
                </a>
            </div>
        <?php endif; ?>
    </section>

    <section class="review-section submitted-reviews-section">
        <div class="section-heading">
            <div>
                <span class="section-eyebrow">YOUR FEEDBACK HISTORY</span>
                <h2>Reviews you've submitted</h2>
                <p>Your feedback helps build trust across HandyLink.</p>
            </div>
            <span class="section-count">
                <?= count($reviews) ?> review<?= count($reviews) === 1 ? '' : 's' ?>
            </span>
        </div>

        <?php if (count($reviews) > 0): ?>
            <div class="submitted-reviews-list">
                <?php foreach ($reviews as $review): ?>
                    <article class="submitted-review-card">
                        <div class="submitted-review-avatar">
                            <?= htmlspecialchars(strtoupper(substr($review['provider_name'], 0, 1))) ?>
                        </div>

                        <div class="submitted-review-content">
                            <div class="submitted-review-heading">
                                <div>
                                    <h3><?= htmlspecialchars($review['provider_name']) ?></h3>
                                    <p><?= htmlspecialchars($review['service_title'] ?: 'Service booking') ?></p>
                                </div>
                                <time>
                                    <?= !empty($review['created_at'])
                                        ? date('d M Y', strtotime($review['created_at']))
                                        : '' ?>
                                </time>
                            </div>

                            <div class="review-stars" aria-label="<?= (int) $review['rating'] ?> out of 5 stars">
                                <?php for ($star = 1; $star <= 5; $star++): ?>
                                    <i class="<?= $star <= (int) $review['rating'] ? 'fa-solid' : 'fa-regular' ?> fa-star"></i>
                                <?php endfor; ?>
                                <span><?= (int) $review['rating'] ?>.0</span>
                            </div>

                            <p class="submitted-review-comment">
                                <?= nl2br(htmlspecialchars($review['comment'])) ?>
                            </p>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="review-empty-state compact-empty-state">
                <div class="review-empty-icon">
                    <i class="fa-regular fa-message"></i>
                </div>
                <h3>You haven't submitted any reviews</h3>
                <p>Your submitted reviews will appear here.</p>
            </div>
        <?php endif; ?>
    </section>
</main>

<!-- Review form modal -->
<div class="review-modal" id="reviewModal" aria-hidden="true">
    <div class="review-modal-backdrop" data-close-modal></div>

    <section class="review-modal-card" role="dialog"
             aria-modal="true" aria-labelledby="reviewModalTitle">
        <button type="button" class="review-modal-close"
                id="closeReviewModal" aria-label="Close review form">
            <i class="fa-solid fa-xmark"></i>
        </button>

        <div class="modal-star-icon"><i class="fa-solid fa-star"></i></div>
        <span class="section-eyebrow">SHARE YOUR EXPERIENCE</span>
        <h2 id="reviewModalTitle">Rate your service</h2>
        <p class="modal-provider-name" id="modalProviderName"></p>
        <p class="modal-service-name" id="modalServiceName"></p>

        <form method="POST" action="reviews.php" id="reviewForm">
            <input type="hidden" name="booking_id" id="modalBookingId">

            <fieldset class="rating-fieldset">
                <legend>Your rating</legend>
                <div class="star-rating-input" role="radiogroup" aria-label="Choose a rating">
                    <?php for ($star = 5; $star >= 1; $star--): ?>
                        <input type="radio"
                               id="rating<?= $star ?>"
                               name="rating"
                               value="<?= $star ?>"
                               required>
                        <label for="rating<?= $star ?>"
                               title="<?= $star ?> star<?= $star === 1 ? '' : 's' ?>">
                            <i class="fa-solid fa-star"></i>
                        </label>
                    <?php endfor; ?>
                </div>
                <p class="rating-description" id="ratingDescription">Select a star rating</p>
            </fieldset>

            <label for="reviewComment" class="comment-label">Your review</label>
            <textarea id="reviewComment" name="comment" rows="5"
                      maxlength="2000" required
                      placeholder="Tell us about your experience with this provider..."></textarea>
            <div class="comment-footer">
                <span>Be respectful and honest.</span>
                <span id="commentCount">0 / 2000</span>
            </div>

            <button type="submit" name="submit_review" class="submit-review-button">
                <i class="fa-solid fa-paper-plane"></i> Submit review
            </button>
        </form>
    </section>
</div>

<footer class="client-footer">
    <p>&copy; <?= date('Y') ?> HandyLink. Connecting people with trusted service providers.</p>
</footer>

<script>
const profileButton = document.getElementById("profileButton");
const profileDropdown = document.getElementById("profileDropdown");
const mobileMenuButton = document.getElementById("mobileMenuButton");
const mainNavigation = document.getElementById("mainNavigation");

if (profileButton && profileDropdown) {
    profileButton.addEventListener("click", function (event) {
        event.stopPropagation();
        const isOpen = profileDropdown.classList.toggle("show");
        profileButton.setAttribute("aria-expanded", isOpen ? "true" : "false");
    });

    document.addEventListener("click", function (event) {
        if (!profileDropdown.contains(event.target) && !profileButton.contains(event.target)) {
            profileDropdown.classList.remove("show");
            profileButton.setAttribute("aria-expanded", "false");
        }
    });
}

if (mobileMenuButton && mainNavigation) {
    mobileMenuButton.addEventListener("click", function () {
        const isOpen = mainNavigation.classList.toggle("show");
        mobileMenuButton.setAttribute("aria-expanded", isOpen ? "true" : "false");
        const icon = mobileMenuButton.querySelector("i");
        if (icon) {
            icon.className = isOpen ? "fa-solid fa-xmark" : "fa-solid fa-bars";
        }
    });
}

/* Open and close the review modal */
const reviewModal = document.getElementById("reviewModal");
const closeReviewModal = document.getElementById("closeReviewModal");
const modalBookingId = document.getElementById("modalBookingId");
const modalProviderName = document.getElementById("modalProviderName");
const modalServiceName = document.getElementById("modalServiceName");
const reviewComment = document.getElementById("reviewComment");
const commentCount = document.getElementById("commentCount");
const ratingDescription = document.getElementById("ratingDescription");

const ratingLabels = {
    1: "Poor",
    2: "Fair",
    3: "Good",
    4: "Very good",
    5: "Excellent"
};

function openReviewModal(button) {
    modalBookingId.value = button.dataset.bookingId;
    modalProviderName.textContent = button.dataset.provider;
    modalServiceName.textContent = button.dataset.service;

    document.querySelectorAll('input[name="rating"]').forEach(input => {
        input.checked = false;
    });

    reviewComment.value = "";
    commentCount.textContent = "0 / 2000";
    ratingDescription.textContent = "Select a star rating";

    reviewModal.classList.add("show");
    reviewModal.setAttribute("aria-hidden", "false");
    document.body.classList.add("review-modal-open");
}

function closeModal() {
    reviewModal.classList.remove("show");
    reviewModal.setAttribute("aria-hidden", "true");
    document.body.classList.remove("review-modal-open");
}

document.querySelectorAll(".write-review-button").forEach(button => {
    button.addEventListener("click", () => openReviewModal(button));
});

closeReviewModal.addEventListener("click", closeModal);

reviewModal.querySelectorAll("[data-close-modal]").forEach(element => {
    element.addEventListener("click", closeModal);
});

document.addEventListener("keydown", event => {
    if (event.key === "Escape" && reviewModal.classList.contains("show")) {
        closeModal();
    }
});

document.querySelectorAll('input[name="rating"]').forEach(input => {
    input.addEventListener("change", function () {
        ratingDescription.textContent = ratingLabels[this.value] || "Select a star rating";
    });
});

reviewComment.addEventListener("input", function () {
    commentCount.textContent = this.value.length + " / 2000";
});
</script>

</body>
</html>
