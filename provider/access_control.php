<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';

global $conn;

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection error. Check config/database.php.');
}

/**
 * Confirm that the user is logged in as a provider
 * and that their account is active.
 */
function requireProviderLogin()
{
    global $conn;

    if (
        !isset($_SESSION['user_id']) ||
        !isset($_SESSION['role']) ||
        $_SESSION['role'] !== 'provider'
    ) {
        header('Location: ../login.php');
        exit;
    }

    $userId = (int) $_SESSION['user_id'];

    $stmt = $conn->prepare(
        "SELECT id, status
         FROM users
         WHERE id = ? AND role = 'provider'
         LIMIT 1"
    );

    if (!$stmt) {
        die('Unable to check provider account status.');
    }

    $stmt->bind_param("i", $userId);
    $stmt->execute();

    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (
        !$user ||
        strtolower(trim((string) $user['status'])) !== 'active'
    ) {
        header('Location: ../login.php');
        exit;
    }
}

/**
 * Return the provider's current approval status.
 */
function getProviderApprovalStatus()
{
    requireProviderLogin();

    global $conn;

    $userId = (int) $_SESSION['user_id'];

    $stmt = $conn->prepare(
        "SELECT id, verification_status
         FROM providers
         WHERE user_id = ?
         LIMIT 1"
    );

    if (!$stmt) {
        die('Unable to check provider approval status.');
    }

    $stmt->bind_param("i", $userId);
    $stmt->execute();

    $provider = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$provider) {
        return [
            'provider_id' => 0,
            'status' => 'pending',
            'approved' => false
        ];
    }

    $status = strtolower(
        trim((string) $provider['verification_status'])
    );

    return [
        'provider_id' => (int) $provider['id'],
        'status' => $status,
        'approved' => ($status === 'approved')
    ];
}

/**
 * Allow any logged-in, active provider to access verification.
 * Approval is not required to view this page.
 */
function requireVerificationAccess()
{
    requireProviderLogin();
}

/**
 * Protect operational pages.
 * Only approved providers can access them.
 */
function requireApprovedProvider()
{
    $provider = getProviderApprovalStatus();

    if (!$provider['approved']) {
        $_SESSION['provider_access_notice'] =
            'Your application must be approved by the administrator after your interview before you can access this page.';

        header('Location: job_vacancies.php');
        exit;
    }
}

/**
 * Protect application and interview pages.
 * Approved providers should no longer access these pages.
 */
function requirePendingProvider()
{
    $provider = getProviderApprovalStatus();

    if ($provider['approved']) {
        header('Location: dashboard.php');
        exit;
    }
}

/**
 * Allow both pending and approved providers to access notifications.
 */
function requireNotificationAccess()
{
    requireProviderLogin();
}