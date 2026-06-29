<?php
include('config/db.php');
include('config/auth.php');
include('config/helpers.php');

$admin_id = requireAdmin();

try {
    // Platform-wide stats
    $stats_stmt = $conn->query(
        'SELECT
            COUNT(DISTINCT u.id)                                                        AS total_users,
            COALESCE(SUM(u.balance), 0)                                                AS total_balances,
            COUNT(t.id)                                                                 AS total_transactions,
            COALESCE(SUM(CASE WHEN t.type = \'deposit\'    THEN t.amount ELSE 0 END), 0) AS total_deposits,
            COALESCE(SUM(CASE WHEN t.type = \'withdrawal\' THEN t.amount ELSE 0 END), 0) AS total_withdrawals
        FROM users u
        LEFT JOIN transactions t ON t.user_id = u.id'
    );
    $stats = $stats_stmt->fetch();

    // 10 most recent users
    $users_stmt = $conn->query(
        'SELECT id, fullname, email, phone, balance, is_verified, is_admin, created_at
         FROM users
         ORDER BY created_at DESC
         LIMIT 10'
    );
    $recent_users = $users_stmt->fetchAll();

    // 10 most recent transactions (with user name)
    $tx_stmt = $conn->query(
        'SELECT t.id, t.type, t.amount, t.description, t.status, t.reference, t.created_at,
                u.fullname, u.email
         FROM transactions t
         JOIN users u ON u.id = t.user_id
         ORDER BY t.created_at DESC
         LIMIT 10'
    );
    $recent_tx = $tx_stmt->fetchAll();

} catch (PDOException $e) {
    error_log('Admin dashboard error: ' . $e->getMessage());
    die('Error loading admin dashboard. Please try again.');
}

// Fetch admin name for navbar
$admin_stmt = $conn->prepare('SELECT fullname FROM users WHERE id = :id');
$admin_stmt->execute([':id' => $admin_id]);
$admin = $admin_stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Softlink Broker</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        /* ── Layout ─────────────────────────────────────────── */
        .admin-wrap {
            display: grid;
            grid-template-columns: 220px 1fr;
            min-height: calc(100vh - 60px);
        }
        .sidebar {
            background: #1a1d2e;
            padding: 24px 0;
            position: sticky;
            top: 0;
            height: calc(100vh - 60px);
            overflow-y: auto;
        }
        .sidebar-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 0 20px 24px;
            border-bottom: 1px solid rgba(255,255,255,.08);
            margin-bottom: 16px;
        }
        .sidebar-brand span {
            font-size: 13px;
            font-weight: 700;
            color: #a78bfa;
            text-transform: uppercase;
            letter-spacing: .08em;
        }
        .sidebar nav a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 11px 20px;
            color: #94a3b8;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: background .2s, color .2s;
        }
        .sidebar nav a:hover,
        .sidebar nav a.active {
            background: rgba(167,139,250,.12);
            color: #a78bfa;
        }
        .sidebar nav a .icon { font-size: 16px; width: 20px; text-align: center; }
        .sidebar-divider {
            border: none;
            border-top: 1px solid rgba(255,255,255,.07);
            margin: 12px 0;
        }

        /* ── Main area ───────────────────────────────────────── */
        .main {
            background: #f1f5f9;
            padding: 28px 32px;
            overflow-y: auto;
        }
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 28px;
        }
        .page-header h1 {
            font-size: 22px;
            font-weight: 700;
            color: #1e293b;
            margin: 0;
        }
        .page-header p {
            color: #64748b;
            font-size: 13px;
            margin: 4px 0 0;
        }
        .admin-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #7c3aed;
            color: white;
            font-size: 12px;
            font-weight: 600;
            padding: 5px 12px;
            border-radius: 20px;
        }

        /* ── Stat cards ──────────────────────────────────────── */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 18px;
            margin-bottom: 28px;
        }
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px 22px;
            box-shadow: 0 1px 4px rgba(0,0,0,.06);
            display: flex;
            flex-direction: column;
            gap: 8px;
            border-top: 3px solid transparent;
        }
        .stat-card.purple { border-top-color: #7c3aed; }
        .stat-card.blue   { border-top-color: #2563eb; }
        .stat-card.green  { border-top-color: #16a34a; }
        .stat-card.red    { border-top-color: #dc2626; }
        .stat-card.amber  { border-top-color: #d97706; }
        .stat-card .label {
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: #64748b;
        }
        .stat-card .value {
            font-size: 26px;
            font-weight: 800;
            color: #1e293b;
            line-height: 1.1;
        }
        .stat-card .icon-wrap {
            font-size: 28px;
            opacity: .18;
            position: absolute;
            right: 18px;
            top: 18px;
        }
        .stat-card { position: relative; overflow: hidden; }

        /* ── Section cards ───────────────────────────────────── */
        .section-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 4px rgba(0,0,0,.06);
            margin-bottom: 24px;
            overflow: hidden;
        }
        .section-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 22px;
            border-bottom: 1px solid #f1f5f9;
        }
        .section-card-header h2 {
            font-size: 15px;
            font-weight: 700;
            color: #1e293b;
            margin: 0;
        }
        .section-card-header .count {
            font-size: 12px;
            color: #94a3b8;
        }

        /* ── Tables ──────────────────────────────────────────── */
        .admin-table {
            width: 100%;
            border-collapse: collapse;
        }
        .admin-table th {
            background: #f8fafc;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: #64748b;
            padding: 10px 22px;
            text-align: left;
            border-bottom: 1px solid #f1f5f9;
        }
        .admin-table td {
            padding: 13px 22px;
            font-size: 13px;
            color: #334155;
            border-bottom: 1px solid #f8fafc;
            vertical-align: middle;
        }
        .admin-table tbody tr:last-child td { border-bottom: none; }
        .admin-table tbody tr:hover td { background: #f8fafc; }
        .empty-row td {
            text-align: center;
            color: #94a3b8;
            padding: 32px;
            font-size: 14px;
        }

        /* ── Pills / status ──────────────────────────────────── */
        .pill {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: capitalize;
        }
        .pill-deposit    { background: #dcfce7; color: #15803d; }
        .pill-withdrawal { background: #fee2e2; color: #b91c1c; }
        .pill-trade      { background: #e0f2fe; color: #0369a1; }
        .pill-fee        { background: #fef9c3; color: #a16207; }
        .pill-completed  { background: #dcfce7; color: #15803d; }
        .pill-pending    { background: #fef9c3; color: #a16207; }
        .pill-failed     { background: #fee2e2; color: #b91c1c; }
        .pill-admin      { background: #ede9fe; color: #6d28d9; }
        .pill-user       { background: #f1f5f9; color: #475569; }
        .pill-verified   { background: #dcfce7; color: #15803d; }
        .pill-unverified { background: #fef9c3; color: #a16207; }

        /* ── Avatar initials ──────────────────────────────────── */
        .avatar {
            width: 32px; height: 32px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            font-size: 13px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .user-cell {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .user-cell-info { line-height: 1.3; }
        .user-cell-name { font-weight: 600; color: #1e293b; font-size: 13px; }
        .user-cell-email { font-size: 11px; color: #94a3b8; }

        /* ── Responsive ───────────────────────────────────────── */
        @media (max-width: 900px) {
            .admin-wrap { grid-template-columns: 1fr; }
            .sidebar { display: none; }
            .main { padding: 20px 16px; }
        }
    </style>
</head>
<body>

<!-- Top navbar -->
<header class="navbar">
    <h2>🏦 Softlink Broker</h2>
    <nav>
        <span style="color:rgba(255,255,255,.75); font-size:14px;">
            👤 <?php echo htmlspecialchars($admin['fullname']); ?>
            &nbsp;·&nbsp;
        </span>
        <a href="dashboard.php">User Dashboard</a>
        <a href="config/logout.php">Logout</a>
    </nav>
</header>

<div class="admin-wrap">

    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-brand">
            <span>⚙️ &nbsp;Admin Panel</span>
        </div>
        <nav>
            <a href="admin_dashboard.php" class="active">
                <span class="icon">📊</span> Overview
            </a>
            <hr class="sidebar-divider">
            <a href="dashboard.php">
                <span class="icon">👤</span> My Dashboard
            </a>
            <a href="deposit.php">
                <span class="icon">💰</span> Deposit
            </a>
            <a href="withdraw.php">
                <span class="icon">🏦</span> Withdraw
            </a>
            <hr class="sidebar-divider">
            <a href="config/logout.php">
                <span class="icon">🚪</span> Logout
            </a>
        </nav>
    </aside>

    <!-- Main content -->
    <main class="main">

        <!-- Page header -->
        <div class="page-header">
            <div>
                <h1>Admin Dashboard</h1>
                <p>Platform overview &amp; recent activity</p>
            </div>
            <span class="admin-badge">🔐 Administrator</span>
        </div>

        <!-- Stat cards -->
        <div class="stats-grid">
            <div class="stat-card purple">
                <div class="icon-wrap">👥</div>
                <div class="label">Total Users</div>
                <div class="value"><?php echo number_format($stats['total_users']); ?></div>
            </div>
            <div class="stat-card amber">
                <div class="icon-wrap">💳</div>
                <div class="label">Total Balances</div>
                <div class="value"><?php echo formatCurrency($stats['total_balances']); ?></div>
            </div>
            <div class="stat-card blue">
                <div class="icon-wrap">🔄</div>
                <div class="label">Transactions</div>
                <div class="value"><?php echo number_format($stats['total_transactions']); ?></div>
            </div>
            <div class="stat-card green">
                <div class="icon-wrap">📥</div>
                <div class="label">Total Deposits</div>
                <div class="value"><?php echo formatCurrency($stats['total_deposits']); ?></div>
            </div>
            <div class="stat-card red">
                <div class="icon-wrap">📤</div>
                <div class="label">Total Withdrawals</div>
                <div class="value"><?php echo formatCurrency($stats['total_withdrawals']); ?></div>
            </div>
        </div>

        <!-- Recent users -->
        <div class="section-card">
            <div class="section-card-header">
                <h2>👥 Recent Users</h2>
                <span class="count">Last 10 registered</span>
            </div>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Phone</th>
                        <th>Balance</th>
                        <th>Role</th>
                        <th>Verified</th>
                        <th>Joined</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recent_users)): ?>
                        <tr class="empty-row"><td colspan="6">No users registered yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($recent_users as $u): ?>
                        <tr>
                            <td>
                                <div class="user-cell">
                                    <div class="avatar">
                                        <?php echo strtoupper(mb_substr($u['fullname'], 0, 1)); ?>
                                    </div>
                                    <div class="user-cell-info">
                                        <div class="user-cell-name"><?php echo htmlspecialchars($u['fullname']); ?></div>
                                        <div class="user-cell-email"><?php echo htmlspecialchars($u['email']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($u['phone'] ?? '—'); ?></td>
                            <td style="font-weight:600;"><?php echo formatCurrency($u['balance']); ?></td>
                            <td>
                                <?php if ($u['is_admin']): ?>
                                    <span class="pill pill-admin">Admin</span>
                                <?php else: ?>
                                    <span class="pill pill-user">User</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($u['is_verified']): ?>
                                    <span class="pill pill-verified">✓ Verified</span>
                                <?php else: ?>
                                    <span class="pill pill-unverified">Pending</span>
                                <?php endif; ?>
                            </td>
                            <td style="color:#64748b; font-size:12px;"><?php echo formatDate($u['created_at']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Recent transactions -->
        <div class="section-card">
            <div class="section-card-header">
                <h2>🔄 Recent Transactions</h2>
                <span class="count">Last 10 across all users</span>
            </div>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Type</th>
                        <th>Amount</th>
                        <th>Description</th>
                        <th>Status</th>
                        <th>Reference</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recent_tx)): ?>
                        <tr class="empty-row"><td colspan="7">No transactions yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($recent_tx as $tx): ?>
                        <tr>
                            <td>
                                <div class="user-cell">
                                    <div class="avatar" style="width:28px;height:28px;font-size:11px;">
                                        <?php echo strtoupper(mb_substr($tx['fullname'], 0, 1)); ?>
                                    </div>
                                    <div class="user-cell-info">
                                        <div class="user-cell-name"><?php echo htmlspecialchars($tx['fullname']); ?></div>
                                        <div class="user-cell-email"><?php echo htmlspecialchars($tx['email']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td><span class="pill pill-<?php echo $tx['type']; ?>"><?php echo ucfirst($tx['type']); ?></span></td>
                            <td style="font-weight:700;"><?php echo formatCurrency($tx['amount']); ?></td>
                            <td style="color:#64748b;"><?php echo htmlspecialchars($tx['description'] ?? '—'); ?></td>
                            <td><span class="pill pill-<?php echo $tx['status']; ?>"><?php echo ucfirst($tx['status']); ?></span></td>
                            <td style="font-size:11px; color:#94a3b8; font-family:monospace;"><?php echo htmlspecialchars($tx['reference'] ?? '—'); ?></td>
                            <td style="color:#64748b; font-size:12px;"><?php echo formatDate($tx['created_at']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </main>
</div>

</body>
</html>
