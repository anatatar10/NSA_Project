<?php
// Centralized Session handling via Redis (Preserves sessions between replicas)
ini_set('session.save_handler', 'redis');
ini_set('session.save_path', 'tcp://redis:6379');
session_start();

$container_ip = gethostbyname(gethostname());
$hostname = gethostname();

echo "<h1>DevOps Project: Load Balanced App</h1>";
echo "<p><strong>Server Hostname:</strong> $hostname</p>";
echo "<p><strong>Container IP:</strong> $container_ip</p>";

// Database Connection Logic (Master for Writes, Slave for Reads)
$db_master = new mysqli("db-master", "root", "root", "app_db");
$db_slave  = new mysqli("db-slave", "root", "root", "app_db");

// Handle Form Submission (Create/Update/Delete - Goes to Master)
if (isset($_POST['add_user'])) {
    $name = $db_master->real_escape_string($_POST['name']);
    $db_master->query("INSERT INTO users (name) VALUES ('$name')");
    echo "<p style='color:green'>User added via Master DB!</p>";
}

// Display Data (Read - Comes from Slave)
$result = $db_slave->query("SELECT * FROM users");
echo "<h3>User List (Fetched from Slave Replica):</h3><ul>";
while($row = $result->fetch_assoc()) {
    echo "<li>ID: " . $row['id'] . " - Name: " . htmlspecialchars($row['name']) . "</li>";
}
echo "</ul>";
?>

<form method="POST">
    <input type="text" name="name" placeholder="Enter username" required>
    <button type="submit" name="add_user">Add to Database</button>
</form>

// In a real app, you'd use 'composer require phpmailer/phpmailer'
// For this project, we'll simulate the SMTP call to the 'mailserver' container.

if (isset($_POST['register'])) {
    $email = $_POST['email'];
    
    // 1. Save to Master DB
    $db_master->query("INSERT INTO users (name, email, confirmed) VALUES ('NewUser', '$email', 0)");

    // 2. Send Confirmation Email via Mailserver container
    // The 'mailserver' container is accessible on the 'backend' network
    $to = $email;
    $subject = "Confirm your account";
    $message = "Please click here to confirm your registration on works-on-my-machine.rip";
    $headers = 'From: noreply@works-on-my-machine.rip';

    // In Docker, you'd configure PHPMailer to use:
    // Host: mailserver, Port: 25 (or 587)
    if (mail($to, $subject, $message, $headers)) {
        echo "<p style='color:blue'>Registration successful! Check your email.</p>";
    }
}
