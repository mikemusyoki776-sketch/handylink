
<?php
session_start();

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'admin'
) {
    header("Location: ../login.php");
    exit;
}

require_once "../config/database.php";
$conn->set_charset("utf8mb4");

if (empty($_SESSION['employee_csrf'])) {
    $_SESSION['employee_csrf'] = bin2hex(random_bytes(32));
}

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function go(string $key, string $message): void
{
    header("Location: employee.php?" . $key . "=" . urlencode($message));
    exit;
}

$statuses = ['Active', 'On Leave', 'Suspended', 'Terminated'];
$types = ['Full-time', 'Part-time', 'Contract', 'Intern'];

$error = '';
$success = '';

/* SAVE EMPLOYEE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['employee_csrf'], $_POST['csrf_token'])
    ) {
        $error = "Session validation failed. Refresh the page and try again.";
    } else {
        $id = (int)($_POST['id'] ?? 0);
        $userId = (int)($_POST['user_id'] ?? 0);

        $employeeNumber = trim($_POST['employee_number'] ?? '');
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $gender = trim($_POST['gender'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $jobTitle = trim($_POST['job_title'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $employmentType = trim($_POST['employment_type'] ?? 'Full-time');
        $hireDate = trim($_POST['hire_date'] ?? '');
        $employmentStatus = trim($_POST['employment_status'] ?? 'Active');

        if (
            $employeeNumber === '' ||
            $firstName === '' ||
            $lastName === '' ||
            $jobTitle === ''
        ) {
            $error = "Please fill in the employee number, first name, last name and job title.";
        } elseif (!in_array($employmentType, $types, true)) {
            $error = "Select a valid employment type.";
        } elseif (!in_array($employmentStatus, $statuses, true)) {
            $error = "Select a valid employment status.";
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Enter a valid email address.";
        } elseif ($hireDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hireDate)) {
            $error = "Enter a valid hire date.";
        } else {
            try {
                if ($id > 0) {
                    $check = $conn->prepare("SELECT id FROM employees WHERE id = ?");
                    $check->bind_param("i", $id);
                    $check->execute();
                    $exists = $check->get_result()->num_rows > 0;
                    $check->close();

                    if (!$exists) {
                        throw new RuntimeException("Employee record not found.");
                    }
                }

                /* Verify selected provider */
                if ($userId > 0) {
                    $check = $conn->prepare(
                        "SELECT u.id
                         FROM users u
                         INNER JOIN providers p ON p.user_id = u.id
                         WHERE u.id = ?
                           AND u.role = 'provider'
                           AND p.verification_status = 'approved'
                         LIMIT 1"
                    );
                    $check->bind_param("i", $userId);
                    $check->execute();
                    $approved = $check->get_result()->num_rows > 0;
                    $check->close();

                    /* Allow an existing link to remain if verification later changes. */
                    if (!$approved && $id > 0) {
                        $check = $conn->prepare(
                            "SELECT id FROM employees WHERE id = ? AND user_id = ?"
                        );
                        $check->bind_param("ii", $id, $userId);
                        $check->execute();
                        $approved = $check->get_result()->num_rows > 0;
                        $check->close();
                    }

                    if (!$approved) {
                        throw new RuntimeException(
                            "Only an approved service provider can be linked."
                        );
                    }

                    $check = $conn->prepare(
                        "SELECT id FROM employees WHERE user_id = ? AND id <> ? LIMIT 1"
                    );
                    $check->bind_param("ii", $userId, $id);
                    $check->execute();
                    $duplicateLink = $check->get_result()->num_rows > 0;
                    $check->close();

                    if ($duplicateLink) {
                        throw new RuntimeException(
                            "This provider is already linked to another employee."
                        );
                    }
                }

                /* Prevent duplicate employee numbers */
                $check = $conn->prepare(
                    "SELECT id FROM employees WHERE employee_number = ? AND id <> ? LIMIT 1"
                );
                $check->bind_param("si", $employeeNumber, $id);
                $check->execute();
                $duplicateNumber = $check->get_result()->num_rows > 0;
                $check->close();

                if ($duplicateNumber) {
                    throw new RuntimeException("This employee number is already in use.");
                }

                $gender = $gender !== '' ? $gender : null;
                $phone = $phone !== '' ? $phone : null;
                $email = $email !== '' ? $email : null;
                $address = $address !== '' ? $address : null;
                $department = $department !== '' ? $department : null;
                $hireDate = $hireDate !== '' ? $hireDate : null;

                if ($id > 0) {
                    $stmt = $conn->prepare(
                        "UPDATE employees SET
                            user_id = NULLIF(?, 0),
                            employee_number = ?,
                            first_name = ?,
                            last_name = ?,
                            gender = ?,
                            phone = ?,
                            email = ?,
                            address = ?,
                            job_title = ?,
                            department = ?,
                            employment_type = ?,
                            hire_date = ?,
                            employment_status = ?
                         WHERE id = ?"
                    );

                    $bindTypes = "i" . str_repeat("s", 12) . "i";

                    $stmt->bind_param(
                        $bindTypes,
                        $userId,
                        $employeeNumber,
                        $firstName,
                        $lastName,
                        $gender,
                        $phone,
                        $email,
                        $address,
                        $jobTitle,
                        $department,
                        $employmentType,
                        $hireDate,
                        $employmentStatus,
                        $id
                    );
                } else {
                    $stmt = $conn->prepare(
                        "INSERT INTO employees (
                            user_id, employee_number, first_name, last_name,
                            gender, phone, email, address, job_title,
                            department, employment_type, hire_date, employment_status
                        ) VALUES (
                            NULLIF(?, 0), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                        )"
                    );

                    $bindTypes = "i" . str_repeat("s", 12);

                    $stmt->bind_param(
                        $bindTypes,
                        $userId,
                        $employeeNumber,
                        $firstName,
                        $lastName,
                        $gender,
                        $phone,
                        $email,
                        $address,
                        $jobTitle,
                        $department,
                        $employmentType,
                        $hireDate,
                        $employmentStatus
                    );
                }

                $stmt->execute();
                $stmt->close();

                go('success', 'Employee saved successfully.');

            } catch (Throwable $ex) {
                error_log("HandyLink employee save error: " . $ex->getMessage());

                if ($ex instanceof RuntimeException) {
                    $error = $ex->getMessage();
                } elseif ($ex instanceof mysqli_sql_exception && (int)$ex->getCode() === 1062) {
                    $error = "A duplicate value exists. Check the employee number and linked provider.";
                } else {
                    $error = "Unable to save the employee. Check the employees table structure and server error log.";
                }
            }
        }
    }
}

/* DELETE EMPLOYEE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['employee_csrf'], $_POST['csrf_token'])
    ) {
        go('error', 'Session validation failed. Please try again.');
    }

    $deleteId = (int)($_POST['id'] ?? 0);

    if ($deleteId <= 0) {
        go('error', 'Invalid employee record.');
    }

    try {
        $stmt = $conn->prepare("DELETE FROM employees WHERE id = ?");
        $stmt->bind_param("i", $deleteId);
        $stmt->execute();
        $deleted = $stmt->affected_rows;
        $stmt->close();

        if ($deleted > 0) {
            go('success', 'Employee deleted successfully.');
        }

        go('error', 'Employee record not found.');
    } catch (Throwable $ex) {
        error_log("HandyLink employee delete error: " . $ex->getMessage());
        go('error', 'Unable to delete this employee because other records may depend on it.');
    }
}

/* LOAD RECORD FOR EDITING */
$editId = (int)($_GET['edit'] ?? 0);
$editEmployee = null;

if ($editId > 0) {
    $stmt = $conn->prepare("SELECT * FROM employees WHERE id = ?");
    $stmt->bind_param("i", $editId);
    $stmt->execute();
    $editEmployee = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$editEmployee) {
        $error = "Employee record not found.";
    }
}

/* LOAD APPROVED PROVIDERS AND CURRENTLY LINKED PROVIDER */
$currentUserId = (int)($editEmployee['user_id'] ?? 0);

$stmt = $conn->prepare(
    "SELECT u.id AS user_id, u.full_name, u.email, u.phone
     FROM users u
     INNER JOIN providers p ON p.user_id = u.id
     WHERE u.role = 'provider'
       AND (p.verification_status = 'approved' OR u.id = ?)
     ORDER BY u.full_name"
);
$stmt->bind_param("i", $currentUserId);
$stmt->execute();
$providerResult = $stmt->get_result();
$providers = [];

while ($provider = $providerResult->fetch_assoc()) {
    $providers[] = $provider;
}
$stmt->close();

$linkedUsers = [];
$linkResult = $conn->query(
    "SELECT user_id, id FROM employees WHERE user_id IS NOT NULL"
);

while ($link = $linkResult->fetch_assoc()) {
    $linkedUsers[(int)$link['user_id']] = (int)$link['id'];
}

/* SEARCH AND FILTER */
$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$sql = "SELECT * FROM employees WHERE 1=1";
$params = [];
$bindTypes = "";

if ($search !== '') {
    $sql .= " AND (
        employee_number LIKE ? OR first_name LIKE ? OR last_name LIKE ?
        OR email LIKE ? OR phone LIKE ? OR job_title LIKE ? OR department LIKE ?
    )";

    $term = "%{$search}%";

    for ($i = 0; $i < 7; $i++) {
        $params[] = $term;
        $bindTypes .= "s";
    }
}

if (in_array($statusFilter, $statuses, true)) {
    $sql .= " AND employment_status = ?";
    $params[] = $statusFilter;
    $bindTypes .= "s";
}

$sql .= " ORDER BY created_at DESC";

$stmt = $conn->prepare($sql);

if ($params) {
    $stmt->bind_param($bindTypes, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();
$employees = [];

while ($row = $result->fetch_assoc()) {
    $employees[] = $row;
}
$stmt->close();

/* STATISTICS */
$statsResult = $conn->query(
    "SELECT
        COUNT(*) AS total,
        COALESCE(SUM(employment_status = 'Active'), 0) AS active_count,
        COALESCE(SUM(employment_status = 'On Leave'), 0) AS leave_count,
        COALESCE(SUM(employment_status = 'Terminated'), 0) AS terminated_count
     FROM employees"
);

$stats = $statsResult->fetch_assoc();

$adminName = $_SESSION['full_name'] ?? 'Admin';
$success = $_GET['success'] ?? '';
$error = $error ?: ($_GET['error'] ?? '');

$form = $editEmployee ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $form = $_POST;
}

$isEditing = (int)($form['id'] ?? 0) > 0;
$formUserId = (int)($form['user_id'] ?? 0);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Employee Records | HandyLink</title>

    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>
        .page-container{width:100%;max-width:1600px;margin:auto;padding:24px;box-sizing:border-box}
        .page-heading{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:22px}
        .page-heading h1{margin:0 0 6px}
        .page-heading p{margin:0}
        .stats-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:15px;margin-bottom:22px}
        .stat-card,.panel{background:#fff;border-radius:12px;padding:20px;box-shadow:0 2px 10px #0000000d;min-width:0}
        .stat-label{color:#666;font-size:14px}
        .stat-number{display:block;font-size:27px;font-weight:700;margin-top:8px}
        .content-grid{display:grid;grid-template-columns:minmax(300px,.85fr) minmax(0,1.5fr);gap:20px;align-items:start}
        .panel h2{margin-top:0}
        .employee-form,.filter-form{display:flex;flex-direction:column;gap:13px}
        .form-row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
        .form-group{display:flex;flex-direction:column;gap:6px;min-width:0}
        .form-group label{font-size:14px;font-weight:600}
        .form-group input,.form-group select,.form-group textarea,.filter-form input,.filter-form select{width:100%;min-width:0;padding:10px;border:1px solid #ccc;border-radius:7px;box-sizing:border-box;background:#fff;color:#222}
        .form-group textarea{min-height:70px;resize:vertical}
        .form-actions,.filter-actions,.action-buttons{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
        .btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:10px 13px;border:0;border-radius:7px;cursor:pointer;text-decoration:none;font-size:14px;font-weight:600}
        .btn-primary{background:#052f0c;color:#fff}
        .btn-secondary{background:#e8e8e8;color:#222}
        .alert{padding:13px 15px;margin-bottom:17px;border-radius:8px;overflow-wrap:anywhere}
        .alert-success{background:#e5f6e9;color:#145c28}
        .alert-error{background:#fdeaea;color:#8a1f1f}
        .filter-form{flex-direction:row;align-items:end;flex-wrap:wrap;margin-bottom:18px}
        .filter-form .form-group{flex:1 1 150px}
        .table-wrapper{width:100%;overflow-x:auto}
        .table-wrapper table{width:100%;min-width:800px;border-collapse:collapse}
        .table-wrapper th,.table-wrapper td{padding:12px 10px;border-bottom:1px solid #eee;text-align:left;vertical-align:top}
        .table-wrapper th{background:#f5f7f5;white-space:nowrap}
        .status{display:inline-block;padding:5px 9px;border-radius:20px;font-size:12px;font-weight:700;background:#eee}
        .status-active{background:#e0f5e5;color:#17662c}
        .status-on-leave{background:#fff0ce;color:#815500}
        .status-suspended,.status-terminated{background:#fce4e4;color:#8a2020}
        .action-btn{border:0;border-radius:6px;padding:7px 9px;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:5px;font-size:13px}
        .edit-btn{background:#e4efe6;color:#052f0c}
        .delete-btn{background:#fbe6e6;color:#9a2020}
        .muted{color:#777;font-size:13px}
        @media(max-width:1000px){.content-grid{grid-template-columns:1fr}.stats-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media(max-width:600px){.page-container{padding:13px}.panel,.stat-card{padding:14px}.form-row{grid-template-columns:1fr}.filter-form{flex-direction:column;align-items:stretch}}
    </style>
</head>

<body>
<header class="topbar">
    <div class="brand">
        <img src="../assets/images/handylink-logo.png" alt="HandyLink Logo"
             style="max-height:48px;max-width:180px;object-fit:contain">
    </div>
    <div class="topbar-user">
        <i class="fa-solid fa-user-shield"></i>
        <span><?= e($adminName) ?></span>
    </div>
</header>

<main class="page-container">
    <div class="page-heading">
        <div>
            <h1><i class="fa-solid fa-users"></i> Employee Records</h1>
            <p>Manage employee information and approved service-provider links.</p>
        </div>
        <a class="btn btn-secondary" href="dashboard.php">
            <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
        </a>
    </div>

    <?php if ($success !== ''): ?>
        <div class="alert alert-success">
            <i class="fa-solid fa-circle-check"></i> <?= e($success) ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert alert-error">
            <i class="fa-solid fa-circle-exclamation"></i> <?= e($error) ?>
        </div>
    <?php endif; ?>

    <section class="stats-grid">
        <div class="stat-card">
            <span class="stat-label"><i class="fa-solid fa-users"></i> Total Employees</span>
            <span class="stat-number"><?= (int)$stats['total'] ?></span>
        </div>
        <div class="stat-card">
            <span class="stat-label"><i class="fa-solid fa-user-check"></i> Active</span>
            <span class="stat-number"><?= (int)$stats['active_count'] ?></span>
        </div>
        <div class="stat-card">
            <span class="stat-label"><i class="fa-solid fa-calendar-minus"></i> On Leave</span>
            <span class="stat-number"><?= (int)$stats['leave_count'] ?></span>
        </div>
        <div class="stat-card">
            <span class="stat-label"><i class="fa-solid fa-user-xmark"></i> Terminated</span>
            <span class="stat-number"><?= (int)$stats['terminated_count'] ?></span>
        </div>
    </section>

    <section class="content-grid">
        <div class="panel">
            <h2>
                <i class="fa-solid fa-user-plus"></i>
                <?= $isEditing ? 'Edit Employee' : 'Add Employee' ?>
            </h2>

            <form method="POST" action="employee.php<?= $isEditing ? '?edit=' . (int)$form['id'] : '' ?>"
                  class="employee-form">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['employee_csrf']) ?>">
                <input type="hidden" name="id" value="<?= (int)($form['id'] ?? 0) ?>">

                <div class="form-group">
                    <label for="user_id">Link Approved Service Provider</label>
                    <select name="user_id" id="user_id">
                        <option value="0">No linked provider account</option>
                        <?php foreach ($providers as $provider): ?>
                            <?php
                            $pid = (int)$provider['user_id'];
                            $usedByAnother = isset($linkedUsers[$pid]) &&
                                $linkedUsers[$pid] !== (int)($form['id'] ?? 0);
                            ?>
                            <?php if (!$usedByAnother): ?>
                                <option value="<?= $pid ?>"
                                    data-name="<?= e($provider['full_name']) ?>"
                                    data-email="<?= e($provider['email']) ?>"
                                    data-phone="<?= e($provider['phone']) ?>"
                                    <?= $formUserId === $pid ? 'selected' : '' ?>>
                                    <?= e($provider['full_name']) ?> — Approved Provider
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                    <small class="muted">Providers must be approved before they can be linked.</small>
                </div>

                <div class="form-group">
                    <label for="employee_number">Employee Number *</label>
                    <input id="employee_number" name="employee_number" required
                           value="<?= e($form['employee_number'] ?? '') ?>"
                           placeholder="e.g. EMP001">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="first_name">First Name *</label>
                        <input id="first_name" name="first_name" required
                               value="<?= e($form['first_name'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label for="last_name">Last Name *</label>
                        <input id="last_name" name="last_name" required
                               value="<?= e($form['last_name'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="gender">Gender</label>
                        <select id="gender" name="gender">
                            <option value="">Select gender</option>
                            <?php foreach (['Male', 'Female', 'Other'] as $g): ?>
                                <option value="<?= e($g) ?>" <?= ($form['gender'] ?? '') === $g ? 'selected' : '' ?>>
                                    <?= e($g) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="phone">Phone</label>
                        <input id="phone" name="phone" value="<?= e($form['phone'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="<?= e($form['email'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label for="address">Address</label>
                    <textarea id="address" name="address"><?= e($form['address'] ?? '') ?></textarea>
                </div>

                <div class="form-group">
                    <label for="job_title">Job Title *</label>
                    <input id="job_title" name="job_title" required
                           value="<?= e($form['job_title'] ?? '') ?>"
                           placeholder="e.g. Electrician, Administrator">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="department">Department</label>
                        <input id="department" name="department" value="<?= e($form['department'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label for="employment_type">Employment Type *</label>
                        <select id="employment_type" name="employment_type" required>
                            <?php foreach ($types as $type): ?>
                                <option value="<?= e($type) ?>"
                                    <?= ($form['employment_type'] ?? 'Full-time') === $type ? 'selected' : '' ?>>
                                    <?= e($type) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="hire_date">Hire Date</label>
                        <input type="date" id="hire_date" name="hire_date"
                               value="<?= e($form['hire_date'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label for="employment_status">Employment Status *</label>
                        <select id="employment_status" name="employment_status" required>
                            <?php foreach ($statuses as $status): ?>
                                <option value="<?= e($status) ?>"
                                    <?= ($form['employment_status'] ?? 'Active') === $status ? 'selected' : '' ?>>
                                    <?= e($status) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fa-solid fa-floppy-disk"></i>
                        <?= $isEditing ? 'Update Employee' : 'Save Employee' ?>
                    </button>
                    <button type="reset" class="btn btn-secondary" id="clearForm">
                        <i class="fa-solid fa-rotate-left"></i> Clear Form
                    </button>
                    <?php if ($isEditing): ?>
                        <a href="employee.php" class="btn btn-secondary">Cancel Edit</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="panel">
            <h2><i class="fa-solid fa-address-book"></i> Employee Directory</h2>

            <form method="GET" action="employee.php" class="filter-form">
                <div class="form-group">
                    <label for="search">Search Employees</label>
                    <input type="search" id="search" name="search"
                           value="<?= e($search) ?>"
                           placeholder="Name, number, email, job title...">
                </div>
                <div class="form-group">
                    <label for="status">Employment Status</label>
                    <select id="status" name="status">
                        <option value="">All statuses</option>
                        <?php foreach ($statuses as $status): ?>
                            <option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>>
                                <?= e($status) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fa-solid fa-magnifying-glass"></i> Search
                    </button>
                    <a href="employee.php" class="btn btn-secondary">Clear Filters</a>
                </div>
            </form>

            <div class="table-wrapper">
                <table>
                    <thead>
                    <tr>
                        <th>Employee No.</th>
                        <th>Employee</th>
                        <th>Job Title</th>
                        <th>Department</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$employees): ?>
                        <tr><td colspan="6">No employee records found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($employees as $employee): ?>
                            <?php $statusClass = strtolower(str_replace(' ', '-', $employee['employment_status'] ?? '')); ?>
                            <tr>
                                <td><?= e($employee['employee_number']) ?></td>
                                <td>
                                    <strong><?= e($employee['first_name'] . ' ' . $employee['last_name']) ?></strong>
                                    <br><small><?= e($employee['email']) ?></small>
                                    <?php if (!empty($employee['user_id'])): ?>
                                        <br><small class="muted"><i class="fa-solid fa-link"></i> Provider linked</small>
                                    <?php endif; ?>
                                </td>
                                <td><?= e($employee['job_title']) ?></td>
                                <td><?= e($employee['department']) ?></td>
                                <td><span class="status status-<?= e($statusClass) ?>"><?= e($employee['employment_status']) ?></span></td>
                                <td>
                                    <div class="action-buttons">
                                        <a class="action-btn edit-btn"
                                           href="employee.php?edit=<?= (int)$employee['id'] ?>">
                                            <i class="fa-solid fa-pen"></i> Edit
                                        </a>
                                        <form method="POST" action="employee.php"
                                              onsubmit="return confirm('Are you sure you want to delete this employee record?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int)$employee['id'] ?>">
                                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['employee_csrf']) ?>">
                                            <button type="submit" class="action-btn delete-btn">
                                                <i class="fa-solid fa-trash"></i> Delete
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const providerSelect = document.getElementById('user_id');
    const form = document.querySelector('.employee-form');
    const clearButton = document.getElementById('clearForm');

    if (providerSelect) {
        providerSelect.addEventListener('change', function () {
            if (this.value === '0') return;

            const option = this.options[this.selectedIndex];
            const parts = (option.dataset.name || '').trim().split(/\s+/);

            document.getElementById('first_name').value = parts.shift() || '';
            document.getElementById('last_name').value = parts.join(' ');
            document.getElementById('email').value = option.dataset.email || '';
            document.getElementById('phone').value = option.dataset.phone || '';
        });
    }

    if (clearButton && form) {
        clearButton.addEventListener('click', function (event) {
            event.preventDefault();

            if (new URLSearchParams(window.location.search).has('edit')) {
                window.location.href = 'employee.php';
                return;
            }

            form.reset();

            if (providerSelect) providerSelect.value = '0';
        });
    }
});
</script>
</body>
</html>
