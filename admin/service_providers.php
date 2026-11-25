
<?php
session_start();
require_once "../config/database.php";

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$search = trim($_GET['search'] ?? '');

$sql = "SELECT id, full_name, email, phone, location, status
        FROM users
        WHERE role = 'provider'";

if ($search !== '') {
    $sql .= " AND (full_name LIKE ? OR email LIKE ? OR phone LIKE ? OR location LIKE ?)";
}

$sql .= " ORDER BY id DESC";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Database query error: " . htmlspecialchars($conn->error));
}

if ($search !== '') {
    $term = "%{$search}%";
    $stmt->bind_param("ssss", $term, $term, $term, $term);
}

$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Providers | HandyLink Admin</title>
    <link rel="stylesheet" href="../assets/css/admin_service_providers.css">
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>
<body>

<div class="page-container">
    <header class="topbar">
        <a href="dashboard.php" class="brand">
            <img src="../assets/images/handylink-logo.png" alt="HandyLink Logo">
            <span>HandyLink <small>Admin Panel</small></span>
        </a>

        <a href="dashboard.php" class="back-link">
            <i class="fa-solid fa-arrow-left"></i> Dashboard
        </a>
    </header>

    <main class="main-content">
        <div class="page-heading">
            <div>
                <p class="eyebrow">ADMINISTRATION</p>
                <h1>Service Providers</h1>
                <p>View registered service providers and their account status.</p>
            </div>

            <div class="total-card">
                <i class="fa-solid fa-users"></i>
                <div>
                    <span>Total Providers</span>
                    <strong><?= $result->num_rows ?></strong>
                </div>
            </div>
        </div>

        <form method="GET" class="search-form">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input
                type="text"
                name="search"
                placeholder="Search name, email, phone or location..."
                value="<?= htmlspecialchars($search) ?>"
            >
            <button type="submit">Search</button>
            <?php if ($search !== ''): ?>
                <a href="service_providers.php" class="reset-link">Reset</a>
            <?php endif; ?>
        </form>

        <section class="table-card">
            <div class="table-heading">
                <h2>Registered Providers</h2>
                <span><?= $result->num_rows ?> record(s)</span>
            </div>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Provider</th>
                            <th>Contact</th>
                            <th>Location</th>
                            <th>Account Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($result->num_rows > 0): ?>
                        <?php $number = 1; ?>
                        <?php while ($provider = $result->fetch_assoc()): ?>
                            <tr>
                                <td><?= $number++ ?></td>
                                <td>
                                    <div class="provider-name">
                                        <span class="provider-avatar">
                                            <i class="fa-solid fa-user-tie"></i>
                                        </span>
                                        <strong><?= htmlspecialchars($provider['full_name'] ?? '') ?></strong>
                                    </div>
                                </td>
                                <td>
                                    <div><?= htmlspecialchars($provider['email'] ?? '') ?></div>
                                    <small><?= htmlspecialchars($provider['phone'] ?? '') ?></small>
                                </td>
                                <td><?= htmlspecialchars($provider['location'] ?? 'Not provided') ?></td>
                                <td>
                                    <span class="status <?= strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '-', $provider['status'] ?? 'unknown')) ?>">
                                        <?= htmlspecialchars(ucfirst($provider['status'] ?? 'Unknown')) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="empty-state">
                                <i class="fa-solid fa-user-slash"></i>
                                <h3>No service providers found</h3>
                                <p>
                                    <?php if ($search !== ''): ?>
                                        Try a different search term.
                                    <?php else: ?>
                                        Registered accounts with the provider role will appear here.
                                    <?php endif; ?>
                                </p>
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <p class="page-note">
            <i class="fa-solid fa-circle-info"></i>
            This page displays account status. Provider approval and verification will be connected
            separately to your existing verification workflow.
        </p>
    </main>
</div>

</body>
</html>

<?php
$stmt->close();
$conn->close();
?>
