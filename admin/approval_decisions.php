
<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../config/database.php";

/* =========================================
   ADMIN ACCESS CONTROL
========================================= */

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'admin'
) {
    header("Location: ../login.php");
    exit;
}

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$success = $_SESSION['approval_success'] ?? '';
$error = '';

unset($_SESSION['approval_success']);


/* =========================================
   PROCESS APPROVAL OR REJECTION
========================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        $error = "Your session check failed. Refresh the page and try again.";

    } else {

        $verification_id = filter_input(
            INPUT_POST,
            'verification_id',
            FILTER_VALIDATE_INT
        );

        $decision = $_POST['decision'] ?? '';
        $admin_comment = trim($_POST['admin_comment'] ?? '');

        if (
            !$verification_id ||
            !in_array($decision, ['approved', 'rejected'], true)
        ) {
            $error = "Please select a valid verification decision.";

        } elseif ($admin_comment === '') {
            $error = "Please provide a comment explaining your decision.";

        } else {

            /*
             * IMPORTANT:
             * verification.provider_id references providers.id.
             * providers.user_id references users.id.
             */

            $check = $conn->prepare(
                "SELECT
                    v.id AS verification_id,
                    v.provider_id AS actual_provider_id,
                    v.document_type,
                    v.status AS document_status,
                    p.user_id AS user_id,
                    p.verification_status AS provider_status,
                    u.full_name,
                    u.status AS account_status
                 FROM verification v
                 INNER JOIN providers p
                    ON p.id = v.provider_id
                 INNER JOIN users u
                    ON u.id = p.user_id
                 WHERE v.id = ?
                   AND u.role = 'provider'
                 LIMIT 1"
            );

            if (!$check) {

                $error = "Unable to prepare the verification check.";

            } else {

                $check->bind_param("i", $verification_id);
                $check->execute();

                $record = $check->get_result()->fetch_assoc();

                $check->close();

                if (!$record) {

                    $error = "The verification submission or its provider record could not be found.";

                } else {

                    $provider_id = (int) $record['actual_provider_id'];
                    $user_id = (int) $record['user_id'];
                    $provider_name = $record['full_name'] ?? 'Provider';

                    $account_status = strtolower(
                        trim((string) ($record['account_status'] ?? ''))
                    );

                    if (
                        $decision === 'approved' &&
                        $account_status !== 'active'
                    ) {

                        $error = "This provider's account is not active. Activate the account before approving verification.";

                    } else {

                        $title = $decision === 'approved'
                            ? 'Verification Approved'
                            : 'Verification Rejected';

                        $message = $decision === 'approved'
                            ? "Hello {$provider_name}, your HandyLink verification document has been approved. Administrator's comment: {$admin_comment}"
                            : "Hello {$provider_name}, your HandyLink verification document has been rejected. Please review the administrator's comment and submit the required documents again if necessary. Administrator's comment: {$admin_comment}";

                        $notification_type = $decision === 'approved'
                            ? 'approval'
                            : 'rejection';

                        try {

                            $conn->begin_transaction();

                            /* 1. Update the selected document. */

                            $updateVerification = $conn->prepare(
                                "UPDATE verification
                                 SET status = ?,
                                     admin_comment = ?,
                                     reviewed_at = NOW()
                                 WHERE id = ?
                                   AND provider_id = ?"
                            );

                            if (!$updateVerification) {
                                throw new Exception(
                                    "Could not prepare the verification update."
                                );
                            }

                            $updateVerification->bind_param(
                                "ssii",
                                $decision,
                                $admin_comment,
                                $verification_id,
                                $provider_id
                            );

                            if (!$updateVerification->execute()) {
                                throw new Exception(
                                    "Could not update the verification document."
                                );
                            }

                            $updateVerification->close();


                            /* 2. Update the provider's overall status. */

                            $updateProvider = $conn->prepare(
                                "UPDATE providers
                                 SET verification_status = ?
                                 WHERE id = ?
                                   AND user_id = ?"
                            );

                            if (!$updateProvider) {
                                throw new Exception(
                                    "Could not prepare the provider status update."
                                );
                            }

                            $updateProvider->bind_param(
                                "sii",
                                $decision,
                                $provider_id,
                                $user_id
                            );

                            if (!$updateProvider->execute()) {
                                throw new Exception(
                                    "Could not update the provider status."
                                );
                            }

                            if ($updateProvider->affected_rows < 0) {
                                throw new Exception(
                                    "The provider status could not be confirmed."
                                );
                            }

                            $updateProvider->close();


                            /* 3. Notify the provider. */

                            $insertNotification = $conn->prepare(
                                "INSERT INTO notifications
                                 (user_id, title, message, type, is_read)
                                 VALUES (?, ?, ?, ?, 0)"
                            );

                            if (!$insertNotification) {
                                throw new Exception(
                                    "Could not prepare the notification."
                                );
                            }

                            $insertNotification->bind_param(
                                "isss",
                                $user_id,
                                $title,
                                $message,
                                $notification_type
                            );

                            if (!$insertNotification->execute()) {
                                throw new Exception(
                                    "Could not save the provider notification."
                                );
                            }

                            $insertNotification->close();


                            /* 4. Send the decision through Messages. */

                            $admin_id = (int) $_SESSION['user_id'];

                            $insertMessage = $conn->prepare(
                                "INSERT INTO messages
                                 (sender_id, receiver_id, booking_id, message, is_read)
                                 VALUES (?, ?, NULL, ?, 0)"
                            );

                            if (!$insertMessage) {
                                throw new Exception(
                                    "Could not prepare the provider message."
                                );
                            }

                            $insertMessage->bind_param(
                                "iis",
                                $admin_id,
                                $user_id,
                                $message
                            );

                            if (!$insertMessage->execute()) {
                                throw new Exception(
                                    "Could not save the decision message."
                                );
                            }

                            $insertMessage->close();


                            /* Save all changes together. */

                            $conn->commit();

                            $_SESSION['approval_success'] =
                                "The document decision was saved. The provider's status, notification and message were updated.";

                            header("Location: approval_decisions.php");
                            exit;

                        } catch (Throwable $exception) {

                            $conn->rollback();

                            error_log(
                                "HandyLink approval error: " .
                                $exception->getMessage()
                            );

                            $error = "The decision could not be saved. Check the PHP error log for details.";
                        }
                    }
                }
            }
        }
    }
}


/* =========================================
   LOAD ALL SUBMITTED VERIFICATION DOCUMENTS
========================================= */

/*
 * Deliberately load ALL submissions rather than only the latest
 * submission per provider, so the administrator can see every
 * document that has been submitted.
 */

$sql = "
    SELECT
        v.id,
        v.provider_id,
        v.document_type,
        v.document_number,
        v.document_file,
        v.status AS document_status,
        v.admin_comment,
        v.submitted_at,
        v.reviewed_at,

        p.user_id,
        p.verification_status AS provider_verification_status,

        u.full_name,
        u.email,
        u.phone,
        u.location,
        u.status AS account_status

    FROM verification v

    INNER JOIN providers p
        ON p.id = v.provider_id

    INNER JOIN users u
        ON u.id = p.user_id

    WHERE u.role = 'provider'

    ORDER BY
        CASE
            WHEN v.status = 'pending' THEN 0
            ELSE 1
        END,
        v.submitted_at DESC
";

$verifications = $conn->query($sql);

if (!$verifications) {
    error_log(
        "HandyLink verification list error: " . $conn->error
    );

    $error = "Unable to load verification submissions. Check the database connection and table structure.";
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Approval Decisions | HandyLink</title>

    <link
        rel="stylesheet"
        href="../assets/css/style.css"
    >

    <link
        rel="stylesheet"
        href="../assets/css/approval_decisions.css"
    >
</head>

<body>

<main class="approval-page">

    <header class="page-header">

        <div>
            <h1>Approval Decisions</h1>

            <p>
                Review provider documents and record verification decisions.
            </p>
        </div>

        <a
            href="dashboard.php"
            class="back-button"
        >
            Back to Dashboard
        </a>

    </header>


    <?php if ($success !== ''): ?>

        <div class="alert success-alert">
            <?= e($success) ?>
        </div>

    <?php endif; ?>


    <?php if ($error !== ''): ?>

        <div class="alert error-alert">
            <?= e($error) ?>
        </div>

    <?php endif; ?>


    <section class="approval-card">

        <div class="section-heading">

            <h2>Provider Verification Documents</h2>

            <p>
                All submitted documents are displayed below, including previous submissions.
            </p>

        </div>


        <div class="table-container">

            <table>

                <thead>
                    <tr>
                        <th>Provider</th>
                        <th>Submitted Document</th>
                        <th>Submission Date</th>
                        <th>Document Status</th>
                        <th>Administrator Comment</th>
                        <th>Decision</th>
                    </tr>
                </thead>

                <tbody>

                <?php if ($verifications && $verifications->num_rows > 0): ?>

                    <?php while ($v = $verifications->fetch_assoc()): ?>

                        <?php
                        $document_status = strtolower(
                            trim((string) ($v['document_status'] ?? 'pending'))
                        );

                        $document_status_label = ucfirst($document_status);

                        $account_status = strtolower(
                            trim((string) ($v['account_status'] ?? ''))
                        );
                        ?>

                        <tr>

                            <td>

                                <strong>
                                    <?= e($v['full_name'] ?? 'Unknown Provider') ?>
                                </strong>

                                <span class="provider-detail">
                                    <?= e($v['email'] ?? '') ?>
                                </span>

                                <span class="provider-detail">
                                    <?= e($v['phone'] ?? '') ?>
                                </span>

                                <span class="provider-detail">
                                    Account:
                                    <?= e(ucfirst($account_status ?: 'unknown')) ?>
                                </span>

                                <span class="provider-detail">
                                    Overall verification:
                                    <?= e(ucfirst(
                                        $v['provider_verification_status'] ?? 'pending'
                                    )) ?>
                                </span>

                            </td>


                            <td>

                                <strong>
                                    <?= e($v['document_type'] ?? 'Document') ?>
                                </strong>

                                <span class="provider-detail">
                                    Document number:
                                    <?= e(
                                        !empty($v['document_number'])
                                            ? $v['document_number']
                                            : 'Not provided'
                                    ) ?>
                                </span>

                                <?php if (!empty($v['document_file'])): ?>

                                    <?php
                                    /*
                                     * The upload path is stored relative
                                     * to the HandyLink project directory.
                                     */
                                    $stored_path = ltrim(
                                        (string) $v['document_file'],
                                        '/'
                                    );

                                    $document_url = '../' . $stored_path;
                                    ?>

                                    <a
                                        class="document-link"
                                        href="<?= e($document_url) ?>"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        View Submitted Document
                                    </a>

                                <?php else: ?>

                                    <span class="provider-detail">
                                        No file path recorded.
                                    </span>

                                <?php endif; ?>

                            </td>


                            <td>
                                <?= e($v['submitted_at'] ?? '') ?>
                            </td>


                            <td>

                                <span class="status-badge <?= e($document_status) ?>">
                                    <?= e($document_status_label) ?>
                                </span>

                                <?php if (!empty($v['reviewed_at'])): ?>

                                    <span class="provider-detail">
                                        Reviewed:
                                        <?= e($v['reviewed_at']) ?>
                                    </span>

                                <?php endif; ?>

                            </td>


                            <td>
                                <?= !empty($v['admin_comment'])
                                    ? nl2br(e($v['admin_comment']))
                                    : 'No comment yet' ?>
                            </td>


                            <td>

                                <?php if ($account_status !== 'active'): ?>

                                    <p class="blocked-note">
                                        This account is not active. Activate it before approving verification.
                                    </p>

                                <?php endif; ?>


                                <form
                                    method="POST"
                                    action="approval_decisions.php"
                                    class="decision-form"
                                    onsubmit="return confirm('Save this decision and notify the provider?');"
                                >

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= e($_SESSION['csrf_token']) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="verification_id"
                                        value="<?= (int) $v['id'] ?>"
                                    >

                                    <label
                                        for="comment-<?= (int) $v['id'] ?>"
                                    >
                                        Administrator's comment
                                    </label>

                                    <textarea
                                        id="comment-<?= (int) $v['id'] ?>"
                                        name="admin_comment"
                                        rows="3"
                                        required
                                        placeholder="Explain your decision..."
                                    ><?= e($v['admin_comment'] ?? '') ?></textarea>

                                    <button
                                        type="submit"
                                        name="decision"
                                        value="approved"
                                        class="decision-button approve-button"
                                        <?= $account_status !== 'active' ? 'disabled' : '' ?>
                                    >
                                        Approve
                                    </button>

                                    <button
                                        type="submit"
                                        name="decision"
                                        value="rejected"
                                        class="decision-button reject-button"
                                    >
                                        Reject
                                    </button>

                                </form>

                            </td>

                        </tr>

                    <?php endwhile; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="6" class="empty-state">
                            No verification documents have been submitted yet.
                        </td>
                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

</main>

</body>
</html>
