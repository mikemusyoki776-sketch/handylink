
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

if (empty($_SESSION["reviews_csrf"])) {
    $_SESSION["reviews_csrf"] = bin2hex(random_bytes(32));
}

$message = "";
$messageType = "success";

/* Delete a review securely */
if ($_SERVER["REQUEST_METHOD"] === "POST" &&
    ($_POST["action"] ?? "") === "delete_review") {

    $token = $_POST["csrf_token"] ?? "";
    $reviewId = filter_input(INPUT_POST, "review_id", FILTER_VALIDATE_INT);

    if (
        !is_string($token) ||
        !hash_equals($_SESSION["reviews_csrf"], $token)
    ) {
        $message = "Security check failed. Please try again.";
        $messageType = "error";
    } elseif (!$reviewId || $reviewId < 1) {
        $message = "Invalid review selected.";
        $messageType = "error";
    } else {
        $deleteStmt = $conn->prepare(
            "DELETE FROM reviews WHERE id = ?"
        );

        if ($deleteStmt) {
            $deleteStmt->bind_param("i", $reviewId);

            if ($deleteStmt->execute() && $deleteStmt->affected_rows > 0) {
                $message = "Review deleted successfully.";
            } else {
                $message = "Review could not be deleted or no longer exists.";
                $messageType = "error";
            }

            $deleteStmt->close();
        } else {
            $message = "Unable to prepare the delete request.";
            $messageType = "error";
        }
    }
}

$search = trim($_GET["search"] ?? "");
$ratingFilter = trim($_GET["rating"] ?? "");

if (!in_array($ratingFilter, ["1", "2", "3", "4", "5"], true)) {
    $ratingFilter = "";
}

/* Review statistics */
$stats = [
    "total" => 0,
    "average" => 0,
    "five_star" => 0,
    "low_rating" => 0
];

$statsResult = $conn->query("
    SELECT
        COUNT(*) AS total,
        COALESCE(AVG(rating), 0) AS average_rating,
        COALESCE(SUM(rating = 5), 0) AS five_star,
        COALESCE(SUM(rating <= 2), 0) AS low_rating
    FROM reviews
");

if ($statsResult) {
    $statsRow = $statsResult->fetch_assoc();

    $stats["total"] = (int) $statsRow["total"];
    $stats["average"] = (float) $statsRow["average_rating"];
    $stats["five_star"] = (int) $statsRow["five_star"];
    $stats["low_rating"] = (int) $statsRow["low_rating"];
}

/* Search and filter reviews */
$sql = "
    SELECT
        r.id,
        r.booking_id,
        r.client_id,
        r.provider_id,
        r.rating,
        r.comment,
        r.created_at,
        client.full_name AS client_name,
        provider.full_name AS provider_name
    FROM reviews r
    LEFT JOIN users client ON r.client_id = client.id
    LEFT JOIN users provider ON r.provider_id = provider.id
    WHERE 1 = 1
";

$params = [];
$types = "";

if ($search !== "") {
    $sql .= "
        AND (
            CAST(r.id AS CHAR) LIKE ?
            OR CAST(r.booking_id AS CHAR) LIKE ?
            OR client.full_name LIKE ?
            OR provider.full_name LIKE ?
            OR r.comment LIKE ?
        )
    ";

    $term = "%" . $search . "%";
    $params = [$term, $term, $term, $term, $term];
    $types = "sssss";
}

if ($ratingFilter !== "") {
    $sql .= " AND r.rating = ?";
    $params[] = (int) $ratingFilter;
    $types .= "i";
}

$sql .= " ORDER BY r.created_at DESC, r.id DESC";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Unable to prepare reviews query. Please check your database structure.");
}

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

$reviews = [];

while ($row = $result->fetch_assoc()) {
    $reviews[] = $row;
}

$stmt->close();

function renderStars($rating)
{
    $rating = (int) $rating;
    $stars = "";

    for ($i = 1; $i <= 5; $i++) {
        $stars .= $i <= $rating ? "★" : "☆";
    }

    return $stars;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Reviews Management | HandyLink</title>

    <link rel="stylesheet" href="../assets/css/style.css">

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>
<body>

<header class="topbar">
    <a href="dashboard.php" class="brand">
        <img src="../assets/images/handylink-logo.png"
             alt="HandyLink Logo">
        <span>
            HandyLink
            <small>Administration</small>
        </span>
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
            <p class="eyebrow">SERVICE QUALITY</p>
            <h1>Reviews Management</h1>
            <p class="page-description">
                Monitor client feedback and ratings for service providers.
            </p>
        </div>

        <div class="heading-icon">
            <i class="fa-solid fa-star"></i>
        </div>
    </section>

    <?php if ($message !== ""): ?>
        <div class="notice <?= $messageType === "error" ? "notice-error" : "notice-success" ?>">
            <i class="fa-solid <?= $messageType === "error"
                ? "fa-circle-exclamation"
                : "fa-circle-check" ?>"></i>
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <section class="stats-grid">

        <article class="stat-card">
            <div class="stat-icon total-icon">
                <i class="fa-solid fa-comments"></i>
            </div>
            <div>
                <p>Total Reviews</p>
                <h2><?= $stats["total"] ?></h2>
            </div>
        </article>

        <article class="stat-card">
            <div class="stat-icon average-icon">
                <i class="fa-solid fa-star"></i>
            </div>
            <div>
                <p>Average Rating</p>
                <h2><?= number_format($stats["average"], 1) ?><small>/5</small></h2>
            </div>
        </article>

        <article class="stat-card">
            <div class="stat-icon five-icon">
                <i class="fa-solid fa-thumbs-up"></i>
            </div>
            <div>
                <p>Five-Star Reviews</p>
                <h2><?= $stats["five_star"] ?></h2>
            </div>
        </article>

        <article class="stat-card">
            <div class="stat-icon low-icon">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </div>
            <div>
                <p>Low Ratings (1–2)</p>
                <h2><?= $stats["low_rating"] ?></h2>
            </div>
        </article>

    </section>

    <section class="reviews-panel">

        <div class="panel-heading">
            <div>
                <h2>Client Reviews</h2>
                <p>Read feedback and moderate inappropriate content.</p>
            </div>

            <span class="record-count">
                <?= count($reviews) ?> record(s)
            </span>
        </div>

        <form method="GET" class="filter-form">
            <div class="search-box">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input
                    type="search"
                    name="search"
                    value="<?= htmlspecialchars($search) ?>"
                    placeholder="Search client, provider, comment or ID..."
                >
            </div>

            <select name="rating" aria-label="Filter by rating">
                <option value="">All ratings</option>
                <?php for ($i = 5; $i >= 1; $i--): ?>
                    <option value="<?= $i ?>"
                        <?= $ratingFilter === (string) $i ? "selected" : "" ?>>
                        <?= $i ?> star<?= $i === 1 ? "" : "s" ?>
                    </option>
                <?php endfor; ?>
            </select>

            <button type="submit" class="filter-button">
                <i class="fa-solid fa-filter"></i>
                Apply
            </button>

            <a href="reviews.php" class="reset-button">Reset</a>
        </form>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Review</th>
                        <th>Client</th>
                        <th>Service Provider</th>
                        <th>Rating</th>
                        <th>Comment</th>
                        <th>Date Submitted</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (empty($reviews)): ?>
                    <tr>
                        <td colspan="7" class="empty-state">
                            <i class="fa-regular fa-comment-dots"></i>
                            <strong>No reviews found</strong>
                            <span>Try another search or rating filter.</span>
                        </td>
                    </tr>
                <?php else: ?>

                    <?php foreach ($reviews as $review): ?>
                        <tr>
                            <td>
                                <strong class="review-number">
                                    #<?= (int) $review["id"] ?>
                                </strong>
                                <small>
                                    Booking:
                                    <?= $review["booking_id"]
                                        ? "#" . (int) $review["booking_id"]
                                        : "N/A" ?>
                                </small>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $review["client_name"] ?: "Unknown client"
                                ) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $review["provider_name"] ?: "Unknown provider"
                                ) ?>
                            </td>

                            <td>
                                <div class="rating-stars"
                                     aria-label="<?= (int) $review["rating"] ?> out of 5 stars">
                                    <?= renderStars($review["rating"]) ?>
                                </div>
                                <small class="rating-number">
                                    <?= (int) $review["rating"] ?>/5
                                </small>
                            </td>

                            <td class="comment-cell">
                                <?= htmlspecialchars(
                                    $review["comment"] ?: "No comment provided"
                                ) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $review["created_at"] ?: "N/A"
                                ) ?>
                            </td>

                            <td>
                                <form method="POST"
                                      class="delete-form"
                                      onsubmit="return confirm('Are you sure you want to delete this review? This action cannot be undone.');">

                                    <input type="hidden"
                                           name="action"
                                           value="delete_review">

                                    <input type="hidden"
                                           name="review_id"
                                           value="<?= (int) $review["id"] ?>">

                                    <input type="hidden"
                                           name="csrf_token"
                                           value="<?= htmlspecialchars($_SESSION["reviews_csrf"]) ?>">

                                    <button type="submit" class="delete-button">
                                        <i class="fa-regular fa-trash-can"></i>
                                        Delete
                                    </button>
                                </form>
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
        HandyLink administration · Review moderation
    </p>

</main>

</body>
</html>
