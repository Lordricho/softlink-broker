<?php
/**
 * Handles user notification POST actions:
 * mark_read | mark_all_read | delete | delete_all_read
 */
include('config/db.php');
include('config/auth.php');

$user_id  = requireAuth();

$action   = $_POST['action']   ?? '';
$notif_id = (int)($_POST['id'] ?? 0);
$redirect = $_POST['redirect'] ?? 'user_notifications.php';

// Whitelist redirect
if (!preg_match('/^(user_notifications\.php|dashboard\.php)/', $redirect)) {
    $redirect = 'user_notifications.php';
}

function back(string $redirect, string $key, string $msg): never {
    $sep = str_contains($redirect, '?') ? '&' : '?';
    header('Location: ' . $redirect . $sep . $key . '=' . urlencode($msg));
    exit();
}

try {
    switch ($action) {

        case 'mark_read':
            if (!$notif_id) back($redirect, 'error', 'Invalid notification.');
            $conn->prepare("UPDATE user_notifications SET is_read = TRUE, read_at = CURRENT_TIMESTAMP WHERE id = :id AND user_id = :uid")
                 ->execute([':id' => $notif_id, ':uid' => $user_id]);
            back($redirect, 'success', 'Notification marked as read.');

        case 'mark_all_read':
            $conn->prepare("UPDATE user_notifications SET is_read = TRUE, read_at = CURRENT_TIMESTAMP WHERE user_id = :uid AND is_read = FALSE AND is_deleted = FALSE")
                 ->execute([':uid' => $user_id]);
            back($redirect, 'success', 'All notifications marked as read.');

        case 'delete':
            if (!$notif_id) back($redirect, 'error', 'Invalid notification.');
            $conn->prepare("UPDATE user_notifications SET is_deleted = TRUE WHERE id = :id AND user_id = :uid")
                 ->execute([':id' => $notif_id, ':uid' => $user_id]);
            back($redirect, 'success', 'Notification deleted.');

        case 'delete_all_read':
            $conn->prepare("UPDATE user_notifications SET is_deleted = TRUE WHERE user_id = :uid AND is_read = TRUE")
                 ->execute([':uid' => $user_id]);
            back($redirect, 'success', 'All read notifications deleted.');

        default:
            back($redirect, 'error', 'Unknown action.');
    }
} catch (PDOException $e) {
    error_log('User notification action error: ' . $e->getMessage());
    back($redirect, 'error', 'Database error. Please try again.');
}
