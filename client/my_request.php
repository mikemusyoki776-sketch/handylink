<?php
session_start();

if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'client'
) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';

$clientId = (int) $_SESSION['user_id'];
$clientName = $_SESSION['full_name'] ?? 'Client';
$firstName = explode(' ', trim($clientName))[0];
$profileImage = $_SESSION['profile_image'] ?? '';

function h($value)
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function requestStatusLabel($status)
{
    $labels = [
        'pending' => 'Pending',
        'accepted' => 'Accepted',
        'rejected' => 'Rejected',
        'in_progress' => 'In Progress',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled'
    ];

    return $labels[$status] ?? ucfirst(str_replace('_', ' ', (string) $status));
}

function requestStatusClass($status)
{
    $allowed = [
        'pending',
        'accepted',
        'rejected',
        'in_progress',
        'completed',
        'cancelled'
    ];

    return in_array($status, $allowed, true) ? $status : 'pending';
}

$allowedStatuses = [
    'pending',
    'accepted',
    'rejected',
    'in_progress',
    'completed',
    'cancelled'
];

$search = trim($_GET['search'] ?? '');
$filter = trim($_GET['status'] ?? '');

if (!in_array($filter, $allowedStatuses, true)) {
    $filter = '';
}

$requests = [];
$counts = [
    'all' => 0,
    'pending' => 0,
    'accepted' => 0,
    'completed' => 0
];

$unreadMessages = 0;
$error = '';

try {
    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new RuntimeException('Database connection is unavailable.');
    }

    // Count all requests belonging to the logged-in client.
    $countStmt = $conn->prepare(
        "SELECT status, COUNT(*) AS total
         FROM service_requests
         WHERE client_id = ?
         GROUP BY status"
    );

    if (!$countStmt) {
        throw new RuntimeException('Could not prepare request counts.');
    }

    $countStmt->bind_param('i', $clientId);
    $countStmt->execute();
    $countResult = $countStmt->get_result();

    while ($row = $countResult->fetch_assoc()) {
        $status = $row['status'];
        $total = (int) $row['total'];

        $counts['all'] += $total;

        if (isset($counts[$status])) {
            $counts[$status] = $total;
        }
    }

    $countStmt->close();

    // Count unread messages for the notification bell.
    $messageStmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM messages
         WHERE receiver_id = ? AND is_read = 0"
    );

    if ($messageStmt) {
        $messageStmt->bind_param('i', $clientId);
        $messageStmt->execute();
        $messageResult = $messageStmt->get_result();
        $unreadMessages = (int) ($messageResult->fetch_assoc()['total'] ?? 0);
        $messageStmt->close();
    }

    // Load the client's requests and their service categories.
    $sql = "
        SELECT
            sr.id,
            sr.category_id,
            sr.title,
            sr.description,
            sr.location,
            sr.preferred_date,
            sr.preferred_time,
            sr.status,
            sr.created_at,
            COALESCE(sc.name, 'General Service') AS category_name
        FROM service_requests sr
         LEFT JOIN categories sc
        ON sc.id = sr.category_id
        WHERE sr.client_id = ?
    ";

    $types = 'i';
    $params = [$clientId];

    if ($filter !== '') {
        $sql .= " AND sr.status = ?";
        $types .= 's';
        $params[] = $filter;
    }

    if ($search !== '') {
        $sql .= " AND (
            sr.title LIKE ?
            OR sr.description LIKE ?
            OR sr.location LIKE ?
            OR sc.name LIKE ?
        )";

        $term = '%' . $search . '%';
        $types .= 'ssss';
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
    }

    $sql .= " ORDER BY sr.created_at DESC";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException('Could not prepare the requests query.');
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $requests[] = $row;
    }

    $stmt->close();

} catch (Throwable $exception) {
    error_log('HandyLink My Requests error: ' . $exception->getMessage());
    $error = 'Unable to load your requests. Please try again later.';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Requests | HandyLink</title>

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">

    <!-- Shared styling used by the Find Services page -->
    <link rel="stylesheet" href="../assets/css/clients/client.css">

    <!-- Styling specific to My Requests -->
    <link rel="stylesheet" href="../assets/css/clients/my_request.css">
</head>
    <script src="../assets/js/client_darkmode.js"></script>
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

        <a class="nav-link active" href="my_request.php">
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
           aria-label="Unread messages"
           title="Messages">

            <i class="fa-regular fa-bell bell-icon" aria-hidden="true"></i>

            <?php if ($unreadMessages > 0): ?>
                <span class="notification-dot"
                      aria-label="Unread messages"></span>
            <?php endif; ?>
        </a>

        <div class="profile-menu-wrapper">

            <button type="button"
                    class="profile-button"
                    id="profileButton"
                    aria-expanded="false">

                <span class="profile-avatar profile-initial">
                    <?php if ($profileImage !== ''): ?>
                        <img src="../<?= h(ltrim($profileImage, '/')) ?>"
                             alt="Profile picture">
                    <?php else: ?>
                        <?= h(strtoupper(substr($firstName, 0, 1))) ?>
                    <?php endif; ?>
                </span>

                <span class="profile-name"><?= h($firstName) ?></span>

                <i class="fa-solid fa-chevron-down chevron-icon"></i>
            </button>

            <div class="profile-dropdown" id="profileDropdown">

                <div class="dropdown-user">
                    <strong><?= h($clientName) ?></strong>
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

<main class="my-requests-container">

    <section class="my-requests-heading">
        <div>
            <span class="eyebrow">YOUR ACTIVITY</span>
            <h1>My Requests</h1>
            <p>Manage your service requests and track their progress.</p>
        </div>

        <a href="find_services.php" class="primary-button">
            <i class="fa-solid fa-plus"></i>
            Find a Service
        </a>
    </section>

    <?php if ($error !== ''): ?>

        <div class="requests-error">
            <i class="fa-solid fa-circle-exclamation"></i>
            <?= h($error) ?>
        </div>

    <?php else: ?>

        <section class="request-summary-grid">

            <article class="request-summary-card">
                <div class="request-summary-icon">
                    <i class="fa-solid fa-clipboard-list"></i>
                </div>
                <div>
                    <span>Total Requests</span>
                    <strong><?= $counts['all'] ?></strong>
                </div>
            </article>

            <article class="request-summary-card">
                <div class="request-summary-icon pending-icon">
                    <i class="fa-regular fa-clock"></i>
                </div>
                <div>
                    <span>Pending</span>
                    <strong><?= $counts['pending'] ?></strong>
                </div>
            </article>

            <article class="request-summary-card">
                <div class="request-summary-icon accepted-icon">
                    <i class="fa-solid fa-check"></i>
                </div>
                <div>
                    <span>Accepted</span>
                    <strong><?= $counts['accepted'] ?></strong>
                </div>
            </article>

            <article class="request-summary-card">
                <div class="request-summary-icon completed-icon">
                    <i class="fa-solid fa-flag-checkered"></i>
                </div>
                <div>
                    <span>Completed</span>
                    <strong><?= $counts['completed'] ?></strong>
                </div>
            </article>

        </section>

        <section class="requests-panel">

            <div class="requests-panel-heading">
                <div>
                    <h2>Service Request History</h2>
                    <p>All the service requests you have submitted.</p>
                </div>

                <span class="request-total-label">
                    <?= count($requests) ?>
                    <?= count($requests) === 1 ? 'request' : 'requests' ?>
                </span>
            </div>

            <div class="request-tools">

                <form method="GET"
                      action="my_request.php"
                      class="request-search-form">

                    <i class="fa-solid fa-magnifying-glass"></i>

                    <input type="search"
                           name="search"
                           value="<?= h($search) ?>"
                           placeholder="Search title, location or category..."
                           aria-label="Search requests">

                    <?php if ($filter !== ''): ?>
                        <input type="hidden"
                               name="status"
                               value="<?= h($filter) ?>">
                    <?php endif; ?>

                    <button type="submit">Search</button>
                </form>

                <form method="GET"
                      action="my_request.php"
                      class="request-filter-form">

                    <?php if ($search !== ''): ?>
                        <input type="hidden"
                               name="search"
                               value="<?= h($search) ?>">
                    <?php endif; ?>

                    <i class="fa-solid fa-filter"></i>

                    <select name="status"
                            aria-label="Filter requests by status"
                            onchange="this.form.submit()">

                        <option value="">All Statuses</option>

                        <?php foreach ($allowedStatuses as $status): ?>
                            <option value="<?= h($status) ?>"
                                <?= $filter === $status ? 'selected' : '' ?>>
                                <?= h(requestStatusLabel($status)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>

            </div>

            <?php if (empty($requests)): ?>

                <div class="requests-empty-state">
                    <div class="requests-empty-icon">
                        <i class="fa-solid fa-clipboard-list"></i>
                    </div>

                    <h3>
                        <?= ($search !== '' || $filter !== '')
                            ? 'No Matching Requests'
                            : 'No Requests Yet' ?>
                    </h3>

                    <p>
                        <?= ($search !== '' || $filter !== '')
                            ? 'Try another search term or choose a different status.'
                            : 'Your submitted service requests will appear here.' ?>
                    </p>

                    <?php if ($search !== '' || $filter !== ''): ?>
                        <a href="my_request.php" class="primary-button">
                            <i class="fa-solid fa-rotate-left"></i>
                            View All Requests
                        </a>
                    <?php else: ?>
                        <a href="find_services.php" class="primary-button">
                            <i class="fa-solid fa-magnifying-glass"></i>
                            Find a Service
                        </a>
                    <?php endif; ?>
                </div>

            <?php else: ?>

                <div class="request-list">

                    <?php foreach ($requests as $request): ?>
                        <?php
                        $status = $request['status'];
                        $statusClass = requestStatusClass($status);
                        $createdDate = !empty($request['created_at'])
                            ? strtotime($request['created_at'])
                            : false;
                        $preferredDate = !empty($request['preferred_date'])
                            ? strtotime($request['preferred_date'])
                            : false;
                        $preferredTime = !empty($request['preferred_time'])
                            ? strtotime($request['preferred_time'])
                            : false;
                        ?>

                        <article class="request-item">

                            <div class="request-item-icon">
                                <i class="fa-solid fa-screwdriver-wrench"></i>
                            </div>

                            <div class="request-item-content">

                                <div class="request-title-row">
                                    <span class="request-category">
                                        <?= h($request['category_name']) ?>
                                    </span>

                                    <span class="request-number">
                                        Request #<?= (int) $request['id'] ?>
                                    </span>
                                </div>

                                <h3><?= h($request['title']) ?></h3>

                                <p class="request-description">
                                    <?= h($request['description'] ?: 'No description provided.') ?>
                                </p>

                                <div class="request-meta">

                                    <span>
                                        <i class="fa-solid fa-location-dot"></i>
                                        <?= h($request['location'] ?: 'Location not specified') ?>
                                    </span>

                                    <?php if ($preferredDate): ?>
                                        <span>
                                            <i class="fa-regular fa-calendar"></i>
                                            Preferred:
                                            <?= h(date('d M Y', $preferredDate)) ?>
                                        </span>
                                    <?php endif; ?>

                                    <?php if ($preferredTime): ?>
                                        <span>
                                            <i class="fa-regular fa-clock"></i>
                                            <?= h(date('g:i A', $preferredTime)) ?>
                                        </span>
                                    <?php endif; ?>

                                    <?php if ($createdDate): ?>
                                        <span>
                                            <i class="fa-regular fa-calendar-plus"></i>
                                            Submitted:
                                            <?= h(date('d M Y', $createdDate)) ?>
                                        </span>
                                    <?php endif; ?>

                                </div>
                            </div>

                            <div class="request-item-status">
                                <span class="status-badge status-<?= h($statusClass) ?>">
                                    <span class="status-dot"></span>
                                    <?= h(requestStatusLabel($status)) ?>
                                </span>
                            </div>

                        </article>
                    <?php endforeach; ?>

                </div>

                <div class="requests-results-footer">
                    Showing <?= count($requests) ?>
                    <?= count($requests) === 1 ? 'request' : 'requests' ?>
                </div>

            <?php endif; ?>

        </section>

    <?php endif; ?>

</main>

<footer class="client-footer">
    <p>&copy; <?= date('Y') ?> HandyLink Solutions. All rights reserved.</p>
    <a href="settings.php">Account Settings</a>
</footer>

<script>
const profileButton = document.getElementById('profileButton');
const profileDropdown = document.getElementById('profileDropdown');
const mobileMenuButton = document.getElementById('mobileMenuButton');
const mainNavigation = document.getElementById('mainNavigation');

profileButton.addEventListener('click', function (event) {
    event.stopPropagation();

    const isOpen = profileDropdown.classList.toggle('show');
    profileButton.setAttribute('aria-expanded', String(isOpen));
});

document.addEventListener('click', function (event) {
    if (!event.target.closest('.profile-menu-wrapper')) {
        profileDropdown.classList.remove('show');
        profileButton.setAttribute('aria-expanded', 'false');
    }
});

mobileMenuButton.addEventListener('click', function () {
    const isOpen = mainNavigation.classList.toggle('show');
    mobileMenuButton.setAttribute('aria-expanded', String(isOpen));
});
</script>

</body>
</html>
