<?php
session_start();

// Page preview only. Account assignment and live moderation are connected later.
$username = $_SESSION['user']['username'] ?? 'Preview';
function adminH(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - Toka</title>
    <link rel="stylesheet" href="dashboard.css?v=<?= filemtime(__DIR__ . '/dashboard.css') ?>">
    <link rel="stylesheet" href="admin.css?v=<?= filemtime(__DIR__ . '/admin.css') ?>">
    <script src="admin.js?v=<?= filemtime(__DIR__ . '/admin.js') ?>" defer></script>
</head>
<body class="admin-page">
    <div class="app">
        <aside class="sidebar">
            <div class="sidebar-top">
                <div class="brand"><a href="my_groups.php" aria-label="Toka home"><img src="../assets/Toka.svg" alt="Toka"></a></div>
                <nav class="nav" aria-label="Main navigation">
                    <a href="my_groups.php" class="nav-item"><span class="nav-icon" aria-hidden="true">&#9823;</span><span>My Groups</span></a>
                    <a href="#admin-overview" class="nav-item"><span class="nav-icon" aria-hidden="true">&#8962;</span><span>Dashboard</span></a>
                    <a href="admin.php" class="nav-item active" aria-current="page">
                        <span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3 4 6v6c0 5 8 9 8 9s8-4 8-9V6l-8-3Z"/><path d="M12 7v7m0 2v1"/></svg></span><span>Admin</span>
                    </a>
                </nav>
            </div>
            <div class="sidebar-bottom">
                <div class="profile"><div class="profile-circle" aria-hidden="true"><?= adminH(strtoupper(substr($username, 0, 1))) ?></div><span><?= adminH($username) ?></span></div>
                <?php if (isset($_SESSION['user'])): ?><a href="../LOGIN_PAGE/logout.php" class="logout" aria-label="Log out">&#8618;</a><?php endif; ?>
            </div>
        </aside>

        <main class="main admin-main" id="admin-overview" tabindex="-1">
            <header class="admin-header">
                <h1>Admin Panel</h1>
                <p>Platform oversight, dispute management, and user control</p>
            </header>

            <noscript><p class="admin-notice">Enable JavaScript to load the admin preview and use its controls.</p></noscript>
            <div id="storage-notice" class="admin-notice" role="status" hidden></div>

            <section class="admin-stats" aria-label="Platform overview">
                <article class="admin-stat stat-active"><span class="stat-icon" aria-hidden="true"><i></i></span><strong id="active-count">—</strong><span>Total Active Groups</span></article>
                <article class="admin-stat stat-completed"><span class="stat-icon" aria-hidden="true"><i></i></span><strong id="completed-count">—</strong><span>Completed Cycles</span></article>
                <article class="admin-stat stat-volume"><span class="stat-icon" aria-hidden="true"><i></i></span><strong id="volume-total">—</strong><span>Total Platform Volume</span></article>
                <article class="admin-stat stat-flagged"><span class="stat-icon" aria-hidden="true"><i></i></span><strong id="flagged-count">—</strong><span>Flagged / Frozen Groups</span></article>
            </section>

            <div class="admin-columns">
                <section class="admin-panel dispute-panel" aria-labelledby="dispute-heading">
                    <header class="panel-heading"><h2 id="dispute-heading" tabindex="-1">Dispute Intervention Desk</h2><p>Frozen and deadlocked groups requiring admin action</p></header>
                    <div class="dispute-scroll" tabindex="0" role="region" aria-label="Dispute table">
                        <table class="dispute-table">
                            <caption class="sr-only">Groups requiring intervention. Votes show votes to freeze out of total members.</caption>
                            <thead><tr><th scope="col">Group</th><th scope="col">Votes</th><th scope="col">Status</th><th scope="col">Reason</th><th scope="col">Actions</th></tr></thead>
                            <tbody id="dispute-rows"></tbody>
                        </table>
                    </div>
                    <div id="disputes-empty" class="admin-empty" hidden><span aria-hidden="true">&#10003;</span><h3>All caught up</h3><p>No groups need intervention right now.</p></div>
                </section>

                <section class="admin-panel roster-panel" aria-labelledby="roster-heading">
                    <header class="panel-heading"><h2 id="roster-heading">User Roster &amp; Blacklist</h2></header>
                    <div class="roster-search"><label class="sr-only" for="user-search">Search users by name or ID</label><input id="user-search" type="search" placeholder="Search name or ID..." autocomplete="off" aria-controls="user-roster"></div>
                    <ul id="user-roster" class="user-roster"></ul>
                    <div id="users-empty" class="admin-empty" hidden><h3>No users found</h3><p>Try another name or user ID.</p><button class="admin-button secondary" id="clear-search" type="button">Clear search</button></div>
                    <p class="roster-count" id="roster-count" role="status"></p>
                </section>
            </div>

            <footer class="preview-footer"><p><strong>Interactive preview</strong> · Sample data. Changes are saved in this browser only.</p><button id="reset-preview" type="button">Reset preview</button></footer>
            <div class="admin-toast" id="admin-feedback" role="status" aria-live="polite" aria-atomic="true"></div>
        </main>
    </div>

    <dialog id="admin-confirm" class="admin-dialog" aria-labelledby="confirm-title" aria-describedby="confirm-description">
        <form id="confirm-form" method="dialog">
            <span class="dialog-mark" aria-hidden="true">!</span>
            <h2 id="confirm-title"></h2><p id="confirm-description"></p>
            <div class="dialog-actions"><button class="admin-button secondary" id="cancel-action" type="button" autofocus>Cancel</button><button class="admin-button" id="confirm-action" type="submit">Confirm</button></div>
        </form>
    </dialog>
</body>
</html>
