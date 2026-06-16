<?php
include('config/db.php');
include('config/auth.php');
include('config/helpers.php');

// Require authentication
$user_id = requireAuth();

try {
    // Get user details
    $user_stmt = $conn->prepare('SELECT id, fullname, email, phone, created_at, balance FROM users WHERE id = :id');
    $user_stmt->execute([':id' => $user_id]);
    $user = $user_stmt->fetch();

    if (!$user) {
        session_destroy();
        header('Location: login.php');
        exit();
    }

    // Get recent transactions
    $tx_stmt = $conn->prepare(
        'SELECT type, amount, description, created_at FROM transactions '
        . 'WHERE user_id = :user_id '
        . 'ORDER BY created_at DESC LIMIT 10'
    );
    $tx_stmt->execute([':user_id' => $user_id]);
    $transactions = $tx_stmt->fetchAll();

    // Get account statistics
    $stats_stmt = $conn->prepare(
        'SELECT COUNT(*) as tx_count, '
        . 'COALESCE(SUM(CASE WHEN type=\'deposit\' THEN amount ELSE 0 END), 0) as total_deposits, '
        . 'COALESCE(SUM(CASE WHEN type=\'withdrawal\' THEN amount ELSE 0 END), 0) as total_withdrawals '
        . 'FROM transactions WHERE user_id = :user_id'
    );
    $stats_stmt->execute([':user_id' => $user_id]);
    $stats = $stats_stmt->fetch();
} catch (PDOException $e) {
    error_log('Dashboard error: ' . $e->getMessage());
    die('Error loading dashboard. Please try again.');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Softlink Broker</title>
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
        .sidebar h3 {
            margin-top: 0;
            color: #333;
        }
        .sidebar ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .sidebar ul li {
            margin: 10px 0;
        }
        .sidebar ul li a {
            color: #007bff;
            text-decoration: none;
            display: block;
            padding: 8px;
            border-radius: 4px;
        }
        .sidebar ul li a:hover {
            background: #e9ecef;
        }
        .main-content {
            background: white;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #f0f0f0;
        }
        .header h1 {
            margin: 0;
            color: #333;
        }
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
        .logout-btn:hover {
            background: #c82333;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
        }
        .stat-card h3 {
            margin: 0 0 10px 0;
            font-size: 14px;
            opacity: 0.9;
        }
        .stat-card .value {
            font-size: 28px;
            font-weight: bold;
        }
        .section {
            margin-bottom: 30px;
        }
        .section h2 {
            color: #333;
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .user-info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .user-info p {
            margin: 8px 0;
            color: #666;
        }
        .user-info strong {
            color: #333;
        }
        .transactions-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        .transactions-table th,
        .transactions-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #f0f0f0;
        }
        .transactions-table th {
            background: #f8f9fa;
            font-weight: bold;
            color: #333;
        }
        .transactions-table tr:hover {
            background: #f8f9fa;
        }
        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
        }
        .badge.deposit {
            background: #d4edda;
            color: #155724;
        }
        .badge.withdrawal {
            background: #f8d7da;
            color: #721c24;
        }
        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        .btn {
            background: #007bff;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        .btn:hover {
            background: #0056b3;
        }
        .btn.secondary {
            background: #6c757d;
        }
        .btn.secondary:hover {
            background: #5a6268;
        }
        @media (max-width: 768px) {
            .dashboard-container {
                grid-template-columns: 1fr;
            }
            .stats-grid {
                grid-template-columns: 1fr;
            }
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
    <!-- Sidebar -->
    <aside class="sidebar">
        <h3>Navigation</h3>
        <ul>
            <li><a href="dashboard.php">📊 Dashboard</a></li>
            <li><a href="#deposit">💰 Deposit Funds</a></li>
            <li><a href="#withdraw">🏦 Withdraw</a></li>
            <li><a href="#settings">⚙️ Settings</a></li>
            <li><a href="config/logout.php">🚪 Logout</a></li>
        </ul>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Header -->
        <div class="header">
            <div>
                <h1>Dashboard</h1>
                <p style="margin: 5px 0 0 0; color: #999;">Welcome back to Softlink Broker</p>
            </div>
            <a href="config/logout.php" class="logout-btn">Logout</a>
        </div>

        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <h3>Account Balance</h3>
                <div class="value"><?php echo formatCurrency($user['balance'] ?? 0); ?></div>
            </div>
            <div class="stat-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                <h3>Total Transactions</h3>
                <div class="value"><?php echo $stats['tx_count'] ?? 0; ?></div>
            </div>
            <div class="stat-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                <h3>Total Deposits</h3>
                <div class="value"><?php echo formatCurrency($stats['total_deposits'] ?? 0); ?></div>
            </div>
            <div class="stat-card" style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);">
                <h3>Total Withdrawals</h3>
                <div class="value"><?php echo formatCurrency($stats['total_withdrawals'] ?? 0); ?></div>
            </div>
        </div>

        <!-- User Information Section -->
        <div class="section">
            <h2>Account Information</h2>
            <div class="user-info">
                <p><strong>Full Name:</strong> <?php echo htmlspecialchars($user['fullname']); ?></p>
                <p><strong>Email:</strong> <?php echo htmlspecialchars($user['email']); ?></p>
                <p><strong>Phone:</strong> <?php echo htmlspecialchars($user['phone'] ?? 'Not provided'); ?></p>
                <p><strong>Member Since:</strong> <?php echo formatDate($user['created_at']); ?></p>
            </div>
            <div class="action-buttons">
                <a href="#" class="btn">Edit Profile</a>
                <a href="#" class="btn secondary">Change Password</a>
            </div>
        </div>

        <!-- Recent Transactions -->
        <div class="section">
            <h2>Recent Transactions</h2>
            <?php if (!empty($transactions)): ?>
                <table class="transactions-table">
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Amount</th>
                            <th>Description</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $tx): ?>
                            <tr>
                                <td><span class="badge <?php echo $tx['type']; ?>"><?php echo ucfirst($tx['type']); ?></span></td>
                                <td><?php echo formatCurrency($tx['amount']); ?></td>
                                <td><?php echo htmlspecialchars($tx['description'] ?? 'N/A'); ?></td>
                                <td><?php echo formatDate($tx['created_at']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p style="color: #999; text-align: center; padding: 20px;">No transactions yet. Make your first deposit to get started!</p>
            <?php endif; ?>
        </div>
    </main>
</div>

</body>
</html>
