<?php
// DATABASE CONNECTION FILE (we will connect later properly on hosting)
// For now we prepare structure

$message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $fullname = $_POST['fullname'];
    $email = $_POST['email'];
    $phone = $_POST['phone'];
    $password = $_POST['password'];

    // SECURITY: hash password
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    // Normally here we will insert into database
    // Example (we activate later):
    /*
    INSERT INTO users (fullname, email, phone, password)
    VALUES (...)
    */

    $message = "Registration successful (DB connection will be activated on hosting)";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Register - Softlink Broker</title>
    <link rel="stylesheet" href="assets/style.css">
</head>

<body>

<div class="auth-box">

    <h2>Create Account</h2>

    <?php if($message != "") echo "<p style='color:green;'>$message</p>"; ?>

    <form method="POST">

        <input type="text" name="fullname" placeholder="Full Name" required>

        <input type="email" name="email" placeholder="Email Address" required>

        <input type="text" name="phone" placeholder="Phone Number">

        <input type="password" name="password" placeholder="Password" required>

        <button type="submit">Register</button>

    </form>

</div>

</body>
</html>
