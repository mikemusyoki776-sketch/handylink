<?php
require_once __DIR__ . '/access_control.php';
requireApprovedProvider();

global $conn;

$userId = (int) $_SESSION['user_id'];

$message = '';
$messageType = '';

/* CSRF protection */
if (empty($_SESSION['support_csrf'])) {
    $_SESSION['support_csrf'] = bin2hex(random_bytes(32));
}

/* Handle enquiry submission */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['support_csrf'], $_POST['csrf_token'])
    ) {
        $message = "Invalid request. Please refresh the page and try again.";
        $messageType = "error";

    } else {

        $subject = trim($_POST['subject'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if ($subject === '' || $category === '' || $description === '') {
            $message = "Please complete all the enquiry fields.";
            $messageType = "error";

        } elseif (
            strlen($subject) > 200 ||
            strlen($category) > 100
        ) {
            $message = "Some of your information is too long.";
            $messageType = "error";

        } else {

            $userType = 'provider';

            $stmt = $conn->prepare("
                INSERT INTO support_tickets
                    (user_id, user_type, subject, category, description)
                VALUES (?, ?, ?, ?, ?)
            ");

            if ($stmt) {
                $stmt->bind_param(
                    "issss",
                    $userId,
                    $userType,
                    $subject,
                    $category,
                    $description
                );

                if ($stmt->execute()) {

                    /* Notify the HandyLink Administrator */
                    $adminId = 3;

                    $notificationTitle = "New Support Enquiry";
                    $notificationMessage = "A service provider has submitted a new support enquiry.";

                    $notificationStmt = $conn->prepare("
                        INSERT INTO notifications
                            (user_id, title, message, type, is_read)
                        VALUES (?, ?, ?, 'support', 0)
                    ");

                    if ($notificationStmt) {
                        $notificationStmt->bind_param(
                            "iss",
                            $adminId,
                            $notificationTitle,
                            $notificationMessage
                        );

                        $notificationStmt->execute();
                        $notificationStmt->close();
                    }

                    $message = "Your enquiry has been submitted successfully. Our support team will review it.";
                    $messageType = "success";

                    $_SESSION['support_csrf'] = bin2hex(random_bytes(32));

                } else {
                    $message = "Unable to submit your enquiry. Please try again.";
                    $messageType = "error";
                }

                $stmt->close();

            } else {
                $message = "Something went wrong. Please try again later.";
                $messageType = "error";
            }
        }
    }
}

/* Fetch the provider's previous enquiries */
$enquiries = [];

$stmt = $conn->prepare("
    SELECT id, subject, category, status, created_at
    FROM support_tickets
    WHERE user_id = ? AND user_type = 'provider'
    ORDER BY created_at DESC
    LIMIT 5
");

if ($stmt) {
    $stmt->bind_param("i", $userId);
    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $enquiries[] = $row;
    }

    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Help & Support | HandyLink</title>

    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/provider_dashboard.css">
    <link rel="stylesheet" href="../assets/css/provider_help_support.css">

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const savedTheme = localStorage.getItem("handylink-provider-theme");

    if (savedTheme === "dark") {
        document.body.classList.add("dark-mode");
    } else {
        document.body.classList.remove("dark-mode");
    }
});
</script>

<body>

<header class="support-header">
    <div class="support-header-inner">

        <a href="dashboard.php" class="support-brand">
            <img src="../assets/images/handylink-logo.png" alt="HandyLink">
            <span>HandyLink</span>
        </a>

        <nav class="support-nav">
            <a href="dashboard.php">
                <i class="fa-solid fa-house"></i>
                Dashboard
            </a>

            <a href="settings.php">
                <i class="fa-solid fa-user-gear"></i>
                Settings & Profile
            </a>

            <a href="help_support.php" class="active">
                <i class="fa-solid fa-circle-question"></i>
                Help & Support
            </a>
        </nav>

    </div>
</header>

<main class="support-container">

    <div class="support-heading">
        <div>
            <h1>Help & Support</h1>
            <p>Find answers to common questions or contact the HandyLink support team.</p>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div class="support-alert <?= htmlspecialchars($messageType) ?>">
            <i class="fa-solid <?= $messageType === 'success'
                ? 'fa-circle-check'
                : 'fa-circle-exclamation' ?>"></i>

            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <!-- Contact information -->
    <section class="support-contact-card">

        <div class="contact-icon">
            <i class="fa-solid fa-headset"></i>
        </div>

        <div class="contact-details">
            <h2>Need assistance?</h2>
            <p>
                Our support team is available to assist you with account,
                verification and system-related questions.
            </p>

            <div class="contact-phone">
                <i class="fa-solid fa-phone"></i>
                <a href="tel:0700000000">0700000000</a>
            </div>
        </div>

    </section>

    <!-- Frequently asked questions -->
    <section class="support-section">

        <div class="support-section-heading">
            <div>
                <h2>Frequently Asked Questions</h2>
                <p>Quick answers to common provider questions.</p>
            </div>
        </div>

        <div class="faq-list">

            <details class="faq-item">
                <summary>
                    How do I get my provider account approved?
                    <i class="fa-solid fa-chevron-down"></i>
                </summary>

                <p>
                    Submit the required verification documents through
                    the Verification section. The HandyLink administration
                    team will review your application and communicate
                    the decision through notifications.
                </p>
            </details>

            <details class="faq-item">
                <summary>
                    Why can't I access some provider features?
                    <i class="fa-solid fa-chevron-down"></i>
                </summary>

                <p>
                    Some provider features are available only after your
                    account has been approved. Check your verification
                    status or contact support if you need assistance.
                </p>
            </details>

            <details class="faq-item">
                <summary>
                    How can I update my personal information?
                    <i class="fa-solid fa-chevron-down"></i>
                </summary>

                <p>
                    Open Settings & Profile to update your personal
                    information, contact details and profile photo.
                </p>
            </details>

            <details class="faq-item">
                <summary>
                    How will I know if my enquiry has been reviewed?
                    <i class="fa-solid fa-chevron-down"></i>
                </summary>

                <p>
                    You can check the status of your submitted enquiries
                    in the Recent Enquiries section on this page.
                </p>
            </details>

        </div>

    </section>

    <!-- Submit enquiry -->
    <section class="support-section">

        <div class="support-section-heading">
            <div>
                <h2>Send an Enquiry</h2>
                <p>Tell us what you need help with.</p>
            </div>
        </div>

        <form method="POST" class="support-form">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars($_SESSION['support_csrf']) ?>">

            <div class="support-form-grid">

                <div class="form-group">
                    <label for="subject">Subject</label>

                    <input
                        type="text"
                        id="subject"
                        name="subject"
                        maxlength="200"
                        placeholder="Enter your enquiry subject"
                        required>
                </div>

                <div class="form-group">
                    <label for="category">Enquiry Category</label>

                    <select id="category" name="category" required>
                        <option value="">Select a category</option>
                        <option value="Account Issues">Account Issues</option>
                        <option value="Verification">Verification</option>
                        <option value="Bookings">Bookings</option>
                        <option value="Availability">Availability</option>
                        <option value="Technical Support">Technical Support</option>
                        <option value="Other">Other</option>
                    </select>
                </div>

            </div>

            <div class="form-group">
                <label for="description">Describe Your Enquiry</label>

                <textarea
                    id="description"
                    name="description"
                    rows="5"
                    placeholder="Explain the issue or assistance you need..."
                    required></textarea>
            </div>

            <button type="submit" class="support-submit-button">
                <i class="fa-solid fa-paper-plane"></i>
                Submit Enquiry
            </button>

        </form>

    </section>

    <!-- Previous enquiries -->
    <section class="support-section">

        <div class="support-section-heading">
            <div>
                <h2>Recent Enquiries</h2>
                <p>Track the status of your previous enquiries.</p>
            </div>
        </div>

        <?php if (!empty($enquiries)): ?>

            <div class="enquiry-list">

                <?php foreach ($enquiries as $enquiry): ?>

                    <div class="enquiry-item">

                        <div class="enquiry-info">
                            <h3>
                                <?= htmlspecialchars($enquiry['subject']) ?>
                            </h3>

                            <p>
                                <?= htmlspecialchars($enquiry['category']) ?>
                                &bull;
                                <?= date(
                                    'd M Y',
                                    strtotime($enquiry['created_at'])
                                ) ?>
                            </p>
                        </div>

                        <span class="enquiry-status <?= strtolower(
                            str_replace(' ', '-', $enquiry['status'])
                        ) ?>">
                            <?= htmlspecialchars($enquiry['status']) ?>
                        </span>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php else: ?>

            <div class="empty-enquiries">
                <i class="fa-regular fa-folder-open"></i>
                <p>You have not submitted any enquiries yet.</p>
            </div>

        <?php endif; ?>

    </section>

</main>

</body>
</html>
