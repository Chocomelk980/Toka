<?php
session_start();

if (isset($_SESSION['user'])) {
    header('Location: ../DASHBOARD/my_groups.php');
    exit;
}

$error = $_SESSION['auth_error'] ?? '';
$success = $_SESSION['auth_success'] ?? '';
unset($_SESSION['auth_error'], $_SESSION['auth_success']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Toka - Log In</title>
    <link rel="stylesheet" href="auth.css">
</head>
<body>
    <main class="auth-card">
        <header class="auth-header">
            <img class="toka-logo" src="../assets/toka_bird.svg" alt="Toka">
            <h1 class="auth-title">Welcome Back!</h1>
            <p class="auth-subtitle">Ready to jump back in?</p>
        </header>

        <?php if ($error !== ''): ?>
            <div class="alert error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="alert success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <form class="auth-form" action="auth.php" method="post" novalidate>
            <input type="hidden" name="action" value="login">

            <div class="field">
                <label for="username">Username:</label>
                <div class="input-wrap">
                    <input id="username" name="username" type="text"
                           placeholder="Enter Username" autocomplete="username" required>
                </div>
            </div>

            <div class="field">
                <label for="login_password">Password:</label>
                <div class="input-wrap has-toggle">
                    <input id="login_password" name="password" type="password"
                           placeholder="Enter your Password" autocomplete="current-password" required>
                    <button class="password-toggle" type="button"
                            aria-label="Show password"
                            onclick="togglePassword('login_password', this)">◉</button>
                </div>
            </div>

            <button class="auth-button" type="submit">Log In</button>
        </form>

        <p class="switch-text">
            Don't have an account? <a href="register.php">Register Now</a>
        </p>
    </main>

    <script>
        function togglePassword(inputId, button) {
            const input = document.getElementById(inputId);
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            button.textContent = show ? '◉' : '◌';
        }
    </script>
</body>
</html>
