<?php
include('config/db.php');
include('config/auth.php');
include('config/helpers.php');
include_once('config/user_notify.php');

$user_id = requireAuth();

// ── Messages ──────────────────────────────────────────────────────────────────
$msgs = ['profile'=>'','password'=>'','notif'=>'','security'=>''];
$types = ['profile'=>'','password'=>'','notif'=>'','security'=>''];

function setMsg(string &$m, string &$t, string $msg, string $type): void {
    $m = $msg; $t = $type;
}

// ── Load user + settings + recent logins ─────────────────────────────────────
try {
    $stmt = $conn->prepare('SELECT id, fullname, email, phone, balance, is_verified, is_admin, profile_picture, created_at, last_login_at FROM users WHERE id = :id');
    $stmt->execute([':id' => $user_id]);
    $user = $stmt->fetch();
    if (!$user) { session_destroy(); header('Location: login.php'); exit(); }

    // Notification preferences (create defaults if missing)
    $np = $conn->prepare('SELECT * FROM user_settings WHERE user_id = :uid');
    $np->execute([':uid' => $user_id]);
    $notif_prefs = $np->fetch();
    if (!$notif_prefs) {
        $conn->prepare('INSERT INTO user_settings (user_id) VALUES (:uid) ON CONFLICT DO NOTHING')->execute([':uid' => $user_id]);
        $notif_prefs = [
            'notif_deposits'=>true,'notif_withdrawals'=>true,'notif_tx_status'=>true,
            'notif_account'=>true,'notif_security'=>true,'notif_admin_messages'=>true,
        ];
    }

    // Recent login activity (last 10)
    $la = $conn->prepare('SELECT login_time, ip_address, user_agent, status FROM login_logs WHERE user_id = :uid ORDER BY login_time DESC LIMIT 10');
    $la->execute([':uid' => $user_id]);
    $login_history = $la->fetchAll();

    // Unread notification count for sidebar badge
    $ns = $conn->prepare('SELECT COUNT(*) FROM user_notifications WHERE user_id = :uid AND is_read = FALSE AND is_deleted = FALSE');
    $ns->execute([':uid' => $user_id]);
    $unread_notif = (int)$ns->fetchColumn();

} catch (PDOException $e) {
    error_log('Settings load error: ' . $e->getMessage());
    die('Error loading settings. Please try again.');
}

// ── Handle POST actions ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Update Profile ────────────────────────────────────────────────────────
    if ($action === 'update_profile') {
        $fullname = sanitizeInput($_POST['fullname'] ?? '');
        $phone    = sanitizeInput($_POST['phone']    ?? '');
        $email    = sanitizeInput($_POST['email']    ?? '');

        if (empty($fullname) || empty($email)) {
            setMsg($msgs['profile'], $types['profile'], 'Full name and email are required.', 'error');
        } elseif (!validateEmail($email)) {
            setMsg($msgs['profile'], $types['profile'], 'Invalid email format.', 'error');
        } elseif (!empty($phone) && !validatePhone($phone)) {
            setMsg($msgs['profile'], $types['profile'], 'Invalid phone number (10–15 digits).', 'error');
        } else {
            try {
                if ($email !== $user['email']) {
                    $dup = $conn->prepare('SELECT id FROM users WHERE email = :email AND id != :id');
                    $dup->execute([':email' => $email, ':id' => $user_id]);
                    if ($dup->rowCount() > 0) {
                        setMsg($msgs['profile'], $types['profile'], 'That email is already in use by another account.', 'error');
                        goto render;
                    }
                }

                // Handle profile picture upload
                $picture_sql  = '';
                $picture_param = [];
                if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
                    $file     = $_FILES['profile_picture'];
                    $allowed  = ['image/jpeg','image/png','image/gif','image/webp'];
                    $max_size = 2 * 1024 * 1024; // 2 MB
                    if (!in_array($file['type'], $allowed, true)) {
                        setMsg($msgs['profile'], $types['profile'], 'Profile picture must be JPG, PNG, GIF, or WebP.', 'error');
                        goto render;
                    }
                    if ($file['size'] > $max_size) {
                        setMsg($msgs['profile'], $types['profile'], 'Profile picture must be under 2 MB.', 'error');
                        goto render;
                    }
                    $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
                    $filename = 'avatar_' . $user_id . '_' . time() . '.' . strtolower($ext);
                    $dest     = 'uploads/avatars/' . $filename;
                    if (move_uploaded_file($file['tmp_name'], $dest)) {
                        // Remove old picture if it exists
                        if ($user['profile_picture'] && file_exists($user['profile_picture'])) {
                            @unlink($user['profile_picture']);
                        }
                        $picture_sql    = ', profile_picture = :picture';
                        $picture_param  = [':picture' => $dest];
                        $user['profile_picture'] = $dest;
                    }
                }

                $upd = $conn->prepare(
                    'UPDATE users SET fullname = :fullname, email = :email, phone = :phone' . $picture_sql . ' WHERE id = :id'
                );
                $upd->execute(array_merge([
                    ':fullname' => $fullname,
                    ':email'    => $email,
                    ':phone'    => $phone,
                    ':id'       => $user_id,
                ], $picture_param));

                $user['fullname'] = $fullname;
                $user['email']    = $email;
                $user['phone']    = $phone;
                $_SESSION['user']['fullname'] = $fullname;
                $_SESSION['user']['email']    = $email;

                setMsg($msgs['profile'], $types['profile'], 'Profile updated successfully.', 'success');
            } catch (PDOException $e) {
                error_log('Profile update error: ' . $e->getMessage());
                setMsg($msgs['profile'], $types['profile'], 'Update failed. Please try again.', 'error');
            }
        }
    }

    // ── Change Password ────────────────────────────────────────────────────────
    elseif ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password']     ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (empty($current) || empty($new) || empty($confirm)) {
            setMsg($msgs['password'], $types['password'], 'All password fields are required.', 'error');
        } elseif (!validatePassword($new)) {
            setMsg($msgs['password'], $types['password'], 'New password must be at least 6 characters.', 'error');
        } elseif ($new !== $confirm) {
            setMsg($msgs['password'], $types['password'], 'New passwords do not match.', 'error');
        } else {
            try {
                $pw = $conn->prepare('SELECT password FROM users WHERE id = :id');
                $pw->execute([':id' => $user_id]);
                $row = $pw->fetch();

                if (!password_verify($current, $row['password'])) {
                    setMsg($msgs['password'], $types['password'], 'Current password is incorrect.', 'error');
                } else {
                    $hashed = password_hash($new, PASSWORD_DEFAULT);
                    $conn->prepare('UPDATE users SET password = :password WHERE id = :id')
                         ->execute([':password' => $hashed, ':id' => $user_id]);
                    // Notify user
                    createUserNotification($conn, [
                        'user_id'    => $user_id,
                        'event_type' => 'password_changed',
                        'priority'   => 'medium',
                        'title'      => '🔑 Password Changed',
                        'message'    => 'Your account password was changed successfully. If you did not do this, please contact support immediately.',
                    ]);
                    setMsg($msgs['password'], $types['password'], 'Password changed successfully.', 'success');
                }
            } catch (PDOException $e) {
                error_log('Password change error: ' . $e->getMessage());
                setMsg($msgs['password'], $types['password'], 'Password change failed. Please try again.', 'error');
            }
        }
    }

    // ── Notification Preferences ───────────────────────────────────────────────
    elseif ($action === 'update_notifications') {
        try {
            $conn->prepare("
                INSERT INTO user_settings
                    (user_id, notif_deposits, notif_withdrawals, notif_tx_status, notif_account, notif_security, notif_admin_messages, updated_at)
                VALUES
                    (:uid, :dep, :wdr, :txs, :acc, :sec, :adm, CURRENT_TIMESTAMP)
                ON CONFLICT (user_id) DO UPDATE SET
                    notif_deposits       = :dep,
                    notif_withdrawals    = :wdr,
                    notif_tx_status      = :txs,
                    notif_account        = :acc,
                    notif_security       = :sec,
                    notif_admin_messages = :adm,
                    updated_at           = CURRENT_TIMESTAMP
            ")->execute([
                ':uid' => $user_id,
                ':dep' => isset($_POST['notif_deposits'])       ? 'TRUE' : 'FALSE',
                ':wdr' => isset($_POST['notif_withdrawals'])    ? 'TRUE' : 'FALSE',
                ':txs' => isset($_POST['notif_tx_status'])      ? 'TRUE' : 'FALSE',
                ':acc' => isset($_POST['notif_account'])        ? 'TRUE' : 'FALSE',
                ':sec' => isset($_POST['notif_security'])       ? 'TRUE' : 'FALSE',
                ':adm' => isset($_POST['notif_admin_messages']) ? 'TRUE' : 'FALSE',
            ]);
            // Refresh local copy
            $notif_prefs['notif_deposits']       = isset($_POST['notif_deposits']);
            $notif_prefs['notif_withdrawals']    = isset($_POST['notif_withdrawals']);
            $notif_prefs['notif_tx_status']      = isset($_POST['notif_tx_status']);
            $notif_prefs['notif_account']        = isset($_POST['notif_account']);
            $notif_prefs['notif_security']       = isset($_POST['notif_security']);
            $notif_prefs['notif_admin_messages'] = isset($_POST['notif_admin_messages']);
            setMsg($msgs['notif'], $types['notif'], 'Notification preferences saved.', 'success');
        } catch (PDOException $e) {
            error_log('Notif pref error: ' . $e->getMessage());
            setMsg($msgs['notif'], $types['notif'], 'Failed to save preferences. Please try again.', 'error');
        }
    }

    // ── Logout All Devices ─────────────────────────────────────────────────────
    elseif ($action === 'logout_all') {
        session_unset();
        session_destroy();
        header('Location: login.php?msg=logged_out_all');
        exit();
    }
}

render:
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings — Softlink Broker</title>
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
        .u-sidebar h3 { margin: 0 0 16px; font-size: 13px; color: #94a3b8; text-transform: uppercase; letter-spacing:.08em; font-weight:700; }
        .u-sidebar a {
            display: flex; align-items: center; gap: 10px;
            padding: 9px 12px; border-radius: 8px;
            text-decoration: none; color: #374151;
            font-size: 14px; font-weight: 500; margin-bottom: 2px;
        }
        .u-sidebar a:hover  { background: #f8fafc; }
        .u-sidebar a.active { background: #7c3aed; color: white; }
        .u-sidebar hr { border: none; border-top: 1px solid #f1f5f9; margin: 12px 0; }
        .notif-badge-sb {
            margin-left: auto; background: #dc2626; color: white;
            border-radius: 10px; padding: 1px 7px; font-size: 10px; font-weight: 800;
            display: <?php echo $unread_notif > 0 ? 'inline' : 'none'; ?>;
        }

        /* ── Main ── */
        .u-main { min-width: 0; }

        /* ── Page header ── */
        .pg-header { margin-bottom: 22px; }
        .pg-header h1 { margin: 0; font-size: 22px; color: #1e293b; }
        .pg-header p  { margin: 4px 0 0; font-size: 13px; color: #64748b; }

        /* ── Account hero card ── */
        .acct-hero {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 14px; padding: 22px 26px;
            display: flex; align-items: center; gap: 18px;
            margin-bottom: 24px; color: white;
        }
        .acct-avatar {
            width: 58px; height: 58px; border-radius: 50%; flex-shrink: 0;
            background: rgba(255,255,255,.25); overflow: hidden;
            display: flex; align-items: center; justify-content: center;
            font-size: 24px; font-weight: 800;
        }
        .acct-avatar img { width:100%; height:100%; object-fit:cover; }
        .acct-hero h3 { margin: 0 0 4px; font-size: 18px; }
        .acct-hero p  { margin: 0; font-size: 13px; opacity: .85; }
        .verified-badge {
            display: inline-flex; align-items: center; gap: 4px;
            background: rgba(255,255,255,.2); border-radius: 20px;
            padding: 2px 10px; font-size: 11px; font-weight: 700; margin-top: 6px;
        }

        /* ── Settings sections ── */
        .settings-sections { display: flex; flex-direction: column; gap: 20px; }

        .s-card {
            background: #fff; border: 1px solid #e2e8f0;
            border-radius: 14px; overflow: hidden;
        }
        .s-card-head {
            padding: 16px 22px; border-bottom: 1px solid #f1f5f9;
            display: flex; align-items: center; gap: 10px;
        }
        .s-card-head h2 { margin: 0; font-size: 16px; color: #1e293b; font-weight: 700; }
        .s-card-head p  { margin: 2px 0 0; font-size: 12px; color: #94a3b8; }
        .s-card-head-icon { font-size: 20px; }
        .s-card-body { padding: 22px; }

        /* Two-column grid inside a card */
        .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        @media(max-width:640px){ .two-col{grid-template-columns:1fr;} }

        /* Form elements */
        .fg { margin-bottom: 16px; }
        .fg label { display:block; font-size:13px; font-weight:600; color:#475569; margin-bottom:5px; }
        .fg input[type=text],.fg input[type=email],.fg input[type=tel],.fg input[type=password] {
            width:100%; padding:10px 13px; border:1px solid #cbd5e1;
            border-radius:8px; font-size:14px; box-sizing:border-box;
            background:white; transition:border-color .2s,box-shadow .2s;
        }
        .fg input:focus { outline:none; border-color:#7c3aed; box-shadow:0 0 0 3px rgba(124,58,237,.12); }
        .fg input[readonly] { background:#f8fafc; color:#94a3b8; cursor:default; }
        .fg .hint { font-size:11px; color:#94a3b8; margin-top:4px; }

        /* File input */
        .fg input[type=file] {
            width:100%; padding:8px; border:1px dashed #cbd5e1;
            border-radius:8px; font-size:13px; box-sizing:border-box;
            background:#fafafa; cursor:pointer;
        }

        /* Flash messages */
        .flash-msg {
            padding: 10px 14px; border-radius: 8px; font-size: 13px;
            font-weight: 500; margin-bottom: 16px;
        }
        .flash-msg.success { background:#dcfce7; color:#166534; border:1px solid #bbf7d0; }
        .flash-msg.error   { background:#fee2e2; color:#991b1b; border:1px solid #fecaca; }

        /* Save buttons */
        .btn-save {
            padding: 11px 24px; border: none; border-radius: 8px;
            font-size: 14px; font-weight: 700; cursor: pointer;
            transition: opacity .2s; width: 100%; margin-top: 4px;
        }
        .btn-save:hover { opacity: .88; }
        .btn-profile  { background: linear-gradient(135deg,#667eea,#764ba2); color:white; }
        .btn-password { background: linear-gradient(135deg,#f093fb,#f5576c); color:white; }
        .btn-notif    { background: linear-gradient(135deg,#4facfe,#00f2fe); color:white; }
        .btn-danger   { background: #dc2626; color: white; border-radius: 8px; padding: 10px 22px; border: none; font-size: 14px; font-weight: 700; cursor: pointer; }
        .btn-danger:hover { background: #b91c1c; }

        /* Toggle switches */
        .toggle-row {
            display: flex; align-items: center; justify-content: space-between;
            padding: 12px 0; border-bottom: 1px solid #f1f5f9;
        }
        .toggle-row:last-child { border-bottom: none; padding-bottom: 0; }
        .toggle-label { font-size: 14px; color: #374151; font-weight: 500; }
        .toggle-sub   { font-size: 12px; color: #94a3b8; margin-top: 2px; }
        .toggle-switch { position: relative; width: 44px; height: 24px; flex-shrink: 0; }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .toggle-slider {
            position: absolute; cursor: pointer; inset: 0;
            background: #cbd5e1; border-radius: 24px; transition: .3s;
        }
        .toggle-slider:before {
            content:''; position:absolute; width:18px; height:18px;
            left:3px; bottom:3px; background:white; border-radius:50%; transition:.3s;
        }
        .toggle-switch input:checked + .toggle-slider { background: #7c3aed; }
        .toggle-switch input:checked + .toggle-slider:before { transform: translateX(20px); }

        /* Account info table */
        .info-table { width:100%; border-collapse:collapse; }
        .info-table td { padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 14px; vertical-align: top; }
        .info-table tr:last-child td { border-bottom: none; }
        .info-table td:first-child { color: #64748b; font-weight: 600; width: 160px; }
        .info-table td:last-child  { color: #1e293b; font-weight: 500; }

        /* Login history table */
        .login-table { width:100%; border-collapse:collapse; font-size:13px; }
        .login-table th { background:#f8fafc; color:#64748b; font-weight:700; padding:8px 12px; text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:.05em; }
        .login-table td { padding:10px 12px; border-bottom:1px solid #f1f5f9; color:#374151; vertical-align:top; }
        .login-table tr:last-child td { border-bottom: none; }
        .login-table tr:hover td { background:#fafafa; }
        .status-ok  { color:#15803d; font-weight:700; }
        .status-fail{ color:#b91c1c; font-weight:700; }
        .ua-trunc { max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }

        /* Danger zone */
        .danger-zone { border: 1px solid #fecaca; border-radius: 14px; padding: 20px 22px; background: #fff5f5; }
        .danger-zone h3 { margin: 0 0 6px; color: #b91c1c; font-size: 16px; }
        .danger-zone p  { margin: 0 0 14px; font-size: 13px; color: #64748b; }
    </style>
</head>
<body style="background:#f8fafc;margin:0;">

<header class="navbar">
    <h2>🏦 Softlink Broker</h2>
    <nav>
        <span style="color:rgba(255,255,255,.75);font-size:14px;">Welcome, <?php echo htmlspecialchars($user['fullname']); ?></span>
    </nav>
</header>

<div class="page-wrap">
    <!-- Sidebar -->
    <aside class="u-sidebar">
        <h3>My Account</h3>
        <a href="dashboard.php">📊 Dashboard</a>
        <a href="deposit.php">💰 Deposit</a>
        <a href="withdraw.php">🏦 Withdraw</a>
        <a href="user_notifications.php">
            🔔 Notifications
            <span id="user-notif-badge" class="notif-badge-sb"><?php echo $unread_notif > 99 ? '99+' : $unread_notif; ?></span>
        </a>
        <a href="settings.php" class="active">⚙️ Settings</a>
        <hr>
        <?php if (!empty($_SESSION['is_admin'])): ?>
        <a href="admin_dashboard.php" style="color:#7c3aed;font-weight:700;">🔐 Admin Panel</a>
        <?php endif; ?>
        <a href="config/logout.php">🚪 Logout</a>
    </aside>

    <!-- Main -->
    <div class="u-main">
        <div class="pg-header">
            <h1>⚙️ Account Settings</h1>
            <p>Manage your profile, security, and preferences</p>
        </div>

        <!-- Account hero card -->
        <div class="acct-hero">
            <div class="acct-avatar">
                <?php if ($user['profile_picture'] && file_exists($user['profile_picture'])): ?>
                    <img src="<?php echo htmlspecialchars($user['profile_picture']); ?>" alt="Avatar">
                <?php else: ?>
                    <?php echo strtoupper(mb_substr($user['fullname'], 0, 1)); ?>
                <?php endif; ?>
            </div>
            <div style="flex:1;">
                <h3><?php echo htmlspecialchars($user['fullname']); ?></h3>
                <p>
                    <?php echo htmlspecialchars($user['email']); ?>
                    &nbsp;·&nbsp; Balance: <strong><?php echo formatCurrency($user['balance']); ?></strong>
                </p>
                <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap;">
                    <span class="verified-badge">
                        <?php echo $user['is_verified'] ? '✅ Verified' : '⏳ Unverified'; ?>
                    </span>
                    <?php if ($user['is_admin']): ?>
                    <span class="verified-badge">🔐 Administrator</span>
                    <?php endif; ?>
                </div>
            </div>
            <div style="text-align:right;opacity:.8;">
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;">Account ID</div>
                <div style="font-size:20px;font-weight:800;">#<?php echo str_pad($user['id'], 6, '0', STR_PAD_LEFT); ?></div>
            </div>
        </div>

        <div class="settings-sections">

            <!-- ① Account Information -->
            <div class="s-card">
                <div class="s-card-head">
                    <span class="s-card-head-icon">📋</span>
                    <div>
                        <h2>Account Information</h2>
                        <p>Your account details and status</p>
                    </div>
                </div>
                <div class="s-card-body">
                    <table class="info-table">
                        <tr>
                            <td>Account ID</td>
                            <td><code style="background:#f1f5f9;padding:2px 8px;border-radius:4px;font-size:13px;">#<?php echo str_pad($user['id'], 6, '0', STR_PAD_LEFT); ?></code></td>
                        </tr>
                        <tr>
                            <td>Account Type</td>
                            <td><?php echo $user['is_admin'] ? '<span style="color:#7c3aed;font-weight:700;">🔐 Administrator</span>' : '👤 Standard User'; ?></td>
                        </tr>
                        <tr>
                            <td>Verification</td>
                            <td><?php echo $user['is_verified'] ? '<span style="color:#15803d;font-weight:700;">✅ Verified</span>' : '<span style="color:#a16207;font-weight:700;">⏳ Pending Verification</span>'; ?></td>
                        </tr>
                        <tr>
                            <td>Account Balance</td>
                            <td><strong><?php echo formatCurrency($user['balance']); ?></strong></td>
                        </tr>
                        <tr>
                            <td>Member Since</td>
                            <td><?php echo formatDate($user['created_at']); ?></td>
                        </tr>
                        <tr>
                            <td>Last Login</td>
                            <td><?php echo $user['last_login_at'] ? formatDate($user['last_login_at']) : '<span style="color:#94a3b8;">Not recorded</span>'; ?></td>
                        </tr>
                        <tr>
                            <td>Phone</td>
                            <td><?php echo $user['phone'] ? htmlspecialchars($user['phone']) : '<span style="color:#94a3b8;">Not provided</span>'; ?></td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- ② Edit Profile + Change Password (2-col on desktop) -->
            <div class="two-col">

                <!-- Edit Profile -->
                <div class="s-card">
                    <div class="s-card-head">
                        <span class="s-card-head-icon">👤</span>
                        <div>
                            <h2>Edit Profile</h2>
                            <p>Update your personal information</p>
                        </div>
                    </div>
                    <div class="s-card-body">
                        <?php if ($msgs['profile']): ?>
                        <div class="flash-msg <?php echo $types['profile']; ?>"><?php echo htmlspecialchars($msgs['profile']); ?></div>
                        <?php endif; ?>
                        <form method="POST" action="settings.php" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="update_profile">
                            <div class="fg">
                                <label>Full Name</label>
                                <input type="text" name="fullname" value="<?php echo htmlspecialchars($user['fullname']); ?>" required>
                            </div>
                            <div class="fg">
                                <label>Email Address</label>
                                <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                            </div>
                            <div class="fg">
                                <label>Phone Number</label>
                                <input type="tel" name="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" placeholder="Optional — 10–15 digits">
                            </div>
                            <div class="fg">
                                <label>Profile Picture <span style="color:#94a3b8;font-weight:400;">(optional, max 2 MB)</span></label>
                                <?php if ($user['profile_picture'] && file_exists($user['profile_picture'])): ?>
                                <div style="margin-bottom:8px;">
                                    <img src="<?php echo htmlspecialchars($user['profile_picture']); ?>"
                                         style="width:48px;height:48px;border-radius:50%;object-fit:cover;border:2px solid #e2e8f0;" alt="Current avatar">
                                    <span style="font-size:12px;color:#94a3b8;margin-left:8px;">Current photo</span>
                                </div>
                                <?php endif; ?>
                                <input type="file" name="profile_picture" accept="image/jpeg,image/png,image/gif,image/webp">
                                <p class="hint">JPG, PNG, GIF or WebP · Max 2 MB</p>
                            </div>
                            <button type="submit" class="btn-save btn-profile">Save Profile</button>
                        </form>
                    </div>
                </div>

                <!-- Change Password -->
                <div class="s-card">
                    <div class="s-card-head">
                        <span class="s-card-head-icon">🔒</span>
                        <div>
                            <h2>Change Password</h2>
                            <p>Keep your account secure</p>
                        </div>
                    </div>
                    <div class="s-card-body">
                        <?php if ($msgs['password']): ?>
                        <div class="flash-msg <?php echo $types['password']; ?>"><?php echo htmlspecialchars($msgs['password']); ?></div>
                        <?php endif; ?>
                        <form method="POST" action="settings.php" id="pwForm">
                            <input type="hidden" name="action" value="change_password">
                            <div class="fg">
                                <label>Current Password</label>
                                <input type="password" name="current_password" placeholder="Enter current password" required>
                            </div>
                            <div class="fg">
                                <label>New Password</label>
                                <input type="password" id="newPw" name="new_password" placeholder="At least 6 characters" required>
                            </div>
                            <div class="fg">
                                <label>Confirm New Password</label>
                                <input type="password" id="confirmPw" name="confirm_password" placeholder="Repeat new password" required>
                                <p class="hint" id="pwMatch"></p>
                            </div>
                            <button type="submit" class="btn-save btn-password">Change Password</button>
                        </form>
                    </div>
                </div>

            </div><!-- /.two-col -->

            <!-- ③ Notification Preferences -->
            <div class="s-card">
                <div class="s-card-head">
                    <span class="s-card-head-icon">🔔</span>
                    <div>
                        <h2>Notification Preferences</h2>
                        <p>Choose which alerts you receive in your Notifications Center</p>
                    </div>
                </div>
                <div class="s-card-body">
                    <?php if ($msgs['notif']): ?>
                    <div class="flash-msg <?php echo $types['notif']; ?>"><?php echo htmlspecialchars($msgs['notif']); ?></div>
                    <?php endif; ?>
                    <form method="POST" action="settings.php">
                        <input type="hidden" name="action" value="update_notifications">
                        <?php
                        $notif_options = [
                            'notif_deposits'       => ['📥', 'Deposit Confirmations',     'Notified when a deposit is processed successfully'],
                            'notif_withdrawals'    => ['📤', 'Withdrawal Confirmations',   'Notified when a withdrawal is processed successfully'],
                            'notif_tx_status'      => ['🔄', 'Transaction Status Updates', 'Notified when a transaction status changes'],
                            'notif_account'        => ['👤', 'Account Status Changes',     'Notified when your account verification or role changes'],
                            'notif_security'       => ['🔒', 'Security Alerts',            'Critical alerts for suspicious activity on your account'],
                            'notif_admin_messages' => ['📢', 'Messages from Administrators','Direct messages and announcements from the support team'],
                        ];
                        foreach ($notif_options as $key => [$ico, $label, $sub]):
                            $checked = !empty($notif_prefs[$key]);
                        ?>
                        <div class="toggle-row">
                            <div>
                                <div class="toggle-label"><?php echo $ico; ?> <?php echo $label; ?></div>
                                <div class="toggle-sub"><?php echo $sub; ?></div>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="<?php echo $key; ?>" <?php echo $checked ? 'checked' : ''; ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                        <?php endforeach; ?>
                        <button type="submit" class="btn-save btn-notif" style="margin-top:18px;">Save Preferences</button>
                    </form>
                </div>
            </div>

            <!-- ④ Security / Login History -->
            <div class="s-card">
                <div class="s-card-head">
                    <span class="s-card-head-icon">🔐</span>
                    <div>
                        <h2>Security &amp; Login Activity</h2>
                        <p>Recent sign-ins to your account</p>
                    </div>
                </div>
                <div class="s-card-body">
                    <?php if ($msgs['security']): ?>
                    <div class="flash-msg <?php echo $types['security']; ?>"><?php echo htmlspecialchars($msgs['security']); ?></div>
                    <?php endif; ?>

                    <?php if (!empty($login_history)): ?>
                    <div style="overflow-x:auto;margin-bottom:20px;">
                        <table class="login-table">
                            <thead>
                                <tr>
                                    <th>Date &amp; Time</th>
                                    <th>IP Address</th>
                                    <th>Browser / Device</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($login_history as $lg): ?>
                                <tr>
                                    <td style="white-space:nowrap;"><?php echo formatDate($lg['login_time']); ?></td>
                                    <td><?php echo htmlspecialchars($lg['ip_address'] ?: '—'); ?></td>
                                    <td><div class="ua-trunc" title="<?php echo htmlspecialchars($lg['user_agent'] ?? ''); ?>"><?php echo htmlspecialchars(mb_strimwidth($lg['user_agent'] ?? '—', 0, 50, '…')); ?></div></td>
                                    <td>
                                        <?php if (($lg['status'] ?? 'success') === 'success'): ?>
                                        <span class="status-ok">✓ Success</span>
                                        <?php else: ?>
                                        <span class="status-fail">✗ Failed</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <p style="color:#94a3b8;font-size:14px;margin:0 0 20px;">No login records found.</p>
                    <?php endif; ?>

                    <!-- Active sessions / logout all -->
                    <div class="danger-zone">
                        <h3>🚨 Logout from All Devices</h3>
                        <p>This will immediately end your current session and sign you out from all active sessions. You will need to log in again.</p>
                        <form method="POST" action="settings.php" onsubmit="return confirm('Log out from all devices? You will need to log in again.');">
                            <input type="hidden" name="action" value="logout_all">
                            <button type="submit" class="btn-danger">Logout All Devices</button>
                        </form>
                    </div>
                </div>
            </div>

        </div><!-- /.settings-sections -->

        <p style="margin-top:24px;font-size:13px;">
            <a href="dashboard.php" style="color:#7c3aed;text-decoration:none;">← Back to Dashboard</a>
        </p>
    </div>
</div>

<script>
// Live password match indicator
const np = document.getElementById('newPw');
const cp = document.getElementById('confirmPw');
const pm = document.getElementById('pwMatch');
if (np && cp && pm) {
    function checkMatch() {
        if (!cp.value) { pm.textContent = ''; return; }
        if (np.value === cp.value) {
            pm.textContent = '✓ Passwords match';
            pm.style.color = '#15803d';
        } else {
            pm.textContent = '✗ Passwords do not match';
            pm.style.color = '#b91c1c';
        }
    }
    np.addEventListener('input', checkMatch);
    cp.addEventListener('input', checkMatch);
}

// Notification badge polling
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
