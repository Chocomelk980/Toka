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
$members = [];
$channels = [];
$errors = [];
$loadError = '';
$success = $_SESSION['group_management_success'] ?? '';
unset($_SESSION['group_management_success']);

function managementH($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function managementDate(?string $value): string
{
    if (!$value) return '';
    try {
        return (new DateTimeImmutable($value))->format('Y-m-d\TH:i');
    } catch (Throwable $e) {
        return '';
    }
}

if ($groupId) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare(
            'SELECT pg.*, gm.role AS viewer_role,
                    (SELECT COUNT(*) FROM group_members m WHERE m.group_id = pg.group_id) AS member_count
             FROM paluwagan_groups pg
             JOIN group_members gm ON gm.group_id = pg.group_id
             WHERE pg.group_id = :group_id AND gm.user_id = :user_id LIMIT 1'
        );
        $stmt->execute([':group_id' => $groupId, ':user_id' => $userId]);
        $group = $stmt->fetch();

        if ($group) {
            $stmt = $pdo->prepare(
                'SELECT gm.member_id, gm.role, gm.assigned_slot_number, u.username
                 FROM group_members gm JOIN users u ON u.user_id = gm.user_id
                 WHERE gm.group_id = :group_id
                 ORDER BY CASE gm.role WHEN "Main Operator" THEN 1 WHEN "Co-Operator" THEN 2 ELSE 3 END,
                          gm.assigned_slot_number'
            );
            $stmt->execute([':group_id' => $groupId]);
            $members = $stmt->fetchAll();

            $stmt = $pdo->prepare(
                'SELECT channel_type, account_name, account_number FROM group_payment_channels
                 WHERE group_id = :group_id ORDER BY channel_id'
            );
            $stmt->execute([':group_id' => $groupId]);
            $channels = $stmt->fetchAll();
        }
    } catch (PDOException $e) {
        error_log('Group management DB error: ' . $e->getMessage());
        $loadError = 'We could not load this group right now. Please refresh the page to try again.';
    }
}

if (!$group && $loadError === '') http_response_code(404);
$isManager = ($group['viewer_role'] ?? '') === 'Main Operator';
$formValues = $group ? [
    'group_name' => $group['group_name'],
    'description_rules' => $group['description_rules'] ?? '',
    'contribution_amount' => number_format((float) $group['contribution_amount'], 2, '.', ''),
    'total_member_slots' => (string) $group['total_member_slots'],
    'late_fee_rate' => number_format((float) $group['late_fee_rate'], 2, '.', ''),
    'payment_frequency' => $group['payment_frequency'],
    'first_payment_due' => managementDate($group['first_payment_due']),
    'grace_period_hours' => (string) $group['grace_period_hours'],
] : [];
$formChannels = array_map(static fn($channel) => [
    'channel_type' => $channel['channel_type'] ?? '',
    'account_name' => $channel['account_name'] ?? '',
    'account_number' => $channel['account_number'] ?? '',
], $channels);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $group) {
    if (!$isManager) {
        http_response_code(403);
        $errors[] = 'Only the Main Operator can change group settings.';
    } else {
        $token = $_POST['management_token'] ?? '';
        $sessionToken = $_SESSION['group_management_token_' . $groupId] ?? '';
        if (!is_string($token) || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
            $errors[] = 'Your form has expired. Refresh the page and try again.';
        } else {
            foreach (['group_name', 'description_rules', 'contribution_amount',
                      'total_member_slots', 'late_fee_rate', 'payment_frequency',
                      'first_payment_due', 'grace_period_hours'] as $field) {
                $formValues[$field] = is_string($_POST[$field] ?? null) ? trim($_POST[$field]) : '';
            }
            $types = $_POST['channel_type'] ?? [];
            $names = $_POST['account_name'] ?? [];
            $numbers = $_POST['account_number'] ?? [];
            $formChannels = [];
            if (is_array($types) && is_array($names) && is_array($numbers)) {
                $rowCount = max(count($types), count($names), count($numbers));
                for ($i = 0; $i < min($rowCount, 4); $i++) {
                    $formChannels[] = [
                        'channel_type' => is_string($types[$i] ?? null) ? trim($types[$i]) : '',
                        'account_name' => is_string($names[$i] ?? null) ? trim($names[$i]) : '',
                        'account_number' => is_string($numbers[$i] ?? null) ? trim($numbers[$i]) : '',
                    ];
                }
            } else {
                $types = $names = $numbers = [];
                $errors[] = 'Payment channels have invalid values. Please review them and try again.';
            }

            if ($formValues['group_name'] === '' || mb_strlen($formValues['group_name']) > 100) {
                $errors[] = 'Group name is required and must be at most 100 characters.';
            }
            if (mb_strlen($formValues['description_rules']) > 65535) $errors[] = 'Description is too long.';

            foreach (['contribution_amount' => 'Contribution amount', 'late_fee_rate' => 'Late fee rate'] as $field => $label) {
                if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $formValues[$field])) {
                    $errors[] = $label . ' must be a valid amount with up to two decimal places.';
                }
            }
            if (preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $formValues['contribution_amount'])
                && (float) $formValues['contribution_amount'] <= 0) {
                $errors[] = 'Contribution amount must be greater than zero.';
            }

            $slots = filter_var($formValues['total_member_slots'], FILTER_VALIDATE_INT, [
                'options' => ['min_range' => max(1, (int) $group['member_count']), 'max_range' => 2147483647],
            ]);
            if ($slots === false) $errors[] = 'Member slots must be a whole number at least as large as current membership.';
            $grace = filter_var($formValues['grace_period_hours'], FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 0, 'max_range' => 2147483647],
            ]);
            if ($grace === false) $errors[] = 'Grace period must be a whole number of zero or more hours.';
            if (!in_array($formValues['payment_frequency'], ['Weekly', 'Monthly'], true)) {
                $errors[] = 'Choose a valid payment frequency.';
            }
            $dueText = $formValues['first_payment_due'];
            $due = preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D', $dueText)
                ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $dueText) : false;
            if (!$due || $due->format('Y-m-d\TH:i') !== $dueText || (int) $due->format('Y') < 1000) {
                $errors[] = 'Enter a valid first payment date and time.';
            }

            $allowedTypes = ['GCash', 'Maya', 'Bank Transfer', 'Cash', 'Other'];
            $typeAliases = ['gcash' => 'GCash', 'maya' => 'Maya', 'paymaya' => 'Maya',
                'bank transfer' => 'Bank Transfer', 'cash' => 'Cash', 'other' => 'Other'];
            $formChannels = array_values($formChannels);
            $channelCount = count($formChannels);
            if ($channelCount < 2 || $channelCount > 3
                || count($types) !== $channelCount || count($names) !== $channelCount
                || count($numbers) !== $channelCount) {
                $errors[] = 'Keep at least two and at most three complete payment channels.';
            }
            foreach ($formChannels as $index => &$channel) {
                $channel['channel_type'] = $typeAliases[strtolower($channel['channel_type'])] ?? $channel['channel_type'];
                $row = 'Payment channel ' . ($index + 1);
                if (!in_array($channel['channel_type'], $allowedTypes, true)) {
                    $errors[] = $row . ': choose GCash, Maya, Bank Transfer, Cash, or Other.';
                }
                if ($channel['account_name'] === '' || mb_strlen($channel['account_name']) > 100) {
                    $errors[] = $row . ': account name is required and must be at most 100 characters.';
                }
                if ($channel['account_number'] === '' || mb_strlen($channel['account_number']) > 50) {
                    $errors[] = $row . ': account number is required and must be at most 50 characters.';
                }
            }
            unset($channel);

            if (!$errors) {
                try {
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare(
                        'UPDATE paluwagan_groups
                         SET group_name = :name, description_rules = :description,
                             contribution_amount = :amount, total_member_slots = :slots,
                             late_fee_rate = :fee, payment_frequency = :frequency,
                             first_payment_due = :due, grace_period_hours = :grace,
                             calculated_total_pot = :pot
                         WHERE group_id = :group_id'
                    );
                    $stmt->execute([
                        ':name' => $formValues['group_name'],
                        ':description' => $formValues['description_rules'],
                        ':amount' => $formValues['contribution_amount'],
                        ':slots' => $slots,
                        ':fee' => $formValues['late_fee_rate'],
                        ':frequency' => $formValues['payment_frequency'],
                        ':due' => $due->format('Y-m-d H:i:s'),
                        ':grace' => $grace,
                        ':pot' => number_format((float) $formValues['contribution_amount'] * $slots, 2, '.', ''),
                        ':group_id' => $groupId,
                    ]);
                    $pdo->prepare('DELETE FROM group_payment_channels WHERE group_id = :group_id')
                        ->execute([':group_id' => $groupId]);
                    $stmt = $pdo->prepare(
                        'INSERT INTO group_payment_channels (group_id, channel_type, account_name, account_number)
                         VALUES (:group_id, :type, :name, :number)'
                    );
                    foreach ($formChannels as $channel) {
                        $stmt->execute([
                            ':group_id' => $groupId,
                            ':type' => $channel['channel_type'],
                            ':name' => $channel['account_name'],
                            ':number' => $channel['account_number'],
                        ]);
                    }
                    $pdo->prepare(
                        'INSERT INTO audit_logs
                         (group_id, actor_user_id, activity_type, details, verification_status)
                         VALUES (:group_id, :actor, :activity, :details, :verification)'
                    )->execute([
                        ':group_id' => $groupId,
                        ':actor' => $userId,
                        ':activity' => 'Group Settings Updated',
                        ':details' => 'Group settings and payment channels updated by Main Operator',
                        ':verification' => 'Main Operator',
                    ]);
                    $pdo->commit();
                    unset($_SESSION['group_management_token_' . $groupId]);
                    $_SESSION['group_management_success'] = 'Group settings have been saved.';
                    header('Location: group_management.php?id=' . $groupId);
                    exit;
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log('Group management save error: ' . $e->getMessage());
                    $errors[] = 'We could not save these settings. Your changes are still here; please try again.';
                }
            }
        }
    }
}

if ($group && $isManager && !isset($_SESSION['group_management_token_' . $groupId])) {
    $_SESSION['group_management_token_' . $groupId] = bin2hex(random_bytes(32));
}
$managementToken = $group && $isManager ? ($_SESSION['group_management_token_' . $groupId] ?? '') : '';
$status = (string) ($group['group_status'] ?? 'Waiting');
$statusClass = strtolower(preg_replace('/[^a-z]+/i', '-', $status));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $group ? managementH($group['group_name']) . ' Management - Toka' : 'Group Management - Toka' ?></title>
    <link rel="stylesheet" href="group_dashboard.css">
</head>
<body>
<div class="dashboard-layout">
    <aside class="group-sidebar">
        <a class="brand" href="my_groups.php" aria-label="Toka home"><img src="../assets/Toka.svg" alt="Toka"></a>
        <nav class="side-nav" aria-label="Main navigation">
            <a href="my_groups.php"><span class="nav-symbol">▦</span> My Groups</a>
            <?php if ($group): ?>
                <a href="group_dashboard.php?id=<?= (int) $groupId ?>"><span class="nav-symbol">⌂</span> Group overview</a>
                <a class="selected" href="group_management.php?id=<?= (int) $groupId ?>"><span class="nav-symbol">⚙</span> Management</a>
                <a href="#members"><span class="nav-symbol">♧</span> Members</a>
                <a href="#settings"><span class="nav-symbol">☷</span> Settings</a>
            <?php endif; ?>
        </nav>
        <div class="sidebar-profile">
            <span class="avatar"><?= managementH(strtoupper(substr($username, 0, 1))) ?></span>
            <span class="sidebar-username"><?= managementH($username) ?></span>
            <a class="logout-link" href="../LOGIN_PAGE/logout.php" aria-label="Log out">↗</a>
        </div>
    </aside>
    <main class="dashboard-main">
        <?php if ($loadError !== ''): ?>
            <div class="notice error-notice" role="alert"><?= managementH($loadError) ?></div>
        <?php elseif (!$group): ?>
            <section class="not-found">
                <span class="eyebrow">GROUP UNAVAILABLE</span><h1>We couldn’t find that group.</h1>
                <p>It may have been removed, or your account may not be a member.</p>
                <a class="button button-primary" href="my_groups.php">Back to My Groups</a>
            </section>
        <?php else: ?>
            <header class="topbar">
                <a class="back-link" href="group_dashboard.php?id=<?= (int) $groupId ?>">← <span>Group dashboard</span></a>
                <span class="status-pill <?= managementH($statusClass) ?>"><?= managementH($status) ?></span>
            </header>
            <section class="management-hero">
                <div><span class="eyebrow">GROUP MANAGEMENT</span><h1>Manage your group</h1>
                    <p>Update group settings, payment channels, and see who’s in your group.</p>
                </div>
                <span class="management-code"><?= managementH($group['group_code']) ?></span>
            </section>

            <?php if ($success !== ''): ?><div class="notice success-notice" role="status"><?= managementH($success) ?></div><?php endif; ?>
            <?php if ($errors): ?>
                <div class="notice error-notice" role="alert"><strong>Please review these details:</strong>
                    <ul><?php foreach ($errors as $error): ?><li><?= managementH($error) ?></li><?php endforeach; ?></ul>
                </div>
            <?php endif; ?>
            <?php if (!$isManager): ?>
                <div class="notice error-notice" role="status">Only the Main Operator can edit this group. You can still review its members and settings below.</div>
            <?php endif; ?>

            <div class="management-grid">
                <section class="panel management-form-panel" id="settings">
                    <div class="panel-heading"><div><span class="eyebrow">GROUP CONFIGURATION</span><h2>Settings</h2></div></div>
                    <form method="post" action="group_management.php?id=<?= (int) $groupId ?>" class="management-form">
                        <input type="hidden" name="management_token" value="<?= managementH($managementToken) ?>">
                        <fieldset <?= !$isManager ? 'disabled' : '' ?>>
                            <div class="management-fields">
                                <label class="management-field full-width">Group name
                                    <input name="group_name" type="text" required maxlength="100" value="<?= managementH($formValues['group_name'] ?? '') ?>">
                                </label>
                                <label class="management-field full-width">Description / house rules <span class="optional-label">Optional</span>
                                    <textarea name="description_rules" rows="4" maxlength="65535"><?= managementH($formValues['description_rules'] ?? '') ?></textarea>
                                </label>
                                <label class="management-field">Contribution amount
                                    <span class="input-with-prefix"><span>₱</span><input id="management-contribution" name="contribution_amount" type="number" min="0.01" max="99999999.99" step="0.01" required value="<?= managementH($formValues['contribution_amount'] ?? '') ?>"></span>
                                </label>
                                <label class="management-field">Member slots
                                    <input id="management-slots" name="total_member_slots" type="number" min="<?= max(1, (int) ($group['member_count'] ?? 1)) ?>" max="2147483647" step="1" required value="<?= managementH($formValues['total_member_slots'] ?? '') ?>">
                                    <small>There are currently <?= (int) $group['member_count'] ?> member<?= (int) $group['member_count'] === 1 ? '' : 's' ?>.</small>
                                </label>
                                <label class="management-field">Calculated total pot
                                    <span class="input-with-prefix"><span>₱</span><input id="management-pot" type="number" readonly value="<?= managementH(number_format((float) ($formValues['contribution_amount'] ?? 0) * (int) ($formValues['total_member_slots'] ?? 0), 2, '.', '')) ?>"></span>
                                </label>
                                <label class="management-field">Late fee rate
                                    <span class="input-with-prefix"><span>₱</span><input name="late_fee_rate" type="number" min="0" max="99999999.99" step="0.01" required value="<?= managementH($formValues['late_fee_rate'] ?? '') ?>"></span>
                                </label>
                                <label class="management-field">Payment frequency
                                    <select name="payment_frequency" required>
                                        <option value="Weekly" <?= ($formValues['payment_frequency'] ?? '') === 'Weekly' ? 'selected' : '' ?>>Weekly</option>
                                        <option value="Monthly" <?= ($formValues['payment_frequency'] ?? '') === 'Monthly' ? 'selected' : '' ?>>Monthly</option>
                                    </select>
                                </label>
                                <label class="management-field">First payment due
                                    <input name="first_payment_due" type="datetime-local" required value="<?= managementH($formValues['first_payment_due'] ?? '') ?>">
                                </label>
                                <label class="management-field">Grace period
                                    <span class="input-with-suffix"><input name="grace_period_hours" type="number" min="0" max="2147483647" step="1" required value="<?= managementH($formValues['grace_period_hours'] ?? '') ?>"><span>hours</span></span>
                                </label>
                            </div>
                            <div class="management-divider"></div>
                            <div class="channel-form-heading"><div><span class="eyebrow">HOW MEMBERS PAY</span><h3>Payment channels</h3></div><button class="add-management-channel" type="button" <?= !$isManager || count($formChannels) >= 3 ? 'disabled' : '' ?>>+ Add channel</button></div>
                            <div id="management-channels">
                                <?php foreach ($formChannels as $channel): ?>
                                    <div class="management-channel-row">
                                        <label class="management-field">Channel
                                            <select name="channel_type[]" required>
                                                <?php foreach (['GCash','Maya','Bank Transfer','Cash','Other'] as $option): ?>
                                                    <option value="<?= managementH($option) ?>" <?= ($channel['channel_type'] ?? '') === $option ? 'selected' : '' ?>><?= managementH($option) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label class="management-field">Account name
                                            <input name="account_name[]" type="text" required maxlength="100" value="<?= managementH($channel['account_name'] ?? '') ?>">
                                        </label>
                                        <label class="management-field">Account number
                                            <input name="account_number[]" type="text" required maxlength="50" value="<?= managementH($channel['account_number'] ?? '') ?>">
                                        </label>
                                        <button class="remove-management-channel" type="button" aria-label="Remove channel" <?= !$isManager || count($formChannels) <= 2 ? 'disabled' : '' ?>>Remove</button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="management-actions">
                                <a class="button button-secondary" href="group_dashboard.php?id=<?= (int) $groupId ?>">Cancel</a>
                                <button class="button button-primary" type="submit">Save changes</button>
                            </div>
                        </fieldset>
                    </form>
                </section>

                <section class="panel management-members-panel" id="members">
                    <div class="panel-heading"><div><span class="eyebrow">YOUR PEOPLE</span><h2>Members</h2></div>
                        <span class="panel-count"><?= count($members) ?> / <?= (int) $group['total_member_slots'] ?></span>
                    </div>
                    <div class="management-member-list">
                        <?php foreach ($members as $member): ?>
                            <article class="member-row">
                                <span class="member-avatar"><?= managementH(strtoupper(substr($member['username'], 0, 1))) ?></span>
                                <div class="member-info"><strong><?= managementH($member['username']) ?>
                                    <?php if ((int) $member['member_id'] === $userId): ?><span class="you-label">YOU</span><?php endif; ?>
                                </strong><small>Slot <?= (int) $member['assigned_slot_number'] ?></small></div>
                                <span class="role-pill <?= $member['role'] === 'Main Operator' ? 'operator' : '' ?>"><?= managementH($member['role']) ?></span>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <div class="management-note">Review the assigned role and contribution slot for each member.</div>
                </section>
            </div>
            <footer class="page-footer">Toka <span>·</span> Group <?= managementH($group['group_code']) ?></footer>
        <?php endif; ?>
    </main>
</div>
<script>
    const channelContainer = document.getElementById('management-channels');
    const addChannelButton = document.querySelector('.add-management-channel');
    const contributionInput = document.getElementById('management-contribution');
    const slotsInput = document.getElementById('management-slots');
    const potInput = document.getElementById('management-pot');

    function updateManagementPot() {
        const amount = Number(contributionInput?.value || 0);
        const slots = Number(slotsInput?.value || 0);
        potInput.value = amount > 0 && slots > 0 ? (Math.round(amount * 100) * slots / 100).toFixed(2) : '';
    }
    contributionInput?.addEventListener('input', updateManagementPot);
    slotsInput?.addEventListener('input', updateManagementPot);

    function updateManagementChannelButtons() {
        if (!channelContainer || !addChannelButton) return;
        const rows = channelContainer.querySelectorAll('.management-channel-row');
        addChannelButton.disabled = rows.length >= 3;
        rows.forEach(row => {
            const remove = row.querySelector('.remove-management-channel');
            remove.disabled = rows.length <= 2;
        });
    }

    addChannelButton?.addEventListener('click', () => {
        if (!channelContainer || channelContainer.children.length >= 3) return;
        const row = document.createElement('div');
        row.className = 'management-channel-row';
        row.innerHTML = '<label class="management-field">Channel<select name="channel_type[]" required><option value="GCash">GCash</option><option value="Maya">Maya</option><option value="Bank Transfer">Bank Transfer</option><option value="Cash">Cash</option><option value="Other">Other</option></select></label>' +
            '<label class="management-field">Account name<input name="account_name[]" required maxlength="100"></label>' +
            '<label class="management-field">Account number<input name="account_number[]" required maxlength="50"></label>' +
            '<button class="remove-management-channel" type="button">Remove</button>';
        channelContainer.appendChild(row);
        updateManagementChannelButtons();
    });

    channelContainer?.addEventListener('click', event => {
        const remove = event.target.closest('.remove-management-channel');
        if (remove && channelContainer.children.length > 2) {
            remove.closest('.management-channel-row').remove();
            updateManagementChannelButtons();
        }
    });
</script>
</body>
</html>

