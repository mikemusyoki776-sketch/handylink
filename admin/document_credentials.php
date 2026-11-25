<?php
session_start();
require_once "../config/database.php";

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');

$sql = "
    SELECT
        v.id,
        v.provider_id,
        v.document_type,
        v.document_number,
        v.document_file,
        v.status,
        v.admin_comment,
        v.submitted_at,
        v.reviewed_at,
        u.full_name,
        u.email,
        u.phone
    FROM verification v
    LEFT JOIN providers p ON p.id = v.provider_id
    LEFT JOIN users u ON u.id = p.user_id
    WHERE 1=1
";

$params = [];
$types = "";

// Search filter
if ($search !== '') {
    $sql .= " AND (
        u.full_name LIKE ?
        OR u.email LIKE ?
        OR v.document_type LIKE ?
        OR v.document_number LIKE ?
    )";

    $term = "%{$search}%";
    $params = array_merge($params, [$term, $term, $term, $term]);
    $types .= "ssss";
}

// Status filter
$allowedStatuses = ['pending', 'approved', 'rejected'];

if (in_array($status, $allowedStatuses, true)) {
    $sql .= " AND v.status = ?";
    $params[] = $status;
    $types .= "s";
}

// Sorting — keep this at the end
$sql .= " ORDER BY
    CASE WHEN v.status = 'pending' THEN 0 ELSE 1 END,
    v.submitted_at DESC
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Database query error: " . htmlspecialchars($conn->error));
}

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();
$totalRecords = $result->num_rows;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Documents & Credentials | HandyLink</title>

    <link rel="stylesheet" href="../assets/css/documents_credentials.css">
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>
<body>

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
            <p class="eyebrow">PROVIDER VERIFICATION</p>
            <h1>Documents & Credentials</h1>
            <p>Review submitted documents and track provider verification status.</p>
        </div>

        <div class="total-card">
            <i class="fa-solid fa-folder-open"></i>
            <div>
                <span>Matching Records</span>
                <strong><?= $totalRecords ?></strong>
            </div>
        </div>
    </div>

    <form method="GET" class="filter-form">
        <div class="search-box">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input
                type="text"
                name="search"
                placeholder="Search provider or document..."
                value="<?= htmlspecialchars($search) ?>"
            >
        </div>

        <select name="status">
            <option value="">All statuses</option>
            <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
            <option value="approved" <?= $status === 'approved' ? 'selected' : '' ?>>Approved</option>
            <option value="rejected" <?= $status === 'rejected' ? 'selected' : '' ?>>Rejected</option>
        </select>

        <button type="submit">
            <i class="fa-solid fa-filter"></i> Filter
        </button>

        <a href="document_credentials.php" class="reset-link">Reset</a>
    </form>

    <section class="table-card">
        <div class="table-heading">
            <h2>Submitted Documents</h2>
            <span><?= $totalRecords ?> record(s)</span>
        </div>

        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Provider</th>
                        <th>Document</th>
                        <th>Document Number</th>
                        <th>Submitted</th>
                        <th>Status</th>
                        <th>Admin Comment</th>
                        <th>Document File</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($totalRecords > 0): ?>
                    <?php $number = 1; ?>
                    <?php while ($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td><?= $number++ ?></td>

                            <td>
                                <strong><?= htmlspecialchars($row['full_name'] ?? 'Unknown provider') ?></strong>
                                <small><?= htmlspecialchars($row['email'] ?? '') ?></small>
                                <small><?= htmlspecialchars($row['phone'] ?? '') ?></small>
                            </td>

                            <td><?= htmlspecialchars($row['document_type'] ?? '') ?></td>

                            <td><?= htmlspecialchars($row['document_number'] ?? '') ?></td>

                            <td>
                                <?= !empty($row['submitted_at'])
                                    ? htmlspecialchars($row['submitted_at'])
                                    : 'Not recorded' ?>
                            </td>

                            <td>
                                <span class="status <?= htmlspecialchars(strtolower($row['status'] ?? 'pending')) ?>">
                                    <?= htmlspecialchars(ucfirst($row['status'] ?? 'Pending')) ?>
                                </span>
                            </td>

                            <td>
                                <?= htmlspecialchars($row['admin_comment'] ?: 'No comment') ?>
                            </td>

                            <td>
                                <?php if (!empty($row['document_file'])): ?>
                                    <a class="document-link"
                                       href="../<?= htmlspecialchars(ltrim($row['document_file'], '/')) ?>"
                                       target="_blank"
                                       rel="noopener noreferrer">
                                        <i class="fa-solid fa-file-arrow-up"></i> View file
                                    </a>
                                <?php else: ?>
                                    <span class="no-file">No file uploaded</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8" class="empty-state">
                            <i class="fa-solid fa-folder-open"></i>
                            <h3>No verification records found</h3>
                            <p>Try changing your search or status filter.</p>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <p class="page-note">
        <i class="fa-solid fa-circle-info"></i>
        This page displays submitted verification records. Approval and rejection controls
        will be added in the Approval Decisions module.
    </p>
</main>

</body>
</html>

<?php
$stmt->close();
$conn->close();
?>
