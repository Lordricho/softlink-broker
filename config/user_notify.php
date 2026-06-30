<?php
/**
 * User notification helper.
 * Call createUserNotification() anywhere after $conn is available.
 * Failures are silently logged so they never break the calling flow.
 */

function createUserNotification(PDO $conn, array $opts): void {
    try {
        $stmt = $conn->prepare("
            INSERT INTO user_notifications
                (user_id, event_type, priority, title, message, transaction_id)
            VALUES
                (:user_id, :event_type, :priority, :title, :message, :transaction_id)
        ");
        $stmt->execute([
            ':user_id'        => $opts['user_id'],
            ':event_type'     => $opts['event_type'],
            ':priority'       => $opts['priority']       ?? 'low',
            ':title'          => $opts['title'],
            ':message'        => $opts['message'],
            ':transaction_id' => $opts['transaction_id'] ?? null,
        ]);
    } catch (PDOException $e) {
        error_log('User notification create error: ' . $e->getMessage());
    }
}

function getUserUnreadCount(PDO $conn, int $user_id): int {
    try {
        return (int)$conn->prepare(
            "SELECT COUNT(*) FROM user_notifications WHERE user_id = :uid AND is_read = FALSE AND is_deleted = FALSE"
        )->execute([':uid' => $user_id])
            ? $conn->prepare("SELECT COUNT(*) FROM user_notifications WHERE user_id = :uid AND is_read = FALSE AND is_deleted = FALSE")->execute([':uid' => $user_id])
            : 0;
    } catch (PDOException $e) {
        return 0;
    }
}

function getUserUnreadCountDirect(PDO $conn, int $user_id): int {
    try {
        $s = $conn->prepare("SELECT COUNT(*) FROM user_notifications WHERE user_id = :uid AND is_read = FALSE AND is_deleted = FALSE");
        $s->execute([':uid' => $user_id]);
        return (int)$s->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}
