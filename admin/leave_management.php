<?php
session_start();

if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'admin'
) {
    header('Location: ../login.php');
    exit();
}

require_once __DIR__ . '/../config/database.php';

date_default_timezone_set('Africa/Nairobi');

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection failed. Check config/database.php.');
}

$conn->set_charset('utf8mb4');

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function redirectLeave(string $message, string $type = 'success'): void
{
    $status = $_GET['status'] ?? $_POST['status'] ?? 'all';
    $leaveType = $_GET['leave_type'] ?? $_POST['leave_type'] ?? 'all';
    $search = $_GET['search'] ?? $_POST['search'] ?? '';

    header(
        'Location: /handylink/admin/leave_management.php?' .
        http_build_query([
            'status' => $status,
            'leave_type' => $leaveType,
            'search' => $search,
            'message' => $message,
            'type' => $type
        ])
    );

    exit();
}

if (empty($_SESSION['leave_csrf'])) {
    $_SESSION['leave_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['leave_csrf'];

$validStatuses = ['all', 'Pending', 'Approved', 'Rejected', 'Cancelled'];

$validLeaveTypes = [
    'all', 'Annual', 'Sick', 'Maternity',
    'Paternity', 'Compassionate', 'Unpaid', 'Other'
];

/* ==========================================
   APPROVE OR REJECT LEAVE APPLICATION
========================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $postedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($postedToken) ||
        !hash_equals($csrfToken, $postedToken)
    ) {
        redirectLeave('Security check failed. Please try again.', 'error');
    }

    $leaveId = filter_var($_POST['leave_id'] ?? '', FILTER_VALIDATE_INT);
    $action = $_POST['action'] ?? '';
    $reviewComment = trim($_POST['review_comment'] ?? '');

    if (!$leaveId || !in_array($action, ['approve', 'reject'], true)) {
        redirectLeave('Invalid leave request action.', 'error');
    }

    $newStatus = $action === 'approve' ? 'Approved' : 'Rejected';

    // Prepare the leave review update.
    $stmt = $conn->prepare(
        "UPDATE leave_requests
         SET status = ?,
             reviewed_by = ?,
             review_comment = ?,
             reviewed_at = NOW()
         WHERE id = ? AND status = 'Pending'"
    );

    if (!$stmt) {
        redirectLeave(
            'Unable to prepare the review action: ' . $conn->error,
            'error'
        );
    }

    // Execute the update.
    $adminId = (int)$_SESSION['user_id'];

    $stmt->bind_param(
        'sisi',
        $newStatus,
        $adminId,
        $reviewComment,
        $leaveId
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();

        redirectLeave('Unable to update leave request: ' . $error, 'error');
    }

    $updated = $stmt->affected_rows;

    $stmt->close();

    if ($updated > 0) {

        // Find the account that submitted the leave request.
        $recipientStmt = $conn->prepare("
            SELECT COALESCE(e.user_id, p.user_id) AS recipient_id,
                   lr.leave_type,
                   lr.start_date,
                   lr.end_date
            FROM leave_requests lr
            LEFT JOIN employees e ON e.id = lr.employee_id
            LEFT JOIN providers p ON p.id = lr.provider_id
            WHERE lr.id = ?
            LIMIT 1
        ");

        if ($recipientStmt) {

            $recipientStmt->bind_param('i', $leaveId);
            $recipientStmt->execute();

            $recipient = $recipientStmt->get_result()->fetch_assoc();

            $recipientStmt->close();

            if (!empty($recipient['recipient_id'])) {

                $recipientId = (int)$recipient['recipient_id'];
                $leaveType = $recipient['leave_type'];

                $startDate = date(
                    'd M Y',
                    strtotime($recipient['start_date'])
                );

                $endDate = date(
                    'd M Y',
                    strtotime($recipient['end_date'])
                );

                $notificationTitle = $newStatus === 'Approved'
                    ? 'Leave Application Approved'
                    : 'Leave Application Rejected';

                $notificationMessage = $newStatus === 'Approved'
                    ? "Your {$leaveType} leave request from {$startDate} to {$endDate} has been approved."
                    : "Your {$leaveType} leave request from {$startDate} to {$endDate} has been rejected.";

                if ($reviewComment !== '') {
                    $notificationMessage .= " Administrator's comment: " . $reviewComment;
                }

                $notificationType = 'leave';

                $notificationStmt = $conn->prepare("
                    INSERT INTO notifications
                    (user_id, title, message, type, is_read, created_at)
                    VALUES (?, ?, ?, ?, 0, NOW())
                ");

                if ($notificationStmt) {

                    $notificationStmt->bind_param(
                        'isss',
                        $recipientId,
                        $notificationTitle,
                        $notificationMessage,
                        $notificationType
                    );

                    $notificationStmt->execute();
                    $notificationStmt->close();
                }
            }
        }

        redirectLeave(
            'Leave request ' . strtolower($newStatus) . ' successfully.'
        );
    }

    redirectLeave(
        'This request may already have been reviewed or may not exist.',
        'error'
    );

} // END POST PROCESSING


/* ==========================================
   FILTERS
========================================== */

$statusFilter = $_GET['status'] ?? 'all';
$typeFilter = $_GET['leave_type'] ?? 'all';
$search = trim($_GET['search'] ?? '');

if (!in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = 'all';
}

if (!in_array($typeFilter, $validLeaveTypes, true)) {
    $typeFilter = 'all';
}

$where = [];
$params = [];
$types = '';

if ($statusFilter !== 'all') {
    $where[] = 'lr.status = ?';
    $params[] = $statusFilter;
    $types .= 's';
}

if ($typeFilter !== 'all') {
    $where[] = 'lr.leave_type = ?';
    $params[] = $typeFilter;
    $types .= 's';
}

if ($search !== '') {

    $where[] = "(
        e.employee_number LIKE ?
        OR e.first_name LIKE ?
        OR e.last_name LIKE ?
        OR u.full_name LIKE ?
        OR u.email LIKE ?
    )";

    $searchTerm = '%' . $search . '%';

    for ($i = 0; $i < 5; $i++) {
        $params[] = $searchTerm;
        $types .= 's';
    }
}

/* ==========================================
   LOAD LEAVE APPLICATIONS
========================================== */

$sql = "
    SELECT
        lr.id,
        lr.employee_id,
        lr.provider_id,
        lr.leave_type,
        lr.start_date,
        lr.end_date,
        lr.reason,
        lr.status,
        lr.review_comment,
        lr.reviewed_at,
        lr.created_at,

        e.employee_number,
        e.first_name,
        e.last_name,
        e.job_title,

        p.id AS matched_provider_id,

        u.full_name,
        u.email,

        reviewer.full_name AS reviewer_name

    FROM leave_requests lr

    LEFT JOIN employees e
        ON e.id = lr.employee_id

    LEFT JOIN providers p
        ON p.id = lr.provider_id

    LEFT JOIN users u
        ON u.id = COALESCE(e.user_id, p.user_id)

    LEFT JOIN users reviewer
        ON reviewer.id = lr.reviewed_by
";

if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= "
    ORDER BY
        CASE WHEN lr.status = 'Pending' THEN 0 ELSE 1 END,
        lr.created_at DESC
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die('Unable to load leave requests: ' . e($conn->error));
}

if ($params) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();

$result = $stmt->get_result();

$leaveRequests = [];

while ($row = $result->fetch_assoc()) {
    $leaveRequests[] = $row;
}

$stmt->close();

/* ==========================================
   LEAVE SUMMARY STATISTICS
========================================== */

$summaryResult = $conn->query("
    SELECT
        COUNT(*) AS total_requests,
        COALESCE(SUM(status = 'Pending'), 0) AS pending_requests,
        COALESCE(SUM(status = 'Approved'), 0) AS approved_requests,
        COALESCE(SUM(status = 'Rejected'), 0) AS rejected_requests,
        COALESCE(SUM(status = 'Cancelled'), 0) AS cancelled_requests
    FROM leave_requests
");

$summary = $summaryResult
    ? $summaryResult->fetch_assoc()
    : [
        'total_requests' => 0,
        'pending_requests' => 0,
        'approved_requests' => 0,
        'rejected_requests' => 0,
        'cancelled_requests' => 0
    ];

$message = $_GET['message'] ?? '';

$messageType = ($_GET['type'] ?? '') === 'error'
    ? 'error'
    : 'success';

$adminName = $_SESSION['full_name'] ?? 'Administrator';

/* ==========================================
   STATUS BADGE CLASS
========================================== */

function statusClass(string $status): string
{
    return match ($status) {
        'Pending' => 'pending',
        'Approved' => 'approved',
        'Rejected' => 'rejected',
        'Cancelled' => 'cancelled',
        default => 'pending'
    };
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Leave Management | HandyLink Admin</title>

    <link rel="stylesheet" href="../assets/css/admin_leave_management.css">

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

</head>

<body>

<header class="topbar">

    <a class="brand" href="dashboard.php">

        <img src="../assets/images/handylink-logo.png" alt="HandyLink logo">

        <span>
            HandyLink
            <small>Administration</small>
        </span>

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

    <section class="page-heading">

        <div>

            <p class="eyebrow">HUMAN RESOURCE MANAGEMENT</p>

            <h1>Leave Management</h1>

            <p class="page-description">
                Review employee leave applications and manage approval decisions.
            </p>

        </div>

        <div class="today-label">

            <i class="fa-regular fa-calendar"></i>

            <?= e(date('l, d F Y')) ?>

        </div>

    </section>

    <?php if ($message !== ''): ?>

        <div class="alert <?= e($messageType) ?>">

            <i class="fa-solid <?= $messageType === 'error'
                ? 'fa-circle-exclamation'
                : 'fa-circle-check' ?>"></i>

            <?= e($message) ?>

        </div>

    <?php endif; ?>

    <!-- SUMMARY CARDS -->

    <section class="stats-grid">

        <article class="stat-card">

            <div class="stat-icon blue">
                <i class="fa-solid fa-folder-open"></i>
            </div>

            <div>
                <span>Total Requests</span>
                <strong><?= (int)$summary['total_requests'] ?></strong>
            </div>

        </article>

        <article class="stat-card">

            <div class="stat-icon orange">
                <i class="fa-regular fa-clock"></i>
            </div>

            <div>
                <span>Pending</span>
                <strong><?= (int)$summary['pending_requests'] ?></strong>
            </div>

        </article>

        <article class="stat-card">

            <div class="stat-icon green">
                <i class="fa-solid fa-check"></i>
            </div>

            <div>
                <span>Approved</span>
                <strong><?= (int)$summary['approved_requests'] ?></strong>
            </div>

        </article>

        <article class="stat-card">

            <div class="stat-icon red">
                <i class="fa-solid fa-xmark"></i>
            </div>

            <div>
                <span>Rejected</span>
                <strong><?= (int)$summary['rejected_requests'] ?></strong>
            </div>

        </article>

    </section>

    <!-- LEAVE APPLICATIONS -->

    <section class="panel">

        <div class="panel-heading">

            <div>

                <h2>Leave Applications</h2>

                <p>
                    Search requests, check dates, and review each application.
                </p>

            </div>

            <span class="request-count">
                <?= count($leaveRequests) ?> result(s)
            </span>

        </div>

        <!-- FILTER FORM -->

        <form class="filter-form" method="POST" action="/handylink/admin/leave_management.php">

            <div class="form-field search-field">

                <label for="search">Search employee</label>

                <input
                    type="text"
                    id="search"
                    name="search"
                    placeholder="Name, employee number or email"
                    value="<?= e($search) ?>"
                >

            </div>

            <div class="form-field">

                <label for="status">Request status</label>

                <select id="status" name="status">

                    <?php foreach ($validStatuses as $statusOption): ?>

                        <option value="<?= e($statusOption) ?>"
                            <?= $statusFilter === $statusOption ? 'selected' : '' ?>>

                            <?= $statusOption === 'all'
                                ? 'All statuses'
                                : e($statusOption) ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

            <div class="form-field">

                <label for="leave_type">Leave type</label>

                <select id="leave_type" name="leave_type">

                    <?php foreach ($validLeaveTypes as $typeOption): ?>

                        <option value="<?= e($typeOption) ?>"
                            <?= $typeFilter === $typeOption ? 'selected' : '' ?>>

                            <?= $typeOption === 'all'
                                ? 'All leave types'
                                : e($typeOption) ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

            <div class="filter-actions">

                <button type="submit" class="btn btn-primary">

                    <i class="fa-solid fa-magnifying-glass"></i> Filter

                </button>

                <a href="leave_management.php" class="btn btn-light">
                    Reset
                </a>

            </div>

        </form>

        <!-- LEAVE REQUEST TABLE -->

        <div class="table-wrap">

            <table>

                <thead>

                    <tr>
                        <th>Employee</th>
                        <th>Leave Type</th>
                        <th>Leave Dates</th>
                        <th>Reason</th>
                        <th>Status</th>
                        <th>Review Details</th>
                        <th>Actions</th>
                    </tr>

                </thead>

                <tbody>

                <?php if (!$leaveRequests): ?>

                    <tr>

                        <td colspan="7">

                            <div class="empty-state">

                                <i class="fa-regular fa-folder-open"></i>

                                <h3>No leave requests found</h3>

                                <p>Try changing your search or filters.</p>

                            </div>

                        </td>

                    </tr>

                <?php else: ?>

                    <?php foreach ($leaveRequests as $leave): ?>

                        <?php

                        $employeeName = trim(
                            ($leave['first_name'] ?? '') . ' ' .
                            ($leave['last_name'] ?? '')
                        );

                        if ($employeeName === '') {
                            $employeeName = $leave['full_name'] ?: 'Employee';
                        }

                        $employeeNumber = $leave['employee_number']
                            ?: (
                                !empty($leave['provider_id'])
                                    ? 'Provider #' . $leave['provider_id']
                                    : 'No employee number'
                            );

                        $jobTitle = $leave['job_title']
                            ?: (
                                !empty($leave['provider_id'])
                                    ? 'Service Provider'
                                    : 'Employee'
                            );

                        $start = new DateTime($leave['start_date']);
                        $end = new DateTime($leave['end_date']);

                        $days = $start->diff($end)->days + 1;

                        $leaveStatus = $leave['status'] ?: 'Pending';

                        ?>

                        <tr>

                            <!-- EMPLOYEE DETAILS -->

                            <td>

                                <div class="employee-cell">

                                    <div class="employee-avatar">

                                        <?= e(strtoupper(substr($employeeName, 0, 1))) ?>

                                    </div>

                                    <div>

                                        <strong><?= e($employeeName) ?></strong>

                                        <small><?= e($employeeNumber) ?></small>

                                        <small><?= e($jobTitle) ?></small>

                                        <?php if (!empty($leave['email'])): ?>

                                            <small><?= e($leave['email']) ?></small>

                                        <?php endif; ?>

                                    </div>

                                </div>

                            </td>

                            <!-- LEAVE TYPE -->

                            <td>

                                <span class="leave-type">
                                    <?= e($leave['leave_type'] ?: 'Annual') ?>
                                </span>

                            </td>

                            <!-- LEAVE DATES -->

                            <td>

                                <strong>
                                    <?= e(date('d M Y', strtotime($leave['start_date']))) ?>
                                </strong>

                                <small>
                                    to <?= e(date('d M Y', strtotime($leave['end_date']))) ?>
                                </small>

                                <small>
                                    <?= (int)$days ?> calendar day(s)
                                </small>

                            </td>

                            <!-- REASON -->

                            <td class="reason-cell">

                                <?= nl2br(e($leave['reason'])) ?>

                            </td>

                            <!-- STATUS -->

                            <td>

                                <span class="status-badge status-<?= e(statusClass($leaveStatus)) ?>">

                                    <?= e($leaveStatus) ?>

                                </span>

                            </td>

                            <!-- REVIEW DETAILS -->

                            <td class="review-cell">

                                <?php if ($leave['review_comment']): ?>

                                    <p>
                                        <?= nl2br(e($leave['review_comment'])) ?>
                                    </p>

                                <?php endif; ?>

                                <?php if ($leave['reviewer_name']): ?>

                                    <small>
                                        Reviewed by <?= e($leave['reviewer_name']) ?>
                                    </small>

                                <?php endif; ?>

                                <?php if ($leave['reviewed_at']): ?>

                                    <small>
                                        <?= e(date('d M Y, g:i A', strtotime($leave['reviewed_at']))) ?>
                                    </small>

                                <?php endif; ?>

                                <?php if (!$leave['review_comment'] && !$leave['reviewed_at']): ?>

                                    <small class="muted">
                                        Not reviewed
                                    </small>

                                <?php endif; ?>

                            </td>

                            <!-- ACTION BUTTONS -->

                            <td>

                                <?php if ($leaveStatus === 'Pending'): ?>

                                    <div class="action-buttons">

                                        <button
                                            type="button"
                                            class="btn btn-approve"
                                            onclick="openReview(
                                                <?= (int)$leave['id'] ?>,
                                                'approve',
                                                <?= e(json_encode($employeeName)) ?>
                                            )"
                                        >

                                            <i class="fa-solid fa-check"></i> Approve

                                        </button>

                                        <button
                                            type="button"
                                            class="btn btn-reject"
                                            onclick="openReview(
                                                <?= (int)$leave['id'] ?>,
                                                'reject',
                                                <?= e(json_encode($employeeName)) ?>
                                            )"
                                        >

                                            <i class="fa-solid fa-xmark"></i> Reject

                                        </button>

                                    </div>

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

    </section>

    <footer class="page-footer">

        HandyLink Administration &copy; <?= date('Y') ?>

    </footer>

</main>

<!-- APPROVAL / REJECTION MODAL -->

<div class="modal-overlay" id="reviewModal" aria-hidden="true">

    <div class="review-modal"
         role="dialog"
         aria-modal="true"
         aria-labelledby="modalTitle">

        <button
            type="button"
            class="modal-close"
            onclick="closeReview()"
            aria-label="Close"
        >

            <i class="fa-solid fa-xmark"></i>

        </button>

        <div class="modal-icon" id="modalIcon">

            <i class="fa-solid fa-clipboard-check"></i>

        </div>

        <h2 id="modalTitle">Review Leave Request</h2>

        <p id="modalDescription"></p>

        <form method="POST" action="leave_management.php">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($csrfToken) ?>"
            >

            <input
                type="hidden"
                name="leave_id"
                id="modalLeaveId"
            >

            <input
                type="hidden"
                name="action"
                id="modalAction"
            >

            <label for="review_comment">
                Review comment (optional)
            </label>

            <textarea
                id="review_comment"
                name="review_comment"
                rows="4"
                maxlength="3000"
                placeholder="Add a comment for the employee..."
            ></textarea>

            <div class="modal-actions">

                <button
                    type="button"
                    class="btn btn-light"
                    onclick="closeReview()"
                >
                    Cancel
                </button>

                <button
                    type="submit"
                    class="btn btn-primary"
                    id="confirmReview"
                >
                    Confirm decision
                </button>

            </div>

        </form>

    </div>

</div>

<script>

function openReview(id, action, employeeName) {

    const modal = document.getElementById('reviewModal');

    const isApprove = action === 'approve';

    document.getElementById('modalLeaveId').value = id;

    document.getElementById('modalAction').value = action;

    document.getElementById('modalTitle').textContent =
        isApprove
            ? 'Approve Leave Request'
            : 'Reject Leave Request';

    document.getElementById('modalDescription').textContent =
        (isApprove
            ? 'You are approving the leave request submitted by '
            : 'You are rejecting the leave request submitted by '
        ) + employeeName + '.';

    document.getElementById('confirmReview').textContent =
        isApprove
            ? 'Approve request'
            : 'Reject request';

    document.getElementById('confirmReview').className =
        isApprove
            ? 'btn btn-approve'
            : 'btn btn-reject';

    document.getElementById('modalIcon').className =
        isApprove
            ? 'modal-icon modal-icon-approve'
            : 'modal-icon modal-icon-reject';

    document.getElementById('modalIcon').innerHTML =
        isApprove
            ? '<i class="fa-solid fa-check"></i>'
            : '<i class="fa-solid fa-xmark"></i>';

    document.getElementById('review_comment').value = '';

    modal.classList.add('show');

    modal.setAttribute('aria-hidden', 'false');

}

function closeReview() {

    const modal = document.getElementById('reviewModal');

    modal.classList.remove('show');

    modal.setAttribute('aria-hidden', 'true');

}

document.getElementById('reviewModal').addEventListener('click', function(event) {

    if (event.target === this) {
        closeReview();
    }

});

document.addEventListener('keydown', function(event) {

    if (event.key === 'Escape') {
        closeReview();
    }

});

</script>

</body>
</html>
