<?php
include('config/db.php');
include('config/auth.php');
include('config/helpers.php');
include_once('config/notify.php');

$admin_id = requireAdmin();

// ── Flash ─────────────────────────────────────────────────────────────────────
$flash      = $_GET['success'] ?? '';
$flash_type = 'success';
if (empty($flash)) { $flash = $_GET['error'] ?? ''; $flash_type = 'error'; }

// ── Filters ───────────────────────────────────────────────────────────────────
$f_cat    = trim($_GET['cat']    ?? '');   // transactions|security|user_accounts|admin_actions
$f_view   = trim($_GET['view']  ?? '');   // unread|critical|archived
$f_pri    = trim($_GET['pri']   ?? '');   // low|medium|high|critical
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 30;
$offset   = ($page - 1) * $per_page;

// ── Build WHERE ────────────────────────────────────────────────────────────────
$where  = [];
$params = [];

// Default: exclude archived unless explicitly viewing archived
if ($f_view === 'archived') {
    $where[] = "n.is_archived = TRUE";
} else {
    $where[] = "n.is_archived = FALSE";
    if ($f_view === 'unread')   { $where[] = "n.is_read = FALSE"; }
    if ($f_view === 'critical') { $where[] = "n.priority = 'critical'"; }
}

$allowed_cats = ['transactions','security','user_accounts','admin_actions','general'];
if (in_array($f_cat, $allowed_cats, true)) {
    $where[] = "n.category = :cat";
    $params[':cat'] = $f_cat;
}
$allowed_pris = ['low','medium','high','critical'];
if (in_array($f_pri, $allowed_pris, true)) {
    $where[] = "n.priority = :pri";
    $params[':pri'] = $f_pri;
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// ── Counters ──────────────────────────────────────────────────────────────────
try {
    $cnt = $conn->query("
        SELECT
            COUNT(*)                                                                AS total_all,
            SUM(CASE WHEN is_archived = FALSE THEN 1 ELSE 0 END)                   AS total_active,
            SUM(CASE WHEN is_read = FALSE AND is_archived = FALSE THEN 1 ELSE 0 END) AS unread,
            SUM(CASE WHEN priority = 'critical' AND is_read = FALSE AND is_archived = FALSE THEN 1 ELSE 0 END) AS critical_unread,
            SUM(CASE WHEN category = 'security' AND is_read = FALSE AND is_archived = FALSE THEN 1 ELSE 0 END) AS security_unread
        FROM admin_notifications
    ")->fetch();

    $total_count = (int)$conn->prepare("SELECT COUNT(*) FROM admin_notifications n {$where_sql}")
        ->execute($params) ? $conn->prepare("SELECT COUNT(*) FROM admin_notifications n {$where_sql}")->execute($params) : 0;

    // Re-run properly
    $cnt_stmt = $conn->prepare("SELECT COUNT(*) FROM admin_notifications n {$where_sql}");
    $cnt_stmt->execute($params);
    $total_rows  = (int)$cnt_stmt->fetchColumn();
    $total_pages = max(1, ceil($total_rows / $per_page));

    // Notifications list
    $list_stmt = $conn->prepare("
        SELECT n.id, n.event_type, n.category, n.priority, n.title, n.description,
               n.reference, n.is_read, n.is_archived, n.created_at, n.read_at,
               n.transaction_id, n.adjustment_id,
               u.id AS uid, u.fullname, u.email,
               ra.fullname AS read_by_name
        FROM admin_notifications n
        LEFT JOIN users u  ON u.id  = n.user_id
        LEFT JOIN users ra ON ra.id = n.read_by
        {$where_sql}
        ORDER BY n.created_at DESC
        LIMIT :lim OFFSET :off
    ");
    foreach ($params as $k => $v) $list_stmt->bindValue($k, $v);
    $list_stmt->bindValue(':lim', $per_page, PDO::PARAM_INT);
    $list_stmt->bindValue(':off', $offset,   PDO::PARAM_INT);
    $list_stmt->execute();
    $notifications = $list_stmt->fetchAll();

    $me = $conn->prepare('SELECT fullname FROM users WHERE id = :id');
    $me->execute([':id' => $admin_id]);
    $admin = $me->fetch();

    $notif_unread = (int)$cnt['unread'];

} catch (PDOException $e) {
    error_log('Admin notifications page error: ' . $e->getMessage());
    die('Error loading notifications. Please try again.');
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function qs(array $over = []): string {
    $keys = ['cat','view','pri','page'];
    $base = [];
    foreach ($keys as $k) if (!empty($_GET[$k])) $base[$k] = $_GET[$k];
    $merged   = array_merge($base, $over);
    $filtered = array_filter($merged, fn($v) => $v !== '' && $v !== null);
    return $filtered ? '?' . http_build_query($filtered) : '';
}

// Map event types to icons + labels
$event_meta = [
    'new_user_registration'     => ['🧑', 'New Registration',     'user_accounts'],
    'deposit_completed'         => ['📥', 'Deposit',              'transactions'],
    'withdrawal_completed'      => ['📤', 'Withdrawal',           'transactions'],
    'suspended_login_attempt'   => ['🚨', 'Suspended Login',      'security'],
    'admin_action_toggle_admin' => ['🔐', 'Admin Role Changed',   'admin_actions'],
    'admin_action_toggle_verified'=>['✅','Verification Changed', 'admin_actions'],
    'admin_action_toggle_suspended'=>['🚫','Account Suspended',   'admin_actions'],
    'adjustment_manual_credit'  => ['⬆', 'Manual Credit',        'transactions'],
    'adjustment_manual_debit'   => ['⬇', 'Manual Debit',         'transactions'],
    'adjustment_reversal'       => ['🔁', 'Reversal',             'transactions'],
    'adjustment_void'           => ['🚫', 'Transaction Voided',   'transactions'],
    'adjustment_correction'     => ['✏️', 'Correction',            'transactions'],
];
function eventMeta(string $event): array {
    global $event_meta;
    return $event_meta[$event] ?? ['🔔', ucwords(str_replace('_',' ',$event)), 'general'];
}

$priority_colors = [
    'low'      => ['#f1f5f9','#475569'],
    'medium'   => ['#e0f2fe','#0369a1'],
    'high'     => ['#fef9c3','#a16207'],
    'critical' => ['#fee2e2','#b91c1c'],
];
$category_labels = [
    'transactions'  => '💳 Transactions',
    'security'      => '🔒 Security',
    'user_accounts' => '👤 User Accounts',
    'admin_actions' => '⚙️ Admin Actions',
    'general'       => '🔔 General',
];

$current_url = 'admin_notifications.php' . qs();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications Center — Softlink Broker Admin</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        <?php include('config/admin_styles.php'); ?>

        /* ── Notification card ───────────────────────────── */
        .notif-list    { padding: 0 0 8px; }
        .notif-card {
            display: flex; gap: 14px; align-items: flex-start;
            padding: 16px 22px; border-bottom: 1px solid #f1f5f9;
            transition: background .15s; position: relative;
        }
        .notif-card:last-child { border-bottom: none; }
        .notif-card:hover { background: #fafafa; }
        .notif-card.unread { background: #f5f3ff; }
        .notif-card.unread:hover { background: #ede9fe; }
        .notif-card.archived { opacity: .6; }

        /* Unread dot */
        .unread-dot {
            width: 8px; height: 8px; border-radius: 50%;
            background: #7c3aed; flex-shrink: 0; margin-top: 7px;
        }
        .unread-dot.hidden { background: transparent; }

        /* Icon bubble */
        .notif-icon {
            width: 38px; height: 38px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px; flex-shrink: 0;
        }
        .notif-icon.transactions  { background: #e0f2fe; }
        .notif-icon.security      { background: #fee2e2; }
        .notif-icon.user_accounts { background: #dcfce7; }
        .notif-icon.admin_actions { background: #ede9fe; }
        .notif-icon.general       { background: #f1f5f9; }

        /* Content */
        .notif-body { flex: 1; min-width: 0; }
        .notif-top  { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 3px; }
        .notif-title { font-weight: 700; color: #1e293b; font-size: 13px; }
        .notif-desc  { font-size: 12px; color: #64748b; margin: 2px 0 6px; line-height: 1.5; }
        .notif-meta  { font-size: 11px; color: #94a3b8; display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }
        .notif-meta a { color: #7c3aed; text-decoration: none; }
        .notif-meta a:hover { text-decoration: underline; }

        /* Priority badge */
        .pri-badge {
            padding: 2px 9px; border-radius: 20px; font-size: 10px;
            font-weight: 800; text-transform: uppercase; letter-spacing: .06em;
        }

        /* Actions */
        .notif-actions { display: flex; gap: 6px; flex-shrink: 0; flex-wrap: wrap; align-items: flex-start; padding-top: 2px; }
        .na-btn {
            padding: 4px 10px; border: none; border-radius: 5px; font-size: 11px;
            font-weight: 700; cursor: pointer; text-decoration: none; display: inline-block;
            white-space: nowrap;
        }
        .na-read    { background: #dcfce7; color: #15803d; }
        .na-read:hover { background: #bbf7d0; }
        .na-unread  { background: #f1f5f9; color: #475569; }
        .na-unread:hover { background: #e2e8f0; }
        .na-archive { background: #fef9c3; color: #a16207; }
        .na-archive:hover { background: #fde68a; }
        .na-restore { background: #f1f5f9; color: #475569; }
        .na-restore:hover { background: #e2e8f0; }
        .na-view    { background: #e0f2fe; color: #0369a1; }
        .na-view:hover { background: #bae6fd; }

        /* Filter tabs */
        .filter-bar {
            display: flex; gap: 8px; flex-wrap: wrap; padding: 16px 22px;
            border-bottom: 1px solid #f1f5f9; align-items: center;
        }
        .fb-tab {
            padding: 6px 16px; border-radius: 20px; font-size: 12px;
            font-weight: 600; text-decoration: none;
            background: #f1f5f9; color: #475569; white-space: nowrap;
        }
        .fb-tab:hover  { background: #e2e8f0; }
        .fb-tab.active { background: #7c3aed; color: white; }
        .fb-tab.critical.active { background: #dc2626; }
        .fb-tab.security.active { background: #b45309; }

        /* Priority filter row */
        .pri-row { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; font-size: 12px; color: #94a3b8; }
        .pri-link {
            padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 600;
            text-decoration: none; background: #f1f5f9; color: #64748b;
        }
        .pri-link:hover { background: #e2e8f0; }
        .pri-link.active { background: #1e293b; color: white; }

        /* Mark all read */
        .mark-all-btn {
            padding: 7px 16px; background: #16a34a; color: white;
            border: none; border-radius: 8px; font-size: 12px;
            font-weight: 700; cursor: pointer; margin-left: auto; white-space: nowrap;
        }
        .mark-all-btn:hover { background: #15803d; }

        /* Empty state */
        .empty-notif {
            text-align: center; padding: 56px 24px; color: #94a3b8;
        }
        .empty-notif .emoji { font-size: 48px; margin-bottom: 12px; }
        .empty-notif p { font-size: 14px; margin: 0; }

        /* Pagination */
        .pagination {
            display: flex; gap: 6px; padding: 16px 22px;
            justify-content: center; flex-wrap: wrap;
        }
        .pagination a, .pagination span {
            padding: 6px 13px; border-radius: 7px; font-size: 13px; font-weight: 600; text-decoration: none;
        }
        .pagination a { background: #f1f5f9; color: #475569; }
        .pagination a:hover { background: #e2e8f0; }
        .pagination .cur { background: #7c3aed; color: white; }

        /* Sidebar notif badge */
        .sidebar-badge {
            display: inline-block; background: #dc2626; color: white;
            border-radius: 10px; padding: 1px 7px; font-size: 10px;
            font-weight: 800; margin-left: 4px; vertical-align: middle;
        }

        /* Counter cards */
        .notif-counters {
            display: grid; grid-template-columns: repeat(auto-fit,minmax(160px,1fr));
            gap: 16px; margin-bottom: 24px;
        }
        .nc-card {
            background: white; border-radius: 12px; padding: 18px 20px;
            box-shadow: 0 1px 4px rgba(0,0,0,.06); text-align: center;
            border-top: 3px solid transparent; cursor: pointer; text-decoration: none;
            display: block; transition: box-shadow .15s;
        }
        .nc-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,.1); }
        .nc-card.purple { border-top-color: #7c3aed; }
        .nc-card.red    { border-top-color: #dc2626; }
        .nc-card.amber  { border-top-color: #d97706; }
        .nc-card.blue   { border-top-color: #2563eb; }
        .nc-num  { font-size: 30px; font-weight: 800; color: #1e293b; line-height: 1; }
        .nc-lbl  { font-size: 12px; font-weight: 600; color: #64748b; margin-top: 4px; text-transform: uppercase; letter-spacing:.06em; }
        .nc-icon { font-size: 22px; margin-bottom: 4px; }
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
            <a href="admin_users.php"><span class="icon">👥</span> User Management</a>
            <a href="admin_transactions.php"><span class="icon">🔄</span> Transactions</a>
            <a href="admin_adjustment.php"><span class="icon">⚙️</span> Adjustments</a>
            <a href="admin_notifications.php" class="active">
                <span class="icon">🔔</span> Notifications
                <?php if ($notif_unread > 0): ?>
                    <span class="sidebar-badge"><?php echo $notif_unread > 99 ? '99+' : $notif_unread; ?></span>
                <?php endif; ?>
            </a>
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

        <div class="page-header">
            <div>
                <h1>🔔 Notifications Center</h1>
                <p>Real-time alerts for all platform activity</p>
            </div>
            <span class="admin-badge">🔐 Administrator</span>
        </div>

        <!-- Counter cards -->
        <div class="notif-counters">
            <a href="admin_notifications.php" class="nc-card purple">
                <div class="nc-icon">🔔</div>
                <div class="nc-num"><?php echo number_format($cnt['total_active']); ?></div>
                <div class="nc-lbl">Total Active</div>
            </a>
            <a href="admin_notifications.php?view=unread" class="nc-card red">
                <div class="nc-icon">📬</div>
                <div class="nc-num"><?php echo number_format($cnt['unread']); ?></div>
                <div class="nc-lbl">Unread</div>
            </a>
            <a href="admin_notifications.php?view=critical" class="nc-card amber">
                <div class="nc-icon">🚨</div>
                <div class="nc-num"><?php echo number_format($cnt['critical_unread']); ?></div>
                <div class="nc-lbl">Critical Alerts</div>
            </a>
            <a href="admin_notifications.php?cat=security" class="nc-card blue">
                <div class="nc-icon">🔒</div>
                <div class="nc-num"><?php echo number_format($cnt['security_unread']); ?></div>
                <div class="nc-lbl">Security Alerts</div>
            </a>
        </div>

        <!-- Notifications list -->
        <div class="section-card">
            <!-- Filter bar -->
            <div class="filter-bar">
                <a href="admin_notifications.php" class="fb-tab <?php echo ($f_view===''&&$f_cat==='') ? 'active' : ''; ?>">All</a>
                <a href="?view=unread"   class="fb-tab <?php echo $f_view==='unread'   ? 'active' : ''; ?>">
                    📬 Unread <?php if ($cnt['unread'] > 0): ?><span style="background:#dc2626;color:white;border-radius:8px;padding:0 5px;font-size:10px;"><?php echo $cnt['unread']; ?></span><?php endif; ?>
                </a>
                <a href="?view=critical" class="fb-tab critical <?php echo $f_view==='critical' ? 'active' : ''; ?>">🚨 Critical</a>
                <a href="?cat=transactions"  class="fb-tab <?php echo $f_cat==='transactions'  ? 'active' : ''; ?>">💳 Transactions</a>
                <a href="?cat=security"      class="fb-tab security <?php echo $f_cat==='security'      ? 'active' : ''; ?>">🔒 Security</a>
                <a href="?cat=user_accounts" class="fb-tab <?php echo $f_cat==='user_accounts' ? 'active' : ''; ?>">👤 User Accounts</a>
                <a href="?cat=admin_actions" class="fb-tab <?php echo $f_cat==='admin_actions' ? 'active' : ''; ?>">⚙️ Admin Actions</a>
                <a href="?view=archived"     class="fb-tab <?php echo $f_view==='archived'     ? 'active' : ''; ?>">🗄 Archived</a>

                <?php if ($cnt['unread'] > 0 && $f_view !== 'archived'): ?>
                <form method="POST" action="admin_notify_action.php" style="margin-left:auto;">
                    <input type="hidden" name="action"   value="mark_all_read">
                    <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($current_url); ?>">
                    <button type="submit" class="mark-all-btn" onclick="return confirm('Mark all notifications as read?')">
                        ✓ Mark All Read
                    </button>
                </form>
                <?php endif; ?>
            </div>

            <!-- Priority sub-filter -->
            <div class="pri-row" style="padding:10px 22px;border-bottom:1px solid #f1f5f9;">
                <span>Priority:</span>
                <a href="admin_notifications.php<?php echo htmlspecialchars(qs(['pri'=>'','page'=>1])); ?>" class="pri-link <?php echo $f_pri===''?'active':''; ?>">All</a>
                <?php foreach (['low','medium','high','critical'] as $p): ?>
                <a href="admin_notifications.php<?php echo htmlspecialchars(qs(['pri'=>$p,'page'=>1])); ?>" class="pri-link <?php echo $f_pri===$p?'active':''; ?>">
                    <?php echo ucfirst($p); ?>
                </a>
                <?php endforeach; ?>
                <span style="margin-left:auto;color:#94a3b8;">
                    <?php echo number_format($total_rows); ?> notification<?php echo $total_rows!==1?'s':''; ?>
                </span>
            </div>

            <!-- Notification items -->
            <div class="notif-list">
            <?php if (empty($notifications)): ?>
                <div class="empty-notif">
                    <div class="emoji">🔔</div>
                    <p>No notifications match your filters.</p>
                </div>
            <?php else: ?>
                <?php foreach ($notifications as $n):
                    [$icon, $label, $cat] = eventMeta($n['event_type']);
                    $is_unread = !$n['is_read'];
                    $pri       = $n['priority'];
                    [$pri_bg, $pri_fg] = $priority_colors[$pri] ?? ['#f1f5f9','#475569'];
                    $cat_css = htmlspecialchars($n['category']);

                    // Build "View related" URL
                    $view_url = null;
                    if ($n['transaction_id'])  $view_url = 'admin_adjustment.php?tx_id=' . $n['transaction_id'];
                    if ($n['adjustment_id'])   $view_url = 'admin_adjustment.php';
                    if ($n['uid'])             $view_url = $view_url ?? 'admin_user_profile.php?id=' . $n['uid'];
                ?>
                <div class="notif-card <?php echo $is_unread ? 'unread' : ''; ?> <?php echo $n['is_archived'] ? 'archived' : ''; ?>"
                     id="notif-<?php echo $n['id']; ?>">
                    <!-- Unread indicator dot -->
                    <div class="unread-dot <?php echo $is_unread ? '' : 'hidden'; ?>"></div>

                    <!-- Category icon -->
                    <div class="notif-icon <?php echo $cat_css; ?>"><?php echo $icon; ?></div>

                    <!-- Body -->
                    <div class="notif-body">
                        <div class="notif-top">
                            <span class="notif-title"><?php echo htmlspecialchars($n['title']); ?></span>
                            <span class="pri-badge" style="background:<?php echo $pri_bg; ?>;color:<?php echo $pri_fg; ?>;">
                                <?php echo ucfirst($pri); ?>
                            </span>
                            <span style="background:#f1f5f9;color:#64748b;padding:2px 8px;border-radius:12px;font-size:10px;font-weight:600;">
                                <?php echo $category_labels[$n['category']] ?? $n['category']; ?>
                            </span>
                        </div>
                        <div class="notif-desc"><?php echo htmlspecialchars($n['description']); ?></div>
                        <div class="notif-meta">
                            <span>🆔 #<?php echo $n['id']; ?></span>
                            <?php if ($n['uid']): ?>
                            <span>👤 <a href="admin_user_profile.php?id=<?php echo $n['uid']; ?>">
                                <?php echo htmlspecialchars($n['fullname']); ?>
                            </a></span>
                            <?php endif; ?>
                            <?php if ($n['reference']): ?>
                            <span>📎 <code style="font-size:10px;"><?php echo htmlspecialchars($n['reference']); ?></code></span>
                            <?php endif; ?>
                            <span>🕐 <?php echo formatDate($n['created_at']); ?></span>
                            <?php if ($n['is_read'] && $n['read_by_name']): ?>
                            <span style="color:#16a34a;">✓ Read by <?php echo htmlspecialchars($n['read_by_name']); ?> · <?php echo formatDate($n['read_at']); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="notif-actions">
                        <?php if ($view_url): ?>
                        <a href="<?php echo htmlspecialchars($view_url); ?>" class="na-btn na-view">View →</a>
                        <?php endif; ?>

                        <?php if ($is_unread): ?>
                        <form method="POST" action="admin_notify_action.php">
                            <input type="hidden" name="action"   value="mark_read">
                            <input type="hidden" name="id"       value="<?php echo $n['id']; ?>">
                            <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($current_url); ?>">
                            <button type="submit" class="na-btn na-read">✓ Read</button>
                        </form>
                        <?php else: ?>
                        <form method="POST" action="admin_notify_action.php">
                            <input type="hidden" name="action"   value="mark_unread">
                            <input type="hidden" name="id"       value="<?php echo $n['id']; ?>">
                            <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($current_url); ?>">
                            <button type="submit" class="na-btn na-unread">○ Unread</button>
                        </form>
                        <?php endif; ?>

                        <?php if (!$n['is_archived']): ?>
                        <form method="POST" action="admin_notify_action.php">
                            <input type="hidden" name="action"   value="archive">
                            <input type="hidden" name="id"       value="<?php echo $n['id']; ?>">
                            <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($current_url); ?>">
                            <button type="submit" class="na-btn na-archive">🗄</button>
                        </form>
                        <?php else: ?>
                        <form method="POST" action="admin_notify_action.php">
                            <input type="hidden" name="action"   value="unarchive">
                            <input type="hidden" name="id"       value="<?php echo $n['id']; ?>">
                            <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($current_url); ?>">
                            <button type="submit" class="na-btn na-restore">↩ Restore</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?><a href="admin_notifications.php<?php echo htmlspecialchars(qs(['page'=>$page-1])); ?>">← Prev</a><?php endif; ?>
                <?php for ($p = max(1,$page-2); $p <= min($total_pages,$page+2); $p++): ?>
                    <?php if ($p===$page): ?><span class="cur"><?php echo $p; ?></span>
                    <?php else: ?><a href="admin_notifications.php<?php echo htmlspecialchars(qs(['page'=>$p])); ?>"><?php echo $p; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?><a href="admin_notifications.php<?php echo htmlspecialchars(qs(['page'=>$page+1])); ?>">Next →</a><?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </main>
</div>
<script>
// Auto-refresh unread counter in sidebar badge every 30 s
(function poll() {
    fetch('api/notifications_count.php')
        .then(r => r.json())
        .then(d => {
            // Refresh counters on this page without full reload (counters in stat cards)
            document.querySelectorAll('.nc-card').forEach(c => {});
        })
        .catch(() => {});
    setTimeout(poll, 30000);
})();
</script>
</body>
</html>
