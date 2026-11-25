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

$adminName = $_SESSION["full_name"] ?? "Admin";

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

function h($value) {
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

$success = "";
$error = "";

$allowedTypes = ["Physical", "Online", "Phone"];
$allowedStatuses = ["Scheduled", "Completed", "Rescheduled", "Cancelled"];
$allowedOutcomes = ["Pending", "Passed", "Failed", "Further Review"];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $token = $_POST["csrf_token"] ?? "";

    if (!hash_equals($_SESSION["csrf_token"], $token)) {
        $error = "Your session token is invalid. Refresh the page and try again.";
    } else {
        $action = $_POST["action"] ?? "";

        if ($action === "save") {
            $id = (int)($_POST["id"] ?? 0);
            $applicationId = (int)($_POST["application_id"] ?? 0);
            $interviewDate = trim($_POST["interview_date"] ?? "");
            $interviewTime = trim($_POST["interview_time"] ?? "");
            $venue = trim($_POST["venue"] ?? "");
            $interviewType = $_POST["interview_type"] ?? "Physical";
            $interviewer = trim($_POST["interviewer"] ?? "");
            $status = $_POST["status"] ?? "Scheduled";
            $outcome = $_POST["outcome"] ?? "Pending";
            $comments = trim($_POST["interviewer_comments"] ?? "");

            if (
                $applicationId < 1 ||
                $interviewDate === "" ||
                $interviewTime === "" ||
                $venue === ""
            ) {
                $error = "Please fill in the applicant, date, time and venue.";
            } elseif (
                !in_array($interviewType, $allowedTypes, true) ||
                !in_array($status, $allowedStatuses, true) ||
                !in_array($outcome, $allowedOutcomes, true)
            ) {
                $error = "Please select valid interview options.";
            } else {
                $check = $conn->prepare(
                    "SELECT id, status FROM job_applications WHERE id = ?"
                );
                $check->bind_param("i", $applicationId);
                $check->execute();
                $application = $check->get_result()->fetch_assoc();
                $check->close();

                if (!$application) {
                    $error = "The selected job application could not be found.";
                } else {
                    try {
                        if ($id > 0) {
                            $stmt = $conn->prepare(
                                "UPDATE interviews
                                 SET application_id = ?,
                                     interview_date = ?,
                                     interview_time = ?,
                                     venue = ?,
                                     interview_type = ?,
                                     interviewer = NULLIF(?, ''),
                                     status = ?,
                                     outcome = ?,
                                     interviewer_comments = NULLIF(?, '')
                                 WHERE id = ?"
                            );

                            $stmt->bind_param(
                                "issssssssi",
                                $applicationId,
                                $interviewDate,
                                $interviewTime,
                                $venue,
                                $interviewType,
                                $interviewer,
                                $status,
                                $outcome,
                                $comments,
                                $id
                            );
                            $stmt->execute();
                            $stmt->close();

                            $success = "Interview details updated successfully.";
                        } else {
                            $stmt = $conn->prepare(
                                "INSERT INTO interviews
                                 (application_id, interview_date, interview_time,
                                  venue, interview_type, interviewer, status,
                                  outcome, interviewer_comments)
                                 VALUES (?, ?, ?, ?, ?, NULLIF(?, ''),
                                         ?, ?, NULLIF(?, ''))"
                            );

                            $stmt->bind_param(
                                "issssssss",
                                $applicationId,
                                $interviewDate,
                                $interviewTime,
                                $venue,
                                $interviewType,
                                $interviewer,
                                $status,
                                $outcome,
                                $comments
                            );
                            $stmt->execute();
                            $stmt->close();

                            $success = "Interview scheduled successfully.";
                        }

                        if ($status === "Completed") {
                            $newApplicationStatus = "Interviewed";
                        } elseif (
                            in_array($status, ["Scheduled", "Rescheduled"], true)
                        ) {
                            $newApplicationStatus = "Interview Scheduled";
                        } else {
                            $newApplicationStatus = "";
                        }

                        if ($newApplicationStatus !== "") {
                            $updateApp = $conn->prepare(
                                "UPDATE job_applications
                                 SET status = ?
                                 WHERE id = ?
                                   AND status NOT IN ('Hired', 'Rejected')"
                            );
                            $updateApp->bind_param(
                                "si",
                                $newApplicationStatus,
                                $applicationId
                            );
                            $updateApp->execute();
                            $updateApp->close();
                        }
                    } catch (mysqli_sql_exception $e) {
                        $error = "Unable to save the interview. Please check the details and try again.";
                    }
                }
            }
        } elseif ($action === "cancel") {
            $id = (int)($_POST["id"] ?? 0);

            if ($id > 0) {
                try {
                    $stmt = $conn->prepare(
                        "UPDATE interviews
                         SET status = 'Cancelled'
                         WHERE id = ?"
                    );
                    $stmt->bind_param("i", $id);
                    $stmt->execute();

                    if ($stmt->affected_rows >= 0) {
                        $success = "Interview marked as cancelled.";
                    }

                    $stmt->close();
                } catch (mysqli_sql_exception $e) {
                    $error = "Unable to cancel this interview.";
                }
            }
        }
    }
}

$editInterview = null;
$editId = (int)($_GET["edit"] ?? 0);

if ($editId > 0) {
    $stmt = $conn->prepare("SELECT * FROM interviews WHERE id = ?");
    $stmt->bind_param("i", $editId);
    $stmt->execute();
    $editInterview = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$applications = [];
$result = $conn->query(
    "SELECT a.id, a.applicant_name, a.email, a.phone,
            a.status AS application_status, v.job_title
     FROM job_applications a
     INNER JOIN job_vacancies v ON a.vacancy_id = v.id
     WHERE a.status NOT IN ('Hired', 'Rejected')
        OR EXISTS (
            SELECT 1 FROM interviews i WHERE i.application_id = a.id
        )
     ORDER BY a.application_date DESC"
);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $applications[] = $row;
    }
}

$search = trim($_GET["search"] ?? "");
$statusFilter = $_GET["status"] ?? "";

$sql = "SELECT i.*, a.applicant_name, a.email, a.phone,
               a.status AS application_status, v.job_title
        FROM interviews i
        INNER JOIN job_applications a ON i.application_id = a.id
        INNER JOIN job_vacancies v ON a.vacancy_id = v.id
        WHERE 1 = 1";

$params = [];
$types = "";

if ($search !== "") {
    $sql .= " AND (a.applicant_name LIKE ? OR a.email LIKE ?
                    OR v.job_title LIKE ? OR i.venue LIKE ?)";
    $term = "%" . $search . "%";
    $params = array_merge($params, [$term, $term, $term, $term]);
    $types .= "ssss";
}

if (in_array($statusFilter, $allowedStatuses, true)) {
    $sql .= " AND i.status = ?";
    $params[] = $statusFilter;
    $types .= "s";
}

$sql .= " ORDER BY i.interview_date DESC, i.interview_time DESC";

$stmt = $conn->prepare($sql);

if ($params) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$interviewsResult = $stmt->get_result();
$interviews = [];

while ($row = $interviewsResult->fetch_assoc()) {
    $interviews[] = $row;
}
$stmt->close();

$statsResult = $conn->query(
    "SELECT
        COUNT(*) AS total_interviews,
        COALESCE(SUM(status = 'Scheduled'), 0) AS scheduled_count,
        COALESCE(SUM(status = 'Completed'), 0) AS completed_count,
        COALESCE(SUM(status = 'Cancelled'), 0) AS cancelled_count
     FROM interviews"
);

$stats = $statsResult
    ? $statsResult->fetch_assoc()
    : [
        "total_interviews" => 0,
        "scheduled_count" => 0,
        "completed_count" => 0,
        "cancelled_count" => 0
    ];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Interview Management | HandyLink Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>
<body>

<header class="interview-header">
    <a href="dashboard.php" class="brand">
        <img src="../assets/images/handylink-logo.png" alt="HandyLink Logo">
        <span>HandyLink <small>Administration</small></span>
    </a>

    <div class="header-right">
        <span>Welcome, <?= h($adminName) ?></span>
        <a href="recruitment.php" class="header-link">
            <i class="fa-solid fa-arrow-left"></i>
            Recruitment
        </a>
    </div>
</header>

<main class="interview-container">

    <nav class="breadcrumb">
        <a href="dashboard.php">Dashboard</a>
        <i class="fa-solid fa-chevron-right"></i>
        <a href="recruitment.php">Recruitment &amp; Application</a>
        <i class="fa-solid fa-chevron-right"></i>
        <strong>Interview Management</strong>
    </nav>

    <section class="page-heading">
        <div>
            <span class="eyebrow">RECRUITMENT</span>
            <h1>Interview Management</h1>
            <p>Schedule candidate interviews and record interview progress and outcomes.</p>
        </div>
        <a href="#interview-form" class="primary-button">
            <i class="fa-solid fa-calendar-plus"></i>
            Schedule Interview
        </a>
    </section>

    <?php if ($success !== ""): ?>
        <div class="alert success-alert">
            <i class="fa-solid fa-circle-check"></i>
            <?= h($success) ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ""): ?>
        <div class="alert error-alert">
            <i class="fa-solid fa-circle-exclamation"></i>
            <?= h($error) ?>
        </div>
    <?php endif; ?>

    <section class="stats-grid">
        <article class="stat-card">
            <div class="stat-icon total-icon"><i class="fa-solid fa-calendar-days"></i></div>
            <div><span>Total Interviews</span><strong><?= (int)$stats["total_interviews"] ?></strong></div>
        </article>
        <article class="stat-card">
            <div class="stat-icon scheduled-icon"><i class="fa-solid fa-clock"></i></div>
            <div><span>Scheduled</span><strong><?= (int)$stats["scheduled_count"] ?></strong></div>
        </article>
        <article class="stat-card">
            <div class="stat-icon completed-icon"><i class="fa-solid fa-circle-check"></i></div>
            <div><span>Completed</span><strong><?= (int)$stats["completed_count"] ?></strong></div>
        </article>
        <article class="stat-card">
            <div class="stat-icon cancelled-icon"><i class="fa-solid fa-calendar-xmark"></i></div>
            <div><span>Cancelled</span><strong><?= (int)$stats["cancelled_count"] ?></strong></div>
        </article>
    </section>

    <section class="form-section" id="interview-form">
        <div class="section-heading">
            <div>
                <h2><?= $editInterview ? "Edit Interview" : "Schedule a New Interview" ?></h2>
                <p>Complete the details below to arrange an interview.</p>
            </div>
            <?php if ($editInterview): ?>
                <a href="interviews.php" class="secondary-button">Cancel Editing</a>
            <?php endif; ?>
        </div>

        <?php if (count($applications) === 0): ?>
            <div class="empty-notice">
                No job applications are available yet. Add an application before scheduling an interview.
            </div>
        <?php else: ?>
            <form method="POST" action="interviews.php<?= $editInterview ? '?edit=' . (int)$editInterview["id"] : '' ?>">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION["csrf_token"]) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= (int)($editInterview["id"] ?? 0) ?>">

                <div class="form-grid">
                    <div class="form-group full-width">
                        <label for="application_id">Applicant and Job Vacancy *</label>
                        <select name="application_id" id="application_id" required>
                            <option value="">Select applicant</option>
                            <?php foreach ($applications as $app): ?>
                                <option value="<?= (int)$app["id"] ?>"
                                    <?= (int)($editInterview["application_id"] ?? 0) === (int)$app["id"] ? "selected" : "" ?>>
                                    <?= h($app["applicant_name"]) ?> —
                                    <?= h($app["job_title"]) ?> [<?= h($app["application_status"]) ?>]
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="interview_date">Interview Date *</label>
                        <input type="date" name="interview_date" id="interview_date"
                               value="<?= h($editInterview["interview_date"] ?? "") ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="interview_time">Interview Time *</label>
                        <input type="time" name="interview_time" id="interview_time"
                               value="<?= h($editInterview["interview_time"] ?? "") ?>" required>
                    </div>

                    <div class="form-group full-width">
                        <label for="venue">Venue or Meeting Details *</label>
                        <input type="text" name="venue" id="venue" maxlength="255"
                               placeholder="e.g. HandyLink Office, Nairobi"
                               value="<?= h($editInterview["venue"] ?? "") ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="interview_type">Interview Type</label>
                        <select name="interview_type" id="interview_type">
                            <?php foreach ($allowedTypes as $type): ?>
                                <option value="<?= h($type) ?>"
                                    <?= ($editInterview["interview_type"] ?? "Physical") === $type ? "selected" : "" ?>>
                                    <?= h($type) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="interviewer">Interviewer</label>
                        <input type="text" name="interviewer" id="interviewer" maxlength="150"
                               placeholder="Name of interviewer"
                               value="<?= h($editInterview["interviewer"] ?? "") ?>">
                    </div>

                    <div class="form-group">
                        <label for="status">Interview Status</label>
                        <select name="status" id="status">
                            <?php foreach ($allowedStatuses as $status): ?>
                                <option value="<?= h($status) ?>"
                                    <?= ($editInterview["status"] ?? "Scheduled") === $status ? "selected" : "" ?>>
                                    <?= h($status) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="outcome">Interview Outcome</label>
                        <select name="outcome" id="outcome">
                            <?php foreach ($allowedOutcomes as $outcome): ?>
                                <option value="<?= h($outcome) ?>"
                                    <?= ($editInterview["outcome"] ?? "Pending") === $outcome ? "selected" : "" ?>>
                                    <?= h($outcome) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group full-width">
                        <label for="interviewer_comments">Interview Comments / Notes</label>
                        <textarea name="interviewer_comments" id="interviewer_comments"
                                  rows="4" placeholder="Enter interview feedback or notes..."><?= h($editInterview["interviewer_comments"] ?? "") ?></textarea>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="primary-button">
                        <i class="fa-solid fa-floppy-disk"></i>
                        <?= $editInterview ? "Update Interview" : "Save Interview" ?>
                    </button>
                    <?php if ($editInterview): ?>
                        <a href="interviews.php" class="secondary-button">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        <?php endif; ?>
    </section>

    <section class="table-section">
        <div class="section-heading">
            <div>
                <h2>Interview Records</h2>
                <p>Search, update and monitor scheduled interviews.</p>
            </div>
        </div>

        <form method="GET" class="filter-form">
            <div class="search-box">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" name="search" placeholder="Search applicant, vacancy or venue..."
                       value="<?= h($search) ?>">
            </div>

            <select name="status" aria-label="Filter by interview status">
                <option value="">All statuses</option>
                <?php foreach ($allowedStatuses as $status): ?>
                    <option value="<?= h($status) ?>" <?= $statusFilter === $status ? "selected" : "" ?>>
                        <?= h($status) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="filter-button">
                <i class="fa-solid fa-filter"></i> Filter
            </button>
            <a href="interviews.php" class="reset-button">Reset</a>
        </form>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Applicant</th>
                        <th>Vacancy</th>
                        <th>Date &amp; Time</th>
                        <th>Venue</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Outcome</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (count($interviews) > 0): ?>
                    <?php foreach ($interviews as $interview): ?>
                        <tr>
                            <td>
                                <strong><?= h($interview["applicant_name"]) ?></strong>
                                <small><?= h($interview["email"]) ?></small>
                                <small><?= h($interview["phone"]) ?></small>
                            </td>
                            <td><?= h($interview["job_title"]) ?></td>
                            <td>
                                <?= h(date("d M Y", strtotime($interview["interview_date"]))) ?>
                                <small><?= h(date("h:i A", strtotime($interview["interview_time"]))) ?></small>
                            </td>
                            <td><?= h($interview["venue"]) ?></td>
                            <td><?= h($interview["interview_type"]) ?></td>
                            <td>
                                <span class="status-badge status-<?= strtolower(str_replace(" ", "-", $interview["status"])) ?>">
                                    <?= h($interview["status"]) ?>
                                </span>
                            </td>
                            <td>
                                <span class="outcome-badge outcome-<?= strtolower(str_replace(" ", "-", $interview["outcome"])) ?>">
                                    <?= h($interview["outcome"]) ?>
                                </span>
                            </td>
                            <td>
                                <div class="action-group">
                                    <a class="icon-button edit-button"
                                       href="interviews.php?edit=<?= (int)$interview["id"] ?>#interview-form"
                                       title="Edit interview" aria-label="Edit interview">
                                        <i class="fa-solid fa-pen"></i>
                                    </a>

                                    <?php if ($interview["status"] !== "Cancelled"): ?>
                                        <form method="POST" action="interviews.php"
                                              onsubmit="return confirm('Cancel this interview?');">
                                            <input type="hidden" name="csrf_token" value="<?= h($_SESSION["csrf_token"]) ?>">
                                            <input type="hidden" name="action" value="cancel">
                                            <input type="hidden" name="id" value="<?= (int)$interview["id"] ?>">
                                            <button type="submit" class="icon-button cancel-button"
                                                    title="Cancel interview" aria-label="Cancel interview">
                                                <i class="fa-solid fa-ban"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8" class="empty-state">
                            <i class="fa-regular fa-calendar-xmark"></i>
                            <strong>No interviews found</strong>
                            <span>Schedule an interview or change your search filters.</span>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <footer class="page-footer">
        &copy; <?= date("Y") ?> HandyLink Solution Management System
    </footer>

</main>
</body>
</html>