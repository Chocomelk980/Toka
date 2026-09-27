<?php
session_start();

if (!isset($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

header('Location: ../DASHBOARD/my_groups.php');
exit;

$user = $_SESSION['user'];
$success = $_SESSION['auth_success'] ?? '';
unset($_SESSION['auth_success']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Toka - Welcome</title>
    <link rel="stylesheet" href="auth.css">
</head>
<body>
    <main class="auth-card">
        <header class="auth-header">
            <img class="toka-logo" src="../assets/toka_bird.svg" alt="Toka">
            <h1 class="auth-title">Welcome, <?= htmlspecialchars($user['username']) ?>!</h1>
            <p class="auth-subtitle">Your authentication flow is working.</p>
        </header>

        <?php if ($success !== ''): ?>
            <div class="alert success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <p class="switch-text">
            Logged in as <strong><?= htmlspecialchars($user['email']) ?></strong>
        </p>

        <form action="logout.php" method="post">
            <button class="auth-button" type="submit">Log Out</button>
        </form>
    </main>
</body>
</html>
