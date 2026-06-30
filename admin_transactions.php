<?php
include('config/db.php');
include('config/auth.php');
include('config/helpers.php');

$admin_id = requireAdmin();

// ── Flash ────────────────────────────────────────────────────────────────────
$flash      = $_GET['success'] ?? '';
$flash_type = 'success';
if (empty($flash)) { $flash = $_GET['error'] ?? ''; $flash_type = 'error'; }

// ── Filters ──────────────────────────────────────────────────────────────────
$search      = trim($_GET['q']      ?? '');   // user name / email / reference
$f_type      = trim($_GET['type']   ?? '');   // deposit | withdrawal | trade | fee
$f_status    = trim($_GET['status'] ?? '');   // pending | completed | failed
$f_date_from = trim($_GET['from']   ?? '');   // YYYY-MM-DD
$f_date_to   = trim($_GET['to']     ?? '');   // YYYY-MM-DD
$f_amount_min= trim($_GET['amin']   ?? '');
$f_amount_max= trim($_GET['amax']   ?? '');
$page        = max(1, (int)($_GET['page'] ?? 1));
$per_page    = 25;
$offset      = ($page - 1) * $per_page;

// ── Build WHERE ───────────────────────────────────────────────────────────────
$where  = [];
$params = [];

if ($search !== '') {
    $where[]        = "(u.fullname ILIKE :q OR u.email ILIKE :q OR t.reference ILIKE :q OR t.description ILIKE :q)";
    $params[':q']   = '%' . $search . '%';
}
$allowed_types   = ['deposit','withdrawal','trade','fee'];
$allowed_statuses= ['pending','completed','failed'];
if (in_array($f_type,   $allowed_types,    true)) { $where[] = "t.type = :type";     $params[':type']   = $f_type; }
if (in_array($f_status, $allowed_statuses, true)) { $where[] = "t.status = :status"; $params[':status'] = $f_status; }
if ($f_date_from !== '') { $where[] = "t.created_at >= :dfrom"; $params[':dfrom'] = $f_date_from . ' 00:00:00'; }
if ($f_date_to   !== '') { $where[] = "t.created_at <= :dto";   $params[':dto']   = $f_date_to   . ' 23:59:59'; }
if ($f_amount_min !== '' && is_numeric($f_amount_min)) { $where[] = "t.amount >= :amin"; $params[':amin'] = $f_amount_min; }
if ($f_amount_max !== '' && is_numeric($f_amount_max)) { $where[] = "t.amount <= :amax"; $params[':amax'] = $f_amount_max; }

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$base_sql = "
    FROM transactions t
    JOIN users u ON u.id = t.user_id
    {$where_sql}
";

// ── CSV Export ────────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $exp_stmt = $conn->prepare("
        SELECT t.id, t.type, t.amount, t.description, t.status, t.reference, t.created_at,
               u.fullname, u.email
        {$base_sql}
        ORDER BY t.created_at DESC
    ");
    foreach ($params as $k => $v) $exp_stmt->bindValue($k, $v);
    $exp_stmt->execute();
    $rows = $exp_stmt->fetchAll();

    $filename = 'softlink_transactions_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel

    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','Type','Amount (NGN)','Description','Status','Reference','Date','User Name','User Email']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'],
            ucfirst($r['type']),
            number_format($r['amount'], 2),
            $r['description'] ?? '',
            ucfirst($r['status']),
            $r['reference'] ?? '',
            date('Y-m-d H:i:s', strtotime($r['created_at'])),
            $r['fullname'],
            $r['email'],
        ]);
    }
    fclose($out);
    exit();
}

// ── Data queries ──────────────────────────────────────────────────────────────
try {
    // Summary stats (unfiltered, platform-wide)
    $sum = $conn->query("
        SELECT
            COUNT(*)                                                             AS total,
            COALESCE(SUM(amount),0)                                             AS total_vol,
            COALESCE(SUM(CASE WHEN type='deposit'    THEN amount ELSE 0 END),0) AS dep_vol,
            COALESCE(SUM(CASE WHEN type='withdrawal' THEN amount ELSE 0 END),0) AS wdr_vol,
            COALESCE(SUM(CASE WHEN status='pending'  THEN 1 ELSE 0 END),0)      AS pending_count,
            COALESCE(SUM(CASE WHEN status='failed'   THEN 1 ELSE 0 END),0)      AS failed_count
        FROM transactions
    ")->fetch();

    // Filtered count for pagination
    $cnt_stmt = $conn->prepare("SELECT COUNT(*) {$base_sql}");
    foreach ($params as $k => $v) $cnt_stmt->bindValue($k, $v);
    $cnt_stmt->execute();
    $total_rows  = (int)$cnt_stmt->fetchColumn();
    $total_pages = max(1, ceil($total_rows / $per_page));

    // Filtered sum for current results
    $fsum_stmt = $conn->prepare("
        SELECT COALESCE(SUM(t.amount),0) AS fvol,
               COALESCE(SUM(CASE WHEN t.type='deposit'    THEN t.amount ELSE 0 END),0) AS fdep,
               COALESCE(SUM(CASE WHEN t.type='withdrawal' THEN t.amount ELSE 0 END),0) AS fwdr
        {$base_sql}
    ");
    foreach ($params as $k => $v) $fsum_stmt->bindValue($k, $v);
    $fsum_stmt->execute();
    $fsum = $fsum_stmt->fetch();

    // Paginated rows
    $list_stmt = $conn->prepare("
        SELECT t.id, t.type, t.amount, t.description, t.status, t.reference, t.created_at,
               u.id AS uid, u.fullname, u.email
        {$base_sql}
        ORDER BY t.created_at DESC
        LIMIT :lim OFFSET :off
    ");
    foreach ($params as $k => $v) $list_stmt->bindValue($k, $v);
    $list_stmt->bindValue(':lim', $per_page, PDO::PARAM_INT);
    $list_stmt->bindValue(':off', $offset,   PDO::PARAM_INT);
    $list_stmt->execute();
    $transactions = $list_stmt->fetchAll();

} catch (PDOException $e) {
    error_log('Admin transactions error: ' . $e->getMessage());
    die('Error loading transactions. Please try again.');
}

// Admin name
$me = $conn->prepare('SELECT fullname FROM users WHERE id = :id');
$me->execute([':id' => $admin_id]);
$admin = $me->fetch();

// Query string helper
function qs(array $over = []): string {
    $keys = ['q','type','status','from','to','amin','amax','page'];
    $base = [];
    foreach ($keys as $k) if (isset($_GET[$k]) && $_GET[$k] !== '') $base[$k] = $_GET[$k];
    $merged  = array_merge($base, $over);
    $filtered = array_filter($merged, fn($v) => $v !== '' && $v !== null);
    return $filtered ? '?' . http_build_query($filtered) : '';
}

// CSV export URL preserves all current filters except page
function csvUrl(): string {
    $keys = ['q','type','status','from','to','amin','amax'];
    $p = ['export' => 'csv'];
    foreach ($keys as $k) if (!empty($_GET[$k])) $p[$k] = $_GET[$k];
    return 'admin_transactions.php?' . http_build_query($p);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaction Management — Softlink Broker Admin</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        <?php include('config/admin_styles.php'); ?>

        /* ── Filter bar ─────────────────────────────────── */
        .filter-card {
            background: white;
            border-radius: 12px;
            padding: 20px 24px;
            box-shadow: 0 1px 4px rgba(0,0,0,.06);
            margin-bottom: 22px;
        }
        .filter-card h3 {
            font-size: 12px; font-weight: 700; text-transform: uppercase;
            letter-spacing: .06em; color: #64748b; margin: 0 0 14px;
        }
        .filter-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr 1fr 1fr 1fr;
            gap: 10px;
            align-items: end;
        }
        @media (max-width: 1100px) { .filter-grid { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 700px)  { .filter-grid { grid-template-columns: 1fr; } }

        .fg { display: flex; flex-direction: column; gap: 4px; }
        .fg label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #94a3b8; }
        .fg input, .fg select {
            padding: 8px 12px; border: 1px solid #e2e8f0;
            border-radius: 8px; font-size: 13px; color: #1e293b; background: white;
        }
        .fg input:focus, .fg select:focus {
            outline: none; border-color: #a78bfa;
            box-shadow: 0 0 0 3px rgba(167,139,250,.15);
        }
        .filter-actions { display: flex; gap: 8px; }
        .btn-filter {
            padding: 8px 18px; background: #7c3aed; color: white;
            border: none; border-radius: 8px; font-size: 13px;
            font-weight: 700; cursor: pointer; white-space: nowrap;
        }
        .btn-filter:hover { background: #6d28d9; }
        .btn-reset-f {
            padding: 8px 14px; background: #f1f5f9; color: #64748b;
            border: 1px solid #e2e8f0; border-radius: 8px; font-size: 13px;
            font-weight: 600; cursor: pointer; text-decoration: none; white-space: nowrap;
        }
        .btn-reset-f:hover { background: #e2e8f0; }

        /* ── Filtered summary strip ─────────────────────── */
        .result-strip {
            display: flex; align-items: center; gap: 16px;
            padding: 12px 22px; background: #f8fafc;
            border-bottom: 1px solid #f1f5f9; flex-wrap: wrap;
        }
        .result-strip .rs-item { font-size: 12px; color: #64748b; }
        .result-strip .rs-item strong { color: #1e293b; }
        .result-strip .rs-sep { color: #e2e8f0; }

        /* ── Export button ──────────────────────────────── */
        .btn-export {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 8px 18px; background: #16a34a; color: white;
            border: none; border-radius: 8px; font-size: 13px;
            font-weight: 700; cursor: pointer; text-decoration: none;
            white-space: nowrap;
        }
        .btn-export:hover { background: #15803d; }

        /* ── Active filter pills ────────────────────────── */
        .active-filters {
            display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 14px;
        }
        .af-pill {
            display: inline-flex; align-items: center; gap: 6px;
            background: #ede9fe; color: #6d28d9;
            padding: 4px 12px; border-radius: 20px;
            font-size: 11px; font-weight: 700;
        }
        .af-pill a { color: #6d28d9; text-decoration: none; font-weight: 900; }
        .af-pill a:hover { color: #4c1d95; }

        /* ── Amount coloring ────────────────────────────── */
        .amt-deposit    { color: #16a34a; font-weight: 700; }
        .amt-withdrawal { color: #dc2626; font-weight: 700; }
        .amt-trade      { color: #2563eb; font-weight: 700; }
        .amt-fee        { color: #d97706; font-weight: 700; }

        /* ── Pagination ─────────────────────────────────── */
        .pagination {
            display: flex; gap: 6px; padding: 16px 22px;
            justify-content: center; flex-wrap: wrap;
        }
        .pagination a, .pagination span {
            padding: 6px 13px; border-radius: 7px;
            font-size: 13px; font-weight: 600; text-decoration: none;
        }
        .pagination a { background: #f1f5f9; color: #475569; }
        .pagination a:hover { background: #e2e8f0; }
        .pagination .pg-current { background: #7c3aed; color: white; }
        .pagination .pg-ellipsis { color: #94a3b8; padding: 6px 4px; }

        /* ── User link in table ─────────────────────────── */
        .user-link { color: #7c3aed; text-decoration: none; font-weight: 600; }
        .user-link:hover { text-decoration: underline; }
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
            <a href="admin_users.php"><span class="icon">👥</span> User Management</a>
            <a href="admin_transactions.php" class="active"><span class="icon">🔄</span> Transactions</a>
            <hr class="sidebar-divider">
            <a href="dashboard.php"><span class="icon">👤</span> My Dashboard</a>
            <a href="deposit.php"><span class="icon">💰</span> Deposit</a>
            <a href="withdraw.php"><span class="icon">🏦</span> Withdraw</a>
            <hr class="sidebar-divider">
            <a href="config/logout.php"><span class="icon">🚪</span> Logout</a>
        </nav>
    </aside>

    <main class="main">
        <?php if ($flash): ?>
        <div class="flash flash-<?php echo $flash_type; ?>"><?php echo htmlspecialchars($flash); ?></div>
        <?php endif; ?>

        <!-- Page header -->
        <div class="page-header">
            <div>
                <h1>🔄 Transaction Management</h1>
                <p>Search, filter, and export all platform transactions</p>
            </div>
            <span class="admin-badge">🔐 Administrator</span>
        </div>

        <!-- Platform-wide stat cards -->
        <div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr));margin-bottom:22px;">
            <div class="stat-card blue">
                <div class="icon-wrap">🔄</div>
                <div class="label">Total Transactions</div>
                <div class="value"><?php echo number_format($sum['total']); ?></div>
            </div>
            <div class="stat-card" style="border-top-color:#0d9488;">
                <div class="icon-wrap">💳</div>
                <div class="label">Total Volume</div>
                <div class="value" style="font-size:18px;"><?php echo formatCurrency($sum['total_vol']); ?></div>
            </div>
            <div class="stat-card green">
                <div class="icon-wrap">📥</div>
                <div class="label">Deposit Volume</div>
                <div class="value" style="font-size:18px;"><?php echo formatCurrency($sum['dep_vol']); ?></div>
            </div>
            <div class="stat-card red">
                <div class="icon-wrap">📤</div>
                <div class="label">Withdrawal Volume</div>
                <div class="value" style="font-size:18px;"><?php echo formatCurrency($sum['wdr_vol']); ?></div>
            </div>
            <div class="stat-card amber">
                <div class="icon-wrap">⏳</div>
                <div class="label">Pending</div>
                <div class="value"><?php echo number_format($sum['pending_count']); ?></div>
            </div>
            <div class="stat-card purple">
                <div class="icon-wrap">❌</div>
                <div class="label">Failed</div>
                <div class="value"><?php echo number_format($sum['failed_count']); ?></div>
            </div>
        </div>

        <!-- Filter bar -->
        <div class="filter-card">
            <h3>🔍 Search &amp; Filter</h3>
            <form method="GET" action="admin_transactions.php">
                <div class="filter-grid">
                    <div class="fg">
                        <label>Search</label>
                        <input type="text" name="q" placeholder="User name, email, reference…"
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="fg">
                        <label>Type</label>
                        <select name="type">
                            <option value="">All Types</option>
                            <?php foreach (['deposit','withdrawal','trade','fee'] as $t): ?>
                            <option value="<?php echo $t; ?>" <?php echo $f_type===$t?'selected':''; ?>>
                                <?php echo ucfirst($t); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="fg">
                        <label>Status</label>
                        <select name="status">
                            <option value="">All Statuses</option>
                            <?php foreach (['completed','pending','failed'] as $s): ?>
                            <option value="<?php echo $s; ?>" <?php echo $f_status===$s?'selected':''; ?>>
                                <?php echo ucfirst($s); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="fg">
                        <label>Date From</label>
                        <input type="date" name="from" value="<?php echo htmlspecialchars($f_date_from); ?>">
                    </div>
                    <div class="fg">
                        <label>Date To</label>
                        <input type="date" name="to" value="<?php echo htmlspecialchars($f_date_to); ?>">
                    </div>
                    <div class="fg">
                        <label>Min Amount (₦)</label>
                        <input type="number" name="amin" step="0.01" min="0"
                               placeholder="0.00" value="<?php echo htmlspecialchars($f_amount_min); ?>">
                    </div>
                    <div class="fg">
                        <label>Max Amount (₦)</label>
                        <input type="number" name="amax" step="0.01" min="0"
                               placeholder="Any" value="<?php echo htmlspecialchars($f_amount_max); ?>">
                    </div>
                </div>
                <div class="filter-actions" style="margin-top:14px;">
                    <button type="submit" class="btn-filter">Apply Filters</button>
                    <a href="admin_transactions.php" class="btn-reset-f">✕ Reset</a>
                </div>
            </form>
        </div>

        <!-- Active filter pills -->
        <?php
        $active = [];
        if ($search     !== '') $active['q']      = ['Search', $search];
        if ($f_type     !== '') $active['type']   = ['Type',   ucfirst($f_type)];
        if ($f_status   !== '') $active['status'] = ['Status', ucfirst($f_status)];
        if ($f_date_from!== '') $active['from']   = ['From',   $f_date_from];
        if ($f_date_to  !== '') $active['to']     = ['To',     $f_date_to];
        if ($f_amount_min!=='') $active['amin']   = ['Min ₦',  $f_amount_min];
        if ($f_amount_max!=='') $active['amax']   = ['Max ₦',  $f_amount_max];
        if ($active):
        ?>
        <div class="active-filters">
            <?php foreach ($active as $key => [$label, $val]): ?>
            <span class="af-pill">
                <?php echo $label; ?>: <?php echo htmlspecialchars($val); ?>
                <a href="admin_transactions.php<?php echo htmlspecialchars(qs([$key => ''])); ?>">×</a>
            </span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Results table -->
        <div class="section-card">
            <div class="section-card-header">
                <h2>Transactions</h2>
                <div style="display:flex;align-items:center;gap:16px;">
                    <span class="count"><?php echo number_format($total_rows); ?> result<?php echo $total_rows!==1?'s':''; ?></span>
                    <a href="<?php echo htmlspecialchars(csvUrl()); ?>" class="btn-export">
                        ⬇ Export CSV
                    </a>
                </div>
            </div>

            <!-- Filtered summary strip -->
            <?php if ($total_rows > 0): ?>
            <div class="result-strip">
                <span class="rs-item">Showing <strong><?php echo min($offset+1,$total_rows); ?>–<?php echo min($offset+$per_page,$total_rows); ?></strong> of <strong><?php echo number_format($total_rows); ?></strong></span>
                <span class="rs-sep">|</span>
                <span class="rs-item">Volume: <strong><?php echo formatCurrency($fsum['fvol']); ?></strong></span>
                <span class="rs-sep">|</span>
                <span class="rs-item">Deposits: <strong style="color:#16a34a;"><?php echo formatCurrency($fsum['fdep']); ?></strong></span>
                <span class="rs-sep">|</span>
                <span class="rs-item">Withdrawals: <strong style="color:#dc2626;"><?php echo formatCurrency($fsum['fwdr']); ?></strong></span>
            </div>
            <?php endif; ?>

            <table class="admin-table">
                <thead>
                    <tr>
                        <th style="width:50px;">ID</th>
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
                <?php if (empty($transactions)): ?>
                    <tr class="empty-row"><td colspan="8">No transactions match your filters.</td></tr>
                <?php else: ?>
                    <?php foreach ($transactions as $tx): ?>
                    <tr>
                        <td style="color:#94a3b8;font-size:12px;">#<?php echo $tx['id']; ?></td>
                        <td>
                            <div class="user-cell">
                                <div class="avatar" style="width:28px;height:28px;font-size:11px;">
                                    <?php echo strtoupper(mb_substr($tx['fullname'],0,1)); ?>
                                </div>
                                <div class="user-cell-info">
                                    <div>
                                        <a href="admin_user_profile.php?id=<?php echo $tx['uid']; ?>" class="user-link">
                                            <?php echo htmlspecialchars($tx['fullname']); ?>
                                        </a>
                                    </div>
                                    <div class="user-cell-email"><?php echo htmlspecialchars($tx['email']); ?></div>
                                </div>
                            </div>
                        </td>
                        <td><span class="pill pill-<?php echo $tx['type']; ?>"><?php echo ucfirst($tx['type']); ?></span></td>
                        <td class="amt-<?php echo $tx['type']; ?>">
                            <?php echo $tx['type']==='withdrawal' ? '−' : '+'; ?><?php echo formatCurrency($tx['amount']); ?>
                        </td>
                        <td style="color:#64748b;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                            <?php echo htmlspecialchars($tx['description'] ?: '—'); ?>
                        </td>
                        <td><span class="pill pill-<?php echo $tx['status']; ?>"><?php echo ucfirst($tx['status']); ?></span></td>
                        <td style="font-size:11px;font-family:monospace;color:#94a3b8;white-space:nowrap;">
                            <?php echo htmlspecialchars($tx['reference'] ?: '—'); ?>
                        </td>
                        <td style="font-size:12px;color:#64748b;white-space:nowrap;"><?php echo formatDate($tx['created_at']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="admin_transactions.php<?php echo htmlspecialchars(qs(['page'=>$page-1])); ?>">← Prev</a>
                <?php endif; ?>

                <?php
                // Smart pagination: always show first, last, and ±2 around current
                $shown = [];
                for ($p = 1; $p <= $total_pages; $p++) {
                    if ($p === 1 || $p === $total_pages || abs($p - $page) <= 2) $shown[] = $p;
                }
                $prev = null;
                foreach ($shown as $p):
                    if ($prev !== null && $p - $prev > 1): ?>
                        <span class="pg-ellipsis">…</span>
                    <?php endif;
                    if ($p === $page): ?>
                        <span class="pg-current"><?php echo $p; ?></span>
                    <?php else: ?>
                        <a href="admin_transactions.php<?php echo htmlspecialchars(qs(['page'=>$p])); ?>"><?php echo $p; ?></a>
                    <?php endif;
                    $prev = $p;
                endforeach; ?>

                <?php if ($page < $total_pages): ?>
                    <a href="admin_transactions.php<?php echo htmlspecialchars(qs(['page'=>$page+1])); ?>">Next →</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </main>
</div>
</body>
</html>
