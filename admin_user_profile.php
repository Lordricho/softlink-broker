<?php
include('config/db.php');
include('config/auth.php');
include('config/helpers.php');

$admin_id = requireAdmin();

$target_id = (int)($_GET['id'] ?? 0);
if (!$target_id) { header('Location: admin_users.php'); exit(); }

// Flash messages
$flash      = $_GET['success'] ?? '';
$flash_type = 'success';
if (empty($flash)) { $flash = $_GET['error'] ?? ''; $flash_type = 'error'; }

try {
    // Full user profile
    $u_stmt = $conn->prepare(
        'SELECT id, fullname, email, phone, balance, is_admin, is_verified, is_suspended, created_at, updated_at
         FROM users WHERE id = :id'
    );
    $u_stmt->execute([':id' => $target_id]);
    $u = $u_stmt->fetch();
    if (!$u) { header('Location: admin_users.php?error=' . urlencode('User not found.')); exit(); }

    // Transaction stats for this user
    $ts_stmt = $conn->prepare(
        'SELECT COUNT(*) AS tx_count,
                COALESCE(SUM(CASE WHEN type=\'deposit\'    THEN amount ELSE 0 END),0) AS total_dep,
                COALESCE(SUM(CASE WHEN type=\'withdrawal\' THEN amount ELSE 0 END),0) AS total_wdr,
                COALESCE(SUM(CASE WHEN type=\'trade\'      THEN amount ELSE 0 END),0) AS total_trade,
                COALESCE(SUM(CASE WHEN type=\'fee\'        THEN amount ELSE 0 END),0) AS total_fee,
                COALESCE(SUM(CASE WHEN status=\'completed\' THEN amount ELSE 0 END),0) AS total_completed,
                COALESCE(SUM(CASE WHEN status=\'pending\'   THEN amount ELSE 0 END),0) AS total_pending
         FROM transactions WHERE user_id = :uid'
    );
    $ts_stmt->execute([':uid' => $target_id]);
    $tx_stats = $ts_stmt->fetch();

    // Full transaction history (paginated)
    $tx_page    = max(1, (int)($_GET['txpage'] ?? 1));
    $tx_per     = 25;
    $tx_offset  = ($tx_page - 1) * $tx_per;

    $tx_count_stmt = $conn->prepare('SELECT COUNT(*) FROM transactions WHERE user_id = :uid');
    $tx_count_stmt->execute([':uid' => $target_id]);
    $tx_total = (int)$tx_count_stmt->fetchColumn();
    $tx_pages  = max(1, ceil($tx_total / $tx_per));

    $tx_stmt = $conn->prepare(
        'SELECT id, type, amount, description, status, reference, created_at
         FROM transactions WHERE user_id = :uid
         ORDER BY created_at DESC
         LIMIT :lim OFFSET :off'
    );
    $tx_stmt->bindValue(':uid', $target_id, PDO::PARAM_INT);
    $tx_stmt->bindValue(':lim', $tx_per,    PDO::PARAM_INT);
    $tx_stmt->bindValue(':off', $tx_offset, PDO::PARAM_INT);
    $tx_stmt->execute();
    $transactions = $tx_stmt->fetchAll();

    // Login logs (last 10)
    $log_stmt = $conn->prepare(
        'SELECT login_time, ip_address, user_agent FROM login_logs
         WHERE user_id = :uid ORDER BY login_time DESC LIMIT 10'
    );
    $log_stmt->execute([':uid' => $target_id]);
    $login_logs = $log_stmt->fetchAll();

} catch (PDOException $e) {
    error_log('Admin user profile error: ' . $e->getMessage());
    die('Error loading user profile. Please try again.');
}

// Admin name
$me = $conn->prepare('SELECT fullname FROM users WHERE id = :id');
$me->execute([':id' => $admin_id]);
$admin = $me->fetch();

$back_url  = 'admin_user_profile.php?id=' . $target_id;
$back_enc  = urlencode($back_url);
$initials  = strtoupper(mb_substr($u['fullname'], 0, 2));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($u['fullname']); ?> — Admin Profile</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        <?php include('config/admin_styles.php'); ?>

        /* ── Profile hero ───────────────────────────────────── */
        .profile-hero {
            background: linear-gradient(135deg,#1a1d2e 0%,#2d2f4a 100%);
            border-radius: 14px;
            padding: 28px 32px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 24px;
            flex-wrap: wrap;
        }
        .hero-avatar {
            width: 72px; height: 72px;
            border-radius: 50%;
            background: linear-gradient(135deg,#7c3aed,#a78bfa);
            color: white;
            font-size: 26px;
            font-weight: 800;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
            border: 3px solid rgba(255,255,255,.15);
        }
        .hero-avatar.suspended { background: linear-gradient(135deg,#dc2626,#f87171); }
        .hero-info { flex: 1; }
        .hero-info h2 { margin:0 0 4px; color:white; font-size:22px; }
        .hero-info p  { margin:0; color:#94a3b8; font-size:13px; }
        .hero-badges  { display:flex; gap:8px; margin-top:10px; flex-wrap:wrap; }
        .hero-actions { display:flex; gap:10px; flex-wrap:wrap; align-self:flex-start; }

        /* ── Action buttons (hero area) ─────────────────────── */
        .hbtn {
            padding: 8px 18px;
            border: none;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            white-space: nowrap;
        }
        .hbtn-purple { background:#7c3aed; color:white; }
        .hbtn-purple:hover { background:#6d28d9; }
        .hbtn-amber  { background:#d97706; color:white; }
        .hbtn-amber:hover  { background:#b45309; }
        .hbtn-green  { background:#16a34a; color:white; }
        .hbtn-green:hover  { background:#15803d; }
        .hbtn-gray   { background:#475569; color:white; }
        .hbtn-gray:hover   { background:#334155; }
        .hbtn-red    { background:#dc2626; color:white; }
        .hbtn-red:hover    { background:#b91c1c; }

        /* ── Info grid ──────────────────────────────────────── */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 18px;
            margin-bottom: 24px;
        }
        .info-card {
            background: white;
            border-radius: 12px;
            padding: 20px 22px;
            box-shadow: 0 1px 4px rgba(0,0,0,.06);
        }
        .info-card h3 {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: #64748b;
            margin: 0 0 14px;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 7px 0;
            border-bottom: 1px solid #f8fafc;
            font-size: 13px;
        }
        .info-row:last-child { border-bottom: none; }
        .info-row .lbl { color: #64748b; }
        .info-row .val { font-weight: 600; color: #1e293b; text-align: right; }

        /* ── Tx history ─────────────────────────────────────── */
        .tx-filter {
            display: flex; gap: 8px; flex-wrap: wrap;
            padding: 14px 22px;
            border-bottom: 1px solid #f1f5f9;
        }
        .tx-pill-filter {
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
            background: #f1f5f9;
            color: #475569;
        }
        .tx-pill-filter:hover { background: #e2e8f0; }
        .tx-pill-filter.active { background: #7c3aed; color: white; }

        /* ── Pagination ─────────────────────────────────────── */
        .pagination { display:flex; gap:6px; margin-top:16px; justify-content:center; flex-wrap:wrap; padding-bottom:16px; }
        .pagination a, .pagination span {
            padding:6px 13px; border-radius:7px; font-size:13px; font-weight:600; text-decoration:none;
        }
        .pagination a { background:#f1f5f9; color:#475569; }
        .pagination a:hover { background:#e2e8f0; }
        .pagination .current { background:#7c3aed; color:white; }

        /* ── Login log ──────────────────────────────────────── */
        .log-row td { font-size:12px !important; }
    </style>
</head>
<body>
<header class="navbar">
    <h2>🏦 Softlink Broker</h2>
    <nav>
        <span style="color:rgba(255,255,255,.75);font-size:14px;">👤 <?php echo htmlspecialchars($admin['fullname']); ?> &nbsp;·&nbsp;</span>
        <a href="dashboard.php">User Dashboard</a>
        <a href="config/logout.php">Logout</a>
    </nav>
</header>

<div class="admin-wrap">
    <aside class="sidebar">
        <div class="sidebar-brand"><span>⚙️ &nbsp;Admin Panel</span></div>
        <nav>
            <a href="admin_dashboard.php"><span class="icon">📊</span> Overview</a>
            <a href="admin_users.php" class="active"><span class="icon">👥</span> User Management</a>
            <a href="admin_transactions.php"><span class="icon">🔄</span> Transactions</a>
            <hr class="sidebar-divider">
            <a href="dashboard.php"><span class="icon">👤</span> My Dashboard</a>
            <a href="deposit.php"><span class="icon">💰</span> Deposit</a>
            <a href="withdraw.php"><span class="icon">🏦</span> Withdraw</a>
            <hr class="sidebar-divider">
            <a href="config/logout.php"><span class="icon">🚪</span> Logout</a>
        </nav>
    </aside>

    <main class="main">
        <!-- Breadcrumb -->
        <div style="margin-bottom:18px;font-size:13px;color:#64748b;">
            <a href="admin_users.php" style="color:#7c3aed;text-decoration:none;">← User Management</a>
            &nbsp;/&nbsp; <?php echo htmlspecialchars($u['fullname']); ?>
        </div>

        <!-- Flash -->
        <?php if ($flash): ?>
        <div class="flash flash-<?php echo $flash_type; ?>"><?php echo htmlspecialchars($flash); ?></div>
        <?php endif; ?>

        <!-- Profile hero -->
        <div class="profile-hero">
            <div class="hero-avatar <?php echo $u['is_suspended'] ? 'suspended' : ''; ?>">
                <?php echo $initials; ?>
            </div>
            <div class="hero-info">
                <h2><?php echo htmlspecialchars($u['fullname']); ?></h2>
                <p><?php echo htmlspecialchars($u['email']); ?><?php echo $u['phone'] ? ' · ' . htmlspecialchars($u['phone']) : ''; ?></p>
                <div class="hero-badges">
                    <?php if ($u['is_admin']): ?>
                        <span class="pill pill-admin">🔐 Admin</span>
                    <?php else: ?>
                        <span class="pill pill-user">👤 User</span>
                    <?php endif; ?>
                    <?php if ($u['is_suspended']): ?>
                        <span class="pill pill-suspended">🚫 Suspended</span>
                    <?php elseif ($u['is_verified']): ?>
                        <span class="pill pill-verified">✓ Verified</span>
                    <?php else: ?>
                        <span class="pill pill-unverified">⏳ Unverified</span>
                    <?php endif; ?>
                </div>
            </div>
            <!-- Action buttons -->
            <div class="hero-actions">
                <!-- Promote / Demote -->
                <?php if ($target_id !== $admin_id): ?>
                <form method="POST" action="admin_action.php" onsubmit="return confirm('<?php echo $u['is_admin'] ? 'Demote to regular user?' : 'Promote to administrator?'; ?>')">
                    <input type="hidden" name="action"   value="toggle_admin">
                    <input type="hidden" name="user_id"  value="<?php echo $u['id']; ?>">
                    <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($back_url); ?>">
                    <button type="submit" class="hbtn <?php echo $u['is_admin'] ? 'hbtn-amber' : 'hbtn-purple'; ?>">
                        <?php echo $u['is_admin'] ? '⬇ Demote Admin' : '⬆ Make Admin'; ?>
                    </button>
                </form>
                <?php endif; ?>

                <!-- Verify / Unverify -->
                <form method="POST" action="admin_action.php">
                    <input type="hidden" name="action"   value="toggle_verified">
                    <input type="hidden" name="user_id"  value="<?php echo $u['id']; ?>">
                    <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($back_url); ?>">
                    <button type="submit" class="hbtn <?php echo $u['is_verified'] ? 'hbtn-gray' : 'hbtn-green'; ?>">
                        <?php echo $u['is_verified'] ? '✕ Unverify' : '✓ Verify'; ?>
                    </button>
                </form>

                <!-- Suspend / Reactivate -->
                <?php if ($target_id !== $admin_id): ?>
                <form method="POST" action="admin_action.php" onsubmit="return confirm('<?php echo $u['is_suspended'] ? 'Reactivate this account?' : 'Suspend this account?'; ?>')">
                    <input type="hidden" name="action"   value="toggle_suspended">
                    <input type="hidden" name="user_id"  value="<?php echo $u['id']; ?>">
                    <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($back_url); ?>">
                    <button type="submit" class="hbtn <?php echo $u['is_suspended'] ? 'hbtn-green' : 'hbtn-red'; ?>">
                        <?php echo $u['is_suspended'] ? '▶ Reactivate' : '🚫 Suspend'; ?>
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Info grid -->
        <div class="info-grid">
            <!-- Account details -->
            <div class="info-card">
                <h3>Account Details</h3>
                <div class="info-row"><span class="lbl">User ID</span>     <span class="val">#<?php echo $u['id']; ?></span></div>
                <div class="info-row"><span class="lbl">Full Name</span>   <span class="val"><?php echo htmlspecialchars($u['fullname']); ?></span></div>
                <div class="info-row"><span class="lbl">Email</span>       <span class="val"><?php echo htmlspecialchars($u['email']); ?></span></div>
                <div class="info-row"><span class="lbl">Phone</span>       <span class="val"><?php echo htmlspecialchars($u['phone'] ?: '—'); ?></span></div>
                <div class="info-row"><span class="lbl">Registered</span>  <span class="val"><?php echo formatDate($u['created_at']); ?></span></div>
                <div class="info-row"><span class="lbl">Last Updated</span><span class="val"><?php echo formatDate($u['updated_at']); ?></span></div>
            </div>

            <!-- Balance & transactions summary -->
            <div class="info-card">
                <h3>Balance & Activity</h3>
                <div class="info-row"><span class="lbl">Current Balance</span> <span class="val" style="color:#16a34a;font-size:15px;"><?php echo formatCurrency($u['balance']); ?></span></div>
                <div class="info-row"><span class="lbl">Total Transactions</span> <span class="val"><?php echo $tx_stats['tx_count']; ?></span></div>
                <div class="info-row"><span class="lbl">Total Deposited</span>  <span class="val" style="color:#16a34a;"><?php echo formatCurrency($tx_stats['total_dep']); ?></span></div>
                <div class="info-row"><span class="lbl">Total Withdrawn</span>  <span class="val" style="color:#dc2626;"><?php echo formatCurrency($tx_stats['total_wdr']); ?></span></div>
                <div class="info-row"><span class="lbl">Trade Volume</span>     <span class="val"><?php echo formatCurrency($tx_stats['total_trade']); ?></span></div>
                <div class="info-row"><span class="lbl">Total Fees</span>       <span class="val"><?php echo formatCurrency($tx_stats['total_fee']); ?></span></div>
            </div>

            <!-- Account status -->
            <div class="info-card">
                <h3>Account Status</h3>
                <div class="info-row"><span class="lbl">Role</span>
                    <span class="val"><?php echo $u['is_admin'] ? '<span class="pill pill-admin">Admin</span>' : '<span class="pill pill-user">User</span>'; ?></span>
                </div>
                <div class="info-row"><span class="lbl">Verification</span>
                    <span class="val">
                        <?php if ($u['is_verified']): ?>
                            <span class="pill pill-verified">✓ Verified</span>
                        <?php else: ?>
                            <span class="pill pill-unverified">Pending</span>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="info-row"><span class="lbl">Account State</span>
                    <span class="val">
                        <?php if ($u['is_suspended']): ?>
                            <span class="pill pill-suspended">🚫 Suspended</span>
                        <?php else: ?>
                            <span class="pill pill-verified">✓ Active</span>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="info-row"><span class="lbl">Completed Volume</span> <span class="val"><?php echo formatCurrency($tx_stats['total_completed']); ?></span></div>
                <div class="info-row"><span class="lbl">Pending Volume</span>   <span class="val"><?php echo formatCurrency($tx_stats['total_pending']); ?></span></div>
                <div class="info-row"><span class="lbl">Login Sessions</span>   <span class="val"><?php echo count($login_logs); ?>+ recorded</span></div>
            </div>
        </div>

        <!-- Full transaction history -->
        <div class="section-card" style="margin-bottom:24px;">
            <div class="section-card-header">
                <h2>🔄 Transaction History</h2>
                <span class="count"><?php echo number_format($tx_total); ?> total</span>
            </div>

            <?php if (empty($transactions)): ?>
                <p style="padding:32px;text-align:center;color:#94a3b8;">No transactions recorded for this user.</p>
            <?php else: ?>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Type</th>
                        <th>Amount</th>
                        <th>Description</th>
                        <th>Status</th>
                        <th>Reference</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $tx): ?>
                    <tr>
                        <td style="color:#94a3b8;"><?php echo $tx['id']; ?></td>
                        <td><span class="pill pill-<?php echo $tx['type']; ?>"><?php echo ucfirst($tx['type']); ?></span></td>
                        <td style="font-weight:700;color:<?php echo $tx['type']==='deposit'?'#16a34a':($tx['type']==='withdrawal'?'#dc2626':'#1e293b'); ?>;">
                            <?php echo $tx['type']==='withdrawal' ? '-' : '+'; ?><?php echo formatCurrency($tx['amount']); ?>
                        </td>
                        <td style="color:#64748b;"><?php echo htmlspecialchars($tx['description'] ?: '—'); ?></td>
                        <td><span class="pill pill-<?php echo $tx['status']; ?>"><?php echo ucfirst($tx['status']); ?></span></td>
                        <td style="font-size:11px;font-family:monospace;color:#94a3b8;"><?php echo htmlspecialchars($tx['reference'] ?: '—'); ?></td>
                        <td style="font-size:12px;color:#64748b;white-space:nowrap;"><?php echo formatDate($tx['created_at']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Tx pagination -->
            <?php if ($tx_pages > 1): ?>
            <div class="pagination">
                <?php if ($tx_page > 1): ?>
                    <a href="?id=<?php echo $target_id; ?>&txpage=<?php echo $tx_page-1; ?>">← Prev</a>
                <?php endif; ?>
                <?php for ($p = max(1,$tx_page-2); $p <= min($tx_pages,$tx_page+2); $p++): ?>
                    <?php if ($p===$tx_page): ?>
                        <span class="current"><?php echo $p; ?></span>
                    <?php else: ?>
                        <a href="?id=<?php echo $target_id; ?>&txpage=<?php echo $p; ?>"><?php echo $p; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                <?php if ($tx_page < $tx_pages): ?>
                    <a href="?id=<?php echo $target_id; ?>&txpage=<?php echo $tx_page+1; ?>">Next →</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Login history -->
        <div class="section-card">
            <div class="section-card-header">
                <h2>🕐 Recent Login Activity</h2>
                <span class="count">Last 10 sessions</span>
            </div>
            <?php if (empty($login_logs)): ?>
                <p style="padding:24px;text-align:center;color:#94a3b8;">No login history recorded.</p>
            <?php else: ?>
            <table class="admin-table">
                <thead><tr><th>Date &amp; Time</th><th>IP Address</th><th>Device / Browser</th></tr></thead>
                <tbody>
                    <?php foreach ($login_logs as $log): ?>
                    <tr class="log-row">
                        <td><?php echo formatDate($log['login_time']); ?></td>
                        <td style="font-family:monospace;"><?php echo htmlspecialchars($log['ip_address'] ?: '—'); ?></td>
                        <td style="color:#64748b;max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                            <?php echo htmlspecialchars(mb_substr($log['user_agent'] ?: '—', 0, 80)); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </main>
</div>
</body>
</html>
