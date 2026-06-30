<?php
/**
 * Handles POST actions on notifications:
 * mark_read | mark_unread | mark_all_read | archive | unarchive
 */
include('config/db.php');
include('config/auth.php');

$admin_id = requireAdmin();

$action   = $_POST['action']   ?? '';
$notif_id = (int)($_POST['id'] ?? 0);
$redirect = $_POST['redirect'] ?? 'admin_notifications.php';

// Whitelist redirect
if (!str_starts_with($redirect, 'admin_notifications.php')) {
    $redirect = 'admin_notifications.php';
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
            $conn->prepare("UPDATE admin_notifications SET is_read = TRUE, read_at = CURRENT_TIMESTAMP, read_by = :admin WHERE id = :id")
                 ->execute([':admin' => $admin_id, ':id' => $notif_id]);
            back($redirect, 'success', 'Notification marked as read.');

        case 'mark_unread':
            if (!$notif_id) back($redirect, 'error', 'Invalid notification.');
            $conn->prepare("UPDATE admin_notifications SET is_read = FALSE, read_at = NULL, read_by = NULL WHERE id = :id")
                 ->execute([':id' => $notif_id]);
            back($redirect, 'success', 'Notification marked as unread.');

        case 'mark_all_read':
            $conn->prepare("UPDATE admin_notifications SET is_read = TRUE, read_at = CURRENT_TIMESTAMP, read_by = :admin WHERE is_read = FALSE AND is_archived = FALSE")
                 ->execute([':admin' => $admin_id]);
            back($redirect, 'success', 'All notifications marked as read.');

        case 'archive':
            if (!$notif_id) back($redirect, 'error', 'Invalid notification.');
            $conn->prepare("UPDATE admin_notifications SET is_archived = TRUE WHERE id = :id")
                 ->execute([':id' => $notif_id]);
            back($redirect, 'success', 'Notification archived.');

        case 'unarchive':
            if (!$notif_id) back($redirect, 'error', 'Invalid notification.');
            $conn->prepare("UPDATE admin_notifications SET is_archived = FALSE WHERE id = :id")
                 ->execute([':id' => $notif_id]);
            back($redirect, 'success', 'Notification restored.');

        default:
            back($redirect, 'error', 'Unknown action.');
    }
} catch (PDOException $e) {
    error_log('Notification action error: ' . $e->getMessage());
    back($redirect, 'error', 'Database error. Please try again.');
}
