<?php
session_start();
$logout_message = isset($_GET['logout']) && $_GET['logout'] === 'success';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Softlink Broker - Your Trusted Digital Brokerage</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            line-height: 1.6;
            color: #333;
        }
        .navbar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .navbar h2 {
            margin: 0;
            font-size: 24px;
        }
        .navbar nav a {
            color: white;
            text-decoration: none;
            margin-left: 20px;
            transition: opacity 0.3s;
        }
        .navbar nav a:hover {
            opacity: 0.8;
        }
        .hero {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 100px 30px;
            text-align: center;
        }
        .hero h1 {
            font-size: 48px;
            margin: 0 0 20px 0;
            font-weight: bold;
        }
        .hero p {
            font-size: 20px;
            margin: 0 0 30px 0;
            opacity: 0.95;
        }
        .btn {
            display: inline-block;
            background: white;
            color: #667eea;
            padding: 15px 40px;
            border-radius: 50px;
            text-decoration: none;
            font-weight: bold;
            margin: 0 10px;
            transition: transform 0.3s, box-shadow 0.3s;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.2);
        }
        .btn.secondary {
            background: transparent;
            color: white;
            border: 2px solid white;
        }
        .features {
            padding: 80px 30px;
            background: #f8f9fa;
            text-align: center;
        }
        .features h2 {
            font-size: 36px;
            margin-bottom: 50px;
            color: #333;
        }
        .box-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
            max-width: 1200px;
            margin: 0 auto;
        }
        .box {
            background: white;
            padding: 40px 30px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transition: transform 0.3s;
        }
        .box:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px rgba(0,0,0,0.2);
        }
        .box h3 {
            color: #667eea;
            font-size: 24px;
            margin-bottom: 15px;
        }
        .box p {
            color: #666;
            line-height: 1.8;
        }
        .cta-section {
            background: #333;
            color: white;
            padding: 60px 30px;
            text-align: center;
        }
        .cta-section h2 {
            font-size: 36px;
            margin-bottom: 30px;
        }
        footer {
            background: #222;
            color: #999;
            padding: 30px;
            text-align: center;
            border-top: 1px solid #444;
        }
        .logout-success {
            background: #d4edda;
            color: #155724;
            padding: 15px;
            text-align: center;
            margin-bottom: 20px;
            border-radius: 4px;
            border: 1px solid #c3e6cb;
        }
        @media (max-width: 768px) {
            .hero h1 {
                font-size: 32px;
            }
            .features h2 {
                font-size: 28px;
            }
            .navbar {
                flex-direction: column;
                gap: 15px;
            }
            .navbar nav {
                display: flex;
                gap: 15px;
                flex-wrap: wrap;
                justify-content: center;
            }
            .navbar nav a {
                margin-left: 0;
            }
        }
    </style>
</head>
<body>

<header class="navbar">
    <h2>🏦 Softlink Broker</h2>
    <nav>
        <a href="index.php">Home</a>
        <?php if (isset($_SESSION['user_id'])): ?>
            <a href="dashboard.php">Dashboard</a>
            <a href="config/logout.php">Logout</a>
        <?php else: ?>
            <a href="login.php">Login</a>
            <a href="register.php">Register</a>
        <?php endif; ?>
    </nav>
</header>

<?php if ($logout_message): ?>
    <div class="logout-success">✅ You have been logged out successfully. See you soon!</div>
<?php endif; ?>

<section class="hero">
    <h1>Trade Smarter. Grow Faster.</h1>
    <p>Your trusted digital brokerage platform for modern investors.</p>
    <div>
        <?php if (!isset($_SESSION['user_id'])): ?>
            <a href="register.php" class="btn">Get Started</a>
            <a href="login.php" class="btn secondary">Login</a>
        <?php else: ?>
            <a href="dashboard.php" class="btn">Go to Dashboard</a>
        <?php endif; ?>
    </div>
</section>

<section class="features">
    <h2>Why Choose Softlink Broker?</h2>
    <div class="box-container">
        <div class="box">
            <h3>⚡ Fast Deposits</h3>
            <p>Instant funding with secure payment systems. Get started in minutes and begin trading right away.</p>
        </div>
        <div class="box">
            <h3>🔒 Secure Trading</h3>
            <p>Advanced encryption and security protocols keep your account and funds safe 24/7.</p>
        </div>
        <div class="box">
            <h3>📊 Real-time Analytics</h3>
            <p>Track your portfolio with advanced charts and market analysis tools.</p>
        </div>
        <div class="box">
            <h3>💬 AI Support</h3>
            <p>24/7 smart assistant ready to help guide your trading decisions and answer questions.</p>
        </div>
        <div class="box">
            <h3>📱 Mobile Ready</h3>
            <p>Trade on the go with our responsive platform that works on all devices.</p>
        </div>
        <div class="box">
            <h3>🌍 Global Access</h3>
            <p>Access international markets and trade with competitive rates from anywhere in the world.</p>
        </div>
    </div>
</section>

<section class="cta-section">
    <h2>Ready to Start Your Trading Journey?</h2>
    <p style="font-size: 18px; margin-bottom: 30px;">Join thousands of traders already earning with Softlink Broker</p>
    <?php if (!isset($_SESSION['user_id'])): ?>
        <a href="register.php" class="btn">Create Free Account</a>
    <?php else: ?>
        <a href="dashboard.php" class="btn">View Dashboard</a>
    <?php endif; ?>
</section>

<footer>
    <p>&copy; 2026 Softlink Broker. All rights reserved. | Privacy Policy | Terms of Service</p>
    <p>Building the future of fintech in Africa 🚀</p>
</footer>

</body>
</html>
