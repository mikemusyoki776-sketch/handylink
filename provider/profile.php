<?php

session_start();

require_once "../config/database.php";

if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "provider") {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION["user_id"];

$stmt = $conn->prepare("
    SELECT
        u.full_name,
        u.email,
        u.phone,
        u.location,
        u.profile_image,
        p.bio,
        p.experience,
        p.verification_status,
        p.availability_status,
        p.rating
    FROM users u
    INNER JOIN providers p ON u.id = p.user_id
    WHERE u.id = ?
    LIMIT 1
");

$stmt->bind_param("i", $user_id);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows !== 1) {
    die("Provider profile not found.");
}

$provider = $result->fetch_assoc();

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Profile - HandyLink Solutions</title>

    <link rel="stylesheet" href="../assets/css/style.css">
    <script src="../assets/js/script.js"></script>

</head>

<body>

<header class="dashboard-header">

    <div class="dashboard-brand">

        <img
            src="../assets/images/handylink-logo.png"
            alt="HandyLink Solutions Logo"
        >

        <span>HandyLink Solutions</span>

    </div>

    <nav class="dashboard-nav">

        <a href="dashboard.php">Dashboard</a>

        <a href="profile.php">My Profile</a>

        <a href="service.php">My Services</a>

        <a href="request.php">Requests</a>

        <a href="bookings.php">Bookings</a>

        <a href="messages.php">Messages</a>

    </nav>

</header>


<main class="profile-container">

    <section class="profile-header">

        <h1>My Provider Profile</h1>

        <p>
            Manage the information clients and HandyLink's
            matching system use to understand your services.
        </p>

    </section>


    <section class="profile-card">

        <div class="profile-image-area">

            <?php if (!empty($provider["profile_image"])): ?>

                <img
                    src="../assets/images/<?php echo htmlspecialchars($provider["profile_image"]); ?>"
                    alt="Provider Profile"
                >

            <?php else: ?>

                <div class="profile-placeholder">
                    <?php echo strtoupper(substr($provider["full_name"], 0, 1)); ?>
                </div>

            <?php endif; ?>

        </div>


        <div class="profile-details">

            <h2>
                <?php echo htmlspecialchars($provider["full_name"]); ?>
            </h2>

            <p>
                <strong>Email:</strong>
                <?php echo htmlspecialchars($provider["email"]); ?>
            </p>

            <p>
                <strong>Phone:</strong>
                <?php echo htmlspecialchars($provider["phone"]); ?>
            </p>

            <p>
                <strong>Location:</strong>
                <?php echo htmlspecialchars($provider["location"]); ?>
            </p>

            <p>
                <strong>Experience:</strong>
                <?php echo (int)$provider["experience"]; ?> years
            </p>

            <p>
                <strong>Verification:</strong>
                <?php echo ucfirst($provider["verification_status"]); ?>
            </p>

            <p>
                <strong>Availability:</strong>
                <?php echo ucfirst($provider["availability_status"]); ?>
            </p>

            <p>
                <strong>Rating:</strong>
                <?php echo number_format((float)$provider["rating"], 1); ?>/5
            </p>

        </div>

    </section>


    <section class="profile-bio">

        <h2>About Me</h2>

        <p>

            <?php

            if (!empty($provider["bio"])) {

                echo nl2br(
                    htmlspecialchars($provider["bio"])
                );

            } else {

                echo "No biography has been added yet.";

            }

            ?>

        </p>

    </section>


    <div class="profile-actions">

        <a href="edit_profile.php" class="profile-button">
            Edit Profile
        </a>

        <a href="dashboard.php" class="profile-button secondary">
            Back to Dashboard
        </a>

    </div>

</main>


<footer>

    <p>
        &copy; 2026 HandyLink Solutions.
        All Rights Reserved.
    </p>

</footer>

</body>

</html>

<?php

$stmt->close();
$conn->close();

?>