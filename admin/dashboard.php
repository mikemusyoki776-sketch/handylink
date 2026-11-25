<?php
session_start();

/* Protect the admin dashboard */
if (
    !isset($_SESSION["user_id"]) ||
    ($_SESSION["role"] ?? "") !== "admin"
) {
    header("Location: ../login.php");
    exit();
}

require_once __DIR__ . '/../config/database.php';

$adminId = (int) $_SESSION["user_id"];
$adminName = $_SESSION["full_name"] ?? "Admin";

/* Get unread notifications */
$unreadCount = 0;
$adminNotifications = [];

$countStmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM notifications
    WHERE user_id = ? AND is_read = 0
");

if ($countStmt) {
    $countStmt->bind_param("i", $adminId);
    $countStmt->execute();

    $countResult = $countStmt->get_result();
    $countRow = $countResult->fetch_assoc();

    $unreadCount = (int) ($countRow["total"] ?? 0);
    $countStmt->close();
}

/* Get recent notifications */
$notificationStmt = $conn->prepare("
    SELECT id, title, message, type, is_read, created_at
    FROM notifications
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 5
");

if ($notificationStmt) {
    $notificationStmt->bind_param("i", $adminId);
    $notificationStmt->execute();

    $notificationResult = $notificationStmt->get_result();

    while ($row = $notificationResult->fetch_assoc()) {
        $adminNotifications[] = $row;
    }

    $notificationStmt->close();
}

/* Reusable dashboard count function */
function getDashboardCount(mysqli $conn, string $sql): int
{
    $result = $conn->query($sql);

    if (!$result) {
        return 0;
    }

    $row = $result->fetch_assoc();

    return (int) ($row["total"] ?? 0);
}

/* Dashboard summary statistics */

$totalClients = getDashboardCount(
    $conn,
    "SELECT COUNT(*) AS total
     FROM users
     WHERE role = 'client'"
);

$totalApprovedProviders = getDashboardCount(
    $conn,
    "SELECT COUNT(*) AS total
     FROM providers
     WHERE verification_status = 'approved'"
);

$totalPendingApplications = getDashboardCount(
    $conn,
    "SELECT COUNT(*) AS total
     FROM job_applications
     WHERE status = 'pending'"
);

$totalPendingLeave = getDashboardCount(
    $conn,
    "SELECT COUNT(*) AS total
     FROM leave_requests
     WHERE status = 'pending'"
);

$totalPendingProviderChecks = getDashboardCount(
    $conn,
    "SELECT COUNT(*) AS total
     FROM providers
     WHERE verification_status = 'pending'"
);

$totalCancelledBookings = getDashboardCount(
    $conn,
    "SELECT COUNT(*) AS total
     FROM bookings
     WHERE status = 'cancelled'"
);

$totalBookings = getDashboardCount(
    $conn,
    "SELECT COUNT(*) AS total
     FROM bookings"
);


/* =====================================================
   AI INSIGHTS
   Initial implementation uses database-driven rules.
   It does not require an external AI API.
===================================================== */

$dashboardInsights = [];

/* Pending applications */
if ($totalPendingApplications > 0) {

    $dashboardInsights[] = [
        "icon" => "fa-file-lines",
        "title" => "Recruitment requires attention",
        "message" => number_format($totalPendingApplications) .
            " job application(s) are awaiting review.",
        "link" => "recruitment.php",
        "link_text" => "Review applications"
    ];
}

/* Pending provider verification */
if ($totalPendingProviderChecks > 0) {

    $dashboardInsights[] = [
        "icon" => "fa-user-check",
        "title" => "Provider verification pending",
        "message" => number_format($totalPendingProviderChecks) .
            " provider(s) are waiting for verification.",
        "link" => "approval_decisions.php",
        "link_text" => "Review providers"
    ];
}

/* Pending leave requests */
if ($totalPendingLeave > 0) {

    $dashboardInsights[] = [
        "icon" => "fa-calendar-minus",
        "title" => "Leave requests need review",
        "message" => number_format($totalPendingLeave) .
            " leave request(s) are awaiting a decision.",
        "link" => "leave_management.php",
        "link_text" => "View leave requests"
    ];
}

/* Booking cancellation analysis */
if ($totalBookings > 0 && $totalCancelledBookings > 0) {

    $cancelRate = (
        $totalCancelledBookings / $totalBookings
    ) * 100;

    if ($cancelRate >= 20) {

        $dashboardInsights[] = [
            "icon" => "fa-calendar-xmark",
            "title" => "Booking cancellations to review",
            "message" => number_format($cancelRate, 1) .
                "% of recorded bookings are marked as cancelled. " .
                "Check booking records for possible patterns.",
            "link" => "bookings.php",
            "link_text" => "Review bookings"
        ];
    }
}

/* No outstanding issues */
if (empty($dashboardInsights)) {

    $dashboardInsights[] = [
        "icon" => "fa-circle-check",
        "title" => "No urgent items identified",
        "message" =>
            "There are no pending recruitment, provider verification " .
            "or leave items based on the current dashboard checks.",
        "link" => "reports.php",
        "link_text" => "View reports"
    ];
}


/* =====================================================
   PROVIDER VERIFICATION CHART DATA
===================================================== */

$providerLabels = [];
$providerValues = [];

$providerQuery = $conn->query("
    SELECT verification_status, COUNT(*) AS total
    FROM providers
    GROUP BY verification_status
");

if ($providerQuery) {

    while ($row = $providerQuery->fetch_assoc()) {

        $providerLabels[] = ucfirst(
            (string) ($row["verification_status"] ?? "Unknown")
        );

        $providerValues[] = (int) $row["total"];
    }
}


/* =====================================================
   BOOKING STATUS CHART DATA
===================================================== */

$bookingLabels = [];
$bookingValues = [];

$bookingQuery = $conn->query("
    SELECT status, COUNT(*) AS total
    FROM bookings
    GROUP BY status
");

if ($bookingQuery) {

    while ($row = $bookingQuery->fetch_assoc()) {

        $bookingLabels[] = ucfirst(
            (string) ($row["status"] ?? "Unknown")
        );

        $bookingValues[] = (int) $row["total"];
    }
}


/* =====================================================
   MONTHLY BOOKING CHART DATA
===================================================== */

$monthLabels = [];
$monthValues = [];

$monthlyQuery = $conn->query("
    SELECT
        DATE_FORMAT(booking_date, '%b %Y') AS month_name,
        COUNT(*) AS total
    FROM bookings
    WHERE booking_date >= DATE_SUB(
        CURDATE(),
        INTERVAL 5 MONTH
    )
    GROUP BY YEAR(booking_date), MONTH(booking_date)
    ORDER BY YEAR(booking_date), MONTH(booking_date)
");

if ($monthlyQuery) {

    while ($row = $monthlyQuery->fetch_assoc()) {

        $monthLabels[] = $row["month_name"];
        $monthValues[] = (int) $row["total"];
    }
}


/* Safely pass chart data to JavaScript */
$providerLabelsJson = json_encode($providerLabels);
$providerValuesJson = json_encode($providerValues);

$bookingLabelsJson = json_encode($bookingLabels);
$bookingValuesJson = json_encode($bookingValues);

$monthLabelsJson = json_encode($monthLabels);
$monthValuesJson = json_encode($monthValues);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <meta name="theme-color" content="#123b32">

    <title>HandyLink Admin Dashboard</title>

    <link rel="stylesheet" href="../assets/css/style.css">

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>


    <style>

        /* =========================================
           DASHBOARD GENERAL
        ========================================= */

        .dashboard-content {
            min-width: 0;
        }

        .dashboard-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin: 8px 0 18px;
        }

        .dashboard-heading h1 {
            margin: 0 0 4px;
            color: #123b32;
            font-size: 24px;
            font-weight: 600;
        }

        .dashboard-heading p {
            margin: 0;
            color: #777;
            font-size: 13px;
        }

        .dashboard-date {
            color: #666;
            font-size: 12px;
            white-space: nowrap;
        }


        /* =========================================
           SUMMARY CARDS
        ========================================= */

        .dashboard-summary {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 18px;
            margin-top: 25px;
        }

        .summary-card {
            display: flex;
            align-items: center;
            gap: 14px;
            min-width: 0;
            padding: 20px;
            background: #fff;
            border: 1px solid #e8ece9;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .03);
        }

        .summary-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 46px;
            width: 46px;
            height: 46px;
            border-radius: 8px;
            background: #edf3ef;
            color: #123b32;
            font-size: 19px;
        }

        .summary-info span {
            display: block;
            color: #777;
            font-size: 12px;
            margin-bottom: 5px;
        }

        .summary-info strong {
            display: block;
            color: #123b32;
            font-size: 24px;
            line-height: 1.2;
        }


        /* =========================================
           GENERAL SECTIONS
        ========================================= */

        .dashboard-section {
            margin-top: 30px;
        }

        .section-heading {
            margin-bottom: 17px;
        }

        .section-heading h2 {
            color: #123b32;
            font-size: 20px;
            margin: 0 0 5px;
        }

        .section-heading p {
            color: #777;
            font-size: 13px;
            margin: 0;
        }


        /* =========================================
           AI INSIGHTS
        ========================================= */

        .ai-insights {
            margin-top: 26px;
            padding: 20px;
            background: #fff;
            border: 1px solid #e2e8e4;
            border-left: 4px solid #123b32;
            border-radius: 10px;
        }

        .ai-insights-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 6px;
        }

        .ai-insights-header i {
            color: #123b32;
            font-size: 18px;
        }

        .ai-insights-header h2 {
            margin: 0;
            color: #123b32;
            font-size: 18px;
            font-weight: 600;
        }

        .ai-insights-note {
            margin: 0 0 16px;
            color: #777;
            font-size: 12px;
        }

        .ai-insight-list {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .ai-insight-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 14px;
            background: #f8faf8;
            border: 1px solid #edf1ed;
            border-radius: 8px;
        }

        .ai-insight-item > i {
            margin-top: 2px;
            color: #123b32;
            font-size: 16px;
        }

        .ai-insight-item h3 {
            margin: 0 0 5px;
            color: #26352e;
            font-size: 13px;
        }

        .ai-insight-item p {
            margin: 0 0 8px;
            color: #666;
            font-size: 12px;
            line-height: 1.5;
        }

        .ai-insight-item a {
            color: #123b32;
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
        }

        .ai-insight-item a:hover {
            text-decoration: underline;
        }


        /* =========================================
           CHARTS
        ========================================= */

        .charts-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px;
        }

        .chart-card {
            min-width: 0;
            padding: 20px;
            background: #fff;
            border: 1px solid #e8ece9;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .03);
        }

        .chart-card h3 {
            color: #123b32;
            font-size: 15px;
            margin: 0 0 5px;
        }

        .chart-card p {
            color: #888;
            font-size: 12px;
            margin: 0 0 16px;
        }

        .chart-container {
            position: relative;
            width: 100%;
            height: 260px;
        }

        .monthly-chart {
            grid-column: 1 / -1;
        }


        /* =========================================
           QUICK ACTIONS
        ========================================= */

        .dashboard-actions {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 15px;
        }

        .action-link {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 17px;
            color: #123b32;
            text-decoration: none;
            background: #fff;
            border: 1px solid #e8ece9;
            border-radius: 9px;
            transition: .2s ease;
        }

        .action-link:hover {
            border-color: #123b32;
        }

        .action-link span {
            font-size: 14px;
            font-weight: 600;
        }

        .action-link i {
            color: #123b32;
        }


        /* =========================================
           RECENT NOTIFICATIONS
        ========================================= */

        .recent-notifications {
            background: #fff;
            border: 1px solid #e8ece9;
            border-radius: 10px;
            overflow: hidden;
        }

        .recent-notification {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            padding: 15px 18px;
            border-bottom: 1px solid #edf0ee;
        }

        .recent-notification:last-child {
            border-bottom: 0;
        }

        .recent-notification strong {
            display: block;
            color: #123b32;
            font-size: 13px;
            margin-bottom: 4px;
        }

        .recent-notification p {
            color: #777;
            font-size: 12px;
            margin: 0;
        }

        .recent-notification small {
            flex: 0 0 auto;
            color: #999;
            font-size: 11px;
        }

        .empty-activity {
            padding: 20px;
            color: #777;
            font-size: 13px;
            text-align: center;
        }


        /* =========================================
           RESPONSIVE DESIGN
        ========================================= */

        @media (max-width: 1100px) {

            .dashboard-summary {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

        }

        @media (max-width: 768px) {

            .charts-grid,
            .dashboard-actions,
            .ai-insight-list {
                grid-template-columns: 1fr;
            }

            .dashboard-heading {
                align-items: flex-start;
                flex-direction: column;
            }

            .monthly-chart {
                grid-column: auto;
            }

            .chart-container {
                height: 230px;
            }

        }

        @media (max-width: 480px) {

            .dashboard-summary {
                grid-template-columns: 1fr;
            }

            .summary-card {
                padding: 16px;
            }

        }

    </style>

</head>


<body>

<div class="admin-layout" id="adminLayout">

    <!-- =====================================
         SIDEBAR
    ====================================== -->

    <aside class="sidebar" id="sidebar">

        <a href="dashboard.php"
           class="brand"
           title="HandyLink Admin">

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


            <a href="dashboard.php"
               class="menu-link active"
               data-tooltip="Dashboard">

                <i class="fa-solid fa-house"></i>

                <span class="menu-label">Dashboard</span>

            </a>


            <!-- PEOPLE -->

            <details class="menu-group">

                <summary class="menu-link"
                         data-tooltip="People">

                    <i class="fa-solid fa-users"></i>

                    <span class="menu-label">People</span>

                    <i class="fa-solid fa-chevron-down submenu-arrow"></i>

                </summary>

                <div class="submenu">

                    <a href="users.php" data-tooltip="Users">
                        Users
                    </a>

                    <a href="employee.php" data-tooltip="Employee Records">
                        Employee Records
                    </a>

                    <a href="recruitment.php"
                       data-tooltip="Recruitment and Applications">
                        Recruitment &amp; Applications
                    </a>

                    <a href="service_providers.php"
                       data-tooltip="Service Providers">
                        Service Providers
                    </a>

                </div>

            </details>


            <!-- HR MANAGEMENT -->

            <details class="menu-group">

                <summary class="menu-link"
                         data-tooltip="HR Management">

                    <i class="fa-solid fa-briefcase"></i>

                    <span class="menu-label">HR Management</span>

                    <i class="fa-solid fa-chevron-down submenu-arrow"></i>

                </summary>

                <div class="submenu">

                    <a href="attendance.php" data-tooltip="Attendance">
                        Attendance
                    </a>

                    <a href="leave_management.php"
                       data-tooltip="Leave Management">
                        Leave Management
                    </a>

                    <a href="performance_evaluation.php"
                       data-tooltip="Performance Evaluation">
                        Performance Evaluation
                    </a>

                </div>

            </details>


            <!-- VERIFICATION -->

            <details class="menu-group">

                <summary class="menu-link"
                         data-tooltip="Verification">

                    <i class="fa-solid fa-shield-halved"></i>

                    <span class="menu-label">Verification</span>

                    <i class="fa-solid fa-chevron-down submenu-arrow"></i>

                </summary>

                <div class="submenu">

                    <a href="document_credentials.php"
                       data-tooltip="Documents and Credentials">
                        Documents &amp; Credentials
                    </a>

                    <a href="#"
                       data-tooltip="Physical Interviews">
                        Physical Interviews
                    </a>

                    <a href="notifications.php"
                       data-tooltip="Applicant Notifications">
                        Applicant Notifications
                    </a>

                    <a href="approval_decisions.php"
                       data-tooltip="Approval Decisions">
                        Approval Decisions
                    </a>

                </div>

            </details>


            <!-- SERVICES -->

            <details class="menu-group">

                <summary class="menu-link"
                         data-tooltip="Services">

                    <i class="fa-solid fa-screwdriver-wrench"></i>

                    <span class="menu-label">Services</span>

                    <i class="fa-solid fa-chevron-down submenu-arrow"></i>

                </summary>

                <div class="submenu">

                    <a href="categories.php" data-tooltip="Categories">
                        Categories
                    </a>

                    <a href="service_requests.php"
                       data-tooltip="Service Requests">
                        Service Requests
                    </a>

                    <a href="bookings.php" data-tooltip="Bookings">
                        Bookings
                    </a>

                    <a href="reviews.php"
                       data-tooltip="Reviews and Ratings">
                        Reviews &amp; Ratings
                    </a>

                </div>

            </details>


            <!-- REPORTS -->

            <details class="menu-group">

                <summary class="menu-link"
                         data-tooltip="Reports">

                    <i class="fa-solid fa-chart-column"></i>

                    <span class="menu-label">Reports</span>

                    <i class="fa-solid fa-chevron-down submenu-arrow"></i>

                </summary>

                <div class="submenu">

                    <a href="reports.php"
                       data-tooltip="Reports and Complaints">
                        Reports
                    </a>

                    <a href="support_enquiries.php"
                       data-tooltip="Support Enquiries">
                        Support Enquiries
                    </a>

                </div>

            </details>

        </nav>


        <!-- SIDEBAR BOTTOM -->

        <div class="sidebar-bottom">

            <a href="logout.php"
               class="menu-link logout-link"
               data-tooltip="Logout">

                <i class="fa-solid fa-right-from-bracket"></i>

                <span class="menu-label">Logout</span>

            </a>

        </div>

    </aside>


    <!-- =====================================
         MAIN AREA
    ====================================== -->

    <div class="main-area">


        <!-- TOP BAR -->

        <header class="topbar">

            <div class="topbar-left">

                <button type="button"
                        class="mobile-menu-toggle"
                        id="mobileMenuToggle"
                        aria-label="Open navigation">

                    <i class="fa-solid fa-bars"></i>

                </button>

                <div>

                    <p class="topbar-caption">
                        HANDYLINK SOLUTIONS
                    </p>

                    <h2>Administration</h2>

                </div>

            </div>


            <div class="admin-account">

                <a href="support_enquiries.php"
                   class="notification-bell"
                   title="Support Enquiries"
                   aria-label="View Support Enquiries">

                    <i class="fa-regular fa-bell"></i>

                    <?php if ($unreadCount > 0): ?>

                        <span class="notification-badge"></span>

                    <?php endif; ?>

                </a>


                <span class="admin-avatar">

                    <i class="fa-solid fa-user-shield"></i>

                </span>


                <span class="admin-account-name">

                    <?= htmlspecialchars(
                        $adminName,
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>

                </span>

            </div>

        </header>



        <!-- =====================================
             DASHBOARD CONTENT
        ====================================== -->

        <main class="dashboard-content">


            <!-- PAGE HEADING -->

            <section class="dashboard-heading">

                <div>

                    <h1>Admin Overview</h1>

                    <p>
                        Here's what's happening across HandyLink Solutions.
                    </p>

                </div>

                <span class="dashboard-date">

                    <i class="fa-regular fa-calendar"></i>

                    <?= date("l, d M Y") ?>

                </span>

            </section>



            <!-- =====================================
                 SUMMARY CARDS
            ====================================== -->

            <section class="dashboard-summary">


                <!-- CLIENTS -->

                <div class="summary-card">

                    <div class="summary-icon">

                        <i class="fa-solid fa-users"></i>

                    </div>

                    <div class="summary-info">

                        <span>Registered Clients</span>

                        <strong>
                            <?= number_format($totalClients) ?>
                        </strong>

                    </div>

                </div>



                <!-- APPROVED PROVIDERS -->

                <div class="summary-card">

                    <div class="summary-icon">

                        <i class="fa-solid fa-user-check"></i>

                    </div>

                    <div class="summary-info">

                        <span>Approved Providers</span>

                        <strong>
                            <?= number_format($totalApprovedProviders) ?>
                        </strong>

                    </div>

                </div>



                <!-- APPLICATIONS -->

                <div class="summary-card">

                    <div class="summary-icon">

                        <i class="fa-solid fa-file-circle-exclamation"></i>

                    </div>

                    <div class="summary-info">

                        <span>Pending Applications</span>

                        <strong>
                            <?= number_format($totalPendingApplications) ?>
                        </strong>

                    </div>

                </div>



                <!-- LEAVE REQUESTS -->

                <div class="summary-card">

                    <div class="summary-icon">

                        <i class="fa-solid fa-calendar-minus"></i>

                    </div>

                    <div class="summary-info">

                        <span>Pending Leave Requests</span>

                        <strong>
                            <?= number_format($totalPendingLeave) ?>
                        </strong>

                    </div>

                </div>


            </section>



            <!-- =====================================
                 AI INSIGHTS
            ====================================== -->

            <section class="ai-insights"
                     aria-labelledby="aiInsightsTitle">

                <div class="ai-insights-header">

                    <i class="fa-solid fa-chart-line"></i>

                    <h2 id="aiInsightsTitle">
                        AI Insights
                    </h2>

                </div>


                <p class="ai-insights-note">
                    Automated observations based on current HandyLink records.
                </p>


                <div class="ai-insight-list">

                    <?php foreach ($dashboardInsights as $insight): ?>

                        <article class="ai-insight-item">

                            <i class="fa-solid <?= htmlspecialchars(
                                $insight["icon"],
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>"></i>


                            <div>

                                <h3>
                                    <?= htmlspecialchars(
                                        $insight["title"],
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?>
                                </h3>


                                <p>
                                    <?= htmlspecialchars(
                                        $insight["message"],
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?>
                                </p>


                                <a href="<?= htmlspecialchars(
                                    $insight["link"],
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>">

                                    <?= htmlspecialchars(
                                        $insight["link_text"],
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?>

                                    <i class="fa-solid fa-arrow-right"></i>

                                </a>

                            </div>

                        </article>

                    <?php endforeach; ?>

                </div>

            </section>



            <!-- =====================================
                 SYSTEM OVERVIEW CHARTS
            ====================================== -->

            <section class="dashboard-section">

                <div class="section-heading">

                    <h2>System Overview</h2>

                    <p>
                        Visual summary of provider verification and service booking activities.
                    </p>

                </div>


                <div class="charts-grid">


                    <!-- PROVIDER VERIFICATION -->

                    <div class="chart-card">

                        <h3>Provider Verification</h3>

                        <p>
                            Providers grouped by verification status.
                        </p>

                        <div class="chart-container">

                            <canvas id="providerChart"></canvas>

                        </div>

                    </div>



                    <!-- BOOKING OVERVIEW -->

                    <div class="chart-card">

                        <h3>Booking Overview</h3>

                        <p>
                            Bookings grouped by their current status.
                        </p>

                        <div class="chart-container">

                            <canvas id="bookingChart"></canvas>

                        </div>

                    </div>



                    <!-- MONTHLY BOOKINGS -->

                    <div class="chart-card monthly-chart">

                        <h3>Monthly Booking Activity</h3>

                        <p>
                            Booking activity over the last six months.
                        </p>

                        <div class="chart-container">

                            <canvas id="monthlyChart"></canvas>

                        </div>

                    </div>


                </div>

            </section>



            <!-- =====================================
                 QUICK ACTIONS
            ====================================== -->

            <section class="dashboard-section">

                <div class="section-heading">

                    <h2>Quick Actions</h2>

                    <p>
                        Open important administration sections.
                    </p>

                </div>


                <div class="dashboard-actions">


                    <a href="approval_decisions.php"
                       class="action-link">

                        <span>Provider Verification</span>

                        <i class="fa-solid fa-arrow-right"></i>

                    </a>



                    <a href="recruitment.php"
                       class="action-link">

                        <span>Job Applications</span>

                        <i class="fa-solid fa-arrow-right"></i>

                    </a>



                    <a href="bookings.php"
                       class="action-link">

                        <span>Service Bookings</span>

                        <i class="fa-solid fa-arrow-right"></i>

                    </a>


                </div>

            </section>



            <!-- =====================================
                 RECENT NOTIFICATIONS
            ====================================== -->

            <section class="dashboard-section">

                <div class="section-heading">

                    <h2>Recent Notifications</h2>

                    <p>
                        Your latest system notifications.
                    </p>

                </div>


                <div class="recent-notifications">

                    <?php if (!empty($adminNotifications)): ?>

                        <?php foreach ($adminNotifications as $notification): ?>

                            <div class="recent-notification">

                                <div>

                                    <strong>

                                        <?= htmlspecialchars(
                                            $notification["title"] ?? "",
                                            ENT_QUOTES,
                                            "UTF-8"
                                        ) ?>

                                    </strong>


                                    <p>

                                        <?= htmlspecialchars(
                                            $notification["message"] ?? "",
                                            ENT_QUOTES,
                                            "UTF-8"
                                        ) ?>

                                    </p>

                                </div>


                                <small>

                                    <?= !empty($notification["created_at"])
                                        ? htmlspecialchars(
                                            date(
                                                "d M Y",
                                                strtotime($notification["created_at"])
                                            ),
                                            ENT_QUOTES,
                                            "UTF-8"
                                        )
                                        : "" ?>

                                </small>

                            </div>

                        <?php endforeach; ?>


                    <?php else: ?>

                        <div class="empty-activity">

                            No recent notifications to display.

                        </div>

                    <?php endif; ?>

                </div>

            </section>


        </main>



        <!-- FOOTER -->

        <footer class="admin-footer">

            <p>

                &copy; <?= date("Y") ?> HandyLink Solutions.

                All Rights Reserved.

            </p>

        </footer>


    </div>

</div>



<!-- MOBILE SIDEBAR OVERLAY -->

<div class="sidebar-overlay" id="sidebarOverlay"></div>



<!-- =====================================
     JAVASCRIPT
====================================== -->

<script>

/* Sidebar elements */

const layout = document.getElementById("adminLayout");

const sidebar = document.getElementById("sidebar");

const sidebarToggle = document.getElementById("sidebarToggle");

const mobileMenuToggle = document.getElementById("mobileMenuToggle");

const sidebarOverlay = document.getElementById("sidebarOverlay");


/* Collapse and expand sidebar */

if (sidebarToggle) {

    sidebarToggle.addEventListener("click", function () {

        const collapsed = layout.classList.toggle("sidebar-collapsed");

        sidebarToggle.setAttribute(
            "aria-expanded",
            String(!collapsed)
        );

        sidebarToggle.setAttribute(
            "aria-label",
            collapsed ? "Expand sidebar" : "Collapse sidebar"
        );

        sidebarToggle.title = collapsed
            ? "Expand sidebar"
            : "Collapse sidebar";

    });

}


/* Only one sidebar submenu open at a time */

document.querySelectorAll(".menu-group").forEach(function (group) {

    group.addEventListener("toggle", function () {

        if (
            group.open &&
            !layout.classList.contains("sidebar-collapsed")
        ) {

            document.querySelectorAll(".menu-group").forEach(function (other) {

                if (other !== group) {
                    other.open = false;
                }

            });

        }

    });

});


/* Mobile sidebar */

function openMobileSidebar() {

    sidebar.classList.add("mobile-sidebar-open");

    sidebarOverlay.classList.add("visible");

}


function closeMobileSidebar() {

    sidebar.classList.remove("mobile-sidebar-open");

    sidebarOverlay.classList.remove("visible");

}


if (mobileMenuToggle) {
    mobileMenuToggle.addEventListener("click", openMobileSidebar);
}

if (sidebarOverlay) {
    sidebarOverlay.addEventListener("click", closeMobileSidebar);
}


/* Close mobile menu using Escape */

document.addEventListener("keydown", function (event) {

    if (event.key === "Escape") {

        closeMobileSidebar();

        document.querySelectorAll(".menu-group[open]").forEach(function (group) {

            group.open = false;

        });

    }

});


/* Prevent placeholder links from jumping to the top */

document.querySelectorAll('.submenu a[href="#"]').forEach(function (link) {

    link.addEventListener("click", function (event) {

        event.preventDefault();

    });

});


/* =====================================
   CHART DATA FROM PHP
===================================== */

const providerLabels = <?= $providerLabelsJson ?: "[]" ?>;

const providerValues = <?= $providerValuesJson ?: "[]" ?>;

const bookingLabels = <?= $bookingLabelsJson ?: "[]" ?>;

const bookingValues = <?= $bookingValuesJson ?: "[]" ?>;

const monthLabels = <?= $monthLabelsJson ?: "[]" ?>;

const monthValues = <?= $monthValuesJson ?: "[]" ?>;


/* =====================================
   CHART CONFIGURATION
===================================== */

if (typeof Chart !== "undefined") {


    /* PROVIDER VERIFICATION DOUGHNUT */

    const providerCanvas = document.getElementById("providerChart");

    if (providerCanvas) {

        new Chart(providerCanvas, {

            type: "doughnut",

            data: {

                labels: providerLabels,

                datasets: [{

                    data: providerValues,

                    backgroundColor: [
                        "#123b32",
                        "#789187",
                        "#b8c5bd",
                        "#dce4de"
                    ],

                    borderColor: "#ffffff",

                    borderWidth: 2

                }]

            },

            options: {

                responsive: true,

                maintainAspectRatio: false,

                plugins: {

                    legend: {

                        position: "bottom"

                    }

                }

            }

        });

    }



    /* BOOKING STATUS BAR CHART */

    const bookingCanvas = document.getElementById("bookingChart");

    if (bookingCanvas) {

        new Chart(bookingCanvas, {

            type: "bar",

            data: {

                labels: bookingLabels,

                datasets: [{

                    label: "Bookings",

                    data: bookingValues,

                    backgroundColor: "#123b32",

                    borderRadius: 3

                }]

            },

            options: {

                responsive: true,

                maintainAspectRatio: false,

                plugins: {

                    legend: {

                        display: false

                    }

                },

                scales: {

                    y: {

                        beginAtZero: true,

                        ticks: {

                            precision: 0

                        }

                    }

                }

            }

        });

    }



    /* MONTHLY BOOKING LINE CHART */

    const monthlyCanvas = document.getElementById("monthlyChart");

    if (monthlyCanvas) {

        new Chart(monthlyCanvas, {

            type: "line",

            data: {

                labels: monthLabels,

                datasets: [{

                    label: "Bookings",

                    data: monthValues,

                    borderColor: "#123b32",

                    backgroundColor: "rgba(18, 59, 50, 0.12)",

                    fill: true,

                    tension: 0.35,

                    pointRadius: 4

                }]

            },

            options: {

                responsive: true,

                maintainAspectRatio: false,

                plugins: {

                    legend: {

                        display: false

                    }

                },

                scales: {

                    y: {

                        beginAtZero: true,

                        ticks: {

                            precision: 0

                        }

                    }

                }

            }

        });

    }

}

</script>


</body>
</html>