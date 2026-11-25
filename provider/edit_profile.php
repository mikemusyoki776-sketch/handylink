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
        p.bio,
        p.experience
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

    <title>Edit Profile - HandyLink Solutions</title>

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
        <a href="#">My Services</a>
        <a href="#">Requests</a>
        <a href="#">Bookings</a>
        <a href="#">Messages</a>

    </nav>

</header>


<main class="edit-profile-container">

    <section class="edit-profile-card">

        <h1>Edit My Profile</h1>

        <p>
            Update your information so clients and HandyLink's
            matching system can better understand your services.
        </p>

        <form action="update_profile.php" method="POST">

            <label for="full_name">Full Name</label>

            <input
                type="text"
                id="full_name"
                name="full_name"
                value="<?php echo htmlspecialchars($provider["full_name"]); ?>"
                required
            >


            <label for="email">Email Address</label>

            <input
                type="email"
                id="email"
                name="email"
                value="<?php echo htmlspecialchars($provider["email"]); ?>"
                required
            >


            <label for="phone">Phone Number</label>

            <input
                type="tel"
                id="phone"
                name="phone"
                value="<?php echo htmlspecialchars($provider["phone"]); ?>"
                required
            >


            <label for="location">Location</label>

            <input
                type="text"
                id="location"
                name="location"
                value="<?php echo htmlspecialchars($provider["location"]); ?>"
                required
            >


            <label for="experience">Years of Experience</label>

            <input
                type="number"
                id="experience"
                name="experience"
                min="0"
                value="<?php echo (int)$provider["experience"]; ?>"
                required
            >


            <label for="bio">About Me</label>

            <textarea
                id="bio"
                name="bio"
                rows="6"
                placeholder="Tell clients about your skills and experience..."
            ><?php echo htmlspecialchars($provider["bio"] ?? ""); ?></textarea>


            <button type="submit">
                Save Changes
            </button>

        </form>

        <a href="profile.php" class="profile-button secondary">
            Cancel
        </a>

    </section>

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