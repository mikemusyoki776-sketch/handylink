
<?php
session_start();

require_once "../config/database.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

$success = "";
$error = "";

// Process notification form
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $user_id = filter_input(INPUT_POST, "user_id", FILTER_VALIDATE_INT);
    $title = trim($_POST["title"] ?? "");
    $message = trim($_POST["message"] ?? "");
    $type = trim($_POST["type"] ?? "general");

    $allowedTypes = [
        "general",
        "verification",
        "approval",
        "rejection"
    ];

    if (!$user_id || $title === "" || $message === "") {
        $error = "Please select a provider and fill in all required fields.";
    } elseif (!in_array($type, $allowedTypes, true)) {
        $error = "Please select a valid notification type.";
    } else {
        // Ensure the recipient is a service provider
        $check = $conn->prepare(
            "SELECT id FROM users WHERE id = ? AND role = 'provider'"
        );
        $check->bind_param("i", $user_id);
        $check->execute();
        $provider = $check->get_result()->fetch_assoc();
        $check->close();

        if (!$provider) {
            $error = "The selected service provider could not be found.";
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO notifications
                (user_id, title, message, type, is_read)
                VALUES (?, ?, ?, ?, 0)"
            );

            $stmt->bind_param(
                "isss",
                $user_id,
                $title,
                $message,
                $type
            );

            if ($stmt->execute()) {
                $success = "Notification sent successfully.";
                $_POST = [];
            } else {
                $error = "Unable to send the notification. Please try again.";
            }

            $stmt->close();
        }
    }
}

// Get registered service providers
$providers = $conn->query(
    "SELECT id, full_name, email
     FROM users
     WHERE role = 'provider'
     ORDER BY full_name ASC"
);

// Get recently sent notifications
$recentNotifications = $conn->query(
    "SELECT n.id, n.title, n.message, n.type,
            n.is_read, n.created_at, u.full_name
     FROM notifications n
     INNER JOIN users u ON u.id = n.user_id
     WHERE u.role = 'provider'
     ORDER BY n.created_at DESC
     LIMIT 10"
);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Applicant Notifications | HandyLink</title>

    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/admin_notifications.css">
</head>
<body>

<div class="notifications-page">

    <div class="page-header">
        <div>
            <h1>Applicant Notifications</h1>
            <p>Send important verification updates to service providers.</p>
        </div>

        <a href="dashboard.php" class="back-button">Back to Dashboard</a>
    </div>

    <?php if ($success !== ""): ?>
        <div class="alert success-alert">
            <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ""): ?>
        <div class="alert error-alert">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <section class="notification-form-card">
        <h2><span class="heading-icon">✉</span> Send a Notification</h2>
        <p class="section-description">
            Select a service provider and enter the message you want them to receive.
        </p>

        <form method="POST" action="notifications.php">

            <div class="form-group">
                <label for="user_id">Service Provider</label>

                <select name="user_id" id="user_id" required>
                    <option value="">-- Select a service provider --</option>

                    <?php if ($providers && $providers->num_rows > 0): ?>
                        <?php while ($provider = $providers->fetch_assoc()): ?>
                            <option
                                value="<?= (int) $provider['id'] ?>"
                                <?= (($_POST['user_id'] ?? '') == $provider['id']) ? 'selected' : '' ?>
                            >
                                <?= htmlspecialchars($provider['full_name']) ?>
                                (<?= htmlspecialchars($provider['email']) ?>)
                            </option>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </select>

                <?php if ($providers && $providers->num_rows === 0): ?>
                    <small>No service providers have registered yet.</small>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="type">Notification Type</label>

                <select name="type" id="type" required>
                    <option value="general">General Update</option>
                    <option value="verification">Verification Update</option>
                    <option value="approval">Verification Approved</option>
                    <option value="rejection">Verification Rejected</option>
                </select>
            </div>

            <div class="form-group">
                <label for="title">Notification Title</label>

                <input
                    type="text"
                    name="title"
                    id="title"
                    maxlength="150"
                    value="<?= htmlspecialchars($_POST['title'] ?? '') ?>"
                    placeholder="e.g. Verification Documents Approved"
                    required
                >
            </div>

            <div class="form-group">
                <label for="message">Message</label>

                <textarea
                    name="message"
                    id="message"
                    rows="5"
                    placeholder="Write the notification message here..."
                    required
                ><?= htmlspecialchars($_POST['message'] ?? '') ?></textarea>
            </div>

            <button type="submit" class="send-button">
                Send Notification
            </button>

        </form>
    </section>

    <section class="recent-notifications">
        <div class="section-heading">
            <div>
                <h2>Recent Notifications</h2>
                <p>Latest notifications sent to service providers.</p>
            </div>
        </div>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Provider</th>
                        <th>Title & Message</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Date Sent</th>
                    </tr>
                </thead>

                <tbody>
                <?php if ($recentNotifications && $recentNotifications->num_rows > 0): ?>
                    <?php while ($notification = $recentNotifications->fetch_assoc()): ?>
                        <tr>
                            <td>
                                <?= htmlspecialchars($notification['full_name']) ?>
                            </td>

                            <td>
                                <strong>
                                    <?= htmlspecialchars($notification['title']) ?>
                                </strong>
                                <p class="message-preview">
                                    <?= htmlspecialchars($notification['message']) ?>
                                </p>
                            </td>

                            <td>
                                <span class="type-label">
                                    <?= htmlspecialchars(ucwords(
                                        str_replace('_', ' ', $notification['type'])
                                    )) ?>
                                </span>
                            </td>

                            <td>
                                <span class="status-label <?= (int) $notification['is_read'] === 1 ? 'read' : 'unread' ?>">
                                    <?= (int) $notification['is_read'] === 1 ? 'Read' : 'Unread' ?>
                                </span>
                            </td>

                            <td>
                                <?= htmlspecialchars($notification['created_at']) ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" class="empty-state">
                            No notifications have been sent to service providers yet.
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

</div>

</body>
</html>
