<?php
/**
 * AquaTrack - Admin and Staff Login
 * Native PHP + MySQL. Login UI styling will live in assets/css/login.css.
 * Move the database settings to config/database.php later.
 */

declare(strict_types=1);

session_name('aquatrack_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function dashboardForRole(string $role): string
{
    return $role === 'Admin'
        ? '../admin/dashboard.php'
        : '../staff/dashboard.php';
}

if (isset($_SESSION['user_id'], $_SESSION['role'])) {
    header('Location: ' . dashboardForRole((string) $_SESSION['role']));
    exit;
}

if (empty($_SESSION['login_csrf'])) {
    $_SESSION['login_csrf'] = bin2hex(random_bytes(32));
}

$error = '';
$identity = '';
$_SESSION['login_attempts'] ??= [];
$_SESSION['login_attempts'] = array_values(array_filter($_SESSION['login_attempts'], fn($t) => $t > time()-900));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identity = trim((string) ($_POST['identity'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $csrf = (string) ($_POST['csrf_token'] ?? '');

    if (count($_SESSION['login_attempts']) >= 8) {
        $error = 'Too many attempts. Please try again later.';
    } elseif (!hash_equals((string) $_SESSION['login_csrf'], $csrf)) {
        $error = 'Session expired. Please refresh and try again.';
    } elseif ($identity === '' || $password === '') {
        $error = 'Please enter your username/email and password.';
    } elseif (strlen($identity) > 255 || strlen($password) > 4096) {
        $error = 'Invalid login details.';
    } else {
        try {
            $pdo = require __DIR__ . '/../config/database.php';

            $query = $pdo->prepare(
                'SELECT user_id, full_name, username, password, role, status
                 FROM users
                 WHERE username = :username OR email = :email
                 LIMIT 1'
            );
            $query->execute([
                'username' => $identity,
                'email' => $identity,
            ]);
            $user = $query->fetch();

            if ($user && $user['status'] === 'Active'
                && password_verify($password, (string) $user['password'])) {
                unset($_SESSION['login_attempts']);
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int) $user['user_id'];
                $_SESSION['full_name'] = (string) $user['full_name'];
                $_SESSION['username'] = (string) $user['username'];
                $_SESSION['role'] = (string) $user['role'];
                unset($_SESSION['login_csrf']);

                header('Location: ' . dashboardForRole((string) $user['role']));
                exit;
            }

            $_SESSION['login_attempts'][] = time();
            $error = 'Incorrect login details or inactive account.';
        } catch (PDOException $exception) {
            error_log('[AquaTrack login] ' . $exception->getMessage());
            $error = 'Database temporarily unavailable.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0e6073">
    <title>AquaTrack | Login</title>
    <link rel="stylesheet" href="../assets/css/login.css">
</head>
<body>
    <div class="page">
        <a class="back-link" href="../index.php">← Back to Home</a>

        <main class="login-card">
            <section class="intro" aria-label="About AquaTrack">
                <div class="logo">
                    <svg viewBox="0 0 48 48" fill="none" aria-hidden="true">
                        <path d="M24 4C24 4 8 22 8 32C8 40.8 15.2 46 24 46C32.8 46 40 40.8 40 32C40 22 24 4 24 4Z" fill="#9DE2E9"/>
                        <path d="M13 33C19 27 27 39 35 31" stroke="#0e6073" stroke-width="3.5" stroke-linecap="round"/>
                    </svg>
                    <div>aqua<span>track</span></div>
                </div>

                <div class="intro-copy">
                    <h1>Welcome back!</h1>
                    <p>Manage orders, deliveries, inventory, and payments in one place for Gaeven's Water Refilling Station.</p>
                </div>
                <small>Water Station Management System</small>
            </section>

            <section class="form-panel" aria-labelledby="login-title">
                <span class="eyebrow">AUTHORIZED ACCESS</span>
                <h2 id="login-title">Sign in</h2>
                <p class="subtitle">Enter the account details provided by your administrator.</p>

                <?php if ($error !== ''): ?>
                    <div class="error" role="alert"><?= escape($error) ?></div>
                <?php endif; ?>

                <form method="post" action="login.php">
                    <input type="hidden" name="csrf_token" value="<?= escape((string) $_SESSION['login_csrf']) ?>">

                    <div class="field">
                        <label for="identity">Username or Email</label>
                        <input id="identity" name="identity" type="text"
                               value="<?= escape($identity) ?>"
                               placeholder="Enter your username or email"
                               autocomplete="username" maxlength="255" required autofocus>
                    </div>

                    <div class="field">
                        <label for="password">Password</label>
                        <input id="password" name="password" type="password"
                               placeholder="Enter your password"
                               autocomplete="current-password" required>
                    </div>

                    <button class="submit" type="submit">Sign In →</button>
                </form>
                <p style="margin-top:15px"><a href="forgot.php">Forgot password?</a></p>

                <p class="note">No public registration. Staff accounts are created by the Admin.</p>
            </section>
        </main>

        <p class="page-footer">AquaTrack · Gaeven's Water Refilling Station</p>
    </div>
</body>
</html>
