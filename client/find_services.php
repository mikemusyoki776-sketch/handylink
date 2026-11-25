
<?php
session_start();

if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "client"
) {
    header("Location: ../login.php");
    exit();
}

require_once __DIR__ . "/../config/database.php";

$userId = (int) $_SESSION["user_id"];
$fullName = $_SESSION["full_name"] ?? "Client";
$firstName = explode(" ", trim($fullName))[0];

$search = trim($_GET["search"] ?? "");
$location = trim($_GET["location"] ?? "");

$categoryId = filter_input(INPUT_GET, "category", FILTER_VALIDATE_INT);
$categoryId = $categoryId && $categoryId > 0 ? $categoryId : 0;

$providers = [];
$categories = [];
$unreadMessages = 0;
$errorMessage = "";

$databaseReady = isset($conn) && $conn instanceof mysqli;


/*
|--------------------------------------------------------------------------
| Availability functions
|--------------------------------------------------------------------------
*/

function clientAvailabilityLabel($status)
{
    $status = strtolower(trim((string) $status));

    if ($status === "available") {
        return "Available";
    }

    if ($status === "busy") {
        return "Busy";
    }

    return "Unavailable";
}

function clientAvailabilityClass($status)
{
    $status = strtolower(trim((string) $status));

    if ($status === "available") {
        return "available";
    }

    if ($status === "busy") {
        return "busy";
    }

    return "unavailable";
}


/*
|--------------------------------------------------------------------------
| Database operations
|--------------------------------------------------------------------------
*/

if (!$databaseReady) {

    $errorMessage = "The database connection could not be established.";

} else {

    /*
    |--------------------------------------------------------------------------
    | Load service categories
    |--------------------------------------------------------------------------
    */

    $categoryTables = ["service_categories", "categories"];

    foreach ($categoryTables as $tableName) {

        $tableCheck = $conn->query(
            "SHOW TABLES LIKE '" . $conn->real_escape_string($tableName) . "'"
        );

        if (!$tableCheck || $tableCheck->num_rows === 0) {
            continue;
        }

        $columnResult = $conn->query("SHOW COLUMNS FROM `$tableName`");

        $columns = [];

        if ($columnResult) {
            while ($column = $columnResult->fetch_assoc()) {
                $columns[] = $column["Field"];
            }
        }

        $idColumn = in_array("id", $columns, true) ? "id" : "";
        $nameColumn = "";

        foreach (["category_name", "name", "title"] as $candidate) {

            if (in_array($candidate, $columns, true)) {
                $nameColumn = $candidate;
                break;
            }
        }

        if ($idColumn !== "" && $nameColumn !== "") {

            $categorySql = "
                SELECT `$idColumn` AS id, `$nameColumn` AS name
                FROM `$tableName`
                ORDER BY `$nameColumn` ASC
            ";

            $categoryResult = $conn->query($categorySql);

            if ($categoryResult) {

                while ($row = $categoryResult->fetch_assoc()) {
                    $categories[] = $row;
                }
            }

            if (!empty($categories)) {
                break;
            }
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Count unread messages
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM messages
        WHERE receiver_id = ? AND is_read = 0
    ");

    if ($stmt) {

        $stmt->bind_param("i", $userId);
        $stmt->execute();

        $messageResult = $stmt->get_result()->fetch_assoc();

        $unreadMessages = (int) ($messageResult["total"] ?? 0);

        $stmt->close();
    }


    /*
    |--------------------------------------------------------------------------
    | Search approved providers and their services
    |--------------------------------------------------------------------------
    */

    $sql = "
        SELECT
            s.id AS service_id,
            s.provider_id,
            s.category_id,
            s.service_name,
            s.description,
            s.price,
            s.location AS service_location,
            s.status AS service_status,

            u.full_name,
            u.profile_image,

            (
              SELECT ja.experience
              FROM job_applications ja
              WHERE ja.provider_id = p.id
                AND ja.status = 'Hired'
              ORDER BY ja.application_date DESC
              LIMIT 1
          ) AS experience,

            COALESCE(
                (
                    SELECT AVG(r.rating)
                    FROM reviews r
                    WHERE r.provider_id = p.id
                ),
                0
            ) AS rating,

            p.availability_status

        FROM services s

        INNER JOIN providers p
            ON p.id = s.provider_id

        INNER JOIN users u
            ON u.id = p.user_id

        WHERE p.verification_status = 'approved'
          AND u.role = 'provider'
          AND (u.status = 'active' OR u.status IS NULL)
          AND (s.status = 'active' OR s.status IS NULL)
    ";

    $types = "";
    $params = [];


    // Search by service name, description or provider name.

    if ($search !== "") {

        $sql .= "
            AND (
                s.service_name LIKE ?
                OR s.description LIKE ?
                OR u.full_name LIKE ?
            )
        ";

        $searchTerm = "%" . $search . "%";

        $types .= "sss";

        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }


    // Filter by location.

    if ($location !== "") {

        $sql .= " AND s.location LIKE ?";

        $types .= "s";

        $params[] = "%" . $location . "%";
    }


    // Filter by category.

    if ($categoryId > 0) {

        $sql .= " AND s.category_id = ?";

        $types .= "i";

        $params[] = $categoryId;
    }


    // Prioritise available providers, then ratings.

    $sql .= "
        ORDER BY
            CASE
                WHEN p.availability_status = 'available' THEN 0
                ELSE 1
            END,
            rating DESC,
            s.service_name ASC

        LIMIT 100
    ";


    $stmt = $conn->prepare($sql);

    if ($stmt) {

        if ($types !== "") {
            $stmt->bind_param($types, ...$params);
        }

        if ($stmt->execute()) {

            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                $providers[] = $row;
            }

            error_log(
                "HandyLink Find Services: " .
                count($providers) . " services found."
            );

        } else {

            $errorMessage = "Database query failed: " . $stmt->error;

            error_log($errorMessage);
        }

        $stmt->close();

    } else {

        $errorMessage = "We could not prepare the provider search. Check that your database tables and columns match your existing project.";
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Find Services | HandyLink</title>

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">

    <link rel="stylesheet"
          href="../assets/css/clients/client.css">

    <script src="../assets/js/client_darkmode.js" defer></script>

</head>

<body>

<header class="client-header">

    <a href="dashboard.php" class="brand">
        <img src="../assets/images/handylink-logo.png" alt="HandyLink Logo">
        <span>HandyLink</span>
    </a>

    <button class="mobile-menu-button"
            id="mobileMenuButton"
            type="button"
            aria-label="Toggle navigation"
            aria-expanded="false">

        <i class="fa-solid fa-bars"></i>

    </button>


    <nav class="main-navigation" id="mainNavigation">

        <a class="nav-link" href="dashboard.php">
            <i class="fa-solid fa-house"></i>
            <span>Dashboard</span>
        </a>

        <a class="nav-link active" href="find_services.php">
            <i class="fa-solid fa-magnifying-glass"></i>
            <span>Find Services</span>
        </a>

        <a class="nav-link" href="my_request.php">
            <i class="fa-regular fa-clipboard"></i>
            <span>My Requests</span>
        </a>

        <a class="nav-link" href="bookings.php">
            <i class="fa-regular fa-calendar-check"></i>
            <span>My Bookings</span>
        </a>

        <a class="nav-link" href="messages.php">
            <i class="fa-regular fa-comments"></i>
            <span>Messages</span>
        </a>

        <a class="nav-link" href="reviews.php">
            <i class="fa-solid fa-star"></i>
            <span>Reviews</span>
        </a>

    </nav>


    <div class="header-actions">

        <a href="messages.php"
           class="notification-button"
           aria-label="Notifications and unread messages"
           title="Notifications">

            <i class="fa-regular fa-bell bell-icon" aria-hidden="true"></i>

            <?php if ($unreadMessages > 0): ?>

                <span class="notification-dot"
                      aria-label="Unread messages"></span>

            <?php endif; ?>

        </a>


        <div class="profile-menu-wrapper">

            <button type="button"
                    class="profile-button"
                    id="profileButton"
                    aria-expanded="false">

                <span class="profile-avatar profile-initial">

                    <?php
                    echo htmlspecialchars(
                        strtoupper(substr($firstName, 0, 1))
                    );
                    ?>

                </span>

                <span class="profile-name">
                    <?php echo htmlspecialchars($firstName); ?>
                </span>

                <i class="fa-solid fa-chevron-down chevron-icon"></i>

            </button>


            <div class="profile-dropdown" id="profileDropdown">

                <div class="dropdown-user">

                    <strong>
                        <?php echo htmlspecialchars($fullName); ?>
                    </strong>

                    <span>Client account</span>

                </div>

                <a href="settings.php">
                    <i class="fa-solid fa-gear"></i>
                    Profile &amp; Settings
                </a>

                <a href="../logout.php" class="dropdown-logout">
                    <i class="fa-solid fa-right-from-bracket"></i>
                    Log Out
                </a>

            </div>

        </div>

    </div>

</header>


<main class="find-services-container">


    <!-- Page heading -->

    <section class="find-services-heading">

        <div>

            <span class="eyebrow">HANDYLINK MARKETPLACE</span>

            <h1>Find the right professional</h1>

            <p>
                Search trusted service providers for your home and everyday needs.
            </p>

        </div>

        <div class="find-heading-icon" aria-hidden="true">
            <i class="fa-solid fa-magnifying-glass"></i>
        </div>

    </section>



    <!-- Search section -->

    <section class="service-search-panel">

        <form method="GET"
              action="find_services.php"
              class="service-search-form">


            <div class="search-field">

                <label for="search">Service or provider</label>

                <div class="search-input-wrap">

                    <i class="fa-solid fa-magnifying-glass"></i>

                    <input
                        type="search"
                        id="search"
                        name="search"
                        placeholder="e.g. plumber, electrician..."
                        value="<?php echo htmlspecialchars($search); ?>">

                </div>

            </div>



            <div class="search-field">

                <label for="category">Service category</label>

                <div class="search-input-wrap">

                    <i class="fa-solid fa-layer-group"></i>

                    <select id="category" name="category">

                        <option value="">All categories</option>

                        <?php foreach ($categories as $category): ?>

                            <option
                                value="<?php echo (int) $category["id"]; ?>"
                                <?php
                                echo $categoryId === (int) $category["id"]
                                    ? "selected"
                                    : "";
                                ?>>

                                <?php echo htmlspecialchars($category["name"]); ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

            </div>



            <div class="search-field">

                <label for="location">Location</label>

                <div class="search-input-wrap">

                    <i class="fa-solid fa-location-dot"></i>

                    <input
                        type="text"
                        id="location"
                        name="location"
                        placeholder="Town, estate or area"
                        value="<?php echo htmlspecialchars($location); ?>">

                </div>

            </div>


            <button type="submit" class="service-search-button">

                <i class="fa-solid fa-magnifying-glass"></i>

                Search

            </button>

        </form>


        <?php if ($search !== "" || $location !== "" || $categoryId > 0): ?>

            <div class="search-active-filters">

                <span>Filtered results</span>

                <a href="find_services.php">
                    <i class="fa-solid fa-xmark"></i>
                    Clear filters
                </a>

            </div>

        <?php endif; ?>

    </section>



    <!-- Provider results -->

    <section class="provider-results-section">


        <div class="provider-results-heading">

            <div>

                <h2>Service Providers</h2>

                <p>
                    <?php echo count($providers); ?>

                    <?php
                    echo count($providers) === 1
                        ? "service"
                        : "services";
                    ?>

                    found
                </p>

            </div>


            <span class="verified-note">

                <i class="fa-solid fa-shield-halved"></i>

                Approved providers

            </span>

        </div>



        <?php if ($errorMessage !== ""): ?>

            <div class="service-error">

                <i class="fa-solid fa-circle-exclamation"></i>

                <?php echo htmlspecialchars($errorMessage); ?>

            </div>


        <?php elseif (!empty($providers)): ?>


            <div class="provider-results-grid">


                <?php foreach ($providers as $provider): ?>

                    <?php

                    /*
                     * Provider availability.
                     */

                    $availability = $provider["availability_status"] ?? "unavailable";

                    $availabilityClass = clientAvailabilityClass($availability);

                    $availabilityLabel = clientAvailabilityLabel($availability);


                    /*
                     * Provider profile image.
                     *
                     * Database values such as uploads/photo.jpg
                     * are relative to the project root.
                     * Since this page is inside the client folder,
                     * prepend ../ when necessary.
                     */

                    $storedProfileImage = trim(
                        str_replace(
                            "\\",
                            "/",
                            (string) ($provider["profile_image"] ?? "")
                        )
                    );

                    $profileImageUrl = "";

                    if ($storedProfileImage !== "") {

                        if (
                            preg_match(
                                '/^https?:\/\//i',
                                $storedProfileImage
                            ) ||
                            str_starts_with($storedProfileImage, "/") ||
                            str_starts_with($storedProfileImage, "../")
                        ) {

                            $profileImageUrl = $storedProfileImage;

                        } else {

                            $profileImageUrl = "../" .
                                ltrim($storedProfileImage, "/");
                        }
                    }


                    /*
                     * Provider rating.
                     */

                    $rating = is_numeric($provider["rating"] ?? null)
                        ? (float) $provider["rating"]
                        : 0;


                    /*
                     * Service price.
                     */

                    $price = is_numeric($provider["price"] ?? null)
                        ? number_format((float) $provider["price"], 2)
                        : null;

                    ?>


                    <article class="provider-service-card">


                        <!-- Provider photo and availability -->

                        <div class="provider-card-top">


                            <div class="provider-avatar">

                                <?php if ($profileImageUrl !== ""): ?>

                                    <img
                                        src="<?php echo htmlspecialchars($profileImageUrl, ENT_QUOTES, 'UTF-8'); ?>"
                                        alt="<?php echo htmlspecialchars($provider["full_name"]); ?>"
                                        onerror="this.onerror=null; this.hidden=true; this.nextElementSibling.hidden=false;">

                                <?php endif; ?>


                                <i
                                    class="fa-solid fa-user"
                                    <?php echo $profileImageUrl !== "" ? "hidden" : ""; ?>>
                                </i>

                            </div>



                            <span class="provider-availability <?php echo htmlspecialchars($availabilityClass); ?>">

                                <span class="availability-dot"></span>

                                <?php echo htmlspecialchars($availabilityLabel); ?>

                            </span>


                        </div>



                        <!-- Provider service information -->

                        <div class="provider-service-info">


                            <p class="provider-service-label">
                                SERVICE
                            </p>


                            <h3>
                                <?php echo htmlspecialchars($provider["service_name"]); ?>
                            </h3>


                            <p class="provider-name">

                                <i class="fa-regular fa-user"></i>

                                <?php echo htmlspecialchars($provider["full_name"]); ?>

                            </p>



                            <p class="provider-description">

                                <?php

                                $description = trim(
                                    $provider["description"] ?? ""
                                );

                                echo htmlspecialchars(
                                    $description !== ""
                                        ? $description
                                        : "Contact this provider to discuss your service requirements."
                                );

                                ?>

                            </p>



                            <!-- Provider details -->

                            <div class="provider-card-details">


                                <span>

                                    <i class="fa-solid fa-location-dot"></i>

                                    <?php

                                    echo htmlspecialchars(
                                        $provider["service_location"] ?: "Location not specified"
                                    );

                                    ?>

                                </span>

                              <div class="provider-experience">
                                  <h4>
                                      <i class="fa-solid fa-briefcase"></i>
                                      Work Experience
                                  </h4>
                              
                                  <p>
                                      <?php
                                      $experience = trim($provider["experience"] ?? "");
                              
                                      echo htmlspecialchars(
                                          $experience !== ""
                                              ? $experience
                                              : "Work experience not provided."
                                      );
                                      ?>
                                  </p>
                              </div>
                              

                            </div>



                            <!-- Price and rating -->

                            <div class="provider-card-bottom">


                                <div class="provider-price">

                                    <span>Starting price</span>

                                    <strong>

                                        <?php if ($price !== null): ?>

                                            KSh <?php echo $price; ?>

                                        <?php else: ?>

                                            Price on request

                                        <?php endif; ?>

                                    </strong>

                                </div>



                                <div class="provider-rating">

                                    <i class="fa-solid fa-star"></i>

                                    <strong>
                                        <?php echo number_format($rating, 1); ?>
                                    </strong>

                                </div>


                            </div>



                            <!-- Request service button -->

                            <?php if ($availabilityClass === "available"): ?>

                                <a
                                    class="provider-request-button"
                                    href="request_service.php?service_id=<?php echo (int) $provider["service_id"]; ?>">

                                    Request Service

                                    <i class="fa-solid fa-arrow-right"></i>

                                </a>

                            <?php else: ?>

                                <button
                                    class="provider-request-button provider-button-disabled"
                                    type="button"
                                    disabled>

                                    Currently
                                    <?php echo htmlspecialchars(strtolower($availabilityLabel)); ?>

                                </button>

                            <?php endif; ?>


                        </div>

                    </article>


                <?php endforeach; ?>


            </div>


        <?php else: ?>


            <!-- No results -->

            <div class="no-providers-state">


                <div class="no-providers-icon">

                    <i class="fa-solid fa-magnifying-glass"></i>

                </div>


                <h3>No service providers found</h3>


                <p>

                    Try another service name, category or location.
                    Approved providers matching your search will appear here.

                </p>


                <a href="find_services.php" class="clear-search-button">

                    View All Services

                </a>


            </div>


        <?php endif; ?>


    </section>

</main>



<!-- Footer -->

<footer class="client-footer">

    <p>
        &copy; <?php echo date("Y"); ?>
        HandyLink Solutions. All rights reserved.
    </p>

    <a href="settings.php">Account Settings</a>

</footer>



<!-- Navigation and profile dropdown scripts -->

<script>

const profileButton = document.getElementById("profileButton");

const profileDropdown = document.getElementById("profileDropdown");

const mobileMenuButton = document.getElementById("mobileMenuButton");

const mainNavigation = document.getElementById("mainNavigation");


if (profileButton && profileDropdown) {

    profileButton.addEventListener("click", function (event) {

        event.stopPropagation();

        const isOpen = profileDropdown.classList.toggle("show");

        profileButton.setAttribute("aria-expanded", String(isOpen));

    });


    document.addEventListener("click", function (event) {

        if (!event.target.closest(".profile-menu-wrapper")) {

            profileDropdown.classList.remove("show");

            profileButton.setAttribute("aria-expanded", "false");

        }

    });

}


if (mobileMenuButton && mainNavigation) {

    mobileMenuButton.addEventListener("click", function () {

        const isOpen = mainNavigation.classList.toggle("show");

        mobileMenuButton.setAttribute("aria-expanded", String(isOpen));

    });

}

</script>


</body>
</html>
