
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Africa/Nairobi');

if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'admin'
) {
    header('Location: ../login.php');
    exit();
}

require_once __DIR__ . '/../config/database.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection failed. Check config/database.php.');
}

$conn->set_charset('utf8mb4');

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$adminName = $_SESSION['full_name'] ?? 'Administrator';
$today = date('Y-m-d');

$section = $_GET['section'] ?? 'attendance';

if (!in_array($section, ['attendance', 'providers', 'leave'], true)) {
    $section = 'attendance';
}

$attendanceStatuses = [
    'Present', 'Absent', 'Late', 'Half Day', 'On Leave'
];

$daysOfWeek = [
    'Monday', 'Tuesday', 'Wednesday', 'Thursday',
    'Friday', 'Saturday', 'Sunday'
];

$validStatuses = ['all', 'Pending', 'Approved', 'Rejected', 'Cancelled'];

$validLeaveTypes = [
    'all', 'Annual', 'Sick', 'Maternity',
    'Paternity', 'Compassionate', 'Unpaid', 'Other'
];

if (empty($_SESSION['attendance_leave_csrf'])) {
    $_SESSION['attendance_leave_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['attendance_leave_csrf'];

$message = $_GET['message'] ?? '';
$messageType = ($_GET['type'] ?? '') === 'error' ? 'error' : 'success';

/*
|--------------------------------------------------------------------------
| Leave review actions
|--------------------------------------------------------------------------
*/

function redirectAttendance(string $section, string $message, string $type = 'success'): void
{
    header(
        'Location: attendance.php?section=' . urlencode($section) .
        '&message=' . urlencode($message) .
        '&type=' . urlencode($type)
    );
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['leave_action'])) {
    $postedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($postedToken) ||
        !hash_equals($csrfToken, $postedToken)
    ) {
        redirectAttendance('leave', 'Security check failed. Please try again.', 'error');
    }

    $leaveId = filter_var($_POST['leave_id'] ?? '', FILTER_VALIDATE_INT);
    $action = $_POST['leave_action'] ?? '';
    $reviewComment = trim($_POST['review_comment'] ?? '');

    if (
        !$leaveId ||
        !in_array($action, ['approve', 'reject'], true)
    ) {
        redirectAttendance('leave', 'Invalid leave request action.', 'error');
    }

    $newStatus = $action === 'approve' ? 'Approved' : 'Rejected';
    $adminId = (int)$_SESSION['user_id'];

    $stmt = $conn->prepare("
        UPDATE leave_requests
        SET status = ?,
            reviewed_by = ?,
            review_comment = ?,
            reviewed_at = NOW()
        WHERE id = ?
          AND status = 'Pending'
    ");

    if (!$stmt) {
        redirectAttendance('leave', 'Unable to prepare the review action.', 'error');
    }

    $stmt->bind_param(
        'sisi',
        $newStatus,
        $adminId,
        $reviewComment,
        $leaveId
    );

    $stmt->execute();
    $updated = $stmt->affected_rows;
    $stmt->close();

    if ($updated > 0) {
        redirectAttendance(
            'leave',
            'Leave request ' . strtolower($newStatus) . ' successfully.'
        );
    }

    redirectAttendance(
        'leave',
        'This request may already have been reviewed or may not exist.',
        'error'
    );
}

/*
|--------------------------------------------------------------------------
| Attendance filters and records
|--------------------------------------------------------------------------
*/

$attendanceSearch = trim($_GET['attendance_search'] ?? '');
$attendanceDateFilter = trim($_GET['attendance_date'] ?? '');
$attendanceStatusFilter = $_GET['attendance_status'] ?? '';

$attendanceWhere = [];
$attendanceParams = [];
$attendanceTypes = '';

if ($attendanceSearch !== '') {
    $attendanceWhere[] = "(
        COALESCE(
            NULLIF(TRIM(CONCAT_WS(' ', e.first_name, e.last_name)), ''),
            u.full_name,
            ''
        ) LIKE ?
        OR COALESCE(e.employee_number, '') LIKE ?
        OR COALESCE(e.job_title, '') LIKE ?
        OR COALESCE(u.email, '') LIKE ?
        OR COALESCE(u.phone, '') LIKE ?
    )";

    $search = '%' . $attendanceSearch . '%';

    array_push(
        $attendanceParams,
        $search, $search, $search, $search, $search
    );

    $attendanceTypes .= 'sssss';
}

if ($attendanceDateFilter !== '') {
    $dateObject = DateTime::createFromFormat('!Y-m-d', $attendanceDateFilter);

    if ($dateObject && $dateObject->format('Y-m-d') === $attendanceDateFilter) {
        $attendanceWhere[] = 'a.attendance_date = ?';
        $attendanceParams[] = $attendanceDateFilter;
        $attendanceTypes .= 's';
    }
}

if (in_array($attendanceStatusFilter, $attendanceStatuses, true)) {
    $attendanceWhere[] = 'a.status = ?';
    $attendanceParams[] = $attendanceStatusFilter;
    $attendanceTypes .= 's';
}

$attendanceSql = "
    SELECT
        a.id, a.employee_id, a.provider_id, a.attendance_date,
        a.check_in, a.check_out, a.status, a.remarks,
        e.employee_number, e.first_name, e.last_name, e.job_title,
        u.full_name AS provider_name,
        u.email AS provider_email,
        u.phone AS provider_phone
    FROM employee_attendance a
    LEFT JOIN employees e ON e.id = a.employee_id
    LEFT JOIN providers p ON p.id = a.provider_id
    LEFT JOIN users u ON u.id = p.user_id
";

if ($attendanceWhere) {
    $attendanceSql .= ' WHERE ' . implode(' AND ', $attendanceWhere);
}

$attendanceSql .= ' ORDER BY a.attendance_date DESC, a.id DESC LIMIT 300';

$attendanceStmt = $conn->prepare($attendanceSql);

if (!$attendanceStmt) {
    die('Unable to load attendance records. Check the employee_attendance table.');
}

if ($attendanceParams) {
    $attendanceStmt->bind_param($attendanceTypes, ...$attendanceParams);
}

$attendanceStmt->execute();
$attendanceResult = $attendanceStmt->get_result();
$attendanceRows = [];

while ($row = $attendanceResult->fetch_assoc()) {
    $attendanceRows[] = $row;
}

$attendanceStmt->close();

$summaryStmt = $conn->prepare("
    SELECT
        COUNT(*) AS total_recorded,
        COALESCE(SUM(status = 'Present'), 0) AS total_present,
        COALESCE(SUM(status = 'Absent'), 0) AS total_absent,
        COALESCE(SUM(status = 'Late'), 0) AS total_late,
        COALESCE(SUM(check_in IS NOT NULL), 0) AS total_checked_in,
        COALESCE(SUM(check_out IS NOT NULL), 0) AS total_checked_out
    FROM employee_attendance
    WHERE attendance_date = ?
");

$summaryStmt->bind_param('s', $today);
$summaryStmt->execute();
$summary = $summaryStmt->get_result()->fetch_assoc();
$summaryStmt->close();

/*
|--------------------------------------------------------------------------
| Provider availability
|--------------------------------------------------------------------------
*/

$providerSearch = trim($_GET['provider_search'] ?? '');
$providerDay = $_GET['provider_day'] ?? '';
$providerStatus = $_GET['provider_status'] ?? '';

$providerWhere = ["u.role = 'provider'"];
$providerParams = [];
$providerTypes = '';

if ($providerSearch !== '') {
    $providerWhere[] = '(u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
    $providerLike = '%' . $providerSearch . '%';

    array_push($providerParams, $providerLike, $providerLike, $providerLike);
    $providerTypes .= 'sss';
}

if (in_array($providerDay, $daysOfWeek, true)) {
    $providerWhere[] = 'av.day_of_week = ?';
    $providerParams[] = $providerDay;
    $providerTypes .= 's';
}

if (in_array($providerStatus, ['available', 'unavailable'], true)) {
    $providerWhere[] = 'av.status = ?';
    $providerParams[] = $providerStatus;
    $providerTypes .= 's';
}

$providerSql = "
    SELECT
        u.id AS user_id, u.full_name, u.email, u.phone,
        u.status AS account_status,
        p.id AS provider_id, p.verification_status,
        p.availability_status AS overall_availability,
        av.id AS schedule_id, av.day_of_week,
        av.start_time, av.end_time, av.status AS schedule_status
    FROM users u
    INNER JOIN providers p ON p.user_id = u.id
    LEFT JOIN availability av ON av.provider_id = p.id
    WHERE " . implode(' AND ', $providerWhere) . "
    ORDER BY u.full_name ASC,
        FIELD(
            av.day_of_week,
            'Monday', 'Tuesday', 'Wednesday', 'Thursday',
            'Friday', 'Saturday', 'Sunday'
        ) ASC
";

$providerStmt = $conn->prepare($providerSql);

if (!$providerStmt) {
    die('Unable to load provider availability.');
}

if ($providerParams) {
    $providerStmt->bind_param($providerTypes, ...$providerParams);
}

$providerStmt->execute();
$providerResult = $providerStmt->get_result();
$providerRows = [];

while ($row = $providerResult->fetch_assoc()) {
    $providerRows[] = $row;
}

$providerStmt->close();

$providerSummaryResult = $conn->query("
    SELECT
        COUNT(*) AS total_providers,
        COALESCE(SUM(availability_status = 'available'), 0) AS available_providers,
        COALESCE(SUM(availability_status = 'unavailable'), 0) AS unavailable_providers
    FROM providers
");

$providerSummary = $providerSummaryResult
    ? $providerSummaryResult->fetch_assoc()
    : [
        'total_providers' => 0,
        'available_providers' => 0,
        'unavailable_providers' => 0
    ];

/*
|--------------------------------------------------------------------------
| Leave management filters and records
|--------------------------------------------------------------------------
*/

$leaveStatus = $_GET['leave_status'] ?? 'all';
$leaveType = $_GET['leave_type'] ?? 'all';
$leaveSearch = trim($_GET['leave_search'] ?? '');

if (!in_array($leaveStatus, $validStatuses, true)) {
    $leaveStatus = 'all';
}

if (!in_array($leaveType, $validLeaveTypes, true)) {
    $leaveType = 'all';
}

$leaveWhere = [];
$leaveParams = [];
$leaveTypes = '';

if ($leaveStatus !== 'all') {
    $leaveWhere[] = 'lr.status = ?';
    $leaveParams[] = $leaveStatus;
    $leaveTypes .= 's';
}

if ($leaveType !== 'all') {
    $leaveWhere[] = 'lr.leave_type = ?';
    $leaveParams[] = $leaveType;
    $leaveTypes .= 's';
}

if ($leaveSearch !== '') {
    $leaveWhere[] = "(
        COALESCE(
            NULLIF(TRIM(CONCAT_WS(' ', e.first_name, e.last_name)), ''),
            ''
        ) LIKE ?
        OR COALESCE(e.employee_number, '') LIKE ?
        OR COALESCE(e.job_title, '') LIKE ?
        OR COALESCE(pu.full_name, '') LIKE ?
        OR COALESCE(pu.email, '') LIKE ?
        OR COALESCE(pu.phone, '') LIKE ?
    )";

    $leaveLike = '%' . $leaveSearch . '%';

    array_push(
        $leaveParams,
        $leaveLike, $leaveLike, $leaveLike,
        $leaveLike, $leaveLike, $leaveLike
    );

    $leaveTypes .= 'ssssss';
}

$leaveSql = "
    SELECT
        lr.id, lr.employee_id, lr.provider_id, lr.leave_type,
        lr.start_date, lr.end_date, lr.reason, lr.status,
        lr.review_comment, lr.reviewed_at, lr.created_at,
        e.employee_number, e.first_name, e.last_name, e.job_title,
        pu.full_name AS provider_name,
        pu.email AS provider_email,
        reviewer.full_name AS reviewer_name
    FROM leave_requests lr
    LEFT JOIN employees e ON e.id = lr.employee_id
    LEFT JOIN providers p ON p.id = lr.provider_id
    LEFT JOIN users pu ON pu.id = p.user_id
    LEFT JOIN users reviewer ON reviewer.id = lr.reviewed_by
";

if ($leaveWhere) {
    $leaveSql .= ' WHERE ' . implode(' AND ', $leaveWhere);
}

$leaveSql .= "
    ORDER BY
        CASE WHEN lr.status = 'Pending' THEN 0 ELSE 1 END,
        lr.created_at DESC
    LIMIT 300
";

$leaveStmt = $conn->prepare($leaveSql);

if (!$leaveStmt) {
    die('Unable to load leave requests. Check the leave_requests table.');
}

if ($leaveParams) {
    $leaveStmt->bind_param($leaveTypes, ...$leaveParams);
}

$leaveStmt->execute();
$leaveResult = $leaveStmt->get_result();
$leaveRows = [];

while ($row = $leaveResult->fetch_assoc()) {
    $leaveRows[] = $row;
}

$leaveStmt->close();

$leaveSummaryResult = $conn->query("
    SELECT
        COUNT(*) AS total_requests,
        COALESCE(SUM(status = 'Pending'), 0) AS pending_requests,
        COALESCE(SUM(status = 'Approved'), 0) AS approved_requests,
        COALESCE(SUM(status = 'Rejected'), 0) AS rejected_requests,
        COALESCE(SUM(status = 'Cancelled'), 0) AS cancelled_requests
    FROM leave_requests
");

$leaveSummary = $leaveSummaryResult
    ? $leaveSummaryResult->fetch_assoc()
    : [
        'total_requests' => 0,
        'pending_requests' => 0,
        'approved_requests' => 0,
        'rejected_requests' => 0,
        'cancelled_requests' => 0
    ];

function attendanceStatusClass(string $status): string
{
    return strtolower(str_replace(' ', '-', $status));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance & Leave Management | HandyLink Admin</title>

    <link rel="stylesheet" href="../assets/css/attendance.css">
    <link rel="stylesheet" href="../assets/css/admin_leave_management.css">
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
</head>
<body>

<header class="topbar">
    <a class="brand" href="dashboard.php">
        <img src="../assets/images/handylink-logo.png" alt="HandyLink logo">
        <span>HandyLink<small>Administration</small></span>
    </a>

    <div class="topbar-right">
        <span class="admin-label">
            <i class="fa-solid fa-user-shield"></i>
            <?= e($adminName) ?>
        </span>

        <a class="back-link" href="dashboard.php">
            <i class="fa-solid fa-arrow-left"></i> Dashboard
        </a>
    </div>
</header>

<main class="page-container">

    <div class="page-heading">
        <div>
            <p class="eyebrow">HUMAN RESOURCE MANAGEMENT</p>
            <h1>Attendance & Leave Management</h1>
            <p class="page-description">
                Monitor attendance, review provider availability, and manage employee
                and provider leave applications.
            </p>
        </div>

        <div class="today-label">
            <i class="fa-regular fa-calendar"></i>
            <?= e(date('l, d F Y')) ?>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert <?= e($messageType) ?>" role="status">
            <i class="fa-solid <?= $messageType === 'error'
                ? 'fa-circle-exclamation'
                : 'fa-circle-check' ?>"></i>
            <?= e($message) ?>
        </div>
    <?php endif; ?>

   

    <?php if ($section === 'attendance'): ?>

        <section class="stats-grid">
            <article class="stat-card">
                <div class="stat-icon blue"><i class="fa-solid fa-clipboard-check"></i></div>
                <div><span>Attendance Records Today</span>
                    <strong><?= (int)$summary['total_recorded'] ?></strong></div>
            </article>

            <article class="stat-card">
                <div class="stat-icon green"><i class="fa-solid fa-user-check"></i></div>
                <div><span>Present Today</span>
                    <strong><?= (int)$summary['total_present'] ?></strong></div>
            </article>

            <article class="stat-card">
                <div class="stat-icon orange"><i class="fa-solid fa-right-to-bracket"></i></div>
                <div><span>Checked In Today</span>
                    <strong><?= (int)$summary['total_checked_in'] ?></strong></div>
            </article>

            <article class="stat-card">
                <div class="stat-icon red"><i class="fa-solid fa-user-xmark"></i></div>
                <div><span>Absent Today</span>
                    <strong><?= (int)$summary['total_absent'] ?></strong></div>
            </article>
        </section>

        <section class="panel">
            <div class="panel-heading">
                <div>
                    <h2><i class="fa-solid fa-list-check"></i> Attendance Records</h2>
                    <p>View employee and provider check-in/check-out times.</p>
                </div>
                <span class="record-count"><?= count($attendanceRows) ?> records shown</span>
            </div>

            <form method="GET" class="filter-form">
                <input type="hidden" name="section" value="attendance">

                <div class="form-field search-field">
                    <label for="attendance_search">Search provider or employee</label>
                    <input type="search" id="attendance_search"
                           name="attendance_search" value="<?= e($attendanceSearch) ?>"
                           placeholder="Name, email, employee number">
                </div>

                <div class="form-field">
                    <label for="attendance_date">Attendance date</label>
                    <input type="date" id="attendance_date" name="attendance_date"
                           value="<?= e($attendanceDateFilter) ?>">
                </div>

                <div class="form-field">
                    <label for="attendance_status">Status</label>
                    <select id="attendance_status" name="attendance_status">
                        <option value="">All statuses</option>
                        <?php foreach ($attendanceStatuses as $status): ?>
                            <option value="<?= e($status) ?>"
                                <?= $attendanceStatusFilter === $status ? 'selected' : '' ?>>
                                <?= e($status) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-actions">
                    <button class="btn btn-primary" type="submit">
                        <i class="fa-solid fa-magnifying-glass"></i> Filter
                    </button>
                    <a class="btn btn-light" href="attendance.php?section=attendance">Reset</a>
                </div>
            </form>

            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Provider / Employee</th>
                        <th>Date</th>
                        <th>Check-in</th>
                        <th>Check-out</th>
                        <th>Status</th>
                        <th>Remarks</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$attendanceRows): ?>
                        <tr><td colspan="6" class="empty-state">
                            <i class="fa-regular fa-folder-open"></i>
                            <strong>No attendance records found</strong>
                            <span>Records will appear here when attendance is recorded.</span>
                        </td></tr>
                    <?php else: ?>
                        <?php foreach ($attendanceRows as $row): ?>
                            <?php
                            $isProvider = !empty($row['provider_id']);

                            if ($isProvider) {
                                $displayName = $row['provider_name'] ?: 'Unknown provider';
                                $displayRole = 'Service Provider';
                                $displayIdentifier = $row['provider_email'] ?? '';
                            } else {
                                $displayName = trim(
                                    ($row['first_name'] ?? '') . ' ' .
                                    ($row['last_name'] ?? '')
                                );
                                $displayName = $displayName ?: 'Employee';
                                $displayRole = $row['job_title'] ?: 'Employee';
                                $displayIdentifier = $row['employee_number'] ?? '';
                            }
                            ?>
                            <tr>
                                <td>
                                    <strong><?= e($displayName) ?></strong>
                                    <small><?= e($displayRole) ?></small>
                                    <?php if ($displayIdentifier !== ''): ?>
                                        <small><?= e($displayIdentifier) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?= e(date('d M Y', strtotime($row['attendance_date']))) ?></td>
                                <td><?= !empty($row['check_in']) ? e(date('h:i A', strtotime($row['check_in']))) : '—' ?></td>
                                <td><?= !empty($row['check_out']) ? e(date('h:i A', strtotime($row['check_out']))) : '—' ?></td>
                                <td><span class="status-badge status-<?= e(attendanceStatusClass($row['status'] ?? '')) ?>">
                                    <?= e($row['status'] ?? 'Unknown') ?>
                                </span></td>
                                <td class="remarks-cell"><?= e($row['remarks'] ?: '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <p class="table-note">A maximum of 300 records are displayed. Times are shown in East Africa Time.</p>
        </section>

    <?php elseif ($section === 'providers'): ?>

        <section class="stats-grid">
            <article class="stat-card">
                <div class="stat-icon blue"><i class="fa-solid fa-people-group"></i></div>
                <div><span>Registered Providers</span>
                    <strong><?= (int)$providerSummary['total_providers'] ?></strong></div>
            </article>

            <article class="stat-card">
                <div class="stat-icon green"><i class="fa-solid fa-calendar-check"></i></div>
                <div><span>Marked Available</span>
                    <strong><?= (int)$providerSummary['available_providers'] ?></strong></div>
            </article>

            <article class="stat-card">
                <div class="stat-icon orange"><i class="fa-solid fa-calendar-xmark"></i></div>
                <div><span>Marked Unavailable</span>
                    <strong><?= (int)$providerSummary['unavailable_providers'] ?></strong></div>
            </article>
        </section>

        <section class="panel">
            <div class="panel-heading">
                <div>
                    <h2><i class="fa-solid fa-calendar-week"></i> Provider Availability</h2>
                    <p>Review providers' saved weekly schedules and working hours.</p>
                </div>
            </div>

            <form method="GET" class="filter-form">
                <input type="hidden" name="section" value="providers">

                <div class="form-field search-field">
                    <label for="provider_search">Search provider</label>
                    <input type="search" id="provider_search" name="provider_search"
                           value="<?= e($providerSearch) ?>" placeholder="Name, email or phone">
                </div>

                <div class="form-field">
                    <label for="provider_day">Day of week</label>
                    <select id="provider_day" name="provider_day">
                        <option value="">All days</option>
                        <?php foreach ($daysOfWeek as $day): ?>
                            <option value="<?= e($day) ?>" <?= $providerDay === $day ? 'selected' : '' ?>>
                                <?= e($day) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-field">
                    <label for="provider_status">Day availability</label>
                    <select id="provider_status" name="provider_status">
                        <option value="">All statuses</option>
                        <option value="available" <?= $providerStatus === 'available' ? 'selected' : '' ?>>Available</option>
                        <option value="unavailable" <?= $providerStatus === 'unavailable' ? 'selected' : '' ?>>Unavailable</option>
                    </select>
                </div>

                <div class="filter-actions">
                    <button class="btn btn-primary" type="submit">
                        <i class="fa-solid fa-magnifying-glass"></i> Filter
                    </button>
                    <a class="btn btn-light" href="attendance.php?section=providers">Reset</a>
                </div>
            </form>

            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Provider</th><th>Verification</th><th>Overall Status</th>
                        <th>Day</th><th>Working Hours</th><th>Day Availability</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$providerRows): ?>
                        <tr><td colspan="6" class="empty-state">
                            <i class="fa-regular fa-calendar-xmark"></i>
                            <strong>No provider schedules found</strong>
                            <span>Try changing your filters or check saved schedules.</span>
                        </td></tr>
                    <?php else: ?>
                        <?php foreach ($providerRows as $row): ?>
                            <tr>
                                <td>
                                    <strong><?= e($row['full_name']) ?></strong>
                                    <small><?= e($row['email']) ?></small>
                                    <small><?= e($row['phone']) ?></small>
                                </td>
                                <td><span class="status-badge status-<?= e(strtolower($row['verification_status'] ?? 'pending')) ?>">
                                    <?= e(ucfirst($row['verification_status'] ?? 'Unknown')) ?>
                                </span></td>
                                <td><span class="status-badge status-<?= e(strtolower($row['overall_availability'] ?? 'unavailable')) ?>">
                                    <?= e(ucfirst($row['overall_availability'] ?? 'Unknown')) ?>
                                </span></td>
                                <td>
                                    <?php if (!empty($row['day_of_week'])): ?>
                                        <strong><?= e($row['day_of_week']) ?></strong>
                                        <?php if (in_array($row['day_of_week'], ['Saturday', 'Sunday'], true)): ?>
                                            <small class="weekend-label">Weekend</small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="muted">No schedule set</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($row['start_time']) && !empty($row['end_time'])): ?>
                                        <?= e(date('g:i A', strtotime($row['start_time']))) ?>
                                        – <?= e(date('g:i A', strtotime($row['end_time']))) ?>
                                    <?php else: ?>
                                        <span class="muted">Not specified</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($row['schedule_status'])): ?>
                                        <span class="status-badge status-<?= e(strtolower($row['schedule_status'])) ?>">
                                            <?= e(ucfirst($row['schedule_status'])) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="status-badge status-pending">Not set</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="info-note">
                <i class="fa-solid fa-circle-info"></i>
                <p>Availability indicates when providers say they can work. It is not proof of attendance.</p>
            </div>
        </section>

    <?php else: ?>

        <section class="stats-grid">
            <article class="stat-card">
                <div class="stat-icon blue"><i class="fa-solid fa-folder-open"></i></div>
                <div><span>Total Requests</span>
                    <strong><?= (int)$leaveSummary['total_requests'] ?></strong></div>
            </article>

            <article class="stat-card">
                <div class="stat-icon orange"><i class="fa-regular fa-clock"></i></div>
                <div><span>Pending</span>
                    <strong><?= (int)$leaveSummary['pending_requests'] ?></strong></div>
            </article>

            <article class="stat-card">
                <div class="stat-icon green"><i class="fa-solid fa-check"></i></div>
                <div><span>Approved</span>
                    <strong><?= (int)$leaveSummary['approved_requests'] ?></strong></div>
            </article>

            <article class="stat-card">
                <div class="stat-icon red"><i class="fa-solid fa-xmark"></i></div>
                <div><span>Rejected</span>
                    <strong><?= (int)$leaveSummary['rejected_requests'] ?></strong></div>
            </article>
        </section>

        <section class="panel">
            <div class="panel-heading">
                <div>
                    <h2><i class="fa-solid fa-calendar-minus"></i> Leave Applications</h2>
                    <p>Review employee and service provider leave applications.</p>
                </div>
                <span class="record-count"><?= count($leaveRows) ?> result(s)</span>
            </div>

            <form method="GET" class="filter-form">
                <input type="hidden" name="section" value="leave">

                <div class="form-field search-field">
                    <label for="leave_search">Search applicant</label>
                    <input type="search" id="leave_search" name="leave_search"
                           value="<?= e($leaveSearch) ?>"
                           placeholder="Name, email, phone or employee number">
                </div>

                <div class="form-field">
                    <label for="leave_status">Request status</label>
                    <select id="leave_status" name="leave_status">
                        <?php foreach ($validStatuses as $option): ?>
                            <option value="<?= e($option) ?>" <?= $leaveStatus === $option ? 'selected' : '' ?>>
                                <?= $option === 'all' ? 'All statuses' : e($option) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-field">
                    <label for="leave_type">Leave type</label>
                    <select id="leave_type" name="leave_type">
                        <?php foreach ($validLeaveTypes as $option): ?>
                            <option value="<?= e($option) ?>" <?= $leaveType === $option ? 'selected' : '' ?>>
                                <?= $option === 'all' ? 'All leave types' : e($option) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-actions">
                    <button class="btn btn-primary" type="submit">
                        <i class="fa-solid fa-magnifying-glass"></i> Filter
                    </button>
                    <a class="btn btn-light" href="attendance.php?section=leave">Reset</a>
                </div>
            </form>

            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Applicant</th>
                        <th>Leave Type</th>
                        <th>Leave Dates</th>
                        <th>Reason</th>
                        <th>Status</th>
                        <th>Review Details</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$leaveRows): ?>
                        <tr><td colspan="7" class="empty-state">
                            <i class="fa-regular fa-folder-open"></i>
                            <strong>No leave requests found</strong>
                            <span>Requests will appear here when submitted.</span>
                        </td></tr>
                    <?php else: ?>
                        <?php foreach ($leaveRows as $leave): ?>
                            <?php
                            $isProviderLeave = !empty($leave['provider_id']);
                            $employeeName = trim(
                                ($leave['first_name'] ?? '') . ' ' .
                                ($leave['last_name'] ?? '')
                            );

                            if ($isProviderLeave) {
                                $applicantName = $leave['provider_name'] ?: 'Unknown provider';
                                $applicantRole = 'Service Provider';
                                $applicantDetail = $leave['provider_email'] ?? '';
                            } else {
                                $applicantName = $employeeName ?: 'Employee';
                                $applicantRole = $leave['job_title'] ?: 'Employee';
                                $applicantDetail = $leave['employee_number'] ?? '';
                            }

                            $startTimestamp = strtotime($leave['start_date']);
                            $endTimestamp = strtotime($leave['end_date']);
                            $leaveDays = ($startTimestamp !== false && $endTimestamp !== false)
                                ? (int)((strtotime(date('Y-m-d', $endTimestamp)) -
                                    strtotime(date('Y-m-d', $startTimestamp))) / 86400) + 1
                                : 0;

                            $leaveStatusValue = $leave['status'] ?: 'Pending';
                            ?>
                            <tr>
                                <td>
                                    <div class="employee-cell">
                                        <div class="employee-avatar">
                                            <?= e(strtoupper(substr($applicantName, 0, 1))) ?>
                                        </div>
                                        <div>
                                            <strong><?= e($applicantName) ?></strong>
                                            <small><?= e($applicantRole) ?></small>
                                            <small><?= e($applicantDetail) ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="leave-type"><?= e($leave['leave_type'] ?: 'Annual') ?></span></td>
                                <td>
                                    <strong><?= e(date('d M Y', strtotime($leave['start_date']))) ?></strong>
                                    <small>to <?= e(date('d M Y', strtotime($leave['end_date']))) ?></small>
                                    <small><?= $leaveDays ?> calendar day(s)</small>
                                </td>
                                <td class="reason-cell"><?= nl2br(e($leave['reason'])) ?></td>
                                <td>
                                    <span class="status-badge status-<?= e(strtolower(str_replace(' ', '-', $leaveStatusValue))) ?>">
                                        <?= e($leaveStatusValue) ?>
                                    </span>
                                </td>
                                <td class="review-cell">
                                    <?php if (!empty($leave['review_comment'])): ?>
                                        <p><?= nl2br(e($leave['review_comment'])) ?></p>
                                    <?php endif; ?>
                                    <?php if (!empty($leave['reviewer_name'])): ?>
                                        <small>Reviewed by <?= e($leave['reviewer_name']) ?></small>
                                    <?php endif; ?>
                                    <?php if (!empty($leave['reviewed_at'])): ?>
                                        <small><?= e(date('d M Y, g:i A', strtotime($leave['reviewed_at']))) ?></small>
                                    <?php endif; ?>
                                    <?php if (empty($leave['review_comment']) && empty($leave['reviewed_at'])): ?>
                                        <small class="muted">Not reviewed</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($leaveStatusValue === 'Pending'): ?>
                                        <form method="POST" class="leave-review-form"
                                              action="attendance.php?section=leave">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                            <input type="hidden" name="leave_id" value="<?= (int)$leave['id'] ?>">
                                            <label class="review-comment-label">
                                                Review comment
                                                <textarea name="review_comment" rows="2" maxlength="3000"
                                                    placeholder="Optional comment"></textarea>
                                            </label>
                                            <button type="submit" name="leave_action" value="approve"
                                                    class="btn btn-approve"
                                                    onclick="return confirm('Approve this leave request?')">
                                                <i class="fa-solid fa-check"></i> Approve
                                            </button>
                                            <button type="submit" name="leave_action" value="reject"
                                                    class="btn btn-reject"
                                                    onclick="return confirm('Reject this leave request?')">
                                                <i class="fa-solid fa-xmark"></i> Reject
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="muted">Decision made</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <p class="table-note">
                A maximum of 300 leave requests are displayed. Provider requests are identified using provider_id.
            </p>
        </section>

    <?php endif; ?>

    <footer class="page-footer">
        HandyLink Administration &copy; <?= date('Y') ?>
    </footer>
</main>

</body>
</html>
