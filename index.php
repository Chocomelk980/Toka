<?php
session_start();
require_once __DIR__ . '/LOGIN_PAGE/access.php';

$signedInUser = tokaCurrentUser();
if ($signedInUser) {
    header('Location: ' . tokaHome($signedInUser), true, 303);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Toka - Save Together</title>
    <link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
</head>
<body>

    <!-- Coin Illustration Background -->
    <img src="assets/background.svg" class="auth-bg" alt="" aria-hidden="true">

    <!-- Navigation Bar -->
    <header class="navbar">
        <div class="logo">
            <a href="index.php">
                <img class="toka-logo" src="assets/Toka.svg" alt="Toka">
            </a>
        </div>

        <div class="nav-buttons">
            <a href="LOGIN_PAGE/index.php" class="nav-login">Log In</a>
            <a href="LOGIN_PAGE/register.php" class="nav-cta">Register</a>
        </div>
    </header>

    <!-- Main Landing Section -->
    <main class="hero">
        <div class="hero-content">
            <p class="eyebrow">SAVE TOGETHER. STAY ORGANIZED.</p>

            <h1>Group savings made <span>simple.</span></h1>

            <p class="hero-description">
                Toka gives you a simple place to create, join, and manage
                group contributions while keeping everyone organized.
            </p>

            <a href="LOGIN_PAGE/register.php" class="main-cta">Get Started</a>

            <!-- Features -->
            <div class="features">
                <div class="feature">
                    <div class="feature-number">01</div>
                    <div>
                        <h3>Create</h3>
                        <p>Start and organize your own Toka group.</p>
                    </div>
                </div>

                <div class="feature">
                    <div class="feature-number">02</div>
                    <div>
                        <h3>Contribute</h3>
                        <p>Stay informed about your group contributions.</p>
                    </div>
                </div>

                <div class="feature">
                    <div class="feature-number">03</div>
                    <div>
                        <h3>Track</h3>
                        <p>Keep your group activity easy to follow.</p>
                    </div>
                </div>
            </div>
        </div>
    </main>
</body>
</html>