
<?php
require_once __DIR__ . '/access_control.php';
requireApprovedProvider();

global $conn;

$userId = (int) $_SESSION['user_id'];

$message = '';
$messageType = '';

/* Fetch provider and account information */
$stmt = $conn->prepare("
    SELECT
        u.full_name,
        u.email,
        u.phone,
        u.location,
        u.profile_image,
        u.password,
        p.bio,
        p.experience
    FROM users u
    INNER JOIN providers p ON p.user_id = u.id
    WHERE u.id = ?
    LIMIT 1
");

$stmt->bind_param("i", $userId);
$stmt->execute();
$provider = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$provider) {
    die("Provider profile could not be found.");
}

/* CSRF protection */
if (empty($_SESSION['settings_csrf'])) {
    $_SESSION['settings_csrf'] = bin2hex(random_bytes(32));
}

/* Handle form submissions */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['settings_csrf'], $_POST['csrf_token'])
    ) {
        $message = "Invalid request. Please refresh the page and try again.";
        $messageType = "error";

    } else {

        $action = $_POST['action'] ?? '';

        /* Update personal and professional profile */
        if ($action === 'update_profile') {

            $fullName = trim($_POST['full_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $location = trim($_POST['location'] ?? '');
            $bio = trim($_POST['bio'] ?? '');
            $experience = trim($_POST['experience'] ?? '');

            if ($fullName === '' || $email === '') {
                $message = "Full name and email are required.";
                $messageType = "error";

            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $message = "Please enter a valid email address.";
                $messageType = "error";

            } else {

                /* Check whether another account uses this email */
                $check = $conn->prepare("
                    SELECT id FROM users
                    WHERE email = ? AND id != ?
                    LIMIT 1
                ");
                $check->bind_param("si", $email, $userId);
                $check->execute();
                $emailExists = $check->get_result()->num_rows > 0;
                $check->close();

                if ($emailExists) {
                    $message = "That email address is already in use.";
                    $messageType = "error";

                } else {

                    $stmt = $conn->prepare("
                        UPDATE users
                        SET full_name = ?, email = ?, phone = ?, location = ?
                        WHERE id = ?
                    ");
                    $stmt->bind_param(
                        "ssssi",
                        $fullName,
                        $email,
                        $phone,
                        $location,
                        $userId
                    );

                    $userUpdated = $stmt->execute();
                    $stmt->close();

                    if ($userUpdated) {
                        $stmt = $conn->prepare("
                            UPDATE providers
                            SET bio = ?, experience = ?
                            WHERE user_id = ?
                        ");
                        $stmt->bind_param("ssi", $bio, $experience, $userId);
                        $providerUpdated = $stmt->execute();
                        $stmt->close();

                        if ($providerUpdated) {
                            $message = "Your profile has been updated successfully.";
                            $messageType = "success";
                        } else {
                            $message = "Your account details were saved, but your professional profile could not be updated.";
                            $messageType = "error";
                        }
                    } else {
                        $message = "Unable to update your profile. Please try again.";
                        $messageType = "error";
                    }
                }
            }
        }

        /* Update profile photo */
        elseif ($action === 'update_photo') {

            if (
                !isset($_FILES['profile_photo']) ||
                $_FILES['profile_photo']['error'] !== UPLOAD_ERR_OK
            ) {
                $message = "Please select a valid image.";
                $messageType = "error";

            } else {

                $file = $_FILES['profile_photo'];

                if ($file['size'] > 2 * 1024 * 1024) {
                    $message = "Image size must not exceed 2MB.";
                    $messageType = "error";

                } else {

                    $imageInfo = getimagesize($file['tmp_name']);
                    $allowedTypes = [
                        IMAGETYPE_JPEG => 'jpg',
                        IMAGETYPE_PNG => 'png',
                        IMAGETYPE_WEBP => 'webp'
                    ];

                    if (
                        !$imageInfo ||
                        !isset($allowedTypes[$imageInfo[2]])
                    ) {
                        $message = "Only JPG, PNG and WEBP images are allowed.";
                        $messageType = "error";

                    } else {

                        $uploadDirectory = __DIR__ . '/../assets/images/providers/';

                        if (
                            !is_dir($uploadDirectory) &&
                            !mkdir($uploadDirectory, 0755, true)
                        ) {
                            $message = "Unable to prepare the image upload directory.";
                            $messageType = "error";

                        } else {

                            $extension = $allowedTypes[$imageInfo[2]];
                            $fileName = 'provider_' . $userId . '_' .
                                bin2hex(random_bytes(8)) . '.' . $extension;

                            $destination = $uploadDirectory . $fileName;
                            $databasePath = 'assets/images/providers/' . $fileName;

                            if (move_uploaded_file($file['tmp_name'], $destination)) {

                                $stmt = $conn->prepare("
                                    UPDATE users
                                    SET profile_image = ?
                                    WHERE id = ?
                                ");
                                $stmt->bind_param("si", $databasePath, $userId);
                                $photoUpdated = $stmt->execute();
                                $stmt->close();

                                if ($photoUpdated) {
                                    $message = "Profile photo updated successfully.";
                                    $messageType = "success";
                                } else {
                                    @unlink($destination);
                                    $message = "Unable to save your profile photo.";
                                    $messageType = "error";
                                }

                            } else {
                                $message = "Image upload failed. Please try again.";
                                $messageType = "error";
                            }
                        }
                    }
                }
            }
        }

        /* Change password */
        elseif ($action === 'change_password') {

            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            if (
                $currentPassword === '' ||
                $newPassword === '' ||
                $confirmPassword === ''
            ) {
                $message = "Please complete all password fields.";
                $messageType = "error";

            } elseif (!password_verify($currentPassword, $provider['password'])) {
                $message = "Your current password is incorrect.";
                $messageType = "error";

            } elseif (strlen($newPassword) < 8) {
                $message = "Your new password must contain at least 8 characters.";
                $messageType = "error";

            } elseif ($newPassword !== $confirmPassword) {
                $message = "The new passwords do not match.";
                $messageType = "error";

            } else {

                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

                $stmt = $conn->prepare("
                    UPDATE users
                    SET password = ?
                    WHERE id = ?
                ");
                $stmt->bind_param("si", $hashedPassword, $userId);
                $passwordUpdated = $stmt->execute();
                $stmt->close();

                if ($passwordUpdated) {
                    $message = "Your password has been changed successfully.";
                    $messageType = "success";
                } else {
                    $message = "Unable to change your password.";
                    $messageType = "error";
                }
            }
        }

        /* Refresh profile information after a POST */
        $stmt = $conn->prepare("
            SELECT
                u.full_name,
                u.email,
                u.phone,
                u.location,
                u.profile_image,
                u.password,
                p.bio,
                p.experience
            FROM users u
            INNER JOIN providers p ON p.user_id = u.id
            WHERE u.id = ?
            LIMIT 1
        ");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $provider = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        /* Rotate token after processing */
        $_SESSION['settings_csrf'] = bin2hex(random_bytes(32));
    }
}

$photoPath = !empty($provider['profile_image'])
    ? '../' . ltrim($provider['profile_image'], '/')
    : '../assets/images/default-avatar.png';

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Settings & Profile | HandyLink</title>

    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/provider_dashboard.css">
    <link rel="stylesheet" href="../assets/css/provider_settings.css">

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

<header class="settings-header">
    <div class="settings-header-inner">
        <a href="dashboard.php" class="settings-brand">
            <img src="../assets/images/handylink-logo.png" alt="HandyLink">
            <span>HandyLink</span>
        </a>

        <nav class="settings-nav">
            <a href="dashboard.php">
                <i class="fa-solid fa-house"></i> Dashboard
            </a>

            <a href="settings.php" class="active">
                <i class="fa-solid fa-user-gear"></i> Settings & Profile
            </a>
        </nav>
    </div>
</header>

<main class="settings-container">

    <div class="settings-heading">
        <div>
            <h1>Settings & Profile</h1>
            <p>Manage your account information and professional profile.</p>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div class="settings-alert <?= htmlspecialchars($messageType) ?>">
            <i class="fa-solid <?= $messageType === 'success'
                ? 'fa-circle-check'
                : 'fa-circle-exclamation' ?>"></i>
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <section class="profile-card">

        <div class="profile-photo-section">
            <img
               src="<?= htmlspecialchars($photoPath) ?>"
               alt="Provider profile photo"
               class="profile-photo"
               onerror="this.onerror=null; this.src='../assets/images/handylink-logo.png';">
            <div class="profile-photo-info">
                <h2><?= htmlspecialchars($provider['full_name']) ?></h2>
                <p>Service Provider</p>
            </div>

            <form method="POST" enctype="multipart/form-data" class="photo-form">
                <input type="hidden" name="csrf_token"
                    value="<?= htmlspecialchars($_SESSION['settings_csrf']) ?>">
                <input type="hidden" name="action" value="update_photo">

                <label for="profile_photo" class="photo-select">
                    <i class="fa-solid fa-camera"></i> Choose Photo
                </label>

                <input
                    type="file"
                    name="profile_photo"
                    id="profile_photo"
                    accept="image/jpeg,image/png,image/webp"
                    required>

                <button type="submit" class="small-save-button">
                    Upload Photo
                </button>

                <small>JPG, PNG or WEBP. Maximum size: 2MB.</small>
            </form>
        </div>

    </section>

    <section class="settings-card">
        <div class="section-heading">
            <div class="section-icon">
                <i class="fa-solid fa-user"></i>
            </div>
            <div>
                <h2>Personal Information</h2>
                <p>Update your account and contact details.</p>
            </div>
        </div>

        <form method="POST">
            <input type="hidden" name="csrf_token"
                value="<?= htmlspecialchars($_SESSION['settings_csrf']) ?>">
            <input type="hidden" name="action" value="update_profile">

            <div class="settings-form-grid">

                <div class="form-group">
                    <label for="full_name">Full Name</label>
                    <input type="text" id="full_name" name="full_name"
                        value="<?= htmlspecialchars($provider['full_name']) ?>"
                        required>
                </div>

                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email"
                        value="<?= htmlspecialchars($provider['email']) ?>"
                        required>
                </div>

                <div class="form-group">
                    <label for="phone">Phone Number</label>
                    <input type="text" id="phone" name="phone"
                        value="<?= htmlspecialchars($provider['phone'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label for="location">Location</label>
                    <input type="text" id="location" name="location"
                        value="<?= htmlspecialchars($provider['location'] ?? '') ?>">
                </div>

            </div>

            <div class="form-group full-width">
                <label for="bio">Professional Bio</label>
                <textarea id="bio" name="bio" rows="4"
                    placeholder="Tell clients about your professional background..."><?= htmlspecialchars($provider['bio'] ?? '') ?></textarea>
            </div>

            <div class="form-group full-width">
                <label for="experience">Professional Experience</label>
                <textarea id="experience" name="experience" rows="3"
                    placeholder="Describe your work experience..."><?= htmlspecialchars($provider['experience'] ?? '') ?></textarea>
            </div>

            <button type="submit" class="primary-save-button">
                <i class="fa-solid fa-floppy-disk"></i>
                Save Changes
            </button>
        </form>
    </section>

    <section class="settings-card">
        <div class="section-heading">
            <div class="section-icon">
                <i class="fa-solid fa-lock"></i>
            </div>
            <div>
                <h2>Change Password</h2>
                <p>Update your password to keep your account secure.</p>
            </div>
        </div>

        <form method="POST">
            <input type="hidden" name="csrf_token"
                value="<?= htmlspecialchars($_SESSION['settings_csrf']) ?>">
            <input type="hidden" name="action" value="change_password">

            <div class="settings-form-grid">

                <div class="form-group full-width">
                    <label for="current_password">Current Password</label>
                    <input type="password" id="current_password"
                        name="current_password" required>
                </div>

                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <input type="password" id="new_password"
                        name="new_password" minlength="8" required>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <input type="password" id="confirm_password"
                        name="confirm_password" minlength="8" required>
                </div>

            </div>

            <button type="submit" class="primary-save-button">
                <i class="fa-solid fa-key"></i>
                Update Password
            </button>
        </form>
    </section>

</main>

</body>
</html>
