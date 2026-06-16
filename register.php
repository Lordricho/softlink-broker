<?php
include("config/db.php");

$message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $fullname = $_POST['fullname'];
    $email = $_POST['email'];
    $phone = $_POST['phone'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

    // Check if email already exists
    $check = $conn->prepare("SELECT id FROM users WHERE email=?");
    $check->bind_param("s", $email);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        $message = "Email already exists!";
    } else {

        $stmt = $conn->prepare("INSERT INTO users (fullname, email, phone, password) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $fullname, $email, $phone, $password);

        if ($stmt->execute()) {
            $message = "Registration successful!";
        } else {
            $message = "Error occurred!";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Register</title>
    <link rel="stylesheet" href="assets/style.css">
</head>

<body>

<div class="auth-box">

    <h2>Create Account</h2>

    <?php if($message != "") echo "<p>$message</p>"; ?>

    <form method="POST">

        <input type="text" name="fullname" placeholder="Full Name" required>
        <input type="email" name="email" placeholder="Email" required>
        <input type="text" name="phone" placeholder="Phone">
        <input type="password" name="password" placeholder="Password" required>

        <button type="submit">Register</button>

    </form>

</div>

</body>
</html>
