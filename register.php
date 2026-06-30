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
    $fullname = sanitizeInput($_POST['fullname'] ?? '');
    $email = sanitizeInput($_POST['email'] ?? '');
    $phone = sanitizeInput($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Validation
    if (empty($fullname) || empty($email) || empty($password)) {
        $message = 'Full name, email, and password are required';
        $message_type = 'error';
    } elseif (!validateEmail($email)) {
        $message = 'Invalid email format';
        $message_type = 'error';
    } elseif (!validatePassword($password)) {
        $message = 'Password must be at least 6 characters';
        $message_type = 'error';
    } elseif ($password !== $confirm_password) {
        $message = 'Passwords do not match';
        $message_type = 'error';
    } elseif (!empty($phone) && !validatePhone($phone)) {
        $message = 'Invalid phone number';
        $message_type = 'error';
    } else {
        try {
            // Check if email exists
            $check_stmt = $conn->prepare('SELECT id FROM users WHERE email = :email');
            $check_stmt->execute([':email' => $email]);

            if ($check_stmt->rowCount() > 0) {
                $message = 'Email already registered. Please login or use a different email';
                $message_type = 'error';
            } else {
                // Hash password
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                // Insert user
                $insert_stmt = $conn->prepare(
                    'INSERT INTO users (fullname, email, phone, password, balance, created_at) '
                    . 'VALUES (:fullname, :email, :phone, :password, 0, CURRENT_TIMESTAMP) '
                    . 'RETURNING id'
                );
                
                $insert_stmt->execute([
                    ':fullname' => $fullname,
                    ':email' => $email,
                    ':phone' => $phone,
                    ':password' => $hashed_password
                ]);

                $result = $insert_stmt->fetch();
                $user_id = $result['id'];

                // Create session
                $_SESSION['user_id'] = $user_id;
                $_SESSION['user'] = [
                    'id' => $user_id,
                    'fullname' => $fullname,
                    'email' => $email
                ];

                // Log registration
                error_log('New user registered: ' . $email . ' (ID: ' . $user_id . ')');

                // Notify admins
                include_once('config/notify.php');
                createNotification($conn, [
                    'event_type'  => 'new_user_registration',
                    'category'    => 'user_accounts',
                    'priority'    => 'medium',
                    'title'       => 'New User Registered',
                    'description' => "New account: {$fullname} ({$email})" . ($phone ? ", Phone: {$phone}" : '') . ". User ID: #{$user_id}",
                    'user_id'     => $user_id,
                ]);

                // Redirect to dashboard
                header('Location: dashboard.php');
                exit();
            }
        } catch (PDOException $e) {
            $message = 'Registration failed. Please try again';
            $message_type = 'error';
            error_log('Registration error: ' . $e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Softlink Broker</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .auth-box {
            max-width: 450px;
            margin: 50px auto;
            padding: 30px;
            border: 1px solid #ddd;
            border-radius: 8px;
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .auth-box h2 {
            text-align: center;
            margin-bottom: 10px;
            color: #333;
        }
        .auth-box p {
            text-align: center;
            color: #999;
            margin-bottom: 20px;
        }
        .auth-box input {
            width: 100%;
            padding: 10px;
            margin: 8px 0;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
            font-size: 14px;
        }
        .auth-box input:focus {
            outline: none;
            border-color: #007bff;
            box-shadow: 0 0 5px rgba(0, 123, 255, 0.3);
        }
        .auth-box button {
            width: 100%;
            padding: 12px;
            margin-top: 15px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            font-weight: bold;
            transition: background 0.3s;
        }
        .auth-box button:hover {
            background: #0056b3;
        }
        .message {
            padding: 12px;
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
        .password-requirements {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 4px;
            margin-top: 10px;
            font-size: 12px;
            color: #666;
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
    <h2>Create Account</h2>
    <p>Join Softlink Broker today</p>

    <?php if ($message): ?>
        <div class="message <?php echo $message_type; ?>"><?php echo $message; ?></div>
    <?php endif; ?>

    <form method="POST" action="register.php">
        <input type="text" name="fullname" placeholder="Full Name" required>
        <input type="email" name="email" placeholder="Email Address" required>
        <input type="tel" name="phone" placeholder="Phone Number (optional)">
        <input type="password" name="password" placeholder="Password" required>
        <input type="password" name="confirm_password" placeholder="Confirm Password" required>

        <div class="password-requirements">
            ✓ Password must be at least 6 characters<br>
            ✓ Passwords must match
        </div>

        <button type="submit">Register</button>
    </form>

    <div class="auth-footer">
        <p>Already have an account? <a href="login.php">Login here</a></p>
    </div>
</div>

</body>
</html>
