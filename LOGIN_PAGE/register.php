<?php
session_start();
require_once __DIR__ . '/access.php';

$signedInUser = tokaCurrentUser();
if ($signedInUser) {
    header('Location: ' . tokaHome($signedInUser), true, 303);
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
    <link rel="stylesheet" href="auth.css?v=<?= filemtime(__DIR__ . '/auth.css') ?>">
</head>
<body>
    <img src="../assets/background.svg" class="auth-bg" alt="" aria-hidden="true">
    
    <main class="auth-card register-card">
        <header class="auth-header">
            <a href="../index.php">
                <img class="toka-logo" src="../assets/toka_auth.svg" alt="Toka">
            </a>
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
                    <input type="password" id="password" name="password" placeholder="Enter at least 8 characters" required>
                    <button type="button" class="password-toggle" onclick="togglePasswordVisibility('password', this)" aria-label="Toggle password visibility">
                        <svg class="eye-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                            <circle cx="12" cy="12" r="3"></circle>
                        </svg>
                    </button>
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
        function togglePasswordVisibility(inputId, button) {
            const input = document.getElementById(inputId);
            if (!input) return;

            const isPassword = input.type === 'password';
            input.type = isPassword ? 'text' : 'password';

            const eyeIcon = `<svg class="eye-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>`;
            const eyeOffIcon = `<svg class="eye-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>`;

            button.innerHTML = isPassword ? eyeOffIcon : eyeIcon;
        }
    </script>
</body>
</html>
