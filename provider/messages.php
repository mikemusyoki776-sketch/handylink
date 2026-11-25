
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/access_control.php';

requireApprovedProvider();

$user_id = (int) $_SESSION['user_id'];

function messageEscape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function messageInitial($name)
{
    $name = trim((string) $name);
    return $name !== '' ? strtoupper(substr($name, 0, 1)) : '?';
}

$errorMessage = $_SESSION['message_error'] ?? '';
unset($_SESSION['message_error']);

/* Provider account details */
$stmt = $conn->prepare(
    "SELECT full_name, email, profile_image, status
     FROM users
     WHERE id = ? AND role = 'provider'
     LIMIT 1"
);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user || strtolower(trim($user['status'] ?? '')) !== 'active') {
    session_destroy();
    header('Location: ../login.php');
    exit();
}

$fullName = $user['full_name'] ?? 'Provider';
$firstName = explode(' ', trim($fullName))[0];
$email = $user['email'] ?? '';
$profileImage = $user['profile_image'] ?? '';
$profileInitial = messageInitial($fullName);

/* Provider verification */
$stmt = $conn->prepare(
    "SELECT id, verification_status
     FROM providers
     WHERE user_id = ?
     LIMIT 1"
);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$provider = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (
    !$provider ||
    strtolower(trim($provider['verification_status'] ?? '')) !== 'approved'
) {
    $_SESSION['provider_access_notice'] =
        'Your provider account must be approved before accessing messages.';
    header('Location: verification.php');
    exit();
}

$provider_id = (int) $provider['id'];

$selected_client_id = filter_input(INPUT_GET, 'client_id', FILTER_VALIDATE_INT);
$selected_client_id = $selected_client_id ? (int) $selected_client_id : 0;

$selected_booking_id = filter_input(INPUT_GET, 'booking_id', FILTER_VALIDATE_INT);
$selected_booking_id = $selected_booking_id ? (int) $selected_booking_id : 0;

$unreadMessages = 0;
$unreadNotifications = 0;
$conversations = [];
$messages = [];
$selectedContact = null;

/* Unread messages */
$stmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM messages
     WHERE receiver_id = ? AND is_read = 0"
);
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $unreadMessages = (int) ($row['total'] ?? 0);
    $stmt->close();
}

/* Unread notifications */
$tableResult = $conn->query("SHOW TABLES LIKE 'notifications'");
if ($tableResult && $tableResult->num_rows > 0) {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM notifications
         WHERE user_id = ? AND is_read = 0"
    );
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $unreadNotifications = (int) ($row['total'] ?? 0);
        $stmt->close();
    }
}

/* Load client and admin conversations in both directions. */
$stmt = $conn->prepare(
    "SELECT
        u.id AS contact_id,
        u.full_name AS contact_name,
        u.role AS contact_role,
        MAX(x.created_at) AS last_message_time,
        (
            SELECT m2.message
            FROM messages m2
            WHERE (
                (m2.sender_id = u.id AND m2.receiver_id = ?)
                OR
                (m2.sender_id = ? AND m2.receiver_id = u.id)
            )
            ORDER BY m2.created_at DESC, m2.id DESC
            LIMIT 1
        ) AS last_message,
        (
            SELECT COUNT(*)
            FROM messages m3
            WHERE m3.sender_id = u.id
              AND m3.receiver_id = ?
              AND m3.is_read = 0
        ) AS unread_count
     FROM (
        SELECT sender_id AS peer_id, created_at
        FROM messages
        WHERE receiver_id = ?

        UNION ALL

        SELECT receiver_id AS peer_id, created_at
        FROM messages
        WHERE sender_id = ?
     ) x
     INNER JOIN users u ON u.id = x.peer_id
     WHERE u.role IN ('client', 'admin')
       AND u.status = 'active'
     GROUP BY u.id, u.full_name, u.role
     ORDER BY last_message_time DESC"
);

if ($stmt) {
    $stmt->bind_param(
        "iiiii",
        $user_id,
        $user_id,
        $user_id,
        $user_id,
        $user_id
    );
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $row['contact_id'] = (int) $row['contact_id'];
        $row['unread_count'] = (int) $row['unread_count'];
        $conversations[$row['contact_id']] = $row;
    }
    $stmt->close();
}

/* Ensure administrators can be contacted before a conversation starts. */
$stmt = $conn->prepare(
    "SELECT id, full_name, role
     FROM users
     WHERE role = 'admin' AND status = 'active'
     ORDER BY full_name"
);
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();

    while ($admin = $result->fetch_assoc()) {
        $adminId = (int) $admin['id'];

        if (!isset($conversations[$adminId])) {
            $conversations[$adminId] = [
                'contact_id' => $adminId,
                'contact_name' => $admin['full_name'],
                'contact_role' => 'admin',
                'last_message_time' => null,
                'last_message' => 'Start a conversation',
                'unread_count' => 0
            ];
        }
    }
    $stmt->close();
}

/* Put conversations with recent messages first. */
uasort($conversations, function ($a, $b) {
    $timeA = $a['last_message_time'] ?? '';
    $timeB = $b['last_message_time'] ?? '';

    if ($timeA === $timeB) {
        return strcasecmp($a['contact_name'], $b['contact_name']);
    }
    if ($timeA === '') return 1;
    if ($timeB === '') return -1;

    return strcmp($timeB, $timeA);
});

/* Validate the selected recipient. */
if ($selected_client_id > 0) {
    $stmt = $conn->prepare(
        "SELECT id, full_name, role, profile_image, status
         FROM users
         WHERE id = ?
           AND role IN ('client', 'admin')
           AND status = 'active'
         LIMIT 1"
    );
    $stmt->bind_param("i", $selected_client_id);
    $stmt->execute();
    $selectedContact = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$selectedContact) {
        $selected_client_id = 0;
        $selected_booking_id = 0;
        $errorMessage = 'The selected recipient is unavailable.';
    }
}

/* Validate an optional booking belongs to this provider and client. */
if ($selectedContact && $selected_booking_id > 0) {
    if (strtolower($selectedContact['role']) !== 'client') {
        $selected_booking_id = 0;
    } else {
        $stmt = $conn->prepare(
            "SELECT b.id
             FROM bookings b
             INNER JOIN providers p ON p.id = b.provider_id
             WHERE b.id = ?
               AND b.client_id = ?
               AND p.user_id = ?
             LIMIT 1"
        );
        $stmt->bind_param(
            "iii",
            $selected_booking_id,
            $selected_client_id,
            $user_id
        );
        $stmt->execute();
        $validBooking = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$validBooking) {
            $selected_booking_id = 0;
        }
    }
}

/* Mark incoming messages read and load this conversation. */
if ($selectedContact) {
    $stmt = $conn->prepare(
        "UPDATE messages
         SET is_read = 1
         WHERE sender_id = ?
           AND receiver_id = ?
           AND is_read = 0"
    );
    $stmt->bind_param("ii", $selected_client_id, $user_id);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare(
        "SELECT id, sender_id, receiver_id, booking_id, message, created_at
         FROM messages
         WHERE (
             (sender_id = ? AND receiver_id = ?)
             OR
             (sender_id = ? AND receiver_id = ?)
         )
         ORDER BY created_at ASC, id ASC"
    );
    $stmt->bind_param(
        "iiii",
        $user_id,
        $selected_client_id,
        $selected_client_id,
        $user_id
    );
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $messages[] = $row;
    }
    $stmt->close();

    /* Refresh unread badge after reading this conversation. */
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM messages
         WHERE receiver_id = ? AND is_read = 0"
    );
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $unreadMessages = (int) ($row['total'] ?? 0);
    $stmt->close();

    if (isset($conversations[$selected_client_id])) {
        $conversations[$selected_client_id]['unread_count'] = 0;
    }

    if (!isset($conversations[$selected_client_id])) {
        $conversations[$selected_client_id] = [
            'contact_id' => $selected_client_id,
            'contact_name' => $selectedContact['full_name'],
            'contact_role' => $selectedContact['role'],
            'last_message_time' => null,
            'last_message' => 'Start a conversation',
            'unread_count' => 0
        ];
    }
}

$selectedContactName = $selectedContact['full_name'] ?? '';
$selectedContactRole = strtolower($selectedContact['role'] ?? '');
$selectedContactInitial = messageInitial($selectedContactName);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages | HandyLink Provider</title>

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/provider_dashboard.css">
    <link rel="stylesheet" href="../assets/css/provider_messages.css">
</head>

<body class="provider-page messages-page">

<header class="provider-header">
    <div class="header-left">
        <a href="dashboard.php" class="brand">
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
        <a href="dashboard.php">
            <i class="fas fa-house"></i><span>Dashboard</span>
        </a>
        <a href="verification.php">
            <i class="fas fa-shield-halved"></i><span>Verification</span>
        </a>
        <a href="services.php">
            <i class="fas fa-screwdriver-wrench"></i><span>My Services</span>
        </a>
        <a href="requests.php">
            <i class="fas fa-clipboard-list"></i><span>Requests</span>
        </a>
        <a href="bookings.php">
            <i class="fas fa-calendar-check"></i><span>Bookings</span>
        </a>

         <a href="messages.php" class="active">
            <i class="fas fa-envelope"></i><span>Messages</span>
            <?php if ($unreadMessages > 0): ?>
                <span class="nav-badge">
                    <?= $unreadMessages > 99 ? '99+' : $unreadMessages ?>
                </span>
            <?php endif; ?>
        </a>
        
        <a href="availability.php">
            <i class="fas fa-calendar-days"></i><span>Availability</span>
        </a>
        <a href="review.php">
            <i class="fas fa-star"></i><span>Reviews</span>
        </a>
        
        <a href="notification.php">
            <i class="fas fa-bell"></i><span>Notifications</span>
            <?php if ($unreadNotifications > 0): ?>
                <span class="nav-badge">
                    <?= $unreadNotifications > 99 ? '99+' : $unreadNotifications ?>
                </span>
            <?php endif; ?>
        </a>
    </nav>

    <div class="header-right">
        <div class="profile-menu">
            <button type="button"
                    class="profile-trigger"
                    id="profileTrigger"
                    aria-expanded="false">

                <?php if (!empty($profileImage)): ?>
                    <img src="../<?= messageEscape(ltrim($profileImage, '/')) ?>"
                         alt="Profile"
                         class="profile-avatar">
                <?php else: ?>
                    <span class="profile-avatar profile-initial">
                        <?= messageEscape($profileInitial) ?>
                    </span>
                <?php endif; ?>

                <span class="profile-name"><?= messageEscape($firstName) ?></span>
                <i class="fas fa-chevron-down dropdown-arrow"></i>
            </button>

            <div class="profile-dropdown" id="profileDropdown">
                <div class="dropdown-user-info">
                    <strong><?= messageEscape($fullName) ?></strong>
                    <span><?= messageEscape($email) ?></span>
                    <small>Service Provider</small>
                </div>

                <div class="dropdown-divider"></div>

                <a href="settings.php">
                    <i class="fas fa-gear"></i> Settings &amp; Profile
                </a>

                <button type="button" id="darkModeToggle">
                    <i class="fas fa-moon" id="themeIcon"></i>
                    <span id="themeText">Dark Mode</span>
                </button>

                <a href="help.php">
                    <i class="fas fa-circle-question"></i> Help &amp; Support
                </a>

                <div class="dropdown-divider"></div>

                <a href="../actions/logout.php" class="logout-link">
                    <i class="fas fa-right-from-bracket"></i> Log Out
                </a>
            </div>
        </div>
    </div>
</header>

<main class="messages-main dashboard-container">

    <section class="messages-page-heading">
        <div>
            <p class="messages-eyebrow">PROVIDER WORKSPACE</p>
            <h1>Messages</h1>
            <p>Communicate with clients and HandyLink administration.</p>
        </div>
    </section>

    <?php if ($errorMessage !== ''): ?>
        <div class="messages-alert" role="alert">
            <i class="fas fa-circle-exclamation"></i>
            <?= messageEscape($errorMessage) ?>
        </div>
    <?php endif; ?>

    <section class="messages-chat-container">

        <aside class="messages-sidebar">
            <div class="messages-sidebar-header">
                <div>
                    <h2>Conversations</h2>
                    <p>Clients and administration</p>
                </div>
                <span class="messages-summary"><?= count($conversations) ?></span>
            </div>

            <div class="messages-search">
                <i class="fas fa-magnifying-glass"></i>
                <input type="search"
                       id="conversationSearch"
                       placeholder="Search conversations..."
                       aria-label="Search conversations">
            </div>

            <div class="messages-chat-list" id="messagesChatList">
                <?php if (!empty($conversations)): ?>

                    <?php foreach ($conversations as $conversation): ?>
                        <?php
                        $contactId = (int) $conversation['contact_id'];
                        $contactName = $conversation['contact_name'] ?? 'Contact';
                        $contactRole = strtolower($conversation['contact_role'] ?? 'client');
                        $lastMessage = $conversation['last_message'] ?? 'Start a conversation';
                        $unreadCount = (int) ($conversation['unread_count'] ?? 0);
                        $isSelected = $selected_client_id === $contactId;
                        $conversationUrl = 'messages.php?client_id=' . $contactId;

                        if ($isSelected && $selected_booking_id > 0) {
                            $conversationUrl .= '&booking_id=' . $selected_booking_id;
                        }
                        ?>

                        <a href="<?= messageEscape($conversationUrl) ?>"
                           class="messages-chat-item <?= $isSelected ? 'active' : '' ?>"
                           data-search="<?= messageEscape(strtolower($contactName . ' ' . $contactRole)) ?>">

                            <span class="messages-client-avatar <?= $contactRole === 'admin' ? 'admin-avatar' : '' ?>">
                                <?php if ($contactRole === 'admin'): ?>
                                    <i class="fas fa-user-shield"></i>
                                <?php else: ?>
                                    <?= messageEscape(messageInitial($contactName)) ?>
                                <?php endif; ?>
                            </span>

                            <span class="messages-chat-item-info">
                                <span class="messages-chat-item-top">
                                    <strong><?= messageEscape($contactName) ?></strong>
                                    <?php if (!empty($conversation['last_message_time'])): ?>
                                        <small>
                                            <?= messageEscape(date('d M', strtotime($conversation['last_message_time']))) ?>
                                        </small>
                                    <?php endif; ?>
                                </span>

                                <span class="messages-chat-preview">
                                    <?= messageEscape($lastMessage) ?>
                                </span>

                                <span class="messages-contact-role">
                                    <?= $contactRole === 'admin' ? 'HandyLink Admin' : 'Client' ?>
                                </span>
                            </span>

                            <?php if ($unreadCount > 0): ?>
                                <span class="messages-unread-badge">
                                    <?= $unreadCount > 99 ? '99+' : $unreadCount ?>
                                </span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>

                    <div class="messages-sidebar-empty messages-no-search-results"
                         id="noSearchResults"
                         style="display:none;">
                        <i class="fas fa-magnifying-glass"></i>
                        <p>No matching conversations found.</p>
                    </div>

                <?php else: ?>
                    <div class="messages-sidebar-empty">
                        <i class="fas fa-comments"></i>
                        <p>No conversations yet.</p>
                        <small>Active administrators will appear here.</small>
                    </div>
                <?php endif; ?>
            </div>
        </aside>

        <section class="messages-chat-window">

            <?php if ($selectedContact): ?>

                <div class="messages-chat-header">
                    <a href="messages.php"
                       class="messages-back-button"
                       aria-label="Back to conversations">
                        <i class="fas fa-arrow-left"></i>
                    </a>

                    <span class="messages-client-avatar <?= $selectedContactRole === 'admin' ? 'admin-avatar' : '' ?>">
                        <?php if ($selectedContactRole === 'admin'): ?>
                            <i class="fas fa-user-shield"></i>
                        <?php else: ?>
                            <?= messageEscape($selectedContactInitial) ?>
                        <?php endif; ?>
                    </span>

                    <div class="messages-chat-contact">
                        <h2><?= messageEscape($selectedContactName) ?></h2>
                        <p>
                            <span class="contact-online-dot"></span>
                            <?= $selectedContactRole === 'admin'
                                ? 'HandyLink Administration'
                                : 'Client conversation' ?>
                        </p>
                    </div>

                    <span class="messages-header-role">
                        <?= $selectedContactRole === 'admin' ? 'ADMIN' : 'CLIENT' ?>
                    </span>
                </div>

                <div class="messages-chat-body" id="messagesChatBody">
                    <?php if (!empty($messages)): ?>

                        <?php foreach ($messages as $message): ?>
                            <?php
                            $isSent = (int) $message['sender_id'] === $user_id;
                            $messageTime = !empty($message['created_at'])
                                ? strtotime($message['created_at'])
                                : false;
                            ?>

                            <div class="messages-message-row <?= $isSent ? 'sent' : 'received' ?>">
                                <?php if (!$isSent): ?>
                                    <span class="messages-message-avatar <?= $selectedContactRole === 'admin' ? 'admin-avatar' : '' ?>">
                                        <?php if ($selectedContactRole === 'admin'): ?>
                                            <i class="fas fa-user-shield"></i>
                                        <?php else: ?>
                                            <?= messageEscape($selectedContactInitial) ?>
                                        <?php endif; ?>
                                    </span>
                                <?php endif; ?>

                                <div class="messages-message-bubble">
                                    <p><?= nl2br(messageEscape($message['message'])) ?></p>
                                    <span class="messages-message-time">
                                        <?= $messageTime
                                            ? messageEscape(date('d M, h:i A', $messageTime))
                                            : '' ?>
                                        <?php if ($isSent): ?>
                                            <i class="fas fa-check-double" aria-label="Sent"></i>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>

                    <?php else: ?>
                        <div class="messages-conversation-empty">
                            <span class="messages-welcome-icon">
                                <i class="fas fa-comments"></i>
                            </span>
                            <h3>Start your conversation</h3>
                            <p>
                                Send a message to
                                <?= messageEscape($selectedContactName) ?>.
                            </p>
                        </div>
                    <?php endif; ?>
                </div>

                <form class="messages-compose"
                      action="send_message.php"
                      method="POST">

                    <input type="hidden"
                           name="receiver_id"
                           value="<?= $selected_client_id ?>">

                    <input type="hidden"
                           name="booking_id"
                           value="<?= $selected_booking_id ?>">

                    <textarea name="message"
                              id="messageInput"
                              rows="1"
                              maxlength="5000"
                              placeholder="Type your message..."
                              aria-label="Type your message"
                              required></textarea>

                    <button type="submit"
                            class="messages-send-button"
                            aria-label="Send message">
                        <i class="fas fa-paper-plane"></i>
                        <span>Send</span>
                    </button>
                </form>

            <?php else: ?>

                <div class="messages-welcome-state">
                    <span class="messages-welcome-icon">
                        <i class="fas fa-comments"></i>
                    </span>
                    <h2>Your HandyLink Messages</h2>
                    <p>
                        Select a client conversation or contact the
                        HandyLink administration team from the list.
                    </p>
                    <div class="messages-welcome-tips">
                        <span><i class="fas fa-user"></i> Client communication</span>
                        <span><i class="fas fa-user-shield"></i> Admin support</span>
                    </div>
                </div>

            <?php endif; ?>

        </section>
    </section>
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
        const enabled = document.body.classList.contains('dark-mode');

        if (themeIcon) {
            themeIcon.className = enabled ? 'fas fa-sun' : 'fas fa-moon';
        }

        if (themeText) {
            themeText.textContent = enabled ? 'Light Mode' : 'Dark Mode';
        }
    }

    try {
        if (localStorage.getItem('handylink-provider-theme') === 'dark') {
            document.body.classList.add('dark-mode');
        }
    } catch (error) {}

    updateThemeButton();

    if (profileTrigger && profileDropdown) {
        profileTrigger.addEventListener('click', function (event) {
            event.stopPropagation();
            const open = profileDropdown.classList.toggle('show');
            profileTrigger.setAttribute('aria-expanded', open ? 'true' : 'false');
        });

        profileDropdown.addEventListener('click', function (event) {
            event.stopPropagation();
        });

        document.addEventListener('click', function () {
            profileDropdown.classList.remove('show');
            profileTrigger.setAttribute('aria-expanded', 'false');
        });
    }

    if (darkModeToggle) {
        darkModeToggle.addEventListener('click', function () {
            document.body.classList.toggle('dark-mode');

            try {
                localStorage.setItem(
                    'handylink-provider-theme',
                    document.body.classList.contains('dark-mode') ? 'dark' : 'light'
                );
            } catch (error) {}

            updateThemeButton();
        });
    }

    if (mobileMenuToggle && mainNav) {
        mobileMenuToggle.addEventListener('click', function () {
            const open = mainNav.classList.toggle('show');
            mobileMenuToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });

        mainNav.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', function () {
                mainNav.classList.remove('show');
                mobileMenuToggle.setAttribute('aria-expanded', 'false');
            });
        });
    }

    const searchInput = document.getElementById('conversationSearch');
    const chatItems = document.querySelectorAll('.messages-chat-item');
    const noSearchResults = document.getElementById('noSearchResults');

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            const term = this.value.trim().toLowerCase();
            let visible = 0;

            chatItems.forEach(function (item) {
                const matches = (item.dataset.search || '').includes(term);
                item.style.display = matches ? '' : 'none';
                if (matches) visible++;
            });

            if (noSearchResults) {
                noSearchResults.style.display =
                    term !== '' && visible === 0 ? 'flex' : 'none';
            }
        });
    }

    const chatBody = document.getElementById('messagesChatBody');
    if (chatBody) {
        chatBody.scrollTop = chatBody.scrollHeight;
    }

    const messageInput = document.getElementById('messageInput');
    if (messageInput) {
        messageInput.addEventListener('input', function () {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 120) + 'px';
        });
    }
});
</script>

</body>
</html>
