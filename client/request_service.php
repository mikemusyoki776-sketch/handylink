<?php
session_start();

if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'client'
) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../config/database.php';

$clientId = (int) $_SESSION['user_id'];

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection failed.');
}

$serviceId = filter_input(INPUT_GET, 'service_id', FILTER_VALIDATE_INT);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $serviceId = filter_input(INPUT_POST, 'service_id', FILTER_VALIDATE_INT);
}

if (!$serviceId || $serviceId < 1) {
    die('Invalid service selected. Please return to Find Services and select a service.');
}

$message = '';
$error = '';

/* Load the selected service and approved provider */
$sql = "
    SELECT
        s.id,
        s.provider_id,
        s.category_id,
        s.service_name,
        s.description,
        s.price,
        s.location,
        u.full_name AS provider_name
    FROM services s
    INNER JOIN providers p ON p.id = s.provider_id
    INNER JOIN users u ON u.id = p.user_id
    WHERE s.id = ?
      AND p.verification_status = 'approved'
      AND u.role = 'provider'
      AND (u.status = 'active' OR u.status IS NULL)
      AND (s.status = 'active' OR s.status IS NULL)
    LIMIT 1
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die('Could not load the selected service: ' . htmlspecialchars($conn->error));
}

$stmt->bind_param('i', $serviceId);
$stmt->execute();
$result = $stmt->get_result();
$service = $result->fetch_assoc();
$stmt->close();

if (!$service) {
    die('This service is unavailable or its provider has not been approved. Please return to Find Services.');
}

/* Submit the request */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $description = trim($_POST['description'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $preferredDate = trim($_POST['preferred_date'] ?? '');
    $preferredTime = trim($_POST['preferred_time'] ?? '');

    if ($description === '' || $location === '') {
        $error = 'Please enter a description of the work and the service location.';
    } elseif (mb_strlen($description) > 10000) {
        $error = 'Your description is too long.';
    } elseif (mb_strlen($location) > 150) {
        $error = 'The location must not exceed 150 characters.';
    } else {
        $dateValue = null;
        $timeValue = null;

        if ($preferredDate !== '') {
            $dateObject = DateTime::createFromFormat('!Y-m-d', $preferredDate);

            if (!$dateObject || $dateObject->format('Y-m-d') !== $preferredDate) {
                $error = 'Please enter a valid preferred date.';
            } elseif ($preferredDate < date('Y-m-d')) {
                $error = 'The preferred date cannot be in the past.';
            } else {
                $dateValue = $preferredDate;
            }
        }

        if ($preferredTime !== '') {
            $timeObject = DateTime::createFromFormat('!H:i', $preferredTime);

            if (!$timeObject || $timeObject->format('H:i') !== $preferredTime) {
                $error = 'Please enter a valid preferred time.';
            } else {
                $timeValue = $preferredTime . ':00';
            }
        }

        if ($error === '') {
            $title = $service['service_name'];
            $categoryId = (int) $service['category_id'];

            $insertSql = "
                INSERT INTO service_requests
                (
                    client_id,
                    category_id,
                    title,
                    description,
                    location,
                    preferred_date,
                    preferred_time
                )
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ";

            $insertStmt = $conn->prepare($insertSql);

            if (!$insertStmt) {
                $error = 'Could not prepare your request. Please try again.';
            } else {
                $insertStmt->bind_param(
                    'iisssss',
                    $clientId,
                    $categoryId,
                    $title,
                    $description,
                    $location,
                    $dateValue,
                    $timeValue
                );

                if ($insertStmt->execute()) {
                    $insertStmt->close();

                    header('Location: my_request.php?submitted=1');
                    exit;
                }

                error_log('HandyLink request submission error: ' . $insertStmt->error);
                $error = 'Your request could not be submitted. Please try again.';
                $insertStmt->close();
            }
        }
    }
}

$pageTitle = 'Request Service';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> | HandyLink</title>
    <link rel="stylesheet" href="../assets/css/clients/client.css">
    <style>
        .request-page {
            max-width: 850px;
            margin: 30px auto;
            padding: 20px;
        }

        .request-card {
            border: 1px solid #ddd;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 22px;
            background: #fff;
            color: #222;
        }

        .request-card h2 {
            margin-top: 0;
        }

        .service-detail {
            margin: 12px 0;
            line-height: 1.6;
            overflow-wrap: anywhere;
        }

        .request-form label {
            display: block;
            font-weight: 600;
            margin: 16px 0 7px;
        }

        .request-form input,
        .request-form textarea {
            box-sizing: border-box;
            width: 100%;
            padding: 12px;
            border: 1px solid #ccc;
            border-radius: 7px;
            font: inherit;
        }

        .request-form textarea {
            min-height: 120px;
            resize: vertical;
        }

        .request-button {
            display: inline-block;
            border: none;
            border-radius: 7px;
            padding: 12px 20px;
            margin-top: 20px;
            background: #08752b;
            color: white;
            font-size: 16px;
            cursor: pointer;
        }

        .back-link {
            display: inline-block;
            margin-bottom: 18px;
            color: #08752b;
        }

        .error-message {
            padding: 12px;
            margin-bottom: 15px;
            border-radius: 7px;
            background: #ffe8e8;
            color: #a00000;
        }

        @media (max-width: 600px) {
            .request-page {
                margin: 12px auto;
                padding: 12px;
            }

            .request-card {
                padding: 17px;
            }
        }
    </style>
    <script src="../assets/js/client_darkmode.js" defer></script>
</head>
<body>
    <main class="request-page">
        <a class="back-link" href="find_services.php">&larr; Back to Find Services</a>

        <section class="request-card">
            <h2>Selected Service</h2>

            <div class="service-detail">
                <strong>Service:</strong>
                <?= htmlspecialchars($service['service_name']) ?>
            </div>

            <div class="service-detail">
                <strong>Provider:</strong>
                <?= htmlspecialchars($service['provider_name']) ?>
            </div>

            <div class="service-detail">
                <strong>Description:</strong><br>
                <?= nl2br(htmlspecialchars($service['description'] ?: 'No description provided.')) ?>
            </div>

            <div class="service-detail">
                <strong>Starting price:</strong>
                <?php if ($service['price'] !== null && $service['price'] !== ''): ?>
                    KSh <?= number_format((float) $service['price'], 2) ?>
                <?php else: ?>
                    To be agreed with the provider
                <?php endif; ?>
            </div>

            <div class="service-detail">
                <strong>Service location:</strong>
                <?= htmlspecialchars($service['location'] ?: 'Not specified') ?>
            </div>
        </section>

        <section class="request-card">
            <h2>Request This Service</h2>
            <p>Describe the work you need and where the service should be provided.</p>

            <?php if ($error !== ''): ?>
                <div class="error-message">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form class="request-form" method="POST" action="request_service.php">
                <input type="hidden" name="service_id"
                       value="<?= (int) $service['id'] ?>">

                <label for="description">Describe the work needed *</label>
                <textarea id="description" name="description" required
                          maxlength="10000"
                          placeholder="Explain the work you need done..."><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>

                <label for="location">Where should the service be provided? *</label>
                <input type="text" id="location" name="location" required
                       maxlength="150"
                       value="<?= htmlspecialchars($_POST['location'] ?? $service['location'] ?? '') ?>"
                       placeholder="Enter your location">

                <label for="preferred_date">Preferred date (optional)</label>
                <input type="date" id="preferred_date" name="preferred_date"
                       min="<?= date('Y-m-d') ?>"
                       value="<?= htmlspecialchars($_POST['preferred_date'] ?? '') ?>">

                <label for="preferred_time">Preferred time (optional)</label>
                <input type="time" id="preferred_time" name="preferred_time"
                       value="<?= htmlspecialchars($_POST['preferred_time'] ?? '') ?>">

                <button type="submit" class="request-button">
                    Submit Service Request
                </button>
            </form>
        </section>
    </main>
</body>
</html>