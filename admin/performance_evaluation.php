
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection error. Check config/database.php.');
}

$evaluatorId = (int) $_SESSION['user_id'];
$error = '';
$success = '';

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $employeeId = (int) ($_POST['employee_id'] ?? 0);
        $evaluationDate = $_POST['evaluation_date'] ?? '';
        $workQuality = (int) ($_POST['work_quality'] ?? 0);
        $punctuality = (int) ($_POST['punctuality'] ?? 0);
        $teamwork = (int) ($_POST['teamwork'] ?? 0);
        $communication = (int) ($_POST['communication'] ?? 0);
        $reliability = (int) ($_POST['reliability'] ?? 0);
        $comments = trim($_POST['comments'] ?? '');

        $scores = [
            $workQuality,
            $punctuality,
            $teamwork,
            $communication,
            $reliability
        ];

        $validDate = DateTime::createFromFormat('!Y-m-d', $evaluationDate);

        if ($employeeId < 1 || !$validDate ||
            $validDate->format('Y-m-d') !== $evaluationDate) {
            $error = 'Please select a valid employee and evaluation date.';
        } elseif (in_array(false, array_map(
            fn($score) => $score >= 1 && $score <= 5,
            $scores
        ), true)) {
            $error = 'All ratings must be between 1 and 5.';
        } else {
            $check = $conn->prepare(
                "SELECT id FROM employees
                 WHERE id = ? AND employment_status = 'Active'"
            );

            if (!$check) {
                $error = 'Unable to validate employee.';
            } else {
                $check->bind_param('i', $employeeId);
                $check->execute();
                $employeeResult = $check->get_result();
                $employeeExists = $employeeResult->num_rows > 0;
                $check->close();

                if (!$employeeExists) {
                    $error = 'The selected employee was not found or is not active.';
                } else {
                    $overallScore = array_sum($scores) / count($scores);

                    $stmt = $conn->prepare(
                        "INSERT INTO performance_evaluations
                        (employee_id, evaluator_id, evaluation_date,
                         work_quality, punctuality, teamwork,
                         communication, reliability, overall_score, comments)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                    );

                    if (!$stmt) {
                        $error = 'Unable to prepare evaluation. Check your database columns.';
                    } else {
                        $stmt->bind_param(
                            'iisiiiiids',
                            $employeeId,
                            $evaluatorId,
                            $evaluationDate,
                            $workQuality,
                            $punctuality,
                            $teamwork,
                            $communication,
                            $reliability,
                            $overallScore,
                            $comments
                        );

                        if ($stmt->execute()) {
                            $stmt->close();
                            header('Location: performance_evaluation.php?saved=1');
                            exit;
                        }

                        $error = 'Could not save evaluation: ' . $stmt->error;
                        $stmt->close();
                    }
                }
            }
        }
    }

    if ($action === 'delete') {
        $evaluationId = (int) ($_POST['evaluation_id'] ?? 0);

        if ($evaluationId > 0) {
            $stmt = $conn->prepare(
                "DELETE FROM performance_evaluations WHERE id = ?"
            );

            if ($stmt) {
                $stmt->bind_param('i', $evaluationId);

                if ($stmt->execute() && $stmt->affected_rows > 0) {
                    $stmt->close();
                    header('Location: performance_evaluation.php?deleted=1');
                    exit;
                }

                $error = 'Evaluation could not be deleted.';
                $stmt->close();
            } else {
                $error = 'Unable to prepare the delete request.';
            }
        }
    }
}

if (isset($_GET['saved'])) {
    $success = 'Performance evaluation saved successfully.';
} elseif (isset($_GET['deleted'])) {
    $success = 'Evaluation deleted successfully.';
}

$employees = $conn->query(
    "SELECT id, employee_number, first_name, last_name,
            job_title, department
     FROM employees
     WHERE employment_status = 'Active'
     ORDER BY first_name, last_name"
);

$summary = $conn->query(
    "SELECT COUNT(*) AS total,
            ROUND(AVG(overall_score), 2) AS average_score
     FROM performance_evaluations"
)->fetch_assoc();

$evaluations = $conn->query(
    "SELECT pe.*, e.employee_number, e.first_name, e.last_name,
            e.job_title, e.department, u.full_name AS evaluator_name
     FROM performance_evaluations pe
     INNER JOIN employees e ON pe.employee_id = e.id
     LEFT JOIN users u ON pe.evaluator_id = u.id
     ORDER BY pe.evaluation_date DESC, pe.id DESC"
);

$employeeCount = $conn->query(
    "SELECT COUNT(*) AS total FROM employees
     WHERE employment_status = 'Active'"
)->fetch_assoc()['total'];

$averageScore = (float) ($summary['average_score'] ?? 0);
$totalEvaluations = (int) ($summary['total'] ?? 0);
$scoreLabel = $averageScore >= 4.5 ? 'Excellent' :
    ($averageScore >= 3.5 ? 'Good' :
    ($averageScore >= 2.5 ? 'Average' :
    ($averageScore > 0 ? 'Needs Improvement' : 'Not Rated')));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Performance Evaluation | HandyLink Admin</title>
    <link rel="stylesheet" href="../assets/css/admin_performance_evaluation.css">
</head>
<body>

<header class="pe-header">
    <a href="dashboard.php" class="pe-brand">
        <img src="../assets/images/handylink-logo.png" alt="HandyLink Logo">
        <span>HandyLink <small>Performance Management</small></span>
    </a>
    <a href="dashboard.php" class="back-link">← Admin Dashboard</a>
</header>

<main class="pe-container">

    <section class="page-heading">
        <div>
            <p class="eyebrow">HUMAN RESOURCE MANAGEMENT</p>
            <h1>Performance Evaluation</h1>
            <p>Evaluate employee performance, record feedback and review progress.</p>
        </div>
        <a href="#new-evaluation" class="primary-button">+ New Evaluation</a>
    </section>

    <?php if ($success): ?>
        <div class="alert success"><?= e($success) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>

    <section class="stats-grid">
        <article class="stat-card">
            <div class="stat-icon">👥</div>
            <p>Active Employees</p>
            <h2><?= (int) $employeeCount ?></h2>
            <span>Currently employed</span>
        </article>

        <article class="stat-card">
            <div class="stat-icon">📋</div>
            <p>Total Evaluations</p>
            <h2><?= $totalEvaluations ?></h2>
            <span>Records completed</span>
        </article>

        <article class="stat-card">
            <div class="stat-icon">⭐</div>
            <p>Average Score</p>
            <h2><?= number_format($averageScore, 2) ?><small>/5</small></h2>
            <span><?= e($scoreLabel) ?></span>
        </article>
    </section>

    <section class="panel" id="new-evaluation">
        <div class="panel-heading">
            <div>
                <h2>New Employee Evaluation</h2>
                <p>Rate each area from 1 (poor) to 5 (excellent).</p>
            </div>
            <span class="section-tag">EVALUATION FORM</span>
        </div>

        <form method="POST" class="evaluation-form">
            <input type="hidden" name="action" value="save">

            <div class="form-grid">
                <div class="form-group">
                    <label for="employee_id">Select Employee *</label>
                    <select name="employee_id" id="employee_id" required>
                        <option value="">Choose an employee</option>
                        <?php if ($employees): ?>
                            <?php while ($employee = $employees->fetch_assoc()): ?>
                                <option value="<?= (int) $employee['id'] ?>">
                                    <?= e($employee['first_name'] . ' ' . $employee['last_name']) ?>
                                    — <?= e($employee['employee_number']) ?>
                                </option>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="evaluation_date">Evaluation Date *</label>
                    <input type="date" name="evaluation_date"
                           id="evaluation_date"
                           value="<?= date('Y-m-d') ?>" required>
                </div>
            </div>

            <div class="rating-section">
                <h3>Performance Criteria</h3>
                <p>Choose a rating for every criterion.</p>

                <?php
                $criteria = [
                    'work_quality' => 'Work Quality',
                    'punctuality' => 'Punctuality',
                    'teamwork' => 'Teamwork',
                    'communication' => 'Communication',
                    'reliability' => 'Reliability'
                ];
                ?>

                <div class="rating-grid">
                    <?php foreach ($criteria as $field => $label): ?>
                        <div class="rating-card">
                            <label for="<?= e($field) ?>"><?= e($label) ?></label>
                            <select name="<?= e($field) ?>"
                                    id="<?= e($field) ?>" required>
                                <option value="">Rate 1–5</option>
                                <option value="1">1 — Poor</option>
                                <option value="2">2 — Fair</option>
                                <option value="3">3 — Average</option>
                                <option value="4">4 — Good</option>
                                <option value="5">5 — Excellent</option>
                            </select>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="score-preview">
                    <span>Calculated overall score</span>
                    <strong id="score-preview">— / 5</strong>
                    <small id="score-label">Rate all five criteria</small>
                </div>
            </div>

            <div class="form-group">
                <label for="comments">Evaluator Comments</label>
                <textarea name="comments" id="comments" rows="4"
                          placeholder="Record achievements, challenges and recommendations..."></textarea>
            </div>

            <div class="form-actions">
                <button type="reset" class="secondary-button">Clear Form</button>
                <button type="submit" class="primary-button">Save Evaluation</button>
            </div>
        </form>
    </section>

    <section class="panel">
        <div class="panel-heading history-heading">
            <div>
                <h2>Evaluation History</h2>
                <p>Previously recorded employee performance evaluations.</p>
            </div>
            <input type="search" id="evaluation-search"
                   placeholder="Search employees..." aria-label="Search evaluations">
        </div>

        <div class="table-wrapper">
            <table id="evaluation-table">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Department</th>
                        <th>Date</th>
                        <th>Score</th>
                        <th>Rating</th>
                        <th>Evaluator</th>
                        <th>Comments</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($evaluations && $evaluations->num_rows > 0): ?>
                    <?php while ($row = $evaluations->fetch_assoc()): ?>
                        <?php
                        $score = (float) $row['overall_score'];
                        $rating = $score >= 4.5 ? 'Excellent' :
                            ($score >= 3.5 ? 'Good' :
                            ($score >= 2.5 ? 'Average' : 'Needs Improvement'));
                        ?>
                        <tr>
                            <td>
                                <strong><?= e($row['first_name'] . ' ' . $row['last_name']) ?></strong>
                                <small><?= e($row['employee_number']) ?></small>
                                <small><?= e($row['job_title']) ?></small>
                            </td>
                            <td><?= e($row['department']) ?></td>
                            <td><?= e($row['evaluation_date']) ?></td>
                            <td><strong><?= number_format($score, 2) ?>/5</strong></td>
                            <td><span class="rating-badge"><?= e($rating) ?></span></td>
                            <td><?= e($row['evaluator_name'] ?? 'Admin') ?></td>
                            <td class="comment-cell">
                                <?= e($row['comments'] ?: 'No comments') ?>
                            </td>
                            <td>
                                <form method="POST"
                                      onsubmit="return confirm('Delete this evaluation permanently?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="evaluation_id"
                                           value="<?= (int) $row['id'] ?>">
                                    <button type="submit" class="delete-button">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8" class="empty-state">
                            No evaluations yet. Create your first evaluation above.
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

</main>

<script>
const ratingFields = [
    'work_quality',
    'punctuality',
    'teamwork',
    'communication',
    'reliability'
];

function updateScorePreview() {
    const values = ratingFields.map(id => {
        const value = document.getElementById(id).value;
        return value === '' ? null : Number(value);
    });

    const scoreElement = document.getElementById('score-preview');
    const labelElement = document.getElementById('score-label');

    if (values.some(value => value === null)) {
        scoreElement.textContent = '— / 5';
        labelElement.textContent = 'Rate all five criteria';
        return;
    }

    const score = values.reduce((sum, value) => sum + value, 0) / values.length;
    scoreElement.textContent = score.toFixed(2) + ' / 5';

    labelElement.textContent = score >= 4.5 ? 'Excellent' :
        score >= 3.5 ? 'Good' :
        score >= 2.5 ? 'Average' : 'Needs Improvement';
}

ratingFields.forEach(id => {
    document.getElementById(id).addEventListener('change', updateScorePreview);
});

document.querySelector('.evaluation-form').addEventListener('reset', () => {
    setTimeout(updateScorePreview, 0);
});

document.getElementById('evaluation-search').addEventListener('input', function () {
    const term = this.value.toLowerCase();

    document.querySelectorAll('#evaluation-table tbody tr').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(term) ? '' : 'none';
    });
});
</script>

</body>
</html>
