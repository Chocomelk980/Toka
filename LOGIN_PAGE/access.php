<?php
require_once __DIR__ . '/dbconnect.php';

const TOKA_BANNED_LOGIN_MESSAGE = 'Log in failed, you are banned from entering this website.';
const TOKA_TAB_AUTH_SESSION_KEY = 'toka_tab_auth_tokens';
const TOKA_TAB_AUTH_REQUEST_KEY = 'toka_tab';
const TOKA_TAB_AUTH_STORAGE_KEY = 'toka_tab_auth';

function tokaRequestTabAuthToken(): string
{
    foreach ([$_POST[TOKA_TAB_AUTH_REQUEST_KEY] ?? null, $_GET[TOKA_TAB_AUTH_REQUEST_KEY] ?? null] as $token) {
        if (is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token)) return $token;
    }
    return '';
}

function tokaValidTabAuthToken(?string $token = null): ?string
{
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $token ??= tokaRequestTabAuthToken();
    if ($token === '') return null;

    $tokens = $_SESSION[TOKA_TAB_AUTH_SESSION_KEY] ?? [];
    if (!is_array($tokens)) $tokens = [];
    if (!isset($tokens[$token])) {
        $_SESSION[TOKA_TAB_AUTH_SESSION_KEY] = $tokens;
        return null;
    }

    $tokens[$token] = time();
    $_SESSION[TOKA_TAB_AUTH_SESSION_KEY] = $tokens;
    return $token;
}

function tokaCreateTabAuthToken(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $tokens = $_SESSION[TOKA_TAB_AUTH_SESSION_KEY] ?? [];
    if (!is_array($tokens)) $tokens = [];
    $token = bin2hex(random_bytes(32));
    $tokens[$token] = time();
    $_SESSION[TOKA_TAB_AUTH_SESSION_KEY] = $tokens;
    return $token;
}

function tokaAppendTabAuthToUrl(string $url, string $token): string
{
    $parts = parse_url($url);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) return $url;
    parse_str($parts['query'] ?? '', $query);
    $query[TOKA_TAB_AUTH_REQUEST_KEY] = $token;
    $result = $parts['path'] ?? '';
    $result .= '?' . http_build_query($query);
    if (isset($parts['fragment'])) $result .= '#' . $parts['fragment'];
    return $result;
}

function tokaAppendTabAuthToRedirect(): void
{
    $token = $GLOBALS['toka_tab_auth_token'] ?? '';
    if ($token === '') return;
    foreach (headers_list() as $header) {
        if (stripos($header, 'Location:') !== 0) continue;
        $location = trim(substr($header, strlen('Location:')));
        if (preg_match('~(?:^|/)logout\.php(?:[?#]|$)~i', $location)) return;
        $updated = tokaAppendTabAuthToUrl($location, $token);
        header_remove('Location');
        header('Location: ' . $updated, true, http_response_code());
        return;
    }
}

function tokaTabAuthBootstrap(): never
{
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('Referrer-Policy: no-referrer');
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width">'
        . '<title>Checking this tab</title><p>Checking this tab’s sign-in…</p><script>'
        . '(function(){const key=' . json_encode(TOKA_TAB_AUTH_STORAGE_KEY) . ';const parameter='
        . json_encode(TOKA_TAB_AUTH_REQUEST_KEY) . ';try{const token=sessionStorage.getItem(key);'
        . 'const requested=new URL(location.href).searchParams.get(parameter);'
        . 'if(token&&token!==requested){const url=new URL(location.href);url.searchParams.set(parameter,token);'
        . 'location.replace(url.href);return;}if(token)sessionStorage.removeItem(key);}catch(e){}'
        . 'location.replace(' . json_encode('../LOGIN_PAGE/index.php?tab_auth_required=1') . ');})();'
        . '</script><noscript><a href="../LOGIN_PAGE/index.php?tab_auth_required=1">Sign in to continue</a></noscript>'
        . '</html>';
    exit;
}

function tokaIsAdmin(array $user): bool
{
    return !empty($user['is_admin']);
}

function tokaHome(array $user, bool $fromProjectRoot = false): string
{
    $dashboardPath = $fromProjectRoot ? 'DASHBOARD/' : '../DASHBOARD/';
    return $dashboardPath . (tokaIsAdmin($user) ? 'admin.php' : 'my_groups.php');
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

    $tabToken = tokaValidTabAuthToken();
    if ($tabToken === null) tokaTabAuthBootstrap();
    $GLOBALS['toka_tab_auth_token'] = $tabToken;
    header('Referrer-Policy: no-referrer');
    header_register_callback('tokaAppendTabAuthToRedirect');

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
