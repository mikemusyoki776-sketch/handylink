<?php
session_start();

if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "provider") {
    header("Location: ../login.php");
    exit();
}

require_once "../config/database.php";

$sql = "SELECT id, name FROM categories WHERE status = 'active' ORDER BY name ASC";
$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Service - HandyLink</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <script src="../assets/js/script.js"></script>
</head>

<body>

<header class="dashboard-header">

    <div class="dashboard-logo">
        <img src="../assets/images/handylink-logo.png" alt="HandyLink Logo">
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
    <a href="services.php" class="active">My Services</a>
    <a href="requests.php">Service Requests</a>
    <a href="bookings.php">Bookings</a>
    <a href="messages.php">Messages</a>
    <a href="availability.php">Availability</a>
    <a href="verification.php">Verification</a>
    <a href="reviews.php">Reviews</a>
</nav>

<main class="dashboard-main">

    <div class="edit-profile-container">

        <div class="edit-profile-card">

            <h1>Add New Service</h1>

            <form action="add_service_action.php" method="POST" class="edit-profile-form">

                <div class="form-group">
                    <label for="service_name">Service Name</label>

                    <input
                        type="text"
                        id="service_name"
                        name="service_name"
                        placeholder="e.g. Electrical Wiring"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="category_id">Service Category</label>

                    <select id="category_id" name="category_id" required>
                        <option value="">Select Category</option>

                        <?php while ($category = $result->fetch_assoc()): ?>

                            <option value="<?php echo $category["id"]; ?>">
                                <?php echo htmlspecialchars($category["name"]); ?>
                            </option>

                        <?php endwhile; ?>

                    </select>
                </div>

                <div class="form-group">
                    <label for="description">Service Description</label>

                    <textarea
                        id="description"
                        name="description"
                        placeholder="Describe the service you provide..."
                        required
                    ></textarea>
                </div>

                <div class="form-group">
                    <label for="price">Starting Price (KSh)</label>

                    <input
                        type="number"
                        id="price"
                        name="price"
                        min="0"
                        step="0.01"
                        placeholder="e.g. 2500"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="location">Service Location</label>

                    <input
                        type="text"
                        id="location"
                        name="location"
                        placeholder="e.g. Nairobi"
                        required
                    >
                </div>

                <div class="edit-profile-actions">

                    <button type="submit" class="profile-button">
                        Add Service
                    </button>

                    <a href="services.php" class="profile-button">
                        Cancel
                    </a>

                </div>

            </form>

        </div>

    </div>

</main>

</body>
</html>