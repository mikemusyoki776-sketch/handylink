
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

if (empty($_SESSION["vacancy_csrf"])) {
    $_SESSION["vacancy_csrf"] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION["vacancy_csrf"];
$message = "";
$messageType = "success";

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

$validStatuses = ["Draft", "Open", "Closed"];
$validTypes = ["Full-time", "Part-time", "Contract", "Intern"];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!hash_equals($csrf, $_POST["csrf_token"] ?? "")) {
        $message = "Invalid request. Refresh the page and try again.";
        $messageType = "error";
    } else {
        $action = $_POST["action"] ?? "";

        if ($action === "save") {
            $id = (int)($_POST["id"] ?? 0);
            $title = trim($_POST["job_title"] ?? "");
            $department = trim($_POST["department"] ?? "");
            $description = trim($_POST["job_description"] ?? "");
            $requirements = trim($_POST["requirements"] ?? "");
            $type = $_POST["employment_type"] ?? "Full-time";
            $location = trim($_POST["location"] ?? "");
            $deadline = trim($_POST["application_deadline"] ?? "");
            $status = $_POST["status"] ?? "Draft";

            if ($title === "" || $description === "") {
                $message = "Job title and description are required.";
                $messageType = "error";
            } elseif (
                !in_array($type, $validTypes, true) ||
                !in_array($status, $validStatuses, true)
            ) {
                $message = "Select a valid employment type and status.";
                $messageType = "error";
            } elseif (
                $deadline !== "" &&
                !preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline)
            ) {
                $message = "Enter a valid application deadline.";
                $messageType = "error";
            } else {
                $department = $department !== "" ? $department : null;
                $requirements = $requirements !== "" ? $requirements : null;
                $location = $location !== "" ? $location : null;
                $deadline = $deadline !== "" ? $deadline : null;

                if ($id > 0) {
                    $stmt = $conn->prepare(
                        "UPDATE job_vacancies
                         SET job_title = ?, department = ?,
                             job_description = ?, requirements = ?,
                             employment_type = ?, location = ?,
                             application_deadline = ?, status = ?
                         WHERE id = ?"
                    );

                    $stmt->bind_param(
                        "ssssssssi",
                        $title, $department, $description, $requirements,
                        $type, $location, $deadline, $status, $id
                    );
                } else {
                    $createdBy = (int)$_SESSION["user_id"];

                    $stmt = $conn->prepare(
                        "INSERT INTO job_vacancies
                         (job_title, department, job_description, requirements,
                          employment_type, location, application_deadline,
                          status, created_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                    );

                    $stmt->bind_param(
                        "ssssssssi",
                        $title, $department, $description, $requirements,
                        $type, $location, $deadline, $status, $createdBy
                    );
                }

                try {
                    if ($stmt->execute()) {
                        $stmt->close();
                        header("Location: job_vacancies.php?success=saved");
                        exit();
                    }

                    $message = "Unable to save the vacancy.";
                    $messageType = "error";
                } catch (mysqli_sql_exception $e) {
                    $message = "A database error occurred while saving the vacancy.";
                    $messageType = "error";
                }

                if ($stmt instanceof mysqli_stmt) {
                    $stmt->close();
                }
            }
        } elseif ($action === "delete") {
            $id = (int)($_POST["id"] ?? 0);

            if ($id > 0) {
                $stmt = $conn->prepare(
                    "DELETE FROM job_vacancies WHERE id = ?"
                );
                $stmt->bind_param("i", $id);

                try {
                    $stmt->execute();

                    if ($stmt->affected_rows > 0) {
                        $stmt->close();
                        header("Location: job_vacancies.php?success=deleted");
                        exit();
                    }

                    $message = "Vacancy not found.";
                    $messageType = "error";
                } catch (mysqli_sql_exception $e) {
                    $message = "This vacancy has applications and cannot be deleted. Close it instead.";
                    $messageType = "error";
                }

                $stmt->close();
            }
        }
    }
}

if (($_GET["success"] ?? "") === "saved") {
    $message = "Vacancy saved successfully.";
} elseif (($_GET["success"] ?? "") === "deleted") {
    $message = "Vacancy deleted successfully.";
}

$search = trim($_GET["search"] ?? "");
$statusFilter = $_GET["status"] ?? "";

$sql = "SELECT v.*,
        (SELECT COUNT(*) FROM job_applications a
         WHERE a.vacancy_id = v.id) AS applicant_count
        FROM job_vacancies v
        WHERE 1=1";

$params = [];
$types = "";

if ($search !== "") {
    $sql .= " AND (v.job_title LIKE ? OR v.department LIKE ? OR v.location LIKE ?)";
    $term = "%" . $search . "%";
    $params = [$term, $term, $term];
    $types = "sss";
}

if (in_array($statusFilter, $validStatuses, true)) {
    $sql .= " AND v.status = ?";
    $params[] = $statusFilter;
    $types .= "s";
}

$sql .= " ORDER BY v.created_at DESC";

$stmt = $conn->prepare($sql);

if ($params) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();
$vacancies = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$statsResult = $conn->query(
    "SELECT COUNT(*) AS total,
        COALESCE(SUM(status = 'Open'), 0) AS open_count,
        COALESCE(SUM(status = 'Draft'), 0) AS draft_count,
        COALESCE(SUM(status = 'Closed'), 0) AS closed_count
     FROM job_vacancies"
);

$stats = $statsResult->fetch_assoc();

$editVacancy = null;

if (isset($_GET["edit"]) && (int)$_GET["edit"] > 0) {
    $editId = (int)$_GET["edit"];
    $stmt = $conn->prepare("SELECT * FROM job_vacancies WHERE id = ?");
    $stmt->bind_param("i", $editId);
    $stmt->execute();
    $editVacancy = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Job Vacancies | HandyLink Admin</title>
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
            <h1>Job Vacancies</h1>
            <p>Create and manage recruitment opportunities at HandyLink.</p>
        </div>
    </div>

    <?php if ($message !== ""): ?>
        <div class="notice <?= h($messageType) ?>">
            <?= h($message) ?>
        </div>
    <?php endif; ?>

    <section class="stats-grid">
        <div class="stat-card">
            <i class="fa-solid fa-briefcase"></i>
            <div>
                <span>Total Vacancies</span>
                <strong><?= (int)$stats["total"] ?></strong>
            </div>
        </div>

        <div class="stat-card">
            <i class="fa-solid fa-door-open"></i>
            <div>
                <span>Open</span>
                <strong><?= (int)$stats["open_count"] ?></strong>
            </div>
        </div>

        <div class="stat-card">
            <i class="fa-solid fa-file-pen"></i>
            <div>
                <span>Drafts</span>
                <strong><?= (int)$stats["draft_count"] ?></strong>
            </div>
        </div>

        <div class="stat-card">
            <i class="fa-solid fa-lock"></i>
            <div>
                <span>Closed</span>
                <strong><?= (int)$stats["closed_count"] ?></strong>
            </div>
        </div>
    </section>

    <section class="main-grid">
        <div class="panel" id="vacancy-form">
            <h2><?= $editVacancy ? "Edit Vacancy" : "Create Vacancy" ?></h2>
            <p class="panel-subtitle">Complete the job details below.</p>

            <form method="POST" action="job_vacancies.php" class="vacancy-form">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= (int)($editVacancy["id"] ?? 0) ?>">

                <label>Job Title *</label>
                <input name="job_title" required maxlength="150"
                       value="<?= h($editVacancy["job_title"] ?? "") ?>"
                       placeholder="e.g. Customer Support Officer">

                <label>Department</label>
                <input name="department" maxlength="100"
                       value="<?= h($editVacancy["department"] ?? "") ?>"
                       placeholder="e.g. Customer Support">

                <label>Job Description *</label>
                <textarea name="job_description" rows="5" required><?= h($editVacancy["job_description"] ?? "") ?></textarea>

                <label>Requirements and Qualifications</label>
                <textarea name="requirements" rows="4"><?= h($editVacancy["requirements"] ?? "") ?></textarea>

                <label>Employment Type</label>
                <select name="employment_type">
                    <?php foreach ($validTypes as $type): ?>
                        <option value="<?= h($type) ?>"
                            <?= ($editVacancy["employment_type"] ?? "Full-time") === $type ? "selected" : "" ?>>
                            <?= h($type) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label>Work Location</label>
                <input name="location" maxlength="150"
                       value="<?= h($editVacancy["location"] ?? "") ?>">

                <label>Application Deadline</label>
                <input type="date" name="application_deadline"
                       value="<?= h($editVacancy["application_deadline"] ?? "") ?>">

                <label>Vacancy Status</label>
                <select name="status">
                    <?php foreach ($validStatuses as $status): ?>
                        <option value="<?= h($status) ?>"
                            <?= ($editVacancy["status"] ?? "Draft") === $status ? "selected" : "" ?>>
                            <?= h($status) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <div class="form-actions">
                    <button class="primary-btn" type="submit">
                        <i class="fa-solid fa-floppy-disk"></i>
                        <?= $editVacancy ? "Update Vacancy" : "Save Vacancy" ?>
                    </button>

                    <?php if ($editVacancy): ?>
                        <a class="secondary-btn" href="job_vacancies.php">Cancel</a>
                    <?php else: ?>
                        <button class="secondary-btn" type="reset">Clear</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="panel">
            <h2>Vacancy Directory</h2>
            <p class="panel-subtitle">Search and manage available positions.</p>

            <form method="GET" class="filter-form">
                <input type="search" name="search"
                       placeholder="Search title, department or location"
                       value="<?= h($search) ?>">

                <select name="status">
                    <option value="">All statuses</option>
                    <?php foreach ($validStatuses as $status): ?>
                        <option value="<?= h($status) ?>"
                            <?= $statusFilter === $status ? "selected" : "" ?>>
                            <?= h($status) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button class="primary-btn" type="submit">Search</button>
                <a class="secondary-btn" href="job_vacancies.php">Reset</a>
            </form>

            <div class="table-wrapper">
                <table>
                    <thead>
                    <tr>
                        <th>Vacancy</th>
                        <th>Type / Location</th>
                        <th>Deadline</th>
                        <th>Applicants</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                    </thead>

                    <tbody>
                    <?php if (!$vacancies): ?>
                        <tr>
                            <td colspan="6" class="empty">No vacancies found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($vacancies as $v): ?>
                            <tr>
                                <td>
                                    <strong><?= h($v["job_title"]) ?></strong>
                                    <small><?= h($v["department"] ?: "No department") ?></small>
                                </td>

                                <td>
                                    <?= h($v["employment_type"]) ?>
                                    <small><?= h($v["location"] ?: "Not specified") ?></small>
                                </td>

                                <td><?= h($v["application_deadline"] ?: "No deadline") ?></td>
                                <td><?= (int)$v["applicant_count"] ?></td>

                                <td>
                                    <span class="status status-<?= strtolower(h($v["status"])) ?>">
                                        <?= h($v["status"]) ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="actions">
                                        <a class="edit-action"
                                           href="job_vacancies.php?edit=<?= (int)$v["id"] ?>#vacancy-form"
                                           title="Edit vacancy">
                                            <i class="fa-solid fa-pen"></i>
                                        </a>

                                        <form method="POST" class="delete-form"
                                              onsubmit="return confirm('Delete this vacancy? Vacancies with applications cannot be deleted.');">
                                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int)$v["id"] ?>">

                                            <button class="delete-action" type="submit" title="Delete vacancy">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</main>

</body>
</html>
