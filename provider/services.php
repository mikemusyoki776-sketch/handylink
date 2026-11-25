<?php
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/access_control.php';

requireApprovedProvider();

$user_id = (int) $_SESSION['user_id'];
$full_name = $_SESSION['full_name'] ?? 'Provider';

function serviceEscape($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/* Get provider ID */
$provider_stmt = $conn->prepare(
    "SELECT id FROM providers WHERE user_id = ? LIMIT 1"
);
$provider_stmt->bind_param("i", $user_id);
$provider_stmt->execute();

$provider_result = $provider_stmt->get_result();
$provider = $provider_result->fetch_assoc();
$provider_stmt->close();

if (!$provider) {
    die("Provider profile not found.");
}

$provider_id = (int) $provider['id'];

$initial = strtoupper(substr(trim($full_name), 0, 1));
$first_name = explode(' ', trim($full_name))[0] ?: 'Provider';

/* Load categories */
$categories = [];
$category_result = $conn->query(
    "SELECT id, name FROM categories ORDER BY name ASC"
);

if ($category_result) {
    while ($category = $category_result->fetch_assoc()) {
        $categories[] = $category;
    }
}

/* Check whether provider already has a service */
$count_stmt = $conn->prepare(
    "SELECT COUNT(*) AS total FROM services WHERE provider_id = ?"
);
$count_stmt->bind_param("i", $provider_id);
$count_stmt->execute();

$count_result = $count_stmt->get_result();
$service_count = (int) $count_result->fetch_assoc()['total'];
$count_stmt->close();

/* Handle service submission on this page */
$form_errors = [];
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'add_service') {

    if ($service_count > 0) {
        $form_errors[] = 'You already have a registered service.';
    } else {
        $category_id = filter_input(
            INPUT_POST,
            'category_id',
            FILTER_VALIDATE_INT
        );

        $service_name = trim($_POST['service_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $price_input = trim($_POST['price'] ?? '');
        $location = trim($_POST['location'] ?? '');

        if (!$category_id) {
            $form_errors[] = 'Please select a service category.';
        }

        $valid_category = false;

        foreach ($categories as $category) {
            if ((int) $category['id'] === (int) $category_id) {
                $valid_category = true;
                break;
            }
        }

        if (!$valid_category) {
            $form_errors[] = 'The selected category is invalid.';
        }

        if ($service_name === '') {
            $form_errors[] = 'Please enter the service name.';
        }

        if ($description === '') {
            $form_errors[] = 'Please enter a service description.';
        }

        if ($price_input === '' ||
            !is_numeric($price_input) ||
            (float) $price_input < 0) {
            $form_errors[] = 'Enter a valid price of zero or more.';
        }

        if ($location === '') {
            $form_errors[] = 'Please enter your service location.';
        }

        if (empty($form_errors)) {
          $price = (float) $price_input;
          $status = 'active';

            $insert = $conn->prepare(
                "INSERT INTO services
                    (provider_id, category_id, service_name,
                     description, price, location, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
            );

            if (!$insert) {
                $form_errors[] = 'Unable to prepare the service. Please try again.';
            } else {
                $insert->bind_param(
                    "iissdss",
                    $provider_id,
                    $category_id,
                    $service_name,
                    $description,
                    $price,
                    $location,
                    $status
                );

                if ($insert->execute()) {
                    $success_message = 'Your service was submitted successfully for administrator review.';
                    $service_count++;
                    $_GET['add'] = null;
                } else {
                    $form_errors[] = 'Unable to save the service. Please try again.';
                }

                $insert->close();
            }
        }
    }
}

/* Fetch provider services */
$sql = "SELECT
            s.id,
            s.service_name,
            s.description,
            s.price,
            s.location,
            s.status,
            c.name AS category_name
        FROM services s
        INNER JOIN categories c ON s.category_id = c.id
        WHERE s.provider_id = ?
        ORDER BY s.created_at DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $provider_id);
$stmt->execute();

$result = $stmt->get_result();
$show_form = isset($_GET['add']) &&
             $_GET['add'] === '1' &&
             $service_count === 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Services - HandyLink</title>

    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/provider_dashboard.css">
    <link rel="stylesheet" href="../assets/css/provider_services.css">
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

    <style>
        .inline-service-form {
            background: var(--card-bg, #fff);
            border: 1px solid #dce5dd;
            border-radius: 14px;
            padding: 25px;
            margin: 25px 0;
        }

        .inline-service-form h2 {
            margin-top: 0;
        }

        .service-form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }

        .service-form-group {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        .service-form-group.full-width {
            grid-column: 1 / -1;
        }

        .service-form-group label {
            font-weight: 600;
        }

        .service-form-group input,
        .service-form-group select,
        .service-form-group textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #cbd5cc;
            border-radius: 8px;
            font: inherit;
        }

        .service-form-group textarea {
            min-height: 120px;
            resize: vertical;
        }

        .service-form-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 20px;
        }

        .service-form-actions button,
        .service-form-actions a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 18px;
            border: 0;
            border-radius: 8px;
            text-decoration: none;
            cursor: pointer;
            font-weight: 600;
        }

        .service-submit-button {
            background: #052f0c;
            color: #fff;
        }

        .service-cancel-button {
            background: #edf1ed;
            color: #26372a;
        }

        .services-inline-alert {
            padding: 14px 16px;
            margin: 18px 0;
            border-radius: 8px;
            line-height: 1.6;
        }

        .services-inline-alert.success {
            background: #e8f6eb;
            color: #155724;
            border: 1px solid #b8dfc1;
        }

        .services-inline-alert.error {
            background: #fff0f0;
            color: #8a1f1f;
            border: 1px solid #f0caca;
        }

        .services-inline-alert ul {
            margin: 8px 0 0;
            padding-left: 22px;
        }

        @media (max-width: 600px) {
            .service-form-grid {
                grid-template-columns: 1fr;
            }

            .service-form-group.full-width {
                grid-column: auto;
            }

            .inline-service-form {
                padding: 18px;
            }
        }
    </style>
</head>

<body class="provider-services-body">

<header class="provider-header">
    <div class="header-left">
        <a href="dashboard.php" class="brand">
            <img src="../assets/images/handylink-logo.png"
                 alt="HandyLink Logo"
                 class="brand-logo">
        </a>

        <button type="button"
                class="mobile-menu-toggle"
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

        <a href="services.php" class="active" aria-current="page">
            <i class="fas fa-screwdriver-wrench"></i><span>My Services</span>
        </a>

        <a href="requests.php">
            <i class="fas fa-clipboard-list"></i><span>Requests</span>
        </a>

        <a href="bookings.php">
            <i class="fas fa-calendar-check"></i><span>Bookings</span>
        </a>

        <a href="availability.php">
            <i class="fas fa-clock"></i><span>Availability</span>
        </a>

        <a href="reviews.php">
            <i class="fas fa-star"></i><span>Reviews</span>
        </a>

        <a href="messages.php">
            <i class="fas fa-comments"></i><span>Messages</span>
        </a>

        <a href="notification.php">
            <i class="fas fa-bell"></i><span>Notifications</span>
        </a>
    </nav>

    <div class="header-right">
        <div class="profile-menu">
            <button type="button"
                    class="profile-trigger"
                    id="profileTrigger"
                    aria-expanded="false">

                <span class="profile-avatar">
                    <?php if (!empty($_SESSION['profile_image'])): ?>
                        <img src="../<?php echo serviceEscape($_SESSION['profile_image']); ?>"
                             alt="Profile picture">
                    <?php else: ?>
                        <span class="profile-initial">
                            <?php echo serviceEscape($initial); ?>
                        </span>
                    <?php endif; ?>
                </span>

                <span class="profile-name">
                    <?php echo serviceEscape($first_name); ?>
                </span>

                <i class="fas fa-chevron-down"></i>
            </button>

            <div class="profile-dropdown" id="profileDropdown">
                <div class="dropdown-user-info">
                    <strong><?php echo serviceEscape($full_name); ?></strong>
                    <span>Service Provider</span>
                </div>

                <a href="profile.php">
                    <i class="fas fa-user"></i> My Profile
                </a>

                <a href="settings.php">
                    <i class="fas fa-gear"></i> Settings
                </a>

                <button type="button" id="darkModeToggle">
                    <i class="fas fa-moon"></i>
                    <span>Toggle dark mode</span>
                </button>

                <a href="help.php">
                    <i class="fas fa-circle-question"></i> Help &amp; Support
                </a>

                <a href="../actions/logout.php" class="dropdown-logout">
                    <i class="fas fa-right-from-bracket"></i> Logout
                </a>
            </div>
        </div>
    </div>
</header>

<main class="provider-services-page">

    <section class="services-page-heading">
        <div class="services-heading-text">
            <span class="services-eyebrow">
                <i class="fas fa-screwdriver-wrench"></i>
                PROVIDER WORKSPACE
            </span>

            <h1>My Services</h1>

            <p>
                Manage the service you offer and help clients find the right
                service for their needs.
            </p>
        </div>

        <?php if ($service_count === 0 && !$show_form): ?>
            <a href="services.php?add=1" class="add-service-button">
                <i class="fas fa-plus"></i> Add Service
            </a>
        <?php endif; ?>
    </section>

    <section class="services-summary">
        <div class="services-summary-icon">
            <i class="fas fa-list-check"></i>
        </div>

        <div class="services-summary-text">
            <span>My listed services</span>
            <strong><?php echo $service_count; ?></strong>
            <small>
                <?php echo $service_count === 1
                    ? 'Service registered'
                    : 'Services registered'; ?>
            </small>
        </div>
    </section>

    <?php if ($success_message !== ''): ?>
        <div class="services-inline-alert success">
            <i class="fas fa-circle-check"></i>
            <?php echo serviceEscape($success_message); ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($form_errors)): ?>
        <div class="services-inline-alert error">
            <strong>Please correct the following:</strong>
            <ul>
                <?php foreach ($form_errors as $error): ?>
                    <li><?php echo serviceEscape($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($show_form || ($_SERVER['REQUEST_METHOD'] === 'POST' &&
        ($_POST['action'] ?? '') === 'add_service' &&
        !empty($form_errors))): ?>

        <section class="inline-service-form" id="serviceForm">
            <h2>Add Your Service</h2>
            <p>Complete the details below. Your service may require administrator approval.</p>

            <form method="POST" action="services.php?add=1">
                <input type="hidden" name="action" value="add_service">

                <div class="service-form-grid">

                    <div class="service-form-group">
                        <label for="category_id">Service category</label>
                        <select name="category_id" id="category_id" required>
                            <option value="">Select category</option>

                            <?php foreach ($categories as $category): ?>
                                <option
                                    value="<?php echo (int) $category['id']; ?>"
                                    <?php echo (
                                        (string) ($_POST['category_id'] ?? '') ===
                                        (string) $category['id']
                                    ) ? 'selected' : ''; ?>>
                                    <?php echo serviceEscape($category['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="service-form-group">
                        <label for="service_name">Service name</label>
                        <input
                            type="text"
                            name="service_name"
                            id="service_name"
                            maxlength="150"
                            required
                            value="<?php echo serviceEscape($_POST['service_name'] ?? ''); ?>"
                            placeholder="e.g. Electrical Wiring">
                    </div>

                    <div class="service-form-group">
                        <label for="price">Starting price (KSh)</label>
                        <input
                            type="number"
                            name="price"
                            id="price"
                            min="0"
                            step="0.01"
                            required
                            value="<?php echo serviceEscape($_POST['price'] ?? ''); ?>"
                            placeholder="Enter price">
                    </div>

                    <div class="service-form-group">
                        <label for="location">Service location</label>
                        <input
                            type="text"
                            name="location"
                            id="location"
                            maxlength="255"
                            required
                            value="<?php echo serviceEscape($_POST['location'] ?? ''); ?>"
                            placeholder="e.g. Nairobi">
                    </div>

                    <div class="service-form-group full-width">
                        <label for="description">Service description</label>
                        <textarea
                            name="description"
                            id="description"
                            required
                            placeholder="Describe the service you provide..."><?php
                                echo serviceEscape($_POST['description'] ?? '');
                            ?></textarea>
                    </div>

                </div>

                <div class="service-form-actions">
                    <button type="submit" class="service-submit-button">
                        <i class="fas fa-check"></i> Submit Service
                    </button>

                    <a href="services.php" class="service-cancel-button">
                        Cancel
                    </a>
                </div>
            </form>
        </section>

    <?php endif; ?>

    <section class="services-list-section">
        <div class="services-section-heading">
            <div>
                <h2>Your service listings</h2>
                <p>View the service registered on your provider profile.</p>
            </div>

            <span class="services-count">
                <?php echo $service_count; ?>
                <?php echo $service_count === 1 ? 'service' : 'services'; ?>
            </span>
        </div>

        <?php if ($service_count > 0): ?>

            <div class="services-grid">

                <?php while ($service = $result->fetch_assoc()): ?>
                    <?php
                    $status = strtolower(trim((string) $service['status']));
                    $status_class = preg_replace('/[^a-z0-9_-]/', '', $status);
                    $status_label = ucfirst($status ?: 'unknown');
                    ?>

                    <article class="service-card">
                        <div class="service-card-top">
                            <div class="service-card-icon">
                                <i class="fas fa-screwdriver-wrench"></i>
                            </div>

                            <span class="service-status status-<?php
                                echo serviceEscape($status_class);
                            ?>">
                                <span class="status-dot"></span>
                                <?php echo serviceEscape($status_label); ?>
                            </span>
                        </div>

                        <div class="service-card-content">
                            <span class="service-category">
                                <?php echo serviceEscape($service['category_name']); ?>
                            </span>

                            <h3>
                                <?php echo serviceEscape($service['service_name']); ?>
                            </h3>

                            <p class="service-description">
                                <?php
                                $description = trim((string) $service['description']);

                                echo $description !== ''
                                    ? nl2br(serviceEscape($description))
                                    : 'No description has been added yet.';
                                ?>
                            </p>

                            <div class="service-detail">
                                <i class="fas fa-money-bill-wave"></i>
                                <div>
                                    <small>Starting price</small>
                                    <strong>
                                        KSh <?php echo number_format(
                                            (float) $service['price'],
                                            2
                                        ); ?>
                                    </strong>
                                </div>
                            </div>

                            <div class="service-detail">
                                <i class="fas fa-location-dot"></i>
                                <div>
                                    <small>Service location</small>
                                    <strong>
                                        <?php echo serviceEscape(
                                            $service['location'] ?: 'Not specified'
                                        ); ?>
                                    </strong>
                                </div>
                            </div>
                        </div>

                        <div class="service-card-actions">
                            <div class="service-restriction-notice">
                                <i class="fas fa-lock"></i>
                                <span>
                                    Editing or deleting this service requires
                                    administrator authorization.
                                </span>
                            </div>
                        </div>
                    </article>

                <?php endwhile; ?>

            </div>

        <?php else: ?>

            <div class="services-empty-state">
                <div class="empty-state-icon">
                    <i class="fas fa-screwdriver-wrench"></i>
                </div>

                <h2>No services added yet</h2>

                <p>
                    You haven't listed a service. Add your first service so
                    clients can learn what you offer.
                </p>

                <a href="services.php?add=1" class="add-service-button">
                    <i class="fas fa-plus"></i> Add Your First Service
                </a>
            </div>

        <?php endif; ?>
    </section>

</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const menuToggle = document.getElementById('mobileMenuToggle');
    const mainNav = document.getElementById('mainNav');
    const profileTrigger = document.getElementById('profileTrigger');
    const profileDropdown = document.getElementById('profileDropdown');
    const darkModeToggle = document.getElementById('darkModeToggle');

    if (menuToggle && mainNav) {
        menuToggle.addEventListener('click', function () {
            const isOpen = mainNav.classList.toggle('show');
            menuToggle.setAttribute('aria-expanded', String(isOpen));

            const icon = menuToggle.querySelector('i');

            if (icon) {
                icon.classList.toggle('fa-bars', !isOpen);
                icon.classList.toggle('fa-xmark', isOpen);
            }
        });
    }

    if (profileTrigger && profileDropdown) {
        profileTrigger.addEventListener('click', function (event) {
            event.stopPropagation();

            const isOpen = profileDropdown.classList.toggle('show');
            profileTrigger.setAttribute('aria-expanded', String(isOpen));
        });

        profileDropdown.addEventListener('click', function (event) {
            event.stopPropagation();
        });

        document.addEventListener('click', function () {
            profileDropdown.classList.remove('show');
            profileTrigger.setAttribute('aria-expanded', 'false');
        });
    }

    function applyTheme(theme) {
        document.body.classList.toggle('dark-mode', theme === 'dark');

        if (darkModeToggle) {
            const icon = darkModeToggle.querySelector('i');
            const label = darkModeToggle.querySelector('span');

            if (icon) {
                icon.className = theme === 'dark'
                    ? 'fas fa-sun'
                    : 'fas fa-moon';
            }

            if (label) {
                label.textContent = theme === 'dark'
                    ? 'Toggle light mode'
                    : 'Toggle dark mode';
            }
        }
    }

    let savedTheme = 'light';

    try {
        savedTheme = localStorage.getItem('handylink-provider-theme') || 'light';
    } catch (error) {
        savedTheme = 'light';
    }

    applyTheme(savedTheme);

    if (darkModeToggle) {
        darkModeToggle.addEventListener('click', function () {
            const newTheme = document.body.classList.contains('dark-mode')
                ? 'light'
                : 'dark';

            applyTheme(newTheme);

            try {
                localStorage.setItem('handylink-provider-theme', newTheme);
            } catch (error) {
                // Theme still works for this page.
            }
        });
    }
});
</script>

</body>
</html>

<?php
$stmt->close();
$conn->close();
?>
