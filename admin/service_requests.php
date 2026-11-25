
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

$search = trim($_GET["search"] ?? "");
$statusFilter = trim($_GET["status"] ?? "");

$allowedStatuses = [
    "pending",
    "approved",
    "accepted",
    "rejected",
    "cancelled",
    "completed",
    "in_progress"
];

$sql = "
    SELECT
        sr.id,
        sr.client_id,
        sr.category_id,
        sr.title,
        sr.description,
        sr.location,
        sr.preferred_date,
        sr.preferred_time,
        sr.status,
        sr.created_at,
        u.full_name AS client_name,
        c.name AS category_name
    FROM service_requests sr
    LEFT JOIN users u ON sr.client_id = u.id
    LEFT JOIN categories c ON sr.category_id = c.id
    WHERE 1 = 1
";

$params = [];
$types = "";

if ($search !== "") {
    $sql .= "
        AND (
            sr.title LIKE ?
            OR sr.location LIKE ?
            OR u.full_name LIKE ?
            OR c.name LIKE ?
        )
    ";

    $term = "%" . $search . "%";

    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;

    $types .= "ssss";
}

if (
    $statusFilter !== "" &&
    in_array($statusFilter, $allowedStatuses, true)
) {
    $sql .= " AND sr.status = ?";
    $params[] = $statusFilter;
    $types .= "s";
}

$sql .= " ORDER BY sr.created_at DESC";

$stmt = $conn->prepare($sql);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

$requests = [];

while ($row = $result->fetch_assoc()) {
    $requests[] = $row;
}

$stmt->close();

$countResult = $conn->query("
    SELECT
        COUNT(*) AS total,
        COALESCE(SUM(LOWER(status) = 'pending'), 0) AS pending,
        COALESCE(SUM(LOWER(status) = 'completed'), 0) AS completed,
        COALESCE(SUM(LOWER(status) = 'cancelled'), 0) AS cancelled
    FROM service_requests
");

$counts = $countResult->fetch_assoc();

function e($value) {
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

function formatStatus($status) {
    return ucwords(str_replace("_", " ", (string)$status));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Service Requests | HandyLink Admin</title>

    <link rel="stylesheet"
          href="../assets/css/style.css">

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>

<body>

<header class="page-header">
    <a href="dashboard.php" class="brand">
        <img src="../assets/images/handylink-logo.png"
             alt="HandyLink Logo">
        <span>HandyLink <small>Administration</small></span>
    </a>

    <a href="dashboard.php" class="back-link">
        <i class="fa-solid fa-arrow-left"></i>
        Dashboard
    </a>
</header>

<main class="container">

    <section class="page-heading">
        <div>
            <p class="eyebrow">SERVICE MANAGEMENT</p>
            <h1>Service Requests</h1>
            <p class="subtitle">
                Monitor service requests submitted by clients.
            </p>
        </div>
    </section>

    <section class="summary-grid">

        <article class="summary-card">
            <div class="summary-icon">
                <i class="fa-solid fa-clipboard-list"></i>
            </div>
            <div>
                <p>Total Requests</p>
                <h2><?= (int)$counts["total"] ?></h2>
            </div>
        </article>

        <article class="summary-card">
            <div class="summary-icon pending-icon">
                <i class="fa-regular fa-clock"></i>
            </div>
            <div>
                <p>Pending</p>
                <h2><?= (int)$counts["pending"] ?></h2>
            </div>
        </article>

        <article class="summary-card">
            <div class="summary-icon completed-icon">
                <i class="fa-solid fa-check-circle"></i>
            </div>
            <div>
                <p>Completed</p>
                <h2><?= (int)$counts["completed"] ?></h2>
            </div>
        </article>

        <article class="summary-card">
            <div class="summary-icon cancelled-icon">
                <i class="fa-solid fa-ban"></i>
            </div>
            <div>
                <p>Cancelled</p>
                <h2><?= (int)$counts["cancelled"] ?></h2>
            </div>
        </article>

    </section>

    <section class="requests-panel">

        <div class="panel-heading">
            <div>
                <h2>All Requests</h2>
                <p>Review the details of each client request.</p>
            </div>
        </div>

        <form method="GET" class="filters">

            <div class="search-box">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input
                    type="search"
                    name="search"
                    placeholder="Search title, client, location..."
                    value="<?= e($search) ?>"
                >
            </div>

            <select name="status">
                <option value="">All statuses</option>

                <?php foreach ($allowedStatuses as $status): ?>
                    <option
                        value="<?= e($status) ?>"
                        <?= $statusFilter === $status ? "selected" : "" ?>
                    >
                        <?= e(formatStatus($status)) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="filter-button">
                <i class="fa-solid fa-filter"></i>
                Filter
            </button>

            <a href="service-requests.php" class="reset-button">
                Reset
            </a>

        </form>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Request</th>
                        <th>Client</th>
                        <th>Category</th>
                        <th>Location</th>
                        <th>Preferred Date</th>
                        <th>Status</th>
                        <th>Details</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (empty($requests)): ?>

                    <tr>
                        <td colspan="7" class="empty-state">
                            <i class="fa-regular fa-folder-open"></i>
                            <p>No service requests found.</p>
                            <span>Try changing your search or filter.</span>
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($requests as $request): ?>
                        <?php
                        $statusClass = strtolower(
                            str_replace(
                                " ",
                                "-",
                                (string)$request["status"]
                            )
                        );
                        ?>

                        <tr>
                            <td>
                                <strong>
                                    <?= e($request["title"]) ?>
                                </strong>
                                <small>
                                    Request #<?= (int)$request["id"] ?>
                                </small>
                            </td>

                            <td>
                                <?= e($request["client_name"] ?: "Unknown client") ?>
                            </td>

                            <td>
                                <?= e($request["category_name"] ?: "Uncategorized") ?>
                            </td>

                            <td>
                                <?= e($request["location"] ?: "Not provided") ?>
                            </td>

                            <td>
                                <?= e($request["preferred_date"] ?: "Not specified") ?>
                                <?php if (!empty($request["preferred_time"])): ?>
                                    <small>
                                        <?= e($request["preferred_time"]) ?>
                                    </small>
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="status <?= e($statusClass) ?>">
                                    <?= e(formatStatus($request["status"])) ?>
                                </span>
                            </td>

                            <td>
                                <button
                                    type="button"
                                    class="details-button"
                                    data-title="<?= e($request["title"]) ?>"
                                    data-client="<?= e($request["client_name"]) ?>"
                                    data-category="<?= e($request["category_name"]) ?>"
                                    data-location="<?= e($request["location"]) ?>"
                                    data-date="<?= e($request["preferred_date"]) ?>"
                                    data-time="<?= e($request["preferred_time"]) ?>"
                                    data-status="<?= e(formatStatus($request["status"])) ?>"
                                    data-description="<?= e($request["description"]) ?>"
                                    data-created="<?= e($request["created_at"]) ?>"
                                    onclick="showDetails(this)"
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

        <div class="table-footer">
            Showing <?= count($requests) ?> request(s)
        </div>

    </section>

</main>

<div class="modal" id="detailsModal" aria-hidden="true">
    <div class="modal-content">

        <div class="modal-heading">
            <div>
                <p class="eyebrow">REQUEST INFORMATION</p>
                <h2 id="modalTitle">Request Details</h2>
            </div>

            <button type="button"
                    class="close-button"
                    onclick="closeDetails()"
                    aria-label="Close details">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="detail-list">
            <div>
                <span>Client</span>
                <strong id="modalClient"></strong>
            </div>

            <div>
                <span>Category</span>
                <strong id="modalCategory"></strong>
            </div>

            <div>
                <span>Location</span>
                <strong id="modalLocation"></strong>
            </div>

            <div>
                <span>Preferred date</span>
                <strong id="modalDate"></strong>
            </div>

            <div>
                <span>Preferred time</span>
                <strong id="modalTime"></strong>
            </div>

            <div>
                <span>Status</span>
                <strong id="modalStatus"></strong>
            </div>

            <div>
                <span>Date submitted</span>
                <strong id="modalCreated"></strong>
            </div>

            <div class="description-detail">
                <span>Description</span>
                <p id="modalDescription"></p>
            </div>
        </div>

        <button type="button"
                class="close-modal-button"
                onclick="closeDetails()">
            Close
        </button>

    </div>
</div>

<script>
function showDetails(button) {
    const fields = {
        title: "modalTitle",
        client: "modalClient",
        category: "modalCategory",
        location: "modalLocation",
        date: "modalDate",
        time: "modalTime",
        status: "modalStatus",
        created: "modalCreated",
        description: "modalDescription"
    };

    Object.keys(fields).forEach(function (key) {
        const element = document.getElementById(fields[key]);
        const value = button.dataset[key];

        element.textContent = value || "Not provided";
    });

    const modal = document.getElementById("detailsModal");
    modal.classList.add("show");
    modal.setAttribute("aria-hidden", "false");
}

function closeDetails() {
    const modal = document.getElementById("detailsModal");
    modal.classList.remove("show");
    modal.setAttribute("aria-hidden", "true");
}

document.getElementById("detailsModal").addEventListener("click", function (event) {
    if (event.target === this) {
        closeDetails();
    }
});

document.addEventListener("keydown", function (event) {
    if (event.key === "Escape") {
        closeDetails();
    }
});
</script>

</body>
</html>
