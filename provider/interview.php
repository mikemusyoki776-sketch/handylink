
<?php
session_start();

require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'provider') {
    header('Location: ../login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];

/* Get the logged-in provider's email */
$userStmt = $conn->prepare(
    "SELECT email FROM users WHERE id = ? AND role = 'provider' LIMIT 1"
);
$userStmt->bind_param("i", $userId);
$userStmt->execute();
$userResult = $userStmt->get_result();
$user = $userResult->fetch_assoc();
$userStmt->close();

if (!$user) {
    exit('Provider account not found.');
}

$email = $user['email'];

/* Count unread notifications for the bell */
$unreadCount = 0;

$notificationStmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM notifications
     WHERE user_id = ? AND is_read = 0"
);

if ($notificationStmt) {
    $notificationStmt->bind_param("i", $userId);
    $notificationStmt->execute();
    $notificationResult = $notificationStmt->get_result();
    $notificationRow = $notificationResult->fetch_assoc();
    $unreadCount = (int) ($notificationRow['total'] ?? 0);
    $notificationStmt->close();
}

/*
 * The interview table is assumed to be named `interviews`.
 * Interviews are linked to the provider through the job application email.
 */
$sql = "
    SELECT
        i.id,
        i.application_id,
        i.interview_date,
        i.interview_time,
        i.venue,
        i.interview_type,
        i.interviewer,
        i.status,
        i.outcome,
        i.interviewer_comments
    FROM interviews i
    INNER JOIN job_applications a
        ON a.id = i.application_id
    WHERE a.email = ?
    ORDER BY i.interview_date DESC, i.interview_time DESC
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    exit('Unable to load interviews. Check the interview table name and columns.');
}

$stmt->bind_param("s", $email);
$stmt->execute();
$interviews = $stmt->get_result();

function interviewEscape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function interviewStatusClass($status) {
    $status = strtolower(trim((string) $status));
    return preg_replace('/[^a-z0-9-]/', '', str_replace(' ', '-', $status));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Interview | HandyLink</title>

    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/provider_jobs.css?v=3">
    <link rel="stylesheet" href="../assets/css/provider_interview.css">

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>

<body class="jobs-page">

<header class="jobs-header">
    <div class="jobs-header-inner">

        <nav class="header-links">

            <a href="job_vacancies.php">
                <i class="fa-solid fa-briefcase"></i>
                Job Vacancies
            </a>

            <a href="job_application.php">
                <i class="fa-solid fa-file-lines"></i>
                Job Application
            </a>

            <a href="verification.php">
                <i class="fa-solid fa-shield-halved"></i>
                Verification
            </a>

            <a href="interview.php" class="active">
                <i class="fa-solid fa-calendar-check"></i>
                Interview
            </a>

            <a href="notification.php">
                <i class="fa-solid fa-bell"></i>
                Notifications
            </a>

        </nav>
    </div>
</header>
    <main class="interview-content">

        <div class="interview-heading">
            <div>
                <span class="interview-eyebrow">RECRUITMENT</span>
                <h1>My Interviews</h1>
                <p>
                    View your interview schedule, arrangements and
                    recruitment outcomes.
                </p>
            </div>
        </div>

        <div class="interview-information">
            <i class="fa-solid fa-circle-info"></i>
            <p>
                Interviews are arranged by the HandyLink administrator.
                Check the details below and monitor your notifications
                for any changes.
            </p>
        </div>

        <?php if ($interviews->num_rows === 0): ?>

            <section class="interview-empty">
                <div class="interview-empty-icon">
                    <i class="fa-regular fa-calendar-xmark"></i>
                </div>

                <h2>No Interviews Scheduled</h2>

                <p>
                    You currently have no interview arrangements.
                    Once an interview is scheduled for your application,
                    its details will appear here.
                </p>

                <a href="job_vacancies.php" class="interview-primary-button">
                    <i class="fa-solid fa-briefcase"></i>
                    View Job Vacancies
                </a>
            </section>

        <?php else: ?>

            <div class="interview-count">
                <i class="fa-regular fa-calendar-check"></i>
                Your interview records
                <span><?= $interviews->num_rows ?></span>
            </div>

            <div class="interview-list">

                <?php while ($interview = $interviews->fetch_assoc()): ?>

                    <article class="interview-card">

                        <div class="interview-card-header">

                            <div class="interview-card-icon">
                                <i class="fa-solid fa-calendar-check"></i>
                            </div>

                            <div class="interview-card-title">
                                <h2>Interview Details</h2>
                                <p>
                                    Application #<?= (int) $interview['application_id'] ?>
                                </p>
                            </div>

                            <span class="interview-status status-<?=
                                interviewEscape(
                                    interviewStatusClass($interview['status'])
                                )
                            ?>">
                                <?= interviewEscape($interview['status']) ?>
                            </span>

                        </div>

                        <div class="interview-details">

                            <div class="interview-detail">
                                <i class="fa-regular fa-calendar"></i>
                                <div>
                                    <span>Interview Date</span>
                                    <strong>
                                        <?= $interview['interview_date']
                                            ? interviewEscape(
                                                date(
                                                    'd M Y',
                                                    strtotime($interview['interview_date'])
                                                )
                                            )
                                            : 'Not provided' ?>
                                    </strong>
                                </div>
                            </div>

                            <div class="interview-detail">
                                <i class="fa-regular fa-clock"></i>
                                <div>
                                    <span>Interview Time</span>
                                    <strong>
                                        <?= $interview['interview_time']
                                            ? interviewEscape(
                                                date(
                                                    'h:i A',
                                                    strtotime($interview['interview_time'])
                                                )
                                            )
                                            : 'Not provided' ?>
                                    </strong>
                                </div>
                            </div>

                            <div class="interview-detail">
                                <i class="fa-solid fa-location-dot"></i>
                                <div>
                                    <span>Venue</span>
                                    <strong>
                                        <?= nl2br(interviewEscape($interview['venue'])) ?>
                                    </strong>
                                </div>
                            </div>

                            <div class="interview-detail">
                                <i class="fa-solid fa-video"></i>
                                <div>
                                    <span>Interview Type</span>
                                    <strong>
                                        <?= interviewEscape($interview['interview_type']) ?>
                                    </strong>
                                </div>
                            </div>

                            <div class="interview-detail">
                                <i class="fa-solid fa-user-tie"></i>
                                <div>
                                    <span>Interviewer</span>
                                    <strong>
                                        <?= interviewEscape(
                                            $interview['interviewer'] ?: 'Not specified'
                                        ) ?>
                                    </strong>
                                </div>
                            </div>

                            <div class="interview-detail">
                                <i class="fa-solid fa-clipboard-check"></i>
                                <div>
                                    <span>Outcome</span>
                                    <strong>
                                        <?= interviewEscape(
                                            $interview['outcome'] ?: 'Pending'
                                        ) ?>
                                    </strong>
                                </div>
                            </div>

                        </div>

                        <?php if (!empty($interview['interviewer_comments'])): ?>

                            <div class="interview-comments">
                                <h3>
                                    <i class="fa-regular fa-comment-dots"></i>
                                    Interviewer Comments
                                </h3>

                                <p><?= nl2br(
                                    interviewEscape($interview['interviewer_comments'])
                                ) ?></p>
                            </div>

                        <?php endif; ?>

                    </article>

                <?php endwhile; ?>

            </div>

        <?php endif; ?>

    </main>

    <footer class="interview-footer">
        &copy; <?= date('Y') ?> HandyLink. All rights reserved.
    </footer>

</div>

</body>
</html>

<?php
$stmt->close();
?>
