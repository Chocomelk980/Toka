<?php
session_start();

require_once __DIR__ . '/access.php';

$signedInUser = tokaCurrentUser();
if ($signedInUser) {
    header('Location: ' . tokaHome($signedInUser), true, 303);
    exit;
}

$action = $_POST['action'] ?? '';

function fail(string $message, string $page, bool $banned = false): never {
    $_SESSION['auth_error'] = $message;
    $_SESSION['auth_banned_login'] = $banned;
    header("Location: {$page}");
    exit;
}

if ($action === 'register') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if ($username === '' || $email === '' || $password === '' || $confirm === '') {
        fail('Please complete all fields.', 'register.php');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail('Please enter a valid email address.', 'register.php');
    }

    if (strlen($password) < 8) {
        fail('Password must be at least 8 characters.', 'register.php');
    }

    if ($password !== $confirm) {
        fail('Passwords do not match.', 'register.php');
    }

    try {
        $pdo = getDbConnection();

        $checkUsername = $pdo->prepare('SELECT user_id FROM users WHERE username = :username LIMIT 1');
        $checkUsername->execute([':username' => $username]);
        if ($checkUsername->fetch()) {
            fail('That username is already registered.', 'register.php');
        }

        $checkEmail = $pdo->prepare('SELECT user_id FROM users WHERE email = :email LIMIT 1');
        $checkEmail->execute([':email' => $email]);
        if ($checkEmail->fetch()) {
            fail('That email address is already registered.', 'register.php');
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $insertStmt = $pdo->prepare(
            'INSERT INTO users (username, email, password_hash, trust_score, is_admin, account_status)
             VALUES (:username, :email, :password_hash, 100, 0, "Active")'
        );

        $insertStmt->execute([
            ':username' => $username,
            ':email' => $email,
            ':password_hash' => $passwordHash,
        ]);

        unset($_SESSION['auth_error'], $_SESSION['auth_banned_login']);
        $_SESSION['auth_success'] = 'Account created successfully. You can now log in.';
        header('Location: index.php');
        exit;
    } catch (PDOException $e) {
        error_log('Registration DB error: ' . $e->getMessage());
        fail('Unable to create your account right now.', 'register.php');
    }
}

if ($action === 'login') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        fail('Please enter your username and password.', 'index.php');
    }

    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare(
            'SELECT user_id, username, email, password_hash, trust_score, is_admin, account_status
             FROM users
             WHERE username = :username
             LIMIT 1'
        );
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            fail('Invalid username or password.', 'index.php');
        }

        if ($user['account_status'] === 'Banned') {
            fail(TOKA_BANNED_LOGIN_MESSAGE, 'index.php', true);
        }
        if (in_array($user['account_status'], ['Suspended', 'Action Needed'], true)) {
            fail('Your account is currently suspended.', 'index.php');
        }

        session_regenerate_id(true);
        unset($_SESSION['auth_error'], $_SESSION['auth_banned_login']);
        $_SESSION['user'] = [
            'user_id' => (int) $user['user_id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'trust_score' => (int) $user['trust_score'],
            'is_admin' => tokaIsAdmin($user),
            'account_status' => $user['account_status'],
        ];

        header('Location: ' . tokaHome($_SESSION['user']), true, 303);
        exit;
    } catch (PDOException $e) {
        error_log('Login DB error: ' . $e->getMessage());
        fail('Invalid username or password.', 'index.php');
    }
}

fail('Invalid request.', 'index.php');
