<?php
include('config/db.php');
include('config/auth.php');
include('config/helpers.php');

$user_id = requireAuth();

$profile_message = '';
$profile_type    = '';
$password_message = '';
$password_type    = '';

// Fetch current user
try {
    $stmt = $conn->prepare('SELECT id, fullname, email, phone, created_at, balance FROM users WHERE id = :id');
    $stmt->execute([':id' => $user_id]);
    $user = $stmt->fetch();
    if (!$user) { session_destroy(); header('Location: login.php'); exit(); }
} catch (PDOException $e) {
    error_log('Settings load error: ' . $e->getMessage());
    die('Error loading settings. Please try again.');
}

// ── Edit Profile ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $fullname = sanitizeInput($_POST['fullname'] ?? '');
    $phone    = sanitizeInput($_POST['phone']    ?? '');
    $email    = sanitizeInput($_POST['email']    ?? '');

    if (empty($fullname) || empty($email)) {
        $profile_message = 'Full name and email are required.';
        $profile_type    = 'error';
    } elseif (!validateEmail($email)) {
        $profile_message = 'Invalid email format.';
        $profile_type    = 'error';
    } elseif (!empty($phone) && !validatePhone($phone)) {
        $profile_message = 'Invalid phone number (10–15 digits).';
        $profile_type    = 'error';
    } else {
        try {
            // Check email uniqueness if changed
            if ($email !== $user['email']) {
                $dup = $conn->prepare('SELECT id FROM users WHERE email = :email AND id != :id');
                $dup->execute([':email' => $email, ':id' => $user_id]);
                if ($dup->rowCount() > 0) {
                    $profile_message = 'That email address is already in use by another account.';
                    $profile_type    = 'error';
                    goto render;
                }
            }
            $upd = $conn->prepare(
                'UPDATE users SET fullname = :fullname, email = :email, phone = :phone WHERE id = :id'
            );
            $upd->execute([
                ':fullname' => $fullname,
                ':email'    => $email,
                ':phone'    => $phone,
                ':id'       => $user_id,
            ]);
            // Refresh local copy
            $user['fullname'] = $fullname;
            $user['email']    = $email;
            $user['phone']    = $phone;
            // Update session name
            $_SESSION['user']['fullname'] = $fullname;
            $_SESSION['user']['email']    = $email;

            $profile_message = 'Profile updated successfully.';
            $profile_type    = 'success';
        } catch (PDOException $e) {
            error_log('Profile update error: ' . $e->getMessage());
            $profile_message = 'Update failed. Please try again.';
            $profile_type    = 'error';
        }
    }
}

// ── Change Password ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    $current  = $_POST['current_password']  ?? '';
    $new      = $_POST['new_password']      ?? '';
    $confirm  = $_POST['confirm_password']  ?? '';

    if (empty($current) || empty($new) || empty($confirm)) {
        $password_message = 'All password fields are required.';
        $password_type    = 'error';
    } elseif (!validatePassword($new)) {
        $password_message = 'New password must be at least 6 characters.';
        $password_type    = 'error';
    } elseif ($new !== $confirm) {
        $password_message = 'New passwords do not match.';
        $password_type    = 'error';
    } else {
        try {
            $pw_stmt = $conn->prepare('SELECT password FROM users WHERE id = :id');
            $pw_stmt->execute([':id' => $user_id]);
            $row = $pw_stmt->fetch();

            if (!password_verify($current, $row['password'])) {
                $password_message = 'Current password is incorrect.';
                $password_type    = 'error';
            } else {
                $hashed = password_hash($new, PASSWORD_DEFAULT);
                $upd_pw = $conn->prepare('UPDATE users SET password = :password WHERE id = :id');
                $upd_pw->execute([':password' => $hashed, ':id' => $user_id]);
                $password_message = 'Password changed successfully.';
                $password_type    = 'success';
            }
        } catch (PDOException $e) {
            error_log('Password change error: ' . $e->getMessage());
            $password_message = 'Password change failed. Please try again.';
            $password_type    = 'error';
        }
    }
}

render:
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - Softlink Broker</title>
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
        .sidebar ul li a.active { background: #667eea; color: white; }

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
            background: #dc3545; color: white;
            padding: 8px 16px; border: none;
            border-radius: 4px; cursor: pointer;
            text-decoration: none; display: inline-block;
        }
        .logout-btn:hover { background: #c82333; }

        /* Settings panels */
        .settings-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
        }
        @media (max-width: 900px) {
            .settings-grid { grid-template-columns: 1fr; }
        }
        .settings-panel {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 26px 28px;
        }
        .settings-panel h2 {
            margin: 0 0 6px 0;
            font-size: 17px;
            color: #1e293b;
        }
        .settings-panel .panel-sub {
            font-size: 13px;
            color: #94a3b8;
            margin: 0 0 20px 0;
        }
        .form-group { margin-bottom: 16px; }
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #475569;
            margin-bottom: 5px;
        }
        .form-group input {
            width: 100%;
            padding: 10px 13px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 14px;
            box-sizing: border-box;
            background: white;
            transition: border-color .2s, box-shadow .2s;
        }
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102,126,234,.12);
        }
        .form-group input[readonly] {
            background: #f1f5f9;
            color: #94a3b8;
            cursor: default;
        }
        .hint { font-size: 11px; color: #94a3b8; margin-top: 4px; }

        .btn-save {
            width: 100%;
            padding: 11px;
            border: none;
            border-radius: 6px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            margin-top: 4px;
            transition: opacity .2s;
        }
        .btn-save:hover { opacity: .88; }
        .btn-profile  { background: linear-gradient(135deg,#667eea,#764ba2); color: white; }
        .btn-password { background: linear-gradient(135deg,#f093fb,#f5576c); color: white; }

        .msg {
            padding: 10px 14px;
            border-radius: 6px;
            font-size: 13px;
            margin-bottom: 16px;
        }
        .msg.success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
        .msg.error   { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }

        /* Account info card */
        .account-card {
            background: linear-gradient(135deg,#667eea 0%,#764ba2 100%);
            color: white;
            border-radius: 10px;
            padding: 20px 24px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 18px;
        }
        .account-avatar {
            width: 52px; height: 52px;
            border-radius: 50%;
            background: rgba(255,255,255,.25);
            display: flex; align-items: center; justify-content: center;
            font-size: 22px; font-weight: 800;
            flex-shrink: 0;
        }
        .account-card h3 { margin: 0 0 4px; font-size: 18px; }
        .account-card p  { margin: 0; font-size: 13px; opacity: .85; }

        @media (max-width: 768px) {
            .dashboard-container { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<header class="navbar">
    <h2>🏦 Softlink Broker</h2>
    <nav>
        <span style="color:rgba(255,255,255,.75);">Welcome, <?php echo htmlspecialchars($user['fullname']); ?></span>
    </nav>
</header>

<div class="dashboard-container">
    <aside class="sidebar">
        <h3>Navigation</h3>
        <ul>
            <li><a href="dashboard.php">📊 Dashboard</a></li>
            <li><a href="deposit.php">💰 Deposit Funds</a></li>
            <li><a href="withdraw.php">🏦 Withdraw</a></li>
            <li><a href="settings.php" class="active">⚙️ Settings</a></li>
            <?php if (!empty($_SESSION['is_admin'])): ?>
            <li><a href="admin_dashboard.php" style="color:#7c3aed;">🔐 Admin Panel</a></li>
            <?php endif; ?>
            <li><a href="config/logout.php">🚪 Logout</a></li>
        </ul>
    </aside>

    <main class="main-content">
        <div class="header">
            <div>
                <h1>⚙️ Settings</h1>
                <p style="margin:5px 0 0;color:#999;">Manage your profile and security</p>
            </div>
            <a href="config/logout.php" class="logout-btn">Logout</a>
        </div>

        <!-- Account summary card -->
        <div class="account-card">
            <div class="account-avatar">
                <?php echo strtoupper(mb_substr($user['fullname'], 0, 1)); ?>
            </div>
            <div>
                <h3><?php echo htmlspecialchars($user['fullname']); ?></h3>
                <p><?php echo htmlspecialchars($user['email']); ?>
                   &nbsp;·&nbsp; Member since <?php echo date('M Y', strtotime($user['created_at'])); ?>
                   &nbsp;·&nbsp; Balance: <?php echo formatCurrency($user['balance']); ?>
                </p>
            </div>
        </div>

        <div class="settings-grid">

            <!-- Edit Profile -->
            <div class="settings-panel">
                <h2>👤 Edit Profile</h2>
                <p class="panel-sub">Update your name, email, and phone number</p>

                <?php if ($profile_message): ?>
                    <div class="msg <?php echo $profile_type; ?>"><?php echo htmlspecialchars($profile_message); ?></div>
                <?php endif; ?>

                <form method="POST" action="settings.php">
                    <input type="hidden" name="action" value="update_profile">
                    <div class="form-group">
                        <label for="fullname">Full Name</label>
                        <input type="text" id="fullname" name="fullname"
                               value="<?php echo htmlspecialchars($user['fullname']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email"
                               value="<?php echo htmlspecialchars($user['email']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="phone">Phone Number</label>
                        <input type="tel" id="phone" name="phone"
                               value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>"
                               placeholder="Optional">
                        <p class="hint">10–15 digits, numbers only</p>
                    </div>
                    <button type="submit" class="btn-save btn-profile">Save Profile</button>
                </form>
            </div>

            <!-- Change Password -->
            <div class="settings-panel">
                <h2>🔒 Change Password</h2>
                <p class="panel-sub">Keep your account secure with a strong password</p>

                <?php if ($password_message): ?>
                    <div class="msg <?php echo $password_type; ?>"><?php echo htmlspecialchars($password_message); ?></div>
                <?php endif; ?>

                <form method="POST" action="settings.php">
                    <input type="hidden" name="action" value="change_password">
                    <div class="form-group">
                        <label for="current_password">Current Password</label>
                        <input type="password" id="current_password" name="current_password"
                               placeholder="Enter current password" required>
                    </div>
                    <div class="form-group">
                        <label for="new_password">New Password</label>
                        <input type="password" id="new_password" name="new_password"
                               placeholder="At least 6 characters" required>
                    </div>
                    <div class="form-group">
                        <label for="confirm_password">Confirm New Password</label>
                        <input type="password" id="confirm_password" name="confirm_password"
                               placeholder="Repeat new password" required>
                        <p class="hint">Must be at least 6 characters and match above</p>
                    </div>
                    <button type="submit" class="btn-save btn-password">Change Password</button>
                </form>
            </div>

        </div><!-- /.settings-grid -->

        <p style="margin-top:24px;">
            <a href="dashboard.php" style="color:#007bff;text-decoration:none;">← Back to Dashboard</a>
        </p>
    </main>
</div>

</body>
</html>
