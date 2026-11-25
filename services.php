<?php

require_once "config/database.php";

$sql = "
    SELECT id, name, description
    FROM categories
    WHERE status = 'active'
    ORDER BY name ASC
";

$result = $conn->query($sql);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Services - HandyLink Solutions</title>

    <link rel="stylesheet" href="assets/css/style.css">

</head>

<body>

<header class="dashboard-header">

    <div class="dashboard-brand">

        <img
            src="assets/images/handylink-logo.png"
            alt="HandyLink Solutions Logo"
        >

        <span>HandyLink Solutions</span>

    </div>

    <nav class="dashboard-nav">

        <a href="index.php">Home</a>

        <a href="services.php">Services</a>
        
        <a href="about.php">About</a>

        <a href="contact.php">Contact</a>

        <a href="login.php">Login</a>

        <a href="register.php">Register</a>

    </nav>

</header>


<main>

    <section class="services-page">

        <div class="services-heading">

            <h1>Our Services</h1>

            <p>
                Find trusted and skilled professionals for your
                everyday service needs.
            </p>

        </div>


        <div class="service-category-grid">

            <?php if ($result && $result->num_rows > 0): ?>

                <?php while ($category = $result->fetch_assoc()): ?>

                    <div class="service-category-card">

                        <div class="service-icon">
                            🔧
                        </div>

                        <h2>
                            <?php echo htmlspecialchars($category["name"]); ?>
                        </h2>

                        <p>
                            <?php
                            echo htmlspecialchars(
                                $category["description"]
                            );
                            ?>
                        </p>

                        <a
                            href="register.php"
                            class="service-button"
                        >
                            Get Started
                        </a>

                    </div>

                <?php endwhile; ?>

            <?php else: ?>

                <p>No services are currently available.</p>

            <?php endif; ?>

        </div>

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

$conn->close();

?>