<?php
include('config/db.php');
include('config/auth.php');
include('config/helpers.php');

$user_id = requireAuth();

$message = '';
$message_type = '';

// Fetch current user balance
try {
    $user_stmt = $conn->prepare('SELECT fullname, balance FROM users WHERE id = :id');
    $user_stmt->execute([':id' => $user_id]);
    $user = $user_stmt->fetch();
    if (!$user) {
        session_destroy();
        header('Location: login.php');
        exit();
    }
} catch (PDOException $e) {
    error_log('Deposit page load error: ' . $e->getMessage());
    die('Error loading page. Please try again.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount = trim($_POST['amount'] ?? '');
    $description = sanitizeInput($_POST['description'] ?? 'Deposit');

    if (!is_numeric($amount) || $amount <= 0) {
        $message = 'Please enter a valid amount greater than zero.';
        $message_type = 'error';
    } elseif ($amount < 100) {
        $message = 'Minimum deposit amount is ₦100.';
        $message_type = 'error';
    } elseif ($amount > 10000000) {
        $message = 'Maximum deposit amount is ₦10,000,000.';
        $message_type = 'error';
    } else {
        try {
            $conn->beginTransaction();

            $reference = 'DEP-' . strtoupper(uniqid());

            $tx_stmt = $conn->prepare(
                'INSERT INTO transactions (user_id, type, amount, description, status, reference) '
                . 'VALUES (:user_id, \'deposit\', :amount, :description, \'completed\', :reference)'
            );
            $tx_stmt->execute([
                ':user_id'     => $user_id,
                ':amount'      => $amount,
                ':description' => $description ?: 'Deposit',
                ':reference'   => $reference,
            ]);

            $bal_stmt = $conn->prepare(
                'UPDATE users SET balance = balance + :amount WHERE id = :id'
            );
            $bal_stmt->execute([':amount' => $amount, ':id' => $user_id]);

            $conn->commit();

            // Notify the user
            include_once('config/user_notify.php');
            createUserNotification($conn, [
                'user_id'    => $user_id,
                'event_type' => 'deposit_confirmed',
                'priority'   => 'low',
                'title'      => '📥 Deposit Confirmed',
                'message'    => 'Your deposit of ' . formatCurrency($amount) . ' has been processed successfully. Reference: ' . $reference . '. Your updated balance reflects this transaction.',
            ]);

            // Notify admins
            include_once('config/notify.php');
            createNotification($conn, [
                'event_type'  => 'deposit_completed',
                'category'    => 'transactions',
                'priority'    => 'low',
                'title'       => 'Deposit Completed',
                'description' => $user['fullname'] . ' deposited ' . formatCurrency($amount) . '. Ref: ' . $reference,
                'user_id'     => $user_id,
                'reference'   => $reference,
            ]);

            // Refresh balance display
            $user['balance'] += $amount;

            $message = 'Deposit of ' . formatCurrency($amount) . ' was successful! Reference: ' . $reference;
            $message_type = 'success';
        } catch (PDOException $e) {
            $conn->rollBack();
            error_log('Deposit error: ' . $e->getMessage());
            $message = 'Deposit failed. Please try again.';
            $message_type = 'error';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deposit Funds - Softlink Broker</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .dashboard-container {
            display: grid;
            grid-template-columns: 1fr 3fr;
            gap: 20px;
            padding: 20px;
            max-width: 1200px;
            margin: 20px auto;
        }
        .sidebar {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            height: fit-content;
        }
        .sidebar h3 { margin-top: 0; color: #333; }
        .sidebar ul { list-style: none; padding: 0; margin: 0; }
        .sidebar ul li { margin: 10px 0; }
        .sidebar ul li a {
            color: #007bff;
            text-decoration: none;
            display: block;
            padding: 8px;
            border-radius: 4px;
        }
        .sidebar ul li a:hover { background: #e9ecef; }
        .sidebar ul li a.active {
            background: #007bff;
            color: white;
        }
        .main-content { background: white; }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #f0f0f0;
        }
        .header h1 { margin: 0; color: #333; }
        .logout-btn {
            background: #dc3545;
            color: white;
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        .logout-btn:hover { background: #c82333; }
        .balance-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px 25px;
            border-radius: 8px;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .balance-card h3 { margin: 0 0 6px 0; font-size: 14px; opacity: 0.9; }
        .balance-card .value { font-size: 32px; font-weight: bold; }
        .form-card {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 30px;
            max-width: 500px;
        }
        .form-card h2 { margin: 0 0 20px 0; color: #333; }
        .form-group { margin-bottom: 18px; }
        .form-group label {
            display: block;
            font-weight: bold;
            color: #555;
            margin-bottom: 6px;
            font-size: 14px;
        }
        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 11px 14px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
            font-size: 15px;
        }
        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 2px rgba(102,126,234,0.15);
        }
        .form-group textarea { resize: vertical; height: 80px; }
        .btn-submit {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            font-weight: bold;
            width: 100%;
        }
        .btn-submit:hover { opacity: 0.9; }
        .message {
            padding: 12px 16px;
            margin-bottom: 20px;
            border-radius: 4px;
            font-size: 14px;
        }
        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .hint {
            font-size: 12px;
            color: #888;
            margin-top: 4px;
        }
        @media (max-width: 768px) {
            .dashboard-container { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<header class="navbar">
    <h2>Softlink Broker</h2>
    <nav>
        <span style="color: #666;">Welcome, <?php echo htmlspecialchars($user['fullname']); ?></span>
    </nav>
</header>

<div class="dashboard-container">
    <aside class="sidebar">
        <h3>Navigation</h3>
        <ul>
            <li><a href="dashboard.php">📊 Dashboard</a></li>
            <li><a href="deposit.php" class="active">💰 Deposit Funds</a></li>
            <li><a href="withdraw.php">🏦 Withdraw</a></li>
            <li><a href="user_notifications.php">🔔 Notifications <span id="user-notif-badge" style="display:none;background:#dc2626;color:white;border-radius:10px;padding:1px 7px;font-size:10px;font-weight:800;margin-left:4px;vertical-align:middle;"></span></a></li>
            <li><a href="config/logout.php">🚪 Logout</a></li>
        </ul>
    </aside>

    <main class="main-content">
        <div class="header">
            <div>
                <h1>💰 Deposit Funds</h1>
                <p style="margin: 5px 0 0 0; color: #999;">Add money to your Softlink Broker account</p>
            </div>
            <a href="config/logout.php" class="logout-btn">Logout</a>
        </div>

        <div class="balance-card">
            <div>
                <h3>Current Balance</h3>
                <div class="value"><?php echo formatCurrency($user['balance']); ?></div>
            </div>
            <span style="font-size: 48px; opacity: 0.4;">💳</span>
        </div>

        <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div class="form-card">
            <h2>Make a Deposit</h2>
            <form method="POST" action="deposit.php">
                <div class="form-group">
                    <label for="amount">Amount (₦)</label>
                    <input
                        type="number"
                        id="amount"
                        name="amount"
                        placeholder="e.g. 5000"
                        min="100"
                        max="10000000"
                        step="0.01"
                        required
                    >
                    <p class="hint">Minimum: ₦100 &nbsp;|&nbsp; Maximum: ₦10,000,000</p>
                </div>
                <div class="form-group">
                    <label for="description">Description (optional)</label>
                    <textarea
                        id="description"
                        name="description"
                        placeholder="e.g. Initial funding, Top-up..."
                    ></textarea>
                </div>
                <button type="submit" class="btn-submit">Deposit Now</button>
            </form>
        </div>

        <p style="margin-top: 20px;">
            <a href="dashboard.php" style="color: #007bff; text-decoration: none;">← Back to Dashboard</a>
        </p>
    </main>
</div>

<script>
(function pollBadge() {
    fetch('api/user_notifications_count.php')
        .then(r => r.json())
        .then(d => {
            var b = document.getElementById('user-notif-badge');
            if (!b) return;
            if (d.unread > 0) { b.textContent = d.unread > 99 ? '99+' : d.unread; b.style.display = 'inline'; }
            else { b.style.display = 'none'; }
        }).catch(() => {});
    setTimeout(pollBadge, 30000);
})();
</script>
</body>
</html>
