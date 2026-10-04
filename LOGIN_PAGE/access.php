<?php
require_once __DIR__ . '/dbconnect.php';

const TOKA_BANNED_LOGIN_MESSAGE = 'Log in failed, you are banned from entering this website.';

function tokaIsAdmin(array $user): bool
{
    // A matching username alone never grants privileges to a registered account.
    return !empty($user['is_admin']) && ($user['username'] ?? '') === 'SaruPeroAdmin';
}

function tokaHome(array $user): string
{
    return tokaIsAdmin($user) ? '../DASHBOARD/admin.php' : '../DASHBOARD/my_groups.php';
}

function tokaCurrentUser(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $userId = (int) ($_SESSION['user']['user_id'] ?? 0);
    if ($userId < 1) return null;

    try {
        $stmt = getDbConnection()->prepare(
            'SELECT user_id, username, email, trust_score, is_admin, account_status
             FROM users WHERE user_id = :user_id LIMIT 1'
        );
        $stmt->execute([':user_id' => $userId]);
        $user = $stmt->fetch();
    } catch (PDOException $e) {
        error_log('Account access check failed: ' . $e->getMessage());
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        exit('We could not verify your account right now. Please refresh to try again.');
    }

    if (!$user || $user['account_status'] !== 'Active') {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['auth_banned_login'] = $user && $user['account_status'] === 'Banned';
        $_SESSION['auth_error'] = $_SESSION['auth_banned_login']
            ? TOKA_BANNED_LOGIN_MESSAGE : 'Please sign in with an active account.';
        return null;
    }

    $user['user_id'] = (int) $user['user_id'];
    $user['trust_score'] = (int) $user['trust_score'];
    $user['is_admin'] = tokaIsAdmin($user);
    if ((bool) ($_SESSION['user']['is_admin'] ?? false) !== $user['is_admin']) {
        session_regenerate_id(true);
    }
    $_SESSION['user'] = $user;

    if ($user['is_admin']) {
        unset($_SESSION['draft_group'], $_SESSION['group_form_token'],
            $_SESSION['generated_group_code'], $_SESSION['join_preview_group_id'],
            $_SESSION['join_request_csrf']);
    }
    return $user;
}

function tokaRequireRole(bool $adminOnly = false): array
{
    $user = tokaCurrentUser();
    if (!$user) {
        header('Location: ../LOGIN_PAGE/index.php', true, 303);
        exit;
    }

    if (tokaIsAdmin($user) !== $adminOnly) {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            exit('This action is not available for your account.');
        }
        header('Location: ' . tokaHome($user), true, 303);
        exit;
    }
    return $user;
}
