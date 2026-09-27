<?php
session_start();
require_once __DIR__ . '/../LOGIN_PAGE/dbconnect.php';

if (!isset($_SESSION['user']) || (int) ($_SESSION['user']['user_id'] ?? 0) < 1) {
    header('Location: ../LOGIN_PAGE/index.php');
    exit;
}

$userId = (int) $_SESSION['user']['user_id'];
$username = $_SESSION['user']['username'] ?? 'User';
$groupId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$group = null;
$channels = [];
$rounds = [];
$loadError = '';

function detailsH($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function detailsDate(?string $value, string $format = 'M j, Y'): string
{
    if (!$value) return '—';
    try {
        return (new DateTimeImmutable($value))->format($format);
    } catch (Throwable $e) {
        return '—';
    }
}

function detailsCsv($value): string
{
    $value = (string) $value;
    if (preg_match('/^[\s]*[=+\-@]/', $value)) return "'" . $value;
    return $value;
}

if ($groupId) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare(
            'SELECT pg.*,
                    (SELECT COUNT(*) FROM group_members m WHERE m.group_id = pg.group_id) AS member_count
             FROM paluwagan_groups pg
             JOIN group_members gm ON gm.group_id = pg.group_id
             WHERE pg.group_id = :group_id AND gm.user_id = :user_id
             LIMIT 1'
        );
        $stmt->execute([':group_id' => $groupId, ':user_id' => $userId]);
        $group = $stmt->fetch();

        if ($group) {
            $stmt = $pdo->prepare(
                'SELECT channel_type, account_name, account_number
                 FROM group_payment_channels WHERE group_id = :group_id ORDER BY channel_id'
            );
            $stmt->execute([':group_id' => $groupId]);
            $channels = $stmt->fetchAll();

            $stmt = $pdo->prepare(
                'SELECT rs.round_number, rs.target_deadline, rs.round_status, rs.remarks,
                        receiver.username AS receiver_name
                 FROM round_schedules rs
                 JOIN group_members receiver_member ON receiver_member.member_id = rs.receiver_member_id
                 JOIN users receiver ON receiver.user_id = receiver_member.user_id
                 WHERE rs.group_id = :group_id ORDER BY rs.round_number'
            );
            $stmt->execute([':group_id' => $groupId]);
            $rounds = $stmt->fetchAll();
        }
    } catch (PDOException $e) {
        error_log('Group details DB error: ' . $e->getMessage());
        $loadError = 'We could not load this group’s details right now. Please refresh the page to try again.';
    }
}

if (!$group && $loadError === '') http_response_code(404);

if ($group && isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="toka-group-' . (int) $groupId . '-details.csv"');
    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ['Section', 'Field', 'Value', 'Status', 'Remarks']);
    foreach ([
        ['Group', 'Name', $group['group_name']],
        ['Group', 'Code', $group['group_code']],
        ['Group', 'Status', $group['group_status']],
        ['Group', 'Date Created', $group['created_at']],
        ['Group', 'Members', $group['member_count'] . '/' . $group['total_member_slots']],
        ['Contribution', 'Target Pot', $group['calculated_total_pot']],
        ['Contribution', 'Individual Share', $group['contribution_amount']],
        ['Schedule', 'Frequency', $group['payment_frequency']],
        ['Schedule', 'First Payment Due', $group['first_payment_due']],
        ['Fees', 'Late Fee', $group['late_fee_rate']],
        ['Fees', 'Grace Period Hours', $group['grace_period_hours']],
        ['Rules', 'Description', $group['description_rules'] ?? ''],
    ] as $row) {
        fputcsv($output, array_map('detailsCsv', $row));
    }
    foreach ($channels as $channel) {
        fputcsv($output, array_map('detailsCsv', [
            'Payment Channel', $channel['channel_type'], $channel['account_name'],
            '', $channel['account_number'],
        ]));
    }
    foreach ($rounds as $round) {
        fputcsv($output, array_map('detailsCsv', [
            'Round ' . $round['round_number'], $round['receiver_name'],
            $round['target_deadline'], $round['round_status'], $round['remarks'],
        ]));
    }
    fclose($output);
    exit;
}

$cycleStart = $group ? detailsDate($group['first_payment_due']) : '—';
$cycleEnd = $rounds ? detailsDate($rounds[count($rounds) - 1]['target_deadline']) : '—';
$cycle = $cycleStart . ($cycleEnd !== '—' && $cycleEnd !== $cycleStart ? ' – ' . $cycleEnd : '');
$operatorName = 'Not assigned';
if ($group) {
    try {
        $stmt = $pdo->prepare(
            'SELECT u.username FROM group_members gm JOIN users u ON u.user_id = gm.user_id
             WHERE gm.group_id = :group_id AND gm.role = :role ORDER BY gm.member_id LIMIT 1'
        );
        $stmt->execute([':group_id' => $groupId, ':role' => 'Main Operator']);
        $operatorName = $stmt->fetchColumn() ?: 'Not assigned';
    } catch (PDOException $e) {
        $operatorName = 'Not assigned';
    }
}
$statusClass = strtolower(preg_replace('/[^a-z]+/i', '-', (string) ($group['group_status'] ?? 'waiting')));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $group ? detailsH($group['group_name']) . ' Details - Toka' : 'Group Details - Toka' ?></title>
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
            <span class="views-avatar"><?= detailsH(strtoupper(substr($username, 0, 1))) ?></span>
            <span><?= detailsH($username) ?></span>
            <a href="../LOGIN_PAGE/logout.php" aria-label="Log out">⇥</a>
        </div>
    </aside>
    <main class="views-main">
        <?php if ($loadError !== ''): ?>
            <div class="views-notice" role="alert"><?= detailsH($loadError) ?></div>
        <?php elseif (!$group): ?>
            <section class="views-not-found">
                <h1>Group unavailable</h1>
                <p>It may have been removed, or your account may not be a member.</p>
                <a class="views-primary" href="my_groups.php">Back to My Groups</a>
            </section>
        <?php else: ?>
            <section class="details-sheet" aria-label="Group details">
                <header class="details-header">
                    <div>
                        <div class="details-title">
                            <h1><?= detailsH($group['group_name']) ?></h1>
                            <span class="details-status <?= detailsH($statusClass) ?>"><?= detailsH($group['group_status']) ?></span>
                        </div>
                        <p>Date Created: <?= detailsH(detailsDate($group['created_at'], 'M j, Y h:i A')) ?>
                            <span>|</span> <?= (int) $group['member_count'] ?>/<?= (int) $group['total_member_slots'] ?> Members
                        </p>
                    </div>
                    <button class="code-copy" type="button" data-code="<?= detailsH($group['group_code']) ?>">
                        Code: <strong><?= detailsH($group['group_code']) ?></strong><span aria-hidden="true">▢</span>
                    </button>
                </header>

                <section class="details-metrics" aria-label="Contribution and schedule details">
                    <div class="metric-column">
                        <div><span>Target Pot</span><strong>₱<?= detailsH(number_format((float) $group['calculated_total_pot'], 2)) ?> per round</strong></div>
                        <div><span>Individual Share</span><strong>₱<?= detailsH(number_format((float) $group['contribution_amount'], 2)) ?> / round</strong></div>
                    </div>
                    <div class="metric-column">
                        <div><span>Frequency</span><strong><?= detailsH($group['payment_frequency']) ?></strong></div>
                        <div><span>Projected Cycle</span><strong><?= detailsH($cycle) ?></strong></div>
                        <div><span>Late Fee</span><strong>₱<?= detailsH(number_format((float) $group['late_fee_rate'], 2)) ?> per missed day</strong></div>
                    </div>
                </section>

                <section class="detail-panels">
                    <div class="rules-panel">
                        <h2>Description / House Rules</h2>
                        <p><?= trim((string) ($group['description_rules'] ?? '')) !== ''
                            ? nl2br(detailsH($group['description_rules']))
                            : 'No description or house rules have been added.' ?></p>
                    </div>
                    <div class="channels-panel">
                        <h2>Payment Channels</h2>
                        <?php if (!$channels): ?>
                            <p class="no-channel-detail">No payment channels have been added.</p>
                        <?php else: ?>
                            <div class="detail-channel-list">
                                <?php foreach ($channels as $channel): ?>
                                    <div class="detail-channel">
                                        <span class="channel-tag"><?= detailsH($channel['channel_type'] ?: 'Other') ?></span>
                                        <span class="channel-holder"><?= detailsH($channel['account_name'] ?: 'Account holder not provided') ?></span>
                                        <span class="channel-number"><?= detailsH($channel['account_number'] ?: 'N/A') ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="round-table-wrap" aria-label="Round schedule">
                    <div class="round-table-scroll">
                        <table class="details-round-table">
                            <thead><tr><th>Round #</th><th>Slot Member</th><th>Target Deadline</th><th>Round Status</th><th>Remarks</th></tr></thead>
                            <tbody>
                            <?php foreach ($rounds as $round): ?>
                                <?php $roundStatusClass = strtolower(str_replace(' ', '-', $round['round_status'])); ?>
                                <tr>
                                    <td class="round-label">Round <?= (int) $round['round_number'] ?></td>
                                    <td><?= detailsH($round['receiver_name']) ?></td>
                                    <td><?= detailsH(detailsDate($round['target_deadline'])) ?></td>
                                    <td><span class="schedule-status <?= detailsH($roundStatusClass) ?>"><?= detailsH($round['round_status']) ?></span></td>
                                    <td class="remarks-cell"><?= detailsH($round['remarks'] ?: ($round['round_status'] === 'Upcoming' ? 'Upcoming' : '—')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$rounds): ?>
                                <tr><td class="empty-rounds" colspan="5">The round schedule has not been set up yet.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </section>

            <div class="views-actions">
                <a class="views-secondary" href="group_dashboard.php?id=<?= (int) $groupId ?>">Back</a>
                <a class="views-primary" href="group_details.php?id=<?= (int) $groupId ?>&amp;export=csv">Export CSV</a>
            </div>
        <?php endif; ?>
    </main>
</div>
<script>
    document.querySelector('.code-copy')?.addEventListener('click', async event => {
        const button = event.currentTarget;
        try {
            await navigator.clipboard.writeText(button.dataset.code);
            const original = button.innerHTML;
            button.innerHTML = 'Code copied ✓';
            window.setTimeout(() => { button.innerHTML = original; }, 1500);
        } catch (error) {
            button.title = 'Group code: ' + button.dataset.code;
        }
    });
</script>
</body>
</html>
