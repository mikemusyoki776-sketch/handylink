
<?php
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once __DIR__ . '/access_control.php';

requireVerificationAccess();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$user_id = (int) $_SESSION['user_id'];

if (empty($_SESSION['verification_csrf'])) {
    $_SESSION['verification_csrf'] = bin2hex(random_bytes(32));
}

$errors = [];
$success_message = '';
$document_type_value = '';
$document_number_value = '';

function verificationEscape($value)
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function verificationStatusClass($status)
{
    return preg_replace(
        '/[^a-z0-9\_-]/',
        '',
        strtolower(trim((string) $status))
    );
}

/* Get provider account details. */
$userQuery = $conn->prepare("
    SELECT full_name, email, profile_image, status
    FROM users
    WHERE id = ? AND role = 'provider'
    LIMIT 1
");

$userQuery->bind_param('i', $user_id);
$userQuery->execute();
$user = $userQuery->get_result()->fetch_assoc();
$userQuery->close();

if (!$user || strtolower(trim($user['status'] ?? '')) !== 'active') {
    session_destroy();
    header('Location: ../login.php');
    exit();
}

$fullName = $user['full_name'] ?? 'Provider';
$firstName = explode(' ', trim($fullName))[0] ?: 'Provider';
$email = $user['email'] ?? '';
$profileImage = $user['profile_image'] ?? '';
$profileInitial = strtoupper(substr($fullName, 0, 1));

/* Get provider information. */
$stmt = $conn->prepare("
    SELECT id, verification_status
    FROM providers
    WHERE user_id = ?
    LIMIT 1
");

$stmt->bind_param('i', $user_id);
$stmt->execute();
$provider = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$provider) {
    http_response_code(403);
    exit('Your provider record could not be found. Please contact the administrator.');
}

$provider_id = (int) $provider['id'];
$provider_status = strtolower(trim($provider['verification_status'] ?? 'pending'));

$status_labels = [
    'pending' => 'Pending Review',
    'approved' => 'Approved',
    'rejected' => 'Rejected'
];

$document_types = [
    'National ID',
    'Passport',
    'Professional Certificate',
    'Academic Certificate',
    'Good Conduct Certificate',
    'Other'
];

/* Count unread messages for the navigation badge. */
$unreadMessages = 0;

$stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM messages
    WHERE receiver_id = ? AND is_read = 0
");

$stmt->bind_param('i', $user_id);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$unreadMessages = (int) ($result['total'] ?? 0);
$stmt->close();

/* Count unread notifications. */
$unreadNotifications = 0;

$tableResult = $conn->query("SHOW TABLES LIKE 'notifications'");

if ($tableResult && $tableResult->num_rows > 0) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM notifications
        WHERE user_id = ? AND is_read = 0
    ");

    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $unreadNotifications = (int) ($result['total'] ?? 0);
    $stmt->close();
}

/* Process verification document submission. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $document_type_value = trim($_POST['document_type'] ?? '');
    $document_number_value = trim($_POST['document_number'] ?? '');
    $csrf_token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($csrf_token) ||
        !hash_equals($_SESSION['verification_csrf'], $csrf_token)
    ) {
        $errors[] = 'Your session could not be verified. Refresh the page and try again.';
    }

    if ($provider_status === 'approved') {
        $errors[] = 'Your provider account is already approved. You do not need to submit verification documents again.';
    }

    if (!in_array($document_type_value, $document_types, true)) {
        $errors[] = 'Please select a valid document type.';
    }

    if (strlen($document_number_value) > 100) {
        $errors[] = 'The document number must not exceed 100 characters.';
    }

    if ($document_number_value === '') {
        $document_number_value = null;
    }

    $file = $_FILES['document_file'] ?? null;

    $allowed_extensions = ['pdf', 'jpg', 'jpeg', 'png'];

    $allowed_mime_types = [
        'application/pdf',
        'image/jpeg',
        'image/png'
    ];

    if (
        !$file ||
        !isset($file['error']) ||
        $file['error'] === UPLOAD_ERR_NO_FILE
    ) {
        $errors[] = 'Please select a document to upload.';
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'The file could not be uploaded. Please try again.';
    } elseif ($file['size'] > 5 * 1024 * 1024) {
        $errors[] = 'The document must not exceed 5 MB.';
    }

    $extension = '';
    $mime_type = '';

    if (
        $file &&
        isset($file['error']) &&
        $file['error'] === UPLOAD_ERR_OK &&
        is_uploaded_file($file['tmp_name'])
    ) {
        $extension = strtolower(
            pathinfo($file['name'], PATHINFO_EXTENSION)
        );

        if (!in_array($extension, $allowed_extensions, true)) {
            $errors[] = 'Only PDF, JPG, JPEG and PNG files are allowed.';
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime_type = $finfo->file($file['tmp_name']);

        if (!in_array($mime_type, $allowed_mime_types, true)) {
            $errors[] = 'The uploaded file does not appear to be a valid PDF, JPG or PNG.';
        }

        if (
            ($extension === 'pdf' && $mime_type !== 'application/pdf') ||
            (in_array($extension, ['jpg', 'jpeg'], true) && $mime_type !== 'image/jpeg') ||
            ($extension === 'png' && $mime_type !== 'image/png')
        ) {
            $errors[] = 'The file extension does not match the uploaded document.';
        }

    } elseif (empty($errors)) {
        $errors[] = 'Please upload a valid document file.';
    }

    $upload_directory = dirname(__DIR__) . '/uploads/verification';

    if (empty($errors)) {
        if (
            !is_dir($upload_directory) &&
            !mkdir($upload_directory, 0750, true) &&
            !is_dir($upload_directory)
        ) {
            $errors[] = 'The upload directory could not be created. Please contact the administrator.';
        }
    }

    if (empty($errors)) {

        $destination = null;
        $transaction_started = false;

        try {
            $stored_filename = bin2hex(random_bytes(20)) . '.' . $extension;
            $destination = $upload_directory . '/' . $stored_filename;
            $database_file_path = 'uploads/verification/' . $stored_filename;

            if (!move_uploaded_file($file['tmp_name'], $destination)) {
                throw new Exception('The uploaded document could not be saved.');
            }

            $conn->begin_transaction();
            $transaction_started = true;

            /* Insert the submitted document. */
            $document_stmt = $conn->prepare("
                INSERT INTO verification (
                    provider_id,
                    document_type,
                    document_number,
                    document_file,
                    status
                )
                VALUES (?, ?, ?, ?, 'pending')
            ");

            $document_stmt->bind_param(
                'isss',
                $provider_id,
                $document_type_value,
                $document_number_value,
                $database_file_path
            );

            $document_stmt->execute();

            if ($document_stmt->affected_rows !== 1) {
                throw new Exception('The verification document was not inserted.');
            }

            $document_stmt->close();

            /* Keep the provider pending until administrator review. */
            $provider_stmt = $conn->prepare("
                UPDATE providers
                SET verification_status = 'pending'
                WHERE id = ?
                AND verification_status <> 'approved'
            ");

            $provider_stmt->bind_param('i', $provider_id);
            $provider_stmt->execute();
            $provider_stmt->close();

            $conn->commit();
            $transaction_started = false;

            unset($_SESSION['verification_csrf']);

            header('Location: verification.php?submitted=1');
            exit();

        } catch (Throwable $exception) {

            if ($transaction_started) {
                $conn->rollback();
            }

            if ($destination && is_file($destination)) {
                unlink($destination);
            }

            error_log(
                'HandyLink verification error: ' . $exception->getMessage()
            );

            $errors[] = 'The document could not be submitted. Please try again.';
        }
    }
}

/* Show confirmation after successful submission. */
if (isset($_GET['submitted']) && $_GET['submitted'] === '1') {
    $success_message = 'Your verification document has been submitted successfully. The administrator will review it.';
}

/* Refresh provider status. */
$stmt = $conn->prepare("
    SELECT verification_status
    FROM providers
    WHERE id = ?
    LIMIT 1
");

$stmt->bind_param('i', $provider_id);
$stmt->execute();
$status_result = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($status_result) {
    $provider_status = strtolower(
        trim($status_result['verification_status'] ?? 'pending')
    );
}

/* Retrieve submitted documents and administrator feedback. */
$documents = [];

$stmt = $conn->prepare("
    SELECT
        id,
        document_type,
        document_number,
        document_file,
        status,
        admin_comment,
        submitted_at,
        reviewed_at
    FROM verification
    WHERE provider_id = ?
    ORDER BY id DESC
");

$stmt->bind_param('i', $provider_id);
$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $documents[] = $row;
}

$stmt->close();

$status_label = $status_labels[$provider_status]
    ?? ucfirst(str_replace('_', ' ', $provider_status));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Verification | HandyLink</title>

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <link rel="stylesheet" href="../assets/css/provider_dashboard.css">
    <link rel="stylesheet" href="../assets/css/provider_verification.css">
</head>

<body>

<header class="provider-header">

    <div class="header-left">
        <a href="<?= $provider_status === 'approved' ? 'dashboard.php' : 'verification.php' ?>"
           class="brand">
            <img src="../assets/images/handylink-logo.png"
                 alt="HandyLink Logo"
                 class="brand-logo">
            <span>HandyLink</span>
        </a>

        <button class="mobile-menu-toggle"
                type="button"
                id="mobileMenuToggle"
                aria-label="Toggle navigation"
                aria-expanded="false">
            <i class="fas fa-bars"></i>
        </button>
    </div>

    <nav class="main-nav" id="mainNav">

        <?php if ($provider_status === 'approved'): ?>

            <a href="dashboard.php">
                <i class="fas fa-house"></i>
                <span>Dashboard</span>
            </a>

            <a href="verification.php" class="active">
                <i class="fas fa-shield-halved"></i>
                <span>Verification</span>
            </a>

            <a href="services.php">
                <i class="fas fa-screwdriver-wrench"></i>
                <span>My Services</span>
            </a>

            <a href="requests.php">
                <i class="fas fa-clipboard-list"></i>
                <span>Requests</span>
            </a>

            <a href="bookings.php">
                <i class="fas fa-calendar-check"></i>
                <span>Bookings</span>
            </a>

            <a href="availability.php">
                <i class="fas fa-calendar-days"></i>
                <span>Availability</span>
            </a>

            <a href="review.php">
                <i class="fas fa-star"></i>
                <span>Reviews</span>
            </a>

            <a href="messages.php">
                <i class="fas fa-envelope"></i>
                <span>Messages</span>

                <?php if ($unreadMessages > 0): ?>
                    <span class="nav-badge">
                        <?= $unreadMessages > 99 ? '99+' : $unreadMessages ?>
                    </span>
                <?php endif; ?>
            </a>

            <a href="notification.php">
                <i class="fas fa-bell"></i>
                <span>Notifications</span>

                <?php if ($unreadNotifications > 0): ?>
                    <span class="nav-badge">
                        <?= $unreadNotifications > 99 ? '99+' : $unreadNotifications ?>
                    </span>
                <?php endif; ?>
            </a>

        <?php else: ?>

            <a href="job_vacancies.php">
                <i class="fas fa-briefcase"></i>
                <span>Job Vacancies</span>
            </a>

            <a href="job_applications.php">
                <i class="fas fa-file-lines"></i>
                <span>Job Application</span>
            </a>

            <a href="verification.php" class="active">
                <i class="fas fa-shield-halved"></i>
                <span>Verification</span>
            </a>

            <a href="interview.php">
                <i class="fas fa-calendar-check"></i>
                <span>Interview</span>
            </a>

            <a href="notification.php">
                <i class="fas fa-bell"></i>
                <span>Notifications</span>

                <?php if ($unreadNotifications > 0): ?>
                    <span class="nav-badge">
                        <?= $unreadNotifications > 99 ? '99+' : $unreadNotifications ?>
                    </span>
                <?php endif; ?>
            </a>

        <?php endif; ?>

    </nav>

    <div class="header-right">
        <div class="profile-menu">

            <button type="button"
                    class="profile-trigger"
                    id="profileTrigger"
                    aria-expanded="false">

                <?php if (!empty($profileImage)): ?>
                    <img src="../<?= verificationEscape(ltrim($profileImage, '/')) ?>"
                         alt="Profile"
                         class="profile-avatar">
                <?php else: ?>
                    <span class="profile-avatar profile-initial">
                        <?= verificationEscape($profileInitial) ?>
                    </span>
                <?php endif; ?>

                <span class="profile-name">
                    <?= verificationEscape($firstName) ?>
                </span>

                <i class="fas fa-chevron-down dropdown-arrow"></i>
            </button>

            <div class="profile-dropdown" id="profileDropdown">

                <div class="dropdown-user-info">
                    <strong><?= verificationEscape($fullName) ?></strong>
                    <span><?= verificationEscape($email) ?></span>
                    <small>Service Provider</small>
                </div>

                <div class="dropdown-divider"></div>

                <a href="settings.php">
                    <i class="fas fa-gear"></i>
                    Settings &amp; Profile
                </a>

                <button type="button" id="darkModeToggle">
                    <i class="fas fa-moon" id="themeIcon"></i>
                    <span id="themeText">Dark Mode</span>
                </button>

                <a href="help.php">
                    <i class="fas fa-circle-question"></i>
                    Help &amp; Support
                </a>

                <div class="dropdown-divider"></div>

                <a href="../actions/logout.php" class="logout-link">
                    <i class="fas fa-right-from-bracket"></i>
                    Log Out
                </a>

            </div>
        </div>
    </div>

</header>

<main class="verification-page">

    <section class="verification-welcome">
        <div>
            <p class="eyebrow">PROVIDER WORKSPACE</p>
            <h1>Provider Verification</h1>
            <p>
                Submit your identification or qualification documents
                and track the administrator's review.
            </p>
        </div>

        <?php if ($provider_status !== 'approved'): ?>
            <a href="#verification-form" class="verification-primary-button">
                <i class="fas fa-cloud-arrow-up"></i>
                Submit Verification
            </a>
        <?php endif; ?>
    </section>

    <?php if ($success_message !== ''): ?>
        <div class="verification-alert success-alert" role="status">
            <i class="fas fa-circle-check"></i>
            <span><?= verificationEscape($success_message) ?></span>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="verification-alert error-alert" role="alert">
            <strong>
                <i class="fas fa-circle-exclamation"></i>
                Please correct the following:
            </strong>

            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= verificationEscape($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <section class="verification-status-panel">
        <div class="verification-status-icon">
            <i class="fas fa-shield-halved"></i>
        </div>

        <div class="verification-status-description">
            <span class="verification-overline">ACCOUNT STATUS</span>
            <h2><?= verificationEscape($status_label) ?></h2>

            <?php if ($provider_status === 'approved'): ?>
                <p>
                    Your provider account has been approved. You can access
                    the provider services available to approved providers.
                </p>
            <?php elseif ($provider_status === 'rejected'): ?>
                <p>
                    Your provider application has not been approved.
                    Check the administrator's feedback and submit the
                    required documents again if necessary.
                </p>
            <?php else: ?>
                <p>
                    Your provider application is awaiting review.
                    Check the status of your submitted documents below.
                </p>
            <?php endif; ?>
        </div>

        <span class="verification-status status-<?= verificationEscape(verificationStatusClass($provider_status)) ?>">
            <i class="fas <?= $provider_status === 'approved'
                ? 'fa-circle-check'
                : ($provider_status === 'rejected' ? 'fa-circle-xmark' : 'fa-clock') ?>"></i>
            <?= verificationEscape($status_label) ?>
        </span>
    </section>

    <section class="verification-section">
        <div class="verification-section-heading">
            <div>
                <h2><i class="fas fa-folder-open"></i> Submitted Documents</h2>
                <p>
                    Review your submitted documents and any feedback
                    from the administrator.
                </p>
            </div>

            <span class="document-count">
                <?= count($documents) ?>
                <?= count($documents) === 1 ? 'document' : 'documents' ?>
            </span>
        </div>

        <?php if (empty($documents)): ?>

            <div class="verification-empty-state">
                <span class="verification-empty-icon">
                    <i class="fas fa-file-circle-question"></i>
                </span>
                <h3>No Documents Submitted</h3>
                <p>
                    Your submitted documents will appear here.
                    Use the verification form to get started.
                </p>
            </div>

        <?php else: ?>

            <div class="verification-grid">

                <?php foreach ($documents as $document): ?>
                    <?php
                    $document_status = strtolower(
                        trim($document['status'] ?? 'pending')
                    );

                    $document_status_label = $status_labels[$document_status]
                        ?? ucfirst(str_replace('_', ' ', $document_status));
                    ?>

                    <article class="verification-card">

                        <div class="verification-card-top">
                            <span class="document-icon">
                                <i class="fas fa-file-lines"></i>
                            </span>

                            <span class="verification-status status-<?= verificationEscape(verificationStatusClass($document_status)) ?>">
                                <?= verificationEscape($document_status_label) ?>
                            </span>
                        </div>

                        <h3><?= verificationEscape($document['document_type']) ?></h3>

                        <div class="document-details">

                            <p>
                                <span>Document number</span>
                                <strong>
                                    <?= !empty($document['document_number'])
                                        ? verificationEscape($document['document_number'])
                                        : 'Not provided' ?>
                                </strong>
                            </p>

                            <p>
                                <span>Submitted</span>
                                <strong>
                                    <?= !empty($document['submitted_at'])
                                        ? verificationEscape(date(
                                            'd M Y, h:i A',
                                            strtotime($document['submitted_at'])
                                        ))
                                        : 'Date unavailable' ?>
                                </strong>
                            </p>

                            <?php if (!empty($document['reviewed_at'])): ?>
                                <p>
                                    <span>Reviewed</span>
                                    <strong>
                                        <?= verificationEscape(date(
                                            'd M Y, h:i A',
                                            strtotime($document['reviewed_at'])
                                        )) ?>
                                    </strong>
                                </p>
                            <?php endif; ?>

                        </div>

                        <?php if (!empty($document['admin_comment'])): ?>
                            <div class="verification-comment">
                                <strong>
                                    <i class="fas fa-comment-dots"></i>
                                    Administrator Feedback
                                </strong>

                                <p><?= nl2br(verificationEscape($document['admin_comment'])) ?></p>
                            </div>
                        <?php endif; ?>

                        <div class="document-file-note">
                            <i class="fas fa-paperclip"></i>
                            <span>
                                <strong>Uploaded file</strong>
                                <?= verificationEscape(
                                    basename($document['document_file'] ?? 'Document uploaded')
                                ) ?>
                            </span>
                        </div>

                    </article>
                <?php endforeach; ?>

            </div>

        <?php endif; ?>
    </section>

    <?php if ($provider_status !== 'approved'): ?>

        <section class="verification-section verification-form-section"
                 id="verification-form">

            <div class="verification-section-heading">
                <div>
                    <h2><i class="fas fa-cloud-arrow-up"></i> Submit a Verification Document</h2>
                    <p>
                        Accepted formats: PDF, JPG, JPEG and PNG.
                        Maximum file size: 5 MB.
                    </p>
                </div>
            </div>

            <form
                action="verification.php#verification-form"
                method="POST"
                enctype="multipart/form-data"
                class="verification-form"
            >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= verificationEscape($_SESSION['verification_csrf']) ?>"
                >

                <div class="verification-form-grid">

                    <div class="verification-form-group">
                        <label for="document_type">
                            Document Type <span class="required-mark">*</span>
                        </label>

                        <select id="document_type" name="document_type" required>
                            <option value="">Select document type</option>

                            <?php foreach ($document_types as $type): ?>
                                <option
                                    value="<?= verificationEscape($type) ?>"
                                    <?= $document_type_value === $type ? 'selected' : '' ?>
                                >
                                    <?= verificationEscape($type) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="verification-form-group">
                        <label for="document_number">Document Number</label>

                        <input
                            type="text"
                            id="document_number"
                            name="document_number"
                            maxlength="100"
                            value="<?= verificationEscape($document_number_value) ?>"
                            placeholder="Enter document number, if applicable"
                        >
                    </div>

                    <div class="verification-form-group verification-file-group">
                        <label for="document_file">
                            Upload Document <span class="required-mark">*</span>
                        </label>

                        <input
                            type="file"
                            id="document_file"
                            name="document_file"
                            accept=".pdf,.jpg,.jpeg,.png"
                            required
                        >

                        <small>
                            Upload a clear, readable copy. Maximum file size is 5 MB.
                        </small>
                    </div>

                </div>

                <div class="verification-form-actions">
                    <button type="submit" class="verification-primary-button">
                        <i class="fas fa-cloud-arrow-up"></i>
                        Submit Document
                    </button>
                </div>

            </form>
        </section>

    <?php endif; ?>

</main>

<footer class="provider-footer">
    <p>&copy; <?= date('Y') ?> HandyLink. All rights reserved.</p>
    <p>Connecting clients with trusted service providers.</p>
</footer>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const profileTrigger = document.getElementById('profileTrigger');
    const profileDropdown = document.getElementById('profileDropdown');
    const darkModeToggle = document.getElementById('darkModeToggle');
    const themeIcon = document.getElementById('themeIcon');
    const themeText = document.getElementById('themeText');
    const mobileMenuToggle = document.getElementById('mobileMenuToggle');
    const mainNav = document.getElementById('mainNav');

    function updateThemeButton() {
        const darkModeEnabled = document.body.classList.contains('dark-mode');

        themeIcon.className = darkModeEnabled
            ? 'fas fa-sun'
            : 'fas fa-moon';

        themeText.textContent = darkModeEnabled
            ? 'Light Mode'
            : 'Dark Mode';
    }

    try {
        if (localStorage.getItem('handylink-provider-theme') === 'dark') {
            document.body.classList.add('dark-mode');
        }
    } catch (error) {
        // Theme preference storage may be unavailable.
    }

    updateThemeButton();

    profileTrigger.addEventListener('click', function (event) {
        event.stopPropagation();

        const isOpen = profileDropdown.classList.toggle('show');
        profileTrigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });

    profileDropdown.addEventListener('click', function (event) {
        event.stopPropagation();
    });

    document.addEventListener('click', function () {
        profileDropdown.classList.remove('show');
        profileTrigger.setAttribute('aria-expanded', 'false');
    });

    darkModeToggle.addEventListener('click', function () {
        document.body.classList.toggle('dark-mode');

        try {
            localStorage.setItem(
                'handylink-provider-theme',
                document.body.classList.contains('dark-mode') ? 'dark' : 'light'
            );
        } catch (error) {
            // Theme still changes for the current page.
        }

        updateThemeButton();
    });

    mobileMenuToggle.addEventListener('click', function () {
        const isOpen = mainNav.classList.toggle('show');
        mobileMenuToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });

    mainNav.querySelectorAll('a').forEach(function (link) {
        link.addEventListener('click', function () {
            mainNav.classList.remove('show');
            mobileMenuToggle.setAttribute('aria-expanded', 'false');
        });
    });
});
</script>

</body>
</html>
