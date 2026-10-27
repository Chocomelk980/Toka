<?php
session_start();
require_once __DIR__ . '/../LOGIN_PAGE/access.php';
tokaRequireRole();
require_once __DIR__ . '/../LOGIN_PAGE/dbconnect.php';
require_once __DIR__ . '/audit_archive_helpers.php';

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
$logsPerPage = 10;
$requestedPage = filter_var($_GET['page'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$currentPage = $requestedPage === false || $requestedPage === null ? 1 : $requestedPage;
$totalLogCount = 0;
$totalPages = 1;
$logOffset = 0;
$isCsvExport = isset($_GET['export']) && $_GET['export'] === 'csv';

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
        tokaEnsureAdminArchiveTables($pdo);
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
            $logsQuery =
                'SELECT al.timestamp, al.activity_type, al.details, al.verification_status,
                        rs.round_number, u.username, gm.role
                 FROM audit_logs al
                 LEFT JOIN round_schedules rs ON rs.round_id = al.round_id
                 LEFT JOIN users u ON u.user_id = al.actor_user_id
                 LEFT JOIN group_members gm ON gm.group_id = al.group_id AND gm.user_id = al.actor_user_id
                 WHERE al.group_id = :group_id
                   AND NOT EXISTS (SELECT 1 FROM audit_log_archives ala WHERE ala.log_id = al.log_id)
                 ORDER BY al.timestamp DESC, al.log_id DESC';

            if ($isCsvExport) {
                $stmt = $pdo->prepare($logsQuery);
                $stmt->execute([':group_id' => $groupId]);
            } else {
                $stmt = $pdo->prepare(
                    'SELECT COUNT(*) FROM audit_logs al
                     WHERE al.group_id = :group_id
                       AND NOT EXISTS (SELECT 1 FROM audit_log_archives ala WHERE ala.log_id = al.log_id)'
                );
                $stmt->execute([':group_id' => $groupId]);
                $totalLogCount = (int) $stmt->fetchColumn();
                $totalPages = max(1, (int) ceil($totalLogCount / $logsPerPage));
                $currentPage = min($currentPage, $totalPages);
                $logOffset = ($currentPage - 1) * $logsPerPage;

                $stmt = $pdo->prepare($logsQuery . ' LIMIT :page_size OFFSET :page_offset');
                $stmt->bindValue(':group_id', (int) $groupId, PDO::PARAM_INT);
                $stmt->bindValue(':page_size', $logsPerPage, PDO::PARAM_INT);
                $stmt->bindValue(':page_offset', $logOffset, PDO::PARAM_INT);
                $stmt->execute();
            }
            $logs = $stmt->fetchAll();
        }
    } catch (PDOException $e) {
        error_log('Group logs DB error: ' . $e->getMessage());
        $loadError = 'We could not load this group’s logs right now. Please refresh the page to try again.';
    }
}

if (!$group && $loadError === '') http_response_code(404);

if ($group && $isCsvExport) {
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
    <link rel="stylesheet" href="group_views.css?v=<?= filemtime(__DIR__ . '/group_views.css') ?>">
    <link rel="stylesheet" href="pagination.css?v=<?= filemtime(__DIR__ . '/pagination.css') ?>">
    <link rel="stylesheet" href="../assets/typography.css?v=<?= filemtime(__DIR__ . '/../assets/typography.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="icon" type="image/svg+xml" href="../assets/toka_icon.svg">
</head>
<body>
<div class="views-layout">
    <aside class="views-sidebar">
        <a class="views-brand" href="my_groups.php" aria-label="Toka home"><img src="../assets/Toka.svg" alt="Toka"></a>
        <nav class="views-nav" aria-label="Main navigation">
            <a href="my_groups.php"><span><i class="fa-solid fa-border-all" aria-hidden="true"></i></span> Dashboard</a>
            <?php if ($group): ?>
                <a class="current" href="group_dashboard.php?id=<?= (int) $groupId ?>"><span><i class="fa-solid fa-users" aria-hidden="true"></i></span> My Groups</a>
            <?php endif; ?>
        </nav>
        <div class="views-profile">
            <span class="views-avatar"><?= logH(strtoupper(substr($username, 0, 1))) ?></span>
            <span><?= logH($username) ?></span>
            <a href="../LOGIN_PAGE/logout.php" aria-label="Log out" title="Log out"><i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i></a>
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
            <div class="logs-toolbar">
                <a class="views-secondary" href="group_dashboard.php?id=<?= (int) $groupId ?>">Back</a>
            <?php if ($totalPages > 1): ?>
                <nav class="logs-pagination" aria-label="Log pages">
                    <div class="pagination-controls">
                        <?php if ($currentPage > 1): ?>
                            <a class="page-control page-arrow" href="group_logs.php?id=<?= (int) $groupId ?>&amp;page=<?= $currentPage - 1 ?>" rel="prev" aria-label="Previous page"><span aria-hidden="true">&#8249;</span></a>
                        <?php else: ?>
                            <button class="page-control page-arrow" type="button" aria-label="Previous page" disabled><span aria-hidden="true">&#8249;</span></button>
                        <?php endif; ?>
                        <?php
                        $pageNumbers = $totalPages <= 7
                            ? range(1, $totalPages)
                            : array_merge(
                                [1],
                                range(max(2, min($currentPage - 1, $totalPages - 2)), min($totalPages - 1, max($currentPage + 1, 3))),
                                [$totalPages]
                            );
                        $previousPageNumber = 0;
                        ?>
                        <?php foreach ($pageNumbers as $pageNumber): ?>
                            <?php if ($pageNumber - $previousPageNumber === 2): ?>
                                <a class="page-control" href="group_logs.php?id=<?= (int) $groupId ?>&amp;page=<?= $previousPageNumber + 1 ?>" aria-label="Page <?= $previousPageNumber + 1 ?>"><?= $previousPageNumber + 1 ?></a>
                            <?php elseif ($pageNumber - $previousPageNumber > 2): ?>
                                <span class="page-ellipsis" aria-hidden="true">&hellip;</span>
                            <?php endif; ?>
                            <?php if ($pageNumber === $currentPage): ?>
                                <span class="page-control" aria-current="page" aria-label="Page <?= $pageNumber ?>"><?= $pageNumber ?></span>
                            <?php else: ?>
                                <a class="page-control" href="group_logs.php?id=<?= (int) $groupId ?>&amp;page=<?= $pageNumber ?>" aria-label="Page <?= $pageNumber ?>"><?= $pageNumber ?></a>
                            <?php endif; ?>
                            <?php $previousPageNumber = $pageNumber; ?>
                        <?php endforeach; ?>
                        <?php if ($currentPage < $totalPages): ?>
                            <a class="page-control page-arrow" href="group_logs.php?id=<?= (int) $groupId ?>&amp;page=<?= $currentPage + 1 ?>" rel="next" aria-label="Next page"><span aria-hidden="true">&#8250;</span></a>
                        <?php else: ?>
                            <button class="page-control page-arrow" type="button" aria-label="Next page" disabled><span aria-hidden="true">&#8250;</span></button>
                        <?php endif; ?>
                    </div>
                    <span class="pagination-summary">
                        Showing <?= $logOffset + 1 ?>–<?= min($logOffset + $logsPerPage, $totalLogCount) ?> of <?= $totalLogCount ?> records
                    </span>
                </nav>
            <?php endif; ?>
                <a class="views-primary" href="group_logs.php?id=<?= (int) $groupId ?>&amp;export=csv">Export CSV</a>
            </div>
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
        <?php endif; ?>
    </main>
</div>
<script src="../LOGIN_PAGE/tab-auth.js?v=<?= filemtime(__DIR__ . '/../LOGIN_PAGE/tab-auth.js') ?>"></script>
</body>
</html>
