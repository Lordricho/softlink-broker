<?php
include('config/db.php');
include('config/auth.php');
include('config/helpers.php');

$admin_id = requireAdmin();

// ── Flash messages from admin_action.php ────────────────────────────────────
$flash         = $_GET['success'] ?? '';
$flash_type    = 'success';
if (empty($flash)) { $flash = $_GET['error'] ?? ''; $flash_type = 'error'; }

// ── Search / filter ──────────────────────────────────────────────────────────
$search     = trim($_GET['q']      ?? '');
$filter_role = trim($_GET['role']  ?? '');   // 'admin' | 'user' | ''
$filter_status = trim($_GET['status'] ?? ''); // 'verified' | 'unverified' | 'suspended' | ''
$page       = max(1, (int)($_GET['page'] ?? 1));
$per_page   = 20;
$offset     = ($page - 1) * $per_page;

// Build WHERE clause
$where_parts = [];
$params      = [];

if ($search !== '') {
    $where_parts[] = "(u.fullname ILIKE :q OR u.email ILIKE :q OR u.phone ILIKE :q)";
    $params[':q']  = '%' . $search . '%';
}
if ($filter_role === 'admin') {
    $where_parts[] = "u.is_admin = TRUE";
} elseif ($filter_role === 'user') {
    $where_parts[] = "u.is_admin = FALSE";
}
if ($filter_status === 'verified') {
    $where_parts[] = "u.is_verified = TRUE AND u.is_suspended = FALSE";
} elseif ($filter_status === 'unverified') {
    $where_parts[] = "u.is_verified = FALSE AND u.is_suspended = FALSE";
} elseif ($filter_status === 'suspended') {
    $where_parts[] = "u.is_suspended = TRUE";
}

$where_sql = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';

try {
    // Summary stats
    $stats = $conn->query("
        SELECT
            COUNT(*)                                        AS total_users,
            SUM(CASE WHEN is_admin = TRUE  THEN 1 ELSE 0 END) AS total_admins,
            SUM(CASE WHEN is_verified = TRUE AND is_suspended = FALSE THEN 1 ELSE 0 END) AS total_verified,
            SUM(CASE WHEN is_suspended = TRUE THEN 1 ELSE 0 END) AS total_suspended,
            SUM(CASE WHEN is_admin = FALSE AND is_verified = FALSE AND is_suspended = FALSE THEN 1 ELSE 0 END) AS total_regular,
            COALESCE(SUM(balance), 0) AS total_balance
        FROM users
    ")->fetch();

    // Count for pagination
    $count_stmt = $conn->prepare("SELECT COUNT(*) FROM users u {$where_sql}");
    $count_stmt->execute($params);
    $total_rows = (int)$count_stmt->fetchColumn();
    $total_pages = max(1, ceil($total_rows / $per_page));

    // User list with transaction counts
    $list_stmt = $conn->prepare("
        SELECT u.id, u.fullname, u.email, u.phone, u.balance,
               u.is_admin, u.is_verified, u.is_suspended, u.created_at,
               COUNT(t.id) AS tx_count
        FROM users u
        LEFT JOIN transactions t ON t.user_id = u.id
        {$where_sql}
        GROUP BY u.id
        ORDER BY u.created_at DESC
        LIMIT :limit OFFSET :offset
    ");
    foreach ($params as $k => $v) $list_stmt->bindValue($k, $v);
    $list_stmt->bindValue(':limit',  $per_page, PDO::PARAM_INT);
    $list_stmt->bindValue(':offset', $offset,   PDO::PARAM_INT);
    $list_stmt->execute();
    $users = $list_stmt->fetchAll();

} catch (PDOException $e) {
    error_log('Admin users error: ' . $e->getMessage());
    die('Error loading user management. Please try again.');
}

// Fetch admin name
$me = $conn->prepare('SELECT fullname FROM users WHERE id = :id');
$me->execute([':id' => $admin_id]);
$admin = $me->fetch();

// Build query string helper (preserves filters across pagination)
function qs(array $overrides = []): string {
    $base = ['q' => $_GET['q'] ?? '', 'role' => $_GET['role'] ?? '', 'status' => $_GET['status'] ?? '', 'page' => $_GET['page'] ?? 1];
    $merged = array_merge($base, $overrides);
    $filtered = array_filter($merged, fn($v) => $v !== '' && $v !== null);
    return $filtered ? '?' . http_build_query($filtered) : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - Softlink Broker Admin</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        <?php include('config/admin_styles.php'); ?>
        /* ── Search bar ─────────────────────────────────────── */
        .search-bar {
            background: white;
            border-radius: 12px;
            padding: 18px 22px;
            box-shadow: 0 1px 4px rgba(0,0,0,.06);
            margin-bottom: 22px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        .search-bar .field { display: flex; flex-direction: column; gap: 5px; flex: 1; min-width: 180px; }
        .search-bar label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #64748b; }
        .search-bar input,
        .search-bar select {
            padding: 9px 13px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 13px;
            color: #1e293b;
            background: white;
        }
        .search-bar input:focus,
        .search-bar select:focus { outline: none; border-color: #a78bfa; box-shadow: 0 0 0 3px rgba(167,139,250,.15); }
        .btn-search {
            padding: 9px 22px;
            background: #7c3aed;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            align-self: flex-end;
            white-space: nowrap;
        }
        .btn-search:hover { background: #6d28d9; }
        .btn-reset {
            padding: 9px 16px;
            background: #f1f5f9;
            color: #64748b;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            align-self: flex-end;
            text-decoration: none;
            white-space: nowrap;
        }
        .btn-reset:hover { background: #e2e8f0; }

        /* ── Action buttons in table ────────────────────────── */
        .action-group { display: flex; gap: 6px; flex-wrap: wrap; }
        .act-btn {
            padding: 4px 11px;
            border: none;
            border-radius: 5px;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            white-space: nowrap;
            text-decoration: none;
            display: inline-block;
        }
        .act-view      { background:#e0f2fe; color:#0369a1; }
        .act-view:hover{ background:#bae6fd; }
        .act-promote   { background:#ede9fe; color:#6d28d9; }
        .act-promote:hover { background:#ddd6fe; }
        .act-demote    { background:#fef9c3; color:#a16207; }
        .act-demote:hover  { background:#fde68a; }
        .act-verify    { background:#dcfce7; color:#15803d; }
        .act-verify:hover  { background:#bbf7d0; }
        .act-unverify  { background:#f1f5f9; color:#475569; }
        .act-unverify:hover{ background:#e2e8f0; }
        .act-suspend   { background:#fee2e2; color:#b91c1c; }
        .act-suspend:hover { background:#fecaca; }
        .act-activate  { background:#dcfce7; color:#15803d; }
        .act-activate:hover{ background:#bbf7d0; }

        /* ── Pagination ─────────────────────────────────────── */
        .pagination { display:flex; gap:6px; margin-top:18px; justify-content:center; flex-wrap:wrap; }
        .pagination a, .pagination span {
            padding: 6px 13px;
            border-radius: 7px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
        }
        .pagination a { background:#f1f5f9; color:#475569; }
        .pagination a:hover { background:#e2e8f0; }
        .pagination .current { background:#7c3aed; color:white; }

        /* ── Results meta ───────────────────────────────────── */
        .results-meta { font-size:12px; color:#94a3b8; margin-bottom:12px; }
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
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-brand"><span>⚙️ &nbsp;Admin Panel</span></div>
        <nav>
            <a href="admin_dashboard.php"><span class="icon">📊</span> Overview</a>
            <a href="admin_users.php" class="active"><span class="icon">👥</span> User Management</a>
            <hr class="sidebar-divider">
            <a href="dashboard.php"><span class="icon">👤</span> My Dashboard</a>
            <a href="deposit.php"><span class="icon">💰</span> Deposit</a>
            <a href="withdraw.php"><span class="icon">🏦</span> Withdraw</a>
            <hr class="sidebar-divider">
            <a href="config/logout.php"><span class="icon">🚪</span> Logout</a>
        </nav>
    </aside>

    <main class="main">
        <!-- Flash message -->
        <?php if ($flash): ?>
        <div class="flash flash-<?php echo $flash_type; ?>"><?php echo htmlspecialchars($flash); ?></div>
        <?php endif; ?>

        <!-- Page header -->
        <div class="page-header">
            <div>
                <h1>👥 User Management</h1>
                <p>Search, view, and manage all registered users</p>
            </div>
            <span class="admin-badge">🔐 Administrator</span>
        </div>

        <!-- Summary stat cards -->
        <div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr));">
            <div class="stat-card purple">
                <div class="icon-wrap">👥</div>
                <div class="label">Total Users</div>
                <div class="value"><?php echo number_format($stats['total_users']); ?></div>
            </div>
            <div class="stat-card blue">
                <div class="icon-wrap">🔐</div>
                <div class="label">Admins</div>
                <div class="value"><?php echo number_format($stats['total_admins']); ?></div>
            </div>
            <div class="stat-card green">
                <div class="icon-wrap">✅</div>
                <div class="label">Verified</div>
                <div class="value"><?php echo number_format($stats['total_verified']); ?></div>
            </div>
            <div class="stat-card amber">
                <div class="icon-wrap">⏳</div>
                <div class="label">Unverified</div>
                <div class="value"><?php echo number_format($stats['total_regular']); ?></div>
            </div>
            <div class="stat-card red">
                <div class="icon-wrap">🚫</div>
                <div class="label">Suspended</div>
                <div class="value"><?php echo number_format($stats['total_suspended']); ?></div>
            </div>
            <div class="stat-card" style="border-top-color:#0d9488;">
                <div class="icon-wrap">💰</div>
                <div class="label">Total Balances</div>
                <div class="value" style="font-size:18px;"><?php echo formatCurrency($stats['total_balance']); ?></div>
            </div>
        </div>

        <!-- Search & filter -->
        <form method="GET" action="admin_users.php">
            <div class="search-bar">
                <div class="field">
                    <label for="q">Search</label>
                    <input type="text" id="q" name="q" placeholder="Name, email, or phone…"
                           value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="field" style="max-width:160px;">
                    <label for="role">Role</label>
                    <select id="role" name="role">
                        <option value="">All Roles</option>
                        <option value="admin" <?php echo $filter_role==='admin' ? 'selected' : ''; ?>>Admin</option>
                        <option value="user"  <?php echo $filter_role==='user'  ? 'selected' : ''; ?>>User</option>
                    </select>
                </div>
                <div class="field" style="max-width:160px;">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="">All Statuses</option>
                        <option value="verified"   <?php echo $filter_status==='verified'   ? 'selected' : ''; ?>>Verified</option>
                        <option value="unverified" <?php echo $filter_status==='unverified' ? 'selected' : ''; ?>>Unverified</option>
                        <option value="suspended"  <?php echo $filter_status==='suspended'  ? 'selected' : ''; ?>>Suspended</option>
                    </select>
                </div>
                <button type="submit" class="btn-search">🔍 Search</button>
                <a href="admin_users.php" class="btn-reset">✕ Reset</a>
            </div>
        </form>

        <!-- Results -->
        <div class="section-card">
            <div class="section-card-header">
                <h2>All Users</h2>
                <span class="count"><?php echo number_format($total_rows); ?> result<?php echo $total_rows !== 1 ? 's' : ''; ?></span>
            </div>

            <div class="results-meta" style="padding:10px 22px 0;">
                Showing <?php echo min($offset + 1, $total_rows); ?>–<?php echo min($offset + $per_page, $total_rows); ?> of <?php echo number_format($total_rows); ?> users
            </div>

            <table class="admin-table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Phone</th>
                        <th>Balance</th>
                        <th>Txns</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Joined</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($users)): ?>
                    <tr class="empty-row"><td colspan="8">No users found matching your criteria.</td></tr>
                <?php else: ?>
                    <?php foreach ($users as $u):
                        $profile_url = 'admin_user_profile.php?id=' . $u['id'];
                        $back        = urlencode('admin_users.php' . qs());
                    ?>
                    <tr>
                        <td>
                            <div class="user-cell">
                                <div class="avatar <?php echo $u['is_suspended'] ? 'avatar-suspended' : ''; ?>">
                                    <?php echo strtoupper(mb_substr($u['fullname'], 0, 1)); ?>
                                </div>
                                <div class="user-cell-info">
                                    <div class="user-cell-name"><?php echo htmlspecialchars($u['fullname']); ?></div>
                                    <div class="user-cell-email"><?php echo htmlspecialchars($u['email']); ?></div>
                                </div>
                            </div>
                        </td>
                        <td><?php echo htmlspecialchars($u['phone'] ?: '—'); ?></td>
                        <td style="font-weight:700;"><?php echo formatCurrency($u['balance']); ?></td>
                        <td style="text-align:center;"><?php echo $u['tx_count']; ?></td>
                        <td>
                            <?php if ($u['is_admin']): ?>
                                <span class="pill pill-admin">Admin</span>
                            <?php else: ?>
                                <span class="pill pill-user">User</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($u['is_suspended']): ?>
                                <span class="pill pill-suspended">🚫 Suspended</span>
                            <?php elseif ($u['is_verified']): ?>
                                <span class="pill pill-verified">✓ Verified</span>
                            <?php else: ?>
                                <span class="pill pill-unverified">Pending</span>
                            <?php endif; ?>
                        </td>
                        <td style="color:#64748b;font-size:12px;white-space:nowrap;"><?php echo date('M d, Y', strtotime($u['created_at'])); ?></td>
                        <td>
                            <div class="action-group">
                                <a href="<?php echo $profile_url; ?>" class="act-btn act-view">View</a>

                                <!-- Admin toggle -->
                                <form method="POST" action="admin_action.php" style="display:inline;" onsubmit="return confirm('<?php echo $u['is_admin'] ? 'Demote this admin to regular user?' : 'Promote this user to admin?'; ?>')">
                                    <input type="hidden" name="action"   value="toggle_admin">
                                    <input type="hidden" name="user_id"  value="<?php echo $u['id']; ?>">
                                    <input type="hidden" name="redirect" value="admin_users.php<?php echo htmlspecialchars(qs()); ?>">
                                    <button type="submit" class="act-btn <?php echo $u['is_admin'] ? 'act-demote' : 'act-promote'; ?>">
                                        <?php echo $u['is_admin'] ? 'Demote' : 'Make Admin'; ?>
                                    </button>
                                </form>

                                <!-- Verify toggle -->
                                <form method="POST" action="admin_action.php" style="display:inline;">
                                    <input type="hidden" name="action"   value="toggle_verified">
                                    <input type="hidden" name="user_id"  value="<?php echo $u['id']; ?>">
                                    <input type="hidden" name="redirect" value="admin_users.php<?php echo htmlspecialchars(qs()); ?>">
                                    <button type="submit" class="act-btn <?php echo $u['is_verified'] ? 'act-unverify' : 'act-verify'; ?>">
                                        <?php echo $u['is_verified'] ? 'Unverify' : 'Verify'; ?>
                                    </button>
                                </form>

                                <!-- Suspend toggle -->
                                <?php if ($u['id'] !== $admin_id): ?>
                                <form method="POST" action="admin_action.php" style="display:inline;" onsubmit="return confirm('<?php echo $u['is_suspended'] ? 'Reactivate this account?' : 'Suspend this account? The user will be blocked from logging in.'; ?>')">
                                    <input type="hidden" name="action"   value="toggle_suspended">
                                    <input type="hidden" name="user_id"  value="<?php echo $u['id']; ?>">
                                    <input type="hidden" name="redirect" value="admin_users.php<?php echo htmlspecialchars(qs()); ?>">
                                    <button type="submit" class="act-btn <?php echo $u['is_suspended'] ? 'act-activate' : 'act-suspend'; ?>">
                                        <?php echo $u['is_suspended'] ? 'Reactivate' : 'Suspend'; ?>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="pagination" style="padding:16px;">
                <?php if ($page > 1): ?>
                    <a href="admin_users.php<?php echo qs(['page' => $page - 1]); ?>">← Prev</a>
                <?php endif; ?>
                <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++): ?>
                    <?php if ($p === $page): ?>
                        <span class="current"><?php echo $p; ?></span>
                    <?php else: ?>
                        <a href="admin_users.php<?php echo qs(['page' => $p]); ?>"><?php echo $p; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                    <a href="admin_users.php<?php echo qs(['page' => $page + 1]); ?>">Next →</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </main>
</div>
</body>
</html>
