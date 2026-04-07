<?php
ini_set('session.save_handler', 'files');
ini_set('session.save_path', '/sessions');
session_start();

$dbMasterHost = getenv('DB_MASTER_HOST') ?: 'db-master';
$dbSlaveHost  = getenv('DB_SLAVE_HOST') ?: 'db-slave';
$dbName       = getenv('DB_NAME') ?: 'app_db';
$dbUser       = getenv('DB_USER') ?: 'appuser';
$dbPass       = getenv('DB_PASS') ?: 'apppass';
$appDomain    = getenv('APP_DOMAIN') ?: 'works-on-my-machine.rip';
$appPort      = getenv('APP_PORT') ?: '8443';
$mailFrom     = getenv('MAIL_FROM') ?: 'noreply@works-on-my-machine.rip';

$containerIp = gethostbyname(gethostname());
$hostname = gethostname();

$db_master = @new mysqli($dbMasterHost, $dbUser, $dbPass, $dbName);
$db_slave  = @new mysqli($dbSlaveHost, $dbUser, $dbPass, $dbName);

if ($db_master->connect_error) {
    die('Master DB connection failed: ' . htmlspecialchars($db_master->connect_error));
}

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function exec_prepared($db, $sql, $types = '', ...$params) {
    $stmt = $db->prepare($sql);
    if (!$stmt) return false;
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function fetch_one_prepared($db, $sql, $types = '', ...$params) {
    $stmt = $db->prepare($sql);
    if (!$stmt) return null;
    if ($types !== '') $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) { $stmt->close(); return null; }
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return $row;
}

function send_confirmation_email($to, $link, $from) {
    $subject = 'Confirm your account';
    $message = "Please confirm your registration:\n\n$link\n";
    $headers = "From: $from\r\n";
    return mail($to, $subject, $message, $headers);
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: /');
    exit;
}

$message = '';
$messageType = '';

if (isset($_GET['confirm'])) {
    $token = trim($_GET['confirm']);
    if (preg_match('/^[a-f0-9]{64}$/', $token)) {
        $user = fetch_one_prepared($db_master, 'SELECT email FROM users WHERE confirmation_token = ? LIMIT 1', 's', $token);
        if ($user) {
            exec_prepared($db_master, 'UPDATE users SET confirmed = 1, confirmation_token = NULL WHERE confirmation_token = ?', 's', $token);
            $message = "Email confirmed for " . e($user['email']) . ".";
            $messageType = 'success';
        }
    }
}

if (isset($_POST['register'])) {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($name !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && $password !== '') {
        $existing = fetch_one_prepared($db_master, 'SELECT id FROM users WHERE email = ? LIMIT 1', 's', $email);
        if ($existing) {
            $message = "Email already registered.";
            $messageType = 'error';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $token = bin2hex(random_bytes(32));
            $ok = exec_prepared($db_master, 'INSERT INTO users (name, email, password_hash, confirmed, confirmation_token) VALUES (?, ?, ?, 0, ?)', 'ssss', $name, $email, $hash, $token);
if ($ok) {
    $confirmLink = 'https://' . $appDomain . ':' . $appPort . '/?confirm=' . urlencode($token);

    if (send_confirmation_email($email, $confirmLink, $mailFrom)) {
        $message = "Registration successful. Check Mailpit for confirmation email.";
        $messageType = 'info';
    } else {
        $message = "User created, but email sending failed.";
        $messageType = 'warning';
    }
}
	 else {
                $message = "Registration failed.";
                $messageType = 'error';
            }
        }
    } else {
        $message = "Invalid registration data.";
        $messageType = 'error';
    }
}

if (isset($_POST['login'])) {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $db_read = (!$db_slave->connect_error) ? $db_slave : $db_master;
    $user = fetch_one_prepared($db_read, 'SELECT id, name, email, password_hash, confirmed FROM users WHERE email = ? LIMIT 1', 's', $email);
    if (!$user && $db_read !== $db_master) {
        $user = fetch_one_prepared($db_master, 'SELECT id, name, email, password_hash, confirmed FROM users WHERE email = ? LIMIT 1', 's', $email);
    }
    if ($user && (int)$user['confirmed'] === 1 && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_email'] = $user['email'];
        header('Location: /');
        exit;
    } else {
        $message = "Invalid credentials or unconfirmed account.";
        $messageType = 'error';
    }
}

if (!empty($_SESSION['user_id'])) {
    if (isset($_POST['add_user'])) {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        if ($name !== '') {
            exec_prepared($db_master, 'INSERT INTO users (name, email, confirmed) VALUES (?, ?, 1)', 'ss', $name, $email ?: null);
            $message = "User added successfully.";
            $messageType = 'success';
        }
    }
    if (isset($_POST['update_user'])) {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if ($id > 0 && $name !== '') {
            exec_prepared($db_master, 'UPDATE users SET name = ? WHERE id = ?', 'si', $name, $id);
            $message = "User updated successfully.";
            $messageType = 'success';
        }
    }
    if (isset($_POST['delete_user'])) {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            exec_prepared($db_master, 'DELETE FROM users WHERE id = ?', 'i', $id);
            $message = "User deleted.";
            $messageType = 'success';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DevOps Project</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Mono:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #0a0a0f;
            --surface: #111118;
            --surface2: #18181f;
            --border: rgba(255,255,255,0.07);
            --accent: #7c6dfa;
            --accent2: #fa6d8e;
            --accent-glow: rgba(124,109,250,0.18);
            --text: #e8e8f0;
            --muted: #6b6b80;
            --success: #4ade80;
            --error: #f87171;
            --warning: #fb923c;
            --info: #60a5fa;
            --radius: 12px;
            --font-head: 'Syne', sans-serif;
            --font-mono: 'DM Mono', monospace;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            background: var(--bg);
            color: var(--text);
            font-family: var(--font-mono);
            min-height: 100vh;
            background-image:
                    radial-gradient(ellipse 80% 50% at 20% -10%, rgba(124,109,250,0.12) 0%, transparent 60%),
                    radial-gradient(ellipse 60% 40% at 80% 110%, rgba(250,109,142,0.08) 0%, transparent 60%);
        }

        .page-wrap {
            max-width: 960px;
            margin: 0 auto;
            padding: 40px 24px 80px;
        }

        /* HEADER */
        .site-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 28px 0 48px;
            border-bottom: 1px solid var(--border);
            margin-bottom: 48px;
            flex-wrap: wrap;
            gap: 16px;
        }

        .logo {
            font-family: var(--font-head);
            font-size: 22px;
            font-weight: 800;
            letter-spacing: -0.5px;
            background: linear-gradient(135deg, var(--accent), var(--accent2));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .meta-pills {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .pill {
            background: var(--surface2);
            border: 1px solid var(--border);
            border-radius: 999px;
            padding: 4px 12px;
            font-size: 11px;
            color: var(--muted);
            font-family: var(--font-mono);
            white-space: nowrap;
        }

        .pill span { color: var(--accent); }

        /* USER BAR */
        .user-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--surface2);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 14px 20px;
            margin-bottom: 32px;
        }

        .user-bar strong {
            font-family: var(--font-head);
            font-weight: 600;
            color: var(--text);
        }

        .btn-ghost {
            font-family: var(--font-mono);
            font-size: 12px;
            color: var(--muted);
            text-decoration: none;
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 6px 14px;
            transition: all 0.2s;
        }

        .btn-ghost:hover { color: var(--error); border-color: var(--error); }

        /* TOAST / MESSAGE */
        .toast {
            border-radius: var(--radius);
            padding: 14px 18px;
            font-size: 13px;
            margin-bottom: 28px;
            border-left: 3px solid;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-8px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .toast.success { background: rgba(74,222,128,0.08); border-color: var(--success); color: var(--success); }
        .toast.error   { background: rgba(248,113,113,0.08); border-color: var(--error);   color: var(--error); }
        .toast.warning { background: rgba(251,146,60,0.08);  border-color: var(--warning); color: var(--warning); }
        .toast.info    { background: rgba(96,165,250,0.08);  border-color: var(--info);    color: var(--info); }

        /* GRID LAYOUT */
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; }

        @media (max-width: 640px) {
            .grid-2, .grid-3 { grid-template-columns: 1fr; }
        }

        /* CARDS */
        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 28px;
            transition: border-color 0.2s;
        }

        .card:hover { border-color: rgba(124,109,250,0.25); }

        .card-title {
            font-family: var(--font-head);
            font-size: 14px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--muted);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .card-title::before {
            content: '';
            display: inline-block;
            width: 6px; height: 6px;
            border-radius: 50%;
            background: var(--accent);
            flex-shrink: 0;
        }

        /* FORMS */
        .field { margin-bottom: 14px; }

        input[type="text"],
        input[type="email"],
        input[type="password"],
        input[type="number"] {
            width: 100%;
            background: var(--surface2);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 11px 14px;
            font-size: 13px;
            font-family: var(--font-mono);
            color: var(--text);
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        input:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--accent-glow);
        }

        input::placeholder { color: var(--muted); }

        .btn {
            width: 100%;
            background: linear-gradient(135deg, var(--accent), #5f51e8);
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 12px;
            font-family: var(--font-head);
            font-size: 14px;
            font-weight: 700;
            letter-spacing: 0.04em;
            cursor: pointer;
            transition: opacity 0.2s, transform 0.15s;
            margin-top: 4px;
        }

        .btn:hover { opacity: 0.9; transform: translateY(-1px); }
        .btn:active { transform: translateY(0); }

        .btn.danger { background: linear-gradient(135deg, var(--error), #c0392b); }
        .btn.secondary { background: var(--surface2); color: var(--text); border: 1px solid var(--border); }

        /* DIVIDER */
        .divider {
            border: none;
            border-top: 1px solid var(--border);
            margin: 36px 0;
        }

        /* SECTION HEADING */
        .section-heading {
            font-family: var(--font-head);
            font-size: 26px;
            font-weight: 800;
            margin-bottom: 24px;
            letter-spacing: -0.5px;
        }

        /* USER TABLE */
        .user-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            margin-top: 4px;
        }

        .user-table th {
            font-family: var(--font-head);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: var(--muted);
            text-align: left;
            padding: 10px 14px;
            border-bottom: 1px solid var(--border);
        }

        .user-table td {
            padding: 12px 14px;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
            color: var(--text);
        }

        .user-table tr:last-child td { border-bottom: none; }
        .user-table tr:hover td { background: rgba(255,255,255,0.02); }

        .badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 500;
        }

        .badge.yes { background: rgba(74,222,128,0.12); color: var(--success); }
        .badge.no  { background: rgba(248,113,113,0.12); color: var(--error); }

        /* LINKS */
        .link-row {
            display: flex;
            gap: 12px;
            margin-top: 20px;
            flex-wrap: wrap;
        }

        .link-chip {
            font-family: var(--font-mono);
            font-size: 12px;
            color: var(--accent);
            text-decoration: none;
            border: 1px solid rgba(124,109,250,0.3);
            border-radius: 6px;
            padding: 6px 14px;
            transition: all 0.2s;
            background: rgba(124,109,250,0.05);
        }

        .link-chip:hover {
            background: rgba(124,109,250,0.15);
            border-color: var(--accent);
        }
    </style>
</head>
<body>
<div class="page-wrap">

    <!-- HEADER -->
    <header class="site-header">
        <div class="logo">⬡ DevOps Project</div>
        <div class="meta-pills">
            <div class="pill">host: <span><?= e($hostname) ?></span></div>
            <div class="pill">ip: <span><?= e($containerIp) ?></span></div>
            <div class="pill">domain: <span><?= e($appDomain) ?></span></div>
            <div class="pill">sid: <span><?= e(substr(session_id(), 0, 12)) ?>…</span></div>
        </div>
    </header>

    <?php if ($message): ?>
        <div class="toast <?= e($messageType) ?>"><?= $message ?></div>
    <?php endif; ?>

    <?php if (empty($_SESSION['user_id'])): ?>

        <!-- AUTH SECTION -->
        <h2 class="section-heading">Welcome back</h2>
        <div class="grid-2">
            <div class="card">
                <div class="card-title">Login</div>
                <form method="POST">
                    <div class="field"><input type="email" name="email" placeholder="Email address" required></div>
                    <div class="field"><input type="password" name="password" placeholder="Password" required></div>
                    <button type="submit" name="login" class="btn">Sign In →</button>
                </form>
            </div>

            <div class="card">
                <div class="card-title">Create Account</div>
                <form method="POST">
                    <div class="field"><input type="text" name="name" placeholder="Full name" required></div>
                    <div class="field"><input type="email" name="email" placeholder="Email address" required></div>
                    <div class="field"><input type="password" name="password" placeholder="Password" required></div>
                    <button type="submit" name="register" class="btn">Register</button>
                </form>
            </div>
        </div>

        <div class="link-row">
            <a href="/phpmyadmin/" class="link-chip">📊 phpMyAdmin</a>
            <a href="/logs/" class="link-chip">📈 GoAccess Logs</a>
        </div>

    <?php else: ?>

        <!-- LOGGED IN -->
        <div class="user-bar">
            <strong>👤 <?= e($_SESSION['user_name']) ?> <span style="color:var(--muted);font-weight:400;font-size:13px">&lt;<?= e($_SESSION['user_email']) ?>&gt;</span></strong>
            <a href="?logout=1" class="btn-ghost">Logout</a>
        </div>

        <!-- USER LIST -->
        <?php
        $db_read = (!$db_slave->connect_error) ? $db_slave : $db_master;
        $stmt = $db_read->prepare('SELECT id, name, email, confirmed FROM users ORDER BY id ASC');
        ?>
        <div class="card" style="margin-bottom:24px">
            <div class="card-title">User List <span style="color:var(--muted);font-size:10px;margin-left:4px">(from <?= (!$db_slave->connect_error) ? 'slave' : 'master' ?>)</span></div>
            <table class="user-table">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Confirmed</th>
                </tr>
                </thead>
                <tbody>
                <?php
                if ($stmt && $stmt->execute()) {
                    $result = $stmt->get_result();
                    while ($row = $result->fetch_assoc()):
                        ?>
                        <tr>
                            <td style="color:var(--muted)"><?= e($row['id']) ?></td>
                            <td><?= e($row['name']) ?></td>
                            <td style="color:var(--muted)"><?= e($row['email']) ?></td>
                            <td><span class="badge <?= $row['confirmed'] ? 'yes' : 'no' ?>"><?= $row['confirmed'] ? 'Yes' : 'No' ?></span></td>
                        </tr>
                    <?php endwhile; $stmt->close(); } ?>
                </tbody>
            </table>
        </div>

        <!-- CRUD ACTIONS -->
        <div class="grid-3">
            <div class="card">
                <div class="card-title">Add User</div>
                <form method="POST">
                    <div class="field"><input type="text" name="name" placeholder="Full name" required></div>
                    <div class="field"><input type="email" name="email" placeholder="Email (optional)"></div>
                    <button type="submit" name="add_user" class="btn">Add User</button>
                </form>
            </div>

            <div class="card">
                <div class="card-title">Update User</div>
                <form method="POST">
                    <div class="field"><input type="number" name="id" placeholder="User ID" required></div>
                    <div class="field"><input type="text" name="name" placeholder="New name" required></div>
                    <button type="submit" name="update_user" class="btn secondary">Update</button>
                </form>
            </div>

            <div class="card">
                <div class="card-title">Delete User</div>
                <form method="POST">
                    <div class="field"><input type="number" name="id" placeholder="User ID" required></div>
                    <button type="submit" name="delete_user" class="btn danger">Delete</button>
                </form>
            </div>
        </div>

        <div class="link-row">
            <a href="/phpmyadmin/" class="link-chip">📊 phpMyAdmin</a>
            <a href="/logs/" class="link-chip">📈 GoAccess Logs</a>
        </div>

    <?php endif; ?>

</div>
</body>
</html>
