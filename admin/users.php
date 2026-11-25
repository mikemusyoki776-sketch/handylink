<?php
session_start();

if (
    !isset($_SESSION["user_id"]) ||
    ($_SESSION["role"] ?? "") !== "admin"
) {
    header("Location: ../login.php");
    exit();
}

require_once "../config/database.php";

$adminName = $_SESSION["full_name"] ?? "Admin";
$adminId = (int) $_SESSION["user_id"];

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection is not available. Check config/database.php.");
}

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

$allowedRoles = ["client", "provider", "admin"];
$allowedStatuses = ["active", "inactive", "suspended"];
$message = "";
$messageType = "success";

/* Handle account-status changes. */
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "change_status") {
    $token = $_POST["csrf_token"] ?? "";
    $targetId = filter_input(INPUT_POST, "user_id", FILTER_VALIDATE_INT);
    $newStatus = strtolower(trim($_POST["status"] ?? ""));

    if (!is_string($token) || !hash_equals($_SESSION["csrf_token"], $token)) {
        $message = "Your session token could not be verified. Refresh the page and try again.";
        $messageType = "error";
    } elseif (!$targetId || !in_array($newStatus, $allowedStatuses, true)) {
        $message = "Invalid account-status request.";
        $messageType = "error";
    } elseif ($targetId === $adminId) {
        $message = "You cannot change the status of the account you are currently using.";
        $messageType = "error";
    } else {
        $check = $conn->prepare("SELECT id, role FROM users WHERE id = ? LIMIT 1");
        $check->bind_param("i", $targetId);
        $check->execute();
        $target = $check->get_result()->fetch_assoc();
        $check->close();

        if (!$target) {
            $message = "That user could not be found.";
            $messageType = "error";
        } elseif (($target["role"] ?? "") === "admin") {
            $message = "Administrator account status cannot be changed from this page.";
            $messageType = "error";
        } else {
            $update = $conn->prepare("UPDATE users SET status = ? WHERE id = ? LIMIT 1");
            $update->bind_param("si", $newStatus, $targetId);

            if ($update->execute()) {
                $message = "User account status updated successfully.";
            } else {
                $message = "The account status could not be updated. Please try again.";
                $messageType = "error";
            }
            $update->close();
        }
    }
}

/* Search and filters. */
$search = trim($_GET["q"] ?? "");
$roleFilter = strtolower(trim($_GET["role"] ?? ""));
$statusFilter = strtolower(trim($_GET["status"] ?? ""));

if (!in_array($roleFilter, $allowedRoles, true)) {
    $roleFilter = "";
}
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = "";
}

$where = [];
$params = [];
$types = "";

if ($search !== "") {
    $where[] = "(full_name LIKE ? OR email LIKE ? OR phone LIKE ? OR location LIKE ?)";
    $term = "%" . $search . "%";
    array_push($params, $term, $term, $term, $term);
    $types .= "ssss";
}
if ($roleFilter !== "") {
    $where[] = "role = ?";
    $params[] = $roleFilter;
    $types .= "s";
}
if ($statusFilter !== "") {
    $where[] = "status = ?";
    $params[] = $statusFilter;
    $types .= "s";
}

$whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";

/* Summary counts use the complete users table, not the current filters. */
$summary = ["total" => 0, "clients" => 0, "providers" => 0, "admins" => 0, "suspended" => 0];
$summaryResult = $conn->query(
    "SELECT COUNT(*) AS total,
            SUM(role = 'client') AS clients,
            SUM(role = 'provider') AS providers,
            SUM(role = 'admin') AS admins,
            SUM(status = 'suspended') AS suspended
     FROM users"
);
if ($summaryResult) {
    $row = $summaryResult->fetch_assoc();
    if ($row) {
        foreach ($summary as $key => $value) {
            $summary[$key] = (int) ($row[$key] ?? 0);
        }
    }
}

$sql = "SELECT id, full_name, email, phone, role, profile_image, location, status
        FROM users
        $whereSql
        ORDER BY id DESC
        LIMIT 300";

$stmt = $conn->prepare($sql);
if ($types !== "") {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$usersResult = $stmt->get_result();
$users = [];
while ($user = $usersResult->fetch_assoc()) {
    $users[] = $user;
}
$stmt->close();

function statusClass($status): string
{
    $status = strtolower((string) $status);
    return in_array($status, ["active", "inactive", "suspended"], true) ? $status : "unknown";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#123b32">
    <title>Users | HandyLink Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/admin_users.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
</head>
<body>
<div class="admin-layout" id="adminLayout">
    <aside class="sidebar" id="sidebar">
        <a href="dashboard.php" class="brand" title="HandyLink Admin">
            <span class="logo-container">
                <img src="../assets/images/handylink-logo.png" alt="HandyLink Solutions Logo">
            </span>
            <span class="brand-text">
                <strong>HandyLink</strong>
                <small>Admin Portal</small>
            </span>
        </a>

        <button type="button" class="sidebar-toggle" id="sidebarToggle"
                aria-label="Collapse sidebar" aria-expanded="true" title="Collapse sidebar">
            <i class="fa-solid fa-angles-left"></i>
        </button>

        <nav class="sidebar-nav" aria-label="Admin navigation">
            <p class="menu-heading">MAIN MENU</p>

            <a href="dashboard.php" class="menu-link" data-tooltip="Dashboard">
                <i class="fa-solid fa-house"></i><span class="menu-label">Dashboard</span>
            </a>

            <details class="menu-group" open>
                <summary class="menu-link active" data-tooltip="People">
                    <i class="fa-solid fa-users"></i>
                    <span class="menu-label">People</span>
                    <i class="fa-solid fa-chevron-down submenu-arrow"></i>
                </summary>
                <div class="submenu">
                    <a href="users.php" class="active" aria-current="page" data-tooltip="Users">Users</a>
                    <a href="employee.php" data-tooltip="Employee Records">Employee Records</a>
                    <a href="recruitment.php" data-tooltip="Recruitment and Applications">Recruitment &amp; Applications</a>
                    <a href="service_providers.php" data-tooltip="Service Providers">Service Providers</a>
                </div>
            </details>

            <details class="menu-group">
                <summary class="menu-link" data-tooltip="HR Management">
                    <i class="fa-solid fa-briefcase"></i>
                    <span class="menu-label">HR Management</span>
                    <i class="fa-solid fa-chevron-down submenu-arrow"></i>
                </summary>
                <div class="submenu">
                    <a href="attendance.php" data-tooltip="Attendance">Attendance</a>
                    <a href="leave_management.php" data-tooltip="Leave Management">Leave Management</a>
                    <a href="performance_evaluation.php" data-tooltip="Performance Evaluation">Performance Evaluation</a>
                </div>
            </details>

            <details class="menu-group">
                <summary class="menu-link" data-tooltip="Verification">
                    <i class="fa-solid fa-shield-halved"></i>
                    <span class="menu-label">Verification</span>
                    <i class="fa-solid fa-chevron-down submenu-arrow"></i>
                </summary>
                <div class="submenu">
                    <a href="document_credentials.php" data-tooltip="Documents and Credentials">Documents &amp; Credentials</a>
                    <a href="interviews.php" data-tooltip="Physical Interviews">Physical Interviews</a>
                    <a href="notifications.php" data-tooltip="Applicant Notifications">Applicant Notifications</a>
                    <a href="approval_decisions.php" data-tooltip="Approval Decisions">Approval Decisions</a>
                </div>
            </details>

            <details class="menu-group">
                <summary class="menu-link" data-tooltip="Services">
                    <i class="fa-solid fa-screwdriver-wrench"></i>
                    <span class="menu-label">Services</span>
                    <i class="fa-solid fa-chevron-down submenu-arrow"></i>
                </summary>
                <div class="submenu">
                    <a href="categories.php" data-tooltip="Categories">Categories</a>
                    <a href="service_requests.php" data-tooltip="Service Requests">Service Requests</a>
                    <a href="bookings.php" data-tooltip="Bookings">Bookings</a>
                    <a href="reviews.php" data-tooltip="Reviews and Ratings">Reviews &amp; Ratings</a>
                </div>
            </details>

            <details class="menu-group">
                <summary class="menu-link" data-tooltip="Reports">
                    <i class="fa-solid fa-chart-column"></i>
                    <span class="menu-label">Reports</span>
                    <i class="fa-solid fa-chevron-down submenu-arrow"></i>
                </summary>
                <div class="submenu">
                    <a href="reports.php" data-tooltip="Reports and Complaints">Reports &amp; Complaints</a>
                    <a href="activity_log.php" data-tooltip="Admin Activity Log">Admin Activity Log</a>
                </div>
            </details>
        </nav>

        <div class="sidebar-bottom">
            <a href="../logout.php" class="menu-link logout-link" data-tooltip="Logout">
                <i class="fa-solid fa-right-from-bracket"></i><span class="menu-label">Logout</span>
            </a>
        </div>
    </aside>

    <div class="main-area">
        <header class="topbar">
            <div class="topbar-left">
                <button type="button" class="mobile-menu-toggle" id="mobileMenuToggle" aria-label="Open navigation">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div>
                    <p class="topbar-caption">HANDYLINK SOLUTIONS</p>
                    <h2>User Management</h2>
                </div>
            </div>
            <div class="admin-account">
                <span class="admin-avatar"><i class="fa-solid fa-user-shield"></i></span>
                <span class="admin-account-name"><?= e($adminName) ?></span>
            </div>
        </header>

        <main class="dashboard-content users-content">
            <section class="users-heading">
                <div>
                    <p class="welcome-eyebrow">PEOPLE MANAGEMENT</p>
                    <h1>Users</h1>
                    <p>View registered accounts and manage user access across HandyLink.</p>
                </div>
            </section>

            <?php if ($message !== ""): ?>
                <div class="users-alert <?= e($messageType) ?>" role="status">
                    <i class="fa-solid <?= $messageType === "success" ? "fa-circle-check" : "fa-circle-exclamation" ?>"></i>
                    <?= e($message) ?>
                </div>
            <?php endif; ?>

            <section class="user-stats" aria-label="User summary">
                <article class="user-stat">
                    <span class="user-stat-icon"><i class="fa-solid fa-users"></i></span>
                    <div><p>Total users</p><strong><?= number_format($summary["total"]) ?></strong></div>
                </article>
                <article class="user-stat">
                    <span class="user-stat-icon"><i class="fa-solid fa-user"></i></span>
                    <div><p>Clients</p><strong><?= number_format($summary["clients"]) ?></strong></div>
                </article>
                <article class="user-stat">
                    <span class="user-stat-icon"><i class="fa-solid fa-screwdriver-wrench"></i></span>
                    <div><p>Service providers</p><strong><?= number_format($summary["providers"]) ?></strong></div>
                </article>
                <article class="user-stat">
                    <span class="user-stat-icon"><i class="fa-solid fa-user-lock"></i></span>
                    <div><p>Suspended accounts</p><strong><?= number_format($summary["suspended"]) ?></strong></div>
                </article>
            </section>

            <section class="users-panel">
                <div class="users-panel-heading">
                    <div>
                        <h2>Registered accounts</h2>
                        <p><?= number_format(count($users)) ?> result(s)<?= count($users) === 300 ? " — showing the latest 300" : "" ?></p>
                    </div>
                </div>

                <form class="users-filters" method="get" action="users.php">
                    <label class="users-search">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search name, email, phone or location">
                    </label>
                    <label>
                        <span class="sr-only">Filter by role</span>
                        <select name="role">
                            <option value="">All roles</option>
                            <?php foreach ($allowedRoles as $role): ?>
                                <option value="<?= e($role) ?>" <?= $roleFilter === $role ? "selected" : "" ?>><?= e(ucfirst($role)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        <span class="sr-only">Filter by status</span>
                        <select name="status">
                            <option value="">All statuses</option>
                            <?php foreach ($allowedStatuses as $status): ?>
                                <option value="<?= e($status) ?>" <?= $statusFilter === $status ? "selected" : "" ?>><?= e(ucfirst($status)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button class="users-button primary" type="submit"><i class="fa-solid fa-filter"></i> Apply</button>
                    <a class="users-button secondary" href="users.php"><i class="fa-solid fa-rotate-left"></i> Reset</a>
                </form>

                <div class="users-table-wrap">
                    <table class="users-table">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Contact</th>
                                <th>Role</th>
                                <th>Location</th>
                                <th>Status</th>
                                <th>Account action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$users): ?>
                            <tr><td colspan="6" class="users-empty">
                                <i class="fa-solid fa-user-slash"></i>
                                <strong>No users found</strong>
                                <span>Try changing your search or filters.</span>
                            </td></tr>
                        <?php else: ?>
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td>
                                        <div class="user-identity">
                                            <span class="user-initials">
                                                <?php
                                                $parts = preg_split('/\s+/', trim((string) $user["full_name"]));
                                                $initials = "";
                                                foreach (array_slice($parts ?: [], 0, 2) as $part) {
                                                    if ($part !== "") $initials .= function_exists("mb_substr") ? mb_substr($part, 0, 1) : substr($part, 0, 1);
                                                }
                                                echo e(strtoupper($initials ?: "U"));
                                                ?>
                                            </span>
                                            <span><strong><?= e($user["full_name"]) ?></strong><small>Account #<?= (int) $user["id"] ?></small></span>
                                        </div>
                                    </td>
                                    <td class="user-contact">
                                        <span><?= e($user["email"]) ?></span>
                                        <small><?= e($user["phone"] ?: "No phone provided") ?></small>
                                    </td>
                                    <td><span class="role-badge role-<?= e($user["role"]) ?>"><?= e(ucfirst($user["role"])) ?></span></td>
                                    <td><?= e($user["location"] ?: "Not provided") ?></td>
                                    <td><span class="status-badge status-<?= e(statusClass($user["status"])) ?>"><?= e(ucfirst($user["status"] ?: "Unknown")) ?></span></td>
                                    <td>
                                        <?php if (($user["role"] ?? "") === "admin"): ?>
                                            <span class="protected-account"><i class="fa-solid fa-shield-halved"></i> Protected</span>
                                        <?php elseif ((int) $user["id"] === $adminId): ?>
                                            <span class="protected-account"><i class="fa-solid fa-lock"></i> Your account</span>
                                        <?php else: ?>
                                            <form method="post" action="users.php<?= $search !== "" || $roleFilter !== "" || $statusFilter !== "" ? "?" . e(http_build_query(["q" => $search, "role" => $roleFilter, "status" => $statusFilter])) : "" ?>" class="user-status-form">
                                                <input type="hidden" name="csrf_token" value="<?= e($_SESSION["csrf_token"]) ?>">
                                                <input type="hidden" name="action" value="change_status">
                                                <input type="hidden" name="user_id" value="<?= (int) $user["id"] ?>">
                                                <select name="status" aria-label="Change status for <?= e($user["full_name"]) ?>">
                                                    <?php foreach ($allowedStatuses as $status): ?>
                                                        <option value="<?= e($status) ?>" <?= strtolower((string) $user["status"]) === $status ? "selected" : "" ?>><?= e(ucfirst($status)) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="submit" class="status-save-button" title="Save account status" aria-label="Save account status for <?= e($user["full_name"]) ?>">
                                                    <i class="fa-solid fa-check"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <p class="users-table-note"><i class="fa-solid fa-circle-info"></i> Passwords are never displayed. Administrator accounts are protected from status changes on this page.</p>
            </section>
        </main>

        <footer class="admin-footer">
            <p>&copy; <?= date("Y") ?> HandyLink Solutions. All Rights Reserved.</p>
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
    sidebarToggle.setAttribute("aria-label", collapsed ? "Expand sidebar" : "Collapse sidebar");
    sidebarToggle.title = collapsed ? "Expand sidebar" : "Collapse sidebar";
});

document.querySelectorAll(".menu-group").forEach(function (group) {
    group.addEventListener("toggle", function () {
        if (group.open && !layout.classList.contains("sidebar-collapsed")) {
            document.querySelectorAll(".menu-group").forEach(function (other) {
                if (other !== group) other.open = false;
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
            if (!group.querySelector(".submenu a.active")) group.open = false;
        });
    }
});
</script>
</body>
</html>
