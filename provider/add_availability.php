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

$message = "";
$message_type = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $day_of_week = trim($_POST["day_of_week"]);
    $start_time = trim($_POST["start_time"]);
    $end_time = trim($_POST["end_time"]);
    $status = trim($_POST["status"]);

    if (empty($day_of_week) || empty($start_time) || empty($end_time) || empty($status)) {

        $message = "Please fill in all fields.";
        $message_type = "error";

    } elseif ($start_time >= $end_time) {

        $message = "End time must be later than start time.";
        $message_type = "error";

    } else {

        $sql = "INSERT INTO availability 
                (provider_id, day_of_week, start_time, end_time, status)
                VALUES (?, ?, ?, ?, ?)";

        $stmt = $conn->prepare($sql);

        $stmt->bind_param(
            "issss",
            $provider_id,
            $day_of_week,
            $start_time,
            $end_time,
            $status
        );

        if ($stmt->execute()) {

            header("Location: availability.php?added=1");
            exit();

        } else {

            $message = "Failed to add availability.";
            $message_type = "error";
        }

        $stmt->close();
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Add Availability - HandyLink</title>

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

            <h1>Add Availability</h1>

            <p>Set the day and hours when you are available.</p>

        </div>

    </div>

    <?php if (!empty($message)): ?>

        <div class="<?php echo $message_type === 'error' ? 'error-message' : 'success-message'; ?>">

            <?php echo htmlspecialchars($message); ?>

        </div>

    <?php endif; ?>

    <div class="profile-card">

        <form method="POST" id="availability-form">

            <div class="form-group">

                <label for="day_of_week">Day of Week</label>

                <select name="day_of_week" id="day_of_week" required>

                    <option value="">Select Day</option>

                    <option value="Monday">Monday</option>

                    <option value="Tuesday">Tuesday</option>

                    <option value="Wednesday">Wednesday</option>

                    <option value="Thursday">Thursday</option>

                    <option value="Friday">Friday</option>

                    <option value="Saturday">Saturday</option>

                    <option value="Sunday">Sunday</option>

                </select>

            </div>

            <div class="form-group">

                <label for="start_time">Start Time</label>

                <input type="time"
                       name="start_time"
                       id="start_time"
                       required>

            </div>

            <div class="form-group">

                <label for="end_time">End Time</label>

                <input type="time"
                       name="end_time"
                       id="end_time"
                       required>

            </div>

            <div class="form-group">

                <label for="status">Status</label>

                <select name="status" id="status" required>

                    <option value="Available">Available</option>

                    <option value="Unavailable">Unavailable</option>

                </select>

            </div>

            <button type="submit" class="profile-button">
                Save Availability
            </button>

            <a href="availability.php" class="profile-button">
                Cancel
            </a>

        </form>

    </div>

</main>

</body>

</html>