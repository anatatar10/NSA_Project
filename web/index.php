<?php
// Centralized Session handling via Redis (Preserves sessions between replicas)
ini_set('session.save_handler', 'files');
ini_set('session.save_path', '/sessions');
session_start();

$container_ip = gethostbyname(gethostname());
$hostname = gethostname();

$db_master = new mysqli('db-master', 'root', 'root', 'app_db');
$db_slave = new mysqli('db-slave', 'root', 'root', 'app_db');

if ($db_master->connect_error || $db_slave->connect_error) {
    die('Database connection failed.');
}

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: /');
    exit;
}

if (isset($_GET['confirm'])) {
    $email = $db_master->real_escape_string($_GET['confirm']);
    $db_master->query("UPDATE users SET confirmed = 1 WHERE email = '$email'");
    echo "<p style='color:green'>Email confirmed for " . e($_GET['confirm']) . '.</p>';
}

if (isset($_POST['register'])) {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($name !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && $password !== '') {
        $nameEsc = $db_master->real_escape_string($name);
        $emailEsc = $db_master->real_escape_string($email);
        $hashEsc = $db_master->real_escape_string(password_hash($password, PASSWORD_DEFAULT));

        $db_master->query("INSERT INTO users (name, email, password_hash, confirmed) VALUES ('$nameEsc', '$emailEsc', '$hashEsc', 0)");

        $confirmLink = 'https://works-on-my-machine.rip:8443/?confirm=' . urlencode($email);
        $subject = 'Confirm your account';
        $message = "Please confirm your registration: $confirmLink";
        $headers = 'From: noreply@works-on-my-machine.rip';

        if (mail($email, $subject, $message, $headers)) {
            echo "<p style='color:blue'>Registration successful! Check your email.</p>";
        } else {
            echo "<p style='color:orange'>Registration saved, email sending failed in this environment.</p>";
        }
    }
}

if (isset($_POST['login'])) {
    $email = $db_slave->real_escape_string(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    $result = $db_slave->query("SELECT id, name, email, password_hash, confirmed FROM users WHERE email = '$email' LIMIT 1");
    $user = $result ? $result->fetch_assoc() : null;

    if ($user && (int) $user['confirmed'] === 1 && !empty($user['password_hash']) && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_email'] = $user['email'];
    } else {
        echo "<p style='color:red'>Invalid credentials or unconfirmed account.</p>";
    }
}

if (!empty($_SESSION['user_id'])) {
    if (isset($_POST['add_user'])) {
        $name = trim($_POST['name'] ?? '');
        if ($name !== '') {
            $nameEsc = $db_master->real_escape_string($name);
            $db_master->query("INSERT INTO users (name, confirmed) VALUES ('$nameEsc', 1)");
            echo "<p style='color:green'>User added via Master DB!</p>";
        }
    }

    if (isset($_POST['update_user'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if ($id > 0 && $name !== '') {
            $nameEsc = $db_master->real_escape_string($name);
            $db_master->query("UPDATE users SET name = '$nameEsc' WHERE id = $id");
            echo "<p style='color:green'>User updated via Master DB!</p>";
        }
    }

    if (isset($_POST['delete_user'])) {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $db_master->query("DELETE FROM users WHERE id = $id");
            echo "<p style='color:green'>User deleted via Master DB!</p>";
        }
    }
}

echo '<h1>DevOps Project: Load Balanced App</h1>';
echo '<p><strong>Server Hostname:</strong> ' . e($hostname) . '</p>';
echo '<p><strong>Container IP:</strong> ' . e($container_ip) . '</p>';

echo '<p><strong>Domain:</strong> works-on-my-machine.rip</p>';

if (empty($_SESSION['user_id'])) {
    ?>
    <h3>Login</h3>
    <form method="POST">
        <input type="email" name="email" placeholder="Email" required>
        <input type="password" name="password" placeholder="Password" required>
        <button type="submit" name="login">Login</button>
    </form>

    <h3>Register</h3>
    <form method="POST">
        <input type="text" name="name" placeholder="Name" required>
        <input type="email" name="email" placeholder="Email" required>
        <input type="password" name="password" placeholder="Password" required>
        <button type="submit" name="register">Register</button>
    </form>
    <?php
    exit;
}

echo '<p>Welcome, ' . e($_SESSION['user_name']) . " | <a href='?logout=1'>Logout</a></p>";

$result = $db_slave->query('SELECT id, name, email, confirmed FROM users ORDER BY id ASC');
echo '<h3>User List (Fetched from Slave Replica):</h3><ul>';
if ($result) {
    while ($row = $result->fetch_assoc()) {
        echo '<li>ID: ' . e($row['id']) . ' - Name: ' . e($row['name']) . ' - Email: ' . e($row['email']) . ' - Confirmed: ' . e($row['confirmed']) . '</li>';
    }
}
echo '</ul>';
?>

<h3>Create User</h3>
<form method="POST">
    <input type="text" name="name" placeholder="Enter username" required>
    <button type="submit" name="add_user">Add to Database</button>
</form>

<h3>Update User</h3>
<form method="POST">
    <input type="number" name="id" placeholder="User ID" required>
    <input type="text" name="name" placeholder="New username" required>
    <button type="submit" name="update_user">Update User</button>
</form>

<h3>Delete User</h3>
<form method="POST">
    <input type="number" name="id" placeholder="User ID" required>
    <button type="submit" name="delete_user">Delete User</button>
</form>
