
<?php
session_start();

if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'admin'
) {
    header("Location: ../login.php");
    exit();
}

require_once "../config/database.php";
$conn->set_charset("utf8mb4");

/* =========================================
   HANDYLINK ADMIN REPORTS
========================================= */

function tableExists(mysqli $conn, string $table): bool
{
    $sql = "SELECT COUNT(*) AS total
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
            AND table_name = ?";

    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;

    $stmt->bind_param("s", $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row['total'] ?? 0) > 0;
}

function columnExists(
    mysqli $conn,
    string $table,
    string $column
): bool {
    $sql = "SELECT COUNT(*) AS total
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
            AND table_name = ?
            AND column_name = ?";

    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;

    $stmt->bind_param("ss", $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row['total'] ?? 0) > 0;
}

/*
 * Count records within a date range when the date
 * column exists. Conditions below are defined by this file.
 */
function countRecords(
    mysqli $conn,
    string $table,
    string $condition = "1=1",
    string $dateColumn = "",
    string $from = "",
    string $to = ""
): int {
    $allowedTables = [
        'users',
        'providers',
        'service_requests',
        'bookings',
        'job_vacancies',
        'job_applications'
    ];

    if (
        !in_array($table, $allowedTables, true) ||
        !tableExists($conn, $table)
    ) {
        return 0;
    }

    $where = $condition;
    $useDates = (
        $dateColumn !== "" &&
        columnExists($conn, $table, $dateColumn)
    );

    if ($useDates && $from !== "" && $to !== "") {
        $where .= " AND `$dateColumn` >= '" .
            $conn->real_escape_string($from) . "'";
        $where .= " AND `$dateColumn` < DATE_ADD('" .
            $conn->real_escape_string($to) .
            "', INTERVAL 1 DAY)";
    } elseif ($useDates && $from !== "") {
        $where .= " AND `$dateColumn` >= '" .
            $conn->real_escape_string($from) . "'";
    } elseif ($useDates && $to !== "") {
        $where .= " AND `$dateColumn` < DATE_ADD('" .
            $conn->real_escape_string($to) .
            "', INTERVAL 1 DAY)";
    }

    $result = $conn->query(
        "SELECT COUNT(*) AS total FROM `$table` WHERE $where"
    );

    if (!$result) return 0;

    $row = $result->fetch_assoc();
    return (int)($row['total'] ?? 0);
}

/* Get status totals for a supported table. */
function getStatusCounts(
    mysqli $conn,
    string $table,
    string $statusColumn,
    string $dateColumn = "",
    string $from = "",
    string $to = ""
): array {
    $allowedTables = [
        'service_requests',
        'bookings',
        'job_vacancies',
        'job_applications'
    ];

    if (
        !in_array($table, $allowedTables, true) ||
        !tableExists($conn, $table) ||
        !columnExists($conn, $table, $statusColumn)
    ) {
        return [];
    }

    $where = "1=1";

    if (
        $dateColumn !== "" &&
        columnExists($conn, $table, $dateColumn)
    ) {
        if ($from !== "") {
            $where .= " AND `$dateColumn` >= '" .
                $conn->real_escape_string($from) . "'";
        }

        if ($to !== "") {
            $where .= " AND `$dateColumn` < DATE_ADD('" .
                $conn->real_escape_string($to) .
                "', INTERVAL 1 DAY)";
        }
    }

    $sql = "SELECT `$statusColumn` AS status_name,
                   COUNT(*) AS total
            FROM `$table`
            WHERE $where
            GROUP BY `$statusColumn`
            ORDER BY total DESC";

    $result = $conn->query($sql);
    $rows = [];

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }

    return $rows;
}

function e($value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function displayStatus($status): string
{
    $status = trim((string)$status);

    return $status === ''
        ? 'Not specified'
        : ucwords(str_replace('_', ' ', $status));
}

function statusClass($status): string
{
    $status = strtolower(trim((string)$status));

    if (in_array($status, [
        'approved', 'completed', 'resolved', 'accepted'
    ], true)) {
        return 'success';
    }

    if (in_array($status, [
        'pending', 'under review', 'in_progress', 'in progress'
    ], true)) {
        return 'warning';
    }

    if (in_array($status, [
        'rejected', 'cancelled', 'canceled', 'declined'
    ], true)) {
        return 'danger';
    }

    return 'neutral';
}

/* =========================================
   DATE FILTERS
========================================= */

$today = date('Y-m-d');
$defaultFrom = date('Y-m-01');

$from = $_GET['from'] ?? $defaultFrom;
$to = $_GET['to'] ?? $today;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = $defaultFrom;
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = $today;
}

if ($from > $to) {
    [$from, $to] = [$to, $from];
}

/* =========================================
   SUMMARY STATISTICS
========================================= */

/*
 * IMPORTANT:
 * Count ALL registered clients, not only clients
 * registered during the selected reporting period.
 */
$clients = countRecords(
    $conn,
    'users',
    "LOWER(TRIM(role)) = 'client'"
);

/* Total provider records. */
$providers = countRecords(
    $conn,
    'providers',
    "1=1"
);

/* Requests and bookings use the selected period. */
$totalRequests = countRecords(
    $conn,
    'service_requests',
    "1=1",
    'created_at',
    $from,
    $to
);

$totalBookings = countRecords(
    $conn,
    'bookings',
    "1=1",
    'booking_date',
    $from,
    $to
);

/* Provider verification totals. */
$approvedProviders = countRecords(
    $conn,
    'providers',
    "verification_status = 'approved'"
);

$pendingProviders = countRecords(
    $conn,
    'providers',
    "verification_status = 'pending'"
);

$rejectedProviders = countRecords(
    $conn,
    'providers',
    "verification_status = 'rejected'"
);

/* Recruitment totals. */
$totalVacancies = countRecords(
    $conn,
    'job_vacancies',
    "1=1",
    'created_at',
    $from,
    $to
);

$totalApplications = countRecords(
    $conn,
    'job_applications',
    "1=1",
    'created_at',
    $from,
    $to
);

/* Status summaries. */
$requestStatuses = getStatusCounts(
    $conn,
    'service_requests',
    'status',
    'created_at',
    $from,
    $to
);

$bookingStatuses = getStatusCounts(
    $conn,
    'bookings',
    'status',
    'booking_date',
    $from,
    $to
);

$applicationStatuses = getStatusCounts(
    $conn,
    'job_applications',
    'status',
    'created_at',
    $from,
    $to
);

/* =========================================
   CSV EXPORT
========================================= */

if (
    isset($_GET['export']) &&
    $_GET['export'] === 'csv'
) {
    header('Content-Type: text/csv; charset=utf-8');
    header(
        'Content-Disposition: attachment; filename="handylink_report_' .
        $from . '_to_' . $to . '.csv"'
    );

    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");

    fputcsv($output, ['HANDYLINK SYSTEM REPORT']);
    fputcsv($output, ['Report period', $from . ' to ' . $to]);
    fputcsv($output, []);
    fputcsv($output, ['SUMMARY STATISTICS', 'TOTAL']);

    fputcsv($output, ['Total registered clients', $clients]);
    fputcsv($output, ['Service providers', $providers]);
    fputcsv($output, ['Approved providers', $approvedProviders]);
    fputcsv($output, ['Pending providers', $pendingProviders]);
    fputcsv($output, ['Rejected providers', $rejectedProviders]);
    fputcsv($output, ['Service requests', $totalRequests]);
    fputcsv($output, ['Bookings', $totalBookings]);
    fputcsv($output, ['Job vacancies', $totalVacancies]);
    fputcsv($output, ['Job applications', $totalApplications]);

    fputcsv($output, []);
    fputcsv($output, ['SERVICE REQUEST STATUS', 'TOTAL']);

    foreach ($requestStatuses as $row) {
        fputcsv($output, [
            $row['status_name'] ?: 'Not specified',
            $row['total']
        ]);
    }

    fputcsv($output, []);
    fputcsv($output, ['BOOKING STATUS', 'TOTAL']);

    foreach ($bookingStatuses as $row) {
        fputcsv($output, [
            $row['status_name'] ?: 'Not specified',
            $row['total']
        ]);
    }

    fputcsv($output, []);
    fputcsv($output, ['JOB APPLICATION STATUS', 'TOTAL']);

    foreach ($applicationStatuses as $row) {
        fputcsv($output, [
            $row['status_name'] ?: 'Not specified',
            $row['total']
        ]);
    }

    fclose($output);
    exit();
}

/* =========================================
   RECENT BOOKINGS
========================================= */

$recentBookings = [];
$bookingQueryError = false;

$canShowBookings =
    tableExists($conn, 'bookings') &&
    tableExists($conn, 'users') &&
    tableExists($conn, 'providers') &&
    columnExists($conn, 'bookings', 'client_id') &&
    columnExists($conn, 'bookings', 'provider_id') &&
    columnExists($conn, 'bookings', 'booking_date') &&
    columnExists($conn, 'bookings', 'status') &&
    columnExists($conn, 'providers', 'user_id');

if ($canShowBookings) {
    $bookingTitle =
        tableExists($conn, 'service_requests') &&
        columnExists($conn, 'bookings', 'request_id') &&
        columnExists($conn, 'service_requests', 'title');

    $titleSelect = $bookingTitle
        ? "sr.title AS service_title"
        : "NULL AS service_title";

    $requestJoin = $bookingTitle
        ? "LEFT JOIN service_requests sr ON sr.id = b.request_id"
        : "";

    $timeSelect = columnExists(
        $conn,
        'bookings',
        'booking_time'
    ) ? "b.booking_time" : "NULL AS booking_time";

    $dateFrom = $conn->real_escape_string($from);
    $dateTo = $conn->real_escape_string($to);

    $sql = "SELECT
                b.id,
                b.booking_date,
                $timeSelect,
                b.status,
                cu.full_name AS client_name,
                pu.full_name AS provider_name,
                $titleSelect
            FROM bookings b
            LEFT JOIN users cu ON cu.id = b.client_id
            LEFT JOIN providers p ON p.id = b.provider_id
            LEFT JOIN users pu ON pu.id = p.user_id
            $requestJoin
            WHERE b.booking_date >= '$dateFrom'
              AND b.booking_date < DATE_ADD('$dateTo', INTERVAL 1 DAY)
            ORDER BY b.booking_date DESC, b.id DESC
            LIMIT 10";

    $result = $conn->query($sql);

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $recentBookings[] = $row;
        }
    } else {
        $bookingQueryError = true;
    }
}

$exportUrl = '?' . http_build_query([
    'from' => $from,
    'to' => $to,
    'export' => 'csv'
]);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>System Reports | HandyLink Admin</title>

    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>
        :root {
            --report-green: #052f0c;
            --report-bg: #f5f7f6;
            --report-border: #e1e7e2;
            --report-text: #202b23;
            --report-muted: #68736b;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--report-bg);
            color: var(--report-text);
            font-family: Arial, Helvetica, sans-serif;
        }

        .report-page {
            width: min(1400px, 100%);
            margin: 0 auto;
            padding: 28px;
        }

        .reports-back {
            margin-bottom: 20px;
        }

        .reports-back a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            padding: 10px 14px;
            min-height: 40px;
            background: var(--report-green);
            color: #fff;
            border-radius: 8px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
        }

        .reports-back a:hover {
            background: #0b4816;
        }

        .report-heading {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 18px;
            margin-bottom: 24px;
        }

        .report-heading h1 {
            margin: 0 0 8px;
            font-size: 27px;
            color: var(--report-green);
        }

        .report-heading p {
            margin: 0;
            color: var(--report-muted);
            line-height: 1.6;
        }

        .report-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .report-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 42px;
            padding: 10px 15px;
            border: 1px solid var(--report-border);
            border-radius: 8px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
        }

        .report-btn-primary {
            color: #fff;
            background: var(--report-green);
            border-color: var(--report-green);
        }

        .report-btn-secondary {
            color: var(--report-green);
            background: #fff;
        }

        .report-filter {
            display: flex;
            flex-wrap: wrap;
            align-items: end;
            gap: 14px;
            background: #fff;
            border: 1px solid var(--report-border);
            border-radius: 12px;
            padding: 18px;
            margin-bottom: 24px;
        }

        .report-filter .field {
            display: flex;
            flex-direction: column;
            gap: 7px;
            min-width: 180px;
        }

        .report-filter label {
            font-size: 12px;
            font-weight: 700;
            color: var(--report-muted);
        }

        .report-filter input {
            min-height: 42px;
            padding: 9px 11px;
            border: 1px solid #ccd5ce;
            border-radius: 7px;
            background: #fff;
            color: var(--report-text);
        }

        .section-title {
            font-size: 17px;
            margin: 28px 0 15px;
            color: var(--report-green);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
        }

        .stat-card {
            min-width: 0;
            padding: 20px;
            border-radius: 12px;
            background: #fff;
            border: 1px solid var(--report-border);
        }

        .stat-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            min-height: 42px;
            margin-bottom: 17px;
        }

        .stat-label {
            flex: 1;
            min-width: 0;
            color: var(--report-muted);
            font-size: 13px;
            line-height: 1.5;
        }

        .stat-icon {
            width: 42px;
            height: 42px;
            min-width: 42px;
            flex: 0 0 42px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            line-height: 1;
            overflow: hidden;
            background: #e9f2eb;
            color: var(--report-green);
            border-radius: 10px;
        }

        .stat-icon i {
            display: block;
            font-size: 18px;
            line-height: 1;
            text-align: center;
        }

        .stat-number {
            font-size: 29px;
            font-weight: 750;
            line-height: 1.2;
            overflow-wrap: anywhere;
            color: var(--report-text);
        }

        .stat-note {
            margin-top: 8px;
            font-size: 11px;
            line-height: 1.5;
            color: var(--report-muted);
        }

        .report-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
            align-items: start;
        }

        .report-panel {
            min-width: 0;
            background: #fff;
            border: 1px solid var(--report-border);
            border-radius: 12px;
            padding: 20px;
        }

        .report-panel h3 {
            margin: 0 0 17px;
            font-size: 15px;
            color: var(--report-green);
        }

        .status-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 13px 0;
            border-bottom: 1px solid #edf0ed;
            font-size: 13px;
        }

        .status-row:last-child {
            border-bottom: 0;
        }

        .status-label {
            overflow-wrap: anywhere;
        }

        .status-value {
            font-weight: 700;
            white-space: nowrap;
        }

        .status-pill {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
        }

        .status-pill.success {
            background: #e5f4e9;
            color: #17632e;
        }

        .status-pill.warning {
            background: #fff2d6;
            color: #885900;
        }

        .status-pill.danger {
            background: #fde8e7;
            color: #a52a25;
        }

        .status-pill.neutral {
            background: #edf0ed;
            color: #4e5a51;
        }

        .table-wrap {
            width: 100%;
            overflow-x: auto;
        }

        .report-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .report-table th {
            padding: 13px 12px;
            text-align: left;
            white-space: nowrap;
            background: #f3f6f3;
            color: #526057;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .35px;
        }

        .report-table td {
            padding: 14px 12px;
            border-bottom: 1px solid #edf0ed;
            vertical-align: middle;
        }

        .report-table tr:last-child td {
            border-bottom: 0;
        }

        .empty-state {
            padding: 25px 12px;
            text-align: center;
            color: var(--report-muted);
            font-size: 13px;
            line-height: 1.6;
        }

        .report-footer {
            margin-top: 24px;
            font-size: 11px;
            color: var(--report-muted);
            text-align: right;
        }

        @media (max-width: 1050px) {
            .stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 700px) {
            .report-page {
                padding: 17px 12px;
            }

            .report-heading h1 {
                font-size: 23px;
            }

            .report-grid {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                gap: 10px;
            }

            .stat-card {
                padding: 14px;
            }

            .stat-number {
                font-size: 24px;
            }

            .stat-icon {
                width: 36px;
                height: 36px;
                min-width: 36px;
                flex-basis: 36px;
            }

            .report-filter .field {
                width: 100%;
            }

            .report-filter input,
            .report-filter .report-btn {
                width: 100%;
            }

            .report-actions {
                width: 100%;
            }

            .report-actions .report-btn {
                flex: 1;
            }
        }

        @media print {
            body {
                background: #fff;
            }

            .report-page {
                width: 100%;
                padding: 0;
            }

            .report-filter,
            .report-actions,
            .no-print {
                display: none !important;
            }

            .stats-grid {
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 8px;
            }

            .stat-card,
            .report-panel {
                break-inside: avoid;
                border: 1px solid #ddd;
                box-shadow: none;
            }

            .report-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        /* Dark-mode support for common site theme classes. */
        body.dark-mode,
        body.dark,
        body[data-theme="dark"] {
            --report-bg: #111712;
            --report-border: #303b32;
            --report-text: #e7eee8;
            --report-muted: #a6b2a8;
            background: var(--report-bg);
            color: var(--report-text);
        }

        body.dark-mode .stat-card,
        body.dark-mode .report-panel,
        body.dark-mode .report-filter,
        body.dark .stat-card,
        body.dark .report-panel,
        body.dark .report-filter,
        body[data-theme="dark"] .stat-card,
        body[data-theme="dark"] .report-panel,
        body[data-theme="dark"] .report-filter {
            background: #1a231c;
            border-color: var(--report-border);
        }

        body.dark-mode .report-table th,
        body.dark .report-table th,
        body[data-theme="dark"] .report-table th {
            background: #222e24;
            color: #d4dfd6;
        }

        body.dark-mode .report-table td,
        body.dark .report-table td,
        body[data-theme="dark"] .report-table td {
            border-color: var(--report-border);
        }

        body.dark-mode .report-filter input,
        body.dark .report-filter input,
        body[data-theme="dark"] .report-filter input {
            background: #111712;
            color: #e7eee8;
            border-color: var(--report-border);
        }

        body.dark-mode .report-btn-secondary,
        body.dark .report-btn-secondary,
        body[data-theme="dark"] .report-btn-secondary {
            background: #1a231c;
            color: #e7eee8;
            border-color: var(--report-border);
        }

        body.dark-mode .report-heading h1,
        body.dark-mode .section-title,
        body.dark-mode .report-panel h3,
        body.dark .report-heading h1,
        body.dark .section-title,
        body.dark .report-panel h3,
        body[data-theme="dark"] .report-heading h1,
        body[data-theme="dark"] .section-title,
        body[data-theme="dark"] .report-panel h3 {
            color: #d8e8da;
        }
    </style>
</head>

<body>

<div class="report-page">

    <div class="reports-back">
        <a href="dashboard.php" title="Back to Dashboard">
            <i class="fa-solid fa-arrow-left"></i>
            <span>Back to Dashboard</span>
        </a>
    </div>

    <header class="report-heading">
        <div>
            <h1>
                <i class="fa-solid fa-chart-line"></i>
                System Reports
            </h1>
            <p>
                Monitor HandyLink activity, service requests, bookings,
                provider verification and recruitment.
            </p>
        </div>

        <div class="report-actions">
            <a class="report-btn report-btn-secondary"
               href="<?= e($exportUrl) ?>">
                <i class="fa-solid fa-file-csv"></i>
                Export CSV
            </a>

            <button type="button"
                    class="report-btn report-btn-primary"
                    onclick="window.print()">
                <i class="fa-solid fa-print"></i>
                Print Report
            </button>
        </div>
    </header>

    <form method="GET" class="report-filter">
        <div class="field">
            <label for="from">Start date</label>
            <input type="date"
                   id="from"
                   name="from"
                   value="<?= e($from) ?>"
                   required>
        </div>

        <div class="field">
            <label for="to">End date</label>
            <input type="date"
                   id="to"
                   name="to"
                   value="<?= e($to) ?>"
                   required>
        </div>

        <button type="submit"
                class="report-btn report-btn-primary">
            <i class="fa-solid fa-filter"></i>
            Apply Filters
        </button>

        <a href="reports.php"
           class="report-btn report-btn-secondary">
            <i class="fa-solid fa-rotate-left"></i>
            Reset
        </a>
    </form>

    <h2 class="section-title">System Overview</h2>

    <div class="stats-grid">

        <div class="stat-card">
            <div class="stat-top">
                <span class="stat-label">Registered Clients</span>
                <span class="stat-icon">
                    <i class="fa-solid fa-users"></i>
                </span>
            </div>
            <div class="stat-number">
                <?= number_format($clients) ?>
            </div>
            <div class="stat-note">
                All registered client accounts, regardless of the selected date range.
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-top">
                <span class="stat-label">Service Providers</span>
                <span class="stat-icon">
                    <i class="fa-solid fa-user-gear"></i>
                </span>
            </div>
            <div class="stat-number">
                <?= number_format($providers) ?>
            </div>
            <div class="stat-note">
                Total provider records currently in the system.
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-top">
                <span class="stat-label">Service Requests</span>
                <span class="stat-icon">
                    <i class="fa-solid fa-clipboard-list"></i>
                </span>
            </div>
            <div class="stat-number">
                <?= number_format($totalRequests) ?>
            </div>
            <div class="stat-note">
                Requests recorded during the selected period when the date column exists.
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-top">
                <span class="stat-label">Bookings</span>
                <span class="stat-icon">
                    <i class="fa-solid fa-calendar-check"></i>
                </span>
            </div>
            <div class="stat-number">
                <?= number_format($totalBookings) ?>
            </div>
            <div class="stat-note">
                Bookings with dates within the selected period.
            </div>
        </div>

    </div>

    <h2 class="section-title">Provider Verification</h2>

    <div class="stats-grid">

        <div class="stat-card">
            <div class="stat-top">
                <span class="stat-label">Approved Providers</span>
                <span class="stat-icon">
                    <i class="fa-solid fa-user-check"></i>
                </span>
            </div>
            <div class="stat-number">
                <?= number_format($approvedProviders) ?>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-top">
                <span class="stat-label">Pending Verification</span>
                <span class="stat-icon">
                    <i class="fa-solid fa-hourglass-half"></i>
                </span>
            </div>
            <div class="stat-number">
                <?= number_format($pendingProviders) ?>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-top">
                <span class="stat-label">Rejected Providers</span>
                <span class="stat-icon">
                    <i class="fa-solid fa-user-xmark"></i>
                </span>
            </div>
            <div class="stat-number">
                <?= number_format($rejectedProviders) ?>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-top">
                <span class="stat-label">Job Vacancies</span>
                <span class="stat-icon">
                    <i class="fa-solid fa-briefcase"></i>
                </span>
            </div>
            <div class="stat-number">
                <?= number_format($totalVacancies) ?>
            </div>
            <div class="stat-note">
                Vacancies recorded during the selected period when the date column exists.
            </div>
        </div>

    </div>

    <h2 class="section-title">Activity Breakdown</h2>

    <div class="report-grid">

        <section class="report-panel">
            <h3>
                <i class="fa-solid fa-clipboard-list"></i>
                Service Request Status
            </h3>

            <?php if (empty($requestStatuses)): ?>
                <div class="empty-state">
                    No service-request status data is available for this period.
                </div>
            <?php else: ?>
                <?php foreach ($requestStatuses as $row): ?>
                    <div class="status-row">
                        <span class="status-label">
                            <?= e(displayStatus($row['status_name'])) ?>
                        </span>
                        <span class="status-value">
                            <?= number_format((int)$row['total']) ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>

        <section class="report-panel">
            <h3>
                <i class="fa-solid fa-calendar-check"></i>
                Booking Status
            </h3>

            <?php if (empty($bookingStatuses)): ?>
                <div class="empty-state">
                    No booking status data is available for this period.
                </div>
            <?php else: ?>
                <?php foreach ($bookingStatuses as $row): ?>
                    <div class="status-row">
                        <span class="status-label">
                            <?= e(displayStatus($row['status_name'])) ?>
                        </span>
                        <span class="status-value">
                            <?= number_format((int)$row['total']) ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>

        <section class="report-panel">
            <h3>
                <i class="fa-solid fa-user-tie"></i>
                Provider Verification Summary
            </h3>

            <div class="status-row">
                <span class="status-label">Approved</span>
                <span class="status-pill success">
                    <?= number_format($approvedProviders) ?>
                </span>
            </div>

            <div class="status-row">
                <span class="status-label">Pending</span>
                <span class="status-pill warning">
                    <?= number_format($pendingProviders) ?>
                </span>
            </div>

            <div class="status-row">
                <span class="status-label">Rejected</span>
                <span class="status-pill danger">
                    <?= number_format($rejectedProviders) ?>
                </span>
            </div>
        </section>

        <section class="report-panel">
            <h3>
                <i class="fa-solid fa-briefcase"></i>
                Recruitment Summary
            </h3>

            <div class="status-row">
                <span class="status-label">Job Vacancies</span>
                <span class="status-value">
                    <?= number_format($totalVacancies) ?>
                </span>
            </div>

            <div class="status-row">
                <span class="status-label">Job Applications</span>
                <span class="status-value">
                    <?= number_format($totalApplications) ?>
                </span>
            </div>

            <?php if (empty($applicationStatuses)): ?>
                <div class="empty-state">
                    No job-application status breakdown is available.
                </div>
            <?php else: ?>
                <?php foreach ($applicationStatuses as $row): ?>
                    <div class="status-row">
                        <span class="status-label">
                            <?= e(displayStatus($row['status_name'])) ?>
                        </span>
                        <span class="status-value">
                            <?= number_format((int)$row['total']) ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>

    </div>

    <h2 class="section-title">Recent Bookings</h2>

    <section class="report-panel">
        <div class="table-wrap">
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Booking ID</th>
                        <th>Service</th>
                        <th>Client</th>
                        <th>Provider</th>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody>
                <?php if ($bookingQueryError): ?>

                    <tr>
                        <td colspan="7" class="empty-state">
                            The recent-bookings query could not be completed.
                            Check that the booking relationships match your database.
                        </td>
                    </tr>

                <?php elseif (empty($recentBookings)): ?>

                    <tr>
                        <td colspan="7" class="empty-state">
                            No bookings were found for the selected dates.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($recentBookings as $booking): ?>
                        <tr>
                            <td>#<?= (int)$booking['id'] ?></td>

                            <td>
                                <?= e(
                                    !empty($booking['service_title'])
                                    ? $booking['service_title']
                                    : 'Service booking'
                                ) ?>
                            </td>

                            <td>
                                <?= e(
                                    $booking['client_name'] ?: 'Unknown client'
                                ) ?>
                            </td>

                            <td>
                                <?= e(
                                    $booking['provider_name'] ?: 'Unknown provider'
                                ) ?>
                            </td>

                            <td><?= e($booking['booking_date']) ?></td>

                            <td>
                                <?= e($booking['booking_time'] ?: '—') ?>
                            </td>

                            <td>
                                <span class="status-pill <?= e(statusClass($booking['status'])) ?>">
                                    <?= e(displayStatus($booking['status'])) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <div class="report-footer">
        Generated on <?= e(date('d M Y, H:i')) ?>.
        Figures are calculated from available HandyLink database records.
    </div>

</div>

</body>
</html>
