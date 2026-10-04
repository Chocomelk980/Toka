<?php
session_start();
require_once __DIR__ . '/access.php';

$signedInUser = tokaCurrentUser();
if ($signedInUser) {
    header('Location: ' . tokaHome($signedInUser), true, 303);
    exit;
}

$error = $_SESSION['auth_error'] ?? '';
$success = $_SESSION['auth_success'] ?? '';
$bannedLogin = !empty($_SESSION['auth_banned_login']);
unset($_SESSION['auth_error'], $_SESSION['auth_success'], $_SESSION['auth_banned_login']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Toka - Log In</title>
    <link rel="stylesheet" href="auth.css?v=<?= filemtime(__DIR__ . '/auth.css') ?>">
</head>
<body>
    <img src="../assets/background.svg" class="auth-bg" alt="" aria-hidden="true">

    <main class="auth-card login-card">
        <header class="auth-header">
            <a href="../index.php">
               <img class="toka-logo" src="../assets/toka_auth.svg" alt="Toka"> 
            </a>
            <h1 class="auth-title">Welcome Back!</h1>
            <p class="auth-subtitle">Ready to jump back in?</p>
        </header>

        <?php if ($error !== '' && !$bannedLogin): ?>
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
                    <input type="password" id="password" name="password" placeholder="Enter at least 8 characters" required>
                    <button type="button" class="password-toggle" onclick="togglePasswordVisibility('password', this)" aria-label="Toggle password visibility">
                        <svg class="eye-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                            <circle cx="12" cy="12" r="3"></circle>
                        </svg>
                    </button>
                </div>
            </div>

            <button class="auth-button" type="submit">Log In</button>
        </form>

        <p class="switch-text">
            Don't have an account? <a href="register.php">Register Now</a>
        </p>
    </main>

    <?php if ($bannedLogin): ?>
        <dialog id="banned-login-dialog" class="auth-dialog" aria-labelledby="banned-login-title" aria-describedby="banned-login-message">
            <form method="dialog">
                <span class="auth-dialog-mark" aria-hidden="true">!</span>
                <h2 id="banned-login-title">Account banned</h2>
                <p id="banned-login-message"><?= htmlspecialchars(TOKA_BANNED_LOGIN_MESSAGE, ENT_QUOTES, 'UTF-8') ?></p>
                <div class="auth-dialog-actions"><button type="submit" autofocus>OK</button></div>
            </form>
        </dialog>
        <noscript><p class="alert error" role="alert"><?= htmlspecialchars(TOKA_BANNED_LOGIN_MESSAGE, ENT_QUOTES, 'UTF-8') ?></p></noscript>
    <?php endif; ?>

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
