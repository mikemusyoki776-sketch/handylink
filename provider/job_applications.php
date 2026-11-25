
<?php
session_start();
$vacancyId = null;

if (
    empty($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'provider'
) {
    header('Location: /handylink/provider/job_vacancies.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';

function e($value)
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$userId = (int) $_SESSION['user_id'];

$errors = [];
$success = '';
$vacancy = null;
$user = [];
$applications = [];
$providerId = 0;

/*
 * Get the vacancy ID from the URL when opening the application page.
 */
$vacancyId = filter_input(
    INPUT_GET,
    'vacancy_id',
    FILTER_VALIDATE_INT
);

/*
 * On form submission, use the vacancy ID submitted by the form.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vacancyId = filter_var(
        $_POST['vacancy_id'] ?? null,
        FILTER_VALIDATE_INT
    );
}

try {
    /*
     * Load the logged-in provider's account details.
     */
    $stmt = $conn->prepare("
        SELECT full_name, email, phone
        FROM users
        WHERE id = ?
          AND role = 'provider'
        LIMIT 1
    ");

    $stmt->bind_param('i', $userId);
    $stmt->execute();

    $user = $stmt->get_result()->fetch_assoc() ?: [];

    $stmt->close();

    if (!$user) {
        throw new RuntimeException('Your provider account could not be found.');
    }

    /*
     * Get the provider ID belonging to the logged-in user.
     */
    $stmt = $conn->prepare("
        SELECT id
        FROM providers
        WHERE user_id = ?
        LIMIT 1
    ");

    $stmt->bind_param('i', $userId);
    $stmt->execute();

    $provider = $stmt->get_result()->fetch_assoc();

    $stmt->close();

    if (!$provider) {
        throw new RuntimeException('Your provider profile could not be found.');
    }

    $providerId = (int) $provider['id'];

    /*
     * Create a CSRF token.
     */
    if (empty($_SESSION['job_applications_csrf'])) {
        $_SESSION['job_applications_csrf'] = bin2hex(random_bytes(32));
    }

    /*
     * Load the selected vacancy.
     * Unapproved providers may access this allowed recruitment page.
     */
    if ($vacancyId) {
        $stmt = $conn->prepare("
            SELECT
                id,
                job_title,
                department,
                job_description,
                requirements,
                employment_type,
                location,
                application_deadline
            FROM job_vacancies
            WHERE id = ?
              AND LOWER(status) IN ('open', 'active')
              AND (
                    application_deadline IS NULL
                    OR application_deadline >= CURDATE()
                  )
            LIMIT 1
        ");

        $stmt->bind_param('i', $vacancyId);
        $stmt->execute();

        $vacancy = $stmt->get_result()->fetch_assoc();

        $stmt->close();
    }

    /*
     * Process the application form.
     */
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';

        if (
            !is_string($token) ||
            !hash_equals($_SESSION['job_applications_csrf'], $token)
        ) {
            $errors[] = 'Your session validation failed. Refresh the page and try again.';
        }

        if (!$vacancyId || !$vacancy) {
            $errors[] = 'This vacancy is unavailable or its application deadline has passed.';
        }

        $applicantName = trim($_POST['applicant_name'] ?? '');
        $email = trim($user['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $education = trim($_POST['education_level'] ?? '');
        $qualifications = trim($_POST['qualifications'] ?? '');
        $experience = trim($_POST['experience'] ?? '');

        if ($applicantName === '') {
            $errors[] = 'Enter your full name.';
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Your account email address is invalid.';
        }

        if ($phone === '') {
            $errors[] = 'Enter your phone number.';
        }

        if ($education === '') {
            $errors[] = 'Enter your education level.';
        }

        if ($qualifications === '') {
            $errors[] = 'Describe your qualifications and skills.';
        }

        if ($experience === '') {
            $errors[] = 'Describe your work experience or state that you are a beginner.';
        }

        if (
            strlen($applicantName) > 255 ||
            strlen($email) > 255 ||
            strlen($phone) > 50
        ) {
            $errors[] = 'One or more fields exceed the allowed length.';
        }

        /*
         * Prevent duplicate applications to the same vacancy.
         * Applications are identified using provider ID and vacancy ID.
         */
        if (!$errors) {
            $stmt = $conn->prepare("
                SELECT id
                FROM job_applications
                WHERE vacancy_id = ?
                  AND provider_id = ?
                LIMIT 1
            ");

            $stmt->bind_param('ii', $vacancyId, $providerId);
            $stmt->execute();

            $alreadyApplied = $stmt->get_result()->num_rows > 0;

            $stmt->close();

            if ($alreadyApplied) {
                $errors[] = 'You have already applied for this vacancy.';
            }
        }

        /*
         * Validate and upload the CV.
         */
        $cvRelativePath = '';

        if (!$errors) {
            if (
                !isset($_FILES['cv_file']) ||
                $_FILES['cv_file']['error'] !== UPLOAD_ERR_OK
            ) {
                $errors[] = 'Upload your CV in PDF, DOC or DOCX format.';
            } else {
                $file = $_FILES['cv_file'];

                if ($file['size'] > 5 * 1024 * 1024) {
                    $errors[] = 'Your CV must not exceed 5 MB.';
                }

                $originalName = $file['name'] ?? '';

                $extension = strtolower(
                    pathinfo($originalName, PATHINFO_EXTENSION)
                );

                $allowedExtensions = ['pdf', 'doc', 'docx'];

                if (!in_array($extension, $allowedExtensions, true)) {
                    $errors[] = 'Only PDF, DOC and DOCX files are allowed.';
                }

                if (
                    !$errors &&
                    !is_uploaded_file($file['tmp_name'])
                ) {
                    $errors[] = 'The uploaded CV is invalid. Please try again.';
                }

                if (!$errors) {
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mime = $finfo->file($file['tmp_name']);

                    $allowedMimes = [
                        'pdf' => [
                            'application/pdf'
                        ],
                        'doc' => [
                            'application/msword',
                            'application/octet-stream'
                        ],
                        'docx' => [
                            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                            'application/zip',
                            'application/octet-stream'
                        ]
                    ];

                    if (!in_array($mime, $allowedMimes[$extension], true)) {
                        $errors[] = 'The uploaded file does not match its extension.';
                    }
                }

                if (!$errors) {
                    $uploadDirectory = __DIR__ . '/../uploads/job_applications';

                    if (
                        !is_dir($uploadDirectory) &&
                        !mkdir($uploadDirectory, 0750, true) &&
                        !is_dir($uploadDirectory)
                    ) {
                        $errors[] = 'The CV upload folder could not be created.';
                    } else {
                        $fileName = bin2hex(random_bytes(16)) . '.' . $extension;

                        $destination = $uploadDirectory . '/' . $fileName;

                        if (!move_uploaded_file($file['tmp_name'], $destination)) {
                            $errors[] = 'Your CV could not be saved. Please try again.';
                        } else {
                            $cvRelativePath = 'uploads/job_applications/' . $fileName;
                        }
                    }
                }
            }
        }

        /*
         * Save the application with the provider ID.
         */
        if (!$errors) {
            try {
                $stmt = $conn->prepare("
                    INSERT INTO job_applications
                    (
                        vacancy_id,
                        provider_id,
                        applicant_name,
                        email,
                        phone,
                        education_level,
                        qualifications,
                        experience,
                        cv_file,
                        application_date,
                        status
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'Pending')
                ");

                $stmt->bind_param(
                    'iisssssss',
                    $vacancyId,
                    $providerId,
                    $applicantName,
                    $email,
                    $phone,
                    $education,
                    $qualifications,
                    $experience,
                    $cvRelativePath
                );

                $stmt->execute();
                $stmt->close();

                unset($_SESSION['job_applications_csrf']);

                header('Location: /handylink/provider/job_applications.php?submitted=1');
                exit;

            } catch (Throwable $exception) {
                if ($cvRelativePath !== '') {
                    $savedFile = __DIR__ . '/../' . $cvRelativePath;

                    if (is_file($savedFile)) {
                        unlink($savedFile);
                    }
                }

                throw $exception;
            }
        }
    }

    /*
     * Show submission confirmation.
     */
    if (
        isset($_GET['submitted']) &&
        $_GET['submitted'] === '1'
    ) {
        $success = 'Your application was submitted successfully.';
    }

    /*
     * Load applications belonging to the logged-in provider.
     */
    $stmt = $conn->prepare("
        SELECT
            ja.id,
            ja.vacancy_id,
            ja.applicant_name,
            ja.application_date,
            ja.status,
            ja.admin_notes,
            jv.job_title,
            jv.department
        FROM job_applications ja
        INNER JOIN job_vacancies jv
            ON jv.id = ja.vacancy_id
        WHERE ja.provider_id = ?
        ORDER BY ja.application_date DESC
    ");

    $stmt->bind_param('i', $providerId);
    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $applications[] = $row;
    }

    $stmt->close();

} catch (Throwable $exception) {
    error_log(
        'HandyLink job applications error: ' .
        $exception->getMessage()
    );

    if (!$errors) {
        $errors[] = 'Something went wrong while loading or submitting your application.';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Job Application | HandyLink</title>

    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/provider_jobs.css?v=3">

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

            <a href="job_applications.php" class="active">
                <i class="fa-solid fa-file-lines"></i>
                Job Application
            </a>

            <a href="verification.php">
                <i class="fa-solid fa-shield-halved"></i>
                Verification
            </a>

            <a href="interview.php">
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

<section class="jobs-hero application-hero">

    <div class="jobs-hero-icon">
        <i class="fa-solid fa-file-signature"></i>
    </div>

    <h1>Job Application</h1>

    <p>
        Apply for available opportunities and track your application status.
    </p>

</section>

<main class="jobs-container application-container">

    <?php if ($success !== ''): ?>
        <div class="jobs-notice success-notice" role="status">
            <i class="fa-solid fa-circle-check"></i>
            <?= e($success) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="jobs-notice error-notice" role="alert">

            <i class="fa-solid fa-triangle-exclamation"></i>

            <div>
                <strong>Please check the following:</strong>

                <ul>
                    <?php foreach ($errors as $errorMessage): ?>
                        <li><?= e($errorMessage) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>

        </div>
    <?php endif; ?>

    <?php if ($vacancy): ?>

        <section class="application-form-card">

            <div class="application-section-heading">

                <div class="job-icon">
                    <i class="fa-solid fa-briefcase"></i>
                </div>

                <div>
                    <h2>Apply for <?= e($vacancy['job_title']) ?></h2>

                    <p>
                        <?= e($vacancy['department'] ?: 'General') ?>
                        &middot;
                        <?= e($vacancy['employment_type'] ?: 'Employment type not specified') ?>
                    </p>
                </div>

            </div>

            <?php if (!empty($vacancy['job_description'])): ?>
                <div class="vacancy-information">
                    <h3>Job Description</h3>
                    <p><?= nl2br(e($vacancy['job_description'])) ?></p>
                </div>
            <?php endif; ?>

            <?php if (!empty($vacancy['requirements'])): ?>
                <div class="vacancy-information">
                    <h3>Requirements</h3>
                    <p><?= nl2br(e($vacancy['requirements'])) ?></p>
                </div>
            <?php endif; ?>

            <form
                method="POST"
                action="/handylink/provider/job_applications.php?vacancy_id=<?= (int) $vacancy['id'] ?>"
                enctype="multipart/form-data"
                class="job-applications-form">

                <input
                    type="hidden"
                    name="vacancy_id"
                    value="<?= (int) $vacancy['id'] ?>">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($_SESSION['job_applications_csrf'] ?? '') ?>">

                <div class="form-grid">

                    <div class="field">
                        <label for="applicant_name">Full Name *</label>

                        <input
                            type="text"
                            id="applicant_name"
                            name="applicant_name"
                            value="<?= e($_POST['applicant_name'] ?? $user['full_name']) ?>"
                            maxlength="255"
                            required>
                    </div>

                    <div class="field">
                        <label for="email">Account Email</label>

                        <input
                            type="email"
                            id="email"
                            value="<?= e($user['email']) ?>"
                            readonly>

                        <small>
                            Your application will be linked to this email.
                        </small>
                    </div>

                    <div class="field">
                        <label for="phone">Phone Number *</label>

                        <input
                            type="tel"
                            id="phone"
                            name="phone"
                            value="<?= e($_POST['phone'] ?? $user['phone'] ?? '') ?>"
                            maxlength="50"
                            required>
                    </div>

                    <div class="field">
                        <label for="education_level">Education Level *</label>

                        <input
                            type="text"
                            id="education_level"
                            name="education_level"
                            value="<?= e($_POST['education_level'] ?? '') ?>"
                            placeholder="e.g. Diploma or Certificate"
                            required>
                    </div>

                    <div class="field full-field">
                        <label for="qualifications">
                            Qualifications and Skills *
                        </label>

                        <textarea
                            id="qualifications"
                            name="qualifications"
                            rows="4"
                            required
                            placeholder="Describe your relevant skills and qualifications"><?= e($_POST['qualifications'] ?? '') ?></textarea>
                    </div>

                    <div class="field full-field">
                        <label for="experience">Work Experience *</label>

                        <textarea
                            id="experience"
                            name="experience"
                            rows="4"
                            required
                            placeholder="Describe your experience, or state that you are a beginner"><?= e($_POST['experience'] ?? '') ?></textarea>
                    </div>

                    <div class="field full-field">
                        <label for="cv_file">
                            Upload CV (PDF, DOC or DOCX; maximum 5 MB) *
                        </label>

                        <input
                            type="file"
                            id="cv_file"
                            name="cv_file"
                            accept=".pdf,.doc,.docx"
                            required>
                    </div>

                </div>

                <div class="form-actions">

                    <a href="job_vacancies.php" class="reset-button">
                        <i class="fa-solid fa-arrow-left"></i>
                        Back to Vacancies
                    </a>

                    <button type="submit" class="search-button">
                        <i class="fa-solid fa-paper-plane"></i>
                        Submit Application
                    </button>

                </div>

            </form>

        </section>

    <?php elseif (isset($_GET['vacancy_id'])): ?>

        <section class="empty-state">

            <i class="fa-solid fa-circle-exclamation"></i>

            <h3>Vacancy unavailable</h3>

            <p>
                This vacancy may have closed or passed its application deadline.
            </p>

            <a href="job_vacancies.php" class="apply-button">
                Browse Vacancies
            </a>

        </section>

    <?php endif; ?>

    <section class="my-applications-section" id="my-applications">

        <div class="jobs-heading">

            <div>
                <h2>
                    <i class="fa-solid fa-folder-open"></i>
                    My Applications
                </h2>

                <p>
                    Review the vacancies you have applied for.
                </p>
            </div>

            <a href="job_vacancies.php" class="search-button">
                <i class="fa-solid fa-magnifying-glass"></i>
                Browse Jobs
            </a>

        </div>

        <?php if (empty($applications)): ?>

            <div class="empty-state">

                <i class="fa-solid fa-file-circle-question"></i>

                <h3>No applications yet</h3>

                <p>
                    Your submitted job applications will appear here.
                </p>

                <a href="job_vacancies.php" class="apply-button">
                    Find Vacancies
                </a>

            </div>

        <?php else: ?>

            <div class="applications-table-wrapper">

                <table class="applications-table">

                    <thead>
                        <tr>
                            <th>Job Title</th>
                            <th>Department</th>
                            <th>Date Applied</th>
                            <th>Status</th>
                            <th>Admin Notes</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($applications as $application): ?>

                            <tr>

                                <td>
                                    <?= e($application['job_title']) ?>
                                </td>

                                <td>
                                    <?= e($application['department'] ?: 'General') ?>
                                </td>

                                <td>
                                    <?=
                                        !empty($application['application_date'])
                                        ? e(date(
                                            'd M Y',
                                            strtotime($application['application_date'])
                                        ))
                                        : '—'
                                    ?>
                                </td>

                                <td>
                                    <span class="application-status status-<?=
                                        e(strtolower(
                                            preg_replace(
                                                '/[^a-zA-Z0-9]+/',
                                                '-',
                                                $application['status'] ?? 'pending'
                                            )
                                        ))
                                    ?>">
                                        <?= e($application['status'] ?: 'Pending') ?>
                                    </span>
                                </td>

                                <td>
                                    <?= e($application['admin_notes'] ?: 'No notes yet') ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </section>

</main>

<footer class="jobs-footer">
    <p>
        &copy; <?= date('Y') ?> HandyLink. Connecting people with opportunities.
    </p>
</footer>

</body>
</html>
