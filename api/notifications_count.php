<?php
/**
 * JSON endpoint for real-time notification badge polling.
 * Returns unread count + the 5 latest unread notification titles.
 */
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

session_start();

if (empty($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
    echo json_encode(['unread' => 0, 'critical' => 0, 'latest' => []]);
    exit();
}

try {
    include('../config/db.php');

    $unread = (int)$conn->query(
        "SELECT COUNT(*) FROM admin_notifications WHERE is_read = FALSE AND is_archived = FALSE"
    )->fetchColumn();

    $critical = (int)$conn->query(
        "SELECT COUNT(*) FROM admin_notifications WHERE priority = 'critical' AND is_read = FALSE AND is_archived = FALSE"
    )->fetchColumn();

    $latest_stmt = $conn->query(
        "SELECT id, title, priority, category, created_at
         FROM admin_notifications
         WHERE is_read = FALSE AND is_archived = FALSE
         ORDER BY created_at DESC LIMIT 5"
    );
    $latest = $latest_stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'unread'   => $unread,
        'critical' => $critical,
        'latest'   => $latest,
    ]);
} catch (Throwable $e) {
    echo json_encode(['unread' => 0, 'critical' => 0, 'latest' => [], 'error' => true]);
}
