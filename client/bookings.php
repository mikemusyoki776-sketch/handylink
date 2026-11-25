
<?php
session_start();

if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "client"
) {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . "/../config/database.php";

$userId = (int) $_SESSION["user_id"];
$fullName = $_SESSION["full_name"] ?? "Client";
$firstName = explode(" ", trim($fullName))[0];
$profileInitial = strtoupper(substr($firstName, 0, 1));

$search = trim($_GET["search"] ?? "");
$statusFilter = trim($_GET["status"] ?? "");

$allowedStatuses = [
    "pending",
    "accepted",
    "rejected",
    "in_progress",
    "completed",
    "cancelled"
];

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = "";
}

$bookings = [];
$unreadMessages = 0;
$errorMessage = "";
$stats = [
    "total" => 0,
    "pending" => 0,
    "accepted" => 0,
    "in_progress" => 0,
    "completed" => 0
];

/* Get unread messages for the navigation notification. */
$messageStmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM messages
     WHERE receiver_id = ? AND is_read = 0"
);

if ($messageStmt) {
    $messageStmt->bind_param("i", $userId);

    if ($messageStmt->execute()) {
        $messageResult = $messageStmt->get_result();
        $messageRow = $messageResult->fetch_assoc();
        $unreadMessages = (int) ($messageRow["total"] ?? 0);
    }

    $messageStmt->close();
}

/* Get booking statistics for this client. */
$statsStmt = $conn->prepare(
    "SELECT
        COUNT(*) AS total,
        COALESCE(SUM(status = 'pending'), 0) AS pending,
        COALESCE(SUM(status = 'accepted'), 0) AS accepted,
        COALESCE(SUM(status = 'in_progress'), 0) AS in_progress,
        COALESCE(SUM(status = 'completed'), 0) AS completed
     FROM bookings
     WHERE client_id = ?"
);

if ($statsStmt) {
    $statsStmt->bind_param("i", $userId);

    if ($statsStmt->execute()) {
        $statsResult = $statsStmt->get_result();
        $statsRow = $statsResult->fetch_assoc();

        if ($statsRow) {
            foreach ($stats as $key => $value) {
                $stats[$key] = (int) ($statsRow[$key] ?? 0);
            }
        }
    }

    $statsStmt->close();
}

/* Retrieve bookings with their service request, category and provider. */
$sql = "
    SELECT
        b.id AS booking_id,
        b.booking_date,
        b.booking_time,
        b.status AS booking_status,
        b.notes,
        b.created_at AS booking_created_at,

        sr.id AS request_id,
        sr.title AS service_title,
        sr.description AS service_description,
        sr.location AS service_location,
        sr.preferred_date,
        sr.preferred_time,

        c.name AS category_name,
        u.full_name AS provider_name

    FROM bookings b

    LEFT JOIN service_requests sr
        ON sr.id = b.request_id

    LEFT JOIN categories c
        ON c.id = sr.category_id

    LEFT JOIN providers p
        ON p.id = b.provider_id

    LEFT JOIN users u
        ON u.id = p.user_id

    WHERE b.client_id = ?

      AND (
          ? = ''
          OR sr.title LIKE CONCAT('%', ?, '%')
          OR sr.location LIKE CONCAT('%', ?, '%')
          OR c.name LIKE CONCAT('%', ?, '%')
          OR u.full_name LIKE CONCAT('%', ?, '%')
      )

      AND (? = '' OR b.status = ?)

    ORDER BY
        CASE
            WHEN b.status = 'pending' THEN 1
            WHEN b.status = 'accepted' THEN 2
            WHEN b.status = 'in_progress' THEN 3
            WHEN b.status = 'completed' THEN 4
            ELSE 5
        END,
        b.created_at DESC
";

$bookingStmt = $conn->prepare($sql);

if ($bookingStmt) {
    $bookingStmt->bind_param(
        "isssssss",
        $userId,
        $search,
        $search,
        $search,
        $search,
        $search,
        $statusFilter,
        $statusFilter
    );

    if ($bookingStmt->execute()) {
        $bookingResult = $bookingStmt->get_result();

        while ($row = $bookingResult->fetch_assoc()) {
            $bookings[] = $row;
        }
    } else {
        $errorMessage = "We couldn't load your bookings. Please try again.";
    }

    $bookingStmt->close();
} else {
    $errorMessage = "We couldn't prepare the bookings list. Please check your database structure.";
}

/* Format booking statuses for display. */
function bookingStatusLabel($status)
{
    $labels = [
        "pending" => "Pending",
        "accepted" => "Accepted",
        "rejected" => "Rejected",
        "in_progress" => "In Progress",
        "completed" => "Completed",
        "cancelled" => "Cancelled"
    ];

    return $labels[$status] ?? ucfirst(str_replace("_", " ", $status));
}

function bookingStatusClass($status)
{
    $allowed = [
        "pending",
        "accepted",
        "rejected",
        "in_progress",
        "completed",
        "cancelled"
    ];

    return in_array($status, $allowed, true) ? $status : "pending";
}

function bookingFormatDate($date)
{
    if (empty($date) || $date === "0000-00-00") {
        return "Not specified";
    }

    $timestamp = strtotime($date);

    return $timestamp ? date("d M Y", $timestamp) : "Not specified";
}

function bookingFormatTime($time)
{
    if (empty($time) || $time === "00:00:00") {
        return "Not specified";
    }

    $timestamp = strtotime($time);

    return $timestamp ? date("g:i A", $timestamp) : "Not specified";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Bookings | HandyLink</title>

    <link rel="stylesheet" href="../assets/css/clients/client.css">
    <link rel="stylesheet" href="../assets/css/clients/bookings.css">

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">

              <script src="../assets/js/client_darkmode.js" defer></script>

</head>
<body>

<header class="client-header">
    <a href="dashboard.php" class="brand">
        <img src="../assets/images/handylink-logo.png" alt="HandyLink Logo">
        <span>HandyLink</span>
    </a>

    <button class="mobile-menu-button"
            id="mobileMenuButton"
            type="button"
            aria-label="Toggle navigation"
            aria-expanded="false">
        <i class="fa-solid fa-bars"></i>
    </button>

    <nav class="main-navigation" id="mainNavigation">
        <a class="nav-link" href="dashboard.php">
            <i class="fa-solid fa-house"></i>
            <span>Dashboard</span>
        </a>

        <a class="nav-link" href="find_services.php">
            <i class="fa-solid fa-magnifying-glass"></i>
            <span>Find Services</span>
        </a>

        <a class="nav-link" href="my_request.php">
            <i class="fa-regular fa-clipboard"></i>
            <span>My Requests</span>
        </a>

        <a class="nav-link active" href="bookings.php">
            <i class="fa-regular fa-calendar-check"></i>
            <span>My Bookings</span>
        </a>

        <a class="nav-link" href="messages.php">
            <i class="fa-regular fa-comments"></i>
            <span>Messages</span>
        </a>
           <a class="nav-link" href="reviews.php">
            <i class="fa-solid fa-star"></i>
            <span>Reviews</span>
        </a>
    </nav>

    <div class="header-actions">
        <a href="messages.php"
           class="notification-button"
           aria-label="Notifications and unread messages"
           title="Notifications">
            <i class="fa-regular fa-bell bell-icon" aria-hidden="true"></i>

            <?php if ($unreadMessages > 0): ?>
                <span class="notification-dot" aria-label="Unread messages"></span>
            <?php endif; ?>
        </a>

        <div class="profile-menu-wrapper">
            <button type="button"
                    class="profile-button"
                    id="profileButton"
                    aria-expanded="false">
                <span class="profile-avatar profile-initial">
                    <?php echo htmlspecialchars($profileInitial); ?>
                </span>

                <span class="profile-name">
                    <?php echo htmlspecialchars($firstName); ?>
                </span>

                <i class="fa-solid fa-chevron-down chevron-icon"></i>
            </button>

            <div class="profile-dropdown" id="profileDropdown">
                <div class="dropdown-user">
                    <strong><?php echo htmlspecialchars($fullName); ?></strong>
                    <span>Client account</span>
                </div>

                <a href="settings.php">
                    <i class="fa-solid fa-gear"></i>
                    Profile &amp; Settings
                </a>

                <a href="../logout.php" class="dropdown-logout">
                    <i class="fa-solid fa-right-from-bracket"></i>
                    Log Out
                </a>
            </div>
        </div>
    </div>
</header>

<main class="bookings-container">

    <section class="bookings-heading">
        <div>
            <span class="eyebrow">YOUR ACTIVITY</span>
            <h1>My Bookings</h1>
            <p>Track your service bookings and see their latest status.</p>
        </div>

        <div class="bookings-heading-icon">
            <i class="fa-regular fa-calendar-check"></i>
        </div>
    </section>

    <section class="booking-stats-grid">
        <article class="booking-stat-card">
            <div class="booking-stat-icon green-icon">
                <i class="fa-solid fa-calendar-days"></i>
            </div>
            <div>
                <p>Total Bookings</p>
                <h2><?php echo $stats["total"]; ?></h2>
                <span>All your bookings</span>
            </div>
        </article>

        <article class="booking-stat-card">
            <div class="booking-stat-icon amber-icon">
                <i class="fa-regular fa-clock"></i>
            </div>
            <div>
                <p>Pending</p>
                <h2><?php echo $stats["pending"]; ?></h2>
                <span>Awaiting a response</span>
            </div>
        </article>

        <article class="booking-stat-card">
            <div class="booking-stat-icon blue-icon">
                <i class="fa-solid fa-check"></i>
            </div>
            <div>
                <p>Accepted</p>
                <h2><?php echo $stats["accepted"]; ?></h2>
                <span>Confirmed by provider</span>
            </div>
        </article>

        <article class="booking-stat-card">
            <div class="booking-stat-icon purple-icon">
                <i class="fa-solid fa-hammer"></i>
            </div>
            <div>
                <p>In Progress</p>
                <h2><?php echo $stats["in_progress"]; ?></h2>
                <span>Services underway</span>
            </div>
        </article>
    </section>

    <section class="bookings-panel">

        <div class="bookings-panel-heading">
            <div>
                <h2>Your Service Bookings</h2>
                <p>Review your booking details and current status.</p>
            </div>

            <a href="find_services.php" class="booking-browse-link">
                <i class="fa-solid fa-plus"></i>
                Find Services
            </a>
        </div>

        <form method="GET" action="bookings.php" class="bookings-filter-form">
            <div class="booking-search-field">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input
                    type="search"
                    name="search"
                    placeholder="Search service, provider or location..."
                    value="<?php echo htmlspecialchars($search); ?>"
                >
            </div>

            <div class="booking-status-field">
                <i class="fa-solid fa-filter"></i>
                <select name="status" aria-label="Filter by booking status">
                    <option value="">All statuses</option>
                    <option value="pending" <?php echo $statusFilter === "pending" ? "selected" : ""; ?>>Pending</option>
                    <option value="accepted" <?php echo $statusFilter === "accepted" ? "selected" : ""; ?>>Accepted</option>
                    <option value="in_progress" <?php echo $statusFilter === "in_progress" ? "selected" : ""; ?>>In Progress</option>
                    <option value="completed" <?php echo $statusFilter === "completed" ? "selected" : ""; ?>>Completed</option>
                    <option value="rejected" <?php echo $statusFilter === "rejected" ? "selected" : ""; ?>>Rejected</option>
                    <option value="cancelled" <?php echo $statusFilter === "cancelled" ? "selected" : ""; ?>>Cancelled</option>
                </select>
            </div>

            <button type="submit" class="booking-filter-button">
                <i class="fa-solid fa-magnifying-glass"></i>
                Search
            </button>

            <?php if ($search !== "" || $statusFilter !== ""): ?>
                <a href="bookings.php" class="booking-clear-filter">Clear</a>
            <?php endif; ?>
        </form>

        <?php if ($errorMessage !== ""): ?>

            <div class="booking-error">
                <i class="fa-solid fa-circle-exclamation"></i>
                <span><?php echo htmlspecialchars($errorMessage); ?></span>
            </div>

        <?php elseif (empty($bookings)): ?>

            <div class="booking-empty-state">
                <div class="booking-empty-icon">
                    <i class="fa-regular fa-calendar-xmark"></i>
                </div>

                <?php if ($search !== "" || $statusFilter !== ""): ?>
                    <h3>No matching bookings found</h3>
                    <p>Try a different search term or status filter.</p>
                    <a href="bookings.php" class="booking-empty-button">
                        Clear Filters
                    </a>
                <?php else: ?>
                    <h3>You don't have any bookings yet</h3>
                    <p>
                        When you book a service, its details and progress will
                        appear here.
                    </p>
                    <a href="find_services.php" class="booking-empty-button">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        Find a Service
                    </a>
                <?php endif; ?>
            </div>

        <?php else: ?>

            <div class="booking-results-count">
                Showing <strong><?php echo count($bookings); ?></strong>
                booking<?php echo count($bookings) === 1 ? "" : "s"; ?>
            </div>

            <div class="booking-list">
                <?php foreach ($bookings as $booking): ?>
                    <?php
                    $bookingStatus = $booking["booking_status"] ?? "pending";
                    $serviceTitle = $booking["service_title"] ?: "Service Booking";
                    $providerName = $booking["provider_name"] ?: "Provider not assigned";
                    $categoryName = $booking["category_name"] ?: "General Service";
                    $serviceLocation = $booking["service_location"] ?: "Location not specified";
                    ?>

                    <article class="booking-card">

                        <div class="booking-card-top">
                            <div class="booking-service-icon">
                                <i class="fa-solid fa-screwdriver-wrench"></i>
                            </div>

                            <div class="booking-service-main">
                                <span class="booking-category">
                                    <?php echo htmlspecialchars($categoryName); ?>
                                </span>

                                <h3>
                                    <?php echo htmlspecialchars($serviceTitle); ?>
                                </h3>

                                <p class="booking-provider">
                                    <i class="fa-regular fa-user"></i>
                                    <?php echo htmlspecialchars($providerName); ?>
                                </p>
                            </div>

                            <span class="booking-status-pill status-<?php echo htmlspecialchars(bookingStatusClass($bookingStatus)); ?>">
                                <?php echo htmlspecialchars(bookingStatusLabel($bookingStatus)); ?>
                            </span>
                        </div>

                        <?php if (!empty($booking["service_description"])): ?>
                            <p class="booking-description">
                                <?php
                                $description = trim($booking["service_description"]);
                                $description = strlen($description) > 180
                                    ? substr($description, 0, 177) . "..."
                                    : $description;

                                echo htmlspecialchars($description);
                                ?>
                            </p>
                        <?php endif; ?>

                        <div class="booking-details-grid">
                            <div class="booking-detail">
                                <i class="fa-regular fa-calendar"></i>
                                <div>
                                    <span>Booking Date</span>
                                    <strong>
                                        <?php echo htmlspecialchars(bookingFormatDate($booking["booking_date"] ?? "")); ?>
                                    </strong>
                                </div>
                            </div>

                            <div class="booking-detail">
                                <i class="fa-regular fa-clock"></i>
                                <div>
                                    <span>Booking Time</span>
                                    <strong>
                                        <?php echo htmlspecialchars(bookingFormatTime($booking["booking_time"] ?? "")); ?>
                                    </strong>
                                </div>
                            </div>

                            <div class="booking-detail">
                                <i class="fa-solid fa-location-dot"></i>
                                <div>
                                    <span>Service Location</span>
                                    <strong>
                                        <?php echo htmlspecialchars($serviceLocation); ?>
                                    </strong>
                                </div>
                            </div>

                            <div class="booking-detail">
                                <i class="fa-solid fa-hashtag"></i>
                                <div>
                                    <span>Booking Reference</span>
                                    <strong>
                                        HL-<?php echo (int) $booking["booking_id"]; ?>
                                    </strong>
                                </div>
                            </div>
                        </div>

                        <?php if (!empty($booking["notes"])): ?>
                            <div class="booking-notes">
                                <i class="fa-regular fa-note-sticky"></i>
                                <div>
                                    <strong>Booking Notes</strong>
                                    <p><?php echo nl2br(htmlspecialchars($booking["notes"])); ?></p>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="booking-card-footer">
                            <span class="booking-created">
                                <i class="fa-regular fa-calendar-plus"></i>
                                Added <?php echo htmlspecialchars(bookingFormatDate($booking["booking_created_at"] ?? "")); ?>
                            </span>

                            <a href="messages.php" class="booking-message-link">
                                <i class="fa-regular fa-comments"></i>
                                Messages
                            </a>
                        </div>

                    </article>
                <?php endforeach; ?>
            </div>

        <?php endif; ?>

    </section>

    <section class="booking-help-panel">
        <div class="booking-help-icon">
            <i class="fa-solid fa-circle-info"></i>
        </div>

        <div>
            <h2>Need help with a booking?</h2>
            <p>
                Check your booking status or use Messages to communicate with
                your service provider.
            </p>
        </div>

        <a href="messages.php" class="booking-help-link">
            Open Messages
            <i class="fa-solid fa-arrow-right"></i>
        </a>
    </section>

</main>

<footer class="client-footer">
    <p>&copy; <?php echo date("Y"); ?> HandyLink Solutions. All rights reserved.</p>
    <a href="settings.php">Account Settings</a>
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
        if (!profileDropdown.contains(event.target) &&
            !profileButton.contains(event.target)) {
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
            icon.className = isOpen
                ? "fa-solid fa-xmark"
                : "fa-solid fa-bars";
        }
    });
}
</script>

</body>
</html>
