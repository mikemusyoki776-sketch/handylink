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

function h($value) {
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

$totalVacancies = 0;
$openVacancies = 0;
$totalApplications = 0;
$pendingApplications = 0;
$shortlistedApplications = 0;

$result = $conn->query(
    "SELECT
        COUNT(*) AS total_vacancies,
        COALESCE(SUM(status = 'Open'), 0) AS open_vacancies
     FROM job_vacancies"
);

if ($result) {
    $stats = $result->fetch_assoc();
    $totalVacancies = (int)$stats["total_vacancies"];
    $openVacancies = (int)$stats["open_vacancies"];
}

$result = $conn->query(
    "SELECT
        COUNT(*) AS total_applications,
        COALESCE(SUM(status = 'Pending'), 0) AS pending_applications,
        COALESCE(SUM(status = 'Shortlisted'), 0) AS shortlisted_applications
     FROM job_applications"
);

if ($result) {
    $stats = $result->fetch_assoc();
    $totalApplications = (int)$stats["total_applications"];
    $pendingApplications = (int)$stats["pending_applications"];
    $shortlistedApplications = (int)$stats["shortlisted_applications"];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recruitment & Application | HandyLink Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>
<body>

<header class="recruitment-header">
    <a href="dashboard.php" class="brand">
        <img src="../assets/images/handylink-logo.png" alt="HandyLink Logo">
        <span>HandyLink <small>Administration</small></span>
    </a>

    <div class="header-right">
        <span class="welcome">Welcome, <?= h($adminName) ?></span>
        <a href="dashboard.php" class="dashboard-link">
            <i class="fa-solid fa-gauge-high"></i>
            Dashboard
        </a>
    </div>
</header>

<main class="recruitment-container">

    <nav class="breadcrumb" aria-label="Breadcrumb">
        <a href="dashboard.php">Dashboard</a>
        <i class="fa-solid fa-chevron-right"></i>
        <span>People</span>
        <i class="fa-solid fa-chevron-right"></i>
        <strong>Recruitment &amp; Application</strong>
    </nav>

    <section class="page-heading">
        <div>
            <span class="eyebrow">PEOPLE MANAGEMENT</span>
            <h1>Recruitment &amp; Application</h1>
            <p>Manage job opportunities, review applicants and track recruitment progress in one place.</p>
        </div>
    </section>

    <section class="stats-grid" aria-label="Recruitment statistics">

        <article class="stat-card">
            <div class="stat-icon vacancies-icon">
                <i class="fa-solid fa-briefcase"></i>
            </div>
            <div>
                <span class="stat-label">Total Vacancies</span>
                <strong><?= $totalVacancies ?></strong>
                <small>All recorded job vacancies</small>
            </div>
        </article>

        <article class="stat-card">
            <div class="stat-icon open-icon">
                <i class="fa-solid fa-door-open"></i>
            </div>
            <div>
                <span class="stat-label">Open Vacancies</span>
                <strong><?= $openVacancies ?></strong>
                <small>Currently accepting applications</small>
            </div>
        </article>

        <article class="stat-card">
            <div class="stat-icon applications-icon">
                <i class="fa-solid fa-file-lines"></i>
            </div>
            <div>
                <span class="stat-label">Total Applications</span>
                <strong><?= $totalApplications ?></strong>
                <small>Applications received</small>
            </div>
        </article>

        <article class="stat-card">
            <div class="stat-icon pending-icon">
                <i class="fa-solid fa-hourglass-half"></i>
            </div>
            <div>
                <span class="stat-label">Pending Review</span>
                <strong><?= $pendingApplications ?></strong>
                <small>Awaiting an admin decision</small>
            </div>
        </article>

    </section>

    <section class="management-section">
        <div class="section-heading">
            <div>
                <h2>Recruitment Management</h2>
                <p>Choose the section you want to manage.</p>
            </div>
        </div>

        <div class="management-grid">

            <article class="management-card">
                <div class="management-card-top">
                    <div class="large-icon vacancies-icon">
                        <i class="fa-solid fa-briefcase"></i>
                    </div>
                    <span class="section-tag">JOB ADVERTISING</span>
                </div>

                <h3>Job Vacancies</h3>
                <p>Create new job advertisements, update job descriptions, set application deadlines and open or close vacancies.</p>

                <div class="card-details">
                    <span><i class="fa-solid fa-circle-check"></i> Open vacancies: <?= $openVacancies ?></span>
                    <span><i class="fa-solid fa-list"></i> Total vacancies: <?= $totalVacancies ?></span>
                </div>

                <a href="job_vacancies.php" class="action-button">
                    Manage Job Vacancies
                    <i class="fa-solid fa-arrow-right"></i>
                </a>
            </article>

            <article class="management-card">
                <div class="management-card-top">
                    <div class="large-icon applications-icon">
                        <i class="fa-solid fa-file-circle-check"></i>
                    </div>
                    <span class="section-tag">APPLICANT REVIEW</span>
                </div>

                <h3>Job Applications</h3>
                <p>Review applicant details, check submitted CV links, shortlist candidates, record administrative notes and update application statuses.</p>

                <div class="card-details">
                    <span><i class="fa-solid fa-hourglass-half"></i> Pending: <?= $pendingApplications ?></span>
                    <span><i class="fa-solid fa-user-check"></i> Shortlisted: <?= $shortlistedApplications ?></span>
                </div>

                <a href="job_application.php" class="action-button">
                    Review Applications
                    <i class="fa-solid fa-arrow-right"></i>
                </a>
            </article>

            <article class="management-card">
                <div class="management-card-top">
                    <div class="large-icon interviews-icon">
                        <i class="fa-solid fa-calendar-check"></i>
                    </div>
                    <span class="section-tag">CANDIDATE ASSESSMENT</span>
                </div>

                <h3>Interview Management</h3>
                <p>Schedule interviews, record venues and interview times, and update interview outcomes after the interview process.</p>

                <div class="card-details">
                    <span><i class="fa-solid fa-calendar-days"></i> Interview scheduling</span>
                    <span><i class="fa-solid fa-clipboard-check"></i> Record outcomes</span>
                </div>

                <a href="interviews.php" class="action-button">
                    Manage Interviews
                    <i class="fa-solid fa-arrow-right"></i>
                </a>
                <small class="coming-note">This section will work after we create the interview management page.</small>
            </article>

        </div>
    </section>

    <section class="workflow-note">
        <div class="workflow-icon">
            <i class="fa-solid fa-circle-info"></i>
        </div>
        <div>
            <h3>Recruitment workflow</h3>
            <p>Create and publish a vacancy, review applications, shortlist suitable candidates, schedule interviews and record the final decision.</p>
        </div>
    </section>

    <footer class="page-footer">
        &copy; <?= date("Y") ?> HandyLink Solution Management System
    </footer>

</main>

</body>
</html>