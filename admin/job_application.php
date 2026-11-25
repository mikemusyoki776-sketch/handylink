
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

if (empty($_SESSION["application_csrf"])) {
    $_SESSION["application_csrf"] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION["application_csrf"];
$message = "";
$messageType = "success";

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

$statuses = [
    "Pending",
    "Shortlisted",
    "Interview Scheduled",
    "Interviewed",
    "Hired",
    "Rejected"
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!hash_equals($csrf, $_POST["csrf_token"] ?? "")) {
        $message = "Invalid request. Refresh the page and try again.";
        $messageType = "error";
    } else {
        $action = $_POST["action"] ?? "";

        if ($action === "update_status") {
            $id = (int)($_POST["id"] ?? 0);
            $status = $_POST["status"] ?? "";
            $notes = trim($_POST["admin_notes"] ?? "");

            if ($id <= 0 || !in_array($status, $statuses, true)) {
                $message = "Please select a valid application and status.";
                $messageType = "error";
            } else {
                $notes = $notes !== "" ? $notes : null;

                $stmt = $conn->prepare(
                    "UPDATE job_applications
                     SET status = ?, admin_notes = ?
                     WHERE id = ?"
                );
                $stmt->bind_param("ssi", $status, $notes, $id);

                if ($stmt->execute() && $stmt->affected_rows >= 0) {
                    $stmt->close();
                    header("Location: job_application.php?success=updated");
                    exit();
                }

                $stmt->close();
                $message = "Unable to update the application.";
                $messageType = "error";
            }
        }
    }
}

if (($_GET["success"] ?? "") === "updated") {
    $message = "Application updated successfully.";
}

$search = trim($_GET["search"] ?? "");
$statusFilter = $_GET["status"] ?? "";
$vacancyFilter = (int)($_GET["vacancy_id"] ?? 0);

$sql = "SELECT a.*, v.job_title
        FROM job_applications a
        INNER JOIN job_vacancies v ON a.vacancy_id = v.id
        WHERE 1=1";

$params = [];
$types = "";

if ($search !== "") {
    $sql .= " AND (
        a.applicant_name LIKE ?
        OR a.email LIKE ?
        OR a.phone LIKE ?
        OR v.job_title LIKE ?
    )";

    $term = "%" . $search . "%";
    $params = [$term, $term, $term, $term];
    $types = "ssss";
}

if (in_array($statusFilter, $statuses, true)) {
    $sql .= " AND a.status = ?";
    $params[] = $statusFilter;
    $types .= "s";
}

if ($vacancyFilter > 0) {
    $sql .= " AND a.vacancy_id = ?";
    $params[] = $vacancyFilter;
    $types .= "i";
}

$sql .= " ORDER BY a.application_date DESC";

$stmt = $conn->prepare($sql);

if ($params) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$applications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$vacanciesResult = $conn->query(
    "SELECT id, job_title FROM job_vacancies ORDER BY job_title"
);
$vacancies = $vacanciesResult->fetch_all(MYSQLI_ASSOC);

$statsResult = $conn->query(
    "SELECT
        COUNT(*) AS total,
        COALESCE(SUM(status = 'Pending'), 0) AS pending_count,
        COALESCE(SUM(status = 'Shortlisted'), 0) AS shortlisted_count,
        COALESCE(SUM(status = 'Hired'), 0) AS hired_count
     FROM job_applications"
);
$stats = $statsResult->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Job Applications | HandyLink Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>
<body>

<header class="topbar">
    <a class="brand" href="dashboard.php">
        <img src="../assets/images/handylink-logo.png" alt="HandyLink Logo">
        <span>HandyLink<small>Administration</small></span>
    </a>

    <div class="top-actions">
        <a href="job_vacancies.php">
            <i class="fa-solid fa-briefcase"></i> Vacancies
        </a>
        <a href="dashboard.php">
            <i class="fa-solid fa-gauge"></i> Dashboard
        </a>
        <a class="logout" href="../logout.php">
            <i class="fa-solid fa-right-from-bracket"></i> Logout
        </a>
    </div>
</header>

<main class="page-container">
    <div class="page-heading">
        <div>
            <span class="eyebrow">HUMAN RESOURCE MANAGEMENT</span>
            <h1>Job Applications</h1>
            <p>Review applicants and manage recruitment progress.</p>
        </div>
    </div>

    <?php if ($message !== ""): ?>
        <div class="notice <?= h($messageType) ?>"><?= h($message) ?></div>
    <?php endif; ?>

    <section class="stats-grid">
        <div class="stat-card">
            <i class="fa-solid fa-users"></i>
            <div><span>Total Applications</span><strong><?= (int)$stats["total"] ?></strong></div>
        </div>
        <div class="stat-card">
            <i class="fa-solid fa-clock"></i>
            <div><span>Pending</span><strong><?= (int)$stats["pending_count"] ?></strong></div>
        </div>
        <div class="stat-card">
            <i class="fa-solid fa-user-check"></i>
            <div><span>Shortlisted</span><strong><?= (int)$stats["shortlisted_count"] ?></strong></div>
        </div>
        <div class="stat-card">
            <i class="fa-solid fa-user-tie"></i>
            <div><span>Hired</span><strong><?= (int)$stats["hired_count"] ?></strong></div>
        </div>
    </section>

    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2>Applicant Directory</h2>
                <p><?= count($applications) ?> application(s) found.</p>
            </div>
            <i class="fa-solid fa-address-card heading-icon"></i>
        </div>

        <form method="GET" class="filter-form">
            <input type="search" name="search"
                   placeholder="Search name, email, phone or vacancy"
                   value="<?= h($search) ?>">

            <select name="vacancy_id">
                <option value="0">All vacancies</option>
                <?php foreach ($vacancies as $vacancy): ?>
                    <option value="<?= (int)$vacancy["id"] ?>"
                        <?= $vacancyFilter === (int)$vacancy["id"] ? "selected" : "" ?>>
                        <?= h($vacancy["job_title"]) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="status">
                <option value="">All statuses</option>
                <?php foreach ($statuses as $status): ?>
                    <option value="<?= h($status) ?>"
                        <?= $statusFilter === $status ? "selected" : "" ?>>
                        <?= h($status) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button class="primary-btn" type="submit">Search</button>
            <a class="secondary-btn" href="job-applications.php">Reset</a>
        </form>

        <div class="table-wrapper">
            <table>
                <thead>
                <tr>
                    <th>Applicant</th>
                    <th>Vacancy</th>
                    <th>Education</th>
                    <th>Applied On</th>
                    <th>CV</th>
                    <th>Update Application</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$applications): ?>
                    <tr>
                        <td colspan="6" class="empty">
                            No applications found. Applications will appear here once submitted.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($applications as $app): ?>
                        <tr>
                            <td>
                                <strong><?= h($app["applicant_name"]) ?></strong>
                                <small><?= h($app["email"]) ?></small>
                                <small><?= h($app["phone"]) ?></small>
                            </td>
                            <td><?= h($app["job_title"]) ?></td>
                            <td><?= h($app["education_level"] ?: "Not specified") ?></td>
                            <td><?= h(date("d M Y", strtotime($app["application_date"]))) ?></td>
                            <td>
                                <?php if (!empty($app["cv_file"])): ?>
                                    <a class="cv-link"
                                       href="../uploads/cvs/<?= rawurlencode(basename($app["cv_file"])) ?>"
                                       target="_blank" rel="noopener">
                                        <i class="fa-solid fa-file-pdf"></i> View CV
                                    </a>
                                <?php else: ?>
                                    <span class="muted">Not uploaded</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="POST" class="update-form">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="id" value="<?= (int)$app["id"] ?>">

                                    <select name="status" aria-label="Application status">
                                        <?php foreach ($statuses as $status): ?>
                                            <option value="<?= h($status) ?>"
                                                <?= $app["status"] === $status ? "selected" : "" ?>>
                                                <?= h($status) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>

                                    <textarea name="admin_notes" rows="2"
                                              placeholder="Admin notes"><?= h($app["admin_notes"] ?? "") ?></textarea>

                                    <button class="primary-btn" type="submit">
                                        <i class="fa-solid fa-floppy-disk"></i> Save
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
</main>

</body>
</html>
