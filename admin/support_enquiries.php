<?php
session_start();

if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'admin'
) {
    header("Location: ../login.php");
    exit();
}

require_once __DIR__ . '/../config/database.php';

$adminId = (int) $_SESSION['user_id'];

$message = '';
$messageType = '';

/* CSRF protection */
if (empty($_SESSION['support_admin_csrf'])) {
    $_SESSION['support_admin_csrf'] = bin2hex(random_bytes(32));
}

/* Update enquiry status */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['support_admin_csrf'], $_POST['csrf_token'])
    ) {
        $message = "Invalid request. Please refresh the page.";
        $messageType = "error";

    } else {

        $enquiryId = (int) ($_POST['enquiry_id'] ?? 0);
        $newStatus = trim($_POST['status'] ?? '');

        $allowedStatuses = ['Open', 'In Progress', 'Resolved'];

        if ($enquiryId <= 0 || !in_array($newStatus, $allowedStatuses, true)) {

            $message = "Invalid enquiry information.";
            $messageType = "error";

        } else {

            $updateStmt = $conn->prepare("
                UPDATE support_tickets
                SET status = ?
                WHERE id = ?
            ");

            if ($updateStmt) {

                $updateStmt->bind_param(
                    "si",
                    $newStatus,
                    $enquiryId
                );

                if ($updateStmt->execute()) {
                    $message = "Enquiry status updated successfully.";
                    $messageType = "success";
                } else {
                    $message = "Unable to update the enquiry status.";
                    $messageType = "error";
                }

                $updateStmt->close();

            } else {
                $message = "Something went wrong. Please try again.";
                $messageType = "error";
            }
        }
    }
}

/* Mark support notifications as read when admin visits this page */
$readStmt = $conn->prepare("
    UPDATE notifications
    SET is_read = 1
    WHERE user_id = ? AND type = 'support' AND is_read = 0
");

if ($readStmt) {
    $readStmt->bind_param("i", $adminId);
    $readStmt->execute();
    $readStmt->close();
}

/* Get enquiry statistics */
$stats = [
    'total' => 0,
    'open' => 0,
    'progress' => 0,
    'resolved' => 0
];

$statsQuery = $conn->query("
    SELECT
        COUNT(*) AS total,
        SUM(status = 'Open') AS open_count,
        SUM(status = 'In Progress') AS progress_count,
        SUM(status = 'Resolved') AS resolved_count
    FROM support_tickets
");

if ($statsQuery) {
    $statsRow = $statsQuery->fetch_assoc();

    $stats['total'] = (int) ($statsRow['total'] ?? 0);
    $stats['open'] = (int) ($statsRow['open_count'] ?? 0);
    $stats['progress'] = (int) ($statsRow['progress_count'] ?? 0);
    $stats['resolved'] = (int) ($statsRow['resolved_count'] ?? 0);
}

/* Fetch all support enquiries */
$enquiries = [];

$enquiryQuery = $conn->query("
    SELECT
        st.id,
        st.user_id,
        st.user_type,
        st.subject,
        st.category,
        st.description,
        st.status,
        st.created_at,
        u.full_name,
        u.email,
        u.phone
    FROM support_tickets st
    INNER JOIN users u ON st.user_id = u.id
    ORDER BY
        CASE
            WHEN st.status = 'Open' THEN 1
            WHEN st.status = 'In Progress' THEN 2
            ELSE 3
        END,
        st.created_at DESC
");

if ($enquiryQuery) {
    while ($row = $enquiryQuery->fetch_assoc()) {
        $enquiries[] = $row;
    }
}

$adminName = $_SESSION['full_name'] ?? 'Administrator';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Support Enquiries | HandyLink Admin</title>

    <link rel="stylesheet" href="../assets/css/style.css">

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">

    <style>
        .support-page {
            padding: 30px;
        }

        .support-page-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
        }

        .support-page-heading h1 {
            margin: 0 0 7px;
            font-size: 25px;
        }

        .support-page-heading p {
            margin: 0;
            color: #64748b;
        }

        .support-stat-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 25px;
        }

        .support-stat-card {
            background: #fff;
            padding: 20px;
            border-radius: 10px;
            border: 1px solid #e5e7eb;
        }

        .support-stat-card span {
            display: block;
            color: #64748b;
            font-size: 13px;
            margin-bottom: 9px;
        }

        .support-stat-card strong {
            font-size: 27px;
            color: #123b32;
        }

        .support-panel {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            overflow: hidden;
        }

        .support-panel-heading {
            padding: 20px;
            border-bottom: 1px solid #e5e7eb;
        }

        .support-panel-heading h2 {
            margin: 0 0 5px;
            font-size: 18px;
        }

        .support-panel-heading p {
            margin: 0;
            color: #64748b;
            font-size: 13px;
        }

        .support-table-wrapper {
            width: 100%;
            overflow-x: auto;
        }

        .support-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 750px;
        }

        .support-table th,
        .support-table td {
            padding: 15px 18px;
            text-align: left;
            border-bottom: 1px solid #edf0f2;
            font-size: 13px;
        }

        .support-table th {
            background: #f8fafc;
            color: #475569;
            font-weight: 600;
        }

        .support-table td {
            color: #334155;
        }

        .support-sender strong {
            display: block;
            margin-bottom: 4px;
            color: #1e293b;
        }

        .support-sender small {
            color: #64748b;
        }

        .support-type {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            background: #eff6ff;
            color: #1d4ed8;
            font-size: 11px;
            text-transform: capitalize;
        }

        .support-status {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        .support-status.open {
            background: #fff7ed;
            color: #c2410c;
        }

        .support-status.in-progress {
            background: #eff6ff;
            color: #1d4ed8;
        }

        .support-status.resolved {
            background: #dcfce7;
            color: #15803d;
        }

        .support-action {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 12px;
            border-radius: 6px;
            background: #123b32;
            color: #fff;
            text-decoration: none;
            font-size: 12px;
            border: none;
            cursor: pointer;
        }

        .support-action:hover {
            background: #1b5949;
            color: #fff;
        }

        .support-empty {
            padding: 45px 20px;
            text-align: center;
            color: #64748b;
        }

        .support-empty i {
            font-size: 35px;
            margin-bottom: 12px;
        }

        .support-alert {
            padding: 13px 16px;
            margin-bottom: 20px;
            border-radius: 7px;
            font-size: 13px;
        }

        .support-alert.success {
            background: #dcfce7;
            color: #166534;
        }

        .support-alert.error {
            background: #fee2e2;
            color: #991b1b;
        }

        .support-details {
            margin-top: 25px;
            padding: 22px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
        }

        .support-details h2 {
            margin-top: 0;
            font-size: 19px;
        }

        .support-details p {
            color: #475569;
            line-height: 1.7;
        }

        .support-details-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 15px;
            margin-bottom: 18px;
        }

        .support-detail-item span {
            display: block;
            color: #64748b;
            font-size: 12px;
            margin-bottom: 5px;
        }

        .support-detail-item strong {
            color: #1e293b;
            font-size: 13px;
        }

        .support-description {
            padding: 15px;
            background: #f8fafc;
            border-radius: 7px;
            white-space: pre-wrap;
        }

        .support-status-form {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 20px;
        }

        .support-status-form select {
            min-width: 170px;
            padding: 10px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            background: #fff;
        }

        .support-status-form button {
            padding: 10px 16px;
            background: #123b32;
            color: #fff;
            border: none;
            border-radius: 6px;
            cursor: pointer;
        }

        .support-back {
            display: inline-block;
            margin-bottom: 15px;
            color: #123b32;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
        }

        @media (max-width: 900px) {
            .support-stat-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 600px) {
            .support-page {
                padding: 18px 12px;
            }

            .support-page-heading {
                align-items: flex-start;
                flex-direction: column;
            }

            .support-stat-grid {
                gap: 10px;
            }

            .support-stat-card {
                padding: 15px;
            }

            .support-details-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<div class="admin-layout" id="adminLayout">

    <aside class="sidebar" id="sidebar">

        <a href="dashboard.php" class="brand" title="HandyLink Admin">
            <span class="logo-container">
                <img src="../assets/images/handylink-logo.png"
                     alt="HandyLink Solutions Logo">
            </span>

            <span class="brand-text">
                <strong>HandyLink</strong>
                <small>Admin Portal</small>
            </span>
        </a>

        <button type="button"
                class="sidebar-toggle"
                id="sidebarToggle"
                aria-label="Collapse sidebar"
                aria-expanded="true"
                title="Collapse sidebar">
            <i class="fa-solid fa-angles-left"></i>
        </button>

        <nav class="sidebar-nav" aria-label="Admin navigation">

            <p class="menu-heading">MAIN MENU</p>

            <a href="dashboard.php" class="menu-link" data-tooltip="Dashboard">
                <i class="fa-solid fa-house"></i>
                <span class="menu-label">Dashboard</span>
            </a>

            <details class="menu-group">
                <summary class="menu-link" data-tooltip="People">
                    <i class="fa-solid fa-users"></i>
                    <span class="menu-label">People</span>
                    <i class="fa-solid fa-chevron-down submenu-arrow"></i>
                </summary>

                <div class="submenu">
                    <a href="users.php">Users</a>
                    <a href="employee.php">Employee Records</a>
                    <a href="recruitment.php">Recruitment &amp; Applications</a>
                    <a href="service_providers.php">Service Providers</a>
                </div>
            </details>

            <details class="menu-group">
                <summary class="menu-link" data-tooltip="HR Management">
                    <i class="fa-solid fa-briefcase"></i>
                    <span class="menu-label">HR Management</span>
                    <i class="fa-solid fa-chevron-down submenu-arrow"></i>
                </summary>

                <div class="submenu">
                    <a href="attendance.php">Attendance</a>
                    <a href="leave_management.php">Leave Management</a>
                    <a href="performance_evaluation.php">Performance Evaluation</a>
                </div>
            </details>

            <details class="menu-group">
                <summary class="menu-link" data-tooltip="Verification">
                    <i class="fa-solid fa-shield-halved"></i>
                    <span class="menu-label">Verification</span>
                    <i class="fa-solid fa-chevron-down submenu-arrow"></i>
                </summary>

                <div class="submenu">
                    <a href="document_credentials.php">Documents &amp; Credentials</a>
                    <a href="#">Physical Interviews</a>
                    <a href="notifications.php">Applicant Notifications</a>
                    <a href="approval_decisions.php">Approval Decisions</a>
                </div>
            </details>

            <details class="menu-group">
                <summary class="menu-link" data-tooltip="Services">
                    <i class="fa-solid fa-screwdriver-wrench"></i>
                    <span class="menu-label">Services</span>
                    <i class="fa-solid fa-chevron-down submenu-arrow"></i>
                </summary>

                <div class="submenu">
                    <a href="categories.php">Categories</a>
                    <a href="service_requests.php">Service Requests</a>
                    <a href="bookings.php">Bookings</a>
                    <a href="reviews.php">Reviews &amp; Ratings</a>
                </div>
            </details>

            <details class="menu-group" open>
                <summary class="menu-link" data-tooltip="Reports">
                    <i class="fa-solid fa-chart-column"></i>
                    <span class="menu-label">Reports</span>
                    <i class="fa-solid fa-chevron-down submenu-arrow"></i>
                </summary>

                <div class="submenu">
                    <a href="reports.php">Reports</a>
                    <a href="support_enquiries.php" class="active">Support Enquiries</a>
                </div>
            </details>

        </nav>

        <div class="sidebar-bottom">
            <a href="logout.php" class="menu-link logout-link" data-tooltip="Logout">
                <i class="fa-solid fa-right-from-bracket"></i>
                <span class="menu-label">Logout</span>
            </a>
        </div>

    </aside>

    <div class="main-area">

        <header class="topbar">

            <div class="topbar-left">
                <button type="button"
                        class="mobile-menu-toggle"
                        id="mobileMenuToggle"
                        aria-label="Open navigation">
                    <i class="fa-solid fa-bars"></i>
                </button>

                <div>
                    <p class="topbar-caption">HANDYLINK SOLUTIONS</p>
                    <h2>Support Enquiries</h2>
                </div>
            </div>

            <div class="admin-account">
                <span class="admin-avatar">
                    <i class="fa-solid fa-user-shield"></i>
                </span>

                <span class="admin-account-name">
                    <?= htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8') ?>
                </span>
            </div>

        </header>

        <main class="support-page">

            <div class="support-page-heading">
                <div>
                    <h1>Support Enquiries</h1>
                    <p>Manage assistance requests submitted by clients and service providers.</p>
                </div>
            </div>

            <?php if ($message !== ''): ?>
                <div class="support-alert <?= htmlspecialchars($messageType) ?>">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <section class="support-stat-grid">

                <div class="support-stat-card">
                    <span>Total Enquiries</span>
                    <strong><?= $stats['total'] ?></strong>
                </div>

                <div class="support-stat-card">
                    <span>Open</span>
                    <strong><?= $stats['open'] ?></strong>
                </div>

                <div class="support-stat-card">
                    <span>In Progress</span>
                    <strong><?= $stats['progress'] ?></strong>
                </div>

                <div class="support-stat-card">
                    <span>Resolved</span>
                    <strong><?= $stats['resolved'] ?></strong>
                </div>

            </section>

            <section class="support-panel">

                <div class="support-panel-heading">
                    <h2>Submitted Enquiries</h2>
                    <p>All enquiries received from HandyLink users.</p>
                </div>

                <?php if (!empty($enquiries)): ?>

                    <div class="support-table-wrapper">

                        <table class="support-table">

                            <thead>
                                <tr>
                                    <th>Sender</th>
                                    <th>Subject</th>
                                    <th>Category</th>
                                    <th>Date Submitted</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>

                            <tbody>

                                <?php foreach ($enquiries as $enquiry): ?>

                                    <tr>

                                        <td class="support-sender">
                                            <strong>
                                                <?= htmlspecialchars($enquiry['full_name']) ?>
                                            </strong>

                                            <small>
                                                <?= htmlspecialchars(ucfirst($enquiry['user_type'])) ?>
                                            </small>
                                        </td>

                                        <td>
                                            <?= htmlspecialchars($enquiry['subject']) ?>
                                        </td>

                                        <td>
                                            <?= htmlspecialchars($enquiry['category']) ?>
                                        </td>

                                        <td>
                                            <?= date('d M Y', strtotime($enquiry['created_at'])) ?>
                                        </td>

                                        <td>
                                            <span class="support-status <?= strtolower(str_replace(' ', '-', $enquiry['status'])) ?>">
                                                <?= htmlspecialchars($enquiry['status']) ?>
                                            </span>
                                        </td>

                                        <td>
                                            <a
                                                href="support_enquiries.php?view=<?= (int) $enquiry['id'] ?>"
                                                class="support-action">
                                                <i class="fa-solid fa-eye"></i>
                                                View
                                            </a>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                <?php else: ?>

                    <div class="support-empty">
                        <i class="fa-regular fa-folder-open"></i>
                        <p>No support enquiries have been submitted yet.</p>
                    </div>

                <?php endif; ?>

            </section>

            <?php
            /* Fetch selected enquiry */
            $selectedEnquiry = null;
            $viewId = (int) ($_GET['view'] ?? 0);

            if ($viewId > 0) {

                $viewStmt = $conn->prepare("
                    SELECT
                        st.id,
                        st.user_id,
                        st.user_type,
                        st.subject,
                        st.category,
                        st.description,
                        st.status,
                        st.created_at,
                        u.full_name,
                        u.email,
                        u.phone
                    FROM support_tickets st
                    INNER JOIN users u ON st.user_id = u.id
                    WHERE st.id = ?
                    LIMIT 1
                ");

                if ($viewStmt) {
                    $viewStmt->bind_param("i", $viewId);
                    $viewStmt->execute();

                    $viewResult = $viewStmt->get_result();
                    $selectedEnquiry = $viewResult->fetch_assoc();

                    $viewStmt->close();
                }
            }
            ?>

            <?php if ($viewId > 0 && $selectedEnquiry): ?>

                <section class="support-details">

                    <a href="support_enquiries.php" class="support-back">
                        <i class="fa-solid fa-arrow-left"></i>
                        Back to all enquiries
                    </a>

                    <h2>Enquiry Details</h2>

                    <div class="support-details-grid">

                        <div class="support-detail-item">
                            <span>Sender</span>
                            <strong>
                                <?= htmlspecialchars($selectedEnquiry['full_name']) ?>
                            </strong>
                        </div>

                        <div class="support-detail-item">
                            <span>User Type</span>
                            <strong>
                                <?= htmlspecialchars(ucfirst($selectedEnquiry['user_type'])) ?>
                            </strong>
                        </div>

                        <div class="support-detail-item">
                            <span>Email Address</span>
                            <strong>
                                <?= htmlspecialchars($selectedEnquiry['email'] ?? 'Not provided') ?>
                            </strong>
                        </div>

                        <div class="support-detail-item">
                            <span>Phone Number</span>
                            <strong>
                                <?= htmlspecialchars($selectedEnquiry['phone'] ?? 'Not provided') ?>
                            </strong>
                        </div>

                        <div class="support-detail-item">
                            <span>Category</span>
                            <strong>
                                <?= htmlspecialchars($selectedEnquiry['category']) ?>
                            </strong>
                        </div>

                        <div class="support-detail-item">
                            <span>Date Submitted</span>
                            <strong>
                                <?= date('d M Y, h:i A', strtotime($selectedEnquiry['created_at'])) ?>
                            </strong>
                        </div>

                    </div>

                    <h3>
                        <?= htmlspecialchars($selectedEnquiry['subject']) ?>
                    </h3>

                    <div class="support-description"><?= htmlspecialchars($selectedEnquiry['description']) ?></div>

                    <form method="POST" class="support-status-form">

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= htmlspecialchars($_SESSION['support_admin_csrf']) ?>">

                        <input
                            type="hidden"
                            name="enquiry_id"
                            value="<?= (int) $selectedEnquiry['id'] ?>">

                        <label for="status"><strong>Update Status:</strong></label>

                        <select name="status" id="status">

                            <option value="Open"
                                <?= $selectedEnquiry['status'] === 'Open' ? 'selected' : '' ?>>
                                Open
                            </option>

                            <option value="In Progress"
                                <?= $selectedEnquiry['status'] === 'In Progress' ? 'selected' : '' ?>>
                                In Progress
                            </option>

                            <option value="Resolved"
                                <?= $selectedEnquiry['status'] === 'Resolved' ? 'selected' : '' ?>>
                                Resolved
                            </option>

                        </select>

                        <button type="submit">
                            <i class="fa-solid fa-floppy-disk"></i>
                            Save Status
                        </button>

                    </form>

                </section>

            <?php elseif ($viewId > 0): ?>

                <div class="support-alert error" style="margin-top:20px;">
                    The selected enquiry could not be found.
                </div>

            <?php endif; ?>

        </main>

        <footer class="admin-footer">
            <p>
                &copy; <?= date('Y') ?> HandyLink Solutions.
                All Rights Reserved.
            </p>
        </footer>

    </div>

</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<script>
const layout = document.getElementById("adminLayout");
const sidebar = document.getElementById("sidebar");
const sidebarToggle = document.getElementById("sidebarToggle");
const mobileMenuToggle = document.getElementById("mobileMenuToggle");
const sidebarOverlay = document.getElementById("sidebarOverlay");

sidebarToggle.addEventListener("click", function () {
    const collapsed = layout.classList.toggle("sidebar-collapsed");

    sidebarToggle.setAttribute("aria-expanded", String(!collapsed));
    sidebarToggle.setAttribute(
        "aria-label",
        collapsed ? "Expand sidebar" : "Collapse sidebar"
    );
    sidebarToggle.title = collapsed ? "Expand sidebar" : "Collapse sidebar";
});

document.querySelectorAll(".menu-group").forEach(function (group) {
    group.addEventListener("toggle", function () {
        if (group.open && !layout.classList.contains("sidebar-collapsed")) {
            document.querySelectorAll(".menu-group").forEach(function (other) {
                if (other !== group) {
                    other.open = false;
                }
            });
        }
    });
});

function openMobileSidebar() {
    sidebar.classList.add("mobile-sidebar-open");
    sidebarOverlay.classList.add("visible");
}

function closeMobileSidebar() {
    sidebar.classList.remove("mobile-sidebar-open");
    sidebarOverlay.classList.remove("visible");
}

mobileMenuToggle.addEventListener("click", openMobileSidebar);
sidebarOverlay.addEventListener("click", closeMobileSidebar);

document.addEventListener("keydown", function (event) {
    if (event.key === "Escape") {
        closeMobileSidebar();

        document.querySelectorAll(".menu-group[open]").forEach(function (group) {
            group.open = false;
        });
    }
});
</script>

</body>
</html>