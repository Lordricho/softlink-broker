<?php
/**
 * JSON endpoint — returns the current user's unread notification count.
 * Polled every 30 s by the user-facing JS badge.
 */
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

session_start();

if (empty($_SESSION['user_id'])) {
    echo json_encode(['unread' => 0, 'important' => 0, 'latest' => []]);
    exit();
}

$uid = (int)$_SESSION['user_id'];

try {
    include('../config/db.php');

    $s = $conn->prepare("SELECT COUNT(*) FROM user_notifications WHERE user_id = :uid AND is_read = FALSE AND is_deleted = FALSE");
    $s->execute([':uid' => $uid]);
    $unread = (int)$s->fetchColumn();

    $s2 = $conn->prepare("SELECT COUNT(*) FROM user_notifications WHERE user_id = :uid AND priority IN ('high','critical') AND is_read = FALSE AND is_deleted = FALSE");
    $s2->execute([':uid' => $uid]);
    $important = (int)$s2->fetchColumn();

    $s3 = $conn->prepare("SELECT id, title, priority, event_type, created_at FROM user_notifications WHERE user_id = :uid AND is_read = FALSE AND is_deleted = FALSE ORDER BY created_at DESC LIMIT 5");
    $s3->execute([':uid' => $uid]);
    $latest = $s3->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['unread' => $unread, 'important' => $important, 'latest' => $latest]);
} catch (Throwable $e) {
    echo json_encode(['unread' => 0, 'important' => 0, 'latest' => [], 'error' => true]);
}
