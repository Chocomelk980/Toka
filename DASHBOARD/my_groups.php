<?php
session_start();
require_once __DIR__ . '/../LOGIN_PAGE/access.php';
tokaRequireRole();

require_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'LOGIN_PAGE' . DIRECTORY_SEPARATOR . 'dbconnect.php';

if (!isset($_SESSION['user'])) {
    header('Location: ../LOGIN_PAGE/index.php');
    exit;
}

$username = $_SESSION['user']['username'] ?? 'User';
$groups = [];
$coopCandidatesByGroup = [];
$loadError = '';
$success = $_SESSION['group_success'] ?? '';
unset($_SESSION['group_success']);

$userId = (int) ($_SESSION['user']['user_id'] ?? 0);

$joinToken = $_SESSION['join_request_csrf'] ?? '';
if ($joinToken === '') {
    $joinToken = bin2hex(random_bytes(32));
    $_SESSION['join_request_csrf'] = $joinToken;
}
$joinModal = null;

function getJoinGroupByCode(PDO $pdo, string $code): array|false
{
    $stmt = $pdo->prepare(
        'SELECT pg.group_id, pg.group_code, pg.group_name, pg.group_status,
                pg.contribution_amount, pg.payment_frequency, pg.total_member_slots,
                (SELECT COUNT(*) FROM group_members m WHERE m.group_id = pg.group_id) AS member_count,
                (SELECT u.username FROM group_members m JOIN users u ON u.user_id = m.user_id
                 WHERE m.group_id = pg.group_id AND m.role = "Main Operator" LIMIT 1) AS main_op,
                (SELECT u.username FROM group_members m JOIN users u ON u.user_id = m.user_id
                 WHERE m.group_id = pg.group_id AND m.role = "Co-Operator" LIMIT 1) AS co_op
         FROM paluwagan_groups pg WHERE pg.group_code = :code LIMIT 1'
    );
    $stmt->execute([':code' => $code]);
    return $stmt->fetch();
}

function joinRequestIsPending(PDO $pdo, int $groupId, int $userId): bool
{
    $stmt = $pdo->prepare(
        'SELECT request_id FROM join_requests
         WHERE group_id = :group_id AND user_id = :user_id AND request_status = :status LIMIT 1'
    );
    $stmt->execute([':group_id' => $groupId, ':user_id' => $userId, ':status' => 'Pending']);
    return (bool) $stmt->fetchColumn();
}

function firstOpenJoinSlot(PDO $pdo, int $groupId, int $totalSlots): int
{
    $stmt = $pdo->prepare('SELECT assigned_slot_number FROM group_members WHERE group_id = :group_id');
    $stmt->execute([':group_id' => $groupId]);
    $occupied = array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
    for ($slot = 1; $slot <= $totalSlots; $slot++) {
        if (!isset($occupied[$slot])) return $slot;
    }
    return 0;
}

function groupCycleRoundDeadline(DateTimeImmutable $firstDue, string $frequency, int $roundOffset): DateTimeImmutable
{
    if ($frequency === 'Weekly') {
        return $firstDue->modify('+' . $roundOffset . ' weeks');
    }

    $month = $firstDue->modify('first day of this month')->modify('+' . $roundOffset . ' months');
    $day = min((int) $firstDue->format('j'), (int) $month->format('t'));
    return $month->setDate((int) $month->format('Y'), (int) $month->format('n'), $day)
        ->setTime((int) $firstDue->format('H'), (int) $firstDue->format('i'), (int) $firstDue->format('s'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $userId > 0) {
    $postedToken = $_POST['join_form_token'] ?? '';
    $joinAction = is_string($_POST['join_action'] ?? null) ? $_POST['join_action'] : '';
    if (!is_string($postedToken) || !hash_equals($joinToken, $postedToken)) {
        $joinModal = ['type' => 'error', 'message' => 'Your form expired. Refresh the page and try again.'];
    } else {
        try {
            $pdo = getDbConnection();
            if ($joinAction === 'start_group') {
                $startGroupId = filter_var($_POST['group_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if (!$startGroupId) {
                    $joinModal = ['type' => 'error', 'message' => 'That group could not be started. Refresh the page and try again.'];
                } else {
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare(
                        'SELECT group_status, total_member_slots, first_payment_due, payment_frequency
                         FROM paluwagan_groups WHERE group_id = :group_id FOR UPDATE'
                    );
                    $stmt->execute([':group_id' => $startGroupId]);
                    $lockedGroup = $stmt->fetch();

                    $stmt = $pdo->prepare(
                        'SELECT member_id FROM group_members
                         WHERE group_id = :group_id AND user_id = :user_id AND role = :role LIMIT 1 FOR UPDATE'
                    );
                    $stmt->execute([':group_id' => $startGroupId, ':user_id' => $userId, ':role' => 'Main Operator']);
                    $isMainOperator = (bool) $stmt->fetch();

                    $stmt = $pdo->prepare('SELECT COUNT(*) FROM group_members WHERE group_id = :group_id');
                    $stmt->execute([':group_id' => $startGroupId]);
                    $memberCount = (int) $stmt->fetchColumn();

                    $stmt = $pdo->prepare(
                        'SELECT member_id FROM group_members
                         WHERE group_id = :group_id AND role = :role LIMIT 1 FOR UPDATE'
                    );
                    $stmt->execute([':group_id' => $startGroupId, ':role' => 'Co-Operator']);
                    $hasCoOperator = (bool) $stmt->fetch();

                    $stmt = $pdo->prepare('SELECT COUNT(*) FROM round_schedules WHERE group_id = :group_id');
                    $stmt->execute([':group_id' => $startGroupId]);
                    $roundCount = (int) $stmt->fetchColumn();

                    if (!$lockedGroup || !$isMainOperator) {
                        $pdo->rollBack();
                        $joinModal = ['type' => 'error', 'message' => 'Only this group’s Main Operator can start it.'];
                    } elseif ($lockedGroup['group_status'] !== 'Waiting' || $roundCount > 0) {
                        $pdo->rollBack();
                        $joinModal = ['type' => 'error', 'message' => 'This group is no longer waiting to start.'];
                    } elseif ($memberCount !== (int) $lockedGroup['total_member_slots'] || !$hasCoOperator) {
                        $pdo->rollBack();
                        $joinModal = ['type' => 'error', 'message' => 'Fill every group slot and select a Co-operator before starting.'];
                    } else {
                        $stmt = $pdo->prepare(
                            'UPDATE paluwagan_groups SET group_status = :active
                             WHERE group_id = :group_id AND group_status = :waiting'
                        );
                        $stmt->execute([
                            ':active' => 'Active', ':group_id' => $startGroupId, ':waiting' => 'Waiting',
                        ]);
                        if ($stmt->rowCount() !== 1) {
                            $pdo->rollBack();
                            $joinModal = ['type' => 'error', 'message' => 'This group could not be started. Refresh the page and try again.'];
                        } else {
                            $stmt = $pdo->prepare(
                                'SELECT member_id FROM group_members WHERE group_id = :group_id ORDER BY assigned_slot_number'
                            );
                            $stmt->execute([':group_id' => $startGroupId]);
                            $cycleMembers = $stmt->fetchAll(PDO::FETCH_COLUMN);
                            if (count($cycleMembers) !== (int) $lockedGroup['total_member_slots']) {
                                $pdo->rollBack();
                                $joinModal = ['type' => 'error', 'message' => 'The group membership changed before it could start. Refresh the page and try again.'];
                            } else {
                                $insertRound = $pdo->prepare(
                                    'INSERT INTO round_schedules (group_id, round_number, receiver_member_id, target_deadline, round_status)
                                     VALUES (:group_id, :round_number, :receiver_member_id, :target_deadline, :round_status)'
                                );
                                $firstDue = new DateTimeImmutable($lockedGroup['first_payment_due']);
                                foreach ($cycleMembers as $roundIndex => $memberId) {
                                    $insertRound->execute([
                                        ':group_id' => $startGroupId,
                                        ':round_number' => $roundIndex + 1,
                                        ':receiver_member_id' => (int) $memberId,
                                        ':target_deadline' => groupCycleRoundDeadline($firstDue, $lockedGroup['payment_frequency'], $roundIndex)->format('Y-m-d H:i:s'),
                                        ':round_status' => $roundIndex === 0 ? 'Ongoing' : 'Upcoming',
                                    ]);
                                }
                            }

                            if ($pdo->inTransaction()) {
                                $pdo->prepare(
                                    'INSERT INTO audit_logs (group_id, actor_user_id, activity_type, details, verification_status)
                                     VALUES (:group_id, :actor, :activity, :details, :verification)'
                                )->execute([
                                    ':group_id' => $startGroupId, ':actor' => $userId,
                                    ':activity' => 'Group Started',
                                    ':details' => 'Group activated and round schedule created after all slots were filled',
                                    ':verification' => 'Main Operator',
                                ]);
                                $pdo->commit();
                                $_SESSION['group_success'] = 'Group started and moved to Active Groups.';
                                header('Location: my_groups.php?tab=Active');
                                exit;
                            }
                        }
                    }
                }
            } elseif ($joinAction === 'assign_coop') {
                $targetGroupId = filter_var($_POST['group_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $candidateUserId = filter_var($_POST['coop_user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if (!$targetGroupId || !$candidateUserId) {
                    $joinModal = ['type' => 'error', 'message' => 'Choose a group member to become Co-operator.'];
                } else {
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare(
                        'SELECT group_status, total_member_slots
                         FROM paluwagan_groups WHERE group_id = :group_id FOR UPDATE'
                    );
                    $stmt->execute([':group_id' => $targetGroupId]);
                    $lockedGroup = $stmt->fetch();

                    $lockedMemberCount = 0;
                    if ($lockedGroup) {
                        $stmt = $pdo->prepare('SELECT COUNT(*) FROM group_members WHERE group_id = :group_id');
                        $stmt->execute([':group_id' => $targetGroupId]);
                        $lockedMemberCount = (int) $stmt->fetchColumn();
                    }

                    $stmt = $pdo->prepare(
                        'SELECT member_id FROM group_members
                         WHERE group_id = :group_id AND user_id = :user_id AND role = :role LIMIT 1 FOR UPDATE'
                    );
                    $stmt->execute([':group_id' => $targetGroupId, ':user_id' => $userId, ':role' => 'Main Operator']);
                    $isMainOperator = (bool) $stmt->fetch();

                    $stmt = $pdo->prepare(
                        'SELECT member_id FROM group_members
                         WHERE group_id = :group_id AND role = :role LIMIT 1 FOR UPDATE'
                    );
                    $stmt->execute([':group_id' => $targetGroupId, ':role' => 'Co-Operator']);
                    $existingCoOperator = (bool) $stmt->fetch();

                    $stmt = $pdo->prepare('SELECT COUNT(*) FROM round_schedules WHERE group_id = :group_id');
                    $stmt->execute([':group_id' => $targetGroupId]);
                    $roundCount = (int) $stmt->fetchColumn();

                    $stmt = $pdo->prepare(
                        'SELECT gm.member_id, u.username FROM group_members gm
                         JOIN users u ON u.user_id = gm.user_id
                         WHERE gm.group_id = :group_id AND gm.user_id = :user_id AND gm.role = :role
                         LIMIT 1 FOR UPDATE'
                    );
                    $stmt->execute([':group_id' => $targetGroupId, ':user_id' => $candidateUserId, ':role' => 'Member']);
                    $candidate = $stmt->fetch();

                    if (!$lockedGroup || !$isMainOperator) {
                        $pdo->rollBack();
                        $joinModal = ['type' => 'error', 'message' => 'Only this group’s Main Operator can select a Co-operator.'];
                    } elseif ($lockedGroup['group_status'] !== 'Waiting' || $roundCount > 0) {
                        $pdo->rollBack();
                        $joinModal = ['type' => 'error', 'message' => 'A Co-operator cannot be selected after the group cycle has started.'];
                    } elseif ($lockedMemberCount < (int) $lockedGroup['total_member_slots']) {
                        $pdo->rollBack();
                        $joinModal = ['type' => 'error', 'message' => 'Fill every group slot before selecting a Co-operator.'];
                    } elseif ($existingCoOperator) {
                        $pdo->rollBack();
                        $joinModal = ['type' => 'error', 'message' => 'This group already has a Co-operator.'];
                    } elseif (!$candidate) {
                        $pdo->rollBack();
                        $joinModal = ['type' => 'error', 'message' => 'Choose a current member of this group.'];
                    } else {
                        $stmt = $pdo->prepare(
                            'UPDATE group_members SET role = :new_role
                             WHERE group_id = :group_id AND user_id = :user_id AND role = :old_role'
                        );
                        $stmt->execute([
                            ':new_role' => 'Co-Operator', ':group_id' => $targetGroupId,
                            ':user_id' => $candidateUserId, ':old_role' => 'Member',
                        ]);
                        if ($stmt->rowCount() !== 1) {
                            $pdo->rollBack();
                            $joinModal = ['type' => 'error', 'message' => 'That member could not be selected. Refresh the page and try again.'];
                        } else {
                            $pdo->prepare(
                                'INSERT INTO audit_logs (group_id, actor_user_id, activity_type, details, verification_status)
                                 VALUES (:group_id, :actor, :activity, :details, :verification)'
                            )->execute([
                                ':group_id' => $targetGroupId, ':actor' => $userId,
                                ':activity' => 'Co-operator Selected',
                                ':details' => $candidate['username'] . ' was selected as Co-operator',
                                ':verification' => 'Main Operator',
                            ]);
                            $pdo->commit();
                            $_SESSION['group_success'] = 'Co-operator selected successfully.';
                            header('Location: my_groups.php');
                            exit;
                        }
                    }
                }
            } elseif ($joinAction === 'preview') {
                unset($_SESSION['join_preview_group_id']);
                $groupCode = strtoupper(trim(is_string($_POST['group_code'] ?? null) ? $_POST['group_code'] : ''));
                if ($groupCode === '') {
                    $joinModal = ['type' => 'error', 'message' => 'Enter a group code to continue.'];
                } else {
                    $joinGroup = getJoinGroupByCode($pdo, $groupCode);
                    if (!$joinGroup) {
                        $joinModal = ['type' => 'missing'];
                        unset($_SESSION['join_preview_group_id']);
                    } elseif (!in_array($joinGroup['group_status'], ['Waiting', 'Active'], true)) {
                        $joinModal = ['type' => 'error', 'message' => 'This group is not accepting new members right now.'];
                        unset($_SESSION['join_preview_group_id']);
                    } else {
                        $stmt = $pdo->prepare(
                            'SELECT member_id FROM group_members WHERE group_id = :group_id AND user_id = :user_id LIMIT 1'
                        );
                        $stmt->execute([':group_id' => $joinGroup['group_id'], ':user_id' => $userId]);
                        if ($stmt->fetch()) {
                            $joinModal = ['type' => 'error', 'message' => 'You are already a member of this group.'];
                        } elseif (joinRequestIsPending($pdo, (int) $joinGroup['group_id'], $userId)) {
                            $joinModal = ['type' => 'waiting', 'group' => $joinGroup];
                            $_SESSION['join_preview_group_id'] = (int) $joinGroup['group_id'];
                        } elseif ((int) $joinGroup['member_count'] >= (int) $joinGroup['total_member_slots']) {
                            $joinModal = ['type' => 'error', 'message' => 'This group is full and has no open member slots.'];
                        } else {
                            $joinGroup['next_open_slot'] = firstOpenJoinSlot($pdo, (int) $joinGroup['group_id'], (int) $joinGroup['total_member_slots']);
                            $joinModal = ['type' => 'preview', 'group' => $joinGroup];
                            $_SESSION['join_preview_group_id'] = (int) $joinGroup['group_id'];
                        }
                    }
                }
            } elseif ($joinAction === 'confirm') {
                $previewGroupId = (int) ($_SESSION['join_preview_group_id'] ?? 0);
                if ($previewGroupId < 1) {
                    $joinModal = ['type' => 'error', 'message' => 'Please enter a group code first.'];
                } else {
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare(
                        'SELECT group_id, group_code, group_name, group_status,
                                contribution_amount, payment_frequency, total_member_slots
                         FROM paluwagan_groups WHERE group_id = :group_id FOR UPDATE'
                    );
                    $stmt->execute([':group_id' => $previewGroupId]);
                    $joinGroup = $stmt->fetch();
                    if (!$joinGroup || !in_array($joinGroup['group_status'], ['Waiting', 'Active'], true)) {
                        $pdo->rollBack();
                        unset($_SESSION['join_preview_group_id']);
                        $joinModal = ['type' => 'missing'];
                    } else {
                        $stmt = $pdo->prepare(
                            'SELECT member_id FROM group_members WHERE group_id = :group_id AND user_id = :user_id LIMIT 1'
                        );
                        $stmt->execute([':group_id' => $previewGroupId, ':user_id' => $userId]);
                        $alreadyMember = (bool) $stmt->fetch();
                        $stmt = $pdo->prepare(
                            'SELECT request_id FROM join_requests
                             WHERE group_id = :group_id AND user_id = :user_id AND request_status = :status LIMIT 1'
                        );
                        $stmt->execute([':group_id' => $previewGroupId, ':user_id' => $userId, ':status' => 'Pending']);
                        $alreadyPending = (bool) $stmt->fetch();
                        $stmt = $pdo->prepare('SELECT COUNT(*) FROM group_members WHERE group_id = :group_id');
                        $stmt->execute([':group_id' => $previewGroupId]);
                        $memberCount = (int) $stmt->fetchColumn();

                        if ($alreadyMember) {
                            $pdo->rollBack();
                            $joinModal = ['type' => 'error', 'message' => 'You are already a member of this group.'];
                        } elseif ($alreadyPending) {
                            $pdo->rollBack();
                            $joinModal = ['type' => 'waiting', 'group' => getJoinGroupByCode($pdo, (string) $joinGroup['group_code']) ?: $joinGroup];
                        } elseif ($memberCount >= (int) $joinGroup['total_member_slots']) {
                            $pdo->rollBack();
                            $joinModal = ['type' => 'error', 'message' => 'This group is full and has no open member slots.'];
                        } else {
                            $stmt = $pdo->prepare(
                                'INSERT INTO join_requests (group_id, user_id, request_status, requested_at)
                                 VALUES (:group_id, :user_id, :status, NOW())'
                            );
                            $stmt->execute([':group_id' => $previewGroupId, ':user_id' => $userId, ':status' => 'Pending']);
                            $pdo->commit();
                            $joinModal = ['type' => 'waiting', 'group' => getJoinGroupByCode($pdo, (string) $joinGroup['group_code']) ?: $joinGroup];
                        }
                    }
                    unset($_SESSION['join_preview_group_id']);
                }
            } elseif ($joinAction === 'cancel') {
                unset($_SESSION['join_preview_group_id']);
            }
        } catch (PDOException $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            error_log('My Groups action error: ' . $e->getMessage());
            $actionError = $joinAction === 'start_group'
                ? 'The group could not be started. Please try again.'
                : ($joinAction === 'assign_coop'
                    ? 'We could not select the Co-operator. Please try again.'
                    : 'We could not send your request. Please try again.');
            $joinModal = ['type' => 'error', 'message' => $actionError];
        }
    }
}
if ($userId > 0) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare(
            'SELECT pg.group_id, pg.group_name AS name, pg.group_status AS status,
                    gm.role AS viewer_role,
                    pg.group_code AS code, pg.payment_frequency AS schedule,
                    pg.contribution_amount AS amount, pg.total_member_slots AS capacity,
                    pg.group_status, pg.created_at,
                    (SELECT COUNT(*) FROM group_members members
                     WHERE members.group_id = pg.group_id) AS members,
                    (SELECT u.username FROM group_members operators
                     JOIN users u ON u.user_id = operators.user_id
                     WHERE operators.group_id = pg.group_id AND operators.role = "Main Operator"
                     ORDER BY operators.member_id LIMIT 1) AS main_op,
                    (SELECT u.username FROM group_members operators
                     JOIN users u ON u.user_id = operators.user_id
                     WHERE operators.group_id = pg.group_id AND operators.role = "Co-Operator"
                     ORDER BY operators.member_id LIMIT 1) AS co_op,
                    (SELECT COUNT(*) FROM round_schedules rounds
                     WHERE rounds.group_id = pg.group_id) AS cycle_rounds
             FROM group_members gm
             JOIN paluwagan_groups pg ON gm.group_id = pg.group_id
             WHERE gm.user_id = :user_id
             ORDER BY pg.created_at DESC'
        );
        $stmt->execute([':user_id' => $userId]);
        $groups = $stmt->fetchAll();

        $selectableGroupIds = [];
        foreach ($groups as $group) {
            if ($group['viewer_role'] === 'Main Operator'
                && empty($group['co_op'])
                && $group['group_status'] === 'Waiting'
                && (int) $group['members'] >= (int) $group['capacity']
                && (int) $group['cycle_rounds'] === 0) {
                $selectableGroupIds[] = (int) $group['group_id'];
            }
        }

        if ($selectableGroupIds) {
            $groupPlaceholders = [];
            $groupParams = [];
            foreach ($selectableGroupIds as $index => $selectableGroupId) {
                $placeholder = ':coop_group_' . $index;
                $groupPlaceholders[] = $placeholder;
                $groupParams[$placeholder] = $selectableGroupId;
                $coopCandidatesByGroup[$selectableGroupId] = [];
            }
            $stmt = $pdo->prepare(
                'SELECT gm.group_id, gm.user_id, u.username
                 FROM group_members gm JOIN users u ON u.user_id = gm.user_id
                 WHERE gm.group_id IN (' . implode(', ', $groupPlaceholders) . ')
                   AND gm.role = :member_role
                 ORDER BY u.username'
            );
            $stmt->execute($groupParams + [':member_role' => 'Member']);
            foreach ($stmt->fetchAll() as $candidate) {
                $coopCandidatesByGroup[(int) $candidate['group_id']][] = $candidate;
            }
        }
    } catch (PDOException $e) {
        error_log('My Groups DB error: ' . $e->getMessage());
        $groups = [];
        $loadError = 'We could not load your groups. Please refresh the page to try again.';
    }
}

$selectedTab = $_GET['tab'] ?? 'Waiting';
if (!in_array($selectedTab, ['Active', 'Waiting', 'Completed'], true)) {
    $selectedTab = 'Waiting';
}

foreach ($groups as &$group) {
    $group['status'] = ucfirst(strtolower((string) ($group['status'] ?? 'Waiting')));
    if (!in_array($group['status'], ['Active', 'Waiting', 'Completed'], true)) {
        $group['status'] = 'Waiting';
    }

    $group['name'] = $group['name'] ?? 'Untitled Group';
    $group['code'] = $group['code'] ?? 'Toka70';
    $group['main_op'] = $group['main_op'] ?? 'User';
    $group['has_co_op'] = $group['co_op'] !== null && $group['co_op'] !== '';
    $group['co_op'] = $group['co_op'] ?? 'Pending';
    $group['amount'] = isset($group['amount']) && $group['amount'] !== '' ? number_format((float) $group['amount'], 2) : '0.00';
    $group['schedule'] = $group['schedule'] ?? 'Monthly';
    $group['frequency'] = $group['frequency'] ?? ($group['schedule'] ?? 'Monthly');
    $group['members'] = (int) ($group['members'] ?? 1);
    $group['capacity'] = max(1, (int) ($group['capacity'] ?? 1));
}
unset($group);

$activeCount = 0;
$waitingCount = 0;
$completedCount = 0;

foreach ($groups as $group) {
    if ($group['status'] === 'Active') {
        $activeCount++;
    }

    if ($group['status'] === 'Waiting') {
        $waitingCount++;
    }

    if ($group['status'] === 'Completed') {
        $completedCount++;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Toka - My Groups</title>
    <link rel="stylesheet" href="dashboard.css?v=<?= filemtime(__DIR__ . '/dashboard.css') ?>">
</head>
<body>
    <div class="app">
        <aside class="sidebar">
            <div class="sidebar-top">
                <div class="brand">
                    <img src="../assets/Toka.svg" alt="Toka">
                </div>

                <nav class="nav">
                    <a href="my_groups.php" class="nav-item active">
                        <span class="nav-icon">👥</span>
                        <span>My Groups</span>
                    </a>

                    <a href="#" class="nav-item" style="color: #c7c7c7;">
                        <span class="nav-icon">⌂</span>
                        <span>Dashboard</span>
                    </a>
                </nav>
            </div>

            <div class="sidebar-bottom">
                <div class="profile">
                    <div class="profile-circle">
                        <?= htmlspecialchars(strtoupper(substr($username, 0, 1))) ?>
                    </div>
                    <span><?= htmlspecialchars($username) ?></span>
                </div>

                <a href="../LOGIN_PAGE/logout.php" class="logout">↪</a>
            </div>
        </aside>

        <main class="main">
            <section class="page-header">
                <div>
                    <h1>My Groups</h1>
                    <p>Manage and track all your Toka groups</p>
                </div>

                <a href="create_group.php" class="new-group-btn">
                    <span>+</span>
                    New Group
                </a>
            </section>


            <section class="join-section">
                <form class="join-box" method="post" action="my_groups.php">
                    <input type="hidden" name="join_form_token" value="<?= htmlspecialchars($joinToken) ?>">
                    <input type="hidden" name="join_action" value="preview">
                    <input type="text" name="group_code" placeholder="Enter group code to join   (e.g. Toka39)" maxlength="20" required>
                    <button type="submit">Join</button>
                </form>
            </section>

            <?php if ($success !== ''): ?>
                <div class="group-message group-success" role="status"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            <?php if ($loadError !== ''): ?>
                <div class="group-message group-error" role="alert"><?= htmlspecialchars($loadError) ?></div>
            <?php endif; ?>

            <?php if (empty($groups) && $loadError === ''): ?>
                <section class="empty-state">
                    <img src="../assets/Empty(icon).svg" alt="No groups" class="empty-icon">
                    <p class="empty-message">
                        Hmm... It looks like you have no
                        <br>
                        outgoing activities.
                    </p>
                </section>
            <?php elseif ($groups): ?>
                <section class="summary-grid">
                    <div class="summary-card active-card">
                        <div class="summary-number"><?= $activeCount ?></div>
                        <div class="summary-text">Active Groups</div>
                    </div>

                    <div class="summary-card waiting-card">
                        <div class="summary-number"><?= $waitingCount ?></div>
                        <div class="summary-text">Waiting Groups</div>
                    </div>

                    <div class="summary-card completed-card">
                        <div class="summary-number"><?= $completedCount ?></div>
                        <div class="summary-text">Completed Groups</div>
                    </div>
                </section>

                <section class="tabs">
                    <button type="button" class="tab<?= $selectedTab === 'Active' ? ' active' : '' ?>" data-status="Active">Active</button>
                    <button type="button" class="tab<?= $selectedTab === 'Waiting' ? ' active' : '' ?>" data-status="Waiting">Waiting</button>
                    <button type="button" class="tab<?= $selectedTab === 'Completed' ? ' active' : '' ?>" data-status="Completed">Completed</button>
                </section>

                <section class="group-list">
                    <?php foreach ($groups as $group): ?>
                        <article class="group-card" data-status="<?= htmlspecialchars($group['status']) ?>"<?= $group['status'] !== $selectedTab ? ' style="display: none;"' : '' ?>>
                            <div class="group-info">
                                <div class="group-heading">
                                    <h2><?= htmlspecialchars($group['name']) ?></h2>
                                    <span class="status <?= strtolower($group['status']) ?>">
                                        <?= htmlspecialchars($group['status']) ?>
                                    </span>
                                </div>

                                <div class="group-details">
                                    <span>♛ Main Op: <?= htmlspecialchars($group['main_op']) ?></span>
                                    <span>🛡 Co-Op: <?= htmlspecialchars($group['co_op']) ?></span>
                                    <span>₱ <?= htmlspecialchars($group['amount']) ?></span>
                                    <span>◷ <?= htmlspecialchars($group['schedule']) ?></span>
                                </div>
                            </div>

                            <div class="capacity">
                                <span class="capacity-label">Slot Capacity</span>


                            <div class="capacity-row">
                                <div class="progress">
                                    <div class="progress-bar" style="width: <?= ($group['members'] / $group['capacity']) * 100 ?>%;"></div>
                                </div>
                                <span><?= $group['members'] ?> / <?= $group['capacity'] ?></span>
                            </div>
                        </div>

                        <div class="group-action">
                            <?php $canStartGroup = $group['viewer_role'] === 'Main Operator'
                                && $group['status'] === 'Waiting'
                                && (int) $group['members'] === (int) $group['capacity']
                                && $group['has_co_op']
                                && (int) $group['cycle_rounds'] === 0; ?>
                            <?php if ($canStartGroup): ?>
                                <form method="post" action="my_groups.php?tab=Waiting" class="start-group-form">
                                    <input type="hidden" name="join_form_token" value="<?= htmlspecialchars($joinToken) ?>">
                                    <input type="hidden" name="join_action" value="start_group">
                                    <input type="hidden" name="group_id" value="<?= (int) $group['group_id'] ?>">
                                    <button type="submit" class="view-button start-button">Start</button>
                                </form>
                            <?php else: ?>
                                <a href="group_dashboard.php?id=<?= (int) $group['group_id'] ?>" class="view-button">View Group</a>
                            <?php endif; ?>
                            <?php if (!$canStartGroup && $group['viewer_role'] === 'Main Operator'
                                && !$group['has_co_op']
                                && $group['group_status'] === 'Waiting'
                                && (int) $group['cycle_rounds'] === 0): ?>
                                <?php if ((int) $group['members'] < (int) $group['capacity']): ?>
                                    <button type="button" class="coop-select-button coop-waiting-button" disabled title="Available when every group slot is filled">Waiting</button>
                                <?php else: ?>
                                    <?php $coopDialogId = 'select-coop-' . (int) $group['group_id']; ?>
                                    <button type="button" class="coop-select-button" data-open-coop="<?= htmlspecialchars($coopDialogId) ?>">Select Co-op</button>
                                    <dialog class="coop-dialog" id="<?= htmlspecialchars($coopDialogId) ?>">
                                        <h2>Ready to select<br>Co-operator?</h2>
                                        <form method="post" action="my_groups.php">
                                            <input type="hidden" name="join_form_token" value="<?= htmlspecialchars($joinToken) ?>">
                                            <input type="hidden" name="join_action" value="assign_coop">
                                            <input type="hidden" name="group_id" value="<?= (int) $group['group_id'] ?>">
                                            <label for="coop-member-<?= (int) $group['group_id'] ?>">Choose from Members</label>
                                            <select id="coop-member-<?= (int) $group['group_id'] ?>" name="coop_user_id" required <?= empty($coopCandidatesByGroup[(int) $group['group_id']]) ? 'disabled' : '' ?>>
                                                <?php if (empty($coopCandidatesByGroup[(int) $group['group_id']])): ?>
                                                    <option value="" selected>No members available</option>
                                                <?php else: ?>
                                                    <option value="" selected disabled>Choose a member</option>
                                                    <?php foreach ($coopCandidatesByGroup[(int) $group['group_id']] as $candidate): ?>
                                                        <option value="<?= (int) $candidate['user_id'] ?>"><?= htmlspecialchars($candidate['username']) ?></option>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </select>
                                            <p class="coop-note">NOTE: You cannot change your Co-operator once the cycle starts.</p>
                                            <div class="coop-modal-actions">
                                                <button type="button" class="coop-back-button" data-close-coop>Back</button>
                                                <button type="submit" class="coop-confirm-button" <?= empty($coopCandidatesByGroup[(int) $group['group_id']]) ? 'disabled' : '' ?>>Confirm</button>
                                            </div>
                                        </form>
                                    </dialog>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
                </section>
            <?php endif; ?>
        </main>
    </div>

    
            <?php if ($joinModal !== null): ?>
                <dialog class="join-dialog" data-join-modal="<?= htmlspecialchars($joinModal['type']) ?>">
                    <?php if ($joinModal['type'] === 'preview' || $joinModal['type'] === 'waiting'): ?>
                        <?php $joinGroup = $joinModal['group']; ?>
                        <div class="join-modal-heading">
                            <div><?php if ($joinModal['type'] === 'waiting'): ?><span class="join-modal-kicker">REQUEST SENT</span><?php endif; ?>
                                <h2><?= $joinModal['type'] === 'preview' ? 'Do you want to join this group?' : 'Your request is waiting for approval.' ?></h2>
                            </div>
                            <button type="button" class="join-modal-close" data-close-join aria-label="Close">×</button>
                        </div>
                        <section class="join-preview-card">
                            <h3><?= htmlspecialchars($joinGroup['group_name']) ?></h3>
                            <div class="join-preview-details">
                                <span>♛ &nbsp; Main Op: <?= htmlspecialchars($joinGroup['main_op'] ?: 'Pending') ?></span>
                                <span>⬟ &nbsp; Co-Op: <?= htmlspecialchars($joinGroup['co_op'] ?: 'Pending') ?></span>
                                <span>₱ &nbsp; <?= htmlspecialchars(number_format((float) $joinGroup['contribution_amount'], 2)) ?></span>
                                <span>◷ &nbsp; <?= htmlspecialchars($joinGroup['payment_frequency']) ?></span>
                                <span>▣ &nbsp; Slots: <?= (int) $joinGroup['member_count'] ?>/<?= (int) $joinGroup['total_member_slots'] ?></span>
                                <?php if ($joinModal['type'] === 'preview'): ?>
                                    <span>♟ &nbsp; Slot Assigned: <?= (int) $joinGroup['next_open_slot'] ?>/<?= (int) $joinGroup['total_member_slots'] ?></span>
                                <?php endif; ?>
                            </div>
                        </section>
                        <div class="join-warning">
                            <?= $joinModal['type'] === 'preview'
                                ? 'Wait for the main operator to approve your join request. You will be unable to leave once approved.'
                                : 'Wait for the main operator to approve your join request. You can close this message while you wait.' ?>
                        </div>
                        <?php if ($joinModal['type'] === 'preview'): ?>
                            <form class="join-modal-actions" method="post" action="my_groups.php">
                                <input type="hidden" name="join_form_token" value="<?= htmlspecialchars($joinToken) ?>">
                                <input type="hidden" name="join_action" value="confirm">
                                <button type="button" class="join-back-button" data-close-join>Back</button>
                                <button type="submit" class="join-confirm-button">Confirm</button>
                            </form>
                        <?php else: ?>
                            <div class="join-modal-actions"><button type="button" class="join-confirm-button" data-close-join>Done</button></div>
                        <?php endif; ?>
                    <?php elseif ($joinModal['type'] === 'missing'): ?>
                        <button type="button" class="join-modal-close missing-close" data-close-join aria-label="Close">×</button>
                        <div class="missing-group-icon" aria-hidden="true">▱</div>
                        <p class="missing-group-message">Hmm... It looks like this group doesn't exist.</p>
                        <div class="join-modal-actions"><button type="button" class="join-confirm-button" data-close-join>Confirm</button></div>
                    <?php else: ?>
                        <button type="button" class="join-modal-close missing-close" data-close-join aria-label="Close">×</button>
                        <div class="missing-group-icon warning-icon" aria-hidden="true">!</div>
                        <p class="missing-group-message"><?= htmlspecialchars($joinModal['message'] ?? 'We could not send your join request.') ?></p>
                        <div class="join-modal-actions"><button type="button" class="join-confirm-button" data-close-join>Confirm</button></div>
                    <?php endif; ?>
                </dialog>
            <?php endif; ?>

<script>
        document.querySelectorAll('[data-open-coop]').forEach(button => {
            const dialog = document.getElementById(button.dataset.openCoop);
            button.addEventListener('click', () => dialog?.showModal());
        });
        document.querySelectorAll('.coop-dialog').forEach(dialog => {
            dialog.querySelectorAll('[data-close-coop]').forEach(button => {
                button.addEventListener('click', () => dialog.close());
            });
            dialog.addEventListener('click', event => {
                if (event.target === dialog) dialog.close();
            });
        });

        const joinModal = document.querySelector('.join-dialog');
        if (joinModal) joinModal.showModal();
        joinModal?.querySelectorAll('[data-close-join]').forEach(button => {
            button.addEventListener('click', () => {
                if (joinModal.dataset.joinModal === 'preview' && button.textContent.trim() === 'Back') {
                    const cancelForm = document.createElement('form');
                    cancelForm.method = 'post';
                    cancelForm.action = 'my_groups.php';
                    cancelForm.innerHTML = '<input type="hidden" name="join_form_token" value="<?= htmlspecialchars($joinToken) ?>"><input type="hidden" name="join_action" value="cancel">';
                    document.body.appendChild(cancelForm);
                    cancelForm.submit();
                    return;
                }
                joinModal.close();
                joinModal.remove();
            });
        });
        joinModal?.addEventListener('click', event => {
            if (event.target === joinModal) {
                joinModal.close();
                joinModal.remove();
            }
        });

        const tabs = document.querySelectorAll(".tab");
        const cards = document.querySelectorAll(".group-card");

        tabs.forEach(tab => {
            tab.addEventListener("click", function () {
                tabs.forEach(item => {
                    item.classList.remove("active");
                });

                this.classList.add("active");

                const selectedStatus = this.dataset.status;

                cards.forEach(card => {
                    if (card.dataset.status === selectedStatus) {
                        card.style.display = "grid";
                    } else {
                        card.style.display = "none";
                    }
                });
            });
        });
    </script>
</body>
</html>

