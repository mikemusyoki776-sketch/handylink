
<?php
session_start();

if (
    !isset($_SESSION["user_id"]) ||
    ($_SESSION["role"] ?? "") !== "admin"
) {
    header("Location: ../login.php");
    exit();
}

require_once "../config/database.php";

$conn->set_charset("utf8mb4");

$adminName = $_SESSION["full_name"] ?? "Admin";

$search = trim($_GET["search"] ?? "");
$statusFilter = trim($_GET["status"] ?? "");

$allowedStatuses = [
    "pending",
    "accepted",
    "confirmed",
    "in_progress",
    "completed",
    "cancelled",
    "rejected"
];

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = "";
}

function bookingStatusClass($status)
{
    $status = strtolower(trim($status));

    $classes = [
        "pending" => "pending",
        "accepted" => "accepted",
        "confirmed" => "accepted",
        "in_progress" => "progress",
        "completed" => "completed",
        "cancelled" => "cancelled",
        "rejected" => "cancelled"
    ];

    return $classes[$status] ?? "default";
}

function bookingStatusLabel($status)
{
    return ucwords(str_replace("_", " ", $status));
}

/* Booking statistics */
$stats = [
    "total" => 0,
    "pending" => 0,
    "active" => 0,
    "completed" => 0,
    "cancelled" => 0
];

$statsSql = "
    SELECT
        COUNT(*) AS total,
        COALESCE(SUM(status = 'pending'), 0) AS pending,
        COALESCE(SUM(status IN ('accepted', 'confirmed', 'in_progress')), 0) AS active,
        COALESCE(SUM(status = 'completed'), 0) AS completed,
        COALESCE(SUM(status IN ('cancelled', 'rejected')), 0) AS cancelled
    FROM bookings
";

$statsResult = $conn->query($statsSql);

if ($statsResult) {
    $stats = $statsResult->fetch_assoc();
}

/* Retrieve bookings */
$sql = "
    SELECT
        b.id,
        b.request_id,
        b.client_id,
        b.provider_id,
        b.booking_date,
        b.booking_time,
        b.status,
        b.notes,
        b.created_at,
        client.full_name AS client_name,
        provider.full_name AS provider_name,
        sr.title AS request_title,
        c.name AS category_name
    FROM bookings b
    LEFT JOIN users client ON b.client_id = client.id
    LEFT JOIN users provider ON b.provider_id = provider.id
    LEFT JOIN service_requests sr ON b.request_id = sr.id
    LEFT JOIN categories c ON sr.category_id = c.id
    WHERE 1 = 1
";

$params = [];
$types = "";

if ($search !== "") {
    $sql .= "
        AND (
            CAST(b.id AS CHAR) LIKE ?
            OR CAST(b.request_id AS CHAR) LIKE ?
            OR client.full_name LIKE ?
            OR provider.full_name LIKE ?
            OR sr.title LIKE ?
            OR c.name LIKE ?
            OR b.notes LIKE ?
        )
    ";

    $term = "%" . $search . "%";

    $params = array_fill(0, 7, $term);
    $types = "sssssss";
}

if ($statusFilter !== "") {
    $sql .= " AND b.status = ?";
    $params[] = $statusFilter;
    $types .= "s";
}

$sql .= " ORDER BY b.created_at DESC, b.id DESC";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Unable to prepare booking query. Please check your database table structure.");
}

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

$bookings = [];

while ($row = $result->fetch_assoc()) {
    $bookings[] = $row;
}

$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Bookings Management | HandyLink</title>

    <link rel="stylesheet" href="../assets/css/style.css">

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>
<body>

<header class="topbar">
    <a href="dashboard.php" class="brand">
        <img src="../assets/images/handylink-logo.png"
             alt="HandyLink Logo">
        <span>HandyLink <small>Administration</small></span>
    </a>

    <div class="topbar-right">
        <span class="admin-label">
            <i class="fa-solid fa-user-shield"></i>
            <?= htmlspecialchars($adminName) ?>
        </span>

        <a href="dashboard.php" class="back-link">
            <i class="fa-solid fa-arrow-left"></i>
            Dashboard
        </a>
    </div>
</header>

<main class="page-container">

    <section class="page-heading">
        <div>
            <p class="eyebrow">SERVICE MANAGEMENT</p>
            <h1>Bookings Management</h1>
            <p class="page-description">
                Monitor service bookings between clients and service providers.
            </p>
        </div>

        <div class="heading-icon">
            <i class="fa-solid fa-calendar-check"></i>
        </div>
    </section>

    <section class="stats-grid">

        <article class="stat-card">
            <div class="stat-icon total-icon">
                <i class="fa-solid fa-calendar-days"></i>
            </div>
            <div>
                <p>Total Bookings</p>
                <h2><?= (int) $stats["total"] ?></h2>
            </div>
        </article>

        <article class="stat-card">
            <div class="stat-icon pending-icon">
                <i class="fa-solid fa-clock"></i>
            </div>
            <div>
                <p>Pending</p>
                <h2><?= (int) $stats["pending"] ?></h2>
            </div>
        </article>

        <article class="stat-card">
            <div class="stat-icon active-icon">
                <i class="fa-solid fa-spinner"></i>
            </div>
            <div>
                <p>Active</p>
                <h2><?= (int) $stats["active"] ?></h2>
            </div>
        </article>

        <article class="stat-card">
            <div class="stat-icon completed-icon">
                <i class="fa-solid fa-circle-check"></i>
            </div>
            <div>
                <p>Completed</p>
                <h2><?= (int) $stats["completed"] ?></h2>
            </div>
        </article>

        <article class="stat-card">
            <div class="stat-icon cancelled-icon">
                <i class="fa-solid fa-ban"></i>
            </div>
            <div>
                <p>Cancelled / Rejected</p>
                <h2><?= (int) $stats["cancelled"] ?></h2>
            </div>
        </article>

    </section>

    <section class="bookings-panel">

        <div class="panel-heading">
            <div>
                <h2>All Bookings</h2>
                <p>Search and review booking records.</p>
            </div>

            <span class="record-count">
                <?= count($bookings) ?> record(s)
            </span>
        </div>

        <form method="GET" class="filter-form">

            <div class="search-box">
                <i class="fa-solid fa-magnifying-glass"></i>

                <input
                    type="search"
                    name="search"
                    placeholder="Search client, provider, service or ID..."
                    value="<?= htmlspecialchars($search) ?>"
                >
            </div>

            <select name="status" aria-label="Filter by booking status">
                <option value="">All statuses</option>

                <?php foreach ($allowedStatuses as $status): ?>
                    <option
                        value="<?= htmlspecialchars($status) ?>"
                        <?= $statusFilter === $status ? "selected" : "" ?>
                    >
                        <?= htmlspecialchars(bookingStatusLabel($status)) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="filter-button">
                <i class="fa-solid fa-filter"></i> Apply
            </button>

            <a href="bookings.php" class="reset-button">Reset</a>

        </form>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Booking</th>
                        <th>Client</th>
                        <th>Service / Request</th>
                        <th>Provider</th>
                        <th>Scheduled Date</th>
                        <th>Status</th>
                        <th>Details</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (empty($bookings)): ?>
                    <tr>
                        <td colspan="7" class="empty-state">
                            <i class="fa-regular fa-calendar-xmark"></i>
                            <strong>No bookings found</strong>
                            <span>Try changing your search or status filter.</span>
                        </td>
                    </tr>
                <?php else: ?>

                    <?php foreach ($bookings as $booking): ?>
                        <?php
                        $details = [
                            "Booking ID" => "#" . $booking["id"],
                            "Request ID" => $booking["request_id"]
                                ? "#" . $booking["request_id"] : "N/A",
                            "Client" => $booking["client_name"] ?: "Unknown client",
                            "Provider" => $booking["provider_name"] ?: "Unassigned",
                            "Category" => $booking["category_name"] ?: "Uncategorised",
                            "Service / Request" => $booking["request_title"] ?: "N/A",
                            "Date" => $booking["booking_date"] ?: "Not set",
                            "Time" => $booking["booking_time"] ?: "Not set",
                            "Status" => bookingStatusLabel($booking["status"]),
                            "Notes" => $booking["notes"] ?: "No notes",
                            "Created" => $booking["created_at"] ?: "N/A"
                        ];
                        ?>

                        <tr>
                            <td>
                                <strong class="booking-number">
                                    #<?= (int) $booking["id"] ?>
                                </strong>
                                <small>
                                    Request:
                                    <?= $booking["request_id"]
                                        ? "#" . (int) $booking["request_id"]
                                        : "N/A" ?>
                                </small>
                            </td>

                            <td>
                                <span class="person-name">
                                    <?= htmlspecialchars(
                                        $booking["client_name"] ?: "Unknown client"
                                    ) ?>
                                </span>
                            </td>

                            <td>
                                <span class="service-name">
                                    <?= htmlspecialchars(
                                        $booking["request_title"] ?: "Service request"
                                    ) ?>
                                </span>
                                <small>
                                    <?= htmlspecialchars(
                                        $booking["category_name"] ?: "Uncategorised"
                                    ) ?>
                                </small>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $booking["provider_name"] ?: "Unassigned"
                                ) ?>
                            </td>

                            <td>
                                <span class="date-value">
                                    <?= htmlspecialchars(
                                        $booking["booking_date"] ?: "Not set"
                                    ) ?>
                                </span>
                                <small>
                                    <?= htmlspecialchars(
                                        $booking["booking_time"] ?: "Not set"
                                    ) ?>
                                </small>
                            </td>

                            <td>
                                <span class="status-badge status-<?= bookingStatusClass($booking["status"]) ?>">
                                    <?= htmlspecialchars(
                                        bookingStatusLabel($booking["status"])
                                    ) ?>
                                </span>
                            </td>

                            <td>
                                <button
                                    type="button"
                                    class="details-button"
                                    data-details="<?= htmlspecialchars(
                                        json_encode(
                                            $details,
                                            JSON_HEX_APOS |
                                            JSON_HEX_QUOT |
                                            JSON_HEX_TAG |
                                            JSON_HEX_AMP
                                        ),
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?>"
                                >
                                    <i class="fa-regular fa-eye"></i>
                                    View
                                </button>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>
                </tbody>
            </table>
        </div>

    </section>

    <p class="page-footer">
        <i class="fa-solid fa-shield-halved"></i>
        HandyLink administration · Booking oversight
    </p>

</main>

<div class="modal-overlay" id="bookingModal" aria-hidden="true">
    <section class="details-modal" role="dialog"
             aria-modal="true" aria-labelledby="modalTitle">

        <div class="modal-header">
            <div>
                <p class="eyebrow">BOOKING INFORMATION</p>
                <h2 id="modalTitle">Booking Details</h2>
            </div>

            <button type="button" class="modal-close" id="closeModal"
                    aria-label="Close details">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="modal-content" id="modalContent"></div>

        <div class="modal-footer">
            <button type="button" class="filter-button" id="closeModalButton">
                Close
            </button>
        </div>

    </section>
</div>

<script>
const modal = document.getElementById("bookingModal");
const modalContent = document.getElementById("modalContent");

function closeBookingModal() {
    modal.classList.remove("show");
    modal.setAttribute("aria-hidden", "true");
}

document.querySelectorAll(".details-button").forEach(button => {
    button.addEventListener("click", () => {
        let details;

        try {
            details = JSON.parse(button.dataset.details);
        } catch (error) {
            alert("Unable to display these booking details.");
            return;
        }

        modalContent.replaceChildren();

        Object.entries(details).forEach(([label, value]) => {
            const item = document.createElement("div");
            item.className = "detail-item";

            const heading = document.createElement("span");
            heading.className = "detail-label";
            heading.textContent = label;

            const content = document.createElement("p");
            content.className = "detail-value";
            content.textContent = value ?? "N/A";

            item.append(heading, content);
            modalContent.appendChild(item);
        });

        modal.classList.add("show");
        modal.setAttribute("aria-hidden", "false");
    });
});

document.getElementById("closeModal").addEventListener(
    "click", closeBookingModal
);

document.getElementById("closeModalButton").addEventListener(
    "click", closeBookingModal
);

modal.addEventListener("click", event => {
    if (event.target === modal) {
        closeBookingModal();
    }
});

document.addEventListener("keydown", event => {
    if (event.key === "Escape") {
        closeBookingModal();
    }
});
</script>

</body>
</html>
