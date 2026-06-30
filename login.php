<?php
session_start();
include('config/db.php');
include('config/helpers.php');

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit();
}

$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = sanitizeInput($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $message = 'Email and password are required';
        $message_type = 'error';
    } elseif (!validateEmail($email)) {
        $message = 'Invalid email format';
        $message_type = 'error';
    } else {
        try {
            // Query user by email
            $stmt = $conn->prepare('SELECT id, fullname, email, password, is_admin, is_suspended FROM users WHERE email = :email');
            $stmt->execute([':email' => $email]);

            if ($stmt->rowCount() === 1) {
                $user = $stmt->fetch();

                // Verify password
                if (password_verify($password, $user['password'])) {
                    // Block suspended accounts before creating a session
                    if ($user['is_suspended']) {
                        $message = 'Your account has been suspended. Please contact support for assistance.';
                        $message_type = 'error';
                        // Alert admins of the blocked attempt
                        include_once('config/notify.php');
                        createNotification($conn, [
                            'event_type'  => 'suspended_login_attempt',
                            'category'    => 'security',
                            'priority'    => 'critical',
                            'title'       => '🚨 Suspended Account Login Attempt',
                            'description' => "User '{$user['fullname']}' ({$user['email']}) attempted login on a suspended account. IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
                            'user_id'     => $user['id'],
                        ]);
                    } else {
                        // Password correct and account active — create session
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['is_admin'] = (bool) $user['is_admin'];
                        $_SESSION['user'] = [
                            'id'       => $user['id'],
                            'fullname' => $user['fullname'],
                            'email'    => $user['email'],
                            'is_admin' => (bool) $user['is_admin'],
                        ];

                        // Log login activity + update last_login_at
                        try {
                            $conn->prepare(
                                'INSERT INTO login_logs (user_id, login_time, ip_address, user_agent, status) '
                                . 'VALUES (:user_id, CURRENT_TIMESTAMP, :ip_address, :user_agent, \'success\')'
                            )->execute([
                                ':user_id'    => $user['id'],
                                ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                                ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                            ]);
                            $conn->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = :id')
                                 ->execute([':id' => $user['id']]);
                        } catch (PDOException $logErr) {
                            error_log('Login log error: ' . $logErr->getMessage());
                        }

                        header('Location: dashboard.php');
                        exit();
                    }
                } else {
                    $message = 'Invalid email or password';
                    $message_type = 'error';
                }
            } else {
                $message = 'Invalid email or password';
                $message_type = 'error';
            }
        } catch (PDOException $e) {
            $message = 'Login failed. Please try again';
            $message_type = 'error';
            error_log('Login error: ' . $e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Softlink Broker</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .auth-box {
            max-width: 400px;
            margin: 100px auto;
            padding: 30px;
            border: 1px solid #ddd;
            border-radius: 8px;
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .auth-box h2 {
            text-align: center;
            margin-bottom: 20px;
            color: #333;
        }
        .auth-box input {
            width: 100%;
            padding: 10px;
            margin: 10px 0;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
            font-size: 14px;
        }
        .auth-box button {
            width: 100%;
            padding: 12px;
            margin-top: 10px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            font-weight: bold;
        }
        .auth-box button:hover {
            background: #0056b3;
        }
        .message {
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 4px;
            text-align: center;
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
        .auth-footer {
            text-align: center;
            margin-top: 20px;
            font-size: 14px;
        }
        .auth-footer a {
            color: #007bff;
            text-decoration: none;
        }
        .auth-footer a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>

<header class="navbar">
    <h2>Softlink Broker</h2>
    <nav>
        <a href="index.php">Home</a>
        <a href="login.php">Login</a>
        <a href="register.php">Register</a>
    </nav>
</header>

<div class="auth-box">
    <h2>Login to Your Account</h2>

    <?php if ($message): ?>
        <div class="message <?php echo $message_type; ?>"><?php echo $message; ?></div>
    <?php endif; ?>

    <form method="POST" action="login.php">
        <input type="email" name="email" placeholder="Email Address" required>
        <input type="password" name="password" placeholder="Password" required>
        <button type="submit">Login</button>
    </form>

    <div class="auth-footer">
        <p>Don't have an account? <a href="register.php">Register here</a></p>
    </div>
</div>

</body>
</html>
