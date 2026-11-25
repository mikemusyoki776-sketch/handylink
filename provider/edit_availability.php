<?php

session_start();

if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "provider") {
    header("Location: ../login.php");
    exit();
}

require_once "../config/database.php";

$user_id = $_SESSION["user_id"];

$provider_sql = "SELECT id FROM providers WHERE user_id = ? LIMIT 1";

$provider_stmt = $conn->prepare($provider_sql);
$provider_stmt->bind_param("i", $user_id);
$provider_stmt->execute();

$provider_result = $provider_stmt->get_result();

if ($provider_result->num_rows === 0) {
    die("Provider profile not found.");
}

$provider = $provider_result->fetch_assoc();
$provider_id = $provider["id"];

$provider_stmt->close();

$availability_id = $_GET["id"] ?? 0;

$sql = "SELECT id, day_of_week, start_time, end_time, status
        FROM availability
        WHERE id = ? AND provider_id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $availability_id, $provider_id);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("Availability record not found.");
}

$availability = $result->fetch_assoc();

$stmt->close();

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $day_of_week = trim($_POST["day_of_week"]);
    $start_time = trim($_POST["start_time"]);
    $end_time = trim($_POST["end_time"]);
    $status = trim($_POST["status"]);

    if (empty($day_of_week) || empty($start_time) || empty($end_time) || empty($status)) {

        $message = "Please fill in all fields.";

    } elseif ($start_time >= $end_time) {

        $message = "End time must be later than start time.";

    } else {

        $update_sql = "UPDATE availability
                       SET day_of_week = ?,
                           start_time = ?,
                           end_time = ?,
                           status = ?
                       WHERE id = ? AND provider_id = ?";

        $update_stmt = $conn->prepare($update_sql);

        $update_stmt->bind_param(
            "ssssii",
            $day_of_week,
            $start_time,
            $end_time,
            $status,
            $availability_id,
            $provider_id
        );

        if ($update_stmt->execute()) {

            header("Location: availability.php?updated=1");
            exit();

        } else {

            $message = "Failed to update availability.";
        }

        $update_stmt->close();
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Edit Availability - HandyLink</title>

    <link rel="stylesheet" href="../assets/css/style.css">

    <script src="../assets/js/script.js"></script>

</head>

<body>

<header class="dashboard-header">

    <div class="dashboard-logo">

        <img src="/handylink/assets/images/handylink-logo.png"
             alt="HandyLink Solutions Logo">

    </div>

    <div class="dashboard-user">

        <span>
            Welcome, <?php echo htmlspecialchars($_SESSION["full_name"]); ?>
        </span>

        <a href="../actions/logout.php" class="logout-button">
            Logout
        </a>

    </div>

</header>

<nav class="provider-menu">

    <a href="dashboard.php">Dashboard</a>
    <a href="profile.php">My Profile</a>
    <a href="services.php">My Services</a>
    <a href="requests.php">Service Requests</a>
    <a href="bookings.php">Bookings</a>
    <a href="messages.php">Messages</a>
    <a href="availability.php" class="active">Availability</a>
    <a href="verification.php">Verification</a>
    <a href="reviews.php">Reviews</a>

</nav>

<main class="dashboard-main">

    <div class="page-header">

        <div>

            <h1>Edit Availability</h1>

            <p>Update your available working day and hours.</p>

        </div>

    </div>

    <?php if (!empty($message)): ?>

        <div class="error-message">

            <?php echo htmlspecialchars($message); ?>

        </div>

    <?php endif; ?>

    <div class="profile-card">

        <form method="POST" id="availability-form">

            <div class="form-group">

                <label for="day_of_week">Day of Week</label>

                <select name="day_of_week" id="day_of_week" required>

                    <option value="Monday" <?php echo $availability["day_of_week"] === "Monday" ? "selected" : ""; ?>>Monday</option>

                    <option value="Tuesday" <?php echo $availability["day_of_week"] === "Tuesday" ? "selected" : ""; ?>>Tuesday</option>

                    <option value="Wednesday" <?php echo $availability["day_of_week"] === "Wednesday" ? "selected" : ""; ?>>Wednesday</option>

                    <option value="Thursday" <?php echo $availability["day_of_week"] === "Thursday" ? "selected" : ""; ?>>Thursday</option>

                    <option value="Friday" <?php echo $availability["day_of_week"] === "Friday" ? "selected" : ""; ?>>Friday</option>

                    <option value="Saturday" <?php echo $availability["day_of_week"] === "Saturday" ? "selected" : ""; ?>>Saturday</option>

                    <option value="Sunday" <?php echo $availability["day_of_week"] === "Sunday" ? "selected" : ""; ?>>Sunday</option>

                </select>

            </div>

            <div class="form-group">

                <label for="start_time">Start Time</label>

                <input type="time"
                       name="start_time"
                       id="start_time"
                       value="<?php echo htmlspecialchars($availability["start_time"]); ?>"
                       required>

            </div>

            <div class="form-group">

                <label for="end_time">End Time</label>

                <input type="time"
                       name="end_time"
                       id="end_time"
                       value="<?php echo htmlspecialchars($availability["end_time"]); ?>"
                       required>

            </div>

            <div class="form-group">

                <label for="status">Status</label>

                <select name="status" id="status" required>

                    <option value="Available" <?php echo $availability["status"] === "Available" ? "selected" : ""; ?>>
                        Available
                    </option>

                    <option value="Unavailable" <?php echo $availability["status"] === "Unavailable" ? "selected" : ""; ?>>
                        Unavailable
                    </option>

                </select>

            </div>

            <button type="submit" class="profile-button">
                Update Availability
            </button>

            <a href="availability.php" class="profile-button">
                Cancel
            </a>

        </form>

    </div>

</main>

</body>

</html>