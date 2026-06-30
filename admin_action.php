<?php
/**
 * Admin Action Handler
 * Handles all POST-based admin toggle actions and redirects back with a status message.
 */
include('config/db.php');
include('config/auth.php');
include_once('config/notify.php');

$admin_id = requireAdmin();

$action   = $_POST['action']   ?? '';
$user_id  = (int)($_POST['user_id'] ?? 0);
$redirect = $_POST['redirect'] ?? 'admin_users.php';

// Basic CSRF-lite: only allow known redirect targets
$allowed_redirects = ['admin_users.php', 'admin_user_profile.php'];
$redirect_base = strtok($redirect, '?');
if (!in_array($redirect_base, $allowed_redirects, true)) {
    $redirect = 'admin_users.php';
}

function fail(string $msg, string $back): void {
    header('Location: ' . $back . (str_contains($back, '?') ? '&' : '?') . 'error=' . urlencode($msg));
    exit();
}
function ok(string $msg, string $back): void {
    header('Location: ' . $back . (str_contains($back, '?') ? '&' : '?') . 'success=' . urlencode($msg));
    exit();
}

if (!$user_id) fail('Invalid user.', $redirect);

// Prevent admin from acting on themselves for destructive actions
$self_destructive = ['toggle_admin', 'toggle_suspended'];
if (in_array($action, $self_destructive, true) && $user_id === $admin_id) {
    fail('You cannot perform this action on your own account.', $redirect);
}

try {
    // Verify target user exists
    $check = $conn->prepare('SELECT id, fullname, is_admin, is_verified, is_suspended FROM users WHERE id = :id');
    $check->execute([':id' => $user_id]);
    $target = $check->fetch();
    if (!$target) fail('User not found.', $redirect);

    switch ($action) {

        case 'toggle_admin':
            $new_val = $target['is_admin'] ? 'FALSE' : 'TRUE';
            $conn->prepare("UPDATE users SET is_admin = {$new_val} WHERE id = :id")->execute([':id' => $user_id]);
            $label = $target['is_admin'] ? 'demoted from admin' : 'promoted to admin';
            createNotification($conn, [
                'event_type'  => 'admin_action_toggle_admin',
                'category'    => 'admin_actions',
                'priority'    => 'high',
                'title'       => 'Admin Role Changed',
                'description' => $target['fullname'] . ' has been ' . $label . ' by admin #' . $admin_id . '.',
                'user_id'     => $user_id,
            ]);
            ok(htmlspecialchars($target['fullname']) . ' has been ' . $label . '.', $redirect);

        case 'toggle_verified':
            $new_val = $target['is_verified'] ? 'FALSE' : 'TRUE';
            $conn->prepare("UPDATE users SET is_verified = {$new_val} WHERE id = :id")->execute([':id' => $user_id]);
            $label = $target['is_verified'] ? 'marked as unverified' : 'verified';
            createNotification($conn, [
                'event_type'  => 'admin_action_toggle_verified',
                'category'    => 'admin_actions',
                'priority'    => 'medium',
                'title'       => 'User Verification Changed',
                'description' => $target['fullname'] . ' has been ' . $label . ' by admin #' . $admin_id . '.',
                'user_id'     => $user_id,
            ]);
            ok(htmlspecialchars($target['fullname']) . ' has been ' . $label . '.', $redirect);

        case 'toggle_suspended':
            $new_val = $target['is_suspended'] ? 'FALSE' : 'TRUE';
            $conn->prepare("UPDATE users SET is_suspended = {$new_val} WHERE id = :id")->execute([':id' => $user_id]);
            $label        = $target['is_suspended'] ? 'reactivated' : 'suspended';
            $isSuspending = !$target['is_suspended'];
            createNotification($conn, [
                'event_type'  => 'admin_action_toggle_suspended',
                'category'    => $isSuspending ? 'security' : 'admin_actions',
                'priority'    => $isSuspending ? 'high'     : 'medium',
                'title'       => $isSuspending ? 'Account Suspended' : 'Account Reactivated',
                'description' => $target['fullname'] . '\'s account has been ' . $label . ' by admin #' . $admin_id . '.',
                'user_id'     => $user_id,
            ]);
            ok(htmlspecialchars($target['fullname']) . '\'s account has been ' . $label . '.', $redirect);

        default:
            fail('Unknown action.', $redirect);
    }

} catch (PDOException $e) {
    error_log('Admin action error: ' . $e->getMessage());
    fail('Database error. Please try again.', $redirect);
}
