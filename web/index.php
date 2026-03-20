<?php
// Centralized Session handling via shared volume (Preserves sessions between replicas)
ini_set('session.save_handler', 'files');
ini_set('session.save_path', '/sessions');
session_start();

$container_ip = gethostbyname(gethostname());
$hostname = gethostname();
$app_domain = 'works-on-my-machine.rip';
$app_port = '8443';

$db_master = new mysqli('db-master', 'root', 'root', 'app_db');
$db_slave = new mysqli('db-slave', 'root', 'root', 'app_db');

if ($db_master->connect_error || $db_slave->connect_error) {
    die('Database connection failed.');
}

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function exec_prepared($db, $sql, $types = '', ...$params)
{
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return false;
    }

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function fetch_one_prepared($db, $sql, $types = '', ...$params)
{
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return null;
    }

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return $row;
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: /');
    exit;
}

if (isset($_GET['confirm'])) {
    $confirmToken = trim($_GET['confirm']);
    if (preg_match('/^[a-f0-9]{64}$/', $confirmToken)) {
        $user = fetch_one_prepared(
            $db_master,
            'SELECT email FROM users WHERE confirmation_token = ? LIMIT 1',
            's',
            $confirmToken
        );

        if ($user) {
            exec_prepared($db_master, 'UPDATE users SET confirmed = 1, confirmation_token = NULL WHERE confirmation_token = ?', 's', $confirmToken);
            echo "<p style='color:green'>Email confirmed for " . e($user['email']) . '.</p>';
        }
    }
}

if (isset($_POST['register'])) {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($name !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && $password !== '') {
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $confirmToken = bin2hex(random_bytes(32));

        exec_prepared(
            $db_master,
            'INSERT INTO users (name, email, password_hash, confirmed, confirmation_token) VALUES (?, ?, ?, 0, ?)',
            'ssss',
            $name,
            $email,
            $hash,
            $confirmToken
        );

        $defaultHost = $app_domain . ':' . $app_port;
        $host = $_SERVER['HTTP_HOST'] ?? $defaultHost;
        if (!preg_match('/^(works-on-my-machine\.rip(?::8443)?|127\.0\.0\.1:8443|localhost:8443)$/', $host)) {
            $host = $defaultHost;
        }

        $confirmLink = 'https://' . $host . '/?confirm=' . urlencode($confirmToken);
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
    $emailInput = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $user = fetch_one_prepared(
        $db_slave,
        'SELECT id, name, email, password_hash, confirmed FROM users WHERE email = ? LIMIT 1',
        's',
        $emailInput
    );

    if (!$user) {
        $user = fetch_one_prepared(
            $db_master,
            'SELECT id, name, email, password_hash, confirmed FROM users WHERE email = ? LIMIT 1',
            's',
            $emailInput
        );
    }

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
            exec_prepared($db_master, 'INSERT INTO users (name, confirmed) VALUES (?, 1)', 's', $name);
            echo "<p style='color:green'>User added via Master DB!</p>";
        }
    }

    if (isset($_POST['update_user'])) {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if ($id > 0 && $name !== '') {
            exec_prepared($db_master, 'UPDATE users SET name = ? WHERE id = ?', 'si', $name, $id);
            echo "<p style='color:green'>User updated via Master DB!</p>";
        }
    }

    if (isset($_POST['delete_user'])) {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            exec_prepared($db_master, 'DELETE FROM users WHERE id = ?', 'i', $id);
            echo "<p style='color:green'>User deleted via Master DB!</p>";
        }
    }
}

echo '<h1>DevOps Project: Load Balanced App</h1>';
echo '<p><strong>Server Hostname:</strong> ' . e($hostname) . '</p>';
echo '<p><strong>Container IP:</strong> ' . e($container_ip) . '</p>';
echo '<p><strong>Domain:</strong> ' . e($app_domain) . '</p>';

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

$stmt = $db_slave->prepare('SELECT id, name, email, confirmed FROM users ORDER BY id ASC');
if (!$stmt) {
    $stmt = $db_master->prepare('SELECT id, name, email, confirmed FROM users ORDER BY id ASC');
}

echo '<h3>User List (Fetched from Slave Replica):</h3><ul>';
if ($stmt && $stmt->execute()) {
    $result = $stmt->get_result();
    while ($result && $row = $result->fetch_assoc()) {
        echo '<li>ID: ' . e($row['id']) . ' - Name: ' . e($row['name']) . ' - Email: ' . e($row['email']) . ' - Confirmed: ' . e($row['confirmed']) . '</li>';
    }
    $stmt->close();
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
