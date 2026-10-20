<?php
session_start();

require_once __DIR__ . '/access.php';

$action = $_POST['action'] ?? '';
$signedInUser = tokaCurrentUser();
$activeTabToken = $signedInUser ? tokaValidTabAuthToken() : null;
if ($signedInUser && $action !== 'login' && ($action !== 'register' || $activeTabToken !== null)) {
    if ($activeTabToken !== null) {
        header('Location: ' . tokaAppendTabAuthToUrl(tokaHome($signedInUser), $activeTabToken), true, 303);
    } else {
        header('Location: index.php?tab_auth_required=1', true, 303);
    }
    exit;
}

function fail(string $message, string $page, bool $banned = false, string|array|null $errorFields = null): never {
    $_SESSION['auth_error'] = $message;
    $_SESSION['auth_banned_login'] = $banned;
    $allowedFields = ['full_name', 'username', 'email', 'password', 'confirm_password'];
    $errorFields = is_array($errorFields) ? $errorFields : ($errorFields === null ? [] : [$errorFields]);
    $_SESSION['auth_error_fields'] = array_values(array_unique(array_intersect($allowedFields, $errorFields)));

    // Keep safe form values across the redirect; credentials are deliberately never stored.
    $oldInput = [];
    $keys = $page === 'register.php' ? ['full_name', 'username', 'email'] : ['username'];
    foreach ($keys as $key) {
        $value = $_POST[$key] ?? '';
        $oldInput[$key] = is_string($value) ? trim($value) : '';
    }
    $_SESSION['auth_old_input'] = $oldInput;

    $tabToken = tokaValidTabAuthToken();
    if ($tabToken !== null) $page = tokaAppendTabAuthToUrl($page, $tabToken);
    header("Location: {$page}");
    exit;
}

if ($action === 'register') {
    $fullName = is_string($_POST['full_name'] ?? null) ? trim($_POST['full_name']) : '';
    $username = is_string($_POST['username'] ?? null) ? trim($_POST['username']) : '';
    $email = is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '';
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $confirm = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';

    if ($fullName === '' || $username === '' || $email === '' || $password === '' || $confirm === '') {
        $missingFields = [];
        foreach (['full_name' => $fullName, 'username' => $username, 'email' => $email,
                  'password' => $password, 'confirm_password' => $confirm] as $field => $value) {
            if ($value === '') $missingFields[] = $field;
        }
        fail('Please complete all fields.', 'register.php', false, $missingFields);
    }

    if (!mb_check_encoding($fullName, 'UTF-8') || mb_strlen($fullName, 'UTF-8') > 150) {
        fail('Full name must be valid text with at most 150 characters.', 'register.php', false, 'full_name');
    }
    $fullName = preg_replace('/\s+/u', ' ', $fullName) ?? $fullName;

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail('Please enter a valid email address.', 'register.php', false, 'email');
    }

    if (strlen($password) < 8) {
        fail('Password must be at least 8 characters.', 'register.php', false, 'password');
    }

    if ($password !== $confirm) {
        fail('Passwords do not match.', 'register.php', false, 'confirm_password');
    }

    try {
        $pdo = getDbConnection();

        $checkUsername = $pdo->prepare('SELECT user_id FROM users WHERE username = :username LIMIT 1');
        $checkUsername->execute([':username' => $username]);
        if ($checkUsername->fetch()) {
            fail('That username is already registered.', 'register.php', false, 'username');
        }

        $checkEmail = $pdo->prepare('SELECT user_id FROM users WHERE email = :email LIMIT 1');
        $checkEmail->execute([':email' => $email]);
        if ($checkEmail->fetch()) {
            fail('That email address is already registered.', 'register.php', false, 'email');
        }

        $checkFullName = $pdo->prepare(
            'SELECT user_id FROM users
             WHERE LOWER(TRIM(full_name)) = LOWER(:full_name)
             LIMIT 1'
        );
        $checkFullName->execute([':full_name' => $fullName]);
        if ($checkFullName->fetch()) {
            fail('That full name is already registered.', 'register.php', false, 'full_name');
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $insertStmt = $pdo->prepare(
            'INSERT INTO users (username, full_name, email, password_hash, trust_score, is_admin, account_status)
             VALUES (:username, :full_name, :email, :password_hash, 100, 0, "Active")'
        );

        $insertStmt->execute([
            ':username' => $username,
            ':full_name' => $fullName,
            ':email' => $email,
            ':password_hash' => $passwordHash,
        ]);

        unset($_SESSION['auth_error'], $_SESSION['auth_banned_login'], $_SESSION['auth_old_input'], $_SESSION['auth_error_fields']);
        $_SESSION['auth_success'] = 'Account created successfully. You can now log in.';
        header('Location: index.php');
        exit;
    } catch (PDOException $e) {
        error_log('Registration DB error: ' . $e->getMessage());
        fail('Unable to create your account right now.', 'register.php');
    }
}

if ($action === 'login') {
    $username = is_string($_POST['username'] ?? null) ? trim($_POST['username']) : '';
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

    if ($username === '' || $password === '') {
        $missingFields = [];
        if ($username === '') $missingFields[] = 'username';
        if ($password === '') $missingFields[] = 'password';
        fail('Please enter your username and password.', 'index.php', false, $missingFields);
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
            fail('Invalid username or password.', 'index.php', false, ['username', 'password']);
        }

        if ($user['account_status'] === 'Banned') {
            fail(TOKA_BANNED_LOGIN_MESSAGE, 'index.php', true);
        }
        if (in_array($user['account_status'], ['Suspended', 'Action Needed'], true)) {
            fail('Your account is currently suspended.', 'index.php');
        }

        if (!$signedInUser || (int) $signedInUser['user_id'] !== (int) $user['user_id']) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        unset($_SESSION['auth_error'], $_SESSION['auth_banned_login'], $_SESSION['auth_old_input'], $_SESSION['auth_error_fields']);
        $_SESSION['user'] = [
            'user_id' => (int) $user['user_id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'trust_score' => (int) $user['trust_score'],
            'is_admin' => tokaIsAdmin($user),
            'account_status' => $user['account_status'],
        ];

        $tabToken = tokaCreateTabAuthToken();
        $destination = tokaAppendTabAuthToUrl(tokaHome($_SESSION['user']), $tabToken);
        $safeToken = json_encode($tabToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $safeDestination = json_encode($destination, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width">'
            . '<title>Signing in</title><p>Opening your account…</p><script>try{sessionStorage.setItem('
            . json_encode(TOKA_TAB_AUTH_STORAGE_KEY) . ',' . $safeToken . ');}catch(e){}location.replace('
            . $safeDestination . ');</script><noscript><a href="'
            . htmlspecialchars($destination, ENT_QUOTES, 'UTF-8')
            . '">Continue to Toka</a></noscript></html>';
        exit;
    } catch (PDOException $e) {
        error_log('Login DB error: ' . $e->getMessage());
        fail('Invalid username or password.', 'index.php');
    }
}

fail('Invalid request.', 'index.php');
