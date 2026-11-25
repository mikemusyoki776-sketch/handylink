
<?php
session_start();

if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "client"
) {
    header("Location: ../login.php");
    exit();
}

require_once __DIR__ . "/../config/database.php";

$userId = (int) $_SESSION["user_id"];
$fullName = $_SESSION["full_name"] ?? "Client";
$firstName = explode(" ", trim($fullName))[0];

$unreadMessages = 0;
$totalRequests = 0;
$activeBookings = 0;
$completedBookings = 0;
$recentRequests = [];
$profileImage = "";

if (isset($conn) && $conn instanceof mysqli) {
    // Unread messages
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM messages
         WHERE receiver_id = ? AND is_read = 0"
    );

    if ($stmt) {
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $unreadMessages = (int) ($stmt->get_result()->fetch_assoc()["total"] ?? 0);
        $stmt->close();
    }

    // Total requests
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM service_requests
         WHERE client_id = ?"
    );

    if ($stmt) {
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $totalRequests = (int) ($stmt->get_result()->fetch_assoc()["total"] ?? 0);
        $stmt->close();
    }

    // Active bookings
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM bookings
         WHERE client_id = ?
         AND status IN ('pending', 'confirmed', 'accepted', 'in_progress')"
    );

    if ($stmt) {
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $activeBookings = (int) ($stmt->get_result()->fetch_assoc()["total"] ?? 0);
        $stmt->close();
    }

    // Completed bookings
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM bookings
         WHERE client_id = ? AND status = 'completed'"
    );

    if ($stmt) {
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $completedBookings = (int) ($stmt->get_result()->fetch_assoc()["total"] ?? 0);
        $stmt->close();
    }

    // Recent requests
    $stmt = $conn->prepare(
        "SELECT id, title, location, status, created_at
         FROM service_requests
         WHERE client_id = ?
         ORDER BY created_at DESC
         LIMIT 5"
    );

    if ($stmt) {
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $recentRequests[] = $row;
        }

        $stmt->close();
    }

    // Profile image
    $stmt = $conn->prepare(
        "SELECT profile_image FROM users WHERE id = ? LIMIT 1"
    );

    if ($stmt) {
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $profileImage = $stmt->get_result()->fetch_assoc()["profile_image"] ?? "";
        $stmt->close();
    }
}

$profileImagePath = "";

if (!empty($profileImage)) {
    $profileImage = str_replace("\\", "/", $profileImage);

    if (
        strpos($profileImage, "https://") === 0 ||
        strpos($profileImage, "http://") === 0
    ) {
        $profileImagePath = $profileImage;
    } else {
        $profileImage = ltrim($profileImage, "/");

        if (strpos($profileImage, "assets/") === 0 ||
            strpos($profileImage, "uploads/") === 0) {
            $profileImagePath = "../" . $profileImage;
        } else {
            $profileImagePath = "../uploads/profiles/" . basename($profileImage);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Client Dashboard | HandyLink</title>

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">

    <link rel="stylesheet" href="../assets/css/clients/client.css">
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
        <a class="nav-link active" href="dashboard.php">
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

        <a class="nav-link" href="bookings.php">
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
           title="<?php echo $unreadMessages > 0
               ? $unreadMessages . ' unread message(s)'
               : 'Notifications'; ?>">

            <i class="fa-regular fa-bell bell-icon" aria-hidden="true"></i>

            <?php if ($unreadMessages > 0): ?>
                <span class="notification-dot" aria-label="Unread messages"></span>
            <?php endif; ?>
        </a>

        <?php include __DIR__ . '/ai_chat_widget.php'; ?>

        <div class="profile-menu-wrapper">

            <button type="button" class="profile-button" id="profileButton"
                    aria-expanded="false">
                <?php if (!empty($profileImagePath)): ?>
                    <img class="profile-avatar"
                         src="<?php echo htmlspecialchars($profileImagePath); ?>"
                         alt="Profile photo">
                <?php else: ?>
                    <span class="profile-avatar profile-initial">
                        <?php echo htmlspecialchars(strtoupper(substr($firstName, 0, 1))); ?>
                    </span>
                <?php endif; ?>

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

                <a href="logout.php" class="dropdown-logout">
                    <i class="fa-solid fa-right-from-bracket"></i>
                    Log Out
                </a>

            </div>
        </div>
    </div>
</header>

<main class="dashboard-container">


    <section class="stats-grid">

        <article class="stat-card">
            <div class="stat-icon green-icon">
                <i class="fa-regular fa-clipboard"></i>
            </div>

            <div>
                <p>Total Requests</p>
                <h2><?php echo $totalRequests; ?></h2>
                <span>Requests you've submitted</span>
            </div>
        </article>

        <article class="stat-card">
            <div class="stat-icon amber-icon">
                <i class="fa-regular fa-calendar-check"></i>
            </div>

            <div>
                <p>Active Bookings</p>
                <h2><?php echo $activeBookings; ?></h2>
                <span>Bookings in progress</span>
            </div>
        </article>

        <article class="stat-card">
            <div class="stat-icon blue-icon">
                <i class="fa-solid fa-check-double"></i>
            </div>

            <div>
                <p>Completed Jobs</p>
                <h2><?php echo $completedBookings; ?></h2>
                <span>Services completed</span>
            </div>
        </article>

    </section>

    <section class="dashboard-content-grid">

        <div class="content-panel recent-panel">

            <div class="panel-heading">
                <div>
                    <h2>Recent Requests</h2>
                    <p>Keep track of your latest service requests.</p>
                </div>

                <a href="requests.php" class="text-link">View all</a>
            </div>

            <?php if (!empty($recentRequests)): ?>

                <div class="request-list">

                    <?php foreach ($recentRequests as $request): ?>
                        <?php
                        $status = strtolower($request["status"] ?? "pending");
                        $statusClass = preg_replace('/[^a-z0-9_-]/', '', $status);
                        ?>

                        <article class="request-row">

                            <div class="request-symbol">
                                <i class="fa-regular fa-file-lines"></i>
                            </div>

                            <div class="request-info">
                                <h3>
                                    <?php echo htmlspecialchars($request["title"]); ?>
                                </h3>

                                <p>
                                    <i class="fa-solid fa-location-dot"></i>
                                    <?php echo htmlspecialchars(
                                        $request["location"] ?? "Location not specified"
                                    ); ?>

                                    <span class="request-separator">·</span>

                                    <?php
                                    echo !empty($request["created_at"])
                                        ? htmlspecialchars(date(
                                            "d M Y",
                                            strtotime($request["created_at"])
                                        ))
                                        : "Date unavailable";
                                    ?>
                                </p>
                            </div>

                            <span class="status-pill status-<?php
                                echo htmlspecialchars($statusClass);
                            ?>">
                                <?php
                                echo htmlspecialchars(
                                    ucwords(str_replace("_", " ", $status))
                                );
                                ?>
                            </span>

                        </article>

                    <?php endforeach; ?>

                </div>

            <?php else: ?>

                <div class="empty-state">
                    <div class="empty-icon">
                        <i class="fa-regular fa-folder-open"></i>
                    </div>

                    <h3>No requests yet</h3>

                    <p>
                        Your service requests will appear here after you submit one.
                    </p>

                    <a href="find_services.php" class="text-link">
                        Explore services
                    </a>
                </div>

            <?php endif; ?>

        </div>

        <aside class="content-panel quick-actions-panel">

            <div class="panel-heading">
                <div>
                    <h2>Quick Actions</h2>
                    <p>What would you like to do?</p>
                </div>
            </div>

            <a href="find_services.php" class="quick-action">
                <span class="action-icon green-icon">
                    <i class="fa-solid fa-magnifying-glass"></i>
                </span>

                <span>
                    <strong>Find a Provider</strong>
                    <small>Explore available professionals</small>
                </span>

                <i class="fa-solid fa-chevron-right action-arrow"></i>
            </a>

            <a href="requests.php" class="quick-action">
                <span class="action-icon amber-icon">
                    <i class="fa-regular fa-clipboard"></i>
                </span>

                <span>
                    <strong>My Requests</strong>
                    <small>Check your service requests</small>
                </span>

                <i class="fa-solid fa-chevron-right action-arrow"></i>
            </a>

            <a href="bookings.php" class="quick-action">
                <span class="action-icon blue-icon">
                    <i class="fa-regular fa-calendar-check"></i>
                </span>

                <span>
                    <strong>My Bookings</strong>
                    <small>View upcoming and completed jobs</small>
                </span>

                <i class="fa-solid fa-chevron-right action-arrow"></i>
            </a>

            <a href="messages.php" class="quick-action">
                <span class="action-icon purple-icon">
                    <i class="fa-regular fa-comments"></i>
                </span>

                <span>
                    <strong>Messages</strong>
                    <small>
                        <?php if ($unreadMessages > 0): ?>
                            You have <?php echo $unreadMessages; ?> unread message(s)
                        <?php else: ?>
                            Communicate with service providers
                        <?php endif; ?>
                    </small>
                </span>

                <i class="fa-solid fa-chevron-right action-arrow"></i>
            </a>

        </aside>

    </section>

    <section class="recommendation-panel">

        <div class="recommendation-copy">
            <span class="eyebrow">MADE FOR YOUR NEEDS</span>

            <h2>Find the right professional for your next job.</h2>

            <p>
                Explore service providers, compare ratings and choose a professional
                who fits your needs.
            </p>

            <a href="find_services.php" class="primary-button">
                Explore Services
                <i class="fa-solid fa-arrow-right"></i>
            </a>
        </div>

        <div class="recommendation-check">
            <i class="fa-solid fa-shield-halved"></i>
        </div>

    </section>

</main>

<footer class="client-footer">
    <p>
        &copy; <?php echo date("Y"); ?> HandyLink Solutions. All rights reserved.
    </p>

    <a href="settings.php">Account Settings</a>
</footer>

<script>
const profileButton = document.getElementById("profileButton");
const profileDropdown = document.getElementById("profileDropdown");
const mobileMenuButton = document.getElementById("mobileMenuButton");
const mainNavigation = document.getElementById("mainNavigation");

profileButton.addEventListener("click", function (event) {
    event.stopPropagation();

    const isOpen = profileDropdown.classList.toggle("show");
    profileButton.setAttribute("aria-expanded", String(isOpen));
});

document.addEventListener("click", function (event) {
    if (!event.target.closest(".profile-menu-wrapper")) {
        profileDropdown.classList.remove("show");
        profileButton.setAttribute("aria-expanded", "false");
    }
});

mobileMenuButton.addEventListener("click", function () {
    const isOpen = mainNavigation.classList.toggle("show");
    mobileMenuButton.setAttribute("aria-expanded", String(isOpen));
});
</script>
<script src="../assets/js/client_darkmode.js"></script>
</body>
</html>
