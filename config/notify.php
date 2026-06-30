<?php
/**
 * Notification helper — call createNotification() anywhere after $conn is available.
 * Failures are silently logged so they never break the calling flow.
 */

function createNotification(PDO $conn, array $opts): void {
    try {
        $stmt = $conn->prepare("
            INSERT INTO admin_notifications
                (event_type, category, priority, title, description,
                 user_id, transaction_id, adjustment_id, reference)
            VALUES
                (:event_type, :category, :priority, :title, :description,
                 :user_id, :transaction_id, :adjustment_id, :reference)
        ");
        $stmt->execute([
            ':event_type'     => $opts['event_type'],
            ':category'       => $opts['category']       ?? 'general',
            ':priority'       => $opts['priority']       ?? 'medium',
            ':title'          => $opts['title'],
            ':description'    => $opts['description'],
            ':user_id'        => $opts['user_id']        ?? null,
            ':transaction_id' => $opts['transaction_id'] ?? null,
            ':adjustment_id'  => $opts['adjustment_id']  ?? null,
            ':reference'      => $opts['reference']      ?? null,
        ]);
    } catch (PDOException $e) {
        error_log('Notification create error: ' . $e->getMessage());
    }
}

function getUnreadNotifCount(PDO $conn): int {
    try {
        return (int)$conn->query(
            "SELECT COUNT(*) FROM admin_notifications WHERE is_read = FALSE AND is_archived = FALSE"
        )->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}
