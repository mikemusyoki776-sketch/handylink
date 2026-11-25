<?php
session_start();
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'provider') {
    header('Location: /handylink/login.php');
    exit;
}

function h($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$search = trim($_GET['search'] ?? '');
$department = trim($_GET['department'] ?? '');
$employmentType = trim($_GET['employment_type'] ?? '');
$location = trim($_GET['location'] ?? '');

$vacancies = [];
$departments = [];
$employmentTypes = [];
$error = '';

try {
    $sql = "SELECT id, job_title, department, job_description,
                   requirements, employment_type, location,
                   application_deadline, created_at
            FROM job_vacancies
            WHERE LOWER(status) IN ('open', 'active')
              AND (
                    application_deadline IS NULL
                    OR application_deadline >= CURDATE()
                  )";

    $params = [];
    $types = '';

    if ($search !== '') {
        $sql .= " AND (
                    job_title LIKE ?
                    OR department LIKE ?
                    OR job_description LIKE ?
                    OR requirements LIKE ?
                  )";

        $term = "%{$search}%";

        array_push(
            $params,
            $term,
            $term,
            $term,
            $term
        );

        $types .= 'ssss';
    }

    if ($department !== '') {
        $sql .= " AND department = ?";
        $params[] = $department;
        $types .= 's';
    }

    if ($employmentType !== '') {
        $sql .= " AND employment_type = ?";
        $params[] = $employmentType;
        $types .= 's';
    }

    if ($location !== '') {
        $sql .= " AND location LIKE ?";
        $params[] = "%{$location}%";
        $types .= 's';
    }

    $sql .= " ORDER BY created_at DESC, id DESC";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new Exception('Could not prepare vacancy query.');
    }

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $vacancies[] = $row;
    }

    $stmt->close();

    $filterResult = $conn->query(
        "SELECT DISTINCT department, employment_type
         FROM job_vacancies
         WHERE LOWER(status) IN ('open', 'active')
           AND (
                application_deadline IS NULL
                OR application_deadline >= CURDATE()
           )"
    );

    if ($filterResult) {
        while ($row = $filterResult->fetch_assoc()) {
            if (!empty($row['department'])) {
                $departments[] = $row['department'];
            }

            if (!empty($row['employment_type'])) {
                $employmentTypes[] = $row['employment_type'];
            }
        }
    }

    $departments = array_unique($departments);
    $employmentTypes = array_unique($employmentTypes);

    sort($departments);
    sort($employmentTypes);

} catch (Throwable $e) {
    $error = 'Unable to load vacancies. Please check the database connection and try again.';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Job Vacancies | HandyLink</title>

    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/provider_jobs.css">

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>

<body class="jobs-page">

<header class="jobs-header">
    <div class="jobs-header-inner">

        <a href="/handylink/provider/job_vacancies.php" class="brand">
            <img src="../assets/images/handylink-logo.png"
                 alt="HandyLink logo">
            <span>HandyLink</span>
        </a>

        <nav class="header-links">

            <a class="active"
               href="/handylink/provider/job_vacancies.php">
                <i class="fa-solid fa-briefcase"></i>
                Job Vacancies
            </a>

            <a href="/handylink/provider/job_applications.php">
                <i class="fa-solid fa-file-lines"></i>
                Job Application
            </a>

            <a href="/handylink/provider/verification.php">
                <i class="fa-solid fa-shield-halved"></i>
                Verification
            </a>

            <a href="/handylink/provider/interview.php">
                <i class="fa-solid fa-calendar-check"></i>
                Interview
            </a>

            <a href="/handylink/provider/notification.php">
                <i class="fa-solid fa-bell"></i>
                Notifications
            </a>

        </nav>
    </div>
</header>

<section class="jobs-hero">
    <div class="jobs-hero-content">

        <span class="hero-label">
            <i class="fa-solid fa-star"></i>
            Grow with HandyLink
        </span>

        <h1>Find Your Next Opportunity</h1>

        <p>
            Explore available vacancies and discover opportunities
            that match your skills and qualifications.
        </p>

    </div>
</section>

<section class="search-panel">
    <form method="GET"
          action="/handylink/provider/job_vacancies.php">

        <div class="search-grid">

            <div class="field">
                <label for="search">Search vacancies</label>

                <div class="input-with-icon">
                    <i class="fa-solid fa-magnifying-glass"></i>

                    <input
                        id="search"
                        name="search"
                        type="text"
                        placeholder="Job title or keywords"
                        value="<?= h($search) ?>">
                </div>
            </div>

            <div class="field">
                <label for="department">Department</label>

                <select id="department" name="department">
                    <option value="">All departments</option>

                    <?php foreach ($departments as $item): ?>
                        <option
                            value="<?= h($item) ?>"
                            <?= $department === $item ? 'selected' : '' ?>>
                            <?= h($item) ?>
                        </option>
                    <?php endforeach; ?>

                </select>
            </div>

            <div class="field">
                <label for="employment_type">Employment type</label>

                <select id="employment_type" name="employment_type">
                    <option value="">All types</option>

                    <?php foreach ($employmentTypes as $item): ?>
                        <option
                            value="<?= h($item) ?>"
                            <?= $employmentType === $item ? 'selected' : '' ?>>
                            <?= h($item) ?>
                        </option>
                    <?php endforeach; ?>

                </select>
            </div>

            <div class="field">
                <label for="location">Location</label>

                <input
                    id="location"
                    name="location"
                    type="text"
                    placeholder="Enter location"
                    value="<?= h($location) ?>">
            </div>

            <div class="search-actions">

                <button type="submit" class="search-button">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    Search
                </button>

                <a class="reset-button"
                   href="/handylink/provider/job_vacancies.php">
                    Reset
                </a>

            </div>

        </div>
    </form>
</section>

<main class="jobs-container">

    <div class="jobs-heading">
        <div>
            <h2>Available Vacancies</h2>
            <p>Review the details before applying.</p>
        </div>

        <span class="jobs-count">
            <?= count($vacancies) ?>
            <?= count($vacancies) === 1 ? 'vacancy' : 'vacancies' ?>
        </span>
    </div>

    <?php if ($error): ?>

        <div class="error-state">
            <i class="fa-solid fa-circle-exclamation"></i>

            <h3>Unable to load vacancies</h3>

            <p><?= h($error) ?></p>
        </div>

    <?php elseif (empty($vacancies)): ?>

        <div class="empty-state">

            <div class="empty-icon">
                <i class="fa-solid fa-briefcase"></i>
            </div>

            <h3>No vacancies found</h3>

            <p>
                There are no matching open vacancies.
                Try changing your filters or check again later.
            </p>

            <a class="reset-button"
               href="/handylink/provider/job_vacancies.php">
                View All Vacancies
            </a>

        </div>

    <?php else: ?>

        <div class="jobs-grid">

            <?php foreach ($vacancies as $vacancy): ?>

                <article class="job-card">

                    <div class="job-card-top">

                        <div class="job-icon">
                            <i class="fa-solid fa-briefcase"></i>
                        </div>

                        <span class="job-status">Open</span>

                    </div>

                    <h3 class="job-title">
                        <?= h($vacancy['job_title']) ?>
                    </h3>

                    <p class="job-department">
                        <i class="fa-solid fa-building"></i>
                        <?= h($vacancy['department'] ?: 'General') ?>
                    </p>

                    <p class="job-description">
                        <?php
                        $description = trim(
                            strip_tags($vacancy['job_description'] ?? '')
                        );

                        echo h(
                            strlen($description) > 180
                                ? substr($description, 0, 180) . '...'
                                : $description
                        );
                        ?>
                    </p>

                    <div class="job-meta">

                        <span>
                            <i class="fa-solid fa-clock"></i>
                            <?= h($vacancy['employment_type'] ?: 'Not specified') ?>
                        </span>

                        <span>
                            <i class="fa-solid fa-location-dot"></i>
                            <?= h($vacancy['location'] ?: 'Not specified') ?>
                        </span>

                    </div>

                    <?php if (!empty($vacancy['requirements'])): ?>

                        <div class="job-requirements">

                            <strong>
                                <i class="fa-solid fa-list-check"></i>
                                Requirements
                            </strong>

                            <p>
                                <?php
                                $requirements = trim(
                                    strip_tags($vacancy['requirements'])
                                );

                                echo h(
                                    strlen($requirements) > 120
                                        ? substr($requirements, 0, 120) . '...'
                                        : $requirements
                                );
                                ?>
                            </p>

                        </div>

                    <?php endif; ?>

                    <div class="job-deadline">

                        <i class="fa-regular fa-calendar"></i>

                        <span>
                            Deadline:

                            <strong>
                                <?=
                                    !empty($vacancy['application_deadline'])
                                    ? h(date(
                                        'd M Y',
                                        strtotime($vacancy['application_deadline'])
                                    ))
                                    : 'Not specified'
                                ?>
                            </strong>
                        </span>

                    </div>

                    <div class="job-actions">

                

                    <a href="job_applications.php?vacancy_id=<?= (int) $vacancy['id'] ?>">
                     Apply Now
                     </a>
                           

                    </div>

                </article>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</main>

<footer class="jobs-footer">
    <p>
        &copy; <?= date('Y') ?> HandyLink. All rights reserved.
    </p>
</footer>

</body>
</html>