<?php
session_start();
require_once __DIR__ . '/../LOGIN_PAGE/access.php';
tokaRequireRole();
require_once __DIR__ . '/../LOGIN_PAGE/dbconnect.php';

if (!isset($_SESSION['user']) || (int) ($_SESSION['user']['user_id'] ?? 0) < 1) {
    header('Location: ../LOGIN_PAGE/index.php');
    exit;
}

$userId = (int) $_SESSION['user']['user_id'];
$username = $_SESSION['user']['username'] ?? 'User';
$groupId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$group = null;
$logs = [];
$loadError = '';

function logH($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function logCsvValue($value): string
{
    $value = (string) $value;
    if (preg_match('/^[\s]*[=+\-@]/', $value)) return "'" . $value;
    return $value;
}

if ($groupId) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare(
            'SELECT pg.group_id, pg.group_name
             FROM paluwagan_groups pg
             JOIN group_members gm ON gm.group_id = pg.group_id
             WHERE pg.group_id = :group_id AND gm.user_id = :user_id
             LIMIT 1'
        );
        $stmt->execute([':group_id' => $groupId, ':user_id' => $userId]);
        $group = $stmt->fetch();

        if ($group) {
            $stmt = $pdo->prepare(
                'SELECT al.timestamp, al.activity_type, al.details, al.verification_status,
                        rs.round_number, u.username, gm.role
                 FROM audit_logs al
                 LEFT JOIN round_schedules rs ON rs.round_id = al.round_id
                 LEFT JOIN users u ON u.user_id = al.actor_user_id
                 LEFT JOIN group_members gm ON gm.group_id = al.group_id AND gm.user_id = al.actor_user_id
                 WHERE al.group_id = :group_id
                 ORDER BY al.timestamp ASC, al.log_id ASC'
            );
            $stmt->execute([':group_id' => $groupId]);
            $logs = $stmt->fetchAll();
        }
    } catch (PDOException $e) {
        error_log('Group logs DB error: ' . $e->getMessage());
        $loadError = 'We could not load this group’s logs right now. Please refresh the page to try again.';
    }
}

if (!$group && $loadError === '') http_response_code(404);

if ($group && isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="toka-group-' . (int) $groupId . '-logs.csv"');
    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ['Timestamp', 'Round #', 'Member', 'Activity Type', 'Details', 'Verification']);
    foreach ($logs as $log) {
        $role = match ($log['role'] ?? '') {
            'Main Operator' => 'Main Op',
            'Co-Operator' => 'Co-Op',
            'Member' => 'Member',
            default => 'System',
        };
        fputcsv($output, [
            logCsvValue($log['timestamp'] ? date('m/d/Y h:i A', strtotime($log['timestamp'])) : ''),
            logCsvValue($log['round_number'] ? 'Round ' . $log['round_number'] : ''),
            logCsvValue(($log['username'] ?: 'System') . ($log['username'] && $role !== 'System' ? ' (' . $role . ')' : '')),
            logCsvValue($log['activity_type']),
            logCsvValue($log['details'] ?: ''),
            logCsvValue($log['verification_status'] ?: ($role === 'System' ? 'System Automated' : 'Operator Recorded')),
        ]);
    }
    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $group ? logH($group['group_name']) . ' Logs - Toka' : 'Group Logs - Toka' ?></title>
    <link rel="stylesheet" href="group_views.css">
</head>
<body>
<div class="views-layout">
    <aside class="views-sidebar">
        <a class="views-brand" href="my_groups.php" aria-label="Toka home"><img src="../assets/Toka.svg" alt="Toka"></a>
        <nav class="views-nav" aria-label="Main navigation">
            <a href="my_groups.php"><span>♟</span> My Groups</a>
            <?php if ($group): ?>
                <a class="current" href="group_dashboard.php?id=<?= (int) $groupId ?>"><span>⌂</span> Dashboard</a>
            <?php endif; ?>
        </nav>
        <div class="views-profile">
            <span class="views-avatar"><?= logH(strtoupper(substr($username, 0, 1))) ?></span>
            <span><?= logH($username) ?></span>
            <a href="../LOGIN_PAGE/logout.php" aria-label="Log out">⇥</a>
        </div>
    </aside>
    <main class="views-main">
        <?php if ($loadError !== ''): ?>
            <div class="views-notice" role="alert"><?= logH($loadError) ?></div>
        <?php elseif (!$group): ?>
            <section class="views-not-found">
                <h1>Group unavailable</h1>
                <p>It may have been removed, or your account may not be a member.</p>
                <a class="views-primary" href="my_groups.php">Back to My Groups</a>
            </section>
        <?php else: ?>
            <section class="logs-sheet" aria-label="Group activity logs">
                <div class="logs-scroll">
                    <table class="logs-table">
                        <thead><tr>
                            <th>Timestamp</th>
                            <th>Round #</th>
                            <th>Member</th>
                            <th>Activity Type</th>
                            <th>Details</th>
                            <th>Verification</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($logs as $log): ?>
                            <?php
                            $role = match ($log['role'] ?? '') {
                                'Main Operator' => 'Main Op',
                                'Co-Operator' => 'Co-Op',
                                'Member' => 'Member',
                                default => 'System',
                            };
                            $memberLabel = ($log['username'] ?: 'System');
                            if ($log['username'] && $role !== 'System') $memberLabel .= ' (' . $role . ')';
                            $verification = $log['verification_status']
                                ?: ($role === 'System' ? 'System Automated' : 'Operator Recorded');
                            ?>
                            <tr>
                                <td class="timestamp-cell"><?= $log['timestamp'] ? logH(date('m/d/Y h:i A', strtotime($log['timestamp']))) : '—' ?></td>
                                <td><?php if ($log['round_number']): ?><span class="round-chip">Round <?= (int) $log['round_number'] ?></span><?php else: ?>—<?php endif; ?></td>
                                <td><?= logH($memberLabel) ?></td>
                                <td class="activity-cell"><?= logH($log['activity_type']) ?></td>
                                <td><?= logH($log['details'] ?: '—') ?></td>
                                <td class="verification-cell"><?= logH($verification) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$logs): ?>
                            <tr class="empty-log-row"><td colspan="6">No activity has been recorded for this group yet.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <div class="views-actions">
                <a class="views-secondary" href="group_dashboard.php?id=<?= (int) $groupId ?>">Back</a>
                <a class="views-primary" href="group_logs.php?id=<?= (int) $groupId ?>&amp;export=csv">Export CSV</a>
            </div>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
