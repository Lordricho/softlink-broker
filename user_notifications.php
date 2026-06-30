<?php
include('config/db.php');
include('config/auth.php');
include('config/helpers.php');
include_once('config/user_notify.php');

$user_id = requireAuth();

// ── Flash ─────────────────────────────────────────────────────────────────────
$flash      = $_GET['success'] ?? '';
$flash_type = 'success';
if (empty($flash)) { $flash = $_GET['error'] ?? ''; $flash_type = 'error'; }

// ── Filters ───────────────────────────────────────────────────────────────────
$f_view = trim($_GET['view'] ?? '');   // unread | important
$f_cat  = trim($_GET['cat']  ?? '');   // transactions | account | security
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 25;
$offset   = ($page - 1) * $per_page;

// ── WHERE ──────────────────────────────────────────────────────────────────────
$where  = ['n.user_id = :uid', 'n.is_deleted = FALSE'];
$params = [':uid' => $user_id];

if ($f_view === 'unread')    { $where[] = 'n.is_read = FALSE'; }
if ($f_view === 'important') { $where[] = "n.priority IN ('high','critical')"; }

$cat_map = [
    'transactions' => ["'deposit_confirmed'","'withdrawal_confirmed'","'adjustment_applied'"],
    'account'      => ["'welcome'","'account_verified'","'account_unverified'","'account_suspended'","'account_reactivated'","'admin_role_changed'","'password_changed'"],
    'security'     => ["'security_alert'","'suspended_login_attempt'"],
];
if (isset($cat_map[$f_cat])) {
    $where[] = 'n.event_type IN (' . implode(',', $cat_map[$f_cat]) . ')';
}

$where_sql = 'WHERE ' . implode(' AND ', $where);

try {
    $user_stmt = $conn->prepare('SELECT fullname, email FROM users WHERE id = :id');
    $user_stmt->execute([':id' => $user_id]);
    $user = $user_stmt->fetch();
    if (!$user) { session_destroy(); header('Location: login.php'); exit(); }

    // Dashboard counters
    $cnt = $conn->prepare("
        SELECT
            COUNT(*)                                                              AS total,
            SUM(CASE WHEN is_read = FALSE THEN 1 ELSE 0 END)                     AS unread,
            SUM(CASE WHEN priority IN ('high','critical') AND is_read = FALSE THEN 1 ELSE 0 END) AS important
        FROM user_notifications WHERE user_id = :uid AND is_deleted = FALSE
    ");
    $cnt->execute([':uid' => $user_id]);
    $counters = $cnt->fetch();

    // Total matching rows
    $cnt2 = $conn->prepare("SELECT COUNT(*) FROM user_notifications n {$where_sql}");
    $cnt2->execute($params);
    $total_rows  = (int)$cnt2->fetchColumn();
    $total_pages = max(1, ceil($total_rows / $per_page));

    // Notifications list
    $list = $conn->prepare("
        SELECT n.id, n.event_type, n.priority, n.title, n.message,
               n.is_read, n.read_at, n.created_at, n.transaction_id
        FROM user_notifications n
        {$where_sql}
        ORDER BY n.is_read ASC, n.created_at DESC
        LIMIT :lim OFFSET :off
    ");
    foreach ($params as $k => $v) $list->bindValue($k, $v);
    $list->bindValue(':lim', $per_page, PDO::PARAM_INT);
    $list->bindValue(':off', $offset,   PDO::PARAM_INT);
    $list->execute();
    $notifications = $list->fetchAll();

    $unread_count = (int)$counters['unread'];

} catch (PDOException $e) {
    error_log('User notifications page error: ' . $e->getMessage());
    die('Error loading notifications. Please try again.');
}

// ── Helpers ────────────────────────────────────────────────────────────────────
function uqs(array $over = []): string {
    $keys = ['view','cat','page'];
    $base = [];
    foreach ($keys as $k) if (!empty($_GET[$k])) $base[$k] = $_GET[$k];
    $m = array_filter(array_merge($base, $over), fn($v) => $v !== '' && $v !== null);
    return $m ? '?' . http_build_query($m) : '';
}

$event_icons = [
    'deposit_confirmed'    => ['📥', '#dcfce7', '#15803d'],
    'withdrawal_confirmed' => ['📤', '#dbeafe', '#1d4ed8'],
    'adjustment_applied'   => ['⚙️', '#fef9c3', '#a16207'],
    'welcome'              => ['🎉', '#ede9fe', '#7c3aed'],
    'account_verified'     => ['✅', '#dcfce7', '#15803d'],
    'account_unverified'   => ['❌', '#fee2e2', '#b91c1c'],
    'account_suspended'    => ['🚫', '#fee2e2', '#b91c1c'],
    'account_reactivated'  => ['✅', '#dcfce7', '#15803d'],
    'admin_role_changed'   => ['🔐', '#ede9fe', '#7c3aed'],
    'password_changed'     => ['🔑', '#fef9c3', '#a16207'],
    'security_alert'       => ['🚨', '#fee2e2', '#b91c1c'],
    'admin_message'        => ['📢', '#e0f2fe', '#0369a1'],
];
function notifIcon(string $type): array {
    global $event_icons;
    return $event_icons[$type] ?? ['🔔', '#f1f5f9', '#475569'];
}

$priority_styles = [
    'low'      => ['#f1f5f9','#475569'],
    'medium'   => ['#e0f2fe','#0369a1'],
    'high'     => ['#fef9c3','#a16207'],
    'critical' => ['#fee2e2','#b91c1c'],
];

$current_url = 'user_notifications.php' . uqs();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Notifications — Softlink Broker</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        /* ── Layout ── */
        .page-wrap {
            display: grid;
            grid-template-columns: 230px 1fr;
            gap: 24px;
            max-width: 1200px;
            margin: 28px auto;
            padding: 0 20px;
        }
        @media(max-width:768px){ .page-wrap{grid-template-columns:1fr;} }

        /* ── Sidebar ── */
        .u-sidebar {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 20px;
            height: fit-content;
        }
        .u-sidebar h3 { margin: 0 0 16px; font-size: 14px; color: #94a3b8; text-transform: uppercase; letter-spacing:.08em; font-weight:700; }
        .u-sidebar a {
            display: flex; align-items: center; gap: 10px;
            padding: 9px 12px; border-radius: 8px;
            text-decoration: none; color: #374151;
            font-size: 14px; font-weight: 500; margin-bottom: 2px;
        }
        .u-sidebar a:hover  { background: #f8fafc; }
        .u-sidebar a.active { background: #7c3aed; color: white; }
        .u-sidebar hr { border: none; border-top: 1px solid #f1f5f9; margin: 12px 0; }
        .u-notif-badge {
            margin-left: auto; background: #dc2626; color: white;
            border-radius: 10px; padding: 1px 7px; font-size: 10px;
            font-weight: 800; display: none;
        }

        /* ── Main ── */
        .u-main { min-width: 0; }
        .u-page-header {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 20px; flex-wrap: wrap; gap: 12px;
        }
        .u-page-header h1 { margin: 0; font-size: 22px; color: #1e293b; }
        .u-page-header p  { margin: 4px 0 0; font-size: 13px; color: #64748b; }

        /* ── Flash ── */
        .u-flash {
            padding: 12px 16px; border-radius: 8px; margin-bottom: 16px;
            font-size: 14px; font-weight: 500;
        }
        .u-flash.success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .u-flash.error   { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

        /* ── Counter cards ── */
        .u-counters {
            display: grid; grid-template-columns: repeat(3, 1fr);
            gap: 14px; margin-bottom: 20px;
        }
        @media(max-width:600px){ .u-counters{grid-template-columns:1fr;} }
        .u-counter {
            background: #fff; border: 1px solid #e2e8f0; border-radius: 12px;
            padding: 16px 18px; text-align: center; text-decoration: none;
            display: block; transition: box-shadow .15s; border-top: 3px solid transparent;
        }
        .u-counter:hover { box-shadow: 0 4px 12px rgba(0,0,0,.08); }
        .u-counter.purple { border-top-color: #7c3aed; }
        .u-counter.red    { border-top-color: #dc2626; }
        .u-counter.amber  { border-top-color: #d97706; }
        .u-counter-num  { font-size: 28px; font-weight: 800; color: #1e293b; line-height: 1; }
        .u-counter-lbl  { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing:.06em; margin-top: 4px; }

        /* ── Notification card ── */
        .notif-panel { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; overflow: hidden; }
        .notif-topbar {
            display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
            padding: 14px 20px; border-bottom: 1px solid #f1f5f9;
        }
        .tab-link {
            padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 600;
            text-decoration: none; background: #f1f5f9; color: #475569; white-space: nowrap;
        }
        .tab-link:hover  { background: #e2e8f0; }
        .tab-link.active { background: #7c3aed; color: white; }
        .tab-link.red.active    { background: #dc2626; }
        .tab-link.amber.active  { background: #d97706; }

        .notif-card {
            display: flex; gap: 14px; align-items: flex-start;
            padding: 16px 20px; border-bottom: 1px solid #f8fafc;
            transition: background .12s;
        }
        .notif-card:last-child { border-bottom: none; }
        .notif-card:hover { background: #fafafa; }
        .notif-card.unread { background: #f5f3ff; }
        .notif-card.unread:hover { background: #ede9fe; }

        /* Unread dot */
        .u-dot { width: 8px; height: 8px; border-radius: 50%; background: #7c3aed; flex-shrink: 0; margin-top: 7px; }
        .u-dot.off { background: transparent; }

        /* Icon */
        .notif-ico {
            width: 40px; height: 40px; border-radius: 10px; display: flex;
            align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0;
        }

        .notif-body { flex: 1; min-width: 0; }
        .notif-title { font-weight: 700; color: #1e293b; font-size: 14px; margin-bottom: 3px; }
        .notif-msg   { font-size: 13px; color: #64748b; line-height: 1.5; margin-bottom: 6px; }
        .notif-meta  { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; font-size: 11px; color: #94a3b8; }
        .notif-meta a { color: #7c3aed; text-decoration: none; font-weight: 600; }
        .notif-meta a:hover { text-decoration: underline; }

        /* Priority pill */
        .pri-pill {
            padding: 2px 8px; border-radius: 20px; font-size: 10px;
            font-weight: 800; text-transform: uppercase; letter-spacing:.05em;
        }

        /* Actions */
        .n-actions { display: flex; gap: 6px; flex-shrink: 0; flex-wrap: wrap; align-items: flex-start; }
        .n-btn {
            padding: 5px 11px; border: none; border-radius: 6px; font-size: 11px;
            font-weight: 700; cursor: pointer; text-decoration: none;
            display: inline-block; white-space: nowrap;
        }
        .n-read    { background: #dcfce7; color: #15803d; }
        .n-read:hover    { background: #bbf7d0; }
        .n-view    { background: #dbeafe; color: #1d4ed8; }
        .n-view:hover    { background: #bfdbfe; }
        .n-delete  { background: #fee2e2; color: #b91c1c; }
        .n-delete:hover  { background: #fecaca; }

        .mark-all-btn {
            margin-left: auto; padding: 7px 14px; background: #16a34a; color: white;
            border: none; border-radius: 8px; font-size: 12px; font-weight: 700;
            cursor: pointer; white-space: nowrap;
        }
        .mark-all-btn:hover { background: #15803d; }
        .del-read-btn {
            padding: 7px 14px; background: #f1f5f9; color: #64748b;
            border: none; border-radius: 8px; font-size: 12px; font-weight: 700;
            cursor: pointer; white-space: nowrap;
        }
        .del-read-btn:hover { background: #e2e8f0; }

        .empty-state { text-align: center; padding: 52px 24px; color: #94a3b8; }
        .empty-state .e-icon { font-size: 44px; margin-bottom: 10px; }
        .empty-state p { font-size: 14px; margin: 0; }

        .pagination { display: flex; gap: 6px; padding: 14px 20px; justify-content: center; flex-wrap: wrap; }
        .pagination a, .pagination span { padding: 6px 13px; border-radius: 7px; font-size: 13px; font-weight: 600; text-decoration: none; }
        .pagination a { background: #f1f5f9; color: #475569; }
        .pagination a:hover { background: #e2e8f0; }
        .pagination .cur { background: #7c3aed; color: white; }
    </style>
</head>
<body style="background:#f8fafc;margin:0;">

<header class="navbar">
    <h2>Softlink Broker</h2>
    <nav>
        <span style="color:rgba(255,255,255,.75);font-size:14px;">
            Welcome, <?php echo htmlspecialchars($user['fullname']); ?>
        </span>
    </nav>
</header>

<div class="page-wrap">
    <!-- Sidebar -->
    <aside class="u-sidebar">
        <h3>My Account</h3>
        <a href="dashboard.php">📊 Dashboard</a>
        <a href="deposit.php">💰 Deposit</a>
        <a href="withdraw.php">🏦 Withdraw</a>
        <a href="user_notifications.php" class="active">
            🔔 Notifications
            <span id="user-notif-badge" class="u-notif-badge"><?php echo $unread_count > 99 ? '99+' : $unread_count; ?></span>
        </a>
        <a href="settings.php">⚙️ Settings</a>
        <hr>
        <?php if (!empty($_SESSION['is_admin'])): ?>
        <a href="admin_dashboard.php" style="color:#7c3aed;font-weight:700;">🔐 Admin Panel</a>
        <?php endif; ?>
        <a href="config/logout.php">🚪 Logout</a>
    </aside>

    <!-- Main -->
    <div class="u-main">
        <?php if ($flash): ?>
        <div class="u-flash <?php echo $flash_type; ?>"><?php echo htmlspecialchars($flash); ?></div>
        <?php endif; ?>

        <div class="u-page-header">
            <div>
                <h1>🔔 My Notifications</h1>
                <p>Stay up-to-date with your account activity</p>
            </div>
        </div>

        <!-- Counter cards -->
        <div class="u-counters">
            <a href="user_notifications.php" class="u-counter purple">
                <div class="u-counter-num"><?php echo number_format($counters['total']); ?></div>
                <div class="u-counter-lbl">Total</div>
            </a>
            <a href="?view=unread" class="u-counter red">
                <div class="u-counter-num"><?php echo number_format($counters['unread']); ?></div>
                <div class="u-counter-lbl">Unread</div>
            </a>
            <a href="?view=important" class="u-counter amber">
                <div class="u-counter-num"><?php echo number_format($counters['important']); ?></div>
                <div class="u-counter-lbl">Important</div>
            </a>
        </div>

        <!-- Notifications panel -->
        <div class="notif-panel">
            <!-- Tab bar -->
            <div class="notif-topbar">
                <a href="user_notifications.php"  class="tab-link <?php echo ($f_view===''&&$f_cat==='') ? 'active' : ''; ?>">All</a>
                <a href="?view=unread"    class="tab-link red   <?php echo $f_view==='unread'    ? 'active' : ''; ?>">
                    📬 Unread <?php if ($counters['unread'] > 0): ?><span style="background:#dc2626;color:white;border-radius:8px;padding:0 5px;font-size:10px;"><?php echo $counters['unread']; ?></span><?php endif; ?>
                </a>
                <a href="?view=important" class="tab-link amber <?php echo $f_view==='important' ? 'active' : ''; ?>">⭐ Important</a>
                <a href="?cat=transactions" class="tab-link <?php echo $f_cat==='transactions' ? 'active' : ''; ?>">💳 Transactions</a>
                <a href="?cat=account"      class="tab-link <?php echo $f_cat==='account'      ? 'active' : ''; ?>">👤 Account</a>
                <a href="?cat=security"     class="tab-link <?php echo $f_cat==='security'     ? 'active' : ''; ?>">🔒 Security</a>

                <?php if ($counters['unread'] > 0): ?>
                <form method="POST" action="user_notify_action.php" style="margin-left:auto;">
                    <input type="hidden" name="action"   value="mark_all_read">
                    <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($current_url); ?>">
                    <button type="submit" class="mark-all-btn">✓ Mark All Read</button>
                </form>
                <?php endif; ?>

                <form method="POST" action="user_notify_action.php">
                    <input type="hidden" name="action"   value="delete_all_read">
                    <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($current_url); ?>">
                    <button type="submit" class="del-read-btn" onclick="return confirm('Delete all read notifications?')">🗑 Clear Read</button>
                </form>
            </div>

            <!-- Count line -->
            <div style="padding:8px 20px;font-size:12px;color:#94a3b8;border-bottom:1px solid #f1f5f9;">
                <?php echo number_format($total_rows); ?> notification<?php echo $total_rows !== 1 ? 's' : ''; ?>
            </div>

            <!-- Items -->
            <?php if (empty($notifications)): ?>
            <div class="empty-state">
                <div class="e-icon">🔔</div>
                <p>No notifications here. You're all caught up!</p>
            </div>
            <?php else: ?>
            <?php foreach ($notifications as $n):
                [$ico, $ico_bg, $ico_fg] = notifIcon($n['event_type']);
                $is_unread = !$n['is_read'];
                [$pri_bg, $pri_fg] = $priority_styles[$n['priority']] ?? ['#f1f5f9','#475569'];
                $view_url = $n['transaction_id'] ? 'dashboard.php' : null;
            ?>
            <div class="notif-card <?php echo $is_unread ? 'unread' : ''; ?>">
                <div class="u-dot <?php echo $is_unread ? '' : 'off'; ?>"></div>
                <div class="notif-ico" style="background:<?php echo $ico_bg; ?>;color:<?php echo $ico_fg; ?>;"><?php echo $ico; ?></div>

                <div class="notif-body">
                    <div class="notif-title"><?php echo htmlspecialchars($n['title']); ?></div>
                    <div class="notif-msg"><?php echo htmlspecialchars($n['message']); ?></div>
                    <div class="notif-meta">
                        <span class="pri-pill" style="background:<?php echo $pri_bg; ?>;color:<?php echo $pri_fg; ?>;">
                            <?php echo ucfirst($n['priority']); ?>
                        </span>
                        <span>🕐 <?php echo formatDate($n['created_at']); ?></span>
                        <?php if ($n['is_read'] && $n['read_at']): ?>
                        <span style="color:#16a34a;">✓ Read <?php echo formatDate($n['read_at']); ?></span>
                        <?php endif; ?>
                        <?php if ($view_url): ?>
                        <a href="<?php echo htmlspecialchars($view_url); ?>">View transaction →</a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="n-actions">
                    <?php if ($view_url): ?>
                    <a href="<?php echo htmlspecialchars($view_url); ?>" class="n-btn n-view">View →</a>
                    <?php endif; ?>

                    <?php if ($is_unread): ?>
                    <form method="POST" action="user_notify_action.php">
                        <input type="hidden" name="action"   value="mark_read">
                        <input type="hidden" name="id"       value="<?php echo $n['id']; ?>">
                        <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($current_url); ?>">
                        <button type="submit" class="n-btn n-read">✓ Read</button>
                    </form>
                    <?php endif; ?>

                    <form method="POST" action="user_notify_action.php">
                        <input type="hidden" name="action"   value="delete">
                        <input type="hidden" name="id"       value="<?php echo $n['id']; ?>">
                        <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($current_url); ?>">
                        <button type="submit" class="n-btn n-delete" onclick="return confirm('Delete this notification?')">🗑</button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?><a href="user_notifications.php<?php echo htmlspecialchars(uqs(['page'=>$page-1])); ?>">← Prev</a><?php endif; ?>
                <?php for ($p = max(1,$page-2); $p <= min($total_pages,$page+2); $p++): ?>
                    <?php if ($p===$page): ?><span class="cur"><?php echo $p; ?></span>
                    <?php else: ?><a href="user_notifications.php<?php echo htmlspecialchars(uqs(['page'=>$p])); ?>"><?php echo $p; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?><a href="user_notifications.php<?php echo htmlspecialchars(uqs(['page'=>$page+1])); ?>">Next →</a><?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
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
