<?php
session_start();

require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'client') {
    header('Location: ../login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$success = '';
$error = '';

function helpEscape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/* Load client information */
$clientName = $_SESSION['full_name'] ?? 'Client';
$clientEmail = '';

$stmt = $conn->prepare(
    "SELECT full_name, email FROM users WHERE id = ? AND role = 'client' LIMIT 1"
);

if ($stmt) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($client = $result->fetch_assoc()) {
        $clientName = $client['full_name'];
        $clientEmail = $client['email'];
    }

    $stmt->close();
}

/* Submit a support request */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if ($subject === '' || $message === '') {
        $error = 'Please complete both the subject and message fields.';
    } elseif (strlen($subject) > 150) {
        $error = 'The subject must not exceed 150 characters.';
    } elseif (strlen($message) < 10) {
        $error = 'Please provide a message of at least 10 characters.';
    } elseif (strlen($message) > 10000) {
        $error = 'Your message is too long. Please keep it under 10,000 characters.';
    } else {

        $category = 'Client Support';

        $stmt = $conn->prepare("
            INSERT INTO support_tickets
                (user_id, user_type, subject, category, description, status)
            VALUES (?, 'client', ?, ?, ?, 'Open')
        ");

        if ($stmt) {
            $stmt->bind_param(
                'isss',
                $userId,
                $subject,
                $category,
                $message
            );

            if ($stmt->execute()) {

                $success = 'Your support request has been submitted successfully.';

                /* Send notification to active administrators */
                $notificationTitle = 'New Client Support Enquiry';
                $notificationMessage = 'A client has submitted a new support enquiry.';

                $notificationStmt = $conn->prepare("
                    INSERT INTO notifications
                        (user_id, title, message, type, is_read)
                    SELECT id, ?, ?, 'support', 0
                    FROM users
                    WHERE role = 'admin'
                      AND status = 'active'
                ");

                if ($notificationStmt) {
                    $notificationStmt->bind_param(
                        'ss',
                        $notificationTitle,
                        $notificationMessage
                    );

                    $notificationStmt->execute();
                    $notificationStmt->close();
                }

            } else {
                $error = 'We could not submit your request. Please try again.';
            }

            $stmt->close();

        } else {
            $error = 'Unable to prepare your support request.';
        }
    }
}

/* Retrieve this client's support requests */
$supportRequests = [];

$stmt = $conn->prepare("
    SELECT
        id,
        subject,
        description AS message,
        status,
        created_at
    FROM support_tickets
    WHERE user_id = ?
      AND user_type = 'client'
    ORDER BY created_at DESC
");

if ($stmt) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $supportRequests[] = $row;
    }

    $stmt->close();
}

/* Generate client initials */
$nameParts = preg_split('/\s+/', trim($clientName));
$initials = '';

foreach ($nameParts as $part) {
    if ($part !== '') {
        $initials .= strtoupper(substr($part, 0, 1));
    }

    if (strlen($initials) >= 2) {
        break;
    }
}

if ($initials === '') {
    $initials = 'C';
}

/* Frequently Asked Questions */
$faqs = [
    [
        'question' => 'How do I find a service provider?',
        'answer' => 'Open Find Services, select the type of service you need, review available providers, and choose one whose experience, location and service details match your needs.'
    ],
    [
        'question' => 'How do I submit a service request?',
        'answer' => 'Go to My Requests and use the request form to describe the job, select a service category, enter your location and provide your preferred date and time.'
    ],
    [
        'question' => 'How can I track my booking?',
        'answer' => 'Open Bookings from your dashboard to review your booking information and the current status. Contact support if a booking appears incorrect.'
    ],
    [
        'question' => 'How do I contact a service provider?',
        'answer' => 'Use the messaging feature available in your account to communicate with a provider where messaging is available.'
    ],
    [
        'question' => 'How do payments work?',
        'answer' => 'HandyLink helps clients connect with service providers. Confirm the price and payment arrangements directly with your provider before work begins. Do not assume that payment is processed through HandyLink.'
    ],
    [
        'question' => 'What should I do if I have a problem with a provider?',
        'answer' => 'Submit a support request below. Include the relevant booking or service details and explain the issue clearly so the HandyLink team can review it.'
    ],
    [
        'question' => 'How do I change my account information?',
        'answer' => 'Open Settings to update your profile information, change your profile picture, select your preferred appearance, or change your password.'
    ]
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Help & Support | HandyLink</title>

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">

    <link rel="stylesheet" href="../assets/css/clients/client.css">
    <link rel="stylesheet" href="../assets/css/clients/help.css">

    <script src="../assets/js/client_darkmode.js"></script>
</head>

<body>

<div class="help-page">

    <header class="help-header">
        <a href="dashboard.php" class="help-brand">
            <img src="../assets/images/handylink-logo.png" alt="HandyLink Logo">
            <span>HandyLink</span>
        </a>

        <nav class="help-navigation" aria-label="Main navigation">
            <a href="dashboard.php">
                <i class="fa-solid fa-house"></i>
                Dashboard
            </a>

            <a href="find_services.php">
                <i class="fa-solid fa-magnifying-glass"></i>
                Find Services
            </a>

            <a href="my_request.php">
                <i class="fa-solid fa-clipboard-list"></i>
                My Requests
            </a>

            <a href="bookings.php">
                <i class="fa-solid fa-calendar-check"></i>
                Bookings
            </a>

            <a href="messages.php">
                <i class="fa-solid fa-envelope"></i>
                Messages
            </a>

            <a href="reviews.php">
                <i class="fa-solid fa-star"></i>
                Reviews
            </a>

            <a href="settings.php">
                <i class="fa-solid fa-gear"></i>
                Settings
            </a>
        </nav>

        <div class="help-user">
            <span class="help-avatar"><?= helpEscape($initials) ?></span>
            <span class="help-user-name"><?= helpEscape($clientName) ?></span>
        </div>
    </header>

    <main class="help-main">

        <section class="help-hero">
            <div class="help-hero-content">
                <span class="help-eyebrow">WE ARE HERE TO HELP</span>
                <h1>How can we help you?</h1>
                <p>
                    Find answers to common questions or send the HandyLink
                    support team a message.
                </p>

                <a href="#contact-support" class="help-hero-button">
                    <i class="fa-solid fa-headset"></i>
                    Contact Support
                </a>
            </div>

            <div class="help-hero-icon">
                <i class="fa-solid fa-comments"></i>
            </div>
        </section>

        <?php if ($success !== ''): ?>
            <div class="help-alert help-success">
                <i class="fa-solid fa-circle-check"></i>
                <?= helpEscape($success) ?>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="help-alert help-error">
                <i class="fa-solid fa-circle-exclamation"></i>
                <?= helpEscape($error) ?>
            </div>
        <?php endif; ?>

        <section class="help-section">
            <div class="help-section-heading">
                <div>
                    <span class="help-section-label">HELP CENTRE</span>
                    <h2>Frequently Asked Questions</h2>
                    <p>Quick answers to common questions about HandyLink.</p>
                </div>
            </div>

            <div class="faq-list">
                <?php foreach ($faqs as $index => $faq): ?>
                    <details class="faq-item" <?= $index === 0 ? 'open' : '' ?>>
                        <summary>
                            <span><?= helpEscape($faq['question']) ?></span>
                            <i class="fa-solid fa-chevron-down"></i>
                        </summary>

                        <div class="faq-answer">
                            <?= helpEscape($faq['answer']) ?>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="help-contact-section" id="contact-support">
            <div class="contact-information">
                <span class="help-section-label">GET IN TOUCH</span>
                <h2>Contact Support</h2>
                <p>
                    Tell us what went wrong or what you need help with.
                    Your request will be saved for the HandyLink team to review.
                </p>

                <div class="contact-feature">
                    <span class="contact-feature-icon">
                        <i class="fa-solid fa-envelope"></i>
                    </span>
                    <div>
                        <strong>Support requests</strong>
                        <p>Submit a message using the form on this page.</p>
                    </div>
                </div>

                <div class="contact-feature">
                    <span class="contact-feature-icon">
                        <i class="fa-solid fa-clipboard-check"></i>
                    </span>
                    <div>
                        <strong>Track your requests</strong>
                        <p>Review your submitted requests and their statuses below.</p>
                    </div>
                </div>

                <div class="contact-feature">
                    <span class="contact-feature-icon">
                        <i class="fa-solid fa-user-shield"></i>
                    </span>
                    <div>
                        <strong>Account assistance</strong>
                        <p>Do not include your password or other confidential information.</p>
                    </div>
                </div>
            </div>

            <div class="support-form-card">
                <h3>Send us a message</h3>
                <p class="support-form-description">
                    Complete the form and submit your support request.
                </p>

                <form action="help.php#contact-support"
                      method="POST"
                      class="help-form">

                    <div class="help-form-field">
                        <label for="support_name">Your name</label>
                        <input type="text"
                               id="support_name"
                               value="<?= helpEscape($clientName) ?>"
                               readonly>
                    </div>

                    <div class="help-form-field">
                        <label for="support_email">Email address</label>
                        <input type="email"
                               id="support_email"
                               value="<?= helpEscape($clientEmail) ?>"
                               readonly>
                    </div>

                    <div class="help-form-field">
                        <label for="subject">Subject</label>
                        <input type="text"
                               id="subject"
                               name="subject"
                               maxlength="150"
                               placeholder="What do you need help with?"
                               required>
                    </div>

                    <div class="help-form-field">
                        <label for="message">Message</label>
                        <textarea id="message"
                                  name="message"
                                  rows="6"
                                  maxlength="10000"
                                  placeholder="Describe your issue in detail..."
                                  required></textarea>
                        <small>Minimum 10 characters.</small>
                    </div>

                    <button type="submit" class="send-support-button">
                        <i class="fa-solid fa-paper-plane"></i>
                        Submit Support Request
                    </button>
                </form>
            </div>
        </section>

        <section class="my-support-section">
            <div class="help-section-heading">
                <div>
                    <span class="help-section-label">YOUR HISTORY</span>
                    <h2>My Support Requests</h2>
                    <p>View support messages you have submitted.</p>
                </div>
            </div>

            <?php if (empty($supportRequests)): ?>
                <div class="no-support-requests">
                    <i class="fa-regular fa-folder-open"></i>
                    <h3>No support requests yet</h3>
                    <p>Your submitted requests will appear here.</p>
                </div>
            <?php else: ?>
                <div class="support-request-list">
                    <?php foreach ($supportRequests as $request): ?>
                        <?php
                        $statusClass = strtolower(
                            str_replace(' ', '-', $request['status'])
                        );
                        ?>

                        <article class="support-request-card">
                            <div class="support-request-top">
                                <div>
                                    <span class="support-request-number">
                                        Request #<?= (int) $request['id'] ?>
                                    </span>

                                    <h3><?= helpEscape($request['subject']) ?></h3>
                                </div>

                                <span class="support-status <?= helpEscape($statusClass) ?>">
                                    <?= helpEscape($request['status']) ?>
                                </span>
                            </div>

                            <p class="support-request-message">
                                <?= nl2br(helpEscape($request['message'])) ?>
                            </p>

                            <div class="support-request-date">
                                <i class="fa-regular fa-clock"></i>
                                Submitted
                                <?= helpEscape(date(
                                    'd M Y, h:i A',
                                    strtotime($request['created_at'])
                                )) ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <footer class="help-footer">
            <p>&copy; <?= date('Y') ?> HandyLink. All rights reserved.</p>
            <a href="settings.php">Account Settings</a>
        </footer>

    </main>
</div>

<script src="../assets/js/clients/theme.js"></script>

</body>
</html>
