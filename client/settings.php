
<?php
session_start();

require_once __DIR__ . '/../config/database.php';

/* Check client login */
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'client') {
    header('Location: ../login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$success = '';
$error = '';

/* Escape output safely */
function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/* Get current client information */
function getClientSettings($conn, $userId)
{
    $sql = "SELECT id, full_name, email, phone, location, profile_image, password
            FROM users
            WHERE id = ? AND role = 'client'
            LIMIT 1";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();

    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    $stmt->close();

    return $user ?: null;
}

$user = getClientSettings($conn, $userId);

if (!$user) {
    session_destroy();
    header('Location: ../login.php');
    exit;
}

/* Process submitted settings */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* Update profile */
    if ($action === 'profile') {
        $fullName = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $location = trim($_POST['location'] ?? '');

        if ($fullName === '') {
            $error = 'Full name is required.';
        } else {
            $profileImage = $user['profile_image'] ?? '';
            $newImagePath = null;

            if (
                isset($_FILES['profile_image']) &&
                $_FILES['profile_image']['error'] !== UPLOAD_ERR_NO_FILE
            ) {
                if ($_FILES['profile_image']['error'] !== UPLOAD_ERR_OK) {
                    $error = 'The image could not be uploaded. Please try again.';
                } elseif ($_FILES['profile_image']['size'] > 2 * 1024 * 1024) {
                    $error = 'The profile image must not exceed 2 MB.';
                } else {
                    $imageInfo = getimagesize($_FILES['profile_image']['tmp_name']);
                    $allowedTypes = [
                        IMAGETYPE_JPEG => 'jpg',
                        IMAGETYPE_PNG => 'png',
                        IMAGETYPE_WEBP => 'webp'
                    ];

                    if (!$imageInfo || !isset($allowedTypes[$imageInfo[2]])) {
                        $error = 'Use a valid JPG, PNG, or WEBP image.';
                    } else {
                        $uploadDirectory = __DIR__ . '/../assets/images/profiles/';

                        if (
                            !is_dir($uploadDirectory) &&
                            !mkdir($uploadDirectory, 0755, true)
                        ) {
                            $error = 'Could not create the profile image folder.';
                        } else {
                            $fileName = 'client_' . $userId . '_' .
                                bin2hex(random_bytes(6)) . '.' .
                                $allowedTypes[$imageInfo[2]];

                            $destination = $uploadDirectory . $fileName;

                            if (
                                move_uploaded_file(
                                    $_FILES['profile_image']['tmp_name'],
                                    $destination
                                )
                            ) {
                                $newImagePath = 'assets/images/profiles/' . $fileName;
                                $profileImage = $newImagePath;
                            } else {
                                $error = 'Failed to save the profile image.';
                            }
                        }
                    }
                }
            }

            if ($error === '') {
                if ($newImagePath !== null) {
                    $sql = "UPDATE users
                            SET full_name = ?, phone = ?, location = ?, profile_image = ?
                            WHERE id = ? AND role = 'client'";

                    $stmt = $conn->prepare($sql);

                    if ($stmt) {
                        $stmt->bind_param(
                            'ssssi',
                            $fullName,
                            $phone,
                            $location,
                            $profileImage,
                            $userId
                        );
                    }
                } else {
                    $sql = "UPDATE users
                            SET full_name = ?, phone = ?, location = ?
                            WHERE id = ? AND role = 'client'";

                    $stmt = $conn->prepare($sql);

                    if ($stmt) {
                        $stmt->bind_param(
                            'sssi',
                            $fullName,
                            $phone,
                            $location,
                            $userId
                        );
                    }
                }

                if (isset($stmt) && $stmt) {
                    if ($stmt->execute()) {
                        $_SESSION['full_name'] = $fullName;

                        if ($newImagePath !== null) {
                            $_SESSION['profile_image'] = $newImagePath;
                        }

                        $success = 'Your profile has been updated successfully.';
                    } else {
                        $error = 'Unable to update your profile. Please try again.';
                    }

                    $stmt->close();
                } else {
                    $error = 'Unable to prepare the profile update.';
                }
            }
        }
    }

    /* Change password */
    elseif ($action === 'password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (
            $currentPassword === '' ||
            $newPassword === '' ||
            $confirmPassword === ''
        ) {
            $error = 'Please complete all password fields.';
        } elseif (!password_verify($currentPassword, $user['password'])) {
            $error = 'Your current password is incorrect.';
        } elseif (strlen($newPassword) < 8) {
            $error = 'Your new password must contain at least 8 characters.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'The new passwords do not match.';
        } else {
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

            $stmt = $conn->prepare(
                "UPDATE users
                 SET password = ?
                 WHERE id = ? AND role = 'client'"
            );

            if ($stmt) {
                $stmt->bind_param('si', $hashedPassword, $userId);

                if ($stmt->execute()) {
                    $success = 'Your password has been changed successfully.';
                } else {
                    $error = 'Unable to change your password.';
                }

                $stmt->close();
            } else {
                $error = 'Unable to prepare the password update.';
            }
        }
    } else {
        $error = 'Invalid settings request.';
    }

    $user = getClientSettings($conn, $userId) ?? $user;
}

/* Prepare display information */
$fullName = $user['full_name'] ?? 'Client';
$email = $user['email'] ?? '';
$phone = $user['phone'] ?? '';
$location = $user['location'] ?? '';
$profileImage = $user['profile_image'] ?? '';

$initials = '';
$nameParts = preg_split('/\s+/', trim($fullName));

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

$imageUrl = '';

if ($profileImage !== '') {
    if (preg_match('~^https?://~i', $profileImage)) {
        $imageUrl = $profileImage;
    } else {
        $imageUrl = '../' . ltrim($profileImage, '/');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Settings | HandyLink</title>

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">

    <link rel="stylesheet" href="../assets/css/clients/client.css">
    <link rel="stylesheet" href="../assets/css/clients/settings.css">
</head>
  <script src="../assets/js/client_darkmode.js"></script>
<body>
<div class="settings-page">

    <header class="settings-header">
        <a href="dashboard.php" class="settings-brand">
            <img src="../assets/images/handylink-logo.png"
                 alt="HandyLink Logo">
            <span>HandyLink</span>
        </a>

        <nav class="settings-top-nav" aria-label="Main navigation">
            <a href="dashboard.php">
                <i class="fa-solid fa-house"></i>
                <span>Dashboard</span>
            </a>

            <a href="find_services.php">
                <i class="fa-solid fa-magnifying-glass"></i>
                <span>Find Services</span>
            </a>

            <a href="my_request.php">
                <i class="fa-solid fa-clipboard-list"></i>
                <span>My Requests</span>
            </a>

            <a href="bookings.php">
                <i class="fa-solid fa-calendar-check"></i>
                <span>Bookings</span>
            </a>

            <a href="messages.php">
                <i class="fa-solid fa-envelope"></i>
                <span>Messages</span>
            </a>

            <a href="reviews.php">
                <i class="fa-solid fa-star"></i>
                <span>Reviews</span>
            </a>

            <a href="settings.php" class="active">
                <i class="fa-solid fa-gear"></i>
                <span>Settings</span>
            </a>
        </nav>

        <div class="settings-header-actions">
            <button type="button"
                    id="headerThemeToggle"
                    class="theme-toggle"
                    aria-label="Switch theme"
                    title="Switch theme">
                <i class="fa-solid fa-moon"></i>
            </button>

            <div class="header-user">
                <?php if ($imageUrl !== ''): ?>
                    <img src="<?= e($imageUrl) ?>"
                         alt="Profile"
                         class="header-avatar">
                <?php else: ?>
                    <span class="header-avatar initials-avatar">
                        <?= e($initials) ?>
                    </span>
                <?php endif; ?>

                <span class="header-user-name"><?= e($fullName) ?></span>
            </div>
        </div>
    </header>

    <main class="settings-main">
        <div class="settings-page-heading">
            <div>
                <p class="eyebrow">YOUR ACCOUNT</p>
                <h1>Settings</h1>
                <p class="heading-description">
                    Manage your profile, appearance and account security.
                </p>
            </div>

            <a href="dashboard.php" class="back-dashboard">
                <i class="fa-solid fa-arrow-left"></i>
                Back to Dashboard
            </a>
        </div>

        <?php if ($success !== ''): ?>
            <div class="settings-alert success-alert">
                <i class="fa-solid fa-circle-check"></i>
                <?= e($success) ?>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="settings-alert error-alert">
                <i class="fa-solid fa-circle-exclamation"></i>
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <section class="settings-welcome">
            <div class="welcome-icon">
                <i class="fa-solid fa-user-gear"></i>
            </div>

            <div>
                <h2>Account preferences</h2>
                <p>
                    Hello, <?= e($fullName) ?>. Keep your account information
                    up to date and choose how you prefer to use HandyLink.
                </p>
            </div>
        </section>

        <div class="settings-content-grid">

            <section class="settings-panel profile-panel">
                <div class="panel-heading">
                    <div class="panel-icon">
                        <i class="fa-solid fa-user"></i>
                    </div>

                    <div>
                        <h2>Profile Information</h2>
                        <p>Update the information associated with your account.</p>
                    </div>
                </div>

                <form method="POST"
                      action="settings.php"
                      enctype="multipart/form-data"
                      class="settings-form">

                    <input type="hidden" name="action" value="profile">

                    <div class="profile-preview">
                        <?php if ($imageUrl !== ''): ?>
                            <img src="<?= e($imageUrl) ?>"
                                 alt="Your profile picture"
                                 class="profile-preview-image">
                        <?php else: ?>
                            <div class="profile-preview-image initials-avatar">
                                <?= e($initials) ?>
                            </div>
                        <?php endif; ?>

                        <div>
                            <strong><?= e($fullName) ?></strong>
                            <p><?= e($email) ?></p>
                            <label for="profile_image" class="upload-label">
                                <i class="fa-solid fa-camera"></i>
                                Change profile picture
                            </label>
                        </div>
                    </div>

                    <div class="form-field">
                        <label for="profile_image">Profile image</label>
                        <input type="file"
                               id="profile_image"
                               name="profile_image"
                               accept=".jpg,.jpeg,.png,.webp">
                        <small>JPG, PNG or WEBP. Maximum size: 2 MB.</small>
                    </div>

                    <div class="form-field">
                        <label for="full_name">Full name</label>
                        <input type="text"
                               id="full_name"
                               name="full_name"
                               value="<?= e($fullName) ?>"
                               maxlength="150"
                               required>
                    </div>

                    <div class="form-field">
                        <label for="email">Email address</label>
                        <input type="email"
                               id="email"
                               value="<?= e($email) ?>"
                               readonly>
                        <small>Contact support if you need to change your email.</small>
                    </div>

                    <div class="form-field">
                        <label for="phone">Phone number</label>
                        <input type="tel"
                               id="phone"
                               name="phone"
                               value="<?= e($phone) ?>"
                               maxlength="30"
                               placeholder="Enter your phone number">
                    </div>

                    <div class="form-field">
                        <label for="location">Location</label>
                        <input type="text"
                               id="location"
                               name="location"
                               value="<?= e($location) ?>"
                               maxlength="255"
                               placeholder="e.g. Thika, Kenya">
                    </div>

                    <button type="submit" class="primary-settings-button">
                        <i class="fa-solid fa-floppy-disk"></i>
                        Save Profile Changes
                    </button>
                </form>
            </section>

            <div class="settings-right-column">

                <section class="settings-panel appearance-panel">
                    <div class="panel-heading">
                        <div class="panel-icon">
                            <i class="fa-solid fa-palette"></i>
                        </div>

                        <div>
                            <h2>Appearance</h2>
                            <p>Choose the theme you prefer.</p>
                        </div>
                    </div>

                    <div class="theme-options">
                        <button type="button"
                                class="theme-option"
                                data-theme-choice="light">
                            <span class="theme-preview light-preview">
                                <i class="fa-solid fa-sun"></i>
                            </span>

                            <span>
                                <strong>Light mode</strong>
                                <small>Bright and clear</small>
                            </span>

                            <i class="fa-solid fa-circle-check theme-check"></i>
                        </button>

                        <button type="button"
                                class="theme-option"
                                data-theme-choice="dark">
                            <span class="theme-preview dark-preview">
                                <i class="fa-solid fa-moon"></i>
                            </span>

                            <span>
                                <strong>Dark mode</strong>
                                <small>Comfortable in low light</small>
                            </span>

                            <i class="fa-solid fa-circle-check theme-check"></i>
                        </button>
                    </div>
                </section>

                <section class="settings-panel security-panel">
                    <div class="panel-heading">
                        <div class="panel-icon">
                            <i class="fa-solid fa-shield-halved"></i>
                        </div>

                        <div>
                            <h2>Password & Security</h2>
                            <p>Keep your account protected.</p>
                        </div>
                    </div>

                    <form method="POST"
                          action="settings.php"
                          class="settings-form">

                        <input type="hidden" name="action" value="password">

                        <div class="form-field">
                            <label for="current_password">Current password</label>
                            <input type="password"
                                   id="current_password"
                                   name="current_password"
                                   autocomplete="current-password"
                                   required>
                        </div>

                        <div class="form-field">
                            <label for="new_password">New password</label>
                            <input type="password"
                                   id="new_password"
                                   name="new_password"
                                   minlength="8"
                                   autocomplete="new-password"
                                   required>
                            <small>Use at least 8 characters.</small>
                        </div>

                        <div class="form-field">
                            <label for="confirm_password">Confirm new password</label>
                            <input type="password"
                                   id="confirm_password"
                                   name="confirm_password"
                                   minlength="8"
                                   autocomplete="new-password"
                                   required>
                        </div>

                        <button type="submit" class="primary-settings-button">
                            <i class="fa-solid fa-lock"></i>
                            Update Password
                        </button>
                    </form>
                </section>

                <section class="settings-panel help-panel">
                    <div class="panel-heading">
                        <div class="panel-icon">
                            <i class="fa-solid fa-circle-question"></i>
                        </div>

                        <div>
                            <h2>Help & Support</h2>
                            <p>Need assistance using HandyLink?</p>
                        </div>
                    </div>

                    <a href="help.php" class="help-link">
                        <span class="help-link-icon">
                            <i class="fa-solid fa-book-open"></i>
                        </span>

                        <span>
                            <strong>Help Centre</strong>
                            <small>Find answers to common questions.</small>
                        </span>

                        <i class="fa-solid fa-chevron-right"></i>
                    </a>

                    <a href="help.php#contact-support" class="help-link">
                        <span class="help-link-icon">
                            <i class="fa-solid fa-headset"></i>
                        </span>

                        <span>
                            <strong>Contact Support</strong>
                            <small>Get help with your account.</small>
                        </span>

                        <i class="fa-solid fa-chevron-right"></i>
                    </a>
                </section>

            </div>
        </div>

        <footer class="settings-footer">
            <p>&copy; <?= date('Y') ?> HandyLink. All rights reserved.</p>
        </footer>
    </main>
</div>

<script src="../assets/js/clients/theme.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const currentPassword = document.getElementById('current_password');
    const newPassword = document.getElementById('new_password');
    const confirmPassword = document.getElementById('confirm_password');

    const passwordForm = document.querySelector(
        'form input[name="action"][value="password"]'
    );

    if (passwordForm) {
        const form = passwordForm.closest('form');

        form.addEventListener('submit', function (event) {
            if (
                newPassword.value !== confirmPassword.value
            ) {
                event.preventDefault();
                alert('The new passwords do not match.');
                confirmPassword.focus();
                return;
            }

            if (newPassword.value.length < 8) {
                event.preventDefault();
                alert('Your new password must contain at least 8 characters.');
                newPassword.focus();
            }
        });
    }
});
</script>

</body>
</html>
