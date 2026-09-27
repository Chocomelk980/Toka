<?php
session_start();

if (isset($_SESSION['user'])) {
    header('Location: ../DASHBOARD/my_groups.php');
    exit;
}

$error = $_SESSION['auth_error'] ?? '';
unset($_SESSION['auth_error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Toka - Register</title>
    <link rel="stylesheet" href="auth.css">
</head>
<body>
    <main class="auth-card">
        <header class="auth-header">
            <img class="toka-logo" src="../assets/toka_bird.svg" alt="Toka">
            <h1 class="auth-title">Welcome to Toka!</h1>
            <p class="auth-subtitle">Ready to register and start a new cycle?</p>
        </header>

        <?php if ($error !== ''): ?>
            <div class="alert error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form class="auth-form" action="auth.php" method="post" novalidate>
            <input type="hidden" name="action" value="register">

            <div class="field">
                <label for="username">Username:</label>
                <div class="input-wrap">
                    <input id="username" name="username" type="text"
                           placeholder="Enter Username" autocomplete="username" maxlength="50" required>
                </div>
            </div>

            <div class="field">
                <label for="email">Email Address:</label>
                <div class="input-wrap">
                    <input id="email" name="email" type="email"
                           placeholder="Enter Email" autocomplete="email" maxlength="120" required>
                </div>
            </div>

            <div class="field">
                <label for="register_password">Password:</label>
                <div class="input-wrap has-toggle">
                    <input id="register_password" name="password" type="password"
                           placeholder="Enter at least 8 characters"
                           autocomplete="new-password" minlength="8" required>
                    <button class="password-toggle" type="button"
                            aria-label="Show password"
                            onclick="togglePassword('register_password', this)">◉</button>
                </div>
            </div>

            <div class="field">
                <label for="confirm_password">Confirm Password:</label>
                <div class="input-wrap has-toggle">
                    <input id="confirm_password" name="confirm_password" type="password"
                           placeholder="Re-enter your password"
                           autocomplete="new-password" minlength="8" required>
                    <button class="password-toggle" type="button"
                            aria-label="Show password"
                            onclick="togglePassword('confirm_password', this)">◉</button>
                </div>
            </div>

            <button class="auth-button" type="submit">Register</button>
        </form>

        <p class="switch-text">
            Already have an account? <a href="index.php">Log in</a>
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
