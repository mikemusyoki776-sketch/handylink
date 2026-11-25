
<?php
session_start();
require_once "../config/database.php";

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'client'
) {
    header("Location: ../login.php");
    exit();
}

$clientId = (int) $_SESSION['user_id'];
$fullName = $_SESSION['full_name'] ?? 'Client';
$firstName = explode(' ', trim($fullName))[0];

$selectedProvider = (int) ($_GET['provider_id'] ?? 0);
$selectedBooking = (int) ($_GET['booking_id'] ?? 0);
$search = trim($_GET['search'] ?? '');

$error = '';
$success = '';

/* Send a message using this same PHP file */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $receiverId = (int) ($_POST['receiver_id'] ?? 0);
    $bookingId = (int) ($_POST['booking_id'] ?? 0);
    $messageText = trim($_POST['message'] ?? '');

    if ($receiverId <= 0 || $messageText === '') {
        $error = "Please select a provider and enter a message.";
    } elseif (mb_strlen($messageText) > 5000) {
        $error = "Your message is too long. Please use 5,000 characters or fewer.";
    } else {
        /* Confirm that the recipient is a provider */
        $recipientStmt = $conn->prepare(
            "SELECT id FROM users WHERE id = ? AND role = 'provider' LIMIT 1"
        );
        $recipientStmt->bind_param("i", $receiverId);
        $recipientStmt->execute();
        $recipientExists = $recipientStmt->get_result()->num_rows > 0;
        $recipientStmt->close();

        $authorized = false;

        if ($recipientExists && $bookingId > 0) {
            /* Booking must belong to this client and this provider */
            $accessStmt = $conn->prepare(
                "SELECT b.id
                 FROM bookings b
                 JOIN providers p ON p.id = b.provider_id
                 WHERE b.id = ?
                   AND b.client_id = ?
                   AND p.user_id = ?
                 LIMIT 1"
            );
            $accessStmt->bind_param(
                "iii",
                $bookingId,
                $clientId,
                $receiverId
            );
            $accessStmt->execute();
            $authorized = $accessStmt->get_result()->num_rows > 0;
            $accessStmt->close();
        } elseif ($recipientExists) {
            /* Permit an existing conversation or a client-provider booking */
            $accessStmt = $conn->prepare(
                "SELECT id FROM messages
                 WHERE (sender_id = ? AND receiver_id = ?)
                    OR (sender_id = ? AND receiver_id = ?)
                 LIMIT 1"
            );
            $accessStmt->bind_param(
                "iiii",
                $clientId,
                $receiverId,
                $receiverId,
                $clientId
            );
            $accessStmt->execute();
            $authorized = $accessStmt->get_result()->num_rows > 0;
            $accessStmt->close();

            if (!$authorized) {
                $bookingAccessStmt = $conn->prepare(
                    "SELECT b.id
                     FROM bookings b
                     JOIN providers p ON p.id = b.provider_id
                     WHERE b.client_id = ?
                       AND p.user_id = ?
                     LIMIT 1"
                );
                $bookingAccessStmt->bind_param(
                    "ii",
                    $clientId,
                    $receiverId
                );
                $bookingAccessStmt->execute();
                $authorized = $bookingAccessStmt->get_result()->num_rows > 0;
                $bookingAccessStmt->close();
            }
        }

        if (!$authorized) {
            $error = "You can message only a provider connected to your conversation or booking.";
        } else {
            $insertStmt = $conn->prepare(
                "INSERT INTO messages
                    (sender_id, receiver_id, booking_id, message, is_read, created_at)
                 VALUES (?, ?, ?, ?, 0, NOW())"
            );

            $messageBookingId = $bookingId > 0 ? $bookingId : null;

            $insertStmt->bind_param(
                "iiis",
                $clientId,
                $receiverId,
                $messageBookingId,
                $messageText
            );

            if ($insertStmt->execute()) {
                $insertStmt->close();

                $redirectUrl = "messages.php?provider_id=" . $receiverId;
                if ($bookingId > 0) {
                    $redirectUrl .= "&booking_id=" . $bookingId;
                }

                header("Location: " . $redirectUrl);
                exit();
            } else {
                $error = "The message could not be sent. Please try again.";
                $insertStmt->close();
            }
        }
    }

    if ($receiverId > 0) {
        $selectedProvider = $receiverId;
        $selectedBooking = $bookingId;
    }
}

/* Count unread messages for the header */
$unreadMessages = 0;
$unreadStmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM messages
     WHERE receiver_id = ? AND is_read = 0"
);
$unreadStmt->bind_param("i", $clientId);
$unreadStmt->execute();
$unreadMessages = (int) ($unreadStmt->get_result()->fetch_assoc()['total'] ?? 0);
$unreadStmt->close();

/* Load providers with existing conversations or bookings */
$providers = [];

$providersSql = "
    SELECT
        u.id,
        u.full_name,
        (
            SELECT m2.message
            FROM messages m2
            WHERE (m2.sender_id = u.id AND m2.receiver_id = ?)
               OR (m2.sender_id = ? AND m2.receiver_id = u.id)
            ORDER BY m2.created_at DESC, m2.id DESC
            LIMIT 1
        ) AS last_message,
        (
            SELECT MAX(m3.created_at)
            FROM messages m3
            WHERE (m3.sender_id = u.id AND m3.receiver_id = ?)
               OR (m3.sender_id = ? AND m3.receiver_id = u.id)
        ) AS last_message_time,
        (
            SELECT COUNT(*)
            FROM messages m4
            WHERE m4.sender_id = u.id
              AND m4.receiver_id = ?
              AND m4.is_read = 0
        ) AS unread_count
    FROM users u
    WHERE u.role = 'provider'
      AND u.full_name LIKE ?
      AND (
          EXISTS (
              SELECT 1 FROM messages m
              WHERE (m.sender_id = u.id AND m.receiver_id = ?)
                 OR (m.sender_id = ? AND m.receiver_id = u.id)
          )
          OR EXISTS (
              SELECT 1
              FROM bookings b
              JOIN providers p ON p.id = b.provider_id
              WHERE b.client_id = ? AND p.user_id = u.id
          )
      )
    ORDER BY
        last_message_time IS NULL ASC,
        last_message_time DESC,
        u.full_name ASC
";

$providersStmt = $conn->prepare($providersSql);
$searchTerm = "%" . $search . "%";

$providersStmt->bind_param(
    "iiiiisiii",
    $clientId,
    $clientId,
    $clientId,
    $clientId,
    $clientId,
    $searchTerm,
    $clientId,
    $clientId,
    $clientId
);
$providersStmt->execute();
$providersResult = $providersStmt->get_result();

while ($row = $providersResult->fetch_assoc()) {
    $providers[] = $row;
}
$providersStmt->close();

/* Load selected provider and verify access */
$provider = null;
$conversationMessages = [];
$conversationAllowed = false;

if ($selectedProvider > 0) {
    $providerStmt = $conn->prepare(
        "SELECT id, full_name
         FROM users
         WHERE id = ? AND role = 'provider'
         LIMIT 1"
    );
    $providerStmt->bind_param("i", $selectedProvider);
    $providerStmt->execute();
    $provider = $providerStmt->get_result()->fetch_assoc();
    $providerStmt->close();

    if ($provider) {
        if ($selectedBooking > 0) {
            $checkStmt = $conn->prepare(
                "SELECT b.id
                 FROM bookings b
                 JOIN providers p ON p.id = b.provider_id
                 WHERE b.id = ?
                   AND b.client_id = ?
                   AND p.user_id = ?
                 LIMIT 1"
            );
            $checkStmt->bind_param(
                "iii",
                $selectedBooking,
                $clientId,
                $selectedProvider
            );
            $checkStmt->execute();
            $conversationAllowed = $checkStmt->get_result()->num_rows > 0;
            $checkStmt->close();
        } else {
            $checkStmt = $conn->prepare(
                "SELECT id FROM messages
                 WHERE (sender_id = ? AND receiver_id = ?)
                    OR (sender_id = ? AND receiver_id = ?)
                 LIMIT 1"
            );
            $checkStmt->bind_param(
                "iiii",
                $clientId,
                $selectedProvider,
                $selectedProvider,
                $clientId
            );
            $checkStmt->execute();
            $conversationAllowed = $checkStmt->get_result()->num_rows > 0;
            $checkStmt->close();

            if (!$conversationAllowed) {
                $checkStmt = $conn->prepare(
                    "SELECT b.id
                     FROM bookings b
                     JOIN providers p ON p.id = b.provider_id
                     WHERE b.client_id = ? AND p.user_id = ?
                     LIMIT 1"
                );
                $checkStmt->bind_param("ii", $clientId, $selectedProvider);
                $checkStmt->execute();
                $conversationAllowed = $checkStmt->get_result()->num_rows > 0;
                $checkStmt->close();
            }
        }
    }

    if ($provider && $conversationAllowed) {
        /* Mark incoming messages from this provider as read */
        $readStmt = $conn->prepare(
            "UPDATE messages
             SET is_read = 1
             WHERE sender_id = ? AND receiver_id = ? AND is_read = 0"
        );
        $readStmt->bind_param("ii", $selectedProvider, $clientId);
        $readStmt->execute();
        $readStmt->close();

        $messageStmt = $conn->prepare(
            "SELECT id, sender_id, receiver_id, booking_id, message, is_read, created_at
             FROM messages
             WHERE (sender_id = ? AND receiver_id = ?)
                OR (sender_id = ? AND receiver_id = ?)
             ORDER BY created_at ASC, id ASC"
        );
        $messageStmt->bind_param(
            "iiii",
            $clientId,
            $selectedProvider,
            $selectedProvider,
            $clientId
        );
        $messageStmt->execute();
        $messageResult = $messageStmt->get_result();

        while ($messageRow = $messageResult->fetch_assoc()) {
            $conversationMessages[] = $messageRow;
        }
        $messageStmt->close();
    } elseif ($provider) {
        $error = "You can open a conversation with this provider after a booking or existing conversation.";
        $provider = null;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages | HandyLink</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/clients/client.css">
    <link rel="stylesheet" href="../assets/css/clients/messages.css">
</head>
   <script src="../assets/js/client_darkmode.js"></script>
<body>

<header class="client-header">
    <a href="dashboard.php" class="brand">
        <img src="../assets/images/handylink-logo.png" alt="HandyLink Logo">
        <span>HandyLink</span>
    </a>

    <button class="mobile-menu-button" id="mobileMenuButton" type="button"
            aria-label="Toggle navigation" aria-expanded="false">
        <i class="fa-solid fa-bars"></i>
    </button>

    <nav class="main-navigation" id="mainNavigation">
        <a class="nav-link" href="dashboard.php"><i class="fa-solid fa-house"></i><span>Dashboard</span></a>
        <a class="nav-link" href="find_services.php"><i class="fa-solid fa-magnifying-glass"></i><span>Find Services</span></a>
        <a class="nav-link" href="my_request.php"><i class="fa-regular fa-clipboard"></i><span>My Requests</span></a>
        <a class="nav-link" href="bookings.php"><i class="fa-regular fa-calendar-check"></i><span>My Bookings</span></a>
        <a class="nav-link active" href="messages.php"><i class="fa-regular fa-comments"></i><span>Messages</span></a>
         <a class="nav-link" href="reviews.php"><i class="fa-solid fa-star"></i><span>Reviews</span></a>
    </nav>

    <div class="header-actions">
        <a href="messages.php" class="notification-button" aria-label="Unread messages" title="Messages">
            <i class="fa-regular fa-bell bell-icon"></i>
            <?php if ($unreadMessages > 0): ?>
                <span class="notification-dot"></span>
            <?php endif; ?>
        </a>

        <div class="profile-menu-wrapper">
            <button type="button" class="profile-button" id="profileButton" aria-expanded="false">
                <span class="profile-avatar profile-initial">
                    <?= htmlspecialchars(strtoupper(substr($firstName, 0, 1))) ?>
                </span>
                <span class="profile-name"><?= htmlspecialchars($firstName) ?></span>
                <i class="fa-solid fa-chevron-down chevron-icon"></i>
            </button>

            <div class="profile-dropdown" id="profileDropdown">
                <div class="dropdown-user">
                    <strong><?= htmlspecialchars($fullName) ?></strong>
                    <span>Client account</span>
                </div>
                <a href="settings.php"><i class="fa-solid fa-gear"></i>Profile &amp; Settings</a>
                <a href="../logout.php" class="dropdown-logout"><i class="fa-solid fa-right-from-bracket"></i>Log Out</a>
            </div>
        </div>
    </div>
</header>

<main class="messages-page">
    <div class="messages-title-row">
        <div>
            <span class="messages-eyebrow">STAY CONNECTED</span>
            <h1>Messages</h1>
            <p>Chat with your HandyLink service providers.</p>
        </div>
        <div class="messages-title-icon"><i class="fa-solid fa-comments"></i></div>
    </div>

    <?php if ($error !== ''): ?>
        <div class="messages-alert" role="alert">
            <i class="fa-solid fa-circle-exclamation"></i>
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <section class="whatsapp-layout <?= $provider && $conversationAllowed ? 'conversation-open' : '' ?>">
        <aside class="conversations-panel">
            <div class="conversations-heading">
                <div>
                    <h2>Chats</h2>
                    <span><?= count($providers) ?> conversation<?= count($providers) === 1 ? '' : 's' ?></span>
                </div>
                <i class="fa-regular fa-message"></i>
            </div>

            <form class="conversation-search" method="GET" action="messages.php">
                <?php if ($selectedProvider > 0): ?>
                    <input type="hidden" name="provider_id" value="<?= $selectedProvider ?>">
                <?php endif; ?>
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" name="search" value="<?= htmlspecialchars($search) ?>"
                       placeholder="Search conversations..." aria-label="Search conversations">
                <?php if ($search !== ''): ?>
                    <a href="messages.php" class="clear-search" aria-label="Clear search">
                        <i class="fa-solid fa-xmark"></i>
                    </a>
                <?php endif; ?>
            </form>

            <div class="conversation-list">
                <?php if (count($providers) > 0): ?>
                    <?php foreach ($providers as $chat): ?>
                        <?php
                        $chatId = (int) $chat['id'];
                        $isActive = $chatId === $selectedProvider;
                        $preview = trim((string) ($chat['last_message'] ?? ''));
                        if ($preview === '') {
                            $preview = 'Start a conversation';
                        } elseif (strlen($preview) > 52) {
                            $preview = substr($preview, 0, 52) . '…';
                        }
                        ?>
                        <a class="conversation-item <?= $isActive ? 'active' : '' ?>"
                           href="messages.php?provider_id=<?= $chatId ?>">
                            <div class="conversation-avatar">
                                <?= htmlspecialchars(strtoupper(substr($chat['full_name'], 0, 1))) ?>
                            </div>
                            <div class="conversation-info">
                                <div class="conversation-topline">
                                    <strong><?= htmlspecialchars($chat['full_name']) ?></strong>
                                    <time>
                                        <?php if (!empty($chat['last_message_time'])): ?>
                                            <?= date('H:i', strtotime($chat['last_message_time'])) ?>
                                        <?php endif; ?>
                                    </time>
                                </div>
                                <div class="conversation-bottomline">
                                    <p><?= htmlspecialchars($preview) ?></p>
                                    <?php if ((int) $chat['unread_count'] > 0): ?>
                                        <span class="unread-badge"><?= (int) $chat['unread_count'] ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-conversations">
                        <span><i class="fa-regular fa-comments"></i></span>
                        <strong>No chats yet</strong>
                        <p>Your conversations with providers will appear here after a booking or message exchange.</p>
                        <a href="find_services.php">Find a service</a>
                    </div>
                <?php endif; ?>
            </div>
        </aside>

        <section class="chat-panel">
            <?php if ($provider && $conversationAllowed): ?>
                <div class="chat-header">
                    <a class="mobile-back-button" href="messages.php" aria-label="Back to chats">
                        <i class="fa-solid fa-arrow-left"></i>
                    </a>
                    <div class="conversation-avatar chat-header-avatar">
                        <?= htmlspecialchars(strtoupper(substr($provider['full_name'], 0, 1))) ?>
                    </div>
                    <div class="chat-header-info">
                        <h2><?= htmlspecialchars($provider['full_name']) ?></h2>
                        <p><span class="status-indicator"></span> HandyLink service provider</p>
                    </div>
                    <div class="chat-header-icon"><i class="fa-solid fa-shield-halved"></i></div>
                </div>

                <div class="chat-messages" id="chatMessages">
                    <div class="chat-date-divider"><span>Conversation</span></div>

                    <?php if (count($conversationMessages) > 0): ?>
                        <?php foreach ($conversationMessages as $msg): ?>
                            <?php $sent = (int) $msg['sender_id'] === $clientId; ?>
                            <div class="message-row <?= $sent ? 'sent' : 'received' ?>">
                                <div class="message-bubble">
                                    <p><?= nl2br(htmlspecialchars($msg['message'])) ?></p>
                                    <div class="message-meta">
                                        <time><?= date('H:i', strtotime($msg['created_at'])) ?></time>
                                        <?php if ($sent): ?>
                                            <i class="fa-solid fa-check-double message-checks" title="Sent"></i>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="chat-empty-state">
                            <div class="chat-empty-icon"><i class="fa-regular fa-comments"></i></div>
                            <h3>Say hello!</h3>
                            <p>This is the beginning of your conversation with <?= htmlspecialchars($provider['full_name']) ?>.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <form class="message-composer" method="POST" action="messages.php?provider_id=<?= (int) $selectedProvider ?><?= $selectedBooking > 0 ? '&booking_id=' . (int) $selectedBooking : '' ?>">
                    <input type="hidden" name="receiver_id" value="<?= (int) $selectedProvider ?>">
                    <input type="hidden" name="booking_id" value="<?= (int) $selectedBooking ?>">
                    <label for="messageInput" class="sr-only">Type a message</label>
                    <textarea id="messageInput" name="message" rows="1" maxlength="5000"
                              placeholder="Type a message..." required></textarea>
                    <button type="submit" name="send_message" aria-label="Send message" title="Send message">
                        <i class="fa-solid fa-paper-plane"></i>
                    </button>
                </form>
            <?php else: ?>
                <div class="chat-welcome">
                    <div class="welcome-illustration">
                        <div class="welcome-bubble bubble-one"><i class="fa-solid fa-comment"></i></div>
                        <div class="welcome-bubble bubble-two"><i class="fa-solid fa-comments"></i></div>
                        <div class="welcome-main-icon"><i class="fa-solid fa-message"></i></div>
                    </div>
                    <h2>Your conversations, all in one place</h2>
                    <p>Select a provider from your chat list to read messages and continue the conversation.</p>
                    <span class="welcome-security"><i class="fa-solid fa-lock"></i> HandyLink messaging</span>
                </div>
            <?php endif; ?>
        </section>
    </section>
</main>

<footer class="client-footer">
    <p>&copy; <?= date('Y') ?> HandyLink. Connecting people with trusted service providers.</p>
</footer>

<script>
const profileButton = document.getElementById("profileButton");
const profileDropdown = document.getElementById("profileDropdown");
const mobileMenuButton = document.getElementById("mobileMenuButton");
const mainNavigation = document.getElementById("mainNavigation");

if (profileButton && profileDropdown) {
    profileButton.addEventListener("click", function (event) {
        event.stopPropagation();
        const isOpen = profileDropdown.classList.toggle("show");
        profileButton.setAttribute("aria-expanded", isOpen ? "true" : "false");
    });

    document.addEventListener("click", function (event) {
        if (!profileDropdown.contains(event.target) && !profileButton.contains(event.target)) {
            profileDropdown.classList.remove("show");
            profileButton.setAttribute("aria-expanded", "false");
        }
    });
}

if (mobileMenuButton && mainNavigation) {
    mobileMenuButton.addEventListener("click", function () {
        const isOpen = mainNavigation.classList.toggle("show");
        mobileMenuButton.setAttribute("aria-expanded", isOpen ? "true" : "false");
        const icon = mobileMenuButton.querySelector("i");
        if (icon) icon.className = isOpen ? "fa-solid fa-xmark" : "fa-solid fa-bars";
    });
}

const chatMessages = document.getElementById("chatMessages");
if (chatMessages) {
    chatMessages.scrollTop = chatMessages.scrollHeight;
}

const messageInput = document.getElementById("messageInput");
if (messageInput) {
    const resizeInput = () => {
        messageInput.style.height = "auto";
        messageInput.style.height = Math.min(messageInput.scrollHeight, 120) + "px";
    };
    messageInput.addEventListener("input", resizeInput);
    messageInput.addEventListener("keydown", function (event) {
        if (event.key === "Enter" && !event.shiftKey) {
            event.preventDefault();
            const form = messageInput.closest("form");
            if (form && messageInput.value.trim() !== "") {
                const submitButton = form.querySelector('button[name="send_message"]');
                if (submitButton) submitButton.click();
            }
        }
    });
}
</script>

</body>
</html>
