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

    $document_type = trim($_POST["document_type"] ?? "");
    $document_number = trim($_POST["document_number"] ?? "");

    if (empty($document_type) || empty($document_number)) {

        $message = "Please fill in all required fields.";
        $message_type = "error";

    } else {

        $document_file = "";

        if (isset($_FILES["document_file"]) &&
            $_FILES["document_file"]["error"] === UPLOAD_ERR_OK) {

            $file_name = $_FILES["document_file"]["name"];
            $file_tmp = $_FILES["document_file"]["tmp_name"];
            $file_size = $_FILES["document_file"]["size"];

            $allowed_types = ["pdf", "jpg", "jpeg", "png"];
            $file_extension = strtolower(
                pathinfo($file_name, PATHINFO_EXTENSION)
            );

            if (!in_array($file_extension, $allowed_types)) {

                $message = "Only PDF, JPG, JPEG and PNG files are allowed.";
                $message_type = "error";

            } elseif ($file_size > 5 * 1024 * 1024) {

                $message = "File size must not exceed 5MB.";
                $message_type = "error";

            } else {

                $upload_directory = "../uploads/verification/";

                if (!is_dir($upload_directory)) {
                    mkdir($upload_directory, 0777, true);
                }

                $new_file_name = "provider_" . $provider_id . "_" . time() . "." . $file_extension;

                $file_destination = $upload_directory . $new_file_name;

                if (move_uploaded_file($file_tmp, $file_destination)) {

                    $document_file = $new_file_name;

                } else {

                    $message = "Failed to upload the document.";
                    $message_type = "error";
                }
            }
        }

        if ($message === "") {

            $sql = "INSERT INTO verification
                    (provider_id, document_type, document_number,
                     document_file, status, submitted_at)
                    VALUES (?, ?, ?, ?, 'Pending', NOW())";

            $stmt = $conn->prepare($sql);

            $stmt->bind_param(
                "isss",
                $provider_id,
                $document_type,
                $document_number,
                $document_file
            );

            if ($stmt->execute()) {

                header("Location: verification.php?submitted=1");
                exit();

            } else {

                $message = "Failed to submit verification.";
                $message_type = "error";
            }

            $stmt->close();
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Submit Verification - HandyLink</title>

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
    <a href="availability.php">Availability</a>
    <a href="verification.php" class="active">Verification</a>
    <a href="reviews.php">Reviews</a>

</nav>

<main class="dashboard-main">

    <div class="page-header">

        <div>

            <h1>Submit Verification</h1>

            <p>
                Provide your identification or qualification details
                for verification.
            </p>

        </div>

    </div>

    <?php if (!empty($message)): ?>

        <div class="<?php echo $message_type === "error"
            ? "error-message"
            : "success-message"; ?>">

            <?php echo htmlspecialchars($message); ?>

        </div>

    <?php endif; ?>

    <div class="profile-card">

        <form method="POST"
              enctype="multipart/form-data"
              id="verification-form">

            <div class="form-group">

                <label for="document_type">
                    Document Type
                </label>

                <select name="document_type"
                        id="document_type"
                        required>

                    <option value="">
                        Select Document
                    </option>

                    <option value="National ID">
                        National ID
                    </option>

                    <option value="Passport">
                        Passport
                    </option>

                    <option value="Certificate">
                        Certificate
                    </option>

                    <option value="Professional License">
                        Professional License
                    </option>

                    <option value="Other">
                        Other
                    </option>

                </select>

            </div>

            <div class="form-group">

                <label for="document_number">
                    Document Number
                </label>

                <input type="text"
                       name="document_number"
                       id="document_number"
                       required>

            </div>

            <div class="form-group">

                <label for="document_file">
                    Upload Document
                </label>

                <input type="file"
                       name="document_file"
                       id="document_file"
                       accept=".pdf,.jpg,.jpeg,.png">

                <small>
                    Allowed formats: PDF, JPG, JPEG and PNG.
                    Maximum size: 5MB.
                </small>
                
                

            </div>

            <button type="submit" class="profile-button">
                Submit Verification
            </button>
            

            <a href="verification.php" class="profile-button">
                Cancel
            </a>

        </form>

    </div>

</main>

</body>

</html>